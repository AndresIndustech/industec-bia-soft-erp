/* =========================================================================
   sw.js — El trabajador de servicio. Es lo que hace que la app funcione sin
   señal, y sin él «instalar» la página es solo un acceso directo.

   EL PROBLEMA QUE RESUELVE, tal como se vio el 2026-09-08: el técnico agregó
   la página a la pantalla de inicio, la abrió sin buena señal, y el navegador
   le sirvió el HTML que tenía guardado pero no alcanzó app.js, reglas.js ni
   catalogos.php. El formulario se veía y no hacía nada: sin desplegables, sin
   buscador y sin firma. Peor que un error claro, porque parece que funciona.

   DOS ESTRATEGIAS, PORQUE SON DOS PROBLEMAS DISTINTOS

     El armazón (HTML, CSS, JS, iconos) -> CACHE PRIMERO.
     Cambia poco y tiene que estar SIEMPRE. Se sirve de la copia local y se
     actualiza por detrás. Así la app abre al instante, con o sin señal.

     Los datos (catálogos, identidad, bandeja) -> RED PRIMERO, CACHE DE RESPALDO.
     Cambian: entran casos nuevos todos los días. Con señal se pide lo fresco;
     sin señal se usa lo último que se guardó, y la interfaz dice de cuándo es.

   LO QUE NUNCA SE GUARDA EN CACHE: los envíos (POST), las pantallas PHP que
   no son de la app del técnico y los PDF. Todo eso va directo a la red.
   ========================================================================= */

/* La version se sube A MANO cada vez que cambia la lista de abajo. Si no se
   sube, el navegador se queda con el armazon viejo y los archivos nuevos no
   se precargan nunca — que es como decir que sin senal no existen.
     v2 -> v3 (2026-09-10): entraron la bandeja del tecnico, la cola de envios,
     el guiado por pasos, el sistema de interfaz compartido y los graficos.
     v3 -> v4 (2026-09-10, auditoria): se guardaba en cache CUALQUIER pagina y
     cualquier PDF, y se devolvia ignorando la direccion: abrir el PDF de una
     orden podia mostrar el de otra. Ahora solo pasan por aqui el armazon y los
     datos, y un 401 o una redireccion al ingreso ya no se confunden con falta
     de senal. Subir la version purga lo que guardaron las anteriores.
     v4 -> v5 (2026-09-11, T2.13, la 008): la app sube las fotos y la firma y
     recibe el numero de la orden. Con el armazon viejo el celular seguiria
     mandando la orden sin ellas. */
const VERSION = 'ot-industec-v21';  // v21: un solo vocabulario para todos los roles — vocabulario_publico.json precargado y UI.T en ui.js; cubre los textos de app.js, cola.js, offline.js, guia.js, reglas.js, ui.js, index.html y cronograma.js (vocabulario 2026-09-24.6, 26-sep-2026)
// v20: ficha del equipo -marca, modelo y serie que se quedan, casilla «sin placa» (T2.28.6, 2026-09-24)
// v19: correo del jefe de operaciones ya no es un campo fijo; «también se enviará a» (T2.28.3, 2026-09-24)
// v18: el buscador del equipo ya no borra lo que se escribe (2026-09-24)
// v17: el equipo se busca escribiendo y se crea si no está; acompañantes de la zona de la orden (2026-09-24)
// v16: correo y administrador editables, repuestos con texto libre, lista de casos que ya no se recorta (2026-09-24)
// v15: estilo.css — distintivo «regularizado», neutro en vez del rojo de «sin atender» (2026-09-22)
// v14: el logo de la cabecera, que daba 404 en cada carga (2026-09-22)
// v13: las ordenes salen solas con la app cerrada (sync), el equipo del aviso se preselecciona, y un 401 ya no borra la copia local (2026-09-22)
// v12: guia.js — ninguna cabecera abría su paso (2026-09-22)
// v11: preventivos rediseñado — cronograma.html/js/css (2026-09-21)
// v10: estilo.css (§S3-§S5), cronograma.js/html/css que escribe y graficos.js accesible (2026-09-13)
//
// OJO: la versión hay que subirla también cuando cambia el CONTENIDO de un
// archivo ya precargado, no solo cuando cambia la lista. `cronograma.html`,
// `.css` y `.js` están en PRECARGA: sin subir la versión, a quien ya tiene la
// aplicación instalada le sigue saliendo la pantalla vieja aunque el servidor
// tenga la nueva, y nadie entiende por qué.
const ARMAZON = `${VERSION}-armazon`;
const DATOS   = `${VERSION}-datos`;

