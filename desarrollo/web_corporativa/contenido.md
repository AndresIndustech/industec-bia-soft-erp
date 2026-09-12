# Contenido de la web corporativa de INDUSTEC

> **Preparado por**: Valentina (comercial y marketing, INDUSTECH) · **Fecha**: 11 de septiembre de 2026 · **Revisado**: 11 de septiembre de 2026 (enfoque en empresas y cadenas, logos y campo «Empresa o cadena»; ver la sección 1)
> **Para**: quien arme el HTML en el dominio temporal `darkviolet-armadillo-872352.hostingersite.com`
> **Estado**: textos finales, listos para pegar. Andrés los revisa; César da el visto bueno.
> **Fuentes**: lo que hoy publica www.industec.me (revisado el 11 de septiembre de 2026) y lo que Andrés indicó ese mismo día sobre cómo quiere presentarse INDUSTEC: proveedor técnico de empresas y cadenas (comida rápida, restaurantes, hoteles, bares y cafeterías, catering), que trabaja con contrato de servicio y no repara equipos domésticos ni atiende a particulares. **No hay aquí ningún dato nuevo sin confirmar.** Todo lo que requiere el visto bueno de César está en `NOTAS_PARA_CESAR.md` y **no va al HTML**.

---

## 0. Cómo leer este documento

- El texto entre comillas «…» o dentro de las tablas es **el texto final**: se pega tal cual.
- **[CTA]** marca un llamado a la acción; al lado va su destino exacto (sección 2.1).
- «Ícono sugerido» orienta el dibujo: trazo de un solo color, del mismo estilo que los SVG rojos que ya tiene la marca (`target`, `trending`, `shield`, `clock`, `star`).
- Trato: **tú** en toda la web (la web actual mezcla tú y usted). Mayúsculas: solo en la primera letra de títulos y botones, nunca en cada palabra.
- Regla de extensión para que César lo entienda de un vistazo: **una idea por bloque**, frases cortas y un máximo de unas 25 palabras por tarjeta.

---

## 1. Qué cambia frente a la web actual, y por qué

| Cambio | Por qué |
|---|---|
| La portada dice en su primera pantalla **qué hace INDUSTEC, para quién y cómo contactarla** (dos botones: WhatsApp y llamada) | Hoy el título es «Soluciones Integrales»: no dice qué se resuelve. El visitante tiene 5 segundos. |
| WhatsApp y llamada a un toque en todas las páginas (barra fija en el celular) | Es el canal real del dueño o administrador de un restaurante, un hotel o un catering. |
| El formulario **no envía nada a ningún servidor**: arma el mensaje y lo abre en WhatsApp o en el correo | Es una regla del proyecto y además pide solo los datos necesarios (LOPDP, principio de minimización). |
| **Se retiran** las dos citas de estudios internacionales y el «previene hasta un 85% de fallas» | No se pudieron comprobar en sus fuentes (el detalle está en `NOTAS_PARA_CESAR.md`, sección A). Publicar cifras que no se pueden respaldar resta confianza. |
| Se corrigen los títulos cruzados de la web actual: los logos de fabricantes aparecen hoy bajo «Nuestros clientes» y los restaurantes bajo «Marcas con las que trabajamos» | Error de la plantilla de Zyro. |
| Se corrigen errores de tipeo («sócio», «negócio», «contatará», «Respuestos», «correccion») | Cuidado de marca. |
| La página `/acceso/` no nombra cargos internos | La regla del proyecto prohíbe publicar la organización interna (zonas, personas). |
| **11 de septiembre de 2026** · La web se presenta como **proveedor técnico de empresas y cadenas**: cadenas de comida rápida, restaurantes, hoteles, bares y cafeterías, catering. Nueva sección «A quién servimos» (3.3) | Pedido de César, a través de Andrés: la gente confunde el servicio con arreglos de cocina para casa o para particulares con equipo de gama baja, que INDUSTEC no hace. |
| **11 de septiembre de 2026** · Una línea amable que filtra al cliente particular, en la portada y en contacto: «Atendemos a empresas y cadenas, bajo contrato de servicio. No reparamos equipos domésticos ni atendemos a particulares.» | Mismo pedido. Evita mensajes que no se van a atender y deja claro, antes de escribir, el tipo de cliente. |
| **11 de septiembre de 2026** · El formulario pide **«Empresa o cadena»** como dato obligatorio (reemplaza a «Tu negocio», que era opcional) | Filtra al particular y le dice a César, desde el primer mensaje, de qué empresa le escriben. Se cambia un campo por otro: no se pide ningún dato más. |
| **11 de septiembre de 2026** · **Logos** de clientes y de marcas de equipos, cada bloque con su nota | Pedido de Andrés. La nota de las marcas deja claro que mencionarlas no significa ser servicio técnico autorizado. |
| **11 de septiembre de 2026** · Se nombran **freidoras y mantenedores de calor** | Los nombró Andrés. Los demás tipos de equipo siguen esperando a César (NOTAS, B4). |

---

## 2. Elementos comunes a todas las páginas

### 2.1 Enlaces de contacto (usar exactamente estos)

Todos los mensajes de WhatsApp empiezan con **«les escribo desde su página web»**. Así César sabe qué conversaciones llegan por la web **sin poner rastreadores** (ver `NOTAS_PARA_CESAR.md`, sección D). El texto se ve y se edita antes de enviar, así que es transparente para quien escribe.

| Clave | Uso | Mensaje que se abre en WhatsApp | Enlace |
|---|---|---|---|
| `wa-general` | Portada, menú, botón flotante, cierres | «Hola INDUSTEC, les escribo desde su página web. Quiero información sobre sus servicios para mi empresa.» | `https://wa.me/593997887709?text=Hola%20INDUSTEC%2C%20les%20escribo%20desde%20su%20p%C3%A1gina%20web.%20Quiero%20informaci%C3%B3n%20sobre%20sus%20servicios%20para%20mi%20empresa.` |
| `wa-consultoria` | Consultoría integral gratuita | «Hola INDUSTEC, les escribo desde su página web. Quiero solicitar la consultoría integral gratuita para mi empresa.» | `https://wa.me/593997887709?text=Hola%20INDUSTEC%2C%20les%20escribo%20desde%20su%20p%C3%A1gina%20web.%20Quiero%20solicitar%20la%20consultor%C3%ADa%20integral%20gratuita%20para%20mi%20empresa.` |
| `wa-asesoria` | Asesoría comercial | «Hola INDUSTEC, les escribo desde su página web. Necesito asesoría para elegir e instalar equipos.» | `https://wa.me/593997887709?text=Hola%20INDUSTEC%2C%20les%20escribo%20desde%20su%20p%C3%A1gina%20web.%20Necesito%20asesor%C3%ADa%20para%20elegir%20e%20instalar%20equipos.` |
| `wa-preventivo` | Mantenimiento preventivo | «Hola INDUSTEC, les escribo desde su página web. Quiero programar un mantenimiento preventivo.» | `https://wa.me/593997887709?text=Hola%20INDUSTEC%2C%20les%20escribo%20desde%20su%20p%C3%A1gina%20web.%20Quiero%20programar%20un%20mantenimiento%20preventivo.` |
| `wa-predictivo` | Mantenimiento predictivo | «Hola INDUSTEC, les escribo desde su página web. Quiero información sobre el mantenimiento predictivo.» | `https://wa.me/593997887709?text=Hola%20INDUSTEC%2C%20les%20escribo%20desde%20su%20p%C3%A1gina%20web.%20Quiero%20informaci%C3%B3n%20sobre%20el%20mantenimiento%20predictivo.` |
| `wa-emergencia` | Mantenimiento correctivo y emergencias | «Hola INDUSTEC, les escribo desde su página web. Tengo una emergencia: un equipo dejó de funcionar.» | `https://wa.me/593997887709?text=Hola%20INDUSTEC%2C%20les%20escribo%20desde%20su%20p%C3%A1gina%20web.%20Tengo%20una%20emergencia%3A%20un%20equipo%20dej%C3%B3%20de%20funcionar.` |
| `wa-planificacion` | Planificación | «Hola INDUSTEC, les escribo desde su página web. Tengo una falla que se repite y quiero solucionarla de forma definitiva.» | `https://wa.me/593997887709?text=Hola%20INDUSTEC%2C%20les%20escribo%20desde%20su%20p%C3%A1gina%20web.%20Tengo%20una%20falla%20que%20se%20repite%20y%20quiero%20solucionarla%20de%20forma%20definitiva.` |
| `tel` | Llamar | Texto visible: **099 788 7709** | `tel:+593997887709` |
| `mail-general` | Correo | Texto visible: **servicioalcliente@industec.me** | `mailto:servicioalcliente@industec.me?subject=Solicitud%20desde%20la%20web` |
| `mail-empleo` | Trabaja con nosotros | — | `mailto:servicioalcliente@industec.me?subject=Quiero%20trabajar%20en%20INDUSTEC` |

