---
name: industec-archivos-canonicos
description: Invocala antes de mover, renombrar, deduplicar o clasificar archivos del corpus documental, y para resolver casos ambiguos que no se pudieron clasificar automaticamente.
---

# Saneamiento y promocion de archivos al arbol canonico

Corpus de referencia: **7.333 PDFs**. Ese numero es el invariante de cuadre de toda la Fase 1.

Precondicion absoluta: se trabaja **siempre** sobre `D:\RESPALDOS\_ORIGEN_DRIVE`, espejo ya verificado por hash. Ningun script escribe ahi. `G:\Mi unidad` no se toca (I-3).

## 1. Dos scripts, nunca uno

- **Fase de analisis**: solo LEE y escribe un manifiesto CSV con la decision propuesta por archivo. No copia ni borra nada.
- **Fase de ejecucion**: lee ese manifiesto ya revisado y ejecuta.

Verificacion: `grep -n 'shutil\|copy2\|os.remove\|rename' t1_6_saneamiento.py` → **0 lineas**. Y el script de ejecucion no contiene ninguna regex de clasificacion (`t1_6_ejecutar.py:33` — su unica entrada es el CSV).

## 2. Tres niveles de confianza; el nivel decide el destino

- **Nivel 1** — automatico sin ambiguedad (mayusculas, ceros, sufijos, formato).
- **Nivel 2** — automatico pero solo con verificacion cruzada contra el maestro.
- **Nivel 3** — cuarentena. No se toca. Decide una persona.

El nivel de un archivo es el **peor** de los niveles de sus campos: `nivel = max(nivel_local, nivel_aviso)`.

El nivel y la **disposicion final** son ejes ortogonales: guarda el nivel (1/2/3) en una columna y el destino (`APLICADO` / `CUARENTENA` / `DESCARTADO_DUPLICADO`) en otra. Intentar codificar "descartado" subiendo el nivel produce codigo muerto (`t1_6_saneamiento.py:368` es un `max(nivel,1)` no-op).

## 3. Nunca inventes un valor canonico

Toda heuristica genera **candidatos**. El candidato se acepta unicamente si al buscarlo en el maestro/alias **resuelve**, y solo si resuelve a **UN** destino. Si empatan dos, es ambiguo → Nivel 3.

Auditoria: cada `return` de nivel < 3 debe ir precedido de una pertenencia al catalogo (`in locales` / `in alias` / `in avisos`). Un `return` de nivel < 3 sin lookup es un bug.

**Mantén una lista negra explicita** de valores conocidos como fuera del maestro (`CODIGOS_NIVEL3_CONOCIDOS = {'RESTAURANTEELVITA','GUSCOTOCOLLAO',...}`) y cortocircuitala a Nivel 3 **antes** de aplicar cualquier heuristica, para que ninguna variante los capture por accidente. Comprueba que ese `return` aparece antes del bucle de variantes.

**Distingue "formato invalido" de "valido pero desconocido".** Si un valor cumple el formato canonico pero no esta en el catalogo, acepta y marca informativamente (`FORMATO_OK_NO_EN_CATALOGO`, Nivel 1): el catalogo de avisos SAP puede no ser exhaustivo. La cuarentena se reserva para lo mal formado sin correccion unica.

## 4. El maestro gana, pero la discrepancia se registra

Cuando un campo del nombre contradice al maestro: usa el valor del maestro, anota la regla con origen→destino (`ZONA_CRUZADA_{origen}_A_{destino}`) y **escala el nivel a 2**. Si no hay zona resoluble: nivel 3 + `ZONA_NO_RESOLUBLE`. Corregir en silencio destruye la trazabilidad (I-5).

## 5. Copia, nunca muevas; verifica por HASH, nunca por tamano

- El espejo de origen es inmutable durante todo el proceso. El manifiesto guarda `ruta_original → ruta_final`, lo que hace la operacion reversible.
- Tras copiar: recalcula SHA-256 en origen y destino y falla si no coinciden (`HASH_NO_COINCIDE_TRAS_COPIA`, borrando la copia mala).
- Si el destino ya existe: **mismo hash** = `YA_EXISTIA` (re-ejecucion segura); **hash distinto** = `DESTINO_YA_OCUPADO_POR_OTRO_CONTENIDO`, jamas sobrescribir.
- El tamano solo es una senal debil; el hash es la prueba.

Verificacion: `grep -n 'shutil.move\|os.unlink\|os.remove\|rmtree' t1_6_ejecutar.py` → 0 lineas. Re-ejecutar completo: todo `YA_EXISTIA`, 0 errores.

## 6. Colisiones de ruta canonica: resuelvelas por CONTENIDO, antes de copiar (I-11)

