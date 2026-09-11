// Pruebas y capturas del sitio en Edge sin ventana, a 390 y 1440 px (y la portada a 1024).
// Nació como arnés de la integración del 11-sep-2026. Sin dependencias (Node 18+, Edge instalado).
//
// Uso (desde web_corporativa/):  node herramientas/capturar-sitio.mjs
//   Variables opcionales: CAPTURAS (carpeta de salida), PERFIL (perfil temporal de Edge), PUERTO y PUERTO_CDP
//   (8790 y 9360 por defecto: si hay otro proceso usándolos, cambiarlos). Deja las capturas y informe_cdp.json
//   en CAPTURAS; por defecto, en la carpeta temporal del sistema (industec-web/capturas).
// Servidor estático local (127.0.0.1, se comporta como Apache e ignora ?v=) + Edge headless por DevTools.
//
// Lecciones de esta corrida (11-sep-2026), que explican la estructura:
//  - Una misma sesión de Edge sin ventana que cambia varias veces de tamaño emulado termina dejando de producir
//    cuadros: la captura se cuelga, no carga lo diferido (loading="lazy") ni aparecen los bloques, y el salto a
//    un #ancla no se ejecuta. Por eso cada grupo de pruebas va en su PROPIA sesión, con una ventana real al
//    menos tan grande como la emulada, la pestaña al frente y con foco, y un repintado forzado antes de medir.
//  - Toda llamada a DevTools lleva plazo; la captura se reintenta una vez.
//  - Perfil de navegador NUEVO en cada sesión (lección del 8-sep-2026).
import http from 'node:http';
import { createReadStream, existsSync, statSync, rmSync, mkdirSync, writeFileSync } from 'node:fs';
import { join, extname, normalize, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { tmpdir } from 'node:os';
import { spawn } from 'node:child_process';
import { setTimeout as esperar } from 'node:timers/promises';

const aqui = dirname(fileURLToPath(import.meta.url));
const RAIZ = normalize(process.env.SITIO || join(aqui, '..', 'sitio'));
const CAPTURAS = process.env.CAPTURAS || join(tmpdir(), 'industec-web', 'capturas');
const PERFIL = process.env.PERFIL || join(tmpdir(), 'industec-web', 'edge_perfil');
const INFORME = join(CAPTURAS, 'informe_cdp.json');
const EDGE = process.env.EDGE || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const PUERTO = Number(process.env.PUERTO || 8790);
const PUERTO_CDP = Number(process.env.PUERTO_CDP || 9360);
const BASE = `http://127.0.0.1:${PUERTO}`;
const PAGINAS = [['inicio', '/'], ['nosotros', '/nosotros/'], ['servicios', '/servicios/'], ['contacto', '/contacto/'], ['acceso', '/acceso/']];
const MOVIL = { ancho: 390, alto: 844, ventana: '500,900' };   // la ventana headless no baja de ~500 px
const ESCRITORIO = { ancho: 1440, alto: 900, ventana: '1440,900' };
mkdirSync(CAPTURAS, { recursive: true });
const hora = () => new Date().toTimeString().slice(0, 8);

// ---------- Servidor estático (se comporta como Apache: /carpeta -> /carpeta/) ----------
const MIME = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp', '.txt': 'text/plain; charset=utf-8' };
const peticiones = [];
const servidor = http.createServer((req, res) => {
  const ruta = decodeURIComponent(new URL(req.url, BASE).pathname);
  peticiones.push(req.url);
  if (ruta.startsWith('/ot/')) { res.writeHead(404); return res.end('ot/ no es parte del sitio'); }
  let archivo = normalize(join(RAIZ, ruta));
  if (!archivo.startsWith(RAIZ)) { res.writeHead(403); return res.end(); }
  if (existsSync(archivo) && statSync(archivo).isDirectory()) {
    if (!ruta.endsWith('/')) { res.writeHead(301, { Location: ruta + '/' }); return res.end(); }
    archivo = join(archivo, 'index.html');
  }
  if (!existsSync(archivo)) { res.writeHead(404); return res.end('no existe'); }
  res.writeHead(200, { 'Content-Type': MIME[extname(archivo)] || 'application/octet-stream', 'Cache-Control': 'no-store' });
  createReadStream(archivo).pipe(res);
});

async function borrarPerfil() {
  for (let i = 0; i < 60; i++) {
    try { rmSync(PERFIL, { recursive: true, force: true }); return; } catch { await esperar(500); }
  }
  throw new Error('No se pudo borrar el perfil del navegador (sigue en uso)');
}

// ---------- Cliente CDP mínimo (WebSocket nativo de Node), con plazo en cada llamada ----------
class Cdp {
  constructor(ws) {
    this.ws = ws; this.id = 0; this.pendientes = new Map(); this.oyentes = new Map();
    ws.onmessage = (ev) => {
      const m = JSON.parse(ev.data);
      if (m.id && this.pendientes.has(m.id)) {
        const { ok, mal } = this.pendientes.get(m.id); this.pendientes.delete(m.id);
        m.error ? mal(new Error(m.error.message)) : ok(m.result);
      } else if (m.method) (this.oyentes.get(m.method) || []).slice().forEach((f) => f(m.params));
    };
  }
  send(method, params = {}, ms = 30000) {
    const id = ++this.id;
    this.ws.send(JSON.stringify({ id, method, params }));
    return new Promise((ok, mal) => {
      const t = setTimeout(() => { this.pendientes.delete(id); mal(new Error(`plazo agotado (${ms} ms): ${method}`)); }, ms);
      this.pendientes.set(id, { ok: (r) => { clearTimeout(t); ok(r); }, mal: (e) => { clearTimeout(t); mal(e); } });
    });
  }
  on(method, f) { if (!this.oyentes.has(method)) this.oyentes.set(method, []); this.oyentes.get(method).push(f); }
  off(method, f) { const l = this.oyentes.get(method) || []; const i = l.indexOf(f); if (i >= 0) l.splice(i, 1); }
  una(method, ms = 20000) {
    return new Promise((ok, mal) => {
      const t = setTimeout(() => { this.off(method, f); mal(new Error('tiempo agotado: ' + method)); }, ms);
      const f = (p) => { clearTimeout(t); this.off(method, f); ok(p); };
      this.on(method, f);
    });
  }
}

async function evaluar(cdp, expr, ms) {
  const r = await cdp.send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true }, ms);
  if (r.exceptionDetails) throw new Error('evaluar: ' + JSON.stringify(r.exceptionDetails).slice(0, 400));
  return r.result.value;
}

