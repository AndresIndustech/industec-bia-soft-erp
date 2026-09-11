# Contenido de la web corporativa de INDUSTEC

> **Preparado por**: Valentina (comercial y marketing, INDUSTECH) · **Fecha**: 11 de septiembre de 2026
> **Para**: quien arme el HTML en el dominio temporal `darkviolet-armadillo-872352.hostingersite.com`
> **Estado**: textos finales, listos para pegar. Andrés los revisa; César da el visto bueno.
> **Fuente única**: lo que hoy publica www.industec.me (revisado el 11 de septiembre de 2026). **No hay aquí ningún dato nuevo sin confirmar.** Todo lo que requiere el visto bueno de César está en `NOTAS_PARA_CESAR.md` y **no va al HTML**.

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

---

## 2. Elementos comunes a todas las páginas

### 2.1 Enlaces de contacto (usar exactamente estos)

Todos los mensajes de WhatsApp empiezan con **«les escribo desde su página web»**. Así César sabe qué conversaciones llegan por la web **sin poner rastreadores** (ver `NOTAS_PARA_CESAR.md`, sección D). El texto se ve y se edita antes de enviar, así que es transparente para quien escribe.

| Clave | Uso | Mensaje que se abre en WhatsApp | Enlace |
|---|---|---|---|
| `wa-general` | Portada, menú, botón flotante, cierres | «Hola INDUSTEC, les escribo desde su página web. Quiero información sobre sus servicios para mi negocio.» | `https://wa.me/593997887709?text=Hola%20INDUSTEC%2C%20les%20escribo%20desde%20su%20p%C3%A1gina%20web.%20Quiero%20informaci%C3%B3n%20sobre%20sus%20servicios%20para%20mi%20negocio.` |
| `wa-consultoria` | Consultoría integral gratuita | «Hola INDUSTEC, les escribo desde su página web. Quiero solicitar la consultoría integral gratuita.» | `https://wa.me/593997887709?text=Hola%20INDUSTEC%2C%20les%20escribo%20desde%20su%20p%C3%A1gina%20web.%20Quiero%20solicitar%20la%20consultor%C3%ADa%20integral%20gratuita.` |
| `wa-asesoria` | Asesoría comercial | «Hola INDUSTEC, les escribo desde su página web. Necesito asesoría para elegir e instalar equipos.» | `https://wa.me/593997887709?text=Hola%20INDUSTEC%2C%20les%20escribo%20desde%20su%20p%C3%A1gina%20web.%20Necesito%20asesor%C3%ADa%20para%20elegir%20e%20instalar%20equipos.` |
| `wa-preventivo` | Mantenimiento preventivo | «Hola INDUSTEC, les escribo desde su página web. Quiero programar un mantenimiento preventivo.» | `https://wa.me/593997887709?text=Hola%20INDUSTEC%2C%20les%20escribo%20desde%20su%20p%C3%A1gina%20web.%20Quiero%20programar%20un%20mantenimiento%20preventivo.` |
| `wa-predictivo` | Mantenimiento predictivo | «Hola INDUSTEC, les escribo desde su página web. Quiero información sobre el mantenimiento predictivo.» | `https://wa.me/593997887709?text=Hola%20INDUSTEC%2C%20les%20escribo%20desde%20su%20p%C3%A1gina%20web.%20Quiero%20informaci%C3%B3n%20sobre%20el%20mantenimiento%20predictivo.` |
| `wa-emergencia` | Mantenimiento correctivo y emergencias | «Hola INDUSTEC, les escribo desde su página web. Tengo una emergencia: un equipo dejó de funcionar.» | `https://wa.me/593997887709?text=Hola%20INDUSTEC%2C%20les%20escribo%20desde%20su%20p%C3%A1gina%20web.%20Tengo%20una%20emergencia%3A%20un%20equipo%20dej%C3%B3%20de%20funcionar.` |
| `wa-planificacion` | Planificación | «Hola INDUSTEC, les escribo desde su página web. Tengo una falla que se repite y quiero solucionarla de forma definitiva.» | `https://wa.me/593997887709?text=Hola%20INDUSTEC%2C%20les%20escribo%20desde%20su%20p%C3%A1gina%20web.%20Tengo%20una%20falla%20que%20se%20repite%20y%20quiero%20solucionarla%20de%20forma%20definitiva.` |
| `tel` | Llamar | Texto visible: **099 788 7709** | `tel:+593997887709` |
| `mail-general` | Correo | Texto visible: **servicioalcliente@industec.me** | `mailto:servicioalcliente@industec.me?subject=Solicitud%20desde%20la%20web` |
| `mail-empleo` | Trabaja con nosotros | — | `mailto:servicioalcliente@industec.me?subject=Quiero%20trabajar%20en%20INDUSTEC` |