Formato del número: en los textos va **099 788 7709**; en los enlaces, siempre en formato internacional (`+593997887709`). Los enlaces a `wa.me` se abren en una pestaña nueva (`target="_blank" rel="noopener"`), y el texto del enlace o su `aria-label` avisa «(se abre WhatsApp)».

**Cambio del 11 de septiembre de 2026**: `wa-general` y `wa-consultoria` terminan ahora en «para mi empresa». Al integrarlo, en **todas** las páginas (también en `/acceso/`, que usa `wa-general` en su línea para clientes) se reemplaza `sus%20servicios%20para%20mi%20negocio.` por `sus%20servicios%20para%20mi%20empresa.`, e `integral%20gratuita.` por `integral%20gratuita%20para%20mi%20empresa.`. Los otros cinco mensajes no cambian, y los siete siguen empezando con «Hola INDUSTEC, les escribo desde su página web.».

**Propuesta para Valentina (revisión del 11 de septiembre de 2026, NO aplicada)**: `wa-emergencia` es el único de los siete mensajes que no menciona el local ni la empresa, y es el que escribe quien busca una «reparación». Se propone «Hola INDUSTEC, les escribo desde su página web. Tengo una emergencia: un equipo de mi local dejó de funcionar.» (enlace: `…Tengo%20una%20emergencia%3A%20un%20equipo%20de%20mi%20local%20dej%C3%B3%20de%20funcionar.`). Si lo apruebas, se cambia en `/servicios/#correctivo` y en la franja de emergencia de `/contacto/`.

### 2.2 Encabezado

- Enlace de salto (visible al recibir el foco): «Saltar al contenido».
- Logo, que enlaza a `/`. Texto alternativo: «INDUSTEC, la solución a sus equipos. Ir al inicio».
- Menú: «Inicio» · «Nosotros» · «Servicios» · «Contacto». La página actual se marca con `aria-current="page"`.
- Botón destacado del menú: **[CTA] «WhatsApp»** → `wa-general`.
- Enlace discreto a la derecha, en letra pequeña y con ícono de candado: «Acceso personal» → `/acceso/`.
- En el celular: botón «Menú» (`aria-label` «Abrir menú» / «Cerrar menú»).

### 2.3 Barra fija inferior (solo en el celular)

Dos botones del mismo ancho: **«WhatsApp»** → `wa-general` · **«Llamar»** → `tel`.
No se muestra en `/acceso/`.

### 2.4 Botón flotante de WhatsApp (escritorio)

Ícono de WhatsApp abajo a la derecha, como en la web actual. `aria-label`: «Escríbenos por WhatsApp (se abre WhatsApp)». En el celular lo reemplaza la barra fija. No se muestra en `/acceso/`.

### 2.5 Pie de página

- Logo + «Asesoría, equipamiento y mantenimiento de cocinas profesionales para empresas y cadenas en el Ecuador.» *(Cambió el 11 de septiembre de 2026; antes decía «Soluciones integrales para hoteles, restaurantes y catering en el Ecuador.». Va en las cinco páginas, también en `/acceso/`.)*
- Columna **«Páginas»**: Inicio · Nosotros · Servicios · Contacto
- Columna **«Contáctanos»**:
  - WhatsApp: +593 99 788 7709 → `wa-general`
  - Teléfono: 099 788 7709 → `tel`
  - Correo: servicioalcliente@industec.me → `mail-general`
- Enlace: «Acceso del personal» → `/acceso/`
- Línea final: «© 2026 INDUSTEC. Todos los derechos reservados.»

### 2.6 Bloque de cierre (se repite al final de Inicio, Nosotros y Servicios)

- **Título**: «¿Un equipo de tu local está fallando o tienes un proyecto nuevo?»
- **Texto**: «Cuéntanos qué necesita tu empresa. Te respondemos por WhatsApp, por teléfono o por correo.»
- **[CTA] principal**: «Escríbenos por WhatsApp» → `wa-general`
- **[CTA] secundario**: «Llamar al 099 788 7709» → `tel`
- **[CTA] terciario** (enlace de texto): «Enviar un correo» → `mail-general`

---

## 3. Inicio — `/`

| Metadato | Texto |
|---|---|
| `<title>` (62 caracteres) | INDUSTEC \| Mantenimiento de cocinas profesionales para cadenas |
| `meta description` (151) | Asesoría, equipamiento y mantenimiento de cocinas profesionales para cadenas de restaurantes, hoteles, bares y cafeterías en Ecuador. Emergencias 24/7. |
| `og:title` | INDUSTEC \| Mantenimiento de cocinas profesionales para cadenas |

«Equipos de cocina» se cambió por «cocinas profesionales» antes de publicar la segunda entrega (11-sep-2026): sin el adjetivo, el título se lee igual para un electrodoméstico de casa (sección 9, «cocina profesional», y el pedido de César).
| `og:description` (150) | Proveedor técnico de empresas y cadenas: asesoría, equipamiento y mantenimiento de cocinas profesionales, con contrato de servicio y emergencias 24/7. |
| H1 | Mantenimiento de cocinas profesionales para cadenas de restaurantes y hoteles |

### 3.1 Portada (primera pantalla)

> **Prueba de los 5 segundos**: **qué** (mantenimiento y equipamiento de cocinas profesionales) + **para quién** (empresas y cadenas) + **cómo contactar** (dos botones). Todo debe verse sin bajar, también en el celular. Desde el 11 de septiembre de 2026 hay una cuarta condición: **que nadie la confunda con un servicio de reparación para el hogar**.

