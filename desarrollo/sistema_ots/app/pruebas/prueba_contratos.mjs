/* =========================================================================
   prueba_contratos.mjs — Los contratos entre el JavaScript y las pantallas.

   ============================================================================
   POR QUE ESTA PRUEBA EXISTE

   Durante el rediseño del 2026-09-10 se rompieron DOS contratos de este tipo,
   y los dos fallaban **en silencio**: sin error en consola, sin nada rojo, con
   la página cargando bien. Son los peores.

   1. **El botón de enviar desapareció.** `guia.js` envuelve cada `<h2>` del
      formulario y todo lo que le sigue en un paso plegable. El panel de
      validación y el botón de enviar están DESPUÉS del último `<h2>`, así que
      se los tragó el último paso — y como solo hay un paso abierto a la vez,
      quedaron plegados e inalcanzables. El técnico llenaba la orden entera y no
      tenía dónde pulsar.

   2. **Las novedades se ocultaban solas.** El contenedor de novedades de la
      visita se llamaba `#novedades`, el mismo id que usa `ui.js` para la barra
      de «el buzón se actualizó». A los 30 segundos, `ui.js` le ponía `hidden` a
      lo que el técnico acababa de escribir. Seguía en el envío, pero
      desaparecía de la pantalla.

   Ninguno de los dos lo habría visto un `node --check` ni un `php -l`: la
   sintaxis estaba perfecta. Lo que falla es el acuerdo entre dos archivos.

   ============================================================================
   QUE COMPRUEBA

   - Todo `getElementById('x')` de un guion tiene su `id="x"` en alguna de las
     pantallas que cargan ese guion.
   - Ningún id se usa para dos cosas distintas en pantallas que comparten guion.
   - Lo que tiene que quedar SIEMPRE visible en el formulario está de verdad
     cubierto por la lista de exclusión de `guia.js`.
   - `novedades.php` sigue siendo un extremo JSON y no una pantalla.

   Se corre con:  node prueba_contratos.mjs
   ========================================================================= */

import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
/* Para ejecutar el gemelo en PHP de verdad, en vez de reimplementarlo aquí. */
import { execFileSync } from 'node:child_process';

const AQUI = dirname(fileURLToPath(import.meta.url));
const PUB = join(AQUI, '..', 'publico');
const leer = (f) => readFileSync(join(PUB, f), 'utf8');

let total = 0, fallos = 0;
function afirmar(que, cond, detalle = '') {
  total++;
  if (!cond) { fallos++; }
  console.log(`  ${que.padEnd(66)} ${cond ? 'ok' : 'FALLA'}${!cond && detalle ? ' — ' + detalle : ''}`);
}

/* -------------------------------------------------------------------------
   Qué guiones carga cada pantalla.

   Se declara a mano y no se deduce: `Ui::pie()` inyecta `ui.js` desde PHP, así
   que buscar etiquetas <script> en el archivo no lo encontraría. Si alguien
   agrega una pantalla, esta lista es lo que hay que tocar — y que haya que
   tocarla es el punto: obliga a pensar qué ids comparte.
   ------------------------------------------------------------------------- */
const PANTALLAS = {
  'index.html':           ['ui.js', 'offline.js', 'cola.js', 'reglas.js', 'app.js', 'guia.js'],
  'mis.php':              ['ui.js', 'offline.js', 'cola.js'],
  'cronograma.html':      ['ui.js', 'reglas.js', 'cronograma.js'],
  // Las que cierran con Ui::pie() reciben ui.js, y algunas un guion más.
  'panel.php':            ['ui.js', 'graficos.js'],
  'reportes.php':         ['ui.js', 'graficos.js'],
  'casos.php':            ['ui.js', 'busqueda.js'],
  'asignacion.php':       ['ui.js'],
  'pendientes.php':       ['ui.js'],
  'novedades_visita.php': ['ui.js'],
  'ordenes.php':          ['ui.js', 'busqueda.js'],
  'usuarios.php':         ['ui.js'],
};

