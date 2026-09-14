/* capturar_pantallas.mjs — Capturas de cada pantalla del sistema, por rol, contra
   el sitio de pruebas, con un navegador de verdad (Edge o Chrome sin ventana por CDP).

   PARA QUÉ EXISTE
   Las baterías por rol (verificar_*.py) comprueban cifras y códigos HTTP, pero no
   ven lo que ve una persona. El 2026-09-12 dos pantallas llevaban dos días sin
   estilos con toda la batería en verde: eso solo se descubre mirando. Este arnés
   entra con cada cuenta de prueba, abre cada pantalla en el tamaño en que se usa
   (escritorio para administración y jefe, celular para el técnico), guarda la
   captura de página completa y anota si el HTML trae un error de PHP o si el
   título no es el esperado. Las capturas sirven además para las hojas del piloto.

   Requiere ~/respaldos/preparar_prueba.php corrido en el servidor (usa
   admin_prueba, jefe_prueba_uio y tec_prueba_uio_a). No escribe nada en la base.

   Uso:  node capturar_pantallas.mjs [--solo admin|jefe|tecnico] [--salida <carpeta>]
                                     [--recorte] [--anonimizar] [--piloto]
     --recorte     captura solo lo que cabe en la ventana (para las hojas del
                   piloto: un PNG de 150 KB, no una tira de 8.000 px).
     --anonimizar  antes de capturar reemplaza en la página los nombres, usuarios
                   y correos del personal real (los lee de la base por SSH; no
                   se escriben en ningún archivo) por «Técnico 1», «Jefe de
                   zona 2»… Las hojas del piloto no llevan datos de una persona
                   real (T2.14.8).
     --piloto      el juego de pantallas de las tres hojas (con filtros, diálogos
                   abiertos y la pantalla de ingreso).
   Variables: INDUSTEC_NAVEGADOR, INDUSTEC_LLAVE_SSH. Sale con 1 si alguna pantalla
   trae un error de PHP o no carga. */
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { homedir, tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { mkdtempSync } from 'node:fs';

const HOST = 'darkviolet-armadillo-872352.hostingersite.com';
const BASE = `https://${HOST}/ot/`;
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
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const ERRORES_PHP = /Fatal error|Parse error|Warning: |Notice: |Deprecated: |Uncaught /;

const args = process.argv.slice(2);
const solo = args.includes('--solo') ? args[args.indexOf('--solo') + 1] : null;
const SALIDA = args.includes('--salida') ? args[args.indexOf('--salida') + 1]
  : join(process.env.INDUSTEC_PRUEBAS_SALIDA || tmpdir(), 'industec-capturas');
const RECORTE = args.includes('--recorte');
const ANONIMIZAR = args.includes('--anonimizar');
const PILOTO = args.includes('--piloto');

/* Qué abre cada rol y en qué tamaño. Las pantallas nuevas se agregan aquí:
   si una ruta no existe todavía en el servidor, la captura queda con «404» y
   no se cuenta como error de PHP. */
const ROLES = {
  admin:   { usuario: 'admin_prueba',     ancho: 1400, alto: 900, rutas: [
    'panel.php', 'casos.php', 'casos.php?est=ATENDIDO', 'asignacion.php', 'pendientes.php',
    'novedades_visita.php', 'ordenes.php', 'cronograma.html', 'reportes.php', 'usuarios.php',
    'bitacora.php', 'documentos.php',
  ] },
  jefe:    { usuario: 'jefe_prueba_uio',  ancho: 1400, alto: 900, rutas: [
    'panel.php', 'casos.php', 'asignacion.php', 'pendientes.php', 'novedades_visita.php',
    'ordenes.php', 'cronograma.html', 'reportes.php',
  ] },
  tecnico: { usuario: 'tec_prueba_uio_a', ancho: 390,  alto: 844, rutas: [
    'mis.php', 'mis.php?t=atendidas', 'mis.php?t=avisos', 'index.html', 'pendientes.php',
    'cronograma.html', 'ordenes.php', 'documentos.php',
  ] },
  // La administración también en celular: las pantallas anchas tienen que caber.
  admin_movil: { usuario: 'admin_prueba', ancho: 390, alto: 844, rutas: ['panel.php', 'asignacion.php', 'pendientes.php'] },
};

/* Las pantallas de las tres hojas del piloto (T2.14.8). Cada ruta puede llevar
   un nombre de archivo propio (`nombre`) y un guion que se corre en la página
   antes de capturar (`antes`: abrir un diálogo, desplazarse a una tabla). */
const CLIC = (regex) => `(() => { const b = [...document.querySelectorAll('button, a.btn')].find(x => ${regex}.test(x.textContent.trim())); if (b) { b.click(); return 'abierto'; } return 'sin boton'; })()`;
const PILOTO_ROLES = {
  admin: { usuario: 'admin_prueba', ancho: 1400, alto: 900, rutas: [
    { ruta: 'panel.php' },
    { ruta: 'casos.php' },
    { ruta: 'casos.php?est=NUEVO', nombre: 'casos_sin_asignar' },
    { ruta: 'asignacion.php' },
    { ruta: 'asignacion.php?zona=UIO', nombre: 'asignacion_uio' },
    { ruta: 'pendientes.php' },
    { ruta: 'novedades_visita.php' },
    { ruta: 'ordenes.php' },
    { ruta: 'ordenes.php', nombre: 'ordenes_compartir', antes: CLIC('/^Compartir/') },
    { ruta: 'reportes.php' },
    { ruta: 'reportes.php?zona=UIO', nombre: 'reportes_uio' },
    { ruta: 'cronograma.html' },
    { ruta: 'documentos.php' },
    { ruta: 'equipos.php' },
    { ruta: 'usuarios.php' },
    { ruta: 'bitacora.php' },
  ] },
  jefe: { usuario: 'jefe_prueba_uio', ancho: 1400, alto: 900, rutas: [
    { ruta: 'panel.php' },
    { ruta: 'casos.php' },
    { ruta: 'asignacion.php' },
    { ruta: 'asignacion.php', nombre: 'asignacion_por_repartir',
      antes: "(() => { const t = document.querySelector('table.repartir'); if (t) { t.scrollIntoView({block: 'start'}); return 'ok'; } return 'sin tabla'; })()" },
    { ruta: 'pendientes.php' },
    { ruta: 'pendientes.php', nombre: 'pendientes_validar', antes: CLIC('/^Validar/') },
    { ruta: 'novedades_visita.php' },
    { ruta: 'cronograma.html' },
    { ruta: 'reportes.php' },
    { ruta: 'ordenes.php' },
    { ruta: 'documentos.php' },
  ] },
  tecnico: { usuario: 'tec_prueba_uio_a', ancho: 390, alto: 844, rutas: [
    { ruta: 'mis.php' },
    { ruta: 'mis.php?t=avisos', nombre: 'mis_avisos' },
    { ruta: 'mis.php?t=atendidas', nombre: 'mis_atendidas' },
    { ruta: 'index.html' },
    { ruta: 'pendientes.php' },
    { ruta: 'ordenes.php' },
    { ruta: 'cronograma.html' },
    { ruta: 'documentos.php' },
  ] },
  // La pantalla de entrada, sin sesión.
  entrada: { usuario: null, ancho: 390, alto: 844, rutas: [
    { ruta: 'login.php', nombre: 'ingreso' },
  ] },
};

function ssh(cmd) {
  const r = spawnSync('ssh', [...SSH, cmd], { encoding: 'utf8', timeout: 120000 });
  if (r.status !== 0) { throw new Error('ssh: ' + (r.stderr || '').trim().slice(0, 200)); }
  return r.stdout;
}

/* Los nombres, usuarios y correos del personal real, leídos de la base del sitio
   de pruebas para reemplazarlos en la página antes de capturar. Se quedan en
   memoria: no se escriben en ningún archivo. Las cuentas de prueba se conservan
   tal cual (no son personas). */
function personalReal() {
  const php = 'require "nucleo/Db.php"; echo json_encode(Db::todos("SELECT usuario, nombre, correo, rol, zona FROM usuarios WHERE usuario NOT LIKE \'%prueba%\' ORDER BY rol, zona, usuario"));';
  const salida = ssh(`cd domains/${HOST}/public_html/ot && php -r ${JSON.stringify(php)}`);
  const filas = JSON.parse(salida);
  const contador = {};
  const mapa = [];
  for (const f of filas) {
    const rol = { TECNICO: 'Técnico', JEFE_ZONA: 'Jefe de zona', ADMIN: 'Administración', SUPERADMIN: 'Dirección' }[f.rol] || 'Persona';
    contador[rol] = (contador[rol] || 0) + 1;
    const alias = `${rol} ${contador[rol]}`;
    if (f.nombre && f.nombre.length > 3) { mapa.push([f.nombre, alias]); }
    if (f.usuario && f.usuario.length > 3) { mapa.push([f.usuario, alias.toLowerCase().replace(/[^a-z0-9]+/g, '_')]); }
    if (f.correo) { mapa.push([f.correo, 'correo@ejemplo.ec']); }
  }
  // Los más largos primero, para que «Nombre Apellido» se reemplace antes que «Nombre».
  mapa.sort((a, b) => b[0].length - a[0].length);
  return mapa;
}

/* Reemplaza en los nodos de texto, en value/title/placeholder/aria-label y en
   los <input> con valor. Devuelve cuántos reemplazos hizo. */
const GUION_ANONIMIZAR = (mapa) => `(() => {
  const mapa = ${JSON.stringify(mapa)};
  const correo = /[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Za-z]{2,}/g;
  let n = 0;
  const cambiar = (t) => {
    let v = t;
    for (const [de, a] of mapa) { if (v.includes(de)) { v = v.split(de).join(a); n++; } }
    if (correo.test(v)) { v = v.replace(correo, 'correo@ejemplo.ec'); n++; }
    correo.lastIndex = 0;
    return v;
  };
  const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  const nodos = [];
  while (w.nextNode()) { nodos.push(w.currentNode); }
  for (const t of nodos) { const v = cambiar(t.nodeValue); if (v !== t.nodeValue) { t.nodeValue = v; } }
  for (const el of document.querySelectorAll('[value],[title],[placeholder],[aria-label]')) {
    for (const at of ['value', 'title', 'placeholder', 'aria-label']) {
      if (el.hasAttribute(at)) { const v = cambiar(el.getAttribute(at)); if (v !== el.getAttribute(at)) { el.setAttribute(at, v); } }
    }
    if (el.tagName === 'INPUT' && el.value) { const v = cambiar(el.value); if (v !== el.value) { el.value = v; } }
  }
  return n;
})()`;

class Navegador {
  constructor(perfil, ancho, alto) { this.perfil = perfil; this.ancho = ancho; this.alto = alto; }

  async abrir() {
    this.puerto = 9700 + Math.floor(Math.random() * 250);
    const args = ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
                  '--disable-extensions', '--hide-scrollbars', '--force-device-scale-factor=1',
                  `--user-data-dir=${this.perfil}`, `--remote-debugging-port=${this.puerto}`,
                  `--window-size=${this.ancho},${this.alto}`, 'about:blank'];
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
    this.estado = null;
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
      } else if (m.method === 'Network.responseReceived' && m.params.type === 'Document') {
        this.estado = m.params.response.status;   // el código HTTP del documento principal
      }
    });
    await this.send('Page.enable');
    await this.send('Runtime.enable');
    await this.send('Network.enable');
    await this.send('Emulation.setDeviceMetricsOverride', {
      width: this.ancho, height: this.alto, deviceScaleFactor: 1, mobile: this.ancho < 600,
    });
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

  async ir(ruta, espera = 6000) {
    this.estado = null;
    await this.send('Page.navigate', { url: BASE + ruta });
    await sleep(espera);
  }

  async captura(archivo) {
    // Página completa: se mide el alto real y se captura más allá de la ventana.
    // Con --recorte, solo la ventana: lo que ve la persona sin desplazarse.
    const alto = RECORTE ? this.alto
      : Math.min(8000, Math.max(this.alto, Number(await this.ev('document.documentElement.scrollHeight')) || this.alto));
    const r = await this.send('Page.captureScreenshot', {
      format: 'png', captureBeyondViewport: true,
      clip: { x: 0, y: 0, width: this.ancho, height: alto, scale: 1 },
    });
    writeFileSync(archivo, Buffer.from(r.data, 'base64'));
    return alto;
  }

  async cerrar() {
    try {
      const v = await fetch(`http://127.0.0.1:${this.puerto}/json/version`).then((r) => r.json());
      const b = new WebSocket(v.webSocketDebuggerUrl);
      await new Promise((r) => b.addEventListener('open', r, { once: true }));
      b.send(JSON.stringify({ id: 1, method: 'Browser.close' }));
    } catch { /* ya estaba cerrado */ }
    for (let i = 0; i < 60 && this.proc.exitCode === null; i++) { await sleep(200); }
    try { this.proc.kill(); } catch { /* nada */ }
    await sleep(500);
  }
}

