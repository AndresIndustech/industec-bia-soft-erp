# Cómo debe funcionar el sistema — decisión de estructura y datos personales

**Fecha:** 2026-09-08 · **Decide:** Andrés Basantes · **Estado:** propuesta
**Reemplaza** las decisiones de ubicación de datos de [`PLAN_APP_GESTION.md`](PLAN_APP_GESTION.md) §1
y ordena lo investigado en [`SISTEMA_COMPLETO.md` §5b](SISTEMA_COMPLETO.md).

> **Esto no es asesoría legal.** El análisis se apoya en la investigación de
> §5b, que leyó la LOPDP y su Reglamento en fuente oficial pero **no pasó
> verificación adversarial**. Antes de firmar nada, un abogado ecuatoriano de la
> materia tiene que confirmarlo.

---

## 1. La respuesta, en una línea

**La base operativa va en Hostinger, con los datos personales reducidos al
mínimo y de vida corta; el archivo completo se queda en la estación; y todos
entran por navegador.**

No es un punto medio de compromiso: es la única forma que cumple las dos cosas
a la vez.

La cuenta de hosting **se mantiene bajo INDUSTECH** por decisión del 2026-09-08.
Eso deja un riesgo legal abierto que **no se resuelve con arquitectura sino con
contrato** — está en la medida 1 de §4.2.

---

## 2. El hecho que ordena todo el análisis

Se venía discutiendo "nube sí o nube no" como si eso decidiera la exposición.
**No la decide.**

Cada orden lleva **nombre, correo y firma manuscrita de un empleado de Grupo
KFC**. El técnico la llena en el celular, parado en la cocina del local, y la
envía a Hostinger. **Ese dato personal entra a la nube en el segundo uno**, sin
importar dónde esté la base de gestión.

Guardar la base de administración en la estación no evita nada de eso: solo
parte la operación en dos y agrega VPN, dependencia de que el equipo esté
encendido, y una segunda copia del mismo dato personal.

**La pregunta correcta no es dónde vive el dato, sino cuánto dato personal vive,
por cuánto tiempo, quién puede alcanzarlo y quién responde por él.**

---

## 3. Lo que obliga la ley — lo que aplica a esta decisión

De la investigación de §5b, los cuatro puntos que cambian el diseño:

| # | Norma | Qué obliga aquí |
|---|---|---|
| 1 | **Art. 43 del Reglamento** | Un encargado que *"determine los fines y los medios"* pasa a ser **responsable**. **Elegir dónde alojar es determinar un medio.** Hoy el hosting está en una cuenta personal de INDUSTECH |
| 2 | **Art. 34 LOPDP + Art. 41 Reglamento** | El encargo tiene que constar **por escrito**, con 7 contenidos mínimos. Y prohíbe comunicar datos a terceros *"ni siquiera para su conservación"* sin ese contrato — que es lo que pasa al alojar en Hostinger sin él |
| 3 | **Art. 67.2 y 68.1** | Proteger **desde el diseño y por defecto**, con medidas técnicas suficientes. Es exactamente por lo que sancionaron a LigaPro: una app con identificadores adivinables. Los ~1.950 PDFs de este proyecto tienen el mismo patrón |
| 4 | **Principio de minimización** | Solo el dato necesario, el tiempo necesario |

**La exposición está verificada, no es hipotética:** al 2026-09-06 un PDF con
firma manuscrita responde HTTP 200 sin autenticación, y el nombre se puede
adivinar.

---

## 4. La estructura recomendada

### 4.1 Dónde vive cada dato

| | Hostinger (MySQL + archivos) | Estación (MariaDB + disco) |
|---|---|---|
| Usuarios, roles, permisos, sesiones | ✅ | — |
| Casos abiertos, asignaciones, cronograma | ✅ | copia de análisis |
| Órdenes de los **últimos 90 días** | ✅ | ✅ |
| **Firma, nombre y correo del administrador** | ✅ **solo mientras la orden está en la ventana** | ✅ dentro del PDF |
| Histórico completo (7.069 órdenes) y PDFs | ❌ **se purga** | ✅ |
| Cruces con SAP, KPIs, reportes | ❌ (corte de 60 s, sin Python) | ✅ |

**Cada tabla tiene un solo dueño.** Sin esa regla, dos bases que se sincronizan
terminan siempre en conflictos que alguien resuelve a mano.

### 4.2 Las seis medidas que vuelven esto defendible

