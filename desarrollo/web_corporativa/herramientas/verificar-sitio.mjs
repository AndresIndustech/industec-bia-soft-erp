#!/usr/bin/env node
// Verificación estática del sitio corporativo de INDUSTEC. Sin dependencias (Node 18+).
//
// Uso:
//   node herramientas/verificar-sitio.mjs                 -> verifica ../sitio
//   node herramientas/verificar-sitio.mjs --manifiesto    -> además escribe sitio.sha256 (para verificar la publicación)
//
// Comprueba:
//   1. La raíz del sitio solo tiene lo permitido (sin .htaccess ni carpeta ot/).
//   2. Todo enlace y recurso interno existe, incluidas las anclas #id. Lo que empieza por /ot/ es el
//      sistema B.IA Soft ERP: se trata como externo y válido (no se toca desde aquí).
//   3. No hay referencias http(s) externas salvo wa.me. El propio dominio solo aparece en metadatos
//      (og:url, og:image, datos estructurados) y debe apuntar a archivos que existen.
//   4. Cada página: lang es*, title, description, robots noindex+nofollow, Open Graph, un solo h1,
//      alt en todas las imágenes, ids únicos, referencias ARIA y etiquetas de campos válidas.
//   5. Mensajes de WhatsApp: todos empiezan con el saludo que permite contar los contactos de la web.
//   6. Nada de datos internos (lista de términos prohibidos) ni almacenamiento o red en el JavaScript.
//   7. Peso total por debajo de 1,5 MB.
import { readdirSync, readFileSync, statSync, writeFileSync, existsSync } from 'node:fs';
import { join, relative, dirname, extname, resolve, sep } from 'node:path';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';

const aqui = dirname(fileURLToPath(import.meta.url));
const SITIO = resolve(process.argv.find((a, i) => i > 1 && !a.startsWith('--')) || join(aqui, '..', 'sitio'));
const DOMINIO = 'https://darkviolet-armadillo-872352.hostingersite.com';
const SALUDO = 'Hola INDUSTEC, les escribo desde su página web.';
const LIMITE = 1.5 * 1024 * 1024;
const RAIZ_PERMITIDA = new Set(['index.html', 'robots.txt', 'assets', 'nosotros', 'servicios', 'contacto', 'acceso']);
const PROHIBIDOS = [
  [/\bRUC\b/, 'RUC'],
  [/\b\d{13}\b/, 'número de 13 dígitos (posible RUC)'],
  [/jefes? de zona/i, 'cargo interno'],
  [/\bzonas?\b/i, 'zonas'],
  [/\b\d+\s+locales\b/i, 'número de locales'],
  [/órdenes?\s+(de\s+trabajo\s+)?(por|al)\s+mes/i, 'órdenes por mes'],
  [/\bOTs?\b/, 'OT'],
  [/César|Basantes/i, 'nombre de persona'],
  [/Andr[eé]s|Valentina|Steven/i, 'nombre de persona'],
  [/5,6\s?%|5,92|83\s?%|85\s?%/, 'cifra retirada por no tener fuente'],
];

const fallos = [];
const avisos = [];
const falla = (m) => fallos.push(m);
const avisa = (m) => avisos.push(m);
const rel = (f) => relative(SITIO, f).split(sep).join('/');

function listar(dir) {
  return readdirSync(dir, { withFileTypes: true }).flatMap((e) =>
    e.isDirectory() ? listar(join(dir, e.name)) : [join(dir, e.name)]);
}

if (!existsSync(SITIO)) { console.error('No existe la carpeta del sitio:', SITIO); process.exit(2); }
const archivos = listar(SITIO);

// 1. Raíz
for (const e of readdirSync(SITIO)) if (!RAIZ_PERMITIDA.has(e)) falla(`Raíz: «${e}» no debe estar en la raíz del sitio`);
if (existsSync(join(SITIO, '.htaccess'))) falla('Raíz: hay un .htaccess (lo heredaría /ot/)');
if (existsSync(join(SITIO, 'ot'))) falla('Raíz: hay una carpeta ot/ (el sistema no se toca desde aquí)');