Formato del número: en los textos va **099 788 7709**; en los enlaces, siempre en formato internacional (`+593997887709`). Los enlaces a `wa.me` se abren en una pestaña nueva (`target="_blank" rel="noopener"`), y el texto del enlace o su `aria-label` avisa «(se abre WhatsApp)».

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

- Logo + «Soluciones integrales para hoteles, restaurantes y catering en el Ecuador.»
- Columna **«Páginas»**: Inicio · Nosotros · Servicios · Contacto
- Columna **«Contáctanos»**:
  - WhatsApp: +593 99 788 7709 → `wa-general`
  - Teléfono: 099 788 7709 → `tel`
  - Correo: servicioalcliente@industec.me → `mail-general`
- Enlace: «Acceso del personal» → `/acceso/`
- Línea final: «© 2026 INDUSTEC. Todos los derechos reservados.»

### 2.6 Bloque de cierre (se repite al final de Inicio, Nosotros y Servicios)

- **Título**: «¿Un equipo te está dando problemas o tienes un proyecto nuevo?»
- **Texto**: «Cuéntanos qué necesitas. Te respondemos por WhatsApp, por teléfono o por correo.»
- **[CTA] principal**: «Escríbenos por WhatsApp» → `wa-general`
- **[CTA] secundario**: «Llamar al 099 788 7709» → `tel`
- **[CTA] terciario** (enlace de texto): «Enviar un correo» → `mail-general`

---

## 3. Inicio — `/`

| Metadato | Texto |
|---|---|
| `<title>` (53 caracteres) | INDUSTEC \| Asesoría y mantenimiento de equipos HORECA |
| `meta description` (142) | Asesoría, equipamiento y mantenimiento preventivo, predictivo y correctivo para restaurantes, hoteles y catering en Ecuador. Emergencias 24/7. |
| `og:title` | INDUSTEC \| Asesoría y mantenimiento de equipos HORECA |
| `og:description` | Tu único proveedor para asesoría, equipamiento y mantenimiento de restaurantes, hoteles y catering. Emergencias 24/7. |
| H1 | Asesoría, equipamiento y mantenimiento para restaurantes, hoteles y catering |

### 3.1 Portada (primera pantalla)

> **Prueba de los 5 segundos**: **qué** (asesoría, equipamiento, mantenimiento) + **para quién** (restaurantes, hoteles, catering) + **cómo contactar** (dos botones). Todo debe verse sin bajar, también en el celular.

- **Etiqueta** (encima del título): «Soluciones integrales · Sector HORECA»
- **H1**: «Asesoría, equipamiento y mantenimiento para restaurantes, hoteles y catering»
- **Texto**: «Tu único proveedor para la excelencia: te ayudamos a elegir, instalar y mantener los equipos de tu negocio, con servicio de emergencia 24/7.»
- **[CTA] principal**: «Escríbenos por WhatsApp» → `wa-general`
- **[CTA] secundario**: «Llamar al 099 788 7709» → `tel`
- **Enlace bajo los botones**: «o pide tu consultoría integral gratuita» → `wa-consultoria`
- **Imagen**: ilustración propia en SVG de una cocina profesional, decorativa (`alt=""`). No usar las fotos de banco de imágenes de la web actual. Las fotos reales están propuestas en NOTAS, sección B2.

