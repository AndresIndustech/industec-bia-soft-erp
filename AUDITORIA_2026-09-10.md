# Auditoría del 2026-09-10 — antes de seguir con el plan

**Pedido de Andrés:** seguir con el plan, pero antes auditar todo y corregir lo que se haya pasado.
**Desde dónde:** el PC de Andrés, sobre una copia del proyecto (instantánea de la estación del
2026-09-10, ~19:00) y contra el servidor en **solo lectura**. Rama `pc/auditoria-2026-09-10` en el
repositorio privado `AndresIndustech/industec-bia-soft-erp`.

> Este documento es el informe. Lo que cambió en el estado va en `ESTADO.md` y lo que sigue en
> `PLAN_INDUSTEC.md` (T2.12 corregida, T2.13 nueva y §11b). No se duplica aquí.

---

## 1. Cómo se hizo

1. **Evidencia en vivo, sin tocar nada** (SSH y `curl`): versiones desplegadas por hash, esquema
   y conteos de la base, extremos sin sesión, zona horaria, permisos de la cuenta de la base.
2. **Siete frentes auditados por agentes independientes**: seguridad, app del técnico,
   migraciones, estación, lógica de negocio, documentación y requisitos nuevos. **Cada hallazgo
   pasó por un verificador escéptico** que intentó refutarlo leyendo el código: 70 hallazgos,
   **ninguno refutado** (38 confirmados tal cual, 32 matizados en gravedad) y 9 más agregados por
   los verificadores.
3. **Correcciones en código**, verificadas con `php -l` en el PHP 8.2 del servidor (por stdin, sin
   escribir allí), `node --check`, las pruebas `.mjs` y pruebas funcionales de solo lectura en el
   servidor (§5).

**Lo que NO se pudo comprobar:** no hay PHP ni base local en este PC (`prueba_48h.php` no se
corrió); `prueba_offline.mjs` necesita Chrome con el servidor apagado; nada se probó con un
usuario real, porque 18 de 19 cuentas siguen con la clave inicial y no se usa la clave de una
persona. La **zona horaria del PHP de la web** se midió después, esa misma noche: UTC, igual que
la base y que el PHP de línea de órdenes (ver §6, «La hora»).

---

## 2. Lo que estaba vivo y se cerró el mismo día

| Qué | Antes | Después |
|---|---|---|
| `catalogos.php` y `cronograma.php` entregaban **sin sesión** 909 casos de KFC con su descripción, los 100 locales con correos y los nombres de los 19 técnicos. `ESTADO.md` los daba por «CERRADOS»: el arreglo existía solo en el código local | `200` · 1,1 MB y 505 KB | `401` sin sesión y con cookie inventada. Con sesión simulada: ADMIN 909 casos, TECNICO id 12 = sus 19 asignados, JEFE_ZONA «sin permiso». Commit `e5f96f3` |

La prueba del jefe de zona dejó **una fila en la bitácora** (id 935, usuario ficticio
`auditoria-cli`, `DENEGADO ots.crear`). No se borró: es un registro de auditoría. Hay que
excluirla al minar rechazos por persona.

---

## 3. Vivo en el sitio de pruebas — **desplegado el 2026-09-10**, salvo el ingreso y usuarios

Afectan a quien ya usa el sitio (la administradora). Andrés aprobó desplegarlos el mismo día.

| Defecto | Evidencia | Corrección |
|---|---|---|
| El **trabajador de servicio v2** guarda en caché cualquier pantalla y cualquier PDF y los devuelve **ignorando la dirección**: abrir el PDF de una orden puede mostrar el de otra | `sw.js` del commit 0938243, desplegado: `cachePrimero` con `ignoreSearch` para todo lo que no es catálogo | `sw.js` v4: solo armazón y datos; nada de PHP ni PDF; subir la versión purga lo guardado |
| La **reconciliación deshace cada 3 horas** la reasignación hecha por una persona (vuelve al firmante del informe) y fabrica casos «ASIGNADO» sin nadie | Las 2 filas huérfanas del servidor (avisos 10336163 y 10336625, creadas a las 21:20:01 del 09-09) y el upsert desplegado | `Reconciliar.php`: el firmante solo se pone si nadie asignó ni derivó (`asignado_por`, `derivado_en`); la guarda va en el SQL |
| El **jefe de zona abre PDFs de otras zonas**, con la firma del administrador del local | `pdf.php` desplegado solo cortaba al técnico | Corte por zona para todo rol con alcance |
| **El bloqueo por intentos** revelaba qué cuentas existen y cualquiera podía bloquear cuentas ajenas. Se temía además que no bloqueara (`bloqueado_hasta` salía de `date()` de PHP y se comparaba con `NOW()` de la base) | `Auth.php` desplegado = local. La zona del PHP web se midió esa noche: UTC, como la base, así que el bloqueo viejo **sí** bloqueaba | Bloqueo en SQL, tope de 20 rechazos por conexión en 15 min, mensaje único |
| La **clave provisional impresa** servía en los extremos JSON (catálogos, envíos) | `exigir()` no miraba `debe_cambiar_clave` | Se exige en `exigir()` |
| **Redirección abierta** con `login.php?r=//otro-sitio` | La expresión aceptaba `//` | Rechazado |
| **XSS en `usuarios.php`**: el nombre iba dentro del JS de un `onsubmit`; una administradora podía escalar a superadministrador | `htmlspecialchars` no protege dentro de JS en un atributo | El texto va en `data-confirma` |
| Dar de baja a un técnico **deja sus casos huérfanos**, invisibles en «Por repartir» | `usuarios.php` solo desactivaba | Sus casos ASIGNADO vuelven a NUEVO, con bitácora |

