<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Ui.php';
require_once __DIR__ . '/nucleo/Vocabulario.php';   // «por aprobar»: el documento no está «en revisión»

/**
 * documentos.php — Aprendizaje: manuales, guías y comunicados, con aprobación.
 *
 * LA DECISIÓN DEL 2026-09-12 (D11)
 * El apartado del archivo es también de aprendizaje: manuales de equipos, guías
 * de procedimiento y comunicados de la empresa, para que un técnico nuevo o uno
 * de otra zona encuentre cómo se hace lo que está haciendo. Lo suben el jefe de
 * zona y la administración; el técnico puede PROPONER (sube y queda en revisión
 * igual); la administración APRUEBA o RECHAZA, con nota; y todo queda en la
 * bitácora. Nada se borra: una versión se retira, y se conserva.
 *
 * LO QUE SE VE ES LA ÚLTIMA VERSIÓN APROBADA. Un documento es el título; cada
 * archivo subido es una versión con su huella (la misma no entra dos veces).
 * Los comunicados piden acuse: «visto por X de Y», y el técnico marca «leído».
 *
 * LOS ARCHIVOS VIVEN EN `documentos/` CON NOMBRE ALEATORIO Y SIN ACCESO WEB
 * (el .htaccess niega todo): solo `documento.php` los sirve, con sesión y
 * bitácora. Un manual de un fabricante o un comunicado interno no tienen por
 * qué estar en una URL adivinable.
 */

$u = Auth::exigir();
if (!Ui::puedeModulo('documentos.ver', ['SUPERADMIN', 'ADMIN', 'JEFE_ZONA', 'TECNICO'], $u)) {
    Auth::bitacora('DENEGADO', 'documento', '', 'sin permiso', null, null, [], false);
    http_response_code(403);
    exit('No tienes acceso al aprendizaje.');
}

$e = fn(?string $s): string => Ui::e($s);

