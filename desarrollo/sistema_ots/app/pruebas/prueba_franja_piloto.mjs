/* prueba_franja_piloto.mjs — La franja del piloto, en un navegador de verdad.

   POR QUÉ EXISTE (revisión del 28-sep-2026)
   Del 24 al 27-sep los técnicos de UIO tomaron por reales 14 OT del piloto. La
   franja naranja del formulario es lo que ahora se lo dice antes de enviar, y
   hasta aquí solo se había comprobado leyendo el código. Esta prueba abre
   index.html en Chrome sin interfaz, con `yo.php` contestado por un router que
   no toca ninguna base (router_franja_piloto.php), y mira el DOM:

     1. PRUEBA        la franja de arriba y la de junto al botón se ven, con el
                      texto que nombra el referente («solo esa llega a Grupo KFC»)
     2. PRODUCCION    las dos quedan escondidas
     3. PRUEBA con el vocabulario_publico.json de ANTES (sin OT_PILOTO): la
                      franja sale igual y el formulario no se cae (antes,
                      UI.T.titulo lanzaba en medio de la carga)
     4. PRUEBA SIN SERVIDOR: se apaga el servidor y se recarga; yo.php sale de
                      la caché del trabajador de servicio y la franja sigue

   node pruebas/prueba_franja_piloto.mjs          (sale con 1 si algo falla) */
import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const AQUI = dirname(fileURLToPath(import.meta.url));
const PUB = join(AQUI, '..', 'publico');
const ROUTER = join(AQUI, 'router_franja_piloto.php');
const PHP = process.env.PHP || 'D:/SOFTWARE/PHP83/php.exe';
const CHROME = process.env.CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let total = 0, fallos = 0;
function afirmar(que, ok, detalle = '') {
  total++; if (!ok) fallos++;
  console.log(`  ${ok ? 'ok   ' : 'FALLA'} ${que}${ok || !detalle ? '' : '  → ' + detalle}`);
}

function servidor(puerto, env) {
  return spawn(PHP, ['-S', `127.0.0.1:${puerto}`, '-t', PUB, ROUTER],
               { stdio: 'ignore', env: { ...process.env, ...env } });
}

async function navegador() {
  const perfil = mkdtempSync(join(tmpdir(), 'franja-'));
  const cdp = 9700 + Math.floor(Math.random() * 200);
  const ch = spawn(CHROME, ['--headless=new', '--disable-gpu', '--no-sandbox', '--no-first-run',
    `--user-data-dir=${perfil}`, `--remote-debugging-port=${cdp}`, 'about:blank'], { stdio: 'ignore' });
  let t;
  for (let i = 0; i < 75 && !t; i++) {
    await sleep(200);
    try { t = (await fetch(`http://127.0.0.1:${cdp}/json/list`).then((r) => r.json())).find((x) => x.type === 'page'); } catch {}
  }
  if (!t) throw new Error('Chrome no abrió el puerto de depuración');
  const ws = new WebSocket(t.webSocketDebuggerUrl);
  await new Promise((r, j) => { ws.addEventListener('open', r, { once: true }); ws.addEventListener('error', () => j(new Error('ws')), { once: true }); });
  let id = 0; const pend = new Map();
  ws.addEventListener('message', async (e) => {
    // En Node, e.data puede llegar como texto o como Blob/ArrayBuffer: el
    // «"[object Object]" is not valid JSON» de prueba_offline.mjs era esto.
    const txt = typeof e.data === 'string' ? e.data
      : (e.data && typeof e.data.text === 'function' ? await e.data.text() : Buffer.from(e.data).toString('utf8'));
    const m = JSON.parse(txt);
    if (m.id && pend.has(m.id)) { const { res, rej } = pend.get(m.id); pend.delete(m.id); m.error ? rej(new Error(m.error.message)) : res(m.result); }
  });
  const send = (method, params = {}) => new Promise((res, rej) => { const g = { id: ++id, method, params }; pend.set(g.id, { res, rej }); ws.send(JSON.stringify(g)); });
  await send('Page.enable'); await send('Runtime.enable');
  const errores = [];
  await send('Runtime.enable');
  ws.addEventListener('message', async (e) => {
    const txt = typeof e.data === 'string' ? e.data : (e.data && typeof e.data.text === 'function' ? await e.data.text() : '');
    try { const m = JSON.parse(txt); if (m.method === 'Runtime.exceptionThrown') errores.push(m.params.exceptionDetails?.exception?.description || m.params.exceptionDetails?.text); } catch {}
  });
  await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 800, deviceScaleFactor: 2, mobile: true });
  const ev = async (x) => (await send('Runtime.evaluate', { expression: x, awaitPromise: true, returnByValue: true })).result?.value;
  const cerrar = () => { try { ws.close(); } catch {} try { ch.kill('SIGKILL'); } catch {} setTimeout(() => { try { rmSync(perfil, { recursive: true, force: true }); } catch {} }, 1500); };
  return { send, ev, cerrar, errores };
}

const LEER = `JSON.stringify({
  arriba: (function(){ var f=document.getElementById('franjaPiloto'); return f ? { hidden: f.hidden, txt: (document.getElementById('franjaPilotoTxt')||{}).textContent||'' } : null; })(),
  enviar: (function(){ var f=document.getElementById('franjaPilotoEnviar'); return f ? { hidden: f.hidden, txt: (document.getElementById('franjaPilotoEnviarTxt')||{}).textContent||'' } : null; })(),
  version: (window.UI && UI.T && UI.T.version) ? UI.T.version() : null,
  titulo: (function(){ try { return UI.T.titulo('OT_PILOTO'); } catch (e) { return 'LANZA: ' + e.message; } })()
})`;