// Repintado forzado: marca la página como sucia y espera dos cuadros (o 600 ms si no llegan).
const PINTAR = `(async () => { const h = document.documentElement; h.classList.toggle('__pinta'); await new Promise((r) => { let n = 0; const f = () => (++n > 1 ? r() : requestAnimationFrame(f)); requestAnimationFrame(f); setTimeout(r, 600); }); h.classList.toggle('__pinta'); return true; })()`;
const pintar = (cdp) => evaluar(cdp, PINTAR);

// Recorre la página cuadro a cuadro (dispara apariciones y carga diferida), vuelve arriba y espera a que
// todo esté visible y cargado (hasta 8 s, con repintados). Devuelve lo que haya quedado pendiente.
const RECORRER = `(async () => {
  const cuadro = () => new Promise((r) => { let n = 0; const f = () => (++n > 1 ? r() : requestAnimationFrame(f)); requestAnimationFrame(f); setTimeout(r, 250); });
  for (let y = 0; y < document.documentElement.scrollHeight; y += 350) { window.scrollTo({ top: y, behavior: 'instant' }); await cuadro(); }
  window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' }); await cuadro();
  window.scrollTo({ top: 0, behavior: 'instant' });
  const pendiente = () => ({ ocultos: [...document.querySelectorAll('.revelar')].filter((e) => getComputedStyle(e).opacity !== '1').length, sinCargar: [...document.images].filter((i) => !(i.complete && i.naturalWidth > 0)).length });
  for (let t = 0; t < 8000; t += 300) { const p = pendiente(); if (!p.ocultos && !p.sinCargar) break; document.documentElement.classList.toggle('__pinta'); await cuadro(); await new Promise((r) => setTimeout(r, 50)); }
  await new Promise((r) => setTimeout(r, 700));
  return pendiente();
})()`;

