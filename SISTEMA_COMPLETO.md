# El sistema completo, de punta a punta

**Fecha:** 2026-09-06 · **Estado:** concepción vigente
**Sustituye como documento rector a:** [`DISENO_APP_OTS.md`](DISENO_APP_OTS.md), que queda como detalle de la app.
**Se apoya en:** [`ESPECIFICACION_OT_UNICA.md`](ESPECIFICACION_OT_UNICA.md) · [`ARQUITECTURA_SISTEMA_OTS.md`](ARQUITECTURA_SISTEMA_OTS.md)

---

## 1. El error de fondo del sistema actual

Todo lo que falla hoy sale de una sola decisión de diseño:

> **El documento ES el registro.**

Si el PDF no se genera y no sale por correo en ese instante, la orden no existe.
De ahí se desprende, en cadena, todo lo demás:

| Consecuencia | Lo que provoca |
|---|---|
| Si falla el envío, no hay nada | El administrador ya se fue y **no firma** |
| El técnico vuelve después | Ya no puede tomar las fotos del equipo |
| Llena de memoria, horas más tarde | **739 órdenes sin descripción del trabajo**, 364 con hora de fin anterior al inicio |
| Se corta la conexión a mitad | El formulario se reinicia y **se pierde todo lo escrito** |
| Reintenta con lo que recuerda | Información incompleta o que no corresponde |

**No es descuido del técnico. El sistema lo obliga a inventar.** Esa es la causa
raíz de la mala calidad del dato, y ningún catálogo ni validación la arregla
mientras el envío siga siendo el momento de la verdad.

### La reconcepción, en una línea

> **La orden es el registro. El documento es una representación. El archivo es una consecuencia.**

La orden nace y queda a salvo **en el teléfono, en el momento de la firma**.
Todo lo demás —subir, generar el PDF, mandar correos, archivar, cruzar con SAP,
reportar— pasa después y puede reintentarse sin que nadie pierda nada.

---

## 2. El recorrido de una orden, de principio a fin

```
  ┌── EL TELÉFONO DEL TÉCNICO ───────────────────────────────────────┐
  │  1. Recibe el caso asignado (o lo crea)                          │
  │     → NACE EL uuid DE LA ORDEN, aquí, en el teléfono             │
  │  2. Llena la orden — SIN SEÑAL, todo local                       │
  │  3. Fotos: se comprimen a WebP al tomarlas, con su sha256        │
  │  4. VALIDA EN EL TELÉFONO. Si algo bloquea, se corrige AHORA     │
  │  5. El administrador FIRMA en pantalla                           │
  │  6. La orden queda GUARDADA Y CERRADA localmente  ← a salvo      │
  └──────────────────────────────┬───────────────────────────────────┘
                                 │  cuando hay señal, solo:
                                 ▼
  ┌── HOSTINGER · ventana operativa ─────────────────────────────────┐
  │  7. Sube foto por foto (56 KB c/u) y luego la orden (5 KB)       │
  │  8. ¿Ya existe ese uuid? → devuelve el número y NO hace nada más │
  │  9. Revalida. Si algo falla, ACEPTA y marca — nunca rechaza      │
  │ 10. Reserva el correlativo (transacción atómica)                 │
  │ 11. Escribe la fila       ← la orden ya existe en la empresa     │
  │ 12. Responde al teléfono YA. El PDF y los correos van en cola    │
  └──────────────────────────────┬───────────────────────────────────┘
                                 │  cada noche (aún no programado)
                                 ▼
  ┌── LA ESTACIÓN · memoria completa ────────────────────────────────┐
  │ 13. Baja las FILAS y los ARCHIVOS, y verifica por SHA-256        │
  │ 14. Archiva en D:\RESPALDOS con el nombre canónico               │
  │ 15. Ingesta a MariaDB y cruza con SAP                            │
  │ 16. Recalcula planes y publica en SALIDAS IA\OTS                 │
  │ 17. Deja el informe de purga para revisión                       │
  └──────────────────────────────┬───────────────────────────────────┘
                                 ▼
        INDUSTEC ve el catálogo · KFC recibe sus reportes
```

**El punto 6 es el corazón de todo.** A partir de ahí la orden existe, aunque el
teléfono se quede sin batería, se apague o pase tres días sin señal.