1. Agrupa las filas candidatas (nivel<3, con `ruta_canonica`, no ya marcadas duplicado) **por `ruta_canonica`**.
2. Ignora los grupos de tamano 1.
3. Calcula el set de hashes SHA-256 de los origenes.
4. `|hashes| == 1` → duplicado real: conserva el primero, marca el resto `DUPLICADO_MISMA_RUTA_CANONICA_HASH_IDENTICO`.
5. `|hashes| > 1` → colision genuina: **todo el grupo sube a nivel 3**, `ruta_canonica` vacia, regla `COLISION_CONTENIDO_DISTINTO_MISMA_CLAVE`. Ninguno se promueve solo, para no elegir arbitrariamente.
6. Imprime los conteos antes de empezar a copiar.

**Detecta duplicados en dos capas.** La deteccion por patron de nombre (sufijo `(n)` en la misma carpeta) es insuficiente: el analisis encontro 29 de ~204 esperados. El resto son copias del mismo archivo en carpetas distintas de Drive y solo aparecen por hash al agrupar por destino.

## 7. Nada se descarta en silencio

Incluye una rama por defecto: `nivel < 3` pero **sin `ruta_canonica` calculada** → cuarentena por seguridad (`{motivo}_SIN_RUTA_CANONICA`). Un archivo sin ruta canonica y sin cuarentena es un archivo perdido.

Verificacion: `sum(contadores.values()) == len(filas)`.

En la cuarentena, que aplana jerarquias, desambigua los nombres repetidos con `hashlib.md5(str(origen).encode()).hexdigest()[:8]` — sufijo **derivado del origen**, no de un contador, para que sea identico entre ejecuciones.

## 8. Cierra el ciclo: los alias confirmados vuelven al maestro

Cada correccion Nivel 1/2 aceptada donde `crudo != canonico` se inserta en `locales_alias(alias_texto, local_codigo, regla_aplicada, nivel_confianza)` con upsert. La siguiente corrida la resuelve como `ALIAS_CONOCIDO` de Nivel 1 y el porcentaje de Nivel 1 sube en cada iteracion. Registra el alias **con y sin sufijo `EC`**.

## 9. Resolucion de casos ambiguos por cuatro senales independientes

Cuando un archivo no se pudo clasificar, reune **todas** las senales disponibles de sistemas distintos:

- **P** — interior del PDF (`Local:`, `Cliente:`): lo que el tecnico declaro en sitio.
- **S** — SAP: `avisos_sap.centro_coste`, a que local se factura.
- **A** — plan de la administracion: el LOCAL que ella asigno a mano.
- **N** — nombre del local: coincidencia del texto libre contra el maestro.

**Criterio, fijado por escrito en el docstring, no a juicio del agente en ejecucion:**
- ≥2 senales coincidentes → confianza **ALTA**.
- Una sola senal → se acepta, marcada **MEDIA** y "unica fuente disponible".
- Se contradicen sin mayoria → **NO se decide**: `CONFLICTO_REVISION_HUMANA`.
- **Prohibido elegir "la primera que llego"** o la mas comoda de calcular.

**PRECEDENCIA DEL DOCUMENTO:** el interior del PDF manda aunque quede en minoria. Las otras senales se derivan del aviso o del correlativo, que son justamente los campos que se digitan mal. El archivo se guarda **donde se hizo el trabajo**, no donde se factura un aviso equivocado. La discrepancia no se descarta: se registra en la nota (`discrepa S_SAP=Kxxx (posible aviso mal digitado)`) y se reporta agregada al cierre como hallazgo de calidad.

> Validacion del criterio: en los 250 casos comparables el PDF coincidio con lo deducido del nombre **sin una sola discrepancia**, y la muestra contra el texto crudo dio 34/34 y 37/37. Resolvio 519/554 (93,7%); los 35 sin mayoria quedaron en el manifiesto.

**Lee SIEMPRE desde `_ORIGEN_DRIVE`**, nunca desde la bandeja de cuarentena que el propio proceso mueve: leer desde la bandeja hacia perder la senal del PDF en cuanto un documento se promovia, y la resolucion cambiaba segun cuantas veces se hubiera corrido. Verificacion: correr el resolutor dos veces y hacer `diff` del CSV — identico byte a byte.

**Pasada global ANTES de decidir caso por caso:** agrupa el aviso por el local deducido de la senal fuerte. Si un mismo numero aparece en documentos de locales distintos, el tecnico lo reutilizo y **ese aviso no sirve para decidir el local de nadie**. Sin esta pasada, cada caso individual parece consistente.

## 10. Casos de negocio que parecen errores y no lo son