**Desplegado el 2026-09-10 hacia las 21:42 (Ecuador), con aprobación de Andrés:** `sw.js` v4,
`pdf.php`, `nucleo/Reconciliar.php`, `nucleo/Auth.php` y los dos `.htaccess`, con `t2_10` (6 de 6
verificados por hash, `php -l` en el sitio). Antes se comprobó que lo reemplazado eran versiones ya
confirmadas en git —`sw.js` de 0938243, `Reconciliar.php` de 47a0b50, `pdf.php` y `Auth.php`
iguales a master—, así que no se perdió nada de la estación; que las firmas públicas de `Auth` y
`Reconciliar` no cambian (las usan `sync_casos.php` y `reconciliar_cli.php`), y que nada de lo
subido llama a clases que falten en el servidor. Las 12 rutas probadas sin sesión responden igual
antes y después, y las cabeceras nuevas salen.

**Las 2 filas huérfanas** pasaron a NUEVO (bitácora 937, `CORREGIR_HUERFANAS`, con la foto del
antes). No se habrían corregido solas: la reconciliación salta los casos abiertos sin informe.
Quedan 0.

**`login.php` y `usuarios.php` no se desplegaron**: su versión corregida viene con el rediseño y
necesita `nucleo/Ui.php` y el `estilo.css` nuevo, que no están en el servidor (subida sola,
`usuarios.php` daba error fatal). Suben con T2.12.3. Hasta entonces siguen vivos la redirección
abierta del ingreso, el XSS de `usuarios.php` —solo lo alcanza quien administra usuarios— y los
casos que quedan huérfanos al dar de baja a un técnico. La exigencia de cambiar la clave provisional
en todas las pantallas sí quedó activa con `Auth.php`: 19 cuentas la tienen pendiente.

---

## 4. Defectos del rediseño (sin desplegar), corregidos en código