**1. El hosting se queda en INDUSTECH — y eso hay que compensarlo en el contrato.**

> **Decisión de Andrés, 2026-09-08.** Se evaluó pasar la cuenta a nombre de
> INDUSTEC y **se descartó**: INDUSTEC no quiere asumir responsabilidades
> adicionales. El hosting sigue bajo INDUSTECH.

Consecuencia que hay que asumir con los ojos abiertos: el **Art. 43 del
Reglamento** dice que un encargado que *"determine los fines y los medios"* pasa
a ser considerado **responsable** respecto de esa parte, y elegir dónde se alojan
los datos es determinar un medio. **INDUSTECH puede ser tratada como responsable,
no como simple proveedor**, con su propio catálogo de infracciones y su propia
multa sobre su propio volumen de negocio.

Como no se cambia la cuenta, la mitigación va por dos vías:

- **Que el contrato de encargo diga expresamente que INDUSTEC instruye o aprueba
  el uso de ese hosting.** Si la elección del medio consta como instrucción del
  responsable, se debilita la lectura de que INDUSTECH la determinó por su
  cuenta. Es el punto donde más se juega el encuadre: que lo redacte el abogado.
- **Que INDUSTECH tenga su propia casa en orden**, porque si la tratan como
  responsable se le exige como tal: delegado de protección de datos inscrito y
  **registro de actividades de tratamiento** que cubra a todos sus clientes, no
  solo a INDUSTEC. El trámite del delegado ya está encargado — ver
  [`BRIEF_DPD_INDUSTECH.md`](BRIEF_DPD_INDUSTECH.md).

**2. Contrato de encargo por escrito, con los 7 contenidos del Art. 41.**
No tenerlo es infracción **grave del responsable** (Art. 68.9). Y sin él,
INDUSTECH no puede legalmente conservar los datos en Hostinger: el Art. 34
prohíbe comunicarlos a terceros *"ni siquiera para su conservación"* sin
contrato. Con la cuenta quedándose en INDUSTECH, este contrato pasa de
importante a **imprescindible**.

**3. La firma deja de existir como archivo suelto.**
Hoy viaja como PNG y se guarda aparte. **Una vez generado el PDF, el PNG se
borra**: la firma ya vive dentro del documento, que es el registro legal. Un
archivo menos que proteger, y minimización real.
*(Queda sin resolver si una firma manuscrita es dato biométrico —el Art. 4 LOPDP
menciona "conductas" pero ninguna resolución de la SPDP lo confirma—. Si lo
fuera, sube a categoría especial del Art. 26. Se diseña como si lo fuera.)*

**4. Ningún archivo con dato personal se sirve por URL adivinable.**
Los PDFs y las fotos salen **solo** por un endpoint autenticado que comprueba el
permiso y **registra quién descargó qué**. El nombre físico deja de ser
`OT-1451-G025-...` y pasa a ser un identificador aleatorio. Esto es literalmente
la conducta por la que se sancionó a LigaPro.

**5. Purga a los 90 días, con las cuatro compuertas ya escritas.**
Lo que sale de la ventana operativa se borra de Hostinger, y solo después de que
la estación tenga la copia verificada por hash. El script ya existe
(`t2_4_purga_hostinger.py`): simula por defecto y sin copia verificada no borra
ni forzándolo. **Menos dato en la nube, menos superficie.**

**6. Traza de acceso en las tres superficies.**
Hoy nadie registra quién leyó o descargó qué. Sin eso no se puede responder ante
un reclamo ni detectar un uso indebido. Va en la misma bitácora que ya existe.

### 4.3 Todos entran por el navegador

La app de administración es **web, servida por Hostinger**. Nadie instala nada,
funciona desde cualquier computador y se actualiza sola. Los jefes técnicos
están en Ambato y Cuenca: cualquier cosa que exija VPN o instalación en cada
máquina se degrada sola con el tiempo.

**El control de acceso no se hace escondiendo botones.** Cada consulta filtra
por el alcance del usuario **en el servidor**. Ocultar una opción del menú deja
el endpoint abierto a quien conozca la URL — es el mismo error por el que hoy un
`GET` a `submit.php` genera una OT en blanco y la manda a KFC.

---

## 5. Por qué se descartan las otras opciones