- **Etiqueta** (encima del título): «Proveedor técnico para empresas · Sector HORECA»
- **H1**: «Mantenimiento de cocinas profesionales para cadenas de restaurantes y hoteles»
- **Texto**: «Tu único proveedor para elegir, instalar y mantener los equipos de línea caliente, línea fría y ventilación de tus locales, con contrato de mantenimiento y emergencias 24/7.» (Andrés, 11 de septiembre de 2026: no solo freidoras y mantenedores de calor, sino línea caliente, línea fría y ventilación.)
- **[CTA] principal**: «Escríbenos por WhatsApp» → `wa-general`
- **[CTA] secundario**: «Llamar al 099 788 7709» → `tel`
- **Enlace bajo los botones**: «o pide tu consultoría integral gratuita» → `wa-consultoria`
- **Línea de filtro** (debajo de ese enlace; letra de cuerpo pequeña, en el azul `#48537E` o en el color del texto, con un ícono neutro de edificio o de información; **nunca en rojo**, que en esta web significa urgencia): «Atendemos a empresas y cadenas, bajo contrato de servicio. No reparamos equipos domésticos ni atendemos a particulares.»
- **Imagen** (vigente desde la ronda 3, tarde del 11 de septiembre de 2026): ilustración propia (`portada-cocina-cadena-frontal.svg`) de la cocina de un local de cadena, con aspecto técnico y empresarial: refrigeración comercial, mesa fría, campana de extracción, batería de freidoras, mantenedor de calor y un técnico diagnosticando con un multímetro. **Nada que se lea como cocina de casa.** Su texto alternativo, descriptivo (no vacío, porque el titular no repite el detalle del equipamiento): «Ilustración: técnico de INDUSTEC revisando una freidora en la línea caliente de una cocina de cadena, junto a la refrigeración y bajo la campana de extracción». La foto propia del técnico revisando las freidoras («Técnico de INDUSTEC revisando freidoras en una cocina profesional») ya no va en la portada: quedó en «Nuestro trabajo» de servicios y en «Así te atendemos» de contacto. Toda imagen se aloja en `sitio/assets`: nada enlazado a Zyro ni a Pexels. Las demás fotos reales siguen propuestas en NOTAS, sección B2.
- **Distintivos bajo la foto** (placa azul; textos de maquetación del 11 de septiembre de 2026, **pendientes de validar por Valentina**): «Línea caliente, línea fría y ventilación» (ícono de termómetro) · «Mantenimiento preventivo, predictivo y correctivo» · «Servicio para cadenas de restaurantes». Reemplazan a «Emergencias 24/7», que ya está en el texto de la portada y en la franja de confianza. No se nombran otros tipos de equipo hasta que César responda la B4.

### 3.2 Franja de confianza (4 datos, todos públicos)

| Dato grande | Texto debajo |
|---|---|
| **2010** | Fundada para evolucionar la industria HORECA |
| **+40 años** | De legado junto a grandes cadenas de comida rápida |
| **24/7** | Servicio de emergencia |
| **1 solo** | Proveedor para asesoría, equipos y mantenimiento de todos tus locales |

### 3.3 A quién servimos (nueva, 11 de septiembre de 2026)

Va **justo después de la franja de confianza** y antes de «Qué hacemos»: primero para quién, después qué. Responde al pedido de César de presentarse como proveedor de empresas y cadenas.

- **H2**: «A quién servimos»
- **Texto**: «Somos el proveedor técnico de empresas y cadenas que no pueden detener su cocina.»

Cinco tarjetas, sin enlace y sin cifras:

| Ícono sugerido | Título | Texto |
|---|---|---|
| Tres fachadas de local iguales, en fila | Cadenas de comida rápida | Línea caliente, línea fría y ventilación al día en cada local, con el mismo estándar en toda la cadena. |
| Tenedor y cuchillo | Restaurantes | Mantenimiento programado para que tu cocina no se detenga en plena operación, y respuesta rápida cuando algo falla. |
| Edificio de hotel | Hoteles | Cocinas que trabajan desde el desayuno hasta el último evento, con un solo proveedor para equiparlas y mantenerlas. |
| Taza y copa | Bares y cafeterías | Equipos a punto para las horas de mayor movimiento, sin paradas que frenen la atención. |
| Campana de servicio (cloche) | Catering | Equipos confiables para producir en volumen y cumplir con cada evento a tiempo. |

- **Pie de la sección**: «¿Tu empresa tiene varios locales? Te proponemos un contrato de mantenimiento que los cubra a todos.»
- **[CTA]** (enlace de texto): «Escríbenos por WhatsApp» → `wa-general`
- **Cuidado**: aquí no se nombran otros tipos de equipo (cocción, refrigeración, hielo, café…) hasta que César responda la B4 de las notas, ni se pone el nombre de un cliente en una tarjeta.

### 3.4 Qué hacemos

- **H2**: «Todo lo que tus equipos necesitan, con un solo proveedor»
- **Texto**: «Te acompañamos desde que eliges un equipo hasta que lo mantienes funcionando en cada uno de tus locales.»

Cinco tarjetas; cada una enlaza a su sección en `/servicios/`.

| Ícono sugerido | Título | Texto | Enlace (texto accesible) → destino |
|---|---|---|---|
| Portapapeles con visto bueno | Asesoría comercial | Te ayudamos a elegir el equipo adecuado, lo instalamos, lo ponemos en marcha y capacitamos a tu personal. | «Más sobre asesoría comercial» → `/servicios/#asesoria` |
| Calendario con engranaje | Mantenimiento preventivo | Visitas programadas por contrato: inspección, limpieza profunda y calibración para que tus equipos no fallen. | «Más sobre mantenimiento preventivo» → `/servicios/#preventivo` |
| Gráfica con lupa | Mantenimiento predictivo | Anticipamos los problemas antes de que ocurran. | «Más sobre mantenimiento predictivo» → `/servicios/#predictivo` |
| Llave inglesa con rayo | Mantenimiento correctivo | Respuesta rápida ante fallas inesperadas, con servicio de emergencia 24/7 y repuestos garantizados. | «Más sobre mantenimiento correctivo» → `/servicios/#correctivo` |
| Diana o mapa de ruta | Planificación | Solucionamos los problemas de forma definitiva y capacitamos a tu personal para evitar nuevas incidencias. | «Más sobre planificación» → `/servicios/#planificacion` |

- **[CTA]** debajo de las tarjetas: «Ver todos los servicios» → `/servicios/`
- En el HTML, el enlace de cada tarjeta dice «Ver servicio» y su nombre accesible (`aria-label`) es «Ver servicio de asesoría comercial», «Ver servicio de mantenimiento preventivo»… El nombre accesible tiene que **empezar por el texto que se ve** (WCAG 2.5.3) para que funcione el control por voz; «Más sobre…» no lo cumplía.

### 3.5 ¿Por qué elegir un proveedor integral?

- **H2**: «¿Por qué elegir un proveedor integral?»
- **Subtítulo**: «Un solo proveedor, múltiples beneficios»

| Ícono (ya existe) | Título | Texto |
|---|---|---|
| `target-red.svg` | Único punto de contacto | Una sola relación para todos tus locales. Simplifica tu gestión. |
| `trending-red.svg` | Ahorro de costos | Ahorras con una negociación integral. |
| `shield-red.svg` | Compatibilidad garantizada | Lo que te proponemos está pensado para funcionar en conjunto. |
| `clock-red.svg` | Menos tiempo de implementación | Tu proyecto se pone en marcha antes. |

### 3.6 Empresas que han confiado en nuestro trabajo (logos)

