-- ============================================================================
-- 008 - La emisión desde la app nueva: correlativo, fotos, PDF y cola de correo
-- (T2.13, camino (a) que eligió Andrés el 2026-09-10: la emisión se termina en
-- la app nueva y el sistema de producción no se toca).
--
-- BASE: la operativa de Hostinger. Se aplica con aplicar_sql.php y se puede
-- correr dos veces sin duplicar nada (IF NOT EXISTS en todo).
--
-- QUE RESUELVE, con los casos de producción que lo motivaron:
--
--   - El correlativo sale hoy de un archivo por zona que se lee, se suma y se
--     reescribe sin candado: dos envíos a la vez sacan el mismo número, y un
--     GET vacío quema uno (87 envíos en blanco). Aquí se reserva con una sola
--     sentencia atómica y solo cuando la orden ya pasó la validación.
--   - Las fotos viajaban dentro del envío, en base64 (~48 MB con 35 fotos contra
--     un límite de 64 MB): si se pasaba, el envío se perdía entero y salía un PDF
--     vacío. Aquí suben de una en una, antes que la orden, que solo lleva sus
--     identificadores.
--   - El correo salía en el mismo momento: con el SMTP caído la orden no llegaba
--     a nadie (82 fallos). Aquí se encola; la orden ya está guardada.
--
-- LOS NUMEROS NO SE SIEMBRAN AQUI. En el sitio de pruebas, Emision.php crea cada
-- serie al primer uso desde el 9000, para que una OT de prueba no lleve un número
-- que exista o vaya a existir pronto en producción. En producción se cargan a
-- mano los contadores reales (`counter_{zona}.txt` de cada módulo), con la
-- emisión detenida: una serie sin contador detiene la emisión, no se inventa.
-- ============================================================================


-- ----------------------------------------------------------------------------
-- 1. Correlativos: una fila por serie (módulo y zona), como los contadores de hoy.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS correlativos (
    serie     VARCHAR(30)  NOT NULL PRIMARY KEY
              COMMENT 'MODULO:ZONA, p. ej. CORRECTIVO:UIO. Es la clave de negocio del contador (I-9)',
    ultimo    INT UNSIGNED NOT NULL DEFAULT 0
              COMMENT 'El último número entregado. El siguiente es ultimo + 1',
    nota      VARCHAR(255) NULL,
    tocado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Se reserva con UPDATE ... SET ultimo = LAST_INSERT_ID(ultimo + 1). Nunca SELECT y después UPDATE';


-- ----------------------------------------------------------------------------
-- 2. La orden recibida guarda cuándo se emitió, la huella de su PDF y por qué
--    no se pudo, si no se pudo. `id_industec` ya existía (007) y ahora es único:
--    dos órdenes con el mismo número es el error que esta migración cierra.
-- ----------------------------------------------------------------------------
ALTER TABLE ot_capturadas
    ADD COLUMN IF NOT EXISTS emitida_en DATETIME NULL
        COMMENT 'Cuándo se generó su PDF. NULL = recibida y todavía sin emitir' AFTER id_industec,
    ADD COLUMN IF NOT EXISTS pdf_sha256 CHAR(64) NULL
        COMMENT 'Huella del PDF que se generó: lo que se entregó se puede comprobar después' AFTER emitida_en,
    ADD COLUMN IF NOT EXISTS emision_error VARCHAR(300) NULL
        COMMENT 'Por qué no se pudo emitir. Se limpia cuando sale' AFTER pdf_sha256,
    ADD UNIQUE KEY IF NOT EXISTS uq_cap_industec (id_industec);


-- ----------------------------------------------------------------------------
-- 3. Las fotos de cada orden, subidas de una en una antes que ella.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ot_fotos (
    foto_id    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    foto_uuid  CHAR(36)     NOT NULL
               COMMENT 'Lo genera el celular: el reintento de la subida no la duplica',
    envio_uuid CHAR(36)     NOT NULL
               COMMENT 'La orden a la que pertenece. Puede no existir todavía: la foto sube antes',
    usuario_id INT UNSIGNED NOT NULL,
    orden_n    TINYINT UNSIGNED NOT NULL DEFAULT 0
               COMMENT 'Su lugar en la orden: el PDF las pone en el orden en que se tomaron',
    ruta       VARCHAR(120) NOT NULL COMMENT 'Relativa a ordenes_fotos/',
    bytes      INT UNSIGNED NOT NULL,
    ancho      SMALLINT UNSIGNED NULL,
    alto       SMALLINT UNSIGNED NULL,
    sha256     CHAR(64)     NOT NULL,
    subida_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_foto (foto_uuid),
    KEY idx_foto_envio (envio_uuid, orden_n),
    CONSTRAINT fk_foto_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Recodificadas en el servidor: sin EXIF, que lleva la ubicación GPS del local y el modelo del teléfono';


-- ----------------------------------------------------------------------------
-- 4. La cola de correo. Una fila por orden y tipo de correo (I-9): reintentar la
--    emisión no encola dos veces.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_queue (
    correo_id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    captura_id  INT UNSIGNED NOT NULL,
    id_industec VARCHAR(60)  NOT NULL,
    tipo        VARCHAR(20)  NOT NULL DEFAULT 'EMISION',
    para        TEXT         NOT NULL COMMENT 'JSON con los destinatarios',
    asunto      VARCHAR(200) NOT NULL,
    cuerpo      TEXT         NOT NULL,
    adjunto     VARCHAR(120) NULL COMMENT 'Nombre del PDF en ordenes_pdf/',
    estado      ENUM('RETENIDO','PENDIENTE','ENVIADO','FALLIDO') NOT NULL DEFAULT 'PENDIENTE'
                COMMENT 'RETENIDO: no sale nunca (sitio de pruebas). PENDIENTE: lo toma el despachador',
    motivo      VARCHAR(255) NULL COMMENT 'Por qué está retenido o por qué falló',
    intentos    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    creado_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    enviado_en  DATETIME     NULL,
    UNIQUE KEY uq_correo (id_industec, tipo),
    KEY idx_correo_estado (estado, creado_en),
    CONSTRAINT fk_correo_captura FOREIGN KEY (captura_id) REFERENCES ot_capturadas(captura_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Con el SMTP caído la orden no se pierde: el correo espera aquí';


-- ============================================================================
-- VERIFICACION despues de aplicar:
--
--   SHOW CREATE TABLE correlativos\G     -> PRIMARY KEY (`serie`)
--   SHOW CREATE TABLE ot_fotos\G         -> UNIQUE KEY `uq_foto` (`foto_uuid`)
--   SHOW CREATE TABLE email_queue\G      -> UNIQUE KEY `uq_correo` (`id_industec`,`tipo`)
--   SHOW INDEX FROM ot_capturadas WHERE Key_name = 'uq_cap_industec';   -> 1 fila, Non_unique = 0
--   SELECT COUNT(*) FROM correlativos;   -> 0 (las series se crean al primer uso)
-- ============================================================================
