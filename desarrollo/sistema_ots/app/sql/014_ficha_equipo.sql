-- ============================================================================
-- 014_ficha_equipo.sql — La marca, el modelo y la serie de cada equipo se
-- quedan (T2.28.6, obs. 4 de la revisión con INDUSTEC; S-2).
--
-- EL PROBLEMA. El maestro de activos que manda SAP no trae marca, modelo ni
-- serie -el técnico los escribe a mano en cada orden, y hasta hoy esos tres
-- campos solo viajaban DENTRO del PDF: la próxima orden del mismo equipo
-- empezaba de cero, y una placa reemplazada no dejaba ningún rastro.
--
-- LA FICHA. `equipos_ficha` guarda el último valor conocido de cada equipo,
-- por su clave (`equipo_sap` real, o `PROPUESTO:<uuid>` para un equipo nuevo
-- todavía sin aprobar — la misma convención que ya usa Catalogo::
-- fusionarPropuestos()). `equipos_ficha_cambios` es el historial: se agrega,
-- nunca se corrige ni se borra una fila (I-9 no aplica aquí porque no hay
-- clave de negocio que deduplicar: cada cambio es un hecho distinto).
--
-- QUIÉN ESCRIBE. Solo `envio.php`, con una orden NUEVA (nunca un reintento):
-- upsert de la ficha con los valores normalizados, sin pisar un dato real con
-- un vacío o un marcador (S/N, XXX...), y una fila en el historial por cada
-- diferencia. Si la serie cambia desde un valor que no era vacío, además
-- queda en la bitácora `EQUIPO_SERIE_CAMBIO` («posible reemplazo del
-- equipo»): es la señal que el jefe de zona necesita para preguntar por qué.
--
-- ADITIVA (S-3): dos CREATE TABLE nuevas, nada más. Sin la migración, envio.php
-- sigue funcionando exactamente igual (el upsert va en try/catch, como el de
-- locales_admin) y catalogos.php sirve `fichas: {}`.
--
-- Idempotente (IF NOT EXISTS). Se aplica con:
--     php aplicar_sql.php sql/014_ficha_equipo.sql
-- Comprobación: php verificar_esquema.php, bloque «migracion 014».
-- ============================================================================

CREATE TABLE IF NOT EXISTS equipos_ficha (
  equipo_clave VARCHAR(60) NOT NULL PRIMARY KEY COMMENT 'equipo_sap, o PROPUESTO:<uuid>',
  local_codigo VARCHAR(12) NOT NULL,
  marca VARCHAR(80) NULL, modelo VARCHAR(80) NULL, serie VARCHAR(80) NULL,
  sin_placa TINYINT(1) NOT NULL DEFAULT 0,
  fuente ENUM('ORDEN','ADMIN') NOT NULL,
  actualizado_por INT UNSIGNED NOT NULL, actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  envio_uuid CHAR(36) NULL,
  KEY idx_ficha_local (local_codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS equipos_ficha_cambios (
  cambio_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  equipo_clave VARCHAR(60) NOT NULL,
  campo ENUM('marca','modelo','serie','sin_placa') NOT NULL,
  antes VARCHAR(80) NULL, despues VARCHAR(80) NULL,
  por INT UNSIGNED NOT NULL, en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, envio_uuid CHAR(36) NULL,
  KEY idx_cambio_equipo (equipo_clave, en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Solo se agrega: es el historial';

-- El libro de migraciones lo escribe `aplicar_sql.php` al terminar, con el
-- sha256 del archivo: anotarlo aquí a mano dejaría una huella vacía y la
-- siguiente corrida creería que la migración cambió.


-- ============================================================================
-- COMPROBACION (se pega la salida literal al cerrar la tarea):
--
--   -- 1. Las dos tablas:
--   SHOW TABLES LIKE 'equipos_ficha%';                       -> 2 filas
--
--   -- 2. La clave de negocio (I-9): equipo_clave es la PRIMARY.
--   SHOW KEYS FROM equipos_ficha WHERE Key_name = 'PRIMARY';  -> equipo_clave
--
--   -- 3. Prueba negativa: aplicar la migración NO siembra ninguna ficha
--   --    desde el histórico (prohibido, tabla de permisos de T2.28.6).
--   SELECT COUNT(*) FROM equipos_ficha;                       -> 0
-- ============================================================================
