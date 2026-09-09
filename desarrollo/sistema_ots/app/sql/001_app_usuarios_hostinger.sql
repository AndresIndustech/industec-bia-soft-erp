-- ============================================================================
-- PARA HOSTINGER -- pegar en phpMyAdmin, con la base u671729428_ots YA ELEGIDA
-- en el panel de la izquierda.
--
-- Generado desde 001_app_usuarios.sql. La diferencia es que aqui NO va
-- CREATE DATABASE ni USE: el usuario u671729428_ots_app no tiene permiso para
-- crear bases, y phpMyAdmin ya trabaja dentro de la que seleccionaste.
--
-- NO crea usuarios. Eso lo hace instalar.php, que genera claves al azar y las
-- muestra una sola vez. Una clave nunca va en un archivo.
-- ============================================================================

-- ============================================================================
-- 001 - Usuarios, roles, permisos y sesion unica.
--
-- BASE: industec_app  (operativa)  -- NO es industec_ots, que es el archivo
-- historico de la Fase 1 y vive en la estacion. Esta se replica en la MySQL de
-- Hostinger, que es donde entran la administradora, los jefes y los tecnicos.
--
-- Es la etapa 1 del plan: sin esto, cualquier endpoint responde a quien sepa la
-- URL. Hoy `catalogos.php` entrega 100 locales, 19 tecnicos y 918 casos con 206
-- usuarios identificables de Grupo KFC, sin pedir nada.
--
-- ROLES Y ALCANCE, decididos por Andres Basantes el 2026-09-08:
--
--   SUPERADMIN   Cesar Basantes (INDUSTEC) y Andres Basantes (INDUSTECH).
--                Son DOS a proposito: la cotizacion dice que lo entregado queda
--                en poder de INDUSTEC, asi que el cliente tiene que poder
--                administrar su propio sistema sin depender del proveedor.
--   ADMIN        La administradora. Asigna tecnicos IGUAL que un jefe de zona;
--                la diferencia es el alcance: ella ve las tres zonas.
--   JEFE_ZONA    Solo su zona. NO ve las otras, ni siquiera de lectura.
--   TECNICO      Solo lo suyo.
--
-- EL ALCANCE NO ES UN PERMISO: es una propiedad del usuario (`zona`) que filtra
-- cada consulta EN EL SERVIDOR. Un permiso dice "puede asignar"; el alcance dice
-- "sobre que filas". Mezclarlos obliga a inventar un permiso por zona.
-- ============================================================================



