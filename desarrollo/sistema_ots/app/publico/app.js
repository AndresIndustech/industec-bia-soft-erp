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

   Guarda la orden en el celular (cola.js) y la entrega a envio.php, que la
   valida, reserva el número de la zona, genera el PDF con las fotos y la
   firma, y encola el correo (la 008). El recibo de esta pantalla (H-04,
   DOC-04) espera el evento `orden-emitida` que dispara cola.js con el número
   real y el estado del correo; mientras no llega, se dice lo que hay: que la
   orden ya está guardada y va en camino.
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
  var equipoAmbiguo = 0;       // H-09: cuántos activos iguales no se pudieron distinguir
  var firma;
  var ultimoUuid = null;       // H-04: qué fila de la cola es "la que se acaba de mandar"
  var reintentarUuid = null;   // H-07: viene de ?reintentar=<uuid>

  /* ---------- Carga ---------- */
  function cargarCatalogo() {
    if (window.CATALOGOS) return Promise.resolve(window.CATALOGOS);
    return fetch('catalogos.php', { credentials: 'same-origin' }).then(function (r) {
      /* 401 = falta la sesion. Se manda al ingreso, y de vuelta aqui despues.
         Solo con senal: sin cobertura la copia guardada sirve y hay que dejarlo
         trabajar, que es el punto entero del modo sin conexion. */
      if (r.status === 401 && navigator.onLine) {
        // H-23: si esto abrió con `?aviso=…` (el caso venía precargado desde
        // la bandeja), volver sin esos parámetros perdía la precarga: el
        // técnico volvía a entrar y tenía que buscar el caso a mano.
        location.href = 'login.php?r=' + encodeURIComponent('index.html' + location.search);
        return new Promise(function () {});   // se corta la cadena: ya nos vamos
      }
      if (r.status === 401) {
        throw new Error('Entra al sistema cuando tengas señal para actualizar tus datos.');
      }
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
      vacio: 'No tienes casos asignados. Si atendiste algo sin caso, elige «Sin orden asignada».',
      clave: function (a) { return a.aviso; },
      etiqueta: function (a) { return a.aviso + ' · ' + (a.local || a.centro_coste_sap || 'sin dato en el catálogo') + ' · ' + (a.caso || 'sin tipo'); },
      buscarEn: function (a) {
        return [a.aviso, a.local, a.local_nombre, a.caso, a.cadena, a.zona,
                a.equipo_denominacion, a.centro_coste_sap].join(' ');
      },
      grupo: function (a) { return a.caso || 'Sin tipo de trabajo'; },
      fila: function (a) {
        return '<span class="cod">' + esc(a.aviso) + '</span> · ' +
               esc(a.local || a.centro_coste_sap || 'sin dato en el catálogo') + ' ' +
               '<span class="cad">' + esc(a.local_nombre || '') + '</span>' +
               (a.fecha_notificacion ? ' <span class="cad">— ' + esc(fechaCorta(a.fecha_notificacion)) + '</span>' : '');
      },
      alElegir: alElegirAviso,
      alLimpiar: alSoltarAviso
    });
  }

  /* Los avisos que se le ofrecen: los que el servidor ya recortó a su alcance
     (catalogos.php aplica Casos::enAlcance; al técnico, sus casos abiertos según
     la base, con Casos::delTecnico, estén o no en el catálogo del buzón).
     Aquí no se vuelve a filtrar por zona: un caso de otra zona que le asignaron
     para apoyar también es suyo, y el filtro viejo lo escondía. */
  function refrescarAvisos() {
    comboAviso.cargar(AVISOS.datos);
    pintarCobertura();
  }

  /* El texto de debajo del combo. Va aparte porque la identidad (yo.php) puede
     llegar después que el catálogo, y recargar el combo borraría el caso que ya
     se precargó desde la ficha. */
  function pintarCobertura() {
    var nota = $('#avisoCobertura');
    if (!nota) return;
    var n = AVISOS.datos.length;
    var cob = AVISOS.cobertura || {};
    var partes = [(YO && YO.rol === 'TECNICO')
      ? (n === 1 ? 'Tienes 1 caso asignado.' : 'Tienes ' + n + ' casos asignados.')
      : (n === 1 ? '1 caso en tu alcance.' : n + ' casos en tu alcance.')];
    if (cob.advertencia) partes.push(cob.advertencia);
    else if (cob.hasta) partes.push('Catálogo SAP al corte del ' + fechaCorta(cob.hasta) + ', no en vivo.');
    nota.textContent = partes.join(' ');
  }

  /* Quien tiene la sesion abierta. Lo trae `yo.php` y el trabajador de
     servicio lo guarda, asi que sigue estando sin senal. */
  var YO = null;

  /**
   * Quien emite la orden.
   *
   * ANTES esto leia un desplegable con los 19 tecnicos, y el tecnico tenia que
   * buscarse en la lista. Dos problemas en uno: un gesto de mas en cada orden
   * —al empezar, cuando lo que quiere es resolver y salir del local— y un
   * agujero de trazabilidad, porque una lista con los 19 nombres permite
   * firmar como cualquiera de ellos.
   *
   * AHORA sale de la sesion. Se busca en el catalogo por nombre para heredar
   * la zona y el id del padron; si no calza, se usa lo de la sesion y la
   * pantalla lo dice en vez de dejar el campo en blanco (I-7).
   *
   * `envio.php` vuelve a tomar la identidad de la sesion al recibir y descarta
   * lo que venga aqui, asi que editar el HTML no cambia quien firma.
   */
  function tecnicoSesion() {
    if (!YO) return null;
    // H-05: si el catálogo no cargó, `CAT` sigue en `null` y esto reventaba
    // antes de llegar a `n.textContent = YO.nombre` -- #yoNombre se quedaba
    // en «Cargando…» para siempre, aunque `yo.php` sí hubiera contestado.
    var t = CAT ? CAT.tecnicos.filter(function (x) { return mismoNombre(x.nombre, YO.nombre); })[0] : null;
    return {
      id: t ? t.id : YO.id,
      nombre: YO.nombre,
      zona: YO.zona || (t ? t.zona : null),
      del_padron: !!t
    };
  }

  /* La nomina guarda el nombre legal completo (ANTHONY MEDARDO JUMBO ROJANO) y
     el usuario del sistema puede tener una forma mas corta. Comparar cadenas
     enteras marcaba como desconocido a casi todo el mundo, que es falso: es la
     misma persona. Se comparan los apellidos y el primer nombre. */
  function mismoNombre(a, b) {
    function tk(x) {
      return String(x || '').toUpperCase()
        .normalize('NFD').replace(/[̀-ͯ]/g, '')
        .split(/[^A-Z]+/).filter(function (p) { return p.length > 2; });
    }
    var ta = tk(a), tb = tk(b);
    if (!ta.length || !tb.length) return false;
    var comunes = ta.filter(function (p) { return tb.indexOf(p) !== -1; });
    return comunes.length >= Math.min(2, Math.min(ta.length, tb.length));
  }

  function pintarYo() {
    var n = $('#yoNombre'), z = $('#yoZona'), nota = $('#yoNota');
    if (!n) return;
    if (!YO) {
      n.textContent = 'No se pudo leer tu sesión';
      nota.textContent = 'Vuelve a entrar al sistema. La orden tiene que quedar firmada '
                       + 'con tu nombre y sin eso no se puede emitir.';
      nota.style.color = '#b91c1c';
      return;
    }
    var t = tecnicoSesion();
    n.textContent = YO.nombre;
    if (t && t.zona) { z.textContent = 'Zona ' + t.zona; z.hidden = false; }
    $('#tecSesion').value = t ? String(t.id) : '';
    nota.textContent = (t && t.del_padron)
      ? 'Sale de tu sesión: no se elige y queda como firma de la orden.'
      : 'Sale de tu sesión. Tu nombre no está en el padrón de técnicos vigentes: el sistema '
      + 'no va a aceptar la orden hasta que la administración te agregue.';
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
      // Un caso que no está en el catálogo del buzón solo trae su número (T2.13.3).
      $('#localNota').textContent = a.sin_catalogo
        ? 'Este caso no está en el listado del buzón: elige el local a mano.'
        : 'El centro de coste ' + (a.centro_coste_sap || '?') +
          ' de esta orden no resuelve contra el maestro. Elige el local a mano.';
    }
    // El tipo se deriva del caso de SAP, y se puede corregir.
    var caso = baja(a.caso || '');
    var tipo = caso.indexOf('preventivo') !== -1 ? 'PREVENTIVO' : 'CORRECTIVO';
    fijarTipo(tipo);
    $('#tipoDerivado').hidden = false;
    $('#tipoDerivado').textContent = 'Derivado del caso “' + (a.caso || 'sin tipo') + '” de la orden. Corrígelo si no corresponde.';
    // H-09: primero se resuelve el equipo (fija `equipoAmbiguo` si hay varios
    // activos iguales), y recién con eso se pinta la ficha, que es donde se
    // avisa si no se pudo identificar entre ellos.
    refrescarEquipos();
    pintarFicha(a);
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
        '<dt>Local</dt><dd>' + (a.local || a.centro_coste_sap
          ? esc(a.local || a.centro_coste_sap) + ' · ' + esc(a.local_nombre || '') : sd) + '</dd>' +
        '<dt>Notificado</dt><dd>' + (a.fecha_notificacion ? esc(fechaCorta(a.fecha_notificacion)) : sd) + '</dd>' +
        '<dt>Comprometido</dt><dd>' + (a.fecha_estimada ? esc(fechaCorta(a.fecha_estimada)) : sd) + '</dd>' +
        '<dt>Activo</dt><dd>' + (a.equipo_denominacion ? esc(limpiarTipo(a.equipo_denominacion)) : sd) + '</dd>' +
      '</dl>' +
      (a.descripcion_trabajo
        ? '<div class="cita">“' + esc(a.descripcion_trabajo) + '”</div>'
        : '<div class="cita">El pedido en palabras de KFC llega en el correo de SAP; el export actual no lo trae.</div>') +
      // H-09: si el activo del aviso calzó con más de un equipo del local, no
      // se adivina cuál -- se dice, y el técnico lo elige él mismo abajo.
      (equipoAmbiguo > 1
        ? '<div class="derivado" style="margin-top:8px">No se pudo identificar el activo entre '
          + equipoAmbiguo + ' equipos iguales del local: elígelo en "Equipos intervenidos".</div>'
        : '');
    f.hidden = false;
  }

  /* H-08: los administradores ya ingresados de este local (`CAT.admins`,
     de `locales_admin`, más reciente primero). Un `<datalist>` no obliga a
     elegir de la lista -- el técnico sigue pudiendo escribir uno nuevo-- pero
     evita reteclear el mismo nombre en cada orden. */
  function poblarAdminsDatalist(cod) {
    var dl = $('#adminsLista');
    if (!dl) return;
    var nombres = (CAT.admins && CAT.admins[cod]) || [];
    dl.innerHTML = nombres.map(function (n) { return '<option value="' + esc(n) + '">'; }).join('');
    var admin = $('#admin');
    if (nombres.length && !admin.value) { admin.value = nombres[0]; }
  }

  /* ---------- Local: derivar zona, cadena y correos ---------- */
  function alCambiarLocal() {
    var cod = $('#local').value;
    var l = localesPorCodigo[cod];
    var chips = $('#chipsLocal');
    poblarAdminsDatalist(cod);
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
    // H-10/D8: cada bloque nace con su propio uuid, lo use o no. Solo hace
    // falta cuando el técnico elige "Equipo nuevo…", pero generarlo aquí
    // evita coordinarlo con el momento del cambio de <select>.
    wrap.dataset.eqUuid = (window.Cola && Cola.uuid) ? Cola.uuid() : String(Date.now()) + '-' + i;
    wrap.innerHTML =
      '<div class="bloque-tit"><span>Equipo #' + (i + 1) + '</span>' +
      '<button type="button" class="btn danger" data-quitar-eq="' + i + '">Quitar</button></div>' +
      '<label>Equipo</label>' +
      '<select class="eq-sel" data-eq-sel="' + i + '"><option value="">Elige el local primero…</option></select>' +
      '<div class="nota-regular" data-eq-nuevo-nota="' + i + '" hidden style="margin-top:8px">' +
      '<b>Se registra como equipo nuevo.</b> Queda visible para todas las zonas y la ' +
      'administración lo revisa antes de sumarlo al catálogo del local.</div>' +
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
      '<div data-eq-area-wrap="' + i + '" hidden style="margin-top:8px">' +
      '  <label>Área (opcional)</label><input type="text" data-eq-area="' + i + '" placeholder="cocina caliente, bodega…">' +
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
      sincronizarFallas();
    });
    wrap.querySelector('[data-eq-estado-seg]').addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return;
      $$('button', this).forEach(function (x) { x.classList.remove('on'); });
      b.classList.add('on');
      sincronizarFallas();
    });
    var selEq = wrap.querySelector('.eq-sel');
    selEq.addEventListener('change', function () {
      var opt = selEq.selectedOptions[0];
      var esNuevo = selEq.value.indexOf('TIPO:') === 0;
      var cod = wrap.querySelector('[data-eq-cod="' + i + '"]');
      cod.value = esNuevo ? '' : ((opt && opt.dataset.cod) || '');
      cod.readOnly = !esNuevo;
      cod.placeholder = esNuevo ? 'si lo tiene, opcional' : 'viene con el equipo';
      wrap.querySelector('[data-eq-area-wrap="' + i + '"]').hidden = !esNuevo;
      wrap.querySelector('[data-eq-nuevo-nota="' + i + '"]').hidden = !esNuevo;
      sincronizarFallas();
    });
    poblarEquipoSelect(selEq);
  }

  function poblarEquipoSelect(sel) {
    var cod = $('#local').value;
    sel.innerHTML = '';
    if (!localesPorCodigo[cod]) { sel.innerHTML = '<option value="">Elige el local primero…</option>'; return; }
    var todos = (CAT.equipos && CAT.equipos[cod]) || [];
    var activos = todos.filter(function (e) { return !e.propuesto; });
    var propuestos = todos.filter(function (e) { return e.propuesto; });

    sel.appendChild(new Option(
      activos.length ? 'Elige el equipo…' : 'Este local no tiene activos en SAP — elige abajo…', ''));

    if (activos.length) {
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
    }

    // H-10/D8: los que otro técnico ya propuso para este local (Catalogo::
    // cargar() los fusiona con `propuesto:true`). Elegir uno de estos NO es
    // "equipo nuevo" -- ya está propuesto-- así que no dispara la nota ni la
    // regla EQUIPO_NUEVO_PROPUESTO otra vez.
    if (propuestos.length) {
      var ogp = document.createElement('optgroup');
      ogp.label = 'Propuestos por otros técnicos (pendientes de aprobar)';
      propuestos.forEach(function (e) {
        var o = document.createElement('option');
        o.value = e.equipo_sap;
        o.dataset.cod = e.codigo_activo || '';
        o.dataset.tipo = e.tipo || '';
        o.textContent = limpiarTipo(e.tipo) + (e.codigo_activo ? ' · AF ' + e.codigo_activo : '') + ' · propuesto';
        ogp.appendChild(o);
      });
      sel.appendChild(ogp);
    }

    var ogn = document.createElement('optgroup');
    ogn.label = 'Equipo nuevo / no está en la lista';
    (CAT.tipos || []).slice().sort().forEach(function (t) {
      var o = new Option(limpiarTipo(t), 'TIPO:' + t);
      o.dataset.tipo = t;
      ogn.appendChild(o);
    });
    sel.appendChild(ogn);

    // H-09: el equipo del aviso viene preseleccionado, cruzando por
    // `equipo_sap` (si SAP lo trae) o por `codigo_activo` contra el activo
    // fijo del caso. Si hay más de un candidato -- varios activos iguales--
    // no se adivina: se deja vacío y se avisa en la ficha (equipoAmbiguo).
    if (avisoElegido) {
      var af = String(avisoElegido.equipo_denominacion || '').trim();
      var cand = Array.prototype.filter.call(sel.options, function (o) {
        return o.value && (
          (avisoElegido.equipo_sap && o.value === avisoElegido.equipo_sap) ||
          (af !== '' && o.dataset.cod && o.dataset.cod === af)
        );
      });
      if (cand.length === 1) {
        sel.value = cand[0].value;
        sel.dispatchEvent(new Event('change'));
      } else if (cand.length > 1) {
        equipoAmbiguo = cand.length;
      }
    }
  }

  function refrescarEquipos() {
    equipoAmbiguo = 0;
    $$('#equipos .eq-sel').forEach(poblarEquipoSelect);
  }

  /* =======================================================================
     Repuestos, estructurados (H-11, D9).

     Una fila por repuesto: descripción, cantidad y número de parte si se
     sabe. Se usa dos veces con el mismo molde -- en "Detalle de los
     repuestos" de la orden, y en "Qué repuesto haría falta" del equipo
     trabado-- porque son el mismo dato en dos momentos distintos: uno ya
     usado, el otro por solicitar.
     ======================================================================= */
  function crearListaPartes(contId) {
    var cont = $(contId);
    function fila(valores) {
      valores = valores || {};
      var row = document.createElement('div');
      row.className = 'grid g3 parte-row';
      row.style.marginTop = '8px';
      row.innerHTML =
        '<div><label>Repuesto</label><input type="text" class="parte-desc" list="partesLista" placeholder="resistencia 5 kW"></div>' +
        '<div><label>Cantidad</label><input type="number" class="parte-cant" min="1" value="1"></div>' +
        '<div><label>N.° de parte</label><input type="text" class="parte-num" placeholder="opcional"></div>';
      cont.appendChild(row);
      row.querySelector('.parte-desc').value = valores.descripcion || '';
      row.querySelector('.parte-cant').value = valores.cantidad || 1;
      row.querySelector('.parte-num').value = valores.numero_parte || '';
      return row;
    }
    return {
      agregar: fila,
      vaciar: function () { cont.innerHTML = ''; },
      vacia: function () { return !cont.children.length; },
      leer: function () {
        return $$('.parte-row', cont).map(function (r) {
          var d = r.querySelector('.parte-desc').value.trim();
          if (!d) return null;
          var cant = Math.max(1, +r.querySelector('.parte-cant').value || 1);
          var num = r.querySelector('.parte-num').value.trim() || null;
          // Si lo que escribió calza EXACTO con un repuesto frecuente, se
          // completa el número de parte y el código -- así el veredicto de
          // repuestos no vuelve a partir de un varchar sin estructura.
          var m = (CAT && CAT.repuestos || []).filter(function (x) { return x.descripcion === d; })[0];
          return { descripcion: d, cantidad: cant, numero_parte: num || (m ? m.numero_parte : null),
                   codigo: m ? m.codigo : null };
        }).filter(Boolean);
      }
    };
  }
  function compilarPartesTexto(items) {
    return (items || []).map(function (p) {
      return p.descripcion + (p.cantidad > 1 ? ' x' + p.cantidad : '') + (p.numero_parte ? ' (' + p.numero_parte + ')' : '');
    }).join('; ');
  }
  var listaRepuestosOrden = null;
  var listaPartesTrabado = null;

  /* =======================================================================
     "Falla encontrada" (H-11, D9): un selector por familia de equipo que
     prellena el diagnóstico y sugiere repuestos frecuentes. La familia se
     resuelve con el mismo patrón que trae `CAT.familias`, insensible a
     mayúsculas y tildes -- es el patrón que también usa el servidor, si algún
     día hace falta repetir la resolución ahí.
     ======================================================================= */
  function familiaDe(tipo) {
    var t = baja(tipo || '').toUpperCase();
    var f = (CAT && CAT.familias || []).filter(function (x) {
      try { return new RegExp(x.patron, 'i').test(t); } catch (e) { return false; }
    })[0];
    return f ? f.familia : null;
  }

  /* El tipo del equipo trabado: el bloque marcado "Deshabilitado", o el único
     equipo de la orden. Igual que `activoDelTrabado()`, pero para el tipo. */
  function tipoDelTrabado() {
    var bloques = $$('#equipos .bloque');
    var parados = bloques.filter(function (b) {
      var on = b.querySelector('[data-eq-estado-seg] button.on');
      return on && on.dataset.v === 'Deshabilitado';
    });
    var b = parados.length === 1 ? parados[0] : (bloques.length === 1 ? bloques[0] : null);
    if (!b) return null;
    var opt = b.querySelector('.eq-sel').selectedOptions[0];
    return opt ? (opt.dataset.tipo || null) : null;
  }

  function sincronizarFallas() {
    var sel = $('#pen_falla');
    if (!sel || !CAT) return;
    var familia = familiaDe(tipoDelTrabado());
    var opciones = (CAT.diagnosticos || []).filter(function (d) { return !familia || d.familia === familia; });
    sel.innerHTML = '<option value="">Elige si calza con algo conocido…</option>' +
      opciones.map(function (d) { return '<option value="' + esc(d.codigo) + '">' + esc(d.titulo) + '</option>'; }).join('');
  }

  /* Al elegir una falla: rellena el diagnóstico (editable después) y sugiere
     los repuestos frecuentes que suelen ir con ella. No pisa filas que el
     técnico ya haya escrito -- se agregan detrás. */
  function alElegirFalla() {
    var sel = $('#pen_falla');
    var cod = sel.value;
    $('#pen_diagnostico_codigo').value = cod;
    if (!cod || !CAT) return;
    var d = CAT.diagnosticos.filter(function (x) { return x.codigo === cod; })[0];
    if (!d) return;
    $('#pen_diagnostico').value = d.texto;
    var yaEstan = listaPartesTrabado ? listaPartesTrabado.leer().map(function (p) { return p.codigo; }) : [];
    (d.partes_frecuentes || []).forEach(function (codigoRep) {
      if (yaEstan.indexOf(codigoRep) !== -1) return;
      var r = (CAT.repuestos || []).filter(function (x) { return x.codigo === codigoRep; })[0];
      if (r && listaPartesTrabado) { listaPartesTrabado.agregar({ descripcion: r.descripcion, cantidad: 1, numero_parte: r.numero_parte }); }
    });
  }

  /* =======================================================================
     Reconstruir los bloques repetibles (equipos, novedades) desde datos
     guardados. Lo usan dos caminos que llegan con formas distintas:

       - El BORRADOR (offline.js, H-14): guarda cada bloque con su propio
         lector (`datosEquipo`/`datosNovedad`), en la misma forma que espera
         `reconstruirEquipos`/`reconstruirNovedades`.
       - "Corregir y reenviar" (cola.js, H-07): trae `fila.orden`, que es la
         forma que ya arma `reunirOrden()` (`equipo_sap`/`tipo`+`nuevo`,
         `codigo_activo`, `equipo_desc`…) y hay que adaptar primero.
     ======================================================================= */
  function equipoOrdenAValor(eq) {
    return eq && eq.nuevo ? 'TIPO:' + (eq.tipo || '') : ((eq && eq.equipo_sap) || '');
  }

  function reconstruirEquipos(lista) {
    $('#equipos').innerHTML = ''; nEq = 0;
    (lista || []).forEach(function (eqd) {
      bloqueEquipo();
      var b = $('#equipos .bloque:last-child');
      var sel = b.querySelector('.eq-sel');
      if (eqd.valor) { sel.value = eqd.valor; sel.dispatchEvent(new Event('change')); }
      var set = function (s, v) { var el = b.querySelector(s); if (el && v) { el.value = v; } };
      set('[data-eq-marca]', eqd.marca); set('[data-eq-modelo]', eqd.modelo); set('[data-eq-serie]', eqd.serie);
      set('[data-eq-area]', eqd.area);
      if (eqd.codigo && sel.value.indexOf('TIPO:') === 0) { set('[data-eq-cod]', eqd.codigo); }
      set('[data-eq-obs]', eqd.obs);
      if (eqd.estado) {
        var btn = b.querySelector('[data-eq-estado-seg] button[data-v="' + eqd.estado + '"]');
        if (btn) { btn.click(); }
      }
    });
    if (!$$('#equipos .bloque').length) { bloqueEquipo(); }
  }

  function reconstruirNovedades(lista) {
    var btn = $('#addNovedad');
    if (!btn || !lista || !lista.length) { return; }
    lista.forEach(function (nvd) {
      if (!nvd.descripcion) { return; }
      btn.click();                        // guia.js arma el bloque; puede no existir
      var b = $('#novedadesVisita .bloque:last-child');
      if (!b) { return; }
      var set = function (s, v) { var el = b.querySelector(s); if (el && v) { el.value = v; } };
      set('.nov-tipo', nvd.tipo); set('.nov-riesgo', nvd.riesgo); set('.nov-resp', nvd.responsable);
      set('.nov-equipo', nvd.equipo); set('.nov-desc', nvd.descripcion);
    });
  }

  /**
   * Vuelca una orden ya reunida (la forma de `reunirOrden()`/`fila.orden`) en
   * el formulario. La usa "Corregir y reenviar" (H-07): la firma NO se
   * restaura como trazo -- se avisa y se pide firmar de nuevo, que es más
   * simple y más confiable que reconstruir el canvas desde el PNG-- pero
   * todo lo demás (local, aviso, equipos, pendiente, novedades…) sí.
   */
  function volcarOrden(o) {
    var sinAviso = !!o.sin_aviso;
    fijarOrigen(sinAviso ? 'SIN_ASIGNAR' : 'ASIGNADA');
    $$('#segOrigen button').forEach(function (b) { b.classList.toggle('on', b.dataset.v === $('#origen').value); });
    if (sinAviso) {
      $('#motivo_sin_aviso').value = o.motivo_sin_aviso || '';
      if (o.local) { comboLocal.elegirPorClave(o.local); }
    } else if (o.aviso && !comboAviso.elegirPorClave(o.aviso) && o.local) {
      // El caso ya no está entre los que carga el celular (se cerró, o se
      // reasignó): al menos se deja el local a mano para no perder el resto.
      comboLocal.elegirPorClave(o.local);
    }
    fijarTipo(o.tipo || 'CORRECTIVO');
    $$('#segTipo button').forEach(function (b) { b.classList.toggle('on', b.dataset.v === $('#tipo').value); });
    if (o.dia_intervencion) { $('#dia_intervencion').value = o.dia_intervencion; }
    $('#admin').value = o.admin || '';
    if (o.fecha_atencion) { $('#fecha_atencion').value = o.fecha_atencion; }
    $('#inicio').value = o.inicio || '';
    $('#fin').value = o.fin || '';
    $('#actividades').value = o.actividades || '';
    $('#observaciones').value = o.observaciones || '';
    $('#estado_ot').value = o.estado_ot || 'Abierta';
    $$('#segEstado button').forEach(function (b) { b.classList.toggle('on', b.dataset.v === $('#estado_ot').value); });
    $('#atiempo').value = o.atiempo || '';
    $$('#segAtiempo button').forEach(function (b) { b.classList.toggle('on', b.dataset.v === o.atiempo); });
    if (o.satisfaccion) {
      $('#satisfaccion').value = o.satisfaccion;
      $$('.star', $('#rating')).forEach(function (x) {
        x.querySelector('span:last-child').textContent = (+x.dataset.v <= o.satisfaccion) ? '★' : '☆';
      });
    }
    $('#uso_repuesto').value = o.uso_repuesto ? '1' : '0';
    $$('#segRepuesto button').forEach(function (b) { b.classList.toggle('on', b.dataset.v === (o.uso_repuesto ? 'si' : 'no')); });
    $('#wrapRepuestos').hidden = !o.uso_repuesto;
    if (o.uso_repuesto && listaRepuestosOrden) {
      listaRepuestosOrden.vaciar();
      if (o.repuestos) { listaRepuestosOrden.agregar({ descripcion: o.repuestos }); }
      if (listaRepuestosOrden.vacia()) { listaRepuestosOrden.agregar(); }
    }
    $('#con_proveedor_marcado').checked = !!o.con_proveedor_marcado;
    $('#wrapProveedor').hidden = !o.con_proveedor_marcado;
    if (o.con_proveedor) {
      var trozos = o.con_proveedor.split(' · ');
      $('#proveedor_nombre').value = trozos[0] || '';
      $('#proveedor_objeto').value = trozos.slice(1).join(' · ') || '';
    }
    reconstruirEquipos((o.equipos || []).map(function (eq) {
      return { valor: equipoOrdenAValor(eq), marca: eq.marca, modelo: eq.modelo, serie: eq.serie,
               codigo: eq.codigo_activo, area: eq.area, obs: eq.obs, estado: eq.estado };
    }));
    var concl = o.concluida !== false;
    $('#concluida').value = concl ? '1' : '0';
    $$('#segConcluye button').forEach(function (b) { b.classList.toggle('on', b.dataset.v === (concl ? 'si' : 'no')); });
    $('#wrapTrabado').hidden = concl;
    $('#pen_diagnostico').required = !concl;
    if (!concl && o.pendiente) {
      $('#pen_diagnostico').value = o.pendiente.diagnostico || '';
      $('#pen_diagnostico_codigo').value = o.pendiente.diagnostico_codigo || '';
      $('#pen_equipo').value = o.pendiente.equipo_desc || '';
      $('#pen_parado').checked = !!o.pendiente.deshabilitado;
      sincronizarFallas();
      if (listaPartesTrabado) {
        listaPartesTrabado.vaciar();
        var partes = (o.pendiente.partes && o.pendiente.partes.length)
          ? o.pendiente.partes : (o.pendiente.parte ? [{ descripcion: o.pendiente.parte }] : []);
        partes.forEach(function (p) { if (p.descripcion) { listaPartesTrabado.agregar(p); } });
        if (listaPartesTrabado.vacia()) { listaPartesTrabado.agregar(); }
      }
    }
    reconstruirNovedades((o.novedades || []).map(function (n) {
      return { tipo: n.tipo, riesgo: n.riesgo, responsable: n.responsable, equipo: n.equipo_desc, descripcion: n.descripcion };
    }));
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
    // Con poca señal el catálogo tarda en llegar, y el técnico puede tocar el
    // selector antes: el combo todavía no existe y solo se cambia el panel.
    if (!comboAviso) return;
    // El aviso que vino precargado de la ficha se suelta al cambiar de origen.
    comboAviso.bloquear(false);
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
    return {
      tieneTinta: function () { return strokes.some(function (s) { return s.length > 1; }); },
      /* La firma como imagen, para el PDF (T2.13, la 008). Se reduce a 600 px
         de ancho: en el PDF sale a 250, y el lienzo de un celular moderno pesa
         varias veces eso sin ganar nada. */
      png: function () {
        var k = Math.min(1, 600 / canvas.width);
        if (k >= 1) { return canvas.toDataURL('image/png'); }
        var c = document.createElement('canvas');
        c.width = Math.round(canvas.width * k);
        c.height = Math.round(canvas.height * k);
        var x = c.getContext('2d');
        x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height);
        x.drawImage(canvas, 0, 0, c.width, c.height);
        return c.toDataURL('image/png');
      }
    };
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

  /* Reduce una foto a 1.600 px por el lado mayor y la vuelve JPEG (T2.13, la
     008): una de 4 MB queda en ~300 KB, que es lo que sube con datos móviles y
     cabe en el celular mientras no hay señal. El servidor la deja después en
     1.200 px, como producción, y le quita el EXIF. Si el navegador no puede
     decodificarla, va tal cual y el servidor decide si sirve. */
  function reducirFoto(file) {
    var LADO = 1600;
    var cargar = window.createImageBitmap
      ? createImageBitmap(file)
      : new Promise(function (ok, mal) {
          var img = new Image(), url = URL.createObjectURL(file);
          img.onload = function () { URL.revokeObjectURL(url); ok(img); };
          img.onerror = function () { URL.revokeObjectURL(url); mal(new Error('no se pudo leer')); };
          img.src = url;
        });
    return cargar.then(function (img) {
      var k = Math.min(1, LADO / Math.max(img.width, img.height));
      var c = document.createElement('canvas');
      c.width = Math.max(1, Math.round(img.width * k));
      c.height = Math.max(1, Math.round(img.height * k));
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      if (img.close) { img.close(); }
      return new Promise(function (ok) { c.toBlob(function (b) { ok(b || file); }, 'image/jpeg', 0.8); });
    }).catch(function () { return file; });
  }

  /** Las fotos de la orden, listas para la cola: cada una con su UUID. */
  function prepararFotos(lista) {
    return Promise.all(lista.map(reducirFoto)).then(function (blobs) {
      return blobs.map(function (b) { return { uuid: Cola.uuid(), blob: b }; });
    });
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
      var eq;
      if (val && val.indexOf('TIPO:') === 0) {
        // H-10/D8: "Equipo nuevo / no está en la lista". Viaja con su propio
        // uuid (idempotente: el reintento no lo duplica en equipos_propuestos).
        eq = { tipo: val.slice(5), nuevo: true, equipo_uuid: b.dataset.eqUuid || null };
      } else if (val) {
        eq = { equipo_sap: val, tipo: (opt && opt.dataset.tipo) || '' };
        if (opt && opt.dataset.propuesto === '1') { eq.propuesto = true; }
      } else { return {}; }
      /* Lo que va al PDF de cada equipo (T2.13, la 008): su estado y lo que el
         técnico escribió de él. Marca, modelo y serie no están en el maestro. */
      var on = b.querySelector('[data-eq-estado-seg] button.on');
      var txt = function (s) { var el = b.querySelector(s); return el ? (el.value || '').trim() : ''; };
      eq.estado = on ? on.dataset.v : null;
      eq.obs = txt('[data-eq-obs]') || null;
      eq.marca = txt('[data-eq-marca]') || null;
      eq.modelo = txt('[data-eq-modelo]') || null;
      eq.serie = txt('[data-eq-serie]') || null;
      eq.codigo_activo = txt('[data-eq-cod]') || null;
      if (eq.nuevo) { eq.area = txt('[data-eq-area]') || null; }
      return eq;
    });

    var resp = tecnicoSesion();
    var nombres = resp ? [resp.nombre] : [];
    $$('#tecnicos .tec-sel').forEach(function (s) {
      var t = CAT.tecnicos.filter(function (x) { return String(x.id) === s.value; })[0];
      if (t && nombres.indexOf(t.nombre) === -1) nombres.push(t.nombre);
    });

    var usoRep = $('#uso_repuesto').value === '1';
    // H-11/D9: el texto libre sigue viajando -- es lo que valida Validacion.php
    // y lo que imprime el PDF-- pero ahora se compone desde la lista.
    if (usoRep && listaRepuestosOrden) { $('#repuestos').value = compilarPartesTexto(listaRepuestosOrden.leer()); }

    // H-18/D10: trabajo con otro proveedor. `con_proveedor_marcado` es la
    // intención (la casilla); `con_proveedor` es el texto compuesto, y solo
    // existe si además se escribió el nombre -- si no, Validacion lo bloquea
    // con CON_PROVEEDOR_SIN_NOMBRE, a propósito.
    var conProvMarcado = $('#con_proveedor_marcado').checked;
    var provNombre = $('#proveedor_nombre').value.trim();
    var provObjeto = $('#proveedor_objeto').value.trim();
    var conProveedor = (conProvMarcado && provNombre)
      ? (provNombre + (provObjeto ? ' · ' + provObjeto : ''))
      : null;
    $('#con_proveedor').value = conProveedor || '';

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
      con_proveedor_marcado: conProvMarcado,
      con_proveedor: conProveedor,
      fecha_atencion: $('#fecha_atencion').value || null,
      inicio: $('#inicio').value || null,
      fin: $('#fin').value || null,
      actividades: $('#actividades').value.trim(),
      /* Lo que el PDF necesita y el formulario ya pedía, pero no viajaba
         (T2.13, la 008): quién firma por el local, las observaciones, el
         estado de la orden, la satisfacción y la firma misma. */
      admin: $('#admin').value.trim(),
      observaciones: $('#observaciones').value.trim(),
      estado_ot: $('#estado_ot').value || null,
      atiempo: $('#atiempo').value || null,
      satisfaccion: $('#satisfaccion').value ? +$('#satisfaccion').value : null,
      firma_presente: firma.tieneTinta(),
      firma_png: firma.tieneTinta() ? firma.png() : null,
      fotos_cantidad: fotos.length,
      tecnico: nombres.join(', '),
      /* La cadena viaja con la orden aunque se derive del maestro. Es lo que
         permite reportar por cliente el dia que INDUSTEC atienda a mas de uno,
         y se copia en el momento: si el maestro cambia manana, la orden tiene
         que seguir diciendo a que cliente se le hizo el trabajo. */
      cadena: l ? (l.cadena || null) : null,
      /* La premisa del servicio, como dato: una visita concluye el trabajo. */
      concluida: $('#concluida').value === '1',
      pendiente: pendienteDeLaOrden(),
      novedades: novedadesDeLaOrden(),
      _hoy: isoLocal(new Date())
    };
  }

  /**
   * El equipo que quedo sin concluir, si lo hubo.
   *
   * Se recoge aqui, en la orden, y no en una pantalla aparte, porque el plazo
   * de 48 horas empieza a correr en el momento en que el tecnico sale del local
   * sabiendo que el equipo quedo parado. Preguntarlo despues por telefono deja
   * ese momento sin registrar, y con el, el plazo sin punto de partida.
   */
  function pendienteDeLaOrden() {
    if ($('#concluida').value === '1') return null;
    // H-11/D9: repuestos a solicitar, estructurados (S3 los recibe vía
    // Pendientes::abrir). `parte` sigue viajando, compuesto desde la lista,
    // para lo que hoy ya lee esa columna en la ficha del caso.
    var partes = listaPartesTrabado ? listaPartesTrabado.leer() : [];
    $('#pen_parte').value = compilarPartesTexto(partes);
    return {
      /* El equipo concreto: sin él, dos equipos trabados del mismo caso caían
         en la misma fila y el segundo se fundía con el primero. */
      activo_fijo: activoDelTrabado(),
      diagnostico: ($('#pen_diagnostico').value || '').trim(),
      diagnostico_codigo: $('#pen_diagnostico_codigo').value || null,
      equipo_desc: ($('#pen_equipo').value || '').trim(),
      parte: ($('#pen_parte').value || '').trim(),
      partes: partes,
      deshabilitado: $('#pen_parado').checked
    };
  }

  /* El código de activo del equipo trabado: el único bloque marcado
     «Deshabilitado», o el único equipo de la orden. Si es ambiguo va vacío y el
     servidor usa el equipo del caso, en vez de adivinar. */
  function activoDelTrabado() {
    var bloques = $$('#equipos .bloque');
    var parados = bloques.filter(function (b) {
      var on = b.querySelector('[data-eq-estado-seg] button.on');
      return on && on.dataset.v === 'Deshabilitado';
    });
    var b = parados.length === 1 ? parados[0] : (bloques.length === 1 ? bloques[0] : null);
    var cod = b ? b.querySelector('[data-eq-cod]') : null;
    return cod ? String(cod.value || '').trim() : '';
  }

  /** Lo que se vio en el local y no era la orden. Cero o varias. */
  function novedadesDeLaOrden() {
    /* `#novedadesVisita`, el contenedor que arma guia.js. Leía `#novedades` —el
       nombre de antes del cambio— y ninguna novedad salía nunca con la orden. */
    return $$('#novedadesVisita .bloque').map(function (b) {
      var d = b.querySelector('.nov-desc');
      if (!d || !d.value.trim()) return null;
      return {
        /* El UUID lo genera el celular al escribir la novedad: es la clave que
           hace que un reintento sin senal no la registre dos veces. */
        novedad_uuid: b.dataset.uuid,
        tipo: b.querySelector('.nov-tipo').value,
        riesgo: b.querySelector('.nov-riesgo').value,
        responsable: b.querySelector('.nov-resp').value,
        equipo_desc: (b.querySelector('.nov-equipo').value || '').trim(),
        descripcion: d.value.trim()
      };
    }).filter(Boolean);
  }

  /* Reglas propias de la captura, encima de las 30 del formato único. */
  function reglasDeCaptura(o) {
    var extra = [];

    /* La premisa del servicio, hecha regla: una intervención concluye el
       trabajo. Dejar un equipo sin concluir es legítimo —es la excepción
       prevista— pero exige decir qué se encontró, porque ese texto es lo que
       sostiene el veredicto de las 48 horas y la respuesta a Grupo KFC. */
    if (o.pendiente && !o.pendiente.diagnostico) {
      extra.push({ campo: 'pen_diagnostico', severidad: 'BLOQUEA',
                   mensaje: 'si el trabajo no quedó concluido, escribe qué encontraste' });
    }
    if (o.pendiente && o.pendiente.deshabilitado && !o.pendiente.equipo_desc && !o.aviso) {
      extra.push({ campo: 'pen_equipo', severidad: 'ADVIERTE',
                   mensaje: 'la orden no tiene aviso y el equipo no está identificado: '
                          + 'la administración no va a saber de qué equipo se trata' });
    }
    var ts = tecnicoSesion();
    if (!ts) {
      // Ya no se elige: sale de la sesion. Si falta, la sesion se perdio, y
      // una orden sin firma identificada no se puede emitir.
      extra.push({ campo: 'tecnico', severidad: 'BLOQUEA',
                   mensaje: 'no se pudo leer tu sesión; vuelve a entrar al sistema antes de enviar' });
    } else if (!ts.del_padron) {
      // H-16: antes esto solo era un texto (pintarYo()) y la orden se dejaba
      // enviar igual -- subía las fotos y recién en envio.php un 400
      // TECNICO_NO_VIGENTE la dejaba RECHAZADA, con veinte minutos de trabajo
      // ya gastados. Se corta aquí, antes de nada.
      extra.push({ campo: 'tecnico', severidad: 'BLOQUEA',
                   mensaje: 'tu usuario no está en el padrón de técnicos vigentes; pide a la '
                          + 'administración que te agregue antes de emitir' });
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

  /* ---------- Envío ------------------------------------------------------
     LA ORDEN SE GUARDA EN EL CELULAR ANTES DE INTENTAR MANDARLA.

     Ese orden importa y no es un detalle. El técnico llena la orden en la
     cocina de un local donde no entra el dato; si el guardado ocurriera
     después de fallar el envío, la orden que se pierde es justo la del caso en
     que el navegador se cierra a mitad del intento.

     `Cola.encolar()` vuelve en cuanto la orden está a salvo en disco. El envío
     es cosa del programa a partir de ahí: sale solo cuando vuelve la señal,
     aunque el técnico cierre la aplicación.

     EL RECIBO DICE LA VERDAD. El servidor devuelve el número de la orden y
     dónde quedó su PDF; en el sitio de pruebas, además, que el correo al local
     no salió. Nada de «enviada correctamente» a secas (I-7).
     --------------------------------------------------------------------- */
  function alEnviar(e) {
    e.preventDefault();
    // H-05: con el catálogo caído, `CAT`/`validar` siguen en `null` y esto
    // reventaba en silencio -- `e.preventDefault()` ya había corrido, así que
    // el botón no hacía nada y el técnico no sabía por qué.
    if (!CAT || !validar) {
      if (window.UI) {
        UI.toast('No se cargaron los locales ni los equipos: no se puede validar la orden. '
                + 'Conéctate una vez y vuelve a intentarlo.', 'err', { vida: 9000 });
      }
      return;
    }
    var o = reunirOrden();
    var hallazgos = validar(o, 'CAPTURA').concat(reglasDeCaptura(o));
    var res = pintarValidacion(hallazgos);
    $('#panelValidacion').scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (res.bloquea) return;

    var seguir = function () {
      var btn = $('#submitBtn');
      btn.disabled = true;
      btn.textContent = 'Guardando…';

      if (!window.Cola) {
        /* Sin la cola no hay garantía de que la orden sobreviva a un fallo de
           red, y una orden perdida son veinte minutos de trabajo del técnico
           dentro de un local. Antes que arriesgarlo, se para y se dice. */
        btn.disabled = false;
        btn.textContent = 'Revisar y enviar';
        if (window.UI) {
          UI.toast('No se pudo preparar el envío. Recarga la aplicación antes de llenar la orden.', 'err');
        }
        return;
      }

      // Las fotos se reducen antes de guardarlas con la orden. La orden queda
      // ligada a quien la llenó: en un celular compartido, el servidor no la
      // acepta con la sesión de otro.
      prepararFotos(fotos).then(function (listas) {
        // H-07: "Corregir y reenviar" -- si esto vino de
        // `?reintentar=<uuid>`, es la MISMA fila, no una nueva; las fotos que
        // ya se habían subido se conservan (Cola.reencolar las mantiene) y
        // solo las que se agregaron ahora van en `listas`.
        return reintentarUuid
          ? Cola.reencolar(reintentarUuid, o, YO ? YO.id : null, listas)
          : Cola.encolar(o, YO ? YO.id : null, listas);
      }).then(function (uuid) {
        ultimoUuid = uuid;
        window.dispatchEvent(new CustomEvent('orden-encolada'));
        mostrarRecibo(o);
      }).catch(function () {
        btn.disabled = false;
        btn.textContent = 'Revisar y enviar';
        if (window.UI) {
          UI.toast('No se pudo guardar la orden en este celular. No cierres la pantalla: '
                 + 'anota los datos antes de salir.', 'err');
        }
      });
    };

    if (hallazgos.length) {
      /* Las advertencias no bloquean, pero se confirman con lo que dicen a la
         vista: el `confirm()` del navegador dejaba al técnico decidiendo a
         ciegas, y en el celular sale como una alerta del sistema que la gente
         descarta sin leer. */
      if (window.UI && UI.confirmar) {
        UI.confirmar({
          titulo: 'Hay ' + hallazgos.length + ' aviso' + (hallazgos.length === 1 ? '' : 's') + ' para revisar',
          detalle: 'No impiden enviar, pero conviene mirarlos: quedan marcados en la orden '
                 + 'y la administración los va a ver.',
          ok: 'Enviar así'
        }, seguir);
      } else if (confirm('Hay ' + hallazgos.length + ' aviso(s) para revisar. ¿Enviar igual?')) {
        seguir();
      }
      return;
    }
    seguir();
  }

  /* H-04/DOC-04: mientras no llega el recibo real de envio.php, se dice lo que
     SÍ se sabe (guardada, en camino) y nada más. `cola.js` dispara
     `orden-emitida` con `{uuid, recibo}` en cuanto el servidor contesta,
     coincida o no con la pantalla que sigue abierta; por eso se compara
     contra `ultimoUuid` antes de pintar nada. */
  window.addEventListener('orden-emitida', function (e) {
    var d = e.detail || {};
    if (!d.uuid || d.uuid !== ultimoUuid) return;
    var r = d.recibo || {};
    var emitida = r.estado === 'EMITIDA';
    if (r.id_industec) { $('#rNombre').textContent = r.id_industec; }
    $('#rNnnnNota').hidden = !!r.id_industec;
    if (r.que_sigue) {
      $('#rEstado').className = 'aviso ' + (emitida ? 'ok' : 'info');
      $('#rEstadoTxt').innerHTML = '<b>' + (emitida ? 'Orden emitida.' : 'Orden recibida.') + '</b> ' + esc(r.que_sigue);
    }
    var verPdf = $('#rVerPdf');
    if (emitida && r.id_industec && verPdf) {
      verPdf.href = 'pdf.php?ot=' + encodeURIComponent(r.id_industec);
      $('#rVerPdfWrap').hidden = false;
    }
  });

  function mostrarRecibo(o) {
    var conSenal = navigator.onLine;
    $('#rNombre').textContent = nombreCanonico(o) || '(sin local, no se puede componer el nombre)';
    $('#rNnnnNota').hidden = false;
    $('#rVerPdfWrap').hidden = true;
    $('#rTarea').hidden = !o.sin_aviso;

    $('#rEstado').className = 'aviso ' + (conSenal ? 'info' : 'warn');
    $('#rEstadoTxt').innerHTML = conSenal
      ? '<b>Orden guardada y en camino.</b> Se está enviando ahora. Si la señal se corta, '
      + 'sale sola cuando vuelva, con la aplicación abierta — no hace falta que la llenes otra vez.'
      : '<b>Orden guardada en este celular.</b> No hay señal, así que todavía no salió. '
      + 'Sale sola cuando vuelvas a abrir la aplicación con señal. '
      + 'Puedes seguir llenando las siguientes.';

    var extra = [];
    if (o.pendiente) {
      extra.push(o.pendiente.deshabilitado
        ? 'El equipo va como deshabilitado. Cuando la orden llegue al sistema se abre el plazo '
        + 'de 48 horas para decidir la vía —repuesto, reparación, garantía o baja—, contado desde ahora.'
        : 'El equipo va como trabado. No corre el plazo de 48 horas porque el local todavía '
        + 'lo puede usar.');
    }
    if (o.novedades && o.novedades.length) {
      extra.push('Reportaste ' + o.novedades.length + ' novedad'
               + (o.novedades.length === 1 ? '' : 'es') + ' del local. Las revisa tu jefe de zona.');
    }
    $('#rExtra').innerHTML = extra.length ? '<li>' + extra.join('</li><li>') + '</li>' : '';
    $('#rExtraWrap').hidden = !extra.length;

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
      equipos: (cat.equipos && cat.equipos.datos) ? cat.equipos.datos : (cat.equipos || {}),
      // H-08/H-11/D8/D9: prellenados de catalogos.php. Si la 009 no está
      // aplicada llegan vacíos y el formulario sigue igual que hoy.
      admins: cat.admins || {},
      familias: arr(cat.familias),
      diagnosticos: arr(cat.diagnosticos),
      repuestos: arr(cat.repuestos_frecuentes)
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
      // H-11/D9: el primer renglón aparece solo -- no hace falta pulsar
      // "Agregar repuesto" antes de poder escribir el primero.
      if (si && listaRepuestosOrden && listaRepuestosOrden.vacia()) { listaRepuestosOrden.agregar(); }
    });
    segmentado($('#segEstado'), function (v) { $('#estado_ot').value = v; });
    segmentado($('#segAtiempo'), function (v) { $('#atiempo').value = v; });
    // H-18/D10: trabajo con otro proveedor.
    $('#con_proveedor_marcado').addEventListener('change', function () {
      $('#wrapProveedor').hidden = !this.checked;
    });
    // H-11/D9: al abrir "el trabajo no quedó concluido", igual que arriba:
    // el primer renglón de repuestos a solicitar aparece solo, y se refresca
    // el selector de fallas por si cambió cuál es el equipo trabado.
    $('#segConcluye').addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return;
      if (b.dataset.v === 'no') {
        sincronizarFallas();
        if (listaPartesTrabado && listaPartesTrabado.vacia()) { listaPartesTrabado.agregar(); }
      }
    });
    $('#pen_falla').addEventListener('change', alElegirFalla);

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
    listaRepuestosOrden = crearListaPartes('#repuestosLista');
    listaPartesTrabado = crearListaPartes('#penPartes');
    $('#addRepuesto').addEventListener('click', function () { listaRepuestosOrden.agregar(); });
    $('#addParte').addEventListener('click', function () { listaPartesTrabado.agregar(); });

    /* H-14: `offline.js` restaura los campos con `id` y dispara esto para que
       app.js reponga lo que sabe reconstruir: los combos derivados
       (local/aviso, con sus chips y correos) y los bloques de equipos y
       novedades, que no tienen `id` propio. */
    window.addEventListener('borrador-restaurado', function (e) {
      var d = e.detail || {};
      if (d.tipo) { fijarTipo(d.tipo); $$('#segTipo button').forEach(function (b) { b.classList.toggle('on', b.dataset.v === d.tipo); }); }
      if (d.origen) { fijarOrigen(d.origen); $$('#segOrigen button').forEach(function (b) { b.classList.toggle('on', b.dataset.v === d.origen); }); }
      if (d.origen === 'ASIGNADA' && d.aviso) { comboAviso.elegirPorClave(d.aviso); }
      else if (d.local) { comboLocal.elegirPorClave(d.local); }
      if (d.uso_repuesto === '1' && listaRepuestosOrden && listaRepuestosOrden.vacia() && d.repuestos) {
        listaRepuestosOrden.agregar({ descripcion: d.repuestos });
      }
      if (Array.isArray(d._equipos) && d._equipos.length) { reconstruirEquipos(d._equipos); }
      if (Array.isArray(d._novedades) && d._novedades.length) { reconstruirNovedades(d._novedades); }
    });

    fetch('yo.php', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (d && d.ok) { YO = d; } })
      .catch(function () { /* sin senal: se resuelve con lo cacheado o se avisa */ })
      .then(function () { pintarYo(); if (CAT) { pintarCobertura(); } });

    cargarCatalogo().then(function (cat) {
      CAT = normalizar(cat);
      AVISOS = cat.avisos || { datos: [], cobertura: null };
      CAT.locales.forEach(function (l) { localesPorCodigo[l.codigo] = l; });
      validar = Reglas.crear({
        locales: CAT.locales, equipos: CAT.equipos, tipos: CAT.tipos, tecnicos: CAT.tecnicos
      });

      pintarYo();

      initComboLocal();
      initComboAviso();
      bloqueEquipo();
      refrescarAvisos();

      // H-11/D9: el datalist compartido de repuestos, con lo que trae el
      // catálogo (puede llegar vacío si la 009 no está aplicada).
      $('#partesLista').innerHTML = (CAT.repuestos || [])
        .map(function (r) { return '<option value="' + esc(r.descripcion) + '">'; }).join('');

      var params = new URLSearchParams(location.search);
      var tipoPedido = params.get('tipo');
      var diaPedido = params.get('dia');
      var equiposPedidos = params.get('equipos');
      var localPreventivo = params.get('local');

      /* Viene de «Emitir la orden de este caso» en la bandeja: el caso ya está
         asignado y entra puesto, sin buscarlo ni teclearlo. Hasta el 2026-09-10
         el enlace mandaba ?aviso= y aquí solo se leía ?local=. */
      var avisoPedido = params.get('aviso');
      if (avisoPedido) {
        fijarOrigen('ASIGNADA');
        $$('#segOrigen button').forEach(function (b) { b.classList.toggle('on', b.dataset.v === 'ASIGNADA'); });
        if (comboAviso.elegirPorClave(avisoPedido)) {
          comboAviso.bloquear(true);
        } else {
          $('#avisoCobertura').textContent = 'El caso ' + avisoPedido + ' no está entre los casos '
            + 'guardados en este celular. Si te lo acaban de asignar, abre la app con señal para actualizarla.';
        }
      } else if (tipoPedido === 'PREVENTIVO' && localPreventivo && localesPorCodigo[localPreventivo]) {
        /* H-17: cada visita del cronograma enlaza aquí con
           `?tipo=PREVENTIVO&dia=N&local=X&equipos=sap1,sap2`. Antes solo se
           leía `?local=` -- ni el tipo, ni el día, ni los equipos llegaban
           prellenados, y el día quedaba sin sincronizar con `required`
           cuando el tipo se fijaba por programa en vez de por clic. */
        fijarOrigen('SIN_ASIGNAR');
        $$('#segOrigen button').forEach(function (b) { b.classList.toggle('on', b.dataset.v === 'SIN_ASIGNAR'); });
        comboLocal.elegirPorClave(localPreventivo);
        fijarTipo('PREVENTIVO');
        $$('#segTipo button').forEach(function (b) { b.classList.toggle('on', b.dataset.v === 'PREVENTIVO'); });
        if (diaPedido) {
          $('#dia_intervencion').value = diaPedido;
          $('#dia_intervencion').dispatchEvent(new Event('change'));
        }
        if (equiposPedidos) {
          var codigos = equiposPedidos.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
          if (codigos.length) {
            reconstruirEquipos(codigos.map(function (sap) { return { valor: sap }; }));
          }
        }
      } else {
        var pedido = localPreventivo || window.PRESELECT;
        if (pedido && localesPorCodigo[pedido]) {
          fijarOrigen('SIN_ASIGNAR');
          $$('#segOrigen button').forEach(function (b) { b.classList.toggle('on', b.dataset.v === 'SIN_ASIGNAR'); });
          comboLocal.elegirPorClave(pedido);
        }
      }

      // H-07: "Corregir y reenviar" -- viene de `cola.js`, que ya conserva la
      // firma y las fotos que se habían subido. Se vuelca todo lo demás; la
      // firma se pide de nuevo (más simple y más confiable que reconstruir el
      // canvas desde el PNG guardado).
      reintentarUuid = params.get('reintentar');
      if (reintentarUuid && window.Cola && Cola.leer) {
        Cola.leer(reintentarUuid).then(function (fila) {
          if (!fila || !fila.orden) {
            reintentarUuid = null;
            if (window.UI) { UI.toast('Esa orden ya no está guardada en este celular para corregir.', 'err'); }
            return;
          }
          volcarOrden(fila.orden);
          if (window.UI) {
            UI.toast('Corrige lo que haga falta. Como ya tenía firma, hay que volver a firmarla antes de reenviar.',
                     'warn', { vida: 12000 });
          }
        });
      }

      $('#pendientes').textContent =
        'Catálogo: ' + CAT.locales.length + ' locales · ' +
        Object.keys(CAT.equipos).length + ' con activos · ' +
        CAT.tipos.length + ' tipos · ' + CAT.tecnicos.length + ' técnicos · ' +
        AVISOS.datos.length + ' órdenes abiertas.';
    }).catch(function (err) {
      /* Un mensaje que diga que hacer, no solo que fallo. El anterior era
         «No se pudo cargar el catálogo: catalogos.php respondió 503», que le
         dice algo al programador y nada al tecnico dentro de un local. */
      var caja = $('#pendientes');
      caja.innerHTML = '';
      var aviso = document.createElement('div');
      aviso.className = 'aviso err';
      aviso.setAttribute('role', 'alert');
      aviso.innerHTML = '<span class="ic" aria-hidden="true">✕</span><div class="cuerpo">' +
        '<b>No se pudieron cargar los locales ni los equipos.</b>' +
        '<p>' + (navigator.onLine
          ? 'Hay señal, así que es un problema del sistema. Avisa a la administración antes de llenar la orden a mano.'
          : 'Estás sin señal y este celular no tiene una copia guardada. Conéctate una vez y la aplicación queda lista para trabajar sin cobertura.') +
        '</p><p class="small">Detalle técnico: ' + String(err && err.message || err) + '</p></div>';
      caja.appendChild(aviso);
      // H-05: el botón lo dice también, no solo el toast de más arriba --
      // sigue visible (no se esconde el formulario, es mejora progresiva),
      // pero deja claro por qué no reacciona.
      var btn = $('#submitBtn');
      if (btn) { btn.disabled = true; btn.title = 'Sin catálogo no se puede validar la orden'; }
    });
  });

})();
