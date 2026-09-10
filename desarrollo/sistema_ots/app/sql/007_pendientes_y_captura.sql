-- ============================================================================
-- 007 — Pendientes técnicos (el equipo que quedó sin concluir), su hilo de
--       insistencias, y la captura de órdenes llenadas sin señal.
--
-- ESTADO: ESCRITA, NO APLICADA. Cambia el esquema, así que requiere aprobación
-- explícita antes de correrla contra `u671729428_ots`. Se aplica con
-- `aplicar_sql.php` y se comprueba con las consultas del final.
--
-- ============================================================================
-- LA REGLA DE NEGOCIO QUE ESTE ARCHIVO CONVIERTE EN TABLAS
--
-- INDUSTEC trabaja con una premisa: **una intervención concluye el trabajo**.
-- El técnico va, diagnostica y resuelve en esa visita. El único motivo válido
-- para volver es que el equipo dependa de una pieza o de un tercero.
--
-- Y encima de eso hay un compromiso duro: **un equipo no puede estar
-- deshabilitado más de 48 horas**. Un local de Grupo KFC con la freidora
-- muerta deja de vender; el reloj no es una meta interna, es el negocio del
-- cliente parado.
--
-- Dentro de esas 48 horas hay que dar un VEREDICTO, y son exactamente cuatro
-- salidas posibles, apoyadas en el informe técnico:
--
--   REPUESTO    la pieza se compra y se instala
--   REPARACION  el equipo sale a un taller
--   GARANTIA    se reclama al fabricante o al proveedor
--   BAJA        el equipo no tiene arreglo razonable y se propone darlo de baja
--
-- El plazo mide EL VEREDICTO, no la reparación completa. Una garantía puede
-- tardar semanas y eso no es un incumplimiento; lo que no puede pasar es que a
-- las 72 horas nadie haya decidido por cuál de las cuatro vías va. Esa
-- distinción es la razón de que haya dos columnas de fecha y no una.
--
-- ----------------------------------------------------------------------------
-- POR QUE TABLA APARTE Y NO COLUMNAS EN `casos_gestion`
--
-- Un caso puede dejar dos equipos sin concluir, con dos veredictos y dos
-- plazos distintos. Con columnas en `casos_gestion` el segundo pisa al primero.
-- Y la insistencia es un hecho que se repite en el tiempo: eso es una fila por
-- vez, no un campo que se sobrescribe.
-- ============================================================================


