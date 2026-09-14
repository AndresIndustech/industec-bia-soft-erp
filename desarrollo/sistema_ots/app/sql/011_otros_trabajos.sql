-- ============================================================================
-- 011_otros_trabajos.sql — «Otros trabajos»: lo que INDUSTEC hace para KFC
-- fuera de su área, con la decisión de la administradora.
--
-- Decisión de Andrés del 2026-09-14. INDUSTEC hace soporte técnico de equipos
-- (config/alcance_trabajos.json). En casos excepcionales, por un acuerdo
-- interno con KFC, hace un trabajo fuera de esa área —un constructivo, por
-- ejemplo— y emite su informe: la orden es válida, el trabajo se hizo, y hay
-- que contarlo y reportárselo a KFC como extra. Y cuando KFC manda un caso
-- fuera del área, la administradora decide: lo acepta (hubo acuerdo) o lo
-- cierra pidiendo a KFC que lo derive. SIEMPRE decide ella.
--
-- ES UNA MARCA APARTE DEL ESTADO, no un estado más (elegido por Andrés): el
-- caso sigue su flujo normal —asignar, atendido, cerrado en SAP— y además
-- lleva la marca, con quién decidió, cuándo y el acuerdo. NULL = sin decidir.
-- «No autorizado» deja constancia sin contarlo como extra. Cerrar el caso y
-- pedir la derivación sigue siendo el veredicto «no nos compete».
--
-- Los «otros clientes» (locales o cadenas fuera de KFC) NO van aquí: son otra
-- evaluación y otro reporte, interno y secundario.
--
-- Idempotente (IF NOT EXISTS). Se aplica con:
--     php aplicar_sql.php sql/011_otros_trabajos.sql
-- Comprobación: php verificar_esquema.php, bloque «migracion 011».
-- ============================================================================

ALTER TABLE casos_gestion
  ADD COLUMN IF NOT EXISTS otro_trabajo ENUM('AUTORIZADO','NO_AUTORIZADO') NULL
      COMMENT 'Trabajo fuera del área: lo decide la administración. NULL = sin decidir',
  ADD COLUMN IF NOT EXISTS otro_trabajo_motivo VARCHAR(255) NULL
      COMMENT 'El acuerdo con KFC, o por qué no se autoriza',
  ADD COLUMN IF NOT EXISTS otro_trabajo_por INT(10) UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS otro_trabajo_en DATETIME NULL,
  ADD KEY IF NOT EXISTS idx_gestion_otro (otro_trabajo);
