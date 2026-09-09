-- ============================================================================
-- 004 - Vigencia de los técnicos: cuándo salió cada uno.
--
-- BASE: industec_ots (el archivo histórico de la estación).
--
-- POR QUE HACE FALTA:
-- `tecnicos` era una FOTO, no un historial: 19 filas, todas con activo = 1, con
-- fecha de ingreso y sin fecha de salida. Sin fecha de salida no se puede
-- responder la única pregunta que importa para juzgar una orden vieja: quién
-- estaba vigente EN LA FECHA de esa orden.
--
-- El padrón que entregó la administración el 2026-09-08
-- (LISTADO DE TECNICOS ACTUALIZADO.xlsx) trae las 40 personas y sus salidas.
--
-- LAS TRES SITUACIONES SON DISTINTAS Y NO SE PUEDEN COLAPSAR:
--
--   fecha_salida IS NULL  y  salida_sin_registro = 0  ->  SIGUE TRABAJANDO
--   fecha_salida = una fecha                          ->  salió ese día
--   fecha_salida IS NULL  y  salida_sin_registro = 1  ->  SALIÓ, no se sabe cuándo
--
-- La tercera es la que obliga a la columna extra. Si "SIN REGISTRO" se guardara
-- como NULL a secas, esas 11 personas parecerían activas y volveríamos a tener
-- el maestro incompleto que causó el problema que esto viene a arreglar.
-- ============================================================================

ALTER TABLE tecnicos
  ADD COLUMN fecha_salida DATE NULL
    COMMENT 'NULL + salida_sin_registro=0 significa que sigue trabajando'
    AFTER fecha_ingreso,
  ADD COLUMN salida_sin_registro TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'El padron dice SIN REGISTRO: ya salio, la fecha no consta'
    AFTER fecha_salida,
  ADD COLUMN ingreso_por_confirmar TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'El padron trae SI en vez de fecha: la administracion no esta segura y la debe confirmar'
    AFTER salida_sin_registro,
  ADD COLUMN fuente_padron VARCHAR(60) NULL
    COMMENT 'De que documento salio la fila, para poder rehacerla'
    AFTER ingreso_por_confirmar;

-- La cédula es la clave natural, pero solo la traen los 19 vigentes: los que ya
-- salieron figuran sin ella. Por eso la UNIQUE KEY existente sobre `cedula`
-- sigue sirviendo (MySQL admite varios NULL) y la carga se hace con
-- DELETE + INSERT, que es lo que corresponde a una tabla que nadie referencia
-- por clave foránea y que se reconstruye entera desde su documento origen.

-- ============================================================================
-- VERIFICACION despues de aplicar y de correr t2_8_padron_tecnicos.py:
--
--   SELECT COUNT(*) FROM tecnicos;                       -> 40
--   SELECT COUNT(*) FROM tecnicos WHERE activo = 1;      -> 19
--   SELECT COUNT(*) FROM tecnicos WHERE salida_sin_registro = 1;  -> 11
--   SELECT COUNT(*) FROM tecnicos WHERE fecha_salida IS NOT NULL; -> 10
--   SELECT COUNT(*) FROM tecnicos WHERE ingreso_por_confirmar = 1;-> 8
--
--   Y la que importa: ningun activo con salida
--   SELECT COUNT(*) FROM tecnicos
--    WHERE activo = 1 AND (fecha_salida IS NOT NULL OR salida_sin_registro = 1);
--     -> 0
-- ============================================================================
