/* =========================================================================
   cola.js — La cola de envíos del técnico. Lo que se llena sin señal.

   ============================================================================
   EL PROBLEMA

   El técnico llena la orden en la cocina de un local de Cuenca donde no entra
   el dato. Pulsa «Enviar». Antes de esto pasaba una de dos cosas, las dos
   malas: o el botón no hacía nada y él perdía veinte minutos de trabajo, o
   creía que se había ido y nunca llegó.

   ============================================================================
   COMO SE RESUELVE

   La orden se guarda ENTERA en el celular antes de intentar nada. A partir de
   ahí el envío es cosa del programa: sale sola cuando vuelve la señal, y
   mientras tanto el técnico ve dónde quedó.

   Tres detalles que parecen menores y no lo son:

   1. **La orden se guarda ANTES del primer intento, no después de que falle.**
      Si se guardara al fallar, la orden que se pierde es justo la del caso en
      que el navegador se cierra a mitad del envío.

   2. **El UUID lo genera el celular, una sola vez, al guardar.** Viaja con
      cada reintento. Sin señal el reintento es la norma —la red vuelve a
      medias, el envío sale, la respuesta no llega— y sin una clave estable el
      servidor no puede distinguir «la mandó dos veces» de «se reintentó el
      mismo envío». Una orden duplicada es un correlativo quemado y un segundo
      PDF a Grupo KFC.

   3. **Un rechazo del servidor NO se reintenta.** Si la orden llegó y el
      servidor dijo que le falta un campo, mandarla mil veces no la va a
      arreglar: se marca, se le dice al técnico qué pasó, y se queda ahí para
      que la corrija. Solo se reintenta lo que falló por RED.

   ============================================================================
   POR QUE IndexedDB Y NO localStorage

   `localStorage` guarda texto y ronda los 5 MB. Una orden lleva fotos y una
   firma: dos órdenes con evidencia lo revientan, y cuando revienta lanza una
   excepción a mitad de la escritura y deja la cola a medias. IndexedDB guarda
   objetos —los archivos tal cual, sin convertirlos a texto— y tiene espacio de
   verdad.

   ============================================================================
   LO QUE ESTO TODAVIA NO HACE, Y HAY QUE DECIRLO

   El servidor recibe la orden y la guarda completa, pero **no genera el PDF ni
   manda el correo**: eso vive en la migración 003 (`correlativos` con reserva
   atómica y `email_queue`), que sigue sin aplicarse. Así que la orden llega y
   queda a salvo, pero el informe al local todavía sale por el camino de hoy.
   La pantalla lo dice con esas palabras en vez de fingir que ya salió (I-7).
   ========================================================================= */
