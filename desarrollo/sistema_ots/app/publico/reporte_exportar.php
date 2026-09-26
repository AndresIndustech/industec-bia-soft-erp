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
 *         mes, quién y cuándo; la hoja «TOTAL DE ÓRDENES ABIERTAS» con las
 *         columnas del plan de zona que la administración ya conoce y su
 *         semáforo (le toca a INDUSTEC · le toca a KFC · repuesto en seguimiento ·
 *         emergente).
 *
 * LAS MISMAS PALABRAS QUE LA OFICINA (vocabulario único, 24-sep-2026). KFC
 * recibe estos archivos, y Andrés decidió que lean los mismos términos que
 * Isabel en pantalla: los nombres de estado, del semáforo, de la zona y de las
 * filas de la tarjeta salen de `Vocabulario::` (o de `Reportes::`, que los
 * pide ahí), no se escriben aquí.
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

$titulo = 'Reporte de servicio · ' . ($zona ? Ui::nombreZona($zona) : 'Las tres zonas') . ' · ' . ucfirst(Reportes::nombreMes($mes));
$SEM = ['amarillo' => 'FDE68A', 'naranja' => 'FDBA74', 'verde' => 'BBF7D0', 'rojo' => 'FECACA'];
$SEM_T = Reportes::semaforo();
$s = $r['salud']; $c48 = $r['c48']; $pre = $r['preventivo']; $nov = $r['novedades'];

