/* =========================================================================
   graficos.js — Los gráficos de los reportes. SVG escrito a mano.

   POR QUE NO HAY UNA LIBRERIA
     1. Política del proyecto: software libre y lo mínimo necesario. Las
        librerías de gráficos pesan cientos de kilobytes para dibujar seis
        barras, y aquí no hace falta ni una décima parte de lo que traen.
     2. El sitio muestra datos de un cliente. Cada guion de un tercero que se
        carga es superficie de ataque nueva, y el `.htaccess` no protege de
        código que se ejecuta dentro de la página.
     3. Tiene que dibujarse sin señal. Lo que depende de una CDN, no lo hace.

   COMO SE USA — todo declarativo, para que PHP solo tenga que soltar el JSON:

     <figure class="viz" data-viz="barras"
             data-titulo="Órdenes por zona"
             data-sub="Las 918 de la ventana de 90 días"
             data-datos='[{"e":"UIO","v":258,"c":"#7c3aed"},{"e":"CUENCA-LOJA","c":…}]'></figure>

   Los rótulos (`e`, `data-titulo`, `data-sub`) llegan ya escritos desde PHP
   con los términos de vocabulario.json: este archivo no nombra estados. Por
   eso cada dato trae su color en `c` —«CUENCA-LOJA» no es una clave de ZONA—
   y `color()` solo cae a buscar la clave cuando no viene.

   Formas disponibles y cuándo usar cada una:

     barras    magnitud comparada entre categorías con nombre largo (locales,
               técnicos, tipos de trabajo). Horizontal: la etiqueta se lee.
     columnas  la misma magnitud a lo largo del tiempo (meses). Vertical,
               porque el tiempo se lee de izquierda a derecha.
     anillo    composición de un total, HASTA 5 porciones. Con más, las
               porciones chicas dejan de compararse y se usa `apilada`.
     apilada   composición cuando hay muchas partes: una sola fila repartida.

   LAS REGLAS QUE NO SE SALTAN, y por qué

     - Un solo eje. Nunca dos escalas verticales en el mismo gráfico: es la
       forma más fácil de sugerir una relación que los datos no dicen.
     - El color sigue a la entidad, no a su posición. UIO es violeta esté
       primera o última, y filtrar no repinta lo que queda.
     - Los colores salen de una paleta ya validada: banda de luminosidad,
       croma, separación para daltonismo (protan, deutan y tritán),
       separación en visión normal y contraste contra el fondo. Las zonas se
       comprobaron contra todos los pares porque conviven en un mismo gráfico.
     - Toda serie va etiquetada además de coloreada, y todo gráfico lleva su
       tabla debajo. Nadie tiene que distinguir dos azules para leer un dato.
   ========================================================================= */
