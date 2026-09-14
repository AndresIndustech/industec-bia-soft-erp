# Web corporativa de INDUSTEC

Sitio estático (HTML + CSS + JavaScript, sin frameworks ni paso de compilación) que reemplaza la web de Zyro de www.industec.me. Mientras tanto vive en la **raíz** del dominio temporal `darkviolet-armadillo-872352.hostingersite.com`, junto al sistema B.IA Soft ERP, que está en `/ot/` y no se toca desde aquí.

- Lo armó Steven (desarrollo de INDUSTECH) el 11 de septiembre de 2026 con los textos de `contenido.md` (Valentina).
- Solo publica lo que ya es público en www.industec.me.
- Lo que espera el visto bueno de César está en `NOTAS_PARA_CESAR.md` y **no** está en el HTML.

**Estado al 13 de septiembre de 2026**:

- La **tercera entrega** es el **rediseño de imagen y navegación (T2.17)**: ilustraciones propias en el estilo fijado por Andrés (sección 10 y `DIRECCION_ARTE.md`), tipografía Barlow autoalojada, el azul del logo como color de marca y el rojo solo como acento y urgencia, WhatsApp como botón primario del menú, los servicios antes que los logos y los logos en una franja compacta. Los textos son los mismos de la segunda entrega (aprobados); cambia la forma.
- Las dos entregas anteriores del 11 de septiembre (secciones 8 y 9) quedaron publicadas y verificadas por hash.
- Lo que espera el visto bueno de César sigue en `NOTAS_PARA_CESAR.md`.

---

## 1. Qué hay en esta carpeta

```
web_corporativa/
├── contenido.md                 textos finales de Valentina (fuente de los textos)
├── NOTAS_PARA_CESAR.md          propuestas que esperan el visto bueno de César
├── LEEME.md                     este archivo
├── sitio.sha256                 manifiesto SHA-256 de lo que se SUBE (lo escribe verificar-sitio.mjs --manifiesto)
├── sitio.publicado.sha256       manifiesto de lo PUBLICADO, base de la regla de caché (lo escribe verificar-publicacion.mjs al terminar en OK)
├── DIRECCION_ARTE.md            el estilo de las ilustraciones (In Tune industrial) y dónde va cada una
├── herramientas/
│   ├── verificar-sitio.mjs          verificación antes de subir (--sellar pone el ?v= de CSS y JS)
│   ├── verificar-publicacion.mjs    compara por hash lo publicado contra sitio/
│   ├── capturar-sitio.mjs           pruebas y capturas en Edge sin ventana (390, 1024 y 1440 px)
│   ├── publicar_sitio.py            sube sitio/ por SSH y lo verifica (--si para hacerlo de verdad)
│   ├── generar_imagenes.py          fotos del sitio (WebP sin metadatos) desde fuentes/fotos/
│   └── generar_og.py                imagen para redes desde fuentes/og/og.html (captura con Edge)
├── fuentes/                     NO se publica: originales de las imágenes
│   ├── fotos/                       foto de la portada (img_6213, 712 × 536) y las 4 de «Nuestro trabajo»
│   ├── logos/                       los 38 logos procesados, su manifiesto (nombre, orden, «compacto») y procesar.py, que los genera
│   ├── og/og.html                   maqueta de la imagen para redes
│   └── zyro/                        respaldo de la web actual: fotos originales, logos tal como están en Zyro y descartadas/ (lo que no pasa a la web, 9.2)
└── sitio/                       ← EXACTAMENTE lo que va dentro de public_html/
    ├── index.html               /             Inicio
    ├── nosotros/index.html      /nosotros/
    ├── servicios/index.html     /servicios/   anclas #asesoria #preventivo #predictivo #correctivo #planificacion
    ├── contacto/index.html      /contacto/    formulario que solo arma el mensaje
    ├── acceso/index.html        /acceso/      personal: botones a /ot/login.php y /ot/ (noindex permanente)
    ├── robots.txt               «Disallow: /» mientras dure el dominio temporal
    └── assets/
        ├── css/estilos.css      se pide como estilos.css?v=<hash> (sección 4)
        ├── js/sitio.js          se pide como sitio.js?v=<hash>
        ├── fonts/               Barlow 600 y 700 (OFL.txt al lado), subconjunto latino en woff2, 24 KB en total
        └── img/
            ├── logo-industec.png y .webp, favicon.svg, favicon-32.png, apple-touch-icon.png
            ├── og-industec-cadenas-v4.jpg             imagen para redes (1200 × 630), con la ilustración de la portada
            ├── ilus/                                  7 ilustraciones SVG a mano (portada, 5 servicios, escena monocroma)
            ├── portada-cine-2-640.webp                la foto real del técnico (servicios y contacto)
            ├── trabajo/                               4 fotos de «Nuestro trabajo» (640 × 480)
            ├── clientes/                              20 logos de clientes (96 px de alto)
            ├── marcas/                                18 logos de marcas de equipos (96 px de alto)
            └── iconos/                                37 SVG propios; se usan como máscara CSS y toman el color del texto
```

**No hay, y es a propósito:**

- `.htaccess` en la raíz: lo heredaría `/ot/`.
- Carpeta `ot/`.
- CDN, fuentes externas, analítica, cookies o formularios que envíen datos a un servidor.
- Imágenes enlazadas a Zyro o a Pexels: todo está en `sitio/assets`.
- `404.html` (ver el punto 5).
- `sitemap.xml` y `canonical`: se agregan con el dominio definitivo.

---

## 2. Cómo se publica

1. **Verificar en local.** Desde esta carpeta, ejecutar `node herramientas/verificar-sitio.mjs --sellar --manifiesto`.
   - Tiene que terminar en «OK: sin fallos». Si hay fallos, no reescribe `sitio.sha256`.
   - `--sellar` pone en las cinco páginas el `?v=` vigente de `estilos.css` y `sitio.js`.
   - Al final lista lo que **ya no está en `sitio/`** frente a la última publicación (`sitio.publicado.sha256`): eso se borra del servidor (pasos 3 y 4).
   - Si cambió el diseño, correr además `node herramientas/capturar-sitio.mjs`: prueba menú, formulario, ancla, movimiento reducido y modo sin JavaScript, audita contraste, desbordes, imágenes y logos, y deja capturas de las cinco páginas a 390 y 1440 px en la carpeta temporal del sistema. Tiene que terminar en «PROBLEMAS: ninguno».

2. **Revisar la raíz en Hostinger antes de subir.** Entrar a hPanel → Archivos → Administrador de archivos → `public_html/`.
   - Si hay un `index.php` o un `default.php` (la página de bienvenida de Hostinger), el servidor puede mostrarlo en lugar de `index.html`. Descargarlo como respaldo y retirarlo.
   - Si ya hay un `.htaccess`, **no se modifica ni se reemplaza**: puede ser del sistema.
   - **La carpeta `ot/` no se abre, no se mueve y no se sobrescribe.**

3. **Subir el CONTENIDO de `sitio/`, no la carpeta `sitio`.** A `public_html/` van `index.html`, `robots.txt`, `assets/`, `nosotros/`, `servicios/`, `contacto/` y `acceso/`.
   - Si se sube un `.zip`, los archivos tienen que estar en la raíz del `.zip`, y se extrae dentro de `public_html/`.
   - No se suben `sitio.sha256`, `herramientas/`, `fuentes/` ni los `.md`.
   - **Todavía no se borra nada.** Los archivos retirados (en la entrega del 11 de septiembre: sección 9.4) se borran en el paso 4, cuando `/` ya sirva el `index.html` nuevo: si se borran antes y el CDN tiene guardada la portada vieja, esa portada queda con imágenes rotas.

4. **Purgar la caché del CDN en hPanel y verificar por hash.** Ejecutar `node herramientas/verificar-publicacion.mjs`. El script:
   - **el origen**: descarga cada archivo con `?v=<marca de tiempo>` (salta la caché del CDN), compara su SHA-256 con el de `sitio/` y revisa su Content-Type y, en las imágenes, que el formato sea el del archivo;
   - **lo que recibe un visitante**: pide las cinco URL limpias **sin ninguna consulta**, y `estilos.css` y `sitio.js` **tal como los pide el HTML** (con su `?v=`), y los compara con `sitio/`; imprime `cache-control`, `age` y las cabeceras de caché del CDN. Si esto falla, el CDN guarda la versión vieja o ignora el `?v=`: purgar otra vez y volver a correr;
   - revisa que `/nosotros` redirija a `/nosotros/` y que `/ot/login.php` siga respondiendo;
   - **retirados**: lo que está en `sitio.publicado.sha256` y ya no en `sitio/`. Si sigue en el servidor, dice si ya se puede borrar (solo cuando `/` sirve el `index.html` nuevo) y termina con **código 2, «FALTA»**. Se borra (sección 9.4) y se vuelve a correr.

   Tiene que terminar en «OK». Entonces **reescribe `sitio.publicado.sha256`** con lo publicado, que es la base de la regla de caché del verificador: va en el commit de la publicación. Con `--sin-registro` no lo toca (pruebas). Si solo fallan los `.html`, `.css` o `.js` en el origen y el CDN de Hostinger tiene activada la minificación, hay que desactivarla y volver a subir. Los PNG y el JPG (si el CDN los recomprime) y, en el dominio temporal, el `robots.txt` salen con ⚠: los cambia el CDN, no la subida, y el original se comprueba en el disco del servidor (sección 8).

   En el PC de Andrés el antivirus inspecciona el HTTPS: correrlo como `NODE_OPTIONS=--use-system-ca node herramientas/verificar-publicacion.mjs`, para que Node use los certificados de Windows.

