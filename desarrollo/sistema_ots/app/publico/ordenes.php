<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Emision.php';
require_once __DIR__ . '/nucleo/Catalogo.php';
require_once __DIR__ . '/pdf.php';        // solo para enlaceCompartido() y otValida()
require_once __DIR__ . '/nucleo/Ui.php';

/**
 * ordenes.php — El Archivo: las órdenes de trabajo de todas las zonas.
 *
 * LA REGLA DEL 2026-09-12 (D1)
 * Andrés decidió que el archivo general de OT —de las tres zonas, como hoy en
 * Google Drive— lo consulta, comparte y descarga todo el personal, en solo
 * lectura: nadie borra ni edita nada desde aquí. El corte por zona sigue
 * rigiendo las ACCIONES (asignar, validar, cerrar), no la lectura. El control
 * compensatorio es la bitácora: cada consulta con sus filtros, cada apertura,
 * cada descarga y cada enlace compartido quedan con quién, cuándo y desde dónde.
 *
 * DE DÓNDE SALE LA LISTA
 * De `ot_archivo` (009), el índice persistente que llena
 * `archivo_indexar_cli.php`: los PDF que están en el servidor, lo que emitió la
 * app, lo que llegó por el buzón de correo y el catálogo histórico que exporta
 * la estación (7.069 órdenes). Hasta el 2026-09-13 esta pantalla listaba solo
 * los casos de la ventana de 90 días del correo y recortaba por rol.
 *
 * LO QUE NO ESTÁ EN EL SERVIDOR
 * Una orden puede estar en el índice y su PDF vivir solo en la estación (D2:
 * subir los históricos a Hostinger está pendiente). Esa fila dice dónde está y
 * ofrece «pedir copia», que deja la solicitud en `ot_archivo_solicitudes` para
 * que la estación la atienda en su próximo saneamiento.
 *
 * COMPARTIR EL INFORME
 * El administrador del local pide su copia y no tiene usuario en el sistema.
 * El enlace firmado y con caducidad de `pdf.php` se genera AL PULSAR el botón
 * (antes se generaban todos al pintar la tabla, sin rastro), con quién lo
 * compartió dentro de la firma, y queda en la bitácora como COMPARTIR_PDF.
 * El botón de WhatsApp abre `wa.me` con el mensaje escrito: manda **a quien lo
 * pulsa** a su propio WhatsApp; el servidor no envía nada por su cuenta.
 */

$u = Auth::exigir();
if (!Ui::puedeModulo(['ots.archivo', 'ots.ver'], ['SUPERADMIN', 'ADMIN', 'JEFE_ZONA', 'TECNICO'], $u)) {
    Auth::bitacora('DENEGADO', 'archivo_ot', '', 'sin permiso', null, null, [], false);
    http_response_code(403);
    exit('No tienes acceso al archivo.');
}

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function json(int $codigo, array $cuerpo): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    exit;
}

$hayIndice = true;
try { Db::todos('SELECT 1 FROM ot_archivo LIMIT 1'); } catch (Throwable $ex) { $hayIndice = false; }

