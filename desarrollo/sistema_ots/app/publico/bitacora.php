<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Ui.php';
require_once __DIR__ . '/nucleo/Vocabulario.php';   // qué hizo cada acción y cómo se llama cada estado

/**
 * bitacora.php — Quién hizo qué, y quién consultó qué.
 *
 * La bitácora se escribe desde el primer día (Auth::bitacora en cada acción y
 * en cada denegación), pero hasta el 2026-09-13 no tenía pantalla: la
 * trazabilidad existía en la base y no a la vista de nadie (TR-10). Andrés
 * pidió trazabilidad de todas las acciones, y de todos los usuarios; esta es la
 * vista de la administración sobre ella.
 *
 * SOLO LECTURA, Y CON RASTRO PROPIO. Los disparadores de la 009 impiden borrar
 * o editar filas de la bitácora aunque alguien tenga la clave de la base. Y
 * consultar la bitácora también queda en la bitácora: es la única forma de
 * responder después «quién estuvo mirando la actividad de quién».
 *
 * FILTROS Y EXPORTACIÓN. Usuario, acción, entidad, referencia, fechas y si la
 * acción tuvo éxito (las denegaciones son las filas con `exito = 0`). Se
 * exporta a CSV lo filtrado, con tope, para el reporte de auditoría que pida
 * el cliente. Desde `usuarios.php` («Ver actividad») y desde la ficha del caso
 * se llega con el filtro puesto.
 */

$u = Auth::exigir();
if (!Ui::puedeModulo('bitacora.ver', ['SUPERADMIN', 'ADMIN'], $u)) {
    Auth::bitacora('DENEGADO', 'bitacora', '', 'sin permiso', null, null, [], false);
    http_response_code(403);
    exit('No tienes acceso a la bitácora.');
}

$e = fn(?string $s): string => Ui::e($s);

/* -------------------------------------------------------------------------
   LO QUE SE LEE, Y LO QUE SE GUARDA. La base guarda códigos (CERRADO_SIN_ATENCION,
   VEREDICTO, NUEVO → ASIGNADO) y así se quedan: son el registro, y por ellos se
   filtra. Lo que se MUESTRA es el nombre del diccionario (24-sep-2026): hasta
   entonces la pantalla pintaba «cerrado sin atencion» o «veredicto» en crudo,
   con palabras que el resto del sistema ya no usa. El código sigue a la vista
   en el `title`, para quien audite contra la base.
   ------------------------------------------------------------------------- */
