-- ============================================================================
-- 002 - La administradora también crea usuarios, pero no de cualquier rango.
--
-- Decisión de Andrés Basantes, 2026-09-08: cada persona entra con su propio
-- usuario y contraseña, y la creación de usuarios de técnicos la pueden hacer
-- la administradora y los superusuarios.
--
-- POR QUE UN PERMISO NUEVO Y NO REUSAR `usuarios.gestionar`:
-- Si a la administradora se le da `usuarios.gestionar`, puede crear otro
-- SUPERADMIN — y con eso se asciende a sí misma. Es escalada de privilegios, y
-- es de los errores más repetidos en sistemas de permisos.
--
-- La regla, que se aplica ADEMAS en el servidor (Auth::rolesQuePuedeCrear):
--   nadie crea un usuario de rango igual o superior al suyo.
--
--   SUPERADMIN  -> puede crear cualquier rol, incluido otro SUPERADMIN
--   ADMIN       -> solo JEFE_ZONA y TECNICO
--   los demás   -> ninguno
--
-- El permiso solo abre la pantalla; el rango decide qué se puede elegir en
-- ella. Los dos controles viven en el servidor: esconder opciones no protege.
-- ============================================================================

INSERT INTO permisos (codigo, modulo, nombre, descripcion) VALUES
  ('usuarios.operativos', 'usuarios', 'Crear técnicos y jefes de zona',
   'Alta, baja y reinicio de clave de usuarios de rango inferior al propio')
ON DUPLICATE KEY UPDATE
  modulo = VALUES(modulo), nombre = VALUES(nombre), descripcion = VALUES(descripcion);

-- El superadministrador ya tiene todo, pero se declara explícito para que la
-- consulta de verificación cuadre.
INSERT INTO rol_permisos (rol, permiso) VALUES
  ('SUPERADMIN', 'usuarios.operativos'),
  ('ADMIN',      'usuarios.operativos')
ON DUPLICATE KEY UPDATE rol = rol;

-- ============================================================================
-- VERIFICACION:
--   SELECT rol, COUNT(*) FROM rol_permisos GROUP BY rol ORDER BY 2 DESC;
--     -> SUPERADMIN 18 | ADMIN 17 | JEFE_ZONA 10 | TECNICO 4
--
--   La administradora NO debe tener usuarios.gestionar:
--   SELECT COUNT(*) FROM rol_permisos WHERE rol='ADMIN' AND permiso='usuarios.gestionar';
--     -> 0
-- ============================================================================
