<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Reportes.php';
require_once __DIR__ . '/nucleo/reporte_pdf.php';

/**
 * reporte_exportar.php — El reporte en Excel, PDF o PowerPoint (T2.14.5, D14).
 *
 * Los tres salen de `Reportes::calcular()`, los mismos números que la pantalla.
 * Andrés pidió los formatos que Grupo KFC exige, profesionales y con la marca:
 *
 *   xlsx  PhpSpreadsheet: una hoja por sección; encabezado INDUSTEC con zona,
 *         mes, quién y cuándo; la hoja «Casos abiertos» con las columnas del
 *         plan de zona que la administración ya conoce y su semáforo
 *         (amarillo INDUSTEC · naranja KFC · verde repuestos SAP · rojo vencido).
 *   pdf   dompdf sobre `nucleo/reporte_pdf.php`, con los gráficos en SVG.
 *   pptx  PhpPresentation: portada, salud, volumen, 48 h, rendimiento,
 *         preventivo y cierre.
 *
 * Las librerías viven fuera de la carpeta web (`~/lib/ot/vendor`, como dompdf).
 * Si falta una, se responde 503 diciendo cuál, y queda en la bitácora: nunca
 * un 500 mudo. Cada exportación queda como EXPORTAR_REPORTE.
 */

$u = Auth::exigir('reportes.ver');

$formato = strtolower((string) ($_GET['formato'] ?? ''));
if (!in_array($formato, ['xlsx', 'pdf', 'pptx'], true)) {
    http_response_code(400);
    exit('Formato no válido: xlsx, pdf o pptx.');
}
$zonaGet = strtoupper((string) ($_GET['zona'] ?? ''));
$mesGet  = (string) ($_GET['mes'] ?? '');
$r = Reportes::calcular($zonaGet !== '' ? $zonaGet : null, $mesGet !== '' ? $mesGet : null);
$zona = $r['meta']['zona']; $mes = $r['meta']['mes'];
$nombre = 'reporte_industec_' . ($zona ?: 'tres_zonas') . '_' . ($mes ?: 'periodo') . '_' . date('Ymd');

/** Carga el autoload de las librerías, como Emision::cargarDompdf(). */
function cargarLibreria(string $clase, string $nombre): void
{
    if (class_exists($clase)) { return; }
    $cfg = Db::config();
    foreach (array_filter([
        $cfg['dompdf_autoload'] ?? null,
        dirname(__DIR__, 4) . '/lib/ot/vendor/autoload.php',   // Hostinger: ~/lib/ot, fuera de public_html
        dirname(__DIR__, 1) . '/lib/vendor/autoload.php',      // estación: app/lib
    ]) as $a) {
        if (is_file($a)) { require_once $a; break; }
    }
    if (!class_exists($clase)) {
        Auth::bitacora('EXPORTAR_REPORTE', 'reportes', $nombre, 'la librería no está instalada en el servidor',
                       null, null, ['clase' => $clase], false);
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        exit('La librería ' . $nombre . ' no está instalada en el servidor (ver app/lib/LEEME.md).');
    }
}

$titulo = 'Reporte de servicio · ' . ($zona ? 'Zona ' . $zona : 'Las tres zonas') . ' · ' . ucfirst(Reportes::nombreMes($mes));
$SEM = ['amarillo' => 'FDE68A', 'naranja' => 'FDBA74', 'verde' => 'BBF7D0', 'rojo' => 'FECACA'];
$SEM_T = ['amarillo' => 'Pendiente INDUSTEC', 'naranja' => 'Pendiente KFC', 'verde' => 'Pendiente repuestos SAP', 'rojo' => 'Emergente / vencido'];
$s = $r['salud']; $c48 = $r['c48']; $pre = $r['preventivo']; $nov = $r['novedades'];

Auth::bitacora('EXPORTAR_REPORTE', 'reportes', $formato, $titulo, null, null,
               ['zona' => $zona, 'mes' => $mes, 'casos' => $r['meta']['casos']]);