/* Lo mínimo para que la app abra y sea usable sin red.
 *
 * OJO CON ESTA LISTA: lo que no esté aquí, sin señal no existe. En la v1 faltaba
 * `offline.js` —el archivo que muestra el aviso de "sin conexión"— así que sin
 * servidor no cargaba, no se registraba su listener y la app funcionaba pero
 * jamás avisaba de que los datos eran viejos. Solo se vio al apagar el servidor
 * de verdad; cortando la red con el depurador no aparecía, porque el trabajador
 * de servicio tiene su propio contexto de red y seguía teniendo internet. */
const PRECARGA = [
  'index.html',
  'estilo.css',
  'reglas.js',
  'offline.js',
  'app.js',
  // `cola.js` es lo que hace que una orden llenada sin senal sobreviva. Si
  // faltara aqui, el tecnico abriria la app sin cobertura, llenaria la orden y
  // al enviar no habria nada que la guardara: veinte minutos de trabajo
  // perdidos, en silencio y sin error visible.
  'cola.js',
  // `ui.js` trae los avisos efimeros y el dialogo de confirmacion; sin el, el
  // envio cae al `confirm()` del navegador y los avisos no aparecen.
  'ui.js',
  // `guia.js` es el guiado por pasos. Es mejora progresiva —sin el, el
  // formulario sale entero— pero cachearlo evita que el tecnico vea una cosa
  // con senal y otra sin ella.
  'guia.js',
  // `vocabulario_publico.json` son las palabras con que la app nombra cada
  // estado (UI.T en ui.js): la copia recortada que escribe
  // herramientas/generar_vocabulario.php, sin las notas internas del
  // vocabulario.json completo (que ya no se sirve). No lleva ningún dato. ui.js
  // trae un respaldo embebido, así que si esta precarga fallara la app sigue
  // hablando igual; con la copia, un cambio de término llega también sin señal.
  'vocabulario_publico.json',
  'manifest.json',
  'iconos/icono-192.png',
  'iconos/icono-512.png',
  // El logo de la cabecera del formulario. Estuvo dando 404 en cada carga
  // desde que se escribió `index.html`: la carpeta `assets/` no existía y el
  // archivo vivía en `nucleo/`, que la web sirve con 403 a propósito. El
  // `onerror` del <img> lo escondía, así que nadie lo vio.
  'assets/logo-industec.png',
  'cronograma.html',
  'cronograma.css',
  'cronograma.js',
];

/* Los datos se precargan tambien. NO es un extra: en la primera visita el
   trabajador de servicio todavia no controla la pagina, asi que el fetch que
   hace app.js no pasa por aqui y no se guarda solo. Medido el 2026-09-08: el
   tecnico instalaba la app, se quedaba sin senal, y encontraba los
   desplegables vacios.

   `yo.php` entro en la v3: sin el, la app abre sin senal y no sabe quien es el
   tecnico. `mis.php` en la v4: es la bandeja, lo primero que abre. */
const PRECARGA_DATOS = ['catalogos.php', 'cronograma.php', 'yo.php', 'mis.php'];

/* Lo único que pasa por la estrategia de datos. Una pantalla o un PDF guardado
   se le puede servir a quien no debe, así que nada más entra aquí. */
const RUTAS_DATOS = ['catalogos.php', 'cronograma.php', 'yo.php', 'mis.php', 'pendientes.php'];

/* Solo estos se buscan en la caché ignorando los parámetros: no llevan ninguno
   que cambie el contenido. `mis.php?ver=…` es otra ficha y tiene que coincidir
   entera, o se muestra el caso equivocado. */
const SIN_PARAMETROS = ['catalogos.php', 'cronograma.php', 'yo.php'];

/* Sin límite, con una barra de señal y sin datos la pantalla se quedaba
   pensando para siempre en vez de mostrar lo guardado. */
const LIMITE_RED_MS = 8000;

self.addEventListener('install', (e) => {
  e.waitUntil((async () => {
    const c = await caches.open(ARMAZON);
    // addAll falla entero si un solo archivo falla. Se guardan de a uno para
    // que un 404 en un icono no deje al técnico sin app.
    await Promise.all(PRECARGA.map(async (u) => {
      try { await c.add(new Request(u, { cache: 'reload' })); }
      catch (err) { console.warn('no se pudo precargar', u, err); }
    }));
    await Promise.all(PRECARGA_DATOS.map((u) => pedirDatos(new Request(u))));
    // Que la versión nueva entre a la primera, sin esperar a que cierre todas
    // las pestañas: el técnico no va a hacer eso.
    await self.skipWaiting();
  })());
});

