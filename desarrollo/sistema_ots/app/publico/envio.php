<?php
declare(strict_types=1);

require_once __DIR__ . '/nucleo/Auth.php';
require_once __DIR__ . '/nucleo/Validacion.php';
require_once __DIR__ . '/nucleo/Pendientes.php';
require_once __DIR__ . '/nucleo/Novedades.php';
require_once __DIR__ . '/nucleo/Catalogo.php';

/**
 * envio.php — Donde aterriza la orden que el técnico llenó, con señal o sin ella.
 *
 * ============================================================================
 * LA IDEMPOTENCIA ES EL PUNTO ENTERO DE ESTE ARCHIVO
 *
 * Sin señal, el reintento es la norma: la red vuelve a medias, el envío sale,
 * la respuesta no llega, y el celular reintenta. Si cada intento creara una
 * orden, el resultado sería un correlativo quemado y un segundo PDF a Grupo
 * KFC — que es exactamente el error que nadie perdona.
 *
 * Por eso el `envio_uuid` lo genera el CELULAR antes del primer intento, viaja
 * con todos, y es UNIQUE en la base. El segundo intento no inserta: devuelve el
 * mismo recibo. Para el técnico es indistinguible; para la base, es una sola
 * orden.
 *
 * La orden, el equipo trabado y las novedades se guardan en UNA transacción:
 * si algo falla a mitad, no queda nada y el reintento lo rehace todo. Separados,
 * un fallo después de guardar la orden dejaba el equipo trabado sin registrar
 * para siempre, porque el reintento la veía «ya recibida» (revisión del
 * 2026-09-10).
 *
 * ============================================================================
 * LAS RESPUESTAS Y SU SIGNIFICADO. El cliente las trata distinto.
 *
 *   200  llegó y está guardada. El celular la borra de su cola.
 *   409  la llenó otro usuario en este mismo celular. Se queda en la cola
 *        hasta que entre él: con la sesión de otro saldría firmada por otro.
 *   4xx  llegó y NO sirve (falta un campo, el local no existe). Reintentar no
 *        la arregla: el celular la marca y se la enseña al técnico.
 *   5xx  el problema es nuestro o del camino. El celular reintenta.
 *
 * Confundir el 4xx con el 5xx es lo que convierte una cola en un bucle.
 *
 * ============================================================================
 * LAS REGLAS SON LAS MISMAS EN LOS TRES SITIOS
 *
 * `Validacion.php` es el mismo juego de reglas que corre en el navegador
 * (`reglas.js`) y en la estación (`t2_5_validacion.py`), con su fixture de 32
 * casos que pasa en los tres. Aquí se vuelve a correr **en el servidor** y no
 * por desconfianza del navegador: es que sin señal el navegador puede estar
 * corriendo una versión vieja de las reglas, guardada en la caché hace un mes.
 * Se valida contra el mismo catálogo que ve el formulario: `Catalogo::cargar()`.
 *
 * ============================================================================
 * LO QUE ESTE ARCHIVO NO HACE, Y SE DICE CLARO (I-7)
 *
 * No reserva el correlativo, no genera el PDF y no manda el correo. Eso
 * necesita una migración NUEVA de app/sql —correlativos con reserva atómica y
 * cola de correo— que todavía no está escrita: la «003» que citaban los
 * documentos es de la base de la estación. Tampoco llegan todavía las fotos ni
 * la imagen de la firma, así que la app nueva NO reemplaza aún al formulario
 * que genera el PDF. La orden queda a salvo en `ot_capturadas` y el recibo lo
 * dice con esas palabras.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $codigo, array $cuerpo): never
{
    http_response_code($codigo);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(405, ['ok' => false, 'motivo' => 'solo POST']);
}

// `exigir` en modo JSON: sin sesión responde 401 en JSON en vez de mandar una
// redirección al login, que el `fetch` de la cola guardaría como si fuera la
// respuesta del envío.
$u = Auth::exigir('ots.crear', true);

$crudo = file_get_contents('php://input') ?: '';
if (strlen($crudo) > 12 * 1024 * 1024) {
    responder(413, ['ok' => false, 'motivo' => 'la orden pesa demasiado; reduce las fotos']);
}
$j = json_decode($crudo, true);
if (!is_array($j)) {
    responder(400, ['ok' => false, 'motivo' => 'el cuerpo no es JSON válido']);
}

$uuid = (string) ($j['envio_uuid'] ?? '');
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
    // Sin un UUID con forma no hay idempotencia posible, y sin idempotencia no
    // se acepta el envío: es preferible que el técnico vea un error a que se
    // dupliquen órdenes en silencio.
    responder(400, ['ok' => false, 'motivo' => 'falta el identificador del envío']);
}

$orden = $j['orden'] ?? null;
if (!is_array($orden)) {
    responder(400, ['ok' => false, 'motivo' => 'el envío no trae la orden']);
}

/* -------------------------------------------------------------------------
   LA ORDEN ES DE QUIEN LA LLENÓ.

   La cola vive en el celular, no en la sesión. Si en un celular compartido
   entra otro técnico, los reintentos saldrían con la sesión de él y la orden
   quedaría firmada por quien no hizo el trabajo. Se corta aquí, en el servidor.
   ------------------------------------------------------------------------- */