const AUDITORIA = `(() => {
  const r = { desbordeX: document.documentElement.scrollWidth - window.innerWidth, altura: document.documentElement.scrollHeight, contraste: [], pequenos: [] };
  const parse = (c) => { const m = c.match(/rgba?\\(([^)]+)\\)/); if (!m) return null; const p = m[1].split(/[\\s,/]+/).filter(Boolean).map(Number); return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }; };
  const lin = (v) => { v /= 255; return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
  const lum = (c) => 0.2126 * lin(c.r) + 0.7152 * lin(c.g) + 0.0722 * lin(c.b);
  const mezcla = (a, b) => ({ r: a.r * a.a + b.r * (1 - a.a), g: a.g * a.a + b.g * (1 - a.a), b: a.b * a.a + b.b * (1 - a.a), a: 1 });
  const fondo = (el) => { const capas = []; for (let e = el; e; e = e.parentElement) { const c = parse(getComputedStyle(e).backgroundColor); if (c && c.a > 0) { capas.push(c); if (c.a >= 1) break; } } let base = { r: 255, g: 255, b: 255, a: 1 }; for (let i = capas.length - 1; i >= 0; i--) base = mezcla(capas[i], base); return base; };
  const vistos = new Set();
  const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  while (w.nextNode()) {
    const t = w.currentNode; if (!t.textContent.trim()) continue;
    const el = t.parentElement; if (!el || vistos.has(el)) continue; vistos.add(el);
    if (el.closest('.sr,[hidden],noscript,script,style')) continue;
    const cs = getComputedStyle(el); if (cs.display === 'none' || cs.visibility === 'hidden') continue;
    const b = el.getBoundingClientRect(); if (!b.width || !b.height) continue;
    const c = parse(cs.color); if (!c) continue;
    const bg = fondo(el); const fg = c.a < 1 ? mezcla(c, bg) : c;
    const l1 = lum(fg), l2 = lum(bg); const ratio = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    const tam = parseFloat(cs.fontSize), peso = parseInt(cs.fontWeight, 10);
    const minimo = (tam >= 24 || (tam >= 18.66 && peso >= 700)) ? 3 : 4.5;
    if (ratio < minimo) r.contraste.push({ texto: t.textContent.trim().slice(0, 40), ratio: +ratio.toFixed(2), minimo, tam, color: cs.color, fondo: 'rgb(' + [bg.r, bg.g, bg.b].map(Math.round).join(',') + ')' });
  }
  for (const e of document.querySelectorAll('a,button,input,select,textarea')) {
    if (e.closest('.sr') || e.classList.contains('saltar')) continue;
    const b = e.getBoundingClientRect(); if (!b.width || !b.height) continue;
    if (b.height < 24 || b.width < 24) r.pequenos.push((e.textContent || e.id || e.className).trim().slice(0, 40) + ' ' + Math.round(b.width) + 'x' + Math.round(b.height));
  }
  r.revelarOcultos = [...document.querySelectorAll('.revelar')].filter((e) => getComputedStyle(e).opacity !== '1').length;
  r.imgSinCargar = [...document.images].filter((i) => !(i.complete && i.naturalWidth > 0)).map((i) => i.getAttribute('src'));
  // Alto máximo de cada logo: 48 px en escritorio y 40 en el celular; los «compactos» (cuadrados o verticales), 64 y 52.
  const escritorio = window.innerWidth >= 960;
  const logos = [...document.querySelectorAll('.logos img')].map((i) => { const b = i.getBoundingClientRect(); const rn = i.naturalWidth / i.naturalHeight; const rb = b.width / b.height; const compacto = i.classList.contains('compacto'); const lim = compacto ? (escritorio ? 64 : 52) : (escritorio ? 48 : 40); return { alt: i.alt, w: +b.width.toFixed(1), h: +b.height.toFixed(1), area: Math.round(b.width * b.height), excede: b.height > lim + 0.5, deformado: !(rn > 0) || Math.abs(rn - rb) / rn > 0.03 }; });
  r.logos = { cantidad: logos.length, deformados: logos.filter((x) => x.deformado).map((x) => x.alt), excedidos: logos.filter((x) => x.excede).map((x) => x.alt + ' ' + x.h), altoMax: logos.length ? Math.max(...logos.map((x) => x.h)) : null, altoMin: logos.length ? Math.min(...logos.map((x) => x.h)) : null, masBajos: logos.slice().sort((a, b) => a.h - b.h).slice(0, 3).map((x) => x.alt + ' ' + x.w + 'x' + x.h), menorArea: logos.slice().sort((a, b) => a.area - b.area).slice(0, 4).map((x) => x.alt + ' ' + x.w + 'x' + x.h) };
  r.galeria = [...document.querySelectorAll('.galeria img')].map((i) => { const b = i.getBoundingClientRect(); return i.getAttribute('src').split('/').pop() + ' ' + Math.round(b.width) + 'x' + Math.round(b.height); });
  return r;
})()`;