/* ======================================================================= PDF */
if ($formato === 'pdf') {
    cargarLibreria(\Dompdf\Dompdf::class, 'dompdf');
    $opt = new \Dompdf\Options();
    $opt->set('isRemoteEnabled', false);
    $opt->set('isHtml5ParserEnabled', true);
    $opt->set('defaultFont', 'DejaVu Sans');
    $opt->set('dpi', 96);
    $d = new \Dompdf\Dompdf($opt);
    $d->loadHtml(reportePdfHtml($r, $u), 'UTF-8');
    $d->setPaper('A4', 'portrait');
    $d->render();
    $pdf = (string) $d->output();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $nombre . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store');
    echo $pdf;
    exit;
}

/* ====================================================================== XLSX */
if ($formato === 'xlsx') {
    cargarLibreria(\PhpOffice\PhpSpreadsheet\Spreadsheet::class, 'PhpSpreadsheet');
    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ss->getProperties()->setCreator('INDUSTEC · B.IA Soft ERP')->setTitle($titulo)->setCompany('INDUSTEC');
    $AZUL = '1F4E79';

    $hoja = static function (\PhpOffice\PhpSpreadsheet\Spreadsheet $ss, string $nombreHoja, bool $primera = false) use ($titulo, $u, $AZUL) {
        $ws = $primera ? $ss->getActiveSheet() : $ss->createSheet();
        $ws->setTitle(mb_substr($nombreHoja, 0, 31));
        $ws->setCellValue('A1', 'INDUSTEC · Servicio técnico');
        $ws->setCellValue('A2', $titulo);
        $ws->setCellValue('A3', 'Generado por ' . $u['nombre'] . ' el ' . date('d/m/Y H:i') . ' · B.IA Soft ERP');
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB($AZUL);
        $ws->getStyle('A2')->getFont()->setBold(true)->setSize(11);
        $ws->getStyle('A3')->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('555555');
        return $ws;
    };
    $tabla = static function ($ws, int $fila, array $cab, array $filas, array $anchos = []) use ($AZUL): int {
        $col = 'A';
        foreach ($cab as $c) { $ws->setCellValue($col . $fila, $c); $col++; }
        $ultima = chr(ord('A') + max(0, count($cab) - 1));
        $ws->getStyle("A$fila:$ultima$fila")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $ws->getStyle("A$fila:$ultima$fila")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($AZUL);
        $f = $fila + 1;
        foreach ($filas as $fl) {
            $col = 'A';
            foreach ($fl as $v) { $ws->setCellValue($col . $f, $v); $col++; }
            $f++;
        }
        if ($filas) {
            $ws->getStyle("A" . ($fila + 1) . ":$ultima" . ($f - 1))->getBorders()->getAllBorders()
               ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setRGB('D9DEE5');
        }
        foreach ($anchos as $c => $a) { $ws->getColumnDimension($c)->setWidth($a); }
        if (!$anchos) { for ($c = 'A'; $c <= $ultima; $c++) { $ws->getColumnDimension($c)->setAutoSize(true); } }
        $ws->freezePane('A' . ($fila + 1));
        return $f + 1;
    };

    // --- Resumen ---
    $ws = $hoja($ss, 'Resumen', true);
    $f = 5;
    $f = $tabla($ws, $f, ['Indicador', 'Valor', 'Cómo se lee'], [
        ['Casos del periodo', $r['meta']['casos'], $mes ? 'creados en ' . Reportes::nombreMes($mes) : 'ventana de 90 días del buzón'],
        ['Siguen abiertos', $s['abiertos'], 'INDUSTEC no los ha cerrado'],
        ['Con informe', $s['con_informe'], 'casos con al menos una orden'],
        ['Con orden de cierre', $s['concluidos'], 'la visita cerró el trabajo'],
        ['Con la orden abierta', $s['en_curso'], 'hubo visita, falta la orden de cierre'],
        ['Se concluye en una visita', $s['pct_concluye'] === null ? 'sin dato' : $s['pct_concluye'] . '%', 'de los casos con orden de cierre: el mismo día y sin equipo trabado'],
        ['Repuestos: validados a tiempo', $c48['a_tiempo'], 'dentro de las 48 h'],
        ['Repuestos: validados tarde', $c48['tarde'], 'después de las 48 h'],
        ['Repuestos: reloj corriendo', $c48['corriendo'], 'todavía a tiempo'],
        ['Repuestos: vencidos ahora', $c48['vencidos'], 'parados, sin validar, más de 48 h'],
        ['Preventivo: cumplidos a tiempo', $pre['fuente'] === null ? 'sin cronograma' : ($pre['pct_a_tiempo'] === null ? 'sin dato' : $pre['pct_a_tiempo'] . '%'), 'contra el plan acordado con KFC'],
        ['Novedades con aviso en SAP', $nov['con_aviso'], 'de ' . $nov['total'] . ' reportadas'],
        ['Órdenes en el archivo', $r['archivo']['total'] ?? 'sin índice', 'índice del archivo general'],
        ['Otros trabajos autorizados', count($r['otros_trabajos']), 'fuera del área, por acuerdo con KFC (hoja «Otros trabajos»)'],
    ], ['A' => 34, 'B' => 16, 'C' => 48]);
    $f = $tabla($ws, $f, ['Antigüedad de lo abierto', 'Casos'], array_map(fn($x) => [$x['e'], $x['v']], $r['edad']));

    // --- Casos abiertos (plan de zona) ---
    $ws = $hoja($ss, 'Casos abiertos');
    $ws->setCellValue('A5', 'Semáforo: amarillo = pendiente INDUSTEC · naranja = pendiente KFC · verde = pendiente repuestos SAP · rojo = emergente / vencido');
    $ws->getStyle('A5')->getFont()->setSize(9)->setItalic(true);
    $filas = array_map(fn($c) => [$c['aviso'], $c['tecnico'] ?: '', $c['local'], $c['local_nombre'], $c['zona'], $c['fecha'],
                                   $c['equipo'] ?: $c['trabajo'], $c['estatus'], $c['dias'], $c['prioridad'], $c['observaciones'], $SEM_T[$c['semaforo']] ?? ''],
                       $r['casos_abiertos']);
    $tabla($ws, 6, ['# OT (aviso)', 'TÉCNICO', 'LOCAL', 'NOMBRE DEL LOCAL', 'ZONA', 'FECHA DE INICIO', 'EQUIPO', 'ESTATUS', 'DÍAS', 'PRIORIDAD', 'OBSERVACIONES', 'SEMÁFORO'], $filas,
           ['A' => 14, 'B' => 26, 'C' => 10, 'D' => 28, 'E' => 8, 'F' => 14, 'G' => 30, 'H' => 22, 'I' => 7, 'J' => 10, 'K' => 50, 'L' => 24]);
    foreach ($r['casos_abiertos'] as $k => $c) {
        $fila = 7 + $k;
        $ws->getStyle("A$fila:L$fila")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($SEM[$c['semaforo']] ?? 'FFFFFF');
    }
    $ws->setAutoFilter('A6:L' . max(7, 6 + count($filas)));

    // --- Por zona y estado ---
    $ws = $hoja($ss, 'Por zona');
    $f = $tabla($ws, 5, ['Zona', 'Casos'], array_map(fn($x) => [$x['e'], $x['v']], $r['zonas']), ['A' => 22, 'B' => 12]);
    $f = $tabla($ws, $f, ['Estado', 'Casos'], array_map(fn($x) => [$x['e'], $x['v']], $r['estados']));
    $f = $tabla($ws, $f, ['Mes', 'Casos'], array_map(fn($x) => [$x['e'], $x['v']], $r['meses']));
    $tabla($ws, $f, ['Cadena', 'Casos'], array_map(fn($x) => [$x['e'], $x['v']], $r['cadenas']));

    // --- Rendimiento por técnico ---
    $ws = $hoja($ss, 'Rendimiento técnicos');
    $tabla($ws, 5, ['Técnico', 'Zona', 'Activo', 'Asignados', 'Con informe', 'Con orden de cierre', 'En una visita', '% en una visita', 'Días a la 1.ª atención', 'Abiertos ahora', 'Repuestos vencidos', 'Novedades', 'Órdenes por la app'],
           array_map(fn($t) => [$t['nombre'], $t['zona'], $t['activo'] ? 'Sí' : 'No', $t['asignados'], $t['con_informe'], $t['cerrados'], $t['una_visita'],
                                $t['pct_una_visita'] === null ? '' : $t['pct_una_visita'] . '%', $t['dias_primera'] ?? '', $t['abiertos_ahora'],
                                $t['pendientes_vencidos'], $t['novedades'], $t['ordenes_app']], $r['rendimiento']),
           ['A' => 30, 'B' => 8, 'C' => 8, 'D' => 11, 'E' => 12, 'F' => 18, 'G' => 13, 'H' => 15, 'I' => 20, 'J' => 14, 'K' => 18, 'L' => 11, 'M' => 17]);

    // --- Cumplimiento 48 h ---
    $ws = $hoja($ss, 'Cumplimiento 48h');
    $tabla($ws, 5, ['Qué', 'Equipos'], array_map(fn($x) => [$x['e'], $x['v']], $r['d48']), ['A' => 26, 'B' => 12]);

    // --- Preventivo ---
    $ws = $hoja($ss, 'Preventivo');
    if ($pre['fuente'] === null) {
        $ws->setCellValue('A5', 'Sin cronograma cargado en este corte.');
    } else {
        $f = $tabla($ws, 5, ['Indicador', 'Valor'], [
            ['Ingresos del periodo', $pre['total']], ['Cumplidos a tiempo', $pre['cumplidos_a_tiempo']],
            ['Cumplidos tarde', $pre['cumplidos_tarde']], ['Vencidos', $pre['vencidos']], ['En curso', $pre['en_curso']],
            ['Por iniciar (≤ 3 días)', $pre['por_iniciar']], ['Planificados', $pre['planificados']], ['Sin agendar', $pre['sin_agendar']],
            ['Reagendados', $pre['reagendados']], ['Kits confirmados', $pre['kits_confirmados']],
            ['% a tiempo (contra el plan acordado)', $pre['pct_a_tiempo'] === null ? 'sin dato' : $pre['pct_a_tiempo'] . '%'],
            ['Fuente', $pre['fuente'] === 'tabla' ? 'base de datos' : 'archivo de la estación (sin importar)'],
        ], ['A' => 36, 'B' => 16, 'C' => 12, 'D' => 12, 'E' => 12, 'F' => 12, 'G' => 12]);
        $f = $tabla($ws, $f, ['Zona', 'Ingresos', 'Cumplidos', 'A tiempo', 'Vencidos', 'En curso', 'Sin agendar'],
                    array_map(fn($z, $pz) => [$z, $pz['total'], $pz['cumplidos'], $pz['a_tiempo'], $pz['vencidos'], $pz['en_curso'], $pz['sin_agendar']],
                              array_keys($pre['por_zona']), $pre['por_zona']));
        if ($pre['motivos']) { $tabla($ws, $f, ['Motivo de reagenda', 'Veces'], array_map(fn($x) => [$x['e'], $x['v']], $pre['motivos'])); }
    }

    // --- Novedades y locales ---
    $ws = $hoja($ss, 'Novedades');
    $f = $tabla($ws, 5, ['Área', 'Novedades'], array_map(fn($x) => [$x['e'], $x['v']], $nov['por_area']), ['A' => 34, 'B' => 12]);
    $tabla($ws, $f, ['Indicador', 'Valor'], [['Reportadas', $nov['total']], ['Sin decidir', $nov['pendientes']], ['De riesgo alto sin decidir', $nov['alto']],
                                            ['De otras áreas sin decidir', $nov['ajenas']], ['Con aviso en SAP', $nov['con_aviso']]]);
    $ws = $hoja($ss, 'Locales');
    $f = $tabla($ws, 5, ['Local', 'Casos'], array_map(fn($x) => [$x['e'], $x['v']], $r['locales']), ['A' => 14, 'B' => 12, 'C' => 40]);
    $f = $tabla($ws, $f, ['Locales que repiten (5 o más)', 'Casos'], array_map(fn($l, $c) => [$l, $c], array_keys($r['reincidentes']), $r['reincidentes']));
    $tabla($ws, $f, ['Qué se pide', 'Casos'], array_map(fn($x) => [$x['e'], $x['v']], $r['tipos']));

    // --- Otros trabajos (011): los extras para KFC, autorizados por la administración ---
    $ws = $hoja($ss, 'Otros trabajos');
    $ws->setCellValue('A5', 'Trabajos fuera del área de INDUSTEC hechos por acuerdo con Grupo KFC y autorizados por la administración. '
        . (int) $r['otros_por_decidir'] . ' casos fuera del área esperan decisión.'
        . (($r['archivo']['modulo_otros'] ?? 0) ? ' Órdenes del módulo «Otros» en el Archivo: ' . (int) $r['archivo']['modulo_otros'] . '.' : ''));
    $ws->getStyle('A5')->getFont()->setSize(9)->setItalic(true);
    $tabla($ws, 6, ['AVISO', 'FECHA', 'LOCAL', 'NOMBRE DEL LOCAL', 'ZONA', 'TRABAJO', 'ORDEN', 'ESTATUS', 'ACUERDO CON KFC', 'AUTORIZÓ', 'AUTORIZADO EL'],
           array_map(fn($o) => [$o['aviso'], $o['fecha'], $o['local'], $o['local_nombre'], $o['zona'], $o['trabajo'], $o['ot'],
                                $o['estatus'], $o['acuerdo'], $o['autorizo'], $o['autorizado_en']], $r['otros_trabajos']),
           ['A' => 14, 'B' => 12, 'C' => 10, 'D' => 28, 'E' => 8, 'F' => 24, 'G' => 32, 'H' => 16, 'I' => 50, 'J' => 26, 'K' => 14]);

    $ss->setActiveSheetIndex(0);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nombre . '.xlsx"');
    header('Cache-Control: private, no-store');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
    exit;
}

