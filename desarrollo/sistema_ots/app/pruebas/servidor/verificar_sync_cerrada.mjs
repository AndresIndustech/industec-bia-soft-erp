/* verificar_sync_cerrada.mjs — Que la orden salga sola con la app CERRADA (T2.26).

   POR QUE EXISTE
   Hasta la v13, `cola.js` reintentaba al cargar, al volver la senal, al volver
   a la pestana y cada dos minutos: todo eso SOLO mientras la aplicacion
   siguiera abierta. El tecnico que llena la orden en la cocina, bloquea el
   telefono y se va al siguiente local no cumple ninguna de las cuatro. Ahora
   `cola.js` registra un `sync` y `sw.js` lo atiende. Esto lo comprueba.

   COMO, SIN ESCRIBIR NADA EN EL SERVIDOR
   Se encola a proposito una orden INVALIDA (sin local, sin equipos): envio.php
   valida y responde 400 ANTES de insertar en `ot_capturadas`, asi que se
   recorre el camino entero —despertar, pedir el token, hacer el POST, tratar
   la respuesta— sin crear ninguna orden ni gastar un correlativo. Solo queda
   una fila de bitacora `ENVIO_RECHAZADO`, que es justamente la evidencia.

   LA COMPROBACION DECISIVA es esa fila de bitacora, contada por `envio_uuid`
   MIENTRAS no hay ninguna pantalla abierta: si el servidor la registro, la
   mando el trabajador de servicio y nadie mas. Se cuenta por UUID y no por
   hora porque la bitacora guarda hora de Ecuador y el `date` del servidor da
   UTC — cinco horas de diferencia, y el filtro por hora no devolvia nada.

   Necesita Chrome o Edge (usa ServiceWorker.dispatchSyncEvent del protocolo de
   depuracion) y ~/respaldos/preparar_prueba.php corrido.

   Uso:  node verificar_sync_cerrada.mjs
   Variables: INDUSTEC_LLAVE_SSH */
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const HOST = 'darkviolet-armadillo-872352.hostingersite.com';
const BASE = `https://${HOST}/ot/`;
const NAVEGADOR = [
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
].find(existsSync);
const LLAVE = 'D:/INDUSTECH IA/desarrollo/agentes/config/clave_hostinger';
const SSH = ['-i', LLAVE, '-o', 'IdentitiesOnly=yes', '-p', '65002', '-o', 'BatchMode=yes',
             '-o', 'ConnectTimeout=20', '-o', 'StrictHostKeyChecking=accept-new', 'u671729428@82.25.73.181'];
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const ssh = (cmd) => {
  const r = spawnSync('ssh', [...SSH, cmd], { encoding: 'utf8', timeout: 120000 });
  if (r.status !== 0) { throw new Error('ssh: ' + (r.stderr || '').trim().slice(0, 300)); }
  return r.stdout;
};

let pasa = 0, falla = 0;
const afirmar = (que, ok, det) => { (ok ? pasa++ : falla++); console.log(`  ${ok ? 'PASA ' : 'FALLA'} ${que.padEnd(56)} ${det ?? ''}`); };

const perfil = mkdtempSync(join(tmpdir(), 'industec-swsync-'));
const puerto = 9700 + Math.floor(Math.random() * 250);
const proc = spawn(NAVEGADOR, ['--headless=new', '--disable-gpu', '--no-first-run',
  '--no-default-browser-check', '--disable-extensions',
  `--user-data-dir=${perfil}`, `--remote-debugging-port=${puerto}`,
  '--window-size=390,844', 'about:blank'], { stdio: 'ignore' });

let pagina;
for (let i = 0; i < 120 && !pagina; i++) {
  await sleep(200);
  try {
    pagina = (await fetch(`http://127.0.0.1:${puerto}/json/list`).then((r) => r.json()))
      .find((x) => x.type === 'page');
  } catch { /* aun no */ }
}
const ws = new WebSocket(pagina.webSocketDebuggerUrl);
let id = 0; const pend = new Map();
let registroId = null, ambito = null;
await new Promise((ok) => ws.addEventListener('open', ok, { once: true }));
ws.addEventListener('message', (e) => {
  const m = JSON.parse(e.data);
  if (m.id && pend.has(m.id)) { const { ok } = pend.get(m.id); pend.delete(m.id); ok(m); return; }
  if (m.method === 'ServiceWorker.workerRegistrationUpdated') {
    for (const r of m.params.registrations || []) {
      if (!r.isDeleted) { registroId = r.registrationId; ambito = r.scopeURL; }
    }
  }
});
const send = (method, params = {}) => new Promise((ok) => { const i = ++id; pend.set(i, { ok }); ws.send(JSON.stringify({ id: i, method, params })); });
const ev = async (expr) => {
  const r = await send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
  if (r.result?.exceptionDetails) { return 'EXCEPCION: ' + (r.result.exceptionDetails.exception?.description || r.result.exceptionDetails.text); }
  return r.result?.result?.value;
};
await send('Page.enable'); await send('Runtime.enable'); await send('ServiceWorker.enable');

