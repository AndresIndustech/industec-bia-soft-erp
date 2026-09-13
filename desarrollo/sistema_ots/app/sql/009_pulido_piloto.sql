-- ============================================================================
-- 009 — El pulido para las pruebas del cliente (T2.14, pedido de Andrés del
--       2026-09-12) y el libro de migraciones.
--
-- BASE: la operativa de Hostinger (u671729428_ots). Se aplica con
-- `php aplicar_sql.php sql/009_pulido_piloto.sql` y se puede correr dos veces sin
-- duplicar nada: todo va con IF NOT EXISTS / IF EXISTS, y los ENUM se amplían
-- SIEMPRE AL FINAL para no mover el significado de lo ya guardado. Después va
-- `009_semilla_diagnosticos.sql` (el catálogo de fallas y repuestos) y se comprueba
-- con `php verificar_esquema.php`, que tiene que decir «(con la 009)» y TODO OK.
--
-- QUÉ RESUELVE, hallazgo por hallazgo (AUDITORIA_2026-09-12.md):
--
--   1. `migraciones`: nadie sabía por la base qué se había aplicado y la 004/005/006
--      no eran idempotentes (E-14). Desde aquí `aplicar_sql.php` anota cada archivo
--      y se niega a reaplicar uno ya registrado salvo --forzar.
--   2. `pendientes`: el flujo real de repuestos —técnico solicita → JEFE VALIDA el
--      diagnóstico y el repuesto → la ADMINISTRACIÓN lo REGISTRA EN SAP con su número
--      → KFC decide: envía el repuesto, taller INDUSTEC, otro proveedor o baja— no
--      cabía en la máquina de estados (P-01..P-04, E-06). Entran los estados nuevos,
--      la validación, el número de requerimiento, el veredicto de KFC, el código del
--      diagnóstico pre-redactado y la lista estructurada de partes.
--   3. `email_queue`: la cola no tenía despachador ni las columnas que uno necesita
--      (E-03, E-13): reclamo, reintentos, error y secuencia para reenviar.
--   4. `ot_capturadas`: los estados reales de la emisión (E-12), el trabajo con otro
--      proveedor (D10), el reintento que no pisa una orden emitida (E-04) y los
--      índices que el archivo y el reemisor necesitan (E-18).
--   5. `ot_archivo`: el archivo general de órdenes de TODAS las zonas que Andrés
--      decidió abrir a todos los usuarios en solo lectura (D1, D2, E-05, TR-08).
--   6. `equipos_propuestos`: el equipo nuevo que el técnico registra desde el
--      formulario y que quedaba perdido dentro del JSON de la orden (H-10, D8).
--   7. `familias_equipo`, `diagnosticos`, `repuestos_frecuentes`: los diagnósticos
--      pre-redactados por daño común y los repuestos que se piden siempre, minados
--      del histórico real (H-11, D9). La semilla va en el archivo hermano.
--   8. `locales_admin`: el administrador de cada local, para elegirlo en vez de
--      teclearlo en cada orden (H-08).
--   9. `documentos`, `documento_versiones`, `documento_acuses`: el apartado de
--      aprendizaje —manuales y guías— con aprobación y versiones (D11, E-09).
--  10. `ingresos_preventivos`, `cronograma_novedades`: el cronograma deja de ser un
--      JSON que no escribe (TR-05, D15).
--  11. `casos_seguimientos`: «pedir seguimiento a un técnico» (ASG-15).
--  12. Permisos nuevos por rol, y la bitácora protegida contra DELETE/UPDATE con
--      triggers (SEG-18): para un mantenimiento excepcional se hace DROP TRIGGER,
--      se corrige y se vuelve a crear; que quede en el historial de git.
--
-- LO QUE NO HACE A PROPÓSITO: no agrega claves foráneas a las columnas *_por de
-- `casos_gestion` (E-22, baja): si alguna fila vieja apunta a un id que ya no
-- existe, el ALTER falla a medias en el servidor. Va en una migración propia
-- cuando se hayan revisado esas filas.
-- ============================================================================