// Los nombres que se repiten en los tres formatos, del diccionario.
$V  = static fn(string $clave, int $n = 1): string => Vocabulario::t($clave, $n);
$Vt = static fn(string $clave): string => Vocabulario::titulo($clave);
$Z  = static fn($z): string => Reportes::rotuloZona((string) $z);
$ORDENES    = $Vt('ORDEN');                                            // «Órdenes»
$EJECUTADOS = Reportes::mayuscula($V('PREV_EJECUTADO', 2));           // «Ejecutados»
$ATRASADOS  = Reportes::mayuscula($V('ATRASADO', 2));                 // «Atrasados»
$VENCIDAS   = 'Solicitudes ' . mb_strtolower($Vt('VENCIDO_48H'), 'UTF-8');   // «Solicitudes vencidas (48 h)»
$EN_MANOS   = $Vt('ASIGNADA') . ' o ' . $V('ESPERA_REPUESTO');         // lo que el técnico tiene en sus manos
// Una fila de la tarjeta que no se pudo calcular se dice, no se escribe 0 (I-7).
$nd = static fn($v) => $v === null ? 'no disponible' : $v;

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
        [$ORDENES . ' del periodo', $r['meta']['casos'], $mes ? 'creadas en ' . Reportes::nombreMes($mes) : 'ventana de 90 días del buzón'],
        // Las cuatro filas de la tarjeta «Por zona», con sus nombres y su ayuda.
        [$Vt('TOTAL_ABIERTAS'), $s['abiertos'], Vocabulario::ayuda('TOTAL_ABIERTAS')],
        [$Vt('ABIERTA'), $nd($s['abiertas']), Vocabulario::ayuda('ABIERTA')],
        [$Vt('ESPERA_INFORME'), $nd($s['espera_informe']), Vocabulario::ayuda('ESPERA_INFORME')],
        [$Vt('EQUIPO_DESHABILITADO'), $nd($s['deshabilitados']), 'Parte del total, no se suma. ' . Vocabulario::ayuda('EQUIPO_DESHABILITADO')],
        ['Con OT INDUSTEC', $s['con_informe'], 'órdenes con al menos una OT INDUSTEC'],
        ['Con ' . $V('OT_CIERRE'), $s['concluidos'], Vocabulario::ayuda('OT_CIERRE')],
        ['Con ' . $V('OT_EVALUACION') . ', sin la de cierre', $s['en_curso'], 'hubo visita y falta la OT INDUSTEC de cierre'],
        ['Se concluye en una visita', $s['pct_concluye'] === null ? 'no disponible' : $s['pct_concluye'] . '%', 'de las órdenes con OT INDUSTEC de cierre: el mismo día y sin dejar un equipo deshabilitado'],
        ['Solicitudes: ' . $V('VALIDADA', 2) . ' a tiempo', $c48['a_tiempo'], 'dentro de las 48 h'],
        ['Solicitudes: ' . $V('VALIDADA', 2) . ' tarde', $c48['tarde'], 'después de las 48 h'],
        ['Solicitudes: ' . $V('POR_VALIDAR', 2) . ', a tiempo', $c48['corriendo'], Vocabulario::ayuda('POR_VALIDAR')],
        [$VENCIDAS, $c48['vencidos'], Vocabulario::ayuda('VENCIDO_48H')],
        ['Preventivo: ' . $V('PREV_EJECUTADO', 2) . ' a tiempo', $pre['fuente'] === null ? 'sin cronograma' : ($pre['pct_a_tiempo'] === null ? 'no disponible' : $pre['pct_a_tiempo'] . '%'), 'contra el plan acordado con KFC'],
        ['Novedades ' . $V('NOVEDAD_CON_AVISO', 2), $nov['con_aviso'], 'de ' . $nov['total'] . ' reportadas'],
        ['OT INDUSTEC en el archivo', $r['archivo']['total'] ?? 'no disponible', 'índice del archivo general'],
        ['Otros trabajos autorizados', count($r['otros_trabajos']), 'fuera del área, por acuerdo con KFC (hoja «Otros trabajos»)'],
    ], ['A' => 40, 'B' => 16, 'C' => 60]);
    $f = $tabla($ws, $f, ['Antigüedad del ' . $V('TOTAL_ABIERTAS'), $ORDENES], array_map(fn($x) => [$x['e'], $x['v']], $r['edad']));

    // --- TOTAL DE ÓRDENES ABIERTAS (plan de zona) ---
    // La hoja sale de la misma función que la tarjeta del panel
    // (Casos::clasificar en Reportes::calcular): sus filas suman la cifra de
    // la tarjeta. El nombre de la hoja es el término del diccionario.
    $ws = $hoja($ss, $Vt('TOTAL_ABIERTAS'));
    $ws->setCellValue('A5', 'Semáforo: ' . implode(' · ', array_map(fn($k, $t) => $k . ' = ' . $t, array_keys($SEM_T), $SEM_T)));
    $ws->getStyle('A5')->getFont()->setSize(9)->setItalic(true);
    $filas = array_map(fn($c) => [$c['aviso'], $c['tecnico'] ?: '', $c['local'], $c['local_nombre'], $Z($c['zona']), $c['fecha'],
                                   $c['equipo'] ?: $c['trabajo'], $c['estatus'], $c['grupo'], $c['equipo_estado'], $c['dias'], $c['prioridad'],
                                   $c['observaciones'], $SEM_T[$c['semaforo']] ?? ''],
                       $r['casos_abiertos']);
    $tabla($ws, 6, [mb_strtoupper($Vt('AVISO_SAP'), 'UTF-8'), 'TÉCNICO', 'LOCAL', 'NOMBRE DEL LOCAL', 'ZONA', 'FECHA DE INICIO', 'EQUIPO', 'ESTADO',
                    'CLASIFICACIÓN', 'ESTADO DEL EQUIPO', 'DÍAS', 'PRIORIDAD', 'OBSERVACIONES', 'SEMÁFORO'], $filas,
           ['A' => 14, 'B' => 26, 'C' => 10, 'D' => 28, 'E' => 13, 'F' => 14, 'G' => 30, 'H' => 22, 'I' => 38, 'J' => 22, 'K' => 7, 'L' => 10, 'M' => 50, 'N' => 30]);
    foreach ($r['casos_abiertos'] as $k => $c) {
        $fila = 7 + $k;
        $ws->getStyle("A$fila:N$fila")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($SEM[$c['semaforo']] ?? 'FFFFFF');
    }
    $ws->setAutoFilter('A6:N' . max(7, 6 + count($filas)));

    // --- Por zona y estado ---
    $ws = $hoja($ss, 'Por zona');
    $f = $tabla($ws, 5, ['Zona', $ORDENES], array_map(fn($x) => [$x['e'], $x['v']], $r['zonas']), ['A' => 22, 'B' => 12]);
    $f = $tabla($ws, $f, ['Estado', $ORDENES], array_map(fn($x) => [$x['e'], $x['v']], $r['estados']));
    $f = $tabla($ws, $f, ['Mes', $ORDENES], array_map(fn($x) => [$x['e'], $x['v']], $r['meses']));
    $tabla($ws, $f, ['Cadena', $ORDENES], array_map(fn($x) => [$x['e'], $x['v']], $r['cadenas']));

    // --- Rendimiento por técnico ---
    $ws = $hoja($ss, 'Rendimiento técnicos');
    $tabla($ws, 5, ['Técnico', 'Zona', 'Activo', $Vt('ASIGNADA'), 'Con OT INDUSTEC', 'Con ' . $V('OT_CIERRE'), 'En una visita', '% en una visita', 'Días a la 1.ª atención', $EN_MANOS, $VENCIDAS, 'Novedades', 'OT INDUSTEC por la app'],
           array_map(fn($t) => [$t['nombre'], $Z($t['zona']), $t['activo'] ? 'Sí' : 'No', $t['asignados'], $t['con_informe'], $t['cerrados'], $t['una_visita'],
                                $t['pct_una_visita'] === null ? '' : $t['pct_una_visita'] . '%', $t['dias_primera'] ?? '', $t['abiertos_ahora'],
                                $t['pendientes_vencidos'], $t['novedades'], $t['ordenes_app']], $r['rendimiento']),
           ['A' => 30, 'B' => 13, 'C' => 8, 'D' => 11, 'E' => 16, 'F' => 26, 'G' => 13, 'H' => 15, 'I' => 20, 'J' => 30, 'K' => 26, 'L' => 11, 'M' => 22]);

    // --- Cumplimiento 48 h ---
    $ws = $hoja($ss, 'Cumplimiento 48h');
    $tabla($ws, 5, ['Qué', 'Solicitudes'], array_map(fn($x) => [$x['e'], $x['v']], $r['d48']), ['A' => 26, 'B' => 12]);

    // --- Preventivo ---
    $ws = $hoja($ss, 'Preventivo');
    if ($pre['fuente'] === null) {
        $ws->setCellValue('A5', 'Sin cronograma cargado en este corte.');
    } else {
        $f = $tabla($ws, 5, ['Indicador', 'Valor'], [
            ['Ingresos del periodo', $pre['total']], [$EJECUTADOS . ' a tiempo', $pre['cumplidos_a_tiempo']],
            [$EJECUTADOS . ' tarde', $pre['cumplidos_tarde']], [$ATRASADOS, $pre['vencidos']],
            [$Vt('PREV_SIN_CIERRE'), $pre['sin_cierre']], [$Vt('PREV_EN_EJECUCION'), $pre['en_curso']],
            [$Vt('PREV_POR_INICIAR'), $pre['por_iniciar']], [$Vt('PREV_PENDIENTE'), $pre['planificados']], [$Vt('PREV_SIN_AGENDAR'), $pre['sin_agendar']],
            [$Vt('PREV_REAGENDADO'), $pre['reagendados']], ['Kits confirmados', $pre['kits_confirmados']],
            ['% ' . $V('PREV_EJECUTADO', 2) . ' a tiempo (contra el plan acordado)', $pre['pct_a_tiempo'] === null ? 'no disponible' : $pre['pct_a_tiempo'] . '%'],
            ['Fuente', $pre['fuente'] === 'tabla' ? 'base de datos' : 'archivo de la estación (sin importar)'],
        ], ['A' => 40, 'B' => 16, 'C' => 12, 'D' => 12, 'E' => 12, 'F' => 34, 'G' => 26, 'H' => 24]);
        $f = $tabla($ws, $f, ['Zona', 'Ingresos', $EJECUTADOS, 'A tiempo', $ATRASADOS, $Vt('PREV_SIN_CIERRE'), $Vt('PREV_EN_EJECUCION'), $Vt('PREV_SIN_AGENDAR')],
                    array_map(fn($z, $pz) => [$Z($z), $pz['total'], $pz['cumplidos'], $pz['a_tiempo'], $pz['vencidos'], $pz['sin_cierre'], $pz['en_curso'], $pz['sin_agendar']],
                              array_keys($pre['por_zona']), $pre['por_zona']));
        if ($pre['motivos']) { $tabla($ws, $f, ['Motivo de reagenda', 'Veces'], array_map(fn($x) => [$x['e'], $x['v']], $pre['motivos'])); }
    }

    // --- Novedades y locales ---
    $ws = $hoja($ss, 'Novedades');
    $f = $tabla($ws, 5, ['Área', 'Novedades'], array_map(fn($x) => [$x['e'], $x['v']], $nov['por_area']), ['A' => 34, 'B' => 12]);
    $porDecidir = $V('NOVEDAD_POR_DECIDIR');
    $tabla($ws, $f, ['Indicador', 'Valor'], [[Vocabulario::titulo('NOVEDAD_REPORTADA'), $nov['total']], [$Vt('NOVEDAD_POR_DECIDIR'), $nov['pendientes']],
                                            ['De riesgo alto, ' . $porDecidir, $nov['alto']],
                                            [$Vt('NOVEDAD_OTRA_AREA') . ', ' . $porDecidir, $nov['ajenas']], [$Vt('NOVEDAD_CON_AVISO'), $nov['con_aviso']]]);
    $ws = $hoja($ss, 'Locales');
    $f = $tabla($ws, 5, ['Local', $ORDENES], array_map(fn($x) => [$x['e'], $x['v']], $r['locales']), ['A' => 14, 'B' => 12, 'C' => 40]);
    $f = $tabla($ws, $f, ['Locales que repiten (5 o más)', $ORDENES], array_map(fn($l, $c) => [$l, $c], array_keys($r['reincidentes']), $r['reincidentes']));
    $tabla($ws, $f, ['Qué se pide', $ORDENES], array_map(fn($x) => [$x['e'], $x['v']], $r['tipos']));

    // --- Otros trabajos (011): los extras para KFC, autorizados por la administración ---
    $ws = $hoja($ss, 'Otros trabajos');
    $ws->setCellValue('A5', 'Trabajos fuera del área de INDUSTEC hechos por acuerdo con Grupo KFC y autorizados por la administración. '
        . (int) $r['otros_por_decidir'] . ' ' . $V('ORDEN', (int) $r['otros_por_decidir']) . ' ' . $V('OTRO_TRABAJO_POR_DECIDIR') . '.'
        . (($r['archivo']['modulo_otros'] ?? 0) ? ' OT INDUSTEC del módulo «Otros» en el Archivo: ' . (int) $r['archivo']['modulo_otros'] . '.' : ''));
    $ws->getStyle('A5')->getFont()->setSize(9)->setItalic(true);
    $tabla($ws, 6, [mb_strtoupper($Vt('AVISO_SAP'), 'UTF-8'), 'FECHA', 'LOCAL', 'NOMBRE DEL LOCAL', 'ZONA', 'TRABAJO', mb_strtoupper($Vt('OT_CIERRE'), 'UTF-8'), 'ESTADO', 'ACUERDO CON KFC', 'AUTORIZÓ', 'AUTORIZADO EL'],
           array_map(fn($o) => [$o['aviso'], $o['fecha'], $o['local'], $o['local_nombre'], $Z($o['zona']), $o['trabajo'], $o['ot'],
                                $o['estatus'], $o['acuerdo'], $o['autorizo'], $o['autorizado_en']], $r['otros_trabajos']),
           ['A' => 14, 'B' => 12, 'C' => 10, 'D' => 28, 'E' => 13, 'F' => 24, 'G' => 32, 'H' => 16, 'I' => 50, 'J' => 26, 'K' => 14]);

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
$texto($slide, ($zona ? Ui::nombreZona($zona) : 'Las tres zonas') . ' · ' . ucfirst(Reportes::nombreMes($mes)), 340, 255, 580, 40, 20, false, $NEGRO);
$texto($slide, 'INDUSTEC para Grupo KFC · generado por ' . $u['nombre'] . ' el ' . date('d/m/Y'), 340, 310, 580, 30, 12, false, $GRIS);
$texto($slide, 'Fuente: buzón de SAP, OT INDUSTEC y lo decidido en B.IA Soft ERP. Nada se estima: lo que falta se dice.', 340, 340, 580, 50, 10, false, $GRIS);

