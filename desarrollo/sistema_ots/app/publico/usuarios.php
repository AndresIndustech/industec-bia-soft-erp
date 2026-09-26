<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Auth.php';
require_once __DIR__ . '/nucleo/Ui.php';
require_once __DIR__ . '/nucleo/Vocabulario.php';   // los mensajes que nombran órdenes y su estado

/**
 * usuarios.php — Alta, baja, edición y claves.
 *
 * DOS CONTROLES, LOS DOS EN EL SERVIDOR:
 *
 *   1. El PERMISO abre la pantalla (`usuarios.gestionar` o `usuarios.operativos`).
 *   2. El RANGO decide qué roles se pueden crear y a quién se puede tocar.
 *      Nadie crea ni asciende a un usuario de rango igual o superior al suyo.
 *      Sin esto, darle a la administradora la capacidad de crear usuarios le
 *      permitiría crear un SUPERADMIN y ascenderse a sí misma.
 *
 * Ninguno de los dos se apoya en esconder opciones: cada acción revalida antes
 * de escribir. Un desplegable recortado es comodidad, no seguridad.
 *
 * POST -> REDIRECCIÓN -> GET (TR-17). Hasta el 2026-09-13 recargar la página
 * después de «Nueva clave» volvía a generar y reemplazar la contraseña. Ahora
 * cada acción termina en una redirección y el resultado viaja en el flash: la
 * contraseña temporal se muestra UNA vez y no vuelve a aparecer.
 *
 * LA LISTA SE AGRUPA POR ZONA para la administración (TR-18): con 23 personas
 * de tres zonas en una sola tabla, encontrar al técnico nuevo de LARB era
 * recorrerla entera.
 */

$u = Auth::exigir();
if (!Auth::puede('usuarios.gestionar') && !Auth::puede('usuarios.operativos')) {
    Auth::bitacora('DENEGADO', 'permiso', 'usuarios', null, null, null, [], false);
    http_response_code(403);
    exit('<p style="font-family:system-ui;padding:24px">No tienes permiso para esta sección.</p>');
}

$ZONAS = ['UIO', 'LARB', 'CNLJ', 'OTRA'];
$ROL = ['SUPERADMIN' => 'Superadministrador', 'ADMIN' => 'Administración',
        'JEFE_ZONA' => 'Jefe de zona', 'TECNICO' => 'Técnico'];
$puedeCrear = Auth::rolesQuePuedeCrear();

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
        Auth::bitacora('DENEGADO', 'usuario', (string) $id, 'fuera de su rango o alcance',
                       null, null, [], false);
        http_response_code(403);
        exit('<p style="font-family:system-ui;padding:24px">No puedes gestionar a ese usuario.</p>');
    }
    return $o;
}

/** Termina la acción: el resultado va al flash y la página se vuelve a pedir por GET. */
function terminar(?string $ok, ?string $error, ?array $clave = null, string $ancla = ''): void
{
    $_SESSION['flash'] = array_filter(['ok' => $ok, 'error' => $error, 'clave' => $clave]);
    header('Location: usuarios.php' . $ancla);
    exit;
}

