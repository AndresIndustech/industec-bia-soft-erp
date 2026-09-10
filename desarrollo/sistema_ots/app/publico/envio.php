<?php
declare(strict_types=1);

require_once __DIR__ . '/nucleo/Auth.php';
require_once __DIR__ . '/nucleo/Validacion.php';
require_once __DIR__ . '/nucleo/Pendientes.php';
require_once __DIR__ . '/nucleo/Novedades.php';

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
 * con todos, y es UNIQUE en la base. El segundo intento no inserta: actualiza
 * la misma fila y devuelve el mismo recibo. Para el técnico es indistinguible;
 * para la base, es una sola orden.
 *
 * ============================================================================
 * TRES RESPUESTAS Y TRES SIGNIFICADOS. El cliente los trata distinto.
 *
 *   200  llegó y está guardada. El celular la borra de su cola.
 *   4xx  llegó y NO sirve (falta un campo, el local no existe). Reintentar no
 *        la arregla: el celular la marca y se la enseña al técnico para que la
 *        corrija. Insistir mil veces con una orden inválida solo llena el log.
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
 *
 * ============================================================================
 * LO QUE ESTE ARCHIVO NO HACE, Y SE DICE CLARO (I-7)
 *
 * No reserva el correlativo, no genera el PDF y no manda el correo. Eso vive
 * en la migración 003 (`correlativos` con reserva atómica y `email_queue`),
 * que sigue sin aplicarse. La orden queda **completa y a salvo** en
 * `ot_capturadas`, y el recibo que se devuelve lo dice con esas palabras. Es
 * preferible a fingir que el informe ya salió: el técnico lo daría por hecho y
 * el local nunca lo recibiría.
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
   LA IDENTIDAD SALE DE LA SESION, NO DE LA ORDEN.

   Aunque el celular mande un `tecnico`, se ignora. El técnico ya no elige su
   nombre en el formulario: eso costaba un gesto en cada orden y permitía
   firmar como otro. Quien emitió la orden es quien tenía la sesión abierta, y
   eso es lo que se guarda.
   ------------------------------------------------------------------------- */
$orden['tecnico_usuario_id'] = (int) $u['usuario_id'];
$orden['tecnico_nombre']     = $u['nombre'];
unset($orden['tecnico_sesion']);

/* -------------------------------------------------------------------------
   Validación con las reglas compartidas.
   ------------------------------------------------------------------------- */
$catalogo = null;
foreach ([__DIR__ . '/catalogos', __DIR__ . '/../../../../SALIDAS IA/OTS/catalogos'] as $base) {
    if (is_file($base . '/locales.json')) {
        $leer = fn(string $f) => json_decode((string) file_get_contents($base . '/' . $f), true) ?: [];
        $catalogo = [
            'locales'  => $leer('locales.json')['locales']   ?? [],
            'equipos'  => $leer('equipos.json')['equipos']   ?? [],
            'tipos'    => $leer('tipos.json')['tipos']       ?? [],
            'tecnicos' => $leer('tecnicos.json')['tecnicos'] ?? [],
        ];
        break;
    }
}

if ($catalogo !== null) {
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
}

/* -------------------------------------------------------------------------
   Se guarda. Idempotente por `envio_uuid`.
   ------------------------------------------------------------------------- */
$cap = (string) ($j['capturada_en'] ?? '');
$t   = strtotime($cap);
// La fecha en que se LLENO, no en la que llegó: entre las dos puede haber
// horas sin cobertura, y para medir tiempos de atención vale la primera.
$capturada = $t !== false ? date('Y-m-d H:i:s', $t) : date('Y-m-d H:i:s');

