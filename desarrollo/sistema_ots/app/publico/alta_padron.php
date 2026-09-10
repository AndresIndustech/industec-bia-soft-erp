<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Auth.php';

/**
 * alta_padron.php — Crea de una vez los usuarios del padrón de la administración.
 *
 * POR QUE EXISTE:
 * Son 19 personas. Cargarlas a mano en `usuarios.php` es 19 formularios, y cada
 * uno es una oportunidad de teclear mal un nombre de usuario. Peor: el nombre
 * de usuario tiene que coincidir con el que quedó en el padrón de la estación
 * (`industec_ots.tecnicos.usuario`), porque ese es el enlace entre la persona
 * que firma una orden y la persona que inicia sesión. Escrito dos veces, tarde
 * o temprano difiere en uno.
 *
 * ESTO NO ES UN ATAJO A LOS PERMISOS. Cada fila pasa por las MISMAS
 * comprobaciones que el formulario de `usuarios.php`:
 *   - el rol debe estar en Auth::rolesQuePuedeCrear() (rango),
 *   - la zona debe estar dentro de Auth::alcanzaZona() (alcance),
 *   - técnico y jefe de zona necesitan zona; administración no la lleva.
 * Hoy el permiso ya deja afuera al jefe de zona: al abrirla recibe un 403, y se
 * comprobó. Las revalidaciones de rango y alcance están igual, para el día en
 * que alguien le conceda `usuarios.operativos` a otro rol. Probado con un
 * padrón alterado que pedía un SUPERADMIN y un ADMIN: la administradora los vio
 * como «no puedes crear ese rol», el POST directo tampoco los creó, y solo se
 * dio de alta el técnico legítimo. Nadie se asciende por venir en un archivo.
 *
 * LA LISTA NO ESTA EN EL CODIGO. Se lee de `nucleo/padron.json`, que genera
 * `t2_8_padron_tecnicos.py` desde el Excel de la administración. Dos razones:
 * los nombres de 19 empleados no van a un archivo versionado, y la lista es un
 * dato de la administración, no una decisión del programa. `nucleo/` está
 * cerrado por .htaccess, así que el archivo no se sirve por web.
 *
 * NUNCA TOCA UNA CUENTA QUE YA EXISTE. Si `amorales` ya está creado, se salta:
 * regenerarle la clave dejaría afuera a alguien que ya entró y la cambió. Para
 * eso está el botón «Nueva clave» de `usuarios.php`, uno por uno.
 *
 * SE USA UNA VEZ, EL DIA DEL ARRANQUE. Después se borra del servidor.
 */

$u = Auth::exigir();
if (!Auth::puede('usuarios.gestionar') && !Auth::puede('usuarios.operativos')) {
    Auth::bitacora('DENEGADO', 'permiso', 'alta_padron');
    http_response_code(403);
    exit('<p style="font-family:system-ui;padding:24px">No tienes permiso para esta sección.</p>');
}

const PADRON_JSON = __DIR__ . '/nucleo/padron.json';
const ZONAS = ['UIO', 'LARB', 'CNLJ', 'OTRA'];

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/** Igual que en usuarios.php: sin I, O, 0, 1, que se confunden al dictarla. */
function claveTemporal(): string
{
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = [];
    for ($b = 0; $b < 4; $b++) {
        $s = '';
        for ($i = 0; $i < 4; $i++) { $s .= $abc[random_int(0, strlen($abc) - 1)]; }
        $out[] = $s;
    }
    return implode('-', $out);
}

/**
 * Lee el padrón y decide, fila por fila, qué se puede hacer con cada persona.
 * No escribe nada: solo clasifica. Así la pantalla previa muestra exactamente
 * lo que va a pasar, y el POST vuelve a llamar a esta misma función.
 */
