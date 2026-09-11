<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Auth.php';

/**
 * usuarios.php — Alta, baja y claves.
 *
 * DOS CONTROLES, LOS DOS EN EL SERVIDOR:
 *
 *   1. El PERMISO abre la pantalla (`usuarios.gestionar` o `usuarios.operativos`).
 *   2. El RANGO decide qué roles se pueden crear y a quién se puede tocar.
 *      Nadie crea un usuario de rango igual o superior al suyo. Sin esto, darle
 *      a la administradora la capacidad de crear usuarios le permitiría crear un
 *      SUPERADMIN y ascenderse a sí misma.
 *
 * Ninguno de los dos se apoya en esconder opciones: cada acción revalida antes
 * de escribir. Un desplegable recortado es comodidad, no seguridad.
 */

$u = Auth::exigir();
if (!Auth::puede('usuarios.gestionar') && !Auth::puede('usuarios.operativos')) {
    Auth::bitacora('DENEGADO', 'permiso', 'usuarios');
    http_response_code(403);
    exit('<p style="font-family:system-ui;padding:24px">No tienes permiso para esta sección.</p>');
}

$ZONAS = ['UIO', 'LARB', 'CNLJ', 'OTRA'];
$puedeCrear = Auth::rolesQuePuedeCrear();
$aviso = null; $error = null; $claveNueva = null;

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function claveTemporal(): string
{
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // sin I, O, 0, 1
    $out = [];
    for ($b = 0; $b < 4; $b++) {
        $s = '';
        for ($i = 0; $i < 4; $i++) { $s .= $abc[random_int(0, strlen($abc) - 1)]; }
        $out[] = $s;
    }
    return implode('-', $out);
}

