/* Prueba REAL de modo sin conexión: levanta el servidor, deja que la app se
   guarde, MATA EL SERVIDOR y recarga.
   node prueba_offline.mjs <carpeta> <puerto>

   Por qué así y no con Network.emulateNetworkConditions: ese comando corta la
   red de la PÁGINA, pero el trabajador de servicio tiene su propio contexto y
   sigue teniendo internet. La primera versión de esta prueba daba "funciona sin
   conexión" cuando en realidad el servidor seguía respondiendo. */
import { spawn } from 'node:child_process';

const [carpeta, puertoArg] = process.argv.slice(2);
const puerto = parseInt(puertoArg, 10) || 8099;
const url = `http://127.0.0.1:${puerto}/index.html`;
const PHP = 'D:/SOFTWARE/PHP83/php.exe';
const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const cdp = 9600 + Math.floor(Math.random() * 90);
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
const stop = setTimeout(() => { console.error('timeout'); process.exit(1); }, 120000);

const servidor = spawn(PHP, ['-S', `127.0.0.1:${puerto}`, '-t', carpeta], { stdio: 'ignore' });
await sleep(1500);

const chrome = spawn(CHROME, ['--headless=new', '--disable-gpu', '--no-sandbox',
  `--remote-debugging-port=${cdp}`, 'about:blank'], { stdio: 'ignore' });

let ws, id = 0; const pend = new Map();
const send = (m, p = {}) => new Promise((res, rej) => {
  const g = { id: ++id, method: m, params: p }; pend.set(g.id, { res, rej }); ws.send(JSON.stringify(g));
});
const ev = async (x) => (await send('Runtime.evaluate',
  { expression: x, awaitPromise: true, returnByValue: true })).result?.value;

const limpiar = (c) => { clearTimeout(stop); try { chrome.kill('SIGKILL'); } catch {} try { servidor.kill('SIGKILL'); } catch {} process.exit(c); };

try {
  let t;
  for (let i = 0; i < 60 && !t; i++) {
    await sleep(200);
    try { t = (await fetch(`http://127.0.0.1:${cdp}/json/list`).then(r => r.json())).find(x => x.type === 'page'); } catch {}
  }
  ws = new WebSocket(t.webSocketDebuggerUrl);
  await new Promise((r, j) => { ws.addEventListener('open', r, { once: true }); ws.addEventListener('error', () => j(new Error('ws')), { once: true }); });
  ws.addEventListener('message', e => {
    const m = JSON.parse(e.data);
    if (m.id && pend.has(m.id)) { const { res, rej } = pend.get(m.id); pend.delete(m.id); m.error ? rej(new Error(m.error.message)) : res(m.result); }
  });
  await send('Page.enable'); await send('Runtime.enable');
  await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 800, deviceScaleFactor: 2, mobile: true });

  console.log('1. Primera visita, con el servidor ENCENDIDO');
  await send('Page.navigate', { url });
  await sleep(7000);
  const d = JSON.parse(await ev(`(async()=>{
    const r = await navigator.serviceWorker.getRegistration();
    const cs = await caches.keys();
    const arm = cs.find(n=>n.endsWith('armazon'));
    const g = arm ? (await (await caches.open(arm)).keys()).map(k=>new URL(k.url).pathname.split('/').pop()) : [];
    const dat = cs.find(n=>n.endsWith('datos'));
    const gd = dat ? (await (await caches.open(dat)).keys()).map(k=>new URL(k.url).pathname.split('/').pop()) : [];
    return JSON.stringify({sw:!!(r&&r.active), armazon:g, datos:gd});
  })()`) || '{}');
  console.log('   service worker activo :', d.sw ? 'SI' : 'NO');
  console.log('   armazon guardado      :', (d.armazon || []).join(', '));
  console.log('   datos guardados       :', (d.datos || []).join(', ') || 'ninguno');

  console.log('\n2. APAGANDO EL SERVIDOR de verdad...');
  servidor.kill('SIGKILL');
  await sleep(2500);
  const vivo = await fetch(url, { signal: AbortSignal.timeout(2500) }).then(() => true).catch(() => false);
  console.log('   ¿el servidor responde?:', vivo ? 'SI (la prueba no vale)' : 'NO — está caído');

  console.log('\n3. Recargando la app SIN servidor');
  await send('Page.reload');
  await sleep(8000);
  const o = JSON.parse(await ev(`JSON.stringify({
    titulo: document.title,
    Reglas: typeof window.Reglas,
    tecnicos: (document.querySelector('#tecSesion')||{}).length ?? -1,
    locales: (document.querySelector('#pendientes')||{}).textContent||'',
    canvas: !!document.querySelector('#signature'),
    marca: window.__datosDesdeCache || null,
    franja: ((document.querySelector('#estadoRed')||{}).textContent||'').trim()
  })`) || '{}');
  console.log('   titulo           :', o.titulo || '(en blanco)');
  console.log('   Reglas cargado   :', o.Reglas);
  console.log('   opciones tecnico :', o.tecnicos);
  console.log('   canvas de firma  :', o.canvas ? 'SI' : 'NO');
  console.log('   pie              :', (o.locales || '').slice(0, 85) || '(vacio)');
  console.log('   datos desde cache:', o.marca || 'no');
  console.log('   franja de aviso  :', o.franja || '(no aparece)');

  const ok = o.Reglas === 'object' && o.tecnicos > 1 && o.canvas && !vivo;
  const avisa = !!o.franja;
  console.log('\n' + (ok ? 'FUNCIONA SIN CONEXION' : 'NO funciona sin conexion'));
  console.log(avisa ? 'Y AVISA de que los datos son guardados' : 'PERO NO AVISA de que los datos son viejos');
  limpiar(ok && avisa ? 0 : 1);
} catch (e) { console.error('ERR', e.message); limpiar(1); }