// Utilidades
const paginas = archivos.filter((f) => f.endsWith('.html'));
const idsDe = new Map();
for (const p of paginas) {
  const html = readFileSync(p, 'utf8');
  idsDe.set(p, [...html.matchAll(/\sid="([^"]+)"/g)].map((m) => m[1]));
}
function destinoLocal(ruta) {
  // ruta absoluta desde la raíz del sitio, sin query ni ancla
  let f = join(SITIO, decodeURIComponent(ruta));
  if (ruta.endsWith('/')) f = join(f, 'index.html');
  return f;
}
function comprobarInterno(origen, valor) {
  if (!valor.startsWith('/')) { falla(`${rel(origen)}: ruta relativa «${valor}» (usar rutas desde la raíz)`); return; }
  if (valor.startsWith('/ot/') || valor === '/ot') return; // sistema B.IA Soft ERP: externo al sitio
  const [sinAncla, ancla] = valor.split('#');
  const ruta = sinAncla.split('?')[0];
  const f = destinoLocal(ruta || '/');
  if (!existsSync(f) || statSync(f).isDirectory()) { falla(`${rel(origen)}: no existe «${valor}»`); return; }
  if (ancla && f.endsWith('.html') && !(idsDe.get(f) || []).includes(ancla)) falla(`${rel(origen)}: ancla #${ancla} no existe en ${rel(f)}`);
}
const mensajesWa = new Set();
function comprobarUrlAbsoluta(origen, url, contexto) {
  if (contexto === 'js' && url === 'https://wa.me/') {
    // El formulario arma la URL como 'https://wa.me/' + NUMERO: se valida que el número sea el correcto.
    if (!readFileSync(origen, 'utf8').includes("'593997887709'")) falla(`${rel(origen)}: el JS arma un enlace de WhatsApp sin el número 593997887709`);
    return;
  }
  if (url.startsWith('https://wa.me/')) {
    const u = new URL(url);
    if (u.pathname !== '/593997887709') falla(`${rel(origen)}: número de WhatsApp inesperado en ${url}`);
    const texto = u.searchParams.get('text');
    if (texto !== null) {
      mensajesWa.add(texto);
      if (!texto.startsWith(SALUDO)) falla(`${rel(origen)}: mensaje de WhatsApp sin el saludo de seguimiento: «${texto}»`);
    }
    return;
  }
  if (url.startsWith(DOMINIO)) {
    if (contexto !== 'meta' && contexto !== 'jsonld') falla(`${rel(origen)}: URL absoluta del propio dominio fuera de metadatos: ${url}`);
    const ruta = url.slice(DOMINIO.length) || '/';
    const f = destinoLocal(ruta.split('#')[0]);
    if (!existsSync(f)) falla(`${rel(origen)}: ${url} apunta a un archivo que no existe`);
    return;
  }
  if (url === 'https://schema.org' && contexto === 'jsonld') return; // vocabulario, no se descarga
  if (url === 'http://www.w3.org/2000/svg' || url === 'http://www.w3.org/1999/xlink') return; // espacio de nombres
  falla(`${rel(origen)}: referencia externa no permitida: ${url}`);
}

// 2-4. Páginas
const PAGINAS_ESPERADAS = ['index.html', 'nosotros/index.html', 'servicios/index.html', 'contacto/index.html', 'acceso/index.html'];
for (const esperada of PAGINAS_ESPERADAS) if (!existsSync(join(SITIO, esperada))) falla(`Falta la página ${esperada}`);

const resumenPaginas = [];
for (const p of paginas) {
  const html = readFileSync(p, 'utf8');
  const nombre = rel(p);
  const jsonld = [...html.matchAll(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/g)].map((m) => m[1]);
  const sinJsonld = html.replace(/<script type="application\/ld\+json">[\s\S]*?<\/script>/g, '');
  for (const bloque of jsonld) {
    try { JSON.parse(bloque); } catch (e) { falla(`${nombre}: JSON-LD inválido (${e.message})`); }
    for (const m of bloque.matchAll(/https?:\/\/[^\s"'<>]+/g)) comprobarUrlAbsoluta(p, m[0], 'jsonld');
  }
  // Atributos con URL
  for (const m of sinJsonld.matchAll(/\s(href|src|action|poster)="([^"]*)"/g)) {
    const [, atr, v] = m;
    if (atr === 'action') { falla(`${nombre}: formulario con action="${v}" (no debe enviar a ningún servidor)`); continue; }
    if (v.startsWith('#')) { if (v.length > 1 && !idsDe.get(p).includes(v.slice(1))) falla(`${nombre}: ancla ${v} no existe`); continue; }
    if (v.startsWith('mailto:')) { if (!v.startsWith('mailto:servicioalcliente@industec.me')) falla(`${nombre}: correo inesperado ${v}`); continue; }
    if (v.startsWith('tel:')) { if (v !== 'tel:+593997887709') falla(`${nombre}: teléfono inesperado ${v}`); continue; }
    if (/^https?:/.test(v)) { comprobarUrlAbsoluta(p, v, 'atributo'); continue; }
    comprobarInterno(p, v);
  }
  for (const m of sinJsonld.matchAll(/\ssrcset="([^"]*)"/g)) {
    for (const parte of m[1].split(',')) comprobarInterno(p, parte.trim().split(/\s+/)[0]);
  }
  for (const m of sinJsonld.matchAll(/<meta\s+(?:property|name)="([^"]+)"\s+content="([^"]*)"/g)) {
    if (/^https?:/.test(m[2])) comprobarUrlAbsoluta(p, m[2], 'meta');
  }
  // Cualquier otra URL suelta en el HTML (texto, estilos en línea…)
  for (const m of sinJsonld.matchAll(/https?:\/\/[^\s"'<>)]+/g)) {
    const u = m[0];
    if (!u.startsWith('https://wa.me/') && !u.startsWith(DOMINIO)) comprobarUrlAbsoluta(p, u, 'texto');
  }
  // Metadatos
  const lang = (html.match(/<html[^>]*\slang="([^"]+)"/) || [])[1];
  if (!lang || !/^es(-|$)/.test(lang)) falla(`${nombre}: lang no es español (${lang})`);
  const title = (html.match(/<title>([^<]*)<\/title>/) || [])[1];
  const desc = (html.match(/<meta name="description" content="([^"]*)"/) || [])[1];
  const robots = (html.match(/<meta name="robots" content="([^"]*)"/) || [])[1] || '';
  if (!title) falla(`${nombre}: sin <title>`);
  if (!desc) falla(`${nombre}: sin meta description`);
  if (!/noindex/.test(robots) || !/nofollow/.test(robots)) falla(`${nombre}: sin robots noindex, nofollow`);
  for (const og of ['og:title', 'og:description', 'og:url', 'og:image', 'og:image:alt', 'og:locale', 'og:site_name']) {
    if (!html.includes(`property="${og}"`)) falla(`${nombre}: falta ${og}`);
  }
  if (!/rel="icon"/.test(html)) falla(`${nombre}: sin favicon`);
  const h1 = (html.match(/<h1\b/g) || []).length;
  if (h1 !== 1) falla(`${nombre}: tiene ${h1} h1 (debe ser 1)`);
  for (const img of html.match(/<img\b[^>]*>/g) || []) if (!/\salt="/.test(img)) falla(`${nombre}: imagen sin alt: ${img.slice(0, 80)}`);
  const ids = idsDe.get(p);
  const repetidos = ids.filter((id, i) => ids.indexOf(id) !== i);
  if (repetidos.length) falla(`${nombre}: ids repetidos ${[...new Set(repetidos)].join(', ')}`);
  for (const m of html.matchAll(/\s(aria-controls|aria-labelledby|aria-describedby|for)="([^"]+)"/g)) {
    for (const id of m[2].split(/\s+/)) if (!ids.includes(id)) falla(`${nombre}: ${m[1]} apunta a #${id}, que no existe`);
  }
  for (const a of html.match(/<a\b[^>]*target="_blank"[^>]*>/g) || []) if (!/rel="[^"]*noopener/.test(a)) falla(`${nombre}: enlace target=_blank sin rel=noopener`);
  // WCAG 2.5.3: si un enlace o botón lleva aria-label, este debe contener el texto que se ve (control por voz).
  for (const m of html.matchAll(/<(a|button)\b[^>]*\saria-label="([^"]+)"[^>]*>([\s\S]*?)<\/\1>/g)) {
    const visto = m[3].replace(/<span class="sr">[\s\S]*?<\/span>/g, '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    if (visto && !m[2].toLowerCase().includes(visto.toLowerCase())) falla(`${nombre}: el aria-label «${m[2]}» no contiene el texto visible «${visto}» (WCAG 2.5.3)`);
  }
  for (const p of html.match(/<p\b[^>]*\saria-(label|labelledby)="[^"]*"[^>]*>/g) || []) falla(`${nombre}: un <p> no admite nombre ARIA: ${p.slice(0, 80)}`);
  for (const campo of html.match(/<(input|select|textarea)\b[^>]*>/g) || []) {
    const id = (campo.match(/\sid="([^"]+)"/) || [])[1];
    if (!id || !html.includes(`for="${id}"`)) falla(`${nombre}: campo sin <label for>: ${campo.slice(0, 60)}`);
    if (/\sname="/.test(campo)) avisa(`${nombre}: el campo ${id} tiene name= (se serializaría si el formulario llegara a enviarse)`);
  }
  // Datos internos: sobre el texto visible y los atributos (sin etiquetas ni URLs codificadas)
  const visible = sinJsonld.replace(/<script[\s\S]*?<\/script>/g, '').replace(/<style[\s\S]*?<\/style>/g, '')
    .replace(/https?:\/\/[^\s"'<>]+/g, '').replace(/<[^>]+>/g, ' ');
  for (const [re, que] of PROHIBIDOS) { const m = visible.match(re); if (m) falla(`${nombre}: posible dato no publicable (${que}): «${m[0]}»`); }
  // Los comentarios HTML también se publican (se ven con «ver código fuente»).
  const comentarios = [...html.matchAll(/<!--([\s\S]*?)-->/g)].map((m) => m[1]).join(' ');
  for (const [re, que] of PROHIBIDOS) { const m = comentarios.match(re); if (m) falla(`${nombre}: posible dato no publicable en un comentario HTML (${que}): «${m[0]}»`); }
  resumenPaginas.push({ pagina: nombre, lang, title: `${title} (${title?.length ?? 0})`, description: desc?.length ?? 0, robots });
}

// CSS: url(...) internos
for (const f of archivos.filter((x) => x.endsWith('.css'))) {
  const css = readFileSync(f, 'utf8');
  for (const m of css.matchAll(/url\(\s*["']?([^"')]+)["']?\s*\)/g)) {
    const v = m[1];
    if (v.startsWith('data:')) continue;
    if (/^https?:/.test(v)) comprobarUrlAbsoluta(f, v, 'css'); else comprobarInterno(f, v);
  }
  if (/@import/.test(css)) falla(`${rel(f)}: usa @import`);
}
// JS: sin red, sin almacenamiento, sin rastreo
for (const f of archivos.filter((x) => x.endsWith('.js'))) {
  const js = readFileSync(f, 'utf8');
  const codigo = js.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
  for (const t of ['localStorage', 'sessionStorage', 'document.cookie', 'fetch(', 'XMLHttpRequest', 'sendBeacon', 'WebSocket', 'indexedDB', 'navigator.geolocation']) {
    if (codigo.includes(t)) falla(`${rel(f)}: usa ${t}`);
  }
  for (const m of codigo.matchAll(/https?:\/\/[^\s"'<>)]+/g)) comprobarUrlAbsoluta(f, m[0].replace(/[',;]+$/, ''), 'js');
}
// SVG: sin referencias externas (salvo el espacio de nombres)
for (const f of archivos.filter((x) => x.endsWith('.svg'))) {
  const svg = readFileSync(f, 'utf8');
  for (const m of svg.matchAll(/https?:\/\/[^\s"'<>)]+/g)) comprobarUrlAbsoluta(f, m[0], 'svg');
  if (/<script/i.test(svg)) falla(`${rel(f)}: SVG con script`);
}
// Datos internos también en CSS, JS, SVG y robots.txt (comentarios incluidos): se publican tal cual.
for (const f of archivos.filter((x) => /\.(css|js|svg|txt)$/.test(x))) {
  const texto = readFileSync(f, 'utf8');
  for (const [re, que] of PROHIBIDOS) { const m = texto.match(re); if (m) falla(`${rel(f)}: posible dato no publicable (${que}): «${m[0]}»`); }
}
// robots.txt
const robotsTxt = existsSync(join(SITIO, 'robots.txt')) ? readFileSync(join(SITIO, 'robots.txt'), 'utf8') : '';
if (!/User-agent:\s*\*/i.test(robotsTxt) || !/^Disallow:\s*\/\s*$/im.test(robotsTxt)) falla('robots.txt no tiene «User-agent: *» + «Disallow: /»');

// 5. Mensajes de WhatsApp
if (mensajesWa.size !== 7) avisa(`Se encontraron ${mensajesWa.size} mensajes de WhatsApp distintos (se esperaban 7)`);

// 7. Peso
let total = 0;
const pesos = archivos.map((f) => { const s = statSync(f).size; total += s; return [rel(f), s]; }).sort((a, b) => b[1] - a[1]);
if (total > LIMITE) falla(`Peso total ${(total / 1024).toFixed(1)} KB supera 1,5 MB`);

// Manifiesto SHA-256 (formato sha256sum)
if (process.argv.includes('--manifiesto')) {
  const lineas = archivos.map((f) => `${createHash('sha256').update(readFileSync(f)).digest('hex')}  ${rel(f)}`).sort((a, b) => a.slice(66).localeCompare(b.slice(66)));
  const destino = join(dirname(SITIO), 'sitio.sha256');
  writeFileSync(destino, lineas.join('\n') + '\n');
  console.log(`Manifiesto: ${destino} (${lineas.length} archivos)`);
}

// Informe
console.log(`Sitio: ${SITIO}`);
console.log(`Archivos: ${archivos.length} · Peso total: ${(total / 1024).toFixed(1)} KB (límite 1.536 KB)`);
console.log('Más pesados:', pesos.slice(0, 5).map(([f, s]) => `${f} ${(s / 1024).toFixed(1)} KB`).join(' · '));
console.table(resumenPaginas);
console.log(`Mensajes de WhatsApp distintos: ${mensajesWa.size}`);
for (const m of mensajesWa) console.log('  -', m);
if (avisos.length) { console.log('\nAVISOS'); avisos.forEach((a) => console.log('  ·', a)); }
if (fallos.length) { console.log('\nFALLOS'); fallos.forEach((f) => console.log('  ✗', f)); process.exit(1); }
console.log('\nOK: sin fallos.');