### 3.2 Franja de confianza (4 datos, todos públicos)

| Dato grande | Texto debajo |
|---|---|
| **2010** | Fundada para evolucionar la industria HORECA |
| **+40 años** | De legado junto a grandes cadenas de comida rápida |
| **24/7** | Servicio de emergencia |
| **1 solo** | Proveedor para asesoría, equipos y mantenimiento |

### 3.3 Qué hacemos

- **H2**: «Todo lo que tus equipos necesitan, con un solo proveedor»
- **Texto**: «Te acompañamos desde que eliges un equipo hasta que lo mantienes funcionando.»

Cinco tarjetas; cada una enlaza a su sección en `/servicios/`.

| Ícono sugerido | Título | Texto | Enlace (texto accesible) → destino |
|---|---|---|---|
| Portapapeles con visto bueno | Asesoría comercial | Te ayudamos a elegir el equipo adecuado, lo instalamos, lo ponemos en marcha y capacitamos a tu personal. | «Más sobre asesoría comercial» → `/servicios/#asesoria` |
| Calendario con engranaje | Mantenimiento preventivo | Inspecciones, limpieza profunda y calibración programadas para que tus equipos no fallen. | «Más sobre mantenimiento preventivo» → `/servicios/#preventivo` |
| Gráfica con lupa | Mantenimiento predictivo | Anticipamos los problemas antes de que ocurran. | «Más sobre mantenimiento predictivo» → `/servicios/#predictivo` |
| Llave inglesa con rayo | Mantenimiento correctivo | Respuesta rápida ante fallas inesperadas, con servicio de emergencia 24/7 y repuestos garantizados. | «Más sobre mantenimiento correctivo» → `/servicios/#correctivo` |
| Diana o mapa de ruta | Planificación | Solucionamos los problemas de forma definitiva y capacitamos a tu personal para evitar nuevas incidencias. | «Más sobre planificación» → `/servicios/#planificacion` |

- **[CTA]** debajo de las tarjetas: «Ver todos los servicios» → `/servicios/`
- En el HTML, el enlace de cada tarjeta dice «Ver servicio» y su nombre accesible (`aria-label`) es «Ver servicio de asesoría comercial», «Ver servicio de mantenimiento preventivo»… El nombre accesible tiene que **empezar por el texto que se ve** (WCAG 2.5.3) para que funcione el control por voz; «Más sobre…» no lo cumplía.

### 3.4 ¿Por qué elegir un proveedor integral?

- **H2**: «¿Por qué elegir un proveedor integral?»
- **Subtítulo**: «Un solo proveedor, múltiples beneficios»

| Ícono (ya existe) | Título | Texto |
|---|---|---|
| `target-red.svg` | Único punto de contacto | Una sola relación para todo. Simplifica tu gestión. |
| `trending-red.svg` | Ahorro de costos | Ahorras con una negociación integral. |
| `shield-red.svg` | Compatibilidad garantizada | Lo que te proponemos está pensado para funcionar en conjunto. |
| `clock-red.svg` | Menos tiempo de implementación | Tu proyecto se pone en marcha antes. |

### 3.5 Empresas que han confiado en nuestro trabajo

- **H2**: «Empresas que han confiado en nuestro trabajo»
- **Texto**: «Hemos realizado trabajos para marcas como:»
- **Lista** (en este orden): KFC · American Deli · Arrayanes · Tropi Burger · Cinnabon · Naturissimo · Yanbal · Gus · TEVCOL · GPF · Menestras del Negro · Juan Valdez Café · V&V · El Bodegón · Baskin Robbins · San Felipe · Decamerón
- **Presentación**: cuadrícula de logos en un solo tono gris, del mismo tamaño, sin enlaces. Si no se tienen los archivos de los logos, los nombres en texto con el mismo estilo. La grafía exacta de algunos nombres está por confirmar (NOTAS, A6); mientras tanto se publica como está aquí, que es como aparece hoy.