/* ====================================================================== PPTX */
cargarLibreria(\PhpOffice\PhpPresentation\PhpPresentation::class, 'PhpPresentation');
$p = new \PhpOffice\PhpPresentation\PhpPresentation();
$p->getLayout()->setDocumentLayout(\PhpOffice\PhpPresentation\DocumentLayout::LAYOUT_SCREEN_16X9, true);
$p->getDocumentProperties()->setCreator('INDUSTEC · B.IA Soft ERP')->setTitle($titulo)->setCompany('INDUSTEC');
$W = 960; $H = 540;
$AZUL = 'FF1F4E79'; $GRIS = 'FF555555'; $NEGRO = 'FF0F172A';
$logoRuta = __DIR__ . '/nucleo/logo-industec.png';

$texto = static function ($slide, string $t, int $x, int $y, int $w, int $h, int $tam, bool $negrita = false, string $color = 'FF0F172A', $alinear = null) {
    $sh = $slide->createRichTextShape()->setHeight($h)->setWidth($w)->setOffsetX($x)->setOffsetY($y);
    $sh->getActiveParagraph()->getAlignment()->setHorizontal($alinear ?: \PhpOffice\PhpPresentation\Style\Alignment::HORIZONTAL_LEFT);
    $run = $sh->createTextRun($t);
    $run->getFont()->setBold($negrita)->setSize($tam)->setColor(new \PhpOffice\PhpPresentation\Style\Color($color));
    return $sh;
};
$cabecera = static function ($slide, string $tituloDiapo) use ($texto, $AZUL, $GRIS, $logoRuta, $W, $titulo) {
    $barra = $slide->createRichTextShape()->setHeight(6)->setWidth($W)->setOffsetX(0)->setOffsetY(0);
    $barra->getFill()->setFillType(\PhpOffice\PhpPresentation\Style\Fill::FILL_SOLID)->setStartColor(new \PhpOffice\PhpPresentation\Style\Color($AZUL));
    if (is_file($logoRuta)) {
        $img = $slide->createDrawingShape();
        $img->setPath($logoRuta)->setHeight(34)->setOffsetX(36)->setOffsetY(20);
    }
    $texto($slide, $tituloDiapo, 36, 60, $W - 72, 40, 24, true, $AZUL);
    $texto($slide, 'INDUSTEC · ' . $titulo, 36, 505, $W - 72, 20, 9, false, $GRIS);
};
$tablaP = static function ($slide, array $cab, array $filas, int $x, int $y, int $w, array $anchos = []) use ($AZUL) {
    $t = $slide->createTableShape(count($cab));
    $t->setWidth($w)->setOffsetX($x)->setOffsetY($y);
    $fila = $t->createRow();
    foreach ($cab as $i => $c) {
        $cell = $fila->nextCell();
        $cell->createTextRun((string) $c)->getFont()->setBold(true)->setSize(11)->setColor(new \PhpOffice\PhpPresentation\Style\Color('FFFFFFFF'));
        $cell->getFill()->setFillType(\PhpOffice\PhpPresentation\Style\Fill::FILL_SOLID)->setStartColor(new \PhpOffice\PhpPresentation\Style\Color($AZUL));
        if (isset($anchos[$i])) { $cell->setWidth($anchos[$i]); }
    }
    foreach ($filas as $k => $fl) {
        $fila = $t->createRow();
        foreach ($fl as $i => $v) {
            $cell = $fila->nextCell();
            $cell->createTextRun((string) $v)->getFont()->setSize(11);
            if ($k % 2 === 1) { $cell->getFill()->setFillType(\PhpOffice\PhpPresentation\Style\Fill::FILL_SOLID)->setStartColor(new \PhpOffice\PhpPresentation\Style\Color('FFF2F2F2')); }
            if (isset($anchos[$i])) { $cell->setWidth($anchos[$i]); }
        }
    }
    return $t;
};
$kpisP = static function ($slide, array $kpis, int $y = 120) use ($texto, $W, $GRIS) {
    $n = count($kpis); $w = (int) (($W - 72 - ($n - 1) * 16) / $n);
    foreach ($kpis as $i => [$v, $t, $color]) {
        $x = 36 + $i * ($w + 16);
        $caja = $slide->createRichTextShape()->setHeight(110)->setWidth($w)->setOffsetX($x)->setOffsetY($y);
        $caja->getFill()->setFillType(\PhpOffice\PhpPresentation\Style\Fill::FILL_SOLID)->setStartColor(new \PhpOffice\PhpPresentation\Style\Color('FFF6F8FB'));
        $caja->getBorder()->setLineStyle(\PhpOffice\PhpPresentation\Style\Border::LINE_SINGLE)->setColor(new \PhpOffice\PhpPresentation\Style\Color('FFD9DEE5'));
        $texto($slide, $v, $x, $y + 12, $w, 50, 34, true, $color, \PhpOffice\PhpPresentation\Style\Alignment::HORIZONTAL_CENTER);
        $texto($slide, $t, $x, $y + 66, $w, 36, 11, false, $GRIS, \PhpOffice\PhpPresentation\Style\Alignment::HORIZONTAL_CENTER);
    }
};
$sem = static fn(?int $pct, int $bien = 85, int $ojo = 70): string => $pct === null ? 'FF777777' : ($pct >= $bien ? 'FF166534' : ($pct >= $ojo ? 'FF92400E' : 'FF991B1B'));