async function entrar(nav, usuario, clave) {
  await nav.ir('login.php', 4000);
  await nav.ev(`(() => {
    const d = document.querySelector('input[name=desplazar]');
    if (d) { d.form.submit(); return 'desplazar'; }
    document.querySelector('#usuario').value = ${JSON.stringify(usuario)};
    document.querySelector('#clave').value = ${JSON.stringify(clave)};
    document.querySelector('#clave').form.submit();
    return 'normal';
  })()`);
  await sleep(4500);
  if (await nav.ev(`!!document.querySelector('input[name=desplazar]')`)) {
    await nav.ev(`document.querySelector('input[name=desplazar]').form.submit()`);
    await sleep(4500);
  }
  return nav.ev('location.pathname');
}

const indice = [];
let fallas = 0;
mkdirSync(SALIDA, { recursive: true });
if (!NAVEGADOR) { console.error('no hay Edge ni Chrome; define INDUSTEC_NAVEGADOR'); process.exit(1); }
const claves = JSON.parse(ssh('cat ~/respaldos/claves_prueba.json')).claves;
const mapaPersonal = ANONIMIZAR ? personalReal() : [];
if (ANONIMIZAR) { console.log(`anonimizar: ${mapaPersonal.length} cadenas del personal real se reemplazan antes de capturar`); }

