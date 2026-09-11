/* =========================================================================
   ui.js — Las piezas de interfaz que comparten todas las pantallas.

   QUE HAY AQUI
     UI.toast()      avisos efímeros: confirman algo que YA pasó
     UI.confirmar()  el diálogo de «¿seguro?» para lo que no se deshace
     contadores      las cifras suben en vez de aparecer, para que se noten
     novedades       la barra de «el buzón cambió», sin recargar por su cuenta
     tiempo relativo  «hace 3 h» calculado en el navegador

   NO HAY UN SOLO FRAMEWORK, Y ES A PROPOSITO. El técnico abre esto en un
   celular de gama baja, con datos móviles y a veces sin señal. Cada kilobyte
   que se descarga es un segundo de pie en la cocina de un local. Todo esto
   pesa menos que el logo.

   REGLA DE REPARTO DE LOS AVISOS, la misma que documenta estilo.css:
     hay que hacer algo  -> aviso fijo en la página (.aviso)
     ya salió bien       -> aviso efímero (toast)
     no se puede deshacer-> diálogo de confirmación
   Un error nunca va en toast: el toast se va y el error hay que leerlo.
   ========================================================================= */
(function (global) {
  'use strict';

  var UI = {};

  /* --- Avisos efímeros ---------------------------------------------------
     Se van solos a los 5 s. Los de error se quedan hasta que se cierren, por
     si aparece uno donde no debía: mejor que estorbe a que se pierda. */
  var ICONO = { ok: '✓', info: 'i', warn: '!', err: '✕' };

  UI.toast = function (texto, tono, opciones) {
    tono = tono || 'info';
    opciones = opciones || {};
    var caja = document.getElementById('toasts');
    if (!caja) {
      // Las pantallas PHP lo traen de Ui::cabecera; el formulario del técnico
      // (index.html) no, y ahí se perdían en silencio el «no se pudo guardar la
      // orden en este celular» y los avisos del recibo. Se crea donde falte.
      caja = document.createElement('div');
      caja.id = 'toasts';
      caja.setAttribute('role', 'status');
      caja.setAttribute('aria-live', 'polite');
      document.body.appendChild(caja);
    }

    var t = document.createElement('div');
    t.className = 'toast ' + tono;
    t.setAttribute('role', tono === 'err' ? 'alert' : 'status');

    var ic = document.createElement('span');
    ic.className = 'ic';
    ic.setAttribute('aria-hidden', 'true');
    ic.textContent = ICONO[tono] || '·';

    var cuerpo = document.createElement('div');
    cuerpo.style.flex = '1';
    cuerpo.style.minWidth = '0';
    cuerpo.textContent = texto;

    var x = document.createElement('button');
    x.className = 'cerrar';
    x.type = 'button';
    x.setAttribute('aria-label', 'Cerrar');
    x.textContent = '×';
    x.addEventListener('click', function () { quitar(t); });

    t.appendChild(ic); t.appendChild(cuerpo); t.appendChild(x);
    caja.appendChild(t);

    var vida = opciones.vida != null ? opciones.vida : (tono === 'err' ? 0 : 5000);
    if (vida > 0) { setTimeout(function () { quitar(t); }, vida); }
    return t;
  };

  function quitar(t) {
    if (!t || !t.parentNode) { return; }
    t.classList.add('saliendo');
    setTimeout(function () { if (t.parentNode) { t.parentNode.removeChild(t); } }, 220);
  }

  /* --- Confirmación ------------------------------------------------------
     Para lo que no se deshace. `confirm()` del navegador no se puede redactar
     y en el celular sale como una alerta del sistema, que la gente descarta
     sin leer. Este dice qué va a pasar y quién lo va a ver. */
  UI.confirmar = function (opciones, alAceptar) {
    var d = document.createElement('dialog');
    d.innerHTML =
      '<h2></h2><p class="sub"></p>' +
      '<div class="row" style="margin-top:16px;gap:8px">' +
      '<button class="btn primary" value="si" type="button"></button>' +
      '<button class="btn" value="no" type="button">Cancelar</button></div>';
    d.querySelector('h2').textContent = opciones.titulo || '¿Confirmas?';
    d.querySelector('p').textContent = opciones.detalle || '';
    var ok = d.querySelector('button[value=si]');
    ok.textContent = opciones.ok || 'Confirmar';
    if (opciones.peligro) { ok.className = 'btn danger'; }

    ok.addEventListener('click', function () { d.close(); alAceptar(); });
    d.querySelector('button[value=no]').addEventListener('click', function () { d.close(); });
    d.addEventListener('close', function () { d.remove(); });
    document.body.appendChild(d);
    d.showModal();
  };

  /* --- Contadores --------------------------------------------------------
     La cifra sube desde cero. No es adorno: cuando la administradora recarga y
     «con alerta» pasa de 3 a 11, el movimiento se lo dice; el número quieto,
     no. Se salta si son cifras grandes o si pidió menos movimiento. */
  var quieto = global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches;

  UI.contar = function (el) {
    var fin = parseInt(el.getAttribute('data-n'), 10);
    if (isNaN(fin)) { return; }
    if (quieto || fin > 5000) { el.textContent = fin.toLocaleString('es-EC'); return; }
    var t0 = null, dur = Math.min(220 + fin * 6, 900);
    function paso(ts) {
      if (t0 === null) { t0 = ts; }
      var p = Math.min((ts - t0) / dur, 1);
      // Desaceleración: arranca rápido y frena, que es como se lee un número.
      var v = Math.round(fin * (1 - Math.pow(1 - p, 3)));
      el.textContent = v.toLocaleString('es-EC');
      if (p < 1) { requestAnimationFrame(paso); }
    }
    requestAnimationFrame(paso);
  };

  /* --- Tiempo relativo ---------------------------------------------------
     «hace 3 h» se entiende de un vistazo; «2026-09-09 11:20» hay que restarlo
     mentalmente. Las fechas llegan del servidor en hora de Ecuador (Db.php
     fija la zona de la base y la de PHP) y sin zona escrita, así que el
     navegador las lee como hora local: cuadra en un celular de Ecuador. Hasta
     el 2026-09-11 llegaban en UTC y esto salía corrido cinco horas. */
  UI.hace = function (iso) {
    var t = Date.parse((iso || '').replace(' ', 'T'));
    if (isNaN(t)) { return ''; }
    var s = (Date.now() - t) / 1000;
    if (s < 60) { return 'recién'; }
    if (s < 3600) { return 'hace ' + Math.floor(s / 60) + ' min'; }
    if (s < 86400) { return 'hace ' + Math.floor(s / 3600) + ' h'; }
    var d = Math.floor(s / 86400);
    if (d < 30) { return 'hace ' + d + (d === 1 ? ' día' : ' días'); }
    return new Date(t).toLocaleDateString('es-EC');
  };

  /* --- Barra de novedades ------------------------------------------------
     El vigilante del buzón (t2_9) empuja los casos nuevos en segundos. Esta
     barra avisa y NO recarga sola: quien mira puede estar a medio leer un caso
     o a medio escribir un motivo, y recargarle la pantalla debajo es peor que
     no avisar. La decisión es de la persona. */
  function novedades() {
    var caja = document.getElementById('novedades');
    var txt  = document.getElementById('nov-txt');
    /* Se exigen LAS DOS partes, no solo la caja.
       Con solo la caja, esta funcion se enganchaba a cualquier elemento que se
       llamara `novedades` — y en el formulario del tecnico habia uno, el de las
       novedades de la visita. El resultado era que a los 30 segundos le ponia
       `hidden` a lo que el tecnico acababa de escribir: seguia en el envio,
       pero desaparecia de la pantalla. Los ids se separaron; esta comprobacion
       es para que no vuelva a pasar si alguien reusa el nombre. */
    if (!caja || !txt) { return; }
    var base = null, visto = null;

    function mirar() {
      fetch('novedades.php', { cache: 'no-store', credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d || !d.hay) { return; }
          if (base === null) { base = d.version; return; }     // primera lectura
          if (d.version === base || d.version === visto) { caja.hidden = true; return; }
          if (typeof d.avisos === 'number') {
            // Al técnico no le cuenta el buzón sino SUS avisos (T2.13.5), y «Ver»
            // lo lleva a ellos en vez de recargar la pantalla en la que está.
            if (d.avisos === 0) { caja.hidden = true; return; }
            txt.textContent = d.avisos === 1 ? 'Tienes 1 aviso nuevo' : 'Tienes ' + d.avisos + ' avisos nuevos';
          } else {
            var n = (typeof d.total === 'number') ? d.total : null;
            txt.textContent = n === null
              ? 'El buzón se actualizó'
              : 'El buzón se actualizó — ahora hay ' + n + (n === 1 ? ' caso' : ' casos');
          }
          caja.dataset.ir = d.ir || '';
          caja.dataset.version = d.version;
          caja.hidden = false;
        })
        .catch(function () { /* sin señal: se reintenta al rato, sin molestar */ });
    }

    var ver = document.getElementById('nov-ver');
    var no  = document.getElementById('nov-no');
    if (ver) {
      ver.addEventListener('click', function () {
        if (caja.dataset.ir) { location.href = caja.dataset.ir; } else { location.reload(); }
      });
    }
    if (no) {
      no.addEventListener('click', function () {
        visto = Number(caja.dataset.version) || null;
        caja.hidden = true;
      });
    }
    mirar();
    setInterval(mirar, 30000);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) { mirar(); }
    });
  }

  /* --- Filtros que se aplican solos --------------------------------------
     Un formulario de filtros con botón «Filtrar» obliga a dos gestos por cada
     cambio. Los desplegables se envían al elegir; el texto no, porque enviar a
     cada tecla recarga la página en mitad de la palabra. */
  function filtrosVivos() {
    document.querySelectorAll('form[data-auto] select').forEach(function (s) {
      s.addEventListener('change', function () { s.form.submit(); });
    });
  }

  /* --- Arranque ---------------------------------------------------------- */
  function arranque() {
    document.querySelectorAll('[data-n]').forEach(UI.contar);
    document.querySelectorAll('[data-hace]').forEach(function (el) {
      var t = UI.hace(el.getAttribute('data-hace'));
      if (t) { el.textContent = t; }
    });
    // El escalonado se hace con una variable por hijo; ponerlo a mano en la
    // plantilla era una línea de PHP repetida en cada bucle.
    document.querySelectorAll('.escalona').forEach(function (c) {
      Array.prototype.forEach.call(c.children, function (h, i) { h.style.setProperty('--i', i); });
    });
    novedades();
    filtrosVivos();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', arranque);
  } else {
    arranque();
  }

  global.UI = UI;
})(window);
