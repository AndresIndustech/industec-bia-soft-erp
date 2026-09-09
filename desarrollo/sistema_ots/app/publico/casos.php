<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Casos.php';

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
 * EL ALCANCE POR ZONA SE FILTRA EN EL SERVIDOR, en `Casos::enAlcance()`.
 * Un jefe de zona no ve las otras dos zonas, y no porque se le escondan las
 * filas al dibujar: nunca salen de esa función. Vive en `nucleo/Casos.php`
 * porque cuatro pantallas la necesitan igual, y con una copia por pantalla la
 * que se olvide de actualizar es la que filtra datos de otra zona.
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
 * DE DONDE SALEN LOS DATOS, Y DONDE SE GUARDAN LAS DECISIONES.
 * El caso viene del JSON que empuja la estación, igual que en `catalogos.php`.
 * Ese archivo se **reescribe entero** en cada barrido, así que ahí no se puede
 * guardar nada: lo que decidimos -- a quién se asignó, el veredicto, el cierre --
 * vive en la tabla `casos_gestion`. Las dos cosas se juntan al dibujar, y
 * ninguna pisa a la otra.
 */

$u = Auth::exigir('casos.ver');
if ($u['debe_cambiar_clave']) { header('Location: clave.php'); exit; }

$ZONAS = ['UIO', 'LARB', 'CNLJ', 'OTRA'];

/**
 * Las acciones.
 *
 * CADA UNA REVALIDA TRES COSAS EN EL SERVIDOR, en este orden:
 *   1. el permiso           -- ¿este rol puede hacerlo?
 *   2. el alcance del caso  -- ¿este caso es suyo? Casos::alcanzaAviso()
 *   3. el dato en sí        -- ¿el técnico existe, la zona es real, hay motivo?
 *
 * Esconder un botón no protege nada: un POST se fabrica a mano. Por eso no se
 * confía en que el formulario haya ofrecido solo lo permitido.
 *
 * Se responde con redirección (POST-redirect-GET) para que recargar la página
 * no repita la acción.
 */
