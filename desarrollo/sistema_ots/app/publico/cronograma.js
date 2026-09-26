/* =========================================================================
   cronograma.js — Preventivos: lo que toca, el mes y el cumplimiento del año.

   DESDE EL 2026-09-13 ESCRIBE (D15, T2.14.5). Confirmar el kit, reagendar,
   marcar como ejecutado (la acción «cerrar» del servidor), agendar un local y
   anotar un movimiento del ingreso (la acción «nota») van a
   `cronograma_accion.php` (POST JSON con X-Csrf) y quedan en la base con su
   bitácora. Mientras el cronograma no esté importado a la base (`fuente`
   distinta de «tabla»), la pantalla lo dice y solo deja agendar.

   LAS TRES REGLAS QUE ORDENAN EL DISEÑO (y que el servidor vuelve a exigir):

   1. EL PLAN ORIGINAL NO SE PISA. Cada ingreso guarda lo acordado con KFC
      (`plan_original`) y lo que rige hoy (`plan_vigente`). Reagendar mueve el
      vigente y exige un motivo. Si al reagendar se sobrescribiera la fecha, el
      cumplimiento siempre daría 100% y no habría nada que reportarle a KFC.

   2. LA NOVEDAD ES OBLIGATORIA AL MOVER UNA FECHA. Es la evidencia de por qué
      se demoró el cronograma. Sin motivo no se guarda el cambio.

   3. EL KIT ES UNA PRECONDICIÓN, NO UN COMENTARIO. Antes de agendar hay que
      confirmarlo; si no está, se reagenda y queda registrado el motivo.

   -------------------------------------------------------------------------
   REESCRITA LA PRESENTACIÓN EL 2026-09-21. Andrés: «la interfaz de preventivos
   me parece muy aturdidora, agolpa tanta información». Medido contra los 368
   ingresos reales de 2026, tenía razón y de sobra: la primera pantalla ponía a
   la vez una franja de cinco chips, ocho tarjetas de cifras teñidas, tres
   recuadros por zona con seis cifras cada uno, una leyenda de siete colores y
   un calendario donde los sesenta chips repetían todos la misma palabra —«sin
   kit»—, porque el 86 % de los ingresos la tiene pendiente. Ciento diez
   elementos antes de desplazarse, ocho colores compitiendo, y tres de las ocho
   cifras eran ceros permanentes.

   Lo que cambia, tomado de cómo resuelven esto los sistemas de mantenimiento
   (Limble, MaintainX, Fiix, MPulse, y el propio SAP PM que usa Grupo KFC):

   · TRES HORIZONTES, UNO A LA VEZ. El mes sirve para ver dónde se amontona el
     trabajo; la semana es donde se decide de verdad; el año es lo que se le
     reporta al cliente. Estaban los tres apilados en la misma pantalla.
   · EL ATRASO ES UNA COLA, NO UN COLOR. Lo vencido y lo que nadie cerró se
     trabajan en su propio bloque, no buscándolos dentro del calendario.
   · «SIN CERRAR» ES UN ESTADO. La regla del servidor marca «en curso» todo lo
     que tenga una orden emitida, sin caducidad: 145 ingresos que arrancaron
     antes de agosto seguían pintados de azul como si estuvieran ocurriendo.
     No es una regla nueva —el servidor sigue diciendo `encurso`—, es decir en
     pantalla lo que ese azul escondía (I-7).
   · EL KIT SE MARCA CUANDO TODAVÍA SIRVE. Un distintivo que sale en el 86 % de
     las filas no distingue nada. Va como punto, y solo dentro del horizonte en
     el que al kit aún se le puede reclamar a KFC.
   · LAS CIFRAS QUE SON CERO NO OCUPAN SITIO, y un porcentaje que no se puede
     calcular se dice con palabras en vez de dibujar un «0 %» rojo (I-7).

   La franja de alertas sigue ahí, fija y sin botón de descartar como la pidió
   el cliente, pero con una sola frase: una alerta que aparece siempre y dice
   cinco cosas a la vez deja de leerse.
   ========================================================================= */