// 2. Salud del servicio
$slide = $p->createSlide(); $cabecera($slide, 'La salud del servicio');
$kpisP($slide, [
    [$s['pct_concluye'] === null ? '—' : $s['pct_concluye'] . '%', 'se concluye en una visita', $sem($s['pct_concluye'])],
    [(string) $r['meta']['casos'], $V('ORDEN', 2) . ' del periodo', $NEGRO],
    [(string) $s['abiertos'], $V('TOTAL_ABIERTAS'), $NEGRO],
    [(string) $c48['vencidos'], mb_strtolower($VENCIDAS, 'UTF-8'), $c48['vencidos'] > 0 ? 'FF991B1B' : 'FF166534'],
]);
$tablaP($slide, ['Antigüedad del ' . $V('TOTAL_ABIERTAS'), $ORDENES], array_map(fn($x) => [$x['e'], $x['v']], $r['edad']), 36, 260, 420, [300, 120]);
$tablaP($slide, ['Plazo de 48 h', 'Solicitudes'], array_map(fn($x) => [$x['e'], $x['v']], $r['d48']), 500, 260, 420, [300, 120]);

// 3. Volumen por zona
$slide = $p->createSlide(); $cabecera($slide, 'El volumen y cómo se reparte');
$tablaP($slide, ['Zona', $ORDENES], array_map(fn($x) => [$x['e'], $x['v']], $r['zonas']), 36, 120, 300, [200, 100]);
$tablaP($slide, ['Estado', $ORDENES], array_map(fn($x) => [$x['e'], $x['v']], array_slice($r['estados'], 0, 8)), 370, 120, 300, [200, 100]);
$tablaP($slide, ['Locales con más órdenes', $ORDENES], array_map(fn($x) => [$x['e'], $x['v']], array_slice($r['locales'], 0, 8)), 700, 120, 224, [140, 84]);