$aviso_ok = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    $aviso  = trim((string) ($_POST['aviso'] ?? ''));
    $gest0  = Casos::gestion();
    $caso   = $aviso === '' ? null : Casos::alcanzaAviso($aviso, $gest0);

    if ($caso === null) {
        Auth::bitacora('DENEGADO', 'caso', $aviso, "accion=$accion fuera de su alcance",
                       null, null, ['accion' => $accion], false);
        $error = 'Ese caso no existe o no está en tu alcance.';
    } else {
        Casos::asegurar($aviso, $caso['zona'] ?? null);
        // El estado ANTES de la acción. Sin esto la bitácora guarda un destino
        // sin origen, y no se puede detectar una transición imposible.
        $antes = $gest0[$aviso]['estado'] ?? 'NUEVO';

        if ($accion === 'asignar' && Auth::puede('casos.asignar')) {
            $idt = (int) ($_POST['tecnico'] ?? 0);
            // El técnico tiene que salir de la lista que ESTE usuario puede
            // asignar. Sin esto, un jefe de zona podría asignarle un caso a un
            // técnico de otra zona mandando el id a mano.
            $ok = array_values(array_filter(Casos::tecnicosAsignables(),
                                            fn($x) => (int) $x['usuario_id'] === $idt));
            if (!$ok) {
                $error = 'Ese técnico no está en tu zona o no está activo.';
            } else {
                Db::ejecutar(
                    "UPDATE casos_gestion
                        SET asignado_a = ?, asignado_por = ?, asignado_en = NOW(),
                            estado = CASE WHEN estado IN ('NUEVO','ASIGNADO','EN_REVISION')
                                          THEN 'ASIGNADO' ELSE estado END
                      WHERE aviso = ?",
                    [$idt, $u['usuario_id'], $aviso]
                );
                Auth::bitacora('ASIGNAR', 'caso', $aviso, 'a ' . $ok[0]['usuario'],
                               $antes, 'ASIGNADO',
                               ['tecnico' => $ok[0]['usuario'], 'tecnico_id' => $idt,
                                'zona' => $caso['zona'] ?? null]);
                $aviso_ok = 'Caso ' . $aviso . ' asignado a ' . $ok[0]['nombre'] . '.';
            }

        } elseif ($accion === 'revision' && Auth::puede('casos.revision')) {
            $motivo = trim((string) ($_POST['motivo'] ?? ''));
            if ($motivo === '') {
                // Sin motivo, la administración recibe un caso en revisión y no
                // sabe qué mirar. El motivo ES la acción.
                $error = 'Escribe por qué lo mandas a revisión.';
            } else {
                Db::ejecutar(
                    "UPDATE casos_gestion
                        SET estado = 'EN_REVISION', revision_motivo = ?,
                            revision_por = ?, revision_en = NOW()
                      WHERE aviso = ?",
                    [mb_substr($motivo, 0, 255), $u['usuario_id'], $aviso]
                );
                Auth::bitacora('EN_REVISION', 'caso', $aviso, $motivo,
                               $antes, 'EN_REVISION',
                               ['motivo' => $motivo, 'zona' => $caso['zona'] ?? null]);
                $aviso_ok = 'Caso ' . $aviso . ' enviado a la administración.';
            }

        } elseif ($accion === 'veredicto' && Auth::puede('casos.veredicto')) {
            $ver = (string) ($_POST['veredicto'] ?? '');
            $motivo = trim((string) ($_POST['motivo'] ?? ''));
            if (!in_array($ver, ['RESUELTO', 'NO_COMPETE'], true)) {
                $error = 'Veredicto no válido.';
            } elseif ($ver === 'NO_COMPETE' && $motivo === '') {
                // Decir que un caso no nos compete es lo que se le responde a
                // KFC. Sin el motivo escrito, esa respuesta no se sostiene.
                $error = 'Para marcar que no nos compete hace falta el motivo.';
            } else {
                Db::ejecutar(
                    'UPDATE casos_gestion
                        SET estado = ?, veredicto_motivo = ?, veredicto_por = ?,
                            veredicto_en = NOW()
                      WHERE aviso = ?',
                    [$ver, mb_substr($motivo, 0, 255) ?: null, $u['usuario_id'], $aviso]
                );
                Auth::bitacora('VEREDICTO', 'caso', $aviso, $ver . ($motivo ? ': ' . $motivo : ''),
                               $antes, $ver,
                               ['veredicto' => $ver, 'motivo' => $motivo ?: null]);
                $aviso_ok = 'Caso ' . $aviso . ': ' . Casos::etiquetaEstado($ver) . '.';
            }

        } elseif ($accion === 'cerrado_sap' && Auth::puede('casos.veredicto')) {
            /* La segunda mano del cierre.
             *
             * El sistema marcó ATENDIDO solo, al ver la orden de cierre. Esto
             * es la administradora diciendo «y además ya lo cerré en SAP», que
             * es un hecho distinto y que el correo nunca avisa. Mientras no lo
             * confirme, el caso sigue siendo un pendiente suyo.
             */
            if ($antes !== 'ATENDIDO') {
                $error = 'Solo se confirma el cierre en SAP de un caso ya atendido.';
                Auth::bitacora('DENEGADO', 'caso', $aviso, 'cierre SAP sin estar atendido',
                               $antes, null, ['accion' => $accion], false);
            } else {
                Db::ejecutar(
                    "UPDATE casos_gestion
                        SET estado = 'RESUELTO', veredicto_por = ?, veredicto_en = NOW(),
                            veredicto_motivo = COALESCE(NULLIF(?, ''), 'cerrado en SAP')
                      WHERE aviso = ?",
                    [$u['usuario_id'], trim((string) ($_POST['motivo'] ?? '')), $aviso]
                );
                Auth::bitacora('CERRADO_SAP', 'caso', $aviso, 'confirmado en SAP',
                               $antes, 'RESUELTO', ['ot' => $gest0[$aviso]['ot_cierre'] ?? null]);
                $aviso_ok = 'Caso ' . $aviso . ' confirmado como cerrado en SAP.';
            }

        } elseif ($accion === 'regularizar' && Auth::puede('casos.veredicto')) {
            /* El caso que se cerró por falta de atención deja de ser pendiente
             * cuando la administración lo regulariza ante KFC. El estado no
             * cambia a propósito: sigue siendo un caso que no se atendió, y eso
             * no se borra por haberlo explicado. */
            if ($antes !== 'CERRADO_SIN_ATENCION') {
                $error = 'Solo se regulariza un caso cerrado por falta de atención.';
            } else {
                Db::ejecutar(
                    'UPDATE casos_gestion SET regularizado_por = ?, regularizado_en = NOW(),
                            nota = COALESCE(NULLIF(?, \'\'), nota)
                      WHERE aviso = ?',
                    [$u['usuario_id'], mb_substr(trim((string) ($_POST['motivo'] ?? '')), 0, 500), $aviso]
                );
                Auth::bitacora('REGULARIZAR', 'caso', $aviso, 'regularizado ante KFC',
                               $antes, $antes, ['nota' => $_POST['motivo'] ?? null]);
                $aviso_ok = 'Caso ' . $aviso . ' marcado como regularizado.';
            }

        } elseif ($accion === 'derivar' && Auth::puede('casos.derivar')) {
            $zn = (string) ($_POST['zona_nueva'] ?? '');
            if (!in_array($zn, $ZONAS, true)) {
                $error = 'Zona no válida.';
            } elseif ($zn === ($caso['zona'] ?? null)) {
                $error = 'El caso ya está en esa zona.';
            } else {
                // Se quita la asignación a propósito: el técnico que lo tenía
                // era de la zona vieja y ya no puede atenderlo.
                Db::ejecutar(
                    "UPDATE casos_gestion
                        SET zona_origen = COALESCE(zona_origen, zona), zona = ?,
                            derivado_por = ?, derivado_en = NOW(),
                            asignado_a = NULL, asignado_en = NULL,
                            estado = CASE WHEN estado = 'ASIGNADO' THEN 'NUEVO' ELSE estado END
                      WHERE aviso = ?",
                    [$zn, $u['usuario_id'], $aviso]
                );
                Auth::bitacora('DERIVAR', 'caso', $aviso, ($caso['zona'] ?? '?') . ' -> ' . $zn,
                               $antes, $antes === 'ASIGNADO' ? 'NUEVO' : $antes,
                               ['zona_antes' => $caso['zona'] ?? null, 'zona_nueva' => $zn]);
                $aviso_ok = 'Caso ' . $aviso . ' derivado a ' . $zn . '. Queda sin asignar.';
            }

        } elseif ($error === null) {
            Auth::bitacora('DENEGADO', 'caso', $aviso, "accion=$accion sin permiso",
                           $antes, null, ['accion' => $accion], false);
            $error = 'No tienes permiso para esa acción.';
        }
    }

    $_SESSION['flash'] = ['ok' => $aviso_ok, 'error' => $error];
    /* Se vuelve a donde se pulsó, no siempre al buzón: `asignacion.php` manda
       aquí sus formularios a propósito -- la validación vive en un solo sitio--
       y devolver al usuario a otra pantalla sería desorientarlo. Solo se aceptan
       nombres de la lista: un `Location` con lo que llegue por POST es una
       redirección abierta. */
    $vuelta = (string) ($_POST['volver'] ?? '');
    $destino = in_array($vuelta, ['asignacion.php', 'ordenes.php'], true) ? $vuelta : 'casos.php';
    $qs = $destino === 'casos.php' ? (string) ($_SERVER['QUERY_STRING'] ?? '') : '';
    header('Location: ' . $destino . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$aviso_ok = $flash['ok'] ?? null;
$error    = $flash['error'] ?? null;

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

$fuente   = Casos::catalogo();
$porAviso = Casos::atenciones();
$gestion  = Casos::gestion();
$zonaAlc  = Auth::zonaAlcance();
$todos = Casos::enAlcance($fuente['datos'] ?? [], $gestion);

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
$fAtn   = (string) ($_GET['atn'] ?? '');            // '' | sin | curso | cerrada
$desdeF = $fDias !== '' ? date('Y-m-d', strtotime('-' . (int) $fDias . ' days')) : null;

$vistos = array_values(array_filter($todos, function ($c) use ($fZona, $fAlert, $fPrio, $fTexto, $fVence, $hoy, $desdeF, $fAtn, $porAviso) {
    if ($fAtn !== '') {
        $e = $porAviso[$c['aviso'] ?? '']['estado_industec'] ?? null;
        if ($fAtn === 'sin' && $e !== null) { return false; }
        if ($fAtn === 'curso' && $e !== 'EN_CURSO') { return false; }
        if ($fAtn === 'cerrada' && $e !== 'CERRADA') { return false; }
    }
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
usort($vistos, function ($a, $b) use ($ordenAlerta, $ordenPrio, $porAviso) {
    // Lo que nadie ha atendido va primero: es lo unico que hay que repartir.
    $sa = isset($porAviso[$a['aviso'] ?? '']) ? 1 : 0;
    $sb = isset($porAviso[$b['aviso'] ?? '']) ? 1 : 0;
    $ka = [$ordenAlerta[$a['estado_alerta'] ?? ''] ?? 3, $sa, $ordenPrio[$a['prioridad'] ?? ''] ?? 3];
    $kb = [$ordenAlerta[$b['estado_alerta'] ?? ''] ?? 3, $sb, $ordenPrio[$b['prioridad'] ?? ''] ?? 3];
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
$nAtend   = count(array_filter($todos, fn($c) => isset($porAviso[$c['aviso'] ?? ''])));
$nCerrIn  = count(array_filter($todos, fn($c) =>
    ($porAviso[$c['aviso'] ?? '']['estado_industec'] ?? '') === 'CERRADA'));
$porZona  = [];
foreach ($todos as $c) { $z = $c['zona'] ?? '(sin zona)'; $porZona[$z] = ($porZona[$z] ?? 0) + 1; }
ksort($porZona);

$generado = (string) ($fuente['generado'] ?? '');
$diasDesde = $generado !== '' ? (int) floor((strtotime($hoy) - strtotime(substr($generado, 0, 10))) / 86400) : null;

/* Las acciones de la fase siguiente. Se dibujan apagadas y se dice qué harán. */
$ACCIONES = [
    ['Asignar técnico',   'casos.asignar',
     'Se elige de los técnicos vigentes de la zona. El caso le aparece en su lista y queda registrado quién lo asignó.'],
    ['Marcar en revisión', 'casos.revision',
     'Lo manda a los pendientes de la administración, con el motivo. Es lo que usa el jefe de zona cuando ve algo que no nos compete.'],
    ['Dar veredicto',      'casos.veredicto',
     'Solo la administración. Resuelve si el caso nos compete o no, y queda el nombre y la fecha de quien lo resolvió.'],
    ['Derivar a otra zona', 'casos.derivar',
     'Para el caso que llegó al buzón equivocado. El caso cambia de zona y queda sin asignar.'],
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
  .ok{ background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;border-radius:9px;padding:10px 12px;font-size:13px;margin-bottom:14px; }
  .err{ background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:9px;padding:10px 12px;font-size:13px;margin-bottom:14px; }
  .tile.atend{ border-color:#bbf7d0; background:#f0fdf4; } .tile.atend .n{ color:#166534; }
  .chip.cerrada{ background:#dcfce7; color:#166534; }
  .chip.curso{ background:#e0f2fe; color:#075985; }
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

    <?php if ($aviso_ok): ?><div class="ok"><?= e($aviso_ok) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>

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
        <div class="tile atend"><div class="n"><?= $nAtend ?></div>
          <div class="t">Ya atendidos<?= $nCerrIn ? ' &middot; ' . $nCerrIn . ' cerrados' : '' ?></div></div>
        <div class="tile"><div class="n"><?= count($todos) ?></div><div class="t">En la ventana de 90 días</div></div>
        <?php if ($nSinZona): ?>
          <div class="tile"><div class="n"><?= $nSinZona ?></div><div class="t">Sin zona resuelta</div></div>
        <?php endif; ?>
      </div>

      <?php /* La cifra de vencidos va aqui abajo y con su explicacion, no como
               numero grande: 911 de 918 no es un atraso, es como funciona SAP. */ ?>
      <?php if ($nAtend): ?>
        <div class="nota-regular" style="margin-bottom:14px">
          <b><?= $nAtend ?> de estos casos ya se atendieron</b><?php if ($nCerrIn): ?>,
          y <b><?= $nCerrIn ?></b> tienen ya su orden de cierre<?php endif; ?>.
          Se sabe porque el informe de cada orden llega a este mismo buzón.
          <p style="margin:6px 0 0">
            <b>Atendido no es lo mismo que cerrado en SAP.</b> Significa que
            INDUSTEC hizo el trabajo y emitió la orden; KFC cierra el caso por su
            lado y de eso el correo no avisa. Sirve para no volver a asignar algo
            que ya se hizo.
          </p>
        </div>
      <?php endif; ?>

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
          <label for="f-atn">Atención</label>
          <select id="f-atn" name="atn">
            <option value="">Todos</option>
            <option value="sin"     <?= $fAtn === 'sin' ? 'selected' : '' ?>>Sin atender</option>
            <option value="curso"   <?= $fAtn === 'curso' ? 'selected' : '' ?>>Atendidos, en curso</option>
            <option value="cerrada" <?= $fAtn === 'cerrada' ? 'selected' : '' ?>>Con orden de cierre</option>
          </select>
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
        <?php if ($fZona || $fAlert || $fPrio || $fTexto || $fVence || $fDias !== '' || $fAtn): ?>
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
            <th>Prioridad</th><th>Atención</th><th>Comprometido</th><th>Acciones</th>
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
              <td>
                <?php $a = $porAviso[$c['aviso'] ?? ''] ?? null; ?>
                <?php if ($a === null): ?>
                  <span class="sub">sin atender</span>
                  <span class="desc mono">creado <?= e($c['fecha_creacion'] ?? '—') ?></span>
                <?php else: ?>
                  <span class="chip <?= $a['estado_industec'] === 'CERRADA' ? 'cerrada' : 'curso' ?>">
                    <?= $a['estado_industec'] === 'CERRADA' ? 'con orden de cierre' : 'atendido, en curso' ?>
                  </span>
                  <?php foreach ($a['ots'] as $o): ?>
                    <span class="desc mono">
                      <?php if (Auth::puede('ots.pdf')): ?>
                        <a href="pdf.php?ot=<?= rawurlencode((string) $o['ot']) ?>"
                           target="_blank" rel="noopener"><?= e($o['ot']) ?></a>
                      <?php else: ?>
                        <?= e($o['ot']) ?>
                      <?php endif; ?>
                      · <?= e(substr((string) $o['fecha'], 0, 10)) ?>
                    </span>
                  <?php endforeach; ?>
                  <?php if ($a['tecnicos']): ?>
                    <span class="desc"><?= e(implode(' · ', $a['tecnicos'])) ?></span>
                  <?php endif; ?>
                  <?php foreach ($a['sin_identificar'] as $s): ?>
                    <span class="desc" style="color:#92400e">firma sin identificar: <?= e($s) ?></span>
                  <?php endforeach; ?>
                <?php endif; ?>
              </td>
              <td class="mono <?= $vencido ? 'vencido' : '' ?>">
                <?= e($c['fecha_estimada'] ?? '—') ?>
                <?php if ($vencido): ?><span class="desc vencido">pasada</span><?php endif; ?>
              </td>
              <td>
                <?php
                $g = $gestion[$c['aviso'] ?? ''] ?? null;
                $est = $g['estado'] ?? 'NUEVO';
                $av = e($c['aviso'] ?? '');
                ?>
                <div class="acciones-fila">
                  <?php if (Auth::puede('casos.asignar') && !in_array($est, ['RESUELTO','NO_COMPETE'], true)): ?>
                    <button class="btn" type="button" onclick="abrir('asignar','<?= $av ?>')">
                      <?= $est === 'ASIGNADO' || $est === 'ATENDIDO' ? 'Reasignar' : 'Asignar' ?>
                    </button>
                  <?php endif; ?>

                  <?php if ($est === 'ATENDIDO' && Auth::puede('casos.veredicto')): ?>
                    <?php /* Lo que la administradora tiene que hacer con este
                             caso: el trabajo esta hecho y falta su parte. Se
                             pone primero y destacado porque es SU pendiente. */ ?>
                    <button class="btn primary" type="button" onclick="abrir('cerrado_sap','<?= $av ?>')">
                      Ya lo cerré en SAP
                    </button>
                  <?php endif; ?>

                  <?php if ($est === 'CERRADO_SIN_ATENCION' && empty($g['regularizado_en'])
                            && Auth::puede('casos.veredicto')): ?>
                    <button class="btn primary" type="button" onclick="abrir('regularizar','<?= $av ?>')">
                      Regularizar
                    </button>
                  <?php endif; ?>

                  <?php if (Auth::puede('casos.revision') && $est !== 'EN_REVISION'
                            && !in_array($est, ['RESUELTO','NO_COMPETE'], true)): ?>
                    <button class="btn" type="button" onclick="abrir('revision','<?= $av ?>')">En revisión</button>
                  <?php endif; ?>

                  <?php if (Auth::puede('casos.veredicto') && $est !== 'ATENDIDO'
                            && $est !== 'CERRADO_SIN_ATENCION'): ?>
                    <button class="btn" type="button" onclick="abrir('veredicto','<?= $av ?>')">Veredicto</button>
                  <?php endif; ?>

                  <?php if (Auth::puede('casos.derivar')): ?>
                    <button class="btn" type="button" onclick="abrir('derivar','<?= $av ?>')">Derivar</button>
                  <?php endif; ?>
                </div>

                <?php if ($g): ?>
                  <span class="desc">
                    <?= e(Casos::etiquetaEstado($est)) ?>
                    <?php if (!empty($g['tecnico_nombre'])): ?>
                      · <?= e($g['tecnico_nombre']) ?><?= $g['tecnico_auto'] ? ' (del informe)' : '' ?>
                    <?php endif; ?>
                  </span>
                  <?php if (!empty($g['revision_motivo']) && $est === 'EN_REVISION'): ?>
                    <span class="desc" style="color:#92400e"><?= e($g['revision_motivo']) ?></span>
                  <?php endif; ?>
                  <?php if (!empty($g['regularizado_en'])): ?>
                    <span class="desc" style="color:#166534">regularizado</span>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($u['rol'] !== 'TECNICO'): ?>
      <h2 style="margin-top:26px">Qué hace cada acción</h2>
      <p class="sub" style="margin:0 0 12px">
        Todo queda registrado: quién lo hizo, cuándo, y desde qué estado — que es
        lo que permite responderle a KFC y, más adelante, detectar lo que se sale
        de lo normal.
      </p>
      <div class="proximo">
        <ul style="margin:0;padding-left:20px">
          <?php foreach ($ACCIONES as [$etiqueta, $permiso, $queHace]): ?>
            <?php if (!Auth::puede($permiso)) { continue; } ?>
            <li><b><?= e($etiqueta) ?></b> — <?= e($queHace) ?></li>
          <?php endforeach; ?>
          <?php if (Auth::puede('casos.veredicto')): ?>
            <li><b>Ya lo cerré en SAP</b> — aparece en los casos que INDUSTEC ya
              cerró. Es tu confirmación de que además lo cerraste del lado de
              KFC, que es el dato que el correo nunca trae.</li>
            <li><b>Regularizar</b> — para los que se cerraron por falta de
              atención. Deja de contarlos como pendiente tuyo; el caso sigue
              constando como no atendido, porque eso no se borra.</li>
          <?php endif; ?>
        </ul>
      </div>

      <?php endif; ?>

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

<?php /* El diálogo de acciones.
         Uno solo para toda la tabla, no cuatro formularios por fila: con 900
         casos serían miles de formularios en el HTML, y la página se arrastra.
         El aviso y la acción se rellenan al pulsar. */ ?>
<dialog id="acc">
  <form method="post" id="acc-form">
    <input type="hidden" name="accion" id="acc-accion">
    <input type="hidden" name="aviso"  id="acc-aviso">
    <h2 id="acc-titulo" style="margin:0 0 4px;font-size:17px"></h2>
    <p class="sub" id="acc-ayuda" style="margin:0 0 12px"></p>

    <div id="acc-tecnico" hidden>
      <label for="acc-tec">Técnico</label>
      <select name="tecnico" id="acc-tec">
        <option value="">Elige…</option>
        <?php foreach (Casos::tecnicosAsignables() as $tc): ?>
          <option value="<?= (int) $tc['usuario_id'] ?>">
            <?= e($tc['nombre']) ?> · <?= e((string) $tc['zona']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div id="acc-zona" hidden>
      <label for="acc-zn">Zona a la que va</label>
      <select name="zona_nueva" id="acc-zn">
        <?php foreach ($ZONAS as $z): ?>
          <option value="<?= e($z) ?>"><?= e($z) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div id="acc-veredicto" hidden>
      <label for="acc-vd">Veredicto</label>
      <select name="veredicto" id="acc-vd">
        <option value="RESUELTO">Nos compete y está resuelto</option>
        <option value="NO_COMPETE">No es trabajo de INDUSTEC</option>
      </select>
    </div>

    <div id="acc-motivo" hidden>
      <label for="acc-mt" id="acc-mt-label">Motivo</label>
      <textarea name="motivo" id="acc-mt" rows="3"
                placeholder="En una línea, para que quien lo lea después entienda"></textarea>
    </div>

    <div class="row" style="margin-top:14px;gap:8px">
      <button class="btn primary" type="submit" id="acc-ok">Confirmar</button>
      <button class="btn" type="button" onclick="document.getElementById('acc').close()">Cancelar</button>
    </div>
  </form>
</dialog>
<style>
  dialog#acc{ border:none; border-radius:12px; padding:18px; max-width:480px;
              width:calc(100% - 28px); box-shadow:0 18px 50px rgba(0,0,0,.25); }
  dialog#acc::backdrop{ background:rgba(15,23,42,.45); }
  dialog#acc label{ display:block; font-size:12px; color:var(--muted); margin-bottom:4px; }
  dialog#acc select, dialog#acc textarea{ width:100%; }
  dialog#acc [hidden]{ display:none !important; }
</style>
<script>
/* Qué pide cada acción. Sale de aquí y no del HTML de cada fila para que el
   texto que ve la persona esté en un solo sitio y no en 900 copias. */
var ACC = {
  asignar:     { t:'Asignar el caso',            a:'Le va a aparecer en su lista de órdenes. Queda registrado quién lo asignó.',
                 campos:['tecnico'], ok:'Asignar' },
  revision:    { t:'Mandar a revisión',          a:'Va a los pendientes de administración con el motivo que escribas.',
                 campos:['motivo'], ok:'Mandar', motivo:'Por qué lo mandas' },
  veredicto:   { t:'Dar veredicto',              a:'Resuelve si el caso nos compete. Queda tu nombre y la fecha.',
                 campos:['veredicto','motivo'], ok:'Guardar', motivo:'Motivo (obligatorio si no nos compete)' },
  derivar:     { t:'Derivar a otra zona',        a:'El caso pasa a la otra zona y queda SIN asignar: el técnico que lo tenía ya no puede atenderlo.',
                 campos:['zona'], ok:'Derivar' },
  cerrado_sap: { t:'Confirmar el cierre en SAP', a:'INDUSTEC ya emitió la orden de cierre. Esto es que además ya lo cerraste en SAP, que es lo que el correo nunca avisa.',
                 campos:['motivo'], ok:'Confirmar', motivo:'Nota (opcional)' },
  regularizar: { t:'Marcar como regularizado',   a:'Se cerró por falta de atención. Esto deja de contarlo como pendiente tuyo; el caso sigue constando como no atendido.',
                 campos:['motivo'], ok:'Regularizar', motivo:'Qué se hizo (opcional)' }
};
function abrir(accion, aviso) {
  var c = ACC[accion];
  document.getElementById('acc-accion').value = accion;
  document.getElementById('acc-aviso').value  = aviso;
  document.getElementById('acc-titulo').textContent = c.t + ' · ' + aviso;
  document.getElementById('acc-ayuda').textContent  = c.a;
  document.getElementById('acc-ok').textContent     = c.ok;
  ['tecnico','zona','veredicto','motivo'].forEach(function (k) {
    document.getElementById('acc-' + k).hidden = c.campos.indexOf(k) === -1;
  });
  var mt = document.getElementById('acc-mt');
  mt.value = '';
  mt.required = (accion === 'revision');
  if (c.motivo) { document.getElementById('acc-mt-label').textContent = c.motivo; }
  document.getElementById('acc-tec').required = (accion === 'asignar');
  document.getElementById('acc').showModal();
}
</script>

<?php /* La barra de novedades. Aparece cuando el buzon cambio en el servidor y
         NO recarga sola: la administradora puede estar leyendo un caso o a
         medio filtrar, y recargarle la pagina debajo seria peor que no avisar.
         Es la misma regla que se fijo para las alertas del cronograma: visible
         siempre, sin estorbar, hasta que la persona decida. */ ?>
<div id="novedades" hidden>
  <span id="nov-txt">El buzón se actualizó</span>
  <button class="btn primary" type="button" id="nov-ver">Ver lo nuevo</button>
  <button class="btn" type="button" id="nov-no" title="Se vuelve a avisar en el próximo cambio">Ahora no</button>
</div>
<style>
  #novedades{ position:fixed; left:50%; transform:translateX(-50%); bottom:18px;
              z-index:60; display:flex; align-items:center; gap:10px;
              background:#0b1220; color:#fff; border-radius:999px;
              padding:9px 10px 9px 18px; font-size:13.5px;
              box-shadow:0 8px 26px rgba(0,0,0,.28); max-width:calc(100vw - 24px); }
  #novedades[hidden]{ display:none !important; }
  #novedades .btn{ padding:6px 13px; font-size:12.5px; }
  #nov-no{ background:transparent; color:#cbd5e1; border-color:#334155; }
  @media (max-width:520px){ #novedades{ padding-left:14px; font-size:12.5px; } }
</style>
<script>
(function () {
  var caja = document.getElementById("novedades");
  var txt  = document.getElementById("nov-txt");
  var base = null;          // la version con la que se dibujo esta pagina
  var visto = null;         // la version que ya se descarto con "Ahora no"

  function mirar() {
    fetch("novedades.php", { cache: "no-store", credentials: "same-origin" })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || !d.hay) { return; }
        if (base === null) { base = d.version; return; }   // primera lectura
        if (d.version === base || d.version === visto) { caja.hidden = true; return; }
        var n = (typeof d.total === "number") ? d.total : null;
        txt.textContent = n === null
          ? "El buzón se actualizó"
          : "El buzón se actualizó — ahora hay " + n + (n === 1 ? " caso" : " casos");
        caja.dataset.version = d.version;
        caja.hidden = false;
      })
      .catch(function () { /* sin señal: se reintenta al rato, sin molestar */ });
  }

  document.getElementById("nov-ver").addEventListener("click", function () {
    location.reload();
  });
  document.getElementById("nov-no").addEventListener("click", function () {
    visto = Number(caja.dataset.version) || null;
    caja.hidden = true;
  });

  mirar();
  setInterval(mirar, 30000);
  // Al volver a la pestaña se mira enseguida, en vez de esperar los 30 s.
  document.addEventListener("visibilitychange", function () {
    if (!document.hidden) { mirar(); }
  });
})();
</script>
</body>
</html>
