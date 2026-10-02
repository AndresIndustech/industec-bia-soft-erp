/* Prueba REAL (Chrome) de que un envío colgado no bloquea la cola del técnico
   (T2.29.10, 1-oct-2026).

     node prueba_cola_timeout.mjs <ruta/a/cola.js>

   POR QUÉ EXISTE. El 30-sep la OT de Anthony Morales tardó 16 minutos en llegar
   y entre la primera foto (12:55:46) y la segunda (13:11:04) el servidor no vio
   ninguna petición. Causa probable: una petición colgada que `fetch` no corta
   nunca; mientras no termina, `enviando` queda en true y `enviarTodo()` rechaza
   todo reintento. Aquí se reproduce: un servidor de verdad cuya PRIMERA subida
   de foto nunca contesta, y se mira si la cola se recupera sola.

   Con el cola.js de antes (sin tiempo límite) la cola se queda trabada y esta
   prueba FALLA; con el nuevo se recupera y PASA. Se pasa la ruta del archivo para
   poder correr las dos versiones con el mismo montaje.

   Sin base ni PHP: un servidor de Node que sirve la página, `cola.js` y cuatro
   puntos (yo.php, foto.php, envio.php y un contador). Los tiempos del archivo
   (120 s) se acortan a 1,5 s por `Cola.tiempos`, si existe. */
import http from 'node:http';
import { readFileSync } from 'node:fs';
import { spawn } from 'node:child_process';

const rutaCola = process.argv[2];
if (!rutaCola) { console.error('uso: node prueba_cola_timeout.mjs <cola.js>'); process.exit(2); }
const colaJs = readFileSync(rutaCola);
const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const cdp = 9700 + Math.floor(Math.random() * 90);
const puerto = 8300 + Math.floor(Math.random() * 90);
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
const parar = setTimeout(() => { console.error('timeout de la prueba'); process.exit(1); }, 60000);

let fotos = 0, envios = 0;
const colgadas = [];
const PAGINA = `<!doctype html><meta charset="utf-8"><div id="cola" hidden></div>
<script>
  window.UI = { T: function (k) { return k; }, toast: function () {}, confirmar: function () {} };
  window.UI.T.titulo = function (k) { return k; };
</script>
<script src="/cola.js"></script>`;

const servidor = http.createServer((req, res) => {
  const ruta = new URL(req.url, 'http://x').pathname;
  const json = (o) => { res.writeHead(200, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(o)); };
  if (ruta === '/pagina.html') { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); return res.end(PAGINA); }
  if (ruta === '/cola.js') { res.writeHead(200, { 'Content-Type': 'text/javascript' }); return res.end(colaJs); }
  if (ruta === '/yo.php') return json({ csrf: 'token-de-prueba' });
  if (ruta === '/foto.php') {
    req.resume();
    fotos++;
    // La PRIMERA subida nunca contesta: es la señal «4G» que no deja pasar nada.
    if (fotos === 1) { colgadas.push(res); return; }
    return req.on('end', () => json({ ok: true, foto_uuid: 'x' }));
  }
  if (ruta === '/envio.php') {
    envios++;
    req.resume();
    return req.on('end', () => json({ ok: true, recibo: { estado: 'EMITIDA', id_industec: 'OT-0001-T001EC-UIO', numero: 1 } }));
  }
  res.writeHead(404); res.end();
});
await new Promise(r => servidor.listen(puerto, '127.0.0.1', r));

const chrome = spawn(CHROME, ['--headless=new', '--disable-gpu', '--no-sandbox',
  `--remote-debugging-port=${cdp}`, 'about:blank'], { stdio: 'ignore' });
let ws, id = 0; const pend = new Map();
const send = (m, p = {}) => new Promise((res, rej) => {
  const g = { id: ++id, method: m, params: p }; pend.set(g.id, { res, rej }); ws.send(JSON.stringify(g));
});
const ev = async (x) => (await send('Runtime.evaluate', { expression: x, awaitPromise: true, returnByValue: true })).result?.value;
const limpiar = (c) => {
  clearTimeout(parar);
  try { chrome.kill('SIGKILL'); } catch {}
  for (const r of colgadas) { try { r.destroy(); } catch {} }
  try { servidor.close(); servidor.closeAllConnections?.(); } catch {}
  process.exit(c);
};

try {
  let t;
  for (let i = 0; i < 60 && !t; i++) {
    await sleep(200);
    try { t = (await fetch(`http://127.0.0.1:${cdp}/json/list`).then(r => r.json())).find(x => x.type === 'page'); } catch {}
  }
  ws = new WebSocket(t.webSocketDebuggerUrl);
  await new Promise((r, j) => { ws.addEventListener('open', r, { once: true }); ws.addEventListener('error', () => j(new Error('ws')), { once: true }); });
  ws.addEventListener('message', async e => {
    const m = JSON.parse(typeof e.data === 'string' ? e.data : await e.data.text());
    if (m.id && pend.has(m.id)) { const { res, rej } = pend.get(m.id); pend.delete(m.id); m.error ? rej(new Error(m.error.message)) : res(m.result); }
  });
  await send('Page.enable'); await send('Runtime.enable');
  await send('Page.navigate', { url: `http://127.0.0.1:${puerto}/pagina.html` });
  await sleep(1500);

  console.log(`cola.js: ${rutaCola}`);
  const conLimite = await ev(`!!(window.Cola && Cola.tiempos)`);
  console.log('  tiene tiempo límite (Cola.tiempos):', conLimite ? 'SI' : 'NO (versión de antes)');

  // 1. Se encola una orden con una foto. El primer foto.php se cuelga.
  await ev(`(async () => {
    if (Cola.tiempos) { Cola.tiempos.foto = 1500; Cola.tiempos.orden = 1500; Cola.tiempos.sesion = 1500; }
    const blob = new Blob([new Uint8Array(2000)], { type: 'image/jpeg' });
    await Cola.encolar({ local: 'T001', aviso: '10000001', tipo: 'CORRECTIVO' }, 900, [{ uuid: crypto.randomUUID(), blob }]);
    return 'ok';
  })()`);
  await sleep(1000);
  console.log(`  t=1,0 s: foto.php recibió ${fotos} petición (la primera no contesta); envio.php ${envios}`);

  // 2. Pasa más que el tiempo límite (1,5 s) y llega el «próximo intento» (cada 2 min en la app real).
  await sleep(2500);
  await ev(`Cola.enviarTodo().then(() => 'ok')`);
  // 3. Se le da tiempo a recuperarse.
  await sleep(2500);
  const pendientes = JSON.parse(await ev(`Cola.pendientes().then(p => JSON.stringify(p.map(f => ({ estado: f.estado, intentos: f.intentos, error: f.ultimo_error }))))`) || '[]');
  console.log(`  t=6,0 s: foto.php ${fotos} peticiones · envio.php ${envios} · pendientes en la cola: ${pendientes.length}`
              + (pendientes.length ? ' ' + JSON.stringify(pendientes) : ''));

  const recuperada = pendientes.length === 0 && envios === 1 && fotos >= 2;
  console.log('\n' + (recuperada ? 'LA COLA SE RECUPERÓ: la OT salió después de la petición colgada'
                                  : 'LA COLA QUEDÓ TRABADA: la petición colgada impidió todo reintento'));
  limpiar(recuperada ? 0 : 1);
} catch (e) { console.error('ERR', e.message); limpiar(1); }