(function (global) {
  'use strict';

  var BD = 'ot-industec', TIENDA = 'cola', VERSION = 1;
  var ENDPOINT = 'envio.php';
  var bd = null;

  function abrir() {
    if (bd) { return Promise.resolve(bd); }
    return new Promise(function (ok, mal) {
      var p = indexedDB.open(BD, VERSION);
      p.onupgradeneeded = function () {
        var d = p.result;
        if (!d.objectStoreNames.contains(TIENDA)) {
          var t = d.createObjectStore(TIENDA, { keyPath: 'uuid' });
          t.createIndex('estado', 'estado');
          t.createIndex('creado', 'creado');
        }
      };
      p.onsuccess = function () { bd = p.result; ok(bd); };
      p.onerror = function () { mal(p.error); };
    });
  }

  function tx(modo, fn) {
    return abrir().then(function (d) {
      return new Promise(function (ok, mal) {
        var t = d.transaction(TIENDA, modo);
        var r = fn(t.objectStore(TIENDA));
        t.oncomplete = function () { ok(r && r.result !== undefined ? r.result : r); };
        t.onerror = function () { mal(t.error); };
      });
    });
  }

  /* UUID v4. `crypto.randomUUID` no está en los WebView viejos de Android que
     todavía hay en los celulares del equipo, así que hay respaldo. */
  function uuid() {
    if (global.crypto && global.crypto.randomUUID) { return global.crypto.randomUUID(); }
    var b = new Uint8Array(16);
    (global.crypto || global.msCrypto).getRandomValues(b);
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    var h = [];
    for (var i = 0; i < 16; i++) { h.push((b[i] + 0x100).toString(16).slice(1)); }
    return h.slice(0, 4).join('') + '-' + h.slice(4, 6).join('') + '-' +
           h.slice(6, 8).join('') + '-' + h.slice(8, 10).join('') + '-' + h.slice(10).join('');
  }

  var Cola = {};

  /**
   * Guardar una orden y ponerla en camino.
   *
   * Devuelve el UUID en cuanto está a salvo en el disco del celular. El envío
   * arranca después y no bloquea: el técnico ya puede cerrar la pantalla.
   */
  Cola.encolar = function (orden) {
    var fila = {
      uuid: uuid(),
      creado: new Date().toISOString(),
      estado: 'PENDIENTE',
      intentos: 0,
      ultimo_error: null,
      resumen: {
        local: orden.local || orden.local_codigo || '',
        aviso: orden.aviso || '',
        caso: orden.caso || ''
      },
      orden: orden
    };
    return tx('readwrite', function (t) { t.put(fila); })
      .then(function () {
        pintar();
        // Se intenta enseguida, pero sin esperar a que termine: si hay señal
        // sale en un segundo, y si no, ya está guardada.
        setTimeout(enviarTodo, 60);
        return fila.uuid;
      });
  };

  Cola.pendientes = function () {
    return tx('readonly', function (t) { return t.getAll(); })
      .then(function (todas) {
        return (todas || []).filter(function (f) { return f.estado !== 'ENVIADA'; });
      })
      .catch(function () { return []; });
  };

  Cola.olvidar = function (id) {
    return tx('readwrite', function (t) { t.delete(id); }).then(pintar);
  };

  /* --- El envío ----------------------------------------------------------
     Se manda de una en una y en orden de llegada. En paralelo iría más rápido
     y no vale la pena: con señal mala, seis peticiones a la vez se estorban
     entre ellas y fallan todas. */
  var enviando = false;

  function enviarTodo() {
    if (enviando || !navigator.onLine) { return Promise.resolve(); }
    enviando = true;
    return Cola.pendientes()
      .then(function (filas) {
        var cola = filas.filter(function (f) { return f.estado === 'PENDIENTE'; });
        /* De una en una y en orden de llegada. En paralelo iria mas rapido y no
           vale la pena: con senal mala, seis peticiones a la vez se estorban
           entre ellas y fallan todas.

           El `catch` por eslabon es lo que evita que una orden que falla al
           GUARDARSE corte la cadena y deje las siguientes sin intentar. */
        return cola.reduce(function (p, f) {
          return p.then(function () {
            return enviarUna(f).catch(function () { /* la siguiente sigue */ });
          });
        }, Promise.resolve());
      })
      .then(function () { enviando = false; pintar(); })
      .catch(function () { enviando = false; pintar(); });
  }

  function enviarUna(fila) {
    return fetch(ENDPOINT, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        envio_uuid: fila.uuid,
        capturada_en: fila.creado,
        orden: fila.orden
      })
    })
      .then(function (r) {
        return r.json().catch(function () { return { ok: false, motivo: 'respuesta ilegible' }; })
          .then(function (j) { return { http: r.status, cuerpo: j }; });
      })
      .then(function (res) {
        if (res.http === 200 && res.cuerpo.ok) {
          fila.estado = 'ENVIADA';
          fila.recibo = res.cuerpo.recibo || null;
          return tx('readwrite', function (t) { t.put(fila); }).then(function () {
            if (global.UI) {
              UI.toast('Orden de ' + (fila.resumen.local || 'el local') + ' enviada.', 'ok');
            }
          });
        }
        if (res.http === 401 || res.http === 403) {
          /* LA SESION SE CAYO. Es un 4xx, pero NO es «la orden no sirve»: la
             orden está perfecta y lo único que falta es volver a entrar.
             Tratarlo como rechazo la marcaría como inválida y no se
             reintentaría nunca — veinte minutos de trabajo del técnico
             perdidos por una sesión caducada, que es lo que pasa siempre que
             el celular estuvo horas sin señal.

             Se queda PENDIENTE y se le dice qué hacer. En cuanto vuelva a
             entrar, el siguiente intento la manda. */
          fila.intentos++;
          fila.ultimo_error = 'hay que volver a entrar al sistema';
          return tx('readwrite', function (t) { t.put(fila); }).then(function () {
            if (global.UI) {
              UI.toast('Tu sesión se cerró. Vuelve a entrar y la orden se envía sola.', 'warn',
                       { vida: 9000 });
            }
          });
        }
        if (res.http >= 400 && res.http < 500) {
          /* El servidor la recibió y la rechazó por lo que trae: le falta un
             campo, el local no existe. Reintentar no la arregla, así que se
             marca y se le dice al técnico qué corregir. Es la diferencia entre
             un problema de red y un problema de la orden. */
          fila.estado = 'RECHAZADA';
          fila.ultimo_error = res.cuerpo.motivo || ('el servidor la rechazó (' + res.http + ')');
          return tx('readwrite', function (t) { t.put(fila); }).then(function () {
            if (global.UI) { UI.toast('Una orden no se pudo enviar: ' + fila.ultimo_error, 'err'); }
          });
        }
        // 5xx o error de red: es del servidor o del camino, y eso sí se
        // reintenta. Se cuenta el intento para poder avisar si no cede.
        fila.intentos++;
        fila.ultimo_error = 'no se pudo enviar todavía';
        return tx('readwrite', function (t) { t.put(fila); });
      })
      .catch(function () {
        fila.intentos++;
        fila.ultimo_error = 'sin conexión';
        return tx('readwrite', function (t) { t.put(fila); });
      });
  }

  /* --- Lo que ve el técnico ---------------------------------------------
     Un recuadro con cuántas van y en qué estado. Sin esto la cola es un
     mecanismo invisible, y a lo invisible no se le tiene confianza: el técnico
     vuelve a llenar la orden «por si acaso» y llegan dos. */
  function pintar() {
    var caja = document.getElementById('cola');
    if (!caja) { return; }

    Cola.pendientes().then(function (filas) {
      if (!filas.length) { caja.hidden = true; caja.innerHTML = ''; return; }

      var rechazadas = filas.filter(function (f) { return f.estado === 'RECHAZADA'; });
      var enCurso = filas.length - rechazadas.length;
      /* Tres situaciones distintas y tres mensajes distintos. Meterlas en uno
         solo —«no se pudo enviar»— haria que el tecnico intente arreglar una
         orden que esta perfecta, o que espere senal cuando lo que falta es
         volver a entrar. */
      var sesion = filas.some(function (f) {
        return f.estado === 'PENDIENTE' && f.ultimo_error === 'hay que volver a entrar al sistema';
      });

      var clase = rechazadas.length ? 'cola'
                : sesion ? 'cola'
                : (navigator.onLine ? 'cola enviando' : 'cola');

      var titulo, detalle;
      if (rechazadas.length) {
        titulo = rechazadas.length === 1
          ? 'Una orden no se pudo enviar'
          : rechazadas.length + ' órdenes no se pudieron enviar';
        detalle = 'El servidor las recibió y las devolvió. Ábrelas, corrige lo que falta y vuelve a enviarlas.';
      } else if (sesion) {
        titulo = enCurso === 1
          ? '1 orden esperando que vuelvas a entrar'
          : enCurso + ' órdenes esperando que vuelvas a entrar';
        detalle = 'Tu sesión se cerró. Están a salvo: entra al sistema y se envían solas.';
      } else if (navigator.onLine) {
        titulo = enCurso === 1 ? 'Enviando 1 orden…' : 'Enviando ' + enCurso + ' órdenes…';
        detalle = 'Se están enviando solas. No hace falta que hagas nada.';
      } else {
        titulo = enCurso === 1
          ? '1 orden guardada, esperando señal'
          : enCurso + ' órdenes guardadas, esperando señal';
        detalle = 'Están a salvo en este celular. Salen solas en cuanto vuelva la señal, aunque cierres la aplicación.';
      }

      var html = '<div class="fila"><span class="n">' + filas.length + '</span>' +
                 '<div><b>' + esc(titulo) + '</b><div style="margin-top:2px">' + esc(detalle) + '</div></div></div><ul>';

      filas.forEach(function (f) {
        var etiqueta = f.estado === 'RECHAZADA' ? 'rechazada'
                     : (f.ultimo_error === 'hay que volver a entrar al sistema' ? 'falta entrar'
                     : (navigator.onLine ? 'enviando' : 'en espera'));
        html += '<li><span>' + esc(f.resumen.local || 'Orden sin local') +
                (f.resumen.aviso ? ' · aviso ' + esc(f.resumen.aviso) : '') + '</span>' +
                '<span class="est-envio">' + etiqueta + '</span></li>';
        if (f.estado === 'RECHAZADA' && f.ultimo_error) {
          html += '<li style="background:transparent;padding:0 9px 6px;font-size:12px">' +
                  esc(f.ultimo_error) + '</li>';
        }
      });
      html += '</ul>';

      caja.className = clase;
      caja.innerHTML = html;
      caja.hidden = false;
    });
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  /* --- Cuándo se intenta -------------------------------------------------
     Al cargar, al recuperar la señal, al volver a la pestaña, y cada dos
     minutos por si el evento `online` mintió — pasa: Android lo dispara al
     asociarse al wifi, antes de que haya salida a internet. */
  function arranque() {
    pintar();
    enviarTodo();
    global.addEventListener('online', function () { pintar(); enviarTodo(); });
    global.addEventListener('offline', pintar);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) { enviarTodo(); }
    });
    setInterval(enviarTodo, 120000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', arranque);
  } else {
    arranque();
  }

  Cola.enviarTodo = enviarTodo;
  Cola.pintar = pintar;
  global.Cola = Cola;
})(window);