$ACCION_TXT = [
    'ASIGNAR'              => 'asignar la orden',
    'ASIGNADO_AUTO'        => Vocabulario::t('ASIGNADA') . ' por su OT INDUSTEC (automático)',
    'ATENDIDO_AUTO'        => Vocabulario::t('ATENDIDA') . ' (automático)',
    'REABRIR'              => 'reabrir la orden',
    'DERIVAR'              => 'derivar a otra zona',
    'EN_REVISION'          => 'mandar a revisión',
    'VEREDICTO'            => Vocabulario::t('RESOLUCION_ADMIN'),
    'CERRADO_SAP'          => Vocabulario::t('CERRADA_SAP'),
    'CERRADO_SAP_MASIVO'   => Vocabulario::t('CERRADA_SAP') . ' (en bloque)',
    'CERRAR_SIN_ATENCION'  => 'cerrar sin atención las de 7+ días sin OT INDUSTEC',
    'CERRADO_SIN_ATENCION' => Vocabulario::t('CERRADA_SIN_ATENCION'),
    'REGULARIZAR'          => 'regularizar ante KFC',
    'REGULARIZAR_MASIVO'   => 'regularizar ante KFC (en bloque)',
    'OT_CIERRE_CON_PENDIENTE' => Vocabulario::t('OT_CIERRE') . ' con ' . Vocabulario::t('SOLICITUD_EN_TRAMITE'),
    'CASO_CONTINUA'        => Vocabulario::t('CONTINUIDAD'),
    'CASO_DESCONTINUA'     => 'deshacer la continuidad',
    'LIBERAR_CASOS'        => 'dejar sus órdenes ' . Vocabulario::t('SIN_ASIGNAR'),
    'VER_AVISOS'           => 'ver sus ' . Vocabulario::t('NOTIFICACION', 2),
    'PENDIENTE_ABRE'       => 'abrir una ' . Vocabulario::t('SOLICITUD'),
    'PENDIENTE_MUEVE'      => 'avanzar la ' . Vocabulario::t('SOLICITUD'),
    'PENDIENTE_RESPONDE'   => 'responder en la ' . Vocabulario::t('SOLICITUD'),
    'PENDIENTE_REGISTRA_SAP' => 'registrar la ' . Vocabulario::t('SOLICITUD') . ' en SAP',
    'PENDIENTE_VEREDICTO_KFC' => Vocabulario::t('DECISION_KFC'),
    'PENDIENTE_RESUELTO_POR_ORDEN' => Vocabulario::t('SOLICITUD') . ' ' . Vocabulario::t('SOLICITUD_TERMINADA')
                                    . ' por la ' . Vocabulario::t('OT_CIERRE'),
    'PENDIENTE_ORDEN_CONCLUIDA_SIN_CERRAR' => Vocabulario::t('OT_CIERRE') . ' con la ' . Vocabulario::t('SOLICITUD_EN_TRAMITE'),
    'PENDIENTE_REPORTE_TARDIO' => Vocabulario::t('SOLICITUD') . ' reportada tarde',
    'PENDIENTE_SIN_AVISO'  => Vocabulario::t('SIN_AVISO_SAP'),
    'PENDIENTE_REGULARIZADO' => 'aviso SAP puesto a una ' . Vocabulario::t('SIN_AVISO_SAP'),
    'CRONOGRAMA_AGENDA'    => 'agendar el ingreso preventivo',
    'CRONOGRAMA_REAGENDA'  => 'reagendar el ingreso preventivo',
    'CRONOGRAMA_KIT'       => 'kit del ingreso preventivo',
    'CRONOGRAMA_NOTA'      => 'nota del ingreso preventivo',
    'CRONOGRAMA_CIERRE'    => 'marcar como ' . Vocabulario::t('PREV_EJECUTADO') . ' el ingreso preventivo',
    'EMISION'              => 'emitir la OT INDUSTEC',
    'EMISION_FALLIDA'      => Vocabulario::t('OT_NO_EMITIDA'),
    'ENVIO_RECIBIDO'       => 'OT INDUSTEC ' . Vocabulario::t('ENVIO_RECIBIDA'),
    'ENVIO_RECHAZADO'      => 'OT INDUSTEC ' . Vocabulario::t('ENVIO_RECHAZADA'),
    'ENVIO_OBSERVADO'      => 'OT INDUSTEC recibida con observaciones',
    'ENVIO_AJENO'          => 'OT INDUSTEC llenada por otro usuario',
    'NOVEDAD_REPORTA'      => 'reportar una ' . Vocabulario::t('NOVEDAD'),
    'NOVEDAD_RESUELVE'     => 'resolver una ' . Vocabulario::t('NOVEDAD'),
];
/** El nombre de una acción. Las que no están en el mapa se leen como antes
 *  (en minúsculas y sin guiones bajos): no nombran ningún estado. */
$accionTxt = static fn(?string $a): string =>
    $ACCION_TXT[(string) $a] ?? strtolower(str_replace('_', ' ', (string) $a));

/** El nombre de un estado según la entidad. Un código que el diccionario no
 *  conoce se muestra tal como está en la base (I-7): es el dato, no un nombre
 *  inventado para taparlo. */
$DOMINIO = ['caso' => 'caso', 'pendiente' => 'pendiente', 'novedad' => 'novedad'];
$estadoTxt = static function (?string $entidad, ?string $est) use ($DOMINIO): string {
    if ($est === null || $est === '') { return '—'; }
    $dom = $DOMINIO[(string) $entidad] ?? null;
    if ($dom === null) { return $est; }
    try {
        $txt = Vocabulario::t(Vocabulario::deEstado($est, $dom));
    } catch (VocabularioError $ex) {
        return $est;
    }
    // Los estados heredados de la solicitud se leen con su paso de entonces.
    $paso = $dom === 'pendiente' ? (Vocabulario::todo()['detalle_pendiente_heredado'][strtoupper($est)] ?? null) : null;
    return $paso !== null ? $txt . ' · ' . $paso : $txt;
};

$fUsuario   = trim((string) ($_GET['usuario'] ?? ''));
$fAccion    = strtoupper(trim((string) ($_GET['accion'] ?? '')));
$fEntidad   = trim((string) ($_GET['entidad'] ?? ''));
$fRef       = trim((string) ($_GET['referencia'] ?? ''));
$fDesde     = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['desde'] ?? '')) ? (string) $_GET['desde'] : '';
$fHasta     = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['hasta'] ?? '')) ? (string) $_GET['hasta'] : '';
$fExito     = in_array($_GET['exito'] ?? '', ['1', '0'], true) ? (string) $_GET['exito'] : '';
$fTexto     = trim((string) ($_GET['q'] ?? ''));
$formato    = (string) ($_GET['formato'] ?? '');
$pagina     = max(1, (int) ($_GET['p'] ?? 1));
$POR_PAGINA = 100;
$TOPE_CSV   = 20000;

