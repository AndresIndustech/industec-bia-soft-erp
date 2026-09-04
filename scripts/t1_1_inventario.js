// T1.1 - Inventario de origen con hash SHA-256
// Recorre G:\Mi unidad y D:\INDUSTECH IA\ENTRADAS IA (SOLO LECTURA)
// Produce SALIDAS IA\CALIDAD\INVENTARIO_ORIGEN.csv
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOTS = [
  { label: 'DRIVE', root: 'G:\Mi unidad' },
  { label: 'ENTRADAS_IA', root: 'D:\INDUSTECH IA\ENTRADAS IA' },
];
const OUT_CSV = 'D:\INDUSTECH IA\SALIDAS IA\CALIDAD\INVENTARIO_ORIGEN.csv';
const OUT_LOG = 'D:\INDUSTECH IA\SALIDAS IA\CALIDAD\INVENTARIO_ORIGEN.log';

function csvEscape(s) {
  if (s == null) return '';
  s = String(s);
  if (/[",\n\r]/.test(s)) return '"' + s.replace(/"/g, '""') + '"';
  return s;
}

function sha256File(filePath) {
  return new Promise((resolve, reject) => {
    const hash = crypto.createHash('sha256');
    const stream = fs.createReadStream(filePath, { highWaterMark: 1024 * 1024 });
    stream.on('data', (chunk) => hash.update(chunk));
    stream.on('end', () => resolve(hash.digest('hex')));
    stream.on('error', (err) => reject(err));
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
    if (e.isSymbolicLink()) continue; // no seguir symlinks (evita ciclos/atajos raros de Drive)
    if (e.isDirectory()) {
      walk(full, out);
    } else if (e.isFile()) {
      out.files.push(full);
    }
  }
}

async function main() {
  const startTime = new Date().toISOString();
  const logLines = [`INICIO ${startTime}`];
  const rows = [];
  const errors = [];
  let totalBytes = 0;
  let idx = 0;

  for (const { label, root } of ROOTS) {
    if (!fs.existsSync(root)) {
      logLines.push(`RAIZ NO EXISTE: ${label} -> ${root}`);
      continue;
    }
    const out = { files: [], errors: [] };
    walk(root, out);
    errors.push(...out.errors.map(x => `${label}\t${x}`));
    logLines.push(`${label}: ${out.files.length} archivos encontrados en ${root}`);

    for (const filePath of out.files) {
      idx++;
      let stat;
      try {
        stat = fs.statSync(filePath);
      } catch (e) {
        errors.push(`${label}\tSTAT_FAIL\t${filePath}\t${e.code || e.message}`);
        continue;
      }
      let hash = '';
      try {
        hash = await sha256File(filePath);
      } catch (e) {
        errors.push(`${label}\tHASH_FAIL\t${filePath}\t${e.code || e.message}`);
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
      if (idx % 500 === 0) {
        console.log(`  ...${idx} archivos procesados (${(totalBytes/1e9).toFixed(2)} GB)`);
      }
    }
  }

  // Escribir CSV
  const header = ['origen','raiz','ruta_relativa','ruta_completa','nombre','extension','tamano_bytes','fecha_modificacion','sha256'];
  const csvLines = [header.join(',')];
  for (const r of rows) {
    csvLines.push(header.map(h => csvEscape(r[h])).join(','));
  }
  fs.writeFileSync(OUT_CSV, '\uFEFF' + csvLines.join('\r\n'), 'utf8');

  const endTime = new Date().toISOString();
  logLines.push(`FIN ${endTime}`);
  logLines.push(`TOTAL ARCHIVOS: ${rows.length}`);
  logLines.push(`TOTAL BYTES: ${totalBytes} (${(totalBytes/1e9).toFixed(3)} GB)`);
  logLines.push(`ERRORES: ${errors.length}`);
  logLines.push('--- ERRORES DETALLE ---');
  logLines.push(...errors);
  fs.writeFileSync(OUT_LOG, logLines.join('\n'), 'utf8');

  console.log('=== RESUMEN T1.1 ===');
  console.log('Total archivos:', rows.length);
  console.log('Total GB:', (totalBytes/1e9).toFixed(3));
  console.log('Errores:', errors.length);
  console.log('CSV:', OUT_CSV);
  console.log('LOG:', OUT_LOG);

  // Resumen por origen
  const byOrigin = {};
  for (const r of rows) {
    byOrigin[r.origen] = byOrigin[r.origen] || { count: 0, bytes: 0 };
    byOrigin[r.origen].count++;
    byOrigin[r.origen].bytes += r.tamano_bytes;
  }
  console.log('--- Por origen ---');
  for (const [k, v] of Object.entries(byOrigin)) {
    console.log(`  ${k}: ${v.count} archivos, ${(v.bytes/1e9).toFixed(3)} GB`);
  }
}

main().catch(e => { console.error('FATAL', e); process.exit(1); });