/** Trae al usuario objetivo y corta si no se le puede tocar. */
function objetivo(int $id): array
{
    $o = Db::uno('SELECT * FROM usuarios WHERE usuario_id = ?', [$id]);
    if (!$o || !Auth::puedeGestionarA($o)) {
        Auth::bitacora('DENEGADO', 'usuario', (string) $id, 'fuera de su rango o alcance');
        http_response_code(403);
        exit('<p style="font-family:system-ui;padding:24px">No puedes gestionar a ese usuario.</p>');
    }
    return $o;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');

    if ($accion === 'crear') {
        $usuario = strtolower(trim((string) ($_POST['usuario'] ?? '')));
        $nombre  = trim((string) ($_POST['nombre'] ?? ''));
        $correo  = trim((string) ($_POST['correo'] ?? '')) ?: null;
        $rol     = (string) ($_POST['rol'] ?? '');
        $zona    = ($_POST['zona'] ?? '') ?: null;

        // Se revalida el rol contra lo que ESTE usuario puede crear. El
        // desplegable ya venía recortado, pero un POST se fabrica a mano.
        if (!in_array($rol, $puedeCrear, true)) {
            $error = 'No puedes crear un usuario con ese rol.';
        } elseif (!preg_match('/^[a-z0-9._-]{3,40}$/', $usuario)) {
            $error = 'El usuario admite minúsculas, números, punto, guion y guion bajo (3 a 40).';
        } elseif ($nombre === '') {
            $error = 'Falta el nombre completo.';
        } elseif (in_array($rol, ['JEFE_ZONA', 'TECNICO'], true) && !in_array($zona, $ZONAS, true)) {
            $error = "Un $rol necesita zona: es su alcance. Sin ella vería las tres.";
        } elseif (in_array($rol, ['SUPERADMIN', 'ADMIN'], true) && $zona !== null) {
            $error = "Un $rol no lleva zona: ve las tres.";
        } elseif (Db::uno('SELECT usuario_id FROM usuarios WHERE usuario = ?', [$usuario])) {
            $error = "Ya existe el usuario «$usuario».";
        } else {
            // Un ADMIN tampoco puede crear fuera de su alcance de zona.
            if (!Auth::alcanzaZona($zona)) {
                $error = 'No puedes crear usuarios de otra zona.';
            } else {
                $clave = claveTemporal();
                Db::ejecutar(
                    'INSERT INTO usuarios (usuario, nombre, correo, clave_hash, rol, zona,
                                           debe_cambiar_clave, creado_por)
                     VALUES (?,?,?,?,?,?,1,?)',
                    [$usuario, $nombre, $correo, password_hash($clave, PASSWORD_DEFAULT),
                     $rol, $zona, $u['usuario_id']]
                );
                Auth::bitacora('CREAR_USUARIO', 'usuario', $usuario, "rol=$rol zona=" . ($zona ?? '-'));
                $claveNueva = ['usuario' => $usuario, 'nombre' => $nombre, 'clave' => $clave];
            }
        }

    } elseif ($accion === 'clave') {
        $o = objetivo((int) ($_POST['id'] ?? 0));
        $clave = claveTemporal();
        Db::ejecutar(
            'UPDATE usuarios SET clave_hash = ?, debe_cambiar_clave = 1,
                    intentos_fallidos = 0, bloqueado_hasta = NULL, sesion_token = NULL
              WHERE usuario_id = ?',
            [password_hash($clave, PASSWORD_DEFAULT), $o['usuario_id']]
        );
        Auth::bitacora('REINICIAR_CLAVE', 'usuario', $o['usuario']);
        $claveNueva = ['usuario' => $o['usuario'], 'nombre' => $o['nombre'], 'clave' => $clave];

    } elseif ($accion === 'activar' || $accion === 'desactivar') {
        $o = objetivo((int) ($_POST['id'] ?? 0));
        $activo = $accion === 'activar' ? 1 : 0;
        // Nunca se borra: se desactiva. INDUSTEC tiene alta rotación y borrar la
        // fila rompería la trazabilidad de todo lo que esa persona hizo.
        Db::ejecutar(
            'UPDATE usuarios SET activo = ?, fecha_baja = ?, sesion_token = NULL
              WHERE usuario_id = ?',
            [$activo, $activo ? null : date('Y-m-d'), $o['usuario_id']]
        );
        Auth::bitacora($activo ? 'ACTIVAR_USUARIO' : 'DESACTIVAR_USUARIO', 'usuario', $o['usuario']);
        if (!$activo) {
            // Sus casos abiertos vuelven a repartir. Asignados a alguien que ya
            // no entra, no aparecían en ninguna bandeja ni en «Por repartir».
            $n = Db::ejecutar(
                "UPDATE casos_gestion SET asignado_a = NULL, asignado_por = NULL, asignado_en = NULL,
                        tecnico_auto = 0, estado = 'NUEVO'
                  WHERE asignado_a = ? AND estado = 'ASIGNADO'", [$o['usuario_id']]);
            if ($n > 0) {
                Auth::bitacora('LIBERAR_CASOS', 'usuario', $o['usuario'], "$n casos vuelven a repartir",
                               'ASIGNADO', 'NUEVO', ['casos' => $n]);
            }
        }
        $aviso = ($activo ? 'Se reactivó a ' : 'Se dio de baja a ') . $o['nombre']
               . '. Su historial queda intacto.';

    } elseif ($accion === 'cerrar_sesion') {
        $o = objetivo((int) ($_POST['id'] ?? 0));
        Db::ejecutar('UPDATE usuarios SET sesion_token = NULL WHERE usuario_id = ?', [$o['usuario_id']]);
        Auth::bitacora('CERRAR_SESION_AJENA', 'usuario', $o['usuario']);
        $aviso = 'Se cerró la sesión de ' . $o['nombre'] . '.';
    }
}

$zonaAlc = Auth::zonaAlcance();
$sql = 'SELECT *, TIMESTAMPDIFF(MINUTE, sesion_ultima, NOW()) AS inactivo
          FROM usuarios';
$cond = []; $par = [];
if ($zonaAlc !== null) { $cond[] = 'zona = ?'; $par[] = $zonaAlc; }
if ($cond) { $sql .= ' WHERE ' . implode(' AND ', $cond); }
$sql .= ' ORDER BY activo DESC, FIELD(rol,"SUPERADMIN","ADMIN","JEFE_ZONA","TECNICO"), nombre';

/* Solo se listan los usuarios que este usuario puede gestionar, más él mismo.
   Antes se veían todos: la administradora veía a los superadministradores con su
   último ingreso y si tenían la sesión abierta. No podía tocarlos, pero tampoco
   necesita esa información. Mínimo necesario. */
