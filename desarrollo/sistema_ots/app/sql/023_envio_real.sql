-- ============================================================================
-- 023_envio_real.sql — El envío real de las OT INDUSTEC, zona por zona, y la
-- cuenta desde la que sale el correo (T2.29, pedido de Andrés del 2026-09-29).
--
-- EL PROBLEMA, MEDIDO EL 2026-09-29. El sitio corría entero en modo PRUEBA
-- (config.php sin `emision_modo`): toda OT salía con número de la serie 9000 y
-- su correo quedaba RETENIDO. Los técnicos veían «del piloto: NO llega a Grupo
-- KFC» y tenían que emitirla otra vez por el formulario viejo. 18 OT del 24 al
-- 29-sep (16 de UIO, 2 de CNLJ) no le llegaron a nadie. Pasar a producción
-- exigía editar config.php en el servidor —el único archivo intocable del
-- sitio— y la cuenta SMTP no tenía dónde guardarse: config.php no la tiene y el
-- sistema viejo la guarda en texto plano en cinco copias.
--
-- LO QUE AGREGA (aditiva, S-3):
--   emision_zonas           el modo de cada zona (PRUEBA / PRODUCCION), desde
--                           cuándo y quién. NACE CON LAS CUATRO EN PRUEBA:
--                           aplicar la migración no activa ningún envío.
--   emision_zonas_cambios   el historial, con los contadores que se sembraron.
--   correo_cuentas          la cuenta SMTP. La clave va cifrada (AES-256-GCM) y
--                           la llave NO está en la base (Correo::llave()).
--   correo_cuentas_cambios  el historial. Nunca guarda la clave.
--   correlativos            con qué número del formulario viejo se sembró cada
--                           serie al activar su zona (para vigilar si se sigue
--                           usando después).
--   email_queue             desde qué cuenta salió cada correo.
--   Las series del piloto se apartan: CORRECTIVO:UIO (9205) se copia a
--   PRUEBA:CORRECTIVO:UIO, para que la serie real se pueda sembrar sin perder la
--   numeración del piloto ni repetir un número 9xxx ya emitido.
--   Dos permisos, solo para SUPERADMIN: emision.activar y correos.cuenta.
--
-- Idempotente (IF NOT EXISTS, ON DUPLICATE KEY). Se aplica con:
--     php aplicar_sql.php sql/023_envio_real.sql
-- Comprobación: php verificar_esquema.php, bloque «migracion 023».
-- ============================================================================