// 1. Portada
$slide = $p->getActiveSlide();
$fondo = $slide->createRichTextShape()->setHeight($H)->setWidth(300)->setOffsetX(0)->setOffsetY(0);
$fondo->getFill()->setFillType(\PhpOffice\PhpPresentation\Style\Fill::FILL_SOLID)->setStartColor(new \PhpOffice\PhpPresentation\Style\Color($AZUL));
if (is_file($logoRuta)) { $img = $slide->createDrawingShape(); $img->setPath($logoRuta)->setHeight(48)->setOffsetX(340)->setOffsetY(120); }
$texto($slide, 'Reporte de servicio', 340, 190, 580, 60, 36, true, $AZUL);
$texto($slide, ($zona ? 'Zona ' . $zona : 'Las tres zonas') . ' · ' . ucfirst(Reportes::nombreMes($mes)), 340, 255, 580, 40, 20, false, $NEGRO);
$texto($slide, 'INDUSTEC para Grupo KFC · generado por ' . $u['nombre'] . ' el ' . date('d/m/Y'), 340, 310, 580, 30, 12, false, $GRIS);
$texto($slide, 'Fuente: buzón de SAP, informes de orden y lo decidido en B.IA Soft ERP. Nada se estima: lo que falta se dice.', 340, 340, 580, 50, 10, false, $GRIS);

