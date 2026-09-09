-- ============================================================================
-- 004 - El caso que YA se atendió: técnico automático y cierre en dos manos.
--
-- BASE: la operativa de Hostinger.
--
-- QUE FALTABA
-- 114 de los 917 casos pendientes ya se atendieron, y de 30 de ellos ya existe
-- la orden de cierre con el nombre del técnico que la firmó. Pedirle a la
-- administradora que «asigne» a alguien que hizo el trabajo hace tres meses es
-- hacerle teclear un dato que el sistema ya sabe.
--
-- EL CIERRE ES DE DOS MANOS, Y ESO NO ES BUROCRACIA
-- Que INDUSTEC emita la orden de cierre y que KFC cierre el caso en SAP son dos
-- hechos distintos, y el correo no avisa el segundo. Si el sistema diera el
-- caso por cerrado al ver la orden, estaría afirmando algo que no le consta y
-- que KFC puede desmentir. Por eso:
--
--   ATENDIDO   INDUSTEC emitió la orden de cierre. Lo pone el sistema solo.
--   RESUELTO   la administradora confirmó que KFC lo cerró en SAP. Lo pone ella.
--
-- Entre los dos hay una espera real, y esa espera es justamente el pendiente
-- que la administradora tiene que trabajar.
--
-- ATENDIDO va al FINAL del ENUM a propósito: agregar un valor en medio corre
-- los demás y cambia el significado de lo ya guardado.
-- ============================================================================

ALTER TABLE casos_gestion
  MODIFY COLUMN estado
    ENUM('NUEVO','ASIGNADO','EN_REVISION','RESUELTO','NO_COMPETE','ATENDIDO')
    NOT NULL DEFAULT 'NUEVO'
    COMMENT 'ATENDIDO lo pone el sistema al ver la orden de cierre; RESUELTO lo pone la administradora',

  ADD COLUMN ot_cierre VARCHAR(60) NULL
    COMMENT 'La orden que cerro el trabajo, p.ej. OT-1430-R007-10334240-UIO'
    AFTER estado,
  ADD COLUMN atendido_en DATETIME NULL
    COMMENT 'Fecha de la orden de cierre, no la de este registro'
    AFTER ot_cierre,
  ADD COLUMN tecnico_auto TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'El tecnico salio del PDF de la orden, no lo asigno una persona'
    AFTER atendido_en;

-- `tecnico_auto` existe para que la pantalla pueda decir la verdad: una cosa es
-- «la administradora se lo asignó» y otra «lo firmó él, lo sacamos del PDF».
-- Sin la marca, las dos se verían igual y nadie sabría cuál revisar.

-- ============================================================================
-- VERIFICACION:
--
--   SHOW COLUMNS FROM casos_gestion LIKE 'estado';
--     -> enum(...,'ATENDIDO')  con ATENDIDO al final
--
--   SELECT COUNT(*) FROM casos_gestion WHERE estado='ATENDIDO';
--     -> 0 antes de la primera reconciliacion; 30 despues
-- ============================================================================
