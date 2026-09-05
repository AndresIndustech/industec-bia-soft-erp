# Estado del proyecto INDUSTEC

> **Empieza por aquí.** Este archivo dice dónde vamos; [`PLAN_INDUSTEC.md`](PLAN_INDUSTEC.md) dice qué hay que construir y con qué criterios.
> Si vas a trabajar, **anótate primero en §5 (Trabajo en paralelo)** antes de tocar nada.

**Última actualización:** 2026-09-05
**Fase en curso:** 1 · Cimientos (septiembre) — sustancialmente cerrada
**Repositorio git:** la raíz del proyecto, `D:\INDUSTECH IA` — cubre el código **y** estos documentos, para que quede historial de las decisiones. Fuera del control de versiones: `ENTRADAS IA`, `SALIDAS IA`, el entorno virtual y las credenciales.

---

## 1. Qué está funcionando hoy

| Pieza | Estado | Cifra verificada |
|---|---|---|
| Corpus histórico saneado | ✅ | **7.070** órdenes en el árbol canónico + 5 informes técnicos + 25 de otros clientes |
| Base de datos poblada | ✅ | **7.069 órdenes activas** · 9.071 equipos · 100 locales · 145 alias · 6.450 avisos SAP · 19 técnicos |
| Auditor de calidad (Agente 1) | ✅ | 2.644 observaciones abiertas, con veredicto editable por la administración |
| Consolidador de plan de zona (Agente 2) | ⚠️ v1 | 87,3% de coincidencia celda a celda en el piloto UIO |
| **Histórico en formato de planificación** | ✅ | **39 planes mensuales** de correctivo (7.863 filas, 3 zonas × 13 meses) + **seguimiento de preventivos** de 96 locales, reconstruidos desde las OTs y SAP. LOCAL, FECHA DE INICIO y ESTADO al 100%; EQUIPO al 99,5% |
| Respaldo TrueNAS | 🔒 | Bloqueado: falta acceso físico al equipo |
| Capacitación de cierre | 🔒 | Bloqueada: falta agendar con el personal |

**Cuadre del corpus, exacto contra los 7.333 PDFs originales:**
`7.070 (órdenes) + 5 (informes técnicos) + 25 (otros clientes) + 233 (duplicados descartados) = 7.333`

**Reparto de las órdenes activas:** CNLJ 2.589 · LARB 2.498 · UIO 1.978 · OTRA 4 — · Correctivo 6.349 · Preventivo 720

---

## 2. Tareas terminadas

| Tarea | Qué dejó |
|---|---|
| T1.1–T1.3 | Inventario con hash de 12.616 archivos y copia verificada a `D:\RESPALDOS` (100% de hashes coincidentes) |
| T1.4 | Stack instalado: Python 3.12 + venv, MariaDB, Poppler |
| T1.5 | Esquema de 13 tablas y maestro de locales, con verificación cruzada contra la hoja GENERAL |
| T1.6 | Saneamiento canónico de los 7.333 PDFs, con manifiesto reversible |
| **T1.6b** | Resolución de la cuarentena cruzando 4 fuentes independientes. 519 de 554 casos resueltos |
| **T1.6c** | Fechas inválidas recuperadas del `CreationDate` del PDF. Ninguna orden activa sin fecha |
| **T1.6d** | 25 trabajos fuera del Grupo KFC archivados en su propia rama |
| **T1.6e** | 5 altas al maestro (95 → 100 locales) y 2 códigos mal escritos resueltos como alias |
| T1.7 | Extracción por patrones e ingesta idempotente a la base |
| T1.8 | ❌ **Cancelada** por directiva del cliente. El Drive queda intocable de forma indefinida |
| T1.10 | Agente 1 · auditor de calidad, con 10 reglas |
| T1.11 | Agente 2 · consolidador, primera versión |
| **Histórico base** | Las 7.065 órdenes (6.345 correctivas + 720 preventivas) reconstruidas en el formato con que se planifica, tomando de los archivos de la administración solo el formato y su seguimiento. Punto de partida para los planes diarios de Fase 2 |
| **Campos SAP faltantes** | Migración `002`: `Estatus 2 de la Orden` y `Denominación objeto` importados a `avisos_sap`. Era el techo de calidad: subió EQUIPO de la mitad a 99,5% y habilitó la columna ESTATUS SAP |
| **Discrepancias de los planes manuales** | 1.552 diferencias detectadas contra la evidencia de las OTs y SAP, 1.114 de ellas contradiciendo evidencia estable, entregadas en Excel para revisión de la administración |
| **Cierre de todos los casos** | El histórico queda con las 7.863 filas en CERRADA, cada una con la evidencia que sostiene su cierre. Solo 191 son una decisión sin ningún registro detrás, listadas para revisión. Se descartaron, por falta de evidencia, el autocierre de KFC a los 7 días y el cierre bajo otro número de caso |