for (const [rol, def] of Object.entries(PILOTO ? PILOTO_ROLES : ROLES)) {
  if (solo && rol !== solo) { continue; }
  const carpeta = join(SALIDA, rol);
  mkdirSync(carpeta, { recursive: true });
  const perfil = mkdtempSync(join(tmpdir(), 'industec-cap-'));
  const nav = new Navegador(perfil, def.ancho, def.alto);
  console.log(`== ${rol} (${def.usuario || 'sin sesión'}, ${def.ancho}×${def.alto}) ==`);
  try {
    await nav.abrir();
    if (def.usuario) {
      const donde = await entrar(nav, def.usuario, claves[def.usuario]);
      if (String(donde).includes('login')) { throw new Error('no pudo entrar: sigue en ' + donde); }
    }
    for (const item of def.rutas) {
      const ruta = typeof item === 'string' ? item : item.ruta;
      const nombre = ((typeof item === 'object' && item.nombre) || ruta.replace(/[^a-z0-9]+/gi, '_').replace(/_+$/, '')) + '.png';
      const archivo = join(carpeta, nombre);
      let fila = { rol, ruta, archivo, http: null, titulo: '', alto: 0, error_php: false, ok: false };
      try {
        await nav.ir(ruta, ruta.endsWith('.html') ? 8000 : 6000);
        fila.http = nav.estado;
        fila.titulo = String(await nav.ev('document.title'));
        const texto = String(await nav.ev('document.body ? document.body.innerText.slice(0, 20000) : ""'));
        fila.error_php = ERRORES_PHP.test(texto);
        if (typeof item === 'object' && item.antes) { fila.antes = String(await nav.ev(item.antes)); await sleep(1500); }
        if (ANONIMIZAR) { fila.anonimizados = Number(await nav.ev(GUION_ANONIMIZAR(mapaPersonal))); }
        fila.alto = await nav.captura(archivo);
        fila.ok = !fila.error_php && (fila.http === null || fila.http < 500) && (!fila.titulo.includes('Ingreso') || !def.usuario);
      } catch (e) {
        fila.titulo = 'ERROR: ' + e.message;
      }
      if (!fila.ok) { fallas++; }
      indice.push(fila);
      console.log(`  ${fila.ok ? 'OK   ' : 'FALLA'} ${ruta.padEnd(28)} http=${fila.http ?? '?'} alto=${fila.alto} ${fila.error_php ? 'ERROR PHP ' : ''}${fila.antes ? '[' + fila.antes + '] ' : ''}${fila.anonimizados ? `anon=${fila.anonimizados} ` : ''}${fila.titulo.slice(0, 60)}`);
    }
  } catch (e) {
    fallas++;
    console.log(`  FALLA ${rol}: ${e.message}`);
    indice.push({ rol, ruta: '(ingreso)', ok: false, titulo: e.message });
  } finally {
    await nav.cerrar();
  }
}
writeFileSync(join(SALIDA, 'indice.json'), JSON.stringify(indice, null, 1));
console.log(`\n${indice.length - fallas} de ${indice.length} pantallas sin error · capturas en ${SALIDA}`);
process.exit(fallas ? 1 : 0);
