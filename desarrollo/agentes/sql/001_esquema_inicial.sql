-- T1.5: Esquema inicial - Sistema de OTs INDUSTEC
-- Compatible con MariaDB/MySQL (portable segun plan seccion 6.4)
SET NAMES utf8mb4;

-- =========================================================
-- MAESTROS
-- =========================================================

CREATE TABLE IF NOT EXISTS locales (
    local_codigo    VARCHAR(10)  NOT NULL PRIMARY KEY COMMENT 'Codigo canonico [A-Z]{1,2}[0-9]{3}EC',
    zona            ENUM('UIO','LARB','CNLJ','OTRA') NOT NULL,
    cadena          VARCHAR(40)  NOT NULL COMMENT 'KFC, GUS, CASA RES, etc, ver vocabulario seccion 6.3',
    nombre          VARCHAR(120) NULL COMMENT 'Nombre/ubicacion descriptiva del local',
    correo_local    VARCHAR(160) NULL,
    correo_jefe_op  VARCHAR(160) NULL,
    fee_mensual     DECIMAL(10,2) NULL,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_locales_zona (zona),
    INDEX idx_locales_cadena (cadena)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Maestro de 95 locales, fuente LOCALES_INDUSTEC_GENERAL.xlsx';

CREATE TABLE IF NOT EXISTS locales_alias (
    alias_id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    alias_texto     VARCHAR(60)  NOT NULL COMMENT 'Variante tal como aparece en archivos reales: k073, CN42, M44...',
    local_codigo    VARCHAR(10)  NOT NULL,
    regla_aplicada  VARCHAR(60)  NOT NULL COMMENT 'p.ej. MAYUSCULAS, CEROS_FALTANTES, CEROS_SOBRANTES, SUFIJO_EC',
    nivel_confianza TINYINT      NOT NULL COMMENT '1=automatico, 2=verificacion cruzada, 3=cuarentena/decidido',
    creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_alias (alias_texto),
    CONSTRAINT fk_alias_local FOREIGN KEY (local_codigo) REFERENCES locales(local_codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='121+ variantes de codigo de local mapeadas a canonico, ver T1.6';

CREATE TABLE IF NOT EXISTS tecnicos (
    tecnico_id      INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nombres         VARCHAR(80)  NOT NULL,
    apellidos       VARCHAR(80)  NOT NULL,
    cedula          VARCHAR(15)  NULL,
    fecha_ingreso   DATE         NULL,
    tipo_tecnico    VARCHAR(40)  NULL COMMENT 'TECNICO B, MANT. PREVENTIVO, SECRETARIA, etc.',
    afiliado        TINYINT(1)   NULL,
    zona_asignada   ENUM('UIO','LARB','CNLJ','OTRA') NULL,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tecnico_cedula (cedula)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='19 empleados, fuente BASE DE DATOS DE EMPLEADOS INDUSTEC.xlsx';

CREATE TABLE IF NOT EXISTS avisos_sap (
    aviso           BIGINT UNSIGNED NOT NULL PRIMARY KEY COMMENT 'Numero de aviso SAP de 8 digitos, llave con idorden del formulario',
    fecha_notificacion DATE      NULL,
    descripcion     VARCHAR(200) NULL,
    clase_aviso     VARCHAR(10)  NULL COMMENT 'L1, etc.',
    centro_coste    VARCHAR(10)  NULL COMMENT 'Codigo de local tal como llega de SAP, sin normalizar',
    ubicacion_tecnica VARCHAR(120) NULL,
    orden_sap       BIGINT UNSIGNED NULL COMMENT 'Numero de orden generado en SAP',
    fecha_creacion_orden DATE   NULL,
    fecha_cierre_tecnico DATE   NULL,
    estatus_aviso   VARCHAR(60)  NULL COMMENT 'MECE, MECE ORAS, etc. (jerga interna SAP, sin valor de estado directo)',
    estatus_aviso_2 VARCHAR(60)  NULL COMMENT 'Estatus 2 del Aviso (APRO...). Mismo vocabulario que estatus_orden_2',
    estatus_orden   VARCHAR(80)  NULL COMMENT 'Estatus de la Orden SAP (CTEC DMNV KKMP NLIQ PREC...)',
    estatus_orden_2 VARCHAR(60)  NULL COMMENT 'Estatus 2 de la Orden: es la columna ESTATUS SAP del plan de seguimiento (REDE, MEDE, APRO, MSOL, IMPO...). Sin esta columna esa celda no se puede llenar sin inventar.',
    equipo_sap      VARCHAR(20)  NULL COMMENT 'Numero de equipo/activo en SAP',
    equipo_denominacion VARCHAR(120) NULL COMMENT 'Denominacion objeto: es la columna EQUIPO del plan de seguimiento. La base solo tenia lo que el tecnico escribio a mano en la orden.',
    estatus_general ENUM('CERRADO','TRATAMIENTO','ABIERTO') NULL COMMENT 'Columna ESTATUS A del export SAP: el estado real y directo del caso segun el cliente. Es el criterio principal de cierre -- mas confiable que inferirlo de si existe una segunda OT de INDUSTEC.',
    modificado_por  VARCHAR(40)  NULL,
    creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_avisos_centro (centro_coste),
    INDEX idx_avisos_fecha (fecha_notificacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='6451 avisos SAP, fuente KPIS INDUSTEC.xlsx';

-- =========================================================
-- OPERACION: ORDENES DE TRABAJO
-- =========================================================

CREATE TABLE IF NOT EXISTS ots (
    id_industec     VARCHAR(60)  NOT NULL PRIMARY KEY COMMENT 'Clave natural, p.ej. OT-2422-K146EC-10352088-CNLJ',
    modulo          ENUM('CORRECTIVO','PREVENTIVO','OTROS') NOT NULL,
    zona            ENUM('UIO','LARB','CNLJ','OTRA') NOT NULL,
    correlativo     INT UNSIGNED NOT NULL,
    dia_intervencion TINYINT UNSIGNED NULL COMMENT 'Solo preventivo: 1-5',
    aviso           BIGINT UNSIGNED NULL COMMENT 'FK logica a avisos_sap.aviso (idorden del formulario)',
    fase            ENUM('EVALUACION','CIERRE') NOT NULL DEFAULT 'EVALUACION',
    local_codigo    VARCHAR(10)  NULL,
    cliente         VARCHAR(80)  NULL,
    tecnico_nombre  VARCHAR(120) NULL COMMENT 'Texto libre tal como viene del formulario',
    admin_nombre    VARCHAR(120) NULL,
    correo_local    VARCHAR(160) NULL,
    correo_jefe_op  VARCHAR(160) NULL,
    fecha_atencion  DATE         NULL,
    hora_inicio     TIME         NULL,
    hora_fin        TIME         NULL,
    tiempo_atencion_min INT      NULL COMMENT 'Calculado; NULL si hora_fin <= hora_inicio, bug conocido del PDF',
    estado_ot       ENUM('ABIERTA','CERRADA') NULL,
    actividades     TEXT         NULL,
    repuestos       TEXT         NULL,
    observaciones   TEXT         NULL,
    satisfaccion    TINYINT UNSIGNED NULL COMMENT '1-10',
    atiempo         ENUM('Si','No') NULL,
    firma_presente  TINYINT(1)   NOT NULL DEFAULT 0,
    fotos_cantidad  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ruta_pdf        VARCHAR(500) NULL COMMENT 'Ruta al PDF en el repositorio canonico D:\RESPALDOS',
    hash_pdf        CHAR(64)     NULL COMMENT 'SHA-256 del PDF de origen',
    fuente          ENUM('DRIVE_HISTORICO','SISTEMA_DESCARGADO','IMAP_EN_VIVO') NOT NULL,
    en_cuarentena   TINYINT(1)   NOT NULL DEFAULT 0,
    motivo_cuarentena VARCHAR(200) NULL,
    creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ots_zona (zona),
    INDEX idx_ots_aviso (aviso),
    INDEX idx_ots_local (local_codigo),
    INDEX idx_ots_fecha (fecha_atencion),
    INDEX idx_ots_estado (estado_ot),
    UNIQUE KEY uq_ots_zona_correlativo_modulo (zona, modulo, correlativo, dia_intervencion),
    CONSTRAINT fk_ots_local FOREIGN KEY (local_codigo) REFERENCES locales(local_codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Aprox 7200 ordenes de trabajo, clave natural id_industec, upsert idempotente';

CREATE TABLE IF NOT EXISTS ot_equipos (
    ot_equipo_id    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_industec     VARCHAR(60)  NOT NULL,
    orden           TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-6, orden dentro de la OT preventiva',
    equipo          VARCHAR(120) NULL,
    marca           VARCHAR(80)  NULL,
    modelo          VARCHAR(80)  NULL,
    serie           VARCHAR(80)  NULL,
    codigo_activo_fijo VARCHAR(40) NULL,
    estado_equipo   ENUM('OPERATIVO','DESHABILITADO') NULL,
    descripcion     TEXT         NULL,
    observaciones   TEXT         NULL,
    creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ot_equipo (id_industec, orden),
    CONSTRAINT fk_equipo_ot FOREIGN KEY (id_industec) REFERENCES ots(id_industec) ON DELETE CASCADE,
    INDEX idx_equipo_tipo (equipo),
    INDEX idx_equipo_marca (marca)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='1 fila por equipo. Correctivo=1 equipo, Preventivo=1 a 7';

CREATE TABLE IF NOT EXISTS ot_fotos (
    foto_id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ot_equipo_id    INT UNSIGNED NOT NULL,
    orden           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    pagina_pdf      INT UNSIGNED NULL,
    creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_foto_equipo FOREIGN KEY (ot_equipo_id) REFERENCES ot_equipos(ot_equipo_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Referencia a fotos incrustadas en el PDF';

-- =========================================================
-- CALIDAD, APRENDIZAJE Y AUDITORIA
-- =========================================================

CREATE TABLE IF NOT EXISTS observaciones_calidad (
    observacion_id  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_industec     VARCHAR(60)  NULL,
    zona            ENUM('UIO','LARB','CNLJ','OTRA') NULL,
    tecnico_nombre  VARCHAR(120) NULL,
    regla           VARCHAR(60)  NOT NULL COMMENT 'p.ej. LOCAL_FUERA_MAESTRO, ZONA_CRUZADA, SIN_FOTOS',
    dimension_dama  ENUM('VALIDEZ','CONSISTENCIA','EXACTITUD','COMPLETITUD','UNICIDAD','OPORTUNIDAD') NOT NULL,
    severidad       ENUM('CRITICA','ALTA','MEDIA','BAJA') NOT NULL,
    evidencia       TEXT         NULL,
    estado          ENUM('ABIERTA','DESCARTADA','CORREGIDA','RESUELTA_AUTOMATICA') NOT NULL DEFAULT 'ABIERTA',
    veredicto_admin VARCHAR(200) NULL COMMENT 'Columna editable por la administracion en el Excel espejo',
    creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resuelto_en     DATETIME     NULL,
    UNIQUE KEY uq_obs_ot_regla (id_industec, regla),
    INDEX idx_obs_ot (id_industec),
    INDEX idx_obs_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Espejo de OBSERVACIONES_OTS.xlsx generado por el Agente 1. La clave unica (id_industec, regla) es obligatoria para que el auditor sea idempotente -- bug real encontrado: sin ella, cada corrida duplicaba todas las filas en vez de actualizarlas.';

CREATE TABLE IF NOT EXISTS plan_snapshots (
    snapshot_id     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    zona            ENUM('UIO','LARB','CNLJ','OTRA') NOT NULL,
    generado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ruta_archivo    VARCHAR(500) NOT NULL,
    filas_json      LONGTEXT     NULL COMMENT 'Snapshot del contenido para comparar contra la version corregida',
    INDEX idx_snap_zona_fecha (zona, generado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Snapshots del Agente 2 Consolidador para aprendizaje T1.11';

CREATE TABLE IF NOT EXISTS correcciones (
    correccion_id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    snapshot_id     INT UNSIGNED NULL,
    zona            ENUM('UIO','LARB','CNLJ','OTRA') NOT NULL,
    fila_ref        VARCHAR(60)  NULL COMMENT 'id_industec u otra referencia de fila',
    columna         VARCHAR(60)  NOT NULL,
    valor_agente    TEXT         NULL,
    valor_admin     TEXT         NULL,
    detectado_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    promovido_a_regla TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT fk_correccion_snapshot FOREIGN KEY (snapshot_id) REFERENCES plan_snapshots(snapshot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Diferencias entre lo generado y lo corregido por la administracion, ver invariante I-4';

CREATE TABLE IF NOT EXISTS consultas_gerente (
    consulta_id     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    chat_id         VARCHAR(40)  NOT NULL COMMENT 'Identificador de chat de Telegram',
    pregunta        TEXT         NOT NULL,
    respuesta       TEXT         NOT NULL,
    ots_citadas     VARCHAR(500) NULL COMMENT 'Lista de id_industec citados, ver invariante I-7',
    correccion_gerente TEXT      NULL,
    creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_consulta_chat (chat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Historial del Agente 4 Consultor, Fase 3';

CREATE TABLE IF NOT EXISTS bitacora (
    bitacora_id     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    agente          VARCHAR(40)  NOT NULL COMMENT 'gestor-ots, consolidador, reporteador, consultor, analista, desarrollador',
    tarea           VARCHAR(20)  NULL COMMENT 'p.ej. T1.6, T1.10',
    accion          VARCHAR(80)  NOT NULL,
    detalle         TEXT         NULL,
    nivel           ENUM('INFO','ADVERTENCIA','ERROR') NOT NULL DEFAULT 'INFO',
    creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bitacora_agente_fecha (agente, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Registro de toda corrida de todo agente, ver regla de ejecucion numero 6 del plan';

CREATE TABLE IF NOT EXISTS manifiesto_saneamiento (
    manifiesto_id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ruta_original   VARCHAR(500) NOT NULL,
    nombre_original VARCHAR(300) NOT NULL,
    ruta_canonica   VARCHAR(500) NULL,
    nombre_canonico VARCHAR(300) NULL,
    regla_aplicada  VARCHAR(60)  NOT NULL,
    nivel_confianza TINYINT      NOT NULL COMMENT '1, 2 o 3 para cuarentena',
    estado          ENUM('APLICADO','CUARENTENA','DESCARTADO_DUPLICADO') NOT NULL,
    decision_admin  VARCHAR(300) NULL COMMENT 'Solo para nivel 3',
    hash_sha256     CHAR(64)     NULL,
    creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_manifiesto_estado (estado),
    INDEX idx_manifiesto_hash (hash_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Espejo en BD de MANIFIESTO_SANEAMIENTO.xlsx, trazabilidad invariante I-5, reversible';

-- ---------------------------------------------------------------------------
-- Notas de evolucion del esquema
--
-- 2026-09-04  zona: se agrega 'OTRA' al final del ENUM (locales atendidos fuera
--             de las tres zonas del contrato con KFC; p. ej. el proyecto de
--             hornos de Pollo Gus). Va al final para no alterar el orden ni el
--             significado de los valores existentes.
-- 2026-09-04  observaciones_calidad.estado: se agrega 'RESUELTA_AUTOMATICA'.
--             La pone el auditor cuando un hallazgo deja de reproducirse; es
--             distinta de DESCARTADA/CORREGIDA, que son veredicto de la
--             administracion. Sin ese valor, el cierre automatico falla con
--             "Data truncated for column 'estado'".
--
-- Regla: todo ALTER que se aplique en caliente desde un script debe reflejarse
-- aqui en la misma sesion. Si no, la base deja de poder reconstruirse desde
-- cero y el fallo solo aparece meses despues, en el peor momento.
-- ---------------------------------------------------------------------------