| Defecto | Por qué importa | Dónde |
|---|---|---|
| `envio.php` leía el catálogo con claves y archivos que `t2_5` no genera: **habría rechazado el 100 % de las órdenes**; y sin catálogo las aceptaba sin validar | Nadie habría podido enviar una orden por la app nueva | `nucleo/Catalogo.php` (nuevo), `envio.php`, `catalogos.php` |
| **Las novedades de la visita nunca se enviaban**: `app.js` leía `#novedades` y el contenedor es `#novedadesVisita` | Pérdida total y silenciosa | `app.js` |
| El botón «Emitir la orden de este caso» **no precargaba el aviso** (el enlace mandaba `?aviso=` y solo se leía `?local=`); el combo filtraba por zona | Requisito 1 de Andrés | `app.js` |
| La orden en cola **no estaba ligada a quien la llenó**: en un celular compartido salía firmada por el siguiente | Trazabilidad de la firma | `cola.js`, `envio.php` (409) |
| Cerrar sesión **no borraba** del teléfono los catálogos ni la bandeja; un 401 se servía desde la caché | Datos de KFC y del personal en teléfonos personales | `sw.js`, `salir.php` |
| El reloj de 48 h se calculaba en PHP contra fechas de la base | Si el PHP de la web corriera en otra zona que la base, tarjetas desfasadas 5 h. Medido esa noche: los dos en UTC, así que en este servidor no había desfase; en SQL deja de depender de esa configuración | `Pendientes.php` (horas en SQL) |
| Un equipo resuelto que vuelve a fallar **no abría nada**; el diagnóstico nuevo pisaba el anterior; un segundo equipo del mismo caso se fundía con el primero | El equipo quedaba parado sin reloj | `Pendientes.php`, `app.js`, 007 (`DIAGNOSTICO`) |
| Un equipo trabado en un caso ya ATENDIDO no lo pasaba a ESPERA_REPUESTO | Se cerraba en SAP con el equipo parado | `Pendientes.php` |
| La garantía negada reiniciaba el reloj desde la apertura original y contaba el equipo como a tiempo y vencido a la vez | Indicador de 48 h falso | `Pendientes.php`, 007 (`plazo_desde`) |
| Un POST repetido reescribía un veredicto ya dado; abrir un pendiente no comprobaba el permiso | Alcance y permiso solo en la interfaz | `Pendientes.php` |
| El técnico reasignado no veía el equipo trabado de su caso | Le pisaba el diagnóstico a otro | `Pendientes.php` (alcance) |
| Un jefe de zona podía reportar novedades en otra zona; un reintento pisaba una novedad ya revisada | Alcance en el servidor | `Novedades.php` |
| La zona de un caso derivado solo se respetaba al filtrar: derivarlo de vuelta se rechazaba para siempre | Derivación rota | `Casos::enAlcance` |
| `reportes.php` mostraba **100 % en verde** sin la 007 | I-7 | `reportes.php` |
| `verificar_esquema.php` habría dado FALLA justo después de aplicar bien la 007 y no comprobaba nada de ella | T2.12.1 no se podía cerrar | `verificar_esquema.php` |
| Textos que prometían lo que no pasa: «sale sola aunque cierres la aplicación», «las 48 h corren desde ahora», «la administración lo revisa» | I-7 | `app.js`, `cola.js`, `offline.js` |
| Los `.htaccess` de la raíz y de `ordenes_pdf/` existían solo en el servidor; faltaban cabeceras de seguridad | Un despliegue desde cero los perdía | `.htaccess` versionados |
| El desplegador **se podía salir del sitio de pruebas** con `--borrar ../../../…`, y `./nucleo/config.php` esquivaba los prohibidos; tenía rutas fijas a `D:\INDUSTECH IA` | Regla 9 | `t2_10_desplegar.py` |
| **El vigilante del buzón queda sordo**: Titan corta el IDLE a los ~20 min, se renovaba a los 24 y al reconectar no se barría | Casos que llegan y no aparecen hasta el correo siguiente. **Esto corre hoy en la estación** | `t2_9_buzon_vigilante.py` |
| El lector del buzón perdía en silencio un lote que el servidor rechazaba, y su compuerta del 95 % se evaluaba después de sobrescribir el catálogo | Catálogo corto empujado igual | `t2_6_imap_avisos.py` |

---

## 5. Evidencia de verificación

```
php -l (PHP 8.2.33 del servidor, por stdin)      15 de 15 sin errores (repetido tras la revisión)
node --check                                      app.js, cola.js, sw.js, offline.js ok
reglas.fixture.mjs                                32 / 32
prueba_graficos.mjs / prueba_contratos.mjs        62 · 0  /  54 · 0
prueba_offline.mjs                                no corre en el PC: levanta el PHP de la estación
                                                  (D:/SOFTWARE/PHP83); se corre allá tras fusionar
py_compile / ast                                  t2_9, t2_6, t2_10 ok

lector nuevo (Catalogo::cargar): locales=100 tecnicos=19 tipos=222 equipos=94
lector viejo de envio.php:       locales=0 tecnicos=0 tipos=0 equipos=0
misma orden válida, con el lector nuevo -> BLOQUEA: ninguno
misma orden válida, con el lector viejo -> BLOQUEA: LOCAL_FUERA_DE_CATALOGO, TECNICO_NO_VIGENTE

verificar_esquema.php nuevo contra la base viva  -> permisos (sin la 007) 19/18/11/5 · TODO OK
consultas nuevas de Reconciliar, Auth y usuarios -> 5 de 5 preparadas contra el esquema vivo, sin ejecutar

t2_10: '../../../yellow-elephant…' '/etc/passwd' 'C:/x' '..' -> abortan
       './nucleo/config.php' -> 'nucleo/config.php' (PROHIBIDO)
       lista blanca: nada del código fuera de --todo salvo las herramientas CLI declaradas
```

---

## 5b. Revisión independiente de estas correcciones