/* Los ids que `Ui::pie()` y `Ui::cabecera()` emiten desde PHP: no aparecen
   escritos en el archivo de la pantalla, pero están en el HTML servido. */
const IDS_DEL_ARMAZON = ['toasts', 'novedades', 'nov-txt', 'nov-ver', 'nov-no'];

function idsDe(archivo) {
  const s = leer(archivo);
  const ids = new Set();
  for (const m of s.matchAll(/\bid\s*=\s*["']([^"']+)["']/g)) { ids.add(m[1]); }
  // Los ids que arma PHP interpolando: `id="acc-<?= ... ?>"` o 'ins' . $id
  for (const m of s.matchAll(/\bid\s*=\s*["']([a-zA-Z_-]+)<\?/g)) { ids.add(m[1] + '*'); }
  return ids;
}

function idsPedidos(guion) {
  const s = leer(guion);
  const ids = new Set();
  for (const m of s.matchAll(/getElementById\(\s*['"]([^'"]+)['"]\s*\)/g)) { ids.add(m[1]); }
  return ids;
}

console.log('=== Cada guion encuentra los ids que pide ===\n');

const GUIONES = [...new Set(Object.values(PANTALLAS).flat())];
const pedidosPor = {};
for (const g of GUIONES) {
  if (!existsSync(join(PUB, g))) { afirmar(`${g}: existe`, false); continue; }
  pedidosPor[g] = idsPedidos(g);
}

for (const [pantalla, guiones] of Object.entries(PANTALLAS)) {
  if (!existsSync(join(PUB, pantalla))) { afirmar(`${pantalla}: existe`, false); continue; }
  const disponibles = new Set([...idsDe(pantalla), ...IDS_DEL_ARMAZON]);

  for (const g of guiones) {
    const pedidos = pedidosPor[g] || new Set();
    const ausentes = [...pedidos].filter((id) => !disponibles.has(id));
    /* Un guion compartido pide ids que solo existen en una de sus pantallas
       —`cola.js` pide `#cola`, que está en index.html y en mis.php pero no en
       el panel— así que la ausencia sola no es un fallo. Lo que SÍ es fallo es
       que un id no exista en NINGUNA pantalla que cargue ese guion: eso es
       código muerto o un id mal escrito. */
    const huerfanos = ausentes.filter((id) => {
      return !Object.entries(PANTALLAS).some(([p, gs]) =>
        gs.includes(g) && existsSync(join(PUB, p)) &&
        new Set([...idsDe(p), ...IDS_DEL_ARMAZON]).has(id));
    });
    if (huerfanos.length) {
      afirmar(`${g}: todos sus ids existen en alguna pantalla suya`, false, huerfanos.join(', '));
    }
  }
}
// Una sola línea de resumen si no hubo huérfanos, para no imprimir 60 «ok».
afirmar('ningún getElementById apunta a un id inexistente', fallos === 0);

console.log('\n=== Ningún id significa dos cosas distintas ===\n');
{
  /* El caso real: `#novedades` era a la vez la barra del buzón (en `ui.js`) y
     el contenedor de novedades de la visita (en el formulario). */
  const enFormulario = idsDe('index.html');
  const delArmazon = new Set(IDS_DEL_ARMAZON);
  const choques = [...enFormulario].filter((id) => delArmazon.has(id));
  afirmar('el formulario no reusa un id del armazón compartido',
          choques.length === 0, choques.join(', '));

  afirmar('las novedades de la visita tienen su propio id',
          enFormulario.has('novedadesVisita'), [...enFormulario].join(',').slice(0, 120));
  afirmar('guia.js busca ese id y no el del armazón',
          leer('guia.js').includes("getElementById('novedadesVisita')"));
  afirmar('ui.js exige nov-txt además de la caja, por si el nombre se reusa',
          /if \(!caja \|\| !txt\)/.test(leer('ui.js')));
}

console.log('\n=== El botón de enviar no se puede quedar dentro de un paso plegado ===\n');
{
  const html = leer('index.html');
  const guia = leer('guia.js');

  // La lista de exclusión de guia.js, tal como está escrita.
  const m = guia.match(/var SIEMPRE_VISIBLE = '([^']+)'/);
  afirmar('guia.js declara qué queda siempre visible', !!m, 'no se encontró SIEMPRE_VISIBLE');
  const selectores = m ? m[1].split(',').map((x) => x.trim()) : [];

  // Dentro del formulario, ¿qué hay después del último <h2>?
  const form = html.slice(html.indexOf('<form id="otForm"'), html.indexOf('</form>'));
  const ultimoH2 = form.lastIndexOf('<h2>');
  const despues = form.slice(ultimoH2);

  const traeValidacion = despues.includes('id="panelValidacion"');
  const traeEnvio = despues.includes('id="submitBtn"');

  afirmar('el panel de validación queda tras el último <h2> (por eso hay exclusión)',
          traeValidacion);
  afirmar('el botón de enviar queda tras el último <h2>', traeEnvio);

  // Y por tanto la exclusión TIENE que cubrirlos, o quedan inalcanzables.
  afirmar('la exclusión cubre #panelValidacion',
          selectores.includes('#panelValidacion'), selectores.join(' | '));
  afirmar('la exclusión cubre el contenedor del botón (.footer)',
          selectores.includes('.footer'), selectores.join(' | '));

  // El botón de enviar tiene que estar dentro de un `.footer`, que es lo que
  // la exclusión rescata. Si alguien lo saca de ahí, vuelve el defecto.
  const idxFooter = form.lastIndexOf('class="footer"');
  const idxBtn = form.indexOf('id="submitBtn"');
  afirmar('el botón de enviar vive dentro de un .footer',
          idxFooter !== -1 && idxBtn > idxFooter, `footer=${idxFooter} btn=${idxBtn}`);

  // Y guia.js tiene que devolverlos al formulario, no dejarlos huérfanos.
  afirmar('guia.js los vuelve a pegar al final del formulario',
          /cola\.forEach\(function \(n\) \{ form\.appendChild\(n\); \}\)/.test(guia));
}

console.log('\n=== Todo botón dentro del formulario declara su type ===\n');
{
  /* Un <button> sin `type` dentro de un <form> vale como `submit`. Las
     cabeceras de los pasos que crea `guia.js` son botones, y sin type habrían
     enviado la orden a medio llenar al pulsarlas. */
  const guia = leer('guia.js');
  const creaBotones = (guia.match(/createElement\('button'\)/g) || []).length;
  const declaraType = (guia.match(/\.type = 'button'/g) || []).length;
  afirmar('guia.js pone type="button" a cada botón que crea',
          creaBotones > 0 && declaraType >= creaBotones, `crea ${creaBotones}, declara ${declaraType}`);

  const html = leer('index.html');
  const form = html.slice(html.indexOf('<form id="otForm"'), html.indexOf('</form>'));
  const sinType = (form.match(/<button(?![^>]*\btype\s*=)[^>]*>/g) || []);
  afirmar('index.html no deja botones sin type dentro del formulario',
          sinType.length === 0, sinType.slice(0, 2).join(' '));
}

console.log('\n=== novedades.php sigue siendo un extremo JSON, no una pantalla ===\n');
{
  /* Se sobrescribió una vez con una pantalla completa. `ui.js` recibía HTML
     donde esperaba JSON, `r.json()` lanzaba, y el `catch` se lo comía: la barra
     de «el buzón se actualizó» dejó de aparecer sin un solo error visible. */
  afirmar('ui.js lo consulta', leer('ui.js').includes("fetch('novedades.php'"));
  afirmar('el archivo existe', existsSync(join(PUB, 'novedades.php')));
  const s = existsSync(join(PUB, 'novedades.php')) ? leer('novedades.php') : '';
  afirmar('devuelve JSON', s.includes('json_encode') && s.includes("Content-Type: application/json"));
  afirmar('NO es una pantalla HTML', !s.includes('<!DOCTYPE') && !s.includes('Ui::cabecera'));
  afirmar('exige sesión y permiso', /Auth::exigir\('casos\.ver',\s*true\)/.test(s));
  afirmar('respeta el alcance por zona', s.includes('Auth::zonaAlcance()'));
  afirmar('la pantalla de novedades vive aparte',
          existsSync(join(PUB, 'novedades_visita.php')));
}

console.log('\n=== Los extremos que sirven datos del cliente exigen sesión ===\n');
{
  /* Se detectó el 2026-09-10 que dos estaban abiertos. Esta comprobación es
     para que no se vuelvan a abrir por descuido en un cambio futuro. */
  for (const [f, permiso] of [['catalogos.php', 'ots.crear'],
                              ['cronograma.php', 'cronograma.ver'],
                              ['envio.php', 'ots.crear'],
                              ['novedades.php', 'casos.ver']]) {
    const s = leer(f);
    afirmar(`${f}: exige sesión`, s.includes('Auth::exigir('), 'no llama a Auth::exigir');
    afirmar(`${f}: y el permiso ${permiso}`, s.includes(`'${permiso}'`));
    afirmar(`${f}: responde en JSON si falta la sesión (no redirige un fetch)`,
            /Auth::exigir\([^)]*,\s*true\)/.test(s));
  }
  afirmar('cronograma.php recorta por zona en el servidor',
          leer('cronograma.php').includes('$zonaAlcance !== null'));
  // Las herramientas de mantenimiento no son endpoints: cortan fuera de CLI.
  for (const f of ['aplicar_sql.php', 'verificar_esquema.php', 'minar.php']) {
    afirmar(`${f}: 404 fuera de la línea de órdenes`,
            /PHP_SAPI\s*!==\s*'cli'/.test(leer(f)));
  }
}

console.log('\n=== El buscador: JS y PHP reducen el texto igual ===\n');
{
  /* `busqueda.js` filtra en vivo y `Ui::coincide()` filtra en el servidor. Si
     las dos normalizaciones se separan, escribir `2466` encontraría una fila
     con JS y otra distinta sin JS. Aquí se cargan las dos y se comparan sobre
     los mismos casos. */
  const js = leer('busqueda.js');
  const php = readFileSync(join(PUB, 'nucleo', 'Ui.php'), 'utf8');

  afirmar('busqueda.js expone Busqueda.normalizar y .calza',
          /Busqueda\.normalizar\s*=/.test(js) && /Busqueda\.calza\s*=/.test(js));
  afirmar('Ui.php tiene normalizarBusqueda() y coincide()',
          /function normalizarBusqueda\(/.test(php) && /function coincide\(/.test(php));

  // No se reimplementa la normalización: se ejecuta la de verdad. `busqueda.js`
  // es un IIFE que cuelga de `window` y toca `document` al final para
  // engancharse al arranque; aquí se le da un `document` de mentira.
  const g = {};
  const docFalso = { readyState: 'complete', addEventListener() {}, querySelectorAll: () => [] };
  new Function('window', 'document', js)(g, docFalso);
  const B = g.Busqueda;

  /* --- El gemelo en PHP: se EJECUTA, no se reimplementa --------------------
     Hasta el 2026-09-12 esta comprobación reimplementaba la normalización de
     PHP aquí en JavaScript, con `normalize('NFD')` — que es exactamente lo que
     hace el JS. Comparaba el JS contra el JS, y por eso daba «ok» mientras la
     tabla de `Ui.php`, escrita a mano con 16 entradas, no cubría â ê î ô û ã õ
     ç: `Sâo` daba `sao` en el navegador y `so` en el servidor. Escribes y salen
     tres resultados; recargas y salen otros.

     Una prueba que reimplementa lo que debe verificar no verifica nada. Ahora
     se invoca el PHP de verdad y se compara su salida literal. Si no hay PHP
     alcanzable, esto FALLA en vez de pasar de largo: un «ok» que en realidad
     fue «no lo pude comprobar» es peor que un fallo (I-7). */
  const PHP = [process.env.PHP_BIN, 'D:/SOFTWARE/PHP83/php.exe', 'php']
    .find((c) => c && (c === 'php' || existsSync(c)));

  /* El corpus lleva UNA muestra por cada carácter de la tabla de `Ui.php`, más
     los que provocaron la divergencia real y los casos límite. Con muestras
     escogidas a dedo esto se vuelve a escapar. */
  const ACENTOS = [...new Set((php.match(/'(.)' => '[a-z]'/g) || [])
    .map((m) => m.charAt(1)))];
  const MUESTRAS = [
    'OT-2466-V093-10352936-CNLJ', '000010353510', 'Café Ñoño',
    'K191  freidora', 'aviso: 10.352.936',
    'Sâo Paulo', 'François', 'João', 'Peñaherrera', 'Cañar',
    '', '   ', '---', 'a\u00a0b', 'ÁÉÍÓÚ', 'MAYÚSCULAS Y minúsculas',
    ...ACENTOS.map((c) => 'x' + c + 'x'),
  ];

  let phpSalida = null;
  try {
    /* Se pasan por stdin como JSON para no pelear con el escapado de la línea
       de órdenes: hay tildes, espacios duros y guiones en las muestras. */
    phpSalida = JSON.parse(execFileSync(PHP, [
      '-r',
      "require getenv('UI_PHP');" +
      '$e = json_decode(stream_get_contents(STDIN), true);' +
      'echo json_encode(array_map([Ui::class, "normalizarBusqueda"], $e));',
    ], {
      input: JSON.stringify(MUESTRAS),
      env: { ...process.env, UI_PHP: join(PUB, 'nucleo', 'Ui.php') },
      encoding: 'utf8',
    }));
  } catch (e) {
    phpSalida = null;
    console.log(`  (no se pudo ejecutar PHP: ${String(e.message).split('\n')[0]})`);
  }

  afirmar(`se ejecutó el PHP de verdad sobre ${MUESTRAS.length} muestras`,
          Array.isArray(phpSalida) && phpSalida.length === MUESTRAS.length,
          'sin PHP no hay comprobación: define PHP_BIN');

  const divergen = !Array.isArray(phpSalida) ? ['no se ejecutó PHP']
    : MUESTRAS.map((m, i) => [m, B.normalizar(m), phpSalida[i]])
              .filter(([, js, ph]) => js !== ph)
              .map(([m, js, ph]) => `${JSON.stringify(m)}: JS=${js} PHP=${ph}`);

  afirmar(`JS y PHP normalizan igual las ${MUESTRAS.length} muestras (${ACENTOS.length} acentos)`,
          divergen.length === 0, divergen.slice(0, 4).join(' · '));

  const CASOS = [
    ['OT-2466-V093-10352936-CNLJ', '2466', true],
    ['OT-2466-V093-10352936-CNLJ', 'v093', true],
    ['OT-2466-V093-10352936-CNLJ', '10352936', true],
    ['aviso 000010353510 v096', '10353510', true],
    ['aviso 000010353510 v096', '000010353510', true],
    ['OT-2466-V093 freidora k191', 'k191 freidora', true],
    ['OT-2466-V093-10352936-CNLJ', '9999', false],
  ];
  let ok = true;
  for (const [fila, q, esp] of CASOS) {
    if (B.calza(B.normalizar(fila), q) !== esp) { ok = false; }
  }
  afirmar('calza() acierta los 7 casos de coincidencia parcial', ok);

  afirmar('casos.php y ordenes.php marcan las filas con data-b',
          /data-b="/.test(leer('casos.php')) && /data-b="/.test(leer('ordenes.php')));

  /* --- Los campos del servidor y los del navegador, los MISMOS -------------
     `Ui::coincide()` filtra en el servidor; `Ui::claveFila()` arma el `data-b`
     que compara `busqueda.js`. Si las dos listas no llevan los mismos campos,
     el buscador en vivo encuentra filas que al recargar desaparecen — y la URL
     compartida, que se filtra en el servidor, no muestra lo que vio quien la
     mandó.

     El 2026-09-12 `ordenes.php` tenía 6 campos en el servidor y 7 en el
     `data-b` (le faltaba `caso`), con un comentario que afirmaba justo lo
     contrario. Se compara el texto de los argumentos de las dos llamadas, que
     es lo único que de verdad dice si coinciden. */
  const campos = (txt, fn) => {
    /* La LLAMADA, no la mención. Las dos pantallas nombran `Ui::coincide()` en
       un comentario de la cabecera, y un `indexOf` a secas enganchaba ese
       comentario y devolvía la lista vacía — o sea, una prueba que «fallaba»
       por su propio error de lectura, que es tan malo como una que pasa sin
       comprobar. La llamada de verdad va seguida de `([`. */
    const m = new RegExp(fn.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\[')
      .exec(txt);
    if (!m) { return null; }
    const a = txt.indexOf('[', m.index);
    if (a < 0) { return null; }
    /* Se busca el corchete que CIERRA, contando profundidad. Cortar en el
       primer `]` daba vacío: el primero es el de `$c['aviso']`, no el del
       arreglo. */
    let prof = 0, b = -1;
    for (let k = a; k < txt.length; k++) {
      if (txt[k] === '[') { prof++; }
      else if (txt[k] === ']') { prof--; if (prof === 0) { b = k; break; } }
    }
    if (b < 0) { return null; }
    /* Cada argumento se reduce a su clave: `$f['local_n']` y
       `$c['local_nombre'] ?? ''` se comparan por el nombre, no por la sintaxis. */
    return (txt.slice(a + 1, b).match(/\[\s*'([^']+)'\s*\]/g) || [])
      .map((m) => m.replace(/.*'([^']+)'.*/, '$1'));
  };

  for (const pantalla of ['casos.php', 'ordenes.php']) {
    const txt = leer(pantalla);
    const srv = campos(txt, 'Ui::coincide(');
    const cli = campos(txt, 'Ui::claveFila(');
    const iguales = srv && cli && srv.length > 0 &&
                    srv.join(',') === cli.join(',');
    afirmar(`${pantalla}: el filtro del servidor y el data-b comparan los mismos campos`,
            iguales,
            srv && cli ? `servidor=[${srv}] data-b=[${cli}]` : 'no pude leer las dos listas');
  }
  afirmar('el input de búsqueda apunta a su tabla con data-busca',
          /data-busca="#tabla-casos"/.test(leer('casos.php'))
          && /data-busca="#tabla-ordenes"/.test(leer('ordenes.php')));
}

console.log('\n=== El service worker conoce los archivos nuevos ===\n');
{
  /* Lo que no esté en la lista, sin señal no existe. Y si la versión no sube,
     el navegador se queda con la lista vieja y no precarga nada nuevo. */
  const sw = leer('sw.js');
  const m = sw.match(/const PRECARGA = \[([\s\S]*?)\];/);
  afirmar('sw.js declara su lista de precarga', !!m);
  const lista = m ? [...m[1].matchAll(/'([^']+)'/g)].map((x) => x[1]) : [];

  for (const f of ['cola.js', 'ui.js', 'guia.js', 'index.html', 'estilo.css', 'app.js']) {
    afirmar(`precarga ${f}`, lista.includes(f), lista.join(', '));
  }
  const inexistentes = lista.filter((f) => !existsSync(join(PUB, f)));
  afirmar('no precarga archivos que no existen', inexistentes.length === 0, inexistentes.join(', '));

  afirmar('la versión subió (v3 o más)', /ot-industec-v([3-9]|\d\d)/.test(sw),
          (sw.match(/ot-industec-v\d+/) || [''])[0]);
  afirmar('yo.php va con los datos, no con el armazón',
          /PRECARGA_DATOS = \[[^\]]*'yo\.php'/.test(sw));
  /* El envío NUNCA se cachea: una respuesta servida desde caché sería una
     orden que el técnico cree enviada y que nunca salió. */
  afirmar('el trabajador de servicio solo intercepta GET',
          /req\.method !== 'GET'\) return/.test(sw));
}

console.log('');
console.log(`${total} comprobaciones · ${fallos} fallos`);
process.exit(fallos > 0 ? 1 : 0);
