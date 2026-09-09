-- ============================================================================
-- 005 - La bitácora deja de ser un diario y pasa a ser un registro de eventos.
--
-- BASE: la operativa de Hostinger.
--
-- POR QUE
-- Hasta ahora la bitácora servía para *auditar*: alguien pregunta qué pasó con
-- un caso y se lee la fila. Eso está bien para responder una pregunta puntual y
-- es inútil para la que pidió Andrés el 2026-09-09: **minar el registro para
-- encontrar comportamientos anómalos**.
--
-- La diferencia está en el `detalle`. Hoy dice `"a amorales"` o
-- `"UIO -> LARB"`: se lee bien y no se consulta. Para detectar un patrón hay
-- que poder preguntar «cuántos casos cambiaron de técnico más de tres veces» o
-- «qué veredictos se dieron sin que nadie los revisara antes», y con texto
-- libre eso obliga a adivinar con LIKE.
--
-- QUE SE AGREGA, Y QUE PREGUNTA PERMITE CADA COSA
--
--   estado_antes / estado_despues
--       Convierte cada fila en una TRANSICION. Sin el estado anterior no se
--       puede detectar un salto imposible -- un caso que pasa a RESUELTO sin
--       haber estado nunca ATENDIDO es alguien cerrando trabajo que no consta.
--
--   datos (JSON)
--       Los campos de la accion, consultables. `detalle` se queda para que una
--       persona lo lea; `datos` es para las consultas.
--
--   equipo
--       El mismo usuario operando desde dos equipos muy distintos en minutos es
--       una senal de credencial compartida. `sesiones_log` ya lo guarda al
--       entrar, pero no por accion.
--
--   exito
--       Distingue la accion que se hizo de la que se INTENTO y se rechazo. Los
--       rechazos son la senal mas util de todas: nadie tropieza dos veces con
--       el mismo permiso por casualidad.
--
-- NADA SE BORRA DE ESTA TABLA. Un registro de eventos que se puede podar no
-- sirve para investigar justo el periodo que alguien querria borrar.
-- ============================================================================

ALTER TABLE bitacora
  ADD COLUMN estado_antes   VARCHAR(20) NULL
    COMMENT 'Estado del caso ANTES de la accion. NULL si la accion no cambia estado'
    AFTER referencia,
  ADD COLUMN estado_despues VARCHAR(20) NULL
    COMMENT 'Estado despues. Con el anterior, cada fila es una transicion'
    AFTER estado_antes,
  ADD COLUMN exito TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '0 = se intento y se rechazo. Los rechazos son la senal mas util'
    AFTER estado_despues,
  ADD COLUMN datos TEXT NULL
    COMMENT 'Los campos de la accion en JSON, para consultar sin adivinar con LIKE'
    AFTER detalle,
  ADD COLUMN equipo VARCHAR(200) NULL
    COMMENT 'User-Agent recortado. Un mismo usuario en dos equipos distintos en minutos es una senal'
    AFTER ip;

-- Los índices salen de las preguntas que se van a hacer, no de las columnas que
-- existen: «qué hizo esta persona en esta franja», «qué pasó con este caso» y
-- «dónde se rechazaron intentos».
ALTER TABLE bitacora
  ADD KEY idx_bitacora_transicion (entidad, referencia, cuando),
  ADD KEY idx_bitacora_rechazos (exito, accion, cuando);

-- ============================================================================
-- CONSULTAS QUE ESTO HABILITA -- son la prueba de que la migracion sirve.
--
-- 1. Saltos de estado imposibles: cerrado sin constar que se atendio.
--      SELECT referencia, cuando, usuario, estado_antes, estado_despues
--        FROM bitacora
--       WHERE entidad='caso' AND estado_despues='RESUELTO'
--         AND estado_antes NOT IN ('ATENDIDO','EN_REVISION');
--
-- 2. Casos que rebotan de tecnico: reasignados mas de tres veces.
--      SELECT referencia, COUNT(*) n FROM bitacora
--       WHERE accion='ASIGNAR' GROUP BY referencia HAVING n > 3 ORDER BY n DESC;
--
-- 3. Intentos rechazados por persona: nadie tropieza dos veces por casualidad.
--      SELECT usuario, accion, COUNT(*) n FROM bitacora
--       WHERE exito=0 GROUP BY usuario, accion HAVING n >= 3;
--
-- 4. Actividad fuera de horario, que puede ser urgencia real o cuenta prestada.
--      SELECT usuario, COUNT(*) n FROM bitacora
--       WHERE HOUR(cuando) NOT BETWEEN 6 AND 21 GROUP BY usuario ORDER BY n DESC;
--
-- 5. Rafagas: mas de 20 acciones en un minuto es alguien automatizando.
--      SELECT usuario, DATE_FORMAT(cuando,'%Y-%m-%d %H:%i') m, COUNT(*) n
--        FROM bitacora GROUP BY usuario, m HAVING n > 20;
--
-- 6. Veredictos sin revision previa, en casos que llevaban alerta de alcance.
--      SELECT b.referencia, b.usuario, b.cuando FROM bitacora b
--       WHERE b.accion='VEREDICTO'
--         AND NOT EXISTS (SELECT 1 FROM bitacora r
--                          WHERE r.entidad='caso' AND r.referencia=b.referencia
--                            AND r.accion='EN_REVISION' AND r.cuando < b.cuando);
--
-- VERIFICACION:
--   SHOW COLUMNS FROM bitacora;  -> estado_antes, estado_despues, exito, datos, equipo
--   SHOW KEYS FROM bitacora WHERE Key_name LIKE 'idx_bitacora_%';  -> 4 indices
-- ============================================================================