Los tres puntos que lo sostienen, y que la primera versión de este documento no
tenía:

### El `uuid` nace en el teléfono (punto 1)

Sin un identificador propio, un reintento tras una respuesta perdida crea una
**segunda orden**: otro correlativo, otro PDF y **otro correo a Grupo KFC por el
mismo trabajo**. Y la respuesta se pierde a menudo, porque justo ahí es donde la
señal es mala.

El teléfono genera un `uuid` al crear la orden. En el servidor es `UNIQUE KEY`.
Reintentar es idempotente: si el `uuid` ya existe, el servidor devuelve el número
que ya asignó y no escribe nada. El técnico puede reintentar cien veces.

### Valida el teléfono; el servidor acepta y marca (puntos 4 y 9)

La primera versión decía que el servidor "revalida todo desde cero", sin decir
qué pasa si algo falla. Y falla en casos reales: el técnico fue dado de baja el
martes por una orden que firmó el lunes, el activo se reasignó, el reloj del
teléfono está adelantado.

**Rechazar una orden ya firmada recrea exactamente el problema que este sistema
existe para eliminar.** Así que:

| Momento | Quién decide | Qué hace |
|---|---|---|
| **Antes de la firma**, en el teléfono | Las reglas `BLOQUEA` | Impide firmar hasta corregir |
| **Después de la firma**, en el servidor | Las mismas reglas | **Acepta siempre.** Lo que falle queda marcado para revisión |

El servidor sigue siendo la autoridad —nunca confía en el cliente— pero su
autoridad se ejerce **marcando**, no descartando. Una orden firmada no se pierde
nunca por una regla.

Y **revalida contra el catálogo vigente en la fecha de atención**, no contra el
de hoy: un técnico dado de baja el martes era técnico el lunes.

### El PDF no se hace esperando al técnico (punto 12)

Armar un PDF con 31 fotos toma tiempo, y hacerlo dentro del clic de "enviar"
deja al técnico mirando una pantalla en un sótano con mala señal — justo donde
la conexión se corta y dispara el reintento. El servidor responde en cuanto la
fila está escrita; el PDF y los correos se generan en cola.

> El techo de PHP en el plan Premium es de **360 s** de ejecución, no de 60 s
> (los 60 s son el corte por consulta a MySQL). Aun así, el técnico no debe
> esperar por algo que no necesita ver.

**El punto 14 se simplifica solo.** Hoy la normalización tiene que adivinar el
local desde 158 grafías; con el formato único el nombre canónico lo construye el
servidor desde campos ya validados. La normalización queda **solo para el
histórico**, no para lo nuevo.

---

## 3. Quién ve qué

Cuatro roles. Cada uno ve **lo suyo**, no todo con permisos encima.

### Técnico — el teléfono

| Pantalla | Qué hace |
|---|---|
| **Mis pendientes** | Los casos que su jefe le asignó. Hoy esto no existe: se enteran por llamada o WhatsApp |
| **Nueva orden** | Desde un caso asignado (llega con local, aviso y equipo ya puestos) o libre |
| **Por enviar** | La cola. Cuántas hay, cuánto pesan, cuándo se intentó |
| **Mis órdenes** | Lo que hizo, con su PDF. Puede reenviarlo sin pedírselo a nadie |

Lo que **no** hace: escribir un local, un equipo o un técnico a mano. Todo sale
de listas cerradas que trae descargadas.

#### Las listas cerradas necesitan salida de emergencia

Una lista cerrada sin escape convierte cada hueco del catálogo en **una orden que
no se puede emitir**, y eso es peor que el sistema actual. Los casos son reales y
conocidos: un local nuevo que KFC abrió esta semana, un activo que se trasladó de
sitio, uno de los **6 locales sin activos catalogados** en SAP, o el catálogo del
teléfono desactualizado porque la estación estuvo apagada.

La salida es *"no está en la lista"*: el técnico escribe qué es, **la orden se
emite igual**, y queda marcada para que alguien complete el catálogo. Se registra
cuántas veces se usa: si sube, el catálogo se está quedando viejo.

> Esto no reabre el texto libre. La diferencia está en que es **una excepción
> declarada y contada**, no el camino normal. Hoy el 100% de los locales se
> escribe a mano; ahí queremos que sea una fracción medida y decreciente.

