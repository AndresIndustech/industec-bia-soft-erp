# Proyecto INDUSTEC — instrucciones del proyecto

## Qué es esto

Automatización de la gestión de órdenes de trabajo (OTs) para **INDUSTEC**, empresa ecuatoriana de soporte técnico HORECA cuyo cliente principal es **Grupo KFC Ecuador** (95% de sus ingresos), con ~100 locales en tres zonas: UIO (Quito), LARB (Latacunga-Ambato-Riobamba-Baños) y CNLJ (Cuenca-Loja).

**Quién es quién:** el usuario de estas sesiones es **Andrés Basantes**, de INDUSTECH SOLUTIONS S.A.S., que **presta** el servicio. Su cliente es **INDUSTEC** (gerente: César Basantes), que lo **recibe**. Rige la cotización **C26-115**: tres fases de un mes, desde el 2 de septiembre de 2026.

## El ciclo de toda conversación — se abre leyendo el plan y se cierra actualizándolo

Este proyecto se ejecuta en conversaciones que no se conocen entre sí. Lo único
que las une son estos dos documentos, así que **leerlos al empezar y dejarlos al
día al terminar no es burocracia: es la única continuidad que hay.**

### Antes de hacer nada

1. Lee **[`PLAN_INDUSTEC.md`](PLAN_INDUSTEC.md)** — empieza por **§11b «Arranque
   para una conversación nueva»**, que dice en un párrafo dónde está parado el
   proyecto, cuál es la siguiente acción concreta, qué está bloqueado y por
   quién, y los errores que este proyecto **ya pagó** y no hay que repetir.
   Después, la tarea que te toque: cada una trae su criterio de aceptación y su
   tabla *autónomo / requiere aprobación / prohibido*.
2. Lee **[`ESTADO.md`](ESTADO.md)** §1 y §1b — qué funciona hoy, con su cifra
   verificada. El plan dice qué construir; `ESTADO.md` dice qué se construyó.
3. **Anótate en §5.1 de `ESTADO.md`** antes de tocar nada, y bórrate al
   terminar. Si esa tabla tiene filas, hay otra conversación trabajando: mira
   §5.2 para saber si choca con lo tuyo.
4. Invoca la skill **`industec-invariantes`** antes de la primera línea de código.

### Al terminar — obligatorio, no opcional

Una tarea no está cerrada hasta que la próxima conversación pueda continuarla
sin preguntarte nada. Antes de dar por terminado:

1. **Actualiza `PLAN_INDUSTEC.md`.** Si la tarea quedó hecha, márcala y escribe
   la siguiente con su criterio de aceptación y su tabla de permisos. Si quedó a
   medias, di **exactamente** en qué subtarea y qué falta. Y actualiza **§11b**:
   la siguiente acción concreta, lo que se desbloqueó y lo que sigue bloqueado.
2. **Actualiza `ESTADO.md`** con lo que quedó funcionando **y su cifra
   verificada**, no con una descripción. Bórrate de §5.1.
3. **Pega la evidencia.** El comando que comprueba el criterio de aceptación y
   su salida literal. Nada «a medias» ni «debería funcionar».
4. **Si aprendiste un error que costó tiempo**, escríbelo en la lista de §11b
   del plan. Es la sección que evita que la siguiente conversación lo repita.
5. **Di también lo que NO se pudo comprobar.** Un «verificado» que en realidad
   fue «pasa el lint» es peor que no decir nada: la próxima conversación
   construye encima creyendo que hay suelo (I-7).

**Nunca dupliques un hecho entre los dos documentos.** El plan dice qué
construir y con qué criterio; `ESTADO.md` dice qué se construyó y qué cifra dio.
Dos versiones del mismo hecho se separan, y la que se queda atrás es la que
alguien lee.

## Reglas que no se violan