async function caso(nombre, env, comprobar) {
  console.log(`\n=== ${nombre} ===`);
  const puerto = 8300 + Math.floor(Math.random() * 400);
  const srv = servidor(puerto, env);
  await sleep(1200);
  const b = await navegador();
  try {
    await b.send('Page.navigate', { url: `http://127.0.0.1:${puerto}/index.html` });
    await sleep(6000);
    const d = JSON.parse((await b.ev(LEER)) || '{}');
    await comprobar(d, b, srv, puerto);
  } catch (e) {
    afirmar(`${nombre}: sin excepción en la prueba`, false, e.message);
  } finally {
    b.cerrar(); try { srv.kill('SIGKILL'); } catch {}
    await sleep(800);
  }
}

const REFERENTE = 'solo esa llega a Grupo KFC y es la que vale';

await caso('1. modo PRUEBA', { FRANJA_MODO: 'PRUEBA' }, async (d, b) => {
  afirmar('la franja de arriba se ve', d.arriba && d.arriba.hidden === false, JSON.stringify(d.arriba));
  afirmar('  … dice que NO llega a Grupo KFC', (d.arriba?.txt || '').includes('NO llega a Grupo KFC'), d.arriba?.txt);
  afirmar('  … y nombra el referente («' + REFERENTE + '»)', (d.arriba?.txt || '').includes(REFERENTE), d.arriba?.txt);
  afirmar('  … con el título del diccionario', (d.arriba?.txt || '').startsWith('OT INDUSTEC del piloto.'), d.arriba?.txt);
  afirmar('la de junto al botón de enviar también se ve', d.enviar && d.enviar.hidden === false, JSON.stringify(d.enviar));
  afirmar('  … y pide emitirla por el formulario de siempre', (d.enviar?.txt || '').includes('formulario de siempre'), d.enviar?.txt);
  afirmar('sin excepciones en la página', b.errores.length === 0, b.errores.join(' | '));
});

await caso('2. modo PRODUCCION', { FRANJA_MODO: 'PRODUCCION' }, async (d, b) => {
  afirmar('la franja de arriba queda escondida', d.arriba && d.arriba.hidden === true, JSON.stringify(d.arriba));
  afirmar('la de junto al botón también', d.enviar && d.enviar.hidden === true, JSON.stringify(d.enviar));
  afirmar('sin excepciones en la página', b.errores.length === 0, b.errores.join(' | '));
});

await caso('3. modo PRUEBA con el vocabulario de antes (sin OT_PILOTO)', { FRANJA_MODO: 'PRUEBA', FRANJA_VOC_VIEJO: '1' }, async (d, b) => {
  afirmar('ui.js aceptó el vocabulario viejo (2026-09-27.2)', d.version === '2026-09-27.2', d.version);
  afirmar('UI.T.titulo(OT_PILOTO) sale del respaldo, sin lanzar', d.titulo === 'OT INDUSTEC del piloto', d.titulo);
  afirmar('la franja se ve igual', d.arriba && d.arriba.hidden === false && (d.arriba.txt || '').includes(REFERENTE), JSON.stringify(d.arriba));
  afirmar('sin excepciones en la página', b.errores.length === 0, b.errores.join(' | '));
});

await caso('4. modo PRUEBA SIN SERVIDOR (yo.php desde la caché)', { FRANJA_MODO: 'PRUEBA' }, async (d, b, srv, puerto) => {
  afirmar('con servidor: la franja se ve', d.arriba && d.arriba.hidden === false, JSON.stringify(d.arriba));
  const sw = await b.ev(`(async()=>{ const r = await navigator.serviceWorker.getRegistration(); const cs = await caches.keys();
      const dat = cs.find(n=>n.endsWith('datos')); const g = dat ? (await (await caches.open(dat)).keys()).map(k=>new URL(k.url).pathname) : [];
      return JSON.stringify({ activo: !!(r && r.active), datos: g }); })()`);
  const s = JSON.parse(sw || '{}');
  afirmar('el trabajador de servicio quedó activo', s.activo === true, sw);
  afirmar('  … con yo.php guardado', (s.datos || []).includes('/yo.php'), sw);
  srv.kill('SIGKILL');
  await sleep(2000);
  const vivo = await fetch(`http://127.0.0.1:${puerto}/index.html`, { signal: AbortSignal.timeout(2000) }).then(() => true).catch(() => false);
  afirmar('el servidor está apagado de verdad', vivo === false);
  await b.send('Page.reload');
  await sleep(9000);
  const o = JSON.parse((await b.ev(LEER)) || '{}');
  afirmar('sin servidor: la franja de arriba sigue', o.arriba && o.arriba.hidden === false && (o.arriba.txt || '').includes(REFERENTE), JSON.stringify(o.arriba));
  afirmar('sin servidor: la de junto al botón sigue', o.enviar && o.enviar.hidden === false, JSON.stringify(o.enviar));
});

console.log(`\n${total} comprobaciones · ${fallos} fallos`);
process.exit(fallos === 0 ? 0 : 1);
