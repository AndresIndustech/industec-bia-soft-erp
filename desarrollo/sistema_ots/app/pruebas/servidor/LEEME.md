# Pruebas contra el sitio de pruebas (Hostinger)

Lo que se despliega se prueba **en el servidor**, no solo en la estación: el 2026-09-11 la
intercalación de Hostinger tumbaba con error 500 el veredicto y los cierres, y en la base de la
estación no se veía (PLAN, errores pagados nº 12).

Solo sirven para el sitio de pruebas (`darkviolet-armadillo-872352`). Nada de esto se corre contra
producción.

## Qué hay

| Archivo | Dónde corre | Qué hace | ¿Escribe? |
|---|---|---|---|
| `verificar_007.php` | servidor, desde `ot/` | Los dos bloques de verificación del pie de la 007, con PASA/FALLA | No: sus pruebas de escritura van en una transacción que se revierte |
| `revertir_007.php` | servidor, desde `ot/` | Deshace la 007 (solo si algo salió mal) | Sin `--si` solo dice lo que haría; se niega si las tablas tienen filas, salvo `--forzar` |
| `preparar_prueba.php` | servidor, desde `ot/` | Crea o renueva 5 cuentas de prueba y le da al técnico A **cuatro avisos sintéticos, nunca un caso real** (T2.28.1): 99990021 y 99990022 CON local (G007EC, G018EC — en `catalogos/casos_prueba.json`, que `Casos::catalogo()` solo fusiona para una cuenta de prueba o por CLI; 99990022 queda además en ESPERA_REPUESTO con un pendiente), y 99990011/99990012 fuera de todo catálogo (T2.13.2/T2.13.3). Deja además datos de prueba en UIO y CNLJ | Sí, y todo queda en `~/respaldos/prueba_deshacer.json` |
| `deshacer_prueba.php` | servidor, desde `ot/` | Revierte lo anterior: desactiva las cuentas, borra lo que crearon (incluida su copia ya indexada en `ot_archivo`, corregido el 2026-09-13) y los avisos sintéticos, y devuelve los casos. Más simple que `limpiar_pruebas.php`: no retira las cuentas ni `casos_prueba.json` | Sí |
| `limpiar_pruebas.php` | servidor, desde `ot/` | El ciclo completo (T2.28.1): además de lo que revierte `deshacer_prueba.php`, retira las 5 cuentas, `catalogos/casos_prueba.json`, los seguimientos y todo lo demás que dejó el arnés. Simulacro por omisión; `--ejecutar='<json>'` (las cifras que imprimió el simulacro) borra, y aborta sin tocar nada si una sola cifra cambió desde entonces | Sí, solo con `--ejecutar` y cifras exactas |
| `alcance_cli.php` | servidor, desde `ot/` | Qué ve un usuario, con las mismas funciones que las pantallas | No |
| `verificar_http.py` | PC o estación | Entra con las cuentas de prueba y comprueba pantallas, alcance, POST fabricados y la orden de la app | Sí: órdenes de prueba de las cuentas de prueba |
| `verificar_ciclo.py` | PC o estación | El ciclo completo de un caso: trabado → veredicto → vía → resuelto | Sí: un pendiente de prueba |
| `verificar_bandeja.py` | PC o estación | T2.13.2, T2.13.3 y T2.13.5: el formulario le ofrece al técnico solo sus casos abiertos; la bandeja y el historial salen de la base, con los casos que no están en el catálogo del buzón; y el buzón de avisos le cuenta lo suyo (le asignan o le quitan un caso, le responden, le resuelven una novedad) | Sí: una orden y una novedad de prueba, una respuesta en el hilo del pendiente de prueba, y el caso de prueba de A pasado a B y devuelto |
| `hora_ecuador.php` | servidor, desde `ot/` | T2.13.7: pasa a hora de Ecuador, una sola vez, las fechas que el servidor guardó en UTC (todas las DATETIME menos `atendido_en`) | Sin `--si` solo dice lo que haría; se niega a correr dos veces o en una base que no escribía en UTC, y revierte si alguna fecha queda en el futuro |
| `verificar_emision.py` | PC o estación | La 008: fotos por `foto.php`, la orden emitida con su número de la serie de pruebas, su PDF (quién lo abre y quién no), el correo retenido en la cola, el reintento sin número nuevo y 10 reservas simultáneas sin repetir (T2.1.5) | Sí: una orden emitida con su PDF y dos fotos |
| `verificar_archivo_pdf.py` | PC o estación | T2.28.17b: que el Archivo sea accesible **por la web**, no solo íntegro en disco (eso lo hace `archivo_verificar_cli.php`, T2.28.17a, por SSH). Muestra estratificada de 300 órdenes `en_servidor=1` (origen × zona × año); por cada una, `pdf.php?ot=…` con ADMIN y con TECNICO → 200/PDF, sin sesión → 302/401. Además localiza por grep los sitios que arman `pdf.php?ot=` en el sitio desplegado y anota si cada uno ofrece el enlace solo cuando el PDF existe | No: solo lee `ot_archivo` y pide PDFs, no envía nada |
| `prueba_cola_vivo.mjs` | PC o estación, con Edge o Chrome | La cola sin servidor y con la sesión caída, con un navegador de verdad (T2.12.7 y T2.12.8), y que el celular reciba el `sw.js` vigente | Sí: dos órdenes de prueba |
| `verificar_formulario.mjs` | PC o estación, con Edge o Chrome | T2.26: el formulario con gestos de verdad — preselección del equipo, el guiado paso a paso, la orden llenada sin señal, y que el navegador acepte el `sync` que la manda con la app cerrada. Se corre SOLA (error nº 31: otra batería con las mismas cuentas desplaza la sesión) | No: la cola se vacía antes de reconectar |