// 4. Cumplimiento 48 h
$slide = $p->createSlide(); $cabecera($slide, 'El plazo de 48 horas de las solicitudes');
$tot48 = array_sum($c48);
$kpisP($slide, [
    [(string) $c48['a_tiempo'], $V('VALIDADA', 2) . ' a tiempo', 'FF166534'],
    [(string) $c48['tarde'], $V('VALIDADA', 2) . ' tarde', 'FF92400E'],
    [(string) $c48['corriendo'], $V('POR_VALIDAR', 2) . ', a tiempo', 'FF1E40AF'],
    [(string) $c48['vencidos'], mb_strtolower($Vt('VENCIDO_48H'), 'UTF-8'), $c48['vencidos'] > 0 ? 'FF991B1B' : 'FF166534'],
]);
$texto($slide, 'De las solicitudes con el equipo deshabilitado, cuándo las validó el jefe de zona. El plazo mide la validación, no la reparación completa. ' . ($tot48 > 0 ? '' : 'Sin ' . $V('EQUIPO_DESHABILITADO', 2) . ' en este corte.'), 36, 250, $W - 72, 60, 12, false, $GRIS);

// 5. Rendimiento por técnico
$slide = $p->createSlide(); $cabecera($slide, 'Rendimiento por técnico');
$tablaP($slide, ['Técnico', 'Zona', $Vt('ASIGNADA'), 'Con OT INDUSTEC', 'En una visita', 'Días 1.ª at.', $VENCIDAS, 'Novedades'],
        array_map(fn($t) => [$t['nombre'], $Z($t['zona']), $t['asignados'], $t['con_informe'],
                             $t['pct_una_visita'] === null ? '—' : $t['una_visita'] . ' de ' . $t['cerrados'] . ' (' . $t['pct_una_visita'] . '%)',
                             $t['dias_primera'] === null ? '—' : number_format((float) $t['dias_primera'], 1, ',', '.'),
                             $t['pendientes_vencidos'], $t['novedades']], array_slice($r['rendimiento'], 0, 12)),
        36, 110, $W - 72, [230, 90, 90, 100, 120, 90, 120, 88]);

