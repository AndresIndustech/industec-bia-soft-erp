<?php
declare(strict_types=1);
/**
 * correos.php — A quién va el correo de cada OT INDUSTEC: el buzón del jefe de
 * zona, las copias internas y las del cliente (por zona, generales o por
 * local), y el correo que un técnico propuso para un local, para aprobar o
 * rechazar (T2.28.2, obs. 2 y 8 de la revisión con INDUSTEC; D-C, D-G, S-1).
 *
 * POR QUÉ EXISTE. Hasta la migración 013 esto estaba fijo en tres sitios: el
 * formulario («Correo del jefe de operaciones», de solo lectura), el maestro
 * de locales (`correo_jefe_op`) y config.php (`correo_por_zona`,
 * `correo_fijos`). Cambiar una copia exigía editar el servidor
 * (T2_28_OBSERVACIONES_INDUSTEC.md §2). Ahora `Destinatarios::resolver()` lee
 * la tabla `correo_destinatarios` y esta es la única pantalla que la toca:
 * nada se borra, solo se activa o desactiva, y cada cambio queda en
 * `correo_destinatarios_cambios` y en la bitácora.
 *
 * Permiso: `correos.configurar` (SUPERADMIN y ADMIN; respaldo si la 013
 * todavía no está aplicada). A quién le llega una orden de KFC no lo decide
 * un jefe de zona ni un técnico (D-C): los dos reciben 403. POST con CSRF
 * (D13) y patrón POST-redirect-GET, como equipos.php.
 *
 * Se busca desde el panel «Automatización» (decisión de Andrés del
 * 2026-09-23): automatizacion.php enlaza aquí, con el número de propuestas
 * pendientes.
 */
require_once __DIR__ . '/nucleo/Ui.php';
require_once __DIR__ . '/nucleo/Catalogo.php';
require_once __DIR__ . '/nucleo/Destinatarios.php';
require_once __DIR__ . '/nucleo/Vocabulario.php';   // CNLJ se lee «CUENCA-LOJA» en los botones de zona

$u = Auth::exigir();
if (!Ui::puedeModulo('correos.configurar', ['SUPERADMIN', 'ADMIN'], $u)) {
    Auth::bitacora('DENEGADO', 'correo_destinatario', '', 'sin permiso correos.configurar', null, null, [], false);
    http_response_code(403);
    exit('No tienes acceso a la configuración de correos.');
}
$e = fn(?string $s): string => Ui::e($s);

const ZONAS = ['UIO', 'LARB', 'CNLJ', 'OTRA'];
const TABS = ['zona', 'generales', 'locales', 'reportes', 'propuestos', 'vista'];