/* -------------------------------------------------------------------------
   LAS DOS ACCIONES. Responden JSON: las pide el botón por fetch y la pantalla
   no se recarga. Las dos exigen el token CSRF y dejan bitácora.
   ------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::exigirCsrf();
    $accion = (string) ($_POST['accion'] ?? '');
    $ot = strtoupper(trim((string) ($_POST['ot'] ?? '')));
    if ($ot === '' || !otValida($ot)) { json(400, ['ok' => false, 'error' => 'Número de OT INDUSTEC no válido.']); }
    $fila = $hayIndice ? Db::uno('SELECT * FROM ot_archivo WHERE id_industec = ?', [$ot]) : null;

    if ($accion === 'compartir') {
        if (!Ui::puedeModulo('ots.compartir', ['SUPERADMIN', 'ADMIN', 'JEFE_ZONA', 'TECNICO'], $u)) {
            Auth::bitacora('DENEGADO', 'ot', $ot, 'compartir sin permiso', null, null, [], false);
            json(403, ['ok' => false, 'error' => 'No tienes permiso para compartir OT INDUSTEC.']);
        }
        if (!Emision::existePdf($ot)) {
            json(404, ['ok' => false, 'error' => 'El PDF de esa OT INDUSTEC no está en el servidor: no hay qué compartir.']);
        }
        $cfg = Db::config();
        $secreto = (string) ($cfg['enlace_secreto'] ?? $cfg['sync_secreto'] ?? '');
        if ($secreto === '') { json(503, ['ok' => false, 'error' => 'El servidor no tiene configurado el secreto de los enlaces.']); }
        $canal = in_array($_POST['canal'] ?? '', ['whatsapp', 'correo', 'copiar'], true) ? (string) $_POST['canal'] : 'copiar';
        $enlace = enlaceCompartido($ot, $secreto, (int) $u['usuario_id']);
        parse_str((string) parse_url($enlace, PHP_URL_QUERY), $q);
        $exp = (int) ($q['exp'] ?? 0);
        Auth::bitacora('COMPARTIR_PDF', 'ot', $ot, 'enlace de ' . HORAS_ENLACE . ' h por ' . $canal,
                       null, null, ['exp' => date('c', $exp), 'canal' => $canal, 'horas' => HORAS_ENLACE]);
        json(200, ['ok' => true, 'enlace' => $enlace, 'caduca' => date('Y-m-d H:i', $exp), 'horas' => HORAS_ENLACE]);
    }

    if ($accion === 'pedir_copia') {
        if ($fila === null) { json(404, ['ok' => false, 'error' => 'Esa OT INDUSTEC no está en el índice.']); }
        if ((int) $fila['en_servidor'] === 1 || Emision::existePdf($ot)) {
            json(409, ['ok' => false, 'error' => 'Esa OT INDUSTEC ya está en el servidor: ábrela directamente.']);
        }
        $n = Db::ejecutar(
            'INSERT INTO ot_archivo_solicitudes (id_industec, usuario_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE solicitado_en = IF(atendido_en IS NULL, solicitado_en, NOW()),
                                     atendido_en = NULL',
            [$ot, (int) $u['usuario_id']]
        );
        Auth::bitacora('SOLICITAR_COPIA', 'ot', $ot, 'copia de la estación: ' . (string) ($fila['fuente_ruta'] ?? '-'),
                       null, null, ['fuente_ruta' => $fila['fuente_ruta'] ?? null, 'nueva' => $n === 1]);
        json(200, ['ok' => true, 'mensaje' => $n === 1
            ? 'Pedido. La estación la sube en su próximo saneamiento y te avisamos aquí mismo.'
            : 'Ya la habías pedido: sigue en la cola de la estación.']);
    }

    json(400, ['ok' => false, 'error' => 'Acción no reconocida.']);
}

/* -------------------------------------------------------------------------
   LOS FILTROS. Todos en el WHERE, con paginación de 50: el archivo tiene
   miles de filas y pintarlas todas es lo que arrastra el celular del técnico.
   ------------------------------------------------------------------------- */
$ZONAS = ['UIO', 'LARB', 'CNLJ', 'OTRA'];
$fq      = trim((string) ($_GET['q'] ?? ''));
$fZona   = strtoupper((string) ($_GET['zona'] ?? ''));
if (!in_array($fZona, $ZONAS, true)) { $fZona = ''; }
$fLocal  = strtoupper(trim((string) ($_GET['local'] ?? '')));
$fDesde  = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['desde'] ?? '')) ? (string) $_GET['desde'] : '';
$fHasta  = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['hasta'] ?? '')) ? (string) $_GET['hasta'] : '';
$fTec    = trim((string) ($_GET['tecnico'] ?? ''));
$fOrigen = strtoupper((string) ($_GET['origen'] ?? ''));
if (!in_array($fOrigen, ['APP', 'CORREO', 'HISTORICO'], true)) { $fOrigen = ''; }
$fDonde  = (string) ($_GET['donde'] ?? '');      // '' | servidor | estacion
$fRec    = ($_GET['rec'] ?? '') === '1';          // últimos 90 días
$fMias   = ($_GET['mias'] ?? '') === '1' && $u['rol'] === 'TECNICO';
$pagina  = max(1, (int) ($_GET['p'] ?? 1));
$POR_PAGINA = 50;