// 2. Salud del servicio
$slide = $p->createSlide(); $cabecera($slide, 'La salud del servicio');
$kpisP($slide, [
    [$s['pct_concluye'] === null ? '—' : $s['pct_concluye'] . '%', 'se concluye en una visita', $sem($s['pct_concluye'])],
    [(string) $r['meta']['casos'], 'casos del periodo', $NEGRO],
    [(string) $s['abiertos'], 'siguen abiertos', $NEGRO],
    [(string) $c48['vencidos'], 'repuestos fuera de las 48 h', $c48['vencidos'] > 0 ? 'FF991B1B' : 'FF166534'],
]);
$tablaP($slide, ['Antigüedad de lo abierto', 'Casos'], array_map(fn($x) => [$x['e'], $x['v']], $r['edad']), 36, 260, 420, [300, 120]);
$tablaP($slide, ['Plazo de 48 h (repuestos)', 'Equipos'], array_map(fn($x) => [$x['e'], $x['v']], $r['d48']), 500, 260, 420, [300, 120]);

// 3. Volumen por zona
$slide = $p->createSlide(); $cabecera($slide, 'El volumen y cómo se reparte');
$tablaP($slide, ['Zona', 'Casos'], array_map(fn($x) => [$x['e'], $x['v']], $r['zonas']), 36, 120, 300, [200, 100]);
$tablaP($slide, ['Estado', 'Casos'], array_map(fn($x) => [$x['e'], $x['v']], array_slice($r['estados'], 0, 8)), 370, 120, 300, [200, 100]);
$tablaP($slide, ['Locales con más casos', 'Casos'], array_map(fn($x) => [$x['e'], $x['v']], array_slice($r['locales'], 0, 8)), 700, 120, 224, [140, 84]);