### 3.6 Cierre

Bloque 2.6.

---

## 4. Nosotros — `/nosotros/`

| Metadato | Texto |
|---|---|
| `<title>` (49) | Nosotros \| INDUSTEC, soluciones integrales HORECA |
| `meta description` (145) | Desde 2010 impulsamos la industria HORECA en Ecuador, con un legado de más de 40 años junto a las principales cadenas de comida rápida del mundo. |
| `og:title` | Nosotros \| INDUSTEC, soluciones integrales HORECA |
| `og:description` | Desde 2010 impulsamos la industria HORECA en Ecuador: más que un proveedor, tu aliado para escalar, automatizar e innovar. |
| H1 | Desde 2010, evolucionando la industria HORECA en el Ecuador |

### 4.1 Encabezado

- **Etiqueta**: «Nosotros»
- **H1**: «Desde 2010, evolucionando la industria HORECA en el Ecuador»
- **Texto**: «Diseñamos soluciones que ayudan a hoteles, restaurantes y servicios de catering a superar con éxito los retos de un entorno en constante cambio.»

### 4.2 Nuestra historia

- **H2**: «Nuestra historia»
- **Párrafo 1**: «En 2010 fundamos INDUSTEC con un propósito: evolucionar la industria HORECA —hoteles, restaurantes y catering— en el Ecuador.»
- **Párrafo 2**: «Sabemos que el sector exige agilidad y precisión. Por eso diseñamos soluciones que ayudan a los negocios a superar con éxito los retos de un entorno en constante cambio.»
- **Frase destacada** (tipografía grande, con el ícono `star-red.svg`): «Más que un proveedor, somos tu mejor aliado para escalar, automatizar e innovar.»

### 4.3 Lo que nos hace diferentes

- **H2**: «Lo que nos hace diferentes»

| Ícono sugerido | Título | Texto |
|---|---|---|
| Medalla | Nuestra herencia | Un legado de más de 40 años de experiencia junto a las cadenas de comida rápida más importantes del mundo. |
| Red de personas | Una red de confianza | Proveedores y especialistas confiables que nos permiten acompañarte en cada paso. |
| Sello con visto bueno | Estándares internacionales | Operaciones rentables, eficientes y bajo estándares de certificación internacional. |

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
| `<title>` (55) | Servicios de asesoría y mantenimiento HORECA \| INDUSTEC |
| `meta description` (146) | Asesoría comercial, mantenimiento preventivo, predictivo y correctivo con emergencias 24/7, y planificación para restaurantes, hoteles y catering. |
| `og:title` | Servicios de asesoría y mantenimiento HORECA \| INDUSTEC |
| `og:description` | Asesoría comercial, mantenimiento preventivo, predictivo y correctivo con emergencias 24/7, y planificación. |
| H1 | Todo el ciclo de tus equipos, con un solo proveedor |

### 5.1 Encabezado

- **Etiqueta**: «Servicios»
- **H1**: «Todo el ciclo de tus equipos, con un solo proveedor»
- **Texto**: «Más de cuatro décadas de experiencia técnica y operativa en el sector HORECA, desde la elección del equipo hasta su mantenimiento.»
- **[CTA]**: «Escríbenos por WhatsApp» → `wa-general`

### 5.2 Guía rápida: ¿qué servicio necesitas?

- **H2**: «¿Qué servicio necesitas?»
- **Visual**: cinco fichas con el formato «situación → servicio». Cada ficha es un enlace a su sección.

| Si tu situación es… | Te sirve… | Enlace |
|---|---|---|
| «Voy a comprar o renovar equipos» | Asesoría comercial | `#asesoria` |
| «Quiero que mis equipos no fallen» | Mantenimiento preventivo | `#preventivo` |
| «Quiero detectar una falla antes de que ocurra» | Mantenimiento predictivo | `#predictivo` |
| «Un equipo ya falló» | Mantenimiento correctivo · 24/7 | `#correctivo` |
| «La misma falla se repite» | Planificación | `#planificacion` |

