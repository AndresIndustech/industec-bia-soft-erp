-- ============================================================================
-- 003 - Preparar la base para que el formulario escriba directo (T2.1.1/T2.1.5)
--
-- >>> NO APLICADA. Requiere aprobacion explicita: toca el esquema de la base
-- >>> de produccion (industec-escritura-mysql). Aqui esta razonada y lista.
--
-- Hay que aplicarla ANTES de la primera escritura en vivo del formulario.
-- Hacerlo despues obliga a migrar filas ya escritas.
-- ============================================================================


-- ----------------------------------------------------------------------------
-- 1. `fuente` no tiene un valor para "lo escribio el propio formulario"
--
-- Hoy el ENUM es ('DRIVE_HISTORICO','SISTEMA_DESCARGADO','IMAP_EN_VIVO') y las
-- 7.416 filas son DRIVE_HISTORICO. Sin un valor propio, una orden nacida en el
-- formulario seria indistinguible de una recuperada del Drive, y son cosas muy
-- distintas: la del formulario tiene los campos tal como los digito el tecnico;
-- la del Drive paso por extraccion de PDF, con lo que eso implica de perdida.
-- ----------------------------------------------------------------------------
ALTER TABLE ots
  MODIFY COLUMN fuente ENUM(
    'DRIVE_HISTORICO',
    'SISTEMA_DESCARGADO',
    'IMAP_EN_VIVO',
    'FORMULARIO_WEB'
  ) NOT NULL
  COMMENT 'De donde salio la fila. FORMULARIO_WEB = la escribio submit.php en el momento del envio';


