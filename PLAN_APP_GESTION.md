# App de gestión de INDUSTEC — roles, módulos y orden de construcción

**Fecha:** 2026-09-08 · **Decide:** Andrés Basantes · **Estado:** **ejecutado** (T2.12 a T2.14, del 2026-09-09 al 2026-09-13; es el diseño de referencia, no una propuesta). Lo que dice aquí «sin desplegar», «propuesta» o «HTTP 200 sin autenticación» describe el 2026-09-08: el PDF y los JSON cortan con 403 desde ese mismo día, y todo lo de abajo está en el sitio de pruebas. Lo vigente: `ESTADO.md` §1b y `PLAN_INDUSTEC.md` §T2.14 (decisiones D1–D16). El paquete del piloto: `desarrollo/sistema_ots/piloto/`.
**Complementa:** [`PLAN_INDUSTEC.md`](PLAN_INDUSTEC.md) (las definiciones de KPI ya cerradas en §T2.2 se reusan, no se redefinen) y [`ARQUITECTURA_SISTEMA_OTS.md`](ARQUITECTURA_SISTEMA_OTS.md).

---

## 1. Dónde vive cada cosa

> **La decisión de estructura y datos personales está en
> [`DECISION_ARQUITECTURA_Y_DATOS.md`](DECISION_ARQUITECTURA_Y_DATOS.md)**, que
> cruza esto con la LOPDP y manda sobre esta sección. Lo de aquí es el resumen
> operativo.
>
> **Corregido el 2026-09-08.** Una versión previa de este documento ponía la
> base y la administración en la estación. Se revisó y quedó al revés para la
> parte operativa, porque los jefes técnicos están en Ambato y Cuenca: con la
> base en la estación necesitaban VPN y dependían de que este computador
> estuviera encendido.

**La base operativa vive en Hostinger; la memoria completa y el análisis, en la
estación.** Es la división que ya tenía [`ARQUITECTURA_SISTEMA_OTS.md`](ARQUITECTURA_SISTEMA_OTS.md) §3.

| En Hostinger (MySQL) | En la estación (MariaDB) |
|---|---|
| Usuarios, roles, permisos, sesiones | Histórico completo: 7.069 órdenes |
| Casos abiertos y sus asignaciones | Cruces con SAP y auditoría de calidad |
| Cronograma vigente y sus novedades | Cálculo de KPIs y tableros pesados |
| Órdenes de los últimos 90 días | Los PDFs archivados |

**Cada tabla tiene un solo dueño.** Sin esa regla, dos bases sincronizándose
terminan siempre en conflictos que hay que resolver a mano.

**Por qué el análisis no sube:** el plan Premium corta toda consulta a los
**60 segundos**, no trae Python, y su respaldo es semanal. Los reportes sobre
7.000 órdenes no caben ahí. Lo que sí cabe —y es lo que se consulta a diario—
es la ventana operativa.

### Todos entran por el navegador

La app de administración es **web, servida por Hostinger**: no se instala nada,
funciona desde cualquier computador y se actualiza sola.

| Quién | Desde dónde | Qué usa |
|---|---|---|
| Técnico | Celular | Captura de OT y buzón de pendientes |
| Administradora | Cualquier computador | Todo, las tres zonas |
| Jefe técnico | Cualquier computador | Su zona |
| Superadministrador | Cualquier computador | Usuarios y permisos |

---

## 2. Roles

Cuatro roles. El alcance de cada uno no es una preferencia de interfaz: es una
regla que se aplica **en el servidor**, en cada consulta.

| Rol | Alcance | Para qué existe |
|---|---|---|
| **Superadministrador** | Todo, más usuarios y permisos | Usuario inicial de INDUSTECH. Crea usuarios y asigna permisos |
| **Administradora** | Las tres zonas | Gestiona los casos antes de asignarlos, tramita en SAP, arma reportes, carga preventivos |
| **Jefe técnico** | **Su zona, y solo su zona** | Procesa el buzón de su zona, asigna técnicos, carga fechas de preventivo, mira su rendimiento |
| **Técnico** | **Sus propias órdenes** | Buzón de pendientes, recordatorio de correctivos abiertos, cronograma, formulario de OT |

### Tres reglas que se fijan ahora, antes de construir

**a) El permiso se comprueba en el servidor, nunca escondiendo botones.**
Ocultar una opción del menú no protege nada: el endpoint sigue abierto para
quien conozca la URL. Es el mismo error que hoy permite que un `GET` a
`submit.php` genere una OT en blanco. Cada consulta filtra por el alcance del
usuario que la pide.

