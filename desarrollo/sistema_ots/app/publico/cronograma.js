/* =========================================================================
   cronograma.js — Calendario de preventivos, alertas y bitácora de novedades.

   ESTA PANTALLA CORRE EN LA ESTACIÓN, no en Hostinger (directiva del cliente,
   2026-09-08): la base y la administración viven en este computador; la
   administradora entra por red y los jefes de zona desde sus portátiles. Al
   celular solo va el llenado de órdenes y el buzón.

   LAS TRES REGLAS QUE ORDENAN EL DISEÑO:

   1. EL PLAN ORIGINAL NO SE PISA. Cada ingreso guarda lo acordado con KFC
      (`plan_original`) y lo que rige hoy (`plan_vigente`). Reagendar mueve el
      vigente y exige un motivo. Si al reagendar se sobrescribiera la fecha, el
      cumplimiento siempre daría 100% y no habría nada que reportarle a KFC.

   2. LA NOVEDAD ES OBLIGATORIA AL MOVER UNA FECHA. Es la evidencia de por qué
      se demoró el cronograma. Sin motivo no se guarda el cambio.

   3. EL KIT ES UNA PRECONDICIÓN, NO UN COMENTARIO. Hoy 316 de 368 ingresos lo
      tienen pendiente. Antes de agendar hay que confirmarlo; si no está, se
      reagenda y queda registrado el motivo.

   La alerta va en una franja fija, sin botón de descartar: se apaga sola cuando
   la condición se resuelve. Lo pidió así el cliente — visible siempre, hasta la
   OT de cierre, pero sin estorbar.
   ========================================================================= */