-- ----------------------------------------------------------------------------
-- 1. El libro de migraciones.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS migraciones (
    archivo      VARCHAR(60)  NOT NULL PRIMARY KEY COMMENT 'Nombre del .sql, p. ej. 009_pulido_piloto.sql',
    sha256       CHAR(64)     NOT NULL DEFAULT '' COMMENT 'Huella del archivo aplicado; vacía en los registros retroactivos',
    aplicada_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aplicada_por VARCHAR(80)  NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Qué migraciones se aplicaron en ESTA base. aplicar_sql.php la escribe y la consulta';

-- Las ocho anteriores se dan por aplicadas: verificar_esquema.php lo comprueba
-- de verdad (tablas, claves y ENUM), esto solo deja constancia en el libro.
INSERT IGNORE INTO migraciones (archivo, sha256, aplicada_por) VALUES
  ('001_app_usuarios_hostinger.sql', '', 'registro retroactivo desde la 009'),
  ('002_admin_crea_tecnicos.sql',    '', 'registro retroactivo desde la 009'),
  ('003_gestion_casos.sql',          '', 'registro retroactivo desde la 009'),
  ('004_cierre_y_tecnico.sql',       '', 'registro retroactivo desde la 009'),
  ('005_bitacora_analizable.sql',    '', 'registro retroactivo desde la 009'),
  ('006_cierre_sin_atencion.sql',    '', 'registro retroactivo desde la 009'),
  ('007_pendientes_y_captura.sql',   '', 'registro retroactivo desde la 009'),
  ('008_emision.sql',                '', 'registro retroactivo desde la 009');


-- ----------------------------------------------------------------------------
-- 2. `pendientes`: el flujo real del repuesto.
--
-- Los estados nuevos van AL FINAL del ENUM. Los viejos (COTIZANDO, COMPRADO,
-- EN_BODEGA, EN_TALLER, GARANTIA_*, BAJA_PROPUESTA) siguen siendo válidos para
-- las filas que ya están en ellos; a los pendientes nuevos ya no se les ofrecen.
--
--   SOLICITADO        el técnico dejó el equipo sin concluir y pidió (corre el reloj)
--   VALIDADO_JEFE     el jefe de zona confirmó diagnóstico y repuesto, y fijó la vía
--   REGISTRADO_SAP    la administración lo registró en SAP con su número: esperando a KFC
--   ESPERA_KFC        reservado (hoy REGISTRADO_SAP ya significa «esperando a KFC»)
--   REPUESTO_ENVIADO  KFC mandó la pieza          → ENTREGADO → RESUELTO
--   TALLER_INDUSTEC   KFC decidió reparar aquí    → DEVUELTO_TALLER → RESUELTO
--   OTRO_PROVEEDOR    KFC lo mandó a un tercero   → RESUELTO
--   (BAJA)            KFC dio de baja el equipo   → BAJA_APROBADA → RESUELTO
-- ----------------------------------------------------------------------------
ALTER TABLE pendientes
  MODIFY COLUMN estado ENUM(
      'SIN_VEREDICTO',
      'COTIZANDO','COMPRADO','EN_BODEGA','ENTREGADO',
      'EN_TALLER','DEVUELTO_TALLER',
      'GARANTIA_RECLAMADA','GARANTIA_APROBADA','GARANTIA_NEGADA',
      'BAJA_PROPUESTA','BAJA_APROBADA',
      'RESUELTO','CANCELADO',
      'SOLICITADO','VALIDADO_JEFE','REGISTRADO_SAP','ESPERA_KFC',
      'REPUESTO_ENVIADO','TALLER_INDUSTEC','OTRO_PROVEEDOR'
    ) NOT NULL DEFAULT 'SIN_VEREDICTO'
    COMMENT 'El paso dentro de la vía. Desde la 009 el pendiente nace SOLICITADO; los estados anteriores a la 009 siguen valiendo para sus filas. Qué transiciones son legales lo impone Pendientes::mover()';

ALTER TABLE pendientes
  ADD COLUMN IF NOT EXISTS validado_por        INT UNSIGNED NULL COMMENT 'El jefe de zona que confirmó diagnóstico y repuesto (D3)' AFTER veredicto_nota,
  ADD COLUMN IF NOT EXISTS validado_en         DATETIME     NULL AFTER validado_por,
  ADD COLUMN IF NOT EXISTS requerimiento_sap   VARCHAR(30)  NULL COMMENT 'El número del requerimiento que la administración registró en SAP. Obligatorio al pasar a REGISTRADO_SAP' AFTER validado_en,
  ADD COLUMN IF NOT EXISTS registrado_sap_por  INT UNSIGNED NULL AFTER requerimiento_sap,
  ADD COLUMN IF NOT EXISTS registrado_sap_en   DATETIME     NULL AFTER registrado_sap_por,
  ADD COLUMN IF NOT EXISTS veredicto_kfc       ENUM('PENDIENTE','REPUESTO_ENVIADO','TALLER_INDUSTEC','OTRO_PROVEEDOR','BAJA') NOT NULL DEFAULT 'PENDIENTE'
                            COMMENT 'Lo que decidió Grupo KFC sobre el equipo. PENDIENTE mientras no responda' AFTER registrado_sap_en,
  ADD COLUMN IF NOT EXISTS veredicto_kfc_ref   VARCHAR(80)  NULL COMMENT 'Referencia de KFC: guía del repuesto, correo, número de caso' AFTER veredicto_kfc,
  ADD COLUMN IF NOT EXISTS veredicto_kfc_en    DATETIME     NULL AFTER veredicto_kfc_ref,
  ADD COLUMN IF NOT EXISTS veredicto_kfc_por   INT UNSIGNED NULL AFTER veredicto_kfc_en,
  ADD COLUMN IF NOT EXISTS diagnostico_codigo  VARCHAR(20)  NULL COMMENT 'El diagnóstico pre-redactado elegido (diagnosticos.codigo). El texto libre se conserva en `diagnostico`' AFTER diagnostico,
  ADD COLUMN IF NOT EXISTS partes              JSON         NULL COMMENT 'Lista estructurada [{descripcion, cantidad, numero_parte, codigo}]. `parte` texto queda como resumen' AFTER parte,
  ADD KEY IF NOT EXISTS idx_pen_sap   (requerimiento_sap),
  ADD KEY IF NOT EXISTS idx_pen_flujo (estado, zona, validado_en);

-- En MariaDB el IF NOT EXISTS va después de FOREIGN KEY, no después de
-- CONSTRAINT: «ADD CONSTRAINT IF NOT EXISTS fk … FOREIGN KEY» es error 1064
-- (medido el 2026-09-13 en el 11.8.9 del servidor).
ALTER TABLE pendientes
  ADD CONSTRAINT fk_pen_valida FOREIGN KEY IF NOT EXISTS fk_pen_valida (validado_por)       REFERENCES usuarios(usuario_id),
  ADD CONSTRAINT fk_pen_sap    FOREIGN KEY IF NOT EXISTS fk_pen_sap    (registrado_sap_por) REFERENCES usuarios(usuario_id),
  ADD CONSTRAINT fk_pen_kfc    FOREIGN KEY IF NOT EXISTS fk_pen_kfc    (veredicto_kfc_por)  REFERENCES usuarios(usuario_id);

-- El hilo conoce los pasos nuevos, y distingue el recordatorio del técnico
-- (cuenta como insistencia) del aviso interno de la oficina (no cuenta).
ALTER TABLE pendiente_notas
  MODIFY COLUMN tipo ENUM('RECORDATORIO','RESPUESTA','VEREDICTO','CAMBIO_ESTADO','DIAGNOSTICO',
                          'AVISO_INTERNO','VALIDACION','REGISTRO_SAP','VEREDICTO_KFC')
    NOT NULL DEFAULT 'RECORDATORIO'
    COMMENT 'RECORDATORIO lo escribe quien espera (cuenta como insistencia). AVISO_INTERNO lo escribe la oficina y no cuenta. VALIDACION, REGISTRO_SAP y VEREDICTO_KFC los deja el sistema al mover el pendiente',
  MODIFY COLUMN estado_antes ENUM('SIN_VEREDICTO','COTIZANDO','COMPRADO','EN_BODEGA','ENTREGADO',
                      'EN_TALLER','DEVUELTO_TALLER','GARANTIA_RECLAMADA','GARANTIA_APROBADA',
                      'GARANTIA_NEGADA','BAJA_PROPUESTA','BAJA_APROBADA','RESUELTO','CANCELADO',
                      'SOLICITADO','VALIDADO_JEFE','REGISTRADO_SAP','ESPERA_KFC',
                      'REPUESTO_ENVIADO','TALLER_INDUSTEC','OTRO_PROVEEDOR') NULL,
  MODIFY COLUMN estado_desp  ENUM('SIN_VEREDICTO','COTIZANDO','COMPRADO','EN_BODEGA','ENTREGADO',
                      'EN_TALLER','DEVUELTO_TALLER','GARANTIA_RECLAMADA','GARANTIA_APROBADA',
                      'GARANTIA_NEGADA','BAJA_PROPUESTA','BAJA_APROBADA','RESUELTO','CANCELADO',
                      'SOLICITADO','VALIDADO_JEFE','REGISTRADO_SAP','ESPERA_KFC',
                      'REPUESTO_ENVIADO','TALLER_INDUSTEC','OTRO_PROVEEDOR') NULL;


-- ----------------------------------------------------------------------------
-- 3. `email_queue`: lo que un despachador necesita.
-- ----------------------------------------------------------------------------
ALTER TABLE email_queue
  MODIFY COLUMN estado ENUM('RETENIDO','PENDIENTE','ENVIADO','FALLIDO','ENVIANDO') NOT NULL DEFAULT 'PENDIENTE'
    COMMENT 'RETENIDO: no sale nunca (sitio de pruebas). PENDIENTE: lo toma el despachador. ENVIANDO: reclamado por un despachador (tomado_en). FALLIDO: agotó los reintentos o el SMTP rechazó de forma permanente',
  ADD COLUMN IF NOT EXISTS tomado_en          DATETIME     NULL COMMENT 'Cuándo lo reclamó el despachador. Un reclamo de más de 15 min se considera abandonado' AFTER intentos,
  ADD COLUMN IF NOT EXISTS ultimo_intento_en  DATETIME     NULL AFTER tomado_en,
  ADD COLUMN IF NOT EXISTS proximo_intento_en DATETIME     NULL COMMENT 'Espera creciente: 5 min, 15 min, 1 h, 4 h, 24 h' AFTER ultimo_intento_en,
  ADD COLUMN IF NOT EXISTS error_ultimo       VARCHAR(300) NULL AFTER proximo_intento_en,
  ADD COLUMN IF NOT EXISTS secuencia          SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = el envío original; 1, 2… los reenvíos pedidos por una persona' AFTER tipo;

ALTER TABLE email_queue
  DROP INDEX IF EXISTS uq_correo;

ALTER TABLE email_queue
  ADD UNIQUE KEY IF NOT EXISTS uq_correo (id_industec, tipo, secuencia),
  ADD KEY IF NOT EXISTS idx_correo_despacho (estado, proximo_intento_en);


-- ----------------------------------------------------------------------------
-- 4. `ot_capturadas`: los estados reales de la emisión y los índices del archivo.
-- ----------------------------------------------------------------------------
ALTER TABLE ot_capturadas
  MODIFY COLUMN estado ENUM('RECIBIDA','PROCESADA','RECHAZADA','NUMERADA','EMITIDA','ENVIADA','FALLIDA') NOT NULL DEFAULT 'RECIBIDA'
    COMMENT 'RECIBIDA: guardada. NUMERADA: con correlativo, sin PDF todavía. EMITIDA: con PDF. ENVIADA: el correo salió. FALLIDA: la emisión falló y el reemisor lo reintenta. PROCESADA y RECHAZADA son anteriores a la 009',
  ADD COLUMN IF NOT EXISTS ultimo_reintento_en DATETIME NULL COMMENT 'El celular volvió a mandar el mismo envio_uuid. Con una orden ya emitida, la carga NO se pisa (E-04)' AFTER recibida_en,
  ADD COLUMN IF NOT EXISTS pdf_sha256_regen    CHAR(64) NULL COMMENT 'Huella de un PDF regenerado; pdf_sha256 conserva la del documento original' AFTER pdf_sha256,
  ADD COLUMN IF NOT EXISTS con_proveedor       VARCHAR(160) NULL COMMENT 'Trabajo con otro proveedor: «<proveedor> · <objeto>» (D10)' AFTER concluida,
  ADD KEY IF NOT EXISTS idx_cap_aviso   (aviso),
  ADD KEY IF NOT EXISTS idx_cap_zona    (zona, capturada_en),
  ADD KEY IF NOT EXISTS idx_cap_local   (local_codigo, capturada_en),
  ADD KEY IF NOT EXISTS idx_cap_emision (estado, emitida_en);

ALTER TABLE casos_gestion
  ADD KEY IF NOT EXISTS idx_gestion_ot (ot_cierre);


-- ----------------------------------------------------------------------------
-- 5. El archivo general de órdenes de trabajo (D1, D2).
--
-- Es un ÍNDICE: sabe de cada orden dónde está —en este servidor, en la estación
-- o en las dos— y lo que hace falta para buscarla. No es la orden: la orden
-- vive en su PDF y, para las emitidas por la app, en `ot_capturadas`.
-- Clave de negocio: el nombre canónico, que es único por construcción (I-9).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ot_archivo (
    id_industec    VARCHAR(60)  NOT NULL PRIMARY KEY COMMENT 'OT-{n}-{LOCAL}[-{AVISO}][-D{día}]-{ZONA}',
    zona           ENUM('UIO','LARB','CNLJ','OTRA') NOT NULL,
    local_codigo   VARCHAR(12)  NULL,
    local_nombre   VARCHAR(120) NULL,
    cadena         VARCHAR(40)  NULL COMMENT 'Del maestro de locales, nunca del prefijo del código',
    aviso          VARCHAR(20)  NULL,
    modulo         ENUM('CORRECTIVO','PREVENTIVO','OTROS') NULL,
    dia            TINYINT UNSIGNED NULL,
    fecha_atencion DATE         NULL,
    tecnico        VARCHAR(160) NULL COMMENT 'Como firmó la orden; puede ser más de una persona',
    origen         ENUM('APP','CORREO','HISTORICO') NOT NULL
                   COMMENT 'APP: la emitió B.IA Soft. CORREO: el cruce de informes la bajó del buzón. HISTORICO: el catálogo de la estación',
    en_servidor    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 si el PDF está en ordenes_pdf/ y pdf.php puede servirlo',
    ruta           VARCHAR(255) NULL COMMENT 'Nombre del PDF en ordenes_pdf/, si está',
    bytes          INT UNSIGNED NULL,
    sha256         CHAR(64)     NULL,
    fuente_ruta    VARCHAR(400) NULL COMMENT 'Dónde vive en la estación (D:\\RESPALDOS\\…), para pedir una copia',
    indexado_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_arch_zona_fecha (zona, fecha_atencion),
    KEY idx_arch_local      (local_codigo),
    KEY idx_arch_aviso      (aviso),
    KEY idx_arch_tecnico    (tecnico),
    KEY idx_arch_origen     (origen, en_servidor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Índice del archivo general de OT de todas las zonas. Lectura para todo usuario con sesión; cada apertura y descarga queda en la bitácora';

CREATE TABLE IF NOT EXISTS ot_archivo_solicitudes (
    solicitud_id  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_industec   VARCHAR(60)  NOT NULL,
    usuario_id    INT UNSIGNED NOT NULL,
    solicitado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atendido_en   DATETIME     NULL COMMENT 'Cuando la estación subió el PDF',
    UNIQUE KEY uq_solicitud (id_industec, usuario_id),
    KEY idx_sol_pend (atendido_en, solicitado_en),
    CONSTRAINT fk_sol_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='«Pedir copia» de una orden que está en la estación y no en el servidor';


-- ----------------------------------------------------------------------------
-- 6. Equipos nuevos registrados desde el formulario (D8).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS equipos_propuestos (
    equipo_uuid   CHAR(36)     NOT NULL PRIMARY KEY COMMENT 'Lo genera el celular: el reintento no lo duplica',
    local_codigo  VARCHAR(12)  NOT NULL,
    zona          ENUM('UIO','LARB','CNLJ','OTRA') NULL,
    tipo          VARCHAR(80)  NOT NULL COMMENT 'Un tipo del catálogo (tipos_equipo), no texto inventado',
    marca         VARCHAR(80)  NULL,
    modelo        VARCHAR(80)  NULL,
    serie         VARCHAR(80)  NULL,
    activo_fijo   VARCHAR(60)  NULL,
    area          VARCHAR(40)  NULL,
    ubicacion     VARCHAR(120) NULL,
    envio_uuid    CHAR(36)     NULL COMMENT 'La orden en la que se registró',
    propuesto_por INT UNSIGNED NOT NULL,
    propuesto_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    estado        ENUM('PROPUESTO','APROBADO','RECHAZADO','FUSIONADO') NOT NULL DEFAULT 'PROPUESTO'
                  COMMENT 'PROPUESTO se ofrece ya en la lista del local (marcado). APROBADO lo toma la estación para el maestro. FUSIONADO: era uno que ya existía',
    revisado_por  INT UNSIGNED NULL,
    revisado_en   DATETIME     NULL,
    nota          VARCHAR(300) NULL,
    KEY idx_eqp_local (local_codigo, estado),
    KEY idx_eqp_estado (estado, propuesto_en),
    CONSTRAINT fk_eqp_propone FOREIGN KEY (propuesto_por) REFERENCES usuarios(usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Equipos que no estaban en el catálogo del local. Visibles para todas las zonas desde que se proponen; la administración los aprueba';


-- ----------------------------------------------------------------------------
-- 7. Familias de equipo, diagnósticos pre-redactados y repuestos frecuentes (D9).
--
-- La familia se resuelve cruzando el nombre del tipo de SAP («000436_SY_MAQYEQ_
-- FREIDORA», «CONGELADOR VERTICAL») contra `patron` (insensible a mayúsculas y
-- tildes), en el celular y en el servidor con la misma tabla.
-- La SEMILLA está en 009_semilla_diagnosticos.sql: sale de 7.863 filas reales
-- de los planes de zona (2025-2026), no de la imaginación de nadie (I-7).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS familias_equipo (
    familia VARCHAR(60)  NOT NULL PRIMARY KEY,
    patron  VARCHAR(200) NOT NULL COMMENT 'Expresión regular que cruza contra el nombre del tipo, ya en mayúsculas y sin tildes',
    orden   SMALLINT     NOT NULL DEFAULT 0 COMMENT 'Por volumen de casos: la primera que calza gana',
    activo  TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS diagnosticos (
    codigo            VARCHAR(20)  NOT NULL PRIMARY KEY COMMENT 'p. ej. FRE-01',
    familia           VARCHAR(60)  NOT NULL,
    titulo            VARCHAR(120) NOT NULL COMMENT 'La falla, en pocas palabras: lo que ve el técnico en el selector',
    texto             VARCHAR(800) NOT NULL COMMENT 'Síntoma: … Causa probable: … Acción: … — se copia al diagnóstico y el técnico lo ajusta',
    partes_frecuentes JSON         NULL COMMENT 'Códigos de repuestos_frecuentes que suelen ir con esta falla',
    activo            TINYINT(1)   NOT NULL DEFAULT 1,
    orden             SMALLINT     NOT NULL DEFAULT 0,
    creado_por        INT UNSIGNED NULL,
    creado_en         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_por   INT UNSIGNED NULL,
    actualizado_en    DATETIME     NULL,
    KEY idx_diag_familia (familia, activo, orden),
    CONSTRAINT fk_diag_familia FOREIGN KEY (familia) REFERENCES familias_equipo(familia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Diagnósticos pre-redactados por daño común. Los editan la administración y los jefes de zona (catalogos.editar)';

CREATE TABLE IF NOT EXISTS repuestos_frecuentes (
    codigo        VARCHAR(20)  NOT NULL PRIMARY KEY COMMENT 'p. ej. REP-001',
    familia       VARCHAR(60)  NOT NULL,
    descripcion   VARCHAR(160) NOT NULL,
    numero_parte  VARCHAR(60)  NULL COMMENT 'El número de parte del fabricante cuando el histórico lo trae (MAN…, HEN…, 17000…)',
    marca         VARCHAR(60)  NULL,
    unidad        VARCHAR(20)  NOT NULL DEFAULT 'unidad',
    activo        TINYINT(1)   NOT NULL DEFAULT 1,
    orden         SMALLINT     NOT NULL DEFAULT 0,
    KEY idx_rep_familia (familia, activo, orden),
    CONSTRAINT fk_rep_familia FOREIGN KEY (familia) REFERENCES familias_equipo(familia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Los repuestos que se piden siempre, por familia, con su número de parte cuando se conoce';


-- ----------------------------------------------------------------------------
-- 8. El administrador de cada local (H-08).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS locales_admin (
    admin_id     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    local_codigo VARCHAR(12)  NOT NULL,
    nombre       VARCHAR(120) NOT NULL,
    correo       VARCHAR(160) NULL,
    telefono     VARCHAR(40)  NULL,
    cargo        VARCHAR(60)  NULL,
    veces        INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Cuántas órdenes firmó: ordena la lista que se ofrece',
    visto_ultimo DATETIME     NULL,
    fuente       ENUM('ORDEN','MANUAL') NOT NULL DEFAULT 'ORDEN',
    activo       TINYINT(1)   NOT NULL DEFAULT 1,
    UNIQUE KEY uq_local_admin (local_codigo, nombre),
    KEY idx_ladm_local (local_codigo, activo, veces)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Nombres de los administradores que han firmado en cada local, para elegirlos en vez de teclearlos';

-- Semilla: lo que ya firmó en las órdenes emitidas por la app.
INSERT INTO locales_admin (local_codigo, nombre, veces, visto_ultimo, fuente)
SELECT c.local_codigo,
       TRIM(JSON_UNQUOTE(JSON_EXTRACT(c.carga, '$.admin'))),
       COUNT(*),
       MAX(c.capturada_en),
       'ORDEN'
  FROM ot_capturadas c
 WHERE c.local_codigo IS NOT NULL
   AND JSON_EXTRACT(c.carga, '$.admin') IS NOT NULL
   AND TRIM(JSON_UNQUOTE(JSON_EXTRACT(c.carga, '$.admin'))) <> ''
 GROUP BY c.local_codigo, TRIM(JSON_UNQUOTE(JSON_EXTRACT(c.carga, '$.admin')))
ON DUPLICATE KEY UPDATE veces = GREATEST(locales_admin.veces, VALUES(veces)),
                        visto_ultimo = GREATEST(COALESCE(locales_admin.visto_ultimo, VALUES(visto_ultimo)), VALUES(visto_ultimo));


-- ----------------------------------------------------------------------------
-- 9. Aprendizaje: manuales, guías y comunicados con aprobación y versiones (D11).
--
-- Nada se borra: una versión se RETIRA con quién y cuándo. El archivo físico
-- lleva un nombre aleatorio en documentos/ (que niega todo por .htaccess) y solo
-- lo sirve documento.php, con sesión y bitácora.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS documentos (
    doc_id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tipo           ENUM('MANUAL','GUIA','COMUNICADO') NOT NULL,
    titulo         VARCHAR(160) NOT NULL,
    descripcion    VARCHAR(600) NULL,
    familia        VARCHAR(60)  NULL COMMENT 'Familia de equipo a la que se refiere, si aplica',
    zona           ENUM('UIO','LARB','CNLJ','OTRA') NULL COMMENT 'NULL = todas las zonas',
    creado_por     INT UNSIGNED NOT NULL,
    creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    activo         TINYINT(1)   NOT NULL DEFAULT 1,
    UNIQUE KEY uq_documento (tipo, titulo),
    KEY idx_doc_lista (tipo, activo, familia, zona),
    CONSTRAINT fk_doc_crea FOREIGN KEY (creado_por) REFERENCES usuarios(usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='El apartado de aprendizaje. Un documento es el título; lo que se ve es su última versión APROBADA';

CREATE TABLE IF NOT EXISTS documento_versiones (
    version_id     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    doc_id         INT UNSIGNED NOT NULL,
    version        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    nombre_archivo VARCHAR(160) NOT NULL COMMENT 'Como lo subieron, para mostrarlo',
    ruta           VARCHAR(255) NOT NULL COMMENT 'Nombre aleatorio dentro de documentos/',
    bytes          INT UNSIGNED NOT NULL,
    sha256         CHAR(64)     NOT NULL,
    mime           VARCHAR(80)  NOT NULL,
    estado         ENUM('EN_REVISION','APROBADA','RECHAZADA','RETIRADA') NOT NULL DEFAULT 'EN_REVISION',
    subida_por     INT UNSIGNED NOT NULL,
    subida_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revisada_por   INT UNSIGNED NULL,
    revisada_en    DATETIME     NULL,
    nota_revision  VARCHAR(400) NULL,
    UNIQUE KEY uq_version (doc_id, version),
    UNIQUE KEY uq_contenido (sha256),
    KEY idx_ver_estado (estado, subida_en),
    CONSTRAINT fk_ver_doc   FOREIGN KEY (doc_id)     REFERENCES documentos(doc_id),
    CONSTRAINT fk_ver_sube  FOREIGN KEY (subida_por) REFERENCES usuarios(usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cada archivo subido. La misma huella no entra dos veces (uq_contenido)';

CREATE TABLE IF NOT EXISTS documento_acuses (
    doc_id     INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    version    SMALLINT UNSIGNED NOT NULL,
    visto_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (doc_id, usuario_id),
    CONSTRAINT fk_acuse_doc FOREIGN KEY (doc_id)     REFERENCES documentos(doc_id),
    CONSTRAINT fk_acuse_usr FOREIGN KEY (usuario_id) REFERENCES usuarios(usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='«Leído» de los comunicados: visto por X de Y';


-- ----------------------------------------------------------------------------
-- 10. El cronograma de preventivos que escribe (D15).
--
-- plan_original es lo pactado con KFC y NO cambia: contra eso se mide el
-- cumplimiento. plan_vigente es lo reagendado, con su motivo en la tabla de
-- novedades. real_* es lo que pasó, y de ahí sale «cumplido a tiempo / tarde».
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ingresos_preventivos (
    ingreso_id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    local_codigo         VARCHAR(12)  NOT NULL,
    zona                 ENUM('UIO','LARB','CNLJ','OTRA') NULL,
    anio                 SMALLINT     NOT NULL,
    numero               TINYINT UNSIGNED NOT NULL COMMENT '1..4: el ingreso del año',
    plan_original_inicio DATE NULL,
    plan_original_fin    DATE NULL,
    plan_vigente_inicio  DATE NULL,
    plan_vigente_fin     DATE NULL,
    kit_estado           ENUM('SIN_KIT','SOLICITADO','CONFIRMADO','ENTREGADO') NOT NULL DEFAULT 'SIN_KIT',
    kit_fecha            DATE NULL,
    kit_nota             VARCHAR(300) NULL,
    real_inicio          DATE NULL,
    real_fin             DATE NULL,
    estado               ENUM('PLANIFICADO','EN_CURSO','CUMPLIDO','VENCIDO','CANCELADO') NOT NULL DEFAULT 'PLANIFICADO',
    ot_ids               VARCHAR(400) NULL COMMENT 'Las órdenes del ingreso, separadas por coma',
    actualizado_por      INT UNSIGNED NULL,
    actualizado_en       DATETIME NULL,
    UNIQUE KEY uq_ingreso (local_codigo, anio, numero),
    KEY idx_ing_zona (zona, anio, plan_vigente_inicio),
    KEY idx_ing_estado (estado, plan_vigente_fin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Un ingreso de preventivo por local y vuelta del año. El cumplimiento se mide contra plan_original';

CREATE TABLE IF NOT EXISTS cronograma_novedades (
    novedad_id    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ingreso_id    INT UNSIGNED NOT NULL,
    tipo          ENUM('REAGENDA','KIT','CIERRE','NOTA','AGENDA') NOT NULL,
    motivo        VARCHAR(120) NULL COMMENT 'Obligatorio al reagendar: es lo que se le explica a KFC',
    detalle       VARCHAR(600) NULL,
    fecha_antes   DATE NULL,
    fecha_despues DATE NULL,
    por           INT UNSIGNED NOT NULL,
    en            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cnov_ingreso (ingreso_id, en),
    CONSTRAINT fk_cnov_ingreso FOREIGN KEY (ingreso_id) REFERENCES ingresos_preventivos(ingreso_id),
    CONSTRAINT fk_cnov_por     FOREIGN KEY (por)        REFERENCES usuarios(usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Lo que pasó con cada ingreso: reagendas con motivo, kit, cierre. Es la traza para KFC';


-- ----------------------------------------------------------------------------
-- 11. «Pedir seguimiento» a un técnico (ASG-15).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS casos_seguimientos (
    seguimiento_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    aviso          VARCHAR(20)  NOT NULL,
    tecnico_id     INT UNSIGNED NOT NULL,
    pedido_por     INT UNSIGNED NOT NULL,
    texto          VARCHAR(600) NOT NULL,
    pedido_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    visto_en       DATETIME     NULL COMMENT 'Cuando el técnico abrió sus avisos',
    respondido_en  DATETIME     NULL,
    respuesta      VARCHAR(600) NULL,
    KEY idx_seg_tecnico (tecnico_id, visto_en, pedido_en),
    KEY idx_seg_aviso   (aviso),
    CONSTRAINT fk_seg_tecnico FOREIGN KEY (tecnico_id) REFERENCES usuarios(usuario_id),
    CONSTRAINT fk_seg_pide    FOREIGN KEY (pedido_por) REFERENCES usuarios(usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Un pedido de seguimiento sobre un caso, dirigido a un técnico. Le aparece en sus avisos';


-- ----------------------------------------------------------------------------
-- 12. Permisos nuevos y su reparto. Un permiso dice «puede»; el alcance lo pone
--     la consulta. `ots.archivo` y `ots.compartir` los tienen los cuatro roles
--     por la decisión D1; el resto sigue la regla de quién decide qué.
-- ----------------------------------------------------------------------------
INSERT INTO permisos (codigo, modulo, nombre, descripcion) VALUES
  ('ots.archivo',                'ots',        'Consultar el archivo general',
   'Ver, descargar y buscar las ordenes de trabajo de todas las zonas (solo lectura). Cada apertura queda en la bitacora'),
  ('ots.compartir',              'ots',        'Compartir el PDF de una orden',
   'Generar el enlace firmado y con caducidad para mandarle el informe al local. Queda registrado quien lo genero'),
  ('repuestos.validar',          'pendientes', 'Validar la solicitud de repuesto',
   'Confirmar que el diagnostico y el repuesto pedidos por el tecnico son correctos, y fijar la via'),
  ('repuestos.registrar_sap',    'pendientes', 'Registrar el requerimiento en SAP',
   'Anotar el numero del requerimiento registrado en SAP y dejar el pendiente esperando a KFC'),
  ('repuestos.responder',        'pendientes', 'Responder en el hilo del pendiente',
   'Escribir una respuesta al tecnico en el hilo de un pendiente'),
  ('catalogos.editar',           'catalogos',  'Editar diagnosticos y repuestos frecuentes',
   'Crear y corregir los diagnosticos pre-redactados y los repuestos frecuentes por familia de equipo'),
  ('equipos.aprobar',            'catalogos',  'Aprobar equipos propuestos',
   'Aprobar o rechazar los equipos nuevos que los tecnicos registran desde el formulario'),
  ('documentos.ver',             'documentos', 'Ver manuales y guias',
   'Abrir el apartado de aprendizaje y descargar los documentos aprobados'),
  ('documentos.subir',           'documentos', 'Subir manuales y guias',
   'Subir un documento o una version nueva. Queda en revision hasta que se apruebe'),
  ('documentos.proponer',        'documentos', 'Proponer un manual o guia',
   'Subir un documento que queda en revision, sin poder publicarlo'),
  ('documentos.aprobar',         'documentos', 'Aprobar manuales y guias',
   'Aprobar, rechazar o retirar una version. Es lo que la hace visible a todos'),
  ('cronograma.editar',          'cronograma', 'Editar el cronograma de preventivos',
   'Confirmar kit, reagendar con motivo, agendar un local y cerrar un ingreso'),
  ('casos.seguimiento',          'casos',      'Pedir seguimiento a un tecnico',
   'Mandarle a un tecnico un pedido de seguimiento sobre un caso, que le aparece en sus avisos'),
  ('casos.cerrar_sin_atencion',  'casos',      'Cerrar casos por falta de atencion',
   'Ejecutar desde el panel el cierre de los casos con mas de una semana sin ningun informe')
ON DUPLICATE KEY UPDATE modulo = VALUES(modulo), nombre = VALUES(nombre), descripcion = VALUES(descripcion);

INSERT INTO rol_permisos (rol, permiso) VALUES
  ('SUPERADMIN','ots.archivo'),   ('ADMIN','ots.archivo'),   ('JEFE_ZONA','ots.archivo'),   ('TECNICO','ots.archivo'),
  ('SUPERADMIN','ots.compartir'), ('ADMIN','ots.compartir'), ('JEFE_ZONA','ots.compartir'), ('TECNICO','ots.compartir'),
  ('SUPERADMIN','repuestos.validar'),       ('ADMIN','repuestos.validar'),       ('JEFE_ZONA','repuestos.validar'),
  ('SUPERADMIN','repuestos.registrar_sap'), ('ADMIN','repuestos.registrar_sap'),
  ('SUPERADMIN','repuestos.responder'),     ('ADMIN','repuestos.responder'),     ('JEFE_ZONA','repuestos.responder'),
  ('SUPERADMIN','catalogos.editar'),        ('ADMIN','catalogos.editar'),        ('JEFE_ZONA','catalogos.editar'),
  ('SUPERADMIN','equipos.aprobar'),         ('ADMIN','equipos.aprobar'),
  ('SUPERADMIN','documentos.ver'),   ('ADMIN','documentos.ver'),   ('JEFE_ZONA','documentos.ver'),   ('TECNICO','documentos.ver'),
  ('SUPERADMIN','documentos.subir'), ('ADMIN','documentos.subir'), ('JEFE_ZONA','documentos.subir'),
  ('TECNICO','documentos.proponer'),
  ('SUPERADMIN','documentos.aprobar'), ('ADMIN','documentos.aprobar'),
  ('SUPERADMIN','cronograma.editar'),  ('ADMIN','cronograma.editar'),  ('JEFE_ZONA','cronograma.editar'),
  ('SUPERADMIN','casos.seguimiento'),  ('ADMIN','casos.seguimiento'),  ('JEFE_ZONA','casos.seguimiento'),
  ('SUPERADMIN','casos.cerrar_sin_atencion'), ('ADMIN','casos.cerrar_sin_atencion')
ON DUPLICATE KEY UPDATE rol = rol;


-- ----------------------------------------------------------------------------
-- 13. La bitácora no se edita ni se borra (SEG-18).
--
-- El usuario MySQL de la aplicación tiene todos los privilegios (lo creó hPanel),
-- así que un DELETE desde cualquier script la vaciaría. Con los triggers, la
-- única forma de tocarla es quitar el trigger primero, y eso queda en git y en
-- el historial de la sesión SSH. Para un mantenimiento excepcional:
--   DROP TRIGGER bitacora_sin_update; ... ; (volver a crear el trigger)
-- ----------------------------------------------------------------------------
DROP TRIGGER IF EXISTS bitacora_sin_delete;
CREATE TRIGGER bitacora_sin_delete BEFORE DELETE ON bitacora
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La bitacora no se borra (009)';

DROP TRIGGER IF EXISTS bitacora_sin_update;
CREATE TRIGGER bitacora_sin_update BEFORE UPDATE ON bitacora
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La bitacora no se edita (009)';


-- ============================================================================
-- VERIFICACIÓN después de aplicar (`php verificar_esquema.php` lo hace todo junto
-- y tiene que terminar en «TODO OK» diciendo «(con la 009)»). A mano:
--
--   SHOW COLUMNS FROM pendientes LIKE 'estado';      -- termina en ,'OTRO_PROVEEDOR')
--   SHOW COLUMNS FROM pendientes LIKE 'requerimiento_sap';   -- 1 fila
--   SHOW COLUMNS FROM email_queue LIKE 'estado';     -- termina en ,'ENVIANDO')
--   SHOW INDEX FROM email_queue WHERE Key_name = 'uq_correo';   -- 3 columnas
--   SHOW COLUMNS FROM ot_capturadas LIKE 'estado';   -- termina en ,'FALLIDA')
--   SELECT COUNT(*) FROM information_schema.TABLES
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN
--      ('migraciones','ot_archivo','ot_archivo_solicitudes','equipos_propuestos',
--       'familias_equipo','diagnosticos','repuestos_frecuentes','locales_admin',
--       'documentos','documento_versiones','documento_acuses','ingresos_preventivos',
--       'cronograma_novedades','casos_seguimientos');                  -- 14
--   SELECT rol, COUNT(*) FROM rol_permisos GROUP BY rol;
--     -- SUPERADMIN 39 · ADMIN 38 · JEFE_ZONA 26 · TECNICO 13
--   SELECT COUNT(*) FROM migraciones;                -- 9 (8 retroactivas + esta)
--   DELETE FROM bitacora WHERE bitacora_id = -1;     -- ERROR 1644 (45000)
--   Tras la semilla: SELECT COUNT(*) FROM familias_equipo;  -- 23
--                    SELECT COUNT(*) FROM diagnosticos;     -- >= 130
--                    SELECT COUNT(*) FROM repuestos_frecuentes;  -- >= 70
-- ============================================================================
