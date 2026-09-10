/* =========================================================================
   prueba_graficos.mjs — Que ningun gráfico tumbe la página de reportes.

   POR QUE ESTA PRUEBA EXISTE
   `graficos.js` dibuja SVG a mano, con aritmética: divisiones por el total,
   ángulos, anchos proporcionales. Todas esas operaciones tienen un caso que
   las rompe, y los casos no son raros — son los normales de un lunes:

     - un reporte de una zona que todavía no tiene ningún caso  -> total 0
     - un solo estado con todo dentro                           -> un dato
     - un mes sin actividad en medio de la serie                -> valor 0
     - el primer día de uso del sistema                         -> lista vacía

   Si el dibujante lanza una excepción con cualquiera de esos, el guion muere
   y se caen TODOS los gráficos de la página, no solo el que falló. El
   `reportes.php` de la administradora quedaría en blanco.

   Se corre con:  node prueba_graficos.mjs
   ========================================================================= */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const AQUI = dirname(fileURLToPath(import.meta.url));
const RUTA = join(AQUI, '..', 'publico', 'graficos.js');

/* -------------------------------------------------------------------------
   Un DOM mínimo. No se usa jsdom a propósito: el proyecto no arrastra
   dependencias para una prueba, y lo que hay que ejercitar es la aritmética
   del dibujante, no el navegador. Cada nodo guarda sus atributos e hijos para
   poder inspeccionarlos después.
   ------------------------------------------------------------------------- */
function nodo(nombre) {
  const n = {
    tagName: String(nombre).toUpperCase(),
    _attrs: {}, children: [], style: {}, classList: null,
    _texto: '', title: '', _listeners: {},
    setAttribute(k, v) { this._attrs[k] = String(v); },
    getAttribute(k) { return k in this._attrs ? this._attrs[k] : null; },
    hasAttribute(k) { return k in this._attrs; },
    appendChild(h) { this.children.push(h); return h; },
    addEventListener(t, f) { (this._listeners[t] ||= []).push(f); },
    remove() {},
    querySelectorAll() { return []; },
    get textContent() { return this._texto; },
    set textContent(v) { this._texto = String(v); },
    get innerHTML() { return this._html || ''; },
    set innerHTML(v) { this._html = String(v); if (v === '') { this.children = []; } },
  };
  n.classList = {
    _s: new Set(),
    add(...c) { c.forEach((x) => this._s.add(x)); },
    remove(...c) { c.forEach((x) => this._s.delete(x)); },
    contains(c) { return this._s.has(c); },
  };
  return n;
}

const cuerpo = nodo('body');
cuerpo.offsetWidth = 0;

const documento = {
  readyState: 'complete',
  body: cuerpo,
  createElement: nodo,
  createElementNS: (_ns, nombre) => nodo(nombre),
  /* La leyenda de `graficos.js` mete el nombre de la serie como nodo de texto
     —no como innerHTML— para que un nombre de local con `&` o `<` no se
     interprete como marcado. El arnés tiene que ofrecerlo o falla por su
     propia carencia y no por un defecto del dibujante. */
  createTextNode(t) {
    const n = nodo('#text');
    n._texto = String(t);
    return n;
  },
  addEventListener() {},
  querySelectorAll() { return []; },
};

const ventana = {
  document: documento,
  innerWidth: 1280,
  matchMedia: () => ({ matches: false }),
  addEventListener() {},
};
ventana.window = ventana;

/* Se ejecuta el archivo real dentro de ese contexto. */
const fuente = readFileSync(RUTA, 'utf8');
new Function('window', 'document', 'globalThis', fuente)(ventana, documento, ventana);

const Graficos = ventana.Graficos;
if (!Graficos || typeof Graficos.dibujar !== 'function') {
  console.error('FALLA: graficos.js no expuso Graficos.dibujar');
  process.exit(1);
}

/* ------------------------------------------------------------------------- */
let total = 0, fallos = 0;

function afirmar(que, cond, detalle = '') {
  total++;
  if (!cond) { fallos++; }
  const marca = cond ? 'ok' : 'FALLA';
  console.log(`  ${que.padEnd(62)} ${marca}${detalle && !cond ? ' — ' + detalle : ''}`);
}