function terminarCorreo(?string $ok, ?string $error, string $query = ''): void
{
    $_SESSION['flash'] = array_filter(['ok' => $ok, 'error' => $error]);
    header('Location: correos.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

/** Los campos comunes de alta/edición, ya limpios. */
function leerCampos(array $in): array
{
    $ambito = strtoupper(trim((string) ($in['ambito'] ?? '')));
    return [
        'uso'          => strtoupper(trim((string) ($in['uso'] ?? 'ORDEN'))),
        'destino'      => strtoupper(trim((string) ($in['destino'] ?? ''))),
        'ambito'       => $ambito,
        'zona'         => $ambito === 'ZONA' ? strtoupper(trim((string) ($in['zona'] ?? ''))) : null,
        'local_codigo' => $ambito === 'LOCAL' ? strtoupper(trim((string) ($in['local_codigo'] ?? ''))) : null,
        'cadena'       => trim((string) ($in['cadena'] ?? '')) !== '' ? mb_substr(trim((string) $in['cadena']), 0, 40) : null,
        'rol'          => strtoupper(trim((string) ($in['rol'] ?? 'OTRO'))),
        'tipo'         => strtoupper(trim((string) ($in['tipo'] ?? 'COPIA'))),
        'correo'       => strtolower(trim((string) ($in['correo'] ?? ''))),
        'nombre'       => trim((string) ($in['nombre'] ?? '')) !== '' ? mb_substr(trim((string) $in['nombre']), 0, 120) : null,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::exigirCsrf();
    $accion = (string) ($_POST['accion'] ?? '');
    $volver = (string) ($_POST['volver'] ?? '');

    if ($accion === 'alta') {
        $c = leerCampos($_POST);
        if (!in_array($c['uso'], ['ORDEN', 'REPORTE'], true) || !in_array($c['destino'], ['INTERNO', 'CLIENTE'], true)
            || !in_array($c['ambito'], ['GENERAL', 'ZONA', 'LOCAL'], true)
            || !in_array($c['rol'], ['JEFE_ZONA', 'JEFE_OPERACIONES', 'OTRO'], true)
            || !in_array($c['tipo'], ['PARA', 'COPIA'], true)) {
            terminarCorreo(null, 'Datos incompletos o inválidos.', $volver);
        }
        if ($c['correo'] === '' || !filter_var($c['correo'], FILTER_VALIDATE_EMAIL)) {
            terminarCorreo(null, 'El correo no es válido.', $volver);
        }
        if ($c['ambito'] === 'ZONA' && !in_array($c['zona'], ZONAS, true)) {
            terminarCorreo(null, 'Elige una zona válida.', $volver);
        }
        if ($c['ambito'] === 'LOCAL' && ($c['local_codigo'] ?? '') === '') {
            terminarCorreo(null, 'Elige un local.', $volver);
        }
        if ($c['rol'] === 'JEFE_ZONA' && $c['ambito'] !== 'ZONA') {
            terminarCorreo(null, 'El jefe de zona va con ámbito Zona: es el buzón institucional de esa zona.', $volver);
        }
        if ($c['rol'] === 'JEFE_OPERACIONES' && $c['ambito'] !== 'LOCAL') {
            terminarCorreo(null, 'El jefe de operaciones va con ámbito Local: es el contacto de ese local en Grupo KFC.', $volver);
        }
        try {
            Db::ejecutar(
                "INSERT INTO correo_destinatarios
                    (uso, destino, ambito, zona, local_codigo, cadena, rol, tipo, correo, nombre, activo, origen, creado_por)
                 VALUES (?,?,?,?,?,?,?,?,?,?,1,'MANUAL',?)",
                [$c['uso'], $c['destino'], $c['ambito'], $c['zona'], $c['local_codigo'], $c['cadena'],
                 $c['rol'], $c['tipo'], $c['correo'], $c['nombre'], (int) $u['usuario_id']]
            );
        } catch (Throwable $ex) {
            $duplicado = str_contains($ex->getMessage(), 'uq_destinatario') || str_contains($ex->getMessage(), 'uq_rol');
            terminarCorreo(null, $duplicado
                ? 'Ya existe una fila igual, o ya hay un jefe de zona / jefe de operaciones ahí (edítalo en vez de duplicarlo).'
                : 'No se pudo guardar.', $volver);
        }
        $id = (int) Db::conn()->lastInsertId();
        Auth::bitacora('CORREO_DEST_ALTA', 'correo_destinatario', (string) $id,
                       $c['correo'] . ' · ' . $c['rol'] . ' · ' . $c['ambito'], null, 'ACTIVO', $c);
        terminarCorreo('Correo agregado.', null, $volver);
    }

    if ($accion === 'editar') {
        $id     = (int) ($_POST['id'] ?? 0);
        $correo = strtolower(trim((string) ($_POST['correo'] ?? '')));
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $tipo   = strtoupper(trim((string) ($_POST['tipo'] ?? '')));
        if ($id <= 0 || $correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL) || !in_array($tipo, ['PARA', 'COPIA'], true)) {
            terminarCorreo(null, 'Datos inválidos para editar.', $volver);
        }
        $antes = Db::uno('SELECT * FROM correo_destinatarios WHERE destinatario_id = ?', [$id]);
        if (!$antes) { terminarCorreo(null, 'Ese destinatario no existe.', $volver); }
        try {
            Db::ejecutar(
                'UPDATE correo_destinatarios SET correo = ?, nombre = ?, tipo = ?, actualizado_por = ?, actualizado_en = NOW()
                  WHERE destinatario_id = ?',
                [$correo, $nombre !== '' ? mb_substr($nombre, 0, 120) : null, $tipo, (int) $u['usuario_id'], $id]
            );
        } catch (Throwable $ex) {
            terminarCorreo(null, 'No se pudo editar: ya existe una fila igual con ese correo.', $volver);
        }
        Db::ejecutar(
            "INSERT INTO correo_destinatarios_cambios (destinatario_id, accion, antes, despues, por) VALUES (?, 'EDICION', ?, ?, ?)",
            [$id, json_encode(['correo' => $antes['correo'], 'nombre' => $antes['nombre'], 'tipo' => $antes['tipo']], JSON_UNESCAPED_UNICODE),
             json_encode(['correo' => $correo, 'nombre' => $nombre, 'tipo' => $tipo], JSON_UNESCAPED_UNICODE), (int) $u['usuario_id']]
        );
        Auth::bitacora('CORREO_DEST_EDITA', 'correo_destinatario', (string) $id,
                       $antes['correo'] . ' → ' . $correo, $antes['correo'], $correo);
        terminarCorreo('Correo actualizado.', null, $volver);
    }

    if ($accion === 'activar' || $accion === 'desactivar') {
        $id     = (int) ($_POST['id'] ?? 0);
        $activo = $accion === 'activar' ? 1 : 0;
        $fila   = Db::uno('SELECT * FROM correo_destinatarios WHERE destinatario_id = ?', [$id]);
        if (!$fila) { terminarCorreo(null, 'Ese destinatario no existe.', $volver); }
        if ($accion === 'desactivar' && $fila['rol'] === 'JEFE_ZONA') {
            // Nunca se desactiva el buzón institucional: toda orden de esa
            // zona tiene que llevar copia a INDUSTEC. Se puede editar el
            // correo si cambia quién lo administra, no apagarlo. Un POST
            // fabricado contra esto es un 400, no un 200 silencioso.
            Auth::bitacora('DENEGADO', 'correo_destinatario', (string) $id,
                           'intento de desactivar el buzón institucional del jefe de zona', null, null, [], false);
            http_response_code(400);
            exit('El buzón del jefe de zona no se puede desactivar, solo editar su correo.');
        }
        Db::ejecutar('UPDATE correo_destinatarios SET activo = ?, actualizado_por = ?, actualizado_en = NOW() WHERE destinatario_id = ?',
                     [$activo, (int) $u['usuario_id'], $id]);
        Db::ejecutar(
            "INSERT INTO correo_destinatarios_cambios (destinatario_id, accion, antes, despues, por) VALUES (?, ?, ?, ?, ?)",
            [$id, $activo ? 'ACTIVAR' : 'DESACTIVAR', json_encode(['activo' => (int) $fila['activo']]),
             json_encode(['activo' => $activo]), (int) $u['usuario_id']]
        );
        Auth::bitacora($activo ? 'CORREO_DEST_ACTIVA' : 'CORREO_DEST_DESACTIVA', 'correo_destinatario', (string) $id,
                       (string) $fila['correo'], (string) $fila['activo'], (string) $activo);
        terminarCorreo($activo ? 'Reactivado.' : 'Desactivado.', null, $volver);
    }

    if ($accion === 'aprobar' || $accion === 'rechazar') {
        $id   = (int) ($_POST['id'] ?? 0);
        $nota = trim((string) ($_POST['nota'] ?? ''));
        $prop = Db::uno('SELECT * FROM locales_correo_propuesto WHERE propuesta_id = ?', [$id]);
        if (!$prop) { terminarCorreo(null, 'Esa propuesta no existe.', $volver); }
        if ($prop['estado'] !== 'PROPUESTO') {
            terminarCorreo(null, 'Esa propuesta ya fue decidida (' . $prop['estado'] . ').', $volver);
        }
        if ($accion === 'rechazar' && mb_strlen($nota) < 5) {
            terminarCorreo(null, 'Escribe el motivo del rechazo (al menos cinco caracteres).', $volver);
        }
        $n = Db::ejecutar(
            "UPDATE locales_correo_propuesto SET estado = ?, revisado_por = ?, revisado_en = NOW(), nota = ?
              WHERE propuesta_id = ? AND estado = 'PROPUESTO'",
            [$accion === 'aprobar' ? 'APROBADO' : 'RECHAZADO', (int) $u['usuario_id'],
             $nota !== '' ? mb_substr($nota, 0, 300) : null, $id]
        );
        if ($n === 0) { terminarCorreo(null, 'Alguien decidió sobre esa propuesta hace un instante. Recarga la lista.', $volver); }
        Auth::bitacora($accion === 'aprobar' ? 'CORREO_LOCAL_APROBAR' : 'CORREO_LOCAL_RECHAZAR',
                       'locales_correo_propuesto', (string) $id,
                       $prop['local_codigo'] . ' → ' . $prop['correo'], 'PROPUESTO',
                       $accion === 'aprobar' ? 'APROBADO' : 'RECHAZADO', ['nota' => $nota]);
        terminarCorreo($accion === 'aprobar'
            ? 'Correo del local aprobado: desde ahora la emisión lo usa como correo de ese local.'
            : 'Propuesta rechazada.', null, $volver);
    }
    terminarCorreo(null, 'Acción desconocida.', $volver);
}

// ------------------------------------------------------------------ la lista
$okFlash  = $_SESSION['flash']['ok'] ?? null;
unset($_SESSION['flash']['ok']);
$errFlash = Ui::errorFlash();

$tab     = in_array((string) ($_GET['tab'] ?? ''), TABS, true) ? (string) $_GET['tab'] : 'zona';
$zonaSel = in_array((string) ($_GET['zona'] ?? ''), ZONAS, true) ? (string) $_GET['zona'] : 'UIO';

$cat = Catalogo::cargar();
$locales = $cat['locales'] ?? [];
$nombreLocal = [];
foreach ($locales as $l) {
    $cod = (string) ($l['codigo'] ?? '');
    if ($cod !== '') { $nombreLocal[$cod] = (string) ($l['nombre'] ?? $cod); }
}
$localSel = strtoupper(trim((string) ($_GET['local'] ?? '')));
if (!isset($nombreLocal[$localSel])) { $localSel = ''; }

$todos = Db::todos(
    'SELECT d.*, cu.nombre AS creado_nombre
       FROM correo_destinatarios d
       LEFT JOIN usuarios cu ON cu.usuario_id = d.creado_por
      ORDER BY d.rol = "JEFE_ZONA" DESC, d.rol = "JEFE_OPERACIONES" DESC, d.correo'
);
$pendientes = Db::todos(
    "SELECT p.*, u.nombre AS propuesto_nombre, u.usuario AS propuesto_usuario
       FROM locales_correo_propuesto p
       LEFT JOIN usuarios u ON u.usuario_id = p.propuesto_por
      WHERE p.estado = 'PROPUESTO' ORDER BY p.propuesto_en DESC LIMIT 200"
);
$contPendientes = (int) (Db::uno("SELECT COUNT(*) n FROM locales_correo_propuesto WHERE estado = 'PROPUESTO'")['n'] ?? 0);

/** @param array<int,array<string,mixed>> $filas */
function filtrar(array $filas, string $uso, string $destino, string $ambito, ?string $zona = null, ?string $local = null): array
{
    return array_values(array_filter($filas, static function ($f) use ($uso, $destino, $ambito, $zona, $local) {
        if ((string) $f['uso'] !== $uso || (string) $f['destino'] !== $destino || (string) $f['ambito'] !== $ambito) { return false; }
        if ($zona !== null && (string) $f['zona'] !== $zona) { return false; }
        if ($local !== null && (string) $f['local_codigo'] !== $local) { return false; }
        return true;
    }));
}

function filaCorreo(array $f, callable $e): string
{
    ob_start();
    ?>
    <tr class="<?= (int) $f['activo'] === 1 ? '' : 'apagado' ?>">
      <td data-th="Correo" class="mono"><?= $e($f['correo']) ?></td>
      <td data-th="Nombre"><?= $e($f['nombre'] ?: '—') ?></td>
      <td data-th="Tipo"><?= $f['tipo'] === 'PARA' ? 'Destinatario' : 'Copia' ?></td>
      <td data-th="Origen" class="sub"><?= $e(strtolower((string) $f['origen'])) ?><?= $f['creado_nombre'] ? ' · ' . $e($f['creado_nombre']) : '' ?></td>
      <td data-th="Estado"><?= (int) $f['activo'] === 1 ? '<span class="estado-red con">activo</span>' : '<span class="estado-red sin">desactivado</span>' ?></td>
      <td data-th="Acciones">
        <form method="post" action="correos.php" class="fila-editar" style="display:inline-flex;gap:4px;flex-wrap:wrap">
          <input type="hidden" name="csrf" value="<?= $e(Auth::csrfToken()) ?>">
          <input type="hidden" name="accion" value="editar">
          <input type="hidden" name="id" value="<?= (int) $f['destinatario_id'] ?>">
          <input type="hidden" name="volver" value="<?= $e($_SERVER['QUERY_STRING'] ?? '') ?>">
          <input type="email" name="correo" value="<?= $e($f['correo']) ?>" required style="width:180px">
          <select name="tipo"><option value="PARA" <?= $f['tipo'] === 'PARA' ? 'selected' : '' ?>>Destinatario</option>
                              <option value="COPIA" <?= $f['tipo'] === 'COPIA' ? 'selected' : '' ?>>Copia</option></select>
          <button class="btn" type="submit">Guardar</button>
        </form>
        <?php if ((int) $f['activo'] === 1 && $f['rol'] === 'JEFE_ZONA'): ?>
          <span class="sub">buzón institucional: no se desactiva</span>
        <?php elseif ((int) $f['activo'] === 1): ?>
          <form method="post" action="correos.php" style="display:inline" onsubmit="return confirm('¿Desactivar <?= $e($f['correo']) ?>? Deja de recibir correos, no se borra.');">
            <input type="hidden" name="csrf" value="<?= $e(Auth::csrfToken()) ?>">
            <input type="hidden" name="accion" value="desactivar">
            <input type="hidden" name="id" value="<?= (int) $f['destinatario_id'] ?>">
            <input type="hidden" name="volver" value="<?= $e($_SERVER['QUERY_STRING'] ?? '') ?>">
            <button class="btn" type="submit">Desactivar</button>
          </form>
        <?php else: ?>
          <form method="post" action="correos.php" style="display:inline">
            <input type="hidden" name="csrf" value="<?= $e(Auth::csrfToken()) ?>">
            <input type="hidden" name="accion" value="activar">
            <input type="hidden" name="id" value="<?= (int) $f['destinatario_id'] ?>">
            <input type="hidden" name="volver" value="<?= $e($_SERVER['QUERY_STRING'] ?? '') ?>">
            <button class="btn primary" type="submit">Reactivar</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php
    return (string) ob_get_clean();
}

function tablaCorreos(array $filas, callable $e, string $vacio): string
{
    ob_start();
    ?>
    <div class="tabla-wrap">
      <table class="tarjetas">
        <thead><tr><th>Correo</th><th>Nombre</th><th>Tipo</th><th>Origen</th><th>Estado</th><th>Acciones</th></tr></thead>
        <tbody>
        <?php if (!$filas): ?>
          <tr><td colspan="6" class="vacio"><?= $e($vacio) ?></td></tr>
        <?php endif; ?>
        <?php foreach ($filas as $f) { echo filaCorreo($f, $e); } ?>
        </tbody>
      </table>
    </div>
    <?php
    return (string) ob_get_clean();
}

function formAlta(callable $e, string $volver, string $uso, string $destino, string $ambito,
                   ?string $zona, ?string $local, string $rolFijo = ''): string
{
    ob_start();
    ?>
    <form method="post" action="correos.php" class="form-alta" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin:8px 0 18px">
      <input type="hidden" name="csrf" value="<?= $e(Auth::csrfToken()) ?>">
      <input type="hidden" name="accion" value="alta">
      <input type="hidden" name="uso" value="<?= $e($uso) ?>">
      <input type="hidden" name="destino" value="<?= $e($destino) ?>">
      <input type="hidden" name="ambito" value="<?= $e($ambito) ?>">
      <?php if ($zona !== null): ?><input type="hidden" name="zona" value="<?= $e($zona) ?>"><?php endif; ?>
      <?php if ($local !== null): ?><input type="hidden" name="local_codigo" value="<?= $e($local) ?>"><?php endif; ?>
      <?php if ($rolFijo !== ''): ?><input type="hidden" name="rol" value="<?= $e($rolFijo) ?>"><?php endif; ?>
      <input type="hidden" name="volver" value="<?= $e($_SERVER['QUERY_STRING'] ?? '') ?>">
      <label>Correo<br><input type="email" name="correo" required placeholder="nombre@dominio.com" style="width:220px"></label>
      <label>Nombre (a quién corresponde)<br><input type="text" name="nombre" maxlength="120" placeholder="p. ej. Jefe de mantenimiento zona" style="width:220px"></label>
      <label>Tipo<br><select name="tipo"><option value="COPIA">Copia</option><option value="PARA">Destinatario</option></select></label>
      <?php if ($rolFijo === ''): ?>
      <label>Cadena (opcional, solo esa)<br><input type="text" name="cadena" maxlength="40" placeholder="todas si se deja vacío" style="width:150px"></label>
      <?php endif; ?>
      <button class="btn primary" type="submit">Agregar</button>
    </form>
    <?php
    return (string) ob_get_clean();
}

Ui::cabecera($u, 'correos.php', ['correos' => $contPendientes], ['titulo' => 'Correos']);
?>
<div class="wrap ancho">
  <div class="titulo entra">
    <h1>Correos de las OT INDUSTEC</h1>
    <p class="sub">
      A quién va cada OT INDUSTEC que se emite: el buzón institucional del jefe de zona (siempre en copia), las demás
      copias internas de INDUSTEC, las copias al cliente (Grupo KFC) y el jefe de operaciones de cada local. Nada se
      borra aquí: se desactiva. Cada cambio queda en la <a href="bitacora.php?entidad=correo_destinatario">bitácora</a>.
    </p>
  </div>

  <?= Ui::aviso('ambar', '<b>En el sitio de pruebas los correos no salen.</b> Lo que configures aquí decide a quién '
      . 'IRÍAN en producción; en este sitio el correo de toda OT INDUSTEC queda RETENIDO (no se conecta a ningún SMTP).') ?>

  <?php if ($okFlash): ?><?= Ui::aviso('ok', $e((string) $okFlash), true) ?><?php endif; ?>
  <?php if ($errFlash): ?><?= Ui::aviso('err', $e($errFlash), true) ?><?php endif; ?>

  <div class="tiles tiles-enlace">
    <a class="tile <?= $tab === 'zona' ? 'azul' : '' ?>" href="correos.php?tab=zona&zona=<?= $e($zonaSel) ?>">
      <div class="t">Por zona</div><div class="pie">Buzón institucional, copias internas y al cliente</div>
    </a>
    <a class="tile <?= $tab === 'generales' ? 'azul' : '' ?>" href="correos.php?tab=generales">
      <div class="t">Generales</div><div class="pie">Van en toda OT INDUSTEC, de cualquier zona</div>
    </a>
    <a class="tile <?= $tab === 'locales' ? 'azul' : '' ?>" href="correos.php?tab=locales">
      <div class="t">Por local</div><div class="pie">Jefe de operaciones de KFC y otras copias</div>
    </a>
    <a class="tile <?= $tab === 'reportes' ? 'azul' : '' ?>" href="correos.php?tab=reportes">
      <div class="t">Reportes automáticos</div><div class="pie">Para cuando se active el envío a clientes</div>
    </a>
    <a class="tile <?= $tab === 'propuestos' ? ($contPendientes > 0 ? 'ambar' : 'verde') : '' ?>" href="correos.php?tab=propuestos">
      <div class="n" data-n="<?= $contPendientes ?>"><?= $contPendientes ?></div>
      <div class="t">Propuestos por técnicos</div><div class="pie">Correos de local por aprobar</div>
    </a>
    <a class="tile <?= $tab === 'vista' ? 'azul' : '' ?>" href="correos.php?tab=vista">
      <div class="t">Vista previa</div><div class="pie">A quién le llega una OT INDUSTEC de un local</div>
    </a>
  </div>

  <?php if ($tab === 'zona'): ?>
    <div class="filtros" style="margin:14px 0">
      <?php foreach (ZONAS as $z): ?>
        <a class="btn <?= $z === $zonaSel ? 'primary' : '' ?>" href="correos.php?tab=zona&zona=<?= $e($z) ?>"><?= $e(Vocabulario::corto(Vocabulario::deEstado($z, 'zona'))) ?></a>
      <?php endforeach; ?>
    </div>

    <?php
      $inZona = filtrar($todos, 'ORDEN', 'INTERNO', 'ZONA', $zonaSel);
      $jefeZona = array_values(array_filter($inZona, static fn($f) => $f['rol'] === 'JEFE_ZONA'));
      $otrasInternas = array_values(array_filter($inZona, static fn($f) => $f['rol'] !== 'JEFE_ZONA'));
    ?>
    <h2>Buzón del jefe de zona (INDUSTEC)</h2>
    <p class="sub" style="margin:0 0 8px">Es el buzón institucional de la zona: no cambia aunque cambie quien lo maneja. Se puede editar, no desactivar ni borrar.</p>
    <?= tablaCorreos($jefeZona, $e,
        'Sin sembrar todavía: corre correos_sembrar_cli.php --ejecutar (requiere aprobación de Andrés).') ?>

    <h2>Otras copias internas (INDUSTEC)</h2>
    <?= tablaCorreos($otrasInternas, $e, 'Ninguna copia interna extra en esta zona todavía.') ?>
    <?= formAlta($e, $_SERVER['QUERY_STRING'] ?? '', 'ORDEN', 'INTERNO', 'ZONA', $zonaSel, null, 'OTRO') ?>

    <h2>Copias al cliente (Grupo KFC)</h2>
    <p class="sub" style="margin:0 0 8px">P. ej. el jefe de mantenimiento de la zona (producción los tenía apagados: revisa el simulacro de <code>correos_sembrar_cli.php</code> antes de activar uno).</p>
    <?= tablaCorreos(filtrar($todos, 'ORDEN', 'CLIENTE', 'ZONA', $zonaSel), $e, 'Ninguna copia al cliente en esta zona todavía.') ?>
    <?= formAlta($e, $_SERVER['QUERY_STRING'] ?? '', 'ORDEN', 'CLIENTE', 'ZONA', $zonaSel, null, 'OTRO') ?>

  <?php elseif ($tab === 'generales'): ?>
    <h2>Copias internas (INDUSTEC), todas las zonas</h2>
    <?= tablaCorreos(filtrar($todos, 'ORDEN', 'INTERNO', 'GENERAL'), $e, 'Ninguna copia interna general todavía.') ?>
    <?= formAlta($e, $_SERVER['QUERY_STRING'] ?? '', 'ORDEN', 'INTERNO', 'GENERAL', null, null, 'OTRO') ?>

    <h2>Copias al cliente (Grupo KFC), todas las zonas</h2>
    <?= tablaCorreos(filtrar($todos, 'ORDEN', 'CLIENTE', 'GENERAL'), $e, 'Ninguna copia general al cliente todavía.') ?>
    <?= formAlta($e, $_SERVER['QUERY_STRING'] ?? '', 'ORDEN', 'CLIENTE', 'GENERAL', null, null, 'OTRO') ?>

  <?php elseif ($tab === 'locales'): ?>
    <form method="get" action="correos.php" class="filtros">
      <input type="hidden" name="tab" value="locales">
      <label>Local
        <input list="dl-locales" name="local" value="<?= $e($localSel) ?>" placeholder="Código o nombre del local" style="width:280px">
        <datalist id="dl-locales">
          <?php foreach ($nombreLocal as $cod => $nom): ?><option value="<?= $e($cod) ?>"><?= $e($nom) ?></option><?php endforeach; ?>
        </datalist>
      </label>
      <button class="btn" type="submit">Buscar</button>
    </form>
    <?php if ($localSel === ''): ?>
      <p class="sub" style="margin-top:14px">Busca un local para ver y editar su jefe de operaciones y sus copias al cliente.</p>
    <?php else: ?>
      <?php
        $enEsteLocal = filtrar($todos, 'ORDEN', 'CLIENTE', 'LOCAL', null, $localSel);
        $jefeOp = array_values(array_filter($enEsteLocal, static fn($f) => $f['rol'] === 'JEFE_OPERACIONES'));
        $otrasCliente = array_values(array_filter($enEsteLocal, static fn($f) => $f['rol'] !== 'JEFE_OPERACIONES'));
      ?>
      <h2><?= $e($localSel) ?> · <?= $e($nombreLocal[$localSel]) ?></h2>
      <h3>Jefe de operaciones de KFC (uno por local)</h3>
      <p class="sub" style="margin:0 0 8px">Hasta que se cargue, el PDF muestra «sin configurar» en esa línea: no es el buzón de zona de INDUSTEC, que va aparte y siempre en copia.</p>
      <?= tablaCorreos($jefeOp, $e, 'Sin configurar todavía.') ?>
      <?= formAlta($e, $_SERVER['QUERY_STRING'] ?? '', 'ORDEN', 'CLIENTE', 'LOCAL', null, $localSel, 'JEFE_OPERACIONES') ?>

      <h3>Otras copias al cliente de este local</h3>
      <?= tablaCorreos($otrasCliente, $e, 'Ninguna copia extra para este local.') ?>
      <?= formAlta($e, $_SERVER['QUERY_STRING'] ?? '', 'ORDEN', 'CLIENTE', 'LOCAL', null, $localSel, 'OTRO') ?>
    <?php endif; ?>

  <?php elseif ($tab === 'reportes'): ?>
    <?= Ui::aviso('neutro', 'Se usarán cuando se active el envío automático de reportes a clientes (T2.3). Esta es la '
        . 'tabla que deben leer los generadores de la conversación «estadísticas».') ?>
    <?= tablaCorreos(array_values(array_filter($todos, static fn($f) => $f['uso'] === 'REPORTE')), $e,
        'Ningún destinatario de reportes configurado todavía.') ?>
    <?= formAlta($e, $_SERVER['QUERY_STRING'] ?? '', 'REPORTE', 'CLIENTE', 'GENERAL', null, null, 'OTRO') ?>

  <?php elseif ($tab === 'propuestos'): ?>
    <p class="sub" style="margin:0 0 10px">El correo que un técnico escribió para el local, si es distinto del maestro. Aprobarlo lo vuelve el correo por defecto de ese local (hasta entonces, la OT INDUSTEC que lo escribió ya salió a ese correo: aprobar no reenvía nada, solo fija el valor para las siguientes).</p>
    <div class="tabla-wrap">
      <table class="tarjetas">
        <thead><tr><th>Local</th><th>Correo propuesto</th><th>Antes</th><th>Veces</th><th>Quién / cuándo</th><th>Decisión</th></tr></thead>
        <tbody>
        <?php if (!$pendientes): ?>
          <tr><td colspan="6" class="vacio">Ninguna propuesta pendiente.</td></tr>
        <?php endif; ?>
        <?php foreach ($pendientes as $p): ?>
          <tr>
            <td data-th="Local"><b class="mono"><?= $e($p['local_codigo']) ?></b><div class="sub"><?= $e($nombreLocal[$p['local_codigo']] ?? '') ?></div></td>
            <td data-th="Propuesto" class="mono"><?= $e($p['correo']) ?></td>
            <td data-th="Antes" class="mono sub"><?= $e($p['correo_anterior'] ?: '—') ?></td>
            <td data-th="Veces"><?= (int) $p['veces'] ?></td>
            <td data-th="Quién"><?= $e($p['propuesto_nombre'] ?: $p['propuesto_usuario'] ?: '—') ?><div class="sub mono"><?= $e(substr((string) $p['propuesto_en'], 0, 16)) ?></div></td>
            <td data-th="Decisión">
              <div class="acciones-fila">
                <form method="post" action="correos.php" style="margin:0">
                  <input type="hidden" name="csrf" value="<?= $e(Auth::csrfToken()) ?>">
                  <input type="hidden" name="accion" value="aprobar">
                  <input type="hidden" name="id" value="<?= (int) $p['propuesta_id'] ?>">
                  <input type="hidden" name="volver" value="tab=propuestos">
                  <button class="btn primary" type="submit">Aprobar</button>
                </form>
                <button class="btn" type="button" data-id="<?= (int) $p['propuesta_id'] ?>" onclick="rechazarProp(this)">Rechazar</button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <dialog id="dlgRechazo">
      <form method="post" action="correos.php">
        <input type="hidden" name="csrf" value="<?= $e(Auth::csrfToken()) ?>">
        <input type="hidden" name="accion" value="rechazar">
        <input type="hidden" name="id" id="dlgPropId" value="">
        <input type="hidden" name="volver" value="tab=propuestos">
        <h2>Rechazar la propuesta</h2>
        <textarea name="nota" rows="3" maxlength="300" required minlength="5" placeholder="Por qué no se aprueba. Queda en la bitácora."></textarea>
        <div class="acciones" style="margin-top:12px">
          <button class="btn primary" type="submit">Rechazar</button>
          <button class="btn" type="button" onclick="document.getElementById('dlgRechazo').close()">Cancelar</button>
        </div>
      </form>
    </dialog>
    <script>
    function rechazarProp(b) {
      document.getElementById('dlgPropId').value = b.dataset.id;
      document.getElementById('dlgRechazo').showModal();
    }
    </script>

  <?php elseif ($tab === 'vista'): ?>
    <form method="get" action="correos.php" class="filtros">
      <input type="hidden" name="tab" value="vista">
      <label>Local
        <input list="dl-locales" name="local" value="<?= $e($localSel) ?>" placeholder="Código o nombre del local" style="width:280px">
        <datalist id="dl-locales">
          <?php foreach ($nombreLocal as $cod => $nom): ?><option value="<?= $e($cod) ?>"><?= $e($nom) ?></option><?php endforeach; ?>
        </datalist>
      </label>
      <button class="btn" type="submit">Ver</button>
    </form>
    <?php if ($localSel === ''): ?>
      <p class="sub" style="margin-top:14px">Elige un local: es la prueba de que la configuración hace lo que se cree.</p>
    <?php else:
      $localFila = null;
      foreach ($locales as $l) { if (($l['codigo'] ?? '') === $localSel) { $localFila = $l; break; } }
      $dest = Destinatarios::resolver('ORDEN', (string) ($localFila['zona'] ?? ''), $localSel,
                                      (string) ($localFila['cadena'] ?? null), null);
    ?>
      <h2><?= $e($localSel) ?> · <?= $e($nombreLocal[$localSel]) ?> · <?= $e(($localFila['zona'] ?? '') !== '' ? Ui::nombreZona((string) $localFila['zona']) : Vocabulario::t('SIN_ZONA')) ?></h2>
      <p>Una OT INDUSTEC de este local, sin que el técnico escriba un correo distinto, se envía a:</p>
      <p><b>Para:</b> <?= $dest['para'] ? implode(', ', array_map($e, $dest['para'])) : '<span class="sub">nadie (sin destinatarios)</span>' ?></p>
      <p><b>Copia:</b> <?= $dest['cc'] ? implode(', ', array_map($e, $dest['cc'])) : '<span class="sub">nadie</span>' ?></p>
      <p><b>Jefe de operaciones (KFC) que imprime el PDF:</b> <?= $e(Destinatarios::jefeOperaciones($localSel, (string) ($localFila['cadena'] ?? '')) ?? 'sin configurar') ?></p>
      <h3>De dónde sale cada dirección</h3>
      <ul>
        <?php foreach ($dest['detalle'] as $d): ?>
          <li class="mono"><?= $e($d['correo']) ?></li>
          <span class="sub"><?= $e($d['tipo'] === 'PARA' ? 'destinatario' : 'copia') ?> · <?= $e($d['origen']) ?></span>
        <?php endforeach; ?>
        <?php if (!$dest['detalle']): ?><li class="sub">Nada calzó para este local.</li><?php endif; ?>
      </ul>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php Ui::pie(); ?>
