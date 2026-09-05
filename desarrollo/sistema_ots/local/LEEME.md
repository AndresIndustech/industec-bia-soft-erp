# Consola local de OTs

Interfaz para revisar lo que hay en la base sin abrir carpetas: buscar una
orden, verla completa, abrir su PDF e imprimirlo, y sacar a Excel exactamente
lo que se acaba de filtrar.

## Arrancarla

```bash
cd "D:\INDUSTECH IA\desarrollo\sistema_ots\local"
..\..\agentes\.venv\Scripts\python.exe app.py
```

Y abrir <http://127.0.0.1:8010>. Para cerrarla, `Ctrl+C`.

## Qué tiene

| Pantalla | Para qué |
|---|---|
| **Buscar** | Filtros por zona, tipo, local, estado según SAP, rango de fechas, número de aviso y texto libre. El texto libre busca dentro de actividades, repuestos, observaciones, técnico y nombre del local |
| **Tablero** | Totales por zona, reparto por estado de SAP, y órdenes por día de los últimos 30 |
| **Detalle** | Una orden completa: identificación, atención, lo que dice SAP, equipos, actividades, repuestos y observaciones. El botón *Imprimir* deja una hoja limpia, sin filtros ni menús |
| **Exportar a Excel** | Baja **lo mismo que se está viendo**, sin el límite de 300 filas de la pantalla. Es lo que se manda a KFC |

## Dos columnas de estado, y no significan lo mismo

- **`Estado según SAP`** — lo que declara SAP. **Es el único criterio de cierre.**
  Para backlog, SLA o reportes a KFC, esta.
- **`Marcó el técnico`** — lo que se eligió en el formulario. Información
  complementaria; el detalle lo dice explícitamente al lado del valor.

Mezclarlas fue lo que sobre-contó el pendiente 8 veces en la Fase 1. Por eso van
separadas y con estos nombres.

## Decisiones de seguridad, y por qué

**Solo escucha en `127.0.0.1`.** No pide contraseña, así que no puede quedar
expuesta a la red. Si más adelante INDUSTEC necesita entrar desde fuera, primero
se le pone autenticación y después un túnel — nunca al revés.

**Es de solo lectura.** No hay un solo `INSERT`, `UPDATE` ni `DELETE`. Corregir
datos se hace por los scripts, que dejan bitácora.

**Todo filtro va como parámetro ligado**, nunca concatenado a la consulta. Vale
igual que en un servidor público: un campo de búsqueda mal armado destruye la
base que costó la Fase 1.

**Abrir un PDF pasa por una lista de raíces permitidas.** La ruta sale de la
base, no de la URL, y aun así se comprueba que cuelgue de `D:\RESPALDOS`. Si un
día alguien escribe una ruta arbitraria en `ruta_pdf`, la consola no la abre.

## Si algo falla

| Síntoma | Causa y arreglo |
|---|---|
| `Address already in use` | Ya hay una consola corriendo. Ciérrala, o mira el puerto: `Get-CimInstance Win32_Process -Filter "Name='python.exe'"` |
| Error de conexión a la base | MariaDB no está arriba, o `config/.env` cambió. Comprueba con `curl http://127.0.0.1:8010/salud` |
| El tablero no muestra el gráfico de 30 días | Es lo esperado mientras no corra la sincronización con Hostinger: no hay órdenes recientes |
| El PDF no abre | La orden no tiene `ruta_pdf`, o el archivo no está en el disco. El mensaje dice cuál de las dos |
