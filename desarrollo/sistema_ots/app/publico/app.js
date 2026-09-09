/* =========================================================================
   app.js — Comportamiento del formulario único de captura.

   LA IDEA DE FONDO: el técnico no teclea ningún número.

     - El correlativo INDUSTEC lo reserva el servidor, del secuencial de su zona.
     - El aviso SAP sale de la ORDEN QUE SE LE ASIGNÓ. El campo que hoy se llama
       ID-ORDEN-GRUPOKFC *es* el aviso: submit.php arma el nombre canónico como
       OT-{correlativo}-{local}-{idorden}-{zona}. Eran un solo campo y estaban
       duplicados en la v1; aquí van fusionados.
     - El local, la zona, la cadena y los correos salen del aviso o del maestro.

   Lo único que nace sin aviso es la emergencia atendida en sitio, y esa queda
   marcada como tarea pendiente de la administración (PLAN §6.4b).

   Esta es una v1 PARA REVISIÓN: valida y arma el resumen; todavía no persiste,
   no genera PDF y no envía correo.
   ========================================================================= */

(function () {
  'use strict';

  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };
  var HOY = new Date();

  var CAT = null;              // catálogos (locales, equipos, tipos, técnicos)
  var AVISOS = { datos: [], cobertura: null };
  var validar = null;
  var localesPorCodigo = {};
  var avisoElegido = null;
  var firma;

  /* ---------- Carga ---------- */
  function cargarCatalogo() {
    if (window.CATALOGOS) return Promise.resolve(window.CATALOGOS);
    return fetch('catalogos.php').then(function (r) {
      if (!r.ok) throw new Error('catalogos.php respondió ' + r.status);
      // El trabajador de servicio marca con `x-guardado-en` lo que sirve desde
      // la copia local. Es la señal honesta de que estos datos no son de ahora:
      // navigator.onLine solo dice si hay red, no si hay internet, y en el
      // celular de un técnico dentro de una cocina esas dos cosas se separan.
      var guardado = r.headers.get('x-guardado-en');
      if (guardado) {
        window.__datosDesdeCache = guardado;
        window.dispatchEvent(new CustomEvent('datos-de-cache', { detail: guardado }));
      }
      return r.json();
    });
  }

  /* ---------- Utilidades ---------- */
  function limpiarTipo(t) {
    // Algunos tipos vienen de SAP como "000108_SY_MAQYEQ_MAQUINA PARA FILTRO".
    // Para mostrar se limpia; el valor crudo se conserva para validar.
    return String(t || '').replace(/^\d{6}_[A-Z0-9]{2,3}_[A-Z]+_/, '').trim() || String(t || '');
  }
  function pad(n, l) { n = String(n); while (n.length < l) n = '0' + n; return n; }
  function baja(s) { return Reglas.quitarTildes(s).toLowerCase(); }
  function isoLocal(d) {
    return d.getFullYear() + '-' + pad(d.getMonth() + 1, 2) + '-' + pad(d.getDate(), 2);
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  function fechaCorta(iso) {
    if (!iso) return null;
    var p = String(iso).slice(0, 10).split('-');
    return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : iso;
  }

  /* =======================================================================
     Combobox con búsqueda — genérico, se usa para locales y para órdenes.

     Un <select> nativo con 100 locales (o 296 avisos) obliga a girar una rueda
     interminable en el celular. Aquí se escribe y filtra. Pero el valor que
     queda es SIEMPRE una clave del catálogo: si lo tecleado no coincide con
     nada, no se fija nada. Nunca es texto libre.
     ======================================================================= */
  function crearCombo(cfg) {
    var wrap = $(cfg.wrap), busca = $(cfg.input), lista = $(cfg.lista);
    var hidden = $(cfg.hidden), clear = $(cfg.clear);
    var abierto = false, activa = -1, items = [], TOPE = 50;

    function filtrar() {
      var q = baja(busca.value).trim();
      if (!q) return items;
      var toks = q.split(/\s+/);
      return items.filter(function (it) {
        var blob = baja(cfg.buscarEn(it));
        return toks.every(function (t) { return blob.indexOf(t) !== -1; });
      });
    }

    function render() {
      var res = filtrar();
      lista.innerHTML = '';
      if (!items.length) {
        lista.innerHTML = '<li class="combo-vacio">' + esc(cfg.vacio || 'Sin opciones') + '</li>';
      } else if (!res.length) {
        lista.innerHTML = '<li class="combo-vacio">Nada coincide con “' + esc(busca.value) + '”</li>';
      } else {
        var grupo = null;
        res.slice(0, TOPE).forEach(function (it, i) {
          var g = cfg.grupo ? cfg.grupo(it) : null;
          if (g && g !== grupo) {
            grupo = g;
            var li = document.createElement('li');
            li.className = 'combo-grupo'; li.setAttribute('role', 'presentation');
            li.textContent = g;
            lista.appendChild(li);
          }
          var o = document.createElement('li');
          o.className = 'combo-opt'; o.setAttribute('role', 'option');
          o.dataset.k = cfg.clave(it);
          o.innerHTML = cfg.fila(it);
          if (i === activa) o.classList.add('activa');
          o.addEventListener('mousedown', function (e) { e.preventDefault(); elegir(it); });
          lista.appendChild(o);
        });
        if (res.length > TOPE) {
          var m = document.createElement('li');
          m.className = 'combo-vacio';
          m.textContent = '… y ' + (res.length - TOPE) + ' más. Sigue escribiendo para acotar.';
          lista.appendChild(m);
        }
      }
      abrir();
    }

    function abrir() { lista.hidden = false; busca.setAttribute('aria-expanded', 'true'); abierto = true; }
    function cerrar() { lista.hidden = true; busca.setAttribute('aria-expanded', 'false'); abierto = false; activa = -1; }

    function elegir(it) {
      hidden.value = cfg.clave(it);
      busca.value = cfg.etiqueta(it);
      clear.hidden = false;
      cerrar();
      if (cfg.alElegir) cfg.alElegir(it);
    }
    function limpiar(silencio) {
      var tenia = hidden.value;
      hidden.value = ''; busca.value = ''; clear.hidden = true;
      if (tenia && cfg.alLimpiar && !silencio) cfg.alLimpiar();
    }

    busca.addEventListener('input', function () {
      if (hidden.value) { hidden.value = ''; if (cfg.alLimpiar) cfg.alLimpiar(); }
      clear.hidden = !busca.value;
      activa = -1;
      render();
    });
    busca.addEventListener('focus', render);
    busca.addEventListener('keydown', function (e) {
      var opts = lista.querySelectorAll('.combo-opt');
      if (e.key === 'ArrowDown') { e.preventDefault(); if (!abierto) render(); activa = Math.min(activa + 1, opts.length - 1); marcar(opts); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); activa = Math.max(activa - 1, 0); marcar(opts); }
      else if (e.key === 'Enter' && abierto && activa >= 0 && opts[activa]) {
        e.preventDefault();
        var k = opts[activa].dataset.k;
        var it = items.filter(function (x) { return String(cfg.clave(x)) === k; })[0];
        if (it) elegir(it);
      } else if (e.key === 'Escape') { cerrar(); }
    });
    function marcar(opts) {
      opts.forEach(function (o, i) {
        o.classList.toggle('activa', i === activa);
        if (i === activa) o.scrollIntoView({ block: 'nearest' });
      });
    }
    clear.addEventListener('click', function () { limpiar(); busca.focus(); render(); });
    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) cerrar(); });

    return {
      cargar: function (nuevos) { items = nuevos || []; limpiar(true); },
      elegirPorClave: function (k) {
        var it = items.filter(function (x) { return String(cfg.clave(x)) === String(k); })[0];
        if (it) elegir(it);
        return !!it;
      },
      limpiar: limpiar,
      bloquear: function (si) { busca.readOnly = !!si; clear.hidden = !!si || !hidden.value; },
      valor: function () { return hidden.value; }
    };
  }

  /* ---------- Combobox de LOCALES ---------- */
  var comboLocal = null;
  function initComboLocal() {
    comboLocal = crearCombo({
      wrap: '#localCombo', input: '#localBusca', lista: '#localLista',
      hidden: '#local', clear: '#localClear',
      clave: function (l) { return l.codigo; },
      etiqueta: function (l) { return l.codigo + ' · ' + l.nombre; },
      buscarEn: function (l) { return l.codigo + ' ' + l.nombre + ' ' + l.cadena + ' ' + l.zona; },
      grupo: function (l) { return l.zona; },
      fila: function (l) {
        return '<span class="cod">' + esc(l.codigo) + '</span> · ' + esc(l.nombre) +
               ' <span class="cad">(' + esc(l.cadena) + ')</span>';
      },
      alElegir: alCambiarLocal,
      alLimpiar: alCambiarLocal
    });
    comboLocal.cargar(CAT.locales.slice().sort(function (a, b) {
      return String(a.zona).localeCompare(b.zona) || String(a.nombre).localeCompare(b.nombre);
    }));
  }

  /* ---------- Combobox de ÓRDENES ASIGNADAS ---------- */
  var comboAviso = null;
  function initComboAviso() {
    comboAviso = crearCombo({
      wrap: '#avisoCombo', input: '#avisoBusca', lista: '#avisoLista',
      hidden: '#aviso', clear: '#avisoClear',
      vacio: 'No hay órdenes abiertas para tu zona en el catálogo.',
      clave: function (a) { return a.aviso; },
      etiqueta: function (a) { return a.aviso + ' · ' + (a.local || a.centro_coste_sap) + ' · ' + (a.caso || 'sin tipo'); },
      buscarEn: function (a) {
        return [a.aviso, a.local, a.local_nombre, a.caso, a.cadena, a.zona,
                a.equipo_denominacion, a.centro_coste_sap].join(' ');
      },
      grupo: function (a) { return a.caso || 'Sin tipo de trabajo'; },
      fila: function (a) {
        return '<span class="cod">' + esc(a.aviso) + '</span> · ' +
               esc(a.local || a.centro_coste_sap) + ' ' +
               '<span class="cad">' + esc(a.local_nombre || '') + '</span>' +
               (a.fecha_notificacion ? ' <span class="cad">— ' + esc(fechaCorta(a.fecha_notificacion)) + '</span>' : '');
      },
      alElegir: alElegirAviso,
      alLimpiar: alSoltarAviso
    });
  }

  /* Los avisos que se le ofrecen al técnico.
     HOY se filtran por la ZONA del técnico, porque la asignación por persona
     todavía no existe en ningún lado: `avisos_sap` no tiene columna de técnico.
     Cuando exista la tabla de asignaciones, aquí se cambia el filtro y la
     pantalla no se toca. La interfaz dice cuál de los dos está usando (I-7). */
  function refrescarAvisos() {
    var tec = tecnicoSesion();
    var lista = AVISOS.datos;
    var nota = $('#avisoCobertura');
    if (tec && tec.zona) {
      lista = lista.filter(function (a) { return a.zona === tec.zona; });
    }
    comboAviso.cargar(lista);
    var cob = AVISOS.cobertura || {};
    var partes = [];
    partes.push(tec ? ('Mostrando las ' + lista.length + ' órdenes de la zona ' + tec.zona + '.')
                    : ('Elige tu nombre para ver tus órdenes (' + AVISOS.datos.length + ' en total).'));
    partes.push('Todavía nadie te las asignó una por una: por ahora se filtran por zona.');
    if (cob.advertencia) partes.push(cob.advertencia);
    else if (cob.hasta) partes.push('Catálogo SAP al corte del ' + fechaCorta(cob.hasta) + ', no en vivo.');
    nota.textContent = partes.join(' ');
  }

  function tecnicoSesion() {
    var id = $('#tecSesion').value;
    if (!id) return null;
    return CAT.tecnicos.filter(function (t) { return String(t.id) === id; })[0] || null;
  }

  function alElegirAviso(a) {
    avisoElegido = a;
    // El local viene de la orden: no se elige a mano ni se puede cruzar de zona.
    if (a.local && localesPorCodigo[a.local]) {
      comboLocal.elegirPorClave(a.local);
      comboLocal.bloquear(true);
      $('#localNota').textContent = 'Viene de la orden ' + a.aviso + '. Si el trabajo fue en otro local, elige “Sin orden asignada”.';
    } else {
      comboLocal.bloquear(false);
      $('#localNota').textContent = 'El centro de coste ' + (a.centro_coste_sap || '?') +
        ' de esta orden no resuelve contra el maestro. Elige el local a mano.';
    }
    // El tipo se deriva del caso de SAP, y se puede corregir.
    var caso = baja(a.caso || '');
    var tipo = caso.indexOf('preventivo') !== -1 ? 'PREVENTIVO' : 'CORRECTIVO';
    fijarTipo(tipo);
    $('#tipoDerivado').hidden = false;
    $('#tipoDerivado').textContent = 'Derivado del caso “' + (a.caso || 'sin tipo') + '” de la orden. Corrígelo si no corresponde.';
    pintarFicha(a);
    refrescarEquipos();
  }

  function alSoltarAviso() {
    avisoElegido = null;
    $('#fichaAviso').hidden = true;
    $('#tipoDerivado').hidden = true;
    comboLocal.bloquear(false);
    comboLocal.limpiar();
    $('#localNota').textContent = 'La zona y la cadena las pone el maestro, no se escriben.';
    alCambiarLocal();
  }

  /* La ficha reproduce lo que trae el correo de SAP. Los campos que el export
     actual NO tiene (prioridad, fecha comprometida, descripción del pedido)
     se muestran como "sin dato": decirlo es la regla, no rellenarlo (I-7). */
  function pintarFicha(a) {
    var f = $('#fichaAviso');
    var prio = (a.prioridad || '').toUpperCase();
    var clase = prio === 'ALTA' ? 'prio-alta' : prio === 'MEDIA' ? 'prio-media'
              : prio === 'BAJA' ? 'prio-baja' : 'prio-sd';
    var sd = '<span class="cad">sin dato en el catálogo</span>';
    f.innerHTML =
      '<div class="ficha-tit">' +
        '<span class="ficha-aviso">' + esc(a.aviso) + '</span>' +
        '<span class="prio ' + clase + '">' + esc(prio || 'PRIORIDAD SIN DATO') + '</span>' +
        '<span class="chip">' + esc(a.estatus) + '</span>' +
      '</div>' +
      '<dl>' +
        '<dt>Trabajo</dt><dd>' + (a.caso ? esc(a.caso) : sd) + '</dd>' +
        '<dt>Local</dt><dd>' + esc(a.local || a.centro_coste_sap) + ' · ' + esc(a.local_nombre || '') + '</dd>' +
        '<dt>Notificado</dt><dd>' + (a.fecha_notificacion ? esc(fechaCorta(a.fecha_notificacion)) : sd) + '</dd>' +
        '<dt>Comprometido</dt><dd>' + (a.fecha_estimada ? esc(fechaCorta(a.fecha_estimada)) : sd) + '</dd>' +
        '<dt>Activo</dt><dd>' + (a.equipo_denominacion ? esc(limpiarTipo(a.equipo_denominacion)) : sd) + '</dd>' +
      '</dl>' +
      (a.descripcion_trabajo
        ? '<div class="cita">“' + esc(a.descripcion_trabajo) + '”</div>'
        : '<div class="cita">El pedido en palabras de KFC llega en el correo de SAP; el export actual no lo trae.</div>');
    f.hidden = false;
  }

  /* ---------- Local: derivar zona, cadena y correos ---------- */
  function alCambiarLocal() {
    var cod = $('#local').value;
    var l = localesPorCodigo[cod];
    var chips = $('#chipsLocal');
    if (!l) {
      chips.hidden = true;
      $('#correolocal').value = ''; $('#correojefeop').value = '';
      refrescarEquipos();
      return;
    }
    chips.hidden = false;
    $('#chipZona').textContent = l.zona;
    $('#chipCadena').textContent = l.cadena;
    $('#chipClienteWrap').hidden = false;
    $('#chipCliente').textContent = (l.cadena === 'KFC') ? 'GRUPO KFC' : l.cadena;

    $('#correolocal').value = l.correo_local || '';
    $('#correojefeop').value = l.correo_jefe_op || '';
    var msg = $('#msgCorreoLocal');
    if (!l.correo_local) {
      msg.textContent = 'Este local no tiene correo en el maestro — hay que conseguirlo.';
      msg.style.color = '#b45309';
      $('#correolocal').readOnly = false;
      $('#correolocal').placeholder = 'falta en el maestro';
    } else if (/servicioalcliente@industec\.me/i.test(l.correo_local)) {
      msg.textContent = 'Es el buzón general de INDUSTEC, no el del restaurante. Falta el correo real del local.';
      msg.style.color = '#b45309';
      $('#correolocal').readOnly = true;
    } else {
      msg.textContent = 'Se toma del maestro.';
      msg.style.color = '';
      $('#correolocal').readOnly = true;
    }
    refrescarEquipos();
  }

  /* ---------- Técnicos acompañantes ---------- */
  var nTec = 0;
  function filaTecnico() {
    var i = nTec++;
    var wrap = document.createElement('div');
    wrap.className = 'bloque';
    wrap.dataset.tec = i;
    wrap.innerHTML =
      '<div class="bloque-tit"><span>Acompañante</span>' +
      '<button type="button" class="btn danger" data-quitar-tec="' + i + '">Quitar</button></div>' +
      '<select class="tec-sel" data-tec-sel="' + i + '"><option value="">Elige al técnico…</option>' +
      opcionesTecnicos() + '</select>';
    $('#tecnicos').appendChild(wrap);
    wrap.querySelector('[data-quitar-tec]').addEventListener('click', function () { wrap.remove(); });
  }
  function opcionesTecnicos() {
    return CAT.tecnicos.slice().sort(function (a, b) { return a.nombre.localeCompare(b.nombre); })
      .map(function (t) {
        return '<option value="' + t.id + '">' + esc(t.nombre) + ' · ' + esc(t.tipo) + ' (' + esc(t.zona) + ')</option>';
      }).join('');
  }

  /* ---------- Equipos (bloque repetible, mínimo 1) ---------- */
  var nEq = 0;
  function bloqueEquipo() {
    var i = nEq++;
    var wrap = document.createElement('div');
    wrap.className = 'bloque';
    wrap.dataset.eq = i;
    wrap.innerHTML =
      '<div class="bloque-tit"><span>Equipo #' + (i + 1) + '</span>' +
      '<button type="button" class="btn danger" data-quitar-eq="' + i + '">Quitar</button></div>' +
      '<label>Equipo</label>' +
      '<select class="eq-sel" data-eq-sel="' + i + '"><option value="">Elige el local primero…</option></select>' +
      '<div class="grid g3" style="margin-top:8px">' +
      '  <div><label>Marca</label><input type="text" data-eq-marca="' + i + '"></div>' +
      '  <div><label>Modelo</label><input type="text" data-eq-modelo="' + i + '"></div>' +
      '  <div><label>Serie</label><input type="text" data-eq-serie="' + i + '"></div>' +
      '</div>' +
      '<div class="grid g2" style="margin-top:8px">' +
      '  <div><label>Código de activo fijo</label><input type="text" data-eq-cod="' + i + '" readonly placeholder="viene con el equipo"></div>' +
      '  <div><label>Estado del equipo</label>' +
      '    <div class="seg" data-eq-estado-seg="' + i + '">' +
      '      <button type="button" data-v="Operativo">Operativo</button>' +
      '      <button type="button" data-v="Deshabilitado">Deshabilitado</button>' +
      '    </div>' +
      '  </div>' +
      '</div>' +
      '<label style="margin-top:8px">Observaciones del equipo</label>' +
      '<textarea data-eq-obs="' + i + '" placeholder="Opcional"></textarea>';
    $('#equipos').appendChild(wrap);

    wrap.querySelector('[data-quitar-eq]').addEventListener('click', function () {
      if ($$('#equipos .bloque').length <= 1) { alert('Toda orden interviene al menos un equipo.'); return; }
      wrap.remove();
      $$('#equipos .bloque').forEach(function (b, k) {
        b.querySelector('.bloque-tit span').textContent = 'Equipo #' + (k + 1);
      });
    });
    wrap.querySelector('[data-eq-estado-seg]').addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return;
      $$('button', this).forEach(function (x) { x.classList.remove('on'); });
      b.classList.add('on');
    });
    var selEq = wrap.querySelector('.eq-sel');
    selEq.addEventListener('change', function () {
      var opt = selEq.selectedOptions[0];
      wrap.querySelector('[data-eq-cod="' + i + '"]').value = (opt && opt.dataset.cod) || '';
    });
    poblarEquipoSelect(selEq);
  }

  function poblarEquipoSelect(sel) {
    var cod = $('#local').value;
    sel.innerHTML = '';
    if (!localesPorCodigo[cod]) { sel.innerHTML = '<option value="">Elige el local primero…</option>'; return; }
    var activos = (CAT.equipos && CAT.equipos[cod]) || [];
    if (activos.length) {
      sel.appendChild(new Option('Elige el equipo…', ''));
      var porArea = {};
      activos.forEach(function (e) { (porArea[e.area || 'Otros'] = porArea[e.area || 'Otros'] || []).push(e); });
      Object.keys(porArea).sort().forEach(function (area) {
        var og = document.createElement('optgroup');
        og.label = area;
        porArea[area].forEach(function (e) {
          var o = document.createElement('option');
          o.value = e.equipo_sap;
          o.dataset.cod = e.codigo_activo || '';
          o.dataset.tipo = e.tipo || '';
          o.textContent = limpiarTipo(e.tipo) + (e.codigo_activo ? ' · AF ' + e.codigo_activo : '') + ' · SAP ' + e.equipo_sap;
          og.appendChild(o);
        });
        sel.appendChild(og);
      });
      // Si la orden de SAP dice qué activo es, se preselecciona: es el dato del
      // cliente, y evita que se elija la freidora equivocada entre 4 iguales.
      if (avisoElegido && avisoElegido.equipo_sap) {
        var hay = Array.prototype.some.call(sel.options, function (o) { return o.value === avisoElegido.equipo_sap; });
        if (hay) { sel.value = avisoElegido.equipo_sap; sel.dispatchEvent(new Event('change')); }
      }
    } else {
      sel.appendChild(new Option('Este local no tiene activos en SAP — elige el tipo…', ''));
      CAT.tipos.slice().sort().forEach(function (t) {
        var o = new Option(limpiarTipo(t), 'TIPO:' + t);
        o.dataset.tipo = t;
        sel.appendChild(o);
      });
    }
  }

  function refrescarEquipos() {
    $$('#equipos .eq-sel').forEach(poblarEquipoSelect);
  }

  /* ---------- Controles segmentados ---------- */
  function segmentado(cont, alElegir) {
    cont.addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return;
      $$('button', cont).forEach(function (x) { x.classList.remove('on'); });
      b.classList.add('on');
      alElegir(b.dataset.v);
    });
  }
  function fijarTipo(v) {
    $('#tipo').value = v;
    $$('#segTipo button').forEach(function (b) { b.classList.toggle('on', b.dataset.v === v); });
    aplicarTipo(v);
  }
  function aplicarTipo(v) {
    $('#wrapDia').hidden = (v !== 'PREVENTIVO');
    $('#subcabecera').textContent = (v === 'PREVENTIVO')
      ? 'Preventivo — el bloque de equipo se repite, hasta 7' : 'Correctivo';
    $('#txtAtiempo').textContent = (v === 'PREVENTIVO')
      ? 'Sus equipos quedaron operando con normalidad:' : 'Su requerimiento fue atendido a tiempo:';
  }

  function fijarOrigen(v) {
    $('#origen').value = v;
    var asignada = (v === 'ASIGNADA');
    $('#wrapAsignada').hidden = !asignada;
    $('#wrapSinAsignar').hidden = asignada;
    if (asignada) {
      refrescarAvisos();
    } else {
      comboAviso.limpiar();
      alSoltarAviso();
    }
  }

  /* ---------- Firma ---------- */
  function initFirma() {
    var canvas = $('#signature'), ctx = canvas.getContext('2d');
    var drawing = false, strokes = [], current = [];
    function redraw() {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.lineJoin = 'round'; ctx.lineCap = 'round'; ctx.strokeStyle = '#111827'; ctx.lineWidth = 2.2;
      function seg(s) { if (!s || s.length < 2) return; ctx.beginPath(); ctx.moveTo(s[0].x, s[0].y); for (var i = 1; i < s.length; i++) ctx.lineTo(s[i].x, s[i].y); ctx.stroke(); }
      strokes.forEach(seg); seg(current);
    }
    function resize() {
      var dpr = Math.max(1, window.devicePixelRatio || 1);
      var w = canvas.clientWidth || 600, h = canvas.clientHeight || 180;
      canvas.width = Math.floor(w * dpr); canvas.height = Math.floor(h * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0); redraw();
    }
    function pos(e) { var r = canvas.getBoundingClientRect(), t = e.touches ? e.touches[0] : e; return { x: t.clientX - r.left, y: t.clientY - r.top }; }
    function start(e) { drawing = true; current = [pos(e)]; e.preventDefault(); }
    function move(e) { if (!drawing) return; current.push(pos(e)); redraw(); e.preventDefault(); }
    function end() { if (!drawing) return; drawing = false; if (current.length) { strokes.push(current); current = []; } }
    canvas.addEventListener('mousedown', start); canvas.addEventListener('mousemove', move); window.addEventListener('mouseup', end);
    canvas.addEventListener('touchstart', start, { passive: false }); canvas.addEventListener('touchmove', move, { passive: false }); canvas.addEventListener('touchend', end);
    $('#clear').addEventListener('click', function () { strokes = []; current = []; redraw(); });
    $('#undo').addEventListener('click', function () { strokes.pop(); redraw(); });
    window.addEventListener('resize', resize); resize();
    return { tieneTinta: function () { return strokes.some(function (s) { return s.length > 1; }); } };
  }

  /* ---------- Fotos ---------- */
  var fotos = [];
  function initFotos() {
    function render() {
      var lista = $('#fileList');
      if (!fotos.length) { lista.innerHTML = '<div class="small">Sin fotos</div>'; return; }
      lista.innerHTML = '';
      fotos.forEach(function (f, idx) {
        var row = document.createElement('div');
        row.className = 'slot';
        row.innerHTML = '<span class="name">' + esc(f.name || ('foto ' + (idx + 1))) + '</span>' +
          '<span class="actions"><button type="button">Quitar</button></span>';
        row.querySelector('button').addEventListener('click', function () { fotos.splice(idx, 1); render(); });
        lista.appendChild(row);
      });
    }
    function agregar(files) {
      Array.prototype.forEach.call(files, function (f) {
        if (fotos.length >= 8) { alert('Máximo 8 fotos.'); return; }
        fotos.push(f);
      });
      render();
    }
    $('#fileCamera').addEventListener('change', function (e) { agregar(e.target.files); });
    $('#fileGallery').addEventListener('change', function (e) { agregar(e.target.files); });
  }

  /* ---------- Satisfacción ---------- */
  function initRating() {
    var cont = $('#rating'), hidden = $('#satisfaccion');
    for (var i = 1; i <= 10; i++) {
      var w = document.createElement('div');
      w.className = 'star'; w.dataset.v = i;
      w.innerHTML = '<span>' + i + '</span><span>☆</span>';
      cont.appendChild(w);
    }
    cont.addEventListener('click', function (e) {
      var w = e.target.closest('.star'); if (!w) return;
      var v = +w.dataset.v;
      hidden.value = v;
      $$('.star', cont).forEach(function (x) {
        x.querySelector('span:last-child').textContent = (+x.dataset.v <= v) ? '★' : '☆';
      });
    });
  }

  /* ---------- Reunir la orden ---------- */
  function reunirOrden() {
    var tipo = $('#tipo').value;
    var cod = $('#local').value;
    var l = localesPorCodigo[cod] || null;
    var sinAsignar = ($('#origen').value === 'SIN_ASIGNAR');

    var equipos = $$('#equipos .bloque').map(function (b) {
      var sel = b.querySelector('.eq-sel');
      var opt = sel.selectedOptions[0];
      var val = sel.value;
      if (val && val.indexOf('TIPO:') === 0) return { tipo: val.slice(5) };
      if (val) return { equipo_sap: val, tipo: (opt && opt.dataset.tipo) || '' };
      return {};
    });

    var resp = tecnicoSesion();
    var nombres = resp ? [resp.nombre] : [];
    $$('#tecnicos .tec-sel').forEach(function (s) {
      var t = CAT.tecnicos.filter(function (x) { return String(x.id) === s.value; })[0];
      if (t && nombres.indexOf(t.nombre) === -1) nombres.push(t.nombre);
    });

    var usoRep = $('#uso_repuesto').value === '1';

    return {
      tipo: tipo,
      origen: sinAsignar ? 'SIN_ASIGNAR' : 'ASIGNADA',
      local: cod || null,
      zona: l ? l.zona : null,
      aviso: sinAsignar ? null : ($('#aviso').value || null),
      sin_aviso: sinAsignar,
      motivo_sin_aviso: sinAsignar ? ($('#motivo_sin_aviso').value.trim() || null) : null,
      dia_intervencion: tipo === 'PREVENTIVO' ? ($('#dia_intervencion').value || null) : null,
      equipos: equipos,
      uso_repuesto: usoRep,
      repuestos: usoRep ? $('#repuestos').value.trim() : null,
      fecha_atencion: $('#fecha_atencion').value || null,
      inicio: $('#inicio').value || null,
      fin: $('#fin').value || null,
      actividades: $('#actividades').value.trim(),
      firma_presente: firma.tieneTinta(),
      fotos_cantidad: fotos.length,
      tecnico: nombres.join(', '),
      _hoy: isoLocal(HOY)
    };
  }

  /* Reglas propias de la captura, encima de las 30 del formato único. */
  function reglasDeCaptura(o) {
    var extra = [];
    if (!tecnicoSesion()) {
      extra.push({ campo: 'tecnico', severidad: 'BLOQUEA', mensaje: 'elige quién llena la orden' });
    }
    if (o.origen === 'ASIGNADA' && !o.aviso) {
      extra.push({ campo: 'aviso', severidad: 'BLOQUEA',
        mensaje: 'elige la orden asignada, o cambia a “Sin orden asignada”' });
    }
    if (o.origen === 'SIN_ASIGNAR' && !o.motivo_sin_aviso) {
      extra.push({ campo: 'aviso', severidad: 'BLOQUEA',
        mensaje: 'decí por qué no había orden asignada; la administración lo necesita para regularizarla' });
    }
    if (o.origen === 'SIN_ASIGNAR' && o.uso_repuesto) {
      // Regla del cliente: sin aviso no se piden repuestos hasta regularizar.
      extra.push({ campo: 'repuestos', severidad: 'ADVIERTE',
        mensaje: 'sin aviso SAP no se puede tramitar el repuesto hasta que la administración regularice la orden' });
    }
    return extra;
  }

  function nombreCanonico(o) {
    var l = localesPorCodigo[o.local];
    if (!l) return null;
    var partes = ['OT', 'NNNN', l.codigo];
    if (o.aviso && /^\d{8}$/.test(o.aviso)) partes.push(o.aviso);
    if (o.tipo === 'PREVENTIVO' && o.dia_intervencion) partes.push('D' + o.dia_intervencion);
    partes.push(l.zona);
    return partes.join('-') + '.pdf';
  }

  /* ---------- Panel de validación ---------- */
  var ETIQUETAS = {
    local: 'Local', zona: 'Zona', aviso: 'Orden / aviso SAP', tipo: 'Tipo',
    dia_intervencion: 'Día', equipos: 'Equipos', uso_repuesto: 'Repuesto',
    repuestos: 'Repuestos', fecha_atencion: 'Fecha', inicio: 'Tiempos',
    fin: 'Hora de fin', actividades: 'Actividades', firma: 'Firma',
    fotos: 'Fotos', tecnico: 'Técnico'
  };
  function pintarValidacion(hallazgos) {
    var panel = $('#panelValidacion');
    panel.className = 'validacion';
    if (!hallazgos.length) {
      panel.classList.add('ok');
      panel.innerHTML = '<b>Todo en orden.</b> La orden puede enviarse.';
      return { bloquea: false };
    }
    var bloq = hallazgos.filter(function (h) { return h.severidad === 'BLOQUEA'; });
    var adv = hallazgos.filter(function (h) { return h.severidad !== 'BLOQUEA'; });
    function fila(h) { return '<li><b>' + esc(ETIQUETAS[h.campo] || h.campo) + ':</b> ' + esc(h.mensaje) + '</li>'; }
    var html = '';
    if (bloq.length) {
      panel.classList.add('bloquea');
      html += '<b>Falta corregir antes de enviar:</b><ul>' + bloq.map(fila).join('') + '</ul>';
    }
    if (adv.length) {
      if (!bloq.length) panel.classList.add('advierte');
      html += (bloq.length ? '<hr style="border:0;border-top:1px solid rgba(0,0,0,.12);margin:10px 0">' : '') +
        '<b>Para revisar (no impide enviar):</b><ul>' + adv.map(fila).join('') + '</ul>';
    }
    panel.innerHTML = html;
    return { bloquea: bloq.length > 0 };
  }

  /* ---------- Envío (v1: solo resumen) ---------- */
  function alEnviar(e) {
    e.preventDefault();
    var o = reunirOrden();
    var hallazgos = validar(o, 'CAPTURA').concat(reglasDeCaptura(o));
    var res = pintarValidacion(hallazgos);
    $('#panelValidacion').scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (res.bloquea) return;
    if (hallazgos.length && !confirm('Hay ' + hallazgos.length + ' aviso(s) para revisar. ¿Enviar igual?')) return;

    var resumen = {
      nombre_canonico: nombreCanonico(o),
      correlativo: 'lo reserva el servidor con el secuencial de la zona ' + (o.zona || '?'),
      responsable: (tecnicoSesion() || {}).nombre || null,
      correos: { local: $('#correolocal').value, jefe_op: $('#correojefeop').value },
      tarea_administracion: o.sin_aviso
        ? 'REGULARIZAR: abrir el caso en SAP o pedírselo a Grupo KFC. Bloquea repuestos y cierre.'
        : null,
      orden: o
    };
    $('#rNombre').textContent = resumen.nombre_canonico || '(sin local, no se puede componer el nombre)';
    $('#rTarea').hidden = !o.sin_aviso;
    $('#rResumen').textContent = JSON.stringify(resumen, null, 2);
    $('#otForm').hidden = true;
    $('#resultado').hidden = false;
    $('#resultado').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function sincronizarInicioFin() {
    var f = $('#fecha_atencion').value;
    if (!f) return;
    ['#inicio', '#fin'].forEach(function (sel) {
      var el = $(sel);
      if (!el.value) el.value = f + 'T08:00';
      el.max = f + 'T23:59';
    });
  }

  function normalizar(cat) {
    function arr(x) { return Array.isArray(x) ? x : (x && x.datos) ? x.datos : []; }
    return {
      locales: arr(cat.locales),
      tecnicos: arr(cat.tecnicos),
      tipos: arr(cat.tipos).map(function (t) { return (typeof t === 'string') ? t : t.tipo; }),
      equipos: (cat.equipos && cat.equipos.datos) ? cat.equipos.datos : (cat.equipos || {})
    };
  }

  /* ---------- Arranque ---------- */
  document.addEventListener('DOMContentLoaded', function () {
    $('#fecha_atencion').value = isoLocal(HOY);
    $('#fecha_atencion').max = isoLocal(HOY);
    sincronizarInicioFin();

    segmentado($('#segTipo'), function (v) { $('#tipo').value = v; aplicarTipo(v); $('#tipoDerivado').hidden = true; });
    segmentado($('#segOrigen'), fijarOrigen);
    segmentado($('#segRepuesto'), function (v) {
      var si = (v === 'si');
      $('#uso_repuesto').value = si ? '1' : '0';
      $('#wrapRepuestos').hidden = !si;
    });
    segmentado($('#segEstado'), function (v) { $('#estado_ot').value = v; });
    segmentado($('#segAtiempo'), function (v) { $('#atiempo').value = v; });

    $('#fecha_atencion').addEventListener('change', sincronizarInicioFin);
    $('#addTecnico').addEventListener('click', filaTecnico);
    $('#addEquipo').addEventListener('click', function () {
      if ($$('#equipos .bloque').length >= 7) { alert('Máximo 7 equipos por orden. Para el resto, una nueva OT.'); return; }
      bloqueEquipo();
    });
    $('#otForm').addEventListener('submit', alEnviar);
    $('#rNueva').addEventListener('click', function () { location.reload(); });

    firma = initFirma();
    initFotos();
    initRating();

    cargarCatalogo().then(function (cat) {
      CAT = normalizar(cat);
      AVISOS = cat.avisos || { datos: [], cobertura: null };
      CAT.locales.forEach(function (l) { localesPorCodigo[l.codigo] = l; });
      validar = Reglas.crear({
        locales: CAT.locales, equipos: CAT.equipos, tipos: CAT.tipos, tecnicos: CAT.tecnicos
      });

      $('#tecSesion').innerHTML = '<option value="">Elige tu nombre…</option>' + opcionesTecnicos();
      $('#tecSesion').addEventListener('change', function () {
        comboAviso.limpiar();
        alSoltarAviso();
        refrescarAvisos();
      });

      initComboLocal();
      initComboAviso();
      bloqueEquipo();
      refrescarAvisos();

      var pedido = new URLSearchParams(location.search).get('local') || window.PRESELECT;
      if (pedido && localesPorCodigo[pedido]) {
        fijarOrigen('SIN_ASIGNAR');
        $$('#segOrigen button').forEach(function (b) { b.classList.toggle('on', b.dataset.v === 'SIN_ASIGNAR'); });
        comboLocal.elegirPorClave(pedido);
      }

      $('#pendientes').textContent =
        'Catálogo: ' + CAT.locales.length + ' locales · ' +
        Object.keys(CAT.equipos).length + ' con activos · ' +
        CAT.tipos.length + ' tipos · ' + CAT.tecnicos.length + ' técnicos · ' +
        AVISOS.datos.length + ' órdenes abiertas.';
    }).catch(function (err) {
      $('#pendientes').textContent = 'No se pudo cargar el catálogo: ' + err.message;
      $('#pendientes').style.color = '#b91c1c';
    });
  });

})();