-- ----------------------------------------------------------------------------
-- 1. El pendiente: un equipo que quedó sin concluir.
--
-- I-9 — la UNIQUE KEY va sobre la clave de negocio desde el CREATE TABLE, no
-- como parche. Aquí es (aviso, activo_fijo): un equipo, de un caso, tiene UN
-- pendiente. Sin esto, el técnico que pulsa dos veces sin señal —que es
-- exactamente lo que pasa cuando la pantalla no responde— abre dos pendientes,
-- con dos relojes, y el tablero cuenta doble.
--
-- `activo_fijo` va con cadena vacía en vez de NULL porque en MySQL dos NULL no
-- colisionan en una UNIQUE KEY: con NULL, el pendiente sin equipo identificado
-- se duplicaría en cada intento.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pendientes (
    pendiente_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,

    aviso           VARCHAR(20)  NOT NULL
      COMMENT 'El caso de SAP. No es FK: `casos_gestion` se puebla al primer toque y el catálogo de casos vive en un JSON que se reescribe entero en cada barrido.',
    zona            ENUM('UIO','LARB','CNLJ','OTRA') NULL,
    local_codigo    VARCHAR(12)  NULL,
    cadena          VARCHAR(40)  NULL
      COMMENT 'Se copia del maestro de locales al crear el pendiente, NUNCA se deriva del prefijo del código (J018EC es Cajun y no Juan Valdez). Está aquí para poder reportar por cliente cuando el sistema atienda a más de una cadena.',

    activo_fijo     VARCHAR(60)  NOT NULL DEFAULT ''
      COMMENT 'El equipo. Vacío y no NULL: dos NULL no colisionan en una UNIQUE KEY y el pendiente sin equipo se duplicaría.',
    equipo_desc     VARCHAR(160) NULL COMMENT 'Cómo lo llama el técnico: "freidora 2", "cámara de frío".',

    -- La bandera que enciende el reloj.
    deshabilitado   TINYINT(1)   NOT NULL DEFAULT 0
      COMMENT 'El equipo quedó FUERA DE SERVICIO. Es lo que dispara el plazo de 48 horas para el veredicto. Sin esta bandera todo pendiente sería igual de urgente, que es lo mismo que ninguno: hay repuestos que pueden esperar porque el equipo sigue operando.',

    diagnostico     VARCHAR(600) NOT NULL
      COMMENT 'Qué encontró el técnico y por qué no pudo concluir. Es obligatorio: la premisa de INDUSTEC es que una intervención concluye el trabajo, así que dejar un equipo sin concluir exige decir por qué, y ese texto es lo que sostiene el veredicto.',

    -- --- El veredicto: las cuatro salidas, y su reloj -----------------------
    via             ENUM('SIN_VEREDICTO','REPUESTO','REPARACION','GARANTIA','BAJA')
                    NOT NULL DEFAULT 'SIN_VEREDICTO'
      COMMENT 'Las cuatro vías del negocio. SIN_VEREDICTO es el estado en que corre el plazo de 48 h: nadie ha decidido todavía por dónde va.',
    veredicto_por   INT UNSIGNED NULL,
    veredicto_en    DATETIME     NULL
      COMMENT 'Cuándo se decidió la vía. Contra `abierto_en` sale el indicador que de verdad importa: cuántos veredictos se dieron dentro de las 48 horas.',
    veredicto_nota  VARCHAR(600) NULL,

    estado          ENUM(
                      'SIN_VEREDICTO',
                      'COTIZANDO','COMPRADO','EN_BODEGA','ENTREGADO',
                      'EN_TALLER','DEVUELTO_TALLER',
                      'GARANTIA_RECLAMADA','GARANTIA_APROBADA','GARANTIA_NEGADA',
                      'BAJA_PROPUESTA','BAJA_APROBADA',
                      'RESUELTO','CANCELADO'
                    ) NOT NULL DEFAULT 'SIN_VEREDICTO'
      COMMENT 'El paso concreto dentro de la vía elegida. Es la unión de los cuatro recorridos; qué transiciones son legales lo impone `Repuestos::mover()` según la vía, porque un ENUM no puede expresar "esto solo vale si via=GARANTIA".',

    parte           VARCHAR(160) NULL COMMENT 'Qué pieza, cuando la vía es REPUESTO.',
    cantidad        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    tercero         VARCHAR(120) NULL COMMENT 'El taller o el proveedor, cuando la vía es REPARACION o GARANTIA.',

    abierto_por     INT UNSIGNED NOT NULL,
    abierto_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
      COMMENT 'Cuándo el técnico dejó el equipo sin concluir. Es el cero del reloj de 48 horas.',

    prometido_para  DATE         NULL
      COMMENT 'Lo que la administración se compromete a cumplir. Es SU dato, no una estimación del sistema: si está vacío, la pantalla lo dice en vez de inventarlo (I-7).',

    gestionado_por  INT UNSIGNED NULL,
    gestionado_en   DATETIME     NULL,
    cerrado_en      DATETIME     NULL,
    nota_cierre     VARCHAR(400) NULL,

    -- Redundante a propósito: permite ordenar y filtrar por insistencia sin un
    -- COUNT correlacionado en cada carga de la pantalla. Lo mantiene el trigger.
    insistencias    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ultima_nota_en  DATETIME     NULL,

    PRIMARY KEY (pendiente_id),
    UNIQUE KEY uq_pendiente (aviso, activo_fijo),
    KEY idx_pen_reloj  (via, deshabilitado, abierto_en),
    KEY idx_pen_estado (estado, zona),
    KEY idx_pen_zona   (zona, via, deshabilitado),
    KEY idx_pen_quien  (abierto_por, estado),
    CONSTRAINT fk_pen_abre     FOREIGN KEY (abierto_por)    REFERENCES usuarios(usuario_id),
    CONSTRAINT fk_pen_veredic  FOREIGN KEY (veredicto_por)  REFERENCES usuarios(usuario_id),
    CONSTRAINT fk_pen_gestiona FOREIGN KEY (gestionado_por) REFERENCES usuarios(usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Equipos que quedaron sin concluir. Clave de negocio (aviso, activo_fijo): un equipo de un caso tiene UN pendiente. El reloj de 48 h corre mientras via=SIN_VEREDICTO.';


-- ----------------------------------------------------------------------------
-- 2. El hilo: recordatorios, respuestas y cambios de estado.
--
-- ESTA TABLA NO LLEVA UNIQUE KEY DE NEGOCIO, y es la excepción razonada a I-9.
-- Cada fila es un hecho distinto en un momento distinto: insistir dos veces por
-- el mismo pendiente no es un duplicado, es exactamente la información que hoy
-- se pierde en WhatsApp. Una UNIQUE KEY aquí impediría registrar la segunda
-- insistencia, que es justo el dato que sostiene la respuesta a Grupo KFC.
--
-- El doble envío accidental —el técnico pulsa dos veces porque la pantalla no
-- responde— se corta en la aplicación comparando contra la última nota del
-- mismo usuario en los últimos 90 segundos. Es un problema de interfaz y se
-- resuelve en la interfaz; convertirlo en restricción del esquema costaría
-- perder insistencias legítimas.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pendiente_notas (
    nota_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pendiente_id INT UNSIGNED NOT NULL,
    usuario_id   INT UNSIGNED NOT NULL,

    tipo         ENUM('RECORDATORIO','RESPUESTA','VEREDICTO','CAMBIO_ESTADO') NOT NULL DEFAULT 'RECORDATORIO'
      COMMENT 'RECORDATORIO lo escribe quien espera. RESPUESTA quien gestiona. VEREDICTO y CAMBIO_ESTADO los deja el sistema al mover el pendiente, para que el hilo se lea completo sin cruzarlo con la bitácora.',

    texto        VARCHAR(600) NOT NULL,
    urgente      TINYINT(1)   NOT NULL DEFAULT 0
      COMMENT 'Se guarda aparte del texto porque hay que poder CONTAR los urgentes sin leerlos.',

    estado_antes ENUM('SIN_VEREDICTO','COTIZANDO','COMPRADO','EN_BODEGA','ENTREGADO',
                      'EN_TALLER','DEVUELTO_TALLER','GARANTIA_RECLAMADA','GARANTIA_APROBADA',
                      'GARANTIA_NEGADA','BAJA_PROPUESTA','BAJA_APROBADA','RESUELTO','CANCELADO') NULL,
    estado_desp  ENUM('SIN_VEREDICTO','COTIZANDO','COMPRADO','EN_BODEGA','ENTREGADO',
                      'EN_TALLER','DEVUELTO_TALLER','GARANTIA_RECLAMADA','GARANTIA_APROBADA',
                      'GARANTIA_NEGADA','BAJA_PROPUESTA','BAJA_APROBADA','RESUELTO','CANCELADO') NULL,

    creado_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (nota_id),
    KEY idx_nota_pen (pendiente_id, creado_en),
    KEY idx_nota_urg (urgente, creado_en),
    CONSTRAINT fk_nota_pen FOREIGN KEY (pendiente_id) REFERENCES pendientes(pendiente_id) ON DELETE CASCADE,
    CONSTRAINT fk_nota_usr FOREIGN KEY (usuario_id)   REFERENCES usuarios(usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Bitácora del pendiente. Sin UNIQUE de negocio a propósito: insistir dos veces es el dato, no un duplicado.';


-- ----------------------------------------------------------------------------
-- 3. Mantener al día el contador de insistencias.
--
-- Con triggers y no desde PHP porque la nota se crea desde tres sitios: la app
-- del técnico, la pantalla del jefe y los movimientos del sistema. Con la
-- cuenta en PHP, el sitio que se olvide de actualizarla deja el tablero
-- mintiendo, y un tablero que miente es peor que no tenerlo.
-- ----------------------------------------------------------------------------
DROP TRIGGER IF EXISTS tr_pnota_insiste;
CREATE TRIGGER tr_pnota_insiste AFTER INSERT ON pendiente_notas
FOR EACH ROW
  UPDATE pendientes
     SET insistencias   = insistencias + IF(NEW.tipo = 'RECORDATORIO', 1, 0),
         ultima_nota_en = NEW.creado_en
   WHERE pendiente_id = NEW.pendiente_id;


-- ----------------------------------------------------------------------------
-- 4. El caso que espera un pendiente es un estado del caso, no un limbo.
--
-- El valor va AL FINAL del ENUM: agregarlo en medio cambiaría el orden y el
-- significado de los existentes en cualquier comparación por índice.
--
-- CUIDADO CON `Reconciliar.php`, que cierra por falta de atención los casos con
-- más de una semana sin informe. Un caso en ESPERA_REPUESTO **sí** fue
-- atendido: el técnico fue, diagnosticó y dejó el equipo documentado. Tiene que
-- quedar fuera de ese cierre Y de la marca automática de ATENDIDO, porque si no
-- la administradora lo cerraría en SAP con el equipo todavía parado. Por eso el
-- estado está en `Reconciliar::INTOCABLES`.
-- ----------------------------------------------------------------------------
ALTER TABLE casos_gestion
  MODIFY COLUMN estado
    ENUM('NUEVO','ASIGNADO','EN_REVISION','RESUELTO','NO_COMPETE','ATENDIDO',
         'CERRADO_SIN_ATENCION','ESPERA_REPUESTO')
    NOT NULL DEFAULT 'NUEVO'
    COMMENT 'Ciclo de vida del caso. ESPERA_REPUESTO se agregó en la 007: el técnico fue y el equipo quedó sin concluir. NO es falta de atención y el automatismo no lo pisa.';


-- ----------------------------------------------------------------------------
-- 5. La orden capturada sin señal.
--
-- El técnico llena la orden en un local sin cobertura. El navegador la guarda y
-- la manda sola en cuanto vuelve la red. Esta tabla es donde aterriza.
--
-- LA IDEMPOTENCIA ES EL PUNTO ENTERO. Sin señal el reintento es la norma: la
-- red vuelve a medias, el envío sale, la respuesta no llega, y el navegador
-- reintenta. Sin una clave que genere el CLIENTE antes de enviar, ese reintento
-- crea una segunda orden — y una orden duplicada es un correlativo quemado y un
-- segundo PDF a Grupo KFC. Por eso `envio_uuid` lo genera el celular al guardar
-- el borrador, viaja con cada intento, y es UNIQUE aquí.
--
-- LO QUE ESTA TABLA NO HACE, Y HAY QUE DECIRLO (I-7): no genera el PDF ni manda
-- el correo. Eso vive en la migración 003 (`correlativos` con reserva atómica y
-- `email_queue`), que sigue sin aplicarse. Hasta entonces la orden queda aquí,
-- completa y a salvo, en estado RECIBIDA, y la pantalla lo dice con esas
-- palabras en vez de fingir que ya salió.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ot_capturadas (
    captura_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,

    envio_uuid     CHAR(36)     NOT NULL
      COMMENT 'Lo genera el celular ANTES del primer intento y no cambia entre reintentos. Es lo único que distingue "la mandó dos veces" de "se reintentó el mismo envío".',

    usuario_id     INT UNSIGNED NOT NULL
      COMMENT 'Quién la emitió. Sale de la SESION, nunca de un desplegable: el técnico ya no elige su nombre, y por eso la firma es trazable.',

    aviso          VARCHAR(20)  NULL
      COMMENT 'NULL cuando la orden nace sin aviso SAP (la emergencia durante la visita). Queda como tarea de regularización de la administración.',
    local_codigo   VARCHAR(12)  NULL,
    zona           ENUM('UIO','LARB','CNLJ','OTRA') NULL,
    cadena         VARCHAR(40)  NULL,
    modulo         ENUM('CORRECTIVO','PREVENTIVO') NULL,
    concluida      TINYINT(1)   NULL
      COMMENT 'Si la intervención cerró el trabajo. Es el indicador central del servicio: la premisa es que una visita concluye, y lo que no concluye tiene que tener su fila en `pendientes`.',

    carga          JSON         NOT NULL
      COMMENT 'La orden entera tal como la llenó el técnico. Se guarda cruda además de por columnas porque el formulario va a cambiar y lo capturado tiene que poder releerse siempre como se capturó.',

    capturada_en   DATETIME     NOT NULL
      COMMENT 'Cuándo la llenó el técnico en su celular. NO es cuándo llegó: entre las dos puede haber horas sin cobertura, y para medir tiempos de atención vale la primera.',
    recibida_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    estado         ENUM('RECIBIDA','PROCESADA','RECHAZADA') NOT NULL DEFAULT 'RECIBIDA',
    motivo_rechazo VARCHAR(300) NULL,
    id_industec    VARCHAR(60)  NULL
      COMMENT 'El nombre canónico asignado al procesarla. Vacío mientras la 003 no esté aplicada.',

    PRIMARY KEY (captura_id),
    UNIQUE KEY uq_captura_envio (envio_uuid),
    KEY idx_cap_estado (estado, recibida_en),
    KEY idx_cap_quien  (usuario_id, capturada_en),
    CONSTRAINT fk_cap_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Ordenes que llegan del formulario, incluidas las llenadas sin señal. Clave de negocio envio_uuid, generada por el celular: es lo que hace que un reintento no cree una orden nueva.';


-- ----------------------------------------------------------------------------
-- 6. Permisos.
--
-- `pendientes.ver` lo tienen los cuatro roles; el ALCANCE lo pone la consulta y
-- no el permiso — el técnico ve los suyos, el jefe los de su zona, la
-- administración los tres. Es la misma separación de la 001: un permiso dice
-- "puede", el alcance dice "sobre qué filas".
-- ----------------------------------------------------------------------------
INSERT INTO permisos (codigo, modulo, nombre, descripcion) VALUES
  ('repuestos.ver',       'pendientes', 'Ver pendientes',
   'Ver los equipos sin concluir que estan dentro de su alcance'),
  ('repuestos.pedir',     'pendientes', 'Abrir pendiente e insistir',
   'Registrar que un equipo quedo sin concluir, y mandar recordatorios sobre el'),
  ('repuestos.veredicto', 'pendientes', 'Dar el veredicto de las 48 h',
   'Decidir la via: repuesto, reparacion, garantia o baja del equipo'),
  ('repuestos.gestionar', 'pendientes', 'Gestionar el pendiente',
   'Mover el estado dentro de la via, comprometer fecha y responder al hilo')
ON DUPLICATE KEY UPDATE modulo = VALUES(modulo), nombre = VALUES(nombre),
                        descripcion = VALUES(descripcion);

INSERT INTO rol_permisos (rol, permiso) VALUES
  ('SUPERADMIN','repuestos.ver'), ('SUPERADMIN','repuestos.pedir'),
  ('SUPERADMIN','repuestos.veredicto'), ('SUPERADMIN','repuestos.gestionar'),
  ('ADMIN','repuestos.ver'), ('ADMIN','repuestos.pedir'),
  ('ADMIN','repuestos.veredicto'), ('ADMIN','repuestos.gestionar'),
  -- El jefe de zona SI da veredicto: es quien está en el terreno, conoce el
  -- equipo y su misión es que ninguno pase de 48 horas sin decisión. Lo que no
  -- tiene es `gestionar`: no compra, no compromete fecha con el proveedor ni
  -- da de baja un activo del cliente. Decide la vía; ejecutarla es de la
  -- administración.
  ('JEFE_ZONA','repuestos.ver'), ('JEFE_ZONA','repuestos.pedir'),
  ('JEFE_ZONA','repuestos.veredicto'),
  ('TECNICO','repuestos.ver'), ('TECNICO','repuestos.pedir')
ON DUPLICATE KEY UPDATE rol = rol;


-- ============================================================================
-- 8. NOVEDADES DETECTADAS EN LA VISITA
--
-- ----------------------------------------------------------------------------
-- DE DONDE SALE ESTA TABLA
--
-- El técnico entra a hacer un preventivo y ve cosas que no son su preventivo:
-- una plancha que ya está fallando y va a necesitar correctivo, un tomacorriente
-- recalentado, un extractor que no jala, un desagüe tapado bajo la freidora.
-- Nada de eso es la orden que fue a hacer, y hasta hoy termina —cuando termina—
-- en una foto por WhatsApp o en un comentario de pasillo.
--
-- Lo que se pierde ahí es doble:
--
--   1. El correctivo que se veía venir. Cuando el equipo se para de verdad, es
--      una urgencia de 48 horas que se pudo haber planificado con semanas.
--   2. Lo que NO le toca a INDUSTEC. Una instalación eléctrica mal hecha, una
--      ventilación insuficiente o un desagüe tapado hacen fallar equipos una y
--      otra vez, y sin registro INDUSTEC queda como la que no sabe reparar,
--      cuando la causa es de otra área.
--
-- ----------------------------------------------------------------------------
-- EL RECORRIDO, Y DONDE ESTA LA DECISION
--
--   El técnico REPORTA lo que vio, con su riesgo y de quién cree que es.
--   -> La administradora y el jefe de zona lo VEN y DECIDEN cómo reportarlo.
--   -> Si procede, se crea el requerimiento EN SAP y el número vuelve aquí.
--
-- El sistema NO crea nada en SAP y no tiene por qué: el aviso lo abre Grupo KFC
-- en su propio sistema. Lo que sí hace es no dejar que la novedad se pierda
-- entre la visita y esa decisión, y guardar el número de aviso cuando exista,
-- para poder demostrar que se avisó y cuándo.
--
-- LA CLASIFICACION LA PROPONE EL TECNICO, NO LA DECIDE. `responsable` es lo
-- que él cree; el veredicto es del jefe o de la administración. Es la misma
-- regla de las alertas del buzón: el sistema marca, la persona resuelve.
-- ============================================================================
CREATE TABLE IF NOT EXISTS novedades (
    novedad_id     INT UNSIGNED NOT NULL AUTO_INCREMENT,

    novedad_uuid   CHAR(36)     NOT NULL
      COMMENT 'Lo genera el celular al escribir la novedad, antes del primer envío. Es la clave de idempotencia: sin señal el reintento es la norma, y sin esto la misma novedad entraría dos veces. NO se usa (aviso, equipo, tipo) como clave porque en una misma visita puede haber dos hallazgos eléctricos distintos y legítimos.',

    -- De dónde salió
    aviso_origen   VARCHAR(20)  NULL
      COMMENT 'El caso durante el cual se detectó. NULL si se reportó fuera de una orden.',
    ot_origen      VARCHAR(60)  NULL COMMENT 'La OT en la que se documentó, cuando ya existe.',
    modulo_origen  ENUM('CORRECTIVO','PREVENTIVO') NULL
      COMMENT 'La mayoría nacen en PREVENTIVO: es la visita en la que hay tiempo de mirar alrededor.',

    local_codigo   VARCHAR(12)  NULL,
    zona           ENUM('UIO','LARB','CNLJ','OTRA') NULL,
    cadena         VARCHAR(40)  NULL
      COMMENT 'Del maestro de locales, jamas del prefijo del codigo. Permite reportar por cliente cuando el sistema atienda a mas de una cadena.',

    -- Qué se vio
    tipo           ENUM('EQUIPO_CORRECTIVO','ELECTRICO','VENTILACION','DESAGUE',
                        'AGUA','GAS','CONSTRUCTIVO','REFRIGERACION','SEGURIDAD','OTRO')
                   NOT NULL DEFAULT 'EQUIPO_CORRECTIVO'
      COMMENT 'Las areas que la operacion nombro como las que hacen fallar equipos. EQUIPO_CORRECTIVO es el hallazgo que le toca a INDUSTEC; el resto son de otras areas del local y suelen ser la causa de fallas repetidas.',

    activo_fijo    VARCHAR(60)  NULL,
    equipo_desc    VARCHAR(160) NULL COMMENT 'Como lo llama el tecnico: "freidora 2", "campana de la plancha".',
    descripcion    VARCHAR(800) NOT NULL COMMENT 'Que vio, en sus palabras. Es la evidencia con la que se le pide el aviso a KFC.',

    riesgo         ENUM('BAJO','MEDIO','ALTO') NOT NULL DEFAULT 'MEDIO'
      COMMENT 'ALTO = va a parar un equipo o es un riesgo para las personas. Ordena la lista del jefe de zona: es lo que decide que se mire hoy y no la semana que viene.',

    responsable_prop ENUM('INDUSTEC','CLIENTE','TERCERO') NOT NULL DEFAULT 'INDUSTEC'
      COMMENT 'De quien CREE el tecnico que es. Es una propuesta, no un veredicto: quien resuelve es el jefe o la administracion. Misma regla que las alertas de alcance del buzon.',

    -- Qué se decidió
    estado         ENUM('REPORTADA','EN_REVISION','DERIVADA_SAP','ASUMIDA_INDUSTEC',
                        'DESCARTADA','RESUELTA')
                   NOT NULL DEFAULT 'REPORTADA'
      COMMENT 'DERIVADA_SAP: se le pidio el aviso a KFC y ya tiene numero. ASUMIDA_INDUSTEC: entra en nuestra planificacion sin aviso nuevo. DESCARTADA: se reviso y no procedia, con motivo.',

    aviso_sap      VARCHAR(20)  NULL
      COMMENT 'El numero que Grupo KFC creo a partir de esta novedad. Es la prueba de que el aviso se dio y de que se convirtio en trabajo. Vacio mientras no exista: el sistema no lo inventa (I-7).',

    veredicto_por  INT UNSIGNED NULL,
    veredicto_en   DATETIME     NULL,
    veredicto_nota VARCHAR(600) NULL,

    reportada_por  INT UNSIGNED NOT NULL,
    reportada_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    detectada_en   DATETIME     NULL
      COMMENT 'Cuando la vio el tecnico, en su celular. Puede ser horas antes de que llegue, si el local no tenia senal.',

    PRIMARY KEY (novedad_id),
    UNIQUE KEY uq_novedad (novedad_uuid),
    KEY idx_nov_bandeja (estado, riesgo, reportada_en),
    KEY idx_nov_zona    (zona, estado),
    KEY idx_nov_local   (local_codigo, tipo),
    KEY idx_nov_quien   (reportada_por, reportada_en),
    CONSTRAINT fk_nov_reporta  FOREIGN KEY (reportada_por) REFERENCES usuarios(usuario_id),
    CONSTRAINT fk_nov_veredic  FOREIGN KEY (veredicto_por) REFERENCES usuarios(usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Lo que el tecnico ve durante una visita y no es su orden: el correctivo que viene, y lo de otras areas (electrico, ventilacion, desague) que hace fallar los equipos. La clave de negocio es el UUID del celular, para que el reintento sin senal no duplique.';


-- ----------------------------------------------------------------------------
-- 9. Permisos de novedades.
--
-- El JEFE_ZONA gestiona: es quien conoce el local y decide como se reporta. La
-- administracion tambien, porque es la que habla con Grupo KFC. El tecnico
-- reporta y ve las suyas; no decide, igual que no decide un veredicto de caso.
-- ----------------------------------------------------------------------------
INSERT INTO permisos (codigo, modulo, nombre, descripcion) VALUES
  ('novedades.ver',       'novedades', 'Ver novedades',
   'Ver las novedades detectadas dentro de su alcance'),
  ('novedades.reportar',  'novedades', 'Reportar novedad',
   'Registrar lo que se vio en la visita y no era la orden'),
  ('novedades.gestionar', 'novedades', 'Resolver novedades',
   'Decidir como se reporta, anotar el aviso SAP creado, o descartarla con motivo')
ON DUPLICATE KEY UPDATE modulo = VALUES(modulo), nombre = VALUES(nombre),
                        descripcion = VALUES(descripcion);

INSERT INTO rol_permisos (rol, permiso) VALUES
  ('SUPERADMIN','novedades.ver'), ('SUPERADMIN','novedades.reportar'), ('SUPERADMIN','novedades.gestionar'),
  ('ADMIN','novedades.ver'),      ('ADMIN','novedades.reportar'),      ('ADMIN','novedades.gestionar'),
  ('JEFE_ZONA','novedades.ver'),  ('JEFE_ZONA','novedades.reportar'),  ('JEFE_ZONA','novedades.gestionar'),
  ('TECNICO','novedades.ver'),    ('TECNICO','novedades.reportar')
ON DUPLICATE KEY UPDATE rol = rol;


-- ============================================================================
-- VERIFICACION de las novedades, despues de aplicar:
--
--   SHOW CREATE TABLE novedades\G
--     -> UNIQUE KEY `uq_novedad` (`novedad_uuid`)
--
--   -- Idempotencia: el mismo envio dos veces no duplica.
--   INSERT INTO novedades (novedad_uuid,descripcion,reportada_por)
--     VALUES ('11111111-1111-1111-1111-111111111111','prueba',1)
--     ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion);
--   INSERT INTO novedades (novedad_uuid,descripcion,reportada_por)
--     VALUES ('11111111-1111-1111-1111-111111111111','prueba',1)
--     ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion);
--   SELECT COUNT(*) FROM novedades WHERE novedad_uuid='11111111-1111-1111-1111-111111111111';
--     -- 1
--   DELETE FROM novedades WHERE novedad_uuid='11111111-1111-1111-1111-111111111111';
--
--   -- El indicador que le interesa a la gerencia: cuanto de lo que hace fallar
--   -- los equipos NO es de INDUSTEC, y cuanto de eso llego a tener aviso.
--   SELECT tipo, responsable_prop, COUNT(*) n,
--          SUM(aviso_sap IS NOT NULL) con_aviso
--     FROM novedades GROUP BY tipo, responsable_prop ORDER BY n DESC;
--
--   SELECT rol, COUNT(*) FROM rol_permisos WHERE permiso LIKE 'novedades%' GROUP BY rol;
--     -- SUPERADMIN 3 · ADMIN 3 · JEFE_ZONA 3 · TECNICO 2
-- ============================================================================


-- ============================================================================
-- VERIFICACION después de aplicar. Pegar la salida literal como evidencia.
--
--   -- 1. La clave de negocio existe desde el CREATE TABLE, no como parche (I-9):
--   SHOW CREATE TABLE pendientes\G
--     -> UNIQUE KEY `uq_pendiente` (`aviso`,`activo_fijo`)
--   SHOW CREATE TABLE ot_capturadas\G
--     -> UNIQUE KEY `uq_captura_envio` (`envio_uuid`)
--
--   -- 2. El estado nuevo entró AL FINAL del ENUM del caso:
--   SHOW COLUMNS FROM casos_gestion LIKE 'estado';
--     -> ...,'CERRADO_SIN_ATENCION','ESPERA_REPUESTO')
--
--   -- 3. Idempotencia: abrir dos veces el mismo pendiente no duplica.
--   INSERT INTO pendientes (aviso,diagnostico,abierto_por) VALUES ('99999999','prueba',1)
--     ON DUPLICATE KEY UPDATE diagnostico=VALUES(diagnostico);
--   INSERT INTO pendientes (aviso,diagnostico,abierto_por) VALUES ('99999999','prueba',1)
--     ON DUPLICATE KEY UPDATE diagnostico=VALUES(diagnostico);
--   SELECT COUNT(*) FROM pendientes WHERE aviso='99999999';   -- debe dar 1
--   DELETE FROM pendientes WHERE aviso='99999999';
--
--   -- 4. El contador de insistencias lo mantiene el trigger, no PHP:
--   SELECT p.pendiente_id, p.insistencias,
--          (SELECT COUNT(*) FROM pendiente_notas n
--            WHERE n.pendiente_id = p.pendiente_id AND n.tipo='RECORDATORIO') AS real_
--     FROM pendientes p HAVING insistencias <> real_;          -- 0 filas
--
--   -- 5. La reconciliación NO cierra ni marca atendido lo que espera:
--   SELECT COUNT(*) FROM casos_gestion
--    WHERE estado IN ('CERRADO_SIN_ATENCION','ATENDIDO')
--      AND aviso IN (SELECT aviso FROM pendientes WHERE estado NOT IN ('RESUELTO','CANCELADO'));
--     -- 0 filas. Si da más, `Reconciliar.php` está pisando pendientes vivos.
--
--   -- 6. EL INDICADOR DEL NEGOCIO: veredictos dentro de las 48 horas.
--   SELECT
--     SUM(veredicto_en IS NOT NULL
--         AND TIMESTAMPDIFF(HOUR, abierto_en, veredicto_en) <= 48) AS a_tiempo,
--     SUM(veredicto_en IS NOT NULL
--         AND TIMESTAMPDIFF(HOUR, abierto_en, veredicto_en) >  48) AS tarde,
--     SUM(via = 'SIN_VEREDICTO'
--         AND deshabilitado = 1
--         AND abierto_en < DATE_SUB(NOW(), INTERVAL 48 HOUR))      AS vencidos_ahora
--   FROM pendientes;
--
--   -- 7. Los permisos quedaron repartidos como dice el diseño:
--   SELECT rol, COUNT(*) FROM rol_permisos WHERE permiso LIKE 'repuestos%' GROUP BY rol;
--     -- SUPERADMIN 4 · ADMIN 4 · JEFE_ZONA 3 · TECNICO 2
-- ============================================================================