(function () {
  'use strict';

  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };

  var DATOS = null;
  var INGRESOS = [];
  var CORRECTIVOS = [];
  var hoy = new Date(); hoy.setHours(0, 0, 0, 0);
  var mesVista = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
  var zonaVista = 'ADMIN';

  var MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
               'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  var DIAS = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];

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

  /* Estado que se pinta. Recalculado en el cliente para que la alerta de 3 días
     sea siempre relativa a HOY, y no a cuándo corrió el script. */
  function estado(i) {
    if (!i.plan_vigente) return 'sinagendar';
    if (i.real && i.real.ots && i.real.ots.length) return i.real.cerrado ? 'cumplido' : 'encurso';
    var ini = d(i.plan_vigente.inicio), fin = d(i.plan_vigente.fin);
    if (fin < hoy) return 'vencido';
    if (dias(hoy, ini) <= 3) return 'poriniciar';
    return 'planificado';
  }
  var ETIQUETA = { vencido: 'Vencido', poriniciar: 'Inicia pronto', encurso: 'En curso',
                   planificado: 'Planificado', sinagendar: 'Sin agendar', cumplido: 'Cumplido' };

  function visibles() {
    return INGRESOS.filter(function (i) { return zonaVista === 'ADMIN' || i.zona === zonaVista; });
  }

  /* ---------- Franja de alertas ---------- */
  function pintarAlertas() {
    var v = visibles();
    var vencidos = v.filter(function (i) { return estado(i) === 'vencido'; });
    var pronto = v.filter(function (i) { return estado(i) === 'poriniciar'; });
    var sinKit = pronto.filter(function (i) { return i.kit === 'PENDIENTE'; });
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
    if (!partes.length) {
      cont.hidden = false;
      cont.innerHTML = '<span class="alerta verde">Nada pendiente por vencer en ' +
        (zonaVista === 'ADMIN' ? 'las tres zonas' : zonaVista) + '</span>';
      return;
    }
    cont.hidden = false;
    cont.innerHTML = partes.join('') +
      '<span class="aviso-fuente">Se apaga solo cuando se emite la OT de cierre</span>';
    $$('.alerta[data-f]', cont).forEach(function (b) {
      b.addEventListener('click', function () { filtrarPor(b.dataset.f); });
    });
  }
  function chip(color, n, texto, filtro) {
    return '<span class="alerta ' + color + '" data-f="' + filtro + '" role="button" tabindex="0">' +
           '<span class="n">' + n + '</span>' + esc(texto) + '</span>';
  }
  function filtrarPor(f) {
    var v = visibles().filter(function (i) {
      if (f === 'sinkit') return estado(i) === 'poriniciar' && i.kit === 'PENDIENTE';
      if (f === 'correctivo') return false;
      return estado(i) === f;
    });
    if (f === 'correctivo') {
      pintarLista(null, CORRECTIVOS.filter(function (c) {
        return (zonaVista === 'ADMIN' || c.zona === zonaVista) && c.estado_alerta === 'CON_ALERTA';
      }));
      return;
    }
    pintarLista(v, null, 'Filtro: ' + (ETIQUETA[f] || f));
  }

  /* ---------- Tarjetas de cumplimiento ---------- */
  function pintarTiles() {
    var v = visibles();
    var cnt = {};
    v.forEach(function (i) { var e = estado(i); cnt[e] = (cnt[e] || 0) + 1; });
    var conFecha = v.filter(function (i) { return i.plan_vigente; }).length;
    var movidos = v.filter(function (i) {
      return i.plan_original && i.plan_vigente && i.plan_original.inicio !== i.plan_vigente.inicio;
    }).length;
    var tiles = [
      ['', v.length, 'ingresos del año'],
      ['rojo', cnt.vencido || 0, 'vencidos'],
      ['ambar', cnt.poriniciar || 0, 'inician en ≤3 días'],
      ['azul', cnt.encurso || 0, 'en curso'],
      ['', movidos, 'reagendados'],
      ['', (v.length - conFecha), 'sin fecha'],
    ];
    $('#tiles').innerHTML = tiles.map(function (t) {
      return '<div class="tile ' + t[0] + '"><div class="v">' + t[1] + '</div><div class="k">' + t[2] + '</div></div>';
    }).join('');
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
      var dias = i.plan_vigente.dias_declarados || [i.plan_vigente.inicio];
      dias.forEach(function (isoDia) {
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
        return '<button type="button" class="chip-local ' + e + '" data-id="' + esc(i.id) + '">' +
               esc(i.local) + (i.kit === 'PENDIENTE' ? ' <span class="kit">sin kit</span>' : '') +
               '</button>';
      }).join('');
      if (lista.length > 3) chips += '<span class="mas">+' + (lista.length - 3) + ' más</span>';
      html += '<div class="dia' + (esHoy ? ' hoy' : '') + '"><span class="n">' + dia + '</span>' + chips + '</div>';
    }
    cal.innerHTML = html;
    $$('.chip-local', cal).forEach(function (b) {
      b.addEventListener('click', function () { abrirPanel(b.dataset.id); });
    });

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
      cont.innerHTML = '<p class="sub">' + correctivos.length + ' correctivos con alerta</p>' +
        correctivos.map(function (c) {
          return '<div class="fila-ing"><span class="cod">' + esc(c.aviso) + '</span>' +
            '<span class="nom">' + esc(c.local || '?') + ' · ' + esc(c.local_nombre || '') + '</span>' +
            '<span class="et vencido">' + esc((c.alertas[0] || {}).regla || 'ALERTA') + '</span>' +
            '<span class="fecha">' + esc(c.caso || '') + '</span></div>';
        }).join('');
      return;
    }
    if (!ings || !ings.length) { cont.innerHTML = '<p class="sub">Sin ingresos en este período.</p>'; return; }
    cont.innerHTML = (titulo ? '<p class="sub">' + esc(titulo) + ' — ' + ings.length + '</p>' : '') +
      ings.map(function (i) {
        var e = estado(i);
        var movido = i.plan_original && i.plan_vigente && i.plan_original.inicio !== i.plan_vigente.inicio;
        return '<div class="fila-ing" data-id="' + esc(i.id) + '">' +
          '<span class="cod">' + esc(i.local) + '</span>' +
          '<span class="nom">' + esc(i.local_nombre || i.ciudad || '') + '</span>' +
          '<span class="fecha">' + (i.plan_vigente ? corta(i.plan_vigente.inicio) + '–' + corta(i.plan_vigente.fin) : 'sin fecha') + '</span>' +
          '<span class="et ' + e + '">' + ETIQUETA[e] + '</span>' +
          (i.kit === 'PENDIENTE' ? '<span class="et kit">sin kit</span>' : '') +
          (movido ? '<span class="et kit">reagendado</span>' : '') +
          '</div>';
      }).join('');
    $$('.fila-ing[data-id]', cont).forEach(function (f) {
      f.addEventListener('click', function () { abrirPanel(f.dataset.id); });
    });
  }

  /* ---------- Panel de detalle ---------- */
  function abrirPanel(id) {
    var i = INGRESOS.filter(function (x) { return x.id === id; })[0];
    if (!i) return;
    var e = estado(i);
    var movido = i.plan_original && i.plan_vigente && i.plan_original.inicio !== i.plan_vigente.inicio;
    $('#panelTitulo').textContent = i.local + ' · ingreso ' + i.numero + ' de 4';

    var h = '<dl class="dl">' +
      '<dt>Local</dt><dd>' + esc(i.local_nombre || '—') + '</dd>' +
      '<dt>Zona</dt><dd>' + esc(i.zona || '—') + ' · ' + esc(i.ciudad || '') + '</dd>' +
      '<dt>Estado</dt><dd><span class="et ' + e + '">' + ETIQUETA[e] + '</span></dd>' +
      '</dl>';

    h += '<div class="bloque-p"><h3>Fechas</h3><dl class="dl">' +
      '<dt>Acordado con KFC</dt><dd>' + (i.plan_original
        ? '<b>' + larga(i.plan_original.inicio) + '</b> a <b>' + larga(i.plan_original.fin) + '</b>'
        : 'sin fecha') + '</dd>' +
      '<dt>Vigente</dt><dd class="' + (movido ? 'movido' : '') + '">' + (i.plan_vigente
        ? '<b>' + larga(i.plan_vigente.inicio) + '</b> a <b>' + larga(i.plan_vigente.fin) + '</b>' +
          (movido ? ' — movido' : '')
        : 'sin fecha') + '</dd>' +
      '<dt>Real</dt><dd>' + (i.real && i.real.inicio
        ? larga(i.real.inicio) + ' a ' + larga(i.real.fin) + (i.real.cerrado ? '' : ' (sin cerrar)')
        : 'todavía no empieza') + '</dd>' +
      '</dl>' +
      (i.anio_supuesto ? '<p class="sub">El año lo pone el sistema: el texto original («' +
        esc(i.texto_origen) + '») no lo trae.</p>' : '') +
      '</div>';

    h += '<div class="bloque-p"><h3>Kit de mantenimiento</h3>' +
      '<p style="margin:0 0 8px;font-size:13px">Estado: <b>' + esc(i.kit) + '</b>' +
      (i.kit_texto ? ' <span class="sub">(«' + esc(i.kit_texto) + '»)</span>' : '') + '</p>' +
      (i.kit === 'PENDIENTE'
        ? '<p class="sub" style="margin:0">Sin kit confirmado no debería agendarse el ingreso. ' +
          'Si ya llegó, confírmalo; si no, reagenda y queda la novedad para reportarle a KFC.</p>'
        : '') +
      '<div class="row" style="margin-top:8px">' +
      '<button type="button" class="btn secondary" data-acc="kit">Confirmar kit recibido</button>' +
      '<button type="button" class="btn" data-acc="reagendar">Reagendar</button>' +
      '</div></div>';

    h += '<div class="bloque-p"><h3>Órdenes emitidas (' + ((i.real && i.real.ots) ? i.real.ots.length : 0) + ')</h3>';
    if (i.real && i.real.ots && i.real.ots.length) {
      h += i.real.ots.map(function (o) {
        return '<div style="font-size:12px;font-family:ui-monospace,monospace;padding:3px 0">' +
               'D' + (o.dia || '?') + ' · ' + corta(o.fecha) + ' · ' + esc(o.id) + '</div>';
      }).join('');
      h += '<p class="sub" style="margin-top:8px">El ingreso se cierra cuando el técnico marca su ' +
           'orden como <b>la última de la serie</b>, y ahí se registra la fecha real de fin.</p>';
    } else {
      h += '<p class="sub" style="margin:0">Ninguna todavía. Se emite una orden por cada día de intervención.</p>';
    }
    h += '</div>';

    h += '<div class="bloque-p"><h3>Novedades</h3>' +
      (i.novedades && i.novedades.length
        ? i.novedades.map(function (n) {
            return '<div class="nov">' + esc(n.texto) + '<div class="meta">' + esc(n.fecha) + ' · ' + esc(n.por) + '</div></div>';
          }).join('')
        : '<p class="sub" style="margin:0 0 8px">Sin novedades registradas.</p>') +
      '<button type="button" class="btn" data-acc="novedad">+ Registrar novedad</button>' +
      '<p class="sub" style="margin-top:8px">Esto es lo que se le reporta a Grupo KFC como ' +
      'motivo de demora frente al cronograma acordado.</p></div>';

    $('#panelCuerpo').innerHTML = h;
    $$('[data-acc]', $('#panelCuerpo')).forEach(function (b) {
      b.addEventListener('click', function () { accion(b.dataset.acc, i); });
    });
    $('#panel').hidden = false;
    $('#panelFondo').hidden = false;
  }
  function cerrarPanel() { $('#panel').hidden = true; $('#panelFondo').hidden = true; }

  /* ---------- Acciones (v1: no persisten todavía) ---------- */
  function accion(acc, i) {
    if (acc === 'kit') {
      abrirModal('Confirmar kit — ' + i.local,
        '<p style="font-size:13px">Confirmas que <b>' + esc(i.local) + '</b> ya tiene el kit de mantenimiento.</p>' +
        '<label>Fecha en que llegó</label><input type="date" id="kitFecha" value="' + iso(hoy) + '">' +
        '<label style="margin-top:8px">Nota (opcional)</label><input type="text" id="kitNota" placeholder="quién lo confirmó, número de guía…">',
        function () { avisoNoPersiste(); });
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
        '<input type="date" id="reFin" value="' + (i.plan_vigente ? i.plan_vigente.fin : iso(hoy)) + '"></div></div>' +
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
          avisoNoPersiste();
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
        function () { avisoNoPersiste(); });
    }
  }
  function avisoNoPersiste() {
    alert('v1 de revisión: la acción todavía no se guarda.\n\n' +
          'Para guardarla hace falta la migración 004 (tablas de cronograma y novedades), ' +
          'que cambia el esquema y necesita aprobación.');
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
      if (alGuardar() !== false) cerrarModal();
    });
  }
  function cerrarModal() { $('#modal').hidden = true; $('#modalFondo').hidden = true; }

  /* ---------- Agendar un local desde cero ---------- */
  function agendar() {
    var opciones = (DATOS.locales || []).filter(function (l) {
      return zonaVista === 'ADMIN' || l.zona === zonaVista;
    }).sort(function (a, b) { return a.nombre.localeCompare(b.nombre); })
      .map(function (l) { return '<option value="' + esc(l.codigo) + '">' + esc(l.codigo + ' · ' + l.nombre) + '</option>'; })
      .join('');
    abrirModal('Agendar un ingreso',
      '<label>Local</label><select id="agLocal"><option value="">Elige el local…</option>' + opciones + '</select>' +
      '<label style="margin-top:8px">¿Qué ingreso del año?</label>' +
      '<select id="agNum"><option>1</option><option>2</option><option>3</option><option>4</option></select>' +
      '<div class="grid g2" style="margin-top:8px">' +
      '<div><label>Inicio</label><input type="date" id="agIni" value="' + iso(hoy) + '"></div>' +
      '<div><label>Fin estimado</label><input type="date" id="agFin" value="' + iso(hoy) + '"></div></div>' +
      '<p class="sub" style="margin-top:8px">El fin es <b>estimado</b>. La fecha real se registra sola ' +
      'cuando el técnico marca su orden como la última de la serie.</p>' +
      '<label style="margin-top:8px" style="display:flex;align-items:center;gap:8px">' +
      '<input type="checkbox" id="agKit" style="width:auto;height:auto;-webkit-appearance:auto;appearance:auto"> ' +
      'Confirmo que el local ya tiene el kit de mantenimiento</label>',
      function () {
        if (!$('#agLocal').value) { alert('Elige el local.'); return false; }
        if ($('#agFin').value < $('#agIni').value) { alert('El fin no puede ser anterior al inicio.'); return false; }
        if (!$('#agKit').checked &&
            !confirm('El kit no está confirmado.\n\nSe puede agendar igual, pero el ingreso queda marcado ' +
                     '«sin kit» y es el motivo de demora más frecuente. ¿Continuar?')) return false;
        avisoNoPersiste();
      });
  }

  /* ---------- Arranque ---------- */
  document.addEventListener('DOMContentLoaded', function () {
    $('#panelCerrar').addEventListener('click', cerrarPanel);
    $('#panelFondo').addEventListener('click', cerrarPanel);
    $('#modalCerrar').addEventListener('click', cerrarModal);
    $('#modalFondo').addEventListener('click', cerrarModal);
    $('#btnAgendar').addEventListener('click', agendar);
    $('#mesAnt').addEventListener('click', function () { mesVista.setMonth(mesVista.getMonth() - 1); pintarCalendario(); });
    $('#mesSig').addEventListener('click', function () { mesVista.setMonth(mesVista.getMonth() + 1); pintarCalendario(); });
    $('#usuario').addEventListener('change', function () {
      zonaVista = this.value; pintarAlertas(); pintarTiles(); pintarCalendario();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { cerrarModal(); cerrarPanel(); }
    });

    fetch('cronograma.php').then(function (r) { return r.json(); }).then(function (j) {
      if (j.error) throw new Error(j.error);
      DATOS = j;
      INGRESOS = (j.cronograma && j.cronograma.ingresos) || [];
      CORRECTIVOS = j.correctivos || [];
      var c = j.cronograma.conversion || {};
      $('#subcab').textContent = '4 ingresos al año por local, acordados con Grupo KFC · ' +
        INGRESOS.length + ' ingresos ' + j.cronograma.anio + ', ' +
        c.convertidos + ' con fecha (' + (c.sin_convertir || []).length + ' sin resolver)';
      pintarAlertas(); pintarTiles(); pintarCalendario();
    }).catch(function (err) {
      $('#alertas').hidden = false;
      $('#alertas').innerHTML = '<span class="alerta roja">No se pudo cargar: ' + esc(err.message) + '</span>';
    });
  });

})();