-- ----------------------------------------------------------------------------
-- Los permisos son DATOS, no codigo.
--
-- Si el rol se cablea en PHP, cada cambio de atribucion es un despliegue. Asi,
-- que un jefe de zona pueda cerrar casos en SAP se marca en una pantalla.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS permisos (
    codigo      VARCHAR(40)  NOT NULL PRIMARY KEY,
    modulo      VARCHAR(30)  NOT NULL,
    nombre      VARCHAR(120) NOT NULL,
    descripcion VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Catalogo de capacidades. Un rol es un paquete de estas.';

INSERT INTO permisos (codigo, modulo, nombre, descripcion) VALUES
  ('casos.ver',          'casos',      'Ver casos',                'Ver el buzon de casos, dentro de su alcance'),
  ('casos.derivar',      'casos',      'Derivar a zona',           'Mandar un caso a la zona que corresponde'),
  ('casos.asignar',      'casos',      'Asignar tecnico',          'Asignar un caso a un tecnico'),
  ('casos.veredicto',    'casos',      'Dar veredicto',            'Resolver si un caso nos compete o no'),
  ('casos.revision',     'casos',      'Marcar en revision',       'Mandar un caso a los pendientes de la administracion'),
  ('cronograma.ver',     'cronograma', 'Ver cronograma',           'Ver el calendario de preventivos'),
  ('cronograma.cargar',  'cronograma', 'Cargar fechas',            'Agendar un ingreso preventivo'),
  ('cronograma.reagendar','cronograma','Reagendar',                'Mover una fecha, con motivo obligatorio'),
  ('cronograma.kit',     'cronograma', 'Confirmar kit',            'Declarar que el local ya tiene el kit'),
  ('ots.crear',          'ots',        'Emitir orden',             'Llenar y enviar una orden de trabajo'),
  ('ots.ver',            'ots',        'Ver ordenes',              'Consultar ordenes, dentro de su alcance'),
  ('ots.reenviar',       'ots',        'Reenviar orden',           'Volver a enviar el PDF por correo'),
  ('reportes.ver',       'reportes',   'Ver reportes',             'Tableros e indicadores'),
  ('reportes.generar',   'reportes',   'Generar reportes',         'Producir el reporte mensual y el de KFC'),
  ('maestros.editar',    'maestros',   'Editar maestros',          'Locales, tecnicos y equipos'),
  ('usuarios.gestionar', 'usuarios',   'Gestionar usuarios',       'Crear usuarios, asignar roles y permisos'),
  ('bitacora.ver',       'bitacora',   'Ver bitacora',             'Quien hizo que, y quien consulto que')
ON DUPLICATE KEY UPDATE
  modulo = VALUES(modulo), nombre = VALUES(nombre), descripcion = VALUES(descripcion);


-- ----------------------------------------------------------------------------
-- Que puede hacer cada rol.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rol_permisos (
    rol     ENUM('SUPERADMIN','ADMIN','JEFE_ZONA','TECNICO') NOT NULL,
    permiso VARCHAR(40) NOT NULL,
    PRIMARY KEY (rol, permiso),
    CONSTRAINT fk_rolperm_permiso FOREIGN KEY (permiso) REFERENCES permisos(codigo)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='I-9: la clave de negocio es (rol, permiso), no un id autoincremental';

-- SUPERADMIN: todo.
INSERT INTO rol_permisos (rol, permiso) SELECT 'SUPERADMIN', codigo FROM permisos
ON DUPLICATE KEY UPDATE rol = rol;

-- ADMIN: todo lo operativo. NO gestiona usuarios -- eso es del superadministrador.
INSERT INTO rol_permisos (rol, permiso) SELECT 'ADMIN', codigo FROM permisos
  WHERE codigo <> 'usuarios.gestionar'
ON DUPLICATE KEY UPDATE rol = rol;

-- JEFE_ZONA: lo mismo que la administradora en su zona, salvo generar reportes,
-- editar maestros y gestionar usuarios.
INSERT INTO rol_permisos (rol, permiso) VALUES
  ('JEFE_ZONA','casos.ver'), ('JEFE_ZONA','casos.asignar'),
  ('JEFE_ZONA','casos.revision'), ('JEFE_ZONA','cronograma.ver'),
  ('JEFE_ZONA','cronograma.cargar'), ('JEFE_ZONA','cronograma.reagendar'),
  ('JEFE_ZONA','cronograma.kit'), ('JEFE_ZONA','ots.ver'),
  ('JEFE_ZONA','ots.reenviar'), ('JEFE_ZONA','reportes.ver')
ON DUPLICATE KEY UPDATE rol = rol;

-- TECNICO: emitir sus ordenes y ver lo suyo.
INSERT INTO rol_permisos (rol, permiso) VALUES
  ('TECNICO','ots.crear'), ('TECNICO','ots.ver'),
  ('TECNICO','casos.ver'), ('TECNICO','cronograma.ver')
ON DUPLICATE KEY UPDATE rol = rol;


-- ----------------------------------------------------------------------------
-- Usuarios.
--
-- SESION UNICA: el token vive AQUI, en la fila del usuario, no en una tabla
-- aparte. Asi "cerrar la otra sesion" es sobrescribir una columna, y la sesion
-- vieja queda invalida sola porque su token deja de coincidir. Una tabla de
-- sesiones activas obligaria a borrar filas y a resolver carreras.
--
-- NUNCA SE BORRA UN USUARIO: se desactiva. INDUSTEC tiene alta rotacion -- 164
-- personas firmaron ordenes y 19 estan vigentes -- y borrar la fila rompe la
-- trazabilidad de todo lo que esa persona hizo.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    usuario_id  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario     VARCHAR(40)  NOT NULL COMMENT 'Con lo que inicia sesion',
    nombre      VARCHAR(120) NOT NULL,
    correo      VARCHAR(160) NULL,
    clave_hash  VARCHAR(255) NOT NULL COMMENT 'password_hash(). Nunca reversible',
    rol         ENUM('SUPERADMIN','ADMIN','JEFE_ZONA','TECNICO') NOT NULL,
    zona        ENUM('UIO','LARB','CNLJ','OTRA') NULL
                COMMENT 'Alcance. NULL en SUPERADMIN y ADMIN: ven las tres zonas',
    tecnico_id  INT UNSIGNED NULL COMMENT 'FK logica a industec_ots.tecnicos',
    activo      TINYINT(1)   NOT NULL DEFAULT 1,
    debe_cambiar_clave TINYINT(1) NOT NULL DEFAULT 1
                COMMENT 'La clave inicial la puso otra persona: se cambia al primer ingreso',
    fecha_alta  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_baja  DATE         NULL COMMENT 'NULL = no consta. No significa que siga activo',
    creado_por  INT UNSIGNED NULL,

    -- Candado de sesion unica
    sesion_token   CHAR(64)     NULL,
    sesion_desde   DATETIME     NULL,
    sesion_ultima  DATETIME     NULL,
    sesion_equipo  VARCHAR(200) NULL COMMENT 'User-Agent recortado, para decir DONDE esta abierta',
    sesion_ip      VARCHAR(45)  NULL,

    -- Freno a la fuerza bruta
    intentos_fallidos TINYINT UNSIGNED NOT NULL DEFAULT 0,
    bloqueado_hasta   DATETIME NULL,
    ultimo_ingreso    DATETIME NULL,

    UNIQUE KEY uq_usuario (usuario),
    KEY idx_usuarios_rol_zona (rol, zona, activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='I-9: la clave de negocio es `usuario`, no el id autoincremental';


-- ----------------------------------------------------------------------------
-- Excepciones por persona, encima de lo que da el rol.
-- `concedido` puede QUITAR un permiso que el rol si da, no solo agregarlo.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuario_permisos (
    usuario_id INT UNSIGNED NOT NULL,
    permiso    VARCHAR(40)  NOT NULL,
    concedido  TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 concede, 0 revoca',
    motivo     VARCHAR(255) NULL,
    otorgado_por INT UNSIGNED NULL,
    otorgado_en  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (usuario_id, permiso),
    CONSTRAINT fk_usrperm_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(usuario_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_usrperm_permiso FOREIGN KEY (permiso) REFERENCES permisos(codigo)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ----------------------------------------------------------------------------
-- Historial de ingresos. Es registro, no control: el candado esta en `usuarios`.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sesiones_log (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NULL,
    usuario    VARCHAR(40)  NOT NULL COMMENT 'Texto: para registrar intentos con usuario inexistente',
    evento     ENUM('INGRESO','SALIDA','RECHAZADO','DESPLAZADO','EXPIRADO') NOT NULL,
    motivo     VARCHAR(120) NULL,
    ip         VARCHAR(45)  NULL,
    equipo     VARCHAR(200) NULL,
    cuando     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sesiones_usuario (usuario_id, cuando),
    KEY idx_sesiones_evento (evento, cuando)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='DESPLAZADO = le cerraron la sesion desde otro equipo';


-- ----------------------------------------------------------------------------
-- Bitacora: quien hizo que, y quien CONSULTO que.
--
-- La traza de consulta no es opcional: hoy nadie registra quien leyo o descargo
-- un PDF con la firma de un empleado de KFC, y sin eso no se puede responder
-- ante un reclamo ni detectar un uso indebido.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bitacora (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NULL,
    usuario    VARCHAR(40)  NULL,
    accion     VARCHAR(60)  NOT NULL COMMENT 'ASIGNAR, REAGENDAR, VEREDICTO, CONSULTAR, DESCARGAR...',
    entidad    VARCHAR(40)  NULL COMMENT 'caso, ingreso, ot, usuario',
    referencia VARCHAR(80)  NULL COMMENT 'el aviso, el id del ingreso, el id_industec',
    detalle    TEXT         NULL,
    ip         VARCHAR(45)  NULL,
    cuando     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_bitacora_usuario (usuario_id, cuando),
    KEY idx_bitacora_accion (accion, cuando),
    KEY idx_bitacora_ref (entidad, referencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- VERIFICACION despues de aplicar:
--
--   SELECT rol, COUNT(*) FROM rol_permisos GROUP BY rol;
--     -> SUPERADMIN 17 | ADMIN 16 | JEFE_ZONA 10 | TECNICO 4
--
--   SHOW CREATE TABLE usuarios\G
--     -> UNIQUE KEY uq_usuario (usuario)
--
-- Los usuarios NO se crean aqui: los crea scripts/crear_usuario.php, que genera
-- una clave aleatoria y la muestra UNA sola vez. Nunca una clave en un archivo
-- versionado.
-- ============================================================================
