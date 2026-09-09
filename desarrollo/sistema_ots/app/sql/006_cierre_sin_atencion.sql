-- ============================================================================
-- 006 - El caso que lleva una semana y nadie tocó.
--
-- BASE: la operativa de Hostinger.
--
-- EL PROBLEMA
-- De los 917 pendientes, 803 no tienen ningún informe de orden. Muchos son de
-- junio. Ahí dentro no hay trabajo por hacer: hay trabajo que **no se hizo**, y
-- mientras siga mezclado con lo vivo, la administradora no puede distinguir lo
-- que hay que repartir hoy de lo que hay que explicarle a KFC.
--
-- LA REGLA, EN PALABRAS DE ANDRES (2026-09-09)
-- Más de una semana desde que se creó, sin informe de atención y sin estar a la
-- espera de repuestos -> se cierra por falta de atención y queda como pendiente
-- de la administradora para regularizar.
--
-- «SIN INFORME» ES LA CONDICION, Y TAMBIEN EXCLUYE LO DE LOS REPUESTOS
-- Un caso a la espera de repuestos SI tiene informe: es una orden abierta que
-- espera la pieza. Al pedir que no exista informe, esos quedan fuera solos. No
-- hace falta una segunda condición, y meterla sobraría y podría contradecir a
-- la primera.
--
-- ESTO NO DICE QUE EL CASO ESTE RESUELTO
-- `CERRADO_SIN_ATENCION` es lo contrario de `RESUELTO`: dice que se cerró
-- porque nadie lo atendió, no porque se hiciera el trabajo. Confundirlos sería
-- reportarle a KFC como cumplido algo que no se cumplió. Por eso son dos
-- valores distintos del ENUM y por eso este arrastra un pendiente hasta que
-- alguien lo regulariza.
--
-- SE AGREGA AL FINAL DEL ENUM, como los anteriores.
-- ============================================================================

ALTER TABLE casos_gestion
  MODIFY COLUMN estado
    ENUM('NUEVO','ASIGNADO','EN_REVISION','RESUELTO','NO_COMPETE','ATENDIDO',
         'CERRADO_SIN_ATENCION')
    NOT NULL DEFAULT 'NUEVO'
    COMMENT 'CERRADO_SIN_ATENCION: paso una semana y nadie lo atendio. Arrastra pendiente',

  ADD COLUMN regularizado_por INT UNSIGNED NULL
    COMMENT 'Quien de administracion lo dio por regularizado ante KFC'
    AFTER tecnico_auto,
  ADD COLUMN regularizado_en DATETIME NULL
    COMMENT 'NULL en un CERRADO_SIN_ATENCION significa que sigue pendiente'
    AFTER regularizado_por;

-- El pendiente no es un campo aparte a propósito: es
-- `estado = 'CERRADO_SIN_ATENCION' AND regularizado_en IS NULL`. Con una
-- bandera suelta habría dos verdades que mantener de acuerdo, y tarde o
-- temprano una diría que sí y la otra que no.
ALTER TABLE casos_gestion
  ADD KEY idx_gestion_regularizar (estado, regularizado_en);

-- ============================================================================
-- VERIFICACION:
--
--   SHOW COLUMNS FROM casos_gestion LIKE 'estado';
--     -> enum(...,'ATENDIDO','CERRADO_SIN_ATENCION')
--
--   Los pendientes de regularizar de la administradora:
--     SELECT COUNT(*) FROM casos_gestion
--      WHERE estado='CERRADO_SIN_ATENCION' AND regularizado_en IS NULL;
--
--   Y la comprobacion que importa -- ninguno cerrado sin atencion tiene informe:
--     (se hace en el script, cruzando contra atenciones.json antes de escribir)
-- ============================================================================
