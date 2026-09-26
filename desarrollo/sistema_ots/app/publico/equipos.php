<?php
declare(strict_types=1);
/**
 * equipos.php — Los equipos nuevos que los técnicos registraron desde el
 * formulario («Equipo nuevo / no está en la lista»), para que la administración
 * los confirme (T2.14.1 y D8; pantalla del 2026-09-13, T2.14.8).
 *
 * POR QUÉ EXISTE. La decisión D8 dice que un equipo propuesto por un técnico se
 * ofrece de inmediato en la lista de su local (marcado), para todas las zonas,
 * y que la administración lo APRUEBA para que la estación lo incorpore al
 * maestro. `envio.php` deja la fila en `equipos_propuestos` y `Catalogo.php` la
 * fusiona en el catálogo del local; lo que faltaba era la pantalla donde alguien
 * la confirma. Sin ella, todo propuesto se quedaba PROPUESTO para siempre.
 *
 * QUÉ HACE. Lista los propuestos (los pendientes arriba, después el historial)
 * con el local, el tipo, marca/modelo/serie, quién lo registró, cuándo y en qué
 * orden; y tres decisiones:
 *   aprobar    → APROBADO: sigue en la lista del local y la estación lo toma
 *                para el maestro (la fila queda como fuente).
 *   rechazar   → RECHAZADO, con nota obligatoria: deja de ofrecerse en la lista.
 *                La orden en la que se registró conserva el equipo tal cual.
 *   ya_existia → FUSIONADO, con nota que diga cuál era: deja de ofrecerse.
 * Cada decisión queda en la bitácora (EQUIPO_APROBAR / EQUIPO_RECHAZAR /
 * EQUIPO_FUSIONAR, entidad `equipo_propuesto`, referencia el uuid).
 *
 * Permiso: `equipos.aprobar` (SUPERADMIN y ADMIN). POST con CSRF (D13) y patrón
 * POST-redirect-GET, como usuarios.php y documentos.php.
 */
require_once __DIR__ . '/nucleo/Ui.php';
require_once __DIR__ . '/nucleo/Catalogo.php';

$u = Auth::exigir();
if (!Ui::puedeModulo('equipos.aprobar', ['SUPERADMIN', 'ADMIN'], $u)) {
    Auth::bitacora('DENEGADO', 'equipo_propuesto', '', 'sin permiso equipos.aprobar', null, null, [], false);
    http_response_code(403);
    exit('No tienes acceso a los equipos propuestos.');
}
$e = fn(?string $s): string => Ui::e($s);