$donde = ['1=1'];
$par = [];
if ($fq !== '') {
    $donde[] = '(a.id_industec LIKE ? OR a.aviso LIKE ? OR a.local_codigo LIKE ? OR a.local_nombre LIKE ? OR a.tecnico LIKE ?)';
    $like = '%' . $fq . '%';
    array_push($par, $like, $like, $like, $like, $like);
}
if ($fZona !== '')   { $donde[] = 'a.zona = ?';          $par[] = $fZona; }
if ($fLocal !== '')  { $donde[] = 'a.local_codigo = ?';  $par[] = $fLocal; }
if ($fDesde !== '')  { $donde[] = 'a.fecha_atencion >= ?'; $par[] = $fDesde; }
if ($fHasta !== '')  { $donde[] = 'a.fecha_atencion <= ?'; $par[] = $fHasta; }
if ($fTec !== '')    { $donde[] = 'a.tecnico LIKE ?';    $par[] = '%' . $fTec . '%'; }
if ($fOrigen !== '') { $donde[] = 'a.origen = ?';        $par[] = $fOrigen; }
if ($fDonde === 'servidor') { $donde[] = 'a.en_servidor = 1'; }
if ($fDonde === 'estacion') { $donde[] = 'a.en_servidor = 0'; }
if ($fRec)           { $donde[] = 'a.fecha_atencion >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)'; }
if ($fMias)          { $donde[] = 'a.tecnico LIKE ?';    $par[] = '%' . $u['nombre'] . '%'; }
$where = implode(' AND ', $donde);

$total = 0; $filas = []; $porZona = []; $tecnicos = [];
if ($hayIndice) {
    $total = (int) (Db::uno("SELECT COUNT(*) n FROM ot_archivo a WHERE $where", $par)['n'] ?? 0);
    $paginas = max(1, (int) ceil($total / $POR_PAGINA));
    if ($pagina > $paginas) { $pagina = $paginas; }
    $filas = Db::todos(
        "SELECT a.* FROM ot_archivo a WHERE $where
          ORDER BY a.fecha_atencion DESC, a.id_industec DESC
          LIMIT $POR_PAGINA OFFSET " . (($pagina - 1) * $POR_PAGINA), $par
    );
    foreach (Db::todos('SELECT zona, COUNT(*) n, SUM(en_servidor) s FROM ot_archivo GROUP BY zona') as $z) {
        $porZona[(string) $z['zona']] = ['n' => (int) $z['n'], 's' => (int) $z['s']];
    }
    $tecnicos = array_column(Db::todos(
        'SELECT tecnico FROM ot_archivo WHERE tecnico IS NOT NULL AND tecnico <> "" GROUP BY tecnico ORDER BY tecnico'
    ), 'tecnico');
} else {
    $paginas = 1;
}
$totalIndice = array_sum(array_map(fn($z) => $z['n'], $porZona));
$enServidor  = array_sum(array_map(fn($z) => $z['s'], $porZona));

$hayFiltro = $fq !== '' || $fZona !== '' || $fLocal !== '' || $fDesde !== '' || $fHasta !== ''
          || $fTec !== '' || $fOrigen !== '' || $fDonde !== '' || $fRec || $fMias;