**b) Los permisos son capacidades, no roles cableados en el código.**
Un rol es un paquete de capacidades (`ver_zona`, `asignar_tecnico`,
`cerrar_caso_sap`, `cargar_cronograma`, `crear_usuario`…). Si mañana la
administradora necesita que un jefe técnico pueda cerrar casos en SAP, eso se
marca en una pantalla, no se reprograma.

**c) Un usuario nunca se borra: se desactiva.**
INDUSTEC tiene alta rotación —164 personas firmaron órdenes, 19 están
vigentes—. Borrar un usuario rompe la trazabilidad de todo lo que hizo. Se
desactiva, con fecha de salida, y su historia queda intacta.

### Una sesión por usuario

Al entrar, si ya hay una sesión activa de ese usuario en otro equipo, se
ofrecen dos caminos: **cerrar la otra sesión** o **no continuar**. Se registra
qué equipo la tenía y desde cuándo.

Esto funciona de verdad **porque la base es una sola**. Sobre un archivo
compartido en una carpeta sincronizada no sería confiable: el candado también
se sincronizaría con retraso y dos personas podrían tomarlo a la vez.

### El superadministrador es de INDUSTECH — y eso hay que resolverlo

El usuario inicial es nuestro, y con él se crean los demás. Pero la cotización
dice que **lo entregado queda funcionando y en poder de INDUSTEC** al cerrar
cada fase. Si el superadministrador se queda solo en manos de INDUSTECH,
INDUSTEC no controla su propio sistema.

Propuesta: **dos superadministradores**, uno de INDUSTECH para soporte y uno de
INDUSTEC (César), entregado al cerrar la fase. Y que el superadministrador no
se use para el trabajo diario: cada quien entra con su propio usuario, para que
la bitácora diga quién hizo qué.

---

## 3. Módulos, por rol

### Superadministrador
- **Usuarios y permisos**: crear, desactivar, asignar rol y zona, forzar cambio
  de contraseña, cerrar sesiones abiertas.
- **Bitácora**: quién hizo qué y cuándo. Sin filtro de zona.
- **Configuración**: el alcance del servicio (`alcance_trabajos.json`), metas de
  SLA, días de anticipación de las alertas.

### Administradora — las tres zonas
- **Buzón general de casos**: lo que llega del correo de SAP, con las alertas de
  alcance ya marcadas. Es el módulo de "gestión de OTs antes de asignar".
  Acciones: derivar a la zona, marcar para cerrar en SAP, registrar el veredicto.
- **Pendientes**: todo lo abierto de las tres zonas, por antigüedad.
- **Regularización**: las órdenes que nacieron sin aviso y esperan que se cree
  el caso en SAP. Bloquean repuestos y cierre hasta resolverse.
- **Cronograma de preventivos**: las tres zonas, con las novedades que explican
  las demoras ante KFC.
- **Reportes**: diario, mensual y el de Grupo KFC (T2.2).
- **Maestros**: locales, técnicos, equipos.

### Jefe técnico — su zona
- **Buzón de la zona**: los casos que la administradora derivó, pendientes de
  procesar. Puede marcarlos **"en revisión"** para que ella los vea en sus
  pendientes y los cierre en SAP si no nos competen.
- **Asignación**: repartir casos entre los técnicos de su zona.
- **Cronograma de su zona**: cargar fechas, confirmar kit, reagendar con motivo.
- **Tablero de la zona** (§4).

### Técnico — sus órdenes (en el celular)
- **Buzón de pendientes**: sus casos asignados, con el más urgente arriba.
- **Recordatorio de correctivos abiertos**: la franja fija que no estorba y no
  se puede descartar; se apaga cuando emite la OT de cierre.
- **Cronograma**: los preventivos que le tocan.
- **Formulario de OT**: lo ya construido.

---

## 4. Los tableros: qué medir

Se reusan las definiciones ya cerradas en `PLAN_INDUSTEC.md` §T2.2. No se
inventan métricas nuevas donde ya hay una decidida.

**Lo que pediste, más lo que aporta:**