- **H2**: «Empresas que han confiado en nuestro trabajo»
- **Texto**: «Hemos realizado trabajos para cadenas y empresas como:»
- **Lista** (en este orden, el de la galería de www.industec.me; **20 clientes**): KFC · American Deli · Ali's Parrilladas & Pizzería · Arrayanes · TropiBurger · Cinnabon · Naturíssimo · Yanbal · El Español · Gus · TEVCOL · Corporación GPF · Menestras del Negro · Juan Valdez Café · Vaco y Vaca · El Bodegón · Baskin Robbins · Il Cappo di Mangi · San Felipe · Decameron
  - **Corrección de la revisión del 11 de septiembre de 2026**: la lista anterior tenía 17. Faltaban Ali's Parrilladas & Pizzería, El Español e Il Cappo di Mangi, que están en la galería pública pero cuyos archivos no dicen de qué empresa son. Además, cada nombre se escribe ahora como lo dice su propio logo: «Vaco y Vaca» (antes «V&V», que salió del nombre del archivo), «Decameron», «Naturíssimo», «TropiBurger» y «Corporación GPF». César lo confirma en NOTAS, A6.
- **Presentación**: **logos** (pedido de Andrés del 11 de septiembre de 2026), del mismo alto visual, en una cuadrícula ordenada, sin enlaces y alojados en `sitio/assets`. Todos con el mismo tratamiento: todos en gris o todos a color. Si un logo no se consigue con calidad suficiente, ese nombre va en texto con el mismo estilo; nunca se deja un hueco. Texto alternativo de cada logo: el nombre de la empresa, tal cual (sección 8). La grafía exacta de algunos nombres está por confirmar (NOTAS, A6); mientras tanto se publica como está aquí, que es como aparece hoy.
- **Nota bajo los logos** (letra pequeña, con contraste AA): «Los logos pertenecen a cada empresa y se muestran como referencia de trabajos realizados.»
- **La portada muestra también las marcas de equipos** (pedido de Andrés del 11 de septiembre de 2026: «no olvides colocar los logos de los clientes y las marcas»): va el bloque 5.8 completo, **con su aviso**, justo después de los clientes y con el mismo fondo.

### 3.7 Cierre

Bloque 2.6.

### 3.8 Orden de la portada y «Nuestro trabajo» (maquetación del 11 de septiembre de 2026)

- **Orden**: portada → franja de confianza → A quién servimos (3.3) → Empresas que han confiado en nuestro trabajo (3.6, con logos) → Marcas de equipos con las que trabajamos (5.8, con logos y aviso) → Qué hacemos (3.4) → Nuestro trabajo → ¿Por qué elegir un proveedor integral? (3.5) → cierre. Los logos de las cadenas suben junto a «A quién servimos» porque son los que terminan de dar la lectura de proveedor de cadenas.
- **Nuestro trabajo** (textos de maquetación, **pendientes de validar por Valentina**; el título es el de la galería de la web actual):
  - **H2**: «Nuestro trabajo»
  - **Texto**: «Fotos de trabajos realizados por nuestro equipo técnico.»
  - **Cuatro fotos propias** de la web actual, sin caras ni letreros de clientes, con estos pies: «Vitrina caliente: revisión del tablero eléctrico» · «Reparación de un horno» · «Freidoras de presión en la cocina de un local» · «Mantenedores de calor en la línea de despacho». En `/servicios/` va el mismo bloque, entre «Planificación» y las marcas, con la foto de la portada («Revisión de freidoras», pedido de Andrés del 11 de septiembre de 2026) en lugar de la línea de despacho.

---

## 4. Nosotros — `/nosotros/`

| Metadato | Texto |
|---|---|
| `<title>` (56) | Nosotros \| INDUSTEC, proveedor técnico de cadenas HORECA |
| `meta description` (145) | Desde 2010 impulsamos la industria HORECA en Ecuador, con un legado de más de 40 años junto a las principales cadenas de comida rápida del mundo. |
| `og:title` | Nosotros \| INDUSTEC, proveedor técnico de cadenas HORECA |
| `og:description` (144) | Desde 2010, proveedor técnico de empresas y cadenas HORECA en Ecuador, con un legado de más de 40 años junto a grandes cadenas de comida rápida. |
| H1 | Desde 2010, evolucionando la industria HORECA en el Ecuador |

### 4.1 Encabezado

- **Etiqueta**: «Nosotros»
- **H1**: «Desde 2010, evolucionando la industria HORECA en el Ecuador»
- **Texto**: «Trabajamos para empresas y cadenas de restaurantes, hoteles, bares, cafeterías y catering, con soluciones que las ayudan a superar los retos de un entorno en constante cambio.»

### 4.2 Nuestra historia

- **H2**: «Nuestra historia»
- **Párrafo 1**: «En 2010 fundamos INDUSTEC con un propósito: evolucionar la industria HORECA —hoteles, restaurantes y catering— en el Ecuador.»
- **Párrafo 2**: «Sabemos que una cocina profesional no puede detenerse. Por eso trabajamos con empresas y cadenas, bajo contrato de servicio, y cuidamos sus equipos en cada local con el mismo estándar.»
- **Frase destacada** (tipografía grande, con el ícono `star-red.svg`): «Más que un proveedor, somos tu mejor aliado para escalar, automatizar e innovar.»

### 4.3 Lo que nos hace diferentes

- **H2**: «Lo que nos hace diferentes»

| Ícono sugerido | Título | Texto |
|---|---|---|
| Medalla | Nuestra herencia | Un legado de más de 40 años de experiencia junto a las cadenas de comida rápida más importantes del mundo. |
| Red de personas | Una red de confianza | Proveedores y especialistas confiables que nos permiten acompañarte en cada paso. |
| Sello con visto bueno | Mejores prácticas y normas | Operaciones rentables, eficientes y bajo métodos probados. (Andrés, 11 de septiembre de 2026; antes: «Estándares internacionales… de certificación internacional») |

### 4.4 Misión y visión (dos tarjetas)

- **Nuestra misión**: «Proporcionar soluciones integrales, innovadoras y personalizadas que impulsen el crecimiento y la eficiencia de nuestros clientes, a través de un servicio de excelencia y un compromiso constante con la calidad.»
- **Nuestra visión**: «Ser reconocidos como el socio preferido por las empresas HORECA que buscan impulsar su crecimiento a través de la innovación, destacándonos por nuestra excelencia, integridad y compromiso con el éxito de nuestros clientes.»

*(Textos oficiales de la web actual. En la visión solo se corrige «sócio» por «socio».)*

### 4.5 Nuestros valores

- **H2**: «Nuestros valores»
- **Subtítulo**: «Los principios que guían nuestras decisiones.»

| Ícono sugerido | Valor | Texto |
|---|---|---|
| Estrella (`star-red.svg`) | Excelencia | Buscamos superar expectativas y mejorar continuamente en cada proyecto. |
| Escudo (`shield-red.svg`) | Integridad | Actuamos con honestidad y transparencia en cada relación y cada decisión. |
| Bombillo | Innovación | Exploramos nuevos enfoques para crear soluciones que generen valor real. |
| Apretón de manos | Colaboración | Trabajamos de cerca contigo, con comunicación abierta y respeto mutuo. |

### 4.6 Nuestra metodología

- **H2**: «Nuestra metodología»
- **Subtítulo**: «Trabajamos de forma estructurada, en cuatro pasos.»
- **Visual**: cuatro pasos numerados, unidos por una línea (horizontal en el escritorio, vertical en el celular).

| Paso | Título | Texto |
|---|---|---|
| 1 | Análisis | Evaluamos tus necesidades para entender tu negocio a fondo. |
| 2 | Diseño | Creamos una solución a la medida de tus objetivos y tu presupuesto, pensando en su escalabilidad y en tu retorno de inversión. |
| 3 | Implementación | La ejecutamos con altos estándares de calidad y eficiencia, para que tu operación diaria se vea afectada lo menos posible. |
| 4 | Soporte | Te acompañamos de forma continua para que todo funcione bien y se adapte a lo que tu negocio necesite. |