// Primera carga de la portada: elemento principal (LCP) y desplazamientos (CLS), antes de tocar la página.
const RENDIMIENTO = `(async () => {
  let ult = null, cls = 0;
  try { new PerformanceObserver((l) => { const e = l.getEntries(); ult = e[e.length - 1]; }).observe({ type: 'largest-contentful-paint', buffered: true }); } catch (e) {}
  try { new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) cls += e.value; }).observe({ type: 'layout-shift', buffered: true }); } catch (e) {}
  await new Promise((r) => setTimeout(r, 900));
  const el = ult && ult.element;
  return { lcpMs: ult ? Math.round(ult.startTime) : null, lcpElemento: el ? el.tagName.toLowerCase() + (el.className ? '.' + String(el.className).split(' ')[0] : '') + ' ' + (el.currentSrc ? el.currentSrc.split('/').pop() : (el.textContent || '').trim().slice(0, 40)) : null, cls: +cls.toFixed(4) };
})()`;

// Medidas de la portada: foto, placa, líneas de cada distintivo y del H1, botones y línea de filtro.
const MEDIDAS_PORTADA = `(() => {
  const q = (s) => document.querySelector(s); const i = q('.portada__foto img'); if (!i) return null;
  const lineas = (el) => { const tops = new Set(); const r = document.createRange(); for (const n of el.childNodes) { if (n.nodeType !== 3 || !n.textContent.trim()) continue; r.selectNodeContents(n); for (const x of r.getClientRects()) tops.add(Math.round(x.top)); } return tops.size; };
  const caja = (el) => { const b = el.getBoundingClientRect(); return Math.round(b.width) + 'x' + Math.round(b.height) + ' @' + Math.round(b.top + scrollY); };
  const botones = [...document.querySelectorAll('.portada .acciones .boton')].map((b) => Math.round(b.getBoundingClientRect().top));
  const barra = q('.barra-movil');
  const altoBarra = barra && getComputedStyle(barra).display !== 'none' ? Math.round(barra.getBoundingClientRect().height) : 0;
  const h1Fin = Math.round(q('.portada h1').getBoundingClientRect().bottom + scrollY);
  return { foto: i.currentSrc.split('/').pop() + ' ' + caja(i) + ' (natural ' + i.naturalWidth + 'x' + i.naturalHeight + ')', sellos: caja(q('.sellos')),
    h1Fin, entraEn667: h1Fin <= 667 - altoBarra,
    lineasSellos: [...document.querySelectorAll('.sellos li')].map((li) => li.textContent.trim().slice(0, 26) + ': ' + lineas(li)),
    h1: caja(q('.portada h1')) + ' · ' + lineas(q('.portada h1')) + ' líneas', botonesEnUnaFila: new Set(botones).size === 1,
    filtro: caja(q('.aviso-filtro')), topBarraFija: barra && getComputedStyle(barra).display !== 'none' ? Math.round(barra.getBoundingClientRect().top) : null,
    trama: getComputedStyle(q('.portada__visual'), '::before').display };
})()`;

// ---------- Sesiones ----------
const informe = { paginas: {}, problemas: [], pruebas: {}, rendimiento: {}, portada: {}, alturas: {}, sesiones: [] };
let etiqueta = '';
const problema = (tipo, detalle) => informe.problemas.push({ pagina: etiqueta, tipo, detalle });