#### Cuánto tarda llenar una orden: nadie lo midió

El proyecto midió los kilobytes con rigor —201 KB por foto, 2,87 MB por orden— y
**no midió un solo segundo del trabajo de la persona que va a usar esto**. El
formato único le pide al técnico más campos que hoy: la casilla de repuesto, el
día de intervención, elegir el activo en vez de teclearlo.

El invariante de despliegue dice que *el formulario debe seguir viéndose y
usándose igual para el técnico*. Antes del piloto hay que cronometrar una orden
correctiva y una preventiva, en el sistema viejo y en el nuevo, con un técnico
real. Si el nuevo tarda más, se ajusta antes de desplegar — no después.

### Jefe de zona — el teléfono o el navegador

| Pantalla | Qué hace |
|---|---|
| **Casos de la zona** | Lo abierto según SAP, con su antigüedad y semáforo |
| **Asignar** | Reparte casos entre sus técnicos |
| **Carga por técnico** | Cuántos casos abiertos tiene cada uno. Hoy **no se puede saber**: 1.113 órdenes llevan dos técnicos en un campo de texto |
| **Revisar** | Devuelve una orden mal hecha antes de que llegue a KFC |

### Administración — el navegador

| Pantalla | Qué hace |
|---|---|
| **Plan del día** | Por zona, listo, sin abrir un correo |
| **Backlog** | Por `estatus_general` de SAP, nunca por la señal interna |
| **Buscar** | Cualquier orden, por local, aviso, fecha o técnico |
| **Calidad** | Lo que el auditor marcó, con veredicto editable |
| **Reenviar** | El PDF a quien haga falta |

### Gerencia — Telegram

Consulta desde el celular, sin abrir nada. Es el rol de la Fase 3 y ya está
contemplado; la base y el catálogo que necesita ya existen.

### El quinto interesado: Grupo KFC

Los cuatro roles de arriba son todos de puertas adentro, y eso es un sesgo del
diseño. **El cliente del cliente no aparece en ningún lado**, y sin embargo:

- **Un empleado de KFC firma cada orden.** El documento que firma es a la vez
  informe de trabajo y encuesta de satisfacción. Cambiar el formulario **cambia
  lo que esa persona firma**, y nadie de KFC lo ha visto.
- El criterio de aceptación *"el mismo PDF que hoy"* es **incompatible con el
  formato único**: si se unifican tres formatos en uno, el PDF necesariamente
  cambia. Hay que elegir: o el PDF cambia y KFC lo aprueba antes, o se conserva
  la maqueta exacta y la unificación es solo interna.
- El entregable contratado de la Fase 2 es el **reporte para Grupo KFC** (T2.2.3),
  y el orden de construcción de la sección 9 no lo incluye. Un sistema de
  captura impecable que no produce el reporte contratado no cumple la fase.
- **El éxito medido en "validez estructural" no le dice nada a KFC.** A KFC le
  habla el cumplimiento de tiempos de atención y el estado real de su backlog.
  Esa es la métrica que hay que poder producir.

**Antes del piloto**: mostrarle a KFC cómo queda el documento que sus
administradores van a firmar. No es un permiso técnico; es no cambiarle un
formulario al cliente del que dependen el 95% de los ingresos sin avisarle.

---

## 3b. Lo que la orden lleva dentro, y que el esquema todavía no sostiene

Tres cosas que el diseño da por hechas y **no existen en la base**. Sin ellas, la
frase "el PDF es una representación regenerable desde la fila" es **falsa**.

### La firma

Hoy `ots.firma_presente` es un **booleano**. La imagen de la firma solo existe
**dentro del PDF**. Es decir: si el PDF se purga del servidor a los 30 días y hay
que regenerarlo desde la fila, sale **sin la firma** — sin lo único que prueba
que el administrador del local aceptó el trabajo.

Hace falta guardarla como dato: la imagen, su `sha256`, quién firmó, cuándo, y
**atada al contenido de la orden** en ese instante. Sin esa atadura, una firma es
un PNG que se puede copiar de otra orden — y hay 7.069 firmas ya archivadas de
donde copiarla.

### Las fotos