### 4.7 Trabaja con nosotros

- **H2**: «¿Quieres formar parte de nuestro equipo?»
- **Texto**: «Siempre buscamos talento apasionado por la innovación.»
- **[CTA]**: «Escríbenos» → `mail-empleo`

*(La sección existe hoy en la web. El correo de destino está por confirmar en NOTAS, B13. Si César decide quitarla, se borra el bloque completo sin afectar lo demás.)*

> **Retirada del HTML el 11 de septiembre de 2026**, en la revisión previa a publicar: no figura en la lista de hechos públicos verificados y su correo de destino está por confirmar. Se repone tal cual, con este texto, cuando César apruebe B13.

### 4.8 Cierre

Bloque 2.6.

---

## 5. Servicios — `/servicios/`

| Metadato | Texto |
|---|---|
| `<title>` (57) | Servicios de mantenimiento para cadenas HORECA \| INDUSTEC |
| `meta description` (149) | Asesoría, mantenimiento preventivo, predictivo y correctivo con emergencias 24/7, y planificación para cadenas de restaurantes, hoteles y cafeterías. |
| `og:title` | Servicios de mantenimiento para cadenas HORECA \| INDUSTEC |
| `og:description` (110) | Mantenimiento preventivo, predictivo y correctivo con emergencias 24/7 para las cocinas de empresas y cadenas. |
| H1 | Todo el ciclo de tus equipos, con un solo proveedor |

### 5.1 Encabezado

- **Etiqueta**: «Servicios para empresas y cadenas»
- **H1**: «Todo el ciclo de tus equipos, con un solo proveedor»
- **Texto**: «Más de cuatro décadas de experiencia técnica junto a cadenas del sector HORECA, desde la elección de cada equipo hasta su mantenimiento en todos tus locales.»
- **[CTA]**: «Escríbenos por WhatsApp» → `wa-general`
- **Línea de filtro** (debajo del botón; revisión del 11 de septiembre de 2026): la misma de la portada (3.1) y de contacto, en azul. Servicios es la página a la que llega quien busca una «reparación» (la guía «Un equipo ya falló» y el botón de emergencia 24/7), así que ahí también tiene que estar.

### 5.2 Guía rápida: ¿qué servicio necesitas?

**Rediseñada en la ronda 3** (tarde del 11 de septiembre de 2026): Andrés no quedó conforme con la secuencia de cinco fichas («en servicios no me gusta cómo se ve esa secuencia»). Ganó, entre dos diseños evaluados por jueces, una **lista de decisiones**: una sola tarjeta blanca con cinco filas de ancho completo, «tu situación» a la izquierda y «te sirve» a la derecha (con ícono, nombre del servicio y flecha), separadas por líneas finas. Toda la fila es el enlace a su sección. La del correctivo lleva barra y fondo rojo, y un sello compacto «24/7» (con un rayo; «Emergencias» solo para lectores de pantalla) junto a la pregunta.

- **H2**: «¿Qué servicio necesitas?»
- **Subtítulo**: «Elige la situación que se parece a la tuya y te llevamos al servicio que te sirve.»
- **Cabecera de la tabla** (oculta a la vista, solo para lectores de pantalla): «Tu situación» / «Te sirve»

| Tu situación… | Te sirve… | Enlace |
|---|---|---|
| «¿Vas a equipar un local nuevo o renovar equipos?» | Asesoría comercial | `#asesoria` |
| «¿Quieres visitas programadas para que tus equipos no fallen?» | Mantenimiento preventivo | `#preventivo` |
| «¿Quieres detectar a tiempo las señales de falla?» | Mantenimiento predictivo | `#predictivo` |
| «¿Un equipo ya falló?» con el sello 24/7 | Mantenimiento correctivo | `#correctivo` |
| «¿Se repite la misma falla?» | Planificación | `#planificacion` |

Las preguntas se redactaron con palabras que ya estaban en el sitio (la del preventivo sale de «para que tus equipos no fallen», la del predictivo de «detectar a tiempo cualquier señal de falla»). **Pendientes de validar por César/Andrés**: que la Asesoría comercial cubra también equipar un local nuevo completo, no solo renovar equipos sueltos, y que las preguntas del preventivo y el predictivo describan bien cada servicio.

### 5.3 Asesoría comercial (`id="asesoria"`)

- **Ícono sugerido**: portapapeles con visto bueno
- **H2**: «Asesoría comercial»
- **Frase**: «Elige bien desde el principio.»
- **Texto**: «Nuestro equipo te guía en la selección adecuada de tus equipos y se encarga de todo lo que viene después: instalación, puesta en funcionamiento y capacitación de uso. Aplicamos las mejores prácticas y normas del sector, con métodos probados, en cada etapa, desde la concepción de tu proyecto hasta su puesta en marcha.» (corregido el 11-sep-2026: decía «Integramos estándares internacionales de calidad y eficiencia…», la misma afirmación que Andrés pidió quitar de Nosotros por no tener respaldo — NOTAS, B7)
- **Viñetas**: Selección adecuada de equipos · Instalación · Puesta en funcionamiento · Capacitación de uso para tu personal
- **[CTA]**: «Pide asesoría para tu proyecto» → `wa-asesoria`

### 5.4 Mantenimiento preventivo (`id="preventivo"`)

- **Ícono sugerido**: calendario con engranaje
- **H2**: «Mantenimiento preventivo»
- **Frase**: «Para que tus equipos no fallen.»
- **Texto**: «Planes periódicos, programados por contrato, que mantienen en óptimas condiciones los equipos de todos tus locales.»
- **Viñetas**: Inspecciones programadas · Limpieza profunda del equipamiento · Calibración para un funcionamiento óptimo · Reemplazo preventivo de componentes
- **[CTA]**: «Programa tu mantenimiento preventivo» → `wa-preventivo`

### 5.5 Mantenimiento predictivo (`id="predictivo"`)

- **Ícono sugerido**: gráfica con lupa
- **H2**: «Mantenimiento predictivo»
- **Frase**: «Anticipamos los problemas antes de que ocurran.»
- **Texto**: «Vigilamos el rendimiento de tus equipos para detectar a tiempo cualquier señal de falla y alargar su vida útil.»
- **Viñetas**: Análisis del funcionamiento de cada equipo · Monitoreo del rendimiento · Identificación temprana de fallas · Optimización de la vida útil
- **[CTA]**: «Consulta por el mantenimiento predictivo» → `wa-predictivo`

### 5.6 Mantenimiento correctivo (`id="correctivo"`)

- **Ícono sugerido**: llave inglesa con rayo
- **Visual**: es el bloque de urgencia; se destaca con el color rojo de la marca.
- **H2**: «Mantenimiento correctivo»
- **Frase**: «Cuando algo falla, respondemos rápido.»
- **Texto**: «Respuesta rápida y efectiva ante fallas inesperadas en los equipos de tus locales.»
- **Viñetas**: Servicio de emergencia 24/7 · Diagnóstico rápido y preciso · Reparaciones especializadas · Repuestos garantizados
- **[CTA] principal**: «Emergencia: escríbenos ahora» → `wa-emergencia`
- **[CTA] secundario**: «Llamar al 099 788 7709» → `tel`

### 5.7 Planificación (`id="planificacion"`)