/**
 * Pide datos a la red. Devuelve la respuesta tal como vino, o null si no hubo
 * red (o no contestó a tiempo). Si vino bien, la guarda marcando CUÁNDO; si la
 * sesión ya no vale, borra lo que se había guardado con ella.
 */
async function pedirDatos(req) {
  let res;
  try {
    if (req.mode === 'navigate') {
      // Una navegación no admite opciones en fetch(): se corta con una carrera.
      res = await Promise.race([
        fetch(req),
        new Promise((_, mal) => setTimeout(() => mal(new Error('sin respuesta')), LIMITE_RED_MS)),
      ]);
    } else {
      const ctl = new AbortController();
      const corte = setTimeout(() => ctl.abort(), LIMITE_RED_MS);
      try { res = await fetch(req, { cache: 'reload', signal: ctl.signal }); }
      finally { clearTimeout(corte); }
    }
  } catch (err) {
    return null;
  }

  // `redirected`: un fetch que siguió la redirección al ingreso trae el login
  // con estado 200. Guardarlo como si fuera la bandeja la dejaba sin señal
  // mostrando el login con fecha fresca.
  if (res.ok && !res.redirected) {
    await olvidarSiEsOtraPersona(req, res);
    const cache = await caches.open(DATOS);
    // La marca de tiempo es lo que después le permite a la app decirle al
    // técnico de cuándo son los datos que está viendo, en vez de callarlo.
    const copia = new Response(await res.clone().blob(), {
      status: res.status,
      headers: new Headers([...res.headers.entries(),
                            ['x-guardado-en', new Date().toISOString()]]),
    });
    await cache.put(req, copia);
  }
  return res;
}

/* Hasta la v12, un 401 borraba TODA la copia guardada. La intención era buena
   —que lo de una sesión no se le sirva a otra— pero el precio lo pagaba el
   técnico: la sesión vence a las 12 horas, o la desplaza su propio ingreso
   desde otro teléfono, y con eso se le borraban los catálogos, la bandeja y su
   identidad. Entraba al local sin señal y la aplicación estaba vacía:
   «no tiene una copia guardada». Reproducido el 2026-09-22.

   La regla ahora distingue las dos cosas, que no son la misma:
     sesión vencida   -> se conserva. Son SUS datos, en SU teléfono, y es lo
                         único que le permite trabajar sin cobertura.
     otra persona     -> se borra, en cuanto `yo.php` contesta con otro usuario.
   Lo guardado solo se sirve sin señal; con señal siempre gana la red. */
const CLAVE_IDENTIDAD = 'sw-identidad-guardada';

async function olvidarSiEsOtraPersona(req, res) {
  if (!new URL(req.url).pathname.endsWith('/yo.php')) { return; }
  let quien = null;
  try { quien = String((await res.clone().json()).id ?? ''); } catch (err) { return; }
  if (!quien) { return; }

  const cache = await caches.open(DATOS);
  const previa = await cache.match(CLAVE_IDENTIDAD);
  const antes = previa ? await previa.text() : null;
  if (antes !== null && antes !== quien) {
    await caches.delete(DATOS);                       // cambió de persona
  }
  await (await caches.open(DATOS)).put(CLAVE_IDENTIDAD, new Response(quien));
}

