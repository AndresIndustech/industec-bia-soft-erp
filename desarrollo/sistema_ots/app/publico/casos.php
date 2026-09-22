<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Casos.php';
require_once __DIR__ . '/nucleo/Ui.php';   // Ui::coincide() se usa al filtrar, antes de pintar
require_once __DIR__ . '/nucleo/Pendientes.php';   // el cierre de dos manos mira si queda un pendiente vivo (ASG-01)
require_once __DIR__ . '/nucleo/Emision.php';   // Emision::existePdf() antes de ofrecer el enlace (ver bloque de abajo)

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

/** Un pendiente de repuestos sigue vivo si no terminó (ASG-01, ASG-02: el
 *  cierre de dos manos no puede pasar con el equipo todavía parado). */
function tienePendienteVivo(string $aviso): bool
{
    if (!Pendientes::disponible()) { return false; }
    return (bool) Db::uno(
        "SELECT 1 FROM pendientes WHERE aviso = ? AND estado NOT IN ('RESUELTO','CANCELADO') LIMIT 1",
        [$aviso]
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::exigirCsrf();
    $accion = (string) ($_POST['accion'] ?? '');

    /* «Cerrar por falta de atención» es una acción sobre EL BUZÓN, no sobre un
     * caso: no trae `aviso` y por eso vive fuera del `if ($caso === null)` de
     * abajo, que exige uno. Lo dispara el botón de panel.php (ASG-05): el
     * cierre automático a los 7 días que todas las pantallas prometían solo
     * existía en `reconciliar_cli.php --ejecutar`, que nadie lanzaba. La
     * guarda del 15 % es la misma que llevaría un cron: no cerrar de un golpe
     * más de lo que razonablemente se acumula en una semana.
     */
    if ($accion === 'cerrar_sin_atencion' && Auth::puede('casos.cerrar_sin_atencion')) {
        require_once __DIR__ . '/nucleo/Reconciliar.php';
        $catalogoDatos = Casos::catalogo()['datos'] ?? [];
        $atenPrevia = Casos::atenciones();
        $conteo = Reconciliar::cerrarSinAtencion($catalogoDatos, $atenPrevia, 7, false);
        $tope = max(5, (int) ceil(count($catalogoDatos) * 0.15));
        if ($conteo['candidatos'] === 0) {
            $error = 'No hay casos con más de 7 días sin informe para cerrar ahora mismo.';
        } elseif ($conteo['candidatos'] > $tope) {
            $error = 'Se iban a cerrar ' . $conteo['candidatos'] . ' casos de una vez (más del 15% del catálogo). '
                   . 'Revisa el buzón antes de forzarlo: puede que el correo no se haya barrido bien.';
            Auth::bitacora('DENEGADO', 'buzon', 'cerrar_sin_atencion', 'supera el 15%: ' . $conteo['candidatos'],
                           null, null, ['candidatos' => $conteo['candidatos'], 'tope' => $tope], false);
        } else {
            $r = Reconciliar::cerrarSinAtencion($catalogoDatos, $atenPrevia, 7, true);
            Auth::bitacora('CERRAR_SIN_ATENCION', 'buzon', 'cerrar_sin_atencion',
                           $r['cerrados'] . ' casos cerrados por falta de atención (corte ' . $r['corte'] . ')',
                           null, null, $r);
            $aviso_ok = $r['cerrados'] . ' caso' . ($r['cerrados'] === 1 ? '' : 's')
                      . ' cerrado' . ($r['cerrados'] === 1 ? '' : 's') . ' por falta de atención.';
        }
        $_SESSION['flash'] = ['ok' => $aviso_ok, 'error' => $error];
        header('Location: panel.php');
        exit;
    }

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
            if (!Casos::puedeTransitar('asignar', $antes)) {
                $error = 'Ese caso no está en un estado que se pueda asignar: primero regularízalo o cierra el pendiente.';
                Auth::bitacora('DENEGADO', 'caso', $aviso, "asignar desde $antes",
                               $antes, null, ['accion' => $accion], false);
            } else {
                $idt = (int) ($_POST['tecnico'] ?? 0);
                // El técnico tiene que salir de la lista que ESTE usuario puede
                // asignar. Sin esto, un jefe de zona podría asignarle un caso a un
                // técnico de otra zona mandando el id a mano.
                $ok = array_values(array_filter(Casos::tecnicosAsignables(),
                                                fn($x) => (int) $x['usuario_id'] === $idt));
                // Asignar entre zonas se permite, pero no en silencio (D4, ASG-03):
                // hay que marcar a propósito que se sabe que es de otra zona.
                $confirmoZona = !empty($_POST['confirmo_zona']);
                $zonaTec = $ok[0]['zona'] ?? null;
                $zonaCaso = $caso['zona'] ?? null;
                if (!$ok) {
                    $error = 'Ese técnico no está en tu zona o no está activo.';
                } elseif ($zonaTec !== $zonaCaso && !$confirmoZona) {
                    $error = 'El técnico es de ' . $zonaTec . ' y el caso de '
                           . ($zonaCaso ?: 'sin zona') . '. Marca que confirmas la zona si de verdad quieres asignarlo así.';
                } else {
                    // ESPERA_REPUESTO no cambia de estado al reasignar: sigue
                    // esperando la pieza, solo cambia quién la tiene detrás.
                    $despues = $antes === 'ESPERA_REPUESTO' ? 'ESPERA_REPUESTO' : 'ASIGNADO';
                    Db::ejecutar(
                        'UPDATE casos_gestion
                            SET asignado_a = ?, asignado_por = ?, asignado_en = NOW(),
                                tecnico_auto = 0, estado = ?
                          WHERE aviso = ?',
                        [$idt, $u['usuario_id'], $despues, $aviso]
                    );
                    // Reabrir un cerrado sin atención es una decisión distinta a
                    // repartir uno nuevo: queda su propia acción en bitácora (D6).
                    $accionBit = $antes === 'CERRADO_SIN_ATENCION' ? 'REABRIR' : 'ASIGNAR';
                    Auth::bitacora($accionBit, 'caso', $aviso, 'a ' . $ok[0]['usuario']
                                   . ($zonaTec !== $zonaCaso ? ' (zona distinta, confirmado)' : ''),
                                   $antes, $despues,
                                   ['tecnico' => $ok[0]['usuario'], 'tecnico_id' => $idt,
                                    'zona_caso' => $zonaCaso, 'zona_tecnico' => $zonaTec,
                                    'confirmo_zona' => $confirmoZona]);
                    $aviso_ok = 'Caso ' . $aviso . ' asignado a ' . $ok[0]['nombre'] . '.';
                }
            }

        } elseif ($accion === 'seguimiento' && Auth::puede('casos.seguimiento')) {
            // Pedirle a un técnico que se pronuncie sobre un caso que ya tiene
            // asignado, sin cambiarle el estado: es un recordatorio, no una
            // transición (ASG-15).
            if (!Casos::puedeTransitar('seguimiento', $antes)) {
                $error = 'Ese caso no está en un estado donde tenga sentido pedir seguimiento.';
            } else {
                $idt = (int) ($_POST['tecnico'] ?? 0);
                $texto = trim((string) ($_POST['texto'] ?? ''));
                $ok = array_values(array_filter(Casos::tecnicosAsignables(),
                                                fn($x) => (int) $x['usuario_id'] === $idt));
                if ($texto === '') {
                    $error = 'Escribe qué le pides al técnico.';
                } elseif (!$ok) {
                    $error = 'Ese técnico no está en tu zona o no está activo.';
                } else {
                    Db::ejecutar(
                        'INSERT INTO casos_seguimientos (aviso, tecnico_id, pedido_por, texto)
                         VALUES (?, ?, ?, ?)',
                        [$aviso, $idt, $u['usuario_id'], mb_substr($texto, 0, 600)]
                    );
                    Auth::bitacora('PEDIR_SEGUIMIENTO', 'caso', $aviso,
                                   'a ' . $ok[0]['usuario'] . ': ' . $texto,
                                   $antes, $antes, ['tecnico' => $ok[0]['usuario'], 'tecnico_id' => $idt]);
                    $aviso_ok = 'Se le pidió seguimiento a ' . $ok[0]['nombre'] . ' sobre el caso ' . $aviso . '.';
                }
            }

        } elseif ($accion === 'revision' && Auth::puede('casos.revision')) {
            $motivo = trim((string) ($_POST['motivo'] ?? ''));
            if (!Casos::puedeTransitar('revision', $antes)) {
                $error = 'Ese caso ya está cerrado: no admite pasar a revisión.';
                Auth::bitacora('DENEGADO', 'caso', $aviso, "revision desde $antes",
                               $antes, null, ['accion' => $accion], false);
            } elseif ($motivo === '') {
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
            } elseif (!Casos::puedeTransitar('veredicto', $antes, $ver)) {
                // RESUELTO solo desde ATENDIDO: el cierre es de dos manos y la
                // segunda no puede darse antes de que exista la primera
                // (ASG-01). Cierra también la puerta a un ESPERA_REPUESTO con
                // el equipo todavía parado.
                $error = $ver === 'RESUELTO'
                    ? 'Solo se marca resuelto un caso ya atendido.'
                    : 'Ese caso ya no está en un estado donde quepa ese veredicto.';
                Auth::bitacora('DENEGADO', 'caso', $aviso, "veredicto=$ver desde $antes",
                               $antes, null, ['accion' => $accion], false);
            } elseif ($ver === 'NO_COMPETE' && $motivo === '') {
                // Decir que un caso no nos compete es lo que se le responde a
                // KFC. Sin el motivo escrito, esa respuesta no se sostiene.
                $error = 'Para marcar que no nos compete hace falta el motivo.';
            } elseif ($ver === 'RESUELTO' && tienePendienteVivo($aviso)) {
                $error = 'Ese caso tiene un pendiente de repuestos abierto: ciérralo primero en Pendientes.';
                Auth::bitacora('DENEGADO', 'caso', $aviso, 'veredicto con pendiente abierto',
                               $antes, null, ['accion' => $accion], false);
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
            if (!Casos::puedeTransitar('cerrado_sap', $antes)) {
                $error = 'Solo se confirma el cierre en SAP de un caso ya atendido.';
                Auth::bitacora('DENEGADO', 'caso', $aviso, 'cierre SAP sin estar atendido',
                               $antes, null, ['accion' => $accion], false);
            } elseif (tienePendienteVivo($aviso)) {
                $error = 'Ese caso tiene un pendiente de repuestos abierto: ciérralo primero en Pendientes.';
                Auth::bitacora('DENEGADO', 'caso', $aviso, 'cierre SAP con pendiente abierto',
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
            if (!Casos::puedeTransitar('regularizar', $antes)) {
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
            if (!Casos::puedeTransitar('derivar', $antes)) {
                $error = 'Ese caso ya está cerrado: no se puede derivar.';
                Auth::bitacora('DENEGADO', 'caso', $aviso, "derivar desde $antes",
                               $antes, null, ['accion' => $accion], false);
            } elseif (!in_array($zn, $ZONAS, true)) {
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

        } elseif ($accion === 'otro_trabajo' && Auth::puede('casos.veredicto')) {
            /* «Otros trabajos» (011, decisión de Andrés del 2026-09-14): lo que
             * INDUSTEC hace para KFC fuera de su área, por un acuerdo. Es una
             * marca aparte del estado: el caso sigue su flujo, y la marca dice
             * si se cuenta y se reporta a KFC como extra. La decide SIEMPRE la
             * administración, y sin el acuerdo escrito no se sostiene ante KFC.
             * No mira el estado a propósito: el 10351229 se atendió y se cerró
             * antes de que existiera esta decisión. */
            $dec = (string) ($_POST['decision'] ?? '');
            $motivo = trim((string) ($_POST['motivo'] ?? ''));
            if (!in_array($dec, ['AUTORIZADO', 'NO_AUTORIZADO'], true)) {
                $error = 'Decisión no válida.';
            } elseif ($motivo === '') {
                $error = $dec === 'AUTORIZADO'
                    ? 'Escribe el acuerdo con KFC: es lo que respalda el trabajo extra.'
                    : 'Escribe por qué no se autoriza.';
            } else {
                Db::ejecutar(
                    'UPDATE casos_gestion
                        SET otro_trabajo = ?, otro_trabajo_motivo = ?,
                            otro_trabajo_por = ?, otro_trabajo_en = NOW()
                      WHERE aviso = ?',
                    [$dec, mb_substr($motivo, 0, 255), $u['usuario_id'], $aviso]
                );
                Auth::bitacora('OTRO_TRABAJO', 'caso', $aviso, $dec . ': ' . $motivo,
                               $antes, $antes,
                               ['decision' => $dec, 'antes' => $gest0[$aviso]['otro_trabajo'] ?? null,
                                'motivo' => $motivo, 'tipo' => $caso['caso'] ?? null,
                                'zona' => $caso['zona'] ?? null]);
                $aviso_ok = 'Caso ' . $aviso . ($dec === 'AUTORIZADO'
                          ? ': autorizado como otro trabajo.' : ': no autorizado como otro trabajo.');
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
       nombres de la lista, con o sin su filtro de zona y su ancla (ASG-06,
       ASG-07): un `Location` con lo que llegue por POST es una redirección
       abierta, así que el patrón es cerrado, no `in_array` contra lo que venga. */
    $vuelta = (string) ($_POST['volver'] ?? '');
    if ($vuelta === 'ordenes.php') {
        $destino = 'ordenes.php';
    } elseif (preg_match('~^asignacion\.php(?:\?zona=(?:UIO|LARB|CNLJ))?(?:#por-repartir-(?:UIO|LARB|CNLJ))?$~', $vuelta)) {
        $destino = $vuelta;
    } else {
        $destino = 'casos.php';
    }
    $qs = $destino === 'casos.php' ? (string) ($_SERVER['QUERY_STRING'] ?? '') : '';
    header('Location: ' . $destino . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

/* El error se pinta aqui; el exito se lo lleva `Ui::pie()` como aviso efimero.
   La regla es la misma en todo el sistema: si hay que hacer algo, aviso fijo;
   si solo hay que enterarse de que salio bien, aviso que se va solo. */
$error = $_SESSION['flash']['error'] ?? null;
unset($_SESSION['flash']['error']);

function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

$fuente   = Casos::catalogo();
$porAviso = Casos::atenciones();
$gestion  = Casos::gestion();
$zonaAlc  = Auth::zonaAlcance();
$todos = Casos::enAlcance($fuente['datos'] ?? [], $gestion);

/* La atención de cada caso sale de TODAS sus fuentes, no solo de
   `atenciones.json`. Hasta el 2026-09-14 un caso con su orden de cierre en la
   gestión —la que dejan la app y la reconciliación— figuraba «sin atender» en
   la columna, en el filtro, en el orden y en los contadores: 38 de los 67
   casos con orden de cierre (10354415 y 10354383, LARB). Se completa
   `$porAviso` aquí, una vez, para que esas cuatro cosas lean lo mismo. */
$docs = Casos::documentos(array_map(fn($c) => (string) ($c['aviso'] ?? ''), $todos), $gestion, $porAviso);
foreach ($todos as $c) {
    $av = (string) ($c['aviso'] ?? '');
    $conCierre = !empty($gestion[$av]['ot_cierre']);
    if (!isset($porAviso[$av]) && ($conCierre || !empty($docs[$av]))) {
        $porAviso[$av] = ['estado_industec' => $conCierre ? 'CERRADA' : 'EN_CURSO',
                          'ots' => [], 'tecnicos' => [], 'sin_identificar' => []];
    } elseif ($conCierre) {
        $porAviso[$av]['estado_industec'] = 'CERRADA';
    }
}
$atencion = static fn(string $av): ?string => $porAviso[$av]['estado_industec'] ?? null;

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
/* Estado de gestion. Es el filtro que usan los enlaces del panel: «tienes 12
   atendidos esperando cierre» tiene que dejar a la persona delante de ESOS 12,
   no de la lista completa para que los busque. */
$fEst   = (string) ($_GET['est'] ?? '');
// «Otros trabajos» (011): por_decidir, AUTORIZADO o NO_AUTORIZADO. El panel
// enlaza aquí con `?otro=por_decidir`.
$fOtro  = (string) ($_GET['otro'] ?? '');
if (!in_array($fOtro, ['por_decidir', 'AUTORIZADO', 'NO_AUTORIZADO'], true)) { $fOtro = ''; }
// «Sin zona» no es una zona más: es la ausencia de una (ASG-21). El buzón no
// podía filtrarla porque `zona=` vacío no filtra nada.
$fDiasAsig = (string) ($_GET['dias_asignado'] ?? '');    // asignados sin informe hace N+ días (ASG-15)
$desdeF = $fDias !== '' ? date('Y-m-d', strtotime('-' . (int) $fDias . ' days')) : null;

$vistos = array_values(array_filter($todos, function ($c) use ($fZona, $fAlert, $fPrio, $fTexto, $fVence, $hoy, $desdeF, $fAtn, $fEst, $fDiasAsig, $fOtro, $porAviso, $gestion) {
    $g = $gestion[$c['aviso'] ?? ''] ?? null;
    if ($fEst !== '' && (($g['estado'] ?? 'NUEVO') !== $fEst)) { return false; }
    if ($fOtro === 'por_decidir' && !Casos::otroTrabajoPorDecidir($c, $g)) { return false; }
    if ($fOtro !== '' && $fOtro !== 'por_decidir' && ($g['otro_trabajo'] ?? null) !== $fOtro) { return false; }
    if ($fAtn !== '') {
        $e = $porAviso[$c['aviso'] ?? '']['estado_industec'] ?? null;
        if ($fAtn === 'sin' && $e !== null) { return false; }
        if ($fAtn === 'curso' && $e !== 'EN_CURSO') { return false; }
        if ($fAtn === 'cerrada' && $e !== 'CERRADA') { return false; }
    }
    if ($desdeF !== null && ($c['fecha_creacion'] ?? '') < $desdeF) { return false; }
    if ($fZona === 'SIN') {
        if (($c['zona'] ?? '') !== '') { return false; }
    } elseif ($fZona !== '' && ($c['zona'] ?? '') !== $fZona) {
        return false;
    }
    if ($fDiasAsig !== '') {
        if (($g['estado'] ?? '') !== 'ASIGNADO') { return false; }
        $d = Ui::dias(substr((string) ($g['asignado_en'] ?? ''), 0, 10) ?: null);
        if ($d === null || $d < (int) $fDiasAsig) { return false; }
    }
    if ($fAlert !== '' && ($c['estado_alerta'] ?? '') !== $fAlert) { return false; }
    if ($fPrio !== '' && ($c['prioridad'] ?? '') !== $fPrio) { return false; }
    if ($fVence && !(($c['fecha_estimada'] ?? '') !== '' && $c['fecha_estimada'] < $hoy)) { return false; }
    if ($fTexto !== '') {
        // Coincidencia parcial y tolerante a guiones/ceros: `2466` encuentra
        // `OT-2466-...`, y `10352936` o `000010352936` dan el mismo aviso.
        // La misma regla la aplica `busqueda.js` en el navegador.
        if (!Ui::coincide([
            $c['aviso'] ?? '', $c['orden_trabajo'] ?? '', $c['local'] ?? '',
            $c['local_nombre'] ?? '', $c['restaurante_sap'] ?? '', $c['activo_fijo'] ?? '',
            $c['descripcion_trabajo'] ?? '', $c['caso'] ?? '',
        ], $fTexto)) { return false; }
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
/* Lo unico que de verdad hay que repartir: llego y nadie lo ha tocado. */
$nSinAsignar = count(array_filter($todos, fn($c) =>
    (($gestion[$c['aviso'] ?? '']['estado'] ?? 'NUEVO') === 'NUEVO')
    && !isset($porAviso[$c['aviso'] ?? ''])));
$porGestion = [];
foreach ($todos as $c) {
    $k = $gestion[$c['aviso'] ?? '']['estado'] ?? 'NUEVO';
    $porGestion[$k] = ($porGestion[$k] ?? 0) + 1;
}
$nCerrIn  = count(array_filter($todos, fn($c) =>
    ($porAviso[$c['aviso'] ?? '']['estado_industec'] ?? '') === 'CERRADA'));
$porZona  = [];
foreach ($todos as $c) { $z = (string) ($c['zona'] ?? ''); $porZona[$z] = ($porZona[$z] ?? 0) + 1; }
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
    ['Pedir seguimiento',  'casos.seguimiento',
     'Le manda al técnico un recordatorio sobre un caso que ya tiene asignado. No le cambia el estado: es un aviso, no una transición.'],
    ['Otros trabajos',     'casos.veredicto',
     'Solo la administración. Para un caso fuera del área: si hubo acuerdo con KFC, lo autoriza como «otro trabajo» con el acuerdo escrito, y se reporta aparte como extra. Si no, el veredicto «no nos compete» lo cierra y se le pide a KFC que lo derive.'],
];

$ROL = ['SUPERADMIN' => 'Superadministrador', 'ADMIN' => 'Administración',
        'JEFE_ZONA' => 'Jefe de zona', 'TECNICO' => 'Técnico'];
$ETIQ_ALERTA = ['CON_ALERTA' => 'con alerta', 'POR_CONFIRMAR' => 'por confirmar',
                'SIN_ALERTA' => 'sin alerta'];

require_once __DIR__ . '/nucleo/Pendientes.php';

$cuentas = ['casos' => $nSinAsignar > 0 ? ['n' => $nSinAsignar] : null];
$pc = Pendientes::contadores();
if ($pc['vencidos'] > 0) { $cuentas['repuestos'] = ['n' => $pc['vencidos'], 'tono' => 'urge']; }

Ui::cabecera($u, 'casos.php', $cuentas, ['titulo' => 'Buzón de casos']);
?>
<div class="wrap ancho">

  <div class="titulo entra">
    <h1>Buzón de casos</h1>
    <p class="sub">
      Lo que Grupo KFC pide por el correo de SAP.
      <?php if ($u['rol'] === 'TECNICO'): ?>
        Ves los casos que te hayan asignado.
      <?php elseif ($zonaAlc): ?>
        Ves los de <b><?= e($zonaAlc) ?></b>, que es tu zona.
      <?php else: ?>
        Ves las tres zonas.
      <?php endif; ?>
    </p>
  </div>

  <?php /* El aviso de exito sale como aviso efimero desde `Ui::pie()`: ya
           ocurrio y no hay nada que hacer con el. El error se queda fijo aqui,
           porque hay que leerlo y actuar. */ ?>
  <?php if ($error): ?><?= Ui::aviso('err', e($error), true) ?><?php endif; ?>

    <?php if (!$fuente): ?>
      <?= Ui::aviso('warn',
          '<b>No hay datos del buzón.</b>'
        . '<p>Falta <span class="mono">catalogos/casos_sap.json</span>, que genera '
        . '<span class="mono">t2_6_imap_avisos.py</span> al leer el correo. Hasta que '
        . 'esté, esta pantalla no puede decir qué hay pendiente — y no va a inventarlo.</p>') ?>
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

      <?php /* =====================================================================
         LA LINEA DE ESTADOS.
         Es el ciclo de vida real de un caso, dibujado y con su cifra. Sirve
         para dos cosas a la vez: filtrar de un clic, y —sobre todo— que
         cualquiera entienda de un vistazo POR DONDE va el trabajo y qué falta
         para cerrarlo. El cierre es de dos manos y eso no se deduce de una
         tabla: el sistema marca ATENDIDO al ver la orden, y la administración
         confirma aparte que además lo cerró en SAP.
         ===================================================================== */ ?>
      <?php
      $PASOS = [
          'NUEVO'           => 'llegó del correo, sin técnico',
          'ASIGNADO'        => 'tiene técnico, se espera el informe',
          'ESPERA_REPUESTO' => 'el equipo quedó trabado',
          'ATENDIDO'        => 'orden emitida; falta cerrarlo en SAP',
          'RESUELTO'        => 'cerrado por las dos partes',
      ];
      ?>
      <nav class="linea" aria-label="Estados del caso">
        <?php foreach ($PASOS as $k => $ayuda): ?>
          <a href="?est=<?= $k ?>" class="<?= $fEst === $k ? 'on' : '' ?>"
             title="<?= e(Ui::ayudaEstado($k)) ?>">
            <div class="paso-n" data-n="<?= (int) ($porGestion[$k] ?? 0) ?>">0</div>
            <div class="paso-t"><?= e(Ui::etiquetaEstado($k)) ?></div>
            <div class="paso-d"><?= e($ayuda) ?></div>
          </a>
        <?php endforeach; ?>
      </nav>
      <?php
      $aparte = [];
      foreach (['EN_REVISION', 'NO_COMPETE', 'CERRADO_SIN_ATENCION'] as $k) {
          if (!empty($porGestion[$k])) { $aparte[$k] = $porGestion[$k]; }
      }
      ?>
      <?php if ($aparte): ?>
        <p class="sub" style="margin:-8px 0 16px">
          Fuera de esa línea:
          <?php foreach ($aparte as $k => $cn): ?>
            <a href="?est=<?= $k ?>" style="text-decoration:none">
              <span class="est est-<?= e(strtolower($k)) ?>"><?= (int) $cn ?> <?= e(Ui::etiquetaEstado($k)) ?></span>
            </a>
          <?php endforeach; ?>
        </p>
      <?php endif; ?>

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
                <?php $val = $z === '' ? 'SIN' : $z; $etq = $z === '' ? 'Sin zona' : $z; ?>
                <option value="<?= e($val) ?>" <?= $fZona === $val ? 'selected' : '' ?>>
                  <?= e($etq) ?> (<?= $n ?>)
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
          <input type="search" id="f-q" name="q" value="<?= e($fTexto) ?>"
                 placeholder="parte del aviso, del local o del equipo"
                 data-busca="#tabla-casos" data-busca-cuenta="#cuenta-casos">
        </div>
        <div class="campo">
          <label for="f-est">Estado</label>
          <select id="f-est" name="est">
            <option value="">Cualquiera</option>
            <?php foreach (Ui::ESTADOS as $k => [$et, $_]): ?>
              <option value="<?= e($k) ?>" <?= $fEst === $k ? 'selected' : '' ?>>
                <?= e($et) ?><?= !empty($porGestion[$k]) ? ' (' . (int) $porGestion[$k] . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
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
        <?php if ($u['rol'] !== 'TECNICO'): ?>
          <div class="campo">
            <label for="f-otro">Otros trabajos</label>
            <select id="f-otro" name="otro">
              <option value="">—</option>
              <option value="por_decidir"   <?= $fOtro === 'por_decidir' ? 'selected' : '' ?>>Fuera del área, por decidir</option>
              <option value="AUTORIZADO"    <?= $fOtro === 'AUTORIZADO' ? 'selected' : '' ?>>Autorizados</option>
              <option value="NO_AUTORIZADO" <?= $fOtro === 'NO_AUTORIZADO' ? 'selected' : '' ?>>No autorizados</option>
            </select>
          </div>
        <?php endif; ?>
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
        <?php if ($fZona || $fAlert || $fPrio || $fTexto || $fVence || $fDias !== '' || $fAtn || $fEst || $fDiasAsig !== '' || $fOtro !== ''): ?>
          <div class="campo">
            <label>&nbsp;</label>
            <a class="btn" href="casos.php" style="height:38px;display:flex;align-items:center">Limpiar</a>
          </div>
        <?php endif; ?>
      </form>

      <p class="sub" style="margin:0 0 8px">
        <b id="cuenta-casos" data-plantilla="{n}"><?= count($vistos) ?></b>
        de <?= count($todos) ?> casos
        <?= count($vistos) === count($todos) ? '' : '(filtrados)' ?>.
        Primero los que tienen alerta; dentro de cada grupo, el más nuevo arriba.
      </p>

      <div class="tabla-wrap">
        <table id="tabla-casos">
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
            /* data-b: lo que el buscador en vivo compara. Mismos campos que
               Ui::coincide() arriba, para que filtrar con y sin JS coincida. */
            $claveFila = Ui::claveFila([
                $c['aviso'] ?? '', $c['orden_trabajo'] ?? '', $c['local'] ?? '',
                $c['local_nombre'] ?? '', $c['restaurante_sap'] ?? '', $c['activo_fijo'] ?? '',
                $c['descripcion_trabajo'] ?? '', $c['caso'] ?? '',
            ]);
            ?>
            <tr class="<?= $conAlerta ? 'con-alerta' : '' ?>" data-b="<?= $claveFila ?>">
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
                  <?= Ui::zona($c['zona']) ?>
                  <?php if (!empty($c['zona_discrepa'])): ?>
                    <span class="alerta-txt">llegó al buzón de <?= e($c['zona_por_buzon'] ?? '?') ?></span>
                  <?php endif; ?>
                <?php else: ?>
                  <?= Ui::zona(null) ?>
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
              <td>
                <?= Ui::prioridad($c['prioridad'] ?? null) ?>
                <?php /* La antiguedad al lado de la prioridad, no en otra
                         columna: juntas responden «esto es urgente Y lleva
                         mucho», que es lo que decide qué se atiende primero. */ ?>
                <span class="desc"><?= Ui::edad($c['fecha_creacion'] ?? null) ?></span>
              </td>
              <td>
                <?php
                /* La atención sale de TODAS las fuentes del caso (Casos::documentos),
                   no solo de `atenciones.json`: con solo esa, un caso con su orden
                   de cierre en la gestión salía «sin atender» (10354415, 10354383). */
                $av0 = (string) ($c['aviso'] ?? '');
                $a   = $porAviso[$av0] ?? null;
                $ats = $atencion($av0);
                ?>
                <?php if ($ats === null): ?>
                  <span class="sub">sin atender</span>
                  <span class="desc mono">creado <?= e($c['fecha_creacion'] ?? '—') ?></span>
                <?php else: ?>
                  <span class="chip <?= $ats === 'CERRADA' ? 'cerrada' : 'curso' ?>">
                    <?= $ats === 'CERRADA' ? 'con orden de cierre' : 'atendido, en curso' ?>
                  </span>
                  <?php foreach ($docs[$av0] ?? [] as $d): ?>
                    <?php /* El enlace solo si el PDF está en el servidor: armado a
                             ciegas caía en «El PDF de esa orden no está en el
                             servidor.» (00870b4). Todos los documentos del aviso,
                             aunque sean dos nombres del mismo informe. */ ?>
                    <span class="desc mono">
                      <?php if (Auth::puede('ots.pdf') && $d['pdf']): ?>
                        <a href="pdf.php?ot=<?= rawurlencode($d['ot']) ?>"
                           target="_blank" rel="noopener"><?= e($d['ot']) ?></a>
                      <?php else: ?>
                        <?= e($d['ot']) ?>
                      <?php endif; ?>
                      <?= $d['fecha'] !== null ? '· ' . e($d['fecha']) : '' ?>
                      <?php if ($d['cierre']): ?><b>· cierre</b><?php endif; ?>
                      <?php if (Auth::puede('ots.pdf') && !$d['pdf']): ?>
                        <span class="derivado">PDF no cargado al archivo todavía</span>
                      <?php endif; ?>
                    </span>
                  <?php endforeach; ?>
                  <?php if (!empty($a['tecnicos'])): ?>
                    <span class="desc"><?= e(implode(' · ', $a['tecnicos'])) ?></span>
                  <?php endif; ?>
                  <?php foreach (($a['sin_identificar'] ?? []) as $s): ?>
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
                <div class="acciones-fila" data-aviso="<?= $av ?>" data-zona="<?= e($c['zona'] ?? '') ?>">
                  <?php if (Auth::puede('casos.asignar') && Casos::puedeTransitar('asignar', $est)): ?>
                    <button class="btn" type="button" data-accion="asignar">
                      <?= $est === 'ASIGNADO' || $est === 'ATENDIDO' || $est === 'ESPERA_REPUESTO' ? 'Reasignar' : 'Asignar' ?>
                    </button>
                  <?php endif; ?>

                  <?php if ($est === 'ATENDIDO' && Auth::puede('casos.veredicto')): ?>
                    <?php /* Lo que la administradora tiene que hacer con este
                             caso: el trabajo esta hecho y falta su parte. Se
                             pone primero y destacado porque es SU pendiente. */ ?>
                    <button class="btn primary" type="button" data-accion="cerrado_sap">
                      Ya lo cerré en SAP
                    </button>
                  <?php endif; ?>

                  <?php /* Con el equipo esperando repuesto no se da veredicto
                           desde aquí (ASG-01): el cierre de dos manos empieza
                           en Pendientes, no en el buzón. */ ?>
                  <?php if ($est === 'ESPERA_REPUESTO'): ?>
                    <a class="btn" href="pendientes.php?q=<?= rawurlencode((string) ($c['aviso'] ?? '')) ?>">Ver pendiente</a>
                  <?php endif; ?>

                  <?php if ($est === 'CERRADO_SIN_ATENCION' && empty($g['regularizado_en'])
                            && Auth::puede('casos.veredicto')): ?>
                    <button class="btn primary" type="button" data-accion="regularizar">
                      Regularizar
                    </button>
                  <?php endif; ?>

                  <?php if (Auth::puede('casos.revision') && Casos::puedeTransitar('revision', $est)): ?>
                    <button class="btn" type="button" data-accion="revision">En revisión</button>
                  <?php endif; ?>

                  <?php if (Auth::puede('casos.veredicto') && $est !== 'ATENDIDO'
                            && $est !== 'CERRADO_SIN_ATENCION' && $est !== 'ESPERA_REPUESTO'): ?>
                    <button class="btn" type="button" data-accion="veredicto">Veredicto</button>
                  <?php endif; ?>

                  <?php if (Auth::puede('casos.derivar') && Casos::puedeTransitar('derivar', $est)): ?>
                    <button class="btn" type="button" data-accion="derivar">Derivar</button>
                  <?php endif; ?>

                  <?php if (Auth::puede('casos.seguimiento') && Casos::puedeTransitar('seguimiento', $est)): ?>
                    <button class="btn" type="button" data-accion="seguimiento">Pedir seguimiento</button>
                  <?php endif; ?>

                  <?php /* «Otros trabajos» (011): la decisión de la administradora
                           sobre un caso fuera del área, o cambiarla. Solo donde
                           hay algo que decidir: con alerta de alcance o ya decidido. */ ?>
                  <?php if (Auth::puede('casos.veredicto') && (Casos::fueraDeArea($c) || !empty($g['otro_trabajo']))): ?>
                    <button class="btn" type="button" data-accion="otro_trabajo">Otro trabajo</button>
                  <?php endif; ?>
                </div>

                <?php if ($g): ?>
                  <span class="desc">
                    <?= Ui::estado($est) ?>
                    <?php if (!empty($g['tecnico_nombre'])): ?>
                      · <?= e($g['tecnico_nombre']) ?><?= $g['tecnico_auto'] ? ' (del informe)' : '' ?>
                    <?php endif; ?>
                  </span>
                  <?php /* El informe con el que se atendió, junto al botón que lo
                           cierra en SAP: la administradora lo necesita para
                           registrarlo y antes tenía que ir a buscarlo al Archivo
                           (pedido de Andrés, 2026-09-14). Solo administración:
                           es su mano del cierre de dos manos. */ ?>
                  <?php if (!empty($g['ot_cierre']) && Auth::puede('casos.veredicto')): ?>
                    <?php $oc = (string) $g['ot_cierre']; ?>
                    <span class="desc">informe:
                      <?php if (Auth::puede('ots.pdf') && Emision::existePdf($oc)): ?>
                        <a class="mono" href="pdf.php?ot=<?= rawurlencode($oc) ?>" target="_blank" rel="noopener"><?= e($oc) ?></a>
                      <?php else: ?>
                        <span class="mono"><?= e($oc) ?></span>
                        <span class="derivado">PDF no cargado al archivo todavía</span>
                      <?php endif; ?>
                    </span>
                  <?php endif; ?>
                  <?php if (!empty($g['revision_motivo']) && $est === 'EN_REVISION'): ?>
                    <span class="desc" style="color:#92400e"><?= e($g['revision_motivo']) ?></span>
                  <?php endif; ?>
                  <?php if (!empty($g['regularizado_en'])): ?>
                    <span class="desc" style="color:#166534">regularizado</span>
                  <?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($g['otro_trabajo'])): ?>
                  <span class="desc" style="color:<?= $g['otro_trabajo'] === 'AUTORIZADO' ? '#166534' : '#64748b' ?>"
                        title="<?= e(($g['otro_trabajo_nombre'] ?? '') . ', ' . substr((string) ($g['otro_trabajo_en'] ?? ''), 0, 10)) ?>">
                    otro trabajo · <?= $g['otro_trabajo'] === 'AUTORIZADO' ? 'autorizado' : 'no autorizado' ?>:
                    <?= e($g['otro_trabajo_motivo'] ?? '') ?>
                  </span>
                <?php elseif (Casos::otroTrabajoPorDecidir($c, $g)): ?>
                  <span class="desc" style="color:#92400e">fuera del área: falta decidir si es un otro trabajo</span>
                <?php endif; ?>
                <?php /* La continuidad (T2.25). Aquí es donde se AUDITA: el
                         técnico declara el enlace sin pedir permiso, y esto es
                         lo que deja ver al jefe de zona y a la administración
                         por qué este aviso no tiene una orden propia. Sin esta
                         línea, un caso ATENDIDO sin orden suya no se distingue
                         de un error. */ ?>
                <?php if (!empty($g['continua_de'])): ?>
                  <span class="desc" style="color:#1d4ed8"
                        title="<?= e(($g['continua_nombre'] ?? 'alguien') . ' lo declaró el '
                                     . substr((string) ($g['continua_en'] ?? ''), 0, 16)
                                     . ($g['continua_nota'] ? ': ' . $g['continua_nota'] : '')) ?>">
                    continúa el aviso <?= e((string) $g['continua_de']) ?><?php
                      if (!empty($g['continua_ot'])): ?> · orden <?= e((string) $g['continua_ot']) ?><?php
                      else: ?> · sin orden propia<?php endif; ?>
                  </span>
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

<?php /* El diálogo de acciones.
         Uno solo para toda la tabla, no cuatro formularios por fila: con 900
         casos serían miles de formularios en el HTML, y la página se arrastra.
         El aviso y la acción se rellenan al pulsar. */ ?>
<dialog id="acc">
  <form method="post" id="acc-form">
    <input type="hidden" name="csrf" value="<?= e(Auth::csrfToken()) ?>">
    <input type="hidden" name="accion" id="acc-accion">
    <input type="hidden" name="aviso"  id="acc-aviso">
    <h2 id="acc-titulo" style="margin:0 0 4px;font-size:17px"></h2>
    <p class="sub" id="acc-ayuda" style="margin:0 0 12px"></p>

    <div id="acc-tecnico" hidden>
      <label for="acc-tec">Técnico</label>
      <select name="tecnico" id="acc-tec">
        <option value="">Elige…</option>
      </select>
      <?php /* Solo aparece cuando se elige un técnico de otra zona (ASG-03, D4):
               una asignación entre zonas se permite, pero no en silencio. */ ?>
      <label id="acc-confirmo-zona-wrap" hidden
             style="display:flex;gap:6px;align-items:flex-start;margin-top:8px;font-size:12.5px;font-weight:400">
        <input type="checkbox" name="confirmo_zona" id="acc-confirmo-zona" value="1" style="margin-top:2px">
        <span>Sé que es de otra zona y quiero asignarlo igual.</span>
      </label>
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
        <option value="NO_COMPETE">No es trabajo de INDUSTEC: se cierra y se pide a KFC que lo derive</option>
      </select>
    </div>

    <?php /* «Otros trabajos» (011). El motivo es obligatorio: si se autoriza, es
             el acuerdo con KFC que respalda el trabajo extra ante el cliente. */ ?>
    <div id="acc-otro" hidden>
      <label for="acc-ot">Decisión</label>
      <select name="decision" id="acc-ot">
        <option value="AUTORIZADO">Autorizar como otro trabajo: hubo acuerdo con KFC</option>
        <option value="NO_AUTORIZADO">No autorizar: no se cuenta como extra</option>
      </select>
    </div>

    <div id="acc-motivo" hidden>
      <label for="acc-mt" id="acc-mt-label">Motivo</label>
      <textarea name="motivo" id="acc-mt" rows="3"
                placeholder="En una línea, para que quien lo lea después entienda"></textarea>
    </div>

    <div id="acc-texto" hidden>
      <label for="acc-tx" id="acc-tx-label">Qué le pides</label>
      <textarea name="texto" id="acc-tx" rows="3"
                placeholder="En una línea: qué necesitas que te cuente o haga"></textarea>
    </div>

    <div class="row" style="margin-top:14px;gap:8px">
      <button class="btn primary" type="submit" id="acc-ok">Confirmar</button>
      <button class="btn" type="button" onclick="document.getElementById('acc').close()">Cancelar</button>
    </div>
  </form>
</dialog>

<script>
/* Qué pide cada acción. Sale de aquí y no del HTML de cada fila para que el
   texto que ve la persona esté en un solo sitio y no en 900 copias. */
var ACC = {
  asignar:     { t:'Asignar el caso',            a:'Le va a aparecer en su lista de órdenes. Queda registrado quién lo asignó.',
                 campos:['tecnico'], ok:'Asignar' },
  seguimiento: { t:'Pedir seguimiento',          a:'Le aparece al técnico en sus avisos, dentro de la app. No le cambia el estado al caso.',
                 campos:['tecnico','texto'], ok:'Pedir' },
  revision:    { t:'Mandar a revisión',          a:'Va a los pendientes de administración con el motivo que escribas.',
                 campos:['motivo'], ok:'Mandar', motivo:'Por qué lo mandas' },
  veredicto:   { t:'Dar veredicto',              a:'Resuelve si el caso nos compete. Queda tu nombre y la fecha.',
                 campos:['veredicto','motivo'], ok:'Guardar', motivo:'Motivo (obligatorio si no nos compete)' },
  derivar:     { t:'Derivar a otra zona',        a:'El caso pasa a la otra zona y queda SIN asignar: el técnico que lo tenía ya no puede atenderlo.',
                 campos:['zona'], ok:'Derivar' },
  cerrado_sap: { t:'Confirmar el cierre en SAP', a:'INDUSTEC ya emitió la orden de cierre. Esto es que además ya lo cerraste en SAP, que es lo que el correo nunca avisa.',
                 campos:['motivo'], ok:'Confirmar', motivo:'Nota (opcional)' },
  regularizar: { t:'Marcar como regularizado',   a:'Se cerró por falta de atención. Esto deja de contarlo como pendiente tuyo; el caso sigue constando como no atendido.',
                 campos:['motivo'], ok:'Regularizar', motivo:'Qué se hizo (opcional)' },
  otro_trabajo: { t:'Otros trabajos',           a:'Un trabajo fuera del área de INDUSTEC hecho por acuerdo con KFC se autoriza aquí: se cuenta y se reporta aparte, como extra, y el caso sigue su flujo normal. Si no hubo acuerdo, lo que corresponde es el veredicto «no nos compete».',
                  campos:['otro','motivo'], ok:'Guardar', motivo:'El acuerdo con KFC (o por qué no se autoriza)' }
};
/* Los técnicos asignables, para reconstruir el <select> según la zona del
   caso que se abrió: solo los suyos, y los de otras zonas aparte y aparte
   (ASG-03, D4). Sale de aquí y no de un <option> fijo porque la misma lista
   sirve para 900 filas de zonas distintas con un solo diálogo. */
var TECNICOS = <?= json_encode(array_map(
    fn($t) => ['id' => (int) $t['usuario_id'], 'nombre' => $t['nombre'], 'zona' => (string) $t['zona']],
    Casos::tecnicosAsignables()
), JSON_UNESCAPED_UNICODE) ?>;

function opcionesTecnico(zona, permitirOtras) {
  var sel = document.getElementById('acc-tec');
  sel.innerHTML = '';
  sel.add(new Option('Elige…', ''));
  var propios = TECNICOS.filter(function (t) { return t.zona === zona; });
  var otros   = TECNICOS.filter(function (t) { return t.zona !== zona; });
  propios.forEach(function (t) {
    var o = new Option(t.nombre + ' (' + t.zona + ')', t.id);
    o.dataset.zona = t.zona;
    sel.add(o);
  });
  if (permitirOtras && otros.length) {
    var og = document.createElement('optgroup');
    og.label = 'Otras zonas (confirmar)';
    otros.forEach(function (t) {
      var o = new Option(t.nombre + ' · ' + t.zona, t.id);
      o.dataset.zona = t.zona;
      og.appendChild(o);
    });
    sel.appendChild(og);
  }
}
document.getElementById('acc-tec').addEventListener('change', function () {
  var opt = this.options[this.selectedIndex];
  var zonaForm = document.getElementById('acc-form').dataset.zona || '';
  var otra = document.getElementById('acc-accion').value === 'asignar'
           && opt && opt.dataset.zona && opt.dataset.zona !== zonaForm;
  document.getElementById('acc-confirmo-zona-wrap').hidden = !otra;
  if (!otra) { document.getElementById('acc-confirmo-zona').checked = false; }
});
document.getElementById('acc-form').addEventListener('submit', function (ev) {
  var wrap = document.getElementById('acc-confirmo-zona-wrap');
  if (document.getElementById('acc-accion').value === 'asignar'
      && !wrap.hidden && !document.getElementById('acc-confirmo-zona').checked) {
    ev.preventDefault();
    alert('Marca que confirmas la zona antes de asignar a otra zona.');
  }
});

function abrir(accion, aviso, zona) {
  var c = ACC[accion];
  document.getElementById('acc-form').dataset.zona = zona || '';
  document.getElementById('acc-accion').value = accion;
  document.getElementById('acc-aviso').value  = aviso;
  document.getElementById('acc-titulo').textContent = c.t + ' · ' + aviso;
  document.getElementById('acc-ayuda').textContent  = c.a;
  document.getElementById('acc-ok').textContent     = c.ok;
  ['tecnico','zona','veredicto','otro','motivo','texto'].forEach(function (k) {
    document.getElementById('acc-' + k).hidden = c.campos.indexOf(k) === -1;
  });
  document.getElementById('acc-confirmo-zona-wrap').hidden = true;
  document.getElementById('acc-confirmo-zona').checked = false;
  var mt = document.getElementById('acc-mt');
  mt.value = '';
  mt.required = (accion === 'revision' || accion === 'otro_trabajo');
  if (c.motivo) { document.getElementById('acc-mt-label').textContent = c.motivo; }
  var tx = document.getElementById('acc-tx');
  tx.value = '';
  tx.required = (accion === 'seguimiento');
  if (c.campos.indexOf('tecnico') !== -1) {
    opcionesTecnico(zona || '', accion === 'asignar');
  }
  document.getElementById('acc-tec').required = (accion === 'asignar' || accion === 'seguimiento');
  document.getElementById('acc').showModal();
}
/* Un solo listener para las 900 filas: cada botón lleva `data-accion`, y la
   fila que lo contiene lleva `data-aviso`/`data-zona` (ASG-14). El aviso
   incrustado en un `onclick` viajaba sin escapar de JS -- solo de HTML --, y
   un aviso con una comilla que llegara del correo de SAP ejecutaría código en
   la sesión con más privilegios del sistema. */
document.getElementById('tabla-casos').addEventListener('click', function (ev) {
  var b = ev.target.closest('[data-accion]');
  if (!b) { return; }
  var fila = b.closest('[data-aviso]');
  if (!fila) { return; }
  abrir(b.dataset.accion, fila.dataset.aviso, fila.dataset.zona);
});
</script>

<?php Ui::pie(['novedades' => true, 'js' => ['busqueda.js']]); ?>
