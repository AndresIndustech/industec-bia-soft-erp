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

     Los catálogos (catalogos.php) -> RED PRIMERO, CACHE DE RESPALDO.
     Cambian: entran casos nuevos todos los días. Con señal se pide lo fresco;
     sin señal se usa lo último que se guardó, y la interfaz dice de cuándo es.
     Nunca se queda sin lista de locales.

   LO QUE NUNCA SE GUARDA EN CACHE: los envíos (POST). Una orden enviada dos
   veces por una respuesta cacheada sería un correlativo quemado y un PDF
   duplicado a Grupo KFC.
   ========================================================================= */

/* La version se sube A MANO cada vez que cambia la lista de abajo. Si no se
   sube, el navegador se queda con el armazon viejo y los archivos nuevos no
   se precargan nunca — que es como decir que sin senal no existen.
     v2 -> v3 (2026-09-10): entraron la bandeja del tecnico, la cola de envios,
     el guiado por pasos, el sistema de interfaz compartido y los graficos. */
const VERSION = 'ot-industec-v3';
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

/* Los datos también se precargan. NO es un extra: en la primera visita el
   trabajador de servicio todavía no controla la página, así que el fetch que
   hace app.js a catalogos.php no pasa por aquí y no se guarda solo. Medido el
   2026-09-08: el técnico instalaba la app, se quedaba sin señal, y encontraba
   los desplegables vacíos con «catalogos.php respondió 503». */
/* Los datos se precargan tambien. NO es un extra: en la primera visita el
   trabajador de servicio todavia no controla la pagina, asi que el fetch que
   hace app.js no pasa por aqui y no se guarda solo.

   `yo.php` entro en la v3. Sin el, la app abre sin senal y no sabe quien es el
   tecnico, asi que no puede firmar la orden — y el formulario tendria que
   volver a preguntarle su nombre, que es justo lo que se elimino. */
const PRECARGA_DATOS = ['catalogos.php', 'cronograma.php', 'yo.php'];

self.addEventListener('install', (e) => {
  e.waitUntil((async () => {
    const c = await caches.open(ARMAZON);
    // addAll falla entero si un solo archivo falla. Se guardan de a uno para
    // que un 404 en un icono no deje al técnico sin app.
    await Promise.all(PRECARGA.map(async (u) => {
      try { await c.add(new Request(u, { cache: 'reload' })); }
      catch (err) { console.warn('no se pudo precargar', u, err); }
    }));
    await Promise.all(PRECARGA_DATOS.map((u) => guardarDatos(new Request(u))));
    // Que la versión nueva entre a la primera, sin esperar a que cierre todas
    // las pestañas: el técnico no va a hacer eso.
    await self.skipWaiting();
  })());
});

/** Pide algo a la red y lo guarda marcando CUÁNDO. Devuelve la respuesta o null. */
async function guardarDatos(req) {
  try {
    const res = await fetch(req, { cache: 'reload' });
    if (!res || !res.ok) return null;
    const cache = await caches.open(DATOS);
    // La marca de tiempo es lo que después le permite a la app decirle al
    // técnico de cuándo son los datos que está viendo, en vez de callarlo.
    const copia = new Response(await res.clone().blob(), {
      status: res.status,
      headers: new Headers([...res.headers.entries(),
                            ['x-guardado-en', new Date().toISOString()]]),
    });
    await cache.put(req, copia);
    return res;
  } catch (err) {
    return null;
  }
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

  // Solo GET del mismo sitio. Un POST jamás se cachea ni se reintenta aquí.
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  /* `envio.php` NO se toca: es POST y ya quedo fuera arriba, pero se deja
     dicho porque es el error mas caro posible en este archivo. Una respuesta
     de envio servida desde cache seria una orden que el tecnico cree enviada y
     que nunca salio. */
  const esDatos = url.pathname.endsWith('catalogos.php') ||
                  url.pathname.endsWith('cronograma.php') ||
                  url.pathname.endsWith('yo.php') ||
                  // El buzon del tecnico y sus pendientes: con senal, lo
                  // fresco; sin senal, lo ultimo que se vio. Una bandeja de
                  // ayer es util; una pantalla de error, no.
                  url.pathname.endsWith('mis.php') ||
                  url.pathname.endsWith('pendientes.php');

  e.respondWith(esDatos ? redPrimero(req) : cachePrimero(req));
});

/** Catálogos: lo fresco si hay red; lo último guardado si no. */
async function redPrimero(req) {
  const res = await guardarDatos(req);
  if (res) return res;

  const cache = await caches.open(DATOS);
  // ignoreSearch: app.js a veces pide catalogos.php?nc=... y sin esto no
  // encontraría la copia que se guardó sin parámetros.
  const guardado = await cache.match(req, { ignoreSearch: true });
  if (guardado) return guardado;

  return new Response(
    JSON.stringify({ error: 'sin_conexion_y_sin_copia' }),
    { status: 503, headers: { 'Content-Type': 'application/json' } }
  );
}

/** Armazón: la copia local primero, y se refresca por detrás. */
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
