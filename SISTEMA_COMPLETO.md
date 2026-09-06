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
  │  2. Llena la orden — SIN SEÑAL, todo local                       │
  │  3. Fotos: se comprimen a WebP en el teléfono al tomarlas        │
  │  4. El administrador FIRMA en pantalla                           │
  │  5. La orden queda GUARDADA Y CERRADA localmente  ← a salvo      │
  └──────────────────────────────┬───────────────────────────────────┘
                                 │  cuando hay señal, solo:
                                 ▼
  ┌── HOSTINGER · ventana operativa ─────────────────────────────────┐
  │  6. Sube foto por foto (56 KB c/u) y luego la orden (5 KB)       │
  │  7. El servidor REVALIDA todo desde cero                         │
  │  8. Reserva el correlativo (transacción atómica)                 │
  │  9. Escribe la fila         ← la orden ya existe en la empresa   │
  │ 10. Genera el PDF a partir de la fila                            │
  │ 11. Encola los correos (no los manda sincrónico)                 │
  └──────────────────────────────┬───────────────────────────────────┘
                                 │  cada noche, 21:30
                                 ▼
  ┌── LA ESTACIÓN · memoria completa ────────────────────────────────┐
  │ 12. Baja lo nuevo y lo verifica por SHA-256                      │
  │ 13. Archiva en D:\RESPALDOS con el nombre canónico               │
  │ 14. Ingesta a MariaDB y cruza con SAP                            │
  │ 15. Recalcula planes y publica en SALIDAS IA\OTS                 │
  │ 16. Deja el informe de purga para revisión                       │
  └──────────────────────────────┬───────────────────────────────────┘
                                 ▼
        INDUSTEC ve el catálogo · KFC recibe sus reportes
```

**El punto 5 es el corazón de todo.** A partir de ahí la orden existe, aunque el
teléfono se quede sin batería, se apague o pase tres días sin señal.

**El punto 13 se simplifica solo.** Hoy la normalización tiene que adivinar el
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

## 6. El control de INDUSTECH — lo que Andrés necesita para operar esto

Esto es lo que no existía en ninguna concepción anterior, y sin ello el sistema
funciona pero **nadie sabe si está funcionando**.

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

### 6.4 Continuidad — la conversación pendiente con César

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
| 0 | Reglas del formato único, en Python y PHP | Los 32 casos del fixture pasan en los dos | ✅ |
| 1 | Catálogos | 100 locales, 1.173 activos, 222 tipos publicados | ✅ |
| 2 | Esquema en MySQL + API de catálogos | El teléfono descarga los catálogos y funciona sin señal | |
| 3 | Formulario único, en línea | Una orden de cada tipo produce el mismo nombre y estructura | |
| 4 | Fotos WebP, subida por separado | Una orden de 31 fotos entra sin acercarse a ningún límite | |
| 5 | **Offline y cola** | Se llena una orden en modo avión, se firma, y se envía sola al recuperar señal | |
| 6 | PDF y cola de correo | Con el SMTP caído la orden igual queda guardada | |
| 7 | Login y roles | Cada orden ligada a quién la emitió; el jefe asigna | |
| 8 | **Piloto UIO, 48 h** | Mismo PDF y mismos correos que hoy, y además fila en base | |
| 9 | LARB, CNLJ, preventivo | 48 h limpias en cada uno | |
| 10 | Gestión y tablero de control | La administración arma el plan sin abrir un correo; Andrés ve si la máquina anda | |
| 11 | Nativo Android | **Solo si el piloto lo justifica con datos** | |

Las etapas 3 a 6 no dependen de ninguna decisión pendiente. La 7 sí: hay que
saber si el técnico entra con usuario propio o con código de zona.

---

## 10. Lo que sigue esperando una decisión

| Pregunta | Quién | Bloquea |
|---|---|---|
| ¿Usuario por técnico o código de zona? | Andrés | Etapa 7 |
| Lista de técnicos vigentes | INDUSTEC | El desplegable de técnicos. El padrón está listo para marcar |
| ¿Los clientes no-KFC entran a la misma base? | Andrés | El modelo de datos |
| ¿A qué dominio va la app? | Andrés / César | El despliegue |
| Continuidad del hosting | Andrés / César | Nada técnico hoy; todo si la relación cambia |
| Los 21 pares de tipos de equipo parecidos | INDUSTEC | La limpieza del catálogo |
| Los 6 locales sin activos en SAP | INDUSTEC / KFC | Su desplegable de equipos |
