/* t2_28_11_descubrir_partstown.mjs — T2.28.11, punto 1: qué enlaces de Parts
   Town llevan de verdad a la ficha de un repuesto, a la página de un modelo y a
   una búsqueda.

   POR QUÉ CON UN NAVEGADOR. Parts Town no tiene API pública y se arma con
   JavaScript: `WebFetch` devolvía la portada para cualquier URL (22-sep). Solo
   un navegador de verdad dice a qué página llega un enlace.

   QUÉ NO HACE (prohibido en T2.28.11): no raspa el sitio, no guarda precios ni
   capturas. Visita unas 40 páginas, una por una y con pausa, y de cada una
   anota solo la URL final, el título y el encabezado. Es el mismo número de
   visitas que haría una persona probando enlaces a mano.

   Uso:  node t2_28_11_descubrir_partstown.mjs
   Deja: SALIDAS IA/OTS/partstown_descubrimiento.json */
import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const NAVEGADOR = [
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
].find(existsSync);
const SALIDA = 'D:/INDUSTECH IA/SALIDAS IA/OTS/partstown_descubrimiento.json';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// 12 números de parte del catálogo de bodega de KFC (T2.28.10a), de marcas
// distintas, tal como vienen: con el prefijo de Parts Town.
const SKUS = [
  ['Henny Penny', 'Hen22455'], ['Manitowoc', 'Man000007926'], ['Frymaster', 'Fm8100705'],
  ['True', 'True970728'], ['Bunn', 'Bu32106.0001'], ['Taylor', 'Taf028992'],
  ['Amana', 'Ama20057401'], ['TurboChef', 'Tbc100232'], ['Prince Castle', 'Pc541-1372S'],
  ['Star', 'Sta2E-Z2894'], ['Pitco', 'Pt60144002-C'], ['Rancilio', 'Ra10705367'],
];
// 5 pares marca/modelo de los más frecuentes en ot_equipos de la estación.
// HC-903 es a propósito: Parts Town lo puede llamar HHC-903.
const MODELOS = [
  ['Henny Penny', 'PFG-690'], ['Henny Penny', 'HC-903'], ['Taylor', 'C712'],
  ['Pitco', 'SG14R'], ['Manitowoc', 'UDF0310A'],
];
const slug = (s) => String(s).toLowerCase().trim().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '');

class Nav {
  async abrir() {
    this.perfil = mkdtempSync(join(tmpdir(), 'pt-'));
    this.puerto = 9450 + Math.floor(Math.random() * 200);
    this.proc = spawn(NAVEGADOR, ['--headless=new', '--disable-gpu', '--no-first-run',
      '--no-default-browser-check', '--disable-extensions', `--user-data-dir=${this.perfil}`,
      `--remote-debugging-port=${this.puerto}`, '--window-size=1280,900', 'about:blank'], { stdio: 'ignore' });
    let pagina;
    for (let i = 0; i < 100 && !pagina; i++) {
      await sleep(200);
      try {
        pagina = (await fetch(`http://127.0.0.1:${this.puerto}/json/list`).then((r) => r.json()))
          .find((x) => x.type === 'page');
      } catch { /* aún no */ }
    }
    if (!pagina) { throw new Error('el navegador no abrió'); }
    this.ws = new WebSocket(pagina.webSocketDebuggerUrl);
    this.id = 0; this.pend = new Map(); this.estado = new Map();
    await new Promise((ok, mal) => {
      this.ws.addEventListener('open', ok, { once: true });
      this.ws.addEventListener('error', () => mal(new Error('ws')), { once: true });
    });
    this.ws.addEventListener('message', (e) => {
      const m = JSON.parse(e.data);
      if (m.id && this.pend.has(m.id)) {
        const { ok, mal } = this.pend.get(m.id); this.pend.delete(m.id);
        m.error ? mal(new Error(m.error.message)) : ok(m.result);
      } else if (m.method === 'Network.responseReceived' && m.params.type === 'Document') {
        this.estado.set(m.params.response.url, m.params.response.status);
      }
    });
    await this.send('Page.enable'); await this.send('Runtime.enable'); await this.send('Network.enable');
  }
  send(method, params = {}) {
    return new Promise((ok, mal) => {
      const id = ++this.id; this.pend.set(id, { ok, mal });
      this.ws.send(JSON.stringify({ id, method, params }));
    });
  }
  async ev(expr) {
    const r = await this.send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
    return r.exceptionDetails ? null : r.result?.value;
  }
  /* Solo URL, título y encabezado. `es_404` por el título o el h1 que el sitio
     pone a las páginas que no existen; `menciona` si el texto del encabezado o
     el título nombra lo que se buscaba (sin guiones ni puntos). */
  async leer(buscado) {
    return this.ev(`(() => {
      const t = (document.title || '').trim();
      const h1 = (document.querySelector('h1')?.innerText || '').trim().slice(0, 160);
      const norm = (s) => s.toLowerCase().replace(/[^a-z0-9]/g, '');
      const b = norm(${JSON.stringify(buscado)});
      const cuerpo = norm((document.body?.innerText || '').slice(0, 4000));
      return { url: location.href, titulo: t.slice(0, 160), h1,
               es_404: /404|not found|no encontr|no existe|page you requested/i.test(t + ' ' + h1),
               bloqueado: /access denied|forbidden|captcha|are you a human|robot|blocked|attention required|cloudflare/i.test(t + ' ' + h1),
               menciona: b.length > 2 && (norm(t + h1).includes(b) || cuerpo.includes(b)) };
    })()`);
  }
  async ir(url, buscado, espera = 7000) {
    await this.send('Page.navigate', { url });
    await sleep(espera);
    const r = (await this.leer(buscado)) || { url: null };
    r.pedida = url;
    r.http = this.estado.get(url) ?? this.estado.get(r.url) ?? null;
    return r;
  }
  /* El buscador del sitio: se escribe como una persona y se lee la URL a la que
     lleva. Así sale el slug de la marca aunque no lo adivinemos. */
  async buscar(termino) {
    await this.send('Page.navigate', { url: 'https://www.partstown.com/es/' });
    await sleep(7000);
    const hay = await this.ev(`(() => {
      const c = [...document.querySelectorAll('input')].find((i) => i.offsetParent !== null &&
        (i.type === 'search' || /search|buscar|q$/i.test(i.name + ' ' + i.id + ' ' + (i.placeholder || ''))));
      if (!c) { return null; }
      c.focus(); c.value = '';
      return { name: c.name, id: c.id, placeholder: c.placeholder };
    })()`);
    if (!hay) { return { pedida: 'buscador', url: null, error: 'no se encontró el buscador' }; }
    await this.send('Input.insertText', { text: termino });
    await sleep(600);
    for (const type of ['keyDown', 'keyUp']) {
      await this.send('Input.dispatchKeyEvent', { type, key: 'Enter', code: 'Enter',
        windowsVirtualKeyCode: 13, nativeVirtualKeyCode: 13, ...(type === 'keyDown' ? { text: '\r' } : {}) });
    }
    await sleep(8000);
    const r = (await this.leer(termino)) || { url: null };
    return { pedida: `buscador «${termino}»`, campo: hay, ...r };
  }
  async cerrar() {
    try { this.ws.close(); } catch { /* nada */ }
    try { this.proc.kill(); } catch { /* nada */ }
    await sleep(800);
    try { rmSync(this.perfil, { recursive: true, force: true }); } catch { /* el perfil queda en temp */ }
  }
}