// SEG-03: la consulta del archivo queda registrada con sus filtros y cuántas
// filas devolvió. Es lo que permite ver un barrido sistemático del archivo.
Auth::bitacora('CONSULTAR', 'archivo_ot', $fZona !== '' ? $fZona : 'todas',
               'q=' . $fq . ' visibles=' . count($filas) . ' de ' . $total,
               null, null, ['q' => $fq, 'zona' => $fZona, 'local' => $fLocal, 'desde' => $fDesde,
                            'hasta' => $fHasta, 'tecnico' => $fTec, 'origen' => $fOrigen,
                            'donde' => $fDonde, 'rec' => $fRec, 'p' => $pagina, 'n' => $total]);

$puedeCompartir = Ui::puedeModulo('ots.compartir', ['SUPERADMIN', 'ADMIN', 'JEFE_ZONA', 'TECNICO'], $u);
$locales = Catalogo::cargar()['locales'] ?? [];
$ORIGEN = ['APP' => 'app', 'CORREO' => 'correo', 'HISTORICO' => 'histórico'];

/** Los parámetros vigentes, para armar enlaces que conserven los filtros. */
$enlace = static function (array $cambios = []) use ($fq, $fZona, $fLocal, $fDesde, $fHasta, $fTec, $fOrigen, $fDonde, $fRec, $fMias): string {
    $p = array_filter(array_merge([
        'q' => $fq, 'zona' => $fZona, 'local' => $fLocal, 'desde' => $fDesde, 'hasta' => $fHasta,
        'tecnico' => $fTec, 'origen' => $fOrigen, 'donde' => $fDonde, 'rec' => $fRec ? '1' : '',
        'mias' => $fMias ? '1' : '',
    ], $cambios), static fn($v) => $v !== '' && $v !== null);
    return 'ordenes.php' . ($p ? '?' . http_build_query($p) : '');
};

