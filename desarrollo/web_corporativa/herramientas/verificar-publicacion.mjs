#!/usr/bin/env node
// Verifica por hash SHA-256 que lo publicado en el dominio sea, byte a byte, lo que hay en sitio/, y que un
// visitante reciba eso mismo a través del CDN de Hostinger. Sin dependencias (Node 18+).
//
// Uso:
//   node herramientas/verificar-publicacion.mjs                       -> dominio temporal y ../sitio
//   node herramientas/verificar-publicacion.mjs https://www.industec.me
//   --sin-registro   no reescribe sitio.publicado.sha256 aunque termine en OK (para pruebas)
//
// Comprueba, en este orden:
//   1. EL ORIGEN: cada archivo de sitio/, pedido con ?v=<marca de tiempo> y no-cache (salta la caché del CDN),
//      con su Content-Type y, en las imágenes, su formato real. Los PNG y JPG que el CDN recomprime salen con ⚠
//      y el original se compara en el disco del servidor (LEEME, sección 8).
//   2. LO QUE RECIBE UN VISITANTE, sin marca de tiempo: las cinco URL limpias sin consulta, y estilos.css y
//      sitio.js tal como los pide el HTML (con su ?v=). Si no coinciden, el CDN guarda la versión vieja (o ignora
//      la consulta ?v=): purgar la caché en hPanel y volver a correr. Imprime cache-control, age y las cabeceras
//      de caché del CDN.
//   3. Que /nosotros (sin barra) redirija a /nosotros/ y que /ot/login.php siga respondiendo.
//   4. Lo retirado desde la última publicación (sitio.publicado.sha256 que no está en sitio/): se borra del
//      servidor SOLO cuando / ya sirve el index.html nuevo; si no, la portada vieja en caché lo seguiría pidiendo.
//      Mientras siga en el servidor, termina con código 2 («FALTA») y no registra la publicación.
// Si todo cuadra, reescribe sitio.publicado.sha256 con lo publicado: es la base de la regla de caché de
// verificar-sitio.mjs. sitio.sha256 sigue siendo la lista de lo que se sube (para sha256sum -c en el servidor).
import { readdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { join, relative, sep, dirname, resolve, extname } from 'node:path';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';

const aqui = dirname(fileURLToPath(import.meta.url));
const args = process.argv.slice(2);
const posicionales = args.filter((a) => !a.startsWith('--'));
const BASE = (posicionales.find((a) => /^https?:\/\//.test(a)) || 'https://darkviolet-armadillo-872352.hostingersite.com').replace(/\/+$/, '');
const SITIO = resolve(posicionales.find((a) => !/^https?:\/\//.test(a)) || join(aqui, '..', 'sitio'));
const PUBLICADO = join(dirname(SITIO), 'sitio.publicado.sha256');
const REGISTRAR = !args.includes('--sin-registro');
const PAGINAS = [['/', 'index.html'], ['/nosotros/', 'nosotros/index.html'], ['/servicios/', 'servicios/index.html'], ['/contacto/', 'contacto/index.html'], ['/acceso/', 'acceso/index.html']];
const TIPOS = { '.html': ['text/html'], '.css': ['text/css'], '.js': ['text/javascript', 'application/javascript', 'application/x-javascript'], '.svg': ['image/svg+xml'], '.png': ['image/png'], '.jpg': ['image/jpeg'], '.webp': ['image/webp'], '.txt': ['text/plain'] };
const FIRMAS = { '.png': [0x89, 0x50, 0x4e, 0x47], '.jpg': [0xff, 0xd8, 0xff], '.webp': [0x52, 0x49, 0x46, 0x46] };
const sha = (buf) => createHash('sha256').update(buf).digest('hex');
const listar = (d) => readdirSync(d, { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? listar(join(d, e.name)) : [join(d, e.name)]));
const url = (rel) => `${BASE}/${rel.split('/').map(encodeURIComponent).join('/')}`;
const marca = Date.now();
// Al origen: consulta única y no-cache, para que el CDN no conteste con su copia.
const alOrigen = (u, extra = {}) => fetch(u, { cache: 'no-store', headers: { 'Cache-Control': 'no-cache' }, ...extra });
// Como un visitante: sin consulta ni no-cache, con cabeceras de navegador; recibe lo que tenga el CDN.
const comoVisitante = (u, extra = {}) => fetch(u, { headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) verificar-publicacion', Accept: 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8' }, ...extra });
const cacheDe = (r) => {
  const partes = [`cache-control=${r.headers.get('cache-control') ?? '—'}`, `age=${r.headers.get('age') ?? '—'}`];
  for (const [k, v] of r.headers) if (k !== 'cache-control' && /cache|cdn|hcdn|^via$/i.test(k)) partes.push(`${k}=${v}`);
  return partes.join(' · ');
};

let problemas = 0;
let avisos = 0;
let pendientes = 0;
const mal = (m) => { problemas++; console.log('✗', m); };
const aviso = (m) => { avisos++; console.log('⚠', m); };
const bien = (m) => console.log('✓', m);

console.log(`Comparando ${SITIO}\ncon         ${BASE}\n\n1. El origen (con ?v=${marca}, salta la caché del CDN)`);
const archivos = listar(SITIO).map((f) => relative(SITIO, f).split(sep).join('/')).sort();
for (const rel of archivos) {
  const local = sha(readFileSync(join(SITIO, rel)));
  const ext = extname(rel).toLowerCase();
  try {
    const r = await alOrigen(`${url(rel)}?v=${marca}`, { redirect: 'manual' });
    if (r.status !== 200) { mal(`${rel}: HTTP ${r.status}`); continue; }
    const cuerpo = Buffer.from(await r.arrayBuffer());
    const tipo = (r.headers.get('content-type') || '').split(';')[0].trim().toLowerCase();
    const remoto = sha(cuerpo);
    // En los dominios temporales *.hostingersite.com el robots.txt es de Hostinger: contenido y cabeceras suyos
    // (el 11-sep-2026 lo mandaba con «text/plain, text/plain»). Con el nuestro, la regla vuelve a ser estricta.
    const robotsAjeno = rel === 'robots.txt' && remoto !== local && /\.hostingersite\.com$/.test(new URL(BASE).hostname);
    if (TIPOS[ext] && !TIPOS[ext].includes(tipo) && !robotsAjeno) mal(`${rel}: se sirve con Content-Type «${tipo || 'ninguno'}» y debe ser ${TIPOS[ext][0]}${ext === '.jpg' ? ' (es lo que declara og:image:type)' : ''}`);
    if (FIRMAS[ext] && !FIRMAS[ext].every((b, i) => cuerpo[i] === b)) mal(`${rel}: lo que llega no es un ${ext.slice(1).toUpperCase()} (el CDN le cambió el formato)`);
    if (remoto === local) bien(rel);
    // Diferencias que pone el CDN de Hostinger y no la subida (medido el 11-sep-2026, con el disco del servidor
    // cuadrando con sitio.sha256): recomprime los PNG al vuelo (y se trata igual a los JPG), y en los dominios
    // temporales *.hostingersite.com sirve su propio robots.txt.
    else if (ext === '.png' || ext === '.jpg') aviso(`${rel}: el CDN lo recomprime (publicado ${remoto.slice(0, 12)}…, ${tipo}); el original se compara en el disco del servidor con sha256sum -c (LEEME, sección 8)`);
    else if (robotsAjeno) aviso(`robots.txt: en el dominio temporal lo sirve Hostinger y no el subido (Content-Type «${tipo}»); mientras tanto protege el noindex de cada página`);
    else mal(`${rel}: el hash no coincide (local ${local.slice(0, 12)}…, publicado ${remoto.slice(0, 12)}…)`);
  } catch (e) { mal(`${rel}: ${e.message}`); }
}

console.log('\nURL limpias en el origen');
for (const [ruta, archivo] of PAGINAS) {
  try {
    const r = await alOrigen(`${BASE}${ruta}?v=${marca}`);
    const igual = r.status === 200 && sha(Buffer.from(await r.arrayBuffer())) === sha(readFileSync(join(SITIO, archivo)));
    igual ? bien(`${ruta} sirve ${archivo}`) : mal(`${ruta}: HTTP ${r.status}${r.status === 200 ? ' pero no es el index.html subido (¿hay un index.php o default.php en la raíz?)' : ''}`);
  } catch (e) { mal(`${ruta}: ${e.message}`); }
}

console.log('\n2. Lo que recibe un visitante (sin marca de tiempo: pasa por la caché del CDN)');
let portadaNueva = false;
for (const [ruta, archivo] of PAGINAS) {
  try {
    const r = await comoVisitante(`${BASE}${ruta}`, { redirect: 'manual' });
    const igual = r.status === 200 && sha(Buffer.from(await r.arrayBuffer())) === sha(readFileSync(join(SITIO, archivo)));
    if (ruta === '/') portadaNueva = igual;
    igual ? bien(`${ruta} sirve el ${archivo} subido   [${cacheDe(r)}]`)
      : mal(`${ruta}: ${r.status === 200 ? `el CDN entrega una versión que no es el ${archivo} subido: purgar la caché en hPanel y volver a correr` : `HTTP ${r.status}`}   [${cacheDe(r)}]`);
  } catch (e) { mal(`${ruta}: ${e.message}`); }
}
const pedidos = new Set();
for (const [, archivo] of PAGINAS) {
  for (const m of readFileSync(join(SITIO, archivo), 'utf8').matchAll(/\s(?:href|src)="(\/assets\/[^"?#]+\.(?:css|js)\?v=[0-9a-f]{8})"/g)) pedidos.add(m[1]);
}
for (const ruta of pedidos) {
  const archivo = ruta.split('?')[0].slice(1);
  try {
    const r = await comoVisitante(`${BASE}${ruta}`);
    const igual = r.status === 200 && sha(Buffer.from(await r.arrayBuffer())) === sha(readFileSync(join(SITIO, archivo)));
    igual ? bien(`${ruta} (como lo pide el HTML)   [${cacheDe(r)}]`)
      : mal(`${ruta}: ${r.status === 200 ? 'el CDN entrega una copia distinta de la subida a quien lo pide como el HTML (¿ignora la consulta ?v=?): purgar la caché en hPanel; si persiste, pasar a nombres con hash' : `HTTP ${r.status}`}   [${cacheDe(r)}]`);
  } catch (e) { mal(`${ruta}: ${e.message}`); }
}

console.log('\n3. Redirección y sistema');
try {
  const r = await alOrigen(`${BASE}/nosotros`, { redirect: 'manual' });
  const destino = r.headers.get('location') || '';
  [301, 302].includes(r.status) && destino.endsWith('/nosotros/') ? bien(`/nosotros redirige a /nosotros/ (HTTP ${r.status})`) : mal(`/nosotros no redirige a /nosotros/ (HTTP ${r.status} ${destino})`);
} catch (e) { mal(`/nosotros: ${e.message}`); }
try {
  const r = await alOrigen(`${BASE}/ot/login.php`, { redirect: 'manual' });
  [200, 301, 302, 303].includes(r.status) ? bien(`/ot/login.php responde (HTTP ${r.status})`) : mal(`/ot/login.php responde HTTP ${r.status}: revisar que la subida no haya tocado ot/`);
} catch (e) { mal(`/ot/login.php: ${e.message}`); }

console.log('\n4. Retirados desde la última publicación (sitio.publicado.sha256)');
if (!existsSync(PUBLICADO)) aviso('No existe sitio.publicado.sha256: no se sabe qué hay que borrar del servidor');
else {
  const antes = readFileSync(PUBLICADO, 'utf8').split('\n').map((l) => l.match(/^[0-9a-f]{64} {2}(.+)$/)).filter(Boolean).map((m) => m[1]);
  const retirados = antes.filter((r) => !archivos.includes(r));
  if (!retirados.length) bien('nada que borrar');
  for (const rel of retirados) {
    try {
      const r = await alOrigen(`${url(rel)}?v=${marca}`, { redirect: 'manual' });
      if ([404, 410].includes(r.status)) { bien(`${rel}: ya no está en el servidor (HTTP ${r.status})`); continue; }
      pendientes++;
      console.log(portadaNueva
        ? `→ ${rel}: sigue en el servidor (HTTP ${r.status}). / ya sirve el index.html nuevo: BORRARLO ahora (LEEME, sección 9.4) y volver a correr`
        : `→ ${rel}: sigue en el servidor (HTTP ${r.status}). Todavía NO borrarlo: / aún no sirve el index.html nuevo y la portada vieja en caché lo pide`);
    } catch (e) { mal(`${rel}: ${e.message}`); }
  }
}

if (problemas) {
  console.log(`\n${problemas} problema(s). Si solo fallan .html/.css/.js y el CDN de Hostinger tiene activada la minificación, desactivarla o volver a subir. Si falla solo el paso 2, purgar la caché del CDN en hPanel y volver a correr.`);
  process.exit(1);
}
if (pendientes) {
  console.log(`\nFALTA: ${pendientes} archivo(s) retirado(s) por borrar del servidor. sitio.publicado.sha256 no se actualiza hasta que no quede ninguno.`);
  process.exit(2);
}
console.log(`\nOK: lo publicado coincide con sitio/, un visitante recibe lo mismo y el sistema sigue respondiendo${avisos ? ` (${avisos} diferencia(s) que pone el CDN, marcadas con ⚠)` : ''}.`);
if (REGISTRAR) {
  const lineas = archivos.map((rel) => `${sha(readFileSync(join(SITIO, rel)))}  ${rel}`).sort((a, b) => a.slice(66).localeCompare(b.slice(66)));
  writeFileSync(PUBLICADO, lineas.join('\n') + '\n');
  console.log(`Registrado: ${PUBLICADO} (${lineas.length} archivos), la base de la regla de caché de verificar-sitio.mjs.`);
} else console.log('(--sin-registro: sitio.publicado.sha256 no se tocó)');