function revisarPadron(array $puedeCrear): array
{
    if (!is_file(PADRON_JSON)) {
        return [null, 'Falta <code>nucleo/padron.json</code>. Lo genera '
                    . '<code>t2_8_padron_tecnicos.py</code> en la estación.'];
    }
    $j = json_decode((string) file_get_contents(PADRON_JSON), true);
    if (!is_array($j) || !isset($j['personas']) || !is_array($j['personas'])) {
        return [null, 'El archivo <code>nucleo/padron.json</code> no se entiende. '
                    . 'Vuelve a generarlo; no lo edites a mano.'];
    }

    $filas = [];
    foreach ($j['personas'] as $p) {
        $usuario = strtolower(trim((string) ($p['usuario'] ?? '')));
        $nombre  = trim((string) ($p['nombre'] ?? ''));
        $rol     = (string) ($p['rol'] ?? '');
        $zona    = ($p['zona'] ?? null) ?: null;
        $motivo  = null;

        // El mismo orden de comprobaciones que el formulario, para que no haya
        // una regla que valga en una pantalla y no en la otra.
        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $usuario)) {
            $motivo = 'nombre de usuario inválido';
        } elseif ($nombre === '') {
            $motivo = 'falta el nombre completo';
        } elseif (!in_array($rol, $puedeCrear, true)) {
            $motivo = 'no puedes crear ese rol';
        } elseif (in_array($rol, ['JEFE_ZONA', 'TECNICO'], true) && !in_array($zona, ZONAS, true)) {
            $motivo = 'le falta la zona, que es su alcance';
        } elseif (in_array($rol, ['SUPERADMIN', 'ADMIN'], true) && $zona !== null) {
            $motivo = 'ese rol no lleva zona';
        } elseif (!Auth::alcanzaZona($zona)) {
            $motivo = 'está fuera de tu zona';
        } elseif (Db::uno('SELECT usuario_id FROM usuarios WHERE usuario = ?', [$usuario])) {
            $motivo = 'ya existe';
        }

        $filas[] = ['usuario' => $usuario, 'nombre' => $nombre, 'rol' => $rol,
                    'zona' => $zona, 'motivo' => $motivo];
    }
    return [['fuente'   => (string) ($j['fuente'] ?? '—'),
             'generado' => (string) ($j['generado'] ?? '—'),
             'filas'    => $filas], null];
}

$puedeCrear = Auth::rolesQuePuedeCrear();
[$padron, $error] = revisarPadron($puedeCrear);
$creadas = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear' && $padron) {
    foreach ($padron['filas'] as $f) {
        if ($f['motivo'] !== null) { continue; }     // saltados: no se tocan
        $clave = claveTemporal();
        Db::ejecutar(
            'INSERT INTO usuarios (usuario, nombre, clave_hash, rol, zona,
                                   debe_cambiar_clave, creado_por)
             VALUES (?,?,?,?,?,1,?)',
            [$f['usuario'], $f['nombre'], password_hash($clave, PASSWORD_DEFAULT),
             $f['rol'], $f['zona'], $u['usuario_id']]
        );
        Auth::bitacora('CREAR_USUARIO', 'usuario', $f['usuario'],
                       'alta masiva, rol=' . $f['rol'] . ' zona=' . ($f['zona'] ?? '-'));
        $creadas[] = $f + ['clave' => $clave];
    }
    // Se vuelve a revisar para que la tabla de abajo muestre el estado real.
    [$padron, $error] = revisarPadron($puedeCrear);
}

$pendientes = $padron ? array_filter($padron['filas'], fn($f) => $f['motivo'] === null) : [];
$ROL = ['SUPERADMIN' => 'Superadministrador', 'ADMIN' => 'Administración',
        'JEFE_ZONA' => 'Jefe de zona', 'TECNICO' => 'Técnico'];
$confirmar = 'Se van a crear ' . count($pendientes) . ' cuentas con contraseña '
           . 'provisional. Las contraseñas se muestran UNA sola vez: ten con qué '
           . 'anotarlas antes de continuar.';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Alta del padrón · B.IA Soft ERP</title>
