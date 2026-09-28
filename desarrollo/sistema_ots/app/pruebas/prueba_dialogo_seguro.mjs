/* =========================================================================
   prueba_dialogo_seguro.mjs — Doble Enter = Confirmar y la pantalla de
   seguridad de «Marcar como cerrada en SAP» (casos.php).

   POR QUE EXISTE. Pedido de Andrés del 2026-09-27: en la nota de «Marcar como
   cerrada en SAP», dos Enter seguidos confirman; y como un doble Enter puede
   ser sin querer, antes de enviar se pregunta «¿Estás seguro?» con el foco en
   «No, volver» (un tercer Enter involuntario vuelve, no cierra la orden).
   Nada de esto lo ve `php -l` ni `prueba_contratos.mjs`: es comportamiento
   de teclado, foco y <dialog> anidado, y se prueba en un navegador de verdad.

   COMO. Recorta de casos.php el diálogo y su <script>, sustituye lo que
   imprime PHP por valores fijos, le añade un arnés que dispara las teclas y
   los clics, y lo carga en Edge sin ventana (--headless=new --dump-dom con
   presupuesto de tiempo virtual, que avanza los setTimeout sin esperar).
   No necesita sesión, servidor ni PHP.

   Se corre con:  node prueba_dialogo_seguro.mjs      (Edge instalado)
   ========================================================================= */

