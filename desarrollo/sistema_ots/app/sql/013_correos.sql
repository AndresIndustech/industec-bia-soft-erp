-- ****************************************************************************
-- Revisado línea por línea contra T2_28_OBSERVACIONES_INDUSTEC.md (T2.28.2) y
-- contra Emision::correoLocal() el 2026-09-24: coincide con la especificación,
-- no duplica la regla del correo del local (Destinatarios::resolver() llama a
-- Emision::correoLocal(), no la reescribe) y no necesitó cambios. El borrador
-- anterior (error nº 44, PLAN_INDUSTEC.md §11b punto 7) se completa aquí.
-- ****************************************************************************
-- ============================================================================
-- 013_correos.sql — A quién va cada correo, en una tabla que edita la
-- administración (T2.28.2, obs. 2 y 8 de la revisión con INDUSTEC; D-C, D-G, S-1).
--
-- EL PROBLEMA, MEDIDO (T2_28_OBSERVACIONES_INDUSTEC.md §2). Los destinatarios de
-- una orden estaban fijos en tres sitios a la vez:
--   - el formulario, con «Correo del jefe de operaciones» de solo lectura y el
--     buzón de zona de INDUSTEC dentro;
--   - el maestro de locales (`correo_jefe_op`: jefezonacuenca-loja@ 34 ·
--     jefezona-uio@ 31 · jefetecniconacional@ 30 · vacío 5);
--   - config.php (`correo_por_zona`, `correo_fijos`), que es intocable.
-- Cambiar una copia exigía editar el servidor. Desde esta migración la emisión
-- lee UNA tabla (`correo_destinatarios`), con ámbito (general, zona, local),
-- tipo (para o copia) e historial, y la cola congela `para` y `cc` al encolar.
--
-- LAS DOS CLAVES DE NEGOCIO (I-9), desde el CREATE:
--   uq_destinatario (uso, destino, ambito_clave, correo): la misma dirección no
--       se repite en el mismo ámbito.
--   uq_rol (uso, ambito_clave, rol_unico): un solo buzón de jefe de zona por
--       zona y un solo jefe de operaciones por local. `rol_unico` es NULL para
--       OTRO, y en MariaDB dos NULL no chocan en una UNIQUE: así caben varias
--       copias OTRO y un solo jefe. `ambito_clave` no tiene NULL a propósito:
--       con NULL el ámbito GENERAL se podría duplicar.
--
-- EL CORREO QUE ESCRIBE EL TECNICO NO REESCRIBE EL MAESTRO. Si es válido, no es
-- @industec.me y es distinto del maestro, `envio.php` lo deja PROPUESTO en
-- `locales_correo_propuesto`; la administración lo aprueba en correos.php. Un
-- error de tipeo no puede desviar todas las órdenes siguientes de ese local.
--
-- ADITIVA (S-3): solo CREATE y ADD, más el ENUM de `locales_admin.fuente`
-- ampliado AL FINAL (HISTORICO, para la siembra de T2.28.3). No inserta
-- destinatarios: eso lo hace `correos_sembrar_cli.php --ejecutar`, con
-- aprobación y su cifra exacta. Sin filas, la emisión sigue igual que antes.
--
-- Idempotente (IF NOT EXISTS). Se aplica con:
--     php aplicar_sql.php sql/013_correos.sql
-- Comprobación: php verificar_esquema.php, bloque «migracion 013».
-- ============================================================================