-- ----------------------------------------------------------------------------
-- 2. La UNIQUE KEY compuesta: por que NO se toca
--
-- La auditoria propuso pasar `dia_intervencion` a NOT NULL DEFAULT 0 para que
-- uq_ots_zona_correlativo_modulo dejara de ser inerte (MySQL considera
-- distintas dos filas con NULL en una columna del indice unico, y hay 6.499
-- filas con NULL ahi).
--
-- SE VERIFICO CONTRA LOS DATOS REALES ANTES DE ESCRIBIR ESTO, Y NO SE PUEDE:
-- la clave (zona, modulo, correlativo) NO es unica en la operacion real.
--
--   a) 9 pares de ordenes DISTINTAS comparten (zona, modulo, correlativo).
--      Son documentos legitimos y diferentes, no duplicados. Por ejemplo:
--        OT-0023-K170EC-10279789-UIO  y  OT-0023-T050EC-UIO
--        OT-1114-E040EC-10318823-LARB y  OT-1114-K147EC-10317494-LARB
--      Es la huella de la condicion de carrera del contador de texto plano.
--
--   b) El correlativo se solapa entre anios. CNLJ 2025 llega a 1225 y CNLJ 2026
--      arranca en 622: el mismo numero existe dos veces con dos anios distintos.
--
--   c) El contador de UIO en el servidor esta en 1820, pero la base ya tiene
--      4 ordenes UIO con correlativo entre 1821 y 2064. Cuando el contador
--      vuelva a pasar por ahi, colisionaran.
--
-- Forzar la UNIQUE KEY haria fallar el ALTER hoy, y si se resolviera a mano,
-- haria fallar inserciones legitimas manana.
--
-- LA CLAVE DE NEGOCIO REAL YA ESTA DECLARADA Y YA ES UNICA: `id_industec` es
-- la PRIMARY KEY, y es exactamente lo que dice PLAN_INDUSTEC.md 6.4 ("Clave
-- natural de una orden: id_industec. Toda carga es upsert sobre esa clave").
-- El invariante I-9 se cumple por ahi, no por la compuesta.
--
-- Lo que si aporta la compuesta es DETECTAR colisiones, que es informacion
-- operativa real: significa que el contador se reinicio o hubo una carrera.
-- Asi que se convierte en indice normal y la deteccion la hace el auditor.
-- ----------------------------------------------------------------------------
ALTER TABLE ots
  DROP INDEX uq_ots_zona_correlativo_modulo,
  ADD INDEX idx_ots_zona_correlativo_modulo (zona, modulo, correlativo, dia_intervencion);


-- ----------------------------------------------------------------------------
-- 3. Correlativos con reserva atomica (T2.1.5)
--
-- Reemplaza a contadores/counter_{zona}.txt, que hoy se lee, se incrementa y se
-- escribe sin flock: dos tecnicos de la misma zona enviando en el mismo segundo
-- obtienen el mismo numero y el segundo PDF sobrescribe al primero en silencio.
--
-- El INSERT ... ON DUPLICATE KEY UPDATE valor = LAST_INSERT_ID(valor + 1) es
-- atomico dentro de InnoDB: reserva y lectura en una sola sentencia, sin
-- transaccion explicita y sin SELECT ... FOR UPDATE. Se lee despues con
-- LAST_INSERT_ID(), que es por conexion.
--
--   INSERT INTO correlativos (modulo, zona, valor) VALUES (?, ?, 1)
--     ON DUPLICATE KEY UPDATE valor = LAST_INSERT_ID(valor + 1);
--   SELECT LAST_INSERT_ID();
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS correlativos (
  modulo ENUM('CORRECTIVO','PREVENTIVO','OTROS') NOT NULL,
  zona   ENUM('UIO','LARB','CNLJ','OTRA')        NOT NULL,
  valor  INT UNSIGNED                            NOT NULL DEFAULT 0,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (modulo, zona)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Reserva atomica del correlativo. Reemplaza a contadores/counter_{zona}.txt';


-- ----------------------------------------------------------------------------
-- 4. Semilla de los correlativos
--
-- CUIDADO: la semilla NO puede salir de MAX(correlativo) de la tabla `ots`.
-- Se comprobo: 347 filas llevan correlativos sinteticos en el rango 90000+,
-- asignados por el saneamiento a documentos cuyo numero real no se pudo leer
-- (OT-0087-Fci-0-UIO, OT-0133-G021-Pendiente-UIO). Sembrar con MAX daria
-- 90056 y la proxima orden saldria como OT-90057.
--
-- La unica fuente valida es el contador del SERVIDOR, que es lo que el sistema
-- vivo esta usando de verdad. Valores leidos el 2026-09-03:
--
--   correctivo: UIO 1820 | LARB 2220 | CNLJ 2423
--   preventivo: UIO  210 | LARB  322 | CNLJ  206
--   otros    :             37 (sin zona: el modulo no la captura)
--
-- >>> ANTES DE APLICAR: volver a leerlos del servidor. Estos ya estan viejos y
-- >>> el sistema sigue emitiendo ordenes. Sembrar por debajo del real genera
-- >>> duplicados; sembrar por encima solo deja huecos, que es lo preferible.
--
--   ssh -p 65002 u671729428@<IP> "cat <DOCROOT>/ot/pruebas/ot_normal_v3/*/contadores/counter_*.txt"
-- ----------------------------------------------------------------------------
INSERT INTO correlativos (modulo, zona, valor) VALUES
  ('CORRECTIVO', 'UIO',  1820),
  ('CORRECTIVO', 'LARB', 2220),
  ('CORRECTIVO', 'CNLJ', 2423),
  ('PREVENTIVO', 'UIO',   210),
  ('PREVENTIVO', 'LARB',  322),
  ('PREVENTIVO', 'CNLJ',  206),
  ('OTROS',      'OTRA',   37)
ON DUPLICATE KEY UPDATE
  -- Nunca hacia atras. Si la fila ya existe con un valor mayor, se respeta:
  -- retroceder un correlativo es emitir ordenes con numero ya usado.
  valor = GREATEST(valor, VALUES(valor));


-- ----------------------------------------------------------------------------
-- 5. Cola de correo (T2.1.6)
--
-- Hoy, si el SMTP falla, submit.php escribe el error en el log y muestra una
-- pantalla de error: la orden queda generada pero no llega a nadie, y nadie se
-- entera. La UNIQUE KEY va sobre la clave de negocio real -- la orden mas el
-- destinatario -- y no sobre el id autoincremental (I-9): asi un reintento no
-- puede duplicar el envio.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_queue (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_industec    VARCHAR(60)     NOT NULL,
  destinatario   VARCHAR(160)    NOT NULL,
  asunto         VARCHAR(255)    NOT NULL,
  cuerpo         TEXT            NOT NULL,
  ruta_adjunto   VARCHAR(500)    NULL,
  estado         ENUM('PENDIENTE','ENVIADO','FALLIDO','REBOTADO') NOT NULL DEFAULT 'PENDIENTE',
  intentos       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ultimo_error   VARCHAR(500)    NULL,
  creado_en      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  enviado_en     TIMESTAMP       NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email_ot_destinatario (id_industec, destinatario),
  KEY idx_email_estado (estado, intentos)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Cola de envio con reintentos. Un correo que falla queda aqui, no se pierde';


-- ============================================================================
-- VERIFICACION despues de aplicar. Las cuatro deben cumplirse.
--
--   SHOW CREATE TABLE ots;
--     -> fuente incluye 'FORMULARIO_WEB'
--     -> ya no existe uq_ots_zona_correlativo_modulo
--     -> PRIMARY KEY sigue siendo id_industec
--
--   SELECT * FROM correlativos ORDER BY modulo, zona;
--     -> 7 filas, ninguna por debajo del contador vivo del servidor
--
--   SELECT COUNT(*) FROM ots;
--     -> 7416, el mismo numero que antes de aplicar. Esta migracion no borra
--        ni modifica ninguna fila de datos.
--
--   SHOW CREATE TABLE email_queue;
--     -> UNIQUE KEY sobre (id_industec, destinatario)
-- ============================================================================
