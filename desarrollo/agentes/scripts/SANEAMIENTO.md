# Saneamiento nocturno de la estación (T2.15)

Cada noche la estación deja a salvo, verificado por hash, todo lo que el sistema viejo y la app nueva produjeron durante el día, y lo lleva a la base y al sitio. Lo hace **un solo orquestador**, `saneamiento_nocturno.py`, que corre los pasos en orden y **aborta al primer fallo**.

| # | Paso | Script | Qué deja |
|---|---|---|---|
| 1 | sync | `t2_4_sync_hostinger.py` | `RESPALDOS\_ORIGEN_SISTEMA\<modulo>\` (sistema viejo) y `RESPALDOS\_ORIGEN_APP\{pdf,fotos}\` (app nueva), con manifiesto en `_manifiestos\`. Un PDF divergente va a `_divergentes\<nombre>.<sha12>.pdf`, nunca encima del que ya estaba |
| 2 | volcado | `t2_4_volcado_bd.py` | `RESPALDOS\_ORIGEN_APP\_bd\volcado_<sello>.sql.gz` + `.sha256`, verificado contra el hash del servidor; 30 diarios + 12 mensuales |
| 3 | normalizar | `t2_4_normalizar_nuevas.py --ejecutar` | El espejo crudo promovido al árbol canónico `RESPALDOS\ORDENES DE TRABAJO\` |
| 4 | ingesta | `t1_7_ingesta.py` | La base de la estación (`ots`, `ot_equipos`) al día; ignora toda carpeta que empiece por `_` |
| 5 | informes | `t2_11_informes_ot.py --empujar` | `atenciones.json` en el sitio; los PDF del buzón en `RESPALDOS\_ORIGEN_BUZON\` |
| 6 | archivo | `t2_15_exportar_archivo.py --desde-mariadb --empujar` | El catálogo histórico en el índice del archivo general del sitio (`ot_archivo`) |

Salidas de cada corrida:

- `logs\saneamiento-<fecha>.log` (con `--log`, que es como lo lanza la Tarea).
- `SALIDAS IA\OTS\estado_nocturno.json`: el semáforo (`ok`, duración, cada paso con su código y sus últimas líneas). La consola local lo muestra a la mañana siguiente.
- Una fila en la `bitacora` de la estación (`agente = saneamiento`, `tarea = T2.15`).
- El candado `logs\saneamiento.lock` evita dos corridas a la vez; uno abandonado (más de 6 h) se pisa.

## Requisitos en la estación

- `config\.env` con `DB_HOST/DB_PORT/DB_USER/DB_PASSWORD/DB_NAME` (base local), `SSH_USER` (hPanel > Avanzado > Acceso SSH), `SYNC_URL` y `SYNC_SECRETO` (para empujar), `IMAP_*` (para los informes), y opcionalmente `RESPALDOS_DIR` (por defecto `D:\RESPALDOS`), `SEGUNDA_COPIA` (el equipo Veeam/TrueNAS; sin ella la purga solo informa) y `PURGA_RETENCION_DIAS` (90 hasta D+30 del corte).
- La llave SSH en `config\clave_hostinger` (o `INDUSTEC_LLAVE_SSH` en el entorno). Probar con:

```bat
.venv\Scripts\python.exe scripts\hostinger_ssh.py --probar
```

## Tarea programada

Desde una consola con permisos de administrador, en `desarrollo\agentes`:

```bat
schtasks /create /tn "INDUSTEC - Saneamiento nocturno" ^
  /tr "\"%CD%\scripts\saneamiento_nocturno.bat\"" ^
  /sc daily /st 02:30 /ru SYSTEM /rl HIGHEST /f
schtasks /change /tn "INDUSTEC - Saneamiento nocturno" /ri 30 /du 02:00
```

- `/ru SYSTEM` la corre aunque nadie haya iniciado sesión.
- `/ri 30 /du 02:00`: si a las 02:30 la estación estaba apagada o el paso falló por red, reintenta cada 30 minutos durante dos horas. El candado impide que dos reintentos se pisen.
- Comprobar: `schtasks /query /tn "INDUSTEC - Saneamiento nocturno" /v /fo list` y, a la mañana, `type "SALIDAS IA\OTS\estado_nocturno.json"`.

## Correr a mano

```bat
.venv\Scripts\python.exe scripts\saneamiento_nocturno.py --ensayo            # qué correría
.venv\Scripts\python.exe scripts\saneamiento_nocturno.py --solo sync volcado # solo dos pasos
.venv\Scripts\python.exe scripts\saneamiento_nocturno.py                     # todo, por pantalla
```

## La purga es aparte, y sigue pidiendo autorización del momento

`t2_4_purga_hostinger.py` **no** está en la cadena nocturna (regla I-2). Solo informa mientras no exista `SEGUNDA_COPIA`, y para borrar exige `--ejecutar --confirmo-borrado` y cinco compuertas por archivo (copia local, segunda copia, hash en `ots.hash_pdf`, retención, `sha256sum -c` en el servidor en el instante del borrado).

## Sembrar los correlativos al corte (T2.16)

`t2_14_sembrar_correlativos.py` lee los contadores del sistema viejo (solo lectura) y deja en `correlativos` de la app el último número de cada serie, para que la primera orden del sistema nuevo continúe la numeración. Por defecto es un ensayo; escribe solo con `--ejecutar`, y es un paso del corte, no de la noche.
