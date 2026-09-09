<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Auth.php';

/**
 * casos.php — Buzón de casos: lo que KFC pide por el correo de SAP.
 *
 * QUE ES ESTA PANTALLA
 * El correo `servicioalcliente@industec.me` recibe los avisos de SAP.
 * `t2_6_imap_avisos.py` lo lee —SOLO lee, no marca nada— y deja los casos
 * vigentes en `casos_sap.json`. Esta página los muestra a quien corresponde,
 * para que la administradora y los jefes de zona decidan a quién se le asigna.
 *
 * EN ESTA FASE NO SE ASIGNA NI SE DECIDE NADA. Se muestran los pendientes, y
 * los botones de la fase siguiente aparecen apagados, con lo que van a hacer
 * escrito al lado. Un botón que no hace nada pero parece que sí es peor que no
 * tenerlo: alguien lo pulsa, cree que asignó y el técnico nunca se entera.
 *
 * EL ALCANCE POR ZONA SE FILTRA AQUI, EN EL SERVIDOR.
 * Un jefe de zona no ve las otras dos zonas, y no porque se le escondan las
 * filas al dibujar: nunca salen de `casosEnAlcance()`. Es la regla que fijó
 * Andrés el 2026-09-08: la administradora ve las tres, cada jefe la suya.
 *
 * LAS ALERTAS NO SON VEREDICTOS.
 * `estado_alerta` marca casos sospechosos —trabajo que INDUSTEC no hace, local
 * fuera del contrato— para que se encuentren rápido. Quien decide es la
 * administradora. La pantalla lo dice con todas sus letras, porque una alerta
 * que se lee como una orden termina en un caso rechazado que sí nos tocaba.
 *
 * LO QUE ESTE BUZON NO SABE, Y HAY QUE DECIRLO.
 * El correo avisa cuando KFC **crea** o **elimina** un caso. NO avisa cuando lo
 * **cierra**. Así que un caso puede figurar aquí como pendiente y estar cerrado
 * en SAP hace días. Por eso arriba va la fecha del último barrido y el aviso.
 * El estado que manda es `avisos_sap.estatus_general` del export de SAP, no
 * esta lista.
 *
 * DE DONDE SALEN LOS DATOS.
 * Del JSON, igual que `catalogos.php` y `cronograma.php`. Cuando la fase
 * siguiente tenga que **guardar** una asignación, esto pasa a una tabla `casos`
 * en `industec_app`: la lectura está aislada en `cargarCasos()` a propósito,
 * para que el cambio sea un solo lugar y la pantalla no se entere.
 */

$u = Auth::exigir('casos.ver');
if ($u['debe_cambiar_clave']) { header('Location: clave.php'); exit; }

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/** Único punto que toca la fuente de datos. Al pasar a SQL, se cambia aquí. */
function cargarCasos(): array
{
    $candidatos = [
        __DIR__ . '/catalogos/casos_sap.json',
        __DIR__ . '/../../../../SALIDAS IA/OTS/catalogos/casos_sap.json',
    ];
    foreach ($candidatos as $c) {
        if (is_file($c)) {
            $j = json_decode((string) file_get_contents($c), true);
            if (is_array($j) && isset($j['datos'])) { return $j; }
        }
    }
    return [];
}

/**
 * Recorta a lo que esta persona puede ver. Se aplica ANTES de contar, filtrar
 * y dibujar: los totales de un jefe de zona son los de su zona, no los de la
 * empresa con la tabla recortada después.
 */
function casosEnAlcance(array $casos, ?string $zonaAlcance, string $rol): array
{
    /* El técnico ve LO SUYO, no lo de su zona. Se detectó probando: un técnico
       de LARB veía los 300 casos de la zona, con el nombre y el pedido de cada
       usuario de KFC que los abrió. `casos.ver` le sirve para consultar SUS
       órdenes, no el buzón entero. Como todavía no existe la asignación, hoy
       esto da vacío a propósito y la pantalla explica por qué. Cuando la fase
       siguiente escriba `asignado_a`, el filtro pasa a ser por ese campo. */
    if ($rol === 'TECNICO') {
        return array_values(array_filter($casos, fn($c) => !empty($c['asignado_a'])));
    }
    if ($zonaAlcance === null) { return $casos; }   // administración: las tres
    return array_values(array_filter($casos, fn($c) => ($c['zona'] ?? null) === $zonaAlcance));
}