async function sesion(titulo, ventana, fn) {
  const t0 = Date.now();
  console.log(hora(), 'sesión', titulo);
  await borrarPerfil();
  const edge = spawn(EDGE, ['--headless=new', `--user-data-dir=${PERFIL}`, `--remote-debugging-port=${PUERTO_CDP}`, `--window-size=${ventana}`, '--hide-scrollbars', '--force-device-scale-factor=1', '--no-first-run', '--no-default-browser-check', '--disable-backgrounding-occluded-windows', '--disable-renderer-backgrounding', '--disable-background-timer-throttling', 'about:blank'], { stdio: 'ignore' });
  let ws = null;
  try {
    let wsUrl = null;
    for (let i = 0; i < 80 && !wsUrl; i++) {
      try { const l = await (await fetch(`http://127.0.0.1:${PUERTO_CDP}/json/list`)).json(); wsUrl = (l.find((t) => t.type === 'page') || {}).webSocketDebuggerUrl; } catch { /* aún no */ }
      if (!wsUrl) await esperar(250);
    }
    if (!wsUrl) throw new Error('Edge no abrió el puerto de depuración');
    ws = new WebSocket(wsUrl);
    await new Promise((ok, mal) => { ws.onopen = ok; ws.onerror = mal; });
    const cdp = new Cdp(ws);
    await cdp.send('Page.enable'); await cdp.send('Runtime.enable'); await cdp.send('Log.enable'); await cdp.send('Network.enable');
    await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
    await cdp.send('Page.bringToFront');
    await cdp.send('Emulation.setFocusEmulationEnabled', { enabled: true });
    cdp.on('Runtime.exceptionThrown', (p) => problema('excepción', p.exceptionDetails.exception?.description || p.exceptionDetails.text));
    cdp.on('Runtime.consoleAPICalled', (p) => { if (['error', 'warning', 'assert'].includes(p.type)) problema('consola ' + p.type, p.args.map((a) => a.value ?? a.description).join(' ')); });
    cdp.on('Log.entryAdded', (p) => { if (['error', 'warning'].includes(p.entry.level)) problema('log ' + p.entry.level, `${p.entry.text} ${p.entry.url || ''}`); });
    cdp.on('Network.responseReceived', (p) => { if (p.response.status >= 400) problema('HTTP ' + p.response.status, p.response.url); });
    cdp.on('Network.loadingFailed', (p) => { if (!p.canceled) problema('red', `${p.errorText} ${p.requestId}`); });
    await fn(cdp);
  } catch (e) {
    problema('ARNÉS', e.message);
    console.log(hora(), 'ERROR DEL ARNÉS en', etiqueta, e.message);
  } finally {
    try { ws?.send(JSON.stringify({ id: 999999, method: 'Browser.close' })); } catch { /* ya cerrado */ }
    try { ws?.close(); } catch { /* ya cerrado */ }
    edge.kill();
    await esperar(1500);
    informe.sesiones.push(`${titulo}: ${((Date.now() - t0) / 1000).toFixed(0)} s`);
    writeFileSync(INFORME, JSON.stringify(informe, null, 2));
  }
}

const metricas = (cdp, { ancho, alto }) => cdp.send('Emulation.setDeviceMetricsOverride', { width: ancho, height: alto, deviceScaleFactor: 1, mobile: false });

async function ir(cdp, url, pausa = 600) {
  const carga = cdp.una('Page.loadEventFired');
  await cdp.send('Page.navigate', { url });
  await carga;
  await esperar(pausa);
  await pintar(cdp);
}

async function capturar(cdp, archivo, completa = false) {
  const opciones = { format: 'png' };
  if (completa) {
    const { ancho, alto } = await evaluar(cdp, `({ ancho: document.documentElement.clientWidth, alto: document.documentElement.scrollHeight })`);
    Object.assign(opciones, { captureBeyondViewport: true, clip: { x: 0, y: 0, width: ancho, height: alto, scale: 1 } });
  }
  for (let intento = 1; ; intento++) {
    await pintar(cdp);
    try {
      const { data } = await cdp.send('Page.captureScreenshot', opciones, intento === 1 ? 20000 : 45000);
      writeFileSync(archivo, Buffer.from(data, 'base64'));
      return;
    } catch (e) {
      if (intento > 1) throw e;
      console.log(hora(), 'captura lenta, reintento:', archivo.split('/').pop());
      await evaluar(cdp, `window.scrollBy(0, 1); window.scrollBy(0, -1); true`);
    }
  }
}

async function auditar(cdp, vista) {
  await metricas(cdp, vista);
  for (const [nombre, ruta] of PAGINAS) {
    etiqueta = `${nombre}@${vista.ancho}`;
    console.log(hora(), 'auditoría', etiqueta);
    await ir(cdp, BASE + ruta, 200);
    if (nombre === 'inicio') informe.rendimiento[etiqueta] = await evaluar(cdp, RENDIMIENTO);
    const pendiente = await evaluar(cdp, RECORRER, 60000);
    const a = await evaluar(cdp, AUDITORIA);
    informe.paginas[etiqueta] = a;
    if (a.desbordeX > 0) problema('desborde horizontal', `${a.desbordeX}px`);
    if (a.contraste.length) problema('contraste', JSON.stringify(a.contraste));
    if (a.revelarOcultos) problema('bloques sin aparecer', `${a.revelarOcultos} (tras esperar: ${JSON.stringify(pendiente)})`);
    if (a.imgSinCargar.length) problema('imágenes sin cargar', a.imgSinCargar.join(', '));
    if (a.logos.deformados.length) problema('logos deformados', a.logos.deformados.join(', '));
    if (a.logos.excedidos.length) problema('logos por encima de su alto máximo', a.logos.excedidos.join(', '));
  }
}