/** Las mismas reglas de rol y zona para el alta y para la edición. */
function validarRolZona(string $rol, ?string $zona, array $puedeCrear, array $ZONAS): ?string
{
    if (!in_array($rol, $puedeCrear, true)) { return 'No puedes dar ese rol: está en tu rango o por encima.'; }
    if (in_array($rol, ['JEFE_ZONA', 'TECNICO'], true) && !in_array($zona, $ZONAS, true)) {
        return "Un $rol necesita zona: es su alcance. Sin ella vería las tres.";
    }
    if (in_array($rol, ['SUPERADMIN', 'ADMIN'], true) && $zona !== null) {
        return "Un $rol no lleva zona: ve las tres.";
    }
    if (!Auth::alcanzaZona($zona)) { return 'No puedes gestionar usuarios de otra zona.'; }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::exigirCsrf();
    $accion = (string) ($_POST['accion'] ?? '');

    if ($accion === 'crear') {
        $usuario = strtolower(trim((string) ($_POST['usuario'] ?? '')));
        $nombre  = trim((string) ($_POST['nombre'] ?? ''));
        $correo  = trim((string) ($_POST['correo'] ?? '')) ?: null;
        $rol     = (string) ($_POST['rol'] ?? '');
        $zona    = ($_POST['zona'] ?? '') !== '' ? strtoupper((string) $_POST['zona']) : null;

        // Se revalida el rol contra lo que ESTE usuario puede crear. El
        // desplegable ya venía recortado, pero un POST se fabrica a mano.
        $error = validarRolZona($rol, $zona, $puedeCrear, $ZONAS);
        if ($error === null && !preg_match('/^[a-z0-9._-]{3,40}$/', $usuario)) {
            $error = 'El usuario admite minúsculas, números, punto, guion y guion bajo (3 a 40).';
        } elseif ($error === null && $nombre === '') {
            $error = 'Falta el nombre completo.';
        } elseif ($error === null && $correo !== null && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $error = 'Ese correo no tiene forma de correo.';
        } elseif ($error === null && Db::uno('SELECT usuario_id FROM usuarios WHERE usuario = ?', [$usuario])) {
            $error = "Ya existe el usuario «$usuario».";
        }
        if ($error !== null) { terminar(null, $error); }

        $clave = claveTemporal();
        Db::ejecutar(
            'INSERT INTO usuarios (usuario, nombre, correo, clave_hash, rol, zona,
                                   debe_cambiar_clave, creado_por)
             VALUES (?,?,?,?,?,?,1,?)',
            [$usuario, $nombre, $correo, password_hash($clave, PASSWORD_DEFAULT),
             $rol, $zona, $u['usuario_id']]
        );
        Auth::bitacora('CREAR_USUARIO', 'usuario', $usuario, "rol=$rol zona=" . ($zona ?? '-'));
        terminar('Usuario ' . $usuario . ' creado.', null,
                 ['usuario' => $usuario, 'nombre' => $nombre, 'clave' => $clave]);

    } elseif ($accion === 'editar') {
        // TR-19: nombre, correo, zona y rol de un usuario existente, con las
        // mismas reglas del alta y el antes/después en la bitácora.
        $o = objetivo((int) ($_POST['id'] ?? 0));
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $correo = trim((string) ($_POST['correo'] ?? '')) ?: null;
        $rol    = (string) ($_POST['rol'] ?? $o['rol']);
        $zona   = ($_POST['zona'] ?? '') !== '' ? strtoupper((string) $_POST['zona']) : null;

        if ($nombre === '') { terminar(null, 'Falta el nombre completo.'); }
        if ($correo !== null && !filter_var($correo, FILTER_VALIDATE_EMAIL)) { terminar(null, 'Ese correo no tiene forma de correo.'); }
        // Cambiar de rol exige que el nuevo esté en el rango de quien edita;
        // conservar el rol que ya tenía no.
        $puedeRol = $rol === $o['rol'] ? array_unique(array_merge($puedeCrear, [$rol])) : $puedeCrear;
        $error = validarRolZona($rol, $zona, $puedeRol, $ZONAS);
        if ($error !== null) { terminar(null, $error); }

        $antes = ['nombre' => $o['nombre'], 'correo' => $o['correo'], 'rol' => $o['rol'], 'zona' => $o['zona']];
        $desp  = ['nombre' => $nombre, 'correo' => $correo, 'rol' => $rol, 'zona' => $zona];
        $cambios = array_keys(array_filter($desp, static fn($v, $k) => $v !== $antes[$k], ARRAY_FILTER_USE_BOTH));
        if (!$cambios) { terminar('No había nada que cambiar en ' . $o['nombre'] . '.', null, null, '#u' . (int) $o['usuario_id']); }

        Db::ejecutar('UPDATE usuarios SET nombre = ?, correo = ?, rol = ?, zona = ? WHERE usuario_id = ?',
                     [$nombre, $correo, $rol, $zona, $o['usuario_id']]);
        if (in_array('rol', $cambios, true) || in_array('zona', $cambios, true)) {
            // Con otro alcance, la sesión abierta seguiría viendo lo de antes
            // hasta que expire: se cierra y vuelve a entrar con el nuevo.
            Db::ejecutar('UPDATE usuarios SET sesion_token = NULL WHERE usuario_id = ?', [$o['usuario_id']]);
        }
        // Las columnas estado_antes/despues son cortas (20): llevan el resumen de
        // lo que cambió; el antes y el después completos van en `datos`.
        $resumen = static fn(array $x): string => mb_substr(implode(' · ', array_map(
            static fn($k) => $k . '=' . (string) ($x[$k] ?? '-'), $cambios)), 0, 20);
        Auth::bitacora('EDITAR_USUARIO', 'usuario', $o['usuario'], 'cambió ' . implode(', ', $cambios),
                       $resumen($antes), $resumen($desp),
                       ['antes' => $antes, 'despues' => $desp, 'cambios' => $cambios]);

        $aviso = 'Se guardaron los cambios de ' . $nombre . '.';
        if (in_array('zona', $cambios, true) && $o['rol'] === 'TECNICO') {
            // Decisión pendiente de Andrés (T2.14.4): sus casos asignados en la
            // zona anterior se quedan como están; se dice cuántos son para que
            // alguien los reparta a mano desde la asignación.
            $n = (int) (Db::uno("SELECT COUNT(*) n FROM casos_gestion WHERE asignado_a = ? AND estado IN ('ASIGNADO','ESPERA_REPUESTO')",
                                [$o['usuario_id']])['n'] ?? 0);
            if ($n > 0) {
                // Cuenta ASIGNADO y ESPERA_REPUESTO: se dicen los dos, con el diccionario.
                $aviso .= " Tiene $n " . Vocabulario::t('ORDEN', $n) . ' en sus manos ('
                        . Vocabulario::t('ASIGNADA', 2) . ' o ' . Vocabulario::t('ESPERA_REPUESTO')
                        . ') en la zona anterior: siguen a su nombre hasta que las reasignes desde Asignar.';
            }
        }
        terminar($aviso, null, null, '#u' . (int) $o['usuario_id']);

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
        terminar('Clave nueva para ' . $o['nombre'] . '.', null,
                 ['usuario' => $o['usuario'], 'nombre' => $o['nombre'], 'clave' => $clave]);

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
        $aviso = ($activo ? 'Se reactivó a ' : 'Se dio de baja a ') . $o['nombre'] . '. Su historial queda intacto.';
        if (!$activo) {
            // Sus casos abiertos vuelven a repartir. Asignados a alguien que ya
            // no entra, no aparecían en ninguna bandeja ni en «Por repartir».
            $n = Db::ejecutar(
                "UPDATE casos_gestion SET asignado_a = NULL, asignado_por = NULL, asignado_en = NULL,
                        tecnico_auto = 0, estado = 'NUEVO'
                  WHERE asignado_a = ? AND estado = 'ASIGNADO'", [$o['usuario_id']]);
            if ($n > 0) {
                Auth::bitacora('LIBERAR_CASOS', 'usuario', $o['usuario'],
                               "$n " . Vocabulario::t('ORDEN', $n) . ' queda' . ($n === 1 ? '' : 'n') . ' ' . Vocabulario::t('SIN_ASIGNAR'),
                               'ASIGNADO', 'NUEVO', ['casos' => $n]);
                $aviso .= " $n " . Vocabulario::t('ORDEN', $n) . ' vuelve' . ($n === 1 ? '' : 'n')
                        . ' a quedar ' . Vocabulario::t('SIN_ASIGNAR') . '.';
            }
        }
        terminar($aviso, null, null, '#u' . (int) $o['usuario_id']);

    } elseif ($accion === 'cerrar_sesion') {
        $o = objetivo((int) ($_POST['id'] ?? 0));
        Db::ejecutar('UPDATE usuarios SET sesion_token = NULL WHERE usuario_id = ?', [$o['usuario_id']]);
        Auth::bitacora('CERRAR_SESION_AJENA', 'usuario', $o['usuario']);
        terminar('Se cerró la sesión de ' . $o['nombre'] . '.', null, null, '#u' . (int) $o['usuario_id']);
    }
    terminar(null, 'Acción no reconocida.');
}