const claves = JSON.parse(ssh('cat ~/respaldos/claves_prueba.json')).claves;
await send('Page.navigate', { url: BASE + 'login.php' }); await sleep(4000);
await ev(`(() => {
  const d = document.querySelector('input[name=desplazar]');
  if (d) { d.form.submit(); return; }
  document.querySelector('#usuario').value = 'tec_prueba_uio_a';
  document.querySelector('#clave').value = ${JSON.stringify(claves['tec_prueba_uio_a'])};
  document.querySelector('#clave').form.submit();
})()`);
await sleep(4500);
if (await ev(`!!document.querySelector('input[name=desplazar]')`)) {
  await ev(`document.querySelector('input[name=desplazar]').form.submit()`); await sleep(4500);
}
await send('Page.navigate', { url: BASE + 'index.html' }); await sleep(10000);
console.log('sw v' + String(await ev(`(async () => (await (await fetch('sw.js')).text()).match(/ot-industec-v\\d+/)[0])()`)));
console.log('registro:', registroId, ambito);

// Una orden que envio.php va a rechazar: sin local, sin equipos, sin firma.
const UUID = await ev(`(async () => {
  const bd = await new Promise((ok) => { const p = indexedDB.open('ot-industec',1); p.onsuccess = () => ok(p.result); });
  const uuid = (crypto.randomUUID ? crypto.randomUUID() : 'prueba-' + Date.now());
  const fila = { uuid, creado: new Date().toISOString(), estado: 'PENDIENTE', intentos: 0,
                 ultimo_error: null, usuario_id: null,
                 resumen: { local: '', aviso: '', caso: 'prueba del sync' },
                 orden: { tipo: 'CORRECTIVO', origen: 'SIN_ASIGNAR', local: '', equipos: [] },
                 fotos: [] };
  await new Promise((ok) => { const t = bd.transaction('cola','readwrite').objectStore('cola').put(fila); t.onsuccess = () => ok(); });
  return uuid;
})()`);
console.log('orden de prueba encolada:', UUID);

// Se cierra la aplicacion: ninguna pantalla del ambito queda abierta.
await send('Page.navigate', { url: 'about:blank' });
await sleep(3000);
const clientes = await (async () => {
  const lista = await fetch(`http://127.0.0.1:${puerto}/json/list`).then((r) => r.json());
  return lista.filter((t) => t.type === 'page' && t.url.includes('/ot/')).length;
})();
afirmar('no queda ninguna pantalla de la app abierta', clientes === 0, `pantallas=${clientes}`);

// Vuelve la senal: el navegador dispara el sync.
const antes = ssh("date '+%Y-%m-%d %H:%M:%S'").trim();
const disp = await send('ServiceWorker.dispatchSyncEvent', {
  origin: `https://${HOST}`, registrationId: String(registroId),
  tag: 'enviar-ordenes', lastChance: false,
});
console.log('dispatchSyncEvent:', JSON.stringify(disp.error || disp.result || {}));
await sleep(9000);

/* LA PRUEBA DECISIVA: que el servidor haya recibido el POST MIENTRAS no habia
   ninguna pantalla abierta. Se cuenta por UUID —unico de esta corrida— y se
   mira ANTES de reabrir nada, asi que si hay registro solo pudo mandarlo el
   trabajador de servicio. Por UUID y no por hora: la bitacora guarda hora de
   Ecuador y el `date` del servidor da UTC, cinco horas de diferencia. */
const contar = () => Number(JSON.parse(ssh(
  `cd domains/${HOST}/public_html/ot && U=${JSON.stringify(UUID)} php -r ` +
  JSON.stringify("require 'nucleo/Db.php'; echo json_encode(Db::uno(\"SELECT COUNT(*) n FROM bitacora WHERE referencia = ?\", [getenv('U')]));")
)).n);
const conLaAppCerrada = contar();
console.log('POST que registro el servidor con la app cerrada:', conLaAppCerrada);
afirmar('el servidor recibio el POST sin ninguna pantalla abierta',
        conLaAppCerrada >= 1, `${conLaAppCerrada} registro(s) para ${UUID.slice(0, 8)}`);

// Se reabre para leer como quedo la fila.
await send('Page.navigate', { url: BASE + 'index.html' }); await sleep(9000);
const fila = await ev(`(async () => {
  const bd = await new Promise((ok) => { const p = indexedDB.open('ot-industec',1); p.onsuccess = () => ok(p.result); });
  const f = await new Promise((ok) => { const t = bd.transaction('cola','readonly').objectStore('cola').get(${JSON.stringify(UUID)}); t.onsuccess = () => ok(t.result); });
  return f ? { estado: f.estado, intentos: f.intentos, error: f.ultimo_error } : null;
})()`);
console.log('la fila quedo:', JSON.stringify(fila));
afirmar('el trabajador de servicio la intento con la app cerrada',
        !!fila && fila.intentos >= 1, fila ? `intentos=${fila.intentos} error=${fila.error}` : 'no está');
/* Al reabrir, la pantalla vuelve a mandarla y es ELLA la que fija el veredicto
   con el motivo detallado del servidor: el trabajador de servicio no inventa
   dictamenes, solo entrega. Reenviar no duplica nada porque envio.php es
   idempotente por envio_uuid. */
afirmar('al reabrir, la pantalla fija el veredicto con el motivo del servidor',
        !!fila && fila.estado === 'RECHAZADA' && /sin local/.test(fila.error || ''),
        fila ? `${fila.estado}: ${(fila.error||'').slice(0,46)}` : '');

console.log('se limpia:', await ev(`(async () => {
  const bd = await new Promise((ok) => { const p = indexedDB.open('ot-industec',1); p.onsuccess = () => ok(p.result); });
  return await new Promise((ok) => { const t = bd.transaction('cola','readwrite').objectStore('cola').clear(); t.onsuccess = () => ok('cola vaciada'); });
})()`));

console.log(`\n${pasa} comprobaciones · ${falla} fallos`);
try { proc.kill(); } catch { /* nada */ }
process.exit(falla ? 1 : 0);