async function capturas(cdp, vista) {
  await metricas(cdp, vista);
  for (const [nombre, ruta] of PAGINAS) {
    etiqueta = `${nombre}@${vista.ancho}`;
    console.log(hora(), 'capturas', etiqueta);
    await ir(cdp, BASE + ruta, 300);
    await evaluar(cdp, RECORRER, 60000);
    informe.alturas[`${nombre}-${vista.ancho}`] = await evaluar(cdp, 'document.documentElement.scrollHeight');
    await capturar(cdp, `${CAPTURAS}/${nombre}-${vista.ancho}-pliegue.png`);
    if (nombre === 'inicio') {
      const m = informe.portada[String(vista.ancho)] = await evaluar(cdp, MEDIDAS_PORTADA);
      // En el celular, la foto y el título completo tienen que entrar en un teléfono de 667 px de alto (iPhone SE/8).
      if (vista === MOVIL && m && !m.entraEn667) problema('portada', `en un teléfono de 667 px el título no entra completo con la foto (termina a ${m.h1Fin} px)`);
    }
    await capturar(cdp, `${CAPTURAS}/${nombre}-${vista.ancho}.png`, true);
  }
}

async function pruebasMovil(cdp) {
  await metricas(cdp, MOVIL);

  // Menú del celular
  etiqueta = 'menu@390'; console.log(hora(), 'prueba', etiqueta);
  await ir(cdp, BASE + '/');
  const menu = await evaluar(cdp, `(() => { const b = document.querySelector('.menu-boton'); const n = document.getElementById('menu-principal');
    const antes = getComputedStyle(n).display; b.click(); const abierto = { display: getComputedStyle(n).display, expandido: b.getAttribute('aria-expanded'), etiqueta: b.getAttribute('aria-label') };
    return { antes, abierto }; })()`);
  await capturar(cdp, `${CAPTURAS}/_menu-movil-abierto-390.png`);
  const cerrado = await evaluar(cdp, `(() => { document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' })); const b = document.querySelector('.menu-boton'); return { display: getComputedStyle(document.getElementById('menu-principal')).display, expandido: b.getAttribute('aria-expanded'), foco: document.activeElement === b }; })()`);
  informe.pruebas.menu = { ...menu, trasEscape: cerrado };

  // Formulario de contacto (campo obligatorio «Empresa o cadena»)
  etiqueta = 'formulario@390'; console.log(hora(), 'prueba', etiqueta);
  await ir(cdp, BASE + '/contacto/');
  const pedidas = [];
  const anotar = (p) => pedidas.push(p.request.url);
  cdp.on('Network.requestWillBeSent', anotar);
  await evaluar(cdp, `HTMLAnchorElement.prototype.click = function () { (window.__abiertos = window.__abiertos || []).push({ href: this.href, target: this.target, rel: this.rel }); }; true`);
  const previaInicial = await evaluar(cdp, `document.getElementById('vista-previa-texto').textContent`);
  const vacio = await evaluar(cdp, `(() => { document.querySelector('button[value="whatsapp"]').click();
    return { errores: ['e-nombre','e-empresa','e-necesidad','e-detalle'].map(id => !document.getElementById(id).hidden), invalido: document.getElementById('f-empresa').getAttribute('aria-invalid'), describedbyEmpresa: document.getElementById('f-empresa').getAttribute('aria-describedby'), foco: document.activeElement.id, abiertos: (window.__abiertos || []).length }; })()`);
  const soloNombre = await evaluar(cdp, `(() => { const e = document.getElementById('f-nombre'); e.value = 'María Prueba'; e.dispatchEvent(new Event('input', { bubbles: true })); document.querySelector('button[value="whatsapp"]').click(); return { foco: document.activeElement.id, errorEmpresa: !document.getElementById('e-empresa').hidden, abiertos: (window.__abiertos || []).length }; })()`);
  await evaluar(cdp, `document.getElementById('f-nombre').scrollIntoView({ block: 'start', behavior: 'instant' }); window.scrollBy({ top: -90, behavior: 'instant' }); true`);
  await capturar(cdp, `${CAPTURAS}/_formulario-errores-390.png`);
  const lleno = await evaluar(cdp, `(() => {
    const poner = (id, v, ev) => { const e = document.getElementById(id); e.value = v; e.dispatchEvent(new Event(ev || 'input', { bubbles: true })); };
    poner('f-nombre', 'María Prueba'); poner('f-empresa', 'Cadena de prueba'); poner('f-necesidad', 'Reparación o emergencia', 'change');
    poner('f-detalle', 'La freidora no calienta.\\nDesde ayer en la mañana.'); poner('f-telefono', '');
    const previa = document.getElementById('vista-previa-texto').textContent;
    const aviso = !document.getElementById('aviso-urgente').hidden;
    const erroresVisibles = ['e-nombre','e-empresa','e-necesidad','e-detalle'].filter(id => !document.getElementById(id).hidden);
    const primeraOpcion = document.getElementById('f-necesidad').options[1].text;
    window.__abiertos = [];
    document.querySelector('button[value="whatsapp"]').click();
    document.querySelector('button[value="correo"]').click();
    return { previa, aviso, erroresVisibles, primeraOpcion, abiertos: window.__abiertos, almacenamiento: localStorage.length + sessionStorage.length, cookies: document.cookie }; })()`);
  const esperado = 'Hola INDUSTEC, les escribo desde su página web.\nNombre: María Prueba\nEmpresa: Cadena de prueba\nNecesito: Reparación o emergencia\nDetalle: La freidora no calienta.\nDesde ayer en la mañana.';
  const waEsperado = 'https://wa.me/593997887709?text=' + encodeURIComponent(esperado);
  const mailEsperado = 'mailto:servicioalcliente@industec.me?subject=' + encodeURIComponent('Solicitud desde la web: Reparación o emergencia - Cadena de prueba') + '&body=' + encodeURIComponent(esperado.replace(/\n/g, '\r\n'));
  await evaluar(cdp, `document.getElementById('f-necesidad').scrollIntoView({ block: 'start', behavior: 'instant' }); window.scrollBy({ top: -90, behavior: 'instant' }); true`);
  await capturar(cdp, `${CAPTURAS}/_formulario-lleno-390.png`);
  cdp.off('Network.requestWillBeSent', anotar);
  informe.pruebas.formulario = {
    previaInicialCorrecta: previaInicial === 'Hola INDUSTEC, les escribo desde su página web.\nNombre: …\nEmpresa: …\nNecesito: …\nDetalle: …',
    vacio, soloNombre, previaCorrecta: lleno.previa === esperado, aviso: lleno.aviso, erroresVisibles: lleno.erroresVisibles, primeraOpcion: lleno.primeraOpcion,
    whatsappCorrecto: lleno.abiertos?.[0]?.href === waEsperado, whatsappPestanaNueva: lleno.abiertos?.[0]?.target === '_blank',
    correoCorrecto: lleno.abiertos?.[1]?.href === mailEsperado, almacenamiento: lleno.almacenamiento, cookies: lleno.cookies,
    peticionesDuranteLaPrueba: pedidas.filter((u) => !u.startsWith('data:')),
    urlWhatsapp: lleno.abiertos?.[0]?.href, urlCorreo: lleno.abiertos?.[1]?.href,
  };

  // Ancla de servicio bajo la cabecera fija
  etiqueta = 'ancla@390'; console.log(hora(), 'prueba', etiqueta);
  await ir(cdp, BASE + '/');
  const carga = cdp.una('Page.loadEventFired');
  await cdp.send('Page.navigate', { url: BASE + '/servicios/#correctivo' });
  await carga;
  const muestras = [];
  for (const pausa of [0, 400, 1300]) {
    await esperar(pausa);
    await pintar(cdp); // un navegador real pinta cuadros solo: ahí se ejecuta el salto al ancla
    muestras.push(await evaluar(cdp, `({ y: Math.round(scrollY), top: Math.round(document.getElementById('correctivo').getBoundingClientRect().top), hash: location.hash, suave: document.documentElement.classList.contains('suave') })`));
  }
  await capturar(cdp, `${CAPTURAS}/_ancla-correctivo-390.png`);
  const altoCab = await evaluar(cdp, `Math.round(document.querySelector('.cabecera').getBoundingClientRect().height)`);
  const ultima = muestras[muestras.length - 1];
  informe.pruebas.ancla = { muestras, altoCabecera: altoCab, aterriza: ultima.top >= altoCab - 2 && ultima.top <= altoCab + 40 };

  // Sin JavaScript (al final: deja los scripts apagados)
  etiqueta = 'sin-js@390'; console.log(hora(), 'prueba', etiqueta);
  await cdp.send('Emulation.setScriptExecutionDisabled', { value: true });
  const c2 = cdp.una('Page.loadEventFired');
  await cdp.send('Page.navigate', { url: BASE + '/contacto/' });
  await c2; await esperar(600);
  const r = await cdp.send('Runtime.evaluate', { expression: `({ nav: getComputedStyle(document.getElementById('menu-principal')).display, botonMenu: getComputedStyle(document.querySelector('.menu-boton')).display, formulario: getComputedStyle(document.getElementById('form-mensaje')).display })`, returnByValue: true });
  informe.pruebas.sinJs = r.result?.value || r;
  try { const { data } = await cdp.send('Page.captureScreenshot', { format: 'png' }, 20000); writeFileSync(`${CAPTURAS}/_sin-js-contacto-390.png`, Buffer.from(data, 'base64')); } catch (e) { informe.pruebas.sinJs.captura = e.message; }
}