CREATE TABLE IF NOT EXISTS correo_destinatarios (
  destinatario_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  uso      ENUM('ORDEN','REPORTE') NOT NULL COMMENT 'ORDEN: cada OT emitida. REPORTE: envío automático a clientes (T2.3), todavía sin emisor',
  destino  ENUM('INTERNO','CLIENTE') NOT NULL COMMENT 'INTERNO: INDUSTEC. CLIENTE: Grupo KFC',
  ambito   ENUM('GENERAL','ZONA','LOCAL') NOT NULL,
  zona     ENUM('UIO','LARB','CNLJ','OTRA') NULL COMMENT 'obligatoria si ambito=ZONA',
  local_codigo VARCHAR(12) NULL COMMENT 'obligatorio si ambito=LOCAL',
  cadena   VARCHAR(40) NULL COMMENT 'opcional: solo para esa cadena (NULL = todas)',
  rol      ENUM('JEFE_ZONA','JEFE_OPERACIONES','OTRO') NOT NULL DEFAULT 'OTRO'
           COMMENT 'JEFE_ZONA: buzón institucional de INDUSTEC, uno por zona, se asigna solo. JEFE_OPERACIONES: el de KFC de cada local',
  rol_unico VARCHAR(20) AS (IF(rol IN ('JEFE_ZONA','JEFE_OPERACIONES'), rol, NULL)) STORED
            COMMENT 'NULL para OTRO: la UNIQUE deja varias copias OTRO y un solo jefe',
  ambito_clave VARCHAR(40) AS (CONCAT(ambito, ':', IFNULL(zona, ''), ':', IFNULL(local_codigo, ''))) STORED
            COMMENT 'sin NULL a propósito: en MariaDB dos NULL no chocan en una UNIQUE y el ámbito GENERAL se duplicaría',
  tipo     ENUM('PARA','COPIA') NOT NULL DEFAULT 'COPIA',
  correo   VARCHAR(160) NOT NULL,
  nombre   VARCHAR(120) NULL COMMENT 'a quién corresponde (p. ej. «Jefe de zona Quito»), no la persona de turno',
  activo   TINYINT(1) NOT NULL DEFAULT 1,
  origen   ENUM('MANUAL','PRODUCCION','HISTORICO','CONFIG_PHP') NOT NULL DEFAULT 'MANUAL',
  creado_por INT UNSIGNED NOT NULL, creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_por INT UNSIGNED NULL, actualizado_en DATETIME NULL,
  UNIQUE KEY uq_destinatario (uso, destino, ambito_clave, correo),
  UNIQUE KEY uq_rol (uso, ambito_clave, rol_unico) COMMENT 'un solo jefe de zona por zona y un solo jefe de operaciones por local',
  KEY idx_dest_busca (uso, activo, ambito, zona, local_codigo),
  CONSTRAINT fk_dest_creado FOREIGN KEY (creado_por) REFERENCES usuarios(usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS correo_destinatarios_cambios (
  cambio_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  destinatario_id INT UNSIGNED NOT NULL,
  accion ENUM('ALTA','EDICION','ACTIVAR','DESACTIVAR') NOT NULL,
  antes JSON NULL, despues JSON NULL,
  por INT UNSIGNED NOT NULL, en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dcamb (destinatario_id, en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Solo se agrega';

CREATE TABLE IF NOT EXISTS locales_correo_propuesto (
  propuesta_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  local_codigo VARCHAR(12) NOT NULL,
  campo  ENUM('LOCAL') NOT NULL DEFAULT 'LOCAL' COMMENT 'Solo el correo del local: el jefe de operaciones ya no se escribe en el formulario (D-G)',
  correo VARCHAR(160) NOT NULL,
  correo_anterior VARCHAR(160) NULL,
  envio_uuid CHAR(36) NULL, propuesto_por INT UNSIGNED NOT NULL,
  propuesto_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  veces INT UNSIGNED NOT NULL DEFAULT 1,
  estado ENUM('PROPUESTO','APROBADO','RECHAZADO','APLICADO') NOT NULL DEFAULT 'PROPUESTO',
  revisado_por INT UNSIGNED NULL, revisado_en DATETIME NULL, nota VARCHAR(300) NULL,
  UNIQUE KEY uq_correo_prop (local_codigo, campo, correo),
  KEY idx_correo_prop_estado (estado, propuesto_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE email_queue ADD COLUMN IF NOT EXISTS cc JSON NULL COMMENT 'Copias congeladas al encolar: si mañana cambia la configuración, se sabe a quién fue' AFTER para;

-- T2.28.3 (D-E): el correo de cada administrador se aprende como su nombre
ALTER TABLE locales_admin
  MODIFY fuente ENUM('ORDEN','MANUAL','HISTORICO') NOT NULL DEFAULT 'ORDEN',
  ADD COLUMN IF NOT EXISTS correo_veces INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS correo_visto DATETIME NULL;


-- ----------------------------------------------------------------------------
-- El permiso (patrón de la 012). Solo administración y superadministradores
-- (D-C): a quién le llega una orden de KFC no lo decide un jefe de zona ni un
-- técnico. El jefe de zona y el técnico reciben 403 en correos.php.
-- ----------------------------------------------------------------------------
INSERT INTO permisos (codigo, modulo, nombre, descripcion) VALUES
  ('correos.configurar', 'correos', 'Configurar a quien van los correos de las ordenes',
   'Copias internas y al cliente por zona, generales y por local; aprobar o rechazar el correo del local que propone un tecnico. Nada se borra: se desactiva')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), descripcion = VALUES(descripcion);

INSERT INTO rol_permisos (rol, permiso) VALUES
  ('SUPERADMIN','correos.configurar'), ('ADMIN','correos.configurar')
ON DUPLICATE KEY UPDATE rol = rol;

-- El libro de migraciones lo escribe `aplicar_sql.php` al terminar, con el
-- sha256 del archivo: anotarlo aquí a mano dejaría una huella vacía y la
-- siguiente corrida creería que la migración cambió.


-- ============================================================================
-- COMPROBACION (se pega la salida literal al cerrar la tarea):
--
--   -- 1. Las dos claves de negocio, sobre ambito_clave:
--   SHOW CREATE TABLE correo_destinatarios;   -> uq_destinatario y uq_rol
--
--   -- 2. La cola guarda las copias aparte:
--   SHOW COLUMNS FROM email_queue LIKE 'cc';  -> 1 fila, json
--
--   -- 3. El permiso, a los dos roles de administración:
--   SELECT COUNT(*) FROM rol_permisos WHERE permiso = 'correos.configurar';  -> 2
--
--   -- 4. Prueba negativa: aplicar la migración NO siembra destinatarios. Eso
--   --    lo hace correos_sembrar_cli.php --ejecutar, con aprobación.
--   SELECT COUNT(*) FROM correo_destinatarios;                               -> 0
-- ============================================================================
