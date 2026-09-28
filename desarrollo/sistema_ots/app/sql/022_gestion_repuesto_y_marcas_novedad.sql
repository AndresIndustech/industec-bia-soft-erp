-- ============================================================================
-- 022_gestion_repuesto_y_marcas_novedad.sql — En qué punto de la gestión de
-- KFC está el repuesto, y las cuatro marcas con que la administradora
-- identifica una novedad.
--
-- Pedido de la administradora del 2026-09-27 (ampliación del cuadro «En qué
-- estado están» del inicio). En SAP ella registra, para una orden a espera de
-- repuesto, en qué manos está: PENDIENTE GESTION PROVEEDORES NACIONALES ·
-- PENDIENTE GESTION BODEGA KFC · PENDIENTE GESTION JEFES TEC. DE
-- MANTENIMIENTO KFC · PENDIENTE APROBACION PARA DESPACHO. Y en las novedades
-- quiere identificar: PROVEEDOR EXTERNO · POR DECIDIR (QUITAR O MEJORAR) ·
-- RIESGO ALTO (DERIVAR RESPONSABLE) · YA TIENE AVISO EN SAP (MANT.
-- CONSTRUCTIVO INDUSTEC).
--
-- POR QUE UNA COLUMNA EN casos_gestion Y NO UN ESTADO DE `pendientes`
--   La cifra que ella mira es POR ORDEN (las «a espera de repuesto» del
--   cuadro), y una orden puede estar a espera de repuesto sin ninguna
--   solicitud en `pendientes` (las de antes de la 007, o las que se marcaron
--   sin pedir pieza). Meter estos cuatro pasos en el ENUM de 21 estados de la
--   solicitud obligaría a inventar transiciones y a tocar el semáforo. Es un
--   dato aparte del estado, como `otro_trabajo` (011): NULL = sin precisar,
--   y el estado ESPERA_REPUESTO no cambia. Cuando la orden sale de
--   ESPERA_REPUESTO el dato se queda como historia, igual que `otro_trabajo`.
--
-- POR QUE UNA TABLA PARA LAS MARCAS Y NO CUATRO COLUMNAS
--   Una novedad puede llevar más de una (riesgo alto Y proveedor externo), y
--   cada marca tiene su quién y su cuándo. Una fila por (novedad, marca), con
--   clave primaria compuesta: ponerla dos veces no duplica; quitarla es
--   borrar la fila (y la bitácora guarda antes/después).
--
-- Idempotente: IF NOT EXISTS y ON DUPLICATE KEY en todo. No toca datos.
-- Se deshace con:
--   ALTER TABLE casos_gestion DROP COLUMN repuesto_gestion, DROP COLUMN
--     repuesto_gestion_por, DROP COLUMN repuesto_gestion_en;
--   DROP TABLE novedad_marcas;
--   DELETE FROM rol_permisos WHERE permiso = 'casos.repuesto_gestion';
--   DELETE FROM permisos WHERE codigo = 'casos.repuesto_gestion';
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. La gestión del repuesto, por orden.
-- ----------------------------------------------------------------------------
ALTER TABLE casos_gestion
  ADD COLUMN IF NOT EXISTS repuesto_gestion
      ENUM('PROVEEDORES_NACIONALES','BODEGA_KFC','JEFES_TEC_KFC','APROBACION_DESPACHO') NULL
      COMMENT 'En que punto de la gestion de KFC esta el repuesto (lo registra la administracion en SAP). NULL = sin precisar. Solo tiene sentido con estado ESPERA_REPUESTO; al salir de ese estado se queda como historia',
  ADD COLUMN IF NOT EXISTS repuesto_gestion_por INT(10) UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS repuesto_gestion_en  DATETIME NULL;

-- ----------------------------------------------------------------------------
-- 2. Las marcas de la novedad.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS novedad_marcas (
    novedad_id  INT UNSIGNED NOT NULL,
    marca       ENUM('PROVEEDOR_EXTERNO','POR_DECIDIR_KFC','RIESGO_ALTO','AVISO_SAP') NOT NULL
      COMMENT 'PROVEEDOR_EXTERNO: la atiende un proveedor externo a INDUSTEC. POR_DECIDIR_KFC: KFC decide si se quita o se mejora. RIESGO_ALTO: derivar al responsable. AVISO_SAP: ya tiene aviso en SAP como mantenimiento constructivo de INDUSTEC',
    puesta_por  INT UNSIGNED NOT NULL,
    puesta_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (novedad_id, marca),
    KEY idx_marca (marca),
    CONSTRAINT fk_nm_novedad FOREIGN KEY (novedad_id) REFERENCES novedades(novedad_id) ON DELETE CASCADE,
    CONSTRAINT fk_nm_por     FOREIGN KEY (puesta_por) REFERENCES usuarios(usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Las marcas con que la administracion o el jefe de zona identifican una novedad (pedido del 2026-09-27). Una fila por marca; la novedad puede llevar varias.';

-- ----------------------------------------------------------------------------
-- 3. Permiso: precisar la gestión del repuesto. Lo tienen administración y
--    jefe de zona (los dos hablan con KFC por el repuesto); el técnico no.
--    Las marcas de la novedad usan `novedades.gestionar`, que ya existe con
--    el mismo reparto.
-- ----------------------------------------------------------------------------
INSERT INTO permisos (codigo, modulo, nombre, descripcion) VALUES
  ('casos.repuesto_gestion', 'casos', 'Precisar la gestión del repuesto',
   'Anotar en qué punto de la gestión de KFC está el repuesto de una orden a espera de repuesto')
ON DUPLICATE KEY UPDATE modulo = VALUES(modulo), nombre = VALUES(nombre), descripcion = VALUES(descripcion);

INSERT INTO rol_permisos (rol, permiso) VALUES
  ('SUPERADMIN', 'casos.repuesto_gestion'),
  ('ADMIN',      'casos.repuesto_gestion'),
  ('JEFE_ZONA',  'casos.repuesto_gestion')
ON DUPLICATE KEY UPDATE rol = rol;

-- El libro de migraciones lo escribe `aplicar_sql.php` al terminar, con el
-- sha256 del archivo.

-- ============================================================================
-- COMPROBACION (se pega la salida literal al cerrar la tarea):
--
--   SHOW COLUMNS FROM casos_gestion LIKE 'repuesto_gestion%';   -> 3 filas
--   SHOW CREATE TABLE novedad_marcas\G                          -> PRIMARY KEY (novedad_id, marca)
--   SELECT COUNT(*) FROM rol_permisos WHERE permiso = 'casos.repuesto_gestion';  -> 3
--   php verificar_esquema.php   -> TODO OK (bloque «migracion 022»)
-- ============================================================================