CREATE TABLE IF NOT EXISTS emision_zonas (
  zona   ENUM('UIO','LARB','CNLJ','OTRA') NOT NULL PRIMARY KEY
         COMMENT 'Clave de negocio (I-9): un solo modo por zona',
  modo   ENUM('PRUEBA','PRODUCCION') NOT NULL DEFAULT 'PRUEBA'
         COMMENT 'PRUEBA: serie 9000 y correo RETENIDO (el piloto). PRODUCCION: número real y el correo sale al local, a Grupo KFC y a las copias',
  desde  DATETIME NULL
         COMMENT 'Desde cuándo rige. Una OT que el técnico llenó antes de esta hora se emite como del piloto: así se lo decía la app',
  por    INT UNSIGNED NULL,
  nota   VARCHAR(300) NULL,
  CONSTRAINT fk_emz_por FOREIGN KEY (por) REFERENCES usuarios(usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='El interruptor del envío real, por zona. Si config.php trae emision_modo, manda ese (PRUEBA = freno de emergencia de todo el sitio)';

INSERT INTO emision_zonas (zona, modo) VALUES ('UIO', 'PRUEBA'), ('LARB', 'PRUEBA'), ('CNLJ', 'PRUEBA'), ('OTRA', 'PRUEBA')
ON DUPLICATE KEY UPDATE zona = zona;

CREATE TABLE IF NOT EXISTS emision_zonas_cambios (
  cambio_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  zona      ENUM('UIO','LARB','CNLJ','OTRA') NOT NULL,
  antes     ENUM('PRUEBA','PRODUCCION') NOT NULL,
  despues   ENUM('PRUEBA','PRODUCCION') NOT NULL,
  detalle   JSON NULL COMMENT 'Al activar: por serie, el número del formulario viejo y desde cuál sigue la app',
  por       INT UNSIGNED NOT NULL,
  en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_emzc (zona, en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Solo se agrega';

CREATE TABLE IF NOT EXISTS correo_cuentas (
  cuenta_id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre           VARCHAR(80)  NOT NULL COMMENT 'Cómo se la reconoce en Correos',
  host             VARCHAR(120) NOT NULL,
  puerto           SMALLINT UNSIGNED NOT NULL,
  seguridad        ENUM('STARTTLS','SSL') NOT NULL
                   COMMENT 'STARTTLS (587) o SSL directo (465). Sin cifrar no se admite: la clave viajaría en claro',
  usuario          VARCHAR(160) NOT NULL,
  clave_cifrada    TEXT NULL COMMENT 'AES-256-GCM en base64 (iv|tag|cifrado). La llave vive en config.php, no aquí',
  llave_huella     CHAR(16) NULL COMMENT 'Huella de la llave con que se cifró: si la llave cambia se avisa, en vez de mandarle basura al SMTP',
  remitente        VARCHAR(160) NOT NULL COMMENT 'La dirección From',
  remitente_nombre VARCHAR(120) NOT NULL,
  activa           TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'La que usa el despachador. Una sola',
  activa_unica     TINYINT(1) AS (IF(activa = 1, 1, NULL)) STORED
                   COMMENT 'NULL si no está activa: en MariaDB dos NULL no chocan en una UNIQUE, así que queda una sola activa',
  habilitada       TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Nada se borra: se deshabilita',
  origen           ENUM('MANUAL','SISTEMA_VIEJO') NOT NULL DEFAULT 'MANUAL'
                   COMMENT 'SISTEMA_VIEJO: copiada de los config.php del formulario viejo (correo_cuenta_importar_cli.php)',
  probada_en       DATETIME NULL,
  probada_ok       TINYINT(1) NULL,
  probada_detalle  VARCHAR(300) NULL,
  creado_por       INT UNSIGNED NOT NULL,
  creado_en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_por  INT UNSIGNED NULL,
  actualizado_en   DATETIME NULL,
  UNIQUE KEY uq_cuenta (host, usuario) COMMENT 'La misma cuenta en el mismo servidor es una sola fila (I-9)',
  UNIQUE KEY uq_cuenta_activa (activa_unica) COMMENT 'Una sola cuenta activa',
  CONSTRAINT ck_cuenta_activa_habilitada CHECK (activa = 0 OR habilitada = 1),
  CONSTRAINT fk_ccu_creado FOREIGN KEY (creado_por) REFERENCES usuarios(usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Desde qué cuenta salen los correos. La clave nunca se muestra ni sale de aquí sin cifrar';

CREATE TABLE IF NOT EXISTS correo_cuentas_cambios (
  cambio_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cuenta_id INT UNSIGNED NOT NULL,
  accion    ENUM('ALTA','EDICION','CLAVE','ACTIVAR','DESHABILITAR','HABILITAR','PRUEBA_CONEXION','PRUEBA_ENVIO','IMPORTACION') NOT NULL,
  antes     JSON NULL,
  despues   JSON NULL COMMENT 'Nunca la clave: a lo sumo, que cambió',
  resultado VARCHAR(300) NULL,
  por       INT UNSIGNED NOT NULL,
  en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ccc (cuenta_id, en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Solo se agrega';

ALTER TABLE correlativos
  ADD COLUMN IF NOT EXISTS viejo_al_activar INT UNSIGNED NULL
      COMMENT 'El contador del formulario viejo cuando se activó la zona. Si después sube, alguien sigue usando el formulario viejo' AFTER nota,
  ADD COLUMN IF NOT EXISTS sembrado_en DATETIME NULL AFTER viejo_al_activar,
  ADD COLUMN IF NOT EXISTS sembrado_por INT UNSIGNED NULL AFTER sembrado_en;

ALTER TABLE email_queue
  ADD COLUMN IF NOT EXISTS enviado_desde VARCHAR(160) NULL
      COMMENT 'La dirección desde la que salió (la cuenta activa en ese momento)' AFTER enviado_en;

-- Las series del piloto, aparte. Sin esto, sembrar CORRECTIVO:UIO con el
-- contador real haría que la próxima OT de una cuenta de prueba tomara un
-- número de la serie 9000 ya emitido (el piloto llegó a 9205 en UIO).
-- La tabla derivada con columnas renombradas (s, u) es a propósito: con
-- `SELECT … FROM correlativos` directo, el `ultimo` del ON DUPLICATE KEY UPDATE
-- es ambiguo y MariaDB aborta con el error 1052 (medido al probar esta
-- migración sobre un volcado del 29-sep, antes de aplicarla en el servidor).
INSERT INTO correlativos (serie, ultimo, nota)
SELECT CONCAT('PRUEBA:', x.s), x.u, 'serie del piloto (modo PRUEBA), apartada por la 023 el día que se sembró la real'
  FROM (SELECT serie AS s, ultimo AS u FROM correlativos
         WHERE serie NOT LIKE 'PRUEBA:%' AND serie NOT LIKE 'ENSAYO:%' AND ultimo >= 9000) AS x
ON DUPLICATE KEY UPDATE ultimo = GREATEST(ultimo, VALUES(ultimo));

-- ----------------------------------------------------------------------------
-- Los permisos (patrón de la 013). Solo SUPERADMIN: activar el envío real de
-- una zona decide qué recibe Grupo KFC, y la cuenta de envío lleva una clave.
-- La administradora ve el estado en Correos, sin los botones.
-- ----------------------------------------------------------------------------
INSERT INTO permisos (codigo, modulo, nombre, descripcion) VALUES
  ('emision.activar', 'correos', 'Activar el envío real de las OT INDUSTEC por zona',
   'Pasar una zona del piloto al envío real (y volver): siembra el contador desde el formulario viejo y desde ese momento el correo sale al local, a Grupo KFC y a las copias'),
  ('correos.cuenta', 'correos', 'Configurar la cuenta desde la que salen los correos',
   'Servidor, usuario, clave y remitente del correo; probar la conexión y mandar un correo de prueba. La clave nunca se muestra')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), descripcion = VALUES(descripcion);

INSERT INTO rol_permisos (rol, permiso) VALUES
  ('SUPERADMIN', 'emision.activar'), ('SUPERADMIN', 'correos.cuenta')
ON DUPLICATE KEY UPDATE rol = rol;

-- El libro de migraciones lo escribe `aplicar_sql.php` al terminar.

-- ============================================================================
-- COMPROBACION (se pega la salida literal al cerrar la tarea):
--
--   -- 1. Las claves de negocio:
--   SHOW CREATE TABLE correo_cuentas;   -> uq_cuenta (host, usuario) y uq_cuenta_activa
--   SHOW CREATE TABLE emision_zonas;    -> PRIMARY KEY (zona)
--
--   -- 2. Prueba negativa: aplicar la migración NO activa ningún envío.
--   SELECT zona, modo FROM emision_zonas;          -> las cuatro en PRUEBA
--   SELECT COUNT(*) FROM correo_cuentas;           -> 0 (la importa correo_cuenta_importar_cli.php)
--
--   -- 3. Las series del piloto, apartadas:
--   SELECT serie, ultimo FROM correlativos ORDER BY serie;
--     -> PRUEBA:CORRECTIVO:UIO con el mismo número que CORRECTIVO:UIO
--
--   -- 4. Los permisos, solo a SUPERADMIN:
--   SELECT rol, permiso FROM rol_permisos WHERE permiso IN ('emision.activar','correos.cuenta');  -> 2 filas
-- ============================================================================
