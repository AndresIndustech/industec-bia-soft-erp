// T1.1 - Inventario de origen con hash SHA-256 (v3: rutas por segmentos, timeout por archivo)
// Recorre G:\Mi unidad y D:\INDUSTECH IA\ENTRADAS IA (SOLO LECTURA)
// Produce SALIDAS IA\CALIDAD\INVENTARIO_ORIGEN.csv
// NOTA: las rutas se construyen con path.join sobre segmentos simples,
// nunca como una sola cadena con backslashes literales (evita mangling de escapes).
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOTS = [
  { label: 'DRIVE', root: path.join('G:', 'Mi unidad') },
  { label: 'ENTRADAS_IA', root: path.join('D:', 'INDUSTECH IA', 'ENTRADAS IA') },
];
const OUT_DIR = path.join('D:', 'INDUSTECH IA', 'SALIDAS IA', 'CALIDAD');
const OUT_CSV = path.join(OUT_DIR, 'INVENTARIO_ORIGEN.csv');
const OUT_LOG = path.join(OUT_DIR, 'INVENTARIO_ORIGEN.log');
const OUT_PROGRESS = path.join(OUT_DIR, 'INVENTARIO_PROGRESO.log');
const FILE_TIMEOUT_MS = 20000; // si un archivo tarda mas de 20s (tipico de placeholders de nube), se salta

// Verificacion de cordura al arrancar: si alguna ruta no contiene separador, algo esta mal.
for (const p of [OUT_CSV, OUT_LOG, OUT_PROGRESS, ...ROOTS.map(r => r.root)]) {
  if (!p.includes(path.sep) && !p.includes('/')) {
    throw new Error('RUTA_SOSPECHOSA_SIN_SEPARADOR: ' + p);
  }
}

