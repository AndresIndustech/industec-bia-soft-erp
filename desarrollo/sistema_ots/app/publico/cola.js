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
   ahí el envío es cosa del programa: sale sola cuando hay señal con la
   aplicación abierta, y mientras tanto el técnico ve dónde quedó.

   Cuatro detalles que parecen menores y no lo son:

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
      arreglar: se marca, se le dice al técnico qué pasó, y se queda ahí.

   4. **La orden es de quien la llenó.** Se guarda su usuario, y si en el mismo
      celular entra otro, el servidor la devuelve (409) en vez de firmarla con
      la sesión del segundo.

   ============================================================================
   POR QUE IndexedDB Y NO localStorage

   `localStorage` guarda texto y ronda los 5 MB. Una orden lleva fotos y una
   firma: dos órdenes con evidencia lo revientan, y cuando revienta lanza una
   excepción a mitad de la escritura y deja la cola a medias. IndexedDB guarda
   objetos y tiene espacio de verdad.

   ============================================================================
   LO QUE ESTO TODAVIA NO HACE, Y HAY QUE DECIRLO

   - No envía con la aplicación cerrada: no hay Background Sync. Sale cuando se
     vuelve a abrir la app con señal, y la pantalla lo dice así.
   - Las fotos suben ANTES que la orden, de una en una (foto.php, T2.13). Si
     una no sube por falta de señal, la orden espera; si el servidor la rechaza
     —no es una imagen, pesa demasiado—, la orden sale sin ella y el PDF dice
     que falta. En el sitio de pruebas el correo al local no sale: queda
     retenido en el servidor, y el recibo lo dice (I-7).
   ========================================================================= */
