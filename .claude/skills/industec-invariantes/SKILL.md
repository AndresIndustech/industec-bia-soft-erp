---
name: industec-invariantes
description: Invocala SIEMPRE al empezar cualquier tarea del proyecto INDUSTEC, antes de leer codigo o tocar datos: fija rutas, permisos de escritura, invariantes no negociables, convencion de nombres y como se especifica y se cierra una tarea.
---

# INDUSTEC — contexto e invariantes

Proyecto de automatizacion de ordenes de trabajo (OTs) para INDUSTEC, empresa de mantenimiento HORECA en Ecuador, cliente final Grupo KFC. Tres zonas operativas: **UIO**, **LARB** (Ambato/Latacunga/Riobamba), **CNLJ** (Cuenca/Loja). 100 locales, 13 cadenas, ~7.333 PDFs historicos.

Esta skill es el arranque obligatorio. Si vas a leer Excel, cargar a MySQL, extraer PDF, mover archivos o escribir un agente, ademas invoca la skill especifica.

## 1. Mapa de rutas (memorizalo, no lo deduzcas)

| Ruta | Que es | Permiso |
|---|---|---|
| `G:\Mi unidad` | Drive vivo de la empresa | **SOLO LECTURA, indefinidamente** |
| `D:\RESPALDOS\_ORIGEN_DRIVE` | Espejo verificado por hash del Drive | Solo lectura. Fuente reversible |
| `D:\RESPALDOS\ORDENES DE TRABAJO\{ANO}\{CORRECTIVO\|PREVENTIVO\|OTROS}\{ZONA}\{CADENA}\` | Arbol canonico de OTs | Escritura por scripts |
| `D:\RESPALDOS\INFORMES TECNICOS\{ano}\{zona}\{cadena}\` | Documentos sin correlativo | Escritura por scripts |
| `D:\RESPALDOS\OTROS CLIENTES\{ano}\{CLIENTE}\` | Trabajos fuera del contrato KFC | Escritura por scripts |
| `D:\INDUSTECH IA\SALIDAS IA\` | **Todo entregable generado** (CALIDAD, reportes, propuestas) | Escritura libre |
| `D:\INDUSTECH IA\desarrollo\agentes\scripts` y `\sql` | Codigo del proyecto | Escritura libre |

`D:\RESPALDOS` **no es temporal**: es el almacenamiento definitivo. La tarea T1.8 (liberar espacio en Drive) esta **CANCELADA**, no pospuesta.

## 2. Invariantes no negociables

**I-1 — Instalar software: sin permiso previo, pero de fuente verificada y en `D:\SOFTWARE`.** La regla original ("nada se instala sin permiso") fue **revocada por el cliente el 2026-09-03**: se puede instalar sin consultar, siempre que la fuente sea verificada (winget, repositorio oficial) y la pieza sea necesaria. Se informa despues de hacerlo. Desde el 2026-09-04 todo software nuevo va en `D:\SOFTWARE\<Herramienta>`, una carpeta por herramienta y sin la version en el nombre. Sigue vigente I-6: libre o gratuito; lo de pago se argumenta con costo antes de adquirirlo.

**I-2 — Copiar y verificar; jamas borrar ni mover desde una carpeta sincronizada.** La secuencia es `copiar -> verificar por hash -> detenerse`. El paso "borrar el origen" no existe salvo autorizacion puntual en el momento; aprobar un plan que lo contemple **no** lo autoriza. Verificacion: `grep -rniE "(Remove-Item|shutil.rmtree|os.remove|/MIR)" scripts/` no debe devolver nada cuyo objetivo sea `G:\`.

**I-3 — `G:\Mi unidad` es de solo lectura.** Ninguna escritura, renombrado ni correccion, ni siquiera de material ya copiado. Directiva textual del cliente: *"la informacion de google drive de industec actual no se toca ni modifica"*.

**I-4 — Los archivos de la administracion no se sobrescriben.** Genera siempre a nombre nuevo bajo `SALIDAS IA`, con marca de origen (`... (generado agente).xlsx`). Detecta archivo bloqueado por Excel y aborta con mensaje claro; nunca fuerces. Verificacion: `Get-FileHash` de la plantilla antes y despues de la corrida debe ser identico.

**I-5 — Ningun renombrado sin fila reversible en el manifiesto** (ruta original, ruta canonica, regla aplicada, nivel, estado). Los casos que requieren persona llevan columna `DECISION ADMIN` / `VEREDICTO ADMIN` vacia, que el agente relee en la corrida siguiente.

**I-6 — Solo software libre o gratuito.** Lo de pago se argumenta con costo antes de adquirirlo. Unico gasto nuevo previsto: Claude Pro.

**I-7 — Si no hay dato, dilo.** Ningun agente completa por verosimilitud. Campo vacio se reporta vacio y se levanta como hallazgo. Toda respuesta cita las ordenes y avisos concretos. Prueba negativa obligatoria: consultar un caso inexistente debe responder que no se tiene el dato.

**I-8 — No cambies la forma de trabajar del tecnico.** Todo cambio al sistema de OTs se despliega primero en UIO, se verifica **48 h**, y solo entonces a LARB y CNLJ. El formulario debe verse y usarse igual.

**I-9 — UNIQUE KEY sobre la clave de negocio real desde el CREATE TABLE**, nunca como parche. Ver skill `industec-escritura-mysql`.

**I-10 — Ninguna escritura a produccion o a un maestro sin verificacion cruzada previa** contra una fuente independiente, que **aborta** con `sys.exit(1)` si no cuadra exactamente. No se degrada a advertencia "porque el desfase es pequeno". Detecto 3 bugs reales antes de corromper el maestro (T1.5, T1.6, T1.11).

**I-11 — Colisiones se resuelven por hash de contenido**, nunca por orden de llegada, fecha o ruta. Mismo hash = duplicado (se conserva una copia). Hash distinto = colision real, todo el grupo a revision humana.

**I-12 — Declara la cobertura temporal de un catalogo antes de usarlo para marcar incumplimientos.** Lo que cae fuera se reporta como "fuera de cobertura", jamas como error. Un catalogo SAP que solo cubria 8 de 12 meses genero ~1.662 falsos positivos en T1.10.

**I-13 — Un prompt no es control de acceso.** LLM que consulta base o dispara accion: usuario MySQL dedicado con solo `SELECT`, `max_statement_time` 2-5 s, `LIMIT` siempre, validador que rechaza todo SQL que no empiece por `SELECT` o toque tablas fuera de lista blanca, schema contract acotado, limite de reintentos, lista blanca de chats.

## 3. Convencion de nombres canonicos — las CUATRO formas

El parser y todo validador deben aceptar exactamente estas cuatro, **sin exigir el aviso en ninguna**:

1. Correctivo: `OT-{correlativo:4}-{LOCAL}-{AVISO:8}-{ZONA}.pdf` → `OT-2422-K146EC-10352088-CNLJ.pdf`
2. Correctivo sin aviso: `OT-{correlativo:4}-{LOCAL}-{ZONA}.pdf` → `OT-0260-A010EC-UIO.pdf`
3. Preventivo: `OT-{correlativo:4}-{LOCAL}-{AVISO:8}-D{n}-{ZONA}.pdf` → `OT-0186-K041EC-10340555-D2-CNLJ.pdf`
4. Preventivo sin aviso: `OT-{correlativo:4}-{LOCAL}-D{n}-{ZONA}.pdf` → `OT-0005-K061EC-D5-CNLJ.pdf`

Correlativo 4 digitos, aviso 8, local `[A-Z]{1,2}[0-9]{2,4}EC`, zona en `{UIO, LARB, CNLJ}`. Existe un segundo patron de la empresa con el **correlativo al final**: `OT-Cajun-10280653-CNLJ-023.pdf` (161 documentos); tratalo como valido, no como defecto.

**Por que importa:** la primera version del estandar exigia el aviso siempre. Esa sola decision mando **185 documentos correctos** a cuarentena (T1.6b). Los preventivos no nacen de un aviso SAP.

Reglas de nombrado adicionales:
- **Manten el guion como separador.** No migres a guion bajo: `OT-2422-K146-10352088-CNLJ` va impreso dentro del PDF, en el asunto del correo y en los planes de la administracion.
- Sin espacios (`Dia 2` → `D2`), ceros a la izquierda, fechas ISO `YYYY-MM-DD`.
- **El modulo lo manda la CARPETA, no el sufijo `-D{n}`.** Deducirlo del nombre fallaba en los preventivos sin numero de dia.
- **Un documento sin correlativo no es una OT**: va a `INFORMES TECNICOS`, fuera de la tabla `ots`. No le fuerces un correlativo ni lo mandes a cuarentena.

## 4. Vocabulario de dominio (identico en todos los scripts)

- Zonas canonicas: `UIO`, `LARB`, `CNLJ`, y `OTRA` (agregada al final del ENUM para locales fuera de las tres zonas contratadas). La hoja Excel llamada `CUENCA` mapea a zona `CNLJ`.
- Cadenas: KFC, GUS, CASA RES, TROPI BURGER, JUAN VALDEZ, MENESTRAS DEL NEGRO, AMERICAN DELI, EL ESPANOL, BASKIN ROBBINS, CAJUN, CINNABON, HELADERIA, IL CAPO.
- **La cadena se resuelve por el maestro de locales, JAMAS por el prefijo del codigo.** El prefijo engana: `J018EC` y `J022EC` son Cajun, otros codigos con `J` son Juan Valdez. Verificacion: `grep -nE "startswith|prefijo" script.py` no debe encontrar derivacion de cadena desde la letra.
- Estado canonico de una OT: `avisos_sap.estatus_general` (`CERRADO`/`TRATAMIENTO`/`ABIERTO`). Ninguna senal interna puede usarse como criterio de backlog, SLA o notificacion.

## 5. Como se especifica y se cierra una tarea

1. **Dimensiona.** Mas de ~5 archivos o ~2 horas es una epica: descomponla en subtareas numeradas por corte **vertical** (funcion completa de punta a punta), nunca por capas. La descomposicion se hace antes de asignar.
2. **Cierra en el documento toda decision de diseno con mas de una opcion valida**, con la opcion elegida y un ejemplo concreto. No la dejes al criterio del agente en ejecucion.
3. **2-3 criterios binarios por subtarea**, en Given/When/Then o tabla entrada→salida, cada uno con su **comando de verificacion exacto**. Al cerrar, pega la salida literal como evidencia. Nada "a medias".
4. **Limite de 2-3 reintentos** y luego escala. Nunca girar esperando que converja.
5. **Tabla por tarea**: autonomo / requiere aprobacion humana / prohibido, con umbral mas conservador de lo habitual.
6. **Las Puertas son bloqueantes.** Una dependencia debe haber PASADO su criterio, no solo haberse ejecutado. **Si un dato real contradice el plan, gana el dato**: documenta la discrepancia y consulta, no improvises una interpretacion.

## 6. Principio de arquitectura: la IA razona, el codigo decide

- **Codigo determinista**: copiar, verificar, renombrar, mover; extraer por patron, cruzar tablas, calcular KPIs; llenar Excel, generar documentos, enviar correo; y **toda** decision de aceptar/rechazar/borrar.
- **IA**: leer texto libre y detectar ambiguedad o vacio; redactar conclusiones; interpretar una pregunta en lenguaje natural; proponer clasificaciones que luego valida una regla.
- La capa de IA se invoca **una vez por lote, nunca una por registro**. Verificacion: contar llamadas al LLM por corrida debe dar O(lotes), no O(registros).

## 7. Checklist antes de escribir la primera linea

```
[ ] Se en que ruta puedo escribir y confirme que no es G:\ ni un archivo de la administracion
[ ] La tarea tiene criterio binario con comando de verificacion
[ ] Si escribo en base: la UNIQUE KEY de negocio ya existe (SHOW CREATE TABLE)
[ ] Si valido contra un catalogo: consulte su MIN/MAX de cobertura real
[ ] Si es irreversible: hay checkpoint de verificacion cruzada que aborta con exit 1
[ ] Ninguna dependencia mia pendiente de pegar evidencia de aceptacion
```
