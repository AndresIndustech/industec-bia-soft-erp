# Captura de OT — formulario único (v1 para revisión)

**Qué es:** la primera de las tres superficies del sistema nuevo
([`DISENO_APP_OTS.md`](../../../DISENO_APP_OTS.md) §2) — la que usan los técnicos
en el celular. Reemplaza a los **tres** formularios que hoy conviven en
producción (`ot_normal_v3`, `ot_mantenimiento`, `ot_normal_otros`) por uno solo,
según [`ESPECIFICACION_OT_UNICA.md`](../../../ESPECIFICACION_OT_UNICA.md).

**Estado:** v1 **para revisar la interfaz**. Valida y arma el resumen; todavía
**no** persiste en base, **no** genera PDF y **no** envía correo. Eso es
T2.1.1–T2.1.6 y necesita la migración `003` aplicada.

## Correr en local

```bash
cd "D:\INDUSTECH IA\desarrollo\sistema_ots\app\publico"
"D:\SOFTWARE\PHP83\php.exe" -S 127.0.0.1:8021
```

Luego `http://127.0.0.1:8021/index.html`. El PHP local sirve
[`catalogos.php`](catalogos.php), que lee los JSON que ya generó
`t2_5_catalogos.py` en `SALIDAS IA\OTS\catalogos\` (100 locales, 1.173 activos,
222 tipos, 19 técnicos).

- `index.html?local=K146EC` — preselecciona un local (útil para revisar el
  comportamiento en cascada).
- `index.html?diag=1` — muestra en el pie si algún elemento se desborda del ancho.

## Archivos

| Archivo | Qué hace |
|---|---|
| `index.html` | El formulario. Sin framework: se mantiene leyéndolo entero |
| `estilo.css` | Lenguaje visual heredado **exacto** de los formularios de hoy (I-8) |
| `reglas.js` | Las 30 reglas del formato único, del lado del navegador |
| `reglas.fixture.mjs` | Corre el fixture compartido contra `reglas.js` |
| `app.js` | Catálogos, listas en cascada, bloques repetibles, firma, validación |
| `catalogos.php` | Sirve los catálogos (local: JSON de SALIDAS IA; producción: MySQL) |

## Las reglas viven ahora en TRES lados — y el fixture las ata

`reglas.js` (navegador) · `nucleo/Validacion.php` (servidor, **la que manda**) ·
`agentes/scripts/t2_5_validacion.py` (ingesta e histórico). Los tres corren
`pruebas/fixture_validacion.json`. Si uno se separa, el fixture lo delata:

```bash
node reglas.fixture.mjs
"D:\SOFTWARE\PHP83\php.exe" ../pruebas/validacion_test.php
cd ../../../agentes && .venv/Scripts/python.exe scripts/t2_5_validacion.py --fixture
```

Los tres deben decir **32/32**. Ninguna validación de cliente reemplaza a la del
servidor (I-13): el navegador valida para que el técnico se entere en el momento,
detrás de la freidora, y no después de subir 35 fotos.

## Lo que cambia respecto al formulario de hoy

Ver [`SALIDAS IA\OTS\REVISION_CAPTURA_v1.md`](../../../SALIDAS%20IA/OTS/REVISION_CAPTURA_v1.md)
— campo por campo, qué se conserva, qué cambia y por qué, y las decisiones que
faltan.