/** Dibuja y devuelve {ok, error, fig}. Nunca debe lanzar. */
function dibujar(tipo, datos, extra = {}) {
  const fig = nodo('figure');
  fig.setAttribute('data-viz', tipo);
  fig.setAttribute('data-datos', JSON.stringify(datos));
  fig.setAttribute('data-titulo', 'prueba');
  for (const [k, v] of Object.entries(extra)) { fig.setAttribute(k, v); }
  try {
    Graficos.dibujar(fig);
    return { ok: true, fig };
  } catch (e) {
    return { ok: false, error: e && e.message, fig };
  }
}

/** Recoge todos los atributos numéricos del árbol, para buscar NaN. */
function numeros(n, salida = []) {
  for (const [k, v] of Object.entries(n._attrs || {})) {
    if (['x', 'y', 'width', 'height', 'x1', 'y1', 'x2', 'y2', 'rx', 'd', 'viewBox'].includes(k)) {
      salida.push([k, v]);
    }
  }
  (n.children || []).forEach((h) => numeros(h, salida));
  return salida;
}

function sinNaN(fig) {
  const malos = numeros(fig).filter(([, v]) => /NaN|Infinity|undefined/.test(v));
  return { bien: malos.length === 0, malos };
}

const FORMAS = ['barras', 'columnas', 'anillo', 'apilada'];

console.log('=== Los casos que rompen la aritmética ===\n');

for (const forma of FORMAS) {
  console.log(`-- ${forma} --`);

  // 1. Lista vacía: el primer día de uso del sistema.
  let r = dibujar(forma, []);
  afirmar(`${forma}: lista vacía no lanza`, r.ok, r.error);

  // 2. Total 0: una zona que todavía no tiene ningún caso. Es el que divide
  //    por cero en el anillo y en la apilada.
  r = dibujar(forma, [{ e: 'UIO', v: 0 }, { e: 'LARB', v: 0 }]);
  afirmar(`${forma}: todos en cero no lanza`, r.ok, r.error);
  if (r.ok) {
    const n = sinNaN(r.fig);
    afirmar(`${forma}: todos en cero no deja NaN en el SVG`, n.bien, JSON.stringify(n.malos.slice(0, 3)));
  }

  // 3. Un solo dato.
  r = dibujar(forma, [{ e: 'UIO', v: 42 }]);
  afirmar(`${forma}: un solo dato no lanza`, r.ok, r.error);

  // 4. Un valor 0 mezclado: el mes sin actividad en medio de la serie.
  r = dibujar(forma, [{ e: 'ene', v: 12 }, { e: 'feb', v: 0 }, { e: 'mar', v: 7 }]);
  afirmar(`${forma}: un cero intercalado no lanza`, r.ok, r.error);
  if (r.ok) {
    afirmar(`${forma}: el cero intercalado no deja NaN`, sinNaN(r.fig).bien);
  }

  // 5. Valores negativos. No deberían llegar, pero un cálculo mal hecho aguas
  //    arriba los produce, y el gráfico no puede ser el que reviente.
  r = dibujar(forma, [{ e: 'a', v: -5 }, { e: 'b', v: 10 }]);
  afirmar(`${forma}: valores negativos no lanzan`, r.ok, r.error);

  // 6. Valores no numéricos, que es lo que llega si el JSON viene mal.
  r = dibujar(forma, [{ e: 'a', v: null }, { e: 'b' }, { e: 'c', v: 'ocho' }]);
  afirmar(`${forma}: valores basura no lanzan`, r.ok, r.error);
  if (r.ok) {
    afirmar(`${forma}: valores basura no dejan NaN`, sinNaN(r.fig).bien);
  }

  // 7. Etiqueta larguísima: el nombre completo de un local.
  r = dibujar(forma, [{ e: 'KFC CENTRO COMERCIAL EL RECREO PLANTA BAJA LOCAL 214', v: 3 }]);
  afirmar(`${forma}: etiqueta muy larga no lanza`, r.ok, r.error);

  // 8. JSON roto en el atributo. Debe caer a lista vacía, no morir.
  {
    const fig = nodo('figure');
    fig.setAttribute('data-viz', forma);
    fig.setAttribute('data-datos', '{esto no es json');
    let ok = true, err = '';
    try { Graficos.dibujar(fig); } catch (e) { ok = false; err = e.message; }
    afirmar(`${forma}: JSON roto no lanza`, ok, err);
  }
  console.log('');
}