$donde = ['1=1'];
$par = [];
if ($fUsuario !== '') {
    // Por nombre de usuario o por su id: `usuarios.php` enlaza por usuario.
    $donde[] = '(b.usuario = ? OR b.usuario_id = ?)';
    $par[] = $fUsuario; $par[] = ctype_digit($fUsuario) ? (int) $fUsuario : -1;
}
if ($fAccion !== '')  { $donde[] = 'b.accion = ?';       $par[] = $fAccion; }
if ($fEntidad !== '') { $donde[] = 'b.entidad = ?';      $par[] = $fEntidad; }
if ($fRef !== '')     { $donde[] = 'b.referencia LIKE ?'; $par[] = '%' . $fRef . '%'; }
if ($fDesde !== '')   { $donde[] = 'b.cuando >= ?';      $par[] = $fDesde . ' 00:00:00'; }
if ($fHasta !== '')   { $donde[] = 'b.cuando <= ?';      $par[] = $fHasta . ' 23:59:59'; }
if ($fExito !== '')   { $donde[] = 'b.exito = ?';        $par[] = (int) $fExito; }
if ($fTexto !== '')   { $donde[] = '(b.detalle LIKE ? OR b.datos LIKE ?)'; $par[] = '%' . $fTexto . '%'; $par[] = '%' . $fTexto . '%'; }
$where = implode(' AND ', $donde);
$filtros = ['usuario' => $fUsuario, 'accion' => $fAccion, 'entidad' => $fEntidad, 'referencia' => $fRef,
            'desde' => $fDesde, 'hasta' => $fHasta, 'exito' => $fExito, 'q' => $fTexto];

$total = (int) (Db::uno("SELECT COUNT(*) n FROM bitacora b WHERE $where", $par)['n'] ?? 0);

/* -------------------------------------------------------------------------
   CSV: lo filtrado, con tope. Es el reporte de auditoría que se le entrega al
   cliente o a quien pregunte; la exportación misma queda en la bitácora.
   ------------------------------------------------------------------------- */