async function pruebasEscritorio(cdp) {
  // Movimiento reducido
  etiqueta = 'movimiento-reducido@1440'; console.log(hora(), 'prueba', etiqueta);
  await metricas(cdp, ESCRITORIO);
  await cdp.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
  await ir(cdp, BASE + '/', 600);
  informe.pruebas.movimientoReducido = await evaluar(cdp, `(() => ({ anim: document.documentElement.classList.contains('anim'), ocultos: [...document.querySelectorAll('.revelar')].filter(e => getComputedStyle(e).opacity !== '1').length, animacionH1: getComputedStyle(document.querySelector('h1')).animationName, animacionFoto: getComputedStyle(document.querySelector('.portada__visual')).animationName, smooth: getComputedStyle(document.documentElement).scrollBehavior }))()`);
  await cdp.send('Emulation.setEmulatedMedia', { features: [] });
  // Portada a 1024 px (entre 960 y 1180 la foto y la trama cambian de tamaño)
  etiqueta = 'inicio@1024'; console.log(hora(), 'prueba', etiqueta);
  await metricas(cdp, { ancho: 1024, alto: 800 });
  await ir(cdp, BASE + '/', 600);
  informe.portada['1024'] = await evaluar(cdp, MEDIDAS_PORTADA);
  const a = await evaluar(cdp, AUDITORIA);
  if (a.desbordeX > 0) problema('desborde horizontal', `${a.desbordeX}px`);
  if (a.contraste.length) problema('contraste', JSON.stringify(a.contraste));
  await capturar(cdp, `${CAPTURAS}/inicio-1024-pliegue.png`);
}

