-- ============================================================================
-- 015_fotos_por_equipo.sql — Fotos del antes y del después, por equipo.
--
-- EL PROBLEMA. `ot_fotos` guardaba una lista plana de fotos por orden, sin
-- decir a qué equipo pertenecía cada una ni si era del antes o del después.
-- Con una preventiva de 7 equipos, el PDF mezclaba 35 fotos en una sola tira
-- y nadie podía comprobar que un equipo concreto quedó reparado (obs. 1 de la
-- revisión con INDUSTEC, 2026-09-23).
--
-- LAS TRES COLUMNAS NUEVAS SON OPCIONALES A PROPÓSITO (§5.4). Un celular con
-- la app vieja en caché sigue mandando fotos sin `equipo_n` ni `momento`
-- durante la ventana de transición (T2.28.15): NULL es "foto de antes de la
-- 015", y se sigue imprimiendo como hoy, en el bloque de siempre.
--
--   equipo_n   la posición del bloque de equipo AL MOMENTO DE ENVIAR (0-6).
--              No es una clave hacia `equipo_sap`: los bloques se pueden
--              quitar antes de enviar, así que la única referencia estable es
--              su lugar en la orden que de verdad se mandó.
--   momento    ANTES, DESPUES o REPUESTO (esta última la usa T2.28.11: la
--              captura de respaldo del repuesto trabado, no esta subtarea).
--   tomada_en  el `lastModified` del archivo en el celular. Informativo nada
--              más -- lo usa la regla FOTO_ANTES_POSTERIOR para advertir, no
--              para bloquear, porque un celular con el reloj mal puesto no
--              tiene por qué invalidar una foto real (I-7: no se inventa un
--              defecto que la foto no tiene).
--
-- Idempotente (IF NOT EXISTS). Se aplica con:
--     php aplicar_sql.php sql/015_fotos_por_equipo.sql
-- Comprobación: php verificar_esquema.php, bloque «migracion 015».
-- ============================================================================

ALTER TABLE ot_fotos
  ADD COLUMN IF NOT EXISTS equipo_n TINYINT UNSIGNED NULL
      COMMENT 'Posicion del bloque de equipo al momento de enviar (0-6). NULL = foto de antes de la 015, o de una app vieja que no lo manda',
  ADD COLUMN IF NOT EXISTS momento ENUM('ANTES','DESPUES','REPUESTO') NULL
      COMMENT 'ANTES/DESPUES del equipo (T2.28.7); REPUESTO es la captura de respaldo de T2.28.11. NULL = sin clasificar',
  ADD COLUMN IF NOT EXISTS tomada_en DATETIME NULL
      COMMENT 'lastModified del archivo en el celular; informativo, nunca bloquea (I-7)',
  ADD KEY IF NOT EXISTS idx_foto_equipo (envio_uuid, equipo_n, momento);

-- El libro de migraciones lo escribe `aplicar_sql.php` al terminar, con el
-- sha256 del archivo: anotarlo aquí a mano dejaría una huella vacía y la
-- siguiente corrida creería que la migración cambió.


-- ============================================================================
-- COMPROBACION (se pega la salida literal al cerrar la tarea):
--
--   -- 1. Las tres columnas y el indice:
--   SHOW COLUMNS FROM ot_fotos LIKE 'equipo_n';   -> 1 fila
--   SHOW COLUMNS FROM ot_fotos LIKE 'momento';     -> 1 fila, enum('ANTES','DESPUES','REPUESTO')
--   SHOW COLUMNS FROM ot_fotos LIKE 'tomada_en';   -> 1 fila
--   SHOW KEYS FROM ot_fotos WHERE Key_name = 'idx_foto_equipo';
--
--   -- 2. Prueba negativa: aplicar la migracion NO reclasifica ninguna foto
--   --    vieja. Las de antes de la 015 quedan con equipo_n y momento en NULL.
--   SELECT COUNT(*) FROM ot_fotos WHERE momento IS NOT NULL;   -> 0 recien aplicada
-- ============================================================================
