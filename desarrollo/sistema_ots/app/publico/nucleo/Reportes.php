<?php
declare(strict_types=1);

require_once __DIR__ . '/Casos.php';
require_once __DIR__ . '/Pendientes.php';
require_once __DIR__ . '/Novedades.php';
require_once __DIR__ . '/Ui.php';

/**
 * Reportes.php — Los números del tablero y de la exportación, calculados una sola vez.
 *
 * POR QUÉ UNA CLASE Y NO EL CÁLCULO DENTRO DE reportes.php
 * Andrés pidió los reportes que le exige Grupo KFC en Excel, PDF y PowerPoint
 * (T2.14.5, D14). Si la pantalla y los tres archivos calcularan cada uno lo
 * suyo, tarde o temprano dirían cifras distintas. Aquí vive el cálculo; la
 * pantalla lo dibuja y `reporte_exportar.php` lo escribe en cada formato.
 *
 * DOS CORTES, LOS DOS EN EL SERVIDOR
 *   zona: la administración elige (Las tres · UIO · LARB · CNLJ); un jefe de
 *         zona recibe la suya aunque pida otra (el alcance manda).
 *   mes:  AAAA-MM sobre la fecha de creación del caso en SAP; sin mes, todo el
 *         periodo que trae el buzón (los 90 días del correo).
 *
 * NADA SE ESTIMA (I-7). Si un insumo falta —el índice del archivo vacío, el
 * cronograma sin importar, ningún caso con informe— la sección lo dice y la
 * cifra sale como null, nunca como cero disfrazado.
 */
final class Reportes
{
    public const ZONAS = ['UIO', 'LARB', 'CNLJ'];

