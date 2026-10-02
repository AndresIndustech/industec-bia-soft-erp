-- ============================================================================
-- 016_alias_locales.sql — Las órdenes sin local, identificadas desde el buzón
-- (T2.28.8, obs. 6 de la revisión con INDUSTEC; D-A).
--
-- EL PROBLEMA, MEDIDO EL 2026-09-30. De 888 órdenes vigentes en el buzón, 2 no
-- resuelven local: SAP las manda como «V090 SUPER AKI LA JOYA GYE» (avisos
-- 10342779 y 10347456) y V090 no está en el maestro. El robot
-- (t2_6_imap_avisos.py) no les adivina el local —bien hecho, I-7—: quedan sin
-- local, con la zona del buzón al que SAP las copió si la hay (las dos, LARB),
-- y la administración solo las veía en una tabla sin nada que hacer con ellas.
-- Resolverlas exigía editar `locales_alias` en la estación a mano.
--
-- LO QUE AGREGA (aditiva, S-3): la decisión de la administración, tomada en el
-- buzón, queda aquí como PROPUESTA: «V090 es el local X» o «V090 no es de
-- nuestras zonas». El robot la recoge en su próxima corrida
-- (`recoger_alias()`), la comprueba contra el maestro de la estación y la
-- aplica en `locales_alias` —o, si es «fuera de alcance», saca esas órdenes del
-- buzón a una lista aparte—, y la marca APLICADO. Si no se puede aplicar (el
-- local no está activo, o la clave ya apunta a otro local) la marca RECHAZADO
-- con el motivo en `nota_robot`, que se ve en el buzón, y no escribe nada de
-- ella (I-10, I-11). El barrido del correo sigue: una decisión imposible no
-- deja fuera del buzón las órdenes nuevas (revisión del 2026-09-30).
--
-- PARA DESHACER UNA DECISIÓN YA APLICADA: se quita el alias en la estación
-- (`DELETE FROM locales_alias WHERE alias_texto = 'V090' AND regla_aplicada =
-- 'ADMIN_BUZON'`). En el barrido siguiente el robot marca la fila RECHAZADO
-- («se quitó en la estación») y vuelve a quedar editable en el buzón. Una
-- decisión «fuera de alcance» aplicada no tiene alias en la estación: se
-- deshace aquí, con `UPDATE locales_alias_propuestos SET estado = 'RECHAZADO',
-- nota_robot = 'deshecha a pedido de ...' WHERE clave = 'V090'`; en el barrido
-- siguiente esas órdenes vuelven a «sin local» y se puede decidir de nuevo.
--
-- LA CLAVE (I-9): la primera palabra del texto de SAP, en mayúsculas y sin nada
-- que no sea letra o número, igual que la usa el robot para resolver el local
-- (`clave(restaurante.split()[0])`). Una palabra, una decisión.
--
-- Idempotente (IF NOT EXISTS, ON DUPLICATE KEY). Se aplica con:
--     php aplicar_sql.php sql/016_alias_locales.sql
-- Comprobación: php verificar_esquema.php, bloque «migracion 016».
-- ============================================================================

CREATE TABLE IF NOT EXISTS locales_alias_propuestos (
  clave         VARCHAR(40)  NOT NULL PRIMARY KEY
                COMMENT 'Primera palabra del texto de SAP normalizada con la misma clave() de t2_6_imap_avisos.py (I-9)',
  texto_sap     VARCHAR(160) NOT NULL COMMENT 'Como lo escribe SAP, para mostrarlo',
  decision      ENUM('LOCAL','FUERA_ALCANCE') NOT NULL,
  local_codigo  VARCHAR(12)  NULL COMMENT 'Obligatorio si decision = LOCAL',
  avisos        VARCHAR(400) NULL COMMENT 'Los avisos que la motivaron, para la bitácora',
  nota          VARCHAR(300) NULL COMMENT 'Obligatoria si decision = FUERA_ALCANCE: el porqué',
  propuesto_por INT UNSIGNED NOT NULL,
  propuesto_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  estado        ENUM('PROPUESTO','APLICADO','RECHAZADO') NOT NULL DEFAULT 'PROPUESTO'
                COMMENT 'PROPUESTO: espera al robot. APLICADO: ya está en locales_alias de la estación o fuera del buzón. RECHAZADO: el robot no la pudo aplicar (motivo en nota_robot)',
  aplicado_en   DATETIME     NULL,
  nota_robot    VARCHAR(300) NULL COMMENT 'Lo que dijo el robot al aplicarla o al no poder',
  CONSTRAINT ck_alias_local CHECK (decision = 'FUERA_ALCANCE' OR local_codigo IS NOT NULL),
  CONSTRAINT fk_alias_prop_por FOREIGN KEY (propuesto_por) REFERENCES usuarios(usuario_id),
  KEY idx_alias_prop_estado (estado, propuesto_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Lo que la administración decidió sobre un local que SAP escribe y el maestro no conoce. La aplica el robot de la estación';

-- El permiso (patrón de la 013): solo administración y superadministradores.
-- A qué local corresponde una orden de KFC no lo decide un técnico ni un jefe.
INSERT INTO permisos (codigo, modulo, nombre, descripcion) VALUES
  ('locales.identificar', 'casos', 'Identificar el local de una orden que SAP escribe distinto',
   'Decir a qué local corresponde el texto de SAP, o que no es de nuestras zonas. Lo aplica el robot en su próxima corrida')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), descripcion = VALUES(descripcion);

INSERT INTO rol_permisos (rol, permiso) VALUES
  ('SUPERADMIN', 'locales.identificar'), ('ADMIN', 'locales.identificar')
ON DUPLICATE KEY UPDATE rol = rol;

-- El libro de migraciones lo escribe `aplicar_sql.php` al terminar.

-- ============================================================================
-- COMPROBACION:
--   SHOW CREATE TABLE locales_alias_propuestos;   -> PRIMARY KEY (clave)
--   SELECT COUNT(*) FROM locales_alias_propuestos; -> 0 (aplicar no decide nada)
--   SELECT rol FROM rol_permisos WHERE permiso = 'locales.identificar';  -> SUPERADMIN, ADMIN
-- ============================================================================