$errFlash   = Ui::errorFlash();
$claveNueva = $_SESSION['flash']['clave'] ?? null;
unset($_SESSION['flash']['clave']);

$zonaAlc = Auth::zonaAlcance();
$sql = 'SELECT *, TIMESTAMPDIFF(MINUTE, sesion_ultima, NOW()) AS inactivo FROM usuarios';
$par = [];
if ($zonaAlc !== null) { $sql .= ' WHERE zona = ?'; $par[] = $zonaAlc; }
// TR-18: la administración ve primero a los de las tres zonas y después una
// zona tras otra; dentro de cada una, activos primero y por rango.
$sql .= ' ORDER BY FIELD(COALESCE(zona, ""), "", "UIO", "LARB", "CNLJ", "OTRA"), activo DESC,
                   FIELD(rol, "SUPERADMIN", "ADMIN", "JEFE_ZONA", "TECNICO"), nombre';

/* Solo se listan los usuarios que este usuario puede gestionar, más él mismo.
   Antes se veían todos: la administradora veía a los superadministradores con su
   último ingreso y si tenían la sesión abierta. No podía tocarlos, pero tampoco
   necesita esa información. Mínimo necesario. */
$todos = array_values(array_filter(
    Db::todos($sql, $par),
    fn($x) => (int) $x['usuario_id'] === (int) $u['usuario_id'] || Auth::puedeGestionarA($x)
));
$grupos = [];
foreach ($todos as $x) { $grupos[(string) ($x['zona'] ?? '')][] = $x; }