Ui::cabecera($u, 'ordenes.php', [], ['titulo' => 'Archivo de OT INDUSTEC']);
?>
<div class="wrap ancho">

  <div class="titulo entra">
    <h1>Archivo de OT INDUSTEC</h1>
    <p class="sub">
      Las OT INDUSTEC de las tres zonas, en solo lectura: se consultan,
      se abren, se descargan y se comparten, y cada uno de esos gestos queda en
      la bitácora con tu nombre. Nada se borra ni se edita desde aquí.
    </p>
  </div>

  <?php if (!$hayIndice): ?>
    <?= Ui::aviso('info', '<b>El índice del archivo todavía no está en la base.</b>'
        . '<p>La migración <span class="mono">009_pulido_piloto.sql</span> crea la tabla '
        . '<span class="mono">ot_archivo</span>; hasta aplicarla no hay qué mostrar.</p>') ?>
  <?php elseif ($totalIndice === 0): ?>
    <?= Ui::aviso('info', '<b>El índice del archivo está vacío.</b>'
        . '<p>Se llena con <span class="mono">archivo_indexar_cli.php</span> (los PDF del servidor, lo '
        . 'que emitió la app y lo que llegó por correo) y con el catálogo histórico que exporta la '
        . 'estación (<span class="mono">t2_15_exportar_archivo.py --empujar</span>).</p>') ?>
  <?php else: ?>

    <div class="tiles tiles-enlace">
      <a class="tile azul" href="ordenes.php">
        <div class="n" data-n="<?= $totalIndice ?>">0</div>
        <div class="t">OT INDUSTEC en el archivo</div>
        <div class="pie"><?= $enServidor ?> con el PDF en el servidor</div>
      </a>
      <?php foreach ($ZONAS as $z): ?>
        <?php if (empty($porZona[$z])) { continue; } ?>
        <a class="tile zona-tile zona-<?= strtolower($z) ?> <?= $fZona === $z ? 'on' : '' ?>" href="<?= e($enlace(['zona' => $z])) ?>">
          <div class="n" data-n="<?= $porZona[$z]['n'] ?>">0</div>
          <div class="t"><?= Ui::zona($z) ?></div>
          <div class="pie"><?= $porZona[$z]['s'] ?> en el servidor</div>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="filtros-rapidos">
      <a class="fr <?= !$hayFiltro ? 'on' : '' ?>" href="ordenes.php">Todas</a>
      <a class="fr <?= $fRec ? 'on' : '' ?>" href="<?= e($enlace(['rec' => '1'])) ?>">Últimos 90 días</a>
      <?php if ($u['rol'] === 'TECNICO'): ?>
        <a class="fr <?= $fMias ? 'on' : '' ?>" href="<?= e($enlace(['mias' => '1'])) ?>">Las mías</a>
      <?php endif; ?>
      <a class="fr <?= $fDonde === 'servidor' ? 'on' : '' ?>" href="<?= e($enlace(['donde' => 'servidor'])) ?>">Con PDF aquí</a>
      <a class="fr <?= $fDonde === 'estacion' ? 'on' : '' ?>" href="<?= e($enlace(['donde' => 'estacion'])) ?>">Solo en la estación</a>
    </div>

    <form class="filtros" method="get" data-auto>
      <?php if ($fRec): ?><input type="hidden" name="rec" value="1"><?php endif; ?>
      <?php if ($fMias): ?><input type="hidden" name="mias" value="1"><?php endif; ?>
      <?php if ($fDonde !== ''): ?><input type="hidden" name="donde" value="<?= e($fDonde) ?>"><?php endif; ?>
      <div class="campo" style="flex:1;min-width:200px">
        <label for="f-q">Buscar</label>
        <input type="search" id="f-q" name="q" value="<?= e($fq) ?>"
               placeholder="OT INDUSTEC, aviso SAP, local o técnico"
               data-busca="#tabla-archivo" data-busca-cuenta="#cuenta-archivo">
      </div>
      <div class="campo">
        <label for="f-zona">Zona</label>
        <select id="f-zona" name="zona">
          <option value="">Las tres</option>
          <?php foreach ($ZONAS as $z): ?>
            <option value="<?= $z ?>" <?= $fZona === $z ? 'selected' : '' ?>><?= e(Ui::zonaCorta($z)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label for="f-local">Local</label>
        <input type="text" id="f-local" name="local" list="locales" value="<?= e($fLocal) ?>"
               placeholder="código" style="width:120px;text-transform:uppercase" autocomplete="off">
        <datalist id="locales">
          <?php foreach ($locales as $l): ?>
            <option value="<?= e((string) ($l['codigo'] ?? '')) ?>"><?= e((string) ($l['nombre'] ?? '')) ?></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="campo">
        <label for="f-desde">Desde</label>
        <input type="date" id="f-desde" name="desde" value="<?= e($fDesde) ?>">
      </div>
      <div class="campo">
        <label for="f-hasta">Hasta</label>
        <input type="date" id="f-hasta" name="hasta" value="<?= e($fHasta) ?>">
      </div>
      <div class="campo">
        <label for="f-tec">Técnico</label>
        <select id="f-tec" name="tecnico">
          <option value="">Todos</option>
          <?php foreach ($tecnicos as $t): ?>
            <option value="<?= e($t) ?>" <?= $fTec === $t ? 'selected' : '' ?>><?= e($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label for="f-origen">Origen</label>
        <select id="f-origen" name="origen">
          <option value="">Todos</option>
          <?php foreach ($ORIGEN as $k => $et): ?>
            <option value="<?= $k ?>" <?= $fOrigen === $k ? 'selected' : '' ?>><?= e($et) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="campo">
        <label>&nbsp;</label>
        <button class="btn primary" type="submit" style="height:38px">Filtrar</button>
      </div>
      <?php if ($hayFiltro): ?>
        <div class="campo"><label>&nbsp;</label>
          <a class="btn" href="ordenes.php" style="height:38px;display:flex;align-items:center">Limpiar</a>
        </div>
      <?php endif; ?>
    </form>

    <p class="sub" style="margin:0 0 8px">
      <b id="cuenta-archivo" data-plantilla="{n}"><?= count($filas) ?></b> de <b><?= $total ?></b>
      OT INDUSTEC<?= $paginas > 1 ? ' · página ' . $pagina . ' de ' . $paginas : '' ?>.
      La más reciente arriba.
    </p>

    <div class="tabla-wrap">
      <table id="tabla-archivo" class="tarjetas">
        <thead><tr>
          <th>OT INDUSTEC</th><th>Fecha</th><th>Local</th><th>Zona</th><th>Aviso SAP</th>
          <th>Técnico</th><th>Origen</th><th>Acciones</th>
        </tr></thead>
        <tbody>
        <?php if (!$filas): ?>
          <tr><td colspan="8" class="vacio">Nada con esos filtros. <a href="ordenes.php">Ver todas</a>.</td></tr>
        <?php endif; ?>
        <?php foreach ($filas as $f): ?>
          <?php
          $ot = (string) $f['id_industec'];
          $aqui = (int) $f['en_servidor'] === 1;
          ?>
          <tr data-b="<?= Ui::claveFila([$ot, (string) $f['aviso'], (string) $f['local_codigo'],
                                         (string) $f['local_nombre'], (string) $f['tecnico']]) ?>">
            <td data-th="OT INDUSTEC">
              <span class="mono"><?= e($ot) ?></span>
              <?php if (!empty($f['modulo'])): ?>
                <span class="desc"><?= e(strtolower((string) $f['modulo'])) ?><?= !empty($f['dia']) ? ' · día ' . (int) $f['dia'] : '' ?></span>
              <?php endif; ?>
            </td>
            <td data-th="Fecha" class="mono"><?= e((string) ($f['fecha_atencion'] ?: '—')) ?></td>
            <td data-th="Local">
              <b><?= e((string) ($f['local_codigo'] ?: '—')) ?></b>
              <span class="desc"><?= e((string) $f['local_nombre']) ?></span>
            </td>
            <td data-th="Zona"><?= Ui::zona((string) $f['zona']) ?></td>
            <td data-th="Aviso SAP" class="mono"><?= e((string) ($f['aviso'] ?: '—')) ?></td>
            <td data-th="Técnico"><?= e((string) ($f['tecnico'] ?: '—')) ?></td>
            <td data-th="Origen"><span class="chip"><?= e($ORIGEN[$f['origen']] ?? strtolower((string) $f['origen'])) ?></span></td>
            <td data-th="Acciones">
              <?php if ($aqui): ?>
                <div class="acc">
                  <a class="btn primary sm" href="pdf.php?ot=<?= rawurlencode($ot) ?>" target="_blank" rel="noopener">Ver</a>
                  <a class="btn sm" href="pdf.php?ot=<?= rawurlencode($ot) ?>&amp;dl=1">Descargar</a>
                  <?php if ($puedeCompartir): ?>
                    <button class="btn sm" type="button" data-ot="<?= e($ot) ?>" onclick="compartir(this)">Compartir</button>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <div class="acc">
                  <button class="btn sm" type="button" data-ot="<?= e($ot) ?>" onclick="pedirCopia(this)">Pedir copia</button>
                </div>
                <span class="desc" title="<?= e((string) ($f['fuente_ruta'] ?? '')) ?>">
                  en la estación<?= !empty($f['fuente_ruta']) ? ': ' . e(mb_strimwidth((string) $f['fuente_ruta'], 0, 60, '…', 'UTF-8')) : '' ?>
                </span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($paginas > 1): ?>
      <nav class="paginacion" aria-label="Páginas">
        <?php if ($pagina > 1): ?>
          <a class="btn sm" href="<?= e($enlace(['p' => $pagina - 1])) ?>">← Anteriores</a>
        <?php endif; ?>
        <span class="sub">página <?= $pagina ?> de <?= $paginas ?></span>
        <?php if ($pagina < $paginas): ?>
          <a class="btn sm" href="<?= e($enlace(['p' => $pagina + 1])) ?>">Siguientes →</a>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</div>