$fuente = cargarCasos();
$zonaAlc = Auth::zonaAlcance();
$todos = casosEnAlcance($fuente['datos'] ?? [], $zonaAlc, (string) $u['rol']);

Auth::bitacora('CONSULTAR', 'buzon', 'casos', 'alcance=' . ($zonaAlc ?? 'todas')
             . ' visibles=' . count($todos));

$hoy = date('Y-m-d');

/* ---- Filtros de la barra. Todo se resuelve en el servidor. -------------- */
$fZona  = (string) ($_GET['zona'] ?? '');
$fAlert = (string) ($_GET['alerta'] ?? '');
$fPrio  = (string) ($_GET['prio'] ?? '');
$fTexto = trim((string) ($_GET['q'] ?? ''));
$fDias  = (string) ($_GET['dias'] ?? '');              // '' = toda la ventana
$fVence = isset($_GET['vencidos']);
$desdeF = $fDias !== '' ? date('Y-m-d', strtotime('-' . (int) $fDias . ' days')) : null;

$vistos = array_values(array_filter($todos, function ($c) use ($fZona, $fAlert, $fPrio, $fTexto, $fVence, $hoy, $desdeF) {
    if ($desdeF !== null && ($c['fecha_creacion'] ?? '') < $desdeF) { return false; }
    if ($fZona !== '' && ($c['zona'] ?? '') !== $fZona) { return false; }
    if ($fAlert !== '' && ($c['estado_alerta'] ?? '') !== $fAlert) { return false; }
    if ($fPrio !== '' && ($c['prioridad'] ?? '') !== $fPrio) { return false; }
    if ($fVence && !(($c['fecha_estimada'] ?? '') !== '' && $c['fecha_estimada'] < $hoy)) { return false; }
    if ($fTexto !== '') {
        $heno = mb_strtolower(implode(' ', [
            $c['aviso'] ?? '', $c['orden_trabajo'] ?? '', $c['local'] ?? '',
            $c['local_nombre'] ?? '', $c['restaurante_sap'] ?? '', $c['activo_fijo'] ?? '',
            $c['descripcion_trabajo'] ?? '', $c['caso'] ?? '',
        ]), 'UTF-8');
        if (mb_strpos($heno, mb_strtolower($fTexto, 'UTF-8')) === false) { return false; }
    }
    return true;
}));

/* Primero lo que tiene alerta, y dentro de eso LO MAS NUEVO.
   No se ordena por "vencido" porque 911 de 918 lo estan: como criterio no
   separa nada. Lo que hay que repartir es lo que acaba de llegar. */
$ordenAlerta = ['CON_ALERTA' => 0, 'POR_CONFIRMAR' => 1, 'SIN_ALERTA' => 2];
$ordenPrio   = ['ALTA' => 0, 'MEDIA' => 1, 'BAJA' => 2];
usort($vistos, function ($a, $b) use ($ordenAlerta, $ordenPrio) {
    $ka = [$ordenAlerta[$a['estado_alerta'] ?? ''] ?? 3, $ordenPrio[$a['prioridad'] ?? ''] ?? 3];
    $kb = [$ordenAlerta[$b['estado_alerta'] ?? ''] ?? 3, $ordenPrio[$b['prioridad'] ?? ''] ?? 3];
    if ($ka !== $kb) { return $ka <=> $kb; }
    return ($b['fecha_creacion'] ?? '') <=> ($a['fecha_creacion'] ?? '');   // mas nuevo arriba
});

/* ---- Contadores, siempre sobre el alcance completo, no sobre el filtro --- */
$nAlerta  = count(array_filter($todos, fn($c) => ($c['estado_alerta'] ?? '') === 'CON_ALERTA'));
$nVencido = count(array_filter($todos, fn($c) => ($c['fecha_estimada'] ?? '') !== '' && $c['fecha_estimada'] < $hoy));
$nHoy     = count(array_filter($todos, fn($c) => ($c['fecha_estimada'] ?? '') === $hoy));
/* Los recien llegados. Es el numero que de verdad sirve para repartir trabajo.
   El de "pasados de fecha" da 911 de 918 y NO es un atraso: SAP compromete casi
   siempre para el dia siguiente, la ventana del buzon es de 90 dias, y el correo
   no avisa cuando KFC cierra un caso. Puesto como cifra grande hacia leer una
   catastrofe que no existe, asi que se muestra abajo y con su advertencia. */
