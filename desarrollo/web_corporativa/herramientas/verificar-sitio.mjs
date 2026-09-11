#!/usr/bin/env node
// Verificación estática del sitio corporativo de INDUSTEC. Sin dependencias (Node 18+).
//
// Uso:
//   node herramientas/verificar-sitio.mjs                          -> verifica ../sitio
//   node herramientas/verificar-sitio.mjs --sellar                 -> antes, pone en cada HTML el ?v= vigente de estilos.css y sitio.js
//   node herramientas/verificar-sitio.mjs --manifiesto             -> además escribe sitio.sha256 (para verificar la publicación)
//   node herramientas/verificar-sitio.mjs --sellar --manifiesto    -> lo habitual después de cambiar el CSS o el JS
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
//   8. Caché del CDN. Hostinger guarda css, js e imágenes 7 días y en la raíz no puede haber .htaccess
//      para cambiarlo. Por eso: estilos.css y sitio.js se piden con ?v=<primeros 8 hex del SHA-256>, que
//      tiene que coincidir con el archivo (--sellar lo pone); una referencia interna solo admite ?v=; y todo
//      archivo que cambió desde la ÚLTIMA PUBLICACIÓN tiene que cambiar de nombre o pedirse con ?v=.
//      La última publicación es sitio.publicado.sha256, que solo reescribe verificar-publicacion.mjs cuando
//      termina en OK; sitio.sha256 (--manifiesto) es la lista de lo que se sube. Los .html y el robots.txt
//      no se guardan en el CDN.
//   9. Identidad (pedido de César, 11-sep-2026): no vuelve la ilustración cocina-profesional; cada logo de
//      clientes y marcas lleva alt con el nombre, width, height, loading="lazy" y decoding="async"; ninguna
//      palabra de servicio doméstico (hogar, casa, doméstico/a, particular, electrodoméstico, domicilio,
//      residencial, vivienda, línea blanca) fuera de la línea que aclara que no se atienden, y esa línea está
//      en la portada, en servicios y en contacto; «servicio técnico autorizado» y «distribuidor oficial» solo
//      aparecen en el aviso de las marcas.
//  10. Logos contra fuentes/logos/manifiesto.json (lo escribe procesar.py): la portada y servicios muestran
//      todos los clientes y todas las marcas, en el orden del manifiesto; cada logo lleva el nombre
//      del manifiesto como alt, width/height con la proporción de su archivo y class="compacto" si el
//      manifiesto lo marca así (cuadrado o vertical); y cada archivo de sitio/assets/img/clientes|marcas es
//      copia exacta del de fuentes/logos, sin sobrantes. Las marcas «retenidas» del manifiesto (NOTAS, A6)
//      no pueden aparecer en ninguna página ni en sitio/assets.
import { readdirSync, readFileSync, statSync, writeFileSync, existsSync } from 'node:fs';
import { join, relative, dirname, resolve, sep } from 'node:path';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';

const aqui = dirname(fileURLToPath(import.meta.url));
const SITIO = resolve(process.argv.find((a, i) => i > 1 && !a.startsWith('--')) || join(aqui, '..', 'sitio'));
const MANIFIESTO = join(dirname(SITIO), 'sitio.sha256');            // lo que se sube (lo escribe --manifiesto)
const PUBLICADO = join(dirname(SITIO), 'sitio.publicado.sha256');   // lo publicado (lo escribe verificar-publicacion.mjs)
const LOGOS = join(dirname(SITIO), 'fuentes', 'logos', 'manifiesto.json');
const DOMINIO = 'https://darkviolet-armadillo-872352.hostingersite.com';
const SALUDO = 'Hola INDUSTEC, les escribo desde su página web.';
const FILTRO = 'Atendemos a empresas y cadenas, bajo contrato de servicio. No reparamos equipos domésticos ni atendemos a particulares.';
const PAGINAS_CON_FILTRO = ['index.html', 'servicios/index.html', 'contacto/index.html'];
const PAGINAS_CON_CLIENTES = ['index.html', 'servicios/index.html'];
const PAGINAS_CON_MARCAS = ['index.html', 'servicios/index.html'];
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
// Servicio doméstico: solo puede aparecer en la línea que aclara que no se atiende (p.aviso-filtro).
// «electrodoméstico» va aparte: dentro de la palabra no hay límite de palabra antes de «dom».
const DOMESTICO = /\b(hogar(es)?|casas?|dom[eé]stic[oa]s?|particular(es)?|domicilios?|residencial(es)?|viviendas?|l[ií]nea\s+blanca)\b|electrodom[eé]stic/i;
// Solo dentro del aviso de las marcas (p.logos__aviso): la web no afirma ninguna autorización (NOTAS, B7).
const AUTORIZADO = /servicio\s+t[eé]cnico\s+autorizado|distribuidor(es)?\s+oficial(es)?/i;

