/* prueba_cola_vivo.mjs — T2.12.7 y T2.12.8 contra el sitio de pruebas, con un
   navegador de verdad (Edge o Chrome sin ventana, manejado por CDP).

   T2.12.7  Sin servidor, la app abre desde la caché y la orden queda guardada en
            el celular; al volver la señal sale sola y llega UNA vez.
   T2.12.8  Si mientras tanto la sesión se cerró (otra sesión del mismo usuario
            la desplaza), la orden queda «falta entrar» y NO «rechazada»; al
            volver a entrar sale sola.

   CÓMO SE «APAGA» EL SERVIDOR: no se puede apagar Hostinger, y cortar la red con
   el depurador no sirve (el trabajador de servicio tiene su propio contexto de
   red; ver prueba_offline.mjs). Se relanza el navegador con el MISMO perfil y el
   dominio desviado a 127.0.0.1, donde no escucha nadie: para la página y para el
   trabajador de servicio el servidor está caído de verdad. Las cookies se guardan
   y se reponen entre arranques para que reiniciar no sea lo que cierra la sesión.

   Requiere ~/respaldos/preparar_prueba.php corrido en el servidor (usa la cuenta
   tec_prueba_uio_a) y deja dos órdenes de prueba, que borra deshacer_prueba.php.

   Uso:  node prueba_cola_vivo.mjs          Sale con 1 si algo falla. */
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, rmSync } from 'node:fs';
import { homedir, tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HOST = 'darkviolet-armadillo-872352.hostingersite.com';
const BASE = `https://${HOST}/ot/`;
const D = `domains/${HOST}/public_html/ot`;
const AQUI = dirname(fileURLToPath(import.meta.url));
const REPO = join(AQUI, '..', '..', '..', '..', '..');
const NAVEGADOR = process.env.INDUSTEC_NAVEGADOR || [
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
].find(existsSync);
const ESTACION = join(REPO, 'desarrollo', 'agentes', 'config', 'clave_hostinger');
const LLAVE = process.env.INDUSTEC_LLAVE_SSH || (existsSync(ESTACION) ? ESTACION : join(homedir(), '.ssh', 'industec_hostinger_pc'));
const SSH = ['-i', LLAVE, '-o', 'IdentitiesOnly=yes', '-p', '65002', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=20',
             '-o', 'StrictHostKeyChecking=accept-new', 'u671729428@82.25.73.181'];
const USUARIO = 'tec_prueba_uio_a';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const resultados = [];

function anotar(que, ok, obtenido) {
  resultados.push({ que, ok: !!ok, obtenido: String(obtenido) });
  console.log(`  ${ok ? 'PASA ' : 'FALLA'} ${que.padEnd(72)} ${String(obtenido).replace(/\s+/g, ' ').slice(0, 90)}`);
}

function ssh(cmd, input) {
  const r = spawnSync('ssh', [...SSH, cmd], { input, encoding: 'utf8', timeout: 120000 });
  if (r.status !== 0) { throw new Error('ssh: ' + (r.stderr || '').trim().slice(0, 200)); }
  return r.stdout;
}

function contar(uuid) {
  if (!/^[0-9a-f-]{36}$/i.test(uuid || '')) { return -1; }
  return parseInt(ssh(`cd ${D} && php`,
    `<?php require 'nucleo/Db.php'; echo (int) Db::uno('SELECT COUNT(*) n FROM ot_capturadas WHERE envio_uuid = ?', ['${uuid}'])['n'];`), 10);
}

class Navegador {
  constructor(perfil) { this.perfil = perfil; }

  async abrir(servidorCaido) {
    this.puerto = 9700 + Math.floor(Math.random() * 250);
    const args = ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
                  '--disable-extensions', `--user-data-dir=${this.perfil}`, `--remote-debugging-port=${this.puerto}`,
                  '--window-size=390,844'];
    if (servidorCaido) { args.push(`--host-resolver-rules=MAP ${HOST} 127.0.0.1`); }
    args.push('about:blank');
    this.proc = spawn(NAVEGADOR, args, { stdio: 'ignore' });
    let pagina;
    for (let i = 0; i < 100 && !pagina; i++) {
      await sleep(200);
      try {
        pagina = (await fetch(`http://127.0.0.1:${this.puerto}/json/list`).then((r) => r.json()))
          .find((x) => x.type === 'page');
      } catch { /* todavía no escucha */ }
    }
    if (!pagina) { throw new Error('el navegador no abrió'); }
    this.ws = new WebSocket(pagina.webSocketDebuggerUrl);
    this.id = 0;
    this.pend = new Map();
    await new Promise((ok, mal) => {
      this.ws.addEventListener('open', ok, { once: true });
      this.ws.addEventListener('error', () => mal(new Error('ws')), { once: true });
    });
    this.ws.addEventListener('message', (e) => {
      const m = JSON.parse(e.data);
      if (m.id && this.pend.has(m.id)) {
        const { ok, mal } = this.pend.get(m.id);
        this.pend.delete(m.id);
        m.error ? mal(new Error(m.error.message)) : ok(m.result);
      }
    });
    await this.send('Page.enable');
    await this.send('Runtime.enable');
    await this.send('Network.enable');
  }

  send(method, params = {}) {
    return new Promise((ok, mal) => {
      const id = ++this.id;
      this.pend.set(id, { ok, mal });
      this.ws.send(JSON.stringify({ id, method, params }));
    });
  }

  async ev(expresion) {
    const r = await this.send('Runtime.evaluate', { expression: expresion, awaitPromise: true, returnByValue: true });
    if (r.exceptionDetails) {
      throw new Error('JS: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text));
    }
    return r.result?.value;
  }

  async ir(ruta, espera = 7000) {
    await this.send('Page.navigate', { url: BASE + ruta });
    await sleep(espera);
  }

  async galletas() {
    return (await this.send('Network.getAllCookies')).cookies.filter((c) => c.domain.includes(HOST));
  }

  async ponerGalletas(cs) {
    if (!cs || !cs.length) { return; }
    await this.send('Network.setCookies', {
      cookies: cs.map((c) => ({
        name: c.name, value: c.value, domain: c.domain, path: c.path, secure: c.secure,
        httpOnly: c.httpOnly, sameSite: c.sameSite, ...(c.expires > 0 ? { expires: c.expires } : {}),
      })),
    });
  }

  async cerrar() {
    try {
      const v = await fetch(`http://127.0.0.1:${this.puerto}/json/version`).then((r) => r.json());
      const b = new WebSocket(v.webSocketDebuggerUrl);
      await new Promise((r) => b.addEventListener('open', r, { once: true }));
      b.send(JSON.stringify({ id: 1, method: 'Browser.close' }));   // cierre ordenado: guarda el perfil
    } catch { /* ya estaba cerrado */ }
    for (let i = 0; i < 60 && this.proc.exitCode === null; i++) { await sleep(200); }
    try { this.proc.kill(); } catch { /* nada */ }
    await sleep(1000);
  }
}

async function entrar(nav, clave) {
  await nav.ir('login.php', 4000);
  await nav.ev(`(() => {
    const d = document.querySelector('input[name=desplazar]');
    if (d) { d.form.submit(); return 'desplazar'; }
    document.querySelector('#usuario').value = ${JSON.stringify(USUARIO)};
    document.querySelector('#clave').value = ${JSON.stringify(clave)};
    document.querySelector('#clave').form.submit();
    return 'normal';
  })()`);
  await sleep(4500);
  // Si había otra sesión abierta, el ingreso ofrece desplazarla.
  if (await nav.ev(`!!document.querySelector('input[name=desplazar]')`)) {
    await nav.ev(`document.querySelector('input[name=desplazar]').form.submit()`);
    await sleep(4500);
  }
  return nav.ev('location.pathname');
}

// La orden se arma en la página con lo que el celular tiene guardado (catálogo y
// yo.php desde la caché si no hay servidor) y se encola como lo hace la app.
const ORDEN_JS = `(async () => {
  const yo = await (await fetch('yo.php')).json();
  const cat = await (await fetch('catalogos.php')).json();
  const c = ((cat.avisos || {}).datos || [])[0];
  const eqs = (cat.equipos || {})[c.local] || [];
  const eq = eqs.length ? { equipo_sap: String(eqs[0].equipo_sap), tipo: eqs[0].tipo || '' }
                        : { tipo: (cat.tipos || ['FREIDORA'])[0] };
  const hoy = new Date(Date.now() - 5 * 3600e3).toISOString().slice(0, 10);
  const orden = { local: c.local, aviso: c.aviso, tipo: 'CORRECTIVO', equipos: [eq], uso_repuesto: false,
                  repuestos: '', fecha_atencion: hoy, inicio: hoy + ' 07:00', fin: hoy + ' 08:15',
                  actividades: 'PRUEBA automatizada sin señal (T2.12.7 y T2.12.8): no es una intervención real.',
                  firma_presente: true, fotos_cantidad: 1, concluida: true };
  const uuid = await Cola.encolar(orden, yo.id ?? yo.usuario_id ?? null);
  return JSON.stringify({ uuid, aviso: c.aviso, local: c.local });
})()`;

const FILA_JS = (uuid) => `new Promise((ok) => {
  const p = indexedDB.open('ot-industec', 1);
  p.onsuccess = () => {
    const g = p.result.transaction('cola', 'readonly').objectStore('cola').get(${JSON.stringify(uuid)});
    g.onsuccess = () => ok(JSON.stringify(g.result ? { estado: g.result.estado, error: g.result.ultimo_error,
      intentos: g.result.intentos, recibo: g.result.recibo ? g.result.recibo.numero : null } : null));
  };
  p.onerror = () => ok('null');
})`;

async function fila(nav, uuid) { return JSON.parse(await nav.ev(FILA_JS(uuid))); }

const perfil = mkdtempSync(join(tmpdir(), 'industec-cola-'));
const nav = new Navegador(perfil);
let galletas = [];
try {
  if (!NAVEGADOR) { throw new Error('no hay Edge ni Chrome; define INDUSTEC_NAVEGADOR'); }
  const clave = JSON.parse(ssh('cat ~/respaldos/claves_prueba.json')).claves[USUARIO];

  console.log('== A. Primera visita, con el servidor en línea ==');
  await nav.abrir(false);
  const donde = await entrar(nav, clave);
  anotar('el técnico entra desde el navegador', !String(donde).includes('login'), donde);
  await nav.ir('index.html', 10000);
  const sw = JSON.parse(await nav.ev(`(async () => {
    const r = await navigator.serviceWorker.getRegistration();
    return JSON.stringify({ activo: !!(r && r.active), caches: await caches.keys() });
  })()`));
  anotar('el trabajador de servicio queda activo con su caché', sw.activo && sw.caches.length > 0, sw.caches.join(', '));
  // Que sea el VIGENTE: el 2026-09-11 el CDN de Hostinger entregaba el sw.js v2
  // horas después de subir el v4, y la prueba lo mostraba sin fallar.
  const { readFileSync } = await import('node:fs');
  const vigente = (readFileSync(new URL('../../publico/sw.js', import.meta.url), 'utf8')
    .match(/const VERSION = '([^']+)'/) || [])[1];
  anotar(`el celular recibió el trabajador de servicio vigente (${vigente})`,
         !!vigente && sw.caches.length > 0 && sw.caches.every((n) => n.startsWith(vigente)), sw.caches.join(', '));
  galletas = await nav.galletas();
  await nav.cerrar();

  console.log('\n== B. Servidor caído: la orden se guarda en el celular ==');
  await nav.abrir(true);
  await nav.ponerGalletas(galletas);
  await nav.ir('index.html', 9000);
  const est = JSON.parse(await nav.ev(`(async () => JSON.stringify({
    cola: typeof Cola, reglas: typeof Reglas, titulo: document.title,
    servidor: await fetch('envio.php', { method: 'POST', body: '{}' }).then(() => 'responde', () => 'caído') }))()`));
  anotar('la app abre sin servidor (armazón desde la caché)', est.cola === 'object' && est.reglas === 'object', `${est.titulo} · Cola ${est.cola} · Reglas ${est.reglas}`);
  anotar('el servidor de verdad no responde (la prueba vale)', est.servidor === 'caído', est.servidor);
  const o1 = JSON.parse(await nav.ev(ORDEN_JS));
  await sleep(4000);
  const f1 = await fila(nav, o1.uuid);
  anotar('la orden queda guardada y PENDIENTE en el celular', f1 && f1.estado === 'PENDIENTE', JSON.stringify(f1));
  anotar('no llegó al servidor', contar(o1.uuid) === 0, `COUNT = ${contar(o1.uuid)}`);
  galletas = await nav.galletas();
  await nav.cerrar();

  console.log('\n== C. Vuelve la señal: la orden sale sola (T2.12.7) ==');
  await nav.abrir(false);
  await nav.ponerGalletas(galletas);
  await nav.ir('index.html', 12000);
  const f1b = await fila(nav, o1.uuid);
  anotar('al abrir la app con señal, la orden se envía sola', f1b && f1b.estado === 'ENVIADA', JSON.stringify(f1b));
  anotar('llegó UNA vez al servidor', contar(o1.uuid) === 1, `COUNT = ${contar(o1.uuid)}`);
  galletas = await nav.galletas();
  await nav.cerrar();

  console.log('\n== D. Sin señal otra vez, y la sesión se cierra desde otro lado (T2.12.8) ==');
  await nav.abrir(true);
  await nav.ponerGalletas(galletas);
  await nav.ir('index.html', 9000);
  const o2 = JSON.parse(await nav.ev(ORDEN_JS));
  await sleep(3000);
  galletas = await nav.galletas();
  await nav.cerrar();
  const desp = spawnSync('python', ['-c',
    `import json, verificar_http as vh; c = json.loads(vh.ssh('cat ~/respaldos/claves_prueba.json')); print(vh.Sesion('${USUARIO}').entrar(c['claves']['${USUARIO}']))`],
    { cwd: AQUI, encoding: 'utf8', env: { ...process.env, PYTHONUTF8: '1', PYTHONIOENCODING: 'utf-8' } });
  anotar('otra sesión del mismo técnico desplaza a la del celular', desp.status === 0 && desp.stdout.includes('302'),
         (desp.stdout + desp.stderr).trim().slice(0, 90));
  await nav.abrir(false);
  await nav.ponerGalletas(galletas);          // la cookie vieja: la sesión que se desplazó
  await nav.ir('index.html', 12000);
  const f2 = await fila(nav, o2.uuid);
  anotar('con la sesión caída, la orden queda en «falta entrar» y NO rechazada',
         f2 && f2.estado === 'PENDIENTE' && /volver a entrar/.test(f2.error || ''), JSON.stringify(f2));
  const pagina = await nav.ev('location.pathname');
  if (String(pagina).endsWith('index.html')) {
    const caja = await nav.ev(`(document.querySelector('#cola') || {}).innerText || ''`);
    anotar('el recuadro de la cola le dice que vuelva a entrar', /vuelvas a entrar/.test(caja), caja);
  } else {
    console.log(`  (la app lo llevó a ${pagina}; el recuadro no se evalúa)`);
  }
  anotar('no llegó al servidor', contar(o2.uuid) === 0, `COUNT = ${contar(o2.uuid)}`);
  const d2 = await entrar(nav, clave);
  anotar('vuelve a entrar', !String(d2).includes('login'), d2);
  await nav.ir('index.html', 12000);
  const f2b = await fila(nav, o2.uuid);
  anotar('al volver a entrar, la orden sale sola', f2b && f2b.estado === 'ENVIADA', JSON.stringify(f2b));
  anotar('llegó UNA vez al servidor', contar(o2.uuid) === 1, `COUNT = ${contar(o2.uuid)}`);
  await nav.cerrar();
} catch (e) {
  anotar('la prueba terminó sin excepción', false, e.message);
  try { await nav.cerrar(); } catch { /* nada */ }
}
rmSync(perfil, { recursive: true, force: true });
const fallas = resultados.filter((r) => !r.ok);
console.log(`\n${resultados.length - fallas.length} de ${resultados.length} comprobaciones pasan; ${fallas.length} fallan`);
process.exit(fallas.length ? 1 : 0);
