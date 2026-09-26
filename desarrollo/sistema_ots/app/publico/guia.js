/* =========================================================================
   guia.js — El formulario, preguntado por pasos.

   ============================================================================
   EL PROBLEMA QUE RESUELVE

   El formato único cubre correctivo y preventivo, con repuesto y sin él, con
   uno o con siete equipos. Puesto entero en pantalla son más de cuarenta
   campos, y el técnico tiene que decidir cuáles le tocan — que es exactamente
   la decisión que el sistema ya sabe tomar. Doce secciones seguidas en un
   celular son doce pantallas de desplazamiento en las que es fácil saltarse
   una y descubrirlo al final, cuando la validación bloquea.

   Preguntando primero **qué va a hacer** y **dónde**, el resto se arma solo y
   quedan a la vista únicamente los campos de ESE caso.

   ============================================================================
   POR QUE ESTA EN UN ARCHIVO APARTE, Y DESPUES DE app.js

   Es **mejora progresiva**: envuelve secciones que ya funcionan sin él. Si
   este guion no carga —una caché rara, un error de red, un navegador viejo—
   el técnico ve el formulario de siempre, entero y usable. Nada de lo que
   valida, calcula o envía vive aquí.

   Por eso también trabaja sobre la estructura (`<h2>` y sus hermanos) en vez
   de exigir un marcado especial: agregar una sección al formulario no obliga a
   tocar este archivo.

   ============================================================================
   TRES DECISIONES DE DISEÑO, CON SU MOTIVO

   1. **Nunca se bloquea el retroceso.** Cualquier paso ya abierto se puede
      volver a abrir y corregir sin perder lo escrito. Un asistente que no deja
      volver es peor que una lista larga.

   2. **Los pasos que no tocan se ven, pero plegados y en gris.** No se
      esconden: el técnico tiene que poder ver cuánto le falta. Un formulario
      que revela pasos de a uno sin decir cuántos son se siente infinito.

   3. **Un paso se marca completo cuando sus obligatorios están llenos**, y ahí
      se abre el siguiente solo. Nada de un botón «Siguiente» que hay que
      buscar con el pulgar en la parte de abajo de la pantalla.
   ========================================================================= */
