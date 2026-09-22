-- ============================================================================
-- 012_continuidad_casos.sql — Un trabajo, varios avisos.
--
-- EL PROBLEMA, MEDIDO. SAP cierra solo el aviso que nadie atendió en 48 horas.
-- Cuando el técnico va, diagnostica y el repuesto tarda, ese aviso muere; KFC
-- abre otro por el mismo equipo. El trabajo es uno, los avisos son varios, y
-- el sistema no tenía cómo decirlo. El caso que lo destapó (pedido de Andrés
-- del 2026-09-21): el horno HORNO-S/M-2023-118 de G006EC tiene CUATRO avisos
-- por el mismo problema —10342524 (18-jul), 10342924 (20-jul), 10343636
-- (23-jul), 10349666 (20-ago)— y para el segundo KFC ya está pidiendo «el
-- repuesto del horno», es decir, el técnico ya había ido.
--
-- Dimensión: en la ventana viva del buzón (918 casos, 90 días) hay 185 grupos
-- local+equipo con más de un caso y 489 casos implicados; 94 de los 304 pares
-- consecutivos nacen con 7 días o menos de diferencia. En el histórico de SAP
-- (6.450 avisos, ene-ago 2026) son 509 avisos dentro de la semana de otro del
-- mismo local y equipo, más 68 el mismo día.
--
-- NADIE ENTRA A UNA CADENA POR PARECIDO. Estas columnas se llenan SOLO cuando
-- una persona lo confirma. Los mismos datos traen los contraejemplos: en
-- J022EC el par del mismo equipo es «informe técnico para dar de baja» ->
-- «instalando el nuevo equipo», y en K124EC «no emite sonido» -> «escape de
-- aceite». Son trabajos distintos sobre el mismo equipo, y enlazarlos por
-- similitud sería declarar ante Grupo KFC que se atendió un caso que nadie
-- atendió (I-7).
--
-- LA CADENA SE GUARDA PLANA, CONTRA LA RAIZ. `continua_de` no apunta al caso
-- inmediatamente anterior sino al PRIMERO de la cadena: 10342924, 10343636 y
-- 10349666 apuntan los tres a 10342524. Dos motivos concretos: un ciclo se
-- vuelve imposible por construcción (`Casos::raiz()` resuelve antes de
-- escribir), y cerrar la cadena entera es un WHERE de un solo nivel en vez de
-- un recorrido recursivo en cada emisión de orden.
--
-- LA CLAVE DE NEGOCIO YA EXISTE (I-9): `aviso` es PRIMARY KEY de la tabla, y
-- eso es justo lo que hace falta aquí —un caso continúa de UNO solo—, así que
-- no se agrega ninguna UNIQUE KEY nueva. La restricción que SI falta no es
-- expresable en el esquema (que `continua_de` no sea el propio aviso, ni un
-- caso que a su vez continúe de este) y la impone `Casos::enlazar()`.
--
-- ESTO NO TOCA `avisos_sap.estatus_general`, NI PUEDE. El estado real de un
-- correctivo lo manda SAP y ninguna señal de este sistema puede hacerse pasar
-- por ese cierre (regla 5 de ESTADO.md §6). Un caso enlazado queda ATENDIDO
-- NUESTRO, que es otra cosa y se ve distinto en pantalla.
--
-- Idempotente (IF NOT EXISTS). Se aplica con:
--     php aplicar_sql.php sql/012_continuidad_casos.sql
-- Comprobación: php verificar_esquema.php, bloque «migracion 012».
-- ============================================================================

ALTER TABLE casos_gestion
  ADD COLUMN IF NOT EXISTS continua_de VARCHAR(20) NULL
      COMMENT 'El aviso RAIZ de la cadena: el primer caso del mismo trabajo. NULL = este caso no continua de ninguno. No es FK: casos_gestion se puebla al primer toque y el aviso raiz puede no tener fila todavia',
  ADD COLUMN IF NOT EXISTS continua_ot VARCHAR(60) NULL
      COMMENT 'La orden de INDUSTEC que ya cubre el trabajo, cuando el caso viejo si alcanzo a emitir una. NULL = la cadena todavia no tiene orden; el trabajo se concluye con la que se emita ahora',
  ADD COLUMN IF NOT EXISTS continua_por INT(10) UNSIGNED NULL
      COMMENT 'Quien declaro que es el mismo trabajo. Es el tecnico que estuvo ahi (decision de Andres del 2026-09-21): el jefe de zona no lo aprueba por adelantado, lo audita despues',
  ADD COLUMN IF NOT EXISTS continua_en DATETIME NULL,
  ADD COLUMN IF NOT EXISTS continua_nota VARCHAR(300) NULL
      COMMENT 'Con que se sostiene el enlace. Es lo que se le muestra al jefe de zona y lo que responde a KFC si pregunta por que este aviso no tiene orden propia',
  ADD KEY IF NOT EXISTS idx_gestion_continua (continua_de),
  ADD CONSTRAINT fk_gestion_continua_por FOREIGN KEY IF NOT EXISTS (continua_por)
      REFERENCES usuarios(usuario_id) ON DELETE SET NULL;


-- ----------------------------------------------------------------------------
-- El permiso. Lo tiene el técnico —es la decisión de Andrés— y también quien
-- gestiona el buzón, porque la administradora se encuentra con las mismas
-- cadenas desde el otro lado. El ALCANCE lo pone la consulta, no el permiso:
-- el técnico solo enlaza casos que tiene asignados.
-- ----------------------------------------------------------------------------
INSERT INTO permisos (codigo, modulo, nombre, descripcion) VALUES
  ('casos.continuidad', 'casos', 'Declarar que un caso continua un trabajo anterior',
   'Enlazar un caso con el aviso anterior del mismo equipo cuando SAP cerro el primero a las 48 horas. Queda en bitacora a nombre de quien lo declara')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), descripcion = VALUES(descripcion);

INSERT INTO rol_permisos (rol, permiso) VALUES
  ('SUPERADMIN','casos.continuidad'), ('ADMIN','casos.continuidad'),
  ('JEFE_ZONA','casos.continuidad'), ('TECNICO','casos.continuidad')
ON DUPLICATE KEY UPDATE rol = rol;

-- El libro de migraciones lo escribe `aplicar_sql.php` al terminar, con el
-- sha256 del archivo: anotarlo aquí a mano dejaría una huella vacía y la
-- siguiente corrida creería que la migración cambió.


-- ============================================================================
-- COMPROBACION (se pega la salida literal al cerrar la tarea):
--
--   -- 1. Las cinco columnas y el indice:
--   SHOW COLUMNS FROM casos_gestion LIKE 'continua%';      -> 5 filas
--   SHOW KEYS FROM casos_gestion WHERE Key_name = 'idx_gestion_continua';
--
--   -- 2. El permiso, repartido a los cuatro roles:
--   SELECT COUNT(*) FROM rol_permisos WHERE permiso = 'casos.continuidad';  -> 4
--
--   -- 3. Prueba negativa, la que importa: aplicar la migracion NO enlaza
--   --    ningun caso por su cuenta. Solo una persona los crea.
--   SELECT COUNT(*) FROM casos_gestion WHERE continua_de IS NOT NULL;       -> 0
-- ============================================================================