$desde7 = date('Y-m-d', strtotime('-7 days'));
$desde1 = date('Y-m-d', strtotime('-1 day'));
$nSemana = count(array_filter($todos, fn($c) => ($c['fecha_creacion'] ?? '') >= $desde7));
$nAyer   = count(array_filter($todos, fn($c) => ($c['fecha_creacion'] ?? '') >= $desde1));
$nSinZona = count(array_filter($todos, fn($c) => empty($c['zona'])));
$porZona  = [];
foreach ($todos as $c) { $z = $c['zona'] ?? '(sin zona)'; $porZona[$z] = ($porZona[$z] ?? 0) + 1; }
ksort($porZona);

$generado = (string) ($fuente['generado'] ?? '');
$diasDesde = $generado !== '' ? (int) floor((strtotime($hoy) - strtotime(substr($generado, 0, 10))) / 86400) : null;

/* Las acciones de la fase siguiente. Se dibujan apagadas y se dice qué harán. */
$ACCIONES = [
    ['Asignar técnico',   'casos.asignar',
     'Elegir de los técnicos vigentes de la zona. El caso le aparece al técnico en su lista de órdenes y se registra quién lo asignó.'],
    ['Marcar en revisión', 'casos.revision',
     'Lo manda a los pendientes de la administración, con el motivo. Es lo que usa el jefe de zona cuando ve algo que no nos compete.'],
    ['Dar veredicto',      'casos.veredicto',
     'Solo la administración. Resuelve si el caso nos compete o no, y queda el nombre y la fecha de quien lo resolvió.'],
    ['Derivar a otra zona', 'casos.derivar',
     'Para el caso que llegó al buzón equivocado. Hoy se detectan 2 con la zona en discrepancia.'],
];

$ROL = ['SUPERADMIN' => 'Superadministrador', 'ADMIN' => 'Administración',
        'JEFE_ZONA' => 'Jefe de zona', 'TECNICO' => 'Técnico'];