(function () {
  'use strict';

  var form = document.getElementById('otForm');
  if (!form) { return; }

  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };

  /* -----------------------------------------------------------------------
     1. Envolver cada sección en su paso.
     Se recorre el formulario tomando cada <h2> y todo lo que le sigue hasta el
     próximo <h2>. Lo que va antes del primer <h2> (si hubiera algo) se deja
     donde está.
     ----------------------------------------------------------------------- */
  var pasos = [];

  /* Lo que NO se envuelve, y por qué.
   *
   * El panel de validación y el botón de enviar están al final del formulario,
   * después del último <h2>. Sin esta lista se los tragaba el último paso —
   * «Firma del administrador»— y como solo hay un paso abierto a la vez,
   * quedaban **plegados e inalcanzables**: el técnico llenaba la orden entera y
   * no tenía dónde pulsar para enviarla. Se detectó leyendo qué había después
   * del último <h2>, no probando, así que vale dejarlo escrito.
   *
   * Se sacan de los pasos y se vuelven a pegar al final del formulario, donde
   * están siempre visibles. */
  var SIEMPRE_VISIBLE = '#panelValidacion, .footer';

  function envolver() {
    var hijos = Array.prototype.slice.call(form.children);
    var actual = null;
    var cola = [];

    hijos.forEach(function (nodo) {
      if (nodo.nodeType === 1 && nodo.matches && nodo.matches(SIEMPRE_VISIBLE)) {
        cola.push(nodo);
        return;
      }
      if (nodo.tagName === 'H2') {
        var caja = document.createElement('section');
        caja.className = 'paso-caja';

        var cab = document.createElement('button');
        cab.type = 'button';                    // NUNCA submit: dentro de un form,
        cab.className = 'cab';                  // un <button> sin type envía.

        var tit = document.createElement('span');
        tit.className = 'tit';
        tit.textContent = nodo.textContent;

        var resu = document.createElement('span');
        resu.className = 'resu';

        var flecha = document.createElement('span');
        flecha.className = 'flecha';
        flecha.setAttribute('aria-hidden', 'true');
        flecha.textContent = '›';

        var envTit = document.createElement('span');
        envTit.style.flex = '1';
        envTit.style.minWidth = '0';
        envTit.appendChild(tit);
        envTit.appendChild(resu);

        cab.appendChild(envTit);
        cab.appendChild(flecha);

        var cuerpo = document.createElement('div');
        cuerpo.className = 'cuerpo';

        caja.appendChild(cab);
        caja.appendChild(cuerpo);
        form.insertBefore(caja, nodo);
        nodo.remove();                          // el <h2> pasa a ser la cabecera

        /* `paso` es de esta vuelta; `actual` es compartido y se reasigna en cada
           <h2>. El clic tiene que cerrar sobre el primero: cuando cerraba sobre
           `actual`, para cuando alguien pulsaba ya valía el ÚLTIMO paso, así que
           `pasos.indexOf(actual)` daba siempre 11 y CUALQUIER cabecera abría
           «Firma del administrador». El técnico no podía abrir ni «La orden» ni
           «Datos generales», y el caso que venía precargado desde la bandeja no
           se veía por ningún lado (Andrés, caso 10355894, 2026-09-22). */
        var paso = { caja: caja, cab: cab, cuerpo: cuerpo, resu: resu,
                     titulo: tit.textContent, abierto: false, visitado: false };
        actual = paso;
        pasos.push(paso);

        cab.addEventListener('click', function () {
          // Un paso pendiente no se abre a golpes: primero hay que completar
          // el anterior. Pero uno ya visitado siempre se puede reabrir.
          if (caja.classList.contains('pendiente')) { return; }
          abrir(pasos.indexOf(paso), true);
        });
        return;
      }
      if (actual) { actual.cuerpo.appendChild(nodo); }
    });

    /* La validación y el botón de enviar, de vuelta al final del formulario y
       fuera de todo paso. `appendChild` sobre un nodo que ya está en el árbol
       lo mueve, y mover conserva los listeners que app.js ya le puso. */
    cola.forEach(function (n) { form.appendChild(n); });
  }

  /* -----------------------------------------------------------------------
     2. La miga de pan.
     Da la única cosa que un formulario largo nunca dice: cuántos pasos son y
     en cuál vas.
     ----------------------------------------------------------------------- */
  var miga = null;

  function migaPan() {
    miga = document.createElement('nav');
    miga.className = 'pasos';
    miga.setAttribute('aria-label', 'Pasos de la OT INDUSTEC');
    pasos.forEach(function (p, i) {
      var d = document.createElement('div');
      d.className = 'p';
      var n = document.createElement('span');
      n.className = 'n';
      var num = document.createElement('span');
      num.textContent = String(i + 1);
      n.appendChild(num);
      d.appendChild(n);
      d.appendChild(document.createTextNode(p.titulo));
      miga.appendChild(d);
      p.miga = d;
    });
    form.insertBefore(miga, form.firstChild);
  }

  /* -----------------------------------------------------------------------
     3. Abrir, cerrar, y saber si un paso está completo.
     ----------------------------------------------------------------------- */
  function abrir(i, porClic) {
    pasos.forEach(function (p, k) {
      var esta = (k === i);
      p.abierto = esta;
      // Un paso sin ningún campo obligatorio (ok === null) -- «Evidencia»,
      // «¿Viste algo más?», «Estado de la orden», «Firma»-- antes solo se
      // marcaba listo si era el primero: los demás quedaban con el ✓ apagado
      // para siempre, aunque el técnico ya los hubiera abierto y llenado.
      // Basta con haberlo abierto una vez.
      if (esta) { p.visitado = true; }
      p.caja.classList.toggle('abierto', esta);
      if (p.miga) { p.miga.classList.toggle('activo', esta); }
    });
    if (porClic) {
      // Se desplaza a la cabecera, no al primer campo: si se enfoca el campo,
      // el teclado del celular sube y tapa el título del paso.
      pasos[i].caja.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    repintar();
  }

  /**
   * ¿Están llenos los obligatorios de este paso?
   *
   * Se mira lo que el navegador ya sabe (`required` y `:invalid`) en vez de
   * mantener una lista de campos por paso. Una lista se desincroniza el día
   * que alguien agrega un campo al HTML; esto no.
   *
   * Los campos CONDICIONALMENTE escondidos no cuentan (H-15): es el caso del
   * día de intervención, que es obligatorio solo en preventivo. Eso se decide
   * mirando el atributo `hidden` de los ancestros -- que es como app.js
   * esconde `#wrapDia`, `#wrapTrabado`, etc. -- y NO la geometría
   * (`offsetParent`/`offsetWidth`/`offsetHeight`): esos tres dan cero para
   * CUALQUIER campo de un paso plegado, porque `.paso-caja:not(.abierto) >
   * .cuerpo { display:none }` los esconde a todos, estén o no respondidos
   * (H-06). Con la geometría, un paso ya lleno se veía "incompleto" en cuanto
   * se plegaba, y perdía el ✓ y el resumen.
   */
  function completo(p) {
    var obligatorios = $$('[required]', p.cuerpo).filter(function (el) { return aplica(el, p.cuerpo); });
    if (!obligatorios.length) { return null; }      // sin obligatorios: no se opina
    return obligatorios.every(function (el) {
      return String(el.value || '').trim() !== '';
    });
  }

  /** ¿El campo aplica de verdad, o está dentro de un bloque condicional
   *  escondido con `hidden` (no con el plegado del propio paso)? */
  function aplica(el, cuerpo) {
    for (var n = el; n && n !== cuerpo; n = n.parentElement) {
      if (n.hidden) { return false; }
    }
    return true;
  }

  /** Un resumen de una línea de lo que se llenó, para el paso plegado. */
  function resumir(p) {
    var trozos = [];
    $$('input, select, textarea', p.cuerpo).forEach(function (el) {
      if (!aplica(el, p.cuerpo) || el.type === 'hidden' || el.type === 'file') { return; }
      /* Una casilla sin atributo `value` vale la cadena "on" ESTÉ MARCADA O NO,
         así que el primer paso se resumía «Correctivo · on» por la casilla de
         "otro proveedor", que nadie había tocado. Lo que resume una casilla es
         su etiqueta, y solo si está marcada. */
      var v;
      if (el.type === 'checkbox' || el.type === 'radio') {
        if (!el.checked) { return; }
        var etiqueta = el.closest('label');
        v = String(etiqueta ? etiqueta.textContent : el.value).trim();
      } else {
        v = String(el.value || '').trim();
      }
      if (!v || v === 'Lo asigna el servidor') { return; }
      if (el.tagName === 'SELECT' && el.selectedOptions[0]) {
        v = el.selectedOptions[0].textContent.trim();
      }
      if (v.length > 26) { v = v.slice(0, 26) + '…'; }
      trozos.push(v);
    });
    // Los controles segmentados y las tarjetas de opción no son campos, pero
    // son la respuesta principal de varios pasos.
    $$('.seg button.on, .opciones .opcion.on', p.cuerpo).forEach(function (b) {
      var t = $('.t', b);
      trozos.unshift((t ? t.textContent : b.textContent).trim());
    });
    return trozos.slice(0, 3).join(' · ');
  }

  function repintar() {
    var previoListo = true;
    pasos.forEach(function (p, i) {
      var ok = completo(p);
      var listo = ok === true || (ok === null && (i === 0 || p.visitado));

      p.caja.classList.toggle('listo', listo && !p.abierto);
      if (p.miga) {
        p.miga.classList.toggle('listo', listo);
      }

      // Un paso queda pendiente —gris y no abrible— si el anterior no está
      // resuelto. El primero nunca es pendiente.
      var pendiente = i > 0 && !previoListo && !p.abierto;
      p.caja.classList.toggle('pendiente', pendiente);

      p.resu.textContent = p.abierto ? '' : (listo ? resumir(p) : '');
      if (ok !== null) { previoListo = previoListo && listo; }
    });
  }

  /* Al completar el paso abierto se ofrece el siguiente, pero NO se salta solo:
     un formulario que cambia de sección debajo del dedo mientras se escribe es
     desorientante. Se marca en verde y el técnico decide. */
  form.addEventListener('input', repintar);
  form.addEventListener('change', repintar);

  /* -----------------------------------------------------------------------
     4. Lo condicional: qué aparece según lo que se responda.
     ----------------------------------------------------------------------- */
  function condicionales() {
    // ¿Concluyó el trabajo? Es la premisa del servicio hecha pregunta, y su
    // respuesta abre el único camino previsto para no concluir.
    var seg = document.getElementById('segConcluye');
    if (seg) {
      seg.addEventListener('click', function (ev) {
        var b = ev.target.closest('button');
        if (!b) { return; }
        $$('button', seg).forEach(function (x) { x.classList.remove('on'); });
        b.classList.add('on');
        var si = b.dataset.v === 'si';
        document.getElementById('concluida').value = si ? '1' : '0';
        var w = document.getElementById('wrapTrabado');
        w.hidden = si;
        // El diagnóstico pasa a ser obligatorio solo cuando aplica. Marcarlo
        // `required` en el HTML bloquearía todas las órdenes que sí concluyen.
        var d = document.getElementById('pen_diagnostico');
        if (d) { d.required = !si; }
        if (!si) { w.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        repintar();
      });
    }

    // El día de intervención es obligatorio en preventivo y no existe en
    // correctivo. `aplicarTipo()` de app.js ya muestra u oculta el bloque;
    // aquí solo se sincroniza el `required` para que el paso se pueda dar por
    // completo en los correctivos.
    var segTipo = document.getElementById('segTipo');
    if (segTipo) {
      segTipo.addEventListener('click', function () {
        setTimeout(function () {
          var dia = document.getElementById('dia_intervencion');
          var wrap = document.getElementById('wrapDia');
          if (dia && wrap) { dia.required = !wrap.hidden; }
          repintar();
        }, 0);
      });
    }
  }

  /* -----------------------------------------------------------------------
     5. Las novedades de la visita.
     Bloques que se agregan a demanda. Cada uno nace con su propio UUID: es la
     clave que hace que un reintento sin señal no registre la misma novedad dos
     veces, igual que en el envío de la orden.
     ----------------------------------------------------------------------- */
  var AREAS = [
    ['EQUIPO_CORRECTIVO', 'Un equipo que va a fallar'],
    ['ELECTRICO',         'Instalación eléctrica'],
    ['VENTILACION',       'Ventilación o extracción'],
    ['DESAGUE',           'Desagüe'],
    ['AGUA',              'Suministro de agua'],
    ['GAS',               'Gas'],
    ['REFRIGERACION',     'Refrigeración del local'],
    ['CONSTRUCTIVO',      'Obra civil'],
    ['SEGURIDAD',         'Riesgo para las personas'],
    ['OTRO',              'Otra cosa']
  ];

  function uuid() {
    if (window.crypto && window.crypto.randomUUID) { return window.crypto.randomUUID(); }
    var b = new Uint8Array(16);
    window.crypto.getRandomValues(b);
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    var h = [];
    for (var i = 0; i < 16; i++) { h.push((b[i] + 0x100).toString(16).slice(1)); }
    return h.slice(0, 4).join('') + '-' + h.slice(4, 6).join('') + '-' +
           h.slice(6, 8).join('') + '-' + h.slice(8, 10).join('') + '-' + h.slice(10).join('');
  }

  function opciones(lista, sel) {
    return lista.map(function (o) {
      var v = Array.isArray(o) ? o[0] : o, t = Array.isArray(o) ? o[1] : o;
      return '<option value="' + v + '"' + (v === sel ? ' selected' : '') + '>' + t + '</option>';
    }).join('');
  }

  function novedades() {
    var cont = document.getElementById('novedadesVisita');
    var btn = document.getElementById('addNovedad');
    if (!cont || !btn) { return; }

    btn.addEventListener('click', function () {
      var n = cont.children.length + 1;
      var b = document.createElement('div');
      b.className = 'bloque';
      b.dataset.uuid = uuid();
      b.innerHTML =
        '<div class="bloque-tit"><span>Novedad ' + n + '</span>' +
        '<button type="button" class="btn danger nov-quitar">Quitar</button></div>' +
        '<label>¿De qué es?</label>' +
        '<select class="nov-tipo">' + opciones(AREAS, 'EQUIPO_CORRECTIVO') + '</select>' +
        '<span class="derivado">Lo que no es un equipo le toca a otra área del local, ' +
        'y suele ser la causa de que el mismo equipo falle varias veces.</span>' +
        '<div class="grid g2" style="margin-top:10px">' +
        '<div><label>Qué equipo o dónde</label>' +
        '<input type="text" class="nov-equipo" placeholder="freidora 2, campana de la plancha…"></div>' +
        '<div><label>¿Qué tan grave?</label><select class="nov-riesgo">' +
        opciones([['MEDIO', 'Medio — hay que verlo'], ['ALTO', 'Alto — va a parar un equipo o es peligroso'],
                  ['BAJO', 'Bajo — se puede planificar']], 'MEDIO') + '</select></div></div>' +
        '<label style="margin-top:10px">Qué viste</label>' +
        '<textarea class="nov-desc" rows="2" placeholder="Descríbelo como se lo contarías a tu jefe de zona"></textarea>' +
        '<label style="margin-top:10px">¿De quién crees que es?</label>' +
        '<select class="nov-resp">' +
        opciones([['INDUSTEC', 'De INDUSTEC'], ['CLIENTE', 'De Grupo KFC'],
                  ['TERCERO', 'De un tercero']], 'INDUSTEC') + '</select>' +
        '<span class="derivado">Es tu opinión, no la decisión: quien resuelve es tu jefe de ' +
        'zona o la administración.</span>';

      b.querySelector('.nov-quitar').addEventListener('click', function () {
        b.remove();
        renumerar(cont);
        repintar();
      });
      cont.appendChild(b);
      b.querySelector('.nov-desc').focus();
      repintar();
    });
  }

  function renumerar(cont) {
    $$('.bloque', cont).forEach(function (b, i) {
      var t = $('.bloque-tit span', b);
      if (t) { t.textContent = 'Novedad ' + (i + 1); }
    });
  }

  /* -----------------------------------------------------------------------
     Arranque. Va detrás de app.js, así que el formulario ya está armado.
     ----------------------------------------------------------------------- */
  try {
    envolver();
    if (!pasos.length) { return; }
    migaPan();
    condicionales();
    novedades();
    abrir(0, false);
  } catch (err) {
    /* Si algo falla aquí, el formulario tiene que quedar completo y usable: es
       mejora progresiva, no un requisito. Se deshace el plegado quitando la
       clase que lo esconde, y el técnico ve todas las secciones seguidas. */
    $$('.paso-caja').forEach(function (c) { c.classList.add('abierto'); });
    if (window.console) { console.warn('guia.js:', err); }
  }
})();