| Opción | Por qué no |
|---|---|
| **Todo en la estación, jefes por VPN** | No evita la exposición: la firma entra a la nube igual, en el envío del técnico. Y agrega VPN, dependencia de un equipo encendido y una copia más del mismo dato personal |
| **Base de datos en carpeta de Google Drive** | Drive sincroniza archivos completos, no transacciones. Dos escritores producen un "archivo en conflicto" y **se pierden escrituras sin aviso**. Los bloqueos no cruzan entre equipos. El candado de sesión también se sincronizaría con retraso |
| **Todo en Hostinger, apagar la estación** | El plan Premium corta las consultas a los **60 segundos**, no trae Python y respalda **semanalmente**. Los reportes sobre 7.000 órdenes no caben, y el histórico completo quedaría con una sola copia y respaldo semanal |
| **Contratar un VPS** | US$ 6,49–11,99 al mes contra la premisa de costo cero, y no resuelve nada que esta estructura no resuelva |

---

## 6. Qué cambia respecto a lo que veníamos armando

| Antes | Ahora | Por qué |
|---|---|---|
| App instalable en cada equipo | **App web** | Sin instalación, sin VPN, funciona desde cualquier ciudad |
| Base en la estación | **Base operativa en Hostinger** | Los jefes están en tres ciudades y el técnico necesita 24/7 |
| Hosting bajo INDUSTECH | **Sigue en INDUSTECH** (decisión del 08-sep) | Se compensa en el contrato de encargo, no cambiando la cuenta |
| La firma se guarda como PNG | **Se borra al generar el PDF** | Minimización |
| PDFs por URL directa | **Endpoint autenticado con traza** | Es la conducta sancionada a LigaPro |

Lo construido no se pierde: el formulario de captura, el lector del buzón, el
filtro de alcance y el cronograma son PHP y JavaScript sin framework, que es
exactamente lo que corre en Hostinger.

---

## 7. Orden de ejecución

**Lo legal y lo urgente van primero, porque hoy hay exposición verificada.**

| # | Qué | Depende de |
|---|---|---|
| **0a** | **Cerrar la exposición de los PDFs** (`.htaccess`, sube por hPanel sin SSH) | Tu aprobación. Es de hoy |
| **0b** | **Inscribir el delegado de protección de datos de INDUSTECH** — el plazo venció hace 8 meses. **Encargado a otra IA** con [`BRIEF_DPD_INDUSTECH.md`](BRIEF_DPD_INDUSTECH.md); guía de referencia en [`TRAMITE_DELEGADO_DATOS.md`](TRAMITE_DELEGADO_DATOS.md) | Nada. Es gratis y en línea |
| **0c** | **Contrato de encargo INDUSTEC ↔ INDUSTECH**, que además debe dejar constancia de que INDUSTEC aprueba el uso de este hosting | Conversación con César y revisión de un abogado |
| **1** | Usuarios, roles, permisos y sesión única | Migración en Hostinger |
| **2** | Buzón de casos con el filtro de alcance | Etapa 1 |
| **3** | Derivación a zona y asignación a técnico | Etapa 2 |
| **4** | Cronograma de preventivos con novedades | Etapa 1 |
| **5** | Buzón del técnico y recordatorios | Etapa 3 |
| **6** | Endpoint autenticado de PDFs + traza de acceso + purga a 90 días | Etapa 1 |
| **7** | Tableros y reportes | Etapas 2–4 |

Las etapas 0a, 0b y 0c **no dependen de programar nada** y son las que más
reducen riesgo. Conviene arrancarlas en paralelo.

---

## 8. Lo que no puedo decidir yo

| # | Decisión | Por qué es tuya |
|---|---|---|
| 1 | ~~Pasar la cuenta de Hostinger a INDUSTEC~~ — **decidido el 08-sep: se queda en INDUSTECH** | Se compensa por contrato (medida 1) |
| 2 | **Firmar el contrato de encargo**, con la cláusula de que INDUSTEC aprueba el hosting | Necesita abogado, y define responsabilidades entre las dos empresas. Con la cuenta en INDUSTECH es imprescindible, no opcional |
| 3 | **Si se contrata el respaldo diario de Hostinger** | Tiene costo, contra la premisa de costo cero. Hoy el respaldo es semanal |
| 4 | **Confirmar el análisis legal con un abogado ecuatoriano** | La investigación es seria pero no pasó verificación adversarial |
| 5 | **Quién es el superadministrador de INDUSTEC** | Define a quién se entrega el control del sistema |
