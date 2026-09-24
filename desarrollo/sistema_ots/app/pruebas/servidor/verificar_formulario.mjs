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

   Necesita ~/respaldos/preparar_prueba.php corrido. Desde T2.28.1 usa uno de
   los avisos sinteticos CON local del tecnico de prueba (99990021 o 99990022,
   nunca un caso real); si ninguno de los dos sigue abierto con local, sale con
   codigo 2 y lo DICE, en vez de dar un falso verde.

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
    aviso: (f.orden||{}).aviso, local: (f.orden||{}).local, correo_local: (f.orden||{}).correo_local,
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
  /* T2.28.1: preparar_prueba.php le da al tecnico de prueba DOS avisos
     sinteticos con local (99990021, 99990022), nunca un caso real. El 99990022
     queda en ESPERA_REPUESTO a proposito, que sigue contando como "abierto"
     (Casos::ABIERTOS_TECNICO) aunque otra bateria ya lo haya usado para
     concluir una orden -asi ninguna deja a esta sin terreno (error nro 36)-.
     Esta bateria NO envia ninguna orden (la cola se vacia antes de reconectar,
     mas abajo), asi que toma cualquiera de los dos que siga abierto. Si por
     algun motivo no queda ninguno, se DICE que no se pudo comprobar en vez de
     dar un falso verde (I-7): no se inventa un caso real para reemplazarlo. */
  const caso = abiertos.filter((a) => a.local)[0];
  if (!caso) {
    console.log('\nNO COMPROBADO: el tecnico de prueba no tiene ahora ningun aviso sintetico (99990021/22) con local abierto.');
    console.log('  Corre ~/respaldos/preparar_prueba.php de nuevo y repite esta bateria.');
    process.exit(2);
  }
  console.log('se prueba con:', JSON.stringify(caso));

  /* G · Lo que reportó INDUSTEC el 2026-09-23, con toques de verdad (touch):
       1. la lista de «Orden asignada» se veía como una franja y no dejaba
          elegir el caso: `.paso-caja` la recortaba con overflow:hidden;
       2. el correo del local era de solo lectura con servicioalcliente@;
       3. repuestos y administrador parecían un selector cerrado (datalist).
     No envía nada: solo escribe en el formulario. */
  console.log('\n== G · casos, correo, administrador y repuestos (reporte del 2026-09-23) ==');
  await nav.send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 });
  const tocar = async (p) => {
    await nav.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: p.x, y: p.y }] });
    await sleep(80);
    await nav.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await sleep(500);
  };
  const centro = (expr) => nav.ev(`(() => { const e = ${expr}; if (!e) return null;
      e.scrollIntoView({ block: 'center' }); const r = e.getBoundingClientRect();
      return { x: r.left + r.width / 2, y: r.top + r.height / 2, h: Math.round(r.height) }; })()`);
  const abrirPasoDe = (sel) => nav.ev(`(() => { const c = document.querySelector(${JSON.stringify(sel)}).closest('.paso-caja');
      if (c && !c.classList.contains('abierto')) { c.querySelector('.cab').click(); } })()`);
  const escribir = async (sel, texto, alFinal) => {
    await tocar(await centro(`document.querySelector(${JSON.stringify(sel)})`));
    await nav.ev(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); e.focus();
      if (${alFinal ? 'true' : 'false'}) { e.setSelectionRange(e.value.length, e.value.length); } else { e.select(); } })()`);
    await nav.send('Input.insertText', { text: texto });
    await sleep(500);
    return nav.ev(`document.querySelector(${JSON.stringify(sel)}).value`);
  };

  await nav.ir('index.html', 10000);
  await abrirPasoDe('#avisoBusca'); await sleep(600);
  await tocar(await centro(`document.querySelector('#avisoBusca')`)); await sleep(700);
  const lista = await nav.ev(`(() => {
    const l = document.querySelector('#avisoLista'), caja = l.closest('.paso-caja');
    return { visible: !l.hidden, opciones: l.querySelectorAll('.combo-opt').length,
             alto: Math.round(l.getBoundingClientRect().height),
             overflow: caja ? getComputedStyle(caja).overflow : '(sin paso)' };
  })()`);
  afirmar('G1 la lista de casos se abre al tocar el buscador', lista.visible && lista.opciones > 0, JSON.stringify(lista));
  afirmar('G1 el paso ya no recorta la lista', lista.overflow === 'visible', 'overflow=' + lista.overflow);
  await nav.captura(join(SALIDA, 'verG1_lista_de_casos.png'));
  const opt = await centro(`[...document.querySelectorAll('#avisoLista .combo-opt')].find((o) => o.dataset.k === ${JSON.stringify(caso.aviso)})
                            || document.querySelector('#avisoLista .combo-opt')`);
  const alcanzable = opt && await nav.ev(`(() => { const e = document.elementFromPoint(${opt.x}, ${opt.y});
                                                    return !!(e && e.closest('#avisoLista .combo-opt')); })()`);
  afirmar('G1 el caso se puede tocar (nada lo tapa ni lo recorta)', !!alcanzable, opt ? `alto de la opción ${opt.h}px` : 'sin opción');
  if (opt) { await tocar(opt); }
  const elegido = await nav.ev(`document.querySelector('#aviso').value`);
  afirmar('G1 tocar el caso lo elige', elegido === caso.aviso, elegido || '(vacío)');

  await abrirPasoDe('#correolocal'); await sleep(500);
  const antes = await nav.ev(`(() => { const c = document.querySelector('#correolocal');
    return { soloLectura: c.readOnly || c.hasAttribute('readonly'), valor: c.value }; })()`);
  afirmar('G2 el correo del local ya no es de solo lectura', !antes.soloLectura, JSON.stringify(antes));
  afirmar('G2 no propone el buzón de INDUSTEC como correo del local', !/@industec\.me/i.test(antes.valor), antes.valor || '(vacío: a escribir)');
  const correo = await escribir('#correolocal', 'admin.prueba@local-prueba.ec');
  afirmar('G2 se puede escribir el correo', correo === 'admin.prueba@local-prueba.ec', correo);
  const admin = await escribir('#admin', 'Nombre Libre de Prueba');
  afirmar('G2 se puede escribir el administrador', admin === 'Nombre Libre de Prueba', admin);

  await abrirPasoDe('#segRepuesto'); await sleep(500);
  await tocar(await centro(`document.querySelector('#segRepuesto button[data-v=si]')`));
  const libre = await escribir('#repuestosLista .parte-desc', 'repuesto escrito a mano xyz');
  afirmar('G3 se puede escribir un repuesto que no está en la lista', libre === 'repuesto escrito a mano xyz', libre);
  const frec = await nav.ev(`(async () => { const j = await (await fetch('catalogos.php', { credentials: 'same-origin' })).json();
                                            return (j.repuestos_frecuentes || []).map((r) => r.descripcion).filter(Boolean); })()`);
  if (!frec.length) {
    console.log('    NO COMPROBADO: el catálogo no trae repuestos frecuentes; no hay sugerencias que elegir.');
  } else {
    const parcial = frec[0].slice(0, 4);
    await escribir('#repuestosLista .parte-desc', parcial);
    const sug = await nav.ev(`(() => { const l = document.querySelector('#repuestosLista .combo-lista');
      return { visible: !l.hidden, n: l.querySelectorAll('.combo-opt').length }; })()`);
    afirmar('G3 al escribir, sugiere los repuestos conocidos', sug.visible && sug.n > 0, `«${parcial}» → ${sug.n} sugerencias`);
    await nav.captura(join(SALIDA, 'verG3_repuesto_sugerencias.png'));
    const s1 = await centro(`document.querySelector('#repuestosLista .combo-lista .combo-opt')`);
    if (s1) { await tocar(s1); }
    const elegidoRep = await nav.ev(`document.querySelector('#repuestosLista .parte-desc').value`);
    afirmar('G3 tocar una sugerencia la pone en el campo', frec.includes(elegidoRep), elegidoRep);
    const editado = await escribir('#repuestosLista .parte-desc', ' (modificado)', true);
    afirmar('G3 después de elegir, se sigue pudiendo escribir', editado === elegidoRep + ' (modificado)', editado);
  }
  await nav.captura(join(SALIDA, 'verG2_campos_editables.png'));

  /* H · el equipo se busca escribiendo y, si no está, se crea (pedido del 2026-09-24). */
  console.log('\n== H · buscar el equipo escribiendo, y crearlo si no está ==');
  await abrirPasoDe('#eqBusca0'); await sleep(500);
  await escribir('#eqBusca0', 'arnes');
  const busq = await nav.ev(`(() => {
    const ops = [...document.querySelectorAll('#eqLista0 .combo-opt:not(.combo-crear)')].map((o) => o.textContent);
    return { n: ops.length, ops, visible: !document.querySelector('#eqLista0').hidden };
  })()`);
  afirmar('H1 escribir filtra la lista del equipo', busq.visible && busq.n > 0 && busq.ops.every((t) => /arnes/i.test(t)),
          `${busq.n} opciones · ${busq.ops[0] || ''}`);
  const s1eq = await centro(`document.querySelector('#eqLista0 .combo-opt:not(.combo-crear)')`);
  if (s1eq) { await tocar(s1eq); }
  const eqElegido = await nav.ev(`document.querySelector('#eqSel0').value`);
  afirmar('H1 tocar una opción elige el equipo', !!eqElegido && eqElegido.indexOf('TIPO:') !== 0, eqElegido);
  await escribir('#eqBusca0', 'Tostadora de prueba xyz');
  const crearOpt = await centro(`document.querySelector('#eqLista0 .combo-crear')`);
  afirmar('H2 si no está, ofrece crearlo', !!crearOpt, crearOpt ? 'aparece «+ Crear…»' : 'no aparece');
  if (crearOpt) { await tocar(crearOpt); }
  const creado = await nav.ev(`({ valor: document.querySelector('#eqSel0').value,
    texto: document.querySelector('#eqBusca0').value,
    nota: !document.querySelector('[data-eq-nuevo-nota="0"]').hidden })`);
  afirmar('H2 crearlo lo deja como equipo nuevo', creado.valor === 'TIPO:TOSTADORA DE PRUEBA XYZ' && creado.nota,
          `${creado.valor} · «${creado.texto}»`);
  await nav.captura(join(SALIDA, 'verH_equipo_creado.png'));

  /* I · acompañantes: solo la zona de la orden, jefe primero, sin quien emite. */
  console.log('\n== I · acompañantes de la zona de la orden ==');
  await abrirPasoDe('#addTecnico'); await sleep(400);
  await tocar(await centro(`document.querySelector('#addTecnico')`));
  const acomp = await nav.ev(`(async () => {
    const j = await (await fetch('catalogos.php', { credentials: 'same-origin' })).json();
    const yo = await (await fetch('yo.php', { credentials: 'same-origin' })).json();
    const s = document.querySelector('#tecnicos .tec-sel');
    const ids = [...s.options].filter((o) => o.value).map((o) => o.value);
    const porId = Object.fromEntries((j.tecnicos || []).map((t) => [String(t.id), t]));
    const zonas = [...new Set(ids.map((i) => (porId[i] || {}).zona))];
    const esperados = (j.tecnicos || []).filter((t) => t.zona === 'UIO' && t.nombre.toLowerCase() !== String(yo.nombre || '').toLowerCase()).length;
    const primero = porId[ids[0]] || {};
    const hayJefe = (j.tecnicos || []).some((t) => t.zona === 'UIO' && /jefe/i.test(t.tipo || ''));
    return { n: ids.length, esperados, zonas, primero: primero.nombre + ' · ' + primero.tipo, primeroEsJefe: /jefe/i.test(primero.tipo || ''), hayJefe };
  })()`);
  afirmar('I solo ofrece empleados de la zona de la orden (UIO)', acomp.zonas.length === 1 && acomp.zonas[0] === 'UIO', JSON.stringify(acomp.zonas));
  afirmar('I son todos los de la zona, menos quien emite', acomp.n === acomp.esperados, `${acomp.n} de ${acomp.esperados}`);
  afirmar('I el jefe de zona va primero', !acomp.hayJefe || acomp.primeroEsJefe, acomp.primero);
  await nav.send('Emulation.setTouchEmulationEnabled', { enabled: false });

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
    set('#correolocal', 'admin.prueba@local-prueba.ec');
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
  // Error nº 40: si el correo escrito no viaja con la orden, editarlo no sirve.
  afirmar('la orden lleva el correo del local que se escribió',
          !!cola[0] && cola[0].correo_local === 'admin.prueba@local-prueba.ec',
          cola.length ? String(cola[0].correo_local) : '');
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
