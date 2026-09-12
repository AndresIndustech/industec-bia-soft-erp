/* =========================================================================
   busqueda.js — Buscar una orden o un aviso escribiendo un pedazo del número.

   POR QUE EXISTE
     El buscador de `casos.php` y `ordenes.php` exigía escribir y pulsar
     «Filtrar», y eso recarga la página entera. Con 900 casos en pantalla, dar
     con la orden que preguntan por teléfono eran tres gestos y una espera. Y
     había que teclear el número tal cual: `2466` no encontraba
     `OT-2466-V093-10352936-CNLJ`, porque la comparación era contra el texto
     entero con sus guiones.

   QUE HACE
     Filtra las filas mientras se escribe, sin ir al servidor. Acepta un
     pedazo del número —`2466`, `V093`, `10352936`, `2466 v093`— y tolera los
     guiones, los espacios y los ceros de delante que trae SAP (`000010352936`
     y `10352936` son el mismo aviso). Cuando no hay ninguna coincidencia lo
     dice, con el texto que se buscó a la vista.

   MEJORA PROGRESIVA, COMO `guia.js`
     Si este archivo no carga, el formulario sigue enviándose al servidor y
     filtra igual: la lógica del servidor no se toca ni se sustituye. Por eso
     el botón «Filtrar» se queda donde está.

   EL CONTRATO CON EL SERVIDOR
     El servidor imprime en cada fila `data-b` con el mismo texto sobre el que
     él busca, ya pasado por `Busqueda::normalizar()` (nucleo/Ui.php). Cliente y
     servidor buscan sobre lo mismo, así que escribir con JS y sin JS da el
     mismo resultado. `prueba_contratos.mjs` comprueba que las dos
     normalizaciones no se separen.
   ========================================================================= */
(function (global) {
  'use strict';

  var Busqueda = {};

  /* --- Normalización -----------------------------------------------------
     Tiene que dar exactamente lo mismo que `Busqueda::normalizar()` en PHP.
     Si se cambia aquí, se cambia allá y se corre la prueba de contratos.

     Se quitan tildes y todo lo que no sea letra o número, así `OT-2466-V093`,
     `ot 2466 v093` y `OT2466V093` son la misma cadena. El rango ̀-ͯ
     es el bloque de diacríticos combinados que deja `normalize('NFD')`; va
     como escape para que el archivo aguante pasar por FTP y editores con otra
     codificación. */
  Busqueda.normalizar = function (s) {
    s = String(s == null ? '' : s).toLowerCase();
    if (s.normalize) {
      s = s.normalize('NFD').replace(/[̀-ͯ]/g, '');
    } else {
      s = s.replace(/[áàä]/g, 'a').replace(/[éèë]/g, 'e')
           .replace(/[íìï]/g, 'i').replace(/[óòö]/g, 'o')
           .replace(/[úùü]/g, 'u').replace(/ñ/g, 'n');
    }
    return s.replace(/[^a-z0-9]+/g, '');
  };

  /* Los avisos de SAP viajan con ceros delante (`000010352936`) y se muestran
     sin ellos. Para que buscar cualquiera de las dos formas encuentre el mismo
     caso, se genera además la versión con los ceros iniciales de cada grupo de
     dígitos quitados. */
  Busqueda.sinCeros = function (s) {
    return s.replace(/0+(\d)/g, '$1');
  };

  /* --- ¿Esta fila calza? -------------------------------------------------
     Se parte el término en palabras y se exigen TODAS: escribir `2466 k191`
     busca la fila que tenga las dos cosas, no las que tengan cualquiera. Es lo
     que hace útil buscar «freidora k191». */
  Busqueda.calza = function (heno, termino) {
    var t = String(termino || '').trim();
    if (t === '') { return true; }
    var pajar = heno + ' ' + Busqueda.sinCeros(heno);
    var partes = t.split(/\s+/);
    for (var i = 0; i < partes.length; i++) {
      var p = Busqueda.normalizar(partes[i]);
      if (p === '') { continue; }
      if (pajar.indexOf(p) === -1 && pajar.indexOf(Busqueda.sinCeros(p)) === -1) {
        return false;
      }
    }
    return true;
  };

  /* --- El buscador de una tabla ---------------------------------------- */
  function montar(input) {
    var tabla = document.querySelector(input.getAttribute('data-busca'));
    if (!tabla) { return; }
    var cuerpo = tabla.tBodies[0];
    if (!cuerpo) { return; }

    // Solo las filas que el servidor marcó como buscables. La fila de «no hay
    // casos con esos filtros», que no lleva `data-b`, no se toca.
    var filas = Array.prototype.filter.call(cuerpo.rows, function (f) {
      return f.hasAttribute('data-b');
    });
    if (!filas.length) { return; }

    var columnas = filas[0].cells.length || 1;
    var contador = document.querySelector(input.getAttribute('data-busca-cuenta') || '');
    var plantilla = contador ? contador.getAttribute('data-plantilla') || '{n}' : '';

    // La fila de «no se encontró» se crea una vez y se enseña o se esconde:
    // recrearla en cada tecla hacía parpadear la tabla.
    var vacia = document.createElement('tr');
    vacia.className = 'sin-resultados';
    vacia.hidden = true;
    var celda = document.createElement('td');
    celda.colSpan = columnas;
    celda.className = 'vacio';
    vacia.appendChild(celda);
    cuerpo.appendChild(vacia);

    function pintar() {
      var t = input.value;
      var n = 0;
      for (var i = 0; i < filas.length; i++) {
        var ok = Busqueda.calza(filas[i].getAttribute('data-b') || '', t);
        filas[i].hidden = !ok;
        if (ok) { n++; }
      }

      if (n === 0 && t.trim() !== '') {
        // Se muestra qué se buscó: si el número venía de un WhatsApp mal
        // copiado, verlo escrito es la mitad del diagnóstico.
        celda.textContent = 'No se encontró nada con «' + t.trim() + '».';
        var pista = document.createElement('div');
        pista.style.marginTop = '6px';
        pista.style.fontSize = '12px';
        pista.textContent = 'Prueba con menos caracteres: basta una parte del '
                          + 'número, del local o del equipo.';
        celda.appendChild(pista);
        vacia.hidden = false;
      } else {
        vacia.hidden = true;
      }

      if (contador) {
        contador.textContent = t.trim() === ''
          ? plantilla.replace('{n}', String(filas.length))
          : n + ' de ' + filas.length;
      }
    }

    var espera = null;
    input.addEventListener('input', function () {
      // 120 ms: lo justo para no recorrer 900 filas por cada tecla, y poco
      // suficiente para que se sienta inmediato.
      if (espera) { clearTimeout(espera); }
      espera = setTimeout(pintar, 120);
    });

    // Enter no recarga la página: el resultado ya está aquí. Escape limpia.
    input.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        if (espera) { clearTimeout(espera); }
        pintar();
      }
      if (ev.key === 'Escape' && input.value !== '') {
        input.value = '';
        pintar();
      }
    });

    input.setAttribute('autocomplete', 'off');
    input.setAttribute('enterkeyhint', 'search');
    if (input.value.trim() !== '') { pintar(); }
  }

  function arranque() {
    document.querySelectorAll('input[data-busca]').forEach(montar);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', arranque);
  } else {
    arranque();
  }

  global.Busqueda = Busqueda;
})(window);