// 6. Preventivo
$slide = $p->createSlide(); $cabecera($slide, 'Cumplimiento del preventivo');
if ($pre['fuente'] === null) {
    $texto($slide, 'Sin cronograma cargado en este corte.', 36, 130, $W - 72, 40, 14, false, $GRIS);
} else {
    $kpisP($slide, [
        [$pre['pct_a_tiempo'] === null ? '—' : $pre['pct_a_tiempo'] . '%', $V('PREV_EJECUTADO', 2) . ' a tiempo (contra el plan acordado)', $sem($pre['pct_a_tiempo'])],
        [(string) ($pre['cumplidos_a_tiempo'] + $pre['cumplidos_tarde']), 'ingresos ' . $V('PREV_EJECUTADO', 2), $NEGRO],
        [(string) ($pre['vencidos'] + $pre['sin_cierre']), $V('ATRASADO', 2) . ' (' . $pre['sin_cierre'] . ' por marcar como ejecutados)',
         $pre['vencidos'] + $pre['sin_cierre'] > 0 ? 'FF991B1B' : 'FF166534'],
        [$pre['kits_confirmados'] . ' / ' . $pre['total'], 'kits confirmados', $NEGRO],
    ]);
    $tablaP($slide, ['Zona', 'Ingresos', $EJECUTADOS, 'A tiempo', $ATRASADOS, $Vt('PREV_SIN_CIERRE'), $Vt('PREV_EN_EJECUCION'), $Vt('PREV_SIN_AGENDAR')],
            array_map(fn($z, $pz) => [$Z($z), $pz['total'], $pz['cumplidos'], $pz['a_tiempo'], $pz['vencidos'], $pz['sin_cierre'], $pz['en_curso'], $pz['sin_agendar']],
                      array_keys($pre['por_zona']), $pre['por_zona']), 36, 260, 620, [90, 70, 75, 70, 75, 90, 80, 70]);
    if ($pre['motivos']) {
        $tablaP($slide, ['Motivo de reagenda', 'Veces'], array_map(fn($x) => [$x['e'], $x['v']], $pre['motivos']), 680, 260, 244, [180, 64]);
    }
}

