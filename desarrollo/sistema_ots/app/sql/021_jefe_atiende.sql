-- ============================================================================
-- 021_jefe_atiende.sql — El jefe de zona también atiende casos y emite su orden.
--
-- Pedido de INDUSTEC del 2026-09-24: «los mismos jefes de zona se pueden
-- asignar casos y atenderlos». Asignarse ya podían (Casos::tecnicosAsignables()
-- incluye JEFE_ZONA); atender no: su rol no tenía `ots.crear`, así que ni veían
-- «Mis órdenes» ni el servidor les aceptaba la orden.
--
-- Solo agrega una fila a rol_permisos. No crea ni borra nada, no toca datos
-- del cliente y se puede aplicar dos veces sin efecto (ON DUPLICATE KEY).
-- Se deshace con: DELETE FROM rol_permisos WHERE rol='JEFE_ZONA' AND permiso='ots.crear';
-- ============================================================================

INSERT INTO rol_permisos (rol, permiso) VALUES ('JEFE_ZONA', 'ots.crear')
ON DUPLICATE KEY UPDATE rol = rol;

-- El libro de migraciones lo escribe `aplicar_sql.php` al terminar, con el
-- sha256 del archivo: anotarlo aquí a mano dejaría una huella vacía.


-- ============================================================================
-- COMPROBACION (se pega la salida literal al cerrar la tarea):
--
--   SELECT COUNT(*) FROM rol_permisos WHERE rol = 'JEFE_ZONA' AND permiso = 'ots.crear';  -> 1
--   php verificar_esquema.php   -> TODO OK (JEFE_ZONA cuenta un permiso más)
-- ============================================================================