console.log('=== El anillo con muchas porciones ===\n');
{
  // Ocho estados es lo real: el anillo tiene que plegar la cola en «Otros» en
  // vez de dibujar ocho quesitos que nadie puede comparar.
  const ocho = ['NUEVO', 'ASIGNADO', 'EN_REVISION', 'ESPERA_REPUESTO', 'ATENDIDO',
                'RESUELTO', 'NO_COMPETE', 'CERRADO_SIN_ATENCION']
    .map((e, i) => ({ e, v: 100 - i * 9 }));
  const r = dibujar('anillo', ocho);
  afirmar('anillo con 8 porciones no lanza', r.ok, r.error);
  if (r.ok) {
    const tabla = JSON.stringify(r.fig.children.map((c) => c.tagName));
    afirmar('anillo con 8: genera tabla de números', tabla.includes('DETAILS'), tabla);
  }
}

console.log('\n=== Todo gráfico lleva su tabla (la regla de compensación) ===\n');
for (const forma of FORMAS) {
  const r = dibujar(forma, [{ e: 'UIO', v: 5 }, { e: 'LARB', v: 3 }]);
  const tags = r.ok ? r.fig.children.map((c) => c.tagName) : [];
  afirmar(`${forma}: incluye la tabla plegada`, tags.includes('DETAILS'), tags.join(','));
}

console.log('\n=== El color sigue a la entidad, no a su posición ===\n');
{
  // UIO tiene que ser el mismo violeta esté primera o última: si el color
  // siguiera al orden, filtrar repintaría lo que queda y la gente creería que
  // está mirando otra cosa.
  const a = dibujar('barras', [{ e: 'UIO', v: 1 }, { e: 'LARB', v: 2 }, { e: 'CNLJ', v: 3 }]);
  const b = dibujar('barras', [{ e: 'CNLJ', v: 3 }, { e: 'LARB', v: 2 }, { e: 'UIO', v: 1 }]);

  const rellenos = (fig) => {
    const out = {};
    const rec = (n) => {
      if (n.tagName === 'RECT' && n._attrs.fill && n._attrs.fill !== '#f1f5f9') {
        out[n._attrs.fill] = true;
      }
      (n.children || []).forEach(rec);
    };
    rec(fig);
    return Object.keys(out).sort();
  };
  const ra = rellenos(a.fig), rb = rellenos(b.fig);
  afirmar('los mismos tres colores en cualquier orden',
          JSON.stringify(ra) === JSON.stringify(rb), `${ra} vs ${rb}`);
  afirmar('UIO conserva su violeta', ra.includes('#7c3aed'), ra.join(','));
  afirmar('LARB conserva su verde azulado', ra.includes('#0d9488'), ra.join(','));
  afirmar('CNLJ conserva su naranja', ra.includes('#ea580c'), ra.join(','));
}

console.log('\n=== Los colores de estado coinciden con los de Ui::colorEstado ===\n');
{
  // Si el anillo pinta «atendido» de un color y el distintivo de la fila lo
  // pinta de otro, quien mira cree que son dos cosas distintas.
  const deUi = {
    NUEVO: '#94a3b8', ASIGNADO: '#2a78d6', EN_REVISION: '#eda100',
    ESPERA_REPUESTO: '#eb6834', ATENDIDO: '#1baf7a', RESUELTO: '#008300',
    NO_COMPETE: '#4a3aa7', CERRADO_SIN_ATENCION: '#e34948',
  };
  for (const [estado, color] of Object.entries(deUi)) {
    afirmar(`${estado}: mismo color en los dos sitios`,
            Graficos.COLOR_ESTADO[estado] === color,
            `graficos=${Graficos.COLOR_ESTADO[estado]} ui=${color}`);
  }
}

console.log('');
console.log(`${total} comprobaciones · ${fallos} fallos`);
process.exit(fallos > 0 ? 1 : 0);