Un revisor aparte, sin saber quién escribió qué, revisó todo lo corregido hoy. Encontró **6 defectos
introducidos por las propias correcciones**; ninguno bloqueante, todos corregidos antes de confirmar:

| # | Dónde | Defecto | Corrección |
|---|---|---|---|
| 1 | `envio.php` | El equipo trabado y las novedades se registraban fuera de la orden: si fallaban después de guardarla, el reintento la veía «ya recibida» y el equipo nunca entraba al control de 48 h | Orden, pendiente y novedades en **una transacción**: si algo falla no queda nada y responde 503 para que la cola reintente. Lo nuevo lo decide el propio `INSERT` (1 fila = nueva), así que dos envíos simultáneos del mismo UUID ya no procesan dos veces lo de dentro |
| 2 | `Pendientes::mover` | Al cerrar el último pendiente, un caso que ya tenía orden de cierre volvía a la bandeja como trabajo abierto | Vuelve a ATENDIDO si tiene `ot_cierre` |
| 3 | `Pendientes::abrir` | Una orden que esperó sin señal podía reabrir un equipo que otro resolvió después (nacía vencido), y la reapertura heredaba las insistencias del episodio anterior | Solo reabre si el reporte es posterior al cierre —lo compara MySQL, con la hora del reporte aunque pase de 72 h—; si no, va al hilo con bitácora `PENDIENTE_REPORTE_TARDIO`. `insistencias = 0` al reabrir |
| 4 | `pdf.php` y `Auth::zonaAlcance` | Un técnico sin zona abría cualquier PDF sin fila de gestión | El técnico nunca entra por la vía de la zona, y `zonaAlcance()` falla cerrado: jefe o técnico sin zona devuelve `''` (ninguna), no `null` (todas). Hoy ninguno activo carece de zona: es preventivo |
| 5 | `sw.js` | Un `fetch` que siguió la redirección al ingreso trae el login con 200, y se guardaba como si fuera la bandeja | Lo redirigido no se guarda y se trata como sesión perdida. Un 403 ya no borra la caché (es falta de permiso, no de sesión); un 5xx sirve la última copia |
| 6 | `app.js` | Tocar «asignada / sin asignar» antes de que llegue el catálogo lanzaba un error | `fijarOrigen` cambia el panel y sale si el combo aún no existe |

De las dudas que dejó abiertas:

- **Tope por IP**: PHP ve la IP real del cliente (4 distintas en `sesiones_log`, ninguna de un
  proxy) y el máximo histórico es de 2 rechazos por IP en 15 min. El tope de 20 no alcanza a nadie
  legítimo.
- **Reemplazo atómico en Windows** (`t2_6`): reintenta 10 × 0,5 s si otro proceso tiene el archivo
  abierto, en vez de perder el barrido.
- **`contar()` del vigilante**: pone tiempo límite al socket antes del EXAMINE, porque
  `esperar_novedad` lo deja sin límite.
- **`.htaccess` vivo frente al versionado**: comparados por SSH en solo lectura. El versionado
  conserva todas las reglas del vivo y agrega `mjs|previo` al bloqueo y las cabeceras de seguridad;
  el de `ordenes_pdf/` es equivalente (solo cambian los comentarios). Desplegarlos no quita nada.

---

## 6. Lo que queda abierto, y de quién es

### En archivos de otras conversaciones de la estación (no se tocaron)

| Archivo | Defecto verificado | Gravedad |
|---|---|---|
| `casos.php` | «Ya lo cerré en SAP» no mira si hay equipos trabados; asignar a mano no pone `tecnico_auto = 0` (queda «del informe»); el aviso va dentro de JS en `onclick` (mismo patrón que el XSS de usuarios.php) | Media |
| `casos.php` | «Veredicto → resuelto» no valida el estado de origen y salta el cierre de dos manos | Baja |
| `nucleo/Ui.php` y `busqueda.js` | `busquedaSinCeros` borra también ceros internos: buscar 2466 trae la OT 20466 | Baja |
| `nucleo/Ui.php` | El reloj dice «vencido hace 2 días» apenas vence (cuenta desde la apertura) | Baja |
| `ordenes.php` | Los enlaces firmados se incrustan por fila sin registrar quién los generó; el técnico ve OT por asignación, no por firma | Baja |
| `t2_11_informes_ot.py` | Sale con 0 aunque el empuje falle; descarta sin rastro los informes cuyo aviso no cruza; guarda en caché para siempre los fallos de lectura | Media |
| `t2_12_cotejo_sap_abiertas.py` | Convierte «no pude leer» en «sin gestión» y recomienda asignar; su compuerta de cuadre es una tautología | Media |
| `casos.php` y `Casos::alcanzaAviso` | Un caso que salió del catálogo del buzón responde «fuera de su alcance» hasta a un superadmin (aviso 10353555, dos intentos el 10-sep a las 19:22). Y **4 casos ASIGNADO a técnicos no están en el catálogo: sus 3 técnicos no los ven** en la bandeja. De 855 filas de gestión, 21 apuntan fuera del catálogo; las otras 17 están cerradas o atendidas. Lo resuelve T2.13.3 (bandeja e historial desde la base, no desde la ventana de 90 días); el mensaje tiene que distinguir «no está en el catálogo» de «fuera de tu alcance» | Media |