await new Promise((ok) => servidor.listen(PUERTO, '127.0.0.1', ok));
try {
  await sesion('auditoría 390', MOVIL.ventana, (cdp) => auditar(cdp, MOVIL));
  await sesion('auditoría 1440', ESCRITORIO.ventana, (cdp) => auditar(cdp, ESCRITORIO));
  await sesion('pruebas 390', MOVIL.ventana, pruebasMovil);
  await sesion('pruebas 1440 y 1024', ESCRITORIO.ventana, pruebasEscritorio);
  await sesion('capturas 390', MOVIL.ventana, (cdp) => capturas(cdp, MOVIL));
  await sesion('capturas 1440', ESCRITORIO.ventana, (cdp) => capturas(cdp, ESCRITORIO));
  informe.peticionesConVersion = [...new Set(peticiones.filter((u) => /\?v=/.test(u)))];
  writeFileSync(INFORME, JSON.stringify(informe, null, 2));
  console.log('PROBLEMAS:', informe.problemas.length ? JSON.stringify(informe.problemas, null, 1) : 'ninguno');
  console.log('PRUEBAS:', JSON.stringify(informe.pruebas, null, 1));
  console.log('RENDIMIENTO:', JSON.stringify(informe.rendimiento));
  console.log('PORTADA:', JSON.stringify(informe.portada, null, 1));
  console.log('PÁGINAS:', JSON.stringify(Object.fromEntries(Object.entries(informe.paginas).map(([k, v]) => [k, { desbordeX: v.desbordeX, altura: v.altura, contraste: v.contraste.length, pequenos: v.pequenos, logos: v.logos.cantidad ? v.logos : undefined, galeria: v.galeria.length ? v.galeria : undefined }]))));
  console.log('SESIONES:', informe.sesiones.join(' · '));
  console.log('CON ?v=:', JSON.stringify(informe.peticionesConVersion));
} finally {
  servidor.close();
  await borrarPerfil().catch(() => {});
}