$captor = (int) ($j['usuario_captura'] ?? 0);
if ($captor !== 0 && $captor !== (int) $u['usuario_id']) {
    Auth::bitacora('ENVIO_AJENO', 'ot', $uuid, 'la orden la llenó otro usuario',
                   null, null, ['usuario_captura' => $captor], false);
    responder(409, ['ok' => false, 'ajena' => true,
                    'motivo' => 'esta orden la llenó otro usuario en este celular; tiene que entrar él para enviarla']);
}
try {
    $previa = Db::uno('SELECT usuario_id FROM ot_capturadas WHERE envio_uuid = ?', [$uuid]);
} catch (Throwable $ex) {
    $previa = null;                     // sin la 007, el INSERT de abajo responde 503
}
if ($previa !== null && (int) $previa['usuario_id'] !== (int) $u['usuario_id']) {
    responder(409, ['ok' => false, 'ajena' => true,
                    'motivo' => 'ese envío ya está registrado a nombre de otro usuario']);
}

/* -------------------------------------------------------------------------
   LA IDENTIDAD SALE DE LA SESION, NO DE LA ORDEN.

   Aunque el celular mande un `tecnico`, el primero de la lista siempre es
   quien tiene la sesión: es lo que valida la regla y lo que queda como firma.
   Los acompañantes se conservan detrás.
   ------------------------------------------------------------------------- */
$orden['tecnico_usuario_id'] = (int) $u['usuario_id'];
$orden['tecnico_nombre']     = $u['nombre'];
unset($orden['tecnico_sesion']);
$acompanantes = array_slice(array_map('trim', explode(',', (string) ($orden['tecnico'] ?? ''))), 1);
$orden['tecnico'] = implode(', ', array_values(array_filter(
    array_merge([(string) $u['nombre']], $acompanantes), static fn($n) => $n !== '')));

/* -------------------------------------------------------------------------
   Validación con las reglas compartidas, contra el catálogo del formulario.
   ------------------------------------------------------------------------- */
$catalogo = Catalogo::cargar();
if ($catalogo === null) {
    // Sin catálogo no se valida, y lo que no se valida no se acepta: 503 para
    // que la cola reintente. Antes, sin catálogo, la orden entraba a ciegas.
    error_log('envio.php: faltan los catálogos del formulario');
    responder(503, ['ok' => false,
                    'motivo' => 'el sistema no puede validar órdenes en este momento; la tuya queda guardada en el celular y se envía sola']);
}

// Lo que el celular no decide: la zona y la cadena salen del maestro del
// local, y «hoy» es la fecha del servidor en Ecuador, no la del teléfono.
foreach ($catalogo['locales'] as $l) {
    if (($l['codigo'] ?? null) === ($orden['local'] ?? null)) {
        $orden['zona']   = $l['zona'] ?? null;
        $orden['cadena'] = $l['cadena'] ?? null;
        break;
    }
}
$orden['_hoy'] = (new DateTimeImmutable('now', new DateTimeZone('America/Guayaquil')))->format('Y-m-d');

$v = new Validacion($catalogo);
$hallazgos = $v->validar($orden, 'CAPTURA');
if (Validacion::bloquea($hallazgos)) {
    $motivos = [];
    foreach ($hallazgos as $h) {
        if ($h->severidad === Validacion::BLOQUEA) { $motivos[] = $h->mensaje; }
    }
    Auth::bitacora('ENVIO_RECHAZADO', 'ot', $uuid,
                   implode(' · ', array_slice($motivos, 0, 3)),
                   null, 'RECHAZADA', ['motivos' => $motivos], false);
    // 400: llegó bien, no sirve. El celular NO la reintenta.
    responder(400, ['ok' => false,
                    'motivo' => implode('. ', array_slice($motivos, 0, 3)),
                    'campos' => $motivos]);
}

/* -------------------------------------------------------------------------
   LO QUE SE ACEPTA Y SE MARCA.

   Una orden firmada no se rechaza por esto —el trabajo se hizo—, pero queda
   marcada en la carga y en la bitácora:
     - el aviso no está entre los casos vigentes de quien la emite (el
       formulario lo precarga desde la asignación, pero un POST se fabrica);
     - el local no coincide con el del caso;
     - el reloj del celular dice algo imposible.
   ------------------------------------------------------------------------- */