(function (global) {
  'use strict';

  /* --- Paleta ------------------------------------------------------------
     Orden FIJO. La octava serie no se genera: se pliega en «Otros». Un color
     inventado al vuelo no pasa ninguna comprobación. */
  var SERIES = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100',
                '#e87ba4', '#008300', '#4a3aa7', '#e34948'];

  /* Colores con significado propio. NO se reutilizan como «serie 4»: si el
     rojo es «vencida (48 h)» en un gráfico, no puede ser «Cuenca» en el de al lado. */
  var ZONA   = { UIO: '#7c3aed', LARB: '#0d9488', CNLJ: '#ea580c', OTRA: '#64748b' };
  var ESTADO = {
    NUEVO: '#94a3b8', ASIGNADO: '#2a78d6', EN_REVISION: '#eda100',
    ESPERA_REPUESTO: '#eb6834', ATENDIDO: '#1baf7a', RESUELTO: '#008300',
    NO_COMPETE: '#4a3aa7', CERRADO_SIN_ATENCION: '#e34948', REGULARIZADO: '#64748b'
  };
  var SEMAFORO = { bien: '#1baf7a', ojo: '#eda100', mal: '#e34948', neutro: '#94a3b8' };

  function color(d, i) {
    if (d.c) { return d.c; }
    var k = String(d.e || '').toUpperCase().replace(/ /g, '_');
    return ZONA[k] || ESTADO[k] || SEMAFORO[d.e] || SERIES[i % SERIES.length];
  }

  function svgEl(n, attrs) {
    var e = document.createElementNS('http://www.w3.org/2000/svg', n);
    for (var k in attrs) { if (attrs[k] != null) { e.setAttribute(k, attrs[k]); } }
    return e;
  }
  function num(v) { return Number(v || 0).toLocaleString('es-EC'); }

  /* --- La ayuda al pasar el puntero --------------------------------------
     Un solo recuadro para toda la página, movido con el puntero. Es la capa
     que convierte un gráfico en algo que se puede interrogar: la barra dice
     «mucho», el recuadro dice «258 casos, el 28%». */
  var globo = null;
  function ayuda(texto, ev) {
    if (!globo) {
      globo = document.createElement('div');
      globo.style.cssText = 'position:fixed;z-index:95;pointer-events:none;background:#0f172a;' +
        'color:#fff;font-size:12px;padding:6px 9px;border-radius:7px;box-shadow:0 6px 18px ' +
        'rgba(15,23,42,.28);white-space:nowrap;opacity:0;transition:opacity 110ms';
      document.body.appendChild(globo);
    }
    globo.textContent = texto;
    globo.style.opacity = '1';
    var x = ev.clientX + 13, y = ev.clientY - 12;
    // Que no se salga por el borde derecho: en el celular se sale siempre.
    if (x + globo.offsetWidth > innerWidth - 8) { x = ev.clientX - globo.offsetWidth - 13; }
    globo.style.left = x + 'px';
    globo.style.top = y + 'px';
  }
  function ocultarAyuda() { if (globo) { globo.style.opacity = '0'; } }

  function conAyuda(el, texto) {
    // <title> nativo: sirve para lector de pantalla y si el guion falla.
    el.appendChild(svgEl('title')).textContent = texto;
    el.addEventListener('mousemove', function (ev) { ayuda(texto, ev); });
    el.addEventListener('mouseleave', ocultarAyuda);
    el.classList.add('marca');
    return el;
  }

  /* --- Barras horizontales -----------------------------------------------
     Para categorías con nombre: el nombre se lee de corrido, sin girar la
     cabeza ni la etiqueta. */
  function barras(datos, opts) {
    var anchoEt = opts.anchoEtiqueta || 118;
    var alto = 26, hueco = 8;
    var w = 560, h = datos.length * (alto + hueco) + 6;
    var max = Math.max.apply(null, datos.map(function (d) { return +d.v || 0; }).concat([1]));
    var total = datos.reduce(function (a, d) { return a + (+d.v || 0); }, 0);
    var zona = w - anchoEt - 62;

    var svg = svgEl('svg', { viewBox: '0 0 ' + w + ' ' + h, role: 'img', 'aria-label': opts.titulo || null });

    datos.forEach(function (d, i) {
      var y = i * (alto + hueco);
      var v = +d.v || 0;
      var len = Math.max((v / max) * zona, v > 0 ? 3 : 0);

      var et = svgEl('text', { x: anchoEt - 8, y: y + alto / 2 + 4, class: 'etiqueta',
                               'text-anchor': 'end' });
      et.textContent = d.e;
      svg.appendChild(et);

      // Canal de fondo: da idea del tope sin dibujar un eje más.
      svg.appendChild(svgEl('rect', { x: anchoEt, y: y + 4, width: zona, height: alto - 8,
                                      rx: 4, fill: '#f1f5f9' }));

      var barra = svgEl('rect', { x: anchoEt, y: y + 4, width: len, height: alto - 8,
                                  rx: 4, fill: color(d, i) });
      var pct = total ? Math.round(v * 1000 / total) / 10 : 0;
      conAyuda(barra, d.e + ': ' + num(v) + (opts.unidad ? ' ' + opts.unidad : '') +
                      (total && !opts.sinPorcentaje ? ' · ' + pct + '%' : ''));
      svg.appendChild(barra);

      // Etiqueta directa. El método pide no numerar todos los puntos de una
      // línea, pero en barras el valor exacto es justo lo que se busca.
      var val = svgEl('text', { x: anchoEt + len + 7, y: y + alto / 2 + 4, class: 'valor' });
      val.textContent = num(v);
      svg.appendChild(val);
    });
    return svg;
  }

  /* --- Columnas ----------------------------------------------------------
     Para el tiempo. Con más de 14 columnas se ocultan etiquetas alternas: es
     preferible perder rótulos a que se pisen. */
  function columnas(datos, opts) {
    var w = 560, h = 200, base = h - 26, tope = 14;
    var max = Math.max.apply(null, datos.map(function (d) { return +d.v || 0; }).concat([1]));
    var paso = w / Math.max(datos.length, 1);
    var ancho = Math.min(paso - 6, 46);

    var svg = svgEl('svg', { viewBox: '0 0 ' + w + ' ' + h, role: 'img', 'aria-label': opts.titulo || null });

    // Cuatro líneas de rejilla y sus valores. Más líneas no ayudan a leer.
    for (var g = 0; g <= 4; g++) {
      var y = tope + (base - tope) * (g / 4);
      svg.appendChild(svgEl('line', { x1: 30, y1: y, x2: w, y2: y, class: 'rejilla' }));
      var t = svgEl('text', { x: 24, y: y + 4, class: 'etiqueta', 'text-anchor': 'end' });
      t.textContent = num(Math.round(max * (1 - g / 4)));
      svg.appendChild(t);
    }

    datos.forEach(function (d, i) {
      var v = +d.v || 0;
      var alto = Math.max((v / max) * (base - tope), v > 0 ? 3 : 0);
      var x = 30 + i * ((w - 30) / datos.length) + (((w - 30) / datos.length) - ancho) / 2;

      var col = svgEl('rect', { x: x, y: base - alto, width: ancho, height: alto,
                                rx: 4, fill: color(d, i) });
      conAyuda(col, d.e + ': ' + num(v) + (opts.unidad ? ' ' + opts.unidad : ''));
      svg.appendChild(col);

      if (datos.length <= 14 || i % 2 === 0) {
        var et = svgEl('text', { x: x + ancho / 2, y: base + 15, class: 'etiqueta',
                                 'text-anchor': 'middle' });
        et.textContent = d.e;
        svg.appendChild(et);
      }
    });
    svg.appendChild(svgEl('line', { x1: 30, y1: base, x2: w, y2: base, class: 'eje' }));
    return svg;
  }

  /* --- Anillo ------------------------------------------------------------
     Composición de un total. Solo hasta cinco porciones: con más, comparar dos
     ángulos pequeños es imposible y el gráfico deja de informar. La función
     pliega el resto en «Otros» ella sola en vez de dibujar algo ilegible.

     Va con hueco al medio, y ahí se pone el total. Un anillo con el total en
     el centro responde dos preguntas —cuánto hay y cómo se reparte— donde un
     pastel macizo solo responde la segunda. */
  function anillo(datos, opts) {
    var s = 210, r = 88, gr = 26, cx = s / 2, cy = s / 2;
    var total = datos.reduce(function (a, d) { return a + (+d.v || 0); }, 0);
    var svg = svgEl('svg', { viewBox: '0 0 ' + s + ' ' + s, role: 'img', 'aria-label': opts.titulo || null });

    if (total <= 0) {
      var vacio = svgEl('text', { x: cx, y: cy, class: 'etiqueta', 'text-anchor': 'middle' });
      vacio.textContent = 'Sin datos';
      svg.appendChild(vacio);
      return svg;
    }

    var ang = -Math.PI / 2;      // arranca arriba, como un reloj
    datos.forEach(function (d, i) {
      var v = +d.v || 0;
      if (v <= 0) { return; }
      var barrido = (v / total) * Math.PI * 2;
      var fin = ang + barrido;
      var grande = barrido > Math.PI ? 1 : 0;
      var re = r, ri = r - gr;
      var p = [
        'M', cx + re * Math.cos(ang), cy + re * Math.sin(ang),
        'A', re, re, 0, grande, 1, cx + re * Math.cos(fin), cy + re * Math.sin(fin),
        'L', cx + ri * Math.cos(fin), cy + ri * Math.sin(fin),
        'A', ri, ri, 0, grande, 0, cx + ri * Math.cos(ang), cy + ri * Math.sin(ang), 'Z'
      ].join(' ');

      var porcion = svgEl('path', { d: p, fill: color(d, i), class: 'sep' });
      conAyuda(porcion, d.e + ': ' + num(v) + ' · ' + Math.round(v * 1000 / total) / 10 + '%');
      svg.appendChild(porcion);
      ang = fin;
    });

    var n = svgEl('text', { x: cx, y: cy - 2, 'text-anchor': 'middle',
                            style: 'font-size:26px;font-weight:700;fill:#0f172a' });
    n.textContent = num(total);
    svg.appendChild(n);
    var t = svgEl('text', { x: cx, y: cy + 16, class: 'etiqueta', 'text-anchor': 'middle' });
    t.textContent = opts.centro || 'en total';
    svg.appendChild(t);
    return svg;
  }

  /* --- Apilada -----------------------------------------------------------
     Una sola fila que reparte el total. Es lo que sustituye al pastel cuando
     hay siete partes: las porciones chicas siguen siendo visibles porque
     comparten la misma altura, y el orden se mantiene estable. */
  function apilada(datos) {
    var total = datos.reduce(function (a, d) { return a + (+d.v || 0); }, 0) || 1;
    var caja = document.createElement('div');
    caja.className = 'compo';
    datos.forEach(function (d, i) {
      var v = +d.v || 0;
      if (v <= 0) { return; }
      var i2 = document.createElement('i');
      i2.style.width = (v / total * 100) + '%';
      i2.style.background = color(d, i);
      var pct = Math.round(v * 1000 / total) / 10;
      i2.title = d.e + ': ' + num(v) + ' · ' + pct + '%';
      i2.addEventListener('mousemove', function (ev) {
        ayuda(d.e + ': ' + num(v) + ' · ' + pct + '%', ev);
      });
      i2.addEventListener('mouseleave', ocultarAyuda);
      caja.appendChild(i2);
    });
    return caja;
  }

  /* --- Leyenda y tabla ---------------------------------------------------
     La leyenda se dibuja siempre que haya dos series o más; con una sola, el
     título ya la nombra y la leyenda es ruido.

     La tabla no es opcional. Tres de los colores de la paleta quedan por
     debajo de 3:1 contra el fondo blanco, y la regla que los deja pasar exige
     compensarlo con etiqueta visible o con la tabla. Aquí están las dos. */
  function leyenda(datos) {
    if (datos.length < 2) { return null; }
    var d = document.createElement('div');
    d.className = 'viz-leyenda';
    datos.forEach(function (x, i) {
      var s = document.createElement('span');
      var c = document.createElement('i');
      c.style.background = color(x, i);
      s.appendChild(c);
      s.appendChild(document.createTextNode(x.e));
      d.appendChild(s);
    });
    return d;
  }

  function tabla(datos, unidad) {
    var total = datos.reduce(function (a, d) { return a + (+d.v || 0); }, 0);
    var det = document.createElement('details');
    det.className = 'viz-tabla';
    var res = document.createElement('summary');
    res.textContent = 'Ver los números';
    det.appendChild(res);

    var t = document.createElement('table');
    var filas = '<thead><tr><th>Qué</th><th class="n">' +
                (unidad || 'Cantidad') + '</th><th class="n">%</th></tr></thead><tbody>';
    datos.forEach(function (d) {
      var v = +d.v || 0;
      filas += '<tr><td>' + esc(d.e) + '</td><td class="n">' + num(v) + '</td>' +
               '<td class="n">' + (total ? Math.round(v * 1000 / total) / 10 + '%' : '—') + '</td></tr>';
    });
    filas += '</tbody><tfoot><tr><th>Total</th><th class="n">' + num(total) +
             '</th><th class="n">100%</th></tr></tfoot>';
    t.innerHTML = filas;
    det.appendChild(t);
    return det;
  }

  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  /* --- Montaje ----------------------------------------------------------- */
  function dibujar(fig) {
    var tipo = fig.getAttribute('data-viz');
    var datos;
    try { datos = JSON.parse(fig.getAttribute('data-datos') || '[]'); }
    catch (e) { datos = []; }

    var opts = {
      // El título del gráfico es también su nombre accesible (TR-21).
      titulo: fig.getAttribute('data-titulo') || '',
      unidad: fig.getAttribute('data-unidad') || '',
      centro: fig.getAttribute('data-centro') || '',
      anchoEtiqueta: +fig.getAttribute('data-ancho-etiqueta') || 0,
      sinPorcentaje: fig.hasAttribute('data-sin-porcentaje')
    };

    // Con más de cinco porciones el anillo deja de poder leerse. En vez de
    // dibujarlo mal, se pliega la cola en «Otros» — que es lo que dice el
    // método y lo que a la vista funciona.
    if (tipo === 'anillo' && datos.length > 5) {
      var cabeza = datos.slice(0, 4);
      var resto = datos.slice(4).reduce(function (a, d) { return a + (+d.v || 0); }, 0);
      if (resto > 0) { cabeza.push({ e: 'Otros', v: resto, c: '#94a3b8' }); }
      datos = cabeza;
    }

    fig.innerHTML = '';
    var tit = fig.getAttribute('data-titulo');
    if (tit) {
      var h = document.createElement('h3');
      h.textContent = tit;
      fig.appendChild(h);
    }
    var sub = fig.getAttribute('data-sub');
    if (sub) {
      var p = document.createElement('p');
      p.className = 'viz-sub';
      p.textContent = sub;
      fig.appendChild(p);
    }

    if (!datos.length) {
      var v = document.createElement('p');
      v.className = 'vacio';
      v.textContent = 'Todavía no hay datos para este gráfico.';
      fig.appendChild(v);
      return;
    }

    var cuerpo = tipo === 'columnas' ? columnas(datos, opts)
               : tipo === 'anillo'   ? anillo(datos, opts)
               : tipo === 'apilada'  ? apilada(datos)
               : barras(datos, { anchoEtiqueta: opts.anchoEtiqueta || 118, titulo: opts.titulo,
                                 unidad: opts.unidad, sinPorcentaje: opts.sinPorcentaje });
    fig.appendChild(cuerpo);

    var l = leyenda(datos);
    if (l && tipo !== 'barras') { fig.appendChild(l); }
    fig.appendChild(tabla(datos, opts.unidad));
  }

  function arranque() {
    document.querySelectorAll('[data-viz]').forEach(dibujar);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', arranque);
  } else {
    arranque();
  }

  global.Graficos = { dibujar: dibujar, COLOR_ZONA: ZONA, COLOR_ESTADO: ESTADO, SERIES: SERIES };
})(window);