// 4. Cumplimiento 48 h
$slide = $p->createSlide(); $cabecera($slide, 'El plazo de 48 horas de los repuestos');
$tot48 = array_sum($c48);
$kpisP($slide, [
    [(string) $c48['a_tiempo'], 'validados a tiempo', 'FF166534'],
    [(string) $c48['tarde'], 'validados tarde', 'FF92400E'],
    [(string) $c48['corriendo'], 'reloj corriendo', 'FF1E40AF'],
    [(string) $c48['vencidos'], 'vencidos ahora', $c48['vencidos'] > 0 ? 'FF991B1B' : 'FF166534'],
]);
$texto($slide, 'De los equipos que quedaron deshabilitados, cuándo validó el jefe de zona la solicitud de repuesto. El plazo mide la decisión, no la reparación completa. ' . ($tot48 > 0 ? '' : 'Sin equipos parados en este corte.'), 36, 250, $W - 72, 60, 12, false, $GRIS);

// 5. Rendimiento por técnico
$slide = $p->createSlide(); $cabecera($slide, 'Rendimiento por técnico');
$tablaP($slide, ['Técnico', 'Zona', 'Asignados', 'Con informe', 'En una visita', 'Días 1.ª at.', 'Rep. vencidos', 'Novedades'],
        array_map(fn($t) => [$t['nombre'], $t['zona'], $t['asignados'], $t['con_informe'],
                             $t['pct_una_visita'] === null ? '—' : $t['una_visita'] . ' de ' . $t['cerrados'] . ' (' . $t['pct_una_visita'] . '%)',
                             $t['dias_primera'] === null ? '—' : number_format((float) $t['dias_primera'], 1, ',', '.'),
                             $t['pendientes_vencidos'], $t['novedades']], array_slice($r['rendimiento'], 0, 12)),
        36, 110, $W - 72, [250, 70, 90, 100, 120, 100, 110, 90]);

