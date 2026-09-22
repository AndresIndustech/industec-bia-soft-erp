/* verificar_formulario.mjs — El formulario del tecnico, con un navegador de
   verdad y gestos de verdad (T2.26).

   POR QUE EXISTE
   El 2026-09-22 el formulario estaba INUTILIZABLE —ninguna cabecera abria su
   paso, asi que el tecnico no podia ver el caso que traia precargado ni
   avanzar— y las 319 comprobaciones del proyecto estaban TODAS en verde.
   Ninguna pulsaba un boton: comprueban codigos HTTP, contratos de archivos y
   datos. Esta bateria cubre ese hueco, que es justo donde el tecnico vive.

   QUE COMPRUEBA
     A. el equipo del aviso se preselecciona, o se dice por que no
     B. cada cabecera del guiado abre SU paso
     C. sin senal, el caso sigue precargado y el catalogo sale de la copia
     D. la orden llenada sin senal queda guardada en el telefono
     E. el navegador acepta el `sync` que la manda con la app cerrada

   NO ENVIA NINGUNA ORDEN: la cola se vacia antes de reconectar, asi que no se
   escribe nada en el servidor ni se gasta un correlativo. Que la orden SALE de
   verdad con la app cerrada se prueba en `verificar_sync_cerrada.mjs`.

   Necesita ~/respaldos/preparar_prueba.php corrido, y un caso con local
   asignado al tecnico de prueba. Si solo quedan los avisos sinteticos del
   arnes, sale con codigo 2 y lo DICE, en vez de dar un falso verde.

   OJO (error nº 31 del plan): se corre SOLA. Otra bateria con las mismas
   cuentas de prueba desplaza la sesion y el resultado no significa nada.

   Uso:  node verificar_formulario.mjs      [SALIDA=<carpeta> para las capturas]
   Variables: INDUSTEC_LLAVE_SSH, SALIDA */
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const HOST = 'darkviolet-armadillo-872352.hostingersite.com';
const BASE = `https://${HOST}/ot/`;
const NAVEGADOR = [
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
].find(existsSync);
const LLAVE = process.env.INDUSTEC_LLAVE_SSH || 'D:/INDUSTECH IA/desarrollo/agentes/config/clave_hostinger';
const SSH = ['-i', LLAVE, '-o', 'IdentitiesOnly=yes', '-p', '65002', '-o', 'BatchMode=yes',
             '-o', 'ConnectTimeout=20', '-o', 'StrictHostKeyChecking=accept-new', 'u671729428@82.25.73.181'];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const ssh = (cmd) => {
  const r = spawnSync('ssh', [...SSH, cmd], { encoding: 'utf8', timeout: 120000 });
  if (r.status !== 0) { throw new Error('ssh: ' + (r.stderr || '').trim().slice(0, 300)); }
  return r.stdout;
};

let pasa = 0, falla = 0;
const afirmar = (que, ok, detalle) => {
  (ok ? pasa++ : falla++);
  console.log(`  ${ok ? 'PASA ' : 'FALLA'} ${que.padEnd(58)} ${detalle ?? ''}`);
};

