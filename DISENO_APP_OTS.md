# Cómo funciona la app nueva

**Fecha:** 2026-09-06 · **Estado:** diseño acordado, en construcción
**Depende de:** [`ESPECIFICACION_OT_UNICA.md`](ESPECIFICACION_OT_UNICA.md) (el formato) y [`ARQUITECTURA_SISTEMA_OTS.md`](ARQUITECTURA_SISTEMA_OTS.md) (dónde vive cada cosa)

---

## 1. El problema real que hay que resolver

No es "hacer un formulario más bonito". Es esto:

> Un técnico está en la cocina de un KFC, detrás de una freidora, con las manos
> sucias, sin señal, y con el administrador del local esperando para firmar.

Todo lo que sigue está diseñado contra esa escena. El sistema de hoy asume lo
contrario —conexión estable, tiempo de sobra, un solo envío gigante— y por eso
produce 87 órdenes en blanco, PDFs vacíos de 7 MB y correlativos quemados.

---

## 2. Tres superficies, una sola verdad

| Superficie | Quién la usa | Dónde corre | Para qué |
|---|---|---|---|
| **Captura** | Los técnicos, en el celular | Hostinger | Emitir la orden en sitio |
| **Gestión** | Administración y jefes de zona | Hostinger | Plan del día, backlog, buscar, reenviar |
| **Consola** | Andrés y la administración | La estación | Análisis, reportes a KFC, histórico completo |

La **consola ya está construida y corriendo**. Las otras dos son lo que se
levanta ahora.

**Hostinger guarda la ventana operativa** —lo abierto más los últimos 90 días—.
**La estación guarda la memoria completa.** Esa división es lo que permite
purgar el servidor sin perder nada y hacer análisis pesado sin chocar con el
corte de 60 segundos por consulta del plan Premium.

---

## 3. La captura, paso a paso

### Se instala como una app

Es una **PWA**: el técnico la abre una vez desde un enlace y le queda un ícono en
la pantalla del celular. No hay que publicarla en ninguna tienda, no hay
instalador, y se actualiza sola.

Al instalarse se baja los catálogos —100 locales, 1.173 activos, 222 tipos, la
lista de técnicos vigentes— y los guarda **en el teléfono**. Por eso los
desplegables funcionan sin señal.

### Funciona sin conexión

Esto no es un lujo: es la diferencia entre que la orden exista o no.

- El técnico llena la orden completa **offline**. Cada campo se guarda en el
  teléfono conforme escribe (IndexedDB). Si se cierra la app, si se apaga el
  celular, si se va la batería: **la orden a medias sigue ahí**.
- Las **validaciones corren en el teléfono**, con las mismas reglas del servidor.
  El técnico se entera de que el aviso tiene 7 dígitos ahí mismo, no después.
- Cuando hay señal, la orden se envía sola. El técnico ve *"2 órdenes pendientes
  de enviar"* y puede seguir trabajando.

### Las fotos van aparte, y de una en una

Hoy las fotos viajan **dentro** del envío, en base64. Una orden preventiva con 7
equipos y 35 fotos llega a ~48 MB contra un límite de 64 MB; si se pasa, PHP
descarta el envío entero **en silencio** y sale un PDF en blanco. Eso es lo que
produjo los `OT-0011---Dia -.pdf`.

En la app nueva cada foto se sube **por separado**, en cuanto hay señal, y el
formulario guarda solo su identificador. El envío final es un JSON de unos 5 KB
que no puede superar ningún límite.

### Qué pasa exactamente al pulsar "enviar"

```
 1. El teléfono revalida la orden completa               (nada sale a medias)
 2. Confirma que todas las fotos ya subieron
 3. POST del JSON de la orden                            (~5 KB, no 48 MB)
 4. El servidor REVALIDA TODO desde cero                 (nunca confía en el cliente)
 5. Reserva el correlativo en una transacción atómica    (no hay dos iguales)
 6. Escribe la fila en la base                           <- la orden YA EXISTE
 7. Genera el PDF a partir de la fila
 8. Encola los correos                                   (no los manda sincrónico)
 9. Devuelve el número de orden al técnico
```

**El paso 6 es el cambio de fondo.** Hoy el registro es el PDF y el correo; a
partir de aquí el registro es la fila, y el PDF pasa a ser **una representación
que se puede volver a generar**. Por eso se puede purgar el servidor sin perder
nada, y por eso un fallo del correo ya no hace desaparecer una orden.

**El correlativo se reserva en el paso 5, no antes.** Un formulario que se
abandona a la mitad no quema un número. Hoy sí: los 87 envíos vacíos consumieron
87 correlativos.

### Lo que deja de ser posible

| Hoy | Con la app |
|---|---|
| Un GET genera una OT en blanco y la manda a KFC | No hay envío sin sesión y sin orden validada |
| El envío se pasa del límite y sale un PDF vacío | Las fotos van aparte; el envío pesa 5 KB |
| Se corta la señal y se pierde todo lo escrito | La orden vive en el teléfono hasta que se confirma |
| Dos técnicos comparten correlativo | Reserva atómica en la base |
| Falla el SMTP y la orden no llega a nadie | La orden ya está guardada; el correo se reintenta |
| Se teclea el local, el equipo, el técnico | Listas cerradas de los catálogos |