$puedeBitacora = Ui::puedeModulo('bitacora.ver', ['SUPERADMIN', 'ADMIN'], $u);
$csrf = Auth::csrfToken();

Ui::cabecera($u, 'usuarios.php', [], ['titulo' => 'Usuarios y permisos']);
?>
<div class="wrap ancho">
  <div class="card">
    <h1 style="font-size:19px;margin:0 0 4px">Usuarios y permisos</h1>
    <p class="sub" style="margin:0 0 16px">
      Cada persona entra con su propio usuario. Los usuarios no se borran: se dan
      de baja, para no romper la trazabilidad de lo que hicieron.
    </p>

    <?php if ($errFlash): ?><?= Ui::aviso('err', e($errFlash), true) ?><?php endif; ?>

    <?php if ($claveNueva): ?>
      <div class="nota-regular" style="margin-bottom:16px">
        <b>Contraseña de <?= e($claveNueva['nombre']) ?> (<?= e($claveNueva['usuario']) ?>)</b>
        <div class="clave"><?= e($claveNueva['clave']) ?></div>
        <p style="margin:10px 0 0">
          <b>Anótala ahora y entrégasela en persona o por un canal aparte.</b>
          No se vuelve a mostrar: la base solo guarda su hash, y al recargar esta
          página desaparece. El sistema le va a exigir cambiarla en el primer ingreso.
        </p>
      </div>
    <?php endif; ?>

    <?php if ($puedeCrear && is_file(__DIR__ . "/nucleo/padron.json")): ?>
      <?php /* Solo aparece mientras el padrón esté cargado en el servidor.
             Al terminar el arranque se borran los dos archivos y el aviso
             desaparece solo, sin tener que acordarse de quitar el enlace. */ ?>
      <div class="nota-regular" style="margin-bottom:16px">
        <b>Hay un padrón de técnicos cargado en el servidor.</b>
        <p style="margin:6px 0 10px">
          Puedes crear de una vez las cuentas de quienes están trabajando hoy,
          con el mismo usuario que quedó en el padrón. A quien ya tenga cuenta
          no se lo toca.
        </p>
        <a class="btn primary" href="alta_padron.php">Ver el padrón y crear las cuentas</a>
      </div>
    <?php endif; ?>

    <?php if ($puedeCrear): ?>
      <details class="crear-usuario" <?= $errFlash ? 'open' : '' ?>>
        <summary><h2>Crear un usuario</h2></summary>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="accion" value="crear">
          <div class="grid g2">
            <div>
              <label for="nombre">Nombre y apellido</label>
              <input type="text" id="nombre" name="nombre" required>
            </div>
            <div>
              <label for="usuario">Usuario</label>
              <input type="text" id="usuario" name="usuario" required
                     autocapitalize="none" spellcheck="false" placeholder="p. ej. kchimbo">
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
      </details>
    <?php endif; ?>

    <h2>Usuarios (<?= count($todos) ?>)</h2>
    <div class="tabla-wrap">
      <table class="tarjetas usuarios">
        <thead><tr>
          <th>Nombre</th><th>Usuario</th><th>Rol</th><th>Alcance</th>
          <th>Sesión</th><th>Acciones</th>
        </tr></thead>
        <?php foreach ($grupos as $z => $lista): ?>
          <?php $activos = count(array_filter($lista, fn($x) => (int) $x['activo'] === 1)); ?>
          <tbody class="grupo-zona">
            <?php if (count($grupos) > 1): ?>
              <tr class="grupo"><th colspan="6">
                <?= $z === '' ? 'Las tres zonas' : Ui::zona($z) ?>
                <span class="cuenta"><?= $activos ?> activo<?= $activos === 1 ? '' : 's' ?><?= count($lista) > $activos ? ' · ' . (count($lista) - $activos) . ' de baja' : '' ?></span>
              </th></tr>
            <?php endif; ?>
            <?php foreach ($lista as $x): ?>
              <?php
              $gestionable = Auth::puedeGestionarA($x);
              $abierta = $x['sesion_token'] !== null && $x['inactivo'] !== null && (int) $x['inactivo'] < 120;
              $rolesEdicion = array_values(array_unique(array_merge($puedeCrear, [$x['rol']])));
              ?>
              <tr class="<?= $x['activo'] ? '' : 'baja' ?>" id="u<?= (int) $x['usuario_id'] ?>">
                <td data-th="Nombre">
                  <b><?= e($x['nombre']) ?></b>
                  <?php if (!$x['activo']): ?>
                    <span class="chip">de baja<?= $x['fecha_baja'] ? ' ' . e($x['fecha_baja']) : '' ?></span>
                  <?php elseif ($x['debe_cambiar_clave']): ?>
                    <span class="chip">no ha entrado</span>
                  <?php endif; ?>
                  <?php if (!empty($x['correo'])): ?><span class="desc"><?= e($x['correo']) ?></span><?php endif; ?>
                </td>
                <td data-th="Usuario" class="mono"><?= e($x['usuario']) ?></td>
                <td data-th="Rol"><?= e($ROL[$x['rol']] ?? $x['rol']) ?></td>
                <td data-th="Alcance"><?= $x['zona'] ? Ui::zona($x['zona']) : 'Las tres' ?></td>
                <td data-th="Sesión">
                  <?php if ($abierta): ?>
                    <span class="chip abierta">sesión abierta</span>
                  <?php else: ?>
                    <span class="sub"><?= $x['ultimo_ingreso'] ? e(substr((string) $x['ultimo_ingreso'], 0, 16)) : 'nunca' ?></span>
                  <?php endif; ?>
                </td>
                <td data-th="Acciones">
                  <?php if ((int) $x['usuario_id'] === (int) $u['usuario_id']): ?>
                    <span class="sub">eres tú</span>
                    <?php if ($puedeBitacora): ?> · <a href="bitacora.php?usuario=<?= rawurlencode($x['usuario']) ?>">tu actividad</a><?php endif; ?>
                  <?php elseif (!$gestionable): ?>
                    <span class="sub">—</span>
                  <?php else: ?>
                    <div class="acciones">
                      <button class="btn" type="button"
                              data-id="<?= (int) $x['usuario_id'] ?>" data-nombre="<?= e($x['nombre']) ?>"
                              data-correo="<?= e((string) $x['correo']) ?>" data-rol="<?= e($x['rol']) ?>"
                              data-zona="<?= e((string) $x['zona']) ?>" data-roles="<?= e(implode(',', $rolesEdicion)) ?>"
                              onclick="editar(this)">Editar</button>
                      <form method="post" data-confirma="<?= e('¿Generar una contraseña nueva para ' . $x['nombre'] . '? La actual deja de servir.') ?>" onsubmit="return confirm(this.dataset.confirma)">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="accion" value="clave">
                        <input type="hidden" name="id" value="<?= (int) $x['usuario_id'] ?>">
                        <button class="btn" type="submit">Nueva clave</button>
                      </form>
                      <?php if ($abierta): ?>
                        <form method="post">
                          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                          <input type="hidden" name="accion" value="cerrar_sesion">
                          <input type="hidden" name="id" value="<?= (int) $x['usuario_id'] ?>">
                          <button class="btn" type="submit">Cerrar sesión</button>
                        </form>
                      <?php endif; ?>
                      <?php if ($puedeBitacora): ?>
                        <a class="btn" href="bitacora.php?usuario=<?= rawurlencode($x['usuario']) ?>">Ver actividad</a>
                      <?php endif; ?>
                      <form method="post" data-confirma="<?= e($x['activo'] ? '¿Dar de baja a ' . $x['nombre'] . '? No podrá entrar, pero su historial queda intacto.' : '¿Reactivar a ' . $x['nombre'] . '?') ?>" onsubmit="return confirm(this.dataset.confirma)">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
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
        <?php endforeach; ?>
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