const fallos = [];
const avisos = [];
const falla = (m) => fallos.push(m);
const avisa = (m) => avisos.push(m);
const rel = (f) => relative(SITIO, f).split(sep).join('/');
const sha = (f) => createHash('sha256').update(readFileSync(f)).digest('hex');
const hash8 = (f) => sha(f).slice(0, 8);
const entidades = (s) => s.replace(/&quot;/g, '"').replace(/&#39;|&apos;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
const leerManifiesto = (f) => new Map(existsSync(f) ? readFileSync(f, 'utf8').split('\n').map((l) => l.match(/^([0-9a-f]{64}) {2}(.+)$/)).filter(Boolean).map((m) => [m[2], m[1]]) : []);

function listar(dir) {
  return readdirSync(dir, { withFileTypes: true }).flatMap((e) =>
    e.isDirectory() ? listar(join(dir, e.name)) : [join(dir, e.name)]);
}

if (!existsSync(SITIO)) { console.error('No existe la carpeta del sitio:', SITIO); process.exit(2); }

// --sellar: el ?v= de estilos.css y sitio.js pasa a ser el hash vigente del archivo, en todas las páginas.
if (process.argv.includes('--sellar')) {
  for (const p of listar(SITIO).filter((f) => f.endsWith('.html'))) {
    const antes = readFileSync(p, 'utf8');
    const despues = antes.replace(/(\s(?:href|src)=")(\/assets\/[^"?#]+\.(?:css|js))(?:\?v=[^"#]*)?"/g, (m, atr, ruta) => {
      const f = join(SITIO, ruta);
      return existsSync(f) ? `${atr}${ruta}?v=${hash8(f)}"` : m;
    });
    if (despues !== antes) { writeFileSync(p, despues); console.log(`Sellado: ${rel(p)}`); }
  }
}

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
const referencias = []; // { origen, ruta (relativa al sitio), consulta }
function destinoLocal(ruta) {
  // ruta absoluta desde la raíz del sitio, sin query ni ancla
  let f = join(SITIO, decodeURIComponent(ruta));
  if (ruta.endsWith('/')) f = join(f, 'index.html');
  return f;
}
function comprobarConsulta(origen, valor, consulta, f) {
  if (!consulta) return;
  const m = consulta.match(/^v=([0-9a-f]{8})$/);
  if (!m) { falla(`${rel(origen)}: consulta inesperada «?${consulta}» en ${valor} (solo se admite ?v=<8 hex del SHA-256>)`); return; }
  if (m[1] !== hash8(f)) falla(`${rel(origen)}: ${valor} lleva ?v=${m[1]} pero el archivo es ${hash8(f)}: correr con --sellar`);
}
function comprobarInterno(origen, valor) {
  if (!valor.startsWith('/')) { falla(`${rel(origen)}: ruta relativa «${valor}» (usar rutas desde la raíz)`); return; }
  if (valor.startsWith('/ot/') || valor === '/ot') return; // sistema B.IA Soft ERP: externo al sitio
  const [sinAncla, ancla] = valor.split('#');
  const [ruta, consulta] = sinAncla.split('?');
  const f = destinoLocal(ruta || '/');
  if (!existsSync(f) || statSync(f).isDirectory()) { falla(`${rel(origen)}: no existe «${valor}»`); return; }
  referencias.push({ origen, ruta: rel(f), consulta: consulta || '' });
  comprobarConsulta(origen, valor, consulta, f);
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
    const [ruta, consulta] = (url.slice(DOMINIO.length) || '/').split('#')[0].split('?');
    const f = destinoLocal(ruta || '/');
    if (!existsSync(f)) { falla(`${rel(origen)}: ${url} apunta a un archivo que no existe`); return; }
    if (!statSync(f).isDirectory()) { referencias.push({ origen, ruta: rel(f), consulta: consulta || '' }); comprobarConsulta(origen, url, consulta, f); }
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
const manifiestoLogos = existsSync(LOGOS) ? JSON.parse(readFileSync(LOGOS, 'utf8')) : null;
if (!manifiestoLogos) falla(`No existe ${LOGOS}: sin él no se pueden comprobar los logos (lo escribe fuentes/logos/procesar.py)`);
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
    // 8. La hoja de estilos y el JavaScript cambian con cada ajuste: siempre con ?v= (el CDN los guarda 7 días).
    if (/^\/assets\/[^?#]+\.(css|js)(\?|#|$)/.test(v) && !/\?v=/.test(v)) falla(`${nombre}: ${v} sin ?v= (el CDN serviría la copia vieja hasta 7 días; correr con --sellar)`);
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
  let logos = 0;
  const logosPagina = { clientes: [], marcas: [] };
  for (const img of html.match(/<img\b[^>]*>/g) || []) {
    if (!/\salt="/.test(img)) falla(`${nombre}: imagen sin alt: ${img.slice(0, 80)}`);
    // 9. Logos de clientes y marcas: nombre como texto alternativo, tamaño reservado y carga diferida.
    const src = (img.match(/\ssrc="([^"]+)"/) || [])[1] || '';
    if (/^\/assets\/img\/(clientes|marcas)\//.test(src)) {
      logos++;
      const alt = (img.match(/\salt="([^"]*)"/) || [])[1];
      if (!alt || !alt.trim()) falla(`${nombre}: logo sin texto alternativo (debe ser el nombre de la empresa o la marca): ${src}`);
      for (const a of ['width', 'height']) if (!new RegExp(`\\s${a}="\\d+"`).test(img)) falla(`${nombre}: logo sin ${a}: ${src}`);
      if (!/\sloading="lazy"/.test(img)) falla(`${nombre}: logo sin loading="lazy": ${src}`);
      if (!/\sdecoding="async"/.test(img)) falla(`${nombre}: logo sin decoding="async": ${src}`);
      const num = (a) => Number((img.match(new RegExp(`\\s${a}="(\\d+)"`)) || [])[1]);
      logosPagina[src.split('/')[3]].push({ archivo: src.replace('/assets/img/', ''), alt: entidades(alt || ''), w: num('width'), h: num('height'), compacto: /\sclass="[^"]*\bcompacto\b/.test(img) });
    }
  }
  // 10. Logos contra el manifiesto: lista completa y en orden, nombre, proporción y clase «compacto».
  if (manifiestoLogos) {
    for (const grupo of ['clientes', 'marcas']) {
      const esperados = manifiestoLogos[grupo].map((l) => l.archivo);
      const vistos = logosPagina[grupo].map((l) => l.archivo);
      const obligatoria = (grupo === 'clientes' ? PAGINAS_CON_CLIENTES : PAGINAS_CON_MARCAS).includes(nombre);
      if (obligatoria && vistos.join() !== esperados.join()) {
        const faltan = esperados.filter((a) => !vistos.includes(a));
        falla(`${nombre}: la lista de ${grupo} no es la del manifiesto (${esperados.length}, en su orden)${faltan.length ? `; faltan ${faltan.join(', ')}` : '; revisar el orden o los repetidos'}`);
      }
      for (const l of logosPagina[grupo]) {
        const m = manifiestoLogos[grupo].find((x) => x.archivo === l.archivo);
        if (!m) { falla(`${nombre}: ${l.archivo} no está en el manifiesto de logos`); continue; }
        if (l.alt !== m.nombre) falla(`${nombre}: ${l.archivo} lleva alt «${l.alt}» y el manifiesto dice «${m.nombre}»`);
        const r = m.ancho / m.alto;
        if (!(Math.abs(l.w / l.h - r) <= 0.03 * r)) falla(`${nombre}: ${l.archivo} con width/height ${l.w}×${l.h}, que no es la proporción del archivo (${m.ancho}×${m.alto})`);
        if (l.compacto !== Boolean(m.compacto)) falla(`${nombre}: ${l.archivo} ${m.compacto ? 'necesita' : 'no lleva'} class="compacto" (proporción ${r.toFixed(2)}; el manifiesto marca compactos los menores que ${manifiestoLogos.compacto_si_proporcion_menor_que})`);
      }
    }
    // Marcas retenidas (NOTAS, A6): ni su logo ni su nombre como texto alternativo.
    for (const m of manifiestoLogos.retenidas || []) {
      if (html.includes(`/${m.archivo.split('/').pop()}`) || html.includes(`alt="${m.nombre}"`)) falla(`${nombre}: muestra ${m.nombre}, que está retenida hasta que César la confirme (NOTAS, A6)`);
    }
  }
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
  // 9. Identidad: texto visible + textos de atributos (alt, title, aria-label, content, placeholder) + JSON-LD.
  const legible = (fuente) => fuente
    .replace(/<script(?! type="application\/ld\+json")[\s\S]*?<\/script>/g, ' ').replace(/<style[\s\S]*?<\/style>/g, ' ')
    .replace(/<!--[\s\S]*?-->/g, ' ').replace(/https?:\/\/[^\s"'<>]+/g, ' ')
    .replace(/<[^>]+>/g, (tag) => ` ${[...tag.matchAll(/\s(?:alt|title|aria-label|content|placeholder)="([^"]*)"/g)].map((m) => m[1]).join(' ')} `);
  const sinFiltro = legible(html.replace(/<p class="aviso-filtro"[^>]*>[\s\S]*?<\/p>/g, ' '));
  const dom = sinFiltro.match(DOMESTICO);
  if (dom) falla(`${nombre}: «${dom[0]}» sugiere servicio doméstico o a particulares (solo va en la línea p.aviso-filtro)`);
  if (PAGINAS_CON_FILTRO.includes(nombre)) {
    const linea = (html.match(/<p class="aviso-filtro"[^>]*>([\s\S]*?)<\/p>/) || [])[1];
    if (!linea || linea.replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim() !== FILTRO) falla(`${nombre}: falta la línea que filtra al cliente particular: «${FILTRO}»`);
  }
  const aut = legible(html.replace(/<p class="[^"]*\blogos__aviso\b[^"]*"[^>]*>[\s\S]*?<\/p>/g, ' ')).match(AUTORIZADO);
  if (aut) falla(`${nombre}: «${aut[0]}» fuera del aviso de las marcas (la web no afirma ninguna autorización)`);
  resumenPaginas.push({ pagina: nombre, lang, title: `${title} (${title?.length ?? 0})`, description: desc?.length ?? 0, robots, logos });
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
  const dom = js.match(DOMESTICO);
  if (dom) falla(`${rel(f)}: «${dom[0]}» sugiere servicio doméstico o a particulares`);
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
// 9. La ilustración de cocina de casa (cocina-profesional.svg) no vuelve: ni el archivo ni referencias.
for (const f of archivos) {
  if (/cocina-profesional/i.test(rel(f))) falla(`${rel(f)}: la ilustración de cocina de casa no debe volver al sitio`);
  else if (/\.(html|css|js|svg|txt)$/.test(f) && /cocina-profesional/i.test(readFileSync(f, 'utf8'))) falla(`${rel(f)}: referencia a cocina-profesional`);
}
// 10. Los archivos de logos del sitio son copia exacta de fuentes/logos y no sobra ninguno.
if (manifiestoLogos) {
  const deManifiesto = new Set();
  for (const grupo of ['clientes', 'marcas']) {
    for (const m of manifiestoLogos[grupo]) {
      deManifiesto.add(`assets/img/${m.archivo}`);
      const enSitio = join(SITIO, 'assets', 'img', m.archivo);
      const enFuentes = join(dirname(LOGOS), m.archivo);
      if (!existsSync(enSitio)) falla(`assets/img/${m.archivo}: está en el manifiesto de logos y falta en el sitio`);
      else if (!existsSync(enFuentes) || sha(enSitio) !== sha(enFuentes)) falla(`assets/img/${m.archivo}: no es copia exacta de fuentes/logos/${m.archivo} (volver a copiar después de procesar.py)`);
    }
  }
  for (const f of archivos) if (/^assets\/img\/(clientes|marcas)\//.test(rel(f)) && !deManifiesto.has(rel(f))) falla(`${rel(f)}: sobra (no está en el manifiesto de logos)`);
  const retenidas = new Set((manifiestoLogos.retenidas || []).map((m) => m.archivo.split('/').pop()));
  for (const f of archivos) if (/^assets\//.test(rel(f)) && retenidas.has(rel(f).split('/').pop())) falla(`${rel(f)}: logo retenido (NOTAS, A6), no se publica`);
}
// robots.txt
const robotsTxt = existsSync(join(SITIO, 'robots.txt')) ? readFileSync(join(SITIO, 'robots.txt'), 'utf8') : '';
if (!/User-agent:\s*\*/i.test(robotsTxt) || !/^Disallow:\s*\/\s*$/im.test(robotsTxt)) falla('robots.txt no tiene «User-agent: *» + «Disallow: /»');

// 5. Mensajes de WhatsApp
if (mensajesWa.size !== 7) avisa(`Se encontraron ${mensajesWa.size} mensajes de WhatsApp distintos (se esperaban 7)`);

// 8. Caché del CDN: lo que cambió desde la última publicación y conserva su nombre. La base es
//    sitio.publicado.sha256 (lo publicado), no sitio.sha256 (lo que se va a subir): si fuera este, dos rondas
//    de cambios entre publicaciones dejarían pasar un archivo cambiado con el mismo nombre.
const publicado = leerManifiesto(PUBLICADO);
if (!publicado.size) avisa(`No hay ${relative(dirname(SITIO), PUBLICADO)}: no se puede aplicar la regla de caché ni listar lo que hay que borrar del servidor`);
const cambiados = [];
for (const f of archivos) {
  const r = rel(f);
  if (r.endsWith('.html') || r === 'robots.txt' || !publicado.has(r) || publicado.get(r) === sha(f)) continue;
  cambiados.push(r);
  const refs = referencias.filter((x) => x.ruta === r);
  const sinV = refs.filter((x) => !/^v=/.test(x.consulta));
  if (!refs.length) avisa(`${r}: cambió desde la última publicación y nadie lo referencia (el CDN puede tener la copia vieja)`);
  else if (sinV.length) falla(`${r}: cambió desde la última publicación y se sigue pidiendo con el mismo nombre en ${[...new Set(sinV.map((x) => rel(x.origen)))].join(', ')}: el CDN serviría la copia vieja hasta 7 días. Cambiarle el nombre o pedirlo con ?v=`);
}
const retirados = [...publicado.keys()].filter((r) => !existsSync(join(SITIO, r)));

// 7. Peso
let total = 0;
const pesos = archivos.map((f) => { const s = statSync(f).size; total += s; return [rel(f), s]; }).sort((a, b) => b[1] - a[1]);
if (total > LIMITE) falla(`Peso total ${(total / 1024).toFixed(1)} KB supera 1,5 MB`);

// Informe (antes de reescribir el manifiesto, contra el que se compara lo cambiado)
console.log(`Sitio: ${SITIO}`);
console.log(`Archivos: ${archivos.length} · Peso total: ${(total / 1024).toFixed(1)} KB (límite 1.536 KB)`);
console.log('Más pesados:', pesos.slice(0, 5).map(([f, s]) => `${f} ${(s / 1024).toFixed(1)} KB`).join(' · '));
console.table(resumenPaginas);
console.log(`Mensajes de WhatsApp distintos: ${mensajesWa.size}`);
for (const m of mensajesWa) console.log('  -', m);
if (publicado.size) {
  console.log(`\nFrente a sitio.publicado.sha256 (última publicación, ${publicado.size} archivos): ${cambiados.length} archivo(s) cambiado(s) con el mismo nombre que el CDN guarda${cambiados.length ? ': ' + cambiados.join(', ') : ''}.`);
  if (retirados.length) console.log(`Ya no están en sitio/ (BORRAR del servidor al publicar): ${retirados.join(', ')}`);
}

// Manifiesto SHA-256 (formato sha256sum)
if (process.argv.includes('--manifiesto') && !fallos.length) {
  const lineas = archivos.map((f) => `${sha(f)}  ${rel(f)}`).sort((a, b) => a.slice(66).localeCompare(b.slice(66)));
  writeFileSync(MANIFIESTO, lineas.join('\n') + '\n');
  console.log(`Manifiesto: ${MANIFIESTO} (${lineas.length} archivos)`);
} else if (process.argv.includes('--manifiesto')) {
  console.log('Manifiesto: NO se reescribió porque hay fallos.');
}

if (avisos.length) { console.log('\nAVISOS'); avisos.forEach((a) => console.log('  ·', a)); }
if (fallos.length) { console.log('\nFALLOS'); fallos.forEach((f) => console.log('  ✗', f)); process.exit(1); }
console.log('\nOK: sin fallos.');