`ot_fotos` existe, pero su propio comentario dice *"Referencia a fotos
incrustadas en el PDF"*: no tiene ruta, ni hash, ni tamaño, y su clave foránea
apunta a `ot_equipos`, que **todavía no existe** cuando la foto se sube.

En el flujo nuevo las fotos **existen antes que la orden**. Necesitan tabla
propia con `sha256`, bytes, dueño y ciclo de vida: qué pasa con las fotos de una
orden que nunca llegó, cuándo se archivan, cuándo se purgan y bajo qué compuerta.
Sin eso quedan huérfanas para siempre en el servidor.

### Los estados de una orden

Hoy solo hay `ABIERTA` y `CERRADA`. La operación real tiene más, y sin nombrarlos
no hay dónde poner los casos que ya sabemos que ocurren:

| Estado | Cuándo |
|---|---|
| `BORRADOR` | En el teléfono, sin firmar |
| `FIRMADA` | Firmada, aún sin subir. **Aquí es donde no se puede perder** |
| `RECIBIDA` | En el servidor, con número asignado |
| `OBSERVADA` | Aceptada pero marcada por una regla, o devuelta por el jefe de zona |
| `ANULADA` | Emitida por error. **No se borra**: se anula con motivo y autor |
| `CORREGIDA` | Reemplazada por otra orden, que la referencia |
| `VISITA FALLIDA` | El técnico fue y no pudo trabajar. Hoy esto no se registra en ningún lado |

**Nada se borra.** Anular es un estado con motivo y responsable, no un `DELETE`.

---

## 4. Dónde vive cada dato, y quién manda

| Dato | Dueño único | Vive en | Se copia a |
|---|---|---|---|
| Catálogos (locales, activos, técnicos) | **MariaDB local** | La estación | Sube a Hostinger y al teléfono |
| Órdenes nuevas | **MySQL de Hostinger** al enviarse | Hostinger | Baja a la estación cada noche |
| Asignaciones | **MySQL de Hostinger** | Hostinger | Baja con las órdenes |
| Histórico, cruces SAP, calidad | **MariaDB local** | La estación | No sube |
| PDFs | Hostinger los genera | 90 días en Hostinger | **`D:\RESPALDOS` para siempre** |
| Avisos SAP | El export de KFC | La estación | Sube el subconjunto operativo |

**Una tabla, un escritor.** Sin esta regla, dos bases sincronizándose terminan
siempre en conflictos que hay que resolver a mano.

**`estatus_general` de SAP es el único criterio de cerrado**, en las cuatro
superficies. Cualquier señal interna se muestra como información, nunca como
criterio.

### Lo que falta para que esto funcione de verdad

Cuatro cosas que la tabla de arriba supone resueltas y no lo están:

**1. La pata que baja las FILAS no existe.** `t2_4_sync_hostinger.py` baja
archivos por SFTP; no trae filas de MySQL. Toda la reconcepción se apoya en que
la orden es una fila, y **no hay nada escrito que la traiga**. Y el delta no
puede ser por `actualizado_en`: entre que se sella el timestamp y se confirma la
transacción hay un desfase, y una fila que cae en esa rendija **no se recupera
jamás**. El delta va por un contador monótono o por marca de sincronizado.

**2. Durante la convivencia hay dos contadores vivos.** El `.txt` del sistema
viejo y la tabla `correlativos` del nuevo emiten números sobre el **mismo**
espacio `(zona, módulo, correlativo)`. La ingesta hace `upsert` por
`id_industec`, que es **sobrescribir en silencio**. Mientras convivan los dos, el
sistema nuevo tiene que arrancar su contador **por encima** del rango que el
viejo pueda alcanzar, no donde el viejo va.

**3. `ots` tiene dos escritores por columna.** La estación escribe cruces con
SAP y veredictos de calidad; el sync nocturno trae la fila de Hostinger. Si el
sync hace `upsert` de la fila entera, **pisa** lo que escribió la estación. El
sync solo puede tocar las columnas que nacen en el formulario.

**4. `asignaciones` no existe en ningún archivo SQL.** El diseño la ascendió a
dato con dueño y nunca se creó.

### El archivo canónico y el año

El año de la carpeta sale hoy del `mtime` del PDF en el servidor. Con la cola
offline eso **deja de ser la fecha de atención**: una orden firmada el 30 de
diciembre y subida el 3 de enero llevaría el PDF fechado en enero. El año tiene
que salir de `fecha_atencion`, que es un campo de la orden, no de la fecha del
archivo.

