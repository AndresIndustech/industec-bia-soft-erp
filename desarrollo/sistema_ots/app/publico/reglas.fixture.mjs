/* Corre el fixture compartido contra reglas.js (la copia del navegador).
   Uso:  node reglas.fixture.mjs
   Debe decir lo mismo que validacion_test.php y que t2_5_validacion.py --fixture. */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { createRequire } from 'node:module';

const aqui = dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const Reglas = require('./reglas.js');

const fx = JSON.parse(readFileSync(join(aqui, '..', 'pruebas', 'fixture_validacion.json'), 'utf8'));
const validar = Reglas.crear(fx.catalogo);

let ok = 0, fail = 0;
for (const caso of fx.casos) {
  const orden = { ...fx.orden_base, ...caso.cambios };
  const contexto = caso.contexto || 'CAPTURA';
  const reglas = validar(orden, contexto).map(h => h.regla).sort();
  const espera = [...caso.espera].sort();
  const igual = reglas.length === espera.length && reglas.every((r, i) => r === espera[i]);
  if (igual) { ok++; }
  else {
    fail++;
    console.log(`\n✗ ${caso.nombre}`);
    console.log(`   espera : ${espera.join(', ') || '(ninguno)'}`);
    console.log(`   obtuvo : ${reglas.join(', ') || '(ninguno)'}`);
  }
}

console.log(`\n${fail === 0 ? '✓' : '✗'} ${ok}/${fx.casos.length} casos del fixture pasan (reglas.js)`);
process.exit(fail === 0 ? 0 : 1);