function csvEscape(s) {
  if (s == null) return '';
  s = String(s);
  if (/[",\n\r]/.test(s)) return '"' + s.replace(/"/g, '""') + '"';
  return s;
}

function logProgress(line) {
  fs.appendFileSync(OUT_PROGRESS, `[${new Date().toISOString()}] ${line}\n`, 'utf8');
}

function sha256FileWithTimeout(filePath, timeoutMs) {
  return new Promise((resolve) => {
    let settled = false;
    const hash = crypto.createHash('sha256');
    let stream;
    const timer = setTimeout(() => {
      if (settled) return;
      settled = true;
      if (stream) stream.destroy();
      resolve({ ok: false, reason: 'TIMEOUT' });
    }, timeoutMs);

    try {
      stream = fs.createReadStream(filePath, { highWaterMark: 1024 * 1024 });
    } catch (e) {
      clearTimeout(timer);
      resolve({ ok: false, reason: 'OPEN_ERROR:' + (e.code || e.message) });
      return;
    }
    stream.on('data', (chunk) => hash.update(chunk));
    stream.on('end', () => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      resolve({ ok: true, hash: hash.digest('hex') });
    });
    stream.on('error', (err) => {
      if (settled) return;
      settled = true;
      clearTimeout(timer);
      resolve({ ok: false, reason: 'STREAM_ERROR:' + (err.code || err.message) });
    });
  });
}

function walk(dir, out) {
  let entries;
  try {
    entries = fs.readdirSync(dir, { withFileTypes: true });
  } catch (e) {
    out.errors.push(`READDIR_FAIL\t${dir}\t${e.code || e.message}`);
    return;
  }
  for (const e of entries) {
    const full = path.join(dir, e.name);
    if (e.isSymbolicLink()) continue;
    if (e.isDirectory()) {
      walk(full, out);
    } else if (e.isFile()) {
      out.files.push(full);
    }
  }
}

async function main() {
  fs.mkdirSync(OUT_DIR, { recursive: true });
  fs.writeFileSync(OUT_PROGRESS, '', 'utf8'); // reset
  const startTime = new Date().toISOString();
  const logLines = [`INICIO ${startTime}`, `OUT_CSV=${OUT_CSV}`, `OUT_LOG=${OUT_LOG}`];
  const rows = [];
  const errors = [];
  let totalBytes = 0;
  let idx = 0;
  let timeouts = 0;

  logProgress('=== INICIO INVENTARIO ===');
  logProgress(`OUT_CSV=${OUT_CSV}`);
  for (const { label, root } of ROOTS) logProgress(`RAIZ ${label} -> ${root}`);

  for (const { label, root } of ROOTS) {
    if (!fs.existsSync(root)) {
      logLines.push(`RAIZ NO EXISTE: ${label} -> ${root}`);
      logProgress(`RAIZ NO EXISTE: ${label} -> ${root}`);
      continue;
    }
    const out = { files: [], errors: [] };
    logProgress(`Enumerando ${label} (${root})...`);
    walk(root, out);
    errors.push(...out.errors.map(x => `${label}\t${x}`));
    logLines.push(`${label}: ${out.files.length} archivos encontrados en ${root}`);
    logProgress(`${label}: ${out.files.length} archivos encontrados`);

    for (const filePath of out.files) {
      idx++;
      let stat;
      try {
        stat = fs.statSync(filePath);
      } catch (e) {
        errors.push(`${label}\tSTAT_FAIL\t${filePath}\t${e.code || e.message}`);
        continue;
      }
      const result = await sha256FileWithTimeout(filePath, FILE_TIMEOUT_MS);
      let hash = '';
      if (result.ok) {
        hash = result.hash;
      } else {
        if (result.reason === 'TIMEOUT') timeouts++;
        errors.push(`${label}\tHASH_FAIL\t${filePath}\t${result.reason}`);
        logProgress(`[${idx}] FALLO: ${filePath} -> ${result.reason}`);
      }
      const rel = path.relative(root, filePath);
      const ext = path.extname(filePath).toLowerCase();
      rows.push({
        origen: label,
        raiz: root,
        ruta_relativa: rel,
        ruta_completa: filePath,
        nombre: path.basename(filePath),
        extension: ext,
        tamano_bytes: stat.size,
        fecha_modificacion: stat.mtime.toISOString(),
        sha256: hash,
      });
      totalBytes += stat.size;
      if (idx % 250 === 0) {
        const msg = `  ...${idx} archivos procesados (${(totalBytes / 1e9).toFixed(2)} GB), ${timeouts} timeouts`;
        console.log(msg);
        logProgress(msg);
      }
    }
  }

  const header = ['origen', 'raiz', 'ruta_relativa', 'ruta_completa', 'nombre', 'extension', 'tamano_bytes', 'fecha_modificacion', 'sha256'];
  const csvLines = [header.join(',')];
  for (const r of rows) {
    csvLines.push(header.map(h => csvEscape(r[h])).join(','));
  }
  fs.writeFileSync(OUT_CSV, '\uFEFF' + csvLines.join('\r\n'), 'utf8');

  const endTime = new Date().toISOString();
  logLines.push(`FIN ${endTime}`);
  logLines.push(`TOTAL ARCHIVOS: ${rows.length}`);
  logLines.push(`TOTAL BYTES: ${totalBytes} (${(totalBytes / 1e9).toFixed(3)} GB)`);
  logLines.push(`TIMEOUTS: ${timeouts}`);
  logLines.push(`ERRORES: ${errors.length}`);
  logLines.push('--- ERRORES DETALLE ---');
  logLines.push(...errors);
  fs.writeFileSync(OUT_LOG, logLines.join('\n'), 'utf8');
  logProgress('=== FIN INVENTARIO ===');

  console.log('=== RESUMEN T1.1 ===');
  console.log('CSV escrito en:', OUT_CSV);
  console.log('Total archivos:', rows.length);
  console.log('Total GB:', (totalBytes / 1e9).toFixed(3));
  console.log('Timeouts:', timeouts);
  console.log('Errores:', errors.length);

  const byOrigin = {};
  for (const r of rows) {
    byOrigin[r.origen] = byOrigin[r.origen] || { count: 0, bytes: 0 };
    byOrigin[r.origen].count++;
    byOrigin[r.origen].bytes += r.tamano_bytes;
  }
  console.log('--- Por origen ---');
  for (const [k, v] of Object.entries(byOrigin)) {
    console.log(`  ${k}: ${v.count} archivos, ${(v.bytes / 1e9).toFixed(3)} GB`);
  }
}

main().catch(e => {
  console.error('FATAL', e);
  try { logProgress('FATAL: ' + e.message); } catch (_) {}
  process.exit(1);
});