- **Ícono sugerido**: diana o mapa de ruta
- **H2**: «Planificación»
- **Frase**: «Soluciones definitivas, no parches.»
- **Texto**: «Solucionamos los problemas de forma definitiva y capacitamos a tu personal para evitar futuras incidencias.»
- **Viñetas**: Solución de fondo para las fallas que se repiten · Capacitación de tu personal · Prevención de nuevas incidencias
- **[CTA]**: «Cuéntanos qué falla se repite» → `wa-planificacion`

### 5.8 Marcas de equipos con las que trabajamos

- **H2**: «Marcas de equipos con las que trabajamos»
- **Texto**: «Conocemos y trabajamos con equipos profesionales de marcas como:»
- **Lista** (en este orden; 18 marcas): Frymaster · Henny Penny · Dean · Hobart · Garland · Taylor · Manitowoc · Vollrath · Bunn · Vitamix · Copeland · Tecumseh · Danfoss · Greenheck · Torrey · Samsung · LG · Westinghouse
  - **Orden de la revisión del 11 de septiembre de 2026**: primero los fabricantes de cocina profesional y luego refrigeración y extracción.
  - **Samsung, LG y Westinghouse, al final**: se retiraron unas horas el 11 de septiembre de 2026 (son las que más se asocian con electrodomésticos) y Andrés pidió reponerlas ese mismo día «para que quede todo completo».
  - **SilverChef** está en la galería de marcas de la web actual y no pasa a la nueva: es una empresa que financia equipos, no un fabricante.
- **Presentación**: **logos**, con el mismo tratamiento que los de clientes (3.6), sin enlaces y alojados en `sitio/assets`. Texto alternativo: el nombre de la marca.
- **Aviso bajo los logos** (visible, letra pequeña, con contraste AA; va **siempre** junto a este bloque, en cualquier página donde aparezca): «Las marcas y sus logos pertenecen a sus fabricantes. Mencionarlas no significa que INDUSTEC sea servicio técnico autorizado ni distribuidor oficial de ellas.»
- **Cuidado**: fuera de ese aviso, no escribir «servicio técnico autorizado», «distribuidor oficial» ni nada parecido. La web actual no lo afirma (NOTAS, B7).

### 5.9 Empresas que han confiado en nuestro trabajo

Mismo bloque que en 3.6 (título, texto, logos y nota). En la web actual ambas listas están en esta página.

### 5.10 Cierre (variante)

- **Título**: «¿No sabes qué servicio necesitas?»
- **Texto**: «Cuéntanos qué pasa con los equipos de tu local y te orientamos.»
- **Botones**: los mismos del bloque 2.6.

---

## 6. Contacto — `/contacto/`

**Rediseñada en la ronda 3** (tarde del 11 de septiembre de 2026): Andrés la sentía impersonal («siento que el diseño de la página de contacto es muy impersonal»). Ganó, entre dos diseños evaluados por jueces, el formato de **conversación con INDUSTEC**: burbujas de saludo, una foto real del equipo dentro de la charla, los tres canales como si fueran los botones de un mensaje, y el formulario con vista previa de chat de WhatsApp. Las secciones 6.1 a 6.4 de abajo reemplazan lo que había antes (tarjetas de canal, franja de emergencia aparte y formulario «Prepara tu mensaje»); 6.5 y 6.6 quedaron con otro nombre y otro lugar, ver la nota al final de cada una.

| Metadato | Texto |
|---|---|
| `<title>` (43) | Contacto \| INDUSTEC · WhatsApp 099 788 7709 |
| `meta description` (143) | Empresas y cadenas: escríbenos por WhatsApp, llámanos al 099 788 7709 o escribe a servicioalcliente@industec.me. Consultoría integral gratuita. |
| `og:title` | Contacto \| INDUSTEC |
| `og:description` (90) | Para empresas y cadenas · WhatsApp y teléfono 099 788 7709 · servicioalcliente@industec.me |
| H1 | Hablemos de las cocinas de tu empresa (antes: «Hablemos de tu empresa») |

### 6.1 Encabezado y charla

- **Etiqueta**: «Contacto»
- **H1**: «Hablemos de las cocinas de tu empresa»
- **Línea de filtro** (justo debajo del H1; mismo estilo que en la portada): «Atendemos a empresas y cadenas, bajo contrato de servicio. No reparamos equipos domésticos ni atendemos a particulares.»
- **Cabecera de la charla**: avatar (el ícono de INDUSTEC) · «INDUSTEC» · «Te atiende nuestro equipo» (**pendiente de confirmar con César si es «nuestro equipo técnico»**, NOTAS B3)
- **Burbuja 1**: «Hola, somos **INDUSTEC**.»
- **Burbuja 2**: «Desde 2010 cuidamos las cocinas profesionales de empresas y cadenas: de la línea caliente y la línea fría a la ventilación.»
- **Burbuja con foto** (la foto real del técnico y otro compañero reparando un horno, `trabajo/horno-rotativo-640.webp`; en escritorio va aparte, a la derecha, con la placa de distintivos debajo): leyenda «Así trabajamos: nuestro equipo técnico en la cocina de un restaurante.» — alt: «Dos técnicos de INDUSTEC trabajan en el tablero de un horno de acero inoxidable»
- **Burbuja 3**: «¿Freidoras, hornos, refrigeración, campanas? Cuéntanos qué pasa en tu local y lo revisamos contigo.»
- **Burbuja de canales**, H2 oculto «¿Por dónde prefieres conversar?»:
  - **[CTA] principal**: «Escribir por WhatsApp» → `wa-general`, con la nota debajo «Tu primer mensaje ya va escrito: solo tienes que enviarlo.»
  - **Botón secundario**: «Llámanos» · 099 788 7709 → `tel`
  - **Botón secundario**: «Escríbenos un correo» · servicioalcliente@industec.me → `mail-general`
- **Placa de distintivos** (solo en escritorio, bajo la foto de la charla): «Servicio de emergencia 24/7» · «Consultoría integral gratuita»

### 6.2 Franja de emergencia

- **Título**: «¿Un equipo se detuvo en plena operación?»
- **Texto**: «Servicio de emergencia 24/7. Escríbenos o llámanos ahora.»
- **[CTA] principal**: «Escríbenos ahora» → `wa-emergencia`
- **[CTA] secundario**: «Llamar al 099 788 7709» → `tel`

### 6.3 Formulario «Prepara tu mensaje»

- **H2**: «Prepara tu mensaje en un minuto»
- **Texto**: «Completa los datos de tu empresa y te dejamos el mensaje listo para enviarlo por WhatsApp o por correo, como prefieras.»
- La vista previa del mensaje (a la derecha del formulario en escritorio) tiene ahora aspecto de conversación de WhatsApp: cabecera azul marino con el ícono de INDUSTEC, «INDUSTEC» y «WhatsApp +593 99 788 7709»; abajo, la burbuja verde con el mensaje que se va armando. Nota bajo la vista previa: «Podrás revisarlo y cambiarlo antes de enviarlo.»

| Etiqueta visible | Tipo | ¿Obligatorio? | Texto de ayuda o ejemplo |
|---|---|---|---|
| Tu nombre | texto | Sí | — |
| Empresa o cadena | texto | Sí | El nombre comercial de tu empresa, cadena, hotel o local. |
| Ciudad | texto | No | — |
| ¿Qué necesitas? | lista | Sí | Opciones: «Un contrato de mantenimiento para mis locales» · «Asesoría para comprar o instalar equipos» · «Mantenimiento preventivo» · «Mantenimiento predictivo» · «Reparación o emergencia» · «Una falla que se repite» · «Otro» |
| Cuéntanos qué pasa | área de texto | Sí | Qué equipo es (marca y modelo, si los tienes), qué está pasando y desde cuándo. |
| Teléfono | teléfono | No | Solo si prefieres que te llamemos. |