try {
    Db::ejecutar(
        'INSERT INTO ot_capturadas
            (envio_uuid, usuario_id, aviso, local_codigo, zona, cadena, modulo,
             concluida, carga, capturada_en)
         VALUES (?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            /* El reintento no crea nada: refresca la carga por si el técnico
               corrigió algo antes de que saliera, y devuelve el mismo recibo. */
            carga = VALUES(carga), recibida_en = NOW()',
        [
            $uuid,
            (int) $u['usuario_id'],
            ($orden['aviso'] ?? '') !== '' ? mb_substr((string) $orden['aviso'], 0, 20) : null,
            ($orden['local'] ?? '') !== '' ? mb_substr((string) $orden['local'], 0, 12) : null,
            in_array($orden['zona'] ?? '', ['UIO', 'LARB', 'CNLJ', 'OTRA'], true) ? $orden['zona'] : null,
            ($orden['cadena'] ?? '') !== '' ? mb_substr((string) $orden['cadena'], 0, 40) : null,
            in_array(strtoupper((string) ($orden['tipo'] ?? '')), ['CORRECTIVO', 'PREVENTIVO'], true)
                ? strtoupper((string) $orden['tipo']) : null,
            isset($orden['concluida']) ? (int) (bool) $orden['concluida'] : null,
            json_encode($orden, JSON_UNESCAPED_UNICODE),
            $capturada,
        ]
    );
} catch (Throwable $ex) {
    /* La tabla puede no existir todavía: la migración 007 está escrita y sin
       aplicar, porque cambia el esquema y eso requiere aprobación. Se responde
       503 —no 400— a propósito: el celular tiene que SEGUIR reintentando, y en
       cuanto la migración se aplique, todo lo que quedó en las colas entra
       solo, sin que nadie vuelva a llenar una orden. */
    error_log('envio.php: ' . $ex->getMessage());
    responder(503, ['ok' => false,
                    'motivo' => 'el sistema todavía no puede recibir órdenes; la tuya queda guardada en el celular y se envía sola']);
}

$fila = Db::uno('SELECT captura_id, recibida_en FROM ot_capturadas WHERE envio_uuid = ?', [$uuid]);

/* -------------------------------------------------------------------------
   LO QUE VIENE DENTRO DE LA ORDEN Y ES OTRA COSA.

   La orden puede traer dos cosas más, y ninguna es un campo del informe:

     `pendiente`  el equipo que quedó sin concluir. Desde que se registra
                  corre el plazo de 48 horas para decidir la vía.
     `novedades`  lo que el técnico vio y no era su orden.

   SE HACE AQUI, EN EL SERVIDOR, Y DESPUES DE GUARDAR LA CAPTURA. El orden
   importa: la orden ya está a salvo, así que si esto falla no se pierde el
   trabajo del técnico. Se le informa en el recibo y queda en la bitácora, en
   vez de rechazar el envío entero y hacer que llene todo otra vez.

   El plazo se cuenta desde `abierto_en`, que es cuando el técnico lo registró
   en su celular — no desde cuando llegó. Entre las dos cosas puede haber horas
   sin cobertura, y el equipo estuvo parado todo ese tiempo.
   ------------------------------------------------------------------------- */
$anexos = [];

$pen = $orden['pendiente'] ?? null;
if (is_array($pen) && trim((string) ($pen['diagnostico'] ?? '')) !== '') {
    if (($orden['aviso'] ?? '') === '' || $orden['aviso'] === null) {
        /* Sin aviso no hay caso al que colgar el pendiente, y `Pendientes` se
           apoya en el aviso para resolver el alcance. Queda dentro de la carga
           JSON —no se pierde— y se dice en el recibo: es tarea de la
           administración regularizarlo primero. */
        $anexos[] = 'El equipo trabado quedó anotado en la orden, pero esta orden no tiene '
                  . 'aviso de SAP, así que todavía no puede entrar al control de 48 horas. '
                  . 'La administración tiene que regularizarla primero.';
        Auth::bitacora('PENDIENTE_SIN_AVISO', 'ot', $uuid,
                       mb_substr((string) $pen['diagnostico'], 0, 150),
                       null, null, ['pendiente' => $pen], true);
    } else {
        [$ok, $msg, $idPen] = Pendientes::abrir([
            'aviso'         => $orden['aviso'],
            'activo_fijo'   => $pen['activo_fijo'] ?? '',
            'equipo_desc'   => $pen['equipo_desc'] ?? '',
            'diagnostico'   => $pen['diagnostico'],
            'parte'         => $pen['parte'] ?? '',
            'deshabilitado' => !empty($pen['deshabilitado']),
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
        'aviso'        => $orden['aviso'] ?? '',
        'modulo'       => strtoupper((string) ($orden['tipo'] ?? '')),
        'detectada_en' => $capturada,
    ]);
    if (!$ok) { $anexos[] = 'Una novedad no se pudo registrar: ' . $msg; }
}

Auth::bitacora('ENVIO_RECIBIDO', 'ot', $uuid,
               'local=' . ($orden['local'] ?? '?') . ' aviso=' . ($orden['aviso'] ?? 'sin aviso'),
               null, 'RECIBIDA',
               ['captura_id' => $fila['captura_id'] ?? null,
                'capturada_en' => $capturada,
                'demora_min' => $t !== false ? round((time() - $t) / 60) : null]);

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
        // Qué pasó con el equipo trabado y con las novedades. Va aparte de la
        // orden porque son hechos distintos con destinos distintos: uno abre un
        // plazo de 48 horas, los otros van a la bandeja del jefe de zona.
        'anexos'     => $anexos,
    ],
]);
