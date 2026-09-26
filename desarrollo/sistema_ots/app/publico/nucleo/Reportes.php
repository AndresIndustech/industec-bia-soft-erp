<?php
declare(strict_types=1);

require_once __DIR__ . '/Casos.php';
require_once __DIR__ . '/Pendientes.php';
require_once __DIR__ . '/Novedades.php';
require_once __DIR__ . '/Ui.php';
require_once __DIR__ . '/Vocabulario.php';   // los rótulos que ve KFC son los mismos de la oficina

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
 *   zona: la administración elige (Las tres · UIO · LARB · CUENCA-LOJA); un
 *         jefe de zona recibe la suya aunque pida otra (el alcance manda).
 *   mes:  AAAA-MM sobre la fecha de creación de la orden en SAP; sin mes, todo
 *         el periodo que trae el buzón (los 90 días del correo).
 *
 * LAS MISMAS PALABRAS QUE LA OFICINA (vocabulario único, 24-sep-2026). Lo que
 * recibe KFC en Excel, PDF y PowerPoint nombra cada cosa con el término de
 * `vocabulario.json`, igual que las pantallas: «total de órdenes abiertas»,
 * «vencidas (48 h)», «ejecutado» y «atrasado» en el preventivo. Por eso los
 * rótulos de los gráficos y del semáforo se piden aquí a `Vocabulario::` y
 * no se escriben en cada formato.
 *
 * NADA SE ESTIMA (I-7). Si un insumo falta —el índice del archivo vacío, el
 * cronograma sin importar, ninguna orden con OT INDUSTEC— la sección lo dice y
 * la cifra sale como null, nunca como cero disfrazado.
 */
final class Reportes
{
    public const ZONAS = ['UIO', 'LARB', 'CNLJ'];