(function (global) {
  'use strict';

  var BD = 'ot-industec', TIENDA = 'cola', VERSION = 1;
  var ENDPOINT = 'envio.php';
  var ENDPOINT_FOTO = 'foto.php';
  var bd = null;

  // Los motivos que la pantalla reconoce para elegir la etiqueta de cada fila.
  var FALTA_ENTRAR = 'hay que volver a entrar al sistema';
  var AJENA        = 'la llenó otro usuario: tiene que entrar él para enviarla';
  var SIN_PERMISO  = 'tu usuario no tiene permiso para enviar órdenes: avisa a la administración';

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

  /* --- CSRF (T2.14.1, punto 10) -------------------------------------------
     `envio.php` y `foto.php` exigen el token de la sesión en la cabecera
     `X-Csrf`. `yo.php` lo entrega y app.js ya lo consulta al arrancar, pero
     `cola.js` puede mandar un envío mucho después -- con la pantalla ya
     cerrada y reabierta, con la app en segundo plano-- así que se pide su
     propia copia, cacheada, en vez de depender de una variable de app.js. */
  var csrfToken = null;
  function obtenerCsrf() {
    return fetch('yo.php', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (d && d.csrf) { csrfToken = d.csrf; } return csrfToken; })
      .catch(function () { return csrfToken; });
  }
  function conCsrf() { return csrfToken ? Promise.resolve(csrfToken) : obtenerCsrf(); }

  /**
   * Guardar una orden y ponerla en camino.
   *
   * Devuelve el UUID en cuanto está a salvo en el disco del celular. El envío
   * arranca después y no bloquea: el técnico ya puede cerrar la pantalla.
   */
  Cola.encolar = function (orden, usuarioId, fotos) {
    fotos = fotos || [];
    // La orden solo lleva los identificadores de sus fotos; ellas van aparte.
    orden.fotos = fotos.map(function (f) { return f.uuid; });
    var fila = {
      uuid: uuid(),
      creado: new Date().toISOString(),
      estado: 'PENDIENTE',
      intentos: 0,
      ultimo_error: null,
      usuario_id: usuarioId || null,
      resumen: {
        local: orden.local || orden.local_codigo || '',
        aviso: orden.aviso || '',
        caso: orden.caso || ''
      },
      orden: orden,
      /* Las fotos, cada una con su UUID. Se marcan al subir: si la señal se
         corta a mitad, las que ya llegaron no se vuelven a mandar. */
      fotos: fotos.map(function (f, i) { return { uuid: f.uuid, n: i, blob: f.blob, subida: false }; })
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

  /** Una fila de la cola, tal como quedó guardada. `undefined` si no está. */
  Cola.leer = function (uuid) {
    return tx('readonly', function (t) { return t.get(uuid); }).catch(function () { return null; });
  };

  /**
   * «Corregir y reenviar» una orden RECHAZADA (H-07).
   *
   * Vuelve a poner PENDIENTE la MISMA fila (mismo `envio_uuid`: `envio.php` ya
   * es idempotente por esa clave, así que el reenvío no crea una segunda
   * orden). Las fotos que ya se habían subido (`subida: true`) se conservan
   * tal cual -- `subirFotos()` las salta solas-- y las nuevas que traiga
   * `nuevasFotos` se agregan detrás, sin subir todavía.
   */
  Cola.reencolar = function (uuid, orden, usuarioId, nuevasFotos) {
    nuevasFotos = nuevasFotos || [];
    return Cola.leer(uuid).then(function (fila) {
      if (!fila) { throw new Error('esa orden ya no está guardada en este celular'); }
      var previas = fila.fotos || [];
      var base = previas.length;
      var nuevas = nuevasFotos.map(function (f, i) { return { uuid: f.uuid, n: base + i, blob: f.blob, subida: false }; });
      orden.fotos = previas.concat(nuevas).map(function (f) { return f.uuid; });
      fila.orden = orden;
      fila.fotos = previas.concat(nuevas);
      fila.estado = 'PENDIENTE';
      fila.intentos = 0;
      fila.ultimo_error = null;
      fila.espera_usuario = false;
      if (usuarioId) { fila.usuario_id = usuarioId; }
      fila.resumen = {
        local: orden.local || orden.local_codigo || '',
        aviso: orden.aviso || '',
        caso: orden.caso || ''
      };
      return guardar(fila).then(function () {
        pintar();
        setTimeout(enviarTodo, 60);
        return fila.uuid;
      });
    });
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
        // H-20: una orden AJENA o SIN_PERMISO no se va a arreglar sola cada
        // 2 minutos -- necesita que alguien entre con la sesión correcta o
        // que la administración dé el permiso-- así que deja de reintentarse
        // sola. `espera_usuario` se apaga con el botón «Reintentar» de la
        // propia fila, que es una acción de la persona, no del reloj.
        var cola = filas.filter(function (f) { return f.estado === 'PENDIENTE' && !f.espera_usuario; });
        /* El `catch` por eslabón es lo que evita que una orden que falla al
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

  function guardar(fila) {
    return tx('readwrite', function (t) { t.put(fila); });
  }

  function enviarUna(fila) {
    return conCsrf().then(function (token) {
      return subirFotos(fila, token)
        .then(function (seguir) {
          if (!seguir) { return null; }
          return fetch(ENDPOINT, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Csrf': token || '' },
            body: JSON.stringify({
              envio_uuid: fila.uuid,
              capturada_en: fila.creado,
              usuario_captura: fila.usuario_id || null,
              orden: fila.orden
            })
          })
            .then(leerRespuesta)
            .then(function (res) { return tratarRespuesta(fila, res); });
        });
    }).catch(function () {
      fila.intentos++;
      fila.ultimo_error = 'sin conexión';
      return guardar(fila);
    });
  }

  function leerRespuesta(r) {
    return r.json().catch(function () { return { ok: false, motivo: 'respuesta ilegible' }; })
      .then(function (j) { return { http: r.status, cuerpo: j || {} }; });
  }

  /* --- Las fotos, antes que la orden -------------------------------------
     De una en una, como las órdenes: con señal mala, varias a la vez se
     estorban y fallan todas. Devuelve si se puede mandar ya la orden: todas
     subieron, o las que el servidor rechazó quedaron fuera. */
  function subirFotos(fila, token) {
    var faltan = (fila.fotos || []).filter(function (f) { return !f.subida && !f.descartada; });
    var parar = function () { return guardar(fila).then(function () { return false; }); };
    return faltan.reduce(function (p, f) {
      return p.then(function (seguir) {
        if (!seguir) { return false; }
        var datos = new FormData();
        datos.append('envio_uuid', fila.uuid);
        datos.append('foto_uuid', f.uuid);
        datos.append('n', String(f.n));
        datos.append('foto', f.blob, f.uuid + '.jpg');
        return fetch(ENDPOINT_FOTO, { method: 'POST', credentials: 'same-origin',
                                      headers: { 'X-Csrf': token || '' }, body: datos })
          .then(leerRespuesta)
          .then(function (res) {
            if (res.http === 200 && res.cuerpo.ok) {
              // Subida: se suelta la imagen, que es lo que ocupa el celular.
              f.subida = true;
              f.blob = null;
              return guardar(fila).then(function () { return true; });
            }
            if (res.http === 409 && res.cuerpo.ajena) { fila.ultimo_error = AJENA; fila.espera_usuario = true; return parar(); }
            if (res.http === 403 && res.cuerpo.error === 'csrf') {
              // El token cambió o venció: se pide uno nuevo para el próximo
              // intento. Es un problema de sesión, no de la foto: se reintenta.
              csrfToken = null;
              fila.intentos++;
              fila.ultimo_error = 'no se pudo confirmar tu sesión; se reintenta';
              return parar();
            }
            if (res.http === 401 || (res.http === 403 && res.cuerpo.error === 'debe_cambiar_clave')) {
              fila.intentos++;
              fila.ultimo_error = FALTA_ENTRAR;
              return parar();
            }
            if (res.http === 403) { fila.intentos++; fila.ultimo_error = SIN_PERMISO; fila.espera_usuario = true; return parar(); }
            if (res.http === 400 && /8|máximo|ocho/i.test(res.cuerpo.motivo || '')) {
              // E-23: el servidor ya tiene el tope de fotos de esta orden.
              // La foto que sobra no sirve para nada más: se descarta.
              f.descartada = true;
              f.error = res.cuerpo.motivo || 'el máximo de fotos por orden ya se alcanzó';
              f.blob = null;
              return guardar(fila).then(function () { return true; });
            }
            if (res.http >= 400 && res.http < 500) {
              // La foto no sirve: la orden sale sin ella, y el PDF dice que falta.
              f.descartada = true;
              f.error = res.cuerpo.motivo || ('el servidor la rechazó (' + res.http + ')');
              f.blob = null;
              return guardar(fila).then(function () { return true; });
            }
            fila.intentos++;
            fila.ultimo_error = 'no se pudieron subir las fotos todavía';
            return parar();
          });
      });
    }, Promise.resolve(true));
  }

  function tratarRespuesta(fila, res) {
    var err = res.cuerpo.error || '';
    if (res.http === 200 && res.cuerpo.ok) {
      fila.estado = 'ENVIADA';
      fila.recibo = res.cuerpo.recibo || null;
      /* H-04/punto 1: si el PDF ya salió (`estado === 'EMITIDA'`), la orden
         y las fotos ya no hacen falta en el celular. Si NO salió todavía
         --el servidor lo reintenta cada 10 min, H-03-- se conservan: hasta
         que se sepa que sí salió, o hasta la purga de 30 días (H-25),
         borrarlas sería no poder demostrar nunca qué se envió. */
      var emitida = fila.recibo && fila.recibo.estado === 'EMITIDA';
      if (emitida) {
        fila.orden = null;
        fila.fotos = null;
      }
      var anexos = (fila.recibo && fila.recibo.anexos) || [];
      var id = fila.recibo && fila.recibo.id_industec;
      return guardar(fila).then(function () {
        // app.js escucha esto para pintar el recibo REAL (número, correo,
        // botón «Ver PDF») en vez del texto fijo de siempre.
        global.dispatchEvent(new CustomEvent('orden-emitida', { detail: { uuid: fila.uuid, recibo: fila.recibo } }));
        if (global.UI) {
          UI.toast(id ? (emitida ? 'Orden ' + id + ' emitida. El PDF está en tu historial.'
                                  : 'Orden ' + id + ' recibida. El PDF se genera desde el servidor.')
                      : 'Orden de ' + (fila.resumen.local || 'el local') + ' enviada.', 'ok');
          if (anexos.length) { UI.toast(anexos.join(' '), 'warn', { vida: 12000 }); }
        }
      });
    }
    if (res.http === 409 && res.cuerpo.ajena) {
      // De otro usuario de este celular: espera a que entre él (H-20: ya no
      // se reintenta sola cada 2 min -- "Reintentar" es del técnico).
      fila.ultimo_error = AJENA;
      fila.espera_usuario = true;
      return guardar(fila);
    }
    if (res.http === 403 && err === 'csrf') {
      // Token vencido o de otra sesión: se pide uno nuevo y se reintenta,
      // igual que un 5xx -- no es que la orden esté mal.
      csrfToken = null;
      fila.intentos++;
      fila.ultimo_error = 'no se pudo confirmar tu sesión; se reintenta';
      return guardar(fila);
    }
    if (res.http === 401 || (res.http === 403 && err === 'debe_cambiar_clave')) {
      /* LA SESION SE CAYO, o falta cambiar la clave provisional. Es un 4xx,
         pero NO es «la orden no sirve»: la orden está perfecta y lo único
         que falta es volver a entrar. Tratarlo como rechazo la marcaría
         como inválida y no se reintentaría nunca. */
      fila.intentos++;
      fila.ultimo_error = FALTA_ENTRAR;
      return guardar(fila).then(function () {
        if (global.UI) {
          UI.toast('Tu sesión se cerró. Vuelve a entrar y la orden se envía sola.', 'warn',
                   { vida: 9000 });
        }
      });
    }
    if (res.http === 403) {
      /* Sin permiso NO es sesión caída: decirle «vuelve a entrar» lo
         mandaba a un callejón sin salida. Se queda en la cola hasta que la
         administración le dé el permiso, y H-20 le apaga el reintento
         automático: es la administración la que tiene que moverse, no el
         reloj del celular. */
      fila.intentos++;
      fila.ultimo_error = SIN_PERMISO;
      fila.espera_usuario = true;
      return guardar(fila);
    }
    if (res.http >= 400 && res.http < 500) {
      /* El servidor la recibió y la rechazó por lo que trae. Reintentar no
         la arregla, así que se marca y se le dice al técnico qué pasó. */
      fila.estado = 'RECHAZADA';
      fila.ultimo_error = res.cuerpo.motivo || ('el servidor la rechazó (' + res.http + ')');
      return guardar(fila).then(function () {
        if (global.UI) { UI.toast('Una orden no se pudo enviar: ' + fila.ultimo_error, 'err'); }
      });
    }
    // 5xx: es del servidor o del camino, y eso sí se reintenta. Se cuenta el
    // intento para poder avisar si no cede.
    fila.intentos++;
    fila.ultimo_error = 'no se pudo enviar todavía';
    return guardar(fila);
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
      var sesion = filas.some(function (f) {
        return f.estado === 'PENDIENTE' && f.ultimo_error === FALTA_ENTRAR;
      });

      var clase = (rechazadas.length || sesion) ? 'cola'
                : (navigator.onLine ? 'cola enviando' : 'cola');

      var titulo, detalle;
      if (rechazadas.length) {
        titulo = rechazadas.length === 1
          ? 'Una orden no se pudo enviar'
          : rechazadas.length + ' órdenes no se pudieron enviar';
        detalle = 'El servidor las revisó y no las aceptó. Lee el motivo y consúltalo con tu '
                + 'jefe de zona; cuando esté resuelto, descártala.';
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
        detalle = 'Están a salvo en este celular. Salen solas cuando vuelvas a abrir la '
                + 'aplicación con señal.';
      }

      var html = '<div class="fila"><span class="n">' + filas.length + '</span>' +
                 '<div><b>' + esc(titulo) + '</b><div style="margin-top:2px">' + esc(detalle) + '</div></div></div><ul>';

      filas.forEach(function (f) {
        var etiqueta = f.estado === 'RECHAZADA' ? 'rechazada'
                     : f.ultimo_error === FALTA_ENTRAR ? 'falta entrar'
                     : f.ultimo_error === AJENA ? 'de otro usuario'
                     : f.ultimo_error === SIN_PERMISO ? 'sin permiso'
                     : (navigator.onLine ? 'enviando' : 'en espera');
        html += '<li><span>' + esc(f.resumen.local || 'Orden sin local') +
                (f.resumen.aviso ? ' · aviso ' + esc(f.resumen.aviso) : '') + '</span>' +
                '<span class="est-envio">' + etiqueta + '</span></li>';
        if (f.estado === 'RECHAZADA') {
          // H-07: «Corregir» manda al formulario con `?reintentar=<uuid>`,
          // que app.js reconoce y vuelca la orden, la firma y las fotos que
          // ya se habían subido -- sin rehacer los veinte minutos de trabajo.
          html += '<li style="background:transparent;padding:0 9px 6px;font-size:12px">' +
                  esc(f.ultimo_error || '') +
                  ' <a class="btn sm" href="index.html?reintentar=' + esc(f.uuid) + '">Corregir</a>' +
                  ' <button type="button" class="btn sm" data-descartar="' + esc(f.uuid) + '">Descartar</button></li>';
        } else if (f.ultimo_error === AJENA || f.ultimo_error === SIN_PERMISO) {
          // H-20: ya no se reintenta sola cada 2 minutos contra algo que no
          // se va a arreglar solo. «Reintentar» es la acción de la persona
          // -tras entrar con la sesión correcta, o tras recibir el permiso-,
          // y «Descartar» existe por si ya se resolvió de otra forma.
          html += '<li style="background:transparent;padding:0 9px 6px;font-size:12px">' +
                  esc(f.ultimo_error) +
                  ' <button type="button" class="btn sm" data-reintentar="' + esc(f.uuid) + '">Reintentar</button>' +
                  ' <button type="button" class="btn sm" data-descartar="' + esc(f.uuid) + '">Descartar</button></li>';
        }
      });
      html += '</ul>';

      caja.className = clase;
      caja.innerHTML = html;
      caja.hidden = false;

      Array.prototype.forEach.call(caja.querySelectorAll('[data-descartar]'), function (b) {
        b.addEventListener('click', function () {
          var descartar = function () { Cola.olvidar(b.getAttribute('data-descartar')); };
          if (global.UI && UI.confirmar) {
            UI.confirmar({
              titulo: 'Descartar esta orden',
              detalle: 'Se borra de este celular y no se vuelve a enviar. Hazlo solo si ya se '
                     + 'resolvió por otro camino.',
              ok: 'Descartar'
            }, descartar);
          } else if (confirm('¿Descartar esta orden? Se borra del celular y no se vuelve a enviar.')) {
            descartar();
          }
        });
      });

      Array.prototype.forEach.call(caja.querySelectorAll('[data-reintentar]'), function (b) {
        b.addEventListener('click', function () {
          var uuid = b.getAttribute('data-reintentar');
          Cola.leer(uuid).then(function (f) {
            if (!f) { return; }
            f.espera_usuario = false;
            f.ultimo_error = null;
            return guardar(f);
          }).then(function () { pintar(); enviarTodo(); });
        });
      });
    });
  }

  /** Filas ENVIADA de hace más de 30 días (H-25): la cola local no crece sin
   *  límite. 30 días alcanza para que el técnico consulte el recibo reciente
   *  y para que, si el PDF tardó en confirmarse (H-03/H-04), haya tiempo de
   *  sobra para que el servidor lo reintente. */
  function purgarEnviadas() {
    var limite = Date.now() - 30 * 86400000;
    return tx('readwrite', function (store) {
      var idx = store.index('estado');
      var req = idx.openCursor(IDBKeyRange.only('ENVIADA'));
      req.onsuccess = function () {
        var cur = req.result;
        if (!cur) { return; }
        var f = cur.value;
        if (f && f.creado && new Date(f.creado).getTime() < limite) { cur.delete(); }
        cur.continue();
      };
    }).catch(function () {});
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
    purgarEnviadas().then(pintar);
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
  Cola.uuid = uuid;
  global.Cola = Cola;
})(window);