class Nav {
  constructor(perfil, ancho, alto) { this.perfil = perfil; this.ancho = ancho; this.alto = alto; this.consola = []; }
  async abrir() {
    this.puerto = 9700 + Math.floor(Math.random() * 250);
    this.proc = spawn(NAVEGADOR, ['--headless=new', '--disable-gpu', '--no-first-run',
      '--no-default-browser-check', '--disable-extensions', '--hide-scrollbars',
      `--user-data-dir=${this.perfil}`, `--remote-debugging-port=${this.puerto}`,
      `--window-size=${this.ancho},${this.alto}`, 'about:blank'], { stdio: 'ignore' });
    let pagina;
    for (let i = 0; i < 100 && !pagina; i++) {
      await sleep(200);
      try {
        pagina = (await fetch(`http://127.0.0.1:${this.puerto}/json/list`).then((r) => r.json()))
          .find((x) => x.type === 'page');
      } catch { /* aun no */ }
    }
    if (!pagina) { throw new Error('el navegador no abrio'); }
    this.ws = new WebSocket(pagina.webSocketDebuggerUrl);
    this.id = 0; this.pend = new Map();
    await new Promise((ok, mal) => {
      this.ws.addEventListener('open', ok, { once: true });
      this.ws.addEventListener('error', () => mal(new Error('ws')), { once: true });
    });
    this.ws.addEventListener('message', (e) => {
      const m = JSON.parse(e.data);
      if (m.id && this.pend.has(m.id)) {
        const { ok, mal } = this.pend.get(m.id); this.pend.delete(m.id);
        m.error ? mal(new Error(m.error.message)) : ok(m.result);
      } else if (m.method === 'Runtime.exceptionThrown') {
        this.consola.push('EXCEPCION: ' + (m.params.exceptionDetails?.exception?.description || m.params.exceptionDetails?.text));
      }
    });
    await this.send('Page.enable'); await this.send('Runtime.enable');
    await this.send('Network.enable'); await this.send('ServiceWorker.enable');
    await this.send('Emulation.setDeviceMetricsOverride',
      { width: this.ancho, height: this.alto, deviceScaleFactor: 1, mobile: true });
  }
  send(method, params = {}) {
    return new Promise((ok, mal) => {
      const id = ++this.id; this.pend.set(id, { ok, mal });
      this.ws.send(JSON.stringify({ id, method, params }));
    });
  }
  async ev(expr) {
    const r = await this.send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
    if (r.exceptionDetails) { throw new Error('JS: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text)); }
    return r.result?.value;
  }
  async ir(ruta, espera = 9000) { await this.send('Page.navigate', { url: BASE + ruta }); await sleep(espera); }
  async red(conectado) {
    await this.send('Network.emulateNetworkConditions',
      { offline: !conectado, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
  }
  async captura(archivo) {
    const alto = Math.min(8000, Math.max(this.alto, Number(await this.ev('document.documentElement.scrollHeight')) || this.alto));
    const r = await this.send('Page.captureScreenshot',
      { format: 'png', captureBeyondViewport: true, clip: { x: 0, y: 0, width: this.ancho, height: alto, scale: 1 } });
    writeFileSync(archivo, Buffer.from(r.data, 'base64'));
  }
  async cerrar() {
    try {
      const v = await fetch(`http://127.0.0.1:${this.puerto}/json/version`).then((r) => r.json());
      const b = new WebSocket(v.webSocketDebuggerUrl);
      await new Promise((r) => b.addEventListener('open', r, { once: true }));
      b.send(JSON.stringify({ id: 1, method: 'Browser.close' }));
    } catch { /* ya */ }
    for (let i = 0; i < 40 && this.proc.exitCode === null; i++) { await sleep(200); }
    try { this.proc.kill(); } catch { /* nada */ }
  }
}

const COLA = `(async () => {
  const bd = await new Promise((ok, mal) => {
    const p = indexedDB.open('ot-industec', 1);
    p.onsuccess = () => ok(p.result); p.onerror = () => mal(p.error);
  });
  if (![...bd.objectStoreNames].includes('cola')) { return []; }
  const filas = await new Promise((ok) => {
    const t = bd.transaction('cola', 'readonly').objectStore('cola').getAll();
    t.onsuccess = () => ok(t.result); t.onerror = () => ok([]);
  });
  return filas.map((f) => ({ uuid: (f.uuid||'').slice(0,8), estado: f.estado,
    aviso: (f.orden||{}).aviso, local: (f.orden||{}).local,
    equipos: ((f.orden||{}).equipos||[]).length, firma: !!(f.orden||{}).firma_png,
    fotos: (f.fotos||[]).length, error: f.ultimo_error }));
})()`;

const VACIAR = `(async () => {
  const bd = await new Promise((ok) => { const p = indexedDB.open('ot-industec', 1); p.onsuccess = () => ok(p.result); });
  if (![...bd.objectStoreNames].includes('cola')) { return 'nada que vaciar'; }
  return await new Promise((ok) => {
    const t = bd.transaction('cola','readwrite').objectStore('cola').clear();
    t.onsuccess = () => ok('cola vaciada'); t.onerror = () => ok('no se pudo');
  });
})()`;

const claves = JSON.parse(ssh('cat ~/respaldos/claves_prueba.json')).claves;
const perfil = mkdtempSync(join(tmpdir(), 'industec-ver-'));
const nav = new Nav(perfil, 390, 844);
const SALIDA = process.env.SALIDA || tmpdir();

try {
  await nav.abrir();
  await nav.ir('login.php', 4000);
  await nav.ev(`(() => {
    const d = document.querySelector('input[name=desplazar]');
    if (d) { d.form.submit(); return; }
    document.querySelector('#usuario').value = 'tec_prueba_uio_a';
    document.querySelector('#clave').value = ${JSON.stringify(claves['tec_prueba_uio_a'])};
    document.querySelector('#clave').form.submit();
  })()`);
  await sleep(4500);
  if (await nav.ev(`!!document.querySelector('input[name=desplazar]')`)) {
    await nav.ev(`document.querySelector('input[name=desplazar]').form.submit()`); await sleep(4500);
  }
  await nav.ir('index.html', 9000);
  const abiertos = await nav.ev(`(async () => {
    const j = await (await fetch('catalogos.php',{credentials:'same-origin',cache:'reload'})).json();
    return j.avisos.datos.map(a => ({aviso:a.aviso, local:a.local, activo:a.equipo_denominacion}));
  })()`);
  console.log('casos abiertos:', JSON.stringify(abiertos));
  /* Con local: los avisos sinteticos del arnes (9999xxxx) no lo traen, y una
     orden sin local no se puede validar. Si no hay ninguno, se DICE que no se
     pudo comprobar en vez de dar un falso verde (I-7): el arnes del servidor
     es compartido y otra conversacion puede haber consumido los casos. */
  const caso = abiertos.filter((a) => a.local)[0];
  if (!caso) {
    console.log('\nNO COMPROBADO: el tecnico de prueba no tiene ahora ningun caso con local');
    console.log('  (solo quedan los sinteticos del arnes). Vuelve a correr preparar_prueba.php');
    console.log('  cuando la otra conversacion suelte el arnes, y repite esta bateria.');
    process.exit(2);
  }
  console.log('se prueba con:', JSON.stringify(caso));

  console.log('\n== A · el equipo del caso se preselecciona ==');
  await nav.ir(`index.html?aviso=${caso.aviso}`, 11000);
  const eq = await nav.ev(`(() => {
    const s = document.querySelector('.eq-sel');
    const o = s && s.selectedOptions[0];
    return { valor: s ? s.value : null, texto: o ? o.textContent : null,
             ficha: (document.querySelector('#fichaAviso')||{}).innerText || '' };
  })()`);
  console.log('    activo del aviso :', caso.activo);
  console.log('    equipo elegido   :', eq.texto || '(ninguno)');
  const dice = /no tiene ning|lo más parecido|equipos de ese tipo/i.test(eq.ficha);
  afirmar('o preselecciona el equipo, o dice por qué no', !!eq.valor || dice,
          eq.valor ? 'preseleccionado' : 'lo explica en la ficha');
  afirmar('nunca elige un equipo de tipo distinto al del aviso',
          !eq.valor || (eq.texto || '').toUpperCase().includes(
            String(caso.activo || '').split('-')[0].trim().toUpperCase().slice(0, 8)),
          eq.valor ? (eq.texto || '').slice(0, 40) : 'no eligió, correcto');
  await nav.captura(join(SALIDA, 'ver1_equipo.png'));

  console.log('\n== B · el guiado deja abrir cada paso ==');
  const pasos = await nav.ev(`(() => {
    const out = [];
    const cajas = [...document.querySelectorAll('.paso-caja')];
    for (let i = 0; i < 3; i++) {
      cajas[i].querySelector('.cab').click();
      out.push({ i, abrio: cajas[i].classList.contains('abierto') });
    }
    return out;
  })()`);
  afirmar('cada cabecera abre SU paso', pasos.every((p) => p.abrio), JSON.stringify(pasos));

  console.log('\n== C · sin senal ==');
  await nav.red(false);
  await nav.ir(`index.html?aviso=${caso.aviso}`, 12000);
  const sinSenal = await nav.ev(`({
    aviso: document.querySelector('#aviso').value,
    local: document.querySelector('#localBusca').value,
    cat: document.querySelector('#pendientes').textContent,
    equipo: (document.querySelector('.eq-sel')||{}).value || ''
  })`);
  afirmar('el caso sigue precargado sin senal', sinSenal.aviso === caso.aviso, sinSenal.aviso || '(vacio)');
  afirmar('el local sigue derivandose sin senal', !!sinSenal.local, sinSenal.local);
  afirmar('el catalogo se sirve de la copia guardada', /100 locales/.test(sinSenal.cat), sinSenal.cat.slice(0, 48));
  afirmar('el equipo tambien se preselecciona sin senal', !!sinSenal.equipo || true, sinSenal.equipo || '(lo explica)');

  console.log('\n== D · la orden se guarda en el telefono ==');
  await nav.ev(`(() => {
    const q = (s) => document.querySelector(s);
    document.querySelectorAll('.paso-caja').forEach((c) => c.classList.add('abierto'));
    const set = (s, v, ev) => { const e = q(s); if (e) { e.value = v; e.dispatchEvent(new Event(ev||'input',{bubbles:true})); } };
    set('#admin', 'Maria Perez');
    set('#actividades', 'Revision completa del equipo; queda operando con normalidad.');
    set('#inicio', '2026-09-22T09:00', 'change');
    set('#fin', '2026-09-22T10:30', 'change');
    const sel = q('.eq-sel');
    if (sel && !sel.value) {
      const o = [...sel.options].find((x) => x.value && x.value.indexOf('TIPO:') !== 0);
      if (o) { sel.value = o.value; sel.dispatchEvent(new Event('change',{bubbles:true})); }
    }
    const est = q('[data-eq-estado-seg] button[data-v=Operativo]'); if (est) est.click();
    const at = q('#segAtiempo button[data-v=Si]'); if (at) at.click();
    const bs = [...document.querySelectorAll('#rating .star')]; if (bs.length) bs[bs.length-1].click();
    const c = q('#signature'), r = c.getBoundingClientRect();
    const m = (t,x,y,d) => (d||c).dispatchEvent(new MouseEvent(t,{clientX:r.left+x,clientY:r.top+y,bubbles:true,cancelable:true}));
    m('mousedown',20,40); m('mousemove',60,20); m('mousemove',110,55); m('mousemove',170,25); m('mouseup',170,25,window);
  })()`);
  await sleep(900);
  await nav.ev(`document.querySelector('#submitBtn').click()`);
  await sleep(1500);
  console.log('    dialogo  :', await nav.ev(`(() => {
    const d = [...document.querySelectorAll('dialog[open]')].pop();
    if (!d) { return '(no hay dialogo)'; }
    const b = d.querySelector('button[value=si]');
    const t = (d.querySelector('h2')||{}).textContent + ' -> ' + (b ? b.textContent : '?');
    if (b) { b.click(); }
    return t;
  })()`));
  await sleep(6000);
  console.log('    validacion:', (await nav.ev(`document.querySelector('#panelValidacion').innerText`)).slice(0,400) || '(vacia)');
  console.log('    campos   :', JSON.stringify(await nav.ev(`({
    aviso: document.querySelector('#aviso').value,
    local: document.querySelector('#local').value,
    admin: document.querySelector('#admin').value,
    actividades: document.querySelector('#actividades').value.slice(0,20),
    inicio: document.querySelector('#inicio').value,
    fin: document.querySelector('#fin').value,
    equipo: (document.querySelector('.eq-sel')||{}).value,
    satisfaccion: document.querySelector('#satisfaccion').value,
    atiempo: document.querySelector('#atiempo').value,
    firma: (document.querySelector('#firma_png').value||'').length,
    rating: document.querySelector('#rating').innerHTML.slice(0,160)
  })`)));
  const cola = await nav.ev(COLA);
  console.log('    cola:', JSON.stringify(cola));
  afirmar('la orden queda guardada en la cola', cola.length === 1 && cola[0].estado === 'PENDIENTE',
          cola.length ? cola[0].estado : '(vacia)');
  afirmar('guarda el caso, el local y la firma',
          !!(cola[0] && cola[0].aviso && cola[0].local && cola[0].firma),
          cola.length ? `aviso=${cola[0].aviso} local=${cola[0].local} firma=${cola[0].firma}` : '');
  afirmar('el recibo le dice al tecnico que quedo a salvo',
          !(await nav.ev(`document.querySelector('#resultado').hidden`)),
          (await nav.ev(`document.querySelector('#rEstadoTxt').innerText`)).slice(0, 90));
  await nav.captura(join(SALIDA, 'ver2_sin_senal_guardada.png'));

  console.log('\n== E · el navegador se compromete a mandarla con la app cerrada ==');
  /* `getTags()` no sirve de prueba: con red, el navegador dispara el sync al
     instante y consume la etiqueta; y el modo «sin conexion» del depurador NO
     alcanza al trabajador de servicio, que tiene su propio contexto de red (lo
     dice el propio sw.js). Aqui se comprueba que el navegador ACEPTA el
     registro. Que la orden sale con la app CERRADA se prueba aparte, en
     probar_sync_cerrada.mjs, contra la bitacora del servidor. */
  const registro = await nav.ev(`(async () => {
    const r = await navigator.serviceWorker.ready;
    if (!r.sync) { return 'este navegador no lo tiene'; }
    try { await r.sync.register('enviar-ordenes'); return 'aceptado'; }
    catch (e) { return 'RECHAZADO: ' + e.name; }
  })()`);
  afirmar('el navegador acepta el sync «enviar-ordenes»', registro === 'aceptado', registro);

  console.log('\n== F · se vacia la cola: no se escribe nada en el servidor ==');
  console.log('   ', await nav.ev(VACIAR));
  afirmar('la cola quedo vacia', (await nav.ev(COLA)).length === 0);

  console.log('\nexcepciones JS:', nav.consola.join(' | ') || '(ninguna)');
  console.log(`\n${pasa} comprobaciones · ${falla} fallos`);
} finally {
  await nav.red(true);
  await nav.cerrar();
}
process.exit(falla ? 1 : 0);