const DIR_DOCS = __DIR__ . '/documentos';
const TOPE_BYTES = 25 * 1024 * 1024;
const TIPOS_ARCHIVO = [
    // extensión => [mimes que se aceptan del detector, mime con que se sirve]
    'pdf'  => [['application/pdf'], 'application/pdf'],
    'jpg'  => [['image/jpeg'], 'image/jpeg'],
    'jpeg' => [['image/jpeg'], 'image/jpeg'],
    'png'  => [['image/png'], 'image/png'],
    'docx' => [['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
               'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    'xlsx' => [['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
               'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    'mp4'  => [['video/mp4', 'application/octet-stream'], 'video/mp4'],
];
$TIPOS = ['MANUAL' => 'Manual', 'GUIA' => 'Guía', 'COMUNICADO' => 'Comunicado'];
$ZONAS = ['UIO', 'LARB', 'CNLJ', 'OTRA'];
/* El estado de cada versión como se lee. Antes salía el código en minúsculas
   («en revision»), con la palabra de la orden; los otros tres son del propio
   Aprendizaje y no chocan con nada (fuera_de_alcance del diccionario). */
$ETIQ_VERSION = ['EN_REVISION' => Vocabulario::t('DOCUMENTO_POR_APROBAR'), 'APROBADA' => 'aprobada',
                 'RECHAZADA' => 'rechazada', 'RETIRADA' => 'retirada'];

$puedeSubir    = Ui::puedeModulo('documentos.subir',    ['SUPERADMIN', 'ADMIN', 'JEFE_ZONA'], $u);
$puedeProponer = Ui::puedeModulo('documentos.proponer', ['TECNICO'], $u);
$puedeAprobar  = Ui::puedeModulo('documentos.aprobar',  ['SUPERADMIN', 'ADMIN'], $u);

$hayTablas = true;
try { Db::todos('SELECT 1 FROM documento_versiones LIMIT 1'); } catch (Throwable $ex) { $hayTablas = false; }

function terminarDoc(?string $ok, ?string $error, string $ancla = ''): void
{
    $_SESSION['flash'] = array_filter(['ok' => $ok, 'error' => $error]);
    header('Location: documentos.php' . ($ancla !== '' ? $ancla : ('?' . (string) ($_SERVER['QUERY_STRING'] ?? ''))));
    exit;
}

/** La carpeta de los archivos, con su .htaccess, exista o no todavía. */
function carpetaDocs(): string
{
    if (!is_dir(DIR_DOCS)) { @mkdir(DIR_DOCS, 0750, true); }
    $ht = DIR_DOCS . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "# Solo documento.php sirve estos archivos, con sesión y bitácora.\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\nOptions -Indexes\n");
    }
    return DIR_DOCS;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::exigirCsrf();
    if (!$hayTablas) { terminarDoc(null, 'El aprendizaje todavía no está instalado en la base (migración 009).'); }
    $accion = (string) ($_POST['accion'] ?? '');

    if ($accion === 'subir') {
        if (!$puedeSubir && !$puedeProponer) {
            Auth::bitacora('DENEGADO', 'documento', '', 'subir sin permiso', null, null, [], false);
            http_response_code(403);
            exit('No tienes permiso para subir documentos.');
        }
        $tipo   = strtoupper((string) ($_POST['tipo'] ?? ''));
        $titulo = trim((string) ($_POST['titulo'] ?? ''));
        $desc   = trim((string) ($_POST['descripcion'] ?? ''));
        $fam    = trim((string) ($_POST['familia'] ?? '')) ?: null;
        $zona   = strtoupper((string) ($_POST['zona'] ?? '')) ?: null;
        $docId  = (int) ($_POST['doc_id'] ?? 0);
        if (!isset($TIPOS[$tipo])) { terminarDoc(null, 'Di qué tipo de documento es: manual, guía o comunicado.'); }
        if ($titulo === '' && $docId === 0) { terminarDoc(null, 'Falta el título.'); }
        if ($zona !== null && !in_array($zona, $ZONAS, true)) { $zona = null; }
        if ($fam !== null && !Db::uno('SELECT 1 FROM familias_equipo WHERE familia = ?', [$fam])) { $fam = null; }

        $f = $_FILES['archivo'] ?? null;
        if (!$f || (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
            terminarDoc(null, in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'El archivo pasa del tamaño que acepta el servidor.' : 'No llegó ningún archivo.');
        }
        if ((int) $f['size'] > TOPE_BYTES) { terminarDoc(null, 'El archivo pasa de 25 MB. Comprímelo o divídelo.'); }
        $ext = strtolower((string) pathinfo((string) $f['name'], PATHINFO_EXTENSION));
        if (!isset(TIPOS_ARCHIVO[$ext])) { terminarDoc(null, 'Solo se aceptan PDF, JPG, PNG, DOCX, XLSX y MP4.'); }
        $mimeReal = (string) (new finfo(FILEINFO_MIME_TYPE))->file((string) $f['tmp_name']);
        if (!in_array($mimeReal, TIPOS_ARCHIVO[$ext][0], true)) {
            terminarDoc(null, 'El contenido del archivo no corresponde a su extensión (' . $mimeReal . ').');
        }
        $sha = hash_file('sha256', (string) $f['tmp_name']);
        $rep = Db::uno('SELECT v.doc_id, v.version, d.titulo FROM documento_versiones v JOIN documentos d ON d.doc_id = v.doc_id WHERE v.sha256 = ?', [$sha]);
        if ($rep) {
            terminarDoc(null, 'Ese mismo archivo ya está subido: es la versión ' . (int) $rep['version'] . ' de «' . $rep['titulo'] . '».');
        }

        // El documento: uno existente (nueva versión) o uno nuevo por (tipo, título).
        if ($docId > 0) {
            $doc = Db::uno('SELECT * FROM documentos WHERE doc_id = ?', [$docId]);
            if (!$doc) { terminarDoc(null, 'Ese documento no existe.'); }
        } else {
            $doc = Db::uno('SELECT * FROM documentos WHERE tipo = ? AND titulo = ?', [$tipo, $titulo]);
            if (!$doc) {
                Db::ejecutar('INSERT INTO documentos (tipo, titulo, descripcion, familia, zona, creado_por) VALUES (?,?,?,?,?,?)',
                             [$tipo, mb_substr($titulo, 0, 160), $desc !== '' ? mb_substr($desc, 0, 600) : null, $fam, $zona, (int) $u['usuario_id']]);
                $doc = Db::uno('SELECT * FROM documentos WHERE tipo = ? AND titulo = ?', [$tipo, $titulo]);
            } elseif ($desc !== '' || $fam !== null || $zona !== null) {
                Db::ejecutar('UPDATE documentos SET descripcion = COALESCE(NULLIF(?, ""), descripcion), familia = COALESCE(?, familia), zona = COALESCE(?, zona) WHERE doc_id = ?',
                             [mb_substr($desc, 0, 600), $fam, $zona, (int) $doc['doc_id']]);
            }
        }
        $docId = (int) $doc['doc_id'];
        $version = (int) (Db::uno('SELECT COALESCE(MAX(version), 0) + 1 v FROM documento_versiones WHERE doc_id = ?', [$docId])['v'] ?? 1);

        $fisico = bin2hex(random_bytes(16)) . '.' . $ext;
        $destino = carpetaDocs() . '/' . $fisico;
        if (!move_uploaded_file((string) $f['tmp_name'], $destino)) {
            terminarDoc(null, 'No se pudo guardar el archivo en el servidor.');
        }
        @chmod($destino, 0640);

        // Quien puede aprobar y marca «publicar ya» lo deja aprobado en el acto,
        // con su nombre en la revisión: mismo rastro, un clic menos.
        $aprobarYa = $puedeAprobar && !empty($_POST['aprobar_ya']);
        Db::ejecutar(
            'INSERT INTO documento_versiones (doc_id, version, nombre_archivo, ruta, bytes, sha256, mime, estado, subida_por,
                                              revisada_por, revisada_en, nota_revision)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [$docId, $version, mb_substr((string) $f['name'], 0, 160), $fisico, (int) $f['size'], $sha,
             TIPOS_ARCHIVO[$ext][1], $aprobarYa ? 'APROBADA' : 'EN_REVISION', (int) $u['usuario_id'],
             $aprobarYa ? (int) $u['usuario_id'] : null, $aprobarYa ? date('Y-m-d H:i:s') : null,
             $aprobarYa ? 'publicado al subirlo' : null]
        );
        $vid = (int) (Db::uno('SELECT version_id FROM documento_versiones WHERE doc_id = ? AND version = ?', [$docId, $version])['version_id'] ?? 0);
        Auth::bitacora($puedeSubir ? 'SUBIR_DOCUMENTO' : 'PROPONER_DOCUMENTO', 'documento', (string) $docId,
                       $TIPOS[$doc['tipo']] . ' «' . $doc['titulo'] . '» v' . $version . ' (' . $ext . ', ' . round((int) $f['size'] / 1024) . ' KB)',
                       null, $aprobarYa ? 'APROBADA' : 'EN_REVISION',
                       ['version_id' => $vid, 'version' => $version, 'sha256' => $sha, 'bytes' => (int) $f['size']]);
        if ($aprobarYa) {
            Auth::bitacora('APROBAR_DOCUMENTO', 'documento', (string) $docId, 'v' . $version . ' publicada al subirla',
                           'EN_REVISION', 'APROBADA', ['version_id' => $vid]);
        }
        terminarDoc($aprobarYa
            ? 'Publicado: «' . $doc['titulo'] . '» versión ' . $version . '.'
            : 'Subido: «' . $doc['titulo'] . '» versión ' . $version . ' queda ' . Vocabulario::t('DOCUMENTO_POR_APROBAR') . ' hasta que administración la apruebe.',
            null, '#d' . $docId);

    } elseif ($accion === 'aprobar' || $accion === 'rechazar' || $accion === 'retirar') {
        if (!$puedeAprobar) {
            Auth::bitacora('DENEGADO', 'documento', (string) ($_POST['version_id'] ?? ''), $accion . ' sin permiso', null, null, [], false);
            http_response_code(403);
            exit('No tienes permiso para aprobar documentos.');
        }
        $vid = (int) ($_POST['version_id'] ?? 0);
        $nota = trim((string) ($_POST['nota'] ?? ''));
        $v = Db::uno('SELECT v.*, d.titulo, d.tipo FROM documento_versiones v JOIN documentos d ON d.doc_id = v.doc_id WHERE v.version_id = ?', [$vid]);
        if (!$v) { terminarDoc(null, 'Esa versión no existe.'); }
        $de = (string) $v['estado'];
        $a = ['aprobar' => 'APROBADA', 'rechazar' => 'RECHAZADA', 'retirar' => 'RETIRADA'][$accion];
        $desde = ['aprobar' => ['EN_REVISION'], 'rechazar' => ['EN_REVISION'], 'retirar' => ['APROBADA']][$accion];
        if (!in_array($de, $desde, true)) { terminarDoc(null, 'Esa versión está «' . strtolower($de) . '»: no se puede ' . $accion . '.'); }
        if ($accion === 'rechazar' && $nota === '') { terminarDoc(null, 'Para rechazarla hace falta el motivo: quien la subió merece saber por qué.'); }
        $n = Db::ejecutar('UPDATE documento_versiones SET estado = ?, revisada_por = ?, revisada_en = NOW(), nota_revision = NULLIF(?, "") WHERE version_id = ? AND estado = ?',
                          [$a, (int) $u['usuario_id'], mb_substr($nota, 0, 400), $vid, $de]);
        if ($n === 0) { terminarDoc(null, 'Esa versión cambió mientras tanto: recarga la pantalla.'); }
        Auth::bitacora(strtoupper($accion) . '_DOCUMENTO', 'documento', (string) $v['doc_id'],
                       $TIPOS[$v['tipo']] . ' «' . $v['titulo'] . '» v' . (int) $v['version'] . ($nota !== '' ? ' — ' . $nota : ''),
                       $de, $a, ['version_id' => $vid, 'nota' => $nota ?: null]);
        terminarDoc(['aprobar' => 'Aprobada', 'rechazar' => 'Rechazada', 'retirar' => 'Retirada'][$accion]
                    . ': «' . $v['titulo'] . '» versión ' . (int) $v['version'] . '.', null, '#d' . (int) $v['doc_id']);

    } elseif ($accion === 'leido') {
        $docId = (int) ($_POST['doc_id'] ?? 0);
        $v = Db::uno('SELECT MAX(version) v FROM documento_versiones WHERE doc_id = ? AND estado = "APROBADA"', [$docId]);
        if (!$v || $v['v'] === null) { terminarDoc(null, 'Ese comunicado no tiene versión publicada.'); }
        Db::ejecutar('INSERT INTO documento_acuses (doc_id, usuario_id, version) VALUES (?,?,?)
                      ON DUPLICATE KEY UPDATE version = VALUES(version), visto_en = NOW()',
                     [$docId, (int) $u['usuario_id'], (int) $v['v']]);
        Auth::bitacora('ACUSE_DOCUMENTO', 'documento', (string) $docId, 'leído v' . (int) $v['v'], null, null, ['version' => (int) $v['v']]);
        terminarDoc('Quedó registrado que lo leíste.', null, '#d' . $docId);
    }
    terminarDoc(null, 'Acción no reconocida.');
}