$ETIQ_ALERTA = ['CON_ALERTA' => 'con alerta', 'POR_CONFIRMAR' => 'por confirmar',
                'SIN_ALERTA' => 'sin alerta'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Buzón de casos · OTs INDUSTEC</title>
<link rel="stylesheet" href="estilo.css">
<style>
  .barra{ display:flex; justify-content:space-between; align-items:center; gap:12px;
          flex-wrap:wrap; padding:10px 14px; background:#fff;
          border-bottom:1px solid var(--border); position:sticky; top:0; z-index:40; }
  .tiles{ display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr));
          gap:10px; margin:0 0 16px; }
  .tile{ background:#fff; border:1px solid var(--border); border-radius:10px; padding:12px 14px; }
  .tile .n{ font-size:26px; font-weight:700; line-height:1.1; font-variant-numeric:tabular-nums; }
  .tile .t{ font-size:11.5px; color:var(--muted); text-transform:uppercase;
            letter-spacing:.05em; margin-top:2px; }
  .tile.alerta{ border-color:#fecaca; background:#fef2f2; } .tile.alerta .n{ color:#991b1b; }
  .tile.vence{ border-color:#fde68a; background:var(--warn-bg); } .tile.vence .n{ color:#92400e; }

  .filtros{ display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; margin-bottom:14px; }
  .filtros .campo{ display:flex; flex-direction:column; gap:3px; }
  .filtros label{ font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
  .filtros select, .filtros input[type=text]{ height:38px; min-width:130px; }

  table{ width:100%; border-collapse:collapse; font-size:13px; }
  th{ text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.05em;
      color:var(--muted); padding:8px 9px; border-bottom:1px solid var(--border);
      background:#fff; position:sticky; top:0; }
  td{ padding:9px; border-bottom:1px solid #f1f5f9; vertical-align:top; }
  tr.con-alerta{ background:#fffbfb; }
  tr.con-alerta td:first-child{ box-shadow:inset 3px 0 0 #ef4444; }
  .mono{ font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:12.5px; }
  .tabla-wrap{ overflow-x:auto; border:1px solid var(--border); border-radius:10px; background:#fff; }
  .vencido{ color:#991b1b; font-weight:700; }
  .alerta-txt{ font-size:11.5px; color:#991b1b; display:block; margin-top:3px; }
  .desc{ color:var(--muted); font-size:12px; display:block; margin-top:3px;
         max-width:42ch; overflow-wrap:anywhere; }
  .acciones-fila{ display:flex; gap:5px; flex-wrap:wrap; }
  .btn[disabled]{ opacity:.42; cursor:not-allowed; }
  .proximo{ border:1px dashed var(--border); border-radius:10px; padding:14px; background:#fff; }
  .proximo li{ margin-bottom:8px; }
  .vacio{ padding:28px 14px; text-align:center; color:var(--muted); }
</style>
</head>
<body>

<div class="barra">
  <strong><a href="panel.php" style="text-decoration:none;color:inherit">← Sistema de OTs</a></strong>
  <div style="display:flex;align-items:center;gap:10px;font-size:13px">
    <span style="font-weight:700"><?= e($u['nombre']) ?></span>
    <span class="chip"><?= e($ROL[$u['rol']] ?? $u['rol']) ?><?= $zonaAlc ? ' · ' . e($zonaAlc) : ' · las 3 zonas' ?></span>
    <a class="btn" href="salir.php">Salir</a>
  </div>
</div>

<div class="wrap">
  <div class="card">
    <h1 style="font-size:19px;margin:0 0 4px">Buzón de casos</h1>
    <p class="sub" style="margin:0 0 14px">
      Lo que Grupo KFC pide por el correo de SAP.
      <?php if ($u['rol'] === 'TECNICO'): ?>
        Ves los casos que te hayan asignado.
      <?php elseif ($zonaAlc): ?>
        Ves los de <b><?= e($zonaAlc) ?></b>, que es tu zona.
      <?php else: ?>
        Ves las tres zonas.
      <?php endif; ?>
    </p>

    <?php if (!$fuente): ?>
      <div class="nota-regular">
        <b>No hay datos del buzón.</b>
        Falta <span class="mono">catalogos/casos_sap.json</span>, que genera
        <span class="mono">t2_6_imap_avisos.py</span> al leer el correo. Hasta que
        esté, esta pantalla no puede decir qué hay pendiente — y no va a inventarlo.
      </div>
    <?php else: ?>

      <div class="nota-regular" style="margin-bottom:16px">
        <b>Último barrido del correo: <?= e(substr($generado, 0, 10)) ?><?php
          if ($diasDesde !== null && $diasDesde > 0) { echo ' · hace ' . $diasDesde . ' día' . ($diasDesde === 1 ? '' : 's'); }
        ?>.</b>
        <p style="margin:6px 0 0">
          El correo avisa cuando KFC <b>crea</b> o <b>elimina</b> un caso, pero
          <b>no avisa cuando lo cierra</b>. Un caso puede figurar aquí como
          pendiente y estar cerrado en SAP. Lo que manda es el estado del export
          de SAP, no esta lista.
        </p>
      </div>

      <?php if ($u['rol'] === 'TECNICO' && !$todos): ?>
        <div class="nota-regular" style="margin-bottom:16px">
          <b>Todavía no tienes casos asignados.</b>
          <p style="margin:6px 0 0">
            No es que esté vacío el buzón: hay casos abiertos, pero el reparto
            entre técnicos todavía no está en funcionamiento. Cuando la
            administración o tu jefe de zona te asignen uno, aparece aquí.
            Mientras tanto sigues emitiendo órdenes por
            <a href="index.html">Emitir orden</a>.
          </p>
        </div>
      <?php endif; ?>

      <div class="tiles">
        <div class="tile vence"><div class="n"><?= $nAyer ?></div><div class="t">Llegaron ayer y hoy</div></div>
        <div class="tile"><div class="n"><?= $nSemana ?></div><div class="t">De los últimos 7 días</div></div>
        <div class="tile <?= $nAlerta ? 'alerta' : '' ?>">
          <div class="n"><?= $nAlerta ?></div><div class="t">Con alerta de alcance</div></div>
        <div class="tile"><div class="n"><?= $nHoy ?></div><div class="t">Comprometidos hoy</div></div>
        <div class="tile"><div class="n"><?= count($todos) ?></div><div class="t">En la ventana de 90 días</div></div>
        <?php if ($nSinZona): ?>
          <div class="tile"><div class="n"><?= $nSinZona ?></div><div class="t">Sin zona resuelta</div></div>
        <?php endif; ?>
      </div>

      <?php /* La cifra de vencidos va aqui abajo y con su explicacion, no como
               numero grande: 911 de 918 no es un atraso, es como funciona SAP. */ ?>
      <p class="sub" style="margin:-6px 0 16px">
        <b><?= $nVencido ?></b> tienen la fecha comprometida pasada, pero
        <b>eso no es un atraso</b>: SAP casi siempre compromete para el día
        siguiente, la ventana son 90 días, y el correo no avisa cuando KFC
        cierra. Buena parte de esos ya están resueltos. Para saber cuáles siguen
        abiertos de verdad hace falta el export de SAP.
      </p>

      <?php if ($nAlerta): ?>
        <div class="nota-regular" style="margin-bottom:14px">
          <b>Las alertas no deciden nada.</b> Marcan casos que <i>parecen</i> no
          corresponder a INDUSTEC —trabajo de infraestructura, local fuera del
          contrato— para que los encuentres rápido.
          <?php if ($u['rol'] === 'JEFE_ZONA'): ?>
            Si ves uno así, lo marcas en revisión y la administración resuelve.
          <?php else: ?>
            El veredicto es tuyo: el sistema no cierra ni rechaza ningún caso.
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <form class="filtros" method="get">
        <?php if ($zonaAlc === null): ?>
          <div class="campo">
            <label for="f-zona">Zona</label>
            <select id="f-zona" name="zona">
              <option value="">Todas</option>
              <?php foreach ($porZona as $z => $n): ?>
                <option value="<?= e($z) ?>" <?= $fZona === $z ? 'selected' : '' ?>>
                  <?= e($z) ?> (<?= $n ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
        <div class="campo">
          <label for="f-alerta">Alerta</label>
          <select id="f-alerta" name="alerta">
            <option value="">Todas</option>
            <?php foreach ($ETIQ_ALERTA as $k => $v): ?>
              <option value="<?= e($k) ?>" <?= $fAlert === $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="campo">
          <label for="f-prio">Prioridad</label>
          <select id="f-prio" name="prio">
            <option value="">Todas</option>
            <?php foreach (['ALTA', 'MEDIA', 'BAJA'] as $p): ?>
              <option value="<?= $p ?>" <?= $fPrio === $p ? 'selected' : '' ?>><?= $p ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="campo" style="flex:1;min-width:200px">
          <label for="f-q">Buscar</label>
          <input type="text" id="f-q" name="q" value="<?= e($fTexto) ?>"
                 placeholder="aviso, local, equipo o texto del pedido">
        </div>
        <div class="campo">
          <label for="f-dias">Llegados en</label>
          <select id="f-dias" name="dias">
            <option value="">Los 90 días</option>
            <?php foreach ([1 => 'Ayer y hoy', 3 => 'Últimos 3 días', 7 => 'Últimos 7 días',
                            15 => 'Últimos 15 días', 30 => 'Últimos 30 días'] as $d => $et): ?>
              <option value="<?= $d ?>" <?= $fDias === (string) $d ? 'selected' : '' ?>><?= $et ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="campo">
          <label>&nbsp;</label>
          <button class="btn primary" type="submit" style="height:38px">Filtrar</button>
        </div>
        <?php if ($fZona || $fAlert || $fPrio || $fTexto || $fVence || $fDias !== ''): ?>
          <div class="campo">
            <label>&nbsp;</label>
            <a class="btn" href="casos.php" style="height:38px;display:flex;align-items:center">Limpiar</a>
          </div>
        <?php endif; ?>
      </form>

      <p class="sub" style="margin:0 0 8px">
        <b><?= count($vistos) ?></b> de <?= count($todos) ?> casos
        <?= count($vistos) === count($todos) ? '' : '(filtrados)' ?>.
        Primero los que tienen alerta; dentro de cada grupo, el más nuevo arriba.
      </p>

      <div class="tabla-wrap">
        <table>
          <thead><tr>
            <th>Aviso</th><th>Local</th><th>Zona</th><th>Caso</th>
            <th>Prioridad</th><th>Creado</th><th>Comprometido</th><th>Acciones</th>
          </tr></thead>
          <tbody>
          <?php if (!$vistos): ?>
            <tr><td colspan="8" class="vacio">
              No hay casos con esos filtros. <a href="casos.php">Ver todos</a>.
            </td></tr>
          <?php endif; ?>
          <?php foreach ($vistos as $c): ?>
            <?php
            $conAlerta = ($c['estado_alerta'] ?? '') === 'CON_ALERTA';
            $vencido = ($c['fecha_estimada'] ?? '') !== '' && $c['fecha_estimada'] < $hoy;
            $prio = strtolower((string) ($c['prioridad'] ?? ''));
            ?>
            <tr class="<?= $conAlerta ? 'con-alerta' : '' ?>">
              <td>
                <span class="mono"><?= e($c['aviso'] ?? '—') ?></span>
                <span class="desc mono" style="font-size:11px"><?= e($c['orden_trabajo'] ?? '') ?></span>
              </td>
              <td>
                <b><?= e($c['local'] ?? '—') ?></b>
                <span class="desc"><?= e($c['local_nombre'] ?? $c['restaurante_sap'] ?? '') ?></span>
              </td>
              <td>
                <?php if (!empty($c['zona'])): ?>
                  <?= e($c['zona']) ?>
                  <?php if (!empty($c['zona_discrepa'])): ?>
                    <span class="alerta-txt">llegó al buzón de <?= e($c['zona_por_buzon'] ?? '?') ?></span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="sub">sin resolver</span>
                <?php endif; ?>
              </td>
              <td>
                <?= e($c['caso'] ?? '—') ?>
                <?php if (!empty($c['activo_fijo'])): ?>
                  <span class="desc"><?= e($c['activo_fijo']) ?></span>
                <?php endif; ?>
                <?php if (!empty($c['descripcion_trabajo'])): ?>
                  <span class="desc"><?= e(mb_strimwidth((string) $c['descripcion_trabajo'], 0, 110, '…', 'UTF-8')) ?></span>
                <?php endif; ?>
                <?php foreach (($c['alertas'] ?? []) as $a): ?>
                  <span class="alerta-txt">⚠ <?= e(is_array($a) ? ($a['motivo'] ?? $a['regla'] ?? json_encode($a)) : (string) $a) ?></span>
                <?php endforeach; ?>
              </td>
              <td><span class="prio prio-<?= e($prio ?: 'sd') ?>"><?= e($c['prioridad'] ?? 'S/D') ?></span></td>
              <td class="mono"><?= e($c['fecha_creacion'] ?? '—') ?></td>
              <td class="mono <?= $vencido ? 'vencido' : '' ?>">
                <?= e($c['fecha_estimada'] ?? '—') ?>
                <?php if ($vencido): ?><span class="desc vencido">pasada</span><?php endif; ?>
              </td>
              <td>
                <div class="acciones-fila">
                  <?php foreach ($ACCIONES as [$etiqueta, $permiso, $queHace]): ?>
                    <?php if (!Auth::puede($permiso)) { continue; } ?>
                    <button class="btn" type="button" disabled
                            title="Siguiente fase — <?= e($queHace) ?>"><?= e($etiqueta) ?></button>
                  <?php endforeach; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <h2 style="margin-top:26px">Lo que van a hacer esos botones</h2>
      <p class="sub" style="margin:0 0 12px">
        Están apagados a propósito: en esta fase el buzón solo muestra. Se
        encienden cuando los casos pasen a la base y quede registrado quién hizo
        cada cosa — asignar sin dejar rastro de quién asignó no sirve para
        responderle a KFC.
      </p>
      <div class="proximo">
        <ul style="margin:0;padding-left:20px">
          <?php foreach ($ACCIONES as [$etiqueta, $permiso, $queHace]): ?>
            <li>
              <b><?= e($etiqueta) ?></b> — <?= e($queHace) ?>
              <?php if (!Auth::puede($permiso)): ?>
                <span class="chip">no está en tu rol</span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
          <li>
            <b>Sincronizar el correo</b> — hoy el barrido se corre a mano en la
            estación y se sube el archivo. Va a quedar automático, con la hora
            del último intento a la vista, aunque falle.
          </li>
        </ul>
      </div>

      <?php if (!empty($fuente['revisar']['sin_local']) && $zonaAlc === null): ?>
        <h2 style="margin-top:26px">Casos sin local resuelto</h2>
        <p class="sub" style="margin:0 0 10px">
          El nombre que manda SAP no calza con ningún local del maestro. No se
          les adivina la zona, así que no aparecen en el buzón de ningún jefe:
          quedan aquí para que la administración los identifique.
        </p>
        <div class="tabla-wrap">
          <table>
            <thead><tr><th>Aviso</th><th>Orden</th><th>Como lo escribe SAP</th></tr></thead>
            <tbody>
            <?php foreach ($fuente['revisar']['sin_local'] as $s): ?>
              <tr>
                <td class="mono"><?= e($s['aviso'] ?? '—') ?></td>
                <td class="mono"><?= e($s['orden_trabajo'] ?? '—') ?></td>
                <td><?= e($s['restaurante_sap'] ?? '—') ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

    <?php endif; ?>
  </div>
</div>
</body>
</html>