function terminarEq(?string $ok, ?string $error): void
{
    $_SESSION['flash'] = array_filter(['ok' => $ok, 'error' => $error]);
    header('Location: equipos.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

const DECISIONES = [
    // acción POST => [estado nuevo, acción de bitácora, ¿nota obligatoria?, texto del resultado]
    'aprobar'    => ['APROBADO',  'EQUIPO_APROBAR',  false, 'Equipo aprobado: sigue en la lista del local y la estación lo incorpora al maestro.'],
    'rechazar'   => ['RECHAZADO', 'EQUIPO_RECHAZAR', true,  'Equipo rechazado: ya no se ofrece en la lista. La orden conserva lo que escribió el técnico.'],
    'ya_existia' => ['FUSIONADO', 'EQUIPO_FUSIONAR', true,  'Marcado como ya existente: ya no se ofrece por duplicado.'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::exigirCsrf();
    $accion = (string) ($_POST['accion'] ?? '');
    $uuid   = strtolower(trim((string) ($_POST['uuid'] ?? '')));
    $nota   = trim((string) ($_POST['nota'] ?? ''));
    if (!isset(DECISIONES[$accion])) {
        terminarEq(null, 'Acción desconocida.');
    }
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
        terminarEq(null, 'El identificador del equipo no es válido.');
    }
    [$estadoNuevo, $accionBit, $notaObligatoria, $mensaje] = DECISIONES[$accion];
    if ($notaObligatoria && mb_strlen($nota) < 5) {
        Auth::bitacora($accionBit, 'equipo_propuesto', $uuid, 'rechazado: falta la nota', null, null, [], false);
        terminarEq(null, 'Escribe el motivo (al menos cinco caracteres): queda en la bitácora y le sirve al técnico.');
    }
    $fila = Db::uno('SELECT equipo_uuid, estado, local_codigo, tipo FROM equipos_propuestos WHERE equipo_uuid = ?', [$uuid]);
    if (!$fila) {
        terminarEq(null, 'Ese equipo propuesto no existe.');
    }
    if ($fila['estado'] !== 'PROPUESTO') {
        Auth::bitacora($accionBit, 'equipo_propuesto', $uuid, 'rechazado: ya estaba ' . $fila['estado'], $fila['estado'], null, [], false);
        terminarEq(null, 'Ese equipo ya fue decidido (' . Ui::e((string) $fila['estado']) . '). Si hay que cambiarlo, se registra de nuevo desde una orden.');
    }
    // `AND estado = 'PROPUESTO'` cierra la carrera entre dos personas decidiendo
    // a la vez sobre el mismo equipo: solo una escribe.
    $n = Db::ejecutar(
        "UPDATE equipos_propuestos
            SET estado = ?, revisado_por = ?, revisado_en = NOW(), nota = ?
          WHERE equipo_uuid = ? AND estado = 'PROPUESTO'",
        [$estadoNuevo, (int) $u['usuario_id'], $nota !== '' ? mb_substr($nota, 0, 300) : null, $uuid]
    );
    if ($n === 0) {
        terminarEq(null, 'Alguien decidió sobre ese equipo hace un instante. Recarga la lista.');
    }
    Auth::bitacora($accionBit, 'equipo_propuesto', $uuid, $nota !== '' ? mb_substr($nota, 0, 200) : null,
                   'PROPUESTO', $estadoNuevo,
                   ['local' => $fila['local_codigo'], 'tipo' => $fila['tipo'], 'nota' => $nota]);
    terminarEq($mensaje, null);
}

// ------------------------------------------------------------------ la lista
$okFlash  = $_SESSION['flash']['ok'] ?? null;
unset($_SESSION['flash']['ok']);
$errFlash = Ui::errorFlash();

$fEstado = strtoupper(trim((string) ($_GET['est'] ?? '')));
if (!in_array($fEstado, ['', 'PROPUESTO', 'APROBADO', 'RECHAZADO', 'FUSIONADO'], true)) { $fEstado = ''; }
$fZona = strtoupper(trim((string) ($_GET['zona'] ?? '')));
if (!in_array($fZona, ['', 'UIO', 'LARB', 'CNLJ', 'OTRA'], true)) { $fZona = ''; }

$donde = ['1=1']; $par = [];
if ($fEstado !== '') { $donde[] = 'p.estado = ?'; $par[] = $fEstado; }
if ($fZona !== '')   { $donde[] = 'p.zona = ?';   $par[] = $fZona; }

$filas = Db::todos(
    "SELECT p.*, u.nombre AS propuesto_nombre, u.usuario AS propuesto_usuario,
            r.nombre AS revisado_nombre,
            c.id_industec AS orden_id
       FROM equipos_propuestos p
       LEFT JOIN usuarios u ON u.usuario_id = p.propuesto_por
       LEFT JOIN usuarios r ON r.usuario_id = p.revisado_por
       LEFT JOIN ot_capturadas c ON c.envio_uuid = p.envio_uuid
      WHERE " . implode(' AND ', $donde) . "
      ORDER BY (p.estado = 'PROPUESTO') DESC, p.propuesto_en DESC
      LIMIT 300",
    $par
);
$cont = ['PROPUESTO' => 0, 'APROBADO' => 0, 'RECHAZADO' => 0, 'FUSIONADO' => 0];
foreach (Db::todos('SELECT estado, COUNT(*) AS n FROM equipos_propuestos GROUP BY estado') as $c) {
    $cont[$c['estado']] = (int) $c['n'];
}

// El nombre del local sale del maestro que publica la estación; si el catálogo
// no está, se muestra el código y nada se rompe.
$nombreLocal = [];
$cat = Catalogo::cargar();
foreach ((array) ($cat['locales'] ?? []) as $l) {
    $cod = (string) ($l['codigo'] ?? '');
    if ($cod !== '') { $nombreLocal[$cod] = (string) ($l['nombre'] ?? $cod); }
}

const ETIQUETA = [
    'PROPUESTO' => ['por confirmar', 'sin'],
    'APROBADO'  => ['aprobado', 'con'],
    'RECHAZADO' => ['rechazado', 'sin'],
    'FUSIONADO' => ['ya existía', 'con'],
];
$hayFiltro = $fEstado !== '' || $fZona !== '';

// T2.28.6 (obs. 4): qué series cambiaron en los últimos 90 días -la señal de
// que un equipo se reemplazó, no solo se le corrigió un typeo a la placa
// (envio.php ya distingue: esto solo se anota si ANTES había un valor real,
// nunca la primera vez que se conoce la serie). Try/catch: sin la 014
// aplicada, la sección sale vacía en vez de tumbar la pantalla.
try {
    $seriesCambiadas = Db::todos(
        "SELECT c.equipo_clave, c.antes, c.despues, c.en, ef.local_codigo, u.nombre AS por_nombre
           FROM equipos_ficha_cambios c
           LEFT JOIN equipos_ficha ef ON ef.equipo_clave = c.equipo_clave
           LEFT JOIN usuarios u ON u.usuario_id = c.por
          WHERE c.campo = 'serie' AND c.antes IS NOT NULL AND c.en >= NOW() - INTERVAL 90 DAY
          ORDER BY c.en DESC
          LIMIT 300"
    );
} catch (Throwable $ex) {
    $seriesCambiadas = [];
}

Ui::cabecera($u, 'equipos.php', ['equipos' => $cont['PROPUESTO']], ['titulo' => 'Equipos nuevos']);
?>
<div class="wrap ancho">
  <div class="titulo entra">
    <h1>Equipos nuevos</h1>
    <p class="sub">
      Los equipos que los técnicos registraron desde una orden porque <b>no estaban en la lista</b> de su local.
      Desde que se proponen ya se ofrecen en esa lista, marcados, para todas las zonas; aquí la administración los
      confirma: <b>aprobar</b> (pasa al catálogo y la estación lo lleva al maestro), <b>rechazar</b> con motivo
      (era un error) o <b>ya existía</b> (se registró por duplicado). La orden conserva siempre lo que escribió el técnico.
    </p>
  </div>

  <?php if ($okFlash): ?><?= Ui::aviso('ok', $e((string) $okFlash), true) ?><?php endif; ?>
  <?php if ($errFlash): ?><?= Ui::aviso('err', $e($errFlash), true) ?><?php endif; ?>

  <div class="tiles tiles-enlace">
    <a class="tile <?= $cont['PROPUESTO'] > 0 ? 'ambar' : 'verde' ?>" href="equipos.php?est=PROPUESTO">
      <div class="n" data-n="<?= $cont['PROPUESTO'] ?>"><?= $cont['PROPUESTO'] ?></div>
      <div class="t">Por confirmar</div>
      <div class="pie">Propuestos por los técnicos</div>
    </a>
    <a class="tile azul" href="equipos.php?est=APROBADO">
      <div class="n" data-n="<?= $cont['APROBADO'] ?>"><?= $cont['APROBADO'] ?></div>
      <div class="t">Aprobados</div>
      <div class="pie">En el catálogo, camino al maestro</div>
    </a>
    <a class="tile" href="equipos.php?est=RECHAZADO">
      <div class="n" data-n="<?= $cont['RECHAZADO'] ?>"><?= $cont['RECHAZADO'] ?></div>
      <div class="t">Rechazados</div>
      <div class="pie">Con su motivo</div>
    </a>
    <a class="tile" href="equipos.php?est=FUSIONADO">
      <div class="n" data-n="<?= $cont['FUSIONADO'] ?>"><?= $cont['FUSIONADO'] ?></div>
      <div class="t">Ya existían</div>
      <div class="pie">Registrados por duplicado</div>
    </a>
  </div>

  <form method="get" class="filtros" action="equipos.php">
    <label>Estado
      <select name="est">
        <option value="">Todos</option>
        <?php foreach (ETIQUETA as $k => [$txt]): ?>
          <option value="<?= $k ?>" <?= $fEstado === $k ? 'selected' : '' ?>><?= $e($txt) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Zona
      <select name="zona">
        <option value="">Todas</option>
        <?php foreach (['UIO', 'LARB', 'CNLJ', 'OTRA'] as $z): ?>
          <option value="<?= $z ?>" <?= $fZona === $z ? 'selected' : '' ?>><?= $z ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn" type="submit">Filtrar</button>
  </form>

  <p class="sub" style="margin:0 0 10px"><b><?= count($filas) ?></b> equipo<?= count($filas) === 1 ? '' : 's' ?><?= $hayFiltro ? ' con esos filtros' : '' ?>. Primero los que faltan por confirmar, el más reciente arriba.</p>

  <div class="tabla-wrap">
    <table class="tarjetas equipos">
      <thead><tr>
        <th>Local</th><th>Equipo</th><th>Lo registró</th><th>En la orden</th><th>Estado</th><th>Decisión</th>
      </tr></thead>
      <tbody>
      <?php if (!$filas): ?>
        <tr><td colspan="6" class="vacio"><?= $hayFiltro
            ? 'Ningún equipo propuesto con esos filtros.'
            : 'Ningún equipo propuesto todavía: cuando un técnico marque «Equipo nuevo / no está en la lista» en una orden, aparece aquí.' ?></td></tr>
      <?php endif; ?>
      <?php foreach ($filas as $f): [$etq, $clase] = ETIQUETA[$f['estado']] ?? [$f['estado'], 'sin'];
            $detalle = implode(' · ', array_filter([
                $f['marca'], $f['modelo'],
                $f['serie'] ? 'serie ' . $f['serie'] : null,
                $f['activo_fijo'] ? 'activo ' . $f['activo_fijo'] : null,
                $f['area'],
            ])); ?>
        <tr id="eq-<?= $e($f['equipo_uuid']) ?>">
          <td data-th="Local">
            <b class="mono"><?= $e($f['local_codigo']) ?></b>
            <div class="sub"><?= $e($nombreLocal[$f['local_codigo']] ?? '') ?>
              <?php if ($f['zona']): ?> · <span class="zona-<?= strtolower($e($f['zona'])) ?>"><?= $e($f['zona']) ?></span><?php endif; ?>
            </div>
          </td>
          <td data-th="Equipo">
            <b><?= $e($f['tipo']) ?></b>
            <div class="sub"><?= $detalle !== '' ? $e($detalle) : 'sin marca ni modelo' ?></div>
          </td>
          <td data-th="Lo registró">
            <?= $e($f['propuesto_nombre'] ?: $f['propuesto_usuario'] ?: '—') ?>
            <div class="sub mono"><?= $e(substr((string) $f['propuesto_en'], 0, 16)) ?></div>
          </td>
          <td data-th="En la orden">
            <?php if ($f['orden_id']): ?>
              <a class="mono" href="ordenes.php?q=<?= rawurlencode((string) $f['orden_id']) ?>"><?= $e($f['orden_id']) ?></a>
            <?php elseif ($f['envio_uuid']): ?>
              <span class="sub">orden en proceso</span>
            <?php else: ?>
              <span class="sub">—</span>
            <?php endif; ?>
          </td>
          <td data-th="Estado">
            <span class="estado-red <?= $clase ?>"><?= $e($etq) ?></span>
            <?php if ($f['estado'] !== 'PROPUESTO'): ?>
              <div class="sub"><?= $e($f['revisado_nombre'] ?: '') ?> · <span class="mono"><?= $e(substr((string) $f['revisado_en'], 0, 16)) ?></span><?= $f['nota'] ? '<br>' . $e($f['nota']) : '' ?></div>
            <?php endif; ?>
          </td>
          <td data-th="Decisión">
            <?php if ($f['estado'] === 'PROPUESTO'): ?>
              <div class="acciones-fila">
                <form method="post" action="equipos.php" style="margin:0">
                  <input type="hidden" name="csrf" value="<?= $e(Auth::csrfToken()) ?>">
                  <input type="hidden" name="accion" value="aprobar">
                  <input type="hidden" name="uuid" value="<?= $e($f['equipo_uuid']) ?>">
                  <button class="btn primary" type="submit">Aprobar</button>
                </form>
                <button class="btn" type="button" data-uuid="<?= $e($f['equipo_uuid']) ?>" data-accion="rechazar" data-tipo="<?= $e($f['tipo']) ?>" onclick="decidir(this)">Rechazar</button>
                <button class="btn" type="button" data-uuid="<?= $e($f['equipo_uuid']) ?>" data-accion="ya_existia" data-tipo="<?= $e($f['tipo']) ?>" onclick="decidir(this)">Ya existía</button>
              </div>
            <?php else: ?>
              <span class="sub">decidido</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <h2 style="margin-top:26px">Series que cambiaron (90 días)</h2>
  <p class="sub" style="margin:0 0 12px">
    Cuando la serie de un equipo cambia desde un valor real -no la primera vez que se conoce, sino un reemplazo-,
    queda anotada aquí. Es lo más parecido a saber que se cambió el equipo sin que nadie lo registre aparte.
    Solo lectura: no hay nada que aprobar.
  </p>
  <div class="tabla-wrap">
    <table class="tarjetas equipos">
      <thead><tr><th>Local</th><th>Equipo</th><th>Serie antes</th><th>Serie ahora</th><th>Cuándo</th><th>Quién</th></tr></thead>
      <tbody>
      <?php if (!$seriesCambiadas): ?>
        <tr><td colspan="6" class="vacio">Ninguna serie cambió en los últimos 90 días.</td></tr>
      <?php endif; ?>
      <?php foreach ($seriesCambiadas as $s): ?>
        <tr>
          <td data-th="Local">
            <b class="mono"><?= $e($s['local_codigo'] ?: '—') ?></b>
            <div class="sub"><?= $e($nombreLocal[$s['local_codigo']] ?? '') ?></div>
          </td>
          <td data-th="Equipo"><span class="mono"><?= $e($s['equipo_clave']) ?></span></td>
          <td data-th="Serie antes"><?= $e($s['antes']) ?></td>
          <td data-th="Serie ahora"><?= $e($s['despues']) ?></td>
          <td data-th="Cuándo"><span class="mono"><?= $e(substr((string) $s['en'], 0, 16)) ?></span></td>
          <td data-th="Quién"><?= $e($s['por_nombre'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <h2 style="margin-top:26px">Qué pasa con cada decisión</h2>
  <p class="sub" style="margin:0 0 12px">
    <b>Aprobar:</b> el equipo queda en el catálogo del local para todas las zonas y la estación lo incorpora al
    maestro en el siguiente saneamiento; si después hay que corregirle la marca o el modelo, se hace en el maestro.
    <b>Rechazar:</b> deja de ofrecerse en la lista; la orden donde se registró no cambia y el motivo va en la bitácora.
    <b>Ya existía:</b> el técnico no lo encontró, pero estaba (otro nombre, otro activo fijo): se anota cuál era y
    deja de ofrecerse por duplicado. Nada se borra: cada decisión queda en la
    <a href="bitacora.php?entidad=equipo_propuesto">bitácora</a> con quién y cuándo.
  </p>
</div>

<dialog id="dlgDecidir">
  <form method="post" action="equipos.php">
    <input type="hidden" name="csrf" value="<?= $e(Auth::csrfToken()) ?>">
    <input type="hidden" name="accion" id="dlgAccion" value="">
    <input type="hidden" name="uuid" id="dlgUuid" value="">
    <h2 id="dlgTitulo">Rechazar el equipo</h2>
    <p class="sub" style="margin:0 0 12px" id="dlgSub"></p>
    <textarea name="nota" id="dlgNota" rows="3" maxlength="300" required minlength="5" placeholder="Por qué no entra al catálogo, o cuál era el equipo que ya existía. Queda en la bitácora."></textarea>
    <div class="acciones" style="margin-top:12px">
      <button class="btn primary" type="submit" id="dlgBoton">Rechazar</button>
      <button class="btn" type="button" onclick="document.getElementById('dlgDecidir').close()">Cancelar</button>
    </div>
  </form>
</dialog>
<script>
function decidir(b) {
  const d = document.getElementById('dlgDecidir');
  const rechazo = b.dataset.accion === 'rechazar';
  document.getElementById('dlgUuid').value = b.dataset.uuid;
  document.getElementById('dlgAccion').value = b.dataset.accion;
  document.getElementById('dlgTitulo').textContent = rechazo ? 'Rechazar el equipo' : 'Marcar que ya existía';
  document.getElementById('dlgSub').textContent = b.dataset.tipo;
  document.getElementById('dlgBoton').textContent = rechazo ? 'Rechazar' : 'Ya existía';
  document.getElementById('dlgNota').value = '';
  d.showModal();
  document.getElementById('dlgNota').focus();
}
</script>
<?php Ui::pie(); ?>
