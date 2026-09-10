<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Casos.php';
require_once __DIR__ . '/pdf.php';        // solo para enlaceCompartido() y otValida()

/**
 * ordenes.php — Las órdenes emitidas, con su informe.
 *
 * QUE MUESTRA
 * Cada caso que tiene al menos una orden: cuál, de qué día, quién la firmó y en
 * qué quedó. Con el PDF a un clic, que es lo que hoy obliga a buscar en el
 * correo.
 *
 * EL ALCANCE, OTRA VEZ EN EL SERVIDOR
 * El técnico ve **sus** órdenes. El jefe de zona las de su zona. La
 * administración todas. Se resuelve con `Casos::enAlcance()`, la misma función
 * que usa el buzón: si algún día cambia la regla, cambia en un solo sitio.
 *
 * COMPARTIR EL INFORME
 * El administrador del local pide su copia y no tiene usuario en el sistema.
 * Para eso está el enlace firmado y con caducidad de `pdf.php`: se genera aquí,
 * se copia, y se manda por correo o por WhatsApp. Caduca solo en 24 horas.
 *
 * El botón de WhatsApp abre `wa.me` con el mensaje escrito. No manda nada por
 * su cuenta: manda **a quien lo pulsa** a su propio WhatsApp con el texto
 * listo. Enviar desde el servidor exigiría la API de negocio de WhatsApp, que
 * es de pago y hoy no está contratada.
 */

$u = Auth::exigir('ots.ver');
if ($u['debe_cambiar_clave']) { header('Location: clave.php'); exit; }

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

$gestion = Casos::gestion();
$catalogo = Casos::catalogo()['datos'] ?? [];
$aten = Casos::atenciones();
$mios = Casos::enAlcance($catalogo, $gestion);

// De lo que este usuario alcanza, solo lo que tiene alguna orden emitida.
$porAviso = [];
foreach ($mios as $c) { $porAviso[$c['aviso'] ?? ''] = $c; }

$filas = [];
foreach ($aten as $aviso => $a) {
    if (!isset($porAviso[$aviso])) { continue; }
    $c = $porAviso[$aviso];
    $g = $gestion[$aviso] ?? [];
    foreach ($a['ots'] ?? [] as $o) {
        $filas[] = [
            'ot'       => (string) ($o['ot'] ?? ''),
            'fecha'    => (string) ($o['fecha'] ?? ''),
            'estado'   => (string) ($o['estado_ot'] ?? ''),
            'personas' => $o['personas'] ?? [],
            'texto'    => (string) ($o['tecnico_texto'] ?? ''),
            'equipo'   => (string) ($o['equipo'] ?? ''),
            'aviso'    => (string) $aviso,
            'local'    => (string) ($c['local'] ?? ''),
            'local_n'  => (string) ($c['local_nombre'] ?? ''),
            'zona'     => (string) ($c['zona'] ?? ''),
            'caso'     => (string) ($c['caso'] ?? ''),
            'gestion'  => (string) ($g['estado'] ?? 'NUEVO'),
        ];
    }
}

$fTexto = trim((string) ($_GET['q'] ?? ''));
$fEstado = (string) ($_GET['est'] ?? '');
if ($fTexto !== '' || $fEstado !== '') {
    $filas = array_values(array_filter($filas, function ($f) use ($fTexto, $fEstado) {
        if ($fEstado !== '' && $f['estado'] !== $fEstado) { return false; }
        if ($fTexto === '') { return true; }
        $heno = mb_strtolower(implode(' ', [$f['ot'], $f['aviso'], $f['local'], $f['local_n'],
                                            $f['equipo'], $f['texto']]), 'UTF-8');
        return mb_strpos($heno, mb_strtolower($fTexto, 'UTF-8')) !== false;
    }));
}
usort($filas, fn($a, $b) => strcmp($b['fecha'], $a['fecha']));   // la más nueva arriba

$cerradas = count(array_filter($filas, fn($f) => $f['estado'] === 'Cerrada'));
$cfg = Db::config();
$secreto = (string) ($cfg['sync_secreto'] ?? '');

$ROL = ['SUPERADMIN' => 'Superadministrador', 'ADMIN' => 'Administración',
        'JEFE_ZONA' => 'Jefe de zona', 'TECNICO' => 'Técnico'];

require_once __DIR__ . '/nucleo/Ui.php';