import { readFileSync, writeFileSync, mkdtempSync, existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const aqui = dirname(fileURLToPath(import.meta.url));
const casos = readFileSync(join(aqui, '..', 'publico', 'casos.php'), 'utf8');

const EDGE = [
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
].find(existsSync);
if (!EDGE) { console.error('No hay Edge instalado: no se puede correr esta prueba.'); process.exit(2); }

const i = casos.indexOf('<dialog id="acc">');
const j = casos.indexOf('</script>', i) + '</script>'.length;
let frag = casos.slice(i, j);
frag = frag.replace(/var TECNICOS = <\?=[\s\S]*?\?>;/,
  "var TECNICOS = [{id:1,nombre:'T1',zona:'UIO',zr:'UIO'},{id:2,nombre:'T2',zona:'LARB',zr:'LARB'}];");
frag = frag.replace(/<\?php[\s\S]*?\?>/g, '').replace(/<\?=[\s\S]*?\?>/g, 'X');

const ARNES = String.raw`
var R = []; var alertas = [];
window.alert = function (m) { alertas.push(m); };
function ok(n, c) { R.push((c ? 'ok   ' : 'FALLO') + ' ' + n); }
var form = document.getElementById('acc-form'), mt = document.getElementById('acc-mt');
var seg = document.getElementById('acc-seguro'), acc = document.getElementById('acc');
var enviados = 0;
form.addEventListener('submit', function (ev) { if (!ev.defaultPrevented) { enviados++; } ev.preventDefault(); });
function enter(shift) { mt.dispatchEvent(new KeyboardEvent('keydown', {key:'Enter', shiftKey:!!shift, bubbles:true, cancelable:true})); }
function espera(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
(async function () {
  // A. doble Enter en cerrado_sap abre la pantalla de seguridad, sin enviar
  abrir('cerrado_sap', '10357233', 'UIO');
  mt.value = 'nota de prueba';
  var e1 = new KeyboardEvent('keydown', {key:'Enter', bubbles:true, cancelable:true});
  mt.dispatchEvent(e1);
  ok('A1 Enter sin Shift queda cancelado (no mete salto)', e1.defaultPrevented);
  ok('A2 un solo Enter no envía ni abre seguridad', enviados === 0 && !seg.open);
  await espera(300);
  enter();
  ok('A3 doble Enter abre #acc-seguro y no envía', seg.open && enviados === 0);
  ok('A4 el foco arranca en «No, volver»', document.activeElement === document.getElementById('acc-seguro-no'));
  ok('A5 el texto lleva el número de orden', /orden 10357233 como cerrada en SAP/.test(document.getElementById('acc-seguro-texto').textContent));
  ok('A6 #acc sigue abierto detrás', acc.open);
  // B. «No, volver» cierra la seguridad, vuelve a la nota sin perder lo escrito
  document.getElementById('acc-seguro-no').click();
  ok('B1 No cierra la seguridad y deja #acc abierto', !seg.open && acc.open);
  ok('B2 la nota no se perdió', mt.value === 'nota de prueba');
  ok('B3 el foco vuelve al textarea', document.activeElement === mt);
  ok('B4 no se envió nada', enviados === 0);
  // C. Esc (close por cualquier vía) vuelve igual
  enter(); await espera(200); enter();
  ok('C1 el doble Enter vuelve a funcionar tras volver', seg.open);
  seg.close();
  ok('C2 tras cerrar (Esc) el foco vuelve a la nota y #acc sigue', document.activeElement === mt && acc.open && enviados === 0);
  // D. Clic en Confirmar también pasa por la seguridad
  document.getElementById('acc-ok').click();
  ok('D1 el botón Confirmar abre la seguridad sin enviar', seg.open && enviados === 0);
  // E. Sí envía de verdad
  document.getElementById('acc-seguro-si').click();
  ok('E1 «Sí» envía el formulario una sola vez', enviados === 1 && !seg.open);
  // F. dos Enter separados por más de 1,5 s no confirman
  abrir('cerrado_sap', '10357234', 'UIO');
  ok('F0 abrir() reinicia la bandera: Confirmar vuelve a pedir seguridad', (document.getElementById('acc-ok').click(), seg.open && enviados === 1));
  seg.close();
  enter(); await espera(1700); enter();
  ok('F1 dos Enter a más de 1,5 s no abren la seguridad', !seg.open && enviados === 1);
  await espera(200); enter();
  ok('F2 el segundo Enter rápido tras el anterior sí la abre', seg.open);
  seg.close();
  // G. Shift+Enter no cuenta
  var e2 = new KeyboardEvent('keydown', {key:'Enter', shiftKey:true, bubbles:true, cancelable:true});
  mt.dispatchEvent(e2);
  ok('G1 Shift+Enter no se cancela (mete salto) ni cuenta', !e2.defaultPrevented && !seg.open);
  // H. otras acciones no cambian: revision no intercepta Enter ni pide seguridad
  abrir('revision', '10357235', 'UIO');
  var e3 = new KeyboardEvent('keydown', {key:'Enter', bubbles:true, cancelable:true});
  mt.dispatchEvent(e3);
  ok('H1 en «revision» Enter no se cancela', !e3.defaultPrevented);
  document.getElementById('acc-ok').click();
  ok('H2 en «revision» con motivo vacío (required) no envía', enviados === 1);
  mt.value = 'motivo'; document.getElementById('acc-ok').click();
  ok('H3 en «revision» con motivo envía directo, sin seguridad', enviados === 2 && !seg.open);
  // I. asignar a otra zona sin confirmar sigue avisando
  abrir('asignar', '10357236', 'UIO');
  var sel = document.getElementById('acc-tec'); sel.value = '2'; sel.dispatchEvent(new Event('change'));
  document.getElementById('acc-ok').click();
  ok('I1 asignar a otra zona sin marcar la casilla avisa y no envía', alertas.length === 1 && enviados === 2 && !seg.open);
  document.getElementById('acc-confirmo-zona').checked = true; document.getElementById('acc-ok').click();
  ok('I2 con la casilla marcada envía sin seguridad', enviados === 3 && !seg.open);
  var pre = document.createElement('pre'); pre.id = 'res'; pre.textContent = R.join('\n') + '\nTOTAL ' + R.length + ' · FALLOS ' + R.filter(function (x) { return x.indexOf('FALLO') === 0; }).length;
  document.body.appendChild(pre);
})();
`;

const html = '<!doctype html><html><head><meta charset="utf-8"><title>t</title></head><body>'
  + '<table id="tabla-casos"></table>' + frag + '<script>' + ARNES + '</script></body></html>';

const dir = mkdtempSync(join(tmpdir(), 'industec-acc-'));
const pagina = join(dir, 'acc_prueba.html');
writeFileSync(pagina, html, 'utf8');

const dom = execFileSync(EDGE, [
  '--headless=new', '--disable-gpu', '--no-first-run', '--user-data-dir=' + join(dir, 'perfil'),
  '--virtual-time-budget=8000', '--dump-dom', pathToFileURL(pagina).href,
], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], maxBuffer: 64 * 1024 * 1024 });

const m = /<pre id="res">([\s\S]*?)<\/pre>/.exec(dom);
if (!m) { console.error('La página no produjo resultado; Edge no llegó a correr el arnés.'); process.exit(2); }
const res = m[1].replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
console.log('=== Doble Enter y pantalla de seguridad en casos.php (Edge sin ventana) ===\n');
console.log(res);
process.exit(/FALLOS 0$/.test(res.trim()) ? 0 : 1);