<dialog id="dlg">
  <h2 style="margin:0 0 4px;font-size:17px">Compartir la OT INDUSTEC</h2>
  <p class="sub" style="margin:0 0 10px">
    Enlace de <b id="dlg-ot" class="mono"></b>. <b>Caduca <span id="dlg-caduca"></span></b> y deja
    registrado quién lo abrió y que lo compartiste tú. Va con la firma del
    administrador del local: mándaselo solo a quien corresponde.
  </p>
  <div class="enlace-caja" id="dlg-url"></div>
  <div class="row" style="gap:8px;flex-wrap:wrap">
    <button class="btn primary" type="button" onclick="copiar()">Copiar enlace</button>
    <a class="btn" id="dlg-wa" target="_blank" rel="noopener">Mandar por WhatsApp</a>
    <a class="btn" id="dlg-mail">Mandar por correo</a>
    <button class="btn" type="button" onclick="document.getElementById('dlg').close()">Cerrar</button>
  </div>
  <p class="sub" id="dlg-copiado" hidden style="margin:8px 0 0;color:var(--ok)">Copiado.</p>
</dialog>

<script>
var CSRF = document.querySelector('meta[name="csrf"]') ? document.querySelector('meta[name="csrf"]').content : '';

function accion(datos) {
  var fd = new FormData();
  Object.keys(datos).forEach(function (k) { fd.append(k, datos[k]); });
  fd.append('csrf', CSRF);
  return fetch('ordenes.php', { method: 'POST', body: fd, credentials: 'same-origin',
                                headers: { 'X-Csrf': CSRF } })
    .then(function (r) { return r.json().then(function (j) { j._status = r.status; return j; }); });
}