---

## 3. Qué sigue

### Bloqueado por el cliente (no depende de nosotros)

| Pendiente | Bloquea |
|---|---|
| Contraseña del buzón Titan + acceso de aplicaciones de terceros | Lectura automática de avisos SAP por IMAP (parte de T1.10) |
| Acceso físico al TrueNAS | T1.9 |
| Anexo de niveles de servicio del contrato con KFC | Metas reales de SLA en T2.2 |
| Confirmar la zona real de los 4 locales Pollo Gus dados de alta con zona `OTRA` | Reportes por zona de esos locales |
| Decidir sobre las altas propuestas en `SALIDAS IA\CALIDAD\PROPUESTA_ALTAS_MAESTRO_LOCALES.xlsx` | Nada; el sistema ya opera con ellas cargadas en la base |
| **Reportes históricos de SAP** (más allá del export actual, que solo cubre ene–ago 2026) | Cerrar las inquietudes del histórico — ver abajo |
| **Habilitar SSH en Hostinger** (hPanel → Avanzado → Acceso SSH) | Toda la sincronización T2.4: sin eso no hay descarga ni purga |
| **Revisar si `cleanup.php` está en algún cron** | Nada, pero es lo más urgente: borra `uploads` y los logs sin verificar copia |

### Etapa del histórico: cerrada, con estas inquietudes anotadas

La reconstrucción del histórico se dio por cerrada el **2026-09-05**. Lo que sigue abierto no se
resuelve con más código: necesita datos del cliente. Cuando lleguen los reportes históricos de SAP,
se contrasta contra ellos y se corrige lo que haga falta.

| Inquietud | Cifra | Con qué se resuelve |
|---|---|---|
| Cierres asumidos sin ningún registro detrás | 191 filas | Reporte histórico de SAP: ver si registran cierre y cuándo |
| Avisos que SAP daba por abiertos al corte del 31-ago, cerrados igual en el histórico | 296 | Un export posterior dice si cerraron y con qué fecha |
| Filas de 2025 sin cobertura del catálogo SAP | 2.726 | Export de SAP que alcance sep–dic 2025 |
| Casos dentro de la cobertura y aun así ausentes del catálogo | 31 | Revisión individual contra SAP |
| Discrepancias de los planes manuales contra evidencia estable | 1.114 | Revisión de la administración sobre el Excel entregado |
| `ESTATUS SAP` llenado al 21,3% | 1.659 de 6.450 avisos | Es el dato real: solo esos tuvieron trámite de repuesto. Se confirma con el histórico de SAP |
| El autocierre de KFC a los 7 días, afirmado pero sin huella en los datos | — | Confirmarlo con el cliente o con un export que registre el motivo de cierre |

### Disponible para trabajar ya

| # | Tarea | Depende de | ¿Paralelizable? |
|---|---|---|---|
| **T1.11-b** | Llevar el consolidador vivo (`agente2_consolidador.py`) al modelo del histórico: agrupar por (aviso, zona), arrastrar por evidencia y leer los campos SAP nuevos. Hoy sigue con la lógica vieja del 87,3% | nada | Sí |
| **T2.4.0–T2.4.4** | Continuidad de datos del sistema en producción: parches de seguridad, espejo verificado, purga con compuerta de hash, normalización y publicación. Ver [`ARQUITECTURA_SISTEMA_OTS.md`](ARQUITECTURA_SISTEMA_OTS.md) | SSH habilitado | Sí, salvo la purga |
| **T2.1.1–T2.1.6** | Intervención al sistema de OTs en producción (PHP/Hostinger), subtarea por subtarea | nada | Sí, entre sí no chocan si se despliegan de a una |
| **T2.2.1–T2.2.3** | Agente 3 · reportes diario, mensual y KFC | nada | Sí |
| **T2.3.1–T2.3.4** | Cola de correo `email_queue` con reintentos y detección de rebotes | nada | Sí |
| **T3.1** | Bot de Telegram para la Gerencia | T2.2 conviene, no obliga | Sí |
| **T3.2** | Analista de confiabilidad y repotenciación | nada | Sí |
| **T3.3** | Manual y continuidad | que lo demás exista | No: se escribe al final |

Cada subtarea está especificada con su criterio de aceptación y su tabla *autónomo / requiere aprobación / prohibido* en `PLAN_INDUSTEC.md`.

---

## 4. Dónde está cada cosa

