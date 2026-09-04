# SALIDAS IA — lo que producen los agentes

Esta carpeta está **sincronizada con Google Drive** y es el único espacio donde los agentes escriben información nueva, mejorada o corregida. Nada de aquí sobrescribe archivos de la administración: los agentes generan a nombre nuevo y ella decide si lo promueve.

## Qué hay en cada carpeta

| Carpeta | Qué contiene |
|---|---|
| **CALIDAD** | Todo lo que hay que revisar o decidir: hallazgos de calidad, manifiestos de lo que se corrigió, y propuestas que esperan tu confirmación |
| **MANTENIMIENTO** | Planes de zona generados por el consolidador, separados en correctivos y preventivos |
| **REPORTES** | Reportes diario, mensual y para Grupo KFC (Fase 2) |
| **PRESENTACIONES** | Material para presentar a la Gerencia y al cliente |
| **DATOS EMPRESA** | Maestros y catálogos corregidos |

## Lo que espera tu decisión (CALIDAD)

| Archivo | Qué pide |
|---|---|
| `OBSERVACIONES_OTS.xlsx` | Los hallazgos de calidad abiertos. Tiene una columna **VEREDICTO ADMIN**: lo que marques ahí, el auditor lo respeta en las corridas siguientes y no vuelve a levantarlo |
| `PROPUESTA_ALTAS_MAESTRO_LOCALES.xlsx` | 5 locales nuevos listos para pegar en el maestro, y 2 códigos que resultaron ser errores de escritura. Ya están cargados en la base; falta solo incorporarlos a tu Excel |
| `MANIFIESTO_CUARENTENA_RESUELTA.xlsx` | Qué se resolvió de la cuarentena y con qué evidencia. La hoja *PENDIENTES DE DECISIÓN* lista lo que no se pudo decidir solo |

## Trazabilidad (CALIDAD)

Estos no piden nada; están para poder auditar o deshacer cualquier cambio.

| Archivo | Para qué sirve |
|---|---|
| `MANIFIESTO_SANEAMIENTO.xlsx` / `.csv` | Nombre y ruta original de cada documento frente a su nombre canónico, con la regla aplicada. Permite deshacer cualquier renombrado |
| `INVENTARIO_ORIGEN.csv` | Los 12.616 archivos originales con su hash SHA-256 |
| `VERIFICACION_COPIA.csv` | Prueba archivo por archivo de que la copia a `D:\RESPALDOS` quedó idéntica |
| `RESOLUCION_CUARENTENA.csv` | Cada documento de cuarentena con las cuatro señales que se cruzaron y por qué se decidió así |
| `INDICE_PLANES_ADMIN.csv` | Las 7.777 referencias extraídas de tus planes de trabajo, que son la fuente que permitió resolver la cuarentena |
| `OTROS_CLIENTES.csv` | Los 25 trabajos que resultaron ser de clientes fuera del Grupo KFC |
