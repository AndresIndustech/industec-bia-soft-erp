-- ============================================================================
-- 003 - La gestión de un caso: a quién se asignó, quién dio el veredicto.
--
-- BASE: la operativa de Hostinger (industec_app / u671729428_ots).
--
-- POR QUE HACE FALTA UNA TABLA
-- Hasta ahora el buzón solo mostraba. Los casos viven en `casos_sap.json`, que
-- la estación **reescribe entero** en cada barrido: cualquier cosa que se
-- guardara ahí se perdería en el siguiente empuje. Asignar sin dejar rastro de
-- quién asignó no sirve para responderle a KFC, así que la decisión va a una
-- tabla y el catálogo se queda como lo que es: la copia de lo que pidió SAP.
--
-- LA CLAVE ES EL AVISO, NO UN ID AUTOINCREMENTAL (I-9).
-- El aviso de SAP es la clave natural del caso: es lo que KFC usa, lo que va en
-- el nombre del PDF y lo que cruza con `avisos_sap`. Con un id propio, dos
-- barridos podrían crear dos filas para el mismo caso y nadie lo notaría.
--
-- QUE NO GUARDA ESTA TABLA
-- No guarda el caso: eso está en el catálogo. Guarda **lo que decidimos sobre
-- él**. Si el caso desaparece del catálogo porque KFC lo elimino, la fila queda
-- y sigue diciendo quién lo asigno y cuándo -- que es justo lo que hay que poder
-- responder despues.
--
-- LOS ESTADOS Y QUIEN LOS PONE
--   NUEVO         nadie lo ha tocado
--   ASIGNADO      la administración o el jefe de zona se lo dio a un técnico
--   EN_REVISION   el jefe de zona vio algo raro y lo mandó a la administración
--   RESUELTO      la administración lo dio por atendido
--   NO_COMPETE    la administración resolvió que no es trabajo de INDUSTEC
--
-- `NO_COMPETE` solo lo pone la administración, nunca una alerta automática. Las
-- alertas de alcance señalan; el veredicto es de ella. Esa regla la fijó Andrés
-- el 2026-09-08 y aquí se hace cumplir con permisos, no con confianza.
-- ============================================================================

CREATE TABLE IF NOT EXISTS casos_gestion (
    aviso        VARCHAR(20) NOT NULL PRIMARY KEY
                 COMMENT 'El aviso de SAP. Clave natural del caso (I-9)',
    zona         ENUM('UIO','LARB','CNLJ','OTRA') NULL
                 COMMENT 'Copiada del caso al gestionarlo, para filtrar sin releer el JSON',
    estado       ENUM('NUEVO','ASIGNADO','EN_REVISION','RESUELTO','NO_COMPETE')
                 NOT NULL DEFAULT 'NUEVO',

    -- Asignación
    asignado_a   INT UNSIGNED NULL COMMENT 'usuarios.usuario_id del tecnico',
    asignado_por INT UNSIGNED NULL,
    asignado_en  DATETIME NULL,

    -- Revisión: lo que usa el jefe de zona para escalar a la administración
    revision_motivo VARCHAR(255) NULL,
    revision_por    INT UNSIGNED NULL,
    revision_en     DATETIME NULL,

    -- Veredicto: solo la administración
    veredicto_motivo VARCHAR(255) NULL,
    veredicto_por    INT UNSIGNED NULL,
    veredicto_en     DATETIME NULL,

    -- Derivación entre zonas, para el caso que llegó al buzón equivocado
    zona_origen   ENUM('UIO','LARB','CNLJ','OTRA') NULL
                  COMMENT 'De donde venia antes de derivarlo. NULL = nunca se derivo',
    derivado_por  INT UNSIGNED NULL,
    derivado_en   DATETIME NULL,

    nota         VARCHAR(500) NULL COMMENT 'Texto libre de la administracion',
    creado_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    tocado_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_gestion_estado (estado, zona),
    KEY idx_gestion_tecnico (asignado_a, estado),

    CONSTRAINT fk_gestion_asignado FOREIGN KEY (asignado_a)
        REFERENCES usuarios(usuario_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Lo que decidimos sobre cada caso. El caso en si vive en el catalogo';

-- La FK sobre `asignado_a` existe por una razón concreta: los usuarios **no se
-- borran, se dan de baja**, pero si algún día alguien borra una fila a mano, el
-- caso quedaría apuntando a un id que ya no existe y la pantalla mostraría un
-- técnico en blanco sin explicación. `ON DELETE SET NULL` lo deja sin asignar,
-- que es un estado que la administradora entiende y puede corregir.


-- ----------------------------------------------------------------------------
-- Permisos nuevos: ver el PDF de una orden es una acción con traza.
-- ----------------------------------------------------------------------------
INSERT INTO permisos (codigo, modulo, nombre, descripcion) VALUES
  ('ots.pdf', 'ots', 'Abrir el PDF de una orden',
   'Descargar o ver el PDF. Queda registrado quien lo abrio y cuando')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), descripcion = VALUES(descripcion);

-- Lo tienen los cuatro roles: el técnico necesita ver sus propias órdenes.
-- El alcance lo pone la consulta, no el permiso.
INSERT INTO rol_permisos (rol, permiso) VALUES
  ('SUPERADMIN','ots.pdf'), ('ADMIN','ots.pdf'),
  ('JEFE_ZONA','ots.pdf'), ('TECNICO','ots.pdf')
ON DUPLICATE KEY UPDATE rol = rol;

-- ============================================================================
-- VERIFICACION despues de aplicar:
--
--   SHOW CREATE TABLE casos_gestion\G
--     -> PRIMARY KEY (`aviso`)                       <- la clave de negocio
--     -> CONSTRAINT fk_gestion_asignado
--
--   SELECT rol, COUNT(*) FROM rol_permisos GROUP BY rol;
--     -> SUPERADMIN 19 | ADMIN 18 | JEFE_ZONA 11 | TECNICO 5
--
--   SELECT COUNT(*) FROM permisos WHERE codigo = 'ots.pdf';   -> 1
-- ============================================================================