- **Botón principal**: «Enviar por WhatsApp»
- **Botón secundario**: «Enviar por correo»
- **Nota bajo los botones** (siempre visible): «Este formulario no guarda ni envía tus datos a ningún servidor: solo prepara el mensaje para que tú lo envíes desde tu WhatsApp o tu correo.»
- **Aviso condicional**: si se elige «Reparación o emergencia», mostrar bajo los botones: «Si es urgente, llámanos: 099 788 7709» (con enlace a `tel`).
- **Mensajes de validación** (en el orden de los campos): «Escribe tu nombre.» · «Escribe el nombre de tu empresa o cadena.» · «Elige qué necesitas.» · «Cuéntanos brevemente qué pasa.»

**Campo «Empresa o cadena»** (11 de septiembre de 2026): reemplaza a «Tu negocio», que era opcional; no se agrega un campo más, así que el formulario sigue pidiendo lo mínimo (LOPDP, principio de minimización). Es obligatorio y va segundo, después del nombre. Para el HTML: `id="f-empresa"`, ayuda `id="a-empresa"`, error `id="e-empresa"`, `autocomplete="organization"`, `maxlength="80"`, `required`, sin `name=`; sin la marca «(opcional)». En `sitio.js` entra en la lista de obligatorios justo después del nombre. La opción nueva de «¿Qué necesitas?» va primera; «Reparación o emergencia» no cambia de texto, porque de ella depende el aviso de urgencia.

**Mensaje que arma el formulario** (el mismo para WhatsApp y para el cuerpo del correo). Las líneas de campos opcionales vacíos se omiten; la línea «Empresa:» va siempre (en la vista previa, con «…» mientras esté vacía):

```
Hola INDUSTEC, les escribo desde su página web.
Nombre: {nombre}
Empresa: {empresa o cadena}
Ciudad: {ciudad}
Necesito: {qué necesitas}
Teléfono: {teléfono}
Detalle: {cuéntanos qué pasa}
```

- WhatsApp: `https://wa.me/593997887709?text=` + `encodeURIComponent(mensaje)`
- Correo: `mailto:servicioalcliente@industec.me?subject=` + `encodeURIComponent("Solicitud desde la web: " + qué necesitas + " - " + empresa o cadena)` + `&body=` + `encodeURIComponent(mensaje)` (saltos de línea como `%0D%0A`)
- No se guarda nada: ni `localStorage`, ni cookies, ni envío a un servidor.

### 6.4 Antes de escribirnos, ten a mano

**Cambió de lugar en la ronda 3**: ya no es una sección aparte, va como una burbuja de INDUSTEC junto al título del formulario (con el mismo avatar de la charla).

- **H3** (dentro de la burbuja): «Para atenderte más rápido, ten a mano:»
- Cuatro íconos con su texto: «La marca y el modelo del equipo» · «Qué está pasando y desde cuándo» · «Una foto del equipo y de su placa» · «La ubicación de tu local»

### 6.5 «Así te atendemos» (nueva en la ronda 3)

En tres pasos, sin plazos ni horarios (los datos que faltan están en NOTAS, B3), con la foto real del técnico revisando freidoras a un lado:

- **H2**: «Así te atendemos»
- **Entrada**: «Del primer mensaje a la solución, en tres pasos.»
- **Paso 1** — «Nos cuentas qué pasa»: «Por WhatsApp, por llamada o por correo: qué equipo es, qué está pasando y en qué local.»
- **Paso 2** — «Conversamos contigo y revisamos el caso»: «Te preguntamos lo necesario para entender el equipo y la operación de tu local.»
- **Paso 3** — «Coordinamos la solución»: «Te proponemos el mantenimiento que necesitan tus equipos: preventivo, predictivo o correctivo, bajo contrato de servicio.»
- **Foto**: `portada-cine-2-640.webp` (la foto real del técnico, con el tratamiento de color «clave baja») — alt: «Técnico de INDUSTEC revisando freidoras en una cocina profesional»

### 6.6 Consultoría integral gratuita

- **H2**: «Consultoría integral gratuita»
- **Texto**: «Cuéntanos el proyecto de tu empresa o cadena y te orientamos sin costo.»
- **[CTA]**: «Pedir mi consultoría gratuita» → `wa-consultoria`

*(Sin mapa, dirección ni horario: hoy no son públicos. Están propuestos en NOTAS, B1, B3 y B12.)*

---

## 7. Acceso del personal — `/acceso/`

| Metadato | Texto |
|---|---|
| `<title>` (30) | Acceso del personal \| INDUSTEC |
| `meta description` (101) | Ingreso exclusivo del personal de INDUSTEC al sistema de gestión B.IA Soft ERP y a la app del técnico. |
| `robots` | `noindex, nofollow` **siempre, también en el dominio definitivo** |
| H1 | Acceso del personal |

- **Etiqueta**: «Solo personal de INDUSTEC»
- **H1**: «Acceso del personal»
- **Texto (dos líneas)**:
  «Ingreso exclusivo del personal de INDUSTEC al sistema de gestión B.IA Soft ERP.»
  (El nombre se quitó en la revisión del 11 de septiembre de 2026 y Andrés autorizó reponerlo ese mismo día: va aquí, en la description y en og:description.)
  «Entra con el usuario y la contraseña que te entregó el administrador del sistema.»
- **[CTA] principal**: «Ingresar al sistema» → `/ot/login.php` · Texto debajo: «El sistema completo, desde el navegador.»
- **[CTA] secundario**: «Abrir la app del técnico» → `/ot/` · Texto debajo: «Para trabajar desde el celular. Ábrela y agrégala a tu pantalla de inicio.»
- **Línea de ayuda**: «¿Olvidaste tu contraseña? Pídesela al administrador del sistema.»
- **Línea para clientes** (separada, en letra más pequeña): «¿Eres cliente y necesitas un servicio? Escríbenos por WhatsApp.» → `wa-general`
- **Íconos sugeridos**: candado (sistema) y celular (app del técnico).
- **Reglas de la página**: los enlaces a `/ot/` llevan `rel="nofollow"`. Aquí no va un formulario de ingreso: el ingreso vive en `/ot/`, y esta página no lo incrusta ni lo copia. Sin barra fija ni botón flotante de WhatsApp. No se nombran cargos internos ni personas.

---

## 8. Textos alternativos e íconos