/* El enlace se pide al servidor al pulsar: así queda en la bitácora quién lo
   compartió y cuándo, y la tabla no lleva cien enlaces firmados de antemano. */
function compartir(b) {
  var ot = b.dataset.ot;
  b.disabled = true;
  accion({ accion: 'compartir', ot: ot, canal: 'copiar' }).then(function (j) {
    b.disabled = false;
    if (!j.ok) { UI.toast(j.error || 'No se pudo generar el enlace.', 'err'); return; }
    var url = location.origin + location.pathname.replace(/ordenes\.php$/, '') + j.enlace;
    document.getElementById('dlg-ot').textContent = ot;
    document.getElementById('dlg-caduca').textContent = 'el ' + j.caduca + ' (' + j.horas + ' horas)';
    document.getElementById('dlg-url').textContent = url;
    document.getElementById('dlg-copiado').hidden = true;
    var texto = 'OT INDUSTEC ' + ot + ': ' + url
              + ' (el enlace caduca en ' + j.horas + ' horas)';
    document.getElementById('dlg-wa').href = 'https://wa.me/?text=' + encodeURIComponent(texto);
    document.getElementById('dlg-mail').href =
        'mailto:?subject=' + encodeURIComponent('OT INDUSTEC ' + ot)
      + '&body=' + encodeURIComponent(texto);
    document.getElementById('dlg').showModal();
  }).catch(function () { b.disabled = false; UI.toast('Sin conexión con el servidor.', 'err'); });
}

function pedirCopia(b) {
  var ot = b.dataset.ot;
  b.disabled = true;
  accion({ accion: 'pedir_copia', ot: ot }).then(function (j) {
    if (!j.ok) { b.disabled = false; UI.toast(j.error || 'No se pudo pedir la copia.', 'err'); return; }
    b.textContent = 'Copia pedida';
    UI.toast(j.mensaje, 'ok');
  }).catch(function () { b.disabled = false; UI.toast('Sin conexión con el servidor.', 'err'); });
}

function copiar() {
  var t = document.getElementById('dlg-url').textContent;
  navigator.clipboard.writeText(t).then(function () {
    document.getElementById('dlg-copiado').hidden = false;
  }).catch(function () {
    /* Sin permiso de portapapeles queda el texto seleccionable a mano: el
       recuadro tiene user-select:all, así que un clic lo selecciona entero. */
    document.getElementById('dlg-url').focus();
  });
}
</script>

<?php Ui::pie(['js' => ['busqueda.js']]); ?>
