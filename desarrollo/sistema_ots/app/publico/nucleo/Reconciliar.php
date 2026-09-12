<?php
declare(strict_types=1);
require_once __DIR__ . '/Db.php';

/**
 * Reconciliar — lo que el sistema deduce solo, sin que nadie teclee.
 *
 * DOS COSAS, Y LAS DOS SON TRIAJE, NO VEREDICTO
 *
 * 1. `atenciones()` — un caso con orden de cierre ya tiene técnico conocido:
 *    el que la firmó, sacado del PDF. Pedirle a la administradora que «asigne»
 *    a alguien que hizo el trabajo hace tres meses es hacerle teclear un dato
 *    que el sistema ya tiene. Queda en ATENDIDO, esperando que ella confirme
 *    el cierre en SAP -- que es el hecho que falta y que el correo no avisa.
 *
 * 2. `cerrarSinAtencion()` — más de una semana sin ningún informe es trabajo
 *    que no se hizo. Mientras siga mezclado con lo vivo, la administradora no
 *    distingue lo que hay que repartir hoy de lo que hay que explicarle a KFC.
 *
 * LO QUE NO HACEN, Y ES DELIBERADO
 * Ninguna de las dos pisa lo que decidió una persona: ni un veredicto, ni una
 * revisión, ni una asignación, ni una derivación a otra zona. La regla del
 * proyecto es que las señales automáticas alertan y la administradora decide;
 * aquí eso se hace cumplir dentro del SQL, no con una foto previa en PHP que
 * puede quedar vieja entre la lectura y la escritura.
 *
 * Hasta el 2026-09-10 el upsert reescribía `asignado_a` con el firmante del
 * informe en cada corrida: el caso que un jefe reasignaba volvía solo, cada
 * 3 horas, al técnico anterior. Y una orden abierta sin firma reconocible
 * fabricaba un «ASIGNADO» sin nadie asignado: así nacieron las 2 filas
 * huérfanas del 2026-09-09.
 *
 * Todo queda en la bitácora con `usuario = 'sistema'`, para que al minar el
 * registro se distinga lo que hizo una persona de lo que dedujo el programa.
 */
final class Reconciliar
{
    /**
     * Estados que una persona puso y que el automatismo no pisa.
     *
     * ESPERA_REPUESTO entró en la 007 y es el caso menos evidente de la lista.
     * Ese caso **sí** tiene informe —el técnico fue, diagnosticó y pidió la
     * pieza—, así que sin esta línea la reconciliación lo marcaría ATENDIDO en
     * cuanto viera la orden. La administradora lo daría por listo y lo cerraría
     * en SAP con el equipo todavía parado esperando la resistencia. Sale de
     * aquí cuando la pieza se instala, no cuando llega el informe que la pidió.
     *
     * La misma lista va escrita en el SQL de `atenciones()`: cambiar una obliga
     * a cambiar la otra.
     */
    private const INTOCABLES = ['RESUELTO', 'NO_COMPETE', 'EN_REVISION', 'ESPERA_REPUESTO'];