    /** Los mismos colores de graficos.js, para el PDF y las presentaciones. */
    public const COLOR_ZONA = ['UIO' => '#7c3aed', 'LARB' => '#0d9488', 'CNLJ' => '#ea580c', 'OTRA' => '#64748b'];
    public const COLOR_ESTADO = [
        'NUEVO' => '#94a3b8', 'ASIGNADO' => '#2a78d6', 'EN_REVISION' => '#eda100',
        'ESPERA_REPUESTO' => '#eb6834', 'ATENDIDO' => '#1baf7a', 'RESUELTO' => '#008300',
        'NO_COMPETE' => '#4a3aa7', 'CERRADO_SIN_ATENCION' => '#e34948', 'REGULARIZADO' => '#64748b',
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
     *               rendimiento, preventivo, casos_abiertos (las órdenes del TOTAL
     *               DE ÓRDENES ABIERTAS; la clave se conserva), archivo
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

        /* El TOTAL DE ÓRDENES ABIERTAS lo decide UNA sola función, la misma de la
           tarjeta «Por zona» del panel (`Casos::clasificar()`, que aplica
           `grupoOrden()` y cuenta una vez cada cadena de continuidad). Antes el
           reporte tenía su propio «siguen abiertos» —todo lo que no estaba
           cerrado, ATENDIDO incluido— y KFC recibía una cifra distinta de la que
           Isabel ve en pantalla con el mismo nombre. */
        $informes = Casos::informesPorAviso($gestion);
        $clasif   = Casos::clasificar($casos, $gestion, $informes);
        $otOk     = (bool) $informes['ot_disponible'];
        $equipoOk = (bool) $informes['equipo_disponible'];
        // Sin una fuente, la fila no se puede calcular: null, no 0 (I-7).
        $nAbiertas = $otOk ? 0 : null;
        $nEsperaInforme = $otOk ? 0 : null;
        $nDeshabilitados = $equipoOk ? 0 : null;

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

        // «Otros trabajos» (011): lo que INDUSTEC hizo para KFC fuera de su
        // área, autorizado por la administradora. Se reporta aparte, como extra.
        $otros = []; $otrosPorDecidir = 0;

        foreach ($casos as $c) {
            $aviso  = (string) ($c['aviso'] ?? '');
            $g      = $gestion[$aviso] ?? [];
            $estado = (string) ($g['estado'] ?? 'NUEVO');
            // Se cuenta por el estado de vista: un «sin atender» ya regularizado
            // no es la alarma roja. La lógica de abajo sigue usando el de la base.
            $vista = Ui::estadoVista($estado, $g);
            $porEstado[$vista] = ($porEstado[$vista] ?? 0) + 1;

            if (($g['otro_trabajo'] ?? null) === 'AUTORIZADO') {
                $otros[] = [
                    'aviso'         => $aviso,
                    'local'         => trim((string) ($c['local'] ?? '')),
                    'local_nombre'  => (string) ($c['local_nombre'] ?? ''),
                    'zona'          => (string) ($c['zona'] ?? ''),
                    'fecha'         => substr((string) ($c['fecha_creacion'] ?? ''), 0, 10),
                    'trabajo'       => (string) ($c['caso'] ?? ''),
                    'ot'            => (string) ($g['ot_cierre'] ?? ''),
                    // Por el estado de VISTA, como el gráfico: una orden cerrada
                    // sin atención y ya regularizada se lee «regularizada» aquí
                    // también, no «cerrada sin atención».
                    'estatus'       => Ui::etiquetaEstado($vista),
                    'acuerdo'       => (string) ($g['otro_trabajo_motivo'] ?? ''),
                    'autorizo'      => (string) ($g['otro_trabajo_nombre'] ?? ''),
                    'autorizado_en' => substr((string) ($g['otro_trabajo_en'] ?? ''), 0, 10),
                ];
            } elseif (Casos::otroTrabajoPorDecidir($c, $g ?: null)) {
                $otrosPorDecidir++;
            }

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

            // En el total: la misma regla que la tarjeta (ver `$clasif` arriba).
            $k = $clasif[$aviso] ?? ['grupo' => null, 'en_total' => false];
            $d = Ui::dias($c['fecha_creacion'] ?? null);
            if ($k['en_total']) {
                $abiertos++;
                if ($d !== null) {
                    if ($d <= 1)      { $edad['Hoy y ayer']++; }
                    elseif ($d <= 3)  { $edad['De 2 a 3 días']++; }
                    elseif ($d <= 7)  { $edad['De 4 a 7 días']++; }
                    else              { $edad['Más de una semana']++; }
                }
                if ($otOk && $k['grupo'] === 'ABIERTA') { $nAbiertas++; }
                elseif ($otOk)                          { $nEsperaInforme++; }
                $equipo = $equipoOk ? Casos::estadoEquipo($aviso, $informes) : null;
                if ($equipo === 'DESHABILITADO') { $nDeshabilitados++; }
                // La hoja «TOTAL DE ÓRDENES ABIERTAS» con las columnas del plan de
                // zona y el semáforo de la administración (amarillo le toca a
                // INDUSTEC, naranja le toca a KFC, verde repuesto en seguimiento,
                // rojo emergente). El verde es ESPERA_REPUESTO sin ninguna solicitud
                // a espera de KFC: incluye las por validar, por registrar en SAP, en
                // taller de INDUSTEC, despachadas y sin solicitud, así que su rótulo
                // (LE_TOCA_KFC_REPUESTO) NO nombra responsable. Partirlo por quién
                // debe la acción es lógica pendiente de Andrés con Isabel. El rojo
                // cuenta días desde que LLEGÓ la orden (o ALTA sin asignar), no
                // desde el último movimiento: así lo dice la ayuda de EMERGENTE.
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
                    'estatus'   => Ui::etiquetaEstado($vista),
                    'estado'    => $estado,
                    // La fila de la tarjeta en que cae, con su mismo rótulo. Sin las
                    // fuentes de OT no se sabe si es abierta o a espera (I-7).
                    'grupo'     => $otOk ? Vocabulario::titulo((string) $k['grupo']) : 'no disponible',
                    // El equipo por su evidencia más reciente, como la fila
                    // EQUIPOS DESHABILITADOS: vacío es «sin dato», nunca Operativo.
                    'equipo_estado' => !$equipoOk ? 'no disponible'
                        : Vocabulario::t(Vocabulario::deEstado((string) $equipo, 'estado_equipo')),
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

        /* --- Se concluye en una visita ------------------------------------------
           Antes salía de `pendientes`: «con informe y sin pendiente» era «una
           visita». Esa tabla solo la llena la app nueva y el 2026-09-22 tenía 4
           filas, todas de prueba, así que el tablero decía 100 % (110 de 110)
           cuando 80 de esos casos tenían la orden ABIERTA: la visita no cerró el
           trabajo. Ahora se mide con las órdenes del propio caso; ver unaVisita(). */
        $concluidos = 0; $concluyeUna = 0; $enCurso = 0; $conPendiente = 0;
        foreach ($casos as $c) {
            $av = (string) ($c['aviso'] ?? '');
            if (!isset($aten[$av])) { continue; }
            $una = self::unaVisita($aten[$av], isset($trabados[$av]));
            if ($una === null) { $enCurso++; continue; }
            $concluidos++;
            if ($una) { $concluyeUna++; }
            if (isset($trabados[$av])) { $conPendiente++; }
        }
        $pctConcluye = $concluidos > 0 ? (int) round($concluyeUna * 100 / $concluidos) : null;

        /* --- Rendimiento por técnico (TR-03) --------------------------------- */
        $rendimiento = self::rendimiento($casos, $gestion, $aten, $trabados, $porAsignado, $zona, $mes);

        /* --- 48 h, novedades, preventivo, archivo ----------------------------- */
        $c48 = Pendientes::cumplimiento48($zona);
        $novedades = self::novedades($zona, $mes);
        $preventivo = self::preventivo($zona, $mes);
        $archivo = self::archivo($zona, $mes);

        /* --- Los arreglos que consumen los gráficos --------------------------- */
        // El color va por la clave (CNLJ); lo que se lee es el rótulo (CUENCA-LOJA).
        $dZona = [];
        foreach ($porZona as $z => $n) { if ($n > 0) { $dZona[] = ['e' => self::rotuloZona($z), 'v' => $n, 'c' => self::COLOR_ZONA[$z]]; } }
        if ($sinZona > 0) { $dZona[] = ['e' => self::rotuloZona(''), 'v' => $sinZona, 'c' => '#94a3b8']; }

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
        // Los cuatro tramos del plazo, con los nombres de la oficina: KFC lee
        // «Vencidas (48 h)», no «Vencidos ahora» ni «Reloj corriendo».
        $d48 = [
            ['e' => Vocabulario::titulo('VALIDADA') . ' a tiempo',  'v' => $c48['a_tiempo'],  'c' => '#1baf7a'],
            ['e' => Vocabulario::titulo('VALIDADA') . ' tarde',     'v' => $c48['tarde'],     'c' => '#eda100'],
            ['e' => Vocabulario::titulo('POR_VALIDAR') . ', a tiempo', 'v' => $c48['corriendo'], 'c' => '#2a78d6'],
            ['e' => Vocabulario::titulo('VENCIDO_48H'),             'v' => $c48['vencidos'],  'c' => '#e34948'],
        ];

        return [
            'meta' => [
                'zona' => $zona, 'mes' => $mes, 'hoy' => $hoy,
                'generado' => (string) ($fuente['generado'] ?? ''),
                'casos' => count($casos), 'hay_fuente' => (bool) $fuente,
                'alcance_fijo' => $za !== null,
            ],
            // `abiertos` es el TOTAL DE ÓRDENES ABIERTAS; `abiertas`, `espera_informe`
            // y `deshabilitados` son las otras tres filas de la tarjeta (null si
            // falta la fuente que las decide).
            'salud' => ['con_informe' => $conInforme, 'concluidos' => $concluidos, 'concluye_una' => $concluyeUna,
                        'en_curso' => $enCurso, 'con_pendiente' => $conPendiente,
                        'pct_concluye' => $pctConcluye, 'abiertos' => $abiertos,
                        'abiertas' => $nAbiertas, 'espera_informe' => $nEsperaInforme,
                        'deshabilitados' => $nDeshabilitados],
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
            // 011: los extras autorizados, y los fuera del área que esperan decisión.
            'otros_trabajos' => $otros,
            'otros_por_decidir' => $otrosPorDecidir,
        ];
    }

    /**
     * ¿El caso se cerró en una sola visita? Con las órdenes que trae el informe.
     *
     *   null  → todavía no se cerró: ninguna orden «Cerrada» (la visita dejó la
     *           orden abierta). No entra al porcentaje: no se sabe aún.
     *   true  → se cerró, todas sus órdenes son del mismo día y no dejó equipo
     *           trabado en `pendientes`.
     *   false → se cerró, pero hizo falta volver otro día o quedó un equipo trabado.
     *
     * Se cuentan DÍAS, no órdenes: INDUSTEC emite una orden por equipo, y R001EC
     * tiene dos del mismo 18 de septiembre que fueron una sola visita.
     */
    private static function unaVisita(array $atencion, bool $trabado): ?bool
    {
        if (($atencion['estado_industec'] ?? '') !== 'CERRADA') { return null; }
        $dias = [];
        foreach ($atencion['ots'] ?? [] as $o) {
            $f = substr((string) ($o['fecha'] ?? ''), 0, 10);
            if ($f !== '') { $dias[$f] = true; }
        }
        return count($dias) <= 1 && !$trabado;
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
            $conInf = 0; $cerrados = 0; $unaVisita = 0; $sumaDias = 0; $nDias = 0; $abiertosAhora = 0;
            foreach ($avisos as $av) {
                $g = $gestion[$av] ?? [];
                if (in_array((string) ($g['estado'] ?? ''), ['ASIGNADO', 'ESPERA_REPUESTO'], true)) { $abiertosAhora++; }
                if (isset($aten[$av])) {
                    $conInf++;
                    $una = self::unaVisita($aten[$av], isset($trabados[$av]));
                    if ($una !== null) { $cerrados++; }
                    if ($una === true) { $unaVisita++; }
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
                'asignados' => $asignados, 'con_informe' => $conInf, 'cerrados' => $cerrados, 'una_visita' => $unaVisita,
                'pct_una_visita' => $cerrados > 0 ? (int) round($unaVisita * 100 / $cerrados) : null,
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
            // Las órdenes del módulo «Otros»: extras que INDUSTEC hace para KFC dentro
            // del mismo trato (Andrés, 2026-09-14). Van con los «otros trabajos».
            $sqlOtros = "SELECT COUNT(*) n FROM ot_archivo WHERE $donde AND modulo = 'OTROS'";
            $parOtros = $par;
            if ($mes !== null) { $sqlOtros .= " AND DATE_FORMAT(fecha_atencion, '%Y-%m') = ?"; $parOtros[] = $mes; }
            return ['total' => (int) ($tot['n'] ?? 0), 'en_servidor' => (int) ($tot['s'] ?? 0), 'mes' => $mesN,
                    'modulo_otros' => (int) (Db::uno($sqlOtros, $parOtros)['n'] ?? 0)];
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
                'cumplidos_a_tiempo' => 0, 'cumplidos_tarde' => 0, 'vencidos' => 0, 'en_curso' => 0, 'sin_cierre' => 0,
                'por_iniciar' => 0, 'planificados' => 0, 'sin_agendar' => 0, 'reagendados' => 0,
                'kits_confirmados' => 0, 'pct_a_tiempo' => null, 'por_zona' => [], 'motivos' => [], 'estados' => []];
        if ($src['fuente'] === null) { return $out; }
        $hoy = date('Y-m-d');
        foreach ($src['ingresos'] as $i) {
            $pv = $i['plan_vigente'] ?? null;
            if ($mes !== null && substr((string) ($pv['inicio'] ?? ''), 0, 7) !== $mes) { continue; }
            $e = self::estadoPreventivo($i, $hoy);
            if ($e === 'cancelado') { continue; }
            /* El mismo corte que cronograma.js: un ingreso «en curso» cuyo fin
               previsto ya pasó está «atrasado, por marcar como ejecutado»
               (PREV_SIN_CIERRE), no en ejecución. `estadoPreventivo()` sigue
               devolviendo 'encurso' porque es el contrato con cronograma.js, que
               hace este mismo corte del lado del navegador. Sin esto KFC leía
               «en ejecución» ingresos de hace dos meses. */
            if ($e === 'encurso') {
                $finPrev = (string) ($pv['fin'] ?? $pv['inicio'] ?? '');
                if ($finPrev !== '' && $finPrev < $hoy) { $e = 'sincerrar'; }
            }
            $z = (string) ($i['zona'] ?? '');
            $pz = &$out['por_zona'][$z !== '' ? $z : 'Sin zona'];
            $pz = $pz ?? ['total' => 0, 'cumplidos' => 0, 'a_tiempo' => 0, 'vencidos' => 0, 'sin_agendar' => 0, 'en_curso' => 0, 'sin_cierre' => 0];
            $out['total']++; $pz['total']++;
            if ($e === 'cumplido') {
                $finReal = (string) ($i['real']['fin'] ?? $i['real']['inicio'] ?? '');
                $finPlan = (string) ($i['plan_original']['fin'] ?? $i['plan_original']['inicio'] ?? '');
                $aTiempo = $finPlan === '' || ($finReal !== '' && $finReal <= $finPlan);
                $out[$aTiempo ? 'cumplidos_a_tiempo' : 'cumplidos_tarde']++;
                $pz['cumplidos']++; if ($aTiempo) { $pz['a_tiempo']++; }
            } elseif ($e === 'vencido')     { $out['vencidos']++; $pz['vencidos']++; }
            elseif ($e === 'encurso')       { $out['en_curso']++; $pz['en_curso']++; }
            elseif ($e === 'sincerrar')     { $out['sin_cierre']++; $pz['sin_cierre']++; }
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
        // La leyenda del jefe técnico (EJECUTADO · PENDIENTE · ATRASADO), con los
        // términos del diccionario: «Cumplidos», «Vencidos» y «En curso» eran
        // nombres que solo tenía este reporte.
        $ejecutados = self::mayuscula(Vocabulario::t('PREV_EJECUTADO', 2));
        $out['estados'] = [
            ['e' => $ejecutados . ' a tiempo',                         'v' => $out['cumplidos_a_tiempo'], 'c' => '#1baf7a'],
            ['e' => $ejecutados . ' tarde',                            'v' => $out['cumplidos_tarde'],    'c' => '#eda100'],
            ['e' => self::mayuscula(Vocabulario::t('ATRASADO', 2)),    'v' => $out['vencidos'],           'c' => '#e34948'],
            ['e' => Vocabulario::titulo('PREV_SIN_CIERRE'),            'v' => $out['sin_cierre'],         'c' => '#eb6834'],
            ['e' => Vocabulario::titulo('PREV_EN_EJECUCION'),          'v' => $out['en_curso'],           'c' => '#2a78d6'],
            ['e' => Vocabulario::titulo('PREV_SIN_AGENDAR'),           'v' => $out['sin_agendar'],        'c' => '#94a3b8'],
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

    /**
     * La zona como se LEE en tablas y gráficos: UIO, LARB, CUENCA-LOJA, OTRA o
     * «sin zona» (la forma corta del diccionario, la misma del chip de las
     * pantallas). CNLJ sigue siendo la clave de la base y del color; lo que ve
     * KFC es CUENCA-LOJA (decisión del 24-sep-2026). Un código que el
     * diccionario no conoce se muestra tal como vino: es el dato, no un nombre
     * inventado para taparlo (I-7).
     */
    public static function rotuloZona(?string $z): string
    {
        $k = strtoupper(trim((string) $z));
        if ($k === 'SIN ZONA') { $k = ''; }            // la clave con que el preventivo agrupa lo que no tiene zona
        $mapa = Vocabulario::mapas()['zona'] ?? [];
        return array_key_exists($k, $mapa) ? Vocabulario::corto($mapa[$k]) : (string) $z;
    }

    /**
     * El texto de cada color del semáforo del plan de zona, del diccionario:
     * antes lo escribían a mano, y distinto, `reporte_exportar.php` y
     * `reporte_pdf.php` («Pendiente INDUSTEC», «Emergente / vencido»).
     *
     * @return array<string,string> amarillo|naranja|verde|rojo => rótulo
     */
    public static function semaforo(): array
    {
        $out = [];
        foreach (['amarillo', 'naranja', 'verde', 'rojo'] as $color) {
            $out[$color] = Vocabulario::titulo(Vocabulario::deEstado($color, 'semaforo'));
        }
        return $out;
    }

    /** Primera letra en mayúscula («ejecutados» → «Ejecutados»), sin tocar el resto. */
    public static function mayuscula(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($s, 1, null, 'UTF-8');
    }
}