---

## 5. El archivo: qué queda, dónde y por cuánto

| Capa | Contenido | Horizonte |
|---|---|---|
| **Teléfono** | Órdenes sin enviar, y las últimas 30 enviadas | Se limpia solo |
| **Hostinger** | Ventana operativa: abierto + 90 días de PDFs | Se purga con compuerta de hash |
| **`D:\RESPALDOS\_ORIGEN_SISTEMA`** | Espejo crudo de lo que emitió el servidor | Permanente, nunca se modifica |
| **`D:\RESPALDOS\ORDENES DE TRABAJO`** | Árbol canónico, año/módulo/zona/cadena | Permanente |
| **TrueNAS** | Segunda copia de todo | Permanente |
| **`SALIDAS IA\OTS`** | Catálogo y novedades para INDUSTEC | Se regenera |
| **Drive / OneDrive** | Solo el año en curso | Purga anual |

**La regla de las dos copias** sigue rigiendo: todo dato de respaldo existe en
exactamente dos lugares —disco local y TrueNAS—. La nube es un tercer ejemplar de
conveniencia, no el respaldo.

---

## 5b. Seguridad y datos personales — lo que este sistema maneja de verdad

Cada orden lleva **nombre, correo y firma manuscrita de un empleado de Grupo
KFC**. Eso son datos personales de terceros, tratados por INDUSTEC, alojados en
una cuenta de hosting de INDUSTECH. La **LOPDP** ecuatoriana (Registro Oficial
Suplemento 459, 26-may-2021; sanciones vigentes desde el 26-may-2023, de hasta el
**1% de la facturación anual**; reglamento por Decreto Ejecutivo 904) aplica, y
ninguno de los documentos del proyecto la nombraba.

No es un trámite: hay **exposición verificada hoy**. El 2026-09-06 comprobé que
`OT-1451-G025-10336167-UIO.pdf` responde HTTP 200 sin autenticación, y el nombre
es enumerable — correlativo de 4 dígitos, local del maestro, aviso de 8 dígitos.

### Lo que el sistema nuevo tiene que resolver, y la primera versión no decía

| Hueco | Qué hace falta |
|---|---|
| **Los PDFs siguen sin protección declarada** | En el sistema nuevo **no** se sirven por URL adivinable: se entregan tras autenticación y por identificador no enumerable |
| **La firma es un PNG que manda el cliente** | Atarla al contenido de la orden y a un `sha256`. Y decidir con INDUSTEC si tiene valor probatorio o es solo evidencia |
| **No existe la baja del técnico** | En una empresa de alta rotación, cada renuncia deja el catálogo completo de KFC —con correos— y órdenes firmadas en un teléfono **personal**. Hace falta: sesión con caducidad, revocación desde el servidor, y borrado del almacén local al cerrar sesión |
| **El catálogo lleva correos que el técnico no necesita** | El teléfono baja locales y equipos; los correos de KFC se quedan en el servidor |
| **Nadie registra quién leyó o descargó qué** | Traza de acceso en las tres superficies |
| **Reenviar el PDF a destinatario libre** | Solo a direcciones del maestro, y con traza |

### Lo que hay que decidir con INDUSTEC, no escribiendo código

Quién es **responsable** y quién **encargado** del tratamiento, si hace falta
contrato de encargo entre INDUSTECH e INDUSTEC, y qué se hace con la exposición
ya verificada. **No soy abogado y esto no es asesoría legal**: es señalar que hay
una obligación con sanción asociada que nadie ha mirado, y que conviene que la
mire quien corresponda.

---

## 6. El control de INDUSTECH — lo que Andrés necesita para operar esto

Esto es lo que no existía en ninguna concepción anterior, y sin ello el sistema
funciona pero **nadie sabe si está funcionando**.

> **Advertencia sobre el tiempo verbal.** Lo que sigue está escrito en presente
> porque describe el diseño, pero **hoy nada de esto corre solo**. Verificado el
> 2026-09-06: no hay ninguna tarea programada, ningún script escribe en la tabla
> `bitacora`, y el pipeline de las 21:30 son **seis comandos que teclea una
> persona**. Mientras eso siga así, "cada noche" significa "cuando Andrés se
> acuerde", y esa es exactamente la fragilidad que esta sección viene a resolver.