// 6. Preventivo
$slide = $p->createSlide(); $cabecera($slide, 'Cumplimiento del preventivo');
if ($pre['fuente'] === null) {
    $texto($slide, 'Sin cronograma cargado en este corte.', 36, 130, $W - 72, 40, 14, false, $GRIS);
} else {
    $kpisP($slide, [
        [$pre['pct_a_tiempo'] === null ? '—' : $pre['pct_a_tiempo'] . '%', 'cumplidos a tiempo (contra el plan acordado)', $sem($pre['pct_a_tiempo'])],
        [(string) ($pre['cumplidos_a_tiempo'] + $pre['cumplidos_tarde']), 'ingresos cumplidos', $NEGRO],
        [(string) $pre['vencidos'], 'vencidos', $pre['vencidos'] > 0 ? 'FF991B1B' : 'FF166534'],
        [$pre['kits_confirmados'] . ' / ' . $pre['total'], 'kits confirmados', $NEGRO],
    ]);
    $tablaP($slide, ['Zona', 'Ingresos', 'Cumplidos', 'A tiempo', 'Vencidos', 'En curso', 'Sin agendar'],
            array_map(fn($z, $pz) => [$z, $pz['total'], $pz['cumplidos'], $pz['a_tiempo'], $pz['vencidos'], $pz['en_curso'], $pz['sin_agendar']],
                      array_keys($pre['por_zona']), $pre['por_zona']), 36, 260, 620, [110, 85, 85, 85, 85, 85, 85]);
    if ($pre['motivos']) {
        $tablaP($slide, ['Motivo de reagenda', 'Veces'], array_map(fn($x) => [$x['e'], $x['v']], $pre['motivos']), 680, 260, 244, [180, 64]);
    }
}

