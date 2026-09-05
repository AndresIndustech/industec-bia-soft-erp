-- 002 · Campos de la ORDEN SAP que faltaban en avisos_sap
--
-- Por que: el plan de seguimiento tiene dos columnas que no se podian llenar
-- con lo importado y quedaban en NINGUNO o vacias:
--
--   ESTATUS SAP  -> vive en "Estatus 2 de la Orden" del export (REDE, MEDE,
--                   APRO, MSOL, IMPO, AUTO, RINC...). Lo que si estaba
--                   importado, `estatus_aviso`, es otro campo y otro
--                   vocabulario ("MECE ORAS") -- ponerlo ahi habria sido
--                   inventar dato (I-7).
--   EQUIPO       -> vive en "Denominacion objeto", con su numero de activo en
--                   "Equipo". La base solo tenia lo que el tecnico escribio a
--                   mano en la orden ("FREIDORA" contra "FREIDORA DE PAPAS").
--
-- MariaDB acepta IF NOT EXISTS, asi que esta migracion es idempotente y no
-- choca con una base recien creada desde 001, que ya trae las columnas.

ALTER TABLE avisos_sap
    ADD COLUMN IF NOT EXISTS estatus_aviso_2 VARCHAR(60) NULL
        COMMENT 'Estatus 2 del Aviso (APRO...). Mismo vocabulario que estatus_orden_2'
        AFTER estatus_aviso,
    ADD COLUMN IF NOT EXISTS estatus_orden VARCHAR(80) NULL
        COMMENT 'Estatus de la Orden SAP (CTEC DMNV KKMP NLIQ PREC...)'
        AFTER estatus_aviso_2,
    ADD COLUMN IF NOT EXISTS estatus_orden_2 VARCHAR(60) NULL
        COMMENT 'Estatus 2 de la Orden: es la columna ESTATUS SAP del plan de seguimiento'
        AFTER estatus_orden,
    ADD COLUMN IF NOT EXISTS equipo_sap VARCHAR(20) NULL
        COMMENT 'Numero de equipo/activo en SAP'
        AFTER estatus_orden_2,
    ADD COLUMN IF NOT EXISTS equipo_denominacion VARCHAR(120) NULL
        COMMENT 'Denominacion objeto: es la columna EQUIPO del plan de seguimiento'
        AFTER equipo_sap;