$observaciones = [];
$aviso = trim((string) ($orden['aviso'] ?? ''));
if ($aviso !== '') {
    $caso = Casos::alcanzaAviso($aviso, Casos::gestion());
    if ($caso === null) {
        $observaciones[] = 'AVISO_FUERA_DE_ALCANCE';
    } elseif (!empty($caso['local']) && $caso['local'] !== ($orden['local'] ?? null)) {
        $observaciones[] = 'LOCAL_DISTINTO_AL_DEL_CASO';
    }
}

// La fecha en que se LLENO, no en la que llegó: entre las dos puede haber
// horas sin cobertura, y para medir tiempos de atención vale la primera. Pero
// el reloj del teléfono no manda sin límite: fuera de [-7 días, +10 min] se
// toma la hora de llegada y se marca. Va con FROM_UNIXTIME para quedar en el
// mismo reloj que `recibida_en`: la base corre en UTC y el PHP de la web no.
$t = strtotime((string) ($j['capturada_en'] ?? ''));
if ($t === false || $t > time() + 600 || $t < time() - 7 * 86400) {
    $observaciones[] = 'FECHA_DE_CAPTURA_DUDOSA';
    $t = time();
}
if ($observaciones) {
    $orden['_observaciones'] = $observaciones;
}

/* -------------------------------------------------------------------------
   SE GUARDA, EN UNA SOLA TRANSACCION.

   `$nueva` sale del propio INSERT: con ON DUPLICATE KEY UPDATE, MariaDB
   devuelve 1 si insertó y 2 o 0 si la fila ya estaba. Así, dos envíos del
   mismo UUID a la vez no procesan dos veces lo que viene dentro: el segundo
   espera el candado de la clave única y encuentra la fila.

   Lo que viene dentro de la orden y es otra cosa:

     `pendiente`  el equipo que quedó sin concluir. Desde que se registra
                  corre el plazo de 48 horas para decidir la vía.
     `novedades`  lo que el técnico vio y no era su orden.

   El plazo se cuenta desde que el técnico lo registró en su celular —acotado
   a las últimas 72 horas en Pendientes::abrir—, no desde que llegó: el equipo
   estuvo parado todo ese tiempo.
   ------------------------------------------------------------------------- */