/* -------------------------------------------------------------------------
   LA LISTA: cada documento con su última versión aprobada.
   ------------------------------------------------------------------------- */
$fTipo = strtoupper((string) ($_GET['tipo'] ?? ''));
if (!isset($TIPOS[$fTipo])) { $fTipo = ''; }
$fFam  = trim((string) ($_GET['familia'] ?? ''));
$fZona = strtoupper((string) ($_GET['zona'] ?? ''));
if (!in_array($fZona, $ZONAS, true)) { $fZona = ''; }
$fq    = trim((string) ($_GET['q'] ?? ''));

$docs = []; $porAprobar = []; $misPropuestas = []; $familias = []; $acuses = []; $miAcuse = []; $vigentesDe = []; $versionesDe = [];
if ($hayTablas) {
    $donde = ['d.activo = 1']; $par = [];
    if ($fTipo !== '') { $donde[] = 'd.tipo = ?'; $par[] = $fTipo; }
    if ($fFam !== '')  { $donde[] = 'd.familia = ?'; $par[] = $fFam; }
    if ($fZona !== '') { $donde[] = '(d.zona = ? OR d.zona IS NULL)'; $par[] = $fZona; }
    if ($fq !== '')    { $donde[] = '(d.titulo LIKE ? OR d.descripcion LIKE ?)'; $par[] = '%' . $fq . '%'; $par[] = '%' . $fq . '%'; }
    $docs = Db::todos(
        'SELECT d.*, v.version_id, v.version, v.nombre_archivo, v.bytes, v.mime, v.subida_en, v.revisada_en,
                u.nombre AS subio, r.nombre AS reviso,
                (SELECT COUNT(*) FROM documento_versiones x WHERE x.doc_id = d.doc_id) AS n_versiones,
                (SELECT COUNT(*) FROM documento_versiones x WHERE x.doc_id = d.doc_id AND x.estado = "EN_REVISION") AS n_revision
           FROM documentos d
      LEFT JOIN documento_versiones v ON v.version_id = (SELECT w.version_id FROM documento_versiones w
                                                          WHERE w.doc_id = d.doc_id AND w.estado = "APROBADA"
                                                          ORDER BY w.version DESC LIMIT 1)
      LEFT JOIN usuarios u ON u.usuario_id = v.subida_por
      LEFT JOIN usuarios r ON r.usuario_id = v.revisada_por
          WHERE ' . implode(' AND ', $donde) . '
          ORDER BY FIELD(d.tipo, "COMUNICADO", "GUIA", "MANUAL"), COALESCE(v.subida_en, d.creado_en) DESC', $par
    );
    $ids = array_map(fn($d) => (int) $d['doc_id'], $docs);
    if ($ids) {
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        foreach (Db::todos("SELECT v.*, u.nombre AS subio, r.nombre AS reviso FROM documento_versiones v
                              JOIN usuarios u ON u.usuario_id = v.subida_por
                         LEFT JOIN usuarios r ON r.usuario_id = v.revisada_por
                             WHERE v.doc_id IN ($marcas) ORDER BY v.version DESC", $ids) as $v) {
            $versionesDe[(int) $v['doc_id']][] = $v;
        }
        foreach (Db::todos("SELECT doc_id, COUNT(*) n FROM documento_acuses WHERE doc_id IN ($marcas) GROUP BY doc_id", $ids) as $a) {
            $acuses[(int) $a['doc_id']] = (int) $a['n'];
        }
        foreach (Db::todos("SELECT doc_id, version FROM documento_acuses WHERE usuario_id = ? AND doc_id IN ($marcas)",
                           array_merge([(int) $u['usuario_id']], $ids)) as $a) {
            $miAcuse[(int) $a['doc_id']] = (int) $a['version'];
        }
    }
    // Y = a quiénes va dirigido un comunicado: el personal de campo activo de
    // su zona (o de todas). Es contra lo que se mide «visto por X de Y».
    $destinatarios = [];
    foreach (Db::todos("SELECT zona, COUNT(*) n FROM usuarios WHERE activo = 1 AND rol IN ('TECNICO','JEFE_ZONA') GROUP BY zona") as $z) {
        $destinatarios[(string) ($z['zona'] ?? '')] = (int) $z['n'];
    }
    $totalCampo = array_sum($destinatarios);
    if ($puedeAprobar) {
        $porAprobar = Db::todos(
            'SELECT v.*, d.titulo, d.tipo, u.nombre AS subio FROM documento_versiones v
               JOIN documentos d ON d.doc_id = v.doc_id JOIN usuarios u ON u.usuario_id = v.subida_por
              WHERE v.estado = "EN_REVISION" ORDER BY v.subida_en ASC'
        );
    }
    $misPropuestas = Db::todos(
        'SELECT v.*, d.titulo, d.tipo, r.nombre AS reviso FROM documento_versiones v
           JOIN documentos d ON d.doc_id = v.doc_id LEFT JOIN usuarios r ON r.usuario_id = v.revisada_por
          WHERE v.subida_por = ? AND v.estado IN ("EN_REVISION","RECHAZADA") ORDER BY v.subida_en DESC LIMIT 20',
        [(int) $u['usuario_id']]
    );
    try { $familias = array_column(Db::todos('SELECT familia FROM familias_equipo WHERE activo = 1 ORDER BY orden'), 'familia'); }
    catch (Throwable $ex) { $familias = []; }
}
// Quien no revisa ve solo lo publicado (con versión aprobada).
$visibles = array_values(array_filter($docs, fn($d) => $puedeAprobar || $d['version_id'] !== null));
$nPorAprobar = count($porAprobar);

Auth::bitacora('CONSULTAR', 'documento', $fTipo !== '' ? $fTipo : 'todos', 'visibles=' . count($visibles),
               null, null, ['tipo' => $fTipo, 'familia' => $fFam, 'zona' => $fZona, 'q' => $fq]);

$errFlash = Ui::errorFlash();
$csrf = Auth::csrfToken();
$kb = static fn($b): string => $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, (int) round($b / 1024)) . ' KB';