(function () {
  'use strict';

  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };

  var DATOS = null;
  var INGRESOS = [];
  var CORRECTIVOS = [];
  var CSRF = '';
  var PUEDE_EDITAR = false;
  var ESCRIBE = false;               // true cuando el cronograma vive en la base
  var hoy = new Date(); hoy.setHours(0, 0, 0, 0);
  var mesVista = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
  var zonaVista = 'ADMIN';
  var vista = 'ahora';
  var filtroActivo = null;
  var panelAbiertoId = null;
  var focoAntes = null;
  var diasAbiertos = {};             // días del calendario con «+N más» desplegado

  var MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
               'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  var DIAS = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];
  var ZONAS = ['UIO', 'LARB', 'CNLJ'];

  /* El horizonte con el que se mira «lo que viene». Quince días es lo que dura
     conseguir un kit y reacomodar una semana; el «inicia en ≤3 días» del
     servidor es el aviso final, no la ventana de planificación. */
  var HORIZONTE = 15;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  function d(iso) { if (!iso) return null; var p = String(iso).slice(0, 10).split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
  function iso(fecha) {
    return fecha.getFullYear() + '-' + String(fecha.getMonth() + 1).padStart(2, '0') +
           '-' + String(fecha.getDate()).padStart(2, '0');
  }
  function corta(isoStr) { var f = d(isoStr); return f ? String(f.getDate()).padStart(2, '0') + '/' + String(f.getMonth() + 1).padStart(2, '0') : '—'; }
  function larga(isoStr) { var f = d(isoStr); return f ? f.getDate() + ' de ' + MESES[f.getMonth()] : 'sin fecha'; }
  function dias(a, b) { return Math.round((b - a) / 86400000); }
  function plural(n, uno, muchos) { return n === 1 ? uno : muchos; }
  function toast(texto, tono) {
    if (window.UI && window.UI.toast) { window.UI.toast(texto, tono || 'ok'); } else { alert(texto); }
  }

  /* ---------- Estado ------------------------------------------------------
     El servidor ya manda el estado calculado a HOY (`estado_hoy`, la misma
     regla que Reportes::estadoPreventivo); si faltara, se recalcula aquí. */
  function estadoServidor(i) {
    if (i.estado_hoy) return i.estado_hoy;
    if (!i.plan_vigente) return 'sinagendar';
    if (i.estado === 'CUMPLIDO' || (i.real && i.real.cerrado)) return 'cumplido';
    if (i.estado === 'CANCELADO') return 'cancelado';
    if (i.real && ((i.real.ots && i.real.ots.length) || i.real.inicio)) return 'encurso';
    var ini = d(i.plan_vigente.inicio), fin = d(i.plan_vigente.fin || i.plan_vigente.inicio);
    if (fin < hoy) return 'vencido';
    if (dias(hoy, ini) <= 3) return 'poriniciar';
    return 'planificado';
  }

  /**
   * El estado que se PINTA. Es el del servidor más una distinción que el
   * servidor no hace y que los datos exigen: «en curso» con el fin previsto ya
   * pasado no está en curso, está SIN CERRAR. Con los 368 ingresos de 2026,
   * 145 de los 176 «en curso» habían arrancado antes de agosto. El contrato con
   * el servidor no se toca: para él siguen siendo `encurso`.
   */
  function estado(i) {
    var e = estadoServidor(i);
    if (e === 'encurso' && finPrevisto(i) && finPrevisto(i) < iso(hoy)) return 'sincerrar';
    return e;
  }
  function finPrevisto(i) {
    if (!i.plan_vigente) return null;
    return i.plan_vigente.fin || i.plan_vigente.inicio || null;
  }
  function inicioPrevisto(i) { return i.plan_vigente ? i.plan_vigente.inicio : null; }
  /** Días desde que se pasó el fin previsto. 0 o negativo = todavía no vence. */
  function atraso(i) {
    var f = finPrevisto(i);
    return f ? dias(d(f), hoy) : 0;
  }

  /* ---------- Las palabras de cada estado ---------------------------------
     Salen del diccionario único (vocabulario.json, vía UI.T de ui.js), el
     mismo que usan el reporte a KFC y la leyenda del jefe técnico:
     EJECUTADO · PENDIENTE · ATRASADO. Los CÓDIGOS de la izquierda ('vencido',
     'sincerrar'…) son los de `estado_hoy` que manda el servidor y los que se
     comparan en todo este archivo y en cronograma.css: no cambian, solo el
     texto. «cierre» ya no se usa aquí: sola es la OT INDUSTEC de cierre, y el
     ingreso preventivo se «marca como ejecutado». */
  var CODIGOS = ['vencido', 'sincerrar', 'poriniciar', 'encurso', 'planificado', 'sinagendar', 'cumplido', 'cancelado'];
  function claveDe(codigo) { return UI.T.deEstado(codigo, 'preventivo'); }
  function mayus(s) { s = String(s); return s.charAt(0).toUpperCase() + s.slice(1); }
  /** El nombre de un estado para contar («3 atrasados»), en singular o plural. */
  function nombre(codigo, n) { return UI.T(claveDe(codigo), n); }
  var ETIQUETA = {};
  var EXPLICA = {};
  CODIGOS.forEach(function (c) {
    ETIQUETA[c] = UI.T.titulo(claveDe(c));
    EXPLICA[c] = UI.T.ayuda(claveDe(c));
  });
  /* Las zonas se rotulan como en el resto del sistema (CNLJ -> CUENCA-LOJA).
     Un código que el diccionario no conoce se muestra tal cual: es el dato,
     no un nombre inventado para taparlo (I-7). */
  function nombreZona(z, largo) {
    var k = String(z == null ? '' : z).trim().toUpperCase();
    if (ZONAS.indexOf(k) === -1 && k !== 'OTRA') return String(z == null ? '' : z);
    var clave = UI.T.deEstado(k, 'zona');
    return largo ? UI.T.titulo(clave) : UI.T.corto(clave);
  }
  /* El tipo de cada movimiento del ingreso (cronograma_novedades.tipo), con
     las palabras del diccionario (PREV_MOVIMIENTO): el de CIERRE es la marca
     de ejecutado. */
  var TIPO_MOVIMIENTO = { AGENDA: 'agenda', REAGENDA: 'reagenda', KIT: 'kit', CIERRE: 'marca de ejecutado', NOTA: 'nota' };

  function kitOk(i) { return i.kit === 'CONFIRMADO' || i.kit === 'ENTREGADO' || i.kit === 'DISPONIBLE'; }
  function kitTexto(i) {
    return { SIN_KIT: 'sin kit', PENDIENTE: 'sin kit', SOLICITADO: 'kit solicitado',
             CONFIRMADO: 'kit confirmado', ENTREGADO: 'kit entregado', DISPONIBLE: 'kit disponible' }[i.kit] || String(i.kit || '').toLowerCase();
  }
  /* El kit se marca SOLO donde todavía se puede hacer algo: un ingreso que aún
     no arranca y que arranca dentro del horizonte. En lo vencido y en lo que
     está sin cerrar el kit ya no es la acción que toca —es tarde—, y marcarlo
     ahí devolvía el distintivo al 86 % de las filas, que es de donde veníamos.
     La regla, en una línea: un distintivo solo sirve si distingue. */
  function kitUrge(i) {
    if (kitOk(i)) return false;
    var e = estado(i);
    if (e !== 'poriniciar' && e !== 'planificado') return false;
    var ini = inicioPrevisto(i);
    return !!ini && dias(hoy, d(ini)) <= HORIZONTE;
  }
  function movido(i) { return !!(i.plan_original && i.plan_vigente && i.plan_original.inicio !== i.plan_vigente.inicio); }
  function aTiempo(i) {
    if (estado(i) !== 'cumplido') return null;
    var finReal = i.real && (i.real.fin || i.real.inicio);
    var finPlan = i.plan_original && (i.plan_original.fin || i.plan_original.inicio);
    return !finPlan || (finReal && finReal <= finPlan);
  }

  function visibles() {
    return INGRESOS.filter(function (i) { return zonaVista === 'ADMIN' || i.zona === zonaVista; });
  }
  function zonaClase(i) {
    if (zonaVista !== 'ADMIN') return '';
    return ZONAS.indexOf(i.zona) !== -1 ? ' z-' + i.zona.toLowerCase() : '';
  }
  function porFecha(a, b) {
    var fa = inicioPrevisto(a) || '9999', fb = inicioPrevisto(b) || '9999';
    return fa.localeCompare(fb) || String(a.local).localeCompare(String(b.local));
  }

  /* ---------- La barra: los módulos que el servidor dice que le tocan ------- */
  function pintarNav(modulos) {
    var nav = $('#barraNav');
    if (!nav || !modulos || !modulos.length) return;
    nav.innerHTML = modulos.map(function (m) {
      var on = m.url === 'cronograma.html';
      return '<a href="' + esc(m.url) + '"' + (on ? ' class="on" aria-current="page"' : '') + '>' + esc(m.etiqueta) + '</a>';
    }).join('');
  }

  /* La franja de alertas va debajo de la barra, no encima (TR-13). */
  function ajustarFranja() {
    var barra = $('.app-barra');
    document.documentElement.style.setProperty('--alto-barra', (barra ? barra.offsetHeight : 0) + 'px');
  }

  /* ---------- Los grupos de trabajo --------------------------------------- */
  function grupos() {
    var v = visibles();
    var g = { vencido: [], sincerrar: [], enmarcha: [], arrancan: [], sinagendar: [], cumplido: [] };
    v.forEach(function (i) {
      var e = estado(i);
      if (e === 'vencido') { g.vencido.push(i); return; }
      if (e === 'sincerrar') { g.sincerrar.push(i); return; }
      if (e === 'sinagendar') { g.sinagendar.push(i); return; }
      if (e === 'cumplido') { g.cumplido.push(i); return; }
      /* «En marcha» es lo que ya empezó y sigue dentro de la fecha prevista.
         «Arrancan» es lo que todavía no empieza y empieza dentro del horizonte.
         Estaban mezclados, y el titular decía «arrancan» de cosas que ya
         habían arrancado. */
      if (e === 'encurso') { g.enmarcha.push(i); return; }
      /* «Arranca ya» entra siempre, incluso si la fecha de inicio ya pasó y el
         ingreso todavía no vence porque su fin es hoy o mañana. Ese caso se
         colaba por un hueco entre bloques y no aparecía en ninguno: es
         justamente el que hay que mirar hoy, no mañana. */
      if (e === 'poriniciar') { g.arrancan.push(i); return; }
      var ini = inicioPrevisto(i);
      if (ini && dias(hoy, d(ini)) >= 0 && dias(hoy, d(ini)) <= HORIZONTE) { g.arrancan.push(i); }
    });
    g.vencido.sort(function (a, b) { return atraso(b) - atraso(a); });
    g.sincerrar.sort(function (a, b) { return atraso(b) - atraso(a); });
    g.enmarcha.sort(porFecha);
    g.arrancan.sort(porFecha);
    g.sinagendar.sort(function (a, b) { return String(a.local).localeCompare(String(b.local)); });
    /* Los dos se trabajan juntos —es «la quincena»—, pero se cuentan aparte
       para que cada frase diga la verdad. */
    g.quincena = g.enmarcha.concat(g.arrancan).sort(porFecha);
    return g;
  }

  /* ---------- Franja de alertas: una sola frase --------------------------- */
  function pintarAlertas(g) {
    var cont = $('#alertas');
    var partes = [];
    if (g.vencido.length) partes.push(chip('roja', g.vencido.length, nombre('vencido', g.vencido.length), 'vencido'));
    if (g.sincerrar.length) partes.push(chip('ambar', g.sincerrar.length, nombre('sincerrar', g.sincerrar.length), 'sincerrar'));
    if (!partes.length) { cont.hidden = true; cont.innerHTML = ''; return; }
    cont.hidden = false;
    cont.innerHTML = partes.join('') +
      '<span class="aviso-fuente">Se apaga sola cuando el ingreso se marca como ejecutado</span>';
    $$('.alerta[data-f]', cont).forEach(function (b) {
      b.addEventListener('click', function () { filtrarPor(b.dataset.f); });
    });
  }
  /* Los chips son botones: se operan con teclado igual que con el dedo (TR-14). */
  function chip(color, n, texto, filtro) {
    return '<button type="button" class="alerta ' + color + '" data-f="' + filtro + '">' +
           '<span class="n">' + n + '</span>' + esc(texto) + '</button>';
  }

  /* ---------- El titular: una frase con lo que hay que hacer -------------- */
  function pintarTitular(g) {
    var el = $('#titular');
    var donde = zonaVista === 'ADMIN' ? 'las tres zonas' : esc(nombreZona(zonaVista));
    var urgen = g.vencido.length + g.sincerrar.length;
    var frases = [];
    if (g.vencido.length) frases.push('<b>' + g.vencido.length + '</b> ' + esc(nombre('vencido', g.vencido.length)));
    if (g.sincerrar.length) frases.push('<b>' + g.sincerrar.length + '</b> ' + esc(nombre('sincerrar', g.sincerrar.length)));
    var viene = g.arrancan.length
      ? 'Quedan <b>' + g.arrancan.length + '</b> por arrancar dentro de los próximos ' + HORIZONTE + ' días'
        + (g.enmarcha.length ? ', y <b>' + g.enmarcha.length + '</b> ya en ejecución' : '') + '.'
      : (g.enmarcha.length ? 'Hay <b>' + g.enmarcha.length + '</b> en ejecución y ninguno por arrancar en ' +
         HORIZONTE + ' días.' : 'No hay ninguno por arrancar en los próximos ' + HORIZONTE + ' días.');
    var t;
    if (urgen) {
      t = 'En ' + donde + ' hay ' + frases.join(' y ') + '. ' + viene;
      el.className = 'titular urge';
    } else {
      // Los dos grupos son la base ATRASADO de la leyenda: basta una palabra.
      t = 'Nada ' + esc(UI.T('ATRASADO')) + ' en ' + donde + '. ' + viene;
      el.className = 'titular calma';
    }
    if (g.sinagendar.length) {
      t += ' Quedan <b>' + g.sinagendar.length + '</b> ' + esc(nombre('sinagendar', g.sinagendar.length)) + '.';
    }
    el.innerHTML = t;
  }

  /* ---------- Filtros rápidos: la cifra va dentro del botón que la filtra -- */
  function pintarFiltros(g) {
    var v = visibles();
    var f = [
      ['todos', 'Todos', v.length, ''],
      ['vencido', nombreFiltro('vencido'), g.vencido.length, 'roja'],
      ['sincerrar', nombreFiltro('sincerrar'), g.sincerrar.length, 'ambar'],
      ['poriniciar', nombreFiltro('poriniciar'), v.filter(function (i) { return estado(i) === 'poriniciar'; }).length, 'ambar'],
      ['sinkit', nombreFiltro('sinkit'), v.filter(kitUrge).length, 'ambar'],
      ['sinagendar', nombreFiltro('sinagendar'), g.sinagendar.length, ''],
      ['cumplido', nombreFiltro('cumplido'), g.cumplido.length, ''],
      ['reagendado', nombreFiltro('reagendado'), v.filter(movido).length, ''],
    ];
    /* Un filtro que siempre da cero no es información: es una casilla que hay
       que leer y descartar cada vez. Solo se dibujan los que tienen algo. */
    $('#filtros').innerHTML = f.filter(function (x) { return x[0] === 'todos' || x[2] > 0; })
      .map(function (x) {
        var on = (filtroActivo === x[0]) || (!filtroActivo && x[0] === 'todos');
        return '<button type="button" class="fr ' + x[3] + (on ? ' on' : '') + '" data-f="' + x[0] + '">' +
               esc(x[1]) + '<span class="n">' + x[2] + '</span></button>';
      }).join('');
    $$('.fr[data-f]', $('#filtros')).forEach(function (b) {
      b.addEventListener('click', function () {
        filtrarPor(b.dataset.f === 'todos' ? null : b.dataset.f);
      });
    });
  }

  function cumpleFiltro(i, f) {
    if (!f) return true;
    if (f === 'todos') return true;
    if (f === 'sinkit') return kitUrge(i);
    if (f === 'reagendado') return movido(i);
    return estado(i) === f;
  }
  function filtrarPor(f) {
    filtroActivo = (f === filtroActivo) ? null : f;
    topes = {};                     // la lista nueva arranca corta, no heredando el «ver más» de la anterior
    if (vista === 'ano') { cambiarVista('ahora'); return; }
    pintar();
    var ancla = vista === 'mes' ? $('#tituloLista') : $('#vAhora');
    if (ancla && filtroActivo) { ancla.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
  }

  /* La marca de kit de una fila. Tres modos: normal (se marca lo que falta),
     `false` (no se marca, ya lo dice la cabecera) e `invertido` (se marca lo
     que sí está listo, porque faltar es la norma en esa lista). */
  function marcaKit(i, opciones) {
    var modo = opciones.kitEnFila;
    if (modo === false) return '';
    if (modo === 'invertido') {
      var e = estado(i);
      if (!kitOk(i) || (e !== 'poriniciar' && e !== 'planificado')) return '';
      return '<span class="et kitok" title="' + esc(kitTexto(i)) + '">kit listo</span>';
    }
    return kitUrge(i) ? '<span class="et kit" title="' + esc(kitTexto(i)) + '">sin kit</span>' : '';
  }

  /* ---------- Una fila ----------------------------------------------------- */
  function fila(i, opciones) {
    opciones = opciones || {};
    var e = estado(i);
    var at = aTiempo(i);
    var fechas = i.plan_vigente
      ? corta(i.plan_vigente.inicio) + (finPrevisto(i) !== i.plan_vigente.inicio ? '–' + corta(finPrevisto(i)) : '')
      : 'sin fecha';
    var cola = '';
    if (opciones.atraso && atraso(i) > 0) {
      cola = '<span class="fecha">hace ' + atraso(i) + ' ' + plural(atraso(i), 'día', 'días') + '</span>';
    } else {
      cola = '<span class="fecha">' + fechas + '</span>';
    }
    /* La fila se abre con clic, Enter o Espacio (TR-14): `role="button"` y
       `tabindex` porque dentro lleva otro botón (agendar) y un botón no puede
       contener otro. */
    return '<div class="fila-ing' + zonaClase(i) + '" data-id="' + esc(i.id) + '" role="button" tabindex="0">' +
      '<span class="cod">' + esc(i.local) + '</span>' +
      '<span class="nom">' + esc(i.local_nombre || i.ciudad || '') +
        ' <span class="sub">· ingreso ' + esc(i.numero) + ' de 4</span></span>' +
      cola +
      (opciones.sinEstado ? '' : '<span class="et ' + e + '">' + ETIQUETA[e] +
        (e === 'cumplido' && at !== null ? (at ? ' a tiempo' : ' tarde') : '') + '</span>') +
      marcaKit(i, opciones) +
      (e === 'sinagendar' && PUEDE_EDITAR
        ? '<button type="button" class="btn sm primary" data-agendar="' + esc(i.local) + '" data-numero="' + esc(i.numero) + '" data-anio="' + esc(i.anio || '') + '">Agendar</button>'
        : '') +
      '</div>';
  }

  /* Una lista con tope y su «ver las N restantes»: ninguna cola deja de estar
     accesible, pero la pantalla no arranca con ochenta y dos filas. */
  var topes = {};
  function lista(ings, clave, opciones) {
    if (!ings.length) return '<p class="nada">Nada por aquí.</p>';
    opciones = opciones || {};
    /* Un distintivo solo sirve si distingue. Cuando la falta de kit es la
       NORMA de la lista —y con el 86 % del año sin kit confirmado lo es casi
       siempre— marcar cada fila es repetir la misma palabra veinte veces. En
       ese caso el número va una vez en la cabecera del bloque y en las filas se
       marca la EXCEPCIÓN: los pocos que sí tienen el kit listo. */
    if (opciones.kitEnFila !== false && ings.length >= 3) {
      var conFalta = ings.filter(kitUrge).length;
      if (conFalta / ings.length >= 0.7) {
        var copia = {};
        for (var k in opciones) { if (Object.prototype.hasOwnProperty.call(opciones, k)) { copia[k] = opciones[k]; } }
        copia.kitEnFila = 'invertido';
        opciones = copia;
      }
    }
    var tope = topes[clave] || 6;
    var html = ings.slice(0, tope).map(function (i) { return fila(i, opciones); }).join('');
    if (ings.length > tope) {
      html += '<div class="b-mas"><button type="button" class="btn sm" data-mas="' + clave + '">' +
              'Ver ' + (ings.length - tope) + ' más</button></div>';
    }
    return html;
  }

  function bloque(clave, color, n, titulo, detalle, ings, abierto, opciones) {
    if (!n) return '';
    var sinKit = ings.filter(kitUrge).length;
    if (sinKit) {
      detalle += sinKit === ings.length
        ? ' A ninguno se le ha confirmado el kit.'
        : ' A ' + sinKit + ' de ' + ings.length + ' no se les ha confirmado el kit.';
    }
    return '<details class="bloque ' + color + '"' + (abierto ? ' open' : '') + ' data-b="' + clave + '">' +
      '<summary><span class="b-fila"><span class="b-n">' + n + '</span>' +
      '<span class="b-t">' + esc(titulo) + '</span></span>' +
      '<span class="b-d">' + esc(detalle) + '</span></summary>' +
      '<div class="b-cuerpo">' + lista(ings, clave, opciones) + '</div></details>';
  }

  /* ---------- Vista «Lo que toca» ----------------------------------------- */
  function pintarAhora(g) {
    var cont = $('#vAhora');
    if (filtroActivo) {
      var sel = visibles().filter(function (i) { return cumpleFiltro(i, filtroActivo); }).sort(porFecha);
      cont.innerHTML = '<p class="sub" style="margin:0 0 8px">' + esc(nombreFiltro(filtroActivo)) + ' — ' + sel.length +
        ' <button type="button" class="btn sm" id="quitarFiltro">Quitar el filtro</button></p>' +
        '<div class="bloque"><div class="b-cuerpo" style="padding-top:8px">' + lista(sel, 'filtro') + '</div></div>';
      var q = $('#quitarFiltro'); if (q) q.addEventListener('click', function () { filtrarPor(null); });
      enganchar(cont);
      return;
    }
    var html = '';
    html += bloque('vencido', 'roja', g.vencido.length, mayus(nombre('vencido', 2)),
      'La fecha acordada pasó y no hay ninguna OT INDUSTEC emitida. Lo más atrasado primero.',
      g.vencido, true, { atraso: true, sinEstado: true });
    html += bloque('sincerrar', 'ambar', g.sincerrar.length, mayus(nombre('sincerrar', 2)),
      'Tienen OT INDUSTEC emitidas y la fecha prevista ya pasó, pero nadie los marcó como ejecutados. ' +
      'Hasta entonces no cuentan como ejecutados frente a KFC.',
      g.sincerrar, g.vencido.length === 0, { atraso: true, sinEstado: true });
    html += bloque('quincena', 'azul', g.quincena.length,
      g.enmarcha.length
        ? 'En ejecución y por arrancar (' + HORIZONTE + ' días)'
        : 'Por arrancar en los próximos ' + HORIZONTE + ' días',
      'Aquí es donde todavía se puede reclamar un kit o mover una fecha a tiempo.',
      g.quincena, true, {});
    html += bloque('sinagendar', 'viol', g.sinagendar.length, mayus(nombre('sinagendar', 2)),
      'Están en el acuerdo con KFC pero no tienen fecha. Agéndalos para que entren al cronograma.',
      g.sinagendar, false, { sinEstado: true });
    if (!html) {
      html = '<p class="nada">No hay nada ' + esc(UI.T('ATRASADO')) + ' ni por arrancar. ' +
             'Mira «El mes» para lo que viene después.</p>';
    }
    cont.innerHTML = html;
    enganchar(cont);
  }
  /* El nombre de cada filtro, el mismo en el botón y en la cabecera de la
     lista filtrada. Los de estado salen del diccionario, en plural; «sinkit»
     junta la falta de kit (fuera del diccionario: logística del material)
     con los pendientes que arrancan dentro del horizonte. */
  function nombreFiltro(f) {
    if (f === 'todos') return 'Todos los ingresos';
    if (f === 'sinkit') return 'Sin kit · ' + UI.T('PREV_PENDIENTE', 2) + ' que arrancan en ' + HORIZONTE + ' días o menos';
    if (f === 'reagendado') return UI.T.titulo('PREV_REAGENDADO');
    if (CODIGOS.indexOf(f) !== -1) return mayus(nombre(f, 2));
    return f;
  }

  /* ---------- Vista «El mes» ---------------------------------------------- */
  function pintarMes() {
    var cal = $('#calendario');
    var y = mesVista.getFullYear(), m = mesVista.getMonth();
    $('#mesTitulo').textContent = MESES[m].charAt(0).toUpperCase() + MESES[m].slice(1) + ' ' + y;

    // Se pintan los DÍAS DECLARADOS, no toda la ventana de inicio a fin: el
    // 19,2% de los ingresos tiene huecos, y rellenar el rango metía un mismo
    // local en todos los días del mes.
    var porDia = {};
    var delMes = [];
    visibles().forEach(function (i) {
      if (!i.plan_vigente) return;
      if (!cumpleFiltro(i, filtroActivo)) return;
      var ds = i.plan_vigente.dias_declarados || [i.plan_vigente.inicio];
      var entra = false;
      ds.forEach(function (isoDia) {
        var f = d(isoDia);
        if (f && f.getFullYear() === y && f.getMonth() === m) {
          (porDia[f.getDate()] = porDia[f.getDate()] || []).push(i);
          entra = true;
        }
      });
      if (entra) delMes.push(i);
    });
    delMes.sort(porFecha);

    // Los dos cuentan en la base ATRASADO de la leyenda.
    var vencidosMes = delMes.filter(function (i) { return estado(i) === 'vencido' || estado(i) === 'sincerrar'; }).length;
    $('#mesResumen').textContent = delMes.length + ' ' + plural(delMes.length, 'ingreso', 'ingresos') +
      (vencidosMes ? ' · ' + vencidosMes + ' ' + UI.T('ATRASADO', vencidosMes) : '');

    var primero = new Date(y, m, 1);
    var arranque = (primero.getDay() + 6) % 7;          // lunes = 0
    var ultimo = new Date(y, m + 1, 0).getDate();
    var html = DIAS.map(function (x) { return '<div class="dia-cab">' + x + '</div>'; }).join('');

    for (var k = 0; k < arranque; k++) html += '<div class="dia fuera"></div>';
    for (var dia = 1; dia <= ultimo; dia++) {
      var esHoy = (y === hoy.getFullYear() && m === hoy.getMonth() && dia === hoy.getDate());
      var listaDia = porDia[dia] || [];
      var clave = y + '-' + m + '-' + dia;
      var tope = diasAbiertos[clave] ? listaDia.length : 3;
      var chips = listaDia.slice(0, tope).map(function (i) {
        var e = estado(i);
        return '<button type="button" class="chip-local ' + e + zonaClase(i) + '" data-id="' + esc(i.id) + '" ' +
               'title="' + esc(i.local + ' · ' + (i.local_nombre || '') + ' · ' + ETIQUETA[e] +
                            (kitUrge(i) ? ' · ' + kitTexto(i) : '')) + '">' +
               '<span>' + esc(i.local) + '</span>' +
               (kitUrge(i) ? '<i class="sinkit" aria-hidden="true"></i>' : '') +
               '</button>';
      }).join('');
      if (listaDia.length > tope) {
        chips += '<button type="button" class="mas" data-dia="' + clave + '">+' + (listaDia.length - tope) + ' más</button>';
      }
      html += '<div class="dia' + (esHoy ? ' hoy' : '') + '"><span class="n">' + dia + '</span>' + chips + '</div>';
    }
    cal.innerHTML = html;
    $$('.chip-local', cal).forEach(function (b) {
      b.addEventListener('click', function () { abrirPanel(b.dataset.id); });
    });
    $$('.mas[data-dia]', cal).forEach(function (b) {
      b.addEventListener('click', function () { diasAbiertos[b.dataset.dia] = true; pintarMes(); });
    });

    $('#tituloLista').textContent = filtroActivo
      ? nombreFiltro(filtroActivo) + ' en ' + MESES[m]
      : 'Ingresos de ' + MESES[m];
    var cont = $('#listaMes');
    cont.innerHTML = delMes.length
      ? '<div class="bloque"><div class="b-cuerpo" style="padding-top:8px">' + lista(delMes, 'mes') + '</div></div>'
      : '<p class="nada">Sin ingresos en este mes' + (filtroActivo ? ' con ese filtro' : '') + '.</p>';
    enganchar(cont);
  }

  /* ---------- Vista «El año y KFC» ---------------------------------------- */
  function cuenta(l) {
    var c = { total: l.length, cumplidos: 0, aTiempo: 0, movidos: 0 };
    l.forEach(function (i) {
      var e = estado(i); c[e] = (c[e] || 0) + 1;
      if (e === 'cumplido') { c.cumplidos++; if (aTiempo(i)) c.aTiempo++; }
      if (movido(i)) c.movidos++;
    });
    return c;
  }
  function pintarAno() {
    var v = visibles();
    var c = cuenta(v);
    var anio = (DATOS && DATOS.cronograma && DATOS.cronograma.anio) || hoy.getFullYear();
    var cont = $('#vAno');

    /* I-7: un porcentaje que no se puede calcular se dice con palabras. Antes
       esto dibujaba un «0 %» en rojo, que se lee como «se incumplió todo»
       cuando lo que pasa es que todavía no se cerró ningún ingreso. */
    var cab;
    if (c.cumplidos) {
      var pct = Math.round(c.aTiempo * 100 / c.cumplidos);
      cab = '<div class="grande">' + pct + ' %</div>' +
            '<div>' + c.aTiempo + ' de ' + c.cumplidos + ' ingresos ' + esc(nombre('cumplido', 2)) +
            ' llegaron dentro de lo acordado.</div>';
    } else {
      cab = '<div class="grande">Sin cifra todavía</div>' +
            '<div>Ningún ingreso de ' + anio + ' está marcado como ' + esc(nombre('cumplido', 1)) + ', así que no hay ' +
            'porcentaje de cumplimiento que reportar. Lo que sí hay: <b>' + (c.sincerrar || 0) + '</b> ' +
            plural(c.sincerrar || 0, 'ingreso', 'ingresos') + ' ' + esc(nombre('sincerrar', c.sincerrar || 0)) +
            ', con OT INDUSTEC emitidas.</div>';
    }
    var html = '<div class="cumpl">' + cab +
      '<p class="regla">Se mide así: un ingreso cuenta <b>a tiempo</b> si su fecha real de fin no pasa de la ' +
      '<b>fecha acordada con Grupo KFC</b>. Reagendar mueve la fecha vigente, nunca la acordada — por eso el ' +
      'cumplimiento no se puede maquillar moviendo el cronograma.</p></div>';

    html += '<div class="tabla-wrap"><table><thead><tr>' +
      '<th>Zona</th><th>Del año</th>' +
      ['vencido', 'sincerrar', 'cumplido', 'sinagendar'].map(function (e) {
        return '<th>' + esc(mayus(nombre(e, 2))) + '</th>';
      }).join('') +
      '</tr></thead><tbody>';
    var filas = zonaVista === 'ADMIN' ? ZONAS : [zonaVista];
    filas.forEach(function (z) {
      var cz = cuenta(v.filter(function (i) { return i.zona === z; }));
      html += '<tr><td><span class="zona zona-' + z.toLowerCase() + '">' + esc(nombreZona(z)) + '</span></td>' +
        '<td>' + cz.total + '</td><td>' + (cz.vencido || 0) + '</td><td>' + (cz.sincerrar || 0) + '</td>' +
        '<td>' + cz.cumplidos + '</td><td>' + (cz.sinagendar || 0) + '</td></tr>';
    });
    var otras = v.filter(function (i) { return ZONAS.indexOf(i.zona) === -1; });
    if (otras.length) {
      var co = cuenta(otras);
      html += '<tr><td><span class="zona zona-otra">' + esc(mayus(UI.T('SIN_ZONA'))) + '</span></td><td>' + otras.length +
        '</td><td>' + (co.vencido || 0) + '</td><td>' + (co.sincerrar || 0) + '</td>' +
        '<td>' + co.cumplidos + '</td><td>' + (co.sinagendar || 0) + '</td></tr>';
    }
    html += '</tbody></table></div>';
    if (otras.length) {
      html += '<p class="sub" style="margin:6px 0 0">«' + esc(mayus(UI.T('SIN_ZONA'))) + '» son ' + otras.length + ' ' +
        plural(otras.length, 'ingreso de un local que no está', 'ingresos de locales que no están') +
        ' en las tres zonas contratadas. No se les asignó una zona por parecido: si el maestro no lo dice, ' +
        'no se inventa.</p>';
    }

    /* El avance mes a mes: dónde se amontona el trabajo del año. Es lo que en
       los sistemas de mantenimiento se mira en la vista de mes para detectar
       picos y huecos, aquí en una sola lectura. */
    html += '<h2>Cómo va el año, mes a mes</h2>' +
      '<p class="sub" style="margin:0 0 8px">Cada barra es un mes según la fecha vigente de inicio. ' +
      ['cumplido', 'vencido', 'sincerrar', 'encurso', 'planificado'].map(function (e) {
        return '<span class="mini"><i class="b-' + e + '"></i>' + ETIQUETA[e] + '</span>';
      }).join(' ') +
      (c.sinagendar ? ' · los <b>' + c.sinagendar + '</b> sin agendar no salen aquí: no tienen fecha.' : '') +
      '</p><div class="meses">';
    for (var m = 0; m < 12; m++) {
      var delMes = v.filter(function (i) {
        var ini = inicioPrevisto(i);
        if (!ini) return false;
        var f = d(ini);
        return f.getFullYear() === anio && f.getMonth() === m;
      });
      var cm = cuenta(delMes);
      var t = delMes.length || 1;
      var seg = ['cumplido', 'vencido', 'sincerrar', 'encurso', 'planificado'].map(function (e) {
        var n = e === 'cumplido' ? cm.cumplidos : (cm[e] || 0);
        return n ? '<i class="b-' + e + '" style="width:' + (n * 100 / t) + '%" title="' + n + ' ' + esc(nombre(e, n)) + '"></i>' : '';
      }).join('');
      html += '<div class="mes-fila' + (m === hoy.getMonth() && anio === hoy.getFullYear() ? ' ahora' : '') + '">' +
        '<span class="mm">' + MESES[m] + '</span>' +
        '<span class="barra">' + seg + '</span>' +
        '<span class="cifra">' + (delMes.length ? delMes.length + ' ' + plural(delMes.length, 'ingreso', 'ingresos') : '—') + '</span></div>';
    }
    html += '</div>';

    var notas = [];
    if (c.movidos) notas.push('<b>' + c.movidos + '</b> ' + plural(c.movidos, 'ingreso reagendado', 'ingresos reagendados') +
      ', cada uno con su motivo en la bitácora: es lo que se le explica a KFC.');
    var conv = DATOS && DATOS.cronograma && DATOS.cronograma.conversion;
    var sinResolver = conv && conv.sin_convertir ? conv.sin_convertir.length : 0;
    if (sinResolver) notas.push('<b>' + sinResolver + '</b> ' + plural(sinResolver, 'ingreso', 'ingresos') +
      ' del plan original con una fecha que el sistema no pudo interpretar. No se inventó ninguna: están sin fecha.');
    var fuera = DATOS && DATOS.cronograma && DATOS.cronograma.locales_fuera_del_maestro;
    if (fuera && fuera.length) notas.push('<b>' + fuera.length + '</b> ' +
      plural(fuera.length, 'local del cronograma que no está', 'locales del cronograma que no están') + ' en el maestro.');
    if (notas.length) {
      html += '<h2>Lo que hay que saber antes de reportar</h2><ul class="sub" style="margin:0;padding-left:18px">' +
        notas.map(function (n) { return '<li style="margin-bottom:4px">' + n + '</li>'; }).join('') + '</ul>';
    }
    cont.innerHTML = html;
  }

  /* ---------- Enganchar los clics de una lista ---------------------------- */
  function enganchar(cont) {
    $$('.fila-ing[data-id]', cont).forEach(function (f) {
      f.addEventListener('click', function (ev) {
        if (ev.target.closest('[data-agendar]')) return;
        abrirPanel(f.dataset.id);
      });
      f.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); abrirPanel(f.dataset.id); }
      });
    });
    $$('[data-agendar]', cont).forEach(function (b) {
      b.addEventListener('click', function (ev) {
        ev.stopPropagation();
        agendar(b.dataset.agendar, +b.dataset.numero || 1, +b.dataset.anio || hoy.getFullYear());
      });
    });
    $$('[data-mas]', cont).forEach(function (b) {
      b.addEventListener('click', function () {
        topes[b.dataset.mas] = (topes[b.dataset.mas] || 6) + 25;
        pintar();
      });
    });
  }

  /* ---------- Cambiar de vista -------------------------------------------- */
  function cambiarVista(v) {
    vista = v;
    $$('.vistas button').forEach(function (b) {
      var on = b.dataset.v === v;
      b.setAttribute('aria-selected', on ? 'true' : 'false');
      b.tabIndex = on ? 0 : -1;
    });
    $('#vAhora').hidden = v !== 'ahora';
    $('#vMes').hidden = v !== 'mes';
    $('#vAno').hidden = v !== 'ano';
    $('#filtros').hidden = v === 'ano';
    pintar();
  }

  function pintar() {
    var g = grupos();
    pintarAlertas(g);
    pintarTitular(g);
    pintarFiltros(g);
    if (vista === 'ahora') pintarAhora(g);
    else if (vista === 'mes') pintarMes();
    else pintarAno();
    ajustarFranja();
  }

  function pintarAyuda() {
    var orden = ['vencido', 'sincerrar', 'poriniciar', 'encurso', 'planificado', 'cumplido', 'sinagendar'];
    $('#ayudaEstados').innerHTML = orden.map(function (e) {
      return '<div class="l"><span class="et ' + e + '">' + ETIQUETA[e] + '</span><span>' + EXPLICA[e] + '</span></div>';
    }).join('') +
      '<div class="l"><span class="et kit">sin kit</span><span>El kit de mantenimiento no está confirmado y el ingreso ' +
      'arranca dentro de ' + HORIZONTE + ' días. En el calendario es el punto de la derecha del chip. ' +
      'Sin kit no debería agendarse: es el motivo de demora más frecuente.</span></div>' +
      '<div class="l"><span class="et kitok">kit listo</span><span>Se marca al revés cuando en una lista casi ninguno ' +
      'tiene el kit: entonces lo que hay que ver es el puñado que sí lo tiene. La cifra completa va en la ' +
      'cabecera del bloque.</span></div>' +
      '<div class="l"><span class="zona zona-uio">UIO</span><span>Con las tres zonas a la vista, la cinta de color ' +
      'a la izquierda de cada fila dice de qué zona es.</span></div>';
  }

  /* ---------- Panel de detalle -------------------------------------------- */
  function abrirPanel(id) {
    var i = INGRESOS.filter(function (x) { return x.id === id; })[0];
    if (!i) return;
    var e = estado(i);
    var mov = movido(i);
    panelAbiertoId = id;
    $('#panelTitulo').textContent = i.local + ' · ingreso ' + i.numero + ' de 4';

    var h = '<dl class="dl">' +
      '<dt>Local</dt><dd>' + esc(i.local_nombre || '—') + '</dd>' +
      '<dt>Zona</dt><dd>' + esc(i.zona ? nombreZona(i.zona) : '—') + (i.ciudad ? ' · ' + esc(i.ciudad) : '') + '</dd>' +
      '<dt>Estado</dt><dd><span class="et ' + e + '">' + ETIQUETA[e] + '</span>' +
      (e === 'cumplido' && aTiempo(i) !== null ? ' <span class="sub">' + (aTiempo(i) ? 'a tiempo' : 'fuera del plan acordado') + '</span>' : '') +
      '<div class="sub" style="margin-top:3px">' + EXPLICA[e] + '</div></dd>' +
      '</dl>';

    h += '<div class="bloque-p"><h3>Fechas</h3><dl class="dl">' +
      '<dt>Acordado con KFC</dt><dd>' + (i.plan_original
        ? '<b>' + larga(i.plan_original.inicio) + '</b> a <b>' + larga(i.plan_original.fin || i.plan_original.inicio) + '</b>'
        : 'sin fecha') + '</dd>' +
      '<dt>Vigente</dt><dd class="' + (mov ? 'movido' : '') + '">' + (i.plan_vigente
        ? '<b>' + larga(i.plan_vigente.inicio) + '</b> a <b>' + larga(i.plan_vigente.fin || i.plan_vigente.inicio) + '</b>' +
          (mov ? ' — movido' : '')
        : 'sin fecha') + '</dd>' +
      '<dt>Real</dt><dd>' + (i.real && i.real.inicio
        ? larga(i.real.inicio) + ' a ' + larga(i.real.fin || i.real.inicio) + (i.real.cerrado ? '' : ' (por marcar como ejecutado)')
        : 'todavía no empieza') + '</dd>' +
      '</dl>' +
      (i.anio_supuesto ? '<p class="sub">El año lo pone el sistema: el texto original («' +
        esc(i.texto_origen) + '») no lo trae.</p>' : '') +
      (PUEDE_EDITAR && e !== 'cumplido' ? '<div class="row" style="margin-top:8px">' +
        (i.plan_vigente ? '<button type="button" class="btn" data-acc="reagendar">Reagendar</button>'
                        : '<button type="button" class="btn primary" data-acc="agendar">Agendar</button>') +
        (i.plan_vigente ? '<button type="button" class="btn secondary" data-acc="cerrar">Marcar como ejecutado</button>' : '') +
        '</div>' : '') +
      '</div>';

    h += '<div class="bloque-p"><h3>Kit de mantenimiento</h3>' +
      '<p style="margin:0 0 8px;font-size:13px">Estado: <b>' + esc(kitTexto(i)) + '</b>' +
      (i.kit_fecha ? ' <span class="sub">(' + esc(corta(i.kit_fecha)) + ')</span>' : '') +
      (i.kit_texto ? ' <span class="sub">(«' + esc(i.kit_texto) + '»)</span>' : '') + '</p>' +
      (!kitOk(i) && e !== 'cumplido'
        ? '<p class="sub" style="margin:0">Sin kit confirmado no debería agendarse el ingreso. ' +
          'Si ya llegó, confírmalo; si no, reagenda y queda el movimiento del ingreso para reportarle a KFC.</p>'
        : '') +
      (PUEDE_EDITAR && e !== 'cumplido' ? '<div class="row" style="margin-top:8px">' +
        '<button type="button" class="btn secondary" data-acc="kit">Actualizar el kit</button></div>' : '') +
      '</div>';

    var ots = (i.real && i.real.ots) || [];
    // Lo que emite el técnico por cada día es la OT INDUSTEC; «orden» es el trabajo que pide KFC.
    h += '<div class="bloque-p"><h3>' + esc(UI.T.titulo('OT_INDUSTEC')) + ' emitidas (' + ots.length + ')</h3>';
    if (ots.length) {
      h += ots.map(function (o) {
        return '<div style="font-size:12px;font-family:ui-monospace,monospace;padding:3px 0">' +
               (o.dia ? 'D' + esc(o.dia) + ' · ' : '') + (o.fecha ? corta(o.fecha) + ' · ' : '') + esc(o.id) + '</div>';
      }).join('');
      if (e === 'sincerrar') {
        h += '<p class="sub" style="margin:6px 0 0">Hay OT INDUSTEC emitidas, pero nadie marcó el ingreso como ' +
             'ejecutado. Mientras siga así, no cuenta como ejecutado frente a KFC.</p>';
      }
    } else {
      h += '<p class="sub" style="margin:0">Ninguna todavía. Se emite una OT INDUSTEC por cada día de intervención.</p>';
    }
    /* La OT INDUSTEC del ingreso se llena desde aquí, con el local, el tipo y
       el día ya puestos (S1 los lee de la URL): un toque menos en el celular. */
    if (i.plan_vigente && e !== 'cumplido') {
      var dur = (i.plan_vigente.dias_declarados && i.plan_vigente.dias_declarados.length) ||
                Math.max(1, dias(d(i.plan_vigente.inicio), d(i.plan_vigente.fin || i.plan_vigente.inicio)) + 1);
      var enlaces = [];
      for (var k = 1; k <= Math.min(dur, 5); k++) {
        enlaces.push('<a class="btn sm" href="index.html?tipo=PREVENTIVO&dia=' + k + '&local=' + encodeURIComponent(i.local) + '">' +
                     esc(UI.T.titulo('OT_INDUSTEC')) + ' del día ' + k + '</a>');
      }
      h += '<div class="row" style="margin-top:8px;flex-wrap:wrap;gap:6px">' + enlaces.join('') + '</div>';
    }
    h += '</div>';

    /* Lo que pasó con el ingreso (agenda, reagenda, kit, marca de ejecutado,
       nota): «movimientos del ingreso», no «novedades», que son lo que el
       técnico ve en el local (PREV_MOVIMIENTO). */
    h += '<div class="bloque-p"><h3>' + esc(UI.T.titulo('PREV_MOVIMIENTO')) + '</h3>' +
      (i.novedades && i.novedades.length
        ? i.novedades.map(function (n) {
            var tipo = n.tipo ? (TIPO_MOVIMIENTO[String(n.tipo).toUpperCase()] || String(n.tipo).toLowerCase()) : '';
            return '<div class="nov">' + (tipo ? '<b>' + esc(tipo) + '</b> · ' : '') + esc(n.texto) +
              (n.fecha_antes || n.fecha_despues ? ' <span class="sub">(' + esc(n.fecha_antes || 'sin fecha') + ' → ' + esc(n.fecha_despues || '') + ')</span>' : '') +
              '<div class="meta">' + esc(n.fecha) + ' · ' + esc(n.por) + '</div></div>';
          }).join('')
        : '<p class="sub" style="margin:0 0 8px">Sin ' + esc(UI.T('PREV_MOVIMIENTO', 2)) + ' registrados.</p>') +
      (PUEDE_EDITAR ? '<button type="button" class="btn" data-acc="novedad">+ Anotar un ' + esc(UI.T('PREV_MOVIMIENTO')) + '</button>' : '') +
      '<p class="sub" style="margin-top:8px">Esto es lo que se le reporta a Grupo KFC como ' +
      'motivo de demora frente al cronograma acordado.</p></div>';

    $('#panelCuerpo').innerHTML = h;
    $$('[data-acc]', $('#panelCuerpo')).forEach(function (b) {
      b.addEventListener('click', function () { accion(b.dataset.acc, i); });
    });
    focoAntes = document.activeElement;
    $('#panel').hidden = false;
    $('#panelFondo').hidden = false;
    $('#panelCerrar').focus();
  }
  function cerrarPanel() {
    $('#panel').hidden = true; $('#panelFondo').hidden = true;
    panelAbiertoId = null;
    if (focoAntes && focoAntes.focus) { try { focoAntes.focus(); } catch (e) {} }
  }

  /* ---------- Guardar: el servidor, con su bitácora ---------------------- */
  function enviar(cuerpo) {
    return fetch('cronograma_accion.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Csrf': CSRF },
      body: JSON.stringify(cuerpo),
    }).then(function (r) { return r.json().then(function (j) { j._status = r.status; return j; }); });
  }
  function guardar(cuerpo, reabrir) {
    return enviar(cuerpo).then(function (j) {
      if (!j.ok) { alert(j.error || 'No se pudo guardar.'); return false; }
      toast(j.mensaje || 'Guardado.', 'ok');
      return cargar().then(function () {
        if (reabrir && panelAbiertoId) {
          var sigue = INGRESOS.filter(function (x) { return x.id === panelAbiertoId; })[0];
          if (sigue) abrirPanel(panelAbiertoId); else cerrarPanel();
        }
        return true;
      });
    }).catch(function () { alert('Sin conexión con el servidor: no se guardó.'); return false; });
  }
  function sinTabla() {
    alert('El cronograma todavía se lee del archivo de la estación, no de la base.\n\n' +
          'Para guardar cambios hay que importarlo una vez con cronograma_importar_cli.php ' +
          '(lo hace administración). Agendar un local sí se puede ya.');
  }

  /* ---------- Acciones ---------------------------------------------------- */
  function accion(acc, i) {
    if (acc === 'agendar') { agendar(i.local, i.numero, i.anio); return; }
    if (!ESCRIBE || !i.ingreso_id) { sinTabla(); return; }
    if (acc === 'kit') {
      abrirModal('Kit de mantenimiento — ' + i.local,
        '<label>Estado del kit</label><select id="kitEstado">' +
        '<option value="SOLICITADO">Solicitado a KFC</option>' +
        '<option value="CONFIRMADO" selected>Confirmado (ya está en el local)</option>' +
        '<option value="ENTREGADO">Entregado al técnico</option>' +
        '<option value="SIN_KIT">Sin kit</option></select>' +
        '<label style="margin-top:8px">Fecha</label><input type="date" id="kitFecha" value="' + iso(hoy) + '">' +
        '<label style="margin-top:8px">Nota (opcional)</label><input type="text" id="kitNota" placeholder="quién lo confirmó, número de guía…">',
        function () {
          return guardar({ accion: 'kit', ingreso_id: i.ingreso_id, estado: $('#kitEstado').value,
                           fecha: $('#kitFecha').value, nota: $('#kitNota').value }, true);
        });
      return;
    }
    if (acc === 'reagendar') {
      abrirModal('Reagendar — ' + i.local,
        '<p style="font-size:13px">Se mueve la fecha <b>vigente</b>. Lo acordado con KFC ' +
        '(' + (i.plan_original ? larga(i.plan_original.inicio) : 'sin fecha') + ') <b>no cambia</b>: ' +
        'es contra eso que se mide el cumplimiento.</p>' +
        '<div class="grid g2"><div><label>Nuevo inicio</label>' +
        '<input type="date" id="reIni" value="' + (i.plan_vigente ? i.plan_vigente.inicio : iso(hoy)) + '"></div>' +
        '<div><label>Fin estimado</label>' +
        '<input type="date" id="reFin" value="' + (i.plan_vigente ? (i.plan_vigente.fin || i.plan_vigente.inicio) : iso(hoy)) + '"></div></div>' +
        '<label style="margin-top:8px">Motivo <span style="color:#b91c1c">(obligatorio)</span></label>' +
        '<select id="reMotivo"><option value="">Elige el motivo…</option>' +
        '<option>Falta el kit de mantenimiento</option>' +
        '<option>El local no dio acceso</option>' +
        '<option>Cruce con otro trabajo de la zona</option>' +
        '<option>Pedido de Grupo KFC</option>' +
        '<option>Falta de personal</option>' +
        '<option>Otro</option></select>' +
        '<label style="margin-top:8px">Detalle</label>' +
        '<textarea id="reDetalle" placeholder="Lo que se le explica a KFC"></textarea>',
        function () {
          if (!$('#reMotivo').value) { alert('El motivo es obligatorio: es lo que se le reporta a KFC.'); return false; }
          if ($('#reFin').value < $('#reIni').value) { alert('El fin no puede ser anterior al inicio.'); return false; }
          return guardar({ accion: 'reagendar', ingreso_id: i.ingreso_id, inicio: $('#reIni').value, fin: $('#reFin').value,
                           motivo: $('#reMotivo').value, detalle: $('#reDetalle').value }, true);
        });
      return;
    }
    if (acc === 'cerrar') {
      abrirModal('Marcar como ejecutado — ' + i.local,
        '<p style="font-size:13px">El ingreso queda <b>' + esc(nombre('cumplido', 1)) + '</b> con la fecha real. Si el fin real pasa de lo ' +
        'acordado con KFC (' + (i.plan_original ? larga(i.plan_original.fin || i.plan_original.inicio) : 'sin fecha') + '), cuenta como tarde.</p>' +
        '<div class="grid g2"><div><label>Inicio real</label>' +
        '<input type="date" id="ciIni" value="' + ((i.real && i.real.inicio) || (i.plan_vigente && i.plan_vigente.inicio) || iso(hoy)) + '"></div>' +
        '<div><label>Fin real</label><input type="date" id="ciFin" value="' + iso(hoy) + '"></div></div>' +
        '<label style="margin-top:8px">' + esc(UI.T.titulo('OT_CIERRE')) + ' (opcional)</label><input type="text" id="ciOt" placeholder="OT-…">' +
        '<label style="margin-top:8px">Nota</label><textarea id="ciNota" placeholder="Qué quedó hecho, qué quedó pendiente"></textarea>',
        function () {
          if ($('#ciFin').value < $('#ciIni').value) { alert('El fin real no puede ser anterior al inicio.'); return false; }
          return guardar({ accion: 'cerrar', ingreso_id: i.ingreso_id, real_inicio: $('#ciIni').value, real_fin: $('#ciFin').value,
                           ot: $('#ciOt').value, nota: $('#ciNota').value }, true);
        });
      return;
    }
    if (acc === 'novedad') {
      /* El tipo viaja como texto libre y se guarda tal cual en el motivo
         (cronograma_accion.php no lo compara): cambiar «Equipo fuera de
         servicio» por el término del diccionario solo cambia lo que se anota
         desde ahora; lo ya guardado se sigue leyendo como se escribió. */
      abrirModal('Anotar un ' + UI.T('PREV_MOVIMIENTO') + ' — ' + i.local,
        '<label>Tipo</label><select id="novTipo">' +
        '<option>Kit pendiente</option><option>Acceso negado</option>' +
        '<option>Trabajo parcial</option><option>' + esc(mayus(UI.T('EQUIPO_DESHABILITADO'))) + '</option>' +
        '<option>Otro</option></select>' +
        '<label style="margin-top:8px">Detalle</label>' +
        '<textarea id="novTexto" placeholder="Qué pasó"></textarea>',
        function () {
          if (!$('#novTexto').value.trim()) { alert('Escribe qué pasó.'); return false; }
          return guardar({ accion: 'nota', ingreso_id: i.ingreso_id, tipo: $('#novTipo').value, nota: $('#novTexto').value }, true);
        });
    }
  }

  function abrirModal(titulo, cuerpo, alGuardar) {
    $('#modalTitulo').textContent = titulo;
    $('#modalCuerpo').innerHTML = cuerpo +
      '<div class="row" style="margin-top:14px;justify-content:flex-end">' +
      '<button type="button" class="btn" id="modalCancelar">Cancelar</button>' +
      '<button type="button" class="btn primary" id="modalGuardar">Guardar</button></div>';
    $('#modal').hidden = false; $('#modalFondo').hidden = false;
    $('#modalCancelar').addEventListener('click', cerrarModal);
    $('#modalGuardar').addEventListener('click', function () {
      var b = this;
      b.disabled = true;
      Promise.resolve(alGuardar()).then(function (ok) {
        b.disabled = false;
        if (ok !== false) cerrarModal();
      });
    });
    var primero = $('#modalCuerpo').querySelector('input, select, textarea');
    if (primero) primero.focus();
  }
  function cerrarModal() { $('#modal').hidden = true; $('#modalFondo').hidden = true; }

  /* ---------- Agendar un local (desde cero o desde la fila «sin agendar») -- */
  function agendar(localPre, numeroPre, anioPre) {
    var opciones = (DATOS.locales || []).filter(function (l) {
      return zonaVista === 'ADMIN' || l.zona === zonaVista;
    }).sort(function (a, b) { return String(a.nombre || '').localeCompare(String(b.nombre || '')); })
      .map(function (l) {
        return '<option value="' + esc(l.codigo) + '"' + (l.codigo === localPre ? ' selected' : '') + '>' +
               esc(l.codigo + ' · ' + (l.nombre || '')) + '</option>';
      }).join('');
    abrirModal('Agendar un ingreso',
      '<label>Local</label><select id="agLocal"' + (localPre ? ' disabled' : '') + '><option value="">Elige el local…</option>' + opciones + '</select>' +
      '<label style="margin-top:8px">¿Qué ingreso del año?</label>' +
      '<select id="agNum">' + [1, 2, 3, 4].map(function (n) { return '<option' + (n === numeroPre ? ' selected' : '') + '>' + n + '</option>'; }).join('') + '</select>' +
      '<div class="grid g2" style="margin-top:8px">' +
      '<div><label>Inicio</label><input type="date" id="agIni" value="' + iso(hoy) + '"></div>' +
      '<div><label>Fin estimado</label><input type="date" id="agFin" value="' + iso(hoy) + '"></div></div>' +
      '<p class="sub" style="margin-top:8px">El fin es <b>estimado</b>. La fecha real se registra al marcar el ingreso como ejecutado.</p>' +
      '<label style="margin-top:8px;display:flex;align-items:center;gap:8px">' +
      '<input type="checkbox" id="agKit" style="width:auto;height:auto;-webkit-appearance:auto;appearance:auto"> ' +
      'Confirmo que el local ya tiene el kit de mantenimiento</label>',
      function () {
        var local = localPre || $('#agLocal').value;
        if (!local) { alert('Elige el local.'); return false; }
        if ($('#agFin').value < $('#agIni').value) { alert('El fin no puede ser anterior al inicio.'); return false; }
        if (!$('#agKit').checked &&
            !confirm('El kit no está confirmado.\n\nSe puede agendar igual, pero el ingreso queda marcado ' +
                     '«sin kit» y es el motivo de demora más frecuente. ¿Continuar?')) return false;
        return guardar({ accion: 'agendar', local: local, numero: +$('#agNum').value, anio: anioPre || hoy.getFullYear(),
                         inicio: $('#agIni').value, fin: $('#agFin').value, kit_confirmado: $('#agKit').checked }, false);
      });
  }

  /* El técnico entra aquí desde su bandeja, en el celular (T2.13.4): lleva la
     misma barra de abajo que mis.php y no la de módulos de la oficina, y sin
     «agendar un local», que no le toca. */
  function barraDelTecnico() {
    var nav = document.querySelector('.app-nav');
    if (nav) { nav.style.display = 'none'; }
    var marca = document.querySelector('.app-marca');
    if (marca) { marca.setAttribute('href', 'mis.php'); }
    var ag = document.getElementById('btnAgendar');
    if (ag) { ag.style.display = 'none'; }
    if (window.UI && window.UI.barraTecnico && !document.querySelector('.nav-abajo')) {
      document.body.classList.add('con-nav-abajo');
      document.body.insertAdjacentHTML('beforeend', window.UI.barraTecnico('preventivos'));
    }
  }

  /**
   * Quién es y qué alcanza, según el servidor. El selector de zona es un
   * FILTRO sobre lo que ya se recibió, no una simulación de rol.
   */
  function pintarQuienSoy(a) {
    var nom = document.getElementById('barraNombre');
    var chipA = document.getElementById('barraAlcance');
    var chipR = document.getElementById('barraRol');
    if (nom) { nom.textContent = a.nombre || ''; }
    if (chipA) { chipA.textContent = a.zona ? nombreZona(a.zona, true) : 'Las 3 zonas'; }
    if (chipR) { chipR.textContent = a.rol_nombre || ''; chipR.hidden = !a.rol_nombre; }
    if (a.rol === 'TECNICO') { barraDelTecnico(); }
    var ag = document.getElementById('btnAgendar');
    if (ag && !PUEDE_EDITAR) { ag.style.display = 'none'; }

    var sel = document.getElementById('usuario');
    if (!sel) { return; }
    var antes = sel.value;
    sel.innerHTML = '';
    if (a.zona) {
      sel.innerHTML = '<option value="' + esc(a.zona) + '">' + esc(nombreZona(a.zona, true)) + '</option>';
      sel.disabled = true;
      zonaVista = a.zona;
    } else {
      // El valor es el código de la base; el rótulo, el del diccionario (CNLJ -> CUENCA-LOJA).
      sel.innerHTML = '<option value="ADMIN">Las 3 zonas</option>' +
                      ZONAS.map(function (z) {
                        return '<option value="' + z + '">Solo ' + esc(nombreZona(z)) + '</option>';
                      }).join('');
      sel.disabled = false;
      zonaVista = antes && antes !== '' ? antes : 'ADMIN';
      sel.value = zonaVista;
    }
  }

  function fuenteTexto(j) {
    var cuando = j.generado_cronograma ? String(j.generado_cronograma).slice(0, 16).replace('T', ' ') : '';
    if (j.fuente === 'tabla') return 'datos en la base' + (cuando ? ', último cambio ' + cuando : '');
    if (j.fuente === 'json') return 'datos del archivo de la estación' + (cuando ? ' del ' + cuando : '') + ' — todavía no importado, así que solo se puede agendar';
    return 'sin cronograma cargado';
  }

  /* ---------- Cargar (y recargar tras guardar) ---------------------------- */
  function cargar() {
    return fetch('cronograma.php', { credentials: 'same-origin' }).then(function (r) {
      /* Sin sesión el servidor responde 401 en JSON. Se manda al ingreso en
         vez de dibujar un calendario vacío. */
      if (r.status === 401 && navigator.onLine) {
        location.href = 'login.php?r=cronograma.html';
        return null;
      }
      return r.json();
    }).then(function (j) {
      if (!j) { return; }
      if (j.error) throw new Error(j.error);
      DATOS = j;
      CSRF = j.csrf || '';
      PUEDE_EDITAR = !!(j.alcance && j.alcance.puede_editar);
      ESCRIBE = j.fuente === 'tabla';
      pintarNav(j.modulos);
      pintarQuienSoy(j.alcance || {});
      INGRESOS = (j.cronograma && j.cronograma.ingresos) || [];
      CORRECTIVOS = j.correctivos || [];
      /* La subcabecera dice de dónde salen los datos y nada más. Antes
         encadenaba cuatro hechos en una línea que nadie terminaba de leer. */
      $('#subcab').textContent = INGRESOS.length + ' ingresos de ' +
        ((j.cronograma && j.cronograma.anio) || hoy.getFullYear()) +
        ', 4 al año por local acordados con Grupo KFC · ' + fuenteTexto(j);
      pintarAyuda();
      pintar();
    }).catch(function (err) {
      $('#alertas').hidden = false;
      $('#alertas').innerHTML = '<span class="alerta roja">No se pudo cargar: ' + esc(err.message) + '</span>';
    });
  }

  /* ---------- Arranque ---------------------------------------------------- */
  document.addEventListener('DOMContentLoaded', function () {
    $('#panelCerrar').addEventListener('click', cerrarPanel);
    $('#panelFondo').addEventListener('click', cerrarPanel);
    $('#modalCerrar').addEventListener('click', cerrarModal);
    $('#modalFondo').addEventListener('click', cerrarModal);
    $('#btnAgendar').addEventListener('click', function () { agendar(null, 1, hoy.getFullYear()); });
    $('#mesAnt').addEventListener('click', function () { diasAbiertos = {}; mesVista.setMonth(mesVista.getMonth() - 1); pintarMes(); });
    $('#mesSig').addEventListener('click', function () { diasAbiertos = {}; mesVista.setMonth(mesVista.getMonth() + 1); pintarMes(); });
    $$('.vistas button').forEach(function (b) {
      b.addEventListener('click', function () { cambiarVista(b.dataset.v); });
      b.addEventListener('keydown', function (ev) {
        if (ev.key !== 'ArrowRight' && ev.key !== 'ArrowLeft') return;
        ev.preventDefault();
        var tabs = $$('.vistas button');
        var k = tabs.indexOf(b) + (ev.key === 'ArrowRight' ? 1 : -1);
        var sig = tabs[(k + tabs.length) % tabs.length];
        sig.focus(); cambiarVista(sig.dataset.v);
      });
    });
    $('#usuario').addEventListener('change', function () {
      zonaVista = this.value; filtroActivo = null; topes = {}; pintar();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { cerrarModal(); cerrarPanel(); }
    });
    window.addEventListener('resize', ajustarFranja);
    ajustarFranja();
    cargar();
  });

})();
