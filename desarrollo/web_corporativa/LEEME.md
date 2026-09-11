# Web corporativa de INDUSTEC

Sitio estático (HTML + CSS + JavaScript, sin frameworks ni paso de compilación) que reemplaza la web de Zyro de www.industec.me. Mientras tanto vive en la **raíz** del dominio temporal `darkviolet-armadillo-872352.hostingersite.com`, junto al sistema B.IA Soft ERP, que está en `/ot/` y no se toca desde aquí.

- Lo armó Steven (desarrollo de INDUSTECH) el 11 de septiembre de 2026 con los textos de `contenido.md` (Valentina).
- Solo publica lo que ya es público en www.industec.me.
- Lo que espera el visto bueno de César está en `NOTAS_PARA_CESAR.md` y **no** está en el HTML.

**Estado al 11 de septiembre de 2026**: **publicado** en la raíz del dominio temporal y verificado por hash, en el disco del servidor y por la web (sección 8).

---

## 1. Qué hay en esta carpeta

```
web_corporativa/
├── contenido.md                 textos finales de Valentina (fuente de los textos)
├── NOTAS_PARA_CESAR.md          propuestas que esperan el visto bueno de César
├── LEEME.md                     este archivo
├── sitio.sha256                 manifiesto SHA-256 de sitio/ (lo regenera el verificador)
├── herramientas/
│   ├── verificar-sitio.mjs          verificación antes de subir
│   └── verificar-publicacion.mjs    compara por hash lo publicado contra sitio/
└── sitio/                       ← EXACTAMENTE lo que va dentro de public_html/
    ├── index.html               /             Inicio
    ├── nosotros/index.html      /nosotros/
    ├── servicios/index.html     /servicios/   anclas #asesoria #preventivo #predictivo #correctivo #planificacion
    ├── contacto/index.html      /contacto/    formulario que solo arma el mensaje
    ├── acceso/index.html        /acceso/      personal: botones a /ot/login.php y /ot/ (noindex permanente)
    ├── robots.txt               «Disallow: /» mientras dure el dominio temporal
    └── assets/
        ├── css/estilos.css
        ├── js/sitio.js
        └── img/                 logo (PNG y WebP), favicon (SVG, PNG de 32 px, apple-touch-icon),
                                 og-industec.png (1200 × 630), cocina-profesional.svg,
                                 iconos/ (30 SVG propios en el rojo #CC504B)
```

**No hay, y es a propósito:**

- `.htaccess` en la raíz: lo heredaría `/ot/`.
- Carpeta `ot/`.
- CDN, fuentes externas, analítica, cookies o formularios que envíen datos a un servidor.
- `404.html` (ver el punto 5).
- `sitemap.xml` y `canonical`: se agregan con el dominio definitivo.

---

## 2. Cómo se publica

1. **Verificar en local.** Desde esta carpeta, ejecutar `node herramientas/verificar-sitio.mjs --manifiesto`. Tiene que terminar en «OK: sin fallos» y regenera `sitio.sha256`.

2. **Revisar la raíz en Hostinger antes de subir.** Entrar a hPanel → Archivos → Administrador de archivos → `public_html/`.
   - Si hay un `index.php` o un `default.php` (la página de bienvenida de Hostinger), el servidor puede mostrarlo en lugar de `index.html`. Descargarlo como respaldo y retirarlo.
   - Si ya hay un `.htaccess`, **no se modifica ni se reemplaza**: puede ser del sistema.
   - **La carpeta `ot/` no se abre, no se mueve y no se sobrescribe.**

3. **Subir el CONTENIDO de `sitio/`, no la carpeta `sitio`.** A `public_html/` van `index.html`, `robots.txt`, `assets/`, `nosotros/`, `servicios/`, `contacto/` y `acceso/`.
   - Si se sube un `.zip`, los archivos tienen que estar en la raíz del `.zip`, y se extrae dentro de `public_html/`.
   - No se suben `sitio.sha256`, `herramientas/` ni los `.md`.

4. **Verificar por hash.** Ejecutar `node herramientas/verificar-publicacion.mjs`. El script:
   - descarga cada archivo publicado y compara su SHA-256 con el de `sitio/`;
   - revisa que las cinco URL limpias sirvan el `index.html` subido;
   - revisa que `/nosotros` redirija a `/nosotros/`;
   - revisa que `/ot/login.php` siga respondiendo.

   Tiene que terminar en «OK». Si solo fallan los `.html`, `.css` o `.js` y el CDN de Hostinger tiene activada la minificación, hay que desactivarla y volver a subir. Los PNG y, en el dominio temporal, el `robots.txt` salen con ⚠: los cambia el CDN, no la subida, y el original se comprueba en el disco del servidor (sección 8).

   En el PC de Andrés el antivirus inspecciona el HTTPS: correrlo como `NODE_OPTIONS=--use-system-ca node herramientas/verificar-publicacion.mjs`, para que Node use los certificados de Windows.