Ui::cabecera($u, 'documentos.php',
    ['documentos' => $nPorAprobar > 0 ? ['n' => $nPorAprobar, 'tono' => 'ojo'] : null],
    ['titulo' => 'Aprendizaje']);
?>
<div class="wrap ancho">
  <div class="titulo entra">
    <h1>Aprendizaje</h1>
    <p class="sub">
      Manuales de equipos, guías de cómo se hace el trabajo y comunicados de la
      empresa, para todas las zonas. Lo que ves está aprobado por administración;
      <?php if ($puedeProponer): ?>
        si tienes un manual o una guía que sirva a los demás, propónlo y queda <?= $e(Vocabulario::t('DOCUMENTO_POR_APROBAR')) ?>.
      <?php elseif ($puedeSubir): ?>
        lo que subas queda <?= $e(Vocabulario::t('DOCUMENTO_POR_APROBAR')) ?> hasta que administración lo apruebe.
      <?php else: ?>
        cada versión queda con quién la subió, quién la aprobó y cuándo.
      <?php endif; ?>
    </p>
  </div>

  <?php if ($errFlash): ?><?= Ui::aviso('err', $e($errFlash), true) ?><?php endif; ?>

  <?php if (!$hayTablas): ?>
    <?= Ui::aviso('info', '<b>El aprendizaje todavía no está instalado en la base.</b>'
        . '<p>La migración <span class="mono">009_pulido_piloto.sql</span> crea las tablas '
        . '<span class="mono">documentos</span>, <span class="mono">documento_versiones</span> y '
        . '<span class="mono">documento_acuses</span>.</p>') ?>
  <?php else: ?>

    <?php if ($puedeSubir || $puedeProponer): ?>
      <p style="margin:0 0 12px">
        <button class="btn primary sm" type="button" onclick="subir(0, '', '')"><?= $puedeSubir ? 'Subir un documento' : 'Proponer un documento' ?></button>
      </p>
    <?php endif; ?>

    <?php if ($puedeAprobar && $porAprobar): ?>
      <section class="card por-aprobar" id="por-aprobar">
        <h2>Por aprobar (<?= $nPorAprobar ?>)</h2>
        <p class="sub" style="margin:0 0 10px">Lo que subieron los jefes de zona y lo que proponen los técnicos. Ábrelo, y apruébalo o recházalo con su motivo.</p>
        <div class="tabla-wrap">
          <table class="tarjetas">
            <thead><tr><th>Documento</th><th>Versión</th><th>Quién</th><th>Cuándo</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($porAprobar as $v): ?>
              <tr>
                <td data-th="Documento"><span class="chip"><?= $e($TIPOS[$v['tipo']] ?? $v['tipo']) ?></span> <b><?= $e($v['titulo']) ?></b>
                    <span class="desc"><?= $e($v['nombre_archivo']) ?> · <?= $e($kb((int) $v['bytes'])) ?></span></td>
                <td data-th="Versión" class="mono">v<?= (int) $v['version'] ?></td>
                <td data-th="Quién"><?= $e($v['subio']) ?></td>
                <td data-th="Cuándo" class="mono"><?= $e(substr((string) $v['subida_en'], 0, 16)) ?></td>
                <td data-th="Acciones">
                  <div class="acc">
                    <a class="btn sm" href="documento.php?v=<?= (int) $v['version_id'] ?>" target="_blank" rel="noopener">Ver</a>
                    <form method="post" style="display:inline">
                      <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                      <input type="hidden" name="accion" value="aprobar">
                      <input type="hidden" name="version_id" value="<?= (int) $v['version_id'] ?>">
                      <button class="btn primary sm" type="submit">Aprobar</button>
                    </form>
                    <button class="btn sm ghost" type="button" onclick="revisar(<?= (int) $v['version_id'] ?>, 'rechazar', <?= $e(json_encode($v['titulo'])) ?>)">Rechazar</button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($misPropuestas && !$puedeAprobar): ?>
      <section class="card" id="mis-propuestas">
        <h2>Lo que subiste y todavía no está publicado</h2>
        <ul class="propuestas">
          <?php foreach ($misPropuestas as $v): ?>
            <li>
              <b><?= $e($v['titulo']) ?></b> v<?= (int) $v['version'] ?> ·
              <span class="est est-<?= $v['estado'] === 'EN_REVISION' ? 'en_revision' : 'no_compete' ?>"><?= $v['estado'] === 'EN_REVISION' ? $e(Vocabulario::t('DOCUMENTO_POR_APROBAR')) : 'rechazada' ?></span>
              <?php if ($v['estado'] === 'RECHAZADA' && !empty($v['nota_revision'])): ?>
                <span class="desc"><?= $e($v['reviso']) ?>: <?= $e($v['nota_revision']) ?></span>
              <?php endif; ?>
              · <a href="documento.php?v=<?= (int) $v['version_id'] ?>" target="_blank" rel="noopener">ver</a>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>

    <div class="filtros-rapidos">
      <a class="fr <?= $fTipo === '' ? 'on' : '' ?>" href="documentos.php">Todo</a>
      <?php foreach (['MANUAL' => 'Manuales', 'GUIA' => 'Guías', 'COMUNICADO' => 'Comunicados'] as $k => $et): ?>
        <a class="fr <?= $fTipo === $k ? 'on' : '' ?>" href="?tipo=<?= $k ?>"><?= $e($et) ?></a>
      <?php endforeach; ?>
      <form method="get" data-auto style="display:flex;gap:6px;margin-left:auto;flex-wrap:wrap;align-items:center">
        <input type="hidden" name="tipo" value="<?= $e($fTipo) ?>">
        <select name="familia" style="height:36px;font-size:13.5px;width:auto;min-width:150px">
          <option value="">Todo equipo</option>
          <?php foreach ($familias as $f): ?>
            <option value="<?= $e($f) ?>" <?= $fFam === $f ? 'selected' : '' ?>><?= $e($f) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="zona" style="height:36px;font-size:13.5px;width:auto;min-width:120px">
          <option value="">Toda zona</option>
          <?php foreach ($ZONAS as $z): ?>
            <option value="<?= $z ?>" <?= $fZona === $z ? 'selected' : '' ?>><?= $e(Ui::zonaCorta($z)) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="search" name="q" value="<?= $e($fq) ?>" placeholder="título…" style="height:36px;width:auto;min-width:150px;font-size:13.5px">
        <button class="btn sm" type="submit">Buscar</button>
      </form>
    </div>

    <?php if (!$visibles): ?>
      <div class="vacio card">
        <span class="icono">📘</span>
        <b style="display:block;font-size:15px;color:var(--ink);margin-bottom:6px">Todavía no hay documentos publicados<?= $fTipo !== '' || $fFam !== '' || $fZona !== '' || $fq !== '' ? ' con esos filtros' : '' ?></b>
        <span style="display:block;max-width:52ch;margin:0 auto">
          <?= $puedeSubir ? 'Sube el primer manual o guía con el botón de arriba.' : 'Cuando administración apruebe el primero, aparece aquí.' ?>
        </span>
      </div>
    <?php else: ?>
      <div class="docs">
        <?php foreach ($visibles as $i => $d): ?>
          <?php
          $id = (int) $d['doc_id'];
          $publicado = $d['version_id'] !== null;
          $esCom = $d['tipo'] === 'COMUNICADO';
          $y = $esCom ? ($d['zona'] ? ($destinatarios[$d['zona']] ?? 0) : $totalCampo) : 0;
          $x = $acuses[$id] ?? 0;
          $leido = isset($miAcuse[$id]) && $publicado && $miAcuse[$id] >= (int) $d['version'];
          $ext = strtolower((string) pathinfo((string) ($d['nombre_archivo'] ?? ''), PATHINFO_EXTENSION));
          ?>
          <article class="doc tipo-<?= strtolower($d['tipo']) ?> <?= $publicado ? '' : 'sin-vigente' ?>" id="d<?= $id ?>" style="--i:<?= $i ?>">
            <div class="cab">
              <div style="min-width:0;flex:1">
                <div class="que">
                  <span class="chip"><?= $e($TIPOS[$d['tipo']]) ?></span>
                  <?= $e($d['titulo']) ?>
                  <?php if (!$publicado): ?><span class="chip">sin versión publicada</span><?php endif; ?>
                </div>
                <div class="meta">
                  <?php if ($d['familia']): ?><span class="chip"><?= $e($d['familia']) ?></span><?php endif; ?>
                  <?= $d['zona'] ? Ui::zona($d['zona']) : '<span class="chip">todas las zonas</span>' ?>
                  <?php if ($publicado): ?>
                    · v<?= (int) $d['version'] ?> · <?= $e($ext ?: $d['mime']) ?> · <?= $e($kb((int) $d['bytes'])) ?>
                    · subió <?= $e($d['subio']) ?> el <?= $e(substr((string) $d['subida_en'], 0, 10)) ?>
                    <?php if ($d['reviso']): ?> · aprobó <?= $e($d['reviso']) ?><?php endif; ?>
                  <?php endif; ?>
                  <?php if ((int) $d['n_revision'] > 0 && $puedeAprobar): ?>
                    · <a href="#por-aprobar"><?= (int) $d['n_revision'] ?> por aprobar</a>
                  <?php endif; ?>
                </div>
              </div>
              <?php if ($esCom && $publicado): ?>
                <div class="acuse">
                  <b><?= $x ?></b> de <b><?= $y ?></b> lo leyeron
                  <?php if ($leido): ?><span class="chip cerrada">leído</span><?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
            <?php if (!empty($d['descripcion'])): ?>
              <p class="desc-doc"><?= $e($d['descripcion']) ?></p>
            <?php endif; ?>
            <div class="acciones">
              <?php if ($publicado): ?>
                <a class="btn primary sm" href="documento.php?v=<?= (int) $d['version_id'] ?>" target="_blank" rel="noopener">Ver</a>
                <a class="btn sm" href="documento.php?v=<?= (int) $d['version_id'] ?>&amp;dl=1">Descargar</a>
                <?php if ($esCom && !$leido): ?>
                  <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="accion" value="leido">
                    <input type="hidden" name="doc_id" value="<?= $id ?>">
                    <button class="btn sm" type="submit">Marcar como leído</button>
                  </form>
                <?php endif; ?>
              <?php endif; ?>
              <?php if ($puedeSubir || $puedeProponer): ?>
                <button class="btn sm ghost" type="button" onclick="subir(<?= $id ?>, <?= $e(json_encode($d['titulo'])) ?>, '<?= $e($d['tipo']) ?>')">Nueva versión</button>
              <?php endif; ?>
              <?php if ($puedeAprobar && $publicado): ?>
                <button class="btn sm ghost" type="button" onclick="revisar(<?= (int) $d['version_id'] ?>, 'retirar', <?= $e(json_encode($d['titulo'])) ?>)">Retirar</button>
              <?php endif; ?>
            </div>
            <?php if ((int) $d['n_versiones'] > 1 || $puedeAprobar): ?>
              <details class="versiones">
                <summary><?= (int) $d['n_versiones'] ?> versión<?= (int) $d['n_versiones'] === 1 ? '' : 'es' ?></summary>
                <ul>
                  <?php foreach ($versionesDe[$id] ?? [] as $v): ?>
                    <li>
                      <span class="mono">v<?= (int) $v['version'] ?></span>
                      <span class="est est-<?= ['APROBADA' => 'resuelto', 'EN_REVISION' => 'en_revision', 'RECHAZADA' => 'no_compete', 'RETIRADA' => 'cerrado_sin_atencion'][$v['estado']] ?? 'nuevo' ?>"><?= $e($ETIQ_VERSION[$v['estado']] ?? strtolower(str_replace('_', ' ', (string) $v['estado']))) ?></span>
                      <?= $e($v['nombre_archivo']) ?> · <?= $e($kb((int) $v['bytes'])) ?> · subió <?= $e($v['subio']) ?> el <?= $e(substr((string) $v['subida_en'], 0, 10)) ?>
                      <?php if ($v['reviso']): ?> · <?= $v['estado'] === 'APROBADA' ? 'aprobó' : 'revisó' ?> <?= $e($v['reviso']) ?><?php endif; ?>
                      <?php if (!empty($v['nota_revision'])): ?><span class="desc"><?= $e($v['nota_revision']) ?></span><?php endif; ?>
                      <?php if ($puedeAprobar || $v['estado'] === 'APROBADA' || (int) $v['subida_por'] === (int) $u['usuario_id']): ?>
                        · <a href="documento.php?v=<?= (int) $v['version_id'] ?>" target="_blank" rel="noopener">ver</a>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </details>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($puedeSubir || $puedeProponer): ?>