| Indicador | Por qué este y no un conteo simple |
|---|---|
| **Casos abiertos por antigüedad** | Un número solo no dice nada. Se agrupa con la semaforización que INDUSTEC ya usa: ≥3 días, 2 días, <2 días |
| **Cerrados en la semana** | Tal como lo pediste, contra la semana anterior para ver tendencia |
| **Tiempo hasta primera atención** y **hasta cierre** | Son los dos relojes de SLA ya definidos. Separados, porque miden cosas distintas |
| **Backlog en semanas de trabajo** | Ya definido: horas-hombre pendientes ÷ capacidad real de la zona. Nunca conteo de tickets |
| **Cumplimiento del preventivo** | Ya definido, con 10% de tolerancia. Contra el **plan original**, no contra el reagendado |
| **% de casos que no nos competían** | Mide cuánto trabajo administrativo genera KFC al asignar mal. Hoy `Mant. Constructivo` fueron 1.477 casos en 8 meses |
| **Órdenes pendientes de regularizar** | Las que nacieron sin aviso. Es dinero sin facturar |
| **Reincidencia por equipo** | El mismo activo que falla otra vez. Es la entrada del analista de confiabilidad (T3.2) |

**Regla que se mantiene:** ningún indicador se publica sin la verificación
cruzada de I-10. Si el total no cuadra contra una fuente independiente, se
aborta y se deja el desfase registrado.

---

## 5. Seguridad, fijada antes de construir

| Regla | Por qué |
|---|---|
| Contraseñas con hash (bcrypt o argon2), nunca reversibles | El sistema actual guarda la clave del SMTP en texto plano, en cinco copias dentro del docroot |
| Cambio de contraseña obligatorio en el primer ingreso | El usuario inicial lo crea otra persona |
| Cierre de sesión por inactividad | Los portátiles se prestan |
| Bitácora de toda decisión: asignar, reagendar, cerrar como "no compete", cambiar permisos | Es lo que protege a INDUSTEC si KFC discute algo, y lo que permite después analizar patrones |
| **La estación consulta Hostinger por HTTPS con token, nunca por MySQL remoto** | Abrir Remote MySQL a `%` sería exponer el puerto 3306 al mundo con las credenciales viajando por internet. Ya se descartó en la auditoría, y los jefes tienen IP residencial dinámica, así que filtrar por IP tampoco sirve |
| Todo por HTTPS, con cookies `Secure`, `HttpOnly` y `SameSite` | La sesión viaja por internet, no por una red local |

**Un pendiente que este cambio hace más urgente:** con la base operativa en
Hostinger, la exposición de datos personales sube. Los PDFs con firma manuscrita
de empleados de KFC **siguen respondiendo HTTP 200 sin autenticación** y el
nombre es adivinable. El parche `.htaccess` está escrito y sin desplegar. Esto
se cierra antes de poner un solo usuario real en el sistema.

---

## 6. Orden de construcción

Cada etapa deja algo usable. No se pasa a la siguiente sin que la anterior esté
probada.

| # | Etapa | Queda listo cuando |
|---|---|---|
| **1** | Usuarios, roles, permisos y sesión única | Cuatro roles entran, cada uno ve solo su alcance, y una segunda sesión ofrece cerrar la primera |
| **2** | Buzón de casos + triage (lo del correo de SAP) | La administradora ve los 918 casos con sus alertas y registra veredicto |
| **3** | Derivación a zona y asignación a técnico | El jefe técnico recibe lo de su zona y asigna |
| **4** | Cronograma de preventivos, con novedades | Se carga una fecha, se reagenda con motivo y queda la bitácora para KFC |
| **5** | Buzón del técnico y recordatorios | El técnico ve sus pendientes en el celular y la alerta se apaga al cerrar |
| **6** | Tableros de zona y general | Los indicadores de §4, con su verificación cruzada |
| **7** | Reportes (T2.2) | Diario, mensual y el de KFC, sobre las plantillas reales |

**Lo que ya está hecho y entra aquí:** el lector del buzón de SAP, el filtro de
alcance, el formulario de captura y el cronograma convertido a fechas reales.

---

## 7. Lo que hace falta decidir

| # | Pregunta | Bloquea |
|---|---|---|
| 1 | **¿Quién es el superadministrador de INDUSTEC?** ¿César, o alguien más? | La entrega del sistema al cliente |
| 2 | **¿La administradora puede asignar técnicos, o eso es solo del jefe de zona?** | El paquete de capacidades de cada rol |
| 3 | **¿Los jefes técnicos ven las otras zonas en modo lectura**, o nada fuera de la suya? | El filtro de alcance |
| 4 | **¿Cuántos usuarios habrá?** Hoy hay 19 técnicos, 3 jefes y 1 administradora | Dimensiona la VPN y las licencias (todo gratuito hasta ~100 equipos) |
| 5 | **El alcance total es mayor que las 3 fases de un mes de la cotización C26-115** | Hay que acordar si esto entra como ampliación o se prioriza dentro de lo contratado |