const n = new Nav();
await n.abrir();
const informe = { fecha: new Date().toISOString(), navegador: NAVEGADOR, fichas: [], modelos: [], busquedas: [] };
try {
  const portada = await n.ir('https://www.partstown.com/henny-penny/hen52347', 'hen52347');
  informe.referencia = portada;           // la ficha que se vio en la búsqueda web del 22-sep
  console.log('referencia', JSON.stringify(portada));
  if (process.env.SOLO_REFERENCIA) { process.exitCode = portada.menciona ? 0 : 1; }
  for (const [marca, sku] of process.env.SOLO_REFERENCIA ? [] : SKUS) {
    const fila = { marca, sku, slug_supuesto: slug(marca) };
    fila.con_es = await n.ir(`https://www.partstown.com/es/${slug(marca)}/${sku.toLowerCase()}`, sku);
    fila.sin_es = await n.ir(`https://www.partstown.com/${slug(marca)}/${sku.toLowerCase()}`, sku);
    fila.buscador = await n.buscar(sku);
    informe.fichas.push(fila);
    console.log(marca.padEnd(14), sku.padEnd(14),
      '| /es/:', fila.con_es.es_404 ? '404' : (fila.con_es.menciona ? 'FICHA' : '?'), fila.con_es.url,
      '| sin /es/:', fila.sin_es.es_404 ? '404' : (fila.sin_es.menciona ? 'FICHA' : '?'),
      '| buscador ->', fila.buscador.url);
  }
  for (const [marca, modelo] of process.env.SOLO_REFERENCIA ? [] : MODELOS) {
    const fila = { marca, modelo };
    fila.con_es = await n.ir(`https://www.partstown.com/es/${slug(marca)}/${slug(modelo)}/parts`, modelo);
    fila.sin_es = await n.ir(`https://www.partstown.com/${slug(marca)}/${slug(modelo)}/parts`, modelo);
    fila.buscador = await n.buscar(`${marca} ${modelo}`);
    informe.modelos.push(fila);
    console.log(marca.padEnd(14), modelo.padEnd(14),
      '| /es/:', fila.con_es.es_404 ? '404' : (fila.con_es.menciona ? 'MODELO' : '?'), fila.con_es.url,
      '| sin /es/:', fila.sin_es.es_404 ? '404' : (fila.sin_es.menciona ? 'MODELO' : '?'),
      '| buscador ->', fila.buscador.url);
  }
} finally {
  await n.cerrar();
  writeFileSync(SALIDA, JSON.stringify(informe, null, 1), 'utf8');
  console.log('->', SALIDA);
}