<dialog id="dlgSubir">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="accion" value="subir">
    <input type="hidden" name="doc_id" id="s-doc">
    <h2 id="s-titulo-dlg"><?= $puedeSubir ? 'Subir un documento' : 'Proponer un documento' ?></h2>
    <p class="sub" style="margin:0 0 12px" id="s-ayuda">
      PDF, imagen, Word, Excel o video de hasta 25 MB. Queda con tu nombre y la
      fecha<?= $puedeAprobar ? '' : ', ' . $e(Vocabulario::t('DOCUMENTO_POR_APROBAR')) . ' hasta que administración lo apruebe' ?>.
    </p>
    <div class="grid g2">
      <div>
        <label for="s-tipo">Tipo</label>
        <select id="s-tipo" name="tipo" required>
          <?php foreach ($TIPOS as $k => $et): ?><option value="<?= $k ?>"><?= $e($et) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="s-titulo">Título</label>
        <input type="text" id="s-titulo" name="titulo" maxlength="160" required placeholder="p. ej. Freidora Pitco SG14: manual de servicio">
      </div>
      <div>
        <label for="s-fam">Equipo (si aplica)</label>
        <select id="s-fam" name="familia">
          <option value="">No aplica</option>
          <?php foreach ($familias as $f): ?><option value="<?= $e($f) ?>"><?= $e($f) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="s-zona">Zona</label>
        <select id="s-zona" name="zona">
          <option value="">Todas</option>
          <?php foreach ($ZONAS as $z): ?><option value="<?= $z ?>"><?= $e(Ui::zonaCorta($z)) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div style="margin:12px 0">
      <label for="s-desc">De qué trata</label>
      <textarea id="s-desc" name="descripcion" rows="2" maxlength="600" placeholder="Una o dos líneas: para qué sirve y a quién."></textarea>
    </div>
    <div style="margin-bottom:12px">
      <label for="s-archivo">Archivo</label>
      <input type="file" id="s-archivo" name="archivo" required accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx,.mp4">
    </div>
    <?php if ($puedeAprobar): ?>
      <label style="display:flex;align-items:center;gap:8px;margin:0 0 12px;font-size:13.5px">
        <input type="checkbox" name="aprobar_ya" value="1" checked style="width:auto;height:auto;margin:0">
        Publicarlo ya (queda aprobado con tu nombre)
      </label>
    <?php endif; ?>
    <div class="row" style="margin-top:14px;gap:8px">
      <button class="btn primary" type="submit"><?= $puedeSubir ? 'Subir' : 'Proponer' ?></button>
      <button class="btn" type="button" onclick="this.closest('dialog').close()">Cancelar</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<?php if ($puedeAprobar): ?>