| Elemento | Texto alternativo |
|---|---|
| Logo del encabezado (enlace a `/`) | «INDUSTEC, la solución a sus equipos. Ir al inicio» |
| Logo del pie | «INDUSTEC, la solución a sus equipos» |
| Imagen de la portada | Ilustración (vigente desde el 11-sep-2026, ronda 3): «Ilustración: técnico de INDUSTEC revisando una freidora en la línea caliente de una cocina de cadena, junto a la refrigeración y bajo la campana de extracción». Si en el futuro vuelve a ser una foto del técnico: «Técnico de INDUSTEC revisando freidoras en una cocina profesional» (sin «batería»: pedido de Andrés del 11-sep-2026) |
| Foto de «Nuestro trabajo» / «Así te atendemos» (portada-cine-2-640.webp, servicios y contacto) | «Técnico de INDUSTEC revisando freidoras en una cocina profesional» |
| Íconos de servicios, segmentos («A quién servimos»), beneficios, valores, pasos y contacto | `alt=""` o `aria-hidden="true"` si el SVG va dentro del HTML (el texto de al lado da el significado) |
| Logos de empresas (si se usan como imagen) | El nombre de la empresa, tal cual: «KFC», «American Deli», «Juan Valdez Café»… |
| Logos de marcas de equipos (si se usan como imagen) | El nombre de la marca: «Hobart», «Frymaster»… |
| Botón flotante de WhatsApp | `aria-label`: «Escríbenos por WhatsApp (se abre WhatsApp)» |
| Imagen para compartir en redes (`og:image`) | `og:image:alt` (en las cinco páginas): «INDUSTEC: mantenimiento de cocinas profesionales para cadenas de restaurantes y hoteles» |
| Fotos reales (cuando César las apruebe, NOTAS B2) | Describir qué se ve y quién lo hace; ej.: «Técnico de INDUSTEC calibrando una freidora en la cocina de un restaurante». **No nombrar al cliente** sin su autorización. |
| «Nuestro trabajo» (11-sep-2026) | «Vitrina caliente de acero inoxidable con el tablero eléctrico abierto durante su mantenimiento» · «Dos técnicos reparan un horno de acero inoxidable en la cocina de un restaurante» · «Dos freidoras de presión en la cocina de un restaurante» · «Mantenedores de calor con temporizadores digitales sobre la línea de despacho de acero inoxidable de un restaurante» |

---

## 9. Metadatos

### 9.1 Comunes a todas las páginas

- `<html lang="es-EC">` · `charset UTF-8` · `viewport` para el celular.
- `og:site_name`: «INDUSTEC» · `og:locale`: `es_EC` · `og:type`: `website` · `og:url`: la URL absoluta de cada página.
- `og:image`: una sola imagen de 1200 × 630 px, con el logo sobre fondo claro y el texto «Mantenimiento de cocinas profesionales para cadenas de restaurantes y hoteles». Es la misma ilustración de la portada, nunca una cocina de casa. Va con URL absoluta. Vigente desde la ronda 3 (11 de septiembre de 2026, tarde): `og-industec-cadenas-v3.jpg` (JPEG de unos 140 KB, con submuestreo de color 4:4:4 para que el rojo y el azul del dibujo no se corran en los filos; en PNG pesaría unos 159 KB, y WhatsApp no muestra las vistas previas muy pesadas); su fuente es `fuentes/og/og.html`.
- `twitter:card`: `summary_large_image`.
- Favicon a partir del logo: la «İ» con su acento, o el monograma en el azul y el rojo del logo (`#48537E` y `#CC504B`).

### 9.2 Mientras el sitio esté en el dominio temporal

- `<meta name="robots" content="noindex, nofollow">` en **todas** las páginas.
- `robots.txt` en la raíz:
  ```
  User-agent: *
  Disallow: /
  ```
- Sin `canonical` y sin `sitemap.xml` hasta el cambio de dominio (lista para ese día en NOTAS, sección E).
- **Nada de `.htaccess` en la raíz** (lo heredaría `/ot/`).

### 9.3 Datos estructurados (solo en `/`, con datos públicos)

Cambiar el dominio de las URL cuando se pase a www.industec.me, y ajustar la ruta del logo a la real.

```json
{
  "@context": "https://schema.org",
  "@type": "LocalBusiness",
  "name": "INDUSTEC",
  "slogan": "Tu único proveedor para la excelencia",
  "description": "Asesoría comercial, equipamiento especializado y mantenimiento preventivo, predictivo y correctivo de cocinas profesionales para empresas y cadenas de restaurantes, hoteles, bares, cafeterías y catering (sector HORECA) en Ecuador.",
  "url": "https://darkviolet-armadillo-872352.hostingersite.com/",
  "logo": "https://darkviolet-armadillo-872352.hostingersite.com/assets/img/logo-industec.png",
  "image": "https://darkviolet-armadillo-872352.hostingersite.com/assets/img/og-industec-cadenas-v3.jpg",
  "telephone": "+593997887709",
  "email": "servicioalcliente@industec.me",
  "foundingDate": "2010",
  "address": { "@type": "PostalAddress", "addressCountry": "EC" },
  "areaServed": { "@type": "Country", "name": "Ecuador" },
  "contactPoint": {
    "@type": "ContactPoint",
    "telephone": "+593997887709",
    "email": "servicioalcliente@industec.me",
    "contactType": "customer service",
    "availableLanguage": "es"
  },
  "hasOfferCatalog": {
    "@type": "OfferCatalog",
    "name": "Servicios",
    "itemListElement": [
      { "@type": "Offer", "itemOffered": { "@type": "Service", "name": "Asesoría comercial" } },
      { "@type": "Offer", "itemOffered": { "@type": "Service", "name": "Mantenimiento preventivo" } },
      { "@type": "Offer", "itemOffered": { "@type": "Service", "name": "Mantenimiento predictivo" } },
      { "@type": "Offer", "itemOffered": { "@type": "Service", "name": "Mantenimiento correctivo" } },
      { "@type": "Offer", "itemOffered": { "@type": "Service", "name": "Planificación" } }
    ]
  }
}
```

Sin dirección de calle, horario, redes (`sameAs`) ni cifras: hoy nada de eso es público.

---

## 10. Página de error 404 (opcional)

Solo si Hostinger sirve `/404.html` sin configuración adicional. **No se añade un `.htaccess` para conseguirlo.**

- **H1**: «Esta página no existe»
- **Texto**: «Puede que el enlace haya cambiado. Vuelve al inicio o escríbenos por WhatsApp.»
- **[CTA]**: «Ir al inicio» → `/` · «Escríbenos por WhatsApp» → `wa-general`

---

## 11. Notas para quien arma el HTML (contenido y accesibilidad)

- **Contraste**: el rojo de la marca `#CC504B` sobre blanco da **4,36 : 1**, por debajo del mínimo AA de 4,5 : 1 para texto normal (lo mismo pasa con texto blanco sobre ese rojo). Se usa en íconos, bordes y títulos grandes (desde 24 px, o desde 18,7 px en negrita). Para texto rojo pequeño y para botones con texto blanco se usa **`#B5413C` (5,55 : 1)**. El azul del logo `#48537E` sobre blanco da 7,46 : 1 y sirve para todo.
- **Enlaces con sentido propio**: nunca «Ver más» o «Clic aquí» solos; si el diseño lo pide corto, el texto completo va en `aria-label` (ver 3.4).
- **Un solo H1 por página**: el indicado en cada tabla de metadatos.
- **Nada de cifras nuevas**: si alguien quiere agregar un número, pasa primero por `NOTAS_PARA_CESAR.md`.
- **Palabras que el verificador rechaza** (`herramientas/verificar-sitio.mjs`, lista de datos no publicables): «zona» o «zonas» —aunque sea «zona de freidoras» o «zona caliente»—, «OT», un número seguido de «locales» («3 locales»), nombres de personas y las cifras retiradas. Los textos de este documento las evitan: que no aparezcan al maquetar, tampoco en comentarios HTML.
- **«Cocina profesional», no «cocina industrial»**: en Ecuador se llama «cocina industrial» también a la cocina a gas de quemadores de alta presión que se compra para la casa o para un comedor pequeño, que es justo el cliente que INDUSTEC no atiende. En la web se dice «cocina profesional» y «equipos de cocina profesional».