### En la estación

- **Fusionar la rama y reiniciar el vigilante** (la tarea programada) para que tome el IDLE de 9 min.
  La estación tiene trabajo sin confirmar de otras conversaciones, y `ESTADO.md` lo toca también
  esta rama: se aparta antes y se devuelve después —
  `git stash push -m "en curso" -- ESTADO.md` → `git fetch origin` →
  `git merge origin/pc/auditoria-2026-09-10` → `git stash pop` (si choca, se conservan las dos
  entradas). Si `git merge` nombra otro archivo con cambios locales, se aparta igual.
- `t2_6`: las celdas vacías de la tabla del correo corren los campos (una «Activo Fijo» vacía deja
  la descripción en su lugar). **No se cambió a ciegas**: hay que mirar un correo real en la
  estación antes de tocar el parser.

### La hora: el servidor en UTC, el negocio en Ecuador (medido el 2026-09-10 por la noche)

La base (`NOW()`) y el PHP de la web (`date.timezone=UTC`, medido con una sonda que se borró al
instante) corren los dos en **UTC**: entre ellos no hay desfase, así que el reloj de 48 h y el
bloqueo por intentos, que se calculan en SQL, están bien. Pero el negocio vive en hora de Ecuador
(UTC−5) y el código no convierte:

- todas las fechas de la base son `DATETIME` con `DEFAULT current_timestamp()`: se guardan en UTC y
  se muestran tal cual, **5 horas adelantadas** (`asignado_en`, `recibida_en`, la bitácora…);
- `date('Y-m-d')` da el «hoy» de UTC: desde las 19:00 de Ecuador ya es mañana, y un caso que vence
  hoy sale «vencido» esa noche (`casos.php:257`, `mis.php:193`, `panel.php:64`, `asignacion.php:40`,
  `reportes.php:67`, `Ui::dias`). Solo `envio.php` fija `America/Guayaquil`.

No bloquea el despliegue de prueba. Se corrige en T2.13.7, antes de que haya usuarios reales.

### Producción (`yellow-elephant`) — diferido al corte por decisión de Andrés del 2026-09-10

`phpinfo.php` responde 200 público · WordPress 6.8.8 con vulnerabilidades · `guardas.php` sin
desplegar: los contadores de zona vacía suman 88 (eran 87 el 05-sep) · revisar en hPanel si algún
cron llama a `cleanup.php` (por SSH no hay `crontab`).

### Documentación que sigue desalineada

`LEEME_ACCESO_HOSTINGER.md` lleva la cabecera corregida; sus pasos 0 y 2 conservan un carácter de
control en `desarrollo\agentes` y rutas de la guía original. `ARQUITECTURA_SISTEMA_OTS.md` y
`DISENO_APP_OTS.md` dicen PHP 8.3: el servidor corre **8.2.33**. `SISTEMA_COMPLETO.md` y
`DECISION_ARQUITECTURA_Y_DATOS.md` siguen marcando como urgente la exposición de los PDF, cerrada
el 08-sep. Los conteos de pruebas (48 → 54) los actualiza la conversación «cotejo SAP» al cerrar.

### Secretos

- Las claves iniciales impresas (`CLAVES_TECNICOS*`) salieron de la copia del PC de Andrés a una
  carpeta privada fuera de repositorios y de OneDrive. **Siguen vigentes para 18 personas.**
- El PC de Andrés tiene llave SSH propia, autorizada en hPanel el 2026-09-10 como «PC Andres» (la
  de la estación figura como «industec»). La copia de la llave de la estación se borró del PC ese
  mismo día, después de comprobar que la nueva conecta y que `t2_10 --probar` funciona con ella.
- El historial del repositorio conserva el correo de un empleado de KFC en un comentario de
  `guardas.php` (ya quitado del código vigente). No se reescribe el historial.
