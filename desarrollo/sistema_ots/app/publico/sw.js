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
     de senal. Subir la version purga lo que guardaron las anteriores. */
const VERSION = 'ot-industec-v4';
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
  'manifest.json',
  'iconos/icono-192.png',
  'iconos/icono-512.png',
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
    const cache = await caches.open(DATOS);
    // La marca de tiempo es lo que después le permite a la app decirle al
    // técnico de cuándo son los datos que está viendo, en vez de callarlo.
    const copia = new Response(await res.clone().blob(), {
      status: res.status,
      headers: new Headers([...res.headers.entries(),
                            ['x-guardado-en', new Date().toISOString()]]),
    });
    await cache.put(req, copia);
  } else if (res.status === 401 || res.type === 'opaqueredirect' || res.redirected) {
    // La sesión ya no vale. Lo guardado con ella no se sirve: se borra. Un 403
    // no: es un «no tienes permiso» para esa pantalla, no una sesión perdida.
    await caches.delete(DATOS);
  }
  return res;
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