<dialog id="dlgEditar">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="accion" value="editar">
    <input type="hidden" name="id" id="ed-id">
    <h2>Editar usuario</h2>
    <p class="sub" style="margin:0 0 12px">
      Nombre, correo, rol y zona. Cambiar el rol o la zona cierra su sesión:
      vuelve a entrar con el alcance nuevo. Todo queda en la bitácora con el
      antes y el después.
    </p>
    <div class="grid g2">
      <div>
        <label for="ed-nombre">Nombre y apellido</label>
        <input type="text" id="ed-nombre" name="nombre" required>
      </div>
      <div>
        <label for="ed-correo">Correo</label>
        <input type="email" id="ed-correo" name="correo">
      </div>
      <div>
        <label for="ed-rol">Rol</label>
        <select id="ed-rol" name="rol" required></select>
      </div>
      <div>
        <label for="ed-zona">Zona</label>
        <select id="ed-zona" name="zona">
          <option value="">Las tres (administración)</option>
          <?php foreach ($ZONAS as $z): ?>
            <?php if ($zonaAlc !== null && $z !== $zonaAlc) continue; ?>
            <option value="<?= e($z) ?>"><?= e($z) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="row" style="margin-top:14px;gap:8px">
      <button class="btn primary" type="submit">Guardar</button>
      <button class="btn" type="button" onclick="this.closest('dialog').close()">Cancelar</button>
    </div>
  </form>
</dialog>

<script>
var ROL = <?= json_encode($ROL, JSON_UNESCAPED_UNICODE) ?>;
function editar(b) {
  document.getElementById('ed-id').value = b.dataset.id;
  document.getElementById('ed-nombre').value = b.dataset.nombre;
  document.getElementById('ed-correo').value = b.dataset.correo;
  var s = document.getElementById('ed-rol');
  s.innerHTML = '';
  (b.dataset.roles || '').split(',').forEach(function (r) {
    if (!r) { return; }
    var o = document.createElement('option');
    o.value = r; o.textContent = ROL[r] || r; o.selected = (r === b.dataset.rol);
    s.appendChild(o);
  });
  document.getElementById('ed-zona').value = b.dataset.zona || '';
  document.getElementById('dlgEditar').showModal();
}
</script>
<?php Ui::pie(); ?>