$anexos = [];
$pdo = Db::conn();
try {
    $pdo->beginTransaction();

    $nueva = Db::ejecutar(
        'INSERT INTO ot_capturadas
            (envio_uuid, usuario_id, aviso, local_codigo, zona, cadena, modulo,
             concluida, carga, capturada_en)
         VALUES (?,?,?,?,?,?,?,?,?,FROM_UNIXTIME(?))
         ON DUPLICATE KEY UPDATE
            /* El reintento no crea nada: refresca la carga por si el técnico
               corrigió algo antes de que saliera, y solo si es del mismo. */
            carga       = IF(usuario_id = VALUES(usuario_id), VALUES(carga), carga),
            recibida_en = IF(usuario_id = VALUES(usuario_id), NOW(), recibida_en)',
        [
            $uuid,
            (int) $u['usuario_id'],
            $aviso !== '' ? mb_substr($aviso, 0, 20) : null,
            ($orden['local'] ?? '') !== '' ? mb_substr((string) $orden['local'], 0, 12) : null,
            in_array($orden['zona'] ?? '', ['UIO', 'LARB', 'CNLJ', 'OTRA'], true) ? $orden['zona'] : null,
            ($orden['cadena'] ?? '') !== '' ? mb_substr((string) $orden['cadena'], 0, 40) : null,
            in_array(strtoupper((string) ($orden['tipo'] ?? '')), ['CORRECTIVO', 'PREVENTIVO'], true)
                ? strtoupper((string) $orden['tipo']) : null,
            isset($orden['concluida']) ? (int) (bool) $orden['concluida'] : null,
            json_encode($orden, JSON_UNESCAPED_UNICODE),
            $t,
        ]
    ) === 1;

    if (!$nueva) {
        $anexos[] = 'Esta orden ya se había recibido: no se registró dos veces.';
    } else {
        $pen = $orden['pendiente'] ?? null;
        if (is_array($pen) && trim((string) ($pen['diagnostico'] ?? '')) !== '') {
            if ($aviso === '') {
                /* Sin aviso no hay caso al que colgar el pendiente, y
                   `Pendientes` se apoya en el aviso para resolver el alcance.
                   Queda dentro de la carga JSON —no se pierde— y se dice en el
                   recibo: es tarea de la administración regularizarlo primero. */
                $anexos[] = 'El equipo trabado quedó anotado en la orden, pero esta orden no tiene '
                          . 'aviso de SAP, así que todavía no puede entrar al control de 48 horas. '
                          . 'La administración tiene que regularizarla primero.';
                Auth::bitacora('PENDIENTE_SIN_AVISO', 'ot', $uuid,
                               mb_substr((string) $pen['diagnostico'], 0, 150),
                               null, null, ['pendiente' => $pen], true);
            } else {
                [$ok, $msg, $idPen] = Pendientes::abrir([
                    'aviso'         => $aviso,
                    'activo_fijo'   => $pen['activo_fijo'] ?? '',
                    'equipo_desc'   => $pen['equipo_desc'] ?? '',
                    'diagnostico'   => $pen['diagnostico'],
                    'parte'         => $pen['parte'] ?? '',
                    'deshabilitado' => !empty($pen['deshabilitado']),
                    'abierto_ts'    => $t,
                ]);
                $anexos[] = $ok ? $msg : ('No se pudo registrar el equipo trabado: ' . $msg
                                        . ' Quedó anotado dentro de la orden.');
            }
        }

        foreach ((array) ($orden['novedades'] ?? []) as $nv) {
            if (!is_array($nv) || trim((string) ($nv['descripcion'] ?? '')) === '') { continue; }
            [$ok, $msg] = Novedades::reportar([
                'novedad_uuid' => $nv['novedad_uuid'] ?? '',
                'descripcion'  => $nv['descripcion'],
                'tipo'         => $nv['tipo'] ?? '',
                'riesgo'       => $nv['riesgo'] ?? '',
                'responsable'  => $nv['responsable'] ?? '',
                'equipo_desc'  => $nv['equipo_desc'] ?? '',
                'local'        => $orden['local'] ?? '',
                'zona'         => $orden['zona'] ?? '',
                'cadena'       => $orden['cadena'] ?? '',
                'aviso'        => $aviso,
                'modulo'       => strtoupper((string) ($orden['tipo'] ?? '')),
                'detectada_ts' => $t,
            ]);
            if (!$ok) { $anexos[] = 'Una novedad no se pudo registrar: ' . $msg; }
        }

        if ($observaciones) {
            Auth::bitacora('ENVIO_OBSERVADO', 'ot', $uuid, implode(', ', $observaciones),
                           null, 'RECIBIDA', ['aviso' => $aviso, 'local' => $orden['local'] ?? null], false);
        }
    }

    $fila = Db::uno('SELECT captura_id, recibida_en FROM ot_capturadas WHERE envio_uuid = ?', [$uuid]);
    $pdo->commit();
} catch (Throwable $ex) {
    /* Nada queda a medias: sin la orden guardada entera, no hay orden. La
       tabla puede no existir todavía —la migración 007 está escrita y sin
       aplicar— o algo falló dentro. Se responde 503 —no 400— a propósito: el
       celular tiene que SEGUIR reintentando, y en cuanto se resuelva, todo lo
       que quedó en las colas entra solo, sin que nadie vuelva a llenar nada. */
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('envio.php: ' . $ex->getMessage());
    responder(503, ['ok' => false,
                    'motivo' => 'el sistema todavía no pudo guardar la orden; la tuya queda guardada en el celular y se envía sola']);
}

if (in_array('AVISO_FUERA_DE_ALCANCE', $observaciones, true)) {
    $anexos[] = 'La orden quedó registrada con una observación: ese caso no figura entre tus casos vigentes.';
}
if (in_array('LOCAL_DISTINTO_AL_DEL_CASO', $observaciones, true)) {
    $anexos[] = 'La orden quedó registrada con una observación: el local no coincide con el del caso.';
}

Auth::bitacora('ENVIO_RECIBIDO', 'ot', $uuid,
               'local=' . ($orden['local'] ?? '?') . ' aviso=' . ($aviso !== '' ? $aviso : 'sin aviso'),
               null, 'RECIBIDA',
               ['captura_id'   => $fila['captura_id'] ?? null,
                'capturada_ts' => $t,
                'demora_min'   => round((time() - $t) / 60),
                'reintento'    => !$nueva]);

responder(200, [
    'ok'     => true,
    'recibo' => [
        'numero'     => (int) ($fila['captura_id'] ?? 0),
        'recibida'   => $fila['recibida_en'] ?? null,
        // Se dice exactamente en qué quedó. Nada de «enviada correctamente»
        // cuando el informe al local todavía sale por el camino de siempre.
        'estado'     => 'RECIBIDA',
        'que_sigue'  => 'La orden quedó guardada en el sistema. El PDF y el correo al local '
                      . 'todavía se emiten por el camino actual.',
        // Qué pasó con el equipo trabado, las novedades y las observaciones.
        // Va aparte de la orden porque son hechos distintos con destinos distintos.
        'anexos'     => $anexos,
    ],
]);
