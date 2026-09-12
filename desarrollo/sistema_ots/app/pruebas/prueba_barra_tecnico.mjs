#!/usr/bin/env node
// prueba_barra_tecnico.mjs — T2.13.4: la barra de abajo del técnico en las pantallas
// estáticas es la misma que pinta mis.php, y el cronograma no le manda al técnico ni el
// padrón ni el maestro de locales. Sin navegador ni servidor: lee los archivos de publico/.
//
// Va aparte de prueba_contratos.mjs (que es donde el PLAN la ubicaba) porque ese archivo
// tiene trabajo en curso de otra conversación sin confirmar.
//
// Uso:  node prueba_barra_tecnico.mjs      (sale con 1 si algo falla)
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

const PUB = join(dirname(fileURLToPath(import.meta.url)), '..', 'publico');
const leer = (f) => readFileSync(join(PUB, f), 'utf8');
let fallas = 0;
const afirmar = (que, ok, detalle = '') => {
  if (!ok) fallas++;
  console.log(`  ${ok ? 'PASA ' : 'FALLA'} ${que}${detalle ? ' · ' + detalle : ''}`);
};

// ui.js en un contexto con lo mínimo de un navegador. No arranca: readyState 'loading'.
const doc = { readyState: 'loading', addEventListener() {}, getElementById() { return null; }, querySelectorAll() { return []; } };
const ctx = { document: doc, console };
ctx.window = ctx; ctx.self = ctx; ctx.globalThis = ctx;
vm.createContext(ctx);
vm.runInContext(leer('ui.js'), ctx);
const UI = ctx.UI || ctx.window.UI;
afirmar('ui.js expone UI.barraTecnico', !!UI && typeof UI.barraTecnico === 'function');

const html = UI.barraTecnico('preventivos');
const hrefs = [...html.matchAll(/href="([^"]+)"/g)].map((m) => m[1]);
afirmar('la barra tiene 5 destinos', hrefs.length === 5, hrefs.join(' '));
afirmar('marca «Preventivos» como la pantalla actual', /<a href="cronograma\.html" class="on" aria-current="page">/.test(html));
afirmar('y solo esa', (html.match(/class="on"/g) || []).length === 1);

// La misma barra que mis.php, en los dos lugares donde la pinta (la ficha y la bandeja).
const mis = leer('mis.php');
const barras = [...mis.matchAll(/<nav class="nav-abajo"[\s\S]*?<\/nav>/g)]
  .map((m) => [...m[0].matchAll(/href="([^"]+)"/g)].map((x) => x[1]));
afirmar('mis.php pinta la barra en la ficha y en la bandeja', barras.length === 2, String(barras.length));
barras.forEach((b, i) => afirmar(`la barra ${i + 1} de mis.php es la misma que la de ui.js`,
  JSON.stringify(b) === JSON.stringify(hrefs), b.join(' ')));

// El cronograma la usa solo con el técnico y le esconde lo que no es suyo.
const cron = leer('cronograma.js');
afirmar('cronograma.js le pinta la barra al técnico',
  /a\.rol === 'TECNICO'/.test(cron) && /barraTecnico\('preventivos'\)/.test(cron));
afirmar('y le esconde «agendar un local»', /btnAgendar[\s\S]{0,80}display = 'none'/.test(cron));
const php = leer('cronograma.php');
afirmar('cronograma.php no le manda el padrón al técnico', /if \(!\$esTecnico\) \{ \$salida\['tecnicos'\]/.test(php));
afirmar('ni el maestro de locales', /'locales'\s*=> \$esTecnico \? \[\] :/.test(php));

console.log(fallas ? `\n${fallas} falla(s)` : '\nOK: la barra del técnico es una sola y el cronograma no le manda lo que no es suyo');
process.exit(fallas ? 1 : 0);