### 5.3 Asesoría comercial (`id="asesoria"`)

- **Ícono sugerido**: portapapeles con visto bueno
- **H2**: «Asesoría comercial»
- **Frase**: «Elige bien desde el principio.»
- **Texto**: «Nuestro equipo te guía en la selección adecuada de tus equipos y se encarga de todo lo que viene después: instalación, puesta en funcionamiento y capacitación de uso. Integramos estándares internacionales de calidad y eficiencia en cada etapa, desde la concepción de tu proyecto hasta su puesta en marcha.»
- **Viñetas**: Selección adecuada de equipos · Instalación · Puesta en funcionamiento · Capacitación de uso para tu personal
- **[CTA]**: «Pide asesoría para tu proyecto» → `wa-asesoria`

### 5.4 Mantenimiento preventivo (`id="preventivo"`)

- **Ícono sugerido**: calendario con engranaje
- **H2**: «Mantenimiento preventivo»
- **Frase**: «Para que tus equipos no fallen.»
- **Texto**: «Procedimientos periódicos y programados que mantienen tus equipos en óptimas condiciones.»
- **Viñetas**: Inspecciones programadas · Limpieza profunda del equipamiento · Calibración para un funcionamiento óptimo · Reemplazo preventivo de componentes
- **[CTA]**: «Programa tu mantenimiento preventivo» → `wa-preventivo`

### 5.5 Mantenimiento predictivo (`id="predictivo"`)

- **Ícono sugerido**: gráfica con lupa
- **H2**: «Mantenimiento predictivo»
- **Frase**: «Anticipamos los problemas antes de que ocurran.»
- **Texto**: «Vigilamos el rendimiento de tus equipos para detectar a tiempo cualquier señal de falla y alargar su vida útil.»
- **Viñetas**: Análisis de su correcto funcionamiento · Monitoreo del rendimiento · Identificación temprana de fallas · Optimización de la vida útil
- **[CTA]**: «Consulta por el mantenimiento predictivo» → `wa-predictivo`

### 5.6 Mantenimiento correctivo (`id="correctivo"`)

- **Ícono sugerido**: llave inglesa con rayo
- **Visual**: es el bloque de urgencia; se destaca con el color rojo de la marca.
- **H2**: «Mantenimiento correctivo»
- **Frase**: «Cuando algo falla, respondemos rápido.»
- **Texto**: «Respuesta rápida y efectiva ante problemas inesperados que requieren corrección inmediata.»
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
- **Texto**: «Conocemos y trabajamos con equipos de marcas como:»
- **Lista** (en este orden): Westinghouse · Samsung · Hobart · Dean · Vollrath · Henny Penny · Copeland · Vitamix · Manitowoc · Greenheck · Bunn · Tecumseh · Torrey · Garland · Danfoss · Taylor · LG · Frymaster
- **Cuidado**: no escribir «servicio técnico autorizado», «distribuidor oficial» ni nada parecido. La web actual no lo afirma (NOTAS, B7).

### 5.9 Empresas que han confiado en nuestro trabajo

Mismo bloque que en 3.5 (título, texto y lista). En la web actual ambas listas están en esta página.

### 5.10 Cierre (variante)

- **Título**: «¿No sabes qué servicio necesitas?»
- **Texto**: «Cuéntanos qué pasa con tu equipo y te orientamos.»
- **Botones**: los mismos del bloque 2.6.

---

## 6. Contacto — `/contacto/`

| Metadato | Texto |
|---|---|
| `<title>` (43) | Contacto \| INDUSTEC · WhatsApp 099 788 7709 |
| `meta description` (135) | Escríbenos por WhatsApp, llámanos al 099 788 7709 o escribe a servicioalcliente@industec.me. Solicita tu consultoría integral gratuita. |
| `og:title` | Contacto \| INDUSTEC |
| `og:description` | WhatsApp y teléfono 099 788 7709 · servicioalcliente@industec.me · Consultoría integral gratuita. |
| H1 | Hablemos de tu negocio |

