# Proyecto INDUSTEC — instrucciones del proyecto

## Qué es esto

Automatización de la gestión de órdenes de trabajo (OTs) para **INDUSTEC**, empresa ecuatoriana de soporte técnico HORECA cuyo cliente principal es **Grupo KFC Ecuador** (95% de sus ingresos), con ~100 locales en tres zonas: UIO (Quito), LARB (Latacunga-Ambato-Riobamba-Baños) y CNLJ (Cuenca-Loja).

**Quién es quién:** el usuario de estas sesiones es **Andrés Basantes**, de INDUSTECH SOLUTIONS S.A.S., que **presta** el servicio. Su cliente es **INDUSTEC** (gerente: César Basantes), que lo **recibe**. Rige la cotización **C26-115**: tres fases de un mes, desde el 2 de septiembre de 2026.

## Antes de hacer nada

1. Lee **[`ESTADO.md`](ESTADO.md)** — qué está hecho, qué sigue, y qué está tocando cada conversación en paralelo.
2. Consulta **[`PLAN_INDUSTEC.md`](PLAN_INDUSTEC.md)** para la tarea concreta: cada una trae su criterio de aceptación y su tabla *autónomo / requiere aprobación / prohibido*.
3. Si vas a ejecutar algo que escriba en la base o mueva archivos, **anótate en §5.1 de `ESTADO.md`** primero.

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