INDUSTECH presta un servicio; necesita saber que la máquina anda, qué se rompió,
qué cuesta, y poder demostrarle a INDUSTEC qué está entregando.

### 6.1 Tablero de operación — «¿esto anda?»

Una sola pantalla en la consola local que responda, sin interpretación:

| Señal | Verde cuando | Rojo dice |
|---|---|---|
| **Sincronización** | Corrió anoche y bajó todo con hash correcto | Cuántos archivos fallaron y por qué |
| **Ingesta** | Todas las órdenes nuevas entraron a la base | Cuáles no y con qué motivo |
| **Cola de envío del servidor** | Vacía o drenando | Cuántos correos llevan reintentos |
| **Órdenes atascadas en teléfonos** | Ninguna con más de 24 h | De qué técnico y desde cuándo |
| **Hostinger vs estación** | Mismo conteo de órdenes | La diferencia exacta |
| **Espacio y cuota** | Bajo los límites del plan | Cuánto falta |
| **Correo Titan** | Bajo 1.000/día | Cuántos van hoy |

La regla: **el tablero no dice "todo bien", dice el número.** "Todo bien" es lo
que decía el sistema viejo mientras perdía el 82% de sus órdenes.

### 6.2 Bitácora única de fallas

Todo lo que se rompe en cualquier pieza —la app, el servidor, el sync, la
ingesta, el auditor— escribe en **un solo lugar**, con la misma forma: qué pieza,
cuándo, qué pasaba, y si se resolvió solo. Hoy los errores están repartidos entre
cinco `error_normal.log`, la salida de los scripts y la nada.

### 6.3 Evidencia para el cliente

Es un contrato de servicio: hay que poder mostrar qué hace la automatización.
Mensual, generado solo:

- Órdenes procesadas, y cuántas entraron sin intervención humana
- Calidad: cuántos `BLOQUEA` evitó el formulario (lo que antes había que
  reconciliar a mano contra SAP)
- Cuánto se archivó y verificó por hash
- Cuánto plan de datos ahorró a los técnicos
- Qué sigue pendiente **del lado del cliente**, con fecha desde cuándo

Ese último punto importa: hoy hay cinco cosas bloqueadas esperando a INDUSTEC
—la contraseña del buzón, el acceso al TrueNAS, el anexo de SLA, los reportes
históricos de SAP, la lista de técnicos vigentes—. Que estén en un informe con
fecha las vuelve visibles en vez de un reclamo verbal.

### 6.4 La memoria del proyecto vive en un solo disco

Antes que cualquier tablero, esto. Verificado el 2026-09-06:

| Qué | Estado | Qué pasa si ese disco muere |
|---|---|---|
| Repositorio git | **Sin remoto** (`git remote -v` no devuelve nada) | Se pierden los 30 commits, todo el código y todas las decisiones documentadas |
| Base MariaDB | Sin ningún script de volcado | Se pierden las 7.069 órdenes, los cruces con SAP y el trabajo de calidad |
| Árbol canónico | En `D:\RESPALDOS`, un solo disco | Se pierden los 7.333 PDFs saneados |
| TrueNAS | **Bloqueado desde el inicio** por falta de acceso físico | La segunda copia que el plan exige no existe |

La **regla de las dos copias** está escrita en el plan desde la Fase 1 y **hoy no
se cumple para nada de lo que produjimos**. Es más urgente que el tablero: un
tablero sirve para saber que algo se rompió; esto sirve para que romperse no sea
definitivo.

Lo barato primero: un remoto privado para el repositorio y un volcado diario de
la base al mismo disco donde ya va todo lo demás. No resuelve la segunda copia
—eso necesita el TrueNAS o algo equivalente— pero saca el código y el esquema del
único punto de falla.

### 6.5 Continuidad — la conversación pendiente con César

El sistema operativo de INDUSTEC corre en la cuenta de Hostinger de **INDUSTECH**
(`slurmfood@gmail.com`, plan Premium que vence el **2026-12-18**). El plan dice
que lo entregado queda funcionando y en poder de INDUSTEC al cerrar cada fase.