### 6.1 Encabezado

- **Etiqueta**: «Contacto»
- **H1**: «Hablemos de tu negocio»
- **Texto**: «Cuéntanos qué necesitas y te respondemos por el medio que prefieras.»

### 6.2 Tres formas de contactarnos (tarjetas grandes; en el celular, una debajo de otra)

| Ícono | Título | Dato | Texto | [CTA] → destino |
|---|---|---|---|---|
| WhatsApp | WhatsApp | +593 99 788 7709 | La forma más rápida. También puedes enviarnos fotos del equipo. | «Escribir por WhatsApp» → `wa-general` |
| Teléfono | Llamada | 099 788 7709 | Para urgencias o si prefieres conversar. | «Llamar ahora» → `tel` |
| Sobre | Correo | servicioalcliente@industec.me | Ideal para enviarnos documentos o los detalles de tu proyecto. | «Enviar correo» → `mail-general` |

### 6.3 Franja de emergencia

- **Título**: «¿Un equipo se detuvo en plena operación?»
- **Texto**: «Servicio de emergencia 24/7.»
- **[CTA] principal**: «Escríbenos ahora» → `wa-emergencia`
- **[CTA] secundario**: «Llamar al 099 788 7709» → `tel`

### 6.4 Formulario «Prepara tu mensaje»

- **H2**: «Prepara tu mensaje en un minuto»
- **Texto**: «Completa estos datos y envíalo por WhatsApp o por correo, como prefieras.»

| Etiqueta visible | Tipo | ¿Obligatorio? | Texto de ayuda o ejemplo |
|---|---|---|---|
| Tu nombre | texto | Sí | — |
| Tu negocio | texto | No | Ej.: restaurante, hotel o catering |
| Ciudad | texto | No | — |
| ¿Qué necesitas? | lista | Sí | Opciones: «Asesoría para comprar o instalar equipos» · «Mantenimiento preventivo» · «Mantenimiento predictivo» · «Reparación o emergencia» · «Una falla que se repite» · «Otro» |
| Cuéntanos qué pasa | área de texto | Sí | Qué equipo es (marca y modelo, si los tienes), qué está pasando y desde cuándo. |
| Teléfono | teléfono | No | Solo si prefieres que te llamemos. |

- **Botón principal**: «Enviar por WhatsApp»
- **Botón secundario**: «Enviar por correo»
- **Nota bajo los botones** (siempre visible): «Este formulario no guarda ni envía tus datos a ningún servidor: solo prepara el mensaje para que tú lo envíes desde tu WhatsApp o tu correo.»
- **Aviso condicional**: si se elige «Reparación o emergencia», mostrar bajo los botones: «Si es urgente, llámanos: 099 788 7709» (con enlace a `tel`).
- **Mensajes de validación**: «Escribe tu nombre.» · «Elige qué necesitas.» · «Cuéntanos brevemente qué pasa.»

**Mensaje que arma el formulario** (el mismo para WhatsApp y para el cuerpo del correo). Las líneas de campos opcionales vacíos se omiten:

```
Hola INDUSTEC, les escribo desde su página web.
Nombre: {nombre}
Negocio: {negocio}
Ciudad: {ciudad}
Necesito: {qué necesitas}
Teléfono: {teléfono}
Detalle: {cuéntanos qué pasa}
```

- WhatsApp: `https://wa.me/593997887709?text=` + `encodeURIComponent(mensaje)`
- Correo: `mailto:servicioalcliente@industec.me?subject=` + `encodeURIComponent("Solicitud desde la web: " + qué necesitas)` + `&body=` + `encodeURIComponent(mensaje)` (saltos de línea como `%0D%0A`)
- No se guarda nada: ni `localStorage`, ni cookies, ni envío a un servidor.

