// T1.3 - Puerta de verificacion: recalcula SHA-256 en destino y compara contra T1.1
// NUNCA se avanza a T1.8 (liberacion del Drive) sin que esta puerta pase.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const readline = require('readline');

const OUT_DIR = path.join('D:', 'INDUSTECH IA', 'SALIDAS IA', 'CALIDAD');
const IN_CSV = path.join(OUT_DIR, 'INVENTARIO_ORIGEN.csv');
const OUT_CSV = path.join(OUT_DIR, 'VERIFICACION_COPIA.csv');
const OUT_REPORT = path.join(OUT_DIR, 'VERIFICACION_COPIA_RESUMEN.md');

// Mapeo raiz de origen -> raiz de espejo en D:\RESPALDOS
const ROOT_MAP = {
  'G:\\Mi unidad': path.join('D:', 'RESPALDOS', '_ORIGEN_DRIVE'),
  'D:\\INDUSTECH IA\\ENTRADAS IA': path.join('D:', 'RESPALDOS', '_ORIGEN_ENTRADAS_IA'),
};

// Extensiones/patrones de documentos nativos de Google que NO tienen contenido
// binario local (el contenido real vive en la nube). No son fallo de copia.
const GOOGLE_NATIVE_EXT = new Set(['.gsheet', '.gdoc', '.gslides', '.gform', '.gdraw']);

function parseCsvLine(line) {
  // Parser simple compatible con el csvEscape de t1_1 (comillas dobles, comas escapadas)
  const out = [];
  let cur = '';
  let inQuotes = false;
  for (let i = 0; i < line.length; i++) {
    const c = line[i];
    if (inQuotes) {
      if (c === '"') {
        if (line[i + 1] === '"') { cur += '"'; i++; }
        else inQuotes = false;
      } else cur += c;
    } else {
      if (c === '"') inQuotes = true;
      else if (c === ',') { out.push(cur); cur = ''; }
      else cur += c;
    }
  }
  out.push(cur);
  return out;
}

function sha256File(filePath) {
  return new Promise((resolve) => {
    const hash = crypto.createHash('sha256');
    let stream;
    try {
      stream = fs.createReadStream(filePath, { highWaterMark: 1024 * 1024 });
    } catch (e) {
      resolve({ ok: false, reason: 'OPEN_ERROR:' + (e.code || e.message) });
      return;
    }
    stream.on('data', (c) => hash.update(c));
    stream.on('end', () => resolve({ ok: true, hash: hash.digest('hex') }));
    stream.on('error', (err) => resolve({ ok: false, reason: 'STREAM_ERROR:' + (err.code || err.message) }));
  });
}

async function main() {
  const rl = readline.createInterface({ input: fs.createReadStream(IN_CSV, { encoding: 'utf8' }) });
  let header = null;
  const rows = [];
  for await (const rawLine of rl) {
    let line = rawLine;
    if (rows.length === 0 && header === null) {
      line = line.replace(/^\uFEFF/, '');
    }
    if (!line) continue;
    const cols = parseCsvLine(line);
    if (!header) { header = cols; continue; }
    const obj = {};
    header.forEach((h, i) => { obj[h] = cols[i]; });
    rows.push(obj);
  }

  console.log(`Filas leidas de inventario: ${rows.length}`);

  const results = [];
  let idx = 0;
  let okCount = 0, mismatchCount = 0, missingCount = 0, excludedCount = 0, noHashOrigCount = 0;

  for (const r of rows) {
    idx++;
    const mirrorRoot = ROOT_MAP[r.raiz];
    if (!mirrorRoot) {
      results.push({ ...r, estado: 'RAIZ_NO_MAPEADA', hash_destino: '' });
      continue;
    }
    const destPath = path.join(mirrorRoot, r.ruta_relativa);
    const ext = (r.extension || '').toLowerCase();

    if (GOOGLE_NATIVE_EXT.has(ext)) {
      // Documento nativo de Google: no tiene contenido binario copiable.
      // Se documenta como EXCLUIDO_NATIVO_GOOGLE, no como fallo.
      excludedCount++;
      results.push({ ...r, estado: 'EXCLUIDO_NATIVO_GOOGLE', hash_destino: '' });
      continue;
    }

    if (!r.sha256) {
      // El origen mismo no tenia hash (deberia coincidir con los mismos excluidos de T1.1)
      noHashOrigCount++;
      results.push({ ...r, estado: 'SIN_HASH_ORIGEN', hash_destino: '' });
      continue;
    }

    if (!fs.existsSync(destPath)) {
      missingCount++;
      results.push({ ...r, estado: 'FALTA_EN_DESTINO', hash_destino: '' });
      continue;
    }

    const res = await sha256File(destPath);
    if (!res.ok) {
      missingCount++;
      results.push({ ...r, estado: 'ERROR_LECTURA_DESTINO:' + res.reason, hash_destino: '' });
      continue;
    }
    if (res.hash === r.sha256) {
      okCount++;
      results.push({ ...r, estado: 'OK', hash_destino: res.hash });
    } else {
      mismatchCount++;
      results.push({ ...r, estado: 'HASH_DIFERENTE', hash_destino: res.hash });
    }

    if (idx % 1000 === 0) console.log(`  ...${idx}/${rows.length} verificados`);
  }

  const header2 = ['origen', 'raiz', 'ruta_relativa', 'nombre', 'extension', 'tamano_bytes', 'sha256', 'hash_destino', 'estado'];
  const csvEscape = (s) => { s = String(s == null ? '' : s); return /[",\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; };
  const csvLines = [header2.join(',')];
  for (const r of results) csvLines.push(header2.map((h) => csvEscape(r[h])).join(','));
  fs.writeFileSync(OUT_CSV, '\uFEFF' + csvLines.join('\r\n'), 'utf8');

  const total = rows.length;
  const passed = (okCount + excludedCount) === total && mismatchCount === 0 && missingCount === 0;

  const report = [
    '# T1.3 - Resultado de la Puerta de Verificacion',
    '',
    `Fecha: ${new Date().toISOString()}`,
    `Total de filas del inventario (T1.1): ${total}`,
    '',
    '| Estado | Cantidad |',
    '|---|---|',
    `| OK (hash identico) | ${okCount} |`,
    `| EXCLUIDO_NATIVO_GOOGLE (.gsheet/.gdoc, sin contenido binario local) | ${excludedCount} |`,
    `| SIN_HASH_ORIGEN (ya sin hash en T1.1) | ${noHashOrigCount} |`,
    `| FALTA_EN_DESTINO | ${missingCount} |`,
    `| HASH_DIFERENTE | ${mismatchCount} |`,
    '',
    `## Resultado: ${passed ? '\u2705 PASA - se puede continuar a T1.4/T1.5/T1.6' : '\u274c NO PASA - investigar antes de continuar'}`,
    '',
    'Criterio de aceptacion (plan T1.3): 100% de hashes coinciden. Los documentos nativos de',
    'Google (.gsheet) se excluyen explicitamente porque no tienen contenido binario local que',
    'copiar bit a bit (el contenido real vive en la nube de Google); su existencia y ubicacion',
    'ya quedaron registradas en el inventario y no representan perdida de informacion.',
  ].join('\n');
  fs.writeFileSync(OUT_REPORT, report, 'utf8');

  console.log('=== RESUMEN T1.3 ===');
  console.log(report);
}

main().catch((e) => { console.error('FATAL', e); process.exit(1); });