if ($formato === 'csv') {
    Auth::bitacora('EXPORTAR_BITACORA', 'bitacora', 'csv', 'filas=' . min($total, $TOPE_CSV), null, null,
                   $filtros + ['total' => $total, 'tope' => $TOPE_CSV]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bitacora_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: private, no-store');
    $salida = fopen('php://output', 'w');
    fwrite($salida, "\xEF\xBB\xBF");   // BOM: Excel en Windows abre el UTF-8 con tildes
    fputcsv($salida, ['id', 'cuando', 'usuario', 'accion', 'entidad', 'referencia', 'detalle',
                      'estado_antes', 'estado_despues', 'exito', 'ip', 'equipo', 'datos',
                      'accion_texto', 'estado_antes_texto', 'estado_despues_texto'], ';');
    $filas = Db::todos("SELECT b.* FROM bitacora b WHERE $where ORDER BY b.id DESC LIMIT $TOPE_CSV", $par);
    foreach ($filas as $f) {
        fputcsv($salida, [$f['id'], $f['cuando'], $f['usuario'], $f['accion'], $f['entidad'], $f['referencia'],
                          $f['detalle'], $f['estado_antes'], $f['estado_despues'], $f['exito'], $f['ip'],
                          $f['equipo'], $f['datos'], $accionTxt($f['accion']),
                          $f['estado_antes'] !== null ? $estadoTxt($f['entidad'], $f['estado_antes']) : '',
                          $f['estado_despues'] !== null ? $estadoTxt($f['entidad'], $f['estado_despues']) : ''], ';');
    }
    fclose($salida);
    exit;
}

$paginas = max(1, (int) ceil($total / $POR_PAGINA));
if ($pagina > $paginas) { $pagina = $paginas; }
$filas = Db::todos(
    "SELECT b.* FROM bitacora b WHERE $where ORDER BY b.id DESC LIMIT $POR_PAGINA OFFSET " . (($pagina - 1) * $POR_PAGINA),
    $par
);
$acciones  = array_column(Db::todos('SELECT accion FROM bitacora GROUP BY accion ORDER BY accion'), 'accion');
$entidades = array_column(Db::todos('SELECT entidad FROM bitacora WHERE entidad IS NOT NULL GROUP BY entidad ORDER BY entidad'), 'entidad');
$usuarios  = Db::todos('SELECT usuario, nombre FROM usuarios ORDER BY nombre');

Auth::bitacora('CONSULTAR', 'bitacora', $fUsuario !== '' ? $fUsuario : 'todos',
               'visibles=' . count($filas) . ' de ' . $total, null, null, $filtros + ['p' => $pagina, 'n' => $total]);

$enlace = static function (array $cambios = []) use ($filtros): string {
    $p = array_filter(array_merge($filtros, $cambios), static fn($v) => $v !== '' && $v !== null);
    return 'bitacora.php' . ($p ? '?' . http_build_query($p) : '');
};

/** A dónde lleva una referencia, según su entidad. */
$destino = static function (?string $entidad, ?string $ref): ?string {
    if ($ref === null || $ref === '') { return null; }
    return match ((string) $entidad) {
        'ot'       => 'ordenes.php?q=' . rawurlencode($ref),
        'caso'     => 'casos.php?ver=' . rawurlencode($ref),
        'usuario'  => 'bitacora.php?usuario=' . rawurlencode($ref),
        'pendiente' => 'pendientes.php?g=abiertos#p' . rawurlencode($ref),
        default    => null,
    };
};

Ui::cabecera($u, 'bitacora.php', [], ['titulo' => 'Bitácora']);
?>
<div class="wrap ancho">
  <div class="titulo entra">
    <h1>Bitácora</h1>
    <p class="sub">
      Cada acción del sistema con quién la hizo, cuándo y desde dónde; las
      denegaciones también, marcadas. No se puede borrar ni editar: la base lo
      impide. Esta consulta queda registrada igual que cualquier otra.
    </p>
  </div>

  <form class="filtros" method="get" data-auto>
    <div class="campo">
      <label for="f-usuario">Usuario</label>
      <select id="f-usuario" name="usuario">
        <option value="">Todos</option>
        <option value="enlace" <?= $fUsuario === 'enlace' ? 'selected' : '' ?>>(enlaces compartidos)</option>
        <option value="automatico" <?= $fUsuario === 'automatico' ? 'selected' : '' ?>>(automático)</option>
        <?php foreach ($usuarios as $x): ?>
          <option value="<?= $e($x['usuario']) ?>" <?= $fUsuario === $x['usuario'] ? 'selected' : '' ?>><?= $e($x['nombre']) ?> · <?= $e($x['usuario']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="campo">
      <label for="f-accion">Acción</label>
      <select id="f-accion" name="accion">
        <option value="">Todas</option>
        <?php foreach ($acciones as $a): ?>
          <option value="<?= $e($a) ?>" <?= $fAccion === $a ? 'selected' : '' ?>><?= $e($accionTxt($a)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="campo">
      <label for="f-entidad">Entidad</label>
      <select id="f-entidad" name="entidad">
        <option value="">Todas</option>
        <?php foreach ($entidades as $en): ?>
          <option value="<?= $e($en) ?>" <?= $fEntidad === $en ? 'selected' : '' ?>><?= $e($en) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="campo">
      <label for="f-ref">Referencia</label>
      <input type="text" id="f-ref" name="referencia" value="<?= $e($fRef) ?>" placeholder="aviso, OT, usuario…" style="width:150px">
    </div>
    <div class="campo">
      <label for="f-desde">Desde</label>
      <input type="date" id="f-desde" name="desde" value="<?= $e($fDesde) ?>">
    </div>
    <div class="campo">
      <label for="f-hasta">Hasta</label>
      <input type="date" id="f-hasta" name="hasta" value="<?= $e($fHasta) ?>">
    </div>
    <div class="campo">
      <label for="f-exito">Resultado</label>
      <select id="f-exito" name="exito">
        <option value="">Todos</option>
        <option value="1" <?= $fExito === '1' ? 'selected' : '' ?>>Con éxito</option>
        <option value="0" <?= $fExito === '0' ? 'selected' : '' ?>>Denegados o fallidos</option>
      </select>
    </div>
    <div class="campo" style="flex:1;min-width:160px">
      <label for="f-q">En el detalle</label>
      <input type="search" id="f-q" name="q" value="<?= $e($fTexto) ?>" placeholder="texto del detalle o de los datos">
    </div>
    <div class="campo">
      <label>&nbsp;</label>
      <button class="btn primary" type="submit" style="height:38px">Filtrar</button>
    </div>
    <div class="campo"><label>&nbsp;</label>
      <a class="btn" href="<?= $e($enlace(['formato' => 'csv'])) ?>" style="height:38px;display:flex;align-items:center"
         title="Lo filtrado, hasta <?= $TOPE_CSV ?> filas">Exportar CSV</a>
    </div>
    <?php if (array_filter($filtros)): ?>
      <div class="campo"><label>&nbsp;</label>
        <a class="btn" href="bitacora.php" style="height:38px;display:flex;align-items:center">Limpiar</a>
      </div>
    <?php endif; ?>
  </form>

  <p class="sub" style="margin:0 0 8px">
    <b><?= count($filas) ?></b> de <b><?= $total ?></b> <?= $total === 1 ? 'fila' : 'filas' ?><?= $paginas > 1 ? ' · página ' . $pagina . ' de ' . $paginas : '' ?>.
    La más reciente arriba.
  </p>

  <div class="tabla-wrap">
    <table class="tarjetas bitacora">
      <thead><tr>
        <th>Cuándo</th><th>Quién</th><th>Qué</th><th>Sobre</th><th>Detalle</th><th>Desde</th>
      </tr></thead>
      <tbody>
      <?php if (!$filas): ?>
        <tr><td colspan="6" class="vacio">Nada con esos filtros.</td></tr>
      <?php endif; ?>
      <?php foreach ($filas as $f): ?>
        <?php $dest = $destino($f['entidad'], $f['referencia']); $datos = json_decode((string) $f['datos'], true); ?>
        <tr class="<?= (int) $f['exito'] === 0 ? 'denegado' : '' ?>">
          <td data-th="Cuándo" class="mono sin-cortar"><?= $e(substr((string) $f['cuando'], 0, 19)) ?></td>
          <td data-th="Quién">
            <b><?= $e((string) ($f['usuario'] ?: '—')) ?></b>
            <?php if ($f['usuario_id']): ?>
              <a class="desc" href="bitacora.php?usuario=<?= rawurlencode((string) $f['usuario']) ?>">ver su actividad</a>
            <?php endif; ?>
          </td>
          <td data-th="Qué">
            <span class="chip <?= (int) $f['exito'] === 0 ? 'chip-no' : '' ?>" title="<?= $e((string) $f['accion']) ?>"><?= $e($accionTxt($f['accion'])) ?></span>
            <?php if ((int) $f['exito'] === 0): ?><span class="desc" style="color:var(--danger)">denegado o fallido</span><?php endif; ?>
          </td>
          <td data-th="Sobre">
            <?= $e((string) ($f['entidad'] ?: '')) ?>
            <?php if ($f['referencia'] !== null && $f['referencia'] !== ''): ?>
              <?php if ($dest): ?>
                <a class="mono" href="<?= $e($dest) ?>"><?= $e((string) $f['referencia']) ?></a>
              <?php else: ?>
                <span class="mono"><?= $e((string) $f['referencia']) ?></span>
              <?php endif; ?>
            <?php endif; ?>
            <?php if ($f['estado_antes'] !== null || $f['estado_despues'] !== null): ?>
              <span class="desc" title="<?= $e((string) ($f['estado_antes'] ?? '—') . ' → ' . (string) ($f['estado_despues'] ?? '—')) ?>"><?= $e($estadoTxt($f['entidad'], $f['estado_antes'])) ?> → <?= $e($estadoTxt($f['entidad'], $f['estado_despues'])) ?></span>
            <?php endif; ?>
          </td>
          <td data-th="Detalle">
            <?= $e((string) ($f['detalle'] ?? '')) ?>
            <?php if (is_array($datos) && $datos): ?>
              <details class="datos"><summary>datos</summary>
                <pre><?= $e(json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
              </details>
            <?php endif; ?>
          </td>
          <td data-th="Desde">
            <span class="mono"><?= $e((string) ($f['ip'] ?: '—')) ?></span>
            <?php if (!empty($f['equipo'])): ?><span class="desc" title="<?= $e((string) $f['equipo']) ?>"><?= $e(mb_strimwidth((string) $f['equipo'], 0, 40, '…', 'UTF-8')) ?></span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($paginas > 1): ?>
    <nav class="paginacion" aria-label="Páginas">
      <?php if ($pagina > 1): ?><a class="btn sm" href="<?= $e($enlace(['p' => $pagina - 1])) ?>">← Más recientes</a><?php endif; ?>
      <span class="sub">página <?= $pagina ?> de <?= $paginas ?></span>
      <?php if ($pagina < $paginas): ?><a class="btn sm" href="<?= $e($enlace(['p' => $pagina + 1])) ?>">Más antiguas →</a><?php endif; ?>
    </nav>
  <?php endif; ?>
</div>

<?php Ui::pie(); ?>