### 6.5 Antes de escribirnos, ten a mano

- **H2**: «Para atenderte más rápido, ten a mano:»
- Cuatro íconos con su texto: «La marca y el modelo del equipo» · «Qué está pasando y desde cuándo» · «Una foto del equipo y de su placa» · «La ubicación de tu local»

### 6.6 Consultoría integral gratuita

- **H2**: «Consultoría integral gratuita»
- **Texto**: «Cuéntanos tu proyecto y te orientamos sin costo.»
- **[CTA]**: «Pedir mi consultoría gratuita» → `wa-consultoria`

*(Sin mapa, dirección ni horario: hoy no son públicos. Están propuestos en NOTAS, B1, B3 y B12.)*

---

## 7. Acceso del personal — `/acceso/`

| Metadato | Texto |
|---|---|
| `<title>` (30) | Acceso del personal \| INDUSTEC |
| `meta description` (102) | Ingreso exclusivo del personal de INDUSTEC al sistema de gestión B.IA Soft ERP y a la app del técnico. |
| `robots` | `noindex, nofollow` **siempre, también en el dominio definitivo** |
| H1 | Acceso del personal |

- **Etiqueta**: «Solo personal de INDUSTEC»
- **H1**: «Acceso del personal»
- **Texto (dos líneas)**:
  «Ingreso exclusivo del personal de INDUSTEC al sistema de gestión B.IA Soft ERP.»
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
| Ilustración de la portada (SVG) | `alt=""` (decorativa: el titular ya dice lo mismo) |
| Íconos de servicios, beneficios, valores, pasos y contacto | `alt=""` o `aria-hidden="true"` si el SVG va dentro del HTML (el texto de al lado da el significado) |
| Logos de empresas (si se usan como imagen) | El nombre de la empresa, tal cual: «KFC», «American Deli», «Juan Valdez Café»… |
| Logos de marcas de equipos (si se usan como imagen) | El nombre de la marca: «Hobart», «Frymaster»… |
| Botón flotante de WhatsApp | `aria-label`: «Escríbenos por WhatsApp (se abre WhatsApp)» |
| Imagen para compartir en redes (`og:image`) | `og:image:alt`: «INDUSTEC: asesoría, equipamiento y mantenimiento para restaurantes, hoteles y catering» |
| Fotos reales (cuando César las apruebe, NOTAS B2) | Describir qué se ve y quién lo hace; ej.: «Técnico de INDUSTEC calibrando una freidora en la cocina de un restaurante». **No nombrar al cliente** sin su autorización. |

---

## 9. Metadatos

### 9.1 Comunes a todas las páginas

- `<html lang="es-EC">` · `charset UTF-8` · `viewport` para el celular.
- `og:site_name`: «INDUSTEC» · `og:locale`: `es_EC` · `og:type`: `website` · `og:url`: la URL absoluta de cada página.
- `og:image`: una sola imagen de 1200 × 630 px, hecha a partir del logo sobre fondo claro y con el texto «Asesoría, equipamiento y mantenimiento para restaurantes, hoteles y catering». Va con URL absoluta.
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
  "description": "Asesoría comercial, equipamiento especializado y mantenimiento preventivo, predictivo y correctivo para hoteles, restaurantes y catering (sector HORECA) en Ecuador.",
  "url": "https://darkviolet-armadillo-872352.hostingersite.com/",
  "logo": "https://darkviolet-armadillo-872352.hostingersite.com/assets/img/logo-industec.png",
  "image": "https://darkviolet-armadillo-872352.hostingersite.com/assets/img/og-industec.png",
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
- **Enlaces con sentido propio**: nunca «Ver más» o «Clic aquí» solos; si el diseño lo pide corto, el texto completo va en `aria-label` (ver 3.3).
- **Un solo H1 por página**: el indicado en cada tabla de metadatos.
- **Nada de cifras nuevas**: si alguien quiere agregar un número, pasa primero por `NOTAS_PARA_CESAR.md`.
