---
name: industec-despliegue-web
description: Invocala antes de desplegar codigo del sistema_ots (desarrollo/sistema_ots/app) al sitio de pruebas darkviolet, antes de fusionar una rama que toque publico/, y antes de correr cualquier bateria de servidor (verificar_*.py, verificar_*.mjs, prueba_*.mjs).
---

# Desplegar y probar el sistema de OTs en darkviolet

Sitio de pruebas: `darkviolet-armadillo-872352.hostingersite.com`, ruta
`public_html/ot/`. Desplegador: `desarrollo/agentes/scripts/t2_10_desplegar.py`.
Arnes de servidor: `desarrollo/sistema_ots/app/pruebas/servidor/`.

## 1. Que archivos subir, despues de fusionar una rama

**La lista sale de `git diff <ultimo-commit-desplegado>..HEAD --name-only --
desarrollo/sistema_ots/app/publico/`, nunca de memoria de cuales archivos
tuvieron conflicto de fusion.** Un archivo puede cambiar en la fusion y
fusionarse SOLO, sin marcador de conflicto — y aun asi tiene que subir.

Encontrado el 2026-09-29 (error 52 del plan): al desplegar T2.28.7 se
subieron los 8 archivos que habian tenido conflicto mas dos que se
recordaban a mano (`cola.js`, `foto.php`). Se olvido `nucleo/plantilla_ot.php`
—cambio, pero se fusiono sin conflicto—. El sintoma fue enganoso: el codigo
que arma los datos (`Emision::html()`) funcionaba perfecto (confirmado con
trazas dentro del propio metodo), pero el PDF salia incompleto porque la
plantilla que lo imprime era la de antes. Ni `php -l` ni
`verificar_esquema.php` lo detectan: el archivo compila y el esquema de base
no cambio.

```bash
git diff <base>..HEAD --name-only -- desarrollo/sistema_ots/app/publico/
```

Esa es la lista completa a pasarle a `t2_10_desplegar.py` (rutas relativas a
`publico/`).

## 2. Verificar que lo desplegado es lo que se subio

`t2_10_desplegar.py` compara por hash lo que sube contra lo que la web
entrega, pero **`--comprobar-web` solo mira `.js`, `.css`, `.html` y
`manifest.json`** (por el CDN de Hostinger, que puede servir una copia vieja
horas despues). **No dice nada de si PHP-FPM realmente cargo un `.php`
nuevo.** Para un `.php`, comparar el sha256 local contra el del servidor
directo por SSH:

```bash
sha256sum archivo.php
ssh ... "sha256sum domains/darkviolet-.../public_html/ot/archivo.php"
```

**Cuando el codigo "se ve bien" pero se comporta distinto en el servidor
que en la lectura del archivo: la primera sospecha es un archivo que falto
en la lista de despliegue, no el opcache ni una condicion de carrera.**
Descartarlo con el hash de cada archivo tocado, ANTES de gastar tiempo
poniendo trazas de depuracion — al reves cuesta media hora (pasó el
2026-09-29).

## 3. El arnes de servidor es de un solo uso por bateria

Ciclo obligatorio, siempre en este orden:

```bash
php ~/respaldos/limpiar_pruebas.php --ejecutar   # 1. simulacro: da las cifras
php ~/respaldos/limpiar_pruebas.php --ejecutar='<cifras tal cual las dio el simulacro>'   # 2. limpia de verdad
php ~/respaldos/preparar_prueba.php              # 3. siembra cuentas y casos sinteticos frescos
python verificar_XXX.py                          # 4. UNA bateria
```

**Correr una segunda bateria (o la misma dos veces) sin repetir los pasos
1-3 ensucia estado compartido** entre corridas —`locales_admin.correo_veces`
se acumula, `casos_gestion` de 99990021/99990022 queda en un estado que la
siguiente bateria no espera, `ot_capturadas` crece— **y produce fallas
falsas que no tienen relacion con el codigo que se esta probando.** Medido
el 2026-09-29: una falla real («aparece en su historial con su PDF») se
volvio 5 fallas de otra bateria (`verificar_ciclo.py`) al correrla sin
re-preparar, y desaparecieron limpiando y preparando de nuevo antes de
correrla sola.

**Regla:** una bateria por cada limpiar+preparar. Si hay que correr varias,
se repite el ciclo completo para cada una, no se encadenan sobre el mismo
arnes.

## 4. El sitio de pruebas es "del piloto" desde el 28-sep-2026

Toda orden que sale del sitio de pruebas tiene `$piloto = true`
(`envio.php`). Esa condicion apaga a proposito, no por bug:

- `Pendientes::resolverPorOrden()` — una orden del piloto nunca resuelve un
  pendiente sola.
- La cadena completa de `Casos::atenderPorOrden()` sobre avisos enlazados
  (`continua_de`) — el piloto solo atiende el aviso propio, no arrastra la
  cadena.
- La coletilla vieja "el correo no salio (sistema en pruebas)" — ahora dice
  "es del piloto: NO llego a Grupo KFC ni al local".

Motivo (comentario en `envio.php`, lineas 656-667): doce ordenes del piloto
se habian cerrado en SAP con un numero que KFC nunca recibio.

**Si una bateria de servidor espera que una orden del sitio de pruebas
cierre un caso o resuelva un pendiente por su cuenta, va a fallar — no es
un defecto nuevo, es que esa bateria se escribio antes del 28-sep y nadie
la volvio a correr contra la base fusionada.** Antes de tocar el codigo,
comprobar en `envio.php` si la funcion que la prueba espera esta detras de
un `!$piloto`.

## 5. Fusionar dos ramas que tocan los mismos archivos del sistema_ots

Cuando ambos lados de un conflicto insertan codigo **distinto y sin
relacion de contenido** en el mismo punto (por ejemplo, una funcion de
seguridad del piloto y una funcion nueva de otra tarea, ambas agregadas
justo despues del mismo metodo), el criterio no es elegir un lado: se
conservan los dos, uno despues del otro. Verificar con `node --check`
(`.js`) y con el balance de llaves (`awk` contando `{`/`}`, ya que no hay
`php -l` en la estacion) antes de dar la fusion por resuelta.

## 6. Comando de verificacion exacto

```bash
# Antes de desplegar: que archivos cambiaron desde el ultimo despliegue confirmado
git diff <base>..HEAD --name-only -- desarrollo/sistema_ots/app/publico/

# Tras desplegar un .php: hash local vs remoto, uno por uno
sha256sum <archivo> ; ssh ... sha256sum <ruta remota>

# El ciclo completo de una bateria
php ~/respaldos/limpiar_pruebas.php --ejecutar
php ~/respaldos/limpiar_pruebas.php --ejecutar='<cifras>'
php ~/respaldos/preparar_prueba.php
python verificar_XXX.py   # o node verificar_XXX.mjs
```
