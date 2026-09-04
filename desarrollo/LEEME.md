# desarrollo — código y trabajo técnico

Todo lo técnico del proyecto vive aquí, para que la raíz quede corta. Lo que no está aquí es porque no puede: `ENTRADAS IA` y `SALIDAS IA` están sincronizadas con Google Drive y moverlas rompería esa sincronización.

## Qué hay

| Carpeta | Qué es |
|---|---|
| **agentes/** | Repositorio git con todo el código: los agentes, los scripts del pipeline y el esquema de la base |
| **datos/** | Datos de trabajo locales (logs de instalación, material temporal). No es la base de datos: esa la administra MariaDB en su propio `datadir` |

## Cómo se corre algo

Siempre con el entorno virtual del proyecto, nunca con el Python del sistema:

```bash
cd "D:\INDUSTECH IA\desarrollo\agentes"
.venv/Scripts/python.exe scripts/<script>.py
```

## Mapa del código (`agentes/`)

| Carpeta | Contenido |
|---|---|
| `scripts/` | Todo el pipeline y los agentes, un archivo por tarea del plan |
| `sql/` | Esquema de la base (`001_esquema_inicial.sql`) |
| `config/` | `.env` con las credenciales — **fuera de git**, no lo publiques |
| `.venv/` | Entorno virtual de Python 3.12 — fuera de git |

### Los scripts, por orden de aparición

Los nombres siguen la tarea del plan que implementan, así que el orden alfabético es casi el cronológico.

| Script | Qué hace |
|---|---|
| `t1_1_inventario.js` · `t1_3_verificacion.js` | Inventario con hash del origen y verificación de la copia |
| `t1_5_importar_maestro_locales.py` | Maestro de locales, con verificación cruzada contra la hoja GENERAL |
| `importar_avisos_sap.py` · `importar_tecnicos.py` | Catálogo de avisos de SAP y nómina de técnicos |
| `t1_6_saneamiento.py` · `t1_6_ejecutar.py` | Saneamiento del corpus: análisis primero, ejecución después |
| `t1_6_muestra_verificacion.py` | Contrasta una muestra de renombrados contra el contenido del PDF |
| `t1_6b_indice_planes.py` | Extrae de los planes de la administración las referencias a cada OT |
| `t1_6b_resolver_cuarentena.py` | Decide a qué local pertenece cada documento dudoso, cruzando cuatro fuentes |
| `t1_6b_verificar_resolucion.py` | **Las 5 comprobaciones que hay que pasar antes de mover un solo archivo** |
| `t1_6b_ejecutar_resolucion.py` | Copia, verifica por hash y actualiza la base |
| `t1_6b_marcar_ids_supersedidos.py` | Marca las filas que quedaron duplicadas por el renombrado |
| `t1_6c_corregir_fechas.py` | Recupera del PDF las fechas que el documento traía mal |
| `t1_6d_otros_clientes.py` | Separa los trabajos que no son del Grupo KFC |
| `t1_6e_altas_maestro.py` | Altas al maestro y alias de códigos mal escritos |
| `t1_7_extractor_pdf.py` · `t1_7_ingesta.py` | Extracción por patrones e ingesta idempotente |
| `agente1_auditor_calidad.py` | Agente 1: reglas de calidad sobre los datos ya cargados |
| `agente2_consolidador.py` · `agente2_comparar.py` | Agente 2: plan de zona, y su comparación celda a celda contra el real |

**Antes de correr cualquier cosa que escriba**, lee §5 de [`../ESTADO.md`](../ESTADO.md): hay un orden obligatorio para el ciclo de re-procesamiento y reglas de qué puede correr en paralelo.