// 7. Novedades y cierre
// --- Otros trabajos (011): los extras para KFC, autorizados por la administración ---
$slide = $p->createSlide(); $cabecera($slide, 'Otros trabajos para Grupo KFC');
$otr = $r['otros_trabajos'];
$texto($slide, count($otr) . ' trabajos fuera del área de INDUSTEC, hechos por acuerdo con Grupo KFC y autorizados por la administración'
    . (($r['archivo']['modulo_otros'] ?? 0) ? '; además, ' . (int) $r['archivo']['modulo_otros'] . ' órdenes del módulo «Otros», extras dentro del mismo trato.' : '.'),
    36, 110, $W - 72, 40, 12, false, $GRIS);
if ($otr) {
    $tablaP($slide, ['Aviso', 'Local', 'Zona', 'Trabajo', 'Orden', 'Acuerdo con KFC'],
            array_map(fn($o) => [$o['aviso'], $o['local'], $o['zona'], $o['trabajo'], $o['ot'] !== '' ? $o['ot'] : '—', $o['acuerdo']],
                      array_slice($otr, 0, 10)),
            36, 160, $W - 72, [90, 70, 60, 170, 200, 298]);
}

$slide = $p->createSlide(); $cabecera($slide, 'Qué hace fallar los equipos, y cierre');
if ($nov['por_area']) {
    $tablaP($slide, ['Área', 'Novedades'], array_map(fn($x) => [$x['e'], $x['v']], array_slice($nov['por_area'], 0, 8)), 36, 120, 420, [300, 120]);
}
$texto($slide, $nov['total'] . ' novedades reportadas en el periodo; ' . $nov['con_aviso'] . ' llegaron a tener aviso en SAP; '
    . $nov['pendientes'] . ' siguen sin decidir (' . $nov['alto'] . ' de riesgo alto).', 500, 120, 420, 80, 12, false, $NEGRO);
$texto($slide, ($r['reincidentes'] ? count($r['reincidentes']) . ' locales con 5 o más casos en el periodo: cuando un local repite así, la causa suele ser de otra área (eléctrica, ventilación, desagüe). ' : '')
    . 'Este reporte se genera desde B.IA Soft ERP con los mismos números que ve la administración; los tres formatos (Excel, PDF y PowerPoint) dicen lo mismo.',
    500, 210, 420, 160, 12, false, $GRIS);
$texto($slide, 'INDUSTEC · un solo punto de contacto para el mantenimiento de sus equipos', 36, 440, $W - 72, 40, 16, true, $AZUL);

header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
header('Content-Disposition: attachment; filename="' . $nombre . '.pptx"');
header('Cache-Control: private, no-store');
\PhpOffice\PhpPresentation\IOFactory::createWriter($p, 'PowerPoint2007')->save('php://output');
exit;