$todos = array_values(array_filter(
    Db::todos($sql, $par),
    fn($x) => (int) $x['usuario_id'] === (int) $u['usuario_id'] || Auth::puedeGestionarA($x)
));

$ROL = ['SUPERADMIN' => 'Superadministrador', 'ADMIN' => 'Administración',
        'JEFE_ZONA' => 'Jefe de zona', 'TECNICO' => 'Técnico'];

require_once __DIR__ . '/nucleo/Ui.php';

Ui::cabecera($u, 'usuarios.php', [], ['titulo' => 'Usuarios y permisos']);
?>
<div class="wrap ancho">
  <div class="card">
    <h1 style="font-size:19px;margin:0 0 4px">Usuarios y permisos</h1>
    <p class="sub" style="margin:0 0 16px">
      Cada persona entra con su propio usuario. Los usuarios no se borran: se dan
      de baja, para no romper la trazabilidad de lo que hicieron.
    </p>

    <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
    <?php if ($aviso): ?><div class="ok"><?= e($aviso) ?></div><?php endif; ?>

    <?php if ($claveNueva): ?>
      <div class="nota-regular" style="margin-bottom:16px">
        <b>Contraseña de <?= e($claveNueva['nombre']) ?> (<?= e($claveNueva['usuario']) ?>)</b>
        <div class="clave"><?= e($claveNueva['clave']) ?></div>
        <p style="margin:10px 0 0">
          <b>Anótala ahora y entrégasela en persona o por un canal aparte.</b>
          No se vuelve a mostrar: la base solo guarda su hash. El sistema le va a
          exigir cambiarla en el primer ingreso.
        </p>
      </div>
    <?php endif; ?>

    <?php if ($puedeCrear && is_file(__DIR__ . "/nucleo/padron.json")): ?>
      <?php /* Solo aparece mientras el padron este cargado en el servidor.
             Al terminar el arranque se borran los dos archivos y el aviso
             desaparece solo, sin tener que acordarse de quitar el enlace. */ ?>
      <div class="nota-regular" style="margin-bottom:16px">
        <b>Hay un padron de tecnicos cargado en el servidor.</b>
        <p style="margin:6px 0 10px">
          Puedes crear de una vez las cuentas de quienes estan trabajando hoy,
          con el mismo usuario que quedo en el padron. A quien ya tenga cuenta
          no se lo toca.
        </p>
        <a class="btn primary" href="alta_padron.php">Ver el padron y crear las cuentas</a>
      </div>
    <?php endif; ?>

    <?php if ($puedeCrear): ?>
      <h2>Crear un usuario</h2>
      <form method="post">
        <input type="hidden" name="accion" value="crear">
        <div class="grid g2">
          <div>
            <label for="nombre">Nombre y apellido</label>
            <input type="text" id="nombre" name="nombre" required>
          </div>
          <div>
            <label for="usuario">Usuario</label>
            <input type="text" id="usuario" name="usuario" required
                   autocapitalize="none" spellcheck="false"
                   placeholder="p. ej. kchimbo">
            <span class="derivado">Minúsculas, sin espacios ni tildes.</span>
          </div>
          <div>
            <label for="rol">Rol</label>
            <select id="rol" name="rol" required>
              <option value="">Elige el rol…</option>
              <?php foreach ($puedeCrear as $r): ?>
                <option value="<?= e($r) ?>"><?= e($ROL[$r] ?? $r) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="derivado">Solo aparecen los roles por debajo del tuyo.</span>
          </div>
          <div>
            <label for="zona">Zona</label>
            <select id="zona" name="zona">
              <option value="">Las tres (administración)</option>
              <?php foreach ($ZONAS as $z): ?>
                <?php if ($zonaAlc !== null && $z !== $zonaAlc) continue; ?>
                <option value="<?= e($z) ?>"><?= e($z) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="derivado">Obligatoria para técnicos y jefes de zona: es su alcance.</span>
          </div>
          <div style="grid-column:1/-1">
            <label for="correo">Correo (opcional)</label>
            <input type="email" id="correo" name="correo">
          </div>
        </div>
        <div class="row" style="margin-top:12px">
          <button class="btn primary" type="submit" style="padding:10px 18px">Crear</button>
        </div>
      </form>
    <?php endif; ?>

    <h2>Usuarios (<?= count($todos) ?>)</h2>
    <div class="tabla-wrap">
      <table>
        <thead><tr>
          <th>Nombre</th><th>Usuario</th><th>Rol</th><th>Alcance</th>
          <th>Sesión</th><th>Acciones</th>
        </tr></thead>
        <tbody>
        <?php foreach ($todos as $x): ?>
          <?php
          $gestionable = Auth::puedeGestionarA($x);
          $abierta = $x['sesion_token'] !== null && $x['inactivo'] !== null && (int) $x['inactivo'] < 120;
          ?>
          <tr class="<?= $x['activo'] ? '' : 'baja' ?>">
            <td>
              <b><?= e($x['nombre']) ?></b>
              <?php if (!$x['activo']): ?>
                <span class="chip">de baja<?= $x['fecha_baja'] ? ' ' . e($x['fecha_baja']) : '' ?></span>
              <?php elseif ($x['debe_cambiar_clave']): ?>
                <span class="chip">no ha entrado</span>
              <?php endif; ?>
            </td>
            <td style="font-family:ui-monospace,monospace"><?= e($x['usuario']) ?></td>
            <td><?= e($ROL[$x['rol']] ?? $x['rol']) ?></td>
            <td><?= $x['zona'] ? e($x['zona']) : 'Las tres' ?></td>
            <td>
              <?php if ($abierta): ?>
                <span class="chip" style="background:#dcfce7;color:#166534">abierta</span>
              <?php else: ?>
                <span class="sub"><?= $x['ultimo_ingreso'] ? e(substr((string) $x['ultimo_ingreso'], 0, 16)) : 'nunca' ?></span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ((int) $x['usuario_id'] === (int) $u['usuario_id']): ?>
                <span class="sub">eres tú</span>
              <?php elseif (!$gestionable): ?>
                <span class="sub">—</span>
              <?php else: ?>
                <div class="acciones">
                  <form method="post" data-confirma="<?= e('¿Generar una contraseña nueva para ' . $x['nombre'] . '? La actual deja de servir.') ?>" onsubmit="return confirm(this.dataset.confirma)">
                    <input type="hidden" name="accion" value="clave">
                    <input type="hidden" name="id" value="<?= (int) $x['usuario_id'] ?>">
                    <button class="btn" type="submit">Nueva clave</button>
                  </form>
                  <?php if ($abierta): ?>
                    <form method="post">
                      <input type="hidden" name="accion" value="cerrar_sesion">
                      <input type="hidden" name="id" value="<?= (int) $x['usuario_id'] ?>">
                      <button class="btn" type="submit">Cerrar sesión</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" data-confirma="<?= e($x['activo'] ? '¿Dar de baja a ' . $x['nombre'] . '? No podrá entrar, pero su historial queda intacto.' : '¿Reactivar a ' . $x['nombre'] . '?') ?>" onsubmit="return confirm(this.dataset.confirma)">
                    <input type="hidden" name="accion" value="<?= $x['activo'] ? 'desactivar' : 'activar' ?>">
                    <input type="hidden" name="id" value="<?= (int) $x['usuario_id'] ?>">
                    <button class="btn <?= $x['activo'] ? 'danger' : 'secondary' ?>" type="submit">
                      <?= $x['activo'] ? 'Dar de baja' : 'Reactivar' ?>
                    </button>
                  </form>
                </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <p class="sub" style="margin-top:16px">
      <?php if ($u['rol'] === 'SUPERADMIN'): ?>
        Como superadministrador puedes crear cualquier rol. Los demás solo pueden
        crear por debajo del suyo: así nadie se asciende a sí mismo.
      <?php else: ?>
        Puedes crear <?= e(implode(' y ', array_map(fn($r) => $ROL[$r] ?? $r, $puedeCrear))) ?>,
        y solo de tu zona. Los roles iguales o superiores al tuyo los crea un
        superadministrador.
      <?php endif; ?>
    </p>
  </div>
</div>
<?php Ui::pie(); ?>