- **Local compuesto** `K073-H015`, `H014 /h069`: dos marcas en el mismo sitio fisico (KFC + Heladeria), **no un typo**. Cascada de arbitraje: (1) si la administracion archivo en uno → `COMPUESTO_SEGUN_ADMIN`, ALTA; (2) si no, y SAP factura a uno → `COMPUESTO_SEGUN_SAP`, MEDIA; (3) sin ninguno → `COMPUESTO_SIN_ARBITRO`, MEDIA, se archiva bajo el primer codigo. El dato contable descartado se conserva en la nota (`SAP factura a Xxxx`).
- **Miembro con la letra equivocada**: si solo uno de los dos codigos resuelve, busca el faltante **por su parte numerica** en todo el maestro y acepta solo si es unico (`h069` → `K069EC`).
- **La letra es la inicial de la marca**: `Local: J054` + `Cliente: Juan valdez` → `V054EC`. Resuelve por cadena+numero **antes** de caer al aviso SAP; sin esta regla el documento terminaba archivado en un KFC. Acepta solo si el texto del cliente identifica una unica cadena y el numero da un unico local dentro de ella.
- **El nombre de la cadena NO identifica un local.** Elimina los nombres de marca del texto y exige que queden ≥5 caracteres de ubicacion propia. Aceptarlo produjo un falso positivo real (`A018`→`A010EC` porque "American Deli" solo calzaba con un local de esa cadena); el caso se resolvio despues como alias `A018`→`A014EC` con evidencia del correo del documento.
- **Correctivo sin aviso SAP**: caso de negocio valido (emergencia con el tecnico ya en sitio), no error. Ver la skill de agentes.

## 11. Cuando una cohorte entera cae en cuarentena, sospecha de la REGLA

> **Caso real:** 185 de las 364 cuarentenas eran un error de diseno: el patron canonico exigia un aviso SAP a los preventivos, que por naturaleza nunca lo tienen. La correccion fue admitir el aviso como opcional, no arreglar los archivos.

Antes de aceptar una bandeja de cuarentena: `SELECT motivo_cuarentena, modulo, COUNT(*) FROM ots WHERE en_cuarentena=1 GROUP BY 1,2 ORDER BY 3 DESC;` — si un motivo concentra un subtipo completo, es defecto de la regla.

**Amplia el alcance a los "correctos sin clasificar"**, no solo a los marcados como error: los 161 documentos con el correlativo al final (`OT-Cajun-10280653-CNLJ-023.pdf`) no estaban mal, estaban sin clasificar. El criterio de seleccion debe ser **identico** en resolutor, verificador y ejecutor: copia el codigo, no lo reescribas de memoria.

## 12. Da respaldo a los campos que forman la clave de destino

Si el correlativo falta en el manifiesto, tomalo del nombre original con los **dos** patrones de la empresa (prefijo `OT-1234-...` y correlativo al final). Sin ese respaldo, todos los documentos no reconocidos se llamaban `OT-0000-...` y colisionaban entre si. Corrige ademas el tipo con la evidencia del nombre: un documento con `Dia N` es preventivo aunque el proceso anterior no lo reconociera.

Verificacion: no debe existir ningun archivo que empiece por `OT-0000-` en el arbol canonico.

## 13. Ramas paralelas para lo que no encaja en el modelo

- Sin correlativo → **no es una OT**: `D:\RESPALDOS\INFORMES TECNICOS\{ano}\{zona}\{cadena}\`, fuera de la tabla `ots`.
- Cliente fuera del contrato → `D:\RESPALDOS\OTROS CLIENTES\{ano}\{CLIENTE}\`. **No es un descarte**: son trabajos reales fuera de alcance, quedan igual de ordenados y en el manifiesto.
- Unifica variantes de nombre de cliente con un **diccionario explicito**, nunca por parecido (`REY DE LAS MENESTRAS`→`EL REY DE LAS MENESTRAS`, `TERMINAL DE CARCELEN`→`RESTAURANTE ELVITA`, `CESAR BASANTES`→`HARRYS`). Los apostrofos se **eliminan**, no se sustituyen por espacio: `DORITAN'S` convertido en `DORITAN S` generaba una carpeta que parecia otro cliente.

## 14. Verificacion independiente ANTES de mover un solo archivo (gate que aborta)

Un script aparte, entre analisis y ejecucion, con `sys.exit(1)` si algo falla. Minimo cinco comprobaciones:

1. **Conservacion**: resueltos + no resueltos = total esperado. Nombres repetidos se dirimen por hash (identico = no es fallo).
2. **Integridad referencial**: todo local resuelto existe en el maestro y su zona es la del **maestro**, no la del nombre del archivo.
3. **Coherencia con el proceso anterior**, listando explicitamente cada diferencia y la via que la produjo.
4. **Muestra contra la evidencia cruda**: `random.seed(20260904)` (reproducible), **estratificada por via de resolucion** (hasta 3 por via) — asi las vias raras, que son las mas riesgosas, quedan cubiertas. Acepta variantes: con/sin sufijo, con/sin ceros, letra de marca + cadena, o el nombre del local (≥ mitad de las palabras largas).
5. **Colisiones de destino** contra el arbol existente.

Solo con `VERIFICACION SUPERADA` y exit 0 se autoriza la ejecucion.

## 15. Muestra de verificacion contra el CONTENIDO, no contra el plan

Verificar el manifiesto contra si mismo no prueba nada. Apunta al **arbol destino ya escrito**, abre el PDF, extrae el dato de negocio (`ID-ORDEN-GRUPOKFC[:\s]*([0-9]+)`) y comparalo con lo que afirma el nombre.

- `random.seed(AAAAMMDD)` + `random.sample(pdfs, 30)`: aleatoria y reproducible.
- **Normaliza ambos lados antes de comparar**: `lstrip("0")` en los dos (el nombre canonico lleva `zfill(8)`).
- **Tres resultados, no dos**: `OK`, `DISCREPANCIA`, `SIN_ETIQUETA_AVISO`. Un PDF sin texto extraible no es fallo ni exito. Contarlo como OK infla la calidad; como error la hunde. La suma de los tres debe igualar el tamano de la muestra.
- **Declara que campos son criterio de fallo**: el local no siempre aparece como codigo literal en el PDF, asi que se reporta como informativo, no como criterio.

Resultados historicos: 30/30, 34/34, 37/37 — el criterio del proyecto es el conteo absoluto, no un porcentaje.

## 16. Cuadre aritmetico de cierre

Cada fase termina mostrando una identidad explicita contra el invariante inicial, en el log y en el mensaje de commit:

```
6597 aplicados + 364 cuarentena + 161 sin-ruta + 211 duplicados = 7333  (0 perdida)
7.070 OTs + 5 informes tecnicos + 25 otros clientes + 233 duplicados descartados = 7.333
```

Los 161 "sin ruta canonica" **solo aparecieron porque el cuadre no daba**. Sin el invariante habrian pasado inadvertidos. Imprime siempre el valor esperado junto al obtenido: `f'Total de PDFs analizados: {total} (esperado 7333)'`.

## 17. Entregables para la administracion

El artefacto de handoff no es el CSV tecnico: es un XLSX con encabezados en negrita, `freeze_panes='A2'`, anchos ajustados (`min(maxlen+2, 50)`), filas Nivel 3 resaltadas (`PatternFill('FFF2CC')`) y una columna **`DECISION ADMIN` vacia**. Dos hojas: lo hecho (`RESUELTAS`) y lo que requiere decision (`PENDIENTES DE DECISION`), con las senales crudas visibles para que la persona arbitre.

Cierra con el cuadre que **aborta**: `CUADRE: registro(N) + pendientes(M) = T (esperado T)`, y `sys.exit(1)` con `ATENCION: el cuadre no da` si falla.

Clasifica lo no resuelto **por causa**, no en un cajon unico: `CONFLICTO_ENTRE_FUENTES`, `NO_ES_UNA_OT`, `FALTA_EN_MAESTRO`, `OTRO_CLIENTE`. Cada categoria tiene un destino distinto aguas abajo.

## 18. Altas al maestro: evidencia citable, y el archivo del cliente no se toca

Un codigo desconocido puede ser un local nuevo (**alta**) o un codigo mal escrito (**alias**). Decidelo con evidencia **externa al propio codigo** y citala en la fila:

- `gusg44@gus.com.ec` impreso en el documento confirma `G044` → alta.
- `kfc167@kfc.com.ec` + "Plaza San Francisco esta en el Centro Historico" → `K166` es alias de `K167EC`.
- `amca14@americandeli.com.ec` + unico American Deli de LARB → `A018` es alias de `A014EC`.

Si un campo obligatorio no lo determina la evidencia (la zona), **no lo inventes**: carga `OTRA` y marcalo `ZONA POR CONFIRMAR`.

El maestro de verdad es el Excel de la administracion en Drive y **no se toca** (I-3, I-4). El script carga la base para que el sistema funcione y deja `SALIDAS IA\CALIDAD\PROPUESTA_ALTAS_MAESTRO_LOCALES.xlsx` con las hojas `ALTAS PROPUESTAS` y `CODIGOS MAL ESCRITOS`, columnas listas para pegar y `CONFIRMA ADMIN` vacia.
