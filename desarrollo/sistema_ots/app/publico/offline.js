/* =========================================================================
   offline.js — Lo que el técnico ve del modo sin conexión.

   Tres cosas, y ninguna estorba mientras hay señal:

   1. REGISTRA EL TRABAJADOR DE SERVICIO. Sin esto, «instalar» la página es un
      acceso directo: se ve el formulario y no funciona nada, que es justo lo
      que pasó el 2026-09-08.

   2. DICE SI HAY SEÑAL Y DE CUÁNDO SON LOS DATOS. Sin señal el técnico sigue
      trabajando, pero tiene que saberlo: si la lista de órdenes es de ayer,
      un caso creado hoy no va a estar. Callarlo sería inventar (I-7).

   3. GUARDA EL BORRADOR MIENTRAS ESCRIBE. Si se cierra la app, se apaga el
      celular o se va la batería, la orden a medias sigue ahí. Hoy, un corte
      de señal se lleva todo lo escrito.

   Encolar los envíos no es de este archivo: lo hace cola.js, en IndexedDB.
   Cuando la orden queda a salvo en la cola, aquí se borra el borrador.
   ========================================================================= */

(function () {
  'use strict';

  var CLAVE_BORRADOR = 'ot_borrador_v1';
  var $ = function (s) { return document.querySelector(s); };

  /* ---------- 1. Trabajador de servicio ---------- */
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js').then(function (reg) {
        // Si hay una versión nueva esperando, se avisa en vez de cambiarla
        // debajo de los pies de alguien que está llenando una orden.
        reg.addEventListener('updatefound', function () {
          var nuevo = reg.installing;
          if (!nuevo) return;
          nuevo.addEventListener('statechange', function () {
            if (nuevo.state === 'installed' && navigator.serviceWorker.controller) {
              pintarEstado(true, 'Hay una versión nueva. Se aplica al volver a abrir la app.');
            }
          });
        });
      }).catch(function (e) {
        console.warn('no se pudo registrar el trabajador de servicio:', e);
      });
    });
  }

  /* ---------- 2. Estado de la conexión ---------- */
  function barra() {
    var b = $('#estadoRed');
    if (b) return b;
    b = document.createElement('div');
    b.id = 'estadoRed';
    b.className = 'estado-red';
    document.body.insertBefore(b, document.body.firstChild);
    return b;
  }

  function pintarEstado(hayRed, extra) {
    var b = barra();
    b.className = 'estado-red ' + (hayRed ? 'con' : 'sin');
    if (hayRed && !extra) {
      // Con señal y sin novedades no hace falta ninguna franja.
      b.hidden = true;
      return;
    }
    b.hidden = false;
    b.innerHTML = hayRed
      ? '<b>Listo.</b> ' + (extra || '')
      : '<b>Sin conexión.</b> Puedes llenar la orden y enviarla: queda guardada en el ' +
        'teléfono y sale sola cuando vuelvas a abrir la app con señal.' + (extra ? ' ' + extra : '');
  }

  function cuando(iso) {
    var f = new Date(iso);
    var hoy = new Date().toDateString() === f.toDateString();
    var hora = f.toLocaleTimeString('es-EC', { hour: '2-digit', minute: '2-digit' });
    return hoy ? ('hoy a las ' + hora)
               : ('del ' + f.toLocaleDateString('es-EC') + ' a las ' + hora);
  }

  /* La señal que manda es de dónde salieron los datos, no navigator.onLine.
     `onLine` solo dice que hay una red conectada: en el celular de un técnico,
     con una barra de señal y sin datos, sigue diciendo que sí. */
  function revisarRed() {
    if (window.__datosDesdeCache) {
      pintarEstado(false, 'Las órdenes y los locales que ves son ' +
        cuando(window.__datosDesdeCache) + '; puede faltar algo de después.');
      return;
    }
    pintarEstado(navigator.onLine);
  }
  window.addEventListener('online', revisarRed);
  window.addEventListener('offline', revisarRed);
  window.addEventListener('datos-de-cache', revisarRed);

  /* ---------- 3. Borrador ---------- */
  function campos() {
    return Array.prototype.slice.call(
      document.querySelectorAll('#otForm input, #otForm select, #otForm textarea')
    ).filter(function (el) { return el.id && el.type !== 'file' && el.type !== 'password'; });
  }

  function guardarBorrador() {
    try {
      var d = {};
      campos().forEach(function (el) {
        d[el.id] = (el.type === 'checkbox') ? el.checked : el.value;
      });
      d._cuando = new Date().toISOString();
      localStorage.setItem(CLAVE_BORRADOR, JSON.stringify(d));
    } catch (e) { /* modo privado o sin espacio: no es motivo para romper nada */ }
  }

  function hayBorrador() {
    try {
      var d = JSON.parse(localStorage.getItem(CLAVE_BORRADOR) || 'null');
      if (!d) return null;
      // Un borrador de hace más de 2 días casi seguro es basura, no trabajo.
      if ((Date.now() - new Date(d._cuando).getTime()) > 2 * 86400000) {
        localStorage.removeItem(CLAVE_BORRADOR);
        return null;
      }
      // Solo cuenta si tiene algo escrito de verdad.
      var util = ['local', 'aviso', 'actividades', 'admin', 'tecSesion'];
      return util.some(function (k) { return d[k]; }) ? d : null;
    } catch (e) { return null; }
  }

  function restaurar(d) {
    campos().forEach(function (el) {
      if (!(el.id in d)) return;
      if (el.type === 'checkbox') el.checked = !!d[el.id];
      else el.value = d[el.id];
      el.dispatchEvent(new Event('change', { bubbles: true }));
    });
  }

  function ofrecerBorrador() {
    var d = hayBorrador();
    if (!d) return;
    var f = new Date(d._cuando);
    var caja = document.createElement('div');
    caja.className = 'nota-regular';
    caja.style.margin = '0 0 14px';
    caja.innerHTML =
      '<b>Tienes una orden a medio llenar</b>, del ' + f.toLocaleDateString('es-EC') +
      ' a las ' + f.toLocaleTimeString('es-EC', { hour: '2-digit', minute: '2-digit' }) + '. ' +
      'Las fotos y la firma no se guardan, hay que rehacerlas.' +
      '<div class="row" style="margin-top:10px">' +
      '<button type="button" class="btn primary" id="bRetomar">Retomar</button>' +
      '<button type="button" class="btn" id="bDescartar">Empezar de nuevo</button></div>';
    var form = $('#otForm');
    form.parentNode.insertBefore(caja, form);
    $('#bRetomar').addEventListener('click', function () { restaurar(d); caja.remove(); });
    $('#bDescartar').addEventListener('click', function () {
      try { localStorage.removeItem(CLAVE_BORRADOR); } catch (e) {}
      caja.remove();
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (!$('#otForm')) return;          // el cronograma no usa nada de esto
    revisarRed();
    // Se espera a que app.js termine de armar los bloques repetibles.
    setTimeout(ofrecerBorrador, 1500);

    var t = null;
    ['input', 'change'].forEach(function (ev) {
      document.addEventListener(ev, function (e) {
        if (!e.target.closest || !e.target.closest('#otForm')) return;
        clearTimeout(t);
        t = setTimeout(guardarBorrador, 600);
      }, true);
    });
    // Cuando la orden queda a salvo en la cola, el borrador deja de tener
    // sentido. Antes se miraba con un temporizador tras el submit, y con un
    // aviso por confirmar el borrador sobrevivía: la app ofrecía «retomar» una
    // orden que ya iba en camino, y reenviarla creaba otra con otro UUID.
    window.addEventListener('orden-encolada', function () {
      clearTimeout(t);
      try { localStorage.removeItem(CLAVE_BORRADOR); } catch (e) {}
    });
  });
})();
