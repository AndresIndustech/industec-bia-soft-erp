# Dirección de arte de las ilustraciones de la web de INDUSTEC

**Fijada por Andrés el 12 de septiembre de 2026**, con tres referencias que envió por chat: las
portadas de los libros *In Tune* (Scott, Foresman English, años 80; libros 1 y 3, y la portada
interior del libro 1). Las imágenes no quedaron en el repositorio; esta descripción es la guía.

## El estilo, en una frase

Ilustración editorial de trazo negro limpio y color plano: personas estilizadas en acción, sin
sombras ni degradados, con una paleta corta y frases manuscritas flotando alrededor.

## Reglas que salen de las referencias

| Rasgo | Cómo es en las referencias | Cómo se aplica aquí |
|---|---|---|
| **Contorno** | Línea negra fina y uniforme en todas las figuras y objetos (ni gruesa ni caligráfica) | `stroke:#1E2438` (la tinta de la marca) de 1,6–2 px a 1x, `stroke-linejoin:round`, `stroke-linecap:round`. Nada sin contorno |
| **Relleno** | Plano, un solo tono por superficie; ningún degradado, ninguna sombra proyectada, ningún brillo | Solo `fill` sólidos. Prohibidos `linearGradient`, `radialGradient`, `filter`, `opacity` para simular volumen |
| **Paleta** | Cálida en la portada 1 (naranja, amarillo, rojo, crema, marrón); fría en la 3 (verde, azul, celeste). Pocos tonos, muy saturados, sobre un fondo de un solo color | Escena principal cálida: fondo `#F6A23A` o crema `#FBE9C8`; ropa y equipos en `#CC504B` (rojo de la marca), `#E8722A`, `#F5C542`, `#FFF3D6`; acentos y un traje en `#48537E` (azul de la marca) y `#9DB7E0`. Piel en dos o tres tonos planos (`#F2B48A`, `#C98B5E`, `#7A4A2A`). Una escena secundaria puede ir monocroma: trazo negro y un solo azul claro `#9DB7E0` sobre blanco, como la portada interior |
| **Figuras** | Cuerpos alargados, cabezas pequeñas, poses de movimiento (baile, brazos en alto, pasos largos); caras con dos puntos y una línea; pelo como una mancha plana | Técnicos con overol azul y chaleco naranja, casco o gorra, herramientas en la mano; administrador del local con camisa y delantal; jefe de zona con tablet; todos en acción (revisando, señalando, cargando una pieza), nunca posando de frente |
| **Escenas** | Grupos de muchas personas, cada una haciendo algo distinto; hay objetos de contexto (guitarra, cartel, puertas) dibujados con el mismo trazo | Cocina de un local de cadena: batería de freidoras, mantenedor de calor, plancha, campana de extracción con ductos, cámara fría, mesa de acero; una camioneta de servicio llegando al local; un taller con un horno abierto |
| **Texto flotante** | Frases cortas en letra manuscrita, en un tono claro sobre el fondo, dispersas y algo giradas | Frases del servicio, en el mismo tono que usa la web (tú): «¿La freidora no calienta?», «Llegamos hoy», «Mantenimiento programado», «Cámara fría a −18 °C», «Campana limpia», «Todo en una sola visita». Siempre en español, nunca datos internos ni nombres de clientes |
| **Composición** | Tercio superior con el título y las frases; dos tercios inferiores con las figuras, sin perspectiva marcada, todo al mismo tamaño | La escena ocupa el ancho; nada de perspectiva forzada; los equipos y las personas al mismo tamaño relativo. Márgenes limpios |
| **Formato** | — | SVG escrito a mano (nada externo, sin fuentes web; el texto manuscrito se dibuja con `<text>` y una fuente cursiva del sistema con `fallback`, o como trazos). Cada SVG bajo 60 KB. Debe verse igual en claro y oscuro: fondo propio siempre |

## Lo que NO es

- No es pixel art ni cómic de línea gruesa.
- No es realismo ni foto: las fotos reales quedan en «Nuestro trabajo» (ya aprobadas por César).
- No hay nada que se lea como cocina de casa: ni cocinas de cuatro hornillas domésticas, ni
  refrigeradoras de hogar, ni delantales floreados. Es la cocina de un local de cadena.

## Dónde se usa (hecho el 13 de septiembre de 2026, T2.17.1)

Las siete ilustraciones viven en `sitio/assets/img/ilus/`, dibujadas a mano en SVG (sin herramienta
generadora: cada archivo es la fuente), con `role="img"` y `aria-label` que describe la escena, y
todas por debajo de 15 KB:

| Archivo | Dónde | Escena |
|---|---|---|
| `portada-cocina-cadena.svg` (712 × 536) | `/` (portada), `/servicios/` (encabezado) y la imagen para redes | La cocina de un local de cadena: campana con ducto, batería de tres freidoras (una apagada, con la luz roja), mantenedor de calor, cámara fría a −18 °C, mesa de acero; un técnico agachado midiendo la freidora con el multímetro, otro en la escalera limpiando el filtro de la campana, un tercero que llega a zancadas con un repuesto, y el administrador del local, con delantal, señalando la freidora. Frases: «¿La freidora no calienta?», «Llegamos hoy», «Campana limpia», «Todo en una sola visita», «Cámara fría a punto», «Mantenimiento programado» |
| `servicio-asesoria.svg` (480 × 360) | `/` (tarjeta) y `/servicios/#asesoria` | Asesor con tableta y administrador del local frente a un horno nuevo recién desembalado |
| `servicio-preventivo.svg` | `/` y `/servicios/#preventivo` | Técnico en la escalera con el filtro de la campana y el paño; calendario con la visita marcada; lista de chequeo |
| `servicio-predictivo.svg` | `/` y `/servicios/#predictivo` | Técnico con la pinza amperimétrica en el compresor de la cámara fría y la gráfica de rendimiento en la tableta |
| `servicio-correctivo.svg` | `/` y `/servicios/#correctivo` | Fondo rojo (es el bloque de urgencia): técnico corriendo con la caja de herramientas hacia una freidora parada, con el reloj de pared y el globo de alerta |
| `servicio-planificacion.svg` | `/` y `/servicios/#planificacion` | Jefe técnico explicando en una pizarra los pasos de la solución de fondo; un cocinero del local y un técnico toman nota |
| `equipo-monocromo.svg` (640 × 400) | `/nosotros/` (encabezado) y `/contacto/` («Así te atendemos») | Monocroma, trazo negro y un solo azul claro: el equipo baja de la camioneta de servicio frente al local con la escalera, la caja de herramientas y un repuesto |

La imagen para redes (`og-industec-cadenas-v4.jpg`, 1200 × 630) se genera con `herramientas/generar_og.py`
a partir de `fuentes/og/og.html`, que monta la portada en la tarjeta. Los textos manuscritos usan
`<text>` con una fuente cursiva del sistema (Segoe Script, Bradley Hand, Comic Sans, Chalkboard) y un
giro de 2 a 6 grados; los contornos son `stroke #1E2438` de 2 px con extremos redondeados, y las
extremidades de las figuras van con dos trazos superpuestos (el negro más grueso debajo, el color
encima), que es lo que da el contorno uniforme sin dibujar cada brazo como un polígono.

Para revisarlas: `herramientas/capturar-sitio.mjs` las captura dentro de las páginas a 390 y 1440 px;
para verlas sueltas basta abrir el SVG en Edge.
