-- ============================================================================
-- 020_automatizacion.sql — El panel «Automatización»: los reportes programados (T2.27.7)
-- ============================================================================
--
-- POR QUE EXISTE
-- T2.27 dejó cinco generadores de reportes para Grupo KFC que se corren a mano
-- (`reportes_kfc.bat`). Andrés pidió el 2026-09-23 que las tareas programadas se
-- construyan DENTRO del panel donde irá la configuración de correos, y que NO se
-- activen: se activan después, con la aprobación de la administradora. Por eso
-- todo lo que se siembra aquí nace con `activa = 0`, y activar exige una
-- persona con el permiso y una nota que diga quién aprobó.
--
-- DONDE CORRE CADA COSA
-- La configuración vive aquí, en la base del sitio. Los generadores corren en la
-- estación (necesitan la base local, el correo y Excel): `t2_27_programador.py`
-- lee estas tablas, corre lo que esté activo y deja cada corrida registrada con
-- `automatizacion_cli.php`. Una tarea inactiva no corre aunque el programador sí.
--
-- POR QUE 020 Y NO 013
-- T2.28 reservó de la 013 a la 019 (`013_correos.sql` es su módulo de correos).
-- Los correos de las ÓRDENES son de T2.28.2 y van a vivir en este mismo panel,
-- en su propia pestaña; los destinatarios de cada REPORTE programado son de esta
-- migración, porque cada reporte va a gente distinta (el del martes a Lincango,
-- Vásquez y Valero; el del miércoles a Erika Zambrano) y el esquema de la 013 no
-- distingue entre reportes.
--
-- Aditiva: solo crea tablas y siembra filas que no existían. Se corre con
--   php aplicar_sql.php ../sql/020_automatizacion.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS automatizacion_tareas (
    tarea_id      INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    clave         VARCHAR(40)  NOT NULL COMMENT 'Clave estable que usa el programador de la estacion',
    nombre        VARCHAR(120) NOT NULL,
    descripcion   VARCHAR(600) NOT NULL,
    comando       VARCHAR(160) NOT NULL COMMENT 'Lo que corre la estacion, relativo a desarrollo/agentes. Solo se edita desde el repositorio',
    dias          VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'Dias ISO separados por coma: 1 lunes ... 7 domingo. Vacio si corre por dia del mes',
    dia_mes       TINYINT UNSIGNED NULL COMMENT 'Si tiene valor, corre ese dia de cada mes y dias se ignora',
    hora          TIME         NOT NULL,
    modo          ENUM('GENERAR','AVISAR','ENVIAR') NOT NULL DEFAULT 'GENERAR'
                  COMMENT 'GENERAR: solo deja los archivos. AVISAR: ademas los manda a los revisores internos. ENVIAR: los manda a los destinatarios del cliente',
    asunto        VARCHAR(200) NULL COMMENT 'Asunto del correo. Si es la respuesta a un hilo, el asunto del hilo',
    hilo          VARCHAR(200) NULL COMMENT 'Asunto del hilo al que se responde (se busca en el correo en solo lectura)',
    activa        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Nace en 0. Solo se activa con aprobacion registrada',
    aprobada_por  INT UNSIGNED NULL,
    aprobada_en   DATETIME     NULL,
    aprobacion_nota VARCHAR(300) NULL COMMENT 'Quien aprobo y como (p. ej. correo de la administradora del dd/mm)',
    creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_por INT UNSIGNED NULL,
    actualizado_en  DATETIME   NULL,
    UNIQUE KEY uq_tarea_clave (clave),
    CONSTRAINT fk_autom_aprobada FOREIGN KEY (aprobada_por) REFERENCES usuarios(usuario_id) ON DELETE SET NULL,
    CONSTRAINT fk_autom_actualizado FOREIGN KEY (actualizado_por) REFERENCES usuarios(usuario_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Reportes programados (T2.27.7). Ninguna se borra: se desactiva.';

CREATE TABLE IF NOT EXISTS automatizacion_destinatarios (
    destinatario_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tarea_id      INT UNSIGNED NOT NULL,
    tipo          ENUM('PARA','COPIA','REVISION') NOT NULL
                  COMMENT 'PARA y COPIA: el cliente o la gerencia, en modo ENVIAR. REVISION: quien revisa antes de enviar, en modo AVISAR',
    correo        VARCHAR(160) NOT NULL,
    nombre        VARCHAR(120) NULL,
    activo        TINYINT(1)   NOT NULL DEFAULT 1,
    origen        ENUM('CORREO_ENVIADO','MANUAL') NOT NULL DEFAULT 'MANUAL'
                  COMMENT 'CORREO_ENVIADO: sembrado de lo que la administradora mando de verdad en ese hilo',
    creado_por    INT UNSIGNED NULL,
    creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_autom_dest (tarea_id, correo),
    CONSTRAINT fk_autom_dest_tarea FOREIGN KEY (tarea_id) REFERENCES automatizacion_tareas(tarea_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='A quien va cada reporte. No se borra: se desactiva.';

CREATE TABLE IF NOT EXISTS automatizacion_cambios (
    cambio_id     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tarea_id      INT UNSIGNED NOT NULL,
    accion        VARCHAR(30)  NOT NULL COMMENT 'ACTIVAR, DESACTIVAR, HORARIO, MODO, DEST_ALTA, DEST_ACTIVAR, DEST_DESACTIVAR',
    antes         JSON NULL,
    despues       JSON NULL,
    nota          VARCHAR(300) NULL,
    por           INT UNSIGNED NOT NULL,
    en            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_autom_camb (tarea_id, en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Solo se agrega: el historial de cada cambio en el panel.';

CREATE TABLE IF NOT EXISTS automatizacion_corridas (
    corrida_id    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tarea_id      INT UNSIGNED NOT NULL,
    programada_para DATETIME   NOT NULL COMMENT 'El horario que toco (dia y hora del panel). Con la UNIQUE, una corrida por horario',
    inicio        DATETIME     NOT NULL,
    fin           DATETIME     NULL,
    estado        ENUM('OK','ERROR','OMITIDA') NOT NULL,
    enviado       ENUM('NO','REVISORES','CLIENTE') NOT NULL DEFAULT 'NO',
    archivos      JSON NULL COMMENT 'Nombres de los archivos generados en SALIDAS IA de la estacion',
    mensaje       VARCHAR(1000) NULL,
    equipo        VARCHAR(60)  NULL COMMENT 'Que computadora la corrio',
    manual        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 si se forzo a mano fuera de su horario',
    UNIQUE KEY uq_autom_corrida (tarea_id, programada_para),
    CONSTRAINT fk_autom_corrida_tarea FOREIGN KEY (tarea_id) REFERENCES automatizacion_tareas(tarea_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Lo que corrio, cuando y con que resultado. Solo se agrega.';

-- ----------------------------------------------------------------------------
-- El permiso: configurar y activar. La activacion ademas exige la nota de quien
-- aprobo (se valida en el codigo), porque Andres la condiciono a la
-- aprobacion de la administradora.
-- ----------------------------------------------------------------------------
INSERT INTO permisos (codigo, modulo, nombre, descripcion) VALUES
  ('automatizacion.configurar', 'automatizacion', 'Configurar y activar los reportes programados',
   'Cambiar horario, modo y destinatarios de los reportes automaticos, y activarlos con la aprobacion registrada')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), descripcion = VALUES(descripcion);

INSERT INTO rol_permisos (rol, permiso) VALUES
  ('SUPERADMIN','automatizacion.configurar'), ('ADMIN','automatizacion.configurar')
ON DUPLICATE KEY UPDATE rol = rol;

-- ----------------------------------------------------------------------------
-- Las cinco tareas, INACTIVAS. `ON DUPLICATE KEY UPDATE clave = clave` a
-- proposito: si alguien ya cambio el horario o el modo en el panel, volver a
-- correr la migracion no se lo pisa.
-- Horarios: los del ciclo real con KFC (ESTADO.md 1r). El martes sale 09h10-09h35;
-- la respuesta vence el miercoles 13h00; la reunion es el jueves.
-- ----------------------------------------------------------------------------
INSERT INTO automatizacion_tareas (clave, nombre, descripcion, comando, dias, dia_mes, hora, modo, asunto, hilo) VALUES
  ('KFC_STATUS_MARTES', 'Pendientes de la semana (martes)',
   'STATUS_PENDIENTES_SEMANA N MES: ordenes abiertas por zona y equipos deshabilitados, con su archivo de revision. Lo mira KFC para actuar sobre los equipos parados',
   'scripts/t2_27_status_semanal.py', '2', NULL, '07:00:00', 'AVISAR',
   'RE: ORDENES SEMANALES PENDIENTES _ INDUSTEC', 'ORDENES SEMANALES PENDIENTES _ INDUSTEC'),
  ('KFC_RESPUESTA_MIERCOLES', 'Respuesta al reporte de SAP (miercoles)',
   'El Excel de KFC de la semana con las hojas RESUMEN, RESUMEN IND y ND INDUSTEC. KFC lo pide hasta el miercoles a las 13h00',
   'scripts/t2_27_respuesta_kfc.py', '3', NULL, '07:00:00', 'AVISAR',
   'RE: REPORTE 2026 SEMANA - MANTENIMIENTO CORRECTIVO', 'REPORTE 2026 SEMANA'),
  ('GERENCIA_TABLERO', 'Tablero de gerencia (miercoles)',
   'El indicador con que KFC mide a INDUSTEC y que avisos cerrar en SAP antes de la reunion del jueves',
   'scripts/t2_27_tablero_gerencia.py', '3', NULL, '07:30:00', 'AVISAR',
   'Tablero de gerencia - semana KFC', NULL),
  ('KFC_KITS_PREVENTIVO', 'Propuesta de pedido de kits de preventivo (lunes)',
   'Los ingresos de preventivo vencidos y de las proximas dos semanas, con la tabla para el correo a la bodega de KFC. La fecha de ingreso la pone quien programa la visita',
   'scripts/t2_27_kits_preventivo.py', '1', NULL, '07:00:00', 'AVISAR',
   'RE: SOLICITUD DE KITS MTO PREVENTIVO', 'SOLICITUD DE KITS MTO PREVENTIVO'),
  ('KFC_PRESENTACION_GESTION', 'Presentacion de gestion por zona (dia 1 del mes)',
   'RESUMEN GESTION INDUSTEC de los dos meses anteriores, en PowerPoint. Las causas tecnicas quedan en rojo para el jefe tecnico',
   'scripts/t2_27_presentacion_gestion.py', '', 1, '07:00:00', 'GENERAR',
   NULL, NULL)
ON DUPLICATE KEY UPDATE clave = clave;

-- ----------------------------------------------------------------------------
-- Destinatarios: los que la administradora uso de verdad en cada hilo (Sent de
-- servicioalcliente@, ESTADO.md 1r). La revision interna va a servicioalcliente@
-- en los tres modos AVISAR. El del miercoles recorta a KFC desde la semana 35
-- (antes iba tambien a los otros proveedores: se dejo de hacer a proposito).
-- ----------------------------------------------------------------------------
INSERT INTO automatizacion_destinatarios (tarea_id, tipo, correo, nombre, origen)
SELECT t.tarea_id, d.tipo, d.correo, d.nombre, 'CORREO_ENVIADO'
  FROM automatizacion_tareas t
  JOIN (
    SELECT 'KFC_STATUS_MARTES' clave, 'REVISION' tipo, 'servicioalcliente@industec.me' correo, 'Servicio al cliente INDUSTEC' nombre
    UNION ALL SELECT 'KFC_STATUS_MARTES', 'PARA',  'miguel.lincango@kfc.com.ec', 'Miguel Lincango (KFC)'
    UNION ALL SELECT 'KFC_STATUS_MARTES', 'PARA',  'miguel.vasquez@kfc.com.ec',  'Miguel Vasquez (KFC)'
    UNION ALL SELECT 'KFC_STATUS_MARTES', 'PARA',  'ronald.valero@kfc.com.ec',   'Ronald Valero (KFC)'
    UNION ALL SELECT 'KFC_STATUS_MARTES', 'COPIA', 'erika.zambrano@kfc.com.ec',  'Erika Zambrano (KFC)'
    UNION ALL SELECT 'KFC_STATUS_MARTES', 'COPIA', 'edgar.armero@kfc.com.ec',    'Edgar Armero (bodega KFC)'
    UNION ALL SELECT 'KFC_STATUS_MARTES', 'COPIA', 'gerenciageneral@industec.me', 'Gerencia general INDUSTEC'
    UNION ALL SELECT 'KFC_STATUS_MARTES', 'COPIA', 'jefetecniconacional@industec.me', 'Jefe tecnico zona LARB'
    UNION ALL SELECT 'KFC_STATUS_MARTES', 'COPIA', 'jefezonacuenca-loja@industec.me', 'Jefe de zona Cuenca-Loja'
    UNION ALL SELECT 'KFC_RESPUESTA_MIERCOLES', 'REVISION', 'servicioalcliente@industec.me', 'Servicio al cliente INDUSTEC'
    UNION ALL SELECT 'KFC_RESPUESTA_MIERCOLES', 'PARA',  'erika.zambrano@kfc.com.ec',  'Erika Zambrano (KFC)'
    UNION ALL SELECT 'KFC_RESPUESTA_MIERCOLES', 'PARA',  'miguel.vasquez@kfc.com.ec',  'Miguel Vasquez (KFC)'
    UNION ALL SELECT 'KFC_RESPUESTA_MIERCOLES', 'PARA',  'ronald.valero@kfc.com.ec',   'Ronald Valero (KFC)'
    UNION ALL SELECT 'KFC_RESPUESTA_MIERCOLES', 'COPIA', 'diana.tejena@kfc.com.ec',    'Diana Tejena (KFC)'
    UNION ALL SELECT 'KFC_RESPUESTA_MIERCOLES', 'COPIA', 'edgar.armero@kfc.com.ec',    'Edgar Armero (bodega KFC)'
    UNION ALL SELECT 'KFC_RESPUESTA_MIERCOLES', 'COPIA', 'miguel.lincango@kfc.com.ec', 'Miguel Lincango (KFC)'
    UNION ALL SELECT 'KFC_RESPUESTA_MIERCOLES', 'COPIA', 'gerenciageneral@industec.me', 'Gerencia general INDUSTEC'
    UNION ALL SELECT 'GERENCIA_TABLERO', 'REVISION', 'gerenciageneral@industec.me', 'Gerencia general INDUSTEC'
    UNION ALL SELECT 'GERENCIA_TABLERO', 'REVISION', 'servicioalcliente@industec.me', 'Servicio al cliente INDUSTEC'
    UNION ALL SELECT 'KFC_KITS_PREVENTIVO', 'REVISION', 'servicioalcliente@industec.me', 'Servicio al cliente INDUSTEC'
    UNION ALL SELECT 'KFC_KITS_PREVENTIVO', 'PARA',  'edgar.armero@kfc.com.ec',   'Edgar Armero (bodega KFC)'
    UNION ALL SELECT 'KFC_KITS_PREVENTIVO', 'PARA',  'miguel.vasquez@kfc.com.ec', 'Miguel Vasquez (KFC)'
    UNION ALL SELECT 'KFC_KITS_PREVENTIVO', 'COPIA', 'jefezona-uio@industec.me',  'Jefe de zona UIO'
  ) d ON d.clave = t.clave
ON DUPLICATE KEY UPDATE destinatario_id = destinatario_id;

-- ============================================================================
-- COMPROBACION (se pega la salida literal al cerrar la tarea):
--   SELECT clave, activa FROM automatizacion_tareas;                  -> 5 filas, activa = 0 en las 5
--   SELECT COUNT(*) FROM automatizacion_destinatarios;                -> 23
--   SELECT COUNT(*) FROM rol_permisos WHERE permiso = 'automatizacion.configurar';  -> 2
--   SHOW CREATE TABLE automatizacion_corridas;                        -> uq_autom_corrida (tarea_id, programada_para)
-- ============================================================================