Ui::cabecera($u, 'ordenes.php', [], ['titulo' => 'Órdenes emitidas']);
?>
<div class="wrap ancho">

  <div class="titulo entra">
    <h1>Órdenes emitidas</h1>
    <p class="sub">
      <?php if ($u['rol'] === 'TECNICO'): ?>
        Las órdenes que has atendido. Puedes abrir el informe o mandárselo al
        administrador del local.
      <?php else: ?>
        Las órdenes con informe recibido<?= Auth::zonaAlcance() ? ' en ' . e((string) Auth::zonaAlcance()) : '' ?>.
      <?php endif; ?>
    </p>
  </div>

    <?php if (!$filas && $fTexto === '' && $fEstado === ''): ?>
      <div class="nota-regular">
        <b>Todavía no hay órdenes que mostrarte.</b>
        <?php if ($u['rol'] === 'TECNICO'): ?>
          Aquí aparecen las que hayas atendido, en cuanto llegue su informe al
          buzón de la empresa.
        <?php else: ?>
          Los informes se leen del correo cada tres horas.
        <?php endif; ?>
      </div>
    <?php else: ?>

      <div class="tiles">
        <div class="tile"><div class="n"><?= count($filas) ?></div><div class="t">Órdenes</div></div>
        <div class="tile"><div class="n"><?= $cerradas ?></div><div class="t">Con cierre</div></div>
        <div class="tile"><div class="n"><?= count($filas) - $cerradas ?></div><div class="t">En curso</div></div>
      </div>

      <form class="filtros" method="get">
        <div class="campo">
          <label for="f-est">Estado</label>
          <select id="f-est" name="est">
            <option value="">Todas</option>
            <option value="Cerrada" <?= $fEstado === 'Cerrada' ? 'selected' : '' ?>>Con cierre</option>
            <option value="Abierta" <?= $fEstado === 'Abierta' ? 'selected' : '' ?>>En curso</option>
          </select>
        </div>
        <div class="campo" style="flex:1;min-width:200px">
          <label for="f-q">Buscar</label>
          <input type="text" id="f-q" name="q" value="<?= e($fTexto) ?>"
                 placeholder="orden, aviso, local o equipo">
        </div>
        <div class="campo">
          <label>&nbsp;</label>
          <button class="btn primary" type="submit" style="height:38px">Filtrar</button>
        </div>
        <?php if ($fTexto !== '' || $fEstado !== ''): ?>
          <div class="campo"><label>&nbsp;</label>
            <a class="btn" href="ordenes.php" style="height:38px;display:flex;align-items:center">Limpiar</a>
          </div>
        <?php endif; ?>
      </form>

      <div class="tabla-wrap">
        <table>
          <thead><tr>
            <th>Orden</th><th>Local</th><th>Fecha</th><th>Quién la firmó</th>
            <th>Estado</th><th>Informe</th>
          </tr></thead>
          <tbody>
          <?php if (!$filas): ?>
            <tr><td colspan="6" class="vacio">Nada con esos filtros. <a href="ordenes.php">Ver todas</a>.</td></tr>
          <?php endif; ?>
          <?php foreach ($filas as $f): ?>
            <tr>
              <td>
                <span class="mono"><?= e($f['ot']) ?></span>
                <span class="desc">aviso <?= e($f['aviso']) ?></span>
              </td>
              <td>
                <b><?= e($f['local']) ?></b>
                <span class="desc"><?= e($f['local_n']) ?></span>
                <span class="desc"><?= Ui::zona($f['zona']) ?></span>
                <span class="desc"><?= e($f['caso']) ?></span>
              </td>
              <td class="mono"><?= e(substr($f['fecha'], 0, 10)) ?></td>
              <td>
                <?php foreach ($f['personas'] as $p): ?>
                  <?php /* Tres situaciones distintas, y confundirlas seria decir
                           que no sabemos quien firmo cuando si lo sabemos:
                             activo true  -> trabaja hoy, tiene usuario
                             activo false -> es del padron, pero ya salio
                             activo null  -> la firma no calzo con nadie */ ?>
                  <div>
                    <?= e($p['nombre']) ?>
                    <?php if ($p['activo'] === null): ?>
                      <span class="chip">firma sin identificar</span>
                    <?php elseif ($p['activo'] === false): ?>
                      <span class="chip">ya no trabaja aquí</span>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
                <?php if ($f['equipo']): ?><span class="desc"><?= e($f['equipo']) ?></span><?php endif; ?>
              </td>
              <td>
                <span class="chip <?= $f['estado'] === 'Cerrada' ? 'cerrada' : 'abierta' ?>">
                  <?= $f['estado'] === 'Cerrada' ? 'con cierre' : 'en curso' ?>
                </span>
                <span class="desc"><?= Ui::estado($f['gestion']) ?></span>
              </td>
              <td>
                <div class="acc">
                  <a class="btn primary" href="pdf.php?ot=<?= rawurlencode($f['ot']) ?>"
                     target="_blank" rel="noopener">Ver PDF</a>
                  <?php if ($secreto !== ''): ?>
                    <button class="btn" type="button"
                            data-ot="<?= e($f['ot']) ?>"
                            data-enlace="<?= e(enlaceCompartido($f['ot'], $secreto)) ?>"
                            onclick="compartir(this)">Compartir</button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
</div>

<dialog id="dlg">
  <h2 style="margin:0 0 4px;font-size:17px">Compartir el informe</h2>
  <p class="sub" style="margin:0 0 10px">
    Enlace de <b id="dlg-ot" class="mono"></b>. <b>Caduca en 24 horas</b> y deja
    registrado quién lo abrió. Va con la firma del administrador del local:
    mándaselo solo a quien corresponde.
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
function compartir(b) {
  var url = location.origin + location.pathname.replace(/ordenes\.php$/, '') + b.dataset.enlace;
  var ot  = b.dataset.ot;
  document.getElementById('dlg-ot').textContent = ot;
  document.getElementById('dlg-url').textContent = url;
  document.getElementById('dlg-copiado').hidden = true;
  var texto = 'Informe de la orden ' + ot + ' de INDUSTEC: ' + url
            + ' (el enlace caduca en 24 horas)';
  document.getElementById('dlg-wa').href = 'https://wa.me/?text=' + encodeURIComponent(texto);
  document.getElementById('dlg-mail').href =
      'mailto:?subject=' + encodeURIComponent('Informe de la orden ' + ot)
    + '&body=' + encodeURIComponent(texto);
  document.getElementById('dlg').showModal();
}
function copiar() {
  var t = document.getElementById('dlg-url').textContent;
  navigator.clipboard.writeText(t).then(function () {
    document.getElementById('dlg-copiado').hidden = false;
  }).catch(function () {
    /* Sin permiso de portapapeles queda el texto seleccionable a mano: el
       recuadro tiene user-select:all, asi que un clic lo selecciona entero. */
    document.getElementById('dlg-url').focus();
  });
}
</script>

<?php Ui::pie(); ?>