// 7. Novedades y cierre
// --- Otros trabajos (011): los extras para KFC, autorizados por la administración ---
$slide = $p->createSlide(); $cabecera($slide, 'Otros trabajos para Grupo KFC');
$otr = $r['otros_trabajos'];
$texto($slide, count($otr) . ' trabajos fuera del área de INDUSTEC, hechos por acuerdo con Grupo KFC y autorizados por la administración'
    . (($r['archivo']['modulo_otros'] ?? 0) ? '; además, ' . (int) $r['archivo']['modulo_otros'] . ' OT INDUSTEC del módulo «Otros», extras dentro del mismo trato.' : '.'),
    36, 110, $W - 72, 40, 12, false, $GRIS);
if ($otr) {
    $tablaP($slide, [$Vt('AVISO_SAP'), 'Local', 'Zona', 'Trabajo', $Vt('OT_CIERRE'), 'Acuerdo con KFC'],
            array_map(fn($o) => [$o['aviso'], $o['local'], $Z($o['zona']), $o['trabajo'], $o['ot'] !== '' ? $o['ot'] : '—', $o['acuerdo']],
                      array_slice($otr, 0, 10)),
            36, 160, $W - 72, [90, 70, 90, 160, 200, 278]);
}

$slide = $p->createSlide(); $cabecera($slide, 'Qué hace fallar los equipos, y cierre');
if ($nov['por_area']) {
    $tablaP($slide, ['Área', 'Novedades'], array_map(fn($x) => [$x['e'], $x['v']], array_slice($nov['por_area'], 0, 8)), 36, 120, 420, [300, 120]);
}
$texto($slide, $nov['total'] . ' novedades reportadas en el periodo; ' . $nov['con_aviso'] . ' ' . $V('NOVEDAD_CON_AVISO', (int) $nov['con_aviso']) . '; '
    . $nov['pendientes'] . ' siguen ' . $V('NOVEDAD_POR_DECIDIR') . ' (' . $nov['alto'] . ' de riesgo alto).', 500, 120, 420, 80, 12, false, $NEGRO);
$texto($slide, ($r['reincidentes'] ? count($r['reincidentes']) . ' locales con 5 o más órdenes en el periodo: cuando un local repite así, la causa suele ser de otra área (eléctrica, ventilación, desagüe). ' : '')
    . 'Este reporte se genera desde B.IA Soft ERP con los mismos números que ve la administración; los tres formatos (Excel, PDF y PowerPoint) dicen lo mismo.',
    500, 210, 420, 160, 12, false, $GRIS);
$texto($slide, 'INDUSTEC · un solo punto de contacto para el mantenimiento de sus equipos', 36, 440, $W - 72, 40, 16, true, $AZUL);

header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
header('Content-Disposition: attachment; filename="' . $nombre . '.pptx"');
header('Cache-Control: private, no-store');
\PhpOffice\PhpPresentation\IOFactory::createWriter($p, 'PowerPoint2007')->save('php://output');
exit;