    /** Los mismos colores de graficos.js, para el PDF y las presentaciones. */
    public const COLOR_ZONA = ['UIO' => '#7c3aed', 'LARB' => '#0d9488', 'CNLJ' => '#ea580c', 'OTRA' => '#64748b'];
    public const COLOR_ESTADO = [
        'NUEVO' => '#94a3b8', 'ASIGNADO' => '#2a78d6', 'EN_REVISION' => '#eda100',
        'ESPERA_REPUESTO' => '#eb6834', 'ATENDIDO' => '#1baf7a', 'RESUELTO' => '#008300',
        'NO_COMPETE' => '#4a3aa7', 'CERRADO_SIN_ATENCION' => '#e34948',
    ];
    public const SERIES = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];

    /** Los motivos de reagenda que ofrece la pantalla; lo que llega distinto se cuenta como «Otro». */
    public const MOTIVOS_REAGENDA = [
        'Falta el kit de mantenimiento', 'El local no dio acceso', 'Cruce con otro trabajo de la zona',
        'Pedido de Grupo KFC', 'Falta de personal', 'Otro',
    ];

    /**
     * Todas las secciones del reporte, para una zona (o las tres) y un mes (o todo).
     *
     * @return array con claves: meta, salud, edad, zonas, estados, meses, locales,
     *               reincidentes, tipos, tecnicos_firma, cadenas, c48, novedades,
     *               rendimiento, preventivo, casos_abiertos, archivo
     */
    public static function calcular(?string $zona, ?string $mes): array
    {
        $za = Auth::zonaAlcance();
        if ($za !== null) { $zona = $za !== '' ? $za : null; }          // el alcance manda
        if ($zona !== null && !in_array($zona, self::ZONAS, true)) { $zona = null; }
        if ($mes !== null && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) { $mes = null; }

        $fuente  = Casos::catalogo();
        $gestion = Casos::gestion();
        $aten    = Casos::atenciones();
        $casos   = Casos::enAlcance($fuente['datos'] ?? [], $gestion);
        if ($zona !== null) {
            $casos = array_values(array_filter($casos, fn($c) => (string) ($c['zona'] ?? '') === $zona));
        }
        if ($mes !== null) {
            $casos = array_values(array_filter($casos, fn($c) => substr((string) ($c['fecha_creacion'] ?? ''), 0, 7) === $mes));
        }
        $hoy = date('Y-m-d');

        /* --- Una sola pasada sobre los casos ---------------------------------- */
        $porZona = ['UIO' => 0, 'LARB' => 0, 'CNLJ' => 0];
        $sinZona = 0;
        $porEstado = $porMes = $porLocal = $porTipo = $porTecnicoFirma = $cadenas = [];
        $edad = ['Hoy y ayer' => 0, 'De 2 a 3 días' => 0, 'De 4 a 7 días' => 0, 'Más de una semana' => 0];
        $abiertos = 0; $conInforme = 0;
        $porAsignado = [];          // usuario_id => [avisos]
        $casosAbiertos = [];

        $trabados = [];
        if (Pendientes::disponible()) {
            foreach (Db::todos("SELECT aviso, MAX(estado IN ('REGISTRADO_SAP','ESPERA_KFC')) esperando_kfc,
                                       MAX(estado NOT IN ('RESUELTO','CANCELADO')) vivo
                                  FROM pendientes WHERE estado <> 'CANCELADO' GROUP BY aviso") as $t) {
                $trabados[(string) $t['aviso']] = ['kfc' => (int) $t['esperando_kfc'] === 1, 'vivo' => (int) $t['vivo'] === 1];
            }
        }

        foreach ($casos as $c) {
            $aviso  = (string) ($c['aviso'] ?? '');
            $g      = $gestion[$aviso] ?? [];
            $estado = (string) ($g['estado'] ?? 'NUEVO');
            $porEstado[$estado] = ($porEstado[$estado] ?? 0) + 1;

            $z = (string) ($c['zona'] ?? '');
            if (isset($porZona[$z])) { $porZona[$z]++; } else { $sinZona++; }

            $m = substr((string) ($c['fecha_creacion'] ?? ''), 0, 7);
            if ($m !== '') { $porMes[$m] = ($porMes[$m] ?? 0) + 1; }

            $loc = trim((string) ($c['local'] ?? ''));
            if ($loc !== '') { $porLocal[$loc] = ($porLocal[$loc] ?? 0) + 1; }

            $cad = trim((string) ($c['cadena'] ?? ''));
            if ($cad !== '') { $cadenas[$cad] = ($cadenas[$cad] ?? 0) + 1; }

            $tp = trim((string) ($c['caso'] ?? '')) ?: 'Sin clasificar';
            $porTipo[$tp] = ($porTipo[$tp] ?? 0) + 1;

            $vivo = !in_array($estado, ['RESUELTO', 'NO_COMPETE', 'CERRADO_SIN_ATENCION'], true);
            $d = Ui::dias($c['fecha_creacion'] ?? null);
            if ($vivo) {
                $abiertos++;
                if ($d !== null) {
                    if ($d <= 1)      { $edad['Hoy y ayer']++; }
                    elseif ($d <= 3)  { $edad['De 2 a 3 días']++; }
                    elseif ($d <= 7)  { $edad['De 4 a 7 días']++; }
                    else              { $edad['Más de una semana']++; }
                }
                // La hoja «Casos abiertos» con las columnas del plan de zona y el
                // semáforo de la administración (amarillo INDUSTEC, naranja KFC,
                // verde repuestos SAP, rojo emergente o vencido).
                $tr = $trabados[$aviso] ?? null;
                if ($d !== null && $d > 7 || strtoupper((string) ($c['prioridad'] ?? '')) === 'ALTA' && $estado === 'NUEVO') {
                    $sem = 'rojo';
                } elseif ($estado === 'ESPERA_REPUESTO') {
                    $sem = $tr && $tr['kfc'] ? 'naranja' : 'verde';
                } else {
                    $sem = 'amarillo';
                }
                $casosAbiertos[] = [
                    'aviso'     => $aviso,
                    'tecnico'   => (string) ($g['tecnico_nombre'] ?? ''),
                    'local'     => $loc,
                    'local_nombre' => (string) ($c['local_nombre'] ?? ''),
                    'zona'      => $z,
                    'fecha'     => substr((string) ($c['fecha_creacion'] ?? ''), 0, 10),
                    'equipo'    => trim((string) ($c['activo_fijo'] ?? '')),
                    'trabajo'   => (string) ($c['caso'] ?? ''),
                    'estatus'   => Ui::etiquetaEstado($estado),
                    'estado'    => $estado,
                    'dias'      => $d,
                    'prioridad' => (string) ($c['prioridad'] ?? ''),
                    'observaciones' => trim((string) (($g['nota'] ?? '') ?: mb_strimwidth((string) ($c['descripcion_trabajo'] ?? ''), 0, 160, '…', 'UTF-8'))),
                    'semaforo'  => $sem,
                ];
            }

            if (isset($aten[$aviso])) {
                $conInforme++;
                foreach (($aten[$aviso]['tecnicos'] ?? []) as $t) {
                    $porTecnicoFirma[$t] = ($porTecnicoFirma[$t] ?? 0) + 1;
                }
            }
            if (!empty($g['asignado_a'])) {
                $porAsignado[(int) $g['asignado_a']][] = $aviso;
            }
        }
        arsort($porLocal); arsort($porTipo); arsort($porTecnicoFirma); ksort($porMes); arsort($cadenas);
        usort($casosAbiertos, fn($a, $b) => ($b['dias'] ?? 0) <=> ($a['dias'] ?? 0));

        /* --- Se concluye en una visita ------------------------------------------ */
        $conPendiente = 0; $concluyeUna = 0; $pctConcluye = null;
        if (Pendientes::disponible() && $conInforme > 0) {
            foreach ($casos as $c) {
                $av = (string) ($c['aviso'] ?? '');
                if (isset($aten[$av], $trabados[$av])) { $conPendiente++; }
            }
            $concluyeUna = max(0, $conInforme - $conPendiente);
            $pctConcluye = (int) round($concluyeUna * 100 / $conInforme);
        }

        /* --- Rendimiento por técnico (TR-03) --------------------------------- */
        $rendimiento = self::rendimiento($casos, $gestion, $aten, $trabados, $porAsignado, $zona, $mes);

        /* --- 48 h, novedades, preventivo, archivo ----------------------------- */
        $c48 = Pendientes::cumplimiento48($zona);
        $novedades = self::novedades($zona, $mes);
        $preventivo = self::preventivo($zona, $mes);
        $archivo = self::archivo($zona, $mes);

        /* --- Los arreglos que consumen los gráficos --------------------------- */
        $dZona = [];
        foreach ($porZona as $z => $n) { if ($n > 0) { $dZona[] = ['e' => $z, 'v' => $n, 'c' => self::COLOR_ZONA[$z]]; } }
        if ($sinZona > 0) { $dZona[] = ['e' => 'Sin zona', 'v' => $sinZona, 'c' => '#94a3b8']; }

        $dEstado = [];
        foreach ($porEstado as $k => $n) { $dEstado[] = ['e' => Ui::etiquetaEstado($k), 'v' => $n, 'c' => Ui::colorEstado($k)]; }
        usort($dEstado, fn($a, $b) => $b['v'] <=> $a['v']);

        $colorEdad = ['Hoy y ayer' => '#1baf7a', 'De 2 a 3 días' => '#2a78d6', 'De 4 a 7 días' => '#eda100', 'Más de una semana' => '#e34948'];
        $dEdad = [];
        foreach ($edad as $k => $n) { $dEdad[] = ['e' => $k, 'v' => $n, 'c' => $colorEdad[$k]]; }

        $dMes = [];
        foreach (array_slice($porMes, -12, 12, true) as $k => $n) { $dMes[] = ['e' => substr($k, 5, 2) . '/' . substr($k, 2, 2), 'v' => $n]; }
        $dLocal = [];
        foreach (array_slice($porLocal, 0, 12, true) as $l => $n) { $dLocal[] = ['e' => $l, 'v' => $n]; }
        $dTipo = [];
        foreach (array_slice($porTipo, 0, 10, true) as $t => $n) { $dTipo[] = ['e' => mb_strimwidth($t, 0, 34, '…', 'UTF-8'), 'v' => $n]; }
        $dTecnico = [];
        foreach (array_slice($porTecnicoFirma, 0, 12, true) as $t => $n) { $dTecnico[] = ['e' => $t, 'v' => $n]; }
        $dCadena = [];
        foreach (array_slice($cadenas, 0, 8, true) as $k => $n) { $dCadena[] = ['e' => $k, 'v' => $n]; }
        $d48 = [
            ['e' => 'Validados a tiempo', 'v' => $c48['a_tiempo'],  'c' => '#1baf7a'],
            ['e' => 'Validados tarde',    'v' => $c48['tarde'],     'c' => '#eda100'],
            ['e' => 'Reloj corriendo',    'v' => $c48['corriendo'], 'c' => '#2a78d6'],
            ['e' => 'Vencidos ahora',     'v' => $c48['vencidos'],  'c' => '#e34948'],
        ];

        return [
            'meta' => [
                'zona' => $zona, 'mes' => $mes, 'hoy' => $hoy,
                'generado' => (string) ($fuente['generado'] ?? ''),
                'casos' => count($casos), 'hay_fuente' => (bool) $fuente,
                'alcance_fijo' => $za !== null,
            ],
            'salud' => ['con_informe' => $conInforme, 'concluye_una' => $concluyeUna,
                        'con_pendiente' => $conPendiente, 'pct_concluye' => $pctConcluye, 'abiertos' => $abiertos],
            'edad' => $dEdad,
            'zonas' => $dZona, 'por_zona' => $porZona, 'sin_zona' => $sinZona,
            'estados' => $dEstado, 'por_estado' => $porEstado,
            'meses' => $dMes,
            'locales' => $dLocal, 'reincidentes' => array_filter($porLocal, fn($n) => $n >= 5),
            'tipos' => $dTipo,
            'tecnicos_firma' => $dTecnico,
            'cadenas' => $dCadena,
            'c48' => $c48, 'd48' => $d48,
            'novedades' => $novedades,
            'rendimiento' => $rendimiento,
            'preventivo' => $preventivo,
            'casos_abiertos' => $casosAbiertos,
            'archivo' => $archivo,
        ];
    }

    /** Rendimiento por técnico: por quién está asignado (usuario_id), no por la firma. */
    private static function rendimiento(array $casos, array $gestion, array $aten, array $trabados,
                                        array $porAsignado, ?string $zona, ?string $mes): array
    {
        $sql = "SELECT usuario_id, nombre, zona, activo FROM usuarios WHERE rol = 'TECNICO'";
        $par = [];
        if ($zona !== null) { $sql .= ' AND zona = ?'; $par[] = $zona; }
        $tecnicos = Db::todos($sql . ' ORDER BY nombre', $par);
        $porAviso = [];
        foreach ($casos as $c) { $porAviso[(string) ($c['aviso'] ?? '')] = $c; }

        // Lo que se cuenta por SQL, en tres consultas para todos los técnicos.
        $vencidos = $novedades = $ordenes = [];
        if (Pendientes::disponible()) {
            $q = "SELECT abierto_por, COUNT(*) n FROM pendientes p
                   WHERE p.estado IN (" . "'" . implode("','", Pendientes::ABIERTOS) . "'" . ")
                     AND p.via = 'SIN_VEREDICTO' AND p.deshabilitado = 1
                     AND COALESCE(p.plazo_desde, p.abierto_en) < DATE_SUB(NOW(), INTERVAL 48 HOUR)";
            $p = [];
            if ($zona !== null) { $q .= ' AND p.zona = ?'; $p[] = $zona; }
            foreach (Db::todos($q . ' GROUP BY abierto_por', $p) as $r) { $vencidos[(int) $r['abierto_por']] = (int) $r['n']; }
        }
        if (Novedades::disponible()) {
            $q = 'SELECT reportada_por, COUNT(*) n FROM novedades n WHERE 1=1';
            $p = [];
            if ($zona !== null) { $q .= ' AND n.zona = ?'; $p[] = $zona; }
            if ($mes !== null)  { $q .= ' AND DATE_FORMAT(n.reportada_en, "%Y-%m") = ?'; $p[] = $mes; }
            foreach (Db::todos($q . ' GROUP BY reportada_por', $p) as $r) { $novedades[(int) $r['reportada_por']] = (int) $r['n']; }
        }
        try {
            $q = "SELECT usuario_id, COUNT(*) n FROM ot_capturadas c WHERE c.estado IN ('EMITIDA','ENVIADA','PROCESADA')";
            $p = [];
            if ($zona !== null) { $q .= ' AND c.zona = ?'; $p[] = $zona; }
            if ($mes !== null)  { $q .= ' AND DATE_FORMAT(COALESCE(c.emitida_en, c.recibida_en), "%Y-%m") = ?'; $p[] = $mes; }
            foreach (Db::todos($q . ' GROUP BY usuario_id', $p) as $r) { $ordenes[(int) $r['usuario_id']] = (int) $r['n']; }
        } catch (Throwable $e) { /* sin la 007 no hay capturas */ }

        $filas = [];
        foreach ($tecnicos as $t) {
            $id = (int) $t['usuario_id'];
            $avisos = $porAsignado[$id] ?? [];
            $asignados = count($avisos);
            $conInf = 0; $unaVisita = 0; $sumaDias = 0; $nDias = 0; $abiertosAhora = 0;
            foreach ($avisos as $av) {
                $g = $gestion[$av] ?? [];
                if (in_array((string) ($g['estado'] ?? ''), ['ASIGNADO', 'ESPERA_REPUESTO'], true)) { $abiertosAhora++; }
                if (isset($aten[$av])) {
                    $conInf++;
                    if (!isset($trabados[$av])) { $unaVisita++; }
                    $creado = substr((string) ($porAviso[$av]['fecha_creacion'] ?? ''), 0, 10);
                    $primera = null;
                    foreach ($aten[$av]['ots'] ?? [] as $o) {
                        $f = substr((string) ($o['fecha'] ?? ''), 0, 10);
                        if ($f !== '' && ($primera === null || $f < $primera)) { $primera = $f; }
                    }
                    if ($creado !== '' && $primera !== null) {
                        $dd = (strtotime($primera) - strtotime($creado)) / 86400;
                        if ($dd >= 0 && $dd < 400) { $sumaDias += $dd; $nDias++; }
                    }
                }
            }
            $fila = [
                'usuario_id' => $id, 'nombre' => (string) $t['nombre'], 'zona' => (string) ($t['zona'] ?? ''),
                'activo' => (int) $t['activo'] === 1,
                'asignados' => $asignados, 'con_informe' => $conInf, 'una_visita' => $unaVisita,
                'pct_una_visita' => $conInf > 0 ? (int) round($unaVisita * 100 / $conInf) : null,
                'dias_primera' => $nDias > 0 ? round($sumaDias / $nDias, 1) : null,
                'abiertos_ahora' => $abiertosAhora,
                'pendientes_vencidos' => $vencidos[$id] ?? 0,
                'novedades' => $novedades[$id] ?? 0,
                'ordenes_app' => $ordenes[$id] ?? 0,
            ];
            $actividad = $asignados + $fila['pendientes_vencidos'] + $fila['novedades'] + $fila['ordenes_app'];
            if ($actividad > 0 || $fila['activo']) { $filas[] = $fila; }
        }
        usort($filas, fn($a, $b) => [$b['asignados'], $b['con_informe']] <=> [$a['asignados'], $a['con_informe']]);
        return $filas;
    }

    /** Novedades por área y sus contadores, para la zona y el mes. */
    private static function novedades(?string $zona, ?string $mes): array
    {
        $out = ['por_area' => [], 'pendientes' => 0, 'alto' => 0, 'ajenas' => 0, 'con_aviso' => 0, 'total' => 0];
        if (!Novedades::disponible()) { return $out; }
        $donde = '1=1'; $par = [];
        if ($zona !== null) { $donde .= ' AND n.zona = ?'; $par[] = $zona; }
        if ($mes !== null)  { $donde .= ' AND DATE_FORMAT(n.reportada_en, "%Y-%m") = ?'; $par[] = $mes; }
        foreach (Db::todos("SELECT tipo, COUNT(*) n FROM novedades n WHERE $donde GROUP BY tipo ORDER BY n DESC", $par) as $r) {
            $out['por_area'][] = ['e' => Novedades::etiquetaTipo($r['tipo']), 'v' => (int) $r['n']];
        }
        $pend = "'" . implode("','", Novedades::PENDIENTES) . "'";
        $f = Db::uno("SELECT COUNT(*) total, SUM(n.estado IN ($pend)) pendientes,
                             SUM(n.estado IN ($pend) AND n.riesgo = 'ALTO') alto,
                             SUM(n.estado IN ($pend) AND n.tipo <> 'EQUIPO_CORRECTIVO') ajenas,
                             SUM(n.aviso_sap IS NOT NULL) con_aviso
                        FROM novedades n WHERE $donde", $par);
        foreach (['total', 'pendientes', 'alto', 'ajenas', 'con_aviso'] as $k) { $out[$k] = (int) ($f[$k] ?? 0); }
        return $out;
    }

    /** Cuántas órdenes hay en el archivo (índice 009) para la zona y el mes. */
    private static function archivo(?string $zona, ?string $mes): ?array
    {
        try {
            $donde = '1=1'; $par = [];
            if ($zona !== null) { $donde .= ' AND zona = ?'; $par[] = $zona; }
            $tot = Db::uno("SELECT COUNT(*) n, SUM(en_servidor) s FROM ot_archivo WHERE $donde", $par);
            $mesN = null;
            if ($mes !== null) {
                $mesN = (int) (Db::uno("SELECT COUNT(*) n FROM ot_archivo WHERE $donde AND DATE_FORMAT(fecha_atencion, '%Y-%m') = ?",
                                       array_merge($par, [$mes]))['n'] ?? 0);
            }
            return ['total' => (int) ($tot['n'] ?? 0), 'en_servidor' => (int) ($tot['s'] ?? 0), 'mes' => $mesN];
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * El estado de un ingreso preventivo, como lo calcula cronograma.js, para
     * que el reporte y el calendario digan lo mismo. Espera plan_vigente y
     * plan_original como ['inicio','fin'] (o null), real como
     * ['inicio','fin','ots','cerrado'] y estado de la tabla si lo hay.
     */
    public static function estadoPreventivo(array $i, ?string $hoy = null): string
    {
        $hoy = $hoy ?? date('Y-m-d');
        $pv = $i['plan_vigente'] ?? null;
        if (empty($pv['inicio'])) { return 'sinagendar'; }
        if (($i['estado'] ?? '') === 'CUMPLIDO' || !empty($i['real']['cerrado'])) { return 'cumplido'; }
        if (($i['estado'] ?? '') === 'CANCELADO') { return 'cancelado'; }
        if (!empty($i['real']['ots']) || !empty($i['real']['inicio'])) { return 'encurso'; }
        $fin = (string) ($pv['fin'] ?? $pv['inicio']);
        if ($fin < $hoy) { return 'vencido'; }
        $dias = (int) round((strtotime((string) $pv['inicio']) - strtotime($hoy)) / 86400);
        return $dias <= 3 ? 'poriniciar' : 'planificado';
    }

    /**
     * Los ingresos preventivos en la forma que usan la pantalla y el reporte:
     * de la tabla (009) cuando tiene filas; si no, del JSON de la estación.
     *
     * @return array{fuente:?string, ingresos:array, generado:?string}
     */
    public static function ingresosPreventivos(?string $zona): array
    {
        $ingresos = []; $fuente = null; $generado = null;
        try {
            $donde = '1=1'; $par = [];
            if ($zona !== null) { $donde .= ' AND i.zona = ?'; $par[] = $zona; }
            $filas = Db::todos("SELECT i.* FROM ingresos_preventivos i WHERE $donde ORDER BY i.local_codigo, i.anio, i.numero", $par);
            if ($filas) {
                $fuente = 'tabla';
                $locales = [];
                try {
                    require_once __DIR__ . '/Catalogo.php';
                    foreach ((Catalogo::cargar()['locales'] ?? []) as $l) { $locales[strtoupper((string) ($l['codigo'] ?? ''))] = $l; }
                } catch (Throwable $e) { /* sin maestro: sin nombres */ }
                $novs = [];
                foreach (Db::todos('SELECT n.*, u.nombre AS por_nombre FROM cronograma_novedades n JOIN usuarios u ON u.usuario_id = n.por ORDER BY n.en ASC') as $n) {
                    $novs[(int) $n['ingreso_id']][] = $n;
                }
                foreach ($filas as $f) {
                    $ots = json_decode((string) ($f['ot_ids'] ?? ''), true);
                    $loc = strtoupper((string) $f['local_codigo']);
                    $ingresos[] = [
                        'id' => $loc . '-' . $f['anio'] . '-' . $f['numero'],
                        'ingreso_id' => (int) $f['ingreso_id'],
                        'local' => $loc, 'local_nombre' => (string) ($locales[$loc]['nombre'] ?? ''),
                        'zona' => (string) ($f['zona'] ?? ''), 'cadena' => (string) ($locales[$loc]['cadena'] ?? ''),
                        'ciudad' => (string) ($locales[$loc]['ciudad'] ?? ''),
                        'anio' => (int) $f['anio'], 'numero' => (int) $f['numero'],
                        'kit' => (string) $f['kit_estado'], 'kit_fecha' => $f['kit_fecha'], 'kit_texto' => (string) ($f['kit_nota'] ?? ''),
                        'plan_original' => $f['plan_original_inicio'] ? ['inicio' => $f['plan_original_inicio'], 'fin' => $f['plan_original_fin'] ?? $f['plan_original_inicio']] : null,
                        'plan_vigente'  => $f['plan_vigente_inicio'] ? ['inicio' => $f['plan_vigente_inicio'], 'fin' => $f['plan_vigente_fin'] ?? $f['plan_vigente_inicio']] : null,
                        'real' => ['inicio' => $f['real_inicio'], 'fin' => $f['real_fin'],
                                   'ots' => is_array($ots) ? array_map(fn($o) => ['id' => (string) $o], $ots) : [],
                                   'cerrado' => $f['estado'] === 'CUMPLIDO'],
                        'estado' => (string) $f['estado'],
                        'novedades' => array_map(fn($n) => [
                            'tipo' => (string) $n['tipo'], 'motivo' => (string) ($n['motivo'] ?? ''),
                            'texto' => trim((string) ($n['motivo'] ?? '') . ((string) ($n['detalle'] ?? '') !== '' ? ' — ' . $n['detalle'] : '')),
                            'fecha' => substr((string) $n['en'], 0, 16), 'por' => (string) $n['por_nombre'],
                            'fecha_antes' => $n['fecha_antes'], 'fecha_despues' => $n['fecha_despues'],
                        ], $novs[(int) $f['ingreso_id']] ?? []),
                    ];
                }
            }
        } catch (Throwable $e) {
            $fuente = null;
        }
        if ($fuente === null) {
            foreach ([__DIR__ . '/../catalogos', __DIR__ . '/../../../../../SALIDAS IA/OTS/catalogos'] as $c) {
                if (is_file($c . '/cronograma_preventivo.json')) {
                    $j = json_decode((string) file_get_contents($c . '/cronograma_preventivo.json'), true);
                    $generado = (string) ($j['generado'] ?? date('c', (int) filemtime($c . '/cronograma_preventivo.json')));
                    foreach (($j['ingresos'] ?? []) as $i) {
                        if ($zona !== null && (string) ($i['zona'] ?? '') !== $zona) { continue; }
                        $i['anio'] = (int) ($j['anio'] ?? date('Y'));
                        $ingresos[] = $i;
                    }
                    $fuente = 'json';
                    break;
                }
            }
        }
        return ['fuente' => $fuente, 'ingresos' => $ingresos, 'generado' => $generado];
    }

    /** Cumplimiento del preventivo (TR-04): contra el plan original. */
    private static function preventivo(?string $zona, ?string $mes): array
    {
        $src = self::ingresosPreventivos($zona);
        $out = ['fuente' => $src['fuente'], 'generado' => $src['generado'], 'total' => 0,
                'cumplidos_a_tiempo' => 0, 'cumplidos_tarde' => 0, 'vencidos' => 0, 'en_curso' => 0,
                'por_iniciar' => 0, 'planificados' => 0, 'sin_agendar' => 0, 'reagendados' => 0,
                'kits_confirmados' => 0, 'pct_a_tiempo' => null, 'por_zona' => [], 'motivos' => [], 'estados' => []];
        if ($src['fuente'] === null) { return $out; }
        $hoy = date('Y-m-d');
        foreach ($src['ingresos'] as $i) {
            $pv = $i['plan_vigente'] ?? null;
            if ($mes !== null && substr((string) ($pv['inicio'] ?? ''), 0, 7) !== $mes) { continue; }
            $e = self::estadoPreventivo($i, $hoy);
            if ($e === 'cancelado') { continue; }
            $z = (string) ($i['zona'] ?? '');
            $pz = &$out['por_zona'][$z !== '' ? $z : 'Sin zona'];
            $pz = $pz ?? ['total' => 0, 'cumplidos' => 0, 'a_tiempo' => 0, 'vencidos' => 0, 'sin_agendar' => 0, 'en_curso' => 0];
            $out['total']++; $pz['total']++;
            if ($e === 'cumplido') {
                $finReal = (string) ($i['real']['fin'] ?? $i['real']['inicio'] ?? '');
                $finPlan = (string) ($i['plan_original']['fin'] ?? $i['plan_original']['inicio'] ?? '');
                $aTiempo = $finPlan === '' || ($finReal !== '' && $finReal <= $finPlan);
                $out[$aTiempo ? 'cumplidos_a_tiempo' : 'cumplidos_tarde']++;
                $pz['cumplidos']++; if ($aTiempo) { $pz['a_tiempo']++; }
            } elseif ($e === 'vencido')     { $out['vencidos']++; $pz['vencidos']++; }
            elseif ($e === 'encurso')       { $out['en_curso']++; $pz['en_curso']++; }
            elseif ($e === 'poriniciar')    { $out['por_iniciar']++; }
            elseif ($e === 'planificado')   { $out['planificados']++; }
            elseif ($e === 'sinagendar')    { $out['sin_agendar']++; $pz['sin_agendar']++; }
            unset($pz);
            $po = $i['plan_original'] ?? null;
            if ($po && $pv && ($po['inicio'] ?? null) !== ($pv['inicio'] ?? null)) { $out['reagendados']++; }
            if (in_array(strtoupper((string) ($i['kit'] ?? '')), ['CONFIRMADO', 'ENTREGADO', 'DISPONIBLE'], true)) { $out['kits_confirmados']++; }
        }
        $cerrados = $out['cumplidos_a_tiempo'] + $out['cumplidos_tarde'] + $out['vencidos'];
        $out['pct_a_tiempo'] = $cerrados > 0 ? (int) round($out['cumplidos_a_tiempo'] * 100 / $cerrados) : null;
        $out['estados'] = [
            ['e' => 'Cumplidos a tiempo', 'v' => $out['cumplidos_a_tiempo'], 'c' => '#1baf7a'],
            ['e' => 'Cumplidos tarde',    'v' => $out['cumplidos_tarde'],    'c' => '#eda100'],
            ['e' => 'Vencidos',           'v' => $out['vencidos'],           'c' => '#e34948'],
            ['e' => 'En curso',           'v' => $out['en_curso'],           'c' => '#2a78d6'],
            ['e' => 'Sin agendar',        'v' => $out['sin_agendar'],        'c' => '#94a3b8'],
        ];
        // Los motivos de reagenda salen de la tabla; con el JSON no existen todavía.
        if ($src['fuente'] === 'tabla') {
            $q = "SELECT n.motivo, COUNT(*) c FROM cronograma_novedades n
                    JOIN ingresos_preventivos i ON i.ingreso_id = n.ingreso_id
                   WHERE n.tipo = 'REAGENDA'";
            $p = [];
            if ($zona !== null) { $q .= ' AND i.zona = ?'; $p[] = $zona; }
            if ($mes !== null)  { $q .= ' AND DATE_FORMAT(n.en, "%Y-%m") = ?'; $p[] = $mes; }
            $motivos = [];
            foreach (Db::todos($q . ' GROUP BY n.motivo', $p) as $r) {
                $m = in_array($r['motivo'], self::MOTIVOS_REAGENDA, true) ? (string) $r['motivo'] : 'Otro';
                $motivos[$m] = ($motivos[$m] ?? 0) + (int) $r['c'];
            }
            arsort($motivos);
            foreach ($motivos as $m => $c) { $out['motivos'][] = ['e' => $m, 'v' => $c]; }
        }
        return $out;
    }

    /* =====================================================================
       Los gráficos en SVG, en PHP, para el PDF: la misma forma que
       graficos.js (barras horizontales y anillo con el total al centro).
       ===================================================================== */

    public static function colorDe(array $d, int $i): string
    {
        if (!empty($d['c'])) { return (string) $d['c']; }
        $k = strtoupper(str_replace(' ', '_', (string) ($d['e'] ?? '')));
        return self::COLOR_ZONA[$k] ?? self::COLOR_ESTADO[$k] ?? self::SERIES[$i % count(self::SERIES)];
    }

    public static function svgBarras(array $datos, int $anchoEt = 118, string $titulo = ''): string
    {
        $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $alto = 24; $hueco = 8; $w = 560; $h = max(1, count($datos)) * ($alto + $hueco) + 6;
        $max = max(1, ...array_map(fn($d) => (int) ($d['v'] ?? 0), $datos ?: [['v' => 0]]));
        $total = array_sum(array_map(fn($d) => (int) ($d['v'] ?? 0), $datos));
        $zona = $w - $anchoEt - 62;
        $s = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" role="img" aria-label="' . $e($titulo) . '">';
        foreach ($datos as $i => $d) {
            $y = $i * ($alto + $hueco);
            $v = (int) ($d['v'] ?? 0);
            $len = max($v / $max * $zona, $v > 0 ? 3 : 0);
            $s .= '<text x="' . ($anchoEt - 8) . '" y="' . ($y + $alto / 2 + 4) . '" text-anchor="end" font-size="11.5" fill="#475569" font-family="DejaVu Sans">' . $e($d['e']) . '</text>';
            $s .= '<rect x="' . $anchoEt . '" y="' . ($y + 4) . '" width="' . $zona . '" height="' . ($alto - 8) . '" rx="4" fill="#f1f5f9"/>';
            $s .= '<rect x="' . $anchoEt . '" y="' . ($y + 4) . '" width="' . round($len, 1) . '" height="' . ($alto - 8) . '" rx="4" fill="' . $e(self::colorDe($d, (int) $i)) . '"/>';
            $pct = $total ? round($v * 100 / $total) : 0;
            $s .= '<text x="' . ($anchoEt + $len + 7) . '" y="' . ($y + $alto / 2 + 4) . '" font-size="11.5" fill="#0f172a" font-family="DejaVu Sans">' . $v . ($total ? ' · ' . $pct . '%' : '') . '</text>';
        }
        return $s . '</svg>';
    }

    public static function svgAnillo(array $datos, string $centro = '', string $titulo = ''): string
    {
        $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $sz = 210; $r = 88; $gr = 26; $cx = $sz / 2; $cy = $sz / 2;
        $total = array_sum(array_map(fn($d) => (int) ($d['v'] ?? 0), $datos));
        $s = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $sz . ' ' . $sz . '" width="' . $sz . '" height="' . $sz . '" role="img" aria-label="' . $e($titulo) . '">';
        if ($total <= 0) {
            return $s . '<text x="' . $cx . '" y="' . $cy . '" text-anchor="middle" font-size="12" fill="#64748b" font-family="DejaVu Sans">Sin datos</text></svg>';
        }
        $ang = -M_PI / 2;
        foreach ($datos as $i => $d) {
            $v = (int) ($d['v'] ?? 0);
            if ($v <= 0) { continue; }
            $barrido = $v / $total * M_PI * 2;
            // Una sola porción del 100 % no se puede dibujar con un arco: se usa un anillo completo.
            if ($v >= $total) {
                $s .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . ($r - $gr / 2) . '" fill="none" stroke="' . $e(self::colorDe($d, (int) $i)) . '" stroke-width="' . $gr . '"/>';
                break;
            }
            $fin = $ang + $barrido;
            $grande = $barrido > M_PI ? 1 : 0;
            $ri = $r - $gr;
            $p = sprintf('M %.2f %.2f A %d %d 0 %d 1 %.2f %.2f L %.2f %.2f A %d %d 0 %d 0 %.2f %.2f Z',
                $cx + $r * cos($ang), $cy + $r * sin($ang), $r, $r, $grande, $cx + $r * cos($fin), $cy + $r * sin($fin),
                $cx + $ri * cos($fin), $cy + $ri * sin($fin), $ri, $ri, $grande, $cx + $ri * cos($ang), $cy + $ri * sin($ang));
            $s .= '<path d="' . $p . '" fill="' . $e(self::colorDe($d, (int) $i)) . '" stroke="#fff" stroke-width="1.5"/>';
            $ang = $fin;
        }
        $s .= '<text x="' . $cx . '" y="' . ($cy - 2) . '" text-anchor="middle" font-size="26" font-weight="700" fill="#0f172a" font-family="DejaVu Sans">' . $total . '</text>';
        $s .= '<text x="' . $cx . '" y="' . ($cy + 16) . '" text-anchor="middle" font-size="11" fill="#64748b" font-family="DejaVu Sans">' . $e($centro ?: 'en total') . '</text>';
        return $s . '</svg>';
    }

    /** La etiqueta del mes, en palabras: «septiembre de 2026». */
    public static function nombreMes(?string $mes): string
    {
        if ($mes === null) { return 'todo el periodo del buzón (90 días)'; }
        $M = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        return $M[(int) substr($mes, 5, 2) - 1] . ' de ' . substr($mes, 0, 4);
    }
}