1. **`G:\Mi unidad` (Drive de INDUSTEC) es de solo lectura, indefinidamente.** Se estudia y se copia; jamás se altera.
2. **Nunca borres información del cliente sin que lo pida en el momento.** Aprobar un plan no autoriza ejecutar un borrado.
3. **No sobrescribas archivos de la administración.** Los agentes escriben a nombre nuevo; ella decide si promueve.
4. **Copiar → verificar por hash → recién entonces mover.** Nunca al revés.
5. **Verifica contra una fuente independiente antes de escribir** en la base o en un maestro, y aborta ruidosamente si no cuadra.
6. **Si no hay dato, se dice.** Ningún agente inventa ni completa por verosimilitud: lo que responda va a Grupo KFC.
7. **Software libre o gratuito**, el mejor de su categoría. Lo de pago se propone con justificación y costo antes de adquirir nada.
8. Se puede instalar sin pedir permiso, si es de fuente verificada y necesario. Se informa después.
9. **En Hostinger solo se tocan los archivos del sitio de pruebas** (`darkviolet-armadillo-872352`, `public_html/ot/`). Los demás sitios del panel —incluido el sistema en producción— no se modifican. **Ningún trámite que genere un cobro** (subir de plan, contratar un servicio, activar un extra de pago) lo hace un agente: se propone con el costo y lo ejecuta Andrés.

Los invariantes completos (I-1 a I-13), con su justificación, están en §1 de `PLAN_INDUSTEC.md`.

## Entorno

```bash
cd "D:\INDUSTECH IA\desarrollo\agentes"
.venv/Scripts/python.exe scripts/<script>.py     # siempre el venv, no el python del sistema
```

- **Base de datos:** MariaDB local, esquema `industec_ots`. Credenciales en `config/.env` (fuera de git).
- **Corpus canónico:** `D:\RESPALDOS\ORDENES DE TRABAJO\{año}\{módulo}\{zona}\{cadena}\`
- **Espejo intacto del origen:** `D:\RESPALDOS\_ORIGEN_DRIVE\` — nunca se modifica; es la red de seguridad.
- **Salidas para la administración:** `D:\INDUSTECH IA\SALIDAS IA\`
- **El repositorio git es la raíz del proyecto**, así que estos documentos y las skills también quedan versionados. `ENTRADAS IA`, `SALIDAS IA`, el venv y `config/.env` están fuera por `.gitignore`.

## Sincronía entre equipos (desde el 2026-09-10)

El proyecto se trabaja desde la estación y desde el PC de Andrés. Los une un repositorio
**privado** en GitHub: `https://github.com/AndresIndustech/industec-bia-soft-erp`.

- **Al empezar:** `git fetch origin` y `git branch -r`. Lo hecho desde el PC de Andrés llega en
  ramas `pc/…`: se fusiona en la estación **antes** de desplegar desde aquí, o el despliegue
  pisa en el servidor lo que ya se corrigió.
- **Al terminar:** confirmar y `git push`. Lo que no está en el remoto no existe para el otro equipo.
- Al remoto no va nada de `ENTRADAS IA`, `SALIDAS IA` ni credenciales (`.gitignore`).
- Cada equipo tiene su propia llave SSH para Hostinger, para poder revocarlas por separado.
  `t2_10_desplegar.py` usa la de `INDUSTEC_LLAVE_SSH` o, si no está, `config/clave_hostinger`.
- El servidor corre **PHP 8.2.33** y **MariaDB 11.8 en UTC** (medido el 2026-09-10). Las
  duraciones se calculan en SQL (`TIMESTAMPDIFF` con `NOW()`), nunca restando `strtotime()`.

## Skills disponibles

Invócalas según lo que vayas a hacer; están en `.claude/skills/`:

| Skill | Cuándo |
|---|---|
| **`industec-invariantes`** | **Siempre, al empezar cualquier tarea.** Rutas, permisos, invariantes y cómo se cierra una tarea |
| `industec-escritura-mysql` | Antes de cualquier `INSERT`/`UPDATE`/`DELETE` o cambio de esquema |
| `industec-lectura-excel` | Al leer un Excel de la administración o de SAP |
| `industec-extraccion-pdf` | Al extraer campos de un PDF por patrones |
| `industec-archivos-canonicos` | Al mover, renombrar o clasificar documentos del corpus |
| `industec-agentes-y-entregables` | Al construir un agente o un archivo que va a leer una persona |

## Cómo se escribe aquí

En español, directo y sin relleno. Los comentarios del código explican **por qué**, citando el caso real que motivó la regla — no repiten lo que el código ya dice. Los mensajes de commit narran qué se rompió y por qué se decidió así.