---

## 4. La gestión

Lo que hoy se hace revisando correos y carpetas:

- **Plan del día por zona** — qué hay abierto, qué se atendió, qué pasó de 48 h.
- **Backlog real** — por `estatus_general` de SAP, nunca por la señal interna.
- **Buscar** cualquier orden por local, aviso, fecha o técnico.
- **Reenviar** el PDF a quien haga falta, sin pedírselo al técnico.
- **Cerrar el ciclo**: marcar lo revisado, anotar el seguimiento.

Es el mismo dato que ve la consola local, pero recortado a la ventana operativa
y accesible desde cualquier lado.

---

## 5. Dónde se construye

El panel tiene cinco sitios en el plan Premium (vence **2026-12-18**):

| Sitio | Qué es | Qué hacemos |
|---|---|---|
| `yellow-elephant-166233…` | **El sistema de OTs en producción**, bajo `/ot/produccion/`. Tiene WordPress encima | No se toca salvo los parches de seguridad |
| `darkorchid-crane-387868…` | WordPress vacío recién instalado | **Aquí se construye.** Se desinstala WordPress primero |
| `industech.me` | Tu dominio, INDUSTECH SOLUTIONS | — |
| `industec.me` | El del cliente, en modo manual | Destino final, cuando esté probado |
| `supayerp.com` | — | — |

Cuando la app esté verificada, va a un subdominio estable —`ot.industec.me` o
similar—, no a un `hostingersite.com` temporal que puede cambiar.

> **Un punto de continuidad que conviene hablar con César.** El sistema
> operativo de INDUSTEC corre hoy en la cuenta de Hostinger de INDUSTECH
> (`slurmfood@gmail.com`). El plan dice que lo entregado queda funcionando y en
> poder de INDUSTEC al cerrar cada fase. Conviene definir, antes de que la app
> nueva sea la operación real, si el hosting se traslada a una cuenta de
> INDUSTEC o se queda donde está y bajo qué acuerdo.

---

## 6. Con qué se construye, y por qué

| Pieza | Elección | Por qué esa |
|---|---|---|
| Servidor | **PHP 8.3 + PDO** | Es lo único que corre en el compartido. Python necesita root, y el compartido no lo da |
| Base operativa | **MySQL de Hostinger** | 3 GB por base; sobra |
| Cliente | **HTML + JavaScript sin framework** | Sin `npm build`, sin `node_modules`. Lo mantiene un agente: tiene que caber en una lectura |
| Offline | **Service Worker + IndexedDB** | Estándar del navegador, cero dependencias, cero costo |
| PDF | **dompdf** | Ya está en el servidor y ya produce el formato que KFC conoce |
| Correo | **PHPMailer + cola** | Ya está. Lo que cambia es que se encola en vez de enviarse sincrónico |

**Costo incremental: US$ 0.** Todo es libre o ya está pagado.

Lo que se descarta: React o Vue (una cadena de compilación que mantener a cambio
de nada), un framework PHP (inodos y complejidad sin retorno), y Node.js (el
plan Premium no lo trae — el panel mismo lo dice: *Web Apps* solo desde
Business).

---

## 7. Orden de construcción

Cada etapa deja algo funcionando y verificable. No se pasa a la siguiente sin
que la anterior esté probada.

| # | Etapa | Cuándo está lista |
|---|---|---|
| **1** | Esquema en MySQL + catálogos cargados | Los 1.173 activos y 100 locales se consultan desde el servidor |
| **2** | API de catálogos y validación | Las mismas 17 reglas del módulo Python, respondiendo por HTTP, con el mismo fixture |
| **3** | Formulario único, en línea | Una orden de cada tipo produce el mismo nombre y la misma estructura |
| **4** | Subida de fotos por separado | Una orden con 35 fotos entra sin acercarse a ningún límite |
| **5** | Offline y cola de envío | Se llena una orden en modo avión y se envía sola al recuperar señal |
| **6** | PDF y cola de correo | El PDF sale igual que el de hoy; con el SMTP caído la orden igual queda guardada |
| **7** | Autenticación por técnico | Cada orden queda ligada a quién la emitió |
| **8** | **Piloto en UIO, 48 h** | Mismo PDF, mismos correos, y además fila en base |
| **9** | LARB, CNLJ y preventivo | 48 h limpias en cada uno |
| **10** | Gestión | La administración arma el plan del día sin abrir un correo |

Los formularios viejos **no se apagan el mismo día**: quedan sirviendo un aviso
con el enlace nuevo hasta confirmar que ningún técnico tiene la URL vieja
guardada en el celular.

---

## 8. Lo que hace falta decidir antes de la etapa 7

| Pregunta | Por qué bloquea |
|---|---|
| ¿El técnico entra con usuario propio o con un código de zona? | Usuario propio da trazabilidad real y hace posible medir carga por persona. Un código de zona es más cómodo pero no distingue quién hizo qué |
| La lista de técnicos vigentes | El desplegable no puede armarse sin ella. El padrón está listo para que la marquen |
| ¿Los clientes no-KFC entran a la misma base? | Define si `cliente` es un campo o una instalación aparte |
| ¿A qué subdominio va la app? | `ot.industec.me` es lo natural, pero el dominio es del cliente |