## Uso

```bash
# 1. Subir las herramientas del servidor a ~/respaldos (fuera de la web)
scp -P 65002 -i <llave> *.php u671729428@82.25.73.181:respaldos/

# 2. Preparar (en el servidor)
cd domains/darkviolet-armadillo-872352.hostingersite.com/public_html/ot
php ~/respaldos/preparar_prueba.php

# 3. Probar (desde el PC o la estación, en cualquier orden -- cada una usa su
#    propio aviso sintético, T2.28.1)
python verificar_http.py
python verificar_ciclo.py
python verificar_bandeja.py
python verificar_emision.py
python verificar_archivo_pdf.py
node verificar_formulario.mjs      # SOLA: ninguna otra batería a la vez con las mismas cuentas
node prueba_cola_vivo.mjs

# 4. Deshacer (en el servidor) al terminar -- el ciclo completo, cuentas incluidas
php ~/respaldos/limpiar_pruebas.php                      # simulacro: solo cuenta
php ~/respaldos/limpiar_pruebas.php --ejecutar='<json>'  # las cifras que imprimió el simulacro

# o, si solo hace falta deshacer lo mínimo (sin tocar las cuentas):
php ~/respaldos/deshacer_prueba.php
```

La llave SSH sale de `INDUSTEC_LLAVE_SSH`; si no está, de la estación
(`desarrollo/agentes/config/clave_hostinger`) o de la del PC de Andrés
(`~/.ssh/industec_hostinger_pc`). Las claves de las cuentas de prueba se generan al azar, viven solo
en `~/respaldos/claves_prueba.json` (0600) y los scripts de Python las leen por SSH, sin guardarlas.

## Cuidado

- Hasta el 2026-09-23 `preparar_prueba.php` asignaba al técnico de prueba **dos casos reales de
  UIO** (las pruebas de la orden necesitan el local y los equipos que trae el catálogo), y así
  quedaron secuestrados los avisos 10355931 y 10356012: un jefe de zona de verdad dejó de verlos en
  su buzón. **Corregido (T2.28.1): ya no toca ningún caso que no empiece por 9999.** Los avisos con
  local (99990021, 99990022) son sintéticos y viven en `catalogos/casos_prueba.json`, que
  `limpiar_pruebas.php` borra; los otros dos (`99990011`, `99990012`) siguen sin local, fuera de todo
  catálogo. `deshacer_prueba.php` (más simple, solo desactiva cuentas) ya no limpia `casos_prueba.json`
  ni sus filas: usa `limpiar_pruebas.php` para un ciclo completo.
- **`deshacer_prueba.php` no limpiaba `ot_archivo`** (hallazgo del 2026-09-13, al retirar los datos
  de prueba antes del piloto): la pantalla «Archivo» se llena aparte, con `archivo_indexar_cli.php`,
  y esas filas sobrevivían al deshacer con el PDF ya borrado del disco — un enlace roto. Corregido:
  ahora borra de `ot_archivo` (solo `origen = 'APP'`) los mismos `id_industec` que borra de
  `ot_capturadas`. Si vuelve a pasar (por ejemplo tras una prueba corrida sin `preparar_prueba.php`
  de por medio), verificar con `SELECT origen, tecnico, COUNT(*) FROM ot_archivo WHERE tecnico LIKE
  'Prueba %' GROUP BY 1,2` antes de dar el sitio por limpio.
- Agrega los técnicos de prueba a `catalogos/tecnicos.json`, con copia previa: `envio.php` exige
  que quien firma esté en el padrón. Si la estación empuja el padrón en medio, se pierden y la
  orden de prueba da 400; basta con volver a preparar.
- En el PC de Andrés el antivirus inspecciona el HTTPS; los scripts lo toleran sin dejar de
  verificar la cadena del certificado.
