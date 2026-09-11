#!/usr/bin/env node
// Verifica por hash SHA-256 que lo publicado en el dominio sea, byte a byte, lo que hay en sitio/.
// Sin dependencias (Node 18+).
//
// Uso:
//   node herramientas/verificar-publicacion.mjs                       -> dominio temporal y ../sitio
//   node herramientas/verificar-publicacion.mjs https://www.industec.me
//
// Además comprueba:
//   - que las cinco páginas respondan en su URL limpia (/, /nosotros/, …) con el mismo contenido;
//   - que /nosotros (sin barra) redirija a /nosotros/ (lo hace Apache solo, sin .htaccess);
//   - que el sistema B.IA Soft ERP siga respondiendo en /ot/login.php (la subida no debe tocarlo).
import { readdirSync, readFileSync } from 'node:fs';
import { join, relative, sep, dirname, resolve } from 'node:path';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';

const aqui = dirname(fileURLToPath(import.meta.url));
const args = process.argv.slice(2);
const BASE = (args.find((a) => /^https?:\/\//.test(a)) || 'https://darkviolet-armadillo-872352.hostingersite.com').replace(/\/+$/, '');
const SITIO = resolve(args.find((a) => !/^https?:\/\//.test(a)) || join(aqui, '..', 'sitio'));
const sha = (buf) => createHash('sha256').update(buf).digest('hex');
const listar = (d) => readdirSync(d, { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? listar(join(d, e.name)) : [join(d, e.name)]));
const marca = Date.now();
const traer = (url, opciones = {}) => fetch(url, { cache: 'no-store', headers: { 'Cache-Control': 'no-cache' }, ...opciones });

let problemas = 0;
let avisos = 0;
const mal = (m) => { problemas++; console.log('✗', m); };
const aviso = (m) => { avisos++; console.log('⚠', m); };
const bien = (m) => console.log('✓', m);

console.log(`Comparando ${SITIO}\ncon         ${BASE}\n`);
const archivos = listar(SITIO).map((f) => relative(SITIO, f).split(sep).join('/')).sort();
for (const rel of archivos) {
  const local = sha(readFileSync(join(SITIO, rel)));
  const url = `${BASE}/${rel.split('/').map(encodeURIComponent).join('/')}?v=${marca}`;
  try {
    const r = await traer(url, { redirect: 'manual' });
    if (r.status !== 200) { mal(`${rel}: HTTP ${r.status}`); continue; }
    const remoto = sha(Buffer.from(await r.arrayBuffer()));
    if (remoto === local) bien(rel);
    // Dos diferencias que pone el CDN de Hostinger y no la subida (medido el 11-sep-2026,
    // con el disco del servidor cuadrando con sitio.sha256): recomprime los PNG al vuelo,
    // y en los dominios temporales *.hostingersite.com sirve su propio robots.txt.
    else if (rel.endsWith('.png')) aviso(`${rel}: el CDN lo recomprime (publicado ${remoto.slice(0, 12)}…); el original se compara en el disco del servidor (LEEME, sección 8)`);
    else if (rel === 'robots.txt' && /\.hostingersite\.com$/.test(new URL(BASE).hostname)) aviso('robots.txt: en el dominio temporal lo sirve Hostinger y no el subido; mientras tanto protege el noindex de cada página');
    else mal(`${rel}: el hash no coincide (local ${local.slice(0, 12)}…, publicado ${remoto.slice(0, 12)}…)`);
  } catch (e) { mal(`${rel}: ${e.message}`); }
}

console.log('\nURL limpias');
for (const [ruta, archivo] of [['/', 'index.html'], ['/nosotros/', 'nosotros/index.html'], ['/servicios/', 'servicios/index.html'], ['/contacto/', 'contacto/index.html'], ['/acceso/', 'acceso/index.html']]) {
  try {
    const r = await traer(`${BASE}${ruta}?v=${marca}`);
    const igual = r.status === 200 && sha(Buffer.from(await r.arrayBuffer())) === sha(readFileSync(join(SITIO, archivo)));
    igual ? bien(`${ruta} sirve ${archivo}`) : mal(`${ruta}: HTTP ${r.status}${r.status === 200 ? ' pero no es el index.html subido (¿hay un index.php o default.php en la raíz?)' : ''}`);
  } catch (e) { mal(`${ruta}: ${e.message}`); }
}

try {
  const r = await traer(`${BASE}/nosotros`, { redirect: 'manual' });
  const destino = r.headers.get('location') || '';
  [301, 302].includes(r.status) && destino.endsWith('/nosotros/') ? bien(`/nosotros redirige a /nosotros/ (HTTP ${r.status})`) : mal(`/nosotros no redirige a /nosotros/ (HTTP ${r.status} ${destino})`);
} catch (e) { mal(`/nosotros: ${e.message}`); }

console.log('\nSistema B.IA Soft ERP');
try {
  const r = await traer(`${BASE}/ot/login.php`, { redirect: 'manual' });
  [200, 301, 302, 303].includes(r.status) ? bien(`/ot/login.php responde (HTTP ${r.status})`) : mal(`/ot/login.php responde HTTP ${r.status}: revisar que la subida no haya tocado ot/`);
} catch (e) { mal(`/ot/login.php: ${e.message}`); }

console.log(problemas
  ? `\n${problemas} problema(s). Si solo fallan .html/.css/.js y el CDN de Hostinger tiene activada la minificación, desactivarla o volver a subir.`
  : `\nOK: lo publicado coincide con sitio/ y el sistema sigue respondiendo${avisos ? ` (${avisos} diferencia(s) que pone el CDN, marcadas con ⚠)` : ''}.`);
process.exit(problemas ? 1 : 0);