    private static function anotar(string $accion, string $aviso, ?string $antes,
                                   ?string $despues, array $datos): void
    {
        Db::ejecutar(
            "INSERT INTO bitacora (usuario_id, usuario, accion, entidad, referencia,
                                   estado_antes, estado_despues, exito, detalle, datos, ip)
             VALUES (NULL, 'sistema', ?, 'caso', ?, ?, ?, 1, ?, ?, 'estacion')",
            [$accion, $aviso, $antes, $despues,
             'automatico',
             json_encode($datos, JSON_UNESCAPED_UNICODE)]
        );
    }

    /**
     * Enlaza cada caso atendido con quien lo atendió.
     *
     * @param array $atenciones El mapa aviso => datos de `atenciones.json`.
     * @return array Cuántos casos quedaron atendidos y cuántos sin técnico.
     */
    public static function atenciones(array $atenciones): array
    {
        // usuario -> usuario_id, en una sola consulta. Con una por caso serían
        // 114 viajes a la base para resolver 19 nombres distintos.
        $porUsuario = [];
        foreach (Db::todos("SELECT usuario_id, usuario FROM usuarios
                             WHERE rol IN ('TECNICO','JEFE_ZONA') AND activo = 1") as $x) {
            $porUsuario[$x['usuario']] = (int) $x['usuario_id'];
        }

        $actuales = [];
        foreach (Db::todos('SELECT aviso, estado, asignado_a FROM casos_gestion') as $g) {
            $actuales[$g['aviso']] = $g;
        }

        $atendidos = $sinTecnico = 0;
        foreach ($atenciones as $aviso => $a) {
            $estadoAntes = $actuales[$aviso]['estado'] ?? null;
            if ($estadoAntes !== null && in_array($estadoAntes, self::INTOCABLES, true)) {
                continue;                         // ya lo resolvió una persona
            }

            $cerrada = ($a['estado_industec'] ?? '') === 'CERRADA';
            $ot = null;
            $fecha = null;
            foreach ($a['ots'] ?? [] as $o) {
                if (($o['estado_ot'] ?? '') === 'Cerrada') { $ot = $o['ot']; $fecha = $o['fecha']; }
            }

            // El primer técnico identificado de la orden de cierre. Si firmaron
            // dos, se enlaza al primero y los demás quedan en el informe: la
            // tabla guarda un responsable, no la cuadrilla.
            $idt = null;
            foreach ($a['usuarios'] ?? [] as $usr) {
                if (isset($porUsuario[$usr])) { $idt = $porUsuario[$usr]; break; }
            }
            if ($idt === null) { $sinTecnico++; }

            // Con orden de cierre, el trabajo terminó. Sin ella hay una orden
            // abierta: alguien fue y sigue en curso, típicamente esperando un
            // repuesto. Pero si esa orden abierta no trae una firma que se
            // reconozca, no hay a quién asignarla: se deja como está en vez de
            // fabricar un «asignado» sin nadie, que ninguna bandeja muestra.
            if (!$cerrada && $idt === null) { continue; }
            $nuevo = $cerrada ? 'ATENDIDO' : 'ASIGNADO';

            // El orden del SET importa: MariaDB evalúa de izquierda a derecha y
            // cada columna ve el valor ya escrito de las anteriores. `estado` va
            // al final porque mira el `asignado_a` que quedó.
            Db::ejecutar(
                "INSERT INTO casos_gestion (aviso, estado, ot_cierre, atendido_en,
                                            asignado_a, tecnico_auto)
                 VALUES (?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                     ot_cierre    = COALESCE(VALUES(ot_cierre), ot_cierre),
                     atendido_en  = COALESCE(VALUES(atendido_en), atendido_en),
                     -- El firmante solo se pone si ninguna persona asignó ni
                     -- derivó el caso. Lo que decidió una persona no se pisa.
                     asignado_a   = IF(asignado_por IS NULL AND derivado_en IS NULL,
                                       COALESCE(VALUES(asignado_a), asignado_a), asignado_a),
                     tecnico_auto = IF(asignado_por IS NULL AND derivado_en IS NULL
                                       AND asignado_a IS NOT NULL, 1, tecnico_auto),
                     estado       = CASE
                         WHEN estado IN ('RESUELTO','NO_COMPETE','EN_REVISION','ESPERA_REPUESTO') THEN estado
                         WHEN VALUES(estado) = 'ASIGNADO' AND asignado_a IS NULL THEN estado
                         ELSE VALUES(estado) END",
                [$aviso, $nuevo, $ot, $fecha ? substr((string) $fecha, 0, 19) : null,
                 $idt, $idt === null ? 0 : 1]
            );

            if ($estadoAntes !== $nuevo) {
                self::anotar($cerrada ? 'ATENDIDO_AUTO' : 'ASIGNADO_AUTO', (string) $aviso,
                             $estadoAntes, $nuevo,
                             ['ot' => $ot, 'tecnicos' => $a['usuarios'] ?? [],
                              'fuente' => 'informe de OT del buzon']);
            }
            if ($cerrada) { $atendidos++; }
        }
        return ['atendidos' => $atendidos, 'sin_tecnico' => $sinTecnico];
    }

    /**
     * Cierra por falta de atención lo que lleva más de `$dias` sin informe.
     *
     * @param array $casos      La lista `datos` del catálogo.
     * @param array $atenciones El mapa de atenciones; tener informe excluye.
     * @param bool  $ejecutar   false = solo cuenta, no escribe.
     */
    public static function cerrarSinAtencion(array $casos, array $atenciones,
                                             int $dias = 7, bool $ejecutar = false): array
    {
        $corte = date('Y-m-d', strtotime("-$dias days"));

        $actuales = [];
        foreach (Db::todos('SELECT aviso, estado FROM casos_gestion') as $g) {
            $actuales[$g['aviso']] = $g['estado'];
        }

        $candidatos = [];
        foreach ($casos as $c) {
            $aviso = (string) ($c['aviso'] ?? '');
            if ($aviso === '') { continue; }
            // Tener informe excluye, y con eso quedan fuera solos los que
            // esperan repuesto: esos SI tienen una orden abierta esperando la
            // pieza. No hace falta una segunda condición para ellos.
            if (isset($atenciones[$aviso])) { continue; }
            $creado = (string) ($c['fecha_creacion'] ?? '');
            if ($creado === '' || $creado >= $corte) { continue; }
            $estado = $actuales[$aviso] ?? 'NUEVO';
            // Solo lo que nadie tocó. Un caso asignado tiene a alguien detrás.
            if (!in_array($estado, ['NUEVO'], true)) { continue; }
            $candidatos[] = ['aviso' => $aviso, 'zona' => $c['zona'] ?? null,
                             'creado' => $creado, 'local' => $c['local'] ?? null];
        }

        if (!$ejecutar) {
            return ['candidatos' => count($candidatos), 'cerrados' => 0, 'corte' => $corte];
        }

        foreach ($candidatos as $c) {
            Db::ejecutar(
                "INSERT INTO casos_gestion (aviso, zona, estado)
                 VALUES (?,?,'CERRADO_SIN_ATENCION')
                 ON DUPLICATE KEY UPDATE
                     estado = CASE WHEN estado = 'NUEVO' THEN 'CERRADO_SIN_ATENCION'
                                   ELSE estado END",
                [$c['aviso'], $c['zona']]
            );
            self::anotar('CERRADO_SIN_ATENCION', $c['aviso'], 'NUEVO', 'CERRADO_SIN_ATENCION',
                         ['creado' => $c['creado'], 'local' => $c['local'],
                          'dias' => $dias, 'motivo' => 'sin informe de atencion']);
        }
        return ['candidatos' => count($candidatos), 'cerrados' => count($candidatos),
                'corte' => $corte];
    }
}