<link rel="stylesheet" href="estilo.css">
<style>
  .barra{ display:flex; justify-content:space-between; align-items:center; gap:12px;
          flex-wrap:wrap; padding:10px 14px; background:#fff;
          border-bottom:1px solid var(--border); position:sticky; top:0; z-index:40; }
  table{ width:100%; border-collapse:collapse; font-size:13.5px; }
  th{ text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.05em;
      color:var(--muted); padding:8px 10px; border-bottom:1px solid var(--border); }
  td{ padding:9px 10px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
  tr.salta td{ opacity:.55; }
  .mono{ font-family:ui-monospace,SFMono-Regular,Menlo,monospace; }
  .clave{ font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:16px;
          font-weight:700; letter-spacing:.05em; user-select:all; }
  .err{ background:#fef2f2; border:1px solid #fecaca; color:#991b1b;
        border-radius:9px; padding:10px 12px; font-size:13px; margin-bottom:14px; }
  .tabla-wrap{ overflow-x:auto; }
  .entregar{ background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:14px; }
  @media print{
    .barra, .noimprimir{ display:none !important; }
    .entregar{ border:none; background:none; padding:0; }
  }
</style>
</head>
<body>

<div class="barra noimprimir">
  <strong><a href="panel.php" style="text-decoration:none;color:inherit">← B.IA Soft ERP</a></strong>
  <div style="display:flex;align-items:center;gap:10px;font-size:13px">
    <span style="font-weight:700"><?= e($u['nombre']) ?></span>
    <a class="btn" href="usuarios.php">Usuarios</a>
    <a class="btn" href="salir.php">Salir</a>
  </div>
</div>

<div class="wrap">
  <div class="card">
    <h1 style="font-size:19px;margin:0 0 4px">Alta del padrón de técnicos</h1>
    <p class="sub" style="margin:0 0 16px">
      Crea de una vez las cuentas de la gente que está trabajando hoy, con el
      mismo nombre de usuario que quedó en el padrón. A quien ya tenga cuenta no
      se lo toca.
    </p>

    <?php if ($error): ?>
      <div class="err"><?= $error ?></div>
    <?php endif; ?>

    <?php if ($creadas): ?>
      <div class="entregar" style="margin-bottom:18px">
        <h2 style="margin:0 0 4px;font-size:17px">
          <?= count($creadas) ?> contraseña<?= count($creadas) === 1 ? '' : 's' ?> —
          esta es la única vez que se ven
        </h2>
        <p style="margin:0 0 12px;font-size:13px">
          Imprime esta hoja o anótalas ahora. La base solo guarda el hash: si
          cierras esta página no hay forma de recuperarlas, y toca generar una
          nueva por persona. A cada uno el sistema le va a pedir cambiarla en su
          primer ingreso. <b>Entrégalas en persona, recortadas una por una.</b>
        </p>
        <div class="tabla-wrap">
          <table>
            <thead><tr><th>Nombre</th><th>Zona</th><th>Usuario</th><th>Contraseña</th></tr></thead>
            <tbody>
            <?php foreach ($creadas as $c): ?>
              <tr>
                <td><?= e($c['nombre']) ?></td>
                <td><?= e($c['zona'] ?? '—') ?></td>
                <td class="mono"><?= e($c['usuario']) ?></td>
                <td class="clave"><?= e($c['clave']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="noimprimir" style="margin:12px 0 0">
          <button class="btn" type="button" onclick="window.print()">Imprimir</button>
        </p>
      </div>
    <?php endif; ?>

    <?php if ($padron): ?>
      <p class="sub" style="margin:0 0 14px">
        Padrón <span class="mono"><?= e($padron['fuente']) ?></span>, generado el
        <?= e($padron['generado']) ?>.
        <b><?= count($pendientes) ?></b> por crear de <?= count($padron['filas']) ?>.
      </p>

      <?php if ($pendientes): ?>
        <form method="post" class="noimprimir" style="margin-bottom:18px"
              onsubmit="return confirm(<?= e(json_encode($confirmar, JSON_UNESCAPED_UNICODE)) ?>)">
          <input type="hidden" name="accion" value="crear">
          <button class="btn primary" type="submit" style="padding:10px 18px">
            Crear las <?= count($pendientes) ?> cuentas que faltan
          </button>
          <span class="derivado" style="display:block;margin-top:6px">
            Antes de pulsar: ten a mano dónde anotar las contraseñas.
          </span>
        </form>
      <?php elseif (!$creadas): ?>
        <p class="sub" style="margin:0 0 18px">
          No hay nada que crear: todos los del padrón ya tienen cuenta.
        </p>
      <?php endif; ?>

      <div class="tabla-wrap">
        <table>
          <thead><tr>
            <th>Nombre</th><th>Zona</th><th>Usuario</th><th>Rol</th><th>Estado</th>
          </tr></thead>
          <tbody>
          <?php foreach ($padron['filas'] as $f): ?>
            <tr class="<?= $f['motivo'] === null ? '' : 'salta' ?>">
              <td><?= e($f['nombre']) ?></td>
              <td><?= e($f['zona'] ?? '—') ?></td>
              <td class="mono"><?= e($f['usuario']) ?></td>
              <td><?= e($ROL[$f['rol']] ?? $f['rol']) ?></td>
              <td>
                <?php if ($f['motivo'] === null): ?>
                  <span class="chip">se va a crear</span>
                <?php else: ?>
                  <span class="sub">se salta: <?= e($f['motivo']) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <p class="sub noimprimir" style="margin-top:18px">
      Esta pantalla es para el arranque. Una vez creadas las cuentas, borra
      <span class="mono">alta_padron.php</span> y
      <span class="mono">nucleo/padron.json</span> del servidor: no hacen falta,
      y el archivo tiene los nombres de todo el personal. Las altas y bajas del
      día a día van por <a href="usuarios.php">Usuarios</a>.
    </p>
  </div>
</div>
</body>
</html>