5. **Prueba a mano en un celular:**
   - **WhatsApp**: el chat se abre con el mensaje «…les escribo desde su página web…».
   - **Llamar**: marca el 099 788 7709.
   - **Formulario de /contacto/**: probar el envío por WhatsApp y por correo.
   - **Menú**: se abre y se cierra.
   - **/acceso/**: los botones «Ingresar al sistema» y «Abrir la app del técnico».

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

7. **Antes de dar de baja Zyro**, descargar los logos de clientes y marcas y las imágenes de «Nuestro trabajo».

8. **Comprobar** que `/nosotros` sin barra lleve a `/nosotros/`. Apache lo hace solo, sin `.htaccess`.

9. **Search Console.** Registrar el sitio a nombre de César, verificándolo por DNS: no agrega scripts a la página.

---

## 4. Cómo se edita

- **Textos**: se editan directamente en cada `index.html`. La cabecera, el pie, la barra del celular y el botón flotante se repiten en las cinco páginas, así que un cambio ahí se hace en las cinco.
- **Mensajes de WhatsApp**: son 7 (`contenido.md`, sección 2.1). Todos empiezan con «Hola INDUSTEC, les escribo desde su página web.»: así César cuenta los contactos que llegan por la web sin rastreadores. El verificador falla si alguno no empieza así.
- **Colores** (en `:root` de `estilos.css`):

  | Color | Código | Uso |
  |---|---|---|
  | Rojo de marca | `#CC504B` | Solo íconos, bordes y títulos grandes: da 4,36:1 sobre blanco |
  | Rojo de texto | `#B5413C` | Texto rojo pequeño y botones con texto blanco (5,55:1) |
  | Azul del logo | `#48537E` | «Acceso al sistema» y detalles |
  | Verde de WhatsApp | `#117A3F` | Barra del celular y botón flotante (5,4:1 con blanco) |

- **Íconos**: están en `assets/img/iconos/`, con trazo de 2 px en rejilla de 24 y el rojo `#CC504B`. Los de contenido van como `<img alt="">`. Los de interfaz (WhatsApp, teléfono, candado, flecha, menú) se usan como máscara CSS y toman el color del texto.
- **Después de cualquier cambio**, ejecutar `node herramientas/verificar-sitio.mjs`.
- **Cifras nuevas**: primero pasan por `NOTAS_PARA_CESAR.md`. Ninguna va directo al HTML.

---

## 5. Comportamiento y accesibilidad

- **Móvil primero.**
  - En el celular: botón «Menú» (Escape lo cierra) y una barra fija con WhatsApp y Llamar.
  - En escritorio: navegación completa, el botón «Acceso al sistema» y un botón flotante de WhatsApp.
  - La barra y el botón flotante no aparecen en `/acceso/`.
- **Sin JavaScript**: el menú se muestra abierto y el formulario se oculta. En su lugar queda un aviso con WhatsApp, teléfono y correo.
- **Formulario**:
  - Valida nombre, necesidad y detalle, y muestra una vista previa del mensaje.
  - Arma el mensaje en el navegador. No usa cookies, almacenamiento del navegador ni llamadas de red.
  - WhatsApp se abre en otra pestaña. El correo abre el programa de correo con el asunto «Solicitud desde la web: …».
  - Si se elige «Reparación o emergencia», aparece el aviso con el teléfono.
- **Movimiento**: la aparición de bloques y la entrada de la portada se apagan con la preferencia «reducir movimiento». El desplazamiento suave se activa solo después de cargar, para que entrar a `/servicios/#correctivo` salte directo a la sección.
- **Accesibilidad**:
  - Contraste AA en todo el texto.
  - Foco visible y enlace «Saltar al contenido».
  - Un solo H1 por página y textos alternativos.
  - Etiquetas en todos los campos y errores asociados al campo.
- **Página 404**: no se incluye. Para servir una propia, Hostinger necesita un `.htaccess` (`ErrorDocument`), y en la raíz está prohibido. El texto está listo en `contenido.md`, sección 10, por si se configura desde hPanel sin `.htaccess`.

---

## 6. Verificación del 11 de septiembre de 2026

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
  - Los 17 clientes y las 18 marcas coinciden con la lista pública.
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
- César: las propuestas de `NOTAS_PARA_CESAR.md` (A1–A6 y B1–B14), incluida B13.
- La subida real y `verificar-publicacion.mjs`: **hechas el 11 de septiembre de 2026** (sección 8).
- Prueba en un teléfono real: WhatsApp, llamada y Safari de iPhone.

---

## 8. Publicación del 11 de septiembre de 2026

Se publicó en la raíz de `darkviolet-armadillo-872352.hostingersite.com` desde el PC de Andrés, por SSH, subiendo por nombre los siete elementos de `sitio/`: `index.html`, `robots.txt`, `assets/`, `nosotros/`, `servicios/`, `contacto/` y `acceso/`. Antes, en `public_html/` solo estaba `ot/`: no había `index.php`, `default.php` ni `.htaccess` que retirar. `ot/` no se tocó, y los permisos de la raíz y de `ot/` siguen en 755.

- **En el disco del servidor, los 45 archivos cuadran con `sitio.sha256`.** Se comprueba así, desde esta carpeta:
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
