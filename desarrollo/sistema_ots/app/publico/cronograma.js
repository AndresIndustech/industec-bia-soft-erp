/* =========================================================================
   cronograma.js — Calendario de preventivos, alertas y bitácora de novedades.

   DESDE EL 2026-09-13 ESCRIBE (D15, T2.14.5). Confirmar el kit, reagendar,
   registrar el cierre, agendar un local y anotar una novedad van a
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

   La alerta va en una franja fija, sin botón de descartar: se apaga sola cuando
   la condición se resuelve. Lo pidió así el cliente — visible siempre, hasta la
   OT de cierre, pero sin estorbar (y sin tapar la barra: va debajo de ella).
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
  var filtroActivo = null;
  var panelAbiertoId = null;
  var focoAntes = null;

  var MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
               'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  var DIAS = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];
  var ZONAS = ['UIO', 'LARB', 'CNLJ'];

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
  function toast(texto, tono) {
    if (window.UI && window.UI.toast) { window.UI.toast(texto, tono || 'ok'); } else { alert(texto); }
  }

  /* Estado que se pinta. El servidor ya lo manda calculado a HOY
     (`estado_hoy`, la misma regla que Reportes::estadoPreventivo); si faltara,
     se recalcula aquí con la regla de siempre. */
  function estado(i) {
    if (i.estado_hoy) return i.estado_hoy;
    if (!i.plan_vigente) return 'sinagendar';
    if (i.estado === 'CUMPLIDO' || (i.real && i.real.cerrado)) return 'cumplido';
    if (i.real && i.real.ots && i.real.ots.length) return 'encurso';
    var ini = d(i.plan_vigente.inicio), fin = d(i.plan_vigente.fin || i.plan_vigente.inicio);
    if (fin < hoy) return 'vencido';
    if (dias(hoy, ini) <= 3) return 'poriniciar';
    return 'planificado';
  }
  var ETIQUETA = { vencido: 'Vencido', poriniciar: 'Inicia pronto', encurso: 'En curso',
                   planificado: 'Planificado', sinagendar: 'Sin agendar', cumplido: 'Cumplido',
                   cancelado: 'Cancelado' };
  function kitOk(i) { return i.kit === 'CONFIRMADO' || i.kit === 'ENTREGADO' || i.kit === 'DISPONIBLE'; }
  function kitTexto(i) {
    return { SIN_KIT: 'sin kit', PENDIENTE: 'sin kit', SOLICITADO: 'kit solicitado',
             CONFIRMADO: 'kit confirmado', ENTREGADO: 'kit entregado', DISPONIBLE: 'kit disponible' }[i.kit] || String(i.kit || '').toLowerCase();
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
  function zonaClase(i) { return ZONAS.indexOf(i.zona) !== -1 ? ' z-' + i.zona.toLowerCase() : ' z-otra'; }
  function marcaZona(i) {
    if (zonaVista !== 'ADMIN') return '';
    var z = i.zona || 'OTRA';
    return '<span class="zona zona-' + (ZONAS.indexOf(z) !== -1 ? z.toLowerCase() : 'otra') + '">' + esc(z) + '</span>';
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

  /* ---------- Franja de alertas ---------- */
  function pintarAlertas() {
    var v = visibles();
    var vencidos = v.filter(function (i) { return estado(i) === 'vencido'; });
    var pronto = v.filter(function (i) { return estado(i) === 'poriniciar'; });
    var sinKit = pronto.filter(function (i) { return !kitOk(i); });
    var sinAgendar = v.filter(function (i) { return estado(i) === 'sinagendar'; });
    var corr = CORRECTIVOS.filter(function (c) { return zonaVista === 'ADMIN' || c.zona === zonaVista; });
    var conAlerta = corr.filter(function (c) { return c.estado_alerta === 'CON_ALERTA'; });

    var partes = [];
    if (vencidos.length) partes.push(chip('roja', vencidos.length, 'preventivos vencidos', 'vencido'));
    if (pronto.length) partes.push(chip('ambar', pronto.length, 'inician en 3 días o menos', 'poriniciar'));
    if (sinKit.length) partes.push(chip('ambar', sinKit.length, 'de esos, sin kit confirmado', 'sinkit'));
    if (sinAgendar.length) partes.push(chip('azul', sinAgendar.length, 'sin agendar', 'sinagendar'));
    if (conAlerta.length) partes.push(chip('roja', conAlerta.length, 'correctivos con alerta', 'correctivo'));

    var cont = $('#alertas');
    cont.hidden = false;
    if (!partes.length) {
      cont.innerHTML = '<span class="alerta verde">Nada pendiente por vencer en ' +
        (zonaVista === 'ADMIN' ? 'las tres zonas' : zonaVista) + '</span>';
      return;
    }
    cont.innerHTML = partes.join('') +
      '<span class="aviso-fuente">Se apaga solo cuando se registra el cierre del ingreso</span>';
    $$('.alerta[data-f]', cont).forEach(function (b) {
      b.addEventListener('click', function () { filtrarPor(b.dataset.f); });
    });
  }
  /* Los chips son botones: se operan con teclado igual que con el dedo (TR-14). */
  function chip(color, n, texto, filtro) {
    return '<button type="button" class="alerta ' + color + '" data-f="' + filtro + '">' +
           '<span class="n">' + n + '</span>' + esc(texto) + '</button>';
  }
  function filtrarPor(f) {
    filtroActivo = f;
    if (f === 'correctivo') {
      pintarLista(null, CORRECTIVOS.filter(function (c) {
        return (zonaVista === 'ADMIN' || c.zona === zonaVista) && c.estado_alerta === 'CON_ALERTA';
      }));
      return;
    }
    var v = visibles().filter(function (i) {
      if (f === 'sinkit') return estado(i) === 'poriniciar' && !kitOk(i);
      if (f === 'reagendado') return movido(i);
      if (f === 'todos') return true;
      return estado(i) === f;
    });
    var titulos = { sinkit: 'Inician pronto sin kit', reagendado: 'Reagendados', todos: 'Todos los ingresos' };
    pintarLista(v, null, 'Filtro: ' + (titulos[f] || ETIQUETA[f] || f));
    var lista = $('#listaMes');
    if (lista) { lista.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
  }

  /* ---------- Tarjetas de cumplimiento (TR-06, TR-15) ---------- */
  function cuenta(lista) {
    var cnt = { total: lista.length, cumplidos: 0, aTiempo: 0, movidos: 0 };
    lista.forEach(function (i) {
      var e = estado(i); cnt[e] = (cnt[e] || 0) + 1;
      if (e === 'cumplido') { cnt.cumplidos++; if (aTiempo(i)) cnt.aTiempo++; }
      if (movido(i)) cnt.movidos++;
    });
    cnt.cerrados = cnt.cumplidos + (cnt.vencido || 0);
    cnt.pct = cnt.cerrados ? Math.round(cnt.aTiempo * 100 / cnt.cerrados) : null;
    return cnt;
  }
  function pintarTiles() {
    var v = visibles();
    var c = cuenta(v);
    var tiles = [
      ['', c.total, 'ingresos del año', 'todos'],
      ['rojo', c.vencido || 0, 'vencidos', 'vencido'],
      ['ambar', c.poriniciar || 0, 'inician en ≤3 días', 'poriniciar'],
      ['azul', c.encurso || 0, 'en curso', 'encurso'],
      ['verde', c.cumplidos, 'cumplidos', 'cumplido'],
      [c.pct === null ? '' : (c.pct >= 85 ? 'verde' : (c.pct >= 70 ? 'ambar' : 'rojo')),
       c.pct === null ? '—' : c.pct + '%', 'a tiempo (contra lo acordado con KFC)', 'cumplido'],
      ['', c.movidos, 'reagendados', 'reagendado'],
      ['', c.sinagendar || 0, 'sin agendar', 'sinagendar'],
    ];
    var html = tiles.map(function (t) {
      return '<button type="button" class="tile ' + t[0] + '" data-f="' + t[3] + '">' +
             '<div class="v">' + t[1] + '</div><div class="k">' + t[2] + '</div></button>';
    }).join('');
    /* Con las tres zonas a la vista, una fila más: cada zona con sus cifras,
       para que la administradora las compare de un barrido. */
    if (zonaVista === 'ADMIN') {
      html += '<div class="tiles-zonas">' + ZONAS.map(function (z) {
        var cz = cuenta(v.filter(function (i) { return i.zona === z; }));
        return '<div class="tile-zona z-' + z.toLowerCase() + '"><span class="zona zona-' + z.toLowerCase() + '">' + z + '</span>' +
          '<span><b>' + cz.total + '</b> ingresos</span>' +
          '<span class="r"><b>' + (cz.vencido || 0) + '</b> vencidos</span>' +
          '<span class="a"><b>' + (cz.poriniciar || 0) + '</b> inician</span>' +
          '<span class="b"><b>' + (cz.encurso || 0) + '</b> en curso</span>' +
          '<span class="g"><b>' + cz.cumplidos + '</b> cumplidos' + (cz.pct !== null ? ' · ' + cz.pct + '% a tiempo' : '') + '</span>' +
          '<span><b>' + (cz.sinagendar || 0) + '</b> sin agendar</span></div>';
      }).join('') + '</div>';
    }
    $('#tiles').innerHTML = html;
    $$('.tile[data-f]', $('#tiles')).forEach(function (b) {
      b.addEventListener('click', function () { filtrarPor(b.dataset.f); });
    });
  }

  /* ---------- Calendario ---------- */
  function pintarCalendario() {
    var cal = $('#calendario');
    var y = mesVista.getFullYear(), m = mesVista.getMonth();
    $('#mesTitulo').textContent = MESES[m].charAt(0).toUpperCase() + MESES[m].slice(1) + ' ' + y;

    // Se pintan los DÍAS DECLARADOS, no toda la ventana de inicio a fin: el
    // 19,2% de los ingresos tiene huecos, y rellenar el rango metía un mismo
    // local en todos los días del mes.
    var porDia = {};
    visibles().forEach(function (i) {
      if (!i.plan_vigente) return;
      var ds = i.plan_vigente.dias_declarados || [i.plan_vigente.inicio];
      ds.forEach(function (isoDia) {
        var f = d(isoDia);
        if (f && f.getFullYear() === y && f.getMonth() === m) {
          (porDia[f.getDate()] = porDia[f.getDate()] || []).push(i);
        }
      });
    });

    var primero = new Date(y, m, 1);
    var arranque = (primero.getDay() + 6) % 7;          // lunes = 0
    var ultimo = new Date(y, m + 1, 0).getDate();
    var html = DIAS.map(function (x) { return '<div class="dia-cab">' + x + '</div>'; }).join('');

    for (var k = 0; k < arranque; k++) html += '<div class="dia fuera"></div>';
    for (var dia = 1; dia <= ultimo; dia++) {
      var esHoy = (y === hoy.getFullYear() && m === hoy.getMonth() && dia === hoy.getDate());
      var lista = porDia[dia] || [];
      var chips = lista.slice(0, 3).map(function (i) {
        var e = estado(i);
        return '<button type="button" class="chip-local ' + e + zonaClase(i) + '" data-id="' + esc(i.id) + '" ' +
               'title="' + esc(i.local + ' · ' + (i.local_nombre || '') + ' · ' + ETIQUETA[e]) + '">' +
               esc(i.local) + (!kitOk(i) && e !== 'cumplido' ? ' <span class="kit">sin kit</span>' : '') +
               '</button>';
      }).join('');
      if (lista.length > 3) chips += '<span class="mas">+' + (lista.length - 3) + ' más</span>';
      html += '<div class="dia' + (esHoy ? ' hoy' : '') + '"><span class="n">' + dia + '</span>' + chips + '</div>';
    }
    cal.innerHTML = html;
    $$('.chip-local', cal).forEach(function (b) {
      b.addEventListener('click', function () { abrirPanel(b.dataset.id); });
    });

    if (filtroActivo) { filtrarPor(filtroActivo); return; }
    var delMes = visibles().filter(function (i) {
      if (!i.plan_vigente) return false;
      var ini = d(i.plan_vigente.inicio);
      return ini.getFullYear() === y && ini.getMonth() === m;
    }).sort(function (a, b) { return a.plan_vigente.inicio.localeCompare(b.plan_vigente.inicio); });
    pintarLista(delMes);
  }

  /* ---------- Lista ---------- */
  function pintarLista(ings, correctivos, titulo) {
    var cont = $('#listaMes');
    if (correctivos) {
      cont.innerHTML = '<p class="sub">' + correctivos.length + ' correctivos con alerta' +
        ' <button type="button" class="btn sm" id="quitarFiltro">Ver el mes</button></p>' +
        correctivos.map(function (c) {
          return '<div class="fila-ing"><span class="cod">' + esc(c.aviso) + '</span>' +
            '<span class="nom">' + esc(c.local || '?') + ' · ' + esc(c.local_nombre || '') + '</span>' +
            '<span class="et vencido">' + esc(((c.alertas || [])[0] || {}).regla || 'ALERTA') + '</span>' +
            '<span class="fecha">' + esc(c.caso || '') + '</span></div>';
        }).join('');
      var q0 = $('#quitarFiltro'); if (q0) q0.addEventListener('click', quitarFiltro);
      return;
    }
    if (!ings || !ings.length) {
      cont.innerHTML = '<p class="sub">' + (titulo ? esc(titulo) + ' — nada.' : 'Sin ingresos en este período.') +
        (titulo ? ' <button type="button" class="btn sm" id="quitarFiltro">Ver el mes</button>' : '') + '</p>';
      var q1 = $('#quitarFiltro'); if (q1) q1.addEventListener('click', quitarFiltro);
      return;
    }
    cont.innerHTML = (titulo ? '<p class="sub">' + esc(titulo) + ' — ' + ings.length +
        ' <button type="button" class="btn sm" id="quitarFiltro">Ver el mes</button></p>' : '') +
      ings.map(function (i) {
        var e = estado(i);
        var at = aTiempo(i);
        /* La fila se abre con clic, Enter o Espacio (TR-14): `role="button"` y
           `tabindex` porque dentro lleva otro botón (agendar) y un botón no
           puede contener otro. */
        return '<div class="fila-ing' + zonaClase(i) + '" data-id="' + esc(i.id) + '" role="button" tabindex="0">' +
          '<span class="cod">' + esc(i.local) + '</span>' +
          marcaZona(i) +
          '<span class="nom">' + esc(i.local_nombre || i.ciudad || '') + ' <span class="sub">· ingreso ' + esc(i.numero) + '</span></span>' +
          '<span class="fecha">' + (i.plan_vigente ? corta(i.plan_vigente.inicio) + '–' + corta(i.plan_vigente.fin || i.plan_vigente.inicio) : 'sin fecha') + '</span>' +
          '<span class="et ' + e + '">' + ETIQUETA[e] + (e === 'cumplido' && at !== null ? (at ? ' a tiempo' : ' tarde') : '') + '</span>' +
          (!kitOk(i) && e !== 'cumplido' ? '<span class="et kit">' + esc(kitTexto(i)) + '</span>' : '') +
          (movido(i) ? '<span class="et kit">reagendado</span>' : '') +
          (e === 'sinagendar' && PUEDE_EDITAR
            ? '<button type="button" class="btn sm primary" data-agendar="' + esc(i.local) + '" data-numero="' + esc(i.numero) + '" data-anio="' + esc(i.anio || '') + '">Agendar</button>'
            : '') +
          '</div>';
      }).join('');
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
    var q2 = $('#quitarFiltro'); if (q2) q2.addEventListener('click', quitarFiltro);
  }
  function quitarFiltro() { filtroActivo = null; pintarCalendario(); }

  /* ---------- Panel de detalle ---------- */
  function abrirPanel(id) {
    var i = INGRESOS.filter(function (x) { return x.id === id; })[0];
    if (!i) return;
    var e = estado(i);
    var mov = movido(i);
    panelAbiertoId = id;
    $('#panelTitulo').textContent = i.local + ' · ingreso ' + i.numero + ' de 4';

    var h = '<dl class="dl">' +
      '<dt>Local</dt><dd>' + esc(i.local_nombre || '—') + '</dd>' +
      '<dt>Zona</dt><dd>' + esc(i.zona || '—') + (i.ciudad ? ' · ' + esc(i.ciudad) : '') + '</dd>' +
      '<dt>Estado</dt><dd><span class="et ' + e + '">' + ETIQUETA[e] + '</span>' +
      (e === 'cumplido' && aTiempo(i) !== null ? ' <span class="sub">' + (aTiempo(i) ? 'a tiempo' : 'fuera del plan acordado') + '</span>' : '') + '</dd>' +
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
        ? larga(i.real.inicio) + ' a ' + larga(i.real.fin || i.real.inicio) + (i.real.cerrado ? '' : ' (sin cerrar)')
        : 'todavía no empieza') + '</dd>' +
      '</dl>' +
      (i.anio_supuesto ? '<p class="sub">El año lo pone el sistema: el texto original («' +
        esc(i.texto_origen) + '») no lo trae.</p>' : '') +
      (PUEDE_EDITAR && e !== 'cumplido' ? '<div class="row" style="margin-top:8px">' +
        (i.plan_vigente ? '<button type="button" class="btn" data-acc="reagendar">Reagendar</button>'
                        : '<button type="button" class="btn primary" data-acc="agendar">Agendar</button>') +
        (i.plan_vigente ? '<button type="button" class="btn secondary" data-acc="cerrar">Registrar el cierre</button>' : '') +
        '</div>' : '') +
      '</div>';

    h += '<div class="bloque-p"><h3>Kit de mantenimiento</h3>' +
      '<p style="margin:0 0 8px;font-size:13px">Estado: <b>' + esc(kitTexto(i)) + '</b>' +
      (i.kit_fecha ? ' <span class="sub">(' + esc(corta(i.kit_fecha)) + ')</span>' : '') +
      (i.kit_texto ? ' <span class="sub">(«' + esc(i.kit_texto) + '»)</span>' : '') + '</p>' +
      (!kitOk(i) && e !== 'cumplido'
        ? '<p class="sub" style="margin:0">Sin kit confirmado no debería agendarse el ingreso. ' +
          'Si ya llegó, confírmalo; si no, reagenda y queda la novedad para reportarle a KFC.</p>'
        : '') +
      (PUEDE_EDITAR && e !== 'cumplido' ? '<div class="row" style="margin-top:8px">' +
        '<button type="button" class="btn secondary" data-acc="kit">Actualizar el kit</button></div>' : '') +
      '</div>';

    var ots = (i.real && i.real.ots) || [];
    h += '<div class="bloque-p"><h3>Órdenes emitidas (' + ots.length + ')</h3>';
    if (ots.length) {
      h += ots.map(function (o) {
        return '<div style="font-size:12px;font-family:ui-monospace,monospace;padding:3px 0">' +
               (o.dia ? 'D' + esc(o.dia) + ' · ' : '') + (o.fecha ? corta(o.fecha) + ' · ' : '') + esc(o.id) + '</div>';
      }).join('');
    } else {
      h += '<p class="sub" style="margin:0">Ninguna todavía. Se emite una orden por cada día de intervención.</p>';
    }
    /* La orden del ingreso se llena desde aquí, con el local, el tipo y el día
       ya puestos (S1 los lee de la URL): un toque menos en el celular. */
    if (i.plan_vigente && e !== 'cumplido') {
      var dur = (i.plan_vigente.dias_declarados && i.plan_vigente.dias_declarados.length) ||
                Math.max(1, dias(d(i.plan_vigente.inicio), d(i.plan_vigente.fin || i.plan_vigente.inicio)) + 1);
      var enlaces = [];
      for (var k = 1; k <= Math.min(dur, 5); k++) {
        enlaces.push('<a class="btn sm" href="index.html?tipo=PREVENTIVO&dia=' + k + '&local=' + encodeURIComponent(i.local) + '">Orden del día ' + k + '</a>');
      }
      h += '<div class="row" style="margin-top:8px;flex-wrap:wrap;gap:6px">' + enlaces.join('') + '</div>';
    }
    h += '</div>';

    h += '<div class="bloque-p"><h3>Novedades</h3>' +
      (i.novedades && i.novedades.length
        ? i.novedades.map(function (n) {
            return '<div class="nov">' + (n.tipo ? '<b>' + esc(n.tipo.toLowerCase()) + '</b> · ' : '') + esc(n.texto) +
              (n.fecha_antes || n.fecha_despues ? ' <span class="sub">(' + esc(n.fecha_antes || 'sin fecha') + ' → ' + esc(n.fecha_despues || '') + ')</span>' : '') +
              '<div class="meta">' + esc(n.fecha) + ' · ' + esc(n.por) + '</div></div>';
          }).join('')
        : '<p class="sub" style="margin:0 0 8px">Sin novedades registradas.</p>') +
      (PUEDE_EDITAR ? '<button type="button" class="btn" data-acc="novedad">+ Registrar novedad</button>' : '') +
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

  /* ---------- Guardar: el servidor, con su bitácora ---------- */
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

  /* ---------- Acciones ---------- */
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
      abrirModal('Registrar el cierre — ' + i.local,
        '<p style="font-size:13px">El ingreso queda <b>cumplido</b> con la fecha real. Si el fin real pasa de lo ' +
        'acordado con KFC (' + (i.plan_original ? larga(i.plan_original.fin || i.plan_original.inicio) : 'sin fecha') + '), cuenta como tarde.</p>' +
        '<div class="grid g2"><div><label>Inicio real</label>' +
        '<input type="date" id="ciIni" value="' + ((i.real && i.real.inicio) || (i.plan_vigente && i.plan_vigente.inicio) || iso(hoy)) + '"></div>' +
        '<div><label>Fin real</label><input type="date" id="ciFin" value="' + iso(hoy) + '"></div></div>' +
        '<label style="margin-top:8px">Orden de cierre (opcional)</label><input type="text" id="ciOt" placeholder="OT-…">' +
        '<label style="margin-top:8px">Nota</label><textarea id="ciNota" placeholder="Qué quedó hecho, qué quedó pendiente"></textarea>',
        function () {
          if ($('#ciFin').value < $('#ciIni').value) { alert('El fin real no puede ser anterior al inicio.'); return false; }
          return guardar({ accion: 'cerrar', ingreso_id: i.ingreso_id, real_inicio: $('#ciIni').value, real_fin: $('#ciFin').value,
                           ot: $('#ciOt').value, nota: $('#ciNota').value }, true);
        });
      return;
    }
    if (acc === 'novedad') {
      abrirModal('Registrar novedad — ' + i.local,
        '<label>Tipo</label><select id="novTipo">' +
        '<option>Kit pendiente</option><option>Acceso negado</option>' +
        '<option>Trabajo parcial</option><option>Equipo fuera de servicio</option>' +
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

  /* ---------- Agendar un local (desde cero o desde la fila «sin agendar») ---------- */
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
      '<p class="sub" style="margin-top:8px">El fin es <b>estimado</b>. La fecha real se registra al cerrar el ingreso.</p>' +
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
    if (chipA) { chipA.textContent = a.zona ? ('Zona ' + a.zona) : 'Las 3 zonas'; }
    if (chipR) { chipR.textContent = a.rol_nombre || ''; chipR.hidden = !a.rol_nombre; }
    if (a.rol === 'TECNICO') { barraDelTecnico(); }
    var ag = document.getElementById('btnAgendar');
    if (ag && !PUEDE_EDITAR) { ag.style.display = 'none'; }

    var sel = document.getElementById('usuario');
    if (!sel) { return; }
    var antes = sel.value;
    sel.innerHTML = '';
    if (a.zona) {
      sel.innerHTML = '<option value="' + a.zona + '">Zona ' + a.zona + '</option>';
      sel.disabled = true;
      zonaVista = a.zona;
    } else {
      sel.innerHTML = '<option value="ADMIN">Las 3 zonas</option>' +
                      '<option value="UIO">Solo UIO</option>' +
                      '<option value="LARB">Solo LARB</option>' +
                      '<option value="CNLJ">Solo CNLJ</option>';
      sel.disabled = false;
      zonaVista = antes && antes !== '' ? antes : 'ADMIN';
      sel.value = zonaVista;
    }
  }

  function fuenteTexto(j) {
    var cuando = j.generado_cronograma ? String(j.generado_cronograma).slice(0, 16).replace('T', ' ') : '';
    if (j.fuente === 'tabla') return 'en la base' + (cuando ? ', último cambio ' + cuando : '');
    if (j.fuente === 'json') return 'del archivo de la estación' + (cuando ? ' del ' + cuando : '') + ' (todavía no importado: solo se puede agendar)';
    return 'sin cronograma cargado';
  }

  /* ---------- Cargar (y recargar tras guardar) ---------- */
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
      var c = (j.cronograma && j.cronograma.conversion) || {};
      $('#subcab').textContent = '4 ingresos al año por local, acordados con Grupo KFC · ' +
        INGRESOS.length + ' ingresos ' + (j.cronograma && j.cronograma.anio) + ', ' +
        (c.convertidos || 0) + ' con fecha (' + ((c.sin_convertir || []).length) + ' sin resolver) · datos ' + fuenteTexto(j);
      pintarAlertas(); pintarTiles(); pintarCalendario(); ajustarFranja();
    }).catch(function (err) {
      $('#alertas').hidden = false;
      $('#alertas').innerHTML = '<span class="alerta roja">No se pudo cargar: ' + esc(err.message) + '</span>';
    });
  }

  /* ---------- Arranque ---------- */
  document.addEventListener('DOMContentLoaded', function () {
    $('#panelCerrar').addEventListener('click', cerrarPanel);
    $('#panelFondo').addEventListener('click', cerrarPanel);
    $('#modalCerrar').addEventListener('click', cerrarModal);
    $('#modalFondo').addEventListener('click', cerrarModal);
    $('#btnAgendar').addEventListener('click', function () { agendar(null, 1, hoy.getFullYear()); });
    $('#mesAnt').addEventListener('click', function () { filtroActivo = null; mesVista.setMonth(mesVista.getMonth() - 1); pintarCalendario(); });
    $('#mesSig').addEventListener('click', function () { filtroActivo = null; mesVista.setMonth(mesVista.getMonth() + 1); pintarCalendario(); });
    $('#usuario').addEventListener('change', function () {
      zonaVista = this.value; filtroActivo = null; pintarAlertas(); pintarTiles(); pintarCalendario();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { cerrarModal(); cerrarPanel(); }
    });
    window.addEventListener('resize', ajustarFranja);
    ajustarFranja();
    cargar();
  });

})();
