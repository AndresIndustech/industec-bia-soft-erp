-- ============================================================================
-- 024_ot_liberadas.sql — Una OT con número del piloto que se manda a Grupo KFC
-- por decisión expresa (T2.29.8, pedido de Andrés del 2026-10-01).
--
-- EL PROBLEMA, MEDIDO EL 2026-10-01. Del 24 al 29-sep la app emitió 18 OT en
-- modo PRUEBA (serie 9000): ninguna salió a nadie. Diez son trabajos reales,
-- recientes, sin informe del formulario viejo para su aviso (OT-9125, 9128,
-- 9146 a 9153). Andrés pidió enviarlas. El criterio de «es del piloto» es el
-- NÚMERO (Emision::esDePrueba, sin consultar nada, igual en la web y en el
-- robot), así que una OT-9125 que sí llegó a KFC seguiría figurando «del piloto
-- · no enviada a KFC», no atendería el caso, no contaría en los reportes y el
-- despachador la devolvería a RETENIDO (Correo::despachar, D-7).
--
-- LO QUE AGREGA (aditiva, S-3): tres columnas en `ot_capturadas` —cuándo, quién
-- y por qué se liberó una OT del piloto—. `esDePrueba()` y `sqlEsDePrueba()`
-- dejan de marcar como del piloto lo que tenga `liberada_en`. Nada se libera por
-- sí solo: lo hace `liberar_ot_piloto_cli.php`, con una lista explícita y
-- --a-nombre-de, y queda en la bitácora (OT_LIBERADA).
--
-- La serie 9000 sigue siendo del piloto para todo lo demás: una OT nueva que
-- salga con 9xxx en modo PRODUCCION sigue siendo un error (Emision::reservar).
--
-- Idempotente (IF NOT EXISTS). Se aplica con:
--     php aplicar_sql.php sql/024_ot_liberadas.sql
-- ============================================================================

ALTER TABLE ot_capturadas
  ADD COLUMN IF NOT EXISTS liberada_en DATETIME NULL
      COMMENT 'Cuándo se liberó una OT con número del piloto para que salga a Grupo KFC. NULL = sigue siendo del piloto (o es una OT real)',
  ADD COLUMN IF NOT EXISTS liberada_por INT UNSIGNED NULL
      COMMENT 'Quién autorizó la liberación (Andrés Basantes, a través de la consola)',
  ADD COLUMN IF NOT EXISTS liberada_nota VARCHAR(300) NULL
      COMMENT 'Por qué: el informe del formulario viejo que no existe y la fecha del pedido';