| Qué | Dónde |
|---|---|
| Plan maestro | `D:\INDUSTECH IA\PLAN_INDUSTEC.md` |
| Código y scripts | `D:\INDUSTECH IA\desarrollo\agentes\scripts\` (repo git) |
| Entorno de Python | `D:\INDUSTECH IA\desarrollo\agentes\.venv\Scripts\python.exe` |
| Credenciales de la base | `D:\INDUSTECH IA\desarrollo\agentes\config\.env` (fuera de git) |
| Esquema SQL | `D:\INDUSTECH IA\desarrollo\agentes\sql\001_esquema_inicial.sql` |
| Corpus canónico de órdenes | `D:\RESPALDOS\ORDENES DE TRABAJO\{año}\{módulo}\{zona}\{cadena}\` |
| Informes técnicos sueltos | `D:\RESPALDOS\INFORMES TECNICOS\` |
| Trabajos de otros clientes | `D:\RESPALDOS\OTROS CLIENTES\{año}\{cliente}\` |
| Espejo intacto del origen (Drive) | `D:\RESPALDOS\_ORIGEN_DRIVE\` — **nunca se modifica** |
| Espejo intacto del origen (sistema en producción) | `D:\RESPALDOS\_ORIGEN_SISTEMA\` — lo que baja de Hostinger, con sus nombres crudos. **Nunca se modifica** |
| Decisión de arquitectura y hallazgos de la auditoría | `D:\INDUSTECH IA\ARQUITECTURA_SISTEMA_OTS.md` |
| Cómo acceder a Hostinger y desplegar los parches | `desarrollo\sistema_ots\LEEME_ACCESO_HOSTINGER.md` |
| Salidas para la administración | `D:\INDUSTECH IA\SALIDAS IA\CALIDAD\` |
| Histórico en formato de planificación | `SALIDAS IA\MANTENIMIENTO\` — correctivos por zona y mes, preventivos por local y año, discrepancias de los planes manuales, con `LEEME_HISTORICO.md` |
| Drive de la empresa | `G:\Mi unidad` — **solo lectura, indefinidamente** |

**Base de datos:** MariaDB local, esquema `industec_ots`. Tablas: `ots`, `ot_equipos`, `ot_fotos`, `locales`, `locales_alias`, `avisos_sap`, `tecnicos`, `observaciones_calidad`, `manifiesto_saneamiento`, `plan_snapshots`, `correcciones`, `consultas_gerente`, `bitacora`.

---

## 5. Trabajo en paralelo

Varias conversaciones pueden avanzar a la vez, **si respetan qué recurso toca cada una**. El problema no es el disco: es la base de datos y el árbol canónico.

### 5.1 Anótate antes de empezar

Edita esta tabla al tomar una tarea y bórrate al terminar. Si la tabla está vacía, nadie está trabajando.

| Tarea | Conversación / responsable | Desde | Recursos que bloquea |
|---|---|---|---|
| _(libre)_ | | | |

### 5.2 Qué choca con qué

| Si vas a... | Bloqueas | No puede correr a la vez |
|---|---|---|
| Correr `t1_7_ingesta.py` | tabla `ots`, `ot_equipos` | cualquier otro script que escriba `ots` |
| Correr `t1_6b_ejecutar_resolucion.py` | tabla `ots` + árbol canónico | la ingesta, y cualquier movimiento de archivos |
| Correr `agente1_auditor_calidad.py` | tabla `observaciones_calidad` | otra corrida del auditor |
| Correr `agente2_consolidador.py` | solo lee `ots`; escribe en `SALIDAS IA` | nada |
| Trabajar en T2.1 (sistema en producción) | Hostinger y su MySQL | otro despliegue a producción |
| Correr `t2_4_sync_hostinger.py` | espejo `_ORIGEN_SISTEMA` | otra sincronización |
| Correr `t2_4_purga_hostinger.py --ejecutar` | uploads de Hostinger | la sincronización, y cualquier despliegue |
| Correr `t2_4_normalizar_nuevas.py --ejecutar` | árbol canónico | la ingesta y los scripts de T1.6b |
| Trabajar en T2.2 / T3.1 / T3.2 | solo lectura de la base | nada |
| Escribir documentación o investigar | nada | nada |

**Regla simple:** lo que solo lee puede correr siempre en paralelo. Lo que escribe en `ots` o mueve archivos del árbol va de a uno.

### 5.3 Orden obligatorio cuando se re-procesa el corpus

Si tocas la resolución de documentos, el ciclo completo es **siempre en este orden**, y cada paso debe terminar antes del siguiente:

```
1. t1_6b_resolver_cuarentena.py     analiza, no escribe nada
2. t1_6b_verificar_resolucion.py    5 comprobaciones; si falla, NO sigas
3. t1_6b_ejecutar_resolucion.py     copia, verifica por hash, actualiza la base
4. t1_7_ingesta.py                  crea las filas de los documentos nuevos
5. t1_6b_ejecutar_resolucion.py     otra vez: activa las filas que acaba de crear la ingesta
6. t1_6c_corregir_fechas.py         rellena fechas desde el PDF
7. agente1_auditor_calidad.py       recalcula observaciones y cierra las que ya no aplican
```

Los pasos 4 y 5 se repiten porque el renombrado cambia el `id_industec`: la ingesta crea la fila canónica y la segunda pasada del ejecutor la activa. Todos los scripts son idempotentes: correrlos de nuevo no duplica nada.

### 5.4 Cómo comprobar que no rompiste nada

```bash
cd "D:\INDUSTECH IA\desarrollo\agentes"
.venv/Scripts/python.exe scripts/t1_6b_verificar_resolucion.py
```

Y el cuadre global, que debe dar exactamente 7.333:

```
PDFs en ORDENES DE TRABAJO + INFORMES TECNICOS + OTROS CLIENTES + _CUARENTENA + 233 duplicados
```

Señales de que algo se rompió: alguna orden activa sin local o sin fecha, algún archivo del árbol con más de una orden activa, o el verificador fallando cualquiera de sus 5 comprobaciones.

---

## 6. Decisiones vigentes que no se re-discuten

Están razonadas en el plan; aquí solo el titular, para que ninguna conversación las revierta por desconocerlas.

1. **El Drive de INDUSTEC (`G:\Mi unidad`) es de solo lectura, indefinidamente.** T1.8 quedó cancelada. Nada se borra de ahí sin autorización explícita y puntual.
2. **Nunca se borra información del cliente sin que él lo pida en el momento.** Aprobar un plan no autoriza ejecutar un borrado.
3. **Los archivos de la administración no se sobrescriben.** Los agentes escriben a nombre nuevo; ella promueve.
4. **El aviso SAP es opcional en el nombre canónico.** Los preventivos no nacen de un aviso, y algunos correctivos tampoco (§6.4b del plan).
5. **`estatus_general` de SAP es el único criterio de "cerrado".** Nunca una señal interna del sistema de OTs.
6. **El interior del PDF manda sobre SAP y sobre el plan de la administración** cuando hay que decidir a qué local pertenece un documento: las otras señales se derivan del aviso o del correlativo, que son los campos que se digitan mal.
7. **Software libre o gratuito**, el mejor de su categoría. Lo de pago se propone con justificación y costo antes de adquirir nada.
8. Se puede instalar sin pedir permiso previo, siempre que sea de fuente verificada y necesario; se informa después.

---

## 7. El criterio ganado está en las skills

Seis skills en `.claude/skills/`, minadas del propio código de la Fase 1 para que no se pierda lo aprendido. Invócalas según lo que vayas a hacer:

| Skill | Cuándo |
|---|---|
| **`industec-invariantes`** | **Siempre, al empezar.** Rutas, permisos, invariantes y cómo se cierra una tarea |
| `industec-escritura-mysql` | Antes de cualquier `INSERT`/`UPDATE`/`DELETE` o cambio de esquema |
| `industec-lectura-excel` | Al leer un Excel de la administración o de SAP |
| `industec-extraccion-pdf` | Al extraer campos de un PDF por patrones |
| `industec-archivos-canonicos` | Al mover, renombrar o clasificar documentos del corpus |
| `industec-agentes-y-entregables` | Al construir un agente o un archivo que va a leer una persona |

Cada una lleva la evidencia real de qué se rompió y el comando exacto que comprueba que la regla se cumple. No son teoría: salieron de los errores que este proyecto ya pagó.

---

## 8. Bitácora de sesiones

| Fecha | Qué se hizo |
|---|---|
| 2026-09-03 | T1.1 a T1.7: inventario, copia verificada, stack, esquema, maestro, saneamiento e ingesta |
| 2026-09-04 | Segunda pasada del plan con 26 correcciones e invariantes I-9 a I-13. T1.6b/c/d/e: cuarentena resuelta, fechas recuperadas, otros clientes separados, altas al maestro. Corregidos 4 bugs de datos anteriores (centros de coste perdidos por `max_col`, correo del maestro en columna equivocada, filas duplicadas por renombrado, falsos positivos del auditor) |
| 2026-09-04 | Proyecto reorganizado: raíz corta y todo lo técnico en `desarrollo/`. Se escribieron el `CLAUDE.md`, este `ESTADO.md` y 6 skills minadas del propio código. Corregida una deriva de esquema que impedía reconstruir la base desde cero |