Hay que definir, **antes** de que la app nueva sea la operación real:

- ¿El hosting se traslada a una cuenta de INDUSTEC, o se queda y bajo qué acuerdo?
- ¿A qué dominio va la app? `ot.industec.me` es lo natural, pero el dominio es del cliente.
- ¿Quién paga la renovación de diciembre?
- Si mañana INDUSTEC opera solo: ¿qué necesita —las claves, el código, el manual—
  y quién lo sostiene?

No es una cuestión técnica y por eso es fácil dejarla para después. Dejarla para
después es exactamente cómo se convierte en un problema.

---

## 7. Cómo se envía: lo medido, no lo supuesto

Medido sobre fotos reales de una orden preventiva del sistema en producción:

| | KB/foto | Orden de 11 fotos | Calidad (PSNR) |
|---|---|---|---|
| **Hoy** — 1280px JPEG en base64 | 201 | **2,87 MB** | referencia |
| 1280px JPEG q70 | 107 | 1,15 MB | 40,2 dB |
| **1280px WebP q70** ← elegido | **56** | **0,60 MB** | 38,5 dB |
| 1024px WebP q70 | 40 | 0,43 MB | 37,7 dB |

**−79% de datos, a la misma resolución.** Los técnicos pagan su propio plan, así
que esto es dinero de su bolsillo.

Y lo que importa más que los megas: sobre una señal mala de interior (~200 kbps)
esos 2,87 MB tardan **~2 minutos** y se caen. Subiendo **foto por foto**, cada
una son 56 KB — dos segundos. Una caída cuesta dos segundos, no la orden.

> `canvas.toBlob(blob, 'image/webp', 0.7)` está soportado en Chrome de Android
> desde enero de 2020. No hace falta una app nativa para esto.

---

## 8. PWA ahora, nativo si el piloto lo justifica

**El ahorro de banda no lo da lo nativo.** WebP, binario en vez de base64 y
subida por partes funcionan igual en las dos. Eso se entrega ya.

Lo que una app Android nativa **sí** aportaría:

| Ventaja real | Peso en este caso |
|---|---|
| Almacenamiento que el sistema no desaloja | La PWA instalada puede pedir `navigator.storage.persist()`, y Chrome lo concede a apps instaladas con uso frecuente. El propio equipo de Chrome documenta que el desalojo automático es muy raro; lo común es que el usuario borre datos a mano |
| `WorkManager`: sube aunque la app esté cerrada | Background Sync lo hace en Android Chrome, con menos garantías |
| Cámara sin decodificar a memoria | Una foto de 12 MP en canvas son ~48 MB de RAM. Con 31 fotos en un celular de gama baja, es riesgo real de cuelgue |

Lo que cuesta: Android Studio, JDK, Gradle, firma, distribución (Play US$25 una
vez, o instalación manual), y **un segundo código que mantener**.

**La decisión: PWA primero, y el piloto decide.** No por preferencia, sino porque
**el servidor es idéntico para las dos**: construida la PWA contra una API limpia,
la app nativa reutiliza el 100% del lado servidor. Nada se desperdicia.

En el piloto de UIO se mide lo que hoy es opinión: **cuántas órdenes quedan
atascadas en cola, si alguna se pierde, y si el navegador se cae con muchas
fotos.** Si eso pasa, lo nativo queda justificado con evidencia. Si no, es un
segundo código a cambio de poco.

> Existe un punto intermedio: empaquetar la PWA como APK con **Bubblewrap**
> (Trusted Web Activity). Da un ícono y un instalable real, pero por dentro
> sigue siendo el navegador — así que **no** resuelve ni el almacenamiento ni la
> memoria de la cámara. Sirve para la comodidad de instalación, no para los
> problemas de fondo.

---

## 9. Orden de construcción