<dialog id="dlgRevisar">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="accion" id="r-accion">
    <input type="hidden" name="version_id" id="r-vid">
    <h2 id="r-titulo"></h2>
    <p class="sub" style="margin:0 0 12px" id="r-ayuda"></p>
    <textarea name="nota" id="r-nota" rows="3" maxlength="400"></textarea>
    <div class="row" style="margin-top:14px;gap:8px">
      <button class="btn primary" type="submit" id="r-ok"></button>
      <button class="btn" type="button" onclick="this.closest('dialog').close()">Cancelar</button>
    </div>
  </form>
</dialog>
<?php endif; ?>

<script>
function subir(docId, titulo, tipo) {
  var d = document.getElementById('dlgSubir');
  if (!d) { return; }
  document.getElementById('s-doc').value = docId || '';
  var t = document.getElementById('s-titulo');
  t.value = titulo || ''; t.readOnly = !!docId;
  if (tipo) { document.getElementById('s-tipo').value = tipo; }
  document.getElementById('s-titulo-dlg').textContent = docId ? 'Nueva versión de «' + titulo + '»' : (<?= json_encode($puedeSubir ? 'Subir un documento' : 'Proponer un documento') ?>);
  d.showModal();
}
function revisar(vid, accion, titulo) {
  var d = document.getElementById('dlgRevisar');
  if (!d) { return; }
  document.getElementById('r-vid').value = vid;
  document.getElementById('r-accion').value = accion;
  var esRechazo = accion === 'rechazar';
  document.getElementById('r-titulo').textContent = (esRechazo ? 'Rechazar' : 'Retirar') + ' «' + titulo + '»';
  document.getElementById('r-ayuda').textContent = esRechazo
    ? 'El motivo es obligatorio: quien lo subió lo lee en su lista.'
    : 'La versión deja de verse pero se conserva, con su historial. Puedes decir por qué.';
  document.getElementById('r-ok').textContent = esRechazo ? 'Rechazar' : 'Retirar';
  var n = document.getElementById('r-nota');
  n.value = ''; n.required = esRechazo;
  n.placeholder = esRechazo ? 'p. ej. es el manual del modelo anterior; sube el del SG14' : 'opcional';
  d.showModal();
}
</script>
<?php Ui::pie(); ?>