5. **Prueba a mano en un celular:**
   - **WhatsApp**: el chat se abre con el mensaje «…les escribo desde su página web…».
   - **Llamar**: marca el 099 788 7709.
   - **Formulario de /contacto/**: probar el envío por WhatsApp y por correo.
   - **Menú**: se abre y se cierra.
   - **/acceso/**: los botones «Ingresar al sistema» y «Abrir la app del técnico».
   - **Vista previa del enlace en WhatsApp**: tiene que salir la foto del técnico con las freidoras. WhatsApp guarda la vista previa de cada enlace; para probar, compartir la dirección con algo al final (por ejemplo `/?v=2`).

---

## 3. Al pasar al dominio definitivo (www.industec.me)

1. **Quitar el noindex de las páginas públicas.** Borrar `<meta name="robots" content="noindex, nofollow">` de `index.html`, `nosotros/index.html`, `servicios/index.html` y `contacto/index.html`; encima de esa línea está el comentario «DOMINIO TEMPORAL». **En `acceso/index.html` se queda**: su comentario dice «PERMANENTE».

2. **Reemplazar `robots.txt`** por:
   ```
   User-agent: *
   Disallow: /ot/
   Sitemap: https://www.industec.me/sitemap.xml
   ```
   `/acceso/` no se bloquea en `robots.txt`: si se bloquea, Google no llega a leer su noindex.

3. **Cambiar el dominio temporal por el definitivo.** Reemplazar `https://darkviolet-armadillo-872352.hostingersite.com` por `https://www.industec.me` en:
   - `og:url` y `og:image` de las cinco páginas;
   - los datos estructurados de `index.html` (`url`, `logo` e `image`).

   Después, buscar `darkviolet-armadillo` en `sitio/`: no debe quedar ninguno.

4. **Agregar `canonical` y `sitemap.xml`.** Poner `<link rel="canonical" href="https://www.industec.me/…/">` en las cuatro páginas públicas y crear un `sitemap.xml` con esas cuatro, sin `/acceso/` ni `/ot/`.

5. **Actualizar los verificadores en el mismo cambio.**
   - En `herramientas/verificar-sitio.mjs`: la constante `DOMINIO`, y las reglas que hoy exigen noindex en todas las páginas y «Disallow: /».
   - En `verificar-publicacion.mjs`: el dominio por defecto.

6. **Correo.** Al mover el dominio desde Zyro, conservar los registros **MX, SPF y DKIM** de industec.me. Si no, servicioalcliente@industec.me deja de recibir correo.

7. **Antes de dar de baja Zyro**: en `fuentes/zyro/` están respaldados (11 de septiembre de 2026) los logos de clientes y marcas, la foto de la portada y **9 de las 33 fotos** de la galería «Nuestro Trabajo», las que la web nueva usa o descartó a propósito. **Las otras 24 no se descargaron**: bajarlas antes de cerrar Zyro si César quiere conservarlas (los nombres salen del HTML de la página de servicios de Zyro). No se copió un escaneo de un certificado de recomendación que Zyro guarda entre las imágenes, porque tiene datos personales (nombre, cédula y firma).

8. **Comprobar** que `/nosotros` sin barra lleve a `/nosotros/`. Apache lo hace solo, sin `.htaccess`.

9. **Search Console.** Registrar el sitio a nombre de César, verificándolo por DNS: no agrega scripts a la página.

---

## 4. Cómo se edita

- **Textos**: se editan directamente en cada `index.html`. La cabecera, el pie, la barra del celular y el botón flotante se repiten en las cinco páginas, así que un cambio ahí se hace en las cinco.
- **Mensajes de WhatsApp**: son 7 (`contenido.md`, sección 2.1). Todos empiezan con «Hola INDUSTEC, les escribo desde su página web.»: así César cuenta los contactos que llegan por la web sin rastreadores. El verificador falla si alguno no empieza así.
- **Caché del CDN (regla del 11 de septiembre de 2026).** Hostinger guarda CSS, JavaScript e imágenes **7 días**, y en la raíz no puede haber `.htaccess` para cambiarlo. Por eso:
  - `estilos.css` y `sitio.js` se piden con `?v=<primeros 8 caracteres de su SHA-256>`. Después de tocarlos, correr el verificador con `--sellar`: lo actualiza en las cinco páginas.
  - **Una imagen que cambia de contenido cambia de nombre** (por ejemplo, la imagen para redes pasó de `og-industec.png` a `og-industec-cadenas.jpg`), y se actualizan todas sus referencias.
  - Se asume que los `.html` y el `robots.txt` no se guardan en el CDN, pero no hay constancia de que se hayan revisado sus cabeceras: por eso `verificar-publicacion.mjs` compara las URL limpias sin consulta y muestra `cache-control` y `age` (sección 2, paso 4).
  - El verificador falla si un archivo cambió desde la **última publicación** y se sigue pidiendo con el mismo nombre y sin `?v=`, o si un `?v=` no coincide con el archivo. La última publicación es `sitio.publicado.sha256`, que solo reescribe `verificar-publicacion.mjs` al terminar en OK; `sitio.sha256` es la lista de lo que se sube. Antes eran un solo archivo y, al regenerarlo antes de publicar, la regla comparaba contra lo que aún no estaba publicado.
- **Imágenes**: se regeneran con `python herramientas/generar_imagenes.py` (fotos) y `python herramientas/generar_og.py` (imagen para redes), sin metadatos. Nada se enlaza a Zyro ni a Pexels.
- **Logos**: la lista, el orden, el nombre (texto alternativo) y los recortes están en `fuentes/logos/procesar.py`. Se regeneran con `python fuentes/logos/procesar.py` (reproduce byte a byte los vigentes), se copian `fuentes/logos/clientes/` y `marcas/` a `sitio/assets/img/` y el HTML se ajusta al manifiesto. El verificador exige los 20 clientes en la portada y en servicios y las 18 marcas en servicios, en el orden del manifiesto, con su nombre como `alt`, `width` y `height` con la proporción del archivo, y `class="compacto"` en los cuadrados o verticales; y que cada archivo del sitio sea copia exacta del de `fuentes/logos`.
- **Colores** (en `:root` de `estilos.css`; regla del rediseño del 13 de septiembre de 2026, tres papeles que no se mezclan):

  | Papel | Color | Código | Uso |
  |---|---|---|---|
  | Marca | Azul del logo y su escala | `#48537E` · `#363F63` · `#232A45` · `#E6E9F2` · `#F3F5FA` | Titulares, íconos, botones neutros, fondos suaves y oscuros, pasos, chips |
  | Acento y urgencia | Rojo de marca / rojo de texto | `#CC504B` / `#B5413C` | La barra de las etiquetas, el bloque del correctivo, la franja de emergencia, la pastilla 24/7. Nada más |
  | Solo WhatsApp | Verde | `#117A3F` | Todo botón que abre WhatsApp (menú, portada, servicios, cierre, barra del celular, flotante) |
  | Ilustraciones | Crema y naranja | `#FBE9C8` · `#F6A23A` | Solo dentro de los SVG y como fondo de espera de sus tarjetas |

- **Tipografía**: titulares, cifras, botones y etiquetas en **Barlow** (600 y 700, OFL, autoalojada en `assets/fonts/`, `font-display: swap`); el cuerpo en la fuente del sistema. La variable es `--titulos`.
- **Íconos**: están en `assets/img/iconos/`, con trazo de 2 px en rejilla de 24. Desde el rediseño **todos** se usan como máscara CSS (`<span class="ico ico-nombre" aria-hidden="true">`), así toman el color del texto: azul en los círculos claros, blanco sobre el azul oscuro y el rojo. Cada archivo tiene su clase `.ico-*` en `estilos.css`.
- **Ilustraciones**: `assets/img/ilus/`, dibujadas a mano en SVG según `DIRECCION_ARTE.md` (ahí está la tabla de cuál va dónde). Al cambiar una, cambia también la imagen para redes (`python herramientas/generar_og.py`) y, si el CDN ya la sirvió con ese nombre, el nombre del archivo.
- **Después de cualquier cambio**, ejecutar `node herramientas/verificar-sitio.mjs` (con `--sellar` si cambió el CSS o el JS).
- **Cifras nuevas**: primero pasan por `NOTAS_PARA_CESAR.md`. Ninguna va directo al HTML.
- **Palabras vetadas**: el verificador falla si aparece «hogar», «casa», «doméstico/a», «particular», «electrodoméstico», «domicilio», «residencial», «vivienda» o «línea blanca» fuera de la línea que aclara que no se atiende a particulares, y si «servicio técnico autorizado» o «distribuidor oficial» aparece fuera del aviso de las marcas. Tampoco se escribe «cocina industrial»: en Ecuador también se llama así a la cocina a gas que se compra para la casa; se dice «cocina profesional». El verificador no lee el texto que va dentro de una imagen: por eso al logo de Menestras del Negro se le quitó el lema «Como preparado en casa.».

---

## 5. Comportamiento y accesibilidad

- **Móvil primero.**
  - En el celular: botón «Menú» (Escape lo cierra) y una barra fija con WhatsApp y Llamar. En la portada, la foto va primero (recorte 16:10) y, por debajo de 600 px, la placa de distintivos baja al final de la portada, para que la foto y el título completo entren en un teléfono de 667 px de alto.
  - En escritorio: navegación completa, el botón «Acceso al sistema» y un botón flotante de WhatsApp.
  - La barra y el botón flotante no aparecen en `/acceso/`.
- **Sin JavaScript**: el menú se muestra abierto y el formulario se oculta. En su lugar queda un aviso con WhatsApp, teléfono y correo.
- **Formulario**:
  - Valida nombre, **empresa o cadena** (obligatorio desde el 11 de septiembre de 2026), necesidad y detalle, y muestra una vista previa del mensaje. La línea «Empresa:» va siempre en el mensaje.
  - Arma el mensaje en el navegador. No usa cookies, almacenamiento del navegador ni llamadas de red.
  - WhatsApp se abre en otra pestaña. El correo abre el programa de correo con el asunto «Solicitud desde la web: <necesidad> - <empresa>».
  - Si se elige «Reparación o emergencia», aparece el aviso con el teléfono.
- **Movimiento**: la aparición de bloques y la entrada del texto de la portada se apagan con la preferencia «reducir movimiento». La foto de la portada no se anima: es el elemento principal de la carga. El desplazamiento suave se activa solo después de cargar, para que entrar a `/servicios/#correctivo` salte directo a la sección.
- **Logos**: tarjetas blancas del mismo alto, sin carrusel; cada logo conserva su proporción y sus colores, a 48 px de alto como máximo (40 en el celular), con carga diferida. Los cuadrados o verticales (proporción menor que 1,6: KFC, TropiBurger, El Español, Gus, TEVCOL, Vaco y Vaca, San Felipe, Taylor y Tecumseh) llevan `class="compacto"` y llegan a 64 px (52 en el celular), para no verse diminutos junto a los anchos.
- **Accesibilidad**:
  - Contraste AA en todo el texto.
  - Foco visible y enlace «Saltar al contenido».
  - Un solo H1 por página y textos alternativos (los logos, con el nombre de la empresa o la marca).
  - Etiquetas en todos los campos y errores asociados al campo.
- **Página 404**: no se incluye. Para servir una propia, Hostinger necesita un `.htaccess` (`ErrorDocument`), y en la raíz está prohibido. El texto está listo en `contenido.md`, sección 10, por si se configura desde hPanel sin `.htaccess`.

---

## 6. Verificación del 11 de septiembre de 2026 (primera versión)

- **`verificar-sitio.mjs`: 0 fallos.**
  - 45 archivos, unos 176 KB en total (límite: 1.536 KB).
  - Las cinco páginas tienen `lang="es-EC"`, title, description, noindex y Open Graph.
  - 7 mensajes de WhatsApp, todos con el saludo.
  - No hay referencias externas salvo `wa.me`, `tel:` y `mailto:`. El propio dominio solo aparece en los metadatos.
- **Edge headless por DevTools, a 390 y 1440 px**:
  - 0 errores de consola, 0 recursos fallidos, 0 desbordes horizontales y 0 textos bajo el contraste AA.
  - Comprobados el menú, el formulario, el modo de movimiento reducido y el modo sin JavaScript. En el formulario: errores, foco, vista previa, URL exactas de WhatsApp y de correo, y que no guarda datos ni hace peticiones.
- **Ancla** `/servicios/#correctivo`: la sección queda a 84 px del borde, justo debajo de la cabecera de 69 px. Se midió en Edge headless sin emulación de dispositivo.
- **Capturas**: la captura por línea de comandos de Edge no baja de unos 500 px de ancho, así que las de 390 px se tomaron por DevTools con métricas de 390 px.
- **Sin probar**:
  - WhatsApp y la llamada en un teléfono real;
  - Safari en iPhone.

---

## 7. Revisión previa a publicar (11 de septiembre de 2026)

Se revisó el sitio contra la lista de hechos públicos de www.industec.me y las reglas del proyecto. **Resultado: apto para publicar en el dominio temporal**, con los pendientes del final.

**Corregido**

- **Contenido.** Se retiró de `/nosotros/` la sección «Trabaja con nosotros», por tres motivos:
  - no figura en la lista de hechos públicos verificados;
  - su correo de destino está por confirmar;
  - recibiría hojas de vida (datos personales) sin aviso de privacidad.

  El texto se conserva en `contenido.md` (4.7) y la propuesta en `NOTAS_PARA_CESAR.md` (B13).
- **Accesibilidad (WCAG 2.5.3, nivel A).** Los cinco enlaces «Ver servicio» de la portada tenían como nombre accesible «Más sobre…», que no contiene el texto visible: el control por voz no los encontraba. Ahora dicen «Ver servicio de asesoría comercial», «Ver servicio de mantenimiento preventivo», etc.
- **ARIA.** La vista previa del formulario es un `<p>` y llevaba `aria-labelledby`, que un párrafo no admite. Se quitó.
- **Foco visible.** El contorno de foco del botón flotante de WhatsApp (#1E2438) desaparecía sobre el bloque oscuro de cierre (#242B45, 1,10:1). Ahora lleva un anillo blanco dentro del contorno oscuro.
- **Verificador** (`verificar-sitio.mjs`). Ahora también falla si:
  - un aria-label no contiene el texto visible;
  - un `<p>` lleva nombre ARIA;
  - aparece un término prohibido en comentarios HTML, CSS, JS, SVG o `robots.txt`;
  - aparecen los nombres Andrés, Valentina o Steven.

**Comprobado sin cambios**

- **Solo datos públicos.**
  - Los 17 clientes y las 18 marcas coinciden con la lista pública. **Corrección (revisión del 11 de septiembre de 2026): para los clientes no era cierto.** La galería pública tiene 20: faltaban Ali's Parrilladas & Pizzería, El Español e Il Cappo di Mangi, cuyos archivos no dicen de qué empresa son. La versión 1 publicada muestra 17 nombres; la segunda entrega, los 20 logos (sección 9.7). Las 18 marcas sí coinciden; la galería tiene además SilverChef, que no es fabricante y queda fuera.
  - La «consultoría integral gratuita» es el botón principal de la web actual.
  - No hay cifras internas, zonas, nombres, RUC ni datos de contratos.
  - Las imágenes no llevan metadatos (EXIF, XMP ni texto PNG).
- **Enlaces.** `/acceso/` → `/ot/login.php` y `/ot/`; WhatsApp `wa.me/593997887709`; `tel:+593997887709`; `mailto:servicioalcliente@industec.me`.
- **Raíz e indexación.** Sin `.htaccess` ni `ot/`; noindex en las cinco páginas; `robots.txt` con «Disallow: /»; títulos y descripciones únicos.
- **Contraste AA.**
  - #B5413C da 5,55:1 sobre blanco, 5,13:1 sobre #F5F6F9 y 4,90:1 sobre #FBEEED.
  - El texto blanco sobre la caja de viñetas de «Mantenimiento correctivo» da 4,64:1: cumple, pero justo. No aclarar ese fondo.
- **Edge headless por DevTools, a 390 y 1440 px.**
  - 0 problemas de consola, red, contraste o desborde.
  - Correctos el menú, el formulario, las anclas (#correctivo queda a 84 px del borde), el movimiento reducido y el modo sin JavaScript.
- **Peso.** 175,6 KB en 45 archivos. `sitio.sha256` regenerado.

**Pendiente (no impide subir al dominio temporal)**

- Andrés: confirmar «Acceso al sistema» como botón destacado de la cabecera. `contenido.md` pedía destacar WhatsApp.
- César: las propuestas de `NOTAS_PARA_CESAR.md` (A1–A6 y B1–B15), incluida B13.
- La subida real y `verificar-publicacion.mjs`: **hechas el 11 de septiembre de 2026** (sección 8).
- Prueba en un teléfono real: WhatsApp, llamada y Safari de iPhone.

---

## 8. Publicación del 11 de septiembre de 2026 (primera versión)

Se publicó en la raíz de `darkviolet-armadillo-872352.hostingersite.com` desde el PC de Andrés, por SSH, subiendo por nombre los siete elementos de `sitio/`: `index.html`, `robots.txt`, `assets/`, `nosotros/`, `servicios/`, `contacto/` y `acceso/`. Antes, en `public_html/` solo estaba `ot/`: no había `index.php`, `default.php` ni `.htaccess` que retirar. `ot/` no se tocó, y los permisos de la raíz y de `ot/` siguen en 755.

- **En el disco del servidor, los 45 archivos cuadraban con `sitio.sha256`.** Se comprueba así, desde esta carpeta:
  ```bash
  sed 's#  sitio/#  #' sitio.sha256 | ssh -i <llave> -p 65002 u671729428@82.25.73.181 \
    'cd domains/darkviolet-armadillo-872352.hostingersite.com/public_html && sha256sum -c --quiet - && echo OK'
  ```
- **Por la web** (`verificar-publicacion.mjs`): HTML, CSS, JS, SVG y WebP coinciden byte a byte; las cinco URL limpias sirven su `index.html`; `/nosotros` redirige con 301 a `/nosotros/`; `/ot/login.php` responde 200.
- **Dos diferencias las pone el CDN de Hostinger, no la subida.** El verificador las marca con ⚠ y no como falla:
  - **Los PNG** los recomprime al vuelo: el logo de 6.412 B llega con 7.495 B, igual que pasa con los íconos de `/ot/`.
  - **El `robots.txt`** del dominio temporal lo sirve el CDN, con «Googlebot: Disallow: /» y «*: Allow: /». El nuestro («Disallow: /») está en el disco, pero no llega. Mientras dure el dominio temporal, lo que evita la indexación es el `noindex` de cada página, que sí llega. En www.industec.me debería servirse el subido: comprobarlo al migrar.
- **Como la ve un navegador**, sin parámetros y en br, gzip y sin comprimir, responden 200: `/`, `/acceso/`, `/nosotros/`, `/servicios/`, `/contacto/`, `/robots.txt` y `/assets/css/estilos.css`.
- **El sistema, igual que antes:** `/ot/login.php`, `/ot/` y `/ot/sw.js` responden 200; `/ot/catalogos/locales.json` y `/ot/nucleo/config.php`, 403. Lo único que cambió es `/`, que antes daba 403 y ahora es la web.
- **Sigue sin probar:** WhatsApp y la llamada en un teléfono real, y Safari en iPhone.

**Cómo se publica desde la segunda entrega**, desde esta carpeta:

```bash
python herramientas/publicar_sitio.py              # ensayo: qué sube y qué borra
python herramientas/publicar_sitio.py --si         # sube, borra lo retirado y verifica disco y web
NODE_OPTIONS=--use-system-ca node herramientas/verificar-publicacion.mjs   # hasta «OK»: registra sitio.publicado.sha256
```

- **Qué hace `publicar_sitio.py`**: compara por hash `sitio/` con la raíz del servidor y sube solo lo que cambió.
- **Qué borra**: solo lo que salió de `sitio/`; nunca `ot/` ni un `.htaccess`.
- **Avisos**: avisa si algo cambió de contenido con la misma dirección.
- **Comprobación**: revisa el resultado en el disco y por la web.
- **Llave**: va en `INDUSTEC_LLAVE_SSH`; cada equipo usa la suya.
- **`NODE_OPTIONS=--use-system-ca`**: solo hace falta en el PC de Andrés, donde Avast inspecciona el HTTPS.

---

## 9. Segunda entrega del 11 de septiembre de 2026: proveedor técnico de cadenas

### 9.1 Qué cambió y por qué

César (a través de Andrés) pidió que la web no se lea como un servicio de reparación para el hogar o para particulares, sino como un proveedor técnico y empresarial de restaurantes, hoteles, bares y cafeterías, orientado a cadenas, y que se muestren los logos de clientes y marcas.

- **Portada.** Sale la ilustración `cocina-profesional.svg` (cocina a gas con horno, campana y refrigeradora, que se leía como cocina de casa). Entra la **foto propia del técnico con casco y chaleco revisando una batería de freidoras** (la misma de la portada de www.industec.me), en tarjeta y con una placa azul de tres distintivos: «Freidoras y mantenedores de calor», «Mantenimiento preventivo, predictivo y correctivo» y «Servicio para cadenas de restaurantes». **Cambió otra vez en la ronda 3 (9.9): la portada vuelve a ser una ilustración propia**, esta vez de la cocina de un local de cadena (no de casa), y el distintivo pasó a «Línea caliente, línea fría y ventilación».
  - Rejilla de escritorio 1.08fr / .92fr: la foto se ve a unos 492 px de ancho (el original mide 712) y los botones WhatsApp y Llamar quedan en una fila.
  - Sin animación de entrada en la foto (es el elemento principal de la carga) y con fondo oscuro mientras carga.
  - `srcset` 640w y 712w (la nativa), nunca ampliada.
- **Textos de Valentina** aplicados tal cual en las cinco páginas (`contenido.md`, 11 de septiembre de 2026): títulos, descripciones, Open Graph, H1, portada, «A quién servimos», franja de confianza, pie, cierres, servicios, contacto y datos estructurados. Los mensajes de WhatsApp «general» y «consultoría» terminan ahora en «para mi empresa».
- **Línea que filtra al particular**, en la portada, en contacto y (desde la revisión, sección 9.7) en la cabecera de servicios, en azul y nunca en rojo: «Atendemos a empresas y cadenas, bajo contrato de servicio. No reparamos equipos domésticos ni atendemos a particulares.»
- **«A quién servimos»** (portada): cinco tarjetas con íconos nuevos (cadenas de comida rápida, restaurantes, hoteles, bares y cafeterías, catering).
- **Logos**: los 20 clientes y las marcas profesionales (portada y servicios) pasan de nombres a logos, con la nota de clientes y el aviso de que las marcas no implican servicio autorizado. En la portada, los clientes suben justo después de «A quién servimos» y las marcas van a continuación (9.8). Samsung, LG y Westinghouse quedaron retenidas por unas horas (9.7) y Andrés pidió reponerlas el mismo día (9.9): son **18 marcas**, no 15.
- **«Nuestro trabajo»**: franja de cuatro fotos propias en la portada y en servicios, sin caras ni letreros de clientes.
- **Formulario**: el campo opcional «Tu negocio» se reemplaza por **«Empresa o cadena»**, obligatorio, y se agrega la opción «Un contrato de mantenimiento para mis locales».
- **Imagen para redes nueva**, con nombre nuevo: `og-industec-cadenas-v2.jpg`, con la foto de la portada (la v2 solo cambia el ícono de la freidora, 9.8). Es JPEG de unos 98 KB porque en PNG pesaba 356 KB, y WhatsApp no muestra las vistas previas muy pesadas. **Reemplazada por `og-industec-cadenas-v3.jpg` en la ronda 3 (9.9)**, con la ilustración de la portada.
- **Verificador**: acepta y valida `?v=`, y tiene las reglas nuevas de las secciones 4 y 9.3.

### 9.2 Imágenes: de dónde sale cada una y su licencia

**Tabla histórica (9.1–9.8).** Desde la ronda 3 (9.9) la portada es de nuevo una ilustración y la foto real quedó en «Nuestro trabajo» de servicios y en «Así te atendemos» de contacto, con otro nombre y otra gradación de color. La tabla vigente está en 9.9; esta se conserva como historial.

| Archivo (`sitio/assets/img/`) | Qué muestra | Origen | Licencia |
|---|---|---|---|
| `portada-tecnico-freidoras-640.webp` y `-712.webp` (**retirados en la ronda 3, 9.9**) | Técnico con casco y chaleco revisando una batería de freidoras | Foto propia publicada en la portada de www.industec.me. Original nativo de Zyro: `img_6213-YrD68EbDEzFeV827.jpg` (712 × 536), en `fuentes/fotos/`. Corrección suave de color, sin ampliar ni enfocar | Propia del cliente, que la usa en su web; César confirma (NOTAS, B2) |
| `trabajo/vitrina-caliente-640.webp` | Vitrina caliente con el tablero eléctrico abierto | Foto propia de la galería «Nuestro Trabajo» de www.industec.me (Zyro: `51-mp86peMZKPfKPw1D.jpg`), recortada para sacar la pared del salón con letras y un logo de Pepsi | Igual |
| `trabajo/horno-rotativo-640.webp` | Dos técnicos dando mantenimiento a un horno | Galería de www.industec.me (Zyro: `25-mxBlpEzRq4H6r10l.jpg`). No se les ve la cara | Igual |
| `trabajo/freidoras-presion-640.webp` | Dos freidoras de presión bajo la campana | Galería de www.industec.me (Zyro: `49-AwvJpEORB1IlW63J.jpg`), recortada para sacar a un técnico que miraba a la cámara | Igual |
| `trabajo/linea-despacho-640.webp` | Mantenedores de calor en la línea de despacho | Galería de www.industec.me (Zyro: `52-mp86peMZqPiwX68P.jpg`), recortada para sacar a un técnico de perfil | Igual |
| `clientes/*.webp` (20) | Logos de clientes | Los 20 logos de la galería pública de www.industec.me (originales en `fuentes/zyro/logos/`), recortados y exportados a 96 px de alto con sus colores por `fuentes/logos/procesar.py`. **San Felipe** va en gris oscuro porque el único archivo es blanco; a **Il Cappo di Mangi** se le quitaron la franja con la bandera y el fondo beige, y a **Menestras del Negro**, el lema «Como preparado en casa.» | Marcas de terceros, como referencia de trabajos realizados (nota visible); César autoriza en NOTAS, A6 |
| `marcas/*.webp` (18, no 15: ver 9.9) | Logos de fabricantes | Los fabricantes de equipo profesional de la galería de marcas de www.industec.me, con el mismo proceso. SilverChef queda fuera (ver abajo) | Marcas de sus fabricantes, con aviso visible de que no implican servicio autorizado ni distribución oficial |
| `logo-industec.png` y `.webp` | Logo de INDUSTEC | `logo-industec-final-Yg2aooX5OyTMV5gx.png` de Zyro (3840 × 717, en `fuentes/zyro/logos/`), reducido a 768 × 143 | Propio del cliente |
| `favicon.svg`, `favicon-32.png` y `apple-touch-icon.png` | Ícono del sitio | Monograma dibujado para el sitio con los colores del logo (la «I» en blanco y rojo sobre el azul #48537E); los dos PNG son el mismo dibujo | Propia |
| `og-industec-cadenas-v2.jpg` (**retirado, ver v3 en 9.9**) | Imagen para redes | Composición propia (`fuentes/og/og.html`) con el logo y la foto de la portada | Propia |
| `iconos/locales.svg`, `restaurantes.svg`, `hoteles.svg`, `bares-cafeterias.svg`, `catering.svg`, `freidora-canastillas.svg` (**retirado, ver 9.9**), `info.svg` | Íconos nuevos | Dibujados para este sitio con el mismo trazo que los demás. La freidora se dibujó tres veces: una caja con una canastilla se leía como freidora de mesa, y el gabinete con dos puertas y dos canastillas (`freidora.svg`), como una caja de regalo, por la cruz de las puertas. `freidora-canastillas.svg`, el frente de una freidora de piso con el tablero y sus perillas y dos canastillas de mango largo, sin cruz (9.8), tampoco se usa desde la ronda 3: el distintivo pasó a hablar de las tres líneas y lleva `termometro.svg` (9.9) | Propia |
| `iconos/` (los otros 30, de la primera versión) | Íconos de servicios, beneficios, valores, contacto e interfaz | Dibujados para este sitio: trazo de 2 px en rejilla de 24, rojo #CC504B | Propia |

- **No se usa ninguna imagen de Pexels.** Para comparar se bajaron dos (una freidora de presión con pollo, cuadro del video 7878258, y un chef con un horno combinado, foto 4253135) y se descartaron: la foto propia muestra justo lo que pidió César. Si alguna vez se usa una: licencia Pexels (uso gratuito, atribución no obligatoria), y se anota aquí con su URL de origen.
- **Todas las imágenes van sin metadatos** (ni EXIF, ni ICC, ni XMP).
- **Retiradas:** `cocina-profesional.svg` (ilustración propia) y `og-industec.png` (la imagen para redes anterior, con esa misma ilustración). En el pulido (9.8), también `iconos/freidora.svg` y `og-industec-cadenas.jpg`, que estuvieron publicados unas horas del 11 de septiembre de 2026. En la ronda 3 (9.9): `portada-tecnico-freidoras-640.webp` y `-712.webp`, `og-industec-cadenas-v2.jpg` e `iconos/freidora-canastillas.svg`.
- **Imágenes de las galerías de Zyro que no pasan a la web** (revisión del 11 de septiembre de 2026):
  - **SilverChef** (`p_1_1650_1859-A0xW7ox6QEsJVW0y.png`, galería de marcas): es una financiera de equipos para hostelería («hospitality equipment funding · Rent. Try. Buy.»), no un fabricante. Se avisa a César en NOTAS, A6.
  - **`r-1-AVLzEwB4b0SNZq7N.jpeg`** (Zyro la muestra hoy en servicios): no es un logo, sino una foto de archivo de un técnico reparando un horno doméstico empotrado. Lectura doméstica, justo la que César rechaza, y licencia desconocida.
  - Las dos se movieron de `fuentes/zyro/logos/` a **`fuentes/zyro/descartadas/`**, para que nadie las tome por logos utilizables.
  - `s7-blog2-support1-dJoBMjOjGNIKj00n.webp` (imagen de la plantilla de Zyro) e `industrial_kitchen_burger_king_2-mePJbQjE35Fbzq63.png` (muestra la marca de un tercero) no se descargaron.
  - El escaneo del certificado de recomendación tampoco se copió: tiene datos personales (sección 3, punto 7).

### 9.3 Caché del CDN: cómo queda resuelto

- `estilos.css` y `sitio.js` cambiaron de contenido: las cinco páginas los piden con `?v=` (el valor lo pone `--sellar`).
- Toda imagen nueva o cambiada tiene nombre nuevo: `og-industec-cadenas-v2.jpg`, `iconos/freidora-canastillas.svg`, `portada-tecnico-freidoras-*.webp`, `trabajo/`, `clientes/` y `marcas/`. `iconos/freidora.svg` y `og-industec-cadenas.jpg` sí llegaron a publicarse, así que sus versiones del pulido (9.8) cambiaron de nombre. Ninguna imagen **publicada** cambió de contenido con el mismo nombre. Lo que cambió en la revisión (el logo de Menestras del Negro, `iconos/freidora.svg` y `og-industec-cadenas.jpg`) nunca se publicó, así que conserva su nombre; el logo de V&V pasó a `clientes/vaco-y-vaca.webp` por claridad.
- Se asume que los HTML no se guardan en el CDN, pero no hay constancia de que se hayan revisado sus cabeceras. `verificar-publicacion.mjs` compara ahora lo que recibe un visitante, sin consulta, y muestra `cache-control` y `age`; esta publicación es además la primera con caché previa, así que también comprobará que el CDN no ignore el `?v=` de `estilos.css` y `sitio.js`.
- Reglas nuevas del verificador: `?v=` obligatorio en CSS y JS y coincidente con el archivo; solo `?v=` como consulta interna; falla si algo cambió desde la última publicación con el mismo nombre; no vuelve `cocina-profesional`; cada logo con `alt` (el nombre), `width`, `height`, `loading="lazy"` y `decoding="async"`; ninguna palabra de servicio doméstico fuera de la línea de filtro, y esa línea presente en la portada, en servicios y en contacto; «servicio técnico autorizado» y «distribuidor oficial» solo en el aviso de las marcas. Desde la revisión (9.7): los logos contra el manifiesto de `procesar.py` (sección 4), la regla doméstica ampliada y la base de la regla de caché en `sitio.publicado.sha256`.

### 9.4 Archivos que hay que BORRAR del servidor al publicar

Ya no están en `sitio/` y nadie los pide. Si se quedan, siguen accesibles por su dirección:

- `public_html/assets/img/cocina-profesional.svg`
- `public_html/assets/img/og-industec.png`
- desde el pulido (9.8): `public_html/assets/img/iconos/freidora.svg` y `public_html/assets/img/og-industec-cadenas.jpg`

Por SSH, desde el PC de Andrés (`-f`: los dos primeros ya se borraron el 11 de septiembre de 2026):

```bash
ssh -i <llave> -p 65002 u671729428@82.25.73.181 \
  'cd domains/darkviolet-armadillo-872352.hostingersite.com/public_html && rm -fv assets/img/cocina-profesional.svg assets/img/og-industec.png assets/img/iconos/freidora.svg assets/img/og-industec-cadenas.jpg'
```

Nada más se borra: el resto se sobrescribe o es nuevo (el logo `clientes/v-v.webp` nunca se publicó, así que no hay nada que borrar de él). **`ot/` no se toca.**

**Cuándo**: solo cuando `/` ya sirva el `index.html` nuevo. `verificar-publicacion.mjs` lo dice, y termina en «FALTA» mientras sigan en el servidor. Después, `sha256sum -c` contra el `sitio.sha256` nuevo (sección 8) tiene que dar OK, y `verificar-publicacion.mjs` también; al terminar en OK registra `sitio.publicado.sha256`.

### 9.5 Verificación en local (11 de septiembre de 2026)

Repetida después de las correcciones de la revisión (sección 9.7):

- **`verificar-sitio.mjs --sellar --manifiesto`: «OK: sin fallos».**
  - 95 archivos, 830,9 KB (límite: 1.536 KB). Lo más pesado: la imagen para redes (98 KB) y la foto de la portada (44 y 34 KB).
  - 7 mensajes de WhatsApp, todos con el saludo.
  - 20 logos en la portada y 38 en servicios (20 clientes y 18 marcas), en el orden del manifiesto, con su nombre como texto alternativo, la proporción de su archivo, tamaño reservado y carga diferida. Los archivos del sitio son copia exacta de `fuentes/logos`.
  - La línea de filtro está en la portada, en servicios y en contacto.
  - Frente a la última publicación (`sitio.publicado.sha256`, 45 archivos), con el mismo nombre solo cambian `estilos.css` y `sitio.js`, que llevan `?v=`, y se retiran `cocina-profesional.svg` y `og-industec.png`. `sitio.sha256` regenerado (95 archivos).
  - **Prueba en negativo**, sobre una copia alterada del sitio: detecta el cliente que falta, un `alt` que no es el del manifiesto, la clase `compacto` que falta, una proporción equivocada, «electrodomésticos», la línea de filtro que falta en servicios, un logo que no es copia de `fuentes/logos` y un archivo sobrante.
- **Edge sin ventana (`capturar-sitio.mjs`), a 390 y 1440 px, y la portada a 1024 px: «PROBLEMAS: ninguno».**
  - 0 errores de consola o de red, 0 desbordes horizontales, 0 textos bajo el contraste AA, todas las imágenes cargadas y ningún logo deformado ni por encima de su alto máximo. Los logos anchos miden hasta 48 px de alto (40 en el celular) y los compactos hasta 64 (52). Los de menor superficie son ahora Vaco y Vaca (50 × 64) y TropiBurger (54 × 64); el más bajo, Copeland, queda a unos 25 px porque es muy ancho.
  - **Portada a 390 px**: la foto (350 × 219) empieza a los 97 px y el título termina a los 538 px, así que la foto y el título completo entran en un teléfono de 667 px de alto, donde la barra fija empieza a los 598. Antes el título empezaba a los 560 px. La placa de distintivos queda después de la línea de filtro.
  - **Portada en escritorio**: la foto es el elemento principal de la carga (296 ms a 390 px y 288 ms a 1440 px, en local) y no hay desplazamientos (CLS 0). A 1440 px la foto mide 491 × 370 y usa la versión de 640; los tres distintivos van en una línea cada uno y los botones WhatsApp y Llamar en una fila. A 1024 px los botones se apilan (la columna de texto mide 492 px). La trama de puntos se ve a 1024 y 1440, no en el celular.
  - **Formulario**: vacío marca los cuatro errores y lleva el foco al nombre; con solo el nombre, el foco va a «Empresa o cadena»; la vista previa arranca con «Empresa: …»; las URL de WhatsApp y de correo salen exactas (asunto «Solicitud desde la web: Reparación o emergencia - Cadena de prueba»); no guarda nada ni hace peticiones.
  - **Menú** (Escape lo cierra y devuelve el foco), **ancla** `/servicios/#correctivo` a 84 px del borde (cabecera de 69), **movimiento reducido** (sin animaciones) y **sin JavaScript** (menú abierto, formulario oculto): correctos.
- **Nota sobre el arnés.** En esta máquina, una sesión de Edge sin ventana que cambia varias veces de tamaño emulado deja de pintar cuadros: las capturas tardan minutos, no carga lo diferido y el salto al ancla no se ejecuta. No es un problema del sitio: con la pestaña al frente, el sitio nuevo y la versión publicada se comportan igual (el ancla aterriza a 84 px en los dos). `capturar-sitio.mjs` usa una sesión corta por ancho, la pestaña al frente y un repintado forzado antes de medir. Así termina en poco más de un minuto.
- **Sin probar**: Safari en iPhone (sobre todo el recorte 16:10 de la foto y la placa que baja con `display: contents`), WhatsApp y la llamada en un teléfono real, la vista previa del enlace en WhatsApp y `verificar-publicacion.mjs` contra Hostinger (solo se probó contra un servidor local).

### 9.6 Pendiente

- **César** (`NOTAS_PARA_CESAR.md`): A6 (autorizar los logos; confirmar la grafía de algunos nombres y los 3 clientes que se agregaron; decir si se reponen Samsung, LG y Westinghouse, retiradas mientras tanto, y si tiene el logo de Menestras del Negro sin lema), B2 (confirmar que las fotos son suyas y que el técnico es de su personal, el archivo original de la foto de la portada y una o dos fotos de un mantenedor de calor o de una freidora de presión), B4, B15 y B3.
- **Valentina**: validar los textos de maquetación que no estaban en su entrega (los tres distintivos de la portada, el subtítulo y los pies de foto de «Nuestro trabajo» y el subtítulo de la imagen para redes); están en `contenido.md`, secciones 3.1 y 3.8.
- **Probar en un teléfono real**: el recorte 16:10 de la foto en Safari de iPhone, WhatsApp, la llamada y la vista previa del enlace en WhatsApp.
- **Andrés**: confirmar si `/acceso/` puede nombrar el sistema («B.IA Soft ERP»). En la revisión se quitó el nombre, que no es un dato público de www.industec.me; reponerlo son tres textos (`contenido.md`, sección 7).
- **Valentina**: la propuesta de `contenido.md` 2.1 para el mensaje de emergencia («…un equipo de mi local dejó de funcionar.»), que no está aplicada.
- **Publicado** el 11 de septiembre de 2026 desde el PC, con la verificación de la sección 8 en OK (9.8). No hizo falta purgar la caché del CDN: todo lo que cambió de contenido cambió de nombre o lleva `?v=`.

### 9.7 Correcciones de la revisión previa a publicar (11 de septiembre de 2026)

La revisión encontró dos fallas que **bloqueaban** la publicación. Las dos están corregidas:

- **Faltaban 3 clientes.** La galería pública de www.industec.me tiene 20 logos y el sitio publicaba 17. Ali's Parrilladas & Pizzería (`logo-YD0DMyLBVqCoDwO5.png`), El Español (`logo_header-dJoBaEz5XjTB7VJ7.png`) e Il Cappo di Mangi (`descarga-AQE47QwDr9U5295K.jpg`) estaban respaldados, pero sus nombres de archivo no dicen de quién son. Ahora van en la portada y en servicios, en el orden de Zyro: Ali's después de American Deli, El Español después de Yanbal e Il Cappo di Mangi después de Baskin Robbins. A Il Cappo di Mangi se le quitaron la franja con la bandera y el fondo beige.
- **«V&V» no era el nombre del logo.** El logo dice «Vaco y Vaca — Restaurant · Cafetería»: ese es ahora su texto alternativo, y el archivo se llama `clientes/vaco-y-vaca.webp`.

Además, las mejoras baratas y seguras:

- **Grafías del logo**: Decameron, Naturíssimo, TropiBurger y Corporación GPF (antes Decamerón, Naturissimo, Tropi Burger y GPF). NOTAS, A6, se lo confirma a César.
- **Marcas**: abren con los fabricantes de cocina profesional y siguen refrigeración y extracción. Samsung, LG y Westinghouse, las de consumo, **no se publican** hasta que César diga si son refrigeración comercial (NOTAS, A6): `procesar.py` las deja en `fuentes/logos/retenidas/`, fuera de las carpetas que se copian al sitio, y el verificador falla si alguna aparece en `sitio/`.
- **Pie de foto de la vitrina**: decía «Mantenedor de calor: revisión del tablero eléctrico» sobre la foto de una vitrina caliente (Fabristeel, con el tablero abierto). Ahora dice «Vitrina caliente: revisión del tablero eléctrico», en la portada y en servicios. Los mantenedores de calor son los de la foto de la línea de despacho.
- **Menestras del Negro sin el lema «Como preparado en casa.»**, la única «casa» del sitio, que el verificador no ve porque está dentro de la imagen. NOTAS, A6, le pide a César un logo oficial sin lema.
- **Logos cuadrados o verticales más grandes** (`class="compacto"`: 64 px en escritorio y 52 en el celular), para que Vaco y Vaca, TropiBurger, Tecumseh o Taylor no se vean diminutos.
- **San Felipe, en gris oscuro desde el script.** Antes, el gris (#333333) salía como «alternativa» de `procesar.py` y se copiaba a mano al sitio; al regenerar los logos en esta revisión, el sitio quedó un momento con el original blanco, invisible sobre la tarjeta blanca (se vio en las capturas). Ahora `procesar.py` deja el gris en `clientes/san-felipe.webp` y el blanco en `alternativas/san-felipe-blanco.webp`, y el verificador exige que el sitio sea copia exacta de `fuentes/logos`.
- **Línea de filtro en servicios**, la página de «Un equipo ya falló» y del botón de emergencia; el verificador la exige ahí.
- **Portada en el celular**: la placa de distintivos baja debajo del texto; en un teléfono de 667 px entran la foto y el título completo.
- **Ícono de freidora** redibujado como freidora de piso (gabinete con dos puertas y dos canastillas), también en la imagen para redes. Se leía como una caja de regalo y se reemplazó en el pulido (9.8).
- **`/acceso/` sin el nombre del sistema** («el sistema de gestión de INDUSTEC»), hasta que Andrés confirme que se puede nombrar.
- **Fuentes y licencias** completas en la sección 9.2, con lo que se excluyó de Zyro y por qué. La foto `r-1` (un horno doméstico) y SilverChef pasan a `fuentes/zyro/descartadas/`.
- **Herramientas**:
  - `sitio.publicado.sha256` separado de `sitio.sha256`, creado desde el `sitio.sha256` de git HEAD, que es el publicado (45 archivos, sección 8).
  - `verificar-sitio.mjs`: filtro en servicios, regla doméstica ampliada (electrodoméstico, domicilio, residencial, vivienda, línea blanca) y logos contra el manifiesto de `procesar.py`, que ahora corre en su sitio y reproduce byte a byte los logos vigentes.
  - `verificar-publicacion.mjs`: lo que recibe un visitante sin consulta, los `?v=` tal como los pide el HTML, cabeceras de caché, Content-Type y formato, el JPG tratado como los PNG, los retirados solo cuando `/` ya es la portada nueva, y registro de `sitio.publicado.sha256`. Se probó contra un servidor local que imita al CDN, en tres escenarios: publicación limpia (OK), un archivo retirado que sigue en el servidor («FALTA», código 2) y la portada vieja en caché (falla y pide purgar). **No se probó contra Hostinger.**
  - `capturar-sitio.mjs`: el alto máximo de cada logo según su clase, y la prueba de los 667 px.
- **Propuesta sin aplicar**: el mensaje de emergencia, para Valentina (`contenido.md`, 2.1).

### 9.8 Pulido después de publicar la segunda entrega (11 de septiembre de 2026)

La segunda entrega se publicó al mediodía, con las dos correcciones que la bloqueaban (Samsung, LG y Westinghouse retenidas, y el pie de foto de la vitrina; 9.7). Enseguida se aplicaron las mejoras que habían marcado los revisores y que tocan el pedido de César y de Andrés:

- **Marcas en la portada** (Andrés: «no olvides colocar los logos de los clientes y las marcas»): va el bloque 5.8 completo, con su aviso, justo después de los clientes y con el mismo fondo (`seccion--sin-arriba`, la clase que ya usa contacto). El verificador exige ahora las 15 marcas también en la portada.
- **Título de la portada**: «INDUSTEC | Mantenimiento de cocinas profesionales para cadenas». Antes decía «… de equipos de cocina …», que se lee igual para un electrodoméstico de casa. También cambia en `og:title`.
- **Ícono de la freidora**: `freidora.svg` se leía como una caja de regalo, por la cruz de las puertas. El nuevo, `freidora-canastillas.svg`, es el frente de una freidora de piso con el tablero, dos perillas y dos canastillas de mango largo, sin cruz. Se eligió entre tres variantes dibujadas y comparadas a 19 y a 150 px. Lleva nombre nuevo porque el viejo ya estaba en el CDN.
- **Imagen para redes v2** (`og-industec-cadenas-v2.jpg`), con el ícono nuevo. Las cinco páginas y los datos estructurados la piden con su nombre nuevo.
- **Respaldo de Zyro** (sección 3, punto 7, y NOTAS, A6): decía que las fotos de «Nuestro trabajo» estaban respaldadas. En realidad lo están 9 de las 33, más la de la portada.
- **`verificar-publicacion.mjs`**: el `robots.txt` que Hostinger sirve en el dominio temporal llega con `Content-Type: text/plain, text/plain`. Si el contenido no es el nuestro, eso es un aviso y no una falla; con el nuestro, la regla sigue estricta.
- **Sin hacer, anotado**:
  - logos de 192 px de alto para pantallas 2x y 3x (hoy son de 96 px, y varios originales de Zyro no dan para más);
  - los logos muy anchos (Copeland, Yanbal) quedan bajos en su tarjeta;
  - la primera pantalla en tableta vertical y en iPhone SE (en el celular, la barra fija de abajo mantiene WhatsApp y Llamar siempre a la vista).

### 9.9 Ronda 3 del 11 de septiembre de 2026: pedidos de Andrés sobre el diseño

Con la segunda entrega ya publicada, Andrés pidió, sobre el resultado que vio, siete ajustes (textual, resumido):

1. Mejorar la foto de la portada para que se vea más cinematográfica, o volver al estilo de dibujo del principio pero con el contenido correcto.
2. No hablar solo de freidoras y mantenedores de calor: también de línea fría y de ventilación.
3. La segunda foto de «Nuestro trabajo» es un horno, no una cocina genérica.
4. En Nosotros, cambiar «estándares internacionales» por «las mejores prácticas y normas… operaciones rentables, eficientes y bajo métodos probados».
5. Rediseñar la secuencia «¿Qué servicio necesitas?» de servicios, que no le gustaba.
6. «Revisión de una batería de freidoras» → «Revisión de freidoras».
7. La página de contacto se sentía impersonal.

Y, en mensajes aparte esa misma tarde: reponer las marcas que se habían retirado («para que quede todo completo y se vea bien») y autorizar el nombre del sistema en `/acceso/`.

**Cómo se decidió.** Para la portada, la guía de servicios y contacto se generaron varias versiones (3 gradaciones de foto y 2 ilustraciones para la portada; 2 diseños para la guía; 2 para contacto) y las evaluaron jueces independientes con criterios fijos. Ganaron: la **ilustración** de la portada (83 contra 82 la otra ilustración, y por delante de las tres fotos), la guía en **lista de decisiones** (89 a 83) y contacto en formato de **conversación** (84 a 82). Cada ganadora pasó después por una ronda de pulido con las correcciones de los jueces y una revisión adversarial final.

**Qué cambió en el sitio:**

- **Portada.** La foto del técnico sale de la portada. Entra una **ilustración propia** (`portada-cocina-cadena-frontal.svg`), en el estilo del dibujo original pero con el contenido correcto: la cocina de un local de cadena, con refrigeración comercial (no doméstica), mesa fría, pantalla de pedidos, campana con filtros y ducto, batería de tres freidoras con el aceite visible, mantenedor de calor y un técnico con casco, chaleco y multímetro diagnosticando una freidora. El distintivo bajo la ilustración pasa de «Freidoras y mantenedores de calor» a **«Línea caliente, línea fría y ventilación»**, con un ícono de termómetro (`iconos/termometro.svg`) en vez de `freidora-canastillas.svg`. El texto de la portada y la tarjeta «Cadenas de comida rápida» de «A quién servimos» dicen ahora lo mismo: línea caliente, línea fría y ventilación.
  - La foto real (`img_6213`) no se pierde: sigue publicada, con la gradación «clave baja» (más oscura y de más contraste que el original), como `portada-cine-2-640.webp`, en «Nuestro trabajo» de servicios («Revisión de freidoras») y en «Así te atendemos» de contacto.
  - Imagen para redes: `og-industec-cadenas-v3.jpg`, con la misma ilustración en la tarjeta. Reemplaza a la v2.
  - `herramientas/gen_ilustracion.py` y `herramientas/render.sh` generan y renderizan el SVG; `--sin-insignia` quita la insignia redonda de la esquina.
- **Nosotros.** La tarjeta «Estándares internacionales» pasa a **«Mejores prácticas y normas»**, con el texto «Operaciones rentables, eficientes y bajo métodos probados.» La misma frase de «estándares internacionales» también estaba en la Asesoría comercial de servicios y se corrigió ahí: «Aplicamos las mejores prácticas y normas del sector, con métodos probados, en cada etapa…».
- **Pies de foto**: «Mantenimiento en la cocina de un restaurante» → **«Reparación de un horno»** (portada y servicios); «Revisión de una batería de freidoras» → **«Revisión de freidoras»** (servicios); el alt de la misma foto en contacto, igual.
- **Servicios, «¿Qué servicio necesitas?».** Deja de ser cinco tarjetas en fila y pasa a una **lista de decisiones**: una tarjeta blanca con cinco filas «tu situación → te sirve», la del correctivo con barra y fondo rojo y un sello compacto «24/7». Las preguntas se reformularon con textos que ya estaban en el sitio (por ejemplo, la del preventivo sale de «para que tus equipos no fallen»).
- **Contacto**, rediseñado como una **conversación con INDUSTEC**: burbujas de saludo, un botón principal de WhatsApp con la nota «Tu primer mensaje ya va escrito», la foto del equipo (el horno) dentro de la charla en el celular y a un lado en escritorio, «Así te atendemos» en tres pasos sin plazos, y la vista previa del formulario con apariencia de chat de WhatsApp. La columna con la foto grande y la placa de distintivos solo se muestra desde 960 px: en el celular repetía «Servicio de emergencia 24/7» justo antes de la franja roja de emergencia.
- **Marcas**: Samsung, LG y Westinghouse, retiradas por unas horas (9.7), se repusieron el mismo día por pedido de Andrés («repón las marcas que falten para que quede todo completo y se vea bien»). Son **18 marcas**, no 15. `fuentes/logos/procesar.py` deja la lista `RETENIDAS` vacía, para el día que haga falta retirar una marca de verdad.
- **`/acceso/`**: ya nombra el sistema, «B.IA Soft ERP», en el texto, la `description` y `og:description` (Andrés lo autorizó el mismo día).
- **Limpieza**: se retiraron del sitio `portada-tecnico-freidoras-640.webp` y `-712.webp`, `og-industec-cadenas-v2.jpg` e `iconos/freidora-canastillas.svg` (9.4 los lista para borrar del servidor al publicar).

**Revisión final y lo que quedó pendiente.** Un revisor de identidad encontró y se corrigieron dos incoherencias que había dejado el pulido: la frase de «estándares internacionales» seguía en servicios (arriba), y el alt de la foto en contacto todavía decía «batería de freidoras». La revisión técnica no llegó a completarse (límite semanal de la cuenta): antes de publicar se corrió el verificador completo a mano y se revisaron las capturas de las 5 páginas a 390 y 1440 px.

**Para César (ver NOTAS_PARA_CESAR.md):** confirmar los colores del uniforme del técnico dibujado, si el «3°» de la pantalla del refrigerador y la insignia con la llave se quedan, si quiere una plancha o una máquina de hielo en la ilustración, que la foto de las freidoras con este tratamiento de color se puede publicar, quién responde WhatsApp/teléfono/correo (la página dice «nuestro equipo», sin «técnico»), y si el horno de la foto es de un restaurante.

**Sin hacer, anotado:**
- ninguna foto real muestra línea fría ni ventilación (solo freidoras y mantenedores); falta pedirle a César 2 o 3 fotos de refrigeración o de una campana;
- el avatar de la conversación de contacto es el favicon, no una foto del equipo;
- el «legado de más de 40 años» se redacta distinto en portada, Nosotros y servicios (NOTAS, B9 sigue abierto);
- `sitio.publicado.sha256` se actualiza recién cuando se publique esta ronda (sección 8). *(Se publicó; ver la sección 10.)*

---

## 10. Tercera entrega, 13 de septiembre de 2026: el rediseño de imagen y navegación (T2.17)

**Qué pidió Andrés (12 de septiembre de 2026):** un rediseño moderno e intuitivo que exhiba y venda mejor el servicio **con la información que ya tiene** (los textos aprobados no cambian), e ilustraciones en el estilo de las portadas de *In Tune* (trazo negro limpio, color plano, figuras estilizadas en acción, frases manuscritas) **en contexto industrial y de servicio técnico**. La guía está en `DIRECCION_ARTE.md`. Lo que la auditoría del 12 de septiembre encontró y esto resuelve: la oferta enterrada bajo 38 logos, el acceso del personal como único botón destacado del menú, el rojo como botón principal y como urgencia a la vez, tipografía solo del sistema, 18 íconos iguales en círculos rosados, la consultoría gratuita como enlace subrayado y Nosotros sin una sola imagen.

### 10.1 Qué cambió

- **Ilustraciones** (`assets/img/ilus/`, siete SVG a mano, todas por debajo de 15 KB, con `role="img"` y `aria-label`): la portada (la cocina de un local de cadena con tres técnicos en acción y el administrador del local), una viñeta por servicio (la del correctivo sobre rojo) y una escena monocroma del equipo bajando de la camioneta para Nosotros y Contacto. Tabla completa en `DIRECCION_ARTE.md`. La imagen para redes pasa a `og-industec-cadenas-v4.jpg`.
- **Sistema visual**: Barlow 600/700 autoalojada (24 KB) para titulares, cifras, botones y etiquetas; el azul del logo y su escala como color de marca; el rojo solo como acento y urgencia; el verde solo en WhatsApp; los 37 íconos como máscara CSS. Tres registros de sección: venta a dos columnas con etiqueta e ilustración, prueba en banda (cifras y logos) y apoyo en rejilla compacta.
- **Portada y navegación**: en el menú, «Escríbenos por WhatsApp» (verde) y, en escritorio, «Emergencias 24/7 · 099 788 7709»; «Acceso del personal» solo al pie. Primera pantalla con la ilustración y dos botones: la consultoría integral gratuita (WhatsApp) y las emergencias 24/7 (llamar). Orden nuevo: portada → confianza («Desde 2010» · «+40 años» · «24/7» · «Un solo proveedor», los mismos hechos) → **Qué hacemos** (cinco tarjetas con su viñeta) → A quién servimos → Nuestro trabajo → Por qué un proveedor integral → **clientes y marcas en una sola franja** (gris, color al pasar, con sus dos notas) → cierre. En servicios, cada servicio va a dos columnas con su viñeta, alternando lados. Nosotros lleva la escena monocroma en el encabezado y la foto de la línea de despacho en la historia. Contacto conserva la conversación de la ronda 3 con el sistema visual nuevo.
- **Formulario de contacto**: «Enviar por WhatsApp» y «Enviar por correo» son **enlaces reales** cuyo `href` lleva el mensaje armado en cada tecla; si falta un dato, el clic se frena y se marcan los campos. Ya no hay clic sintético sobre un enlace oculto (W-12). `capturar-sitio.mjs` prueba ese comportamiento.
- **Retirados del sitio**: `portada-cocina-cadena-frontal.svg` y `og-industec-cadenas-v3.jpg` (los borró `publicar_sitio.py` al publicar).

### 10.2 Verificación en local

`node herramientas/verificar-sitio.mjs --sellar --manifiesto`: **OK: sin fallos**; 104 archivos, 932,9 KB (límite 1.536 KB); 38 logos en la portada y en servicios; los 7 mensajes de WhatsApp (el verificador cuenta 8 porque el formulario de contacto parte del mensaje general y lo reemplaza por el armado; es el mismo texto). `node herramientas/capturar-sitio.mjs`: **PROBLEMAS: ninguno**; sin desbordes ni fallos de contraste a 390 y 1440 px; menú, formulario (los cuatro errores con los campos vacíos, el foco al primero, WhatsApp y correo con el mensaje exacto, 0 almacenamiento, 0 cookies), ancla bajo la cabecera, movimiento reducido y modo sin JavaScript en verde; el título de la portada entra con la ilustración en un teléfono de 667 px. Los dos hallazgos de la primera corrida se corrigieron antes de publicar: el sello «Servicio de emergencia 24/7» salía blanco sobre blanco (la regla `.servicio--urgente p` pintaba el `<p>` del sello) y la prueba del formulario buscaba los botones viejos.

### 10.3 Publicación (13 de septiembre de 2026, desde el PC de Andrés)

`python herramientas/publicar_sitio.py --si`:

```
sitio/: 104 archivos · en el servidor: 95
subir (18):  acceso/index.html, assets/css/estilos.css, assets/fonts/barlow-bold.woff2, assets/fonts/barlow-semibold.woff2, assets/fonts/OFL.txt, assets/img/ilus/equipo-monocromo.svg, assets/img/ilus/portada-cocina-cadena.svg, assets/img/ilus/servicio-asesoria.svg, assets/img/ilus/servicio-correctivo.svg, assets/img/ilus/servicio-planificacion.svg, assets/img/ilus/servicio-predictivo.svg, assets/img/ilus/servicio-preventivo.svg, assets/img/og-industec-cadenas-v4.jpg, assets/js/sitio.js, contacto/index.html, index.html, nosotros/index.html, servicios/index.html
borrar (2): assets/img/portada-cocina-cadena-frontal.svg, assets/img/og-industec-cadenas-v3.jpg

disco del servidor: 104 de 104 cuadran por hash
755 .
755 ot
sin .htaccess en la raíz
web: 5 páginas y 58 recursos comprobados en dos codificaciones · /ot/login.php 200
  aviso: /assets/img/apple-touch-icon.png (gzip): el CDN recomprime las imágenes
  aviso: /assets/img/logo-industec.png (identity): el CDN recomprime las imágenes
OK: publicado y verificado.
```

`NODE_OPTIONS=--use-system-ca node herramientas/verificar-publicacion.mjs` (resumen; los ⚠ son los de siempre: los PNG, el JPG y el `robots.txt` los cambia el CDN):

```
✓ /assets/css/estilos.css?v=dcc6c927 (como lo pide el HTML)   [cache-control=public, max-age=604800 · x-hcdn-cache-status=MISS]
✓ /assets/js/sitio.js?v=26dec5c1 (como lo pide el HTML)       [cache-control=public, max-age=604800 · x-hcdn-cache-status=MISS]
✓ / sirve el index.html subido · ✓ /nosotros/ · ✓ /servicios/ · ✓ /contacto/ · ✓ /acceso/   [x-hcdn-cache-status=DYNAMIC]
✓ /nosotros redirige a /nosotros/ (HTTP 301)
✓ /ot/login.php responde (HTTP 200)
✓ assets/img/og-industec-cadenas-v3.jpg: ya no está en el servidor (HTTP 404)
✓ assets/img/portada-cocina-cadena-frontal.svg: ya no está en el servidor (HTTP 404)

OK: lo publicado coincide con sitio/, un visitante recibe lo mismo y el sistema sigue respondiendo (4 diferencia(s) que pone el CDN, marcadas con ⚠).
Registrado: sitio.publicado.sha256 (104 archivos)
```

Y aparte, con `curl`: `/ot/login.php` → 200 y `/ot/catalogos/locales.json` → 403, que es el criterio de T2.17.4.

### 10.4 Pendiente

- La **prueba a mano en un celular** (sección 2, paso 5) y la vista previa del enlace en WhatsApp con la imagen v4: las hace Andrés.
- Las cinco URL propias por servicio (SEO, W-11) y cualquier foto o dato nuevo requieren su aprobación (y las fotos, la de César: NOTAS B2).
- Cuando César confirme los colores del uniforme o pida otro equipo en la ilustración, se edita el SVG a mano (es la fuente) y se regenera la imagen para redes con un nombre nuevo.
