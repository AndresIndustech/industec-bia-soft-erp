-- ============================================================================
-- 005 - El nombre de usuario vive en el padrón, no se teclea dos veces.
--
-- BASE: industec_ots (la estación).
--
-- EL PROBLEMA QUE CIERRA:
-- `industec_app.usuarios` trae una columna `tecnico_id` documentada como «FK
-- lógica a industec_ots.tecnicos». Todavía no la usa nadie, y hay que dejarla
-- así: **ese enlace se rompería solo**. La carga del padrón es DELETE + INSERT,
-- e InnoDB no reinicia el AUTO_INCREMENT al borrar, así que cada recarga le da
-- ids nuevos a las mismas personas (1..40, luego 41..80). Un `tecnico_id`
-- guardado en Hostinger seguiría apuntando a un número que mañana es de otra
-- persona, en silencio y sin error.
--
-- El enlace estable es el NOMBRE DE USUARIO: `amorales`, `kchimbo`. Es único,
-- lo eligió una persona, y ya existe en la app porque es con lo que inicia
-- sesión. Guardarlo aquí hace que las dos bases coincidan por construcción, en
-- vez de porque alguien lo escribió igual en los dos lados.
--
-- Y no agrega dato personal a Hostinger, que es la dirección contraria a la que
-- fija DECISION_ARQUITECTURA_Y_DATOS.md: la cédula se queda en la estación.
--
-- Solo lo llevan los 19 vigentes. Quien ya salió no tiene usuario ni lo
-- necesita: su historial se sigue leyendo por nombre.
-- ============================================================================

ALTER TABLE tecnicos
  ADD COLUMN usuario VARCHAR(40) NULL
    COMMENT 'Con lo que inicia sesion en la app. NULL en quien ya salio'
    AFTER cedula,
  ADD UNIQUE KEY uq_tecnico_usuario (usuario);

-- La UNIQUE KEY es la que importa: dos personas con el mismo usuario
-- significaría que una entra con la sesión de la otra. MySQL admite varios NULL
-- en una UNIQUE, así que los 21 que ya salieron no colisionan entre sí.

-- ============================================================================
-- VERIFICACION despues de correr t2_8_padron_tecnicos.py:
--
--   SELECT COUNT(*) FROM tecnicos WHERE usuario IS NOT NULL;        -> 19
--   SELECT COUNT(*) FROM tecnicos WHERE activo = 1 AND usuario IS NULL; -> 0
--   SELECT COUNT(DISTINCT usuario) FROM tecnicos WHERE usuario IS NOT NULL; -> 19
-- ============================================================================
