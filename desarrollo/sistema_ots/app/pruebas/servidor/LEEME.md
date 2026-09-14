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
| `preparar_prueba.php` | servidor, desde `ot/` | Crea o renueva 5 cuentas de prueba, asigna 2 casos de UIO al técnico A, le crea 2 avisos sintéticos fuera del catálogo (99990011 abierto y 99990012 atendido sin orden de cierre) y deja datos de prueba en UIO y CNLJ | Sí, y todo queda en `~/respaldos/prueba_deshacer.json` |
| `deshacer_prueba.php` | servidor, desde `ot/` | Revierte lo anterior: desactiva las cuentas, borra lo que crearon (incluida su copia ya indexada en `ot_archivo`, corregido el 2026-09-13) y los avisos sintéticos, y devuelve los casos | Sí |
| `alcance_cli.php` | servidor, desde `ot/` | Qué ve un usuario, con las mismas funciones que las pantallas | No |
| `verificar_http.py` | PC o estación | Entra con las cuentas de prueba y comprueba pantallas, alcance, POST fabricados y la orden de la app | Sí: órdenes de prueba de las cuentas de prueba |
| `verificar_ciclo.py` | PC o estación | El ciclo completo de un caso: trabado → veredicto → vía → resuelto | Sí: un pendiente de prueba |
| `verificar_bandeja.py` | PC o estación | T2.13.2, T2.13.3 y T2.13.5: el formulario le ofrece al técnico solo sus casos abiertos; la bandeja y el historial salen de la base, con los casos que no están en el catálogo del buzón; y el buzón de avisos le cuenta lo suyo (le asignan o le quitan un caso, le responden, le resuelven una novedad) | Sí: una orden y una novedad de prueba, una respuesta en el hilo del pendiente de prueba, y el caso de prueba de A pasado a B y devuelto |
| `hora_ecuador.php` | servidor, desde `ot/` | T2.13.7: pasa a hora de Ecuador, una sola vez, las fechas que el servidor guardó en UTC (todas las DATETIME menos `atendido_en`) | Sin `--si` solo dice lo que haría; se niega a correr dos veces o en una base que no escribía en UTC, y revierte si alguna fecha queda en el futuro |
| `verificar_emision.py` | PC o estación | La 008: fotos por `foto.php`, la orden emitida con su número de la serie de pruebas, su PDF (quién lo abre y quién no), el correo retenido en la cola, el reintento sin número nuevo y 10 reservas simultáneas sin repetir (T2.1.5) | Sí: una orden emitida con su PDF y dos fotos |
| `prueba_cola_vivo.mjs` | PC o estación, con Edge o Chrome | La cola sin servidor y con la sesión caída, con un navegador de verdad (T2.12.7 y T2.12.8), y que el celular reciba el `sw.js` vigente | Sí: dos órdenes de prueba |

## Uso

```bash
# 1. Subir las herramientas del servidor a ~/respaldos (fuera de la web)
scp -P 65002 -i <llave> *.php u671729428@82.25.73.181:respaldos/

# 2. Preparar (en el servidor)
cd domains/darkviolet-armadillo-872352.hostingersite.com/public_html/ot
php ~/respaldos/preparar_prueba.php

# 3. Probar (desde el PC o la estación)
python verificar_http.py
python verificar_ciclo.py
python verificar_bandeja.py
python verificar_emision.py
node prueba_cola_vivo.mjs

# 4. Deshacer (en el servidor) al terminar
php ~/respaldos/deshacer_prueba.php
```

La llave SSH sale de `INDUSTEC_LLAVE_SSH`; si no está, de la estación
(`desarrollo/agentes/config/clave_hostinger`) o de la del PC de Andrés
(`~/.ssh/industec_hostinger_pc`). Las claves de las cuentas de prueba se generan al azar, viven solo
en `~/respaldos/claves_prueba.json` (0600) y los scripts de Python las leen por SSH, sin guardarlas.

## Cuidado

- `preparar_prueba.php` asigna al técnico de prueba **dos casos reales de UIO**: las pruebas de la
  orden necesitan el local y los equipos que trae el catálogo del buzón. Se devuelven a como
  estaban con `deshacer_prueba.php`. No dejarlo corriendo sin necesidad. Desde T2.13.3 crea además
  avisos sintéticos `9999xxxx`, que no existen en SAP y se borran enteros al deshacer.
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