self.addEventListener('activate', (e) => {
  e.waitUntil((async () => {
    const nombres = await caches.keys();
    await Promise.all(nombres
      .filter((n) => !n.startsWith(VERSION))
      .map((n) => caches.delete(n)));
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', (e) => {
  const req = e.request;

  // Solo GET del mismo sitio. Un POST jamás se cachea ni se reintenta aquí:
  // una respuesta de envio.php servida desde caché sería una orden que el
  // técnico cree enviada y que nunca salió.
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  const nombre = url.pathname.split('/').pop();
  if (RUTAS_DATOS.includes(nombre)) {
    e.respondWith(redPrimero(req));
    return;
  }
  const esArmazon = url.pathname.endsWith('/') ||
                    PRECARGA.some((p) => url.pathname.endsWith('/' + p));
  if (esArmazon) {
    e.respondWith(cachePrimero(req));
  }
  // Todo lo demás —pantallas PHP de gestión, pdf.php, extremos JSON— va
  // directo a la red, sin pasar por la caché.
});

/** Datos: lo fresco si hay red; lo último guardado si no. */
async function redPrimero(req) {
  const res = await pedirDatos(req);
  // Lo que el servidor contestó se devuelve tal cual —los 401, 403 y la
  // redirección de una navegación, que el navegador sigue solo al ingreso—,
  // salvo sus propios fallos: con un 5xx se prefiere la última copia.
  if (res && res.status < 500) {
    return res;
  }

  // Sin red, o el servidor falló: lo último que se guardó, si lo hay.
  const nombre = new URL(req.url).pathname.split('/').pop();
  const cache = await caches.open(DATOS);
  const guardado = await cache.match(req, { ignoreSearch: SIN_PARAMETROS.includes(nombre) });
  if (guardado) return guardado;
  if (res) return res;

  if (req.mode === 'navigate') {
    return new Response(
      '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width">' +
      '<p style="font-family:system-ui;padding:24px">Sin conexión, y esta pantalla no tiene ' +
      'una copia guardada en el celular. Vuelve a intentarlo con señal.</p>',
      { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
  }
  return new Response(
    JSON.stringify({ error: 'sin_conexion_y_sin_copia' }),
    { status: 503, headers: { 'Content-Type': 'application/json' } }
  );
}

/** Armazón: la copia local primero, y se refresca por detrás. Solo archivos
 *  estáticos llegan aquí, así que ignorar los parámetros es seguro: así
 *  `index.html?aviso=…` abre sin señal. */
async function cachePrimero(req) {
  const cache = await caches.open(ARMAZON);
  const guardado = await cache.match(req, { ignoreSearch: true });

  const refrescar = fetch(req).then((res) => {
    if (res && res.ok) cache.put(req, res.clone());
    return res;
  }).catch(() => null);

  if (guardado) {
    refrescar;           // no se espera: la app ya tiene qué mostrar
    return guardado;
  }
  const res = await refrescar;
  if (res) return res;

  // Navegación sin copia: mejor el formulario que la pantalla de dinosaurio.
  if (req.mode === 'navigate') {
    const inicio = await cache.match('index.html');
    if (inicio) return inicio;
  }
  return new Response('Sin conexión y sin copia guardada.', {
    status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' },
  });
}

/* =========================================================================
   QUE LAS ORDENES SALGAN SOLAS, AUNQUE LA APP ESTE CERRADA

   El problema: `cola.js` reintenta al cargar, al volver la señal, al volver a
   la pestaña y cada dos minutos — todo eso solo mientras la aplicación siga
   ABIERTA. El técnico que llena la orden en la cocina, bloquea el teléfono y
   se va al siguiente local no cumple ninguna de las cuatro: su orden se queda
   en el celular hasta que vuelva a abrir la app. Con cuatro locales al día,
   eso es media jornada de órdenes esperando.

   `sync` es la única pieza del navegador que promete despertar al trabajador
   de servicio cuando vuelva la conexión con la app cerrada. Aquí se usa como
   DISPARADOR, no como una segunda implementación del envío:

     1. Si hay una pantalla abierta, se le pide a ELLA que mande. Es la que
        sabe subir las fotos y tratar las once respuestas distintas del
        servidor; duplicar esa lógica aquí sería tener dos envíos que se
        separan al primer cambio.
     2. Solo si no hay ninguna pantalla —que es justo el caso que hoy no
        funciona— manda este guion, y solo las órdenes SIN FOTOS pendientes.
        Las que llevan fotos necesitan la subida en varios pasos y esperan a
        que la app se abra: es lento, pero nunca miente.

   Reenviar dos veces la misma orden es inofensivo: `envio.php` es idempotente
   por `envio_uuid` —lo genera el celular antes del primer intento— y una
   segunda llegada se reconoce como la misma orden, no como una nueva. Sin esa
   garantía, esto no se podría hacer desde aquí.
   ========================================================================= */
const BD_COLA = 'ot-industec', TIENDA_COLA = 'cola';

function abrirCola() {
  return new Promise((ok, mal) => {
    const p = indexedDB.open(BD_COLA, 1);
    // Sin `onupgradeneeded`: si la tienda no existe es que el técnico todavía
    // no ha guardado ninguna orden, y no hay nada que mandar. Crearla desde
    // aquí solo serviría para dejar una base vacía por medio.
    p.onsuccess = () => ok(p.result);
    p.onerror = () => mal(p.error);
  });
}

function filasDeCola(bd) {
  return new Promise((ok) => {
    if (![...bd.objectStoreNames].includes(TIENDA_COLA)) { ok([]); return; }
    const p = bd.transaction(TIENDA_COLA, 'readonly').objectStore(TIENDA_COLA).getAll();
    p.onsuccess = () => ok(p.result || []);
    p.onerror = () => ok([]);
  });
}

function guardarFila(bd, fila) {
  return new Promise((ok) => {
    const p = bd.transaction(TIENDA_COLA, 'readwrite').objectStore(TIENDA_COLA).put(fila);
    p.onsuccess = () => ok(true);
    p.onerror = () => ok(false);
  });
}

/** ¿Alguna pantalla abierta se hizo cargo? Se espera su respuesta, con corte:
 *  una pestaña congelada no puede dejar la orden sin salir. */
async function loMandaUnaPantalla() {
  const clientes = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
  if (!clientes.length) { return false; }
  const respuestas = await Promise.all(clientes.map((c) => new Promise((ok) => {
    const canal = new MessageChannel();
    const corte = setTimeout(() => ok(false), 15000);
    canal.port1.onmessage = (e) => { clearTimeout(corte); ok(!!(e.data && e.data.atendido)); };
    try { c.postMessage({ tipo: 'enviar-cola' }, [canal.port2]); }
    catch (err) { clearTimeout(corte); ok(false); }
  })));
  return respuestas.some(Boolean);
}

async function mandarPendientes() {
  if (await loMandaUnaPantalla()) { return; }

  let bd;
  try { bd = await abrirCola(); } catch (err) { return; }
  const filas = await filasDeCola(bd);
  const listas = filas.filter((f) =>
    f.estado === 'PENDIENTE' && !f.espera_usuario &&
    !(f.fotos || []).some((x) => !x.subida && !x.descartada));
  if (!listas.length) { return; }

  // El token sale de `yo.php`, igual que en la página. Si no hay sesión, no se
  // manda nada: se deja la orden donde está y el técnico la ve al entrar.
  let token = '';
  try {
    const r = await fetch('yo.php', { credentials: 'same-origin' });
    if (!r.ok) { return; }
    token = (await r.json()).csrf || '';
  } catch (err) { return; }

  for (const fila of listas) {
    try {
      const r = await fetch('envio.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-Csrf': token },
        body: JSON.stringify({
          envio_uuid: fila.uuid,
          capturada_en: fila.creado,
          usuario_captura: fila.usuario_id || null,
          orden: fila.orden,
        }),
      });
      const cuerpo = await r.json().catch(() => ({}));
      if (r.status === 200 && cuerpo.ok) {
        fila.estado = 'ENVIADA';
        fila.recibo = cuerpo.recibo || null;
        fila.ultimo_error = null;
      } else if (r.status >= 500 || r.status === 0) {
        // Del servidor o del camino: se reintenta. No se toca el estado.
        fila.intentos = (fila.intentos || 0) + 1;
        fila.ultimo_error = 'el servidor no pudo recibirla todavía';
      } else {
        /* Cualquier otra respuesta —rechazo, sesión, permiso, orden ajena— la
           trata la página, que sabe distinguirlas y decírselo al técnico. Aquí
           solo se cuenta el intento y se deja quieta: inventar un veredicto
           desde el trabajador de servicio es justo como se pierde una orden. */
        fila.intentos = (fila.intentos || 0) + 1;
        fila.ultimo_error = 'quedó pendiente de revisar al abrir la aplicación';
      }
      await guardarFila(bd, fila);
    } catch (err) {
      break;              // se cortó la señal otra vez: el resto espera al próximo sync
    }
  }
}

self.addEventListener('sync', (e) => {
  // Si esto falla, el navegador reintenta el `sync` solo. Por eso se deja
  // propagar el fallo en vez de tragárselo.
  if (e.tag === 'enviar-ordenes') { e.waitUntil(mandarPendientes()); }
});

/* La página puede preguntar cuándo se guardaron los catálogos, para decirlo. */
self.addEventListener('message', (e) => {
  if (e.data?.tipo === 'fecha-datos') {
    e.waitUntil((async () => {
      const cache = await caches.open(DATOS);
      const claves = await cache.keys();
      let fecha = null;
      for (const k of claves) {
        const r = await cache.match(k);
        const f = r?.headers.get('x-guardado-en');
        if (f && (!fecha || f > fecha)) fecha = f;
      }
      e.source?.postMessage({ tipo: 'fecha-datos', fecha });
    })());
  }
});
