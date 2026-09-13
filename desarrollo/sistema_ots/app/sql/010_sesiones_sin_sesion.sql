-- ============================================================================
-- 010_sesiones_sin_sesion.sql — La denegación sin sesión deja rastro (SEG-20).
--
-- `Auth::exigir()` registra en `sesiones_log` cada petición sin sesión a algo
-- que la exige (una fila por conexión y minuto). El ENUM de eventos no lo
-- admitía: hasta aplicar esto, Auth lo intenta y lo descarta en silencio.
--
-- Idempotente: MODIFY deja la columna igual si ya tiene el valor.
-- Se aplica con:  php aplicar_sql.php sql/010_sesiones_sin_sesion.sql
-- ============================================================================

ALTER TABLE sesiones_log
  MODIFY evento ENUM('INGRESO','SALIDA','RECHAZADO','DESPLAZADO','EXPIRADO','SIN_SESION') NOT NULL;