| # | Etapa | Listo cuando | Estado |
|---|---|---|---|
| **−1** | **Cerrar la exposición pública de los PDFs** | Un PDF concreto pasa de HTTP 200 a 403. **No necesita SSH**: el `.htaccess` se sube por el gestor de archivos de hPanel | 🔴 urgente |
| **−1** | **Sacar el proyecto del único disco** | El repositorio tiene remoto y existe un volcado de la base | 🔴 urgente |
| 0 | Reglas del formato único, en Python y PHP | Los 32 casos del fixture pasan en los dos | ✅ |
| 1 | Catálogos | 100 locales, 1.173 activos, 222 tipos publicados | ✅ |
| **2** | **Esquema**: `uuid`, firmas, fotos, estados, asignaciones, activos, técnicos | `SHOW CREATE TABLE` muestra la `UNIQUE KEY` del `uuid`; la firma se guarda con su hash | |
| 3 | API de catálogos | El teléfono los descarga y funciona sin señal | |
| 4 | Formulario único, en línea | Una orden de cada tipo produce el mismo nombre y estructura | |
| 5 | Fotos WebP, subida por separado | Una orden de 31 fotos entra sin acercarse a ningún límite | |
| 6 | **Offline, cola e idempotencia** | Se llena en modo avión, se firma, se envía sola. **Y reenviar 20 veces la misma orden produce una sola** | |
| 7 | PDF y correo, ambos en cola | Con el SMTP caído la orden igual queda guardada. El técnico no espera al PDF | |
| 8 | Login, roles y baja de técnico | Cada orden ligada a quién la emitió; al cerrar sesión el teléfono queda limpio | |
| **9** | **Bajada de filas desde Hostinger** | Una orden creada en el servidor aparece en MariaDB sin perder ninguna | |
| **10** | **Cronometrar el llenado** | Una orden correctiva y una preventiva, viejo contra nuevo, con un técnico real | |
| **11** | **Enseñarle el documento a KFC** | Alguien de KFC vio cómo queda lo que sus administradores van a firmar | |
| 12 | **Piloto UIO** | Ver abajo: 48 h no alcanzan | |
| 13 | LARB, CNLJ, preventivo | Cada uno con su ventana limpia | |
| 14 | Gestión, tablero y **reporte KFC (T2.2.3)** | La administración arma el plan sin abrir un correo; el reporte contratado sale solo | |
| 15 | Nativo Android | **Solo si el piloto lo justifica con datos** | |

### El piloto: 48 horas no alcanzan, y tiene que haber camino de vuelta

**48 h en UIO son unas 11 órdenes.** Con ese volumen no se detecta nada: ni una
orden atascada, ni un duplicado, ni un cuelgue con muchas fotos. El piloto se
mide **por órdenes, no por horas**: no menos de 50 órdenes correctivas y 5
preventivas completas.

Y hace falta lo que la primera versión no tenía: **criterio de aborto escrito de
antemano** —una sola orden perdida, o dos duplicadas, y se vuelve atrás— y
**camino de vuelta real**. Los formularios viejos **siguen aceptando envíos**
durante todo el piloto. Un técnico que no puede emitir un sábado por la noche
tiene que poder usar el de siempre; si en su lugar encuentra un aviso con un
enlace, el sistema nuevo acaba de romper la operación del cliente que representa
el 95% de los ingresos.

Las etapas 4 a 7 no dependen de ninguna decisión pendiente. La 8 sí: hay que
saber si el técnico entra con usuario propio o con código de zona.

---

## 10. Lo que sigue esperando una decisión

| Pregunta | Quién | Bloquea |
|---|---|---|
| ¿Qué se hace con la exposición de PDFs ya verificada? | Andrés / César | Nada técnico: el parche está listo. Pero hay una obligación legal que mirar |
| ¿Quién es responsable y quién encargado bajo la LOPDP? | INDUSTEC, con asesoría | El encuadre legal de todo el tratamiento |
| ¿El PDF puede cambiar, o hay que conservar la maqueta exacta? | KFC, vía INDUSTEC | La etapa 11, y el alcance real de la unificación |
| ¿Usuario por técnico o código de zona? | Andrés | Etapa 8 |
| Lista de técnicos vigentes | INDUSTEC | El desplegable de técnicos. El padrón está listo para marcar |
| ¿Los clientes no-KFC entran a la misma base? | Andrés | El modelo de datos |
| ¿A qué dominio va la app? | Andrés / César | El despliegue |
| Continuidad del hosting | Andrés / César | Nada técnico hoy; todo si la relación cambia |
| Los 21 pares de tipos de equipo parecidos | INDUSTEC | La limpieza del catálogo |
| Los 6 locales sin activos en SAP | INDUSTEC / KFC | Su desplegable de equipos |
