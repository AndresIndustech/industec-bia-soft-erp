<?php
declare(strict_types=1);

require_once __DIR__ . '/nucleo/Auth.php';
require_once __DIR__ . '/nucleo/Validacion.php';
require_once __DIR__ . '/nucleo/Pendientes.php';
require_once __DIR__ . '/nucleo/Novedades.php';
require_once __DIR__ . '/nucleo/Catalogo.php';
require_once __DIR__ . '/nucleo/Emision.php';

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
 * LA EMISION VA DESPUES DE GUARDAR (T2.13, la 008)
 *
 * Primero la orden queda a salvo en `ot_capturadas`; recién después
 * `Emision::emitir()` le da su número, genera el PDF —con las fotos, que
 * subieron antes por foto.php, y la firma— y encola el correo. Va fuera de la
 * transacción de la orden a propósito: si el PDF falla, la orden no se pierde,
 * y el siguiente reintento del celular vuelve a intentarlo sin repetir lo hecho.
 * En el sitio de pruebas el correo queda retenido y no sale nunca, y el recibo
 * lo dice con esas palabras (I-7).
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
// CSRF (T2.14.1, punto 10): `cola.js` manda el token de `yo.php` (cacheado)
// en la cabecera `X-Csrf`, que `Auth::exigirCsrf()` ya sabe leer.
Auth::exigirCsrf();

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
    // `emitida_en` y `carga` se leen ya aquí, ANTES de tocar nada: es contra lo
    // que se compara después de guardar, para saber si un reintento llegó con
    // datos distintos a los de una orden que ya se había emitido (E-04).
    $previa = Db::uno('SELECT usuario_id, emitida_en, carga FROM ot_capturadas WHERE envio_uuid = ?', [$uuid]);
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
// mismo reloj que `recibida_en` (el de la base), sin depender de la zona del PHP.
$t = strtotime((string) ($j['capturada_en'] ?? ''));
if ($t === false || $t > time() + 600 || $t < time() - 7 * 86400) {
    $observaciones[] = 'FECHA_DE_CAPTURA_DUDOSA';
    $t = time();
}
if ($observaciones) {
    $orden['_observaciones'] = $observaciones;
}

/* -------------------------------------------------------------------------
   LA PREMISA DEL SERVICIO, TAMBIEN EN EL SERVIDOR (H-11 item 7).

   El navegador ya no debería mandar un `pendiente` con `concluida = 1` --
   `pendienteDeLaOrden()` en app.js devuelve null en ese caso-- pero un POST se
   fabrica a mano, y "una visita concluye el trabajo" es la premisa del
   servicio, no un detalle de interfaz. Si llega junto, la orden no sirve:
   400, no se reintenta.
   ------------------------------------------------------------------------- */
$concluida = !array_key_exists('concluida', $orden) || (bool) $orden['concluida'];
$pen = $orden['pendiente'] ?? null;
if ($concluida && is_array($pen) && trim((string) ($pen['diagnostico'] ?? '')) !== '') {
    responder(400, ['ok' => false,
                    'motivo' => 'la orden llegó "concluida" pero trae un equipo pendiente; revisa "¿Quedó concluido el trabajo?"']);
}

// Trabajo con otro proveedor (H-18, D10): ya lo validó Validacion::validar()
// arriba (CON_PROVEEDOR_SIN_NOMBRE bloquea si falta el nombre); aquí solo se
// prepara el texto que va a la columna.
$conProveedor = !empty($orden['con_proveedor_marcado']) && trim((string) ($orden['con_proveedor'] ?? '')) !== ''
    ? mb_substr(trim((string) $orden['con_proveedor']), 0, 160)
    : null;

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
             concluida, carga, con_proveedor, capturada_en)
         VALUES (?,?,?,?,?,?,?,?,?,?,FROM_UNIXTIME(?))
         ON DUPLICATE KEY UPDATE
            /* El reintento no crea nada: refresca la carga por si el técnico
               corrigió algo antes de que saliera, y solo si es del mismo
               usuario Y la orden todavía NO se emitió (E-04): una vez que el
               PDF salió, lo que cuenta es lo que ese PDF describe, y la fila
               no puede quedar diciendo otra cosa. `recibida_en` NO se toca:
               conserva la primera llegada, que es la que mide la demora. */
            carga          = IF(usuario_id = VALUES(usuario_id) AND emitida_en IS NULL, VALUES(carga), carga),
            con_proveedor  = IF(usuario_id = VALUES(usuario_id) AND emitida_en IS NULL, VALUES(con_proveedor), con_proveedor),
            ultimo_reintento_en = NOW()',
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
            $conProveedor,
            $t,
        ]
    ) === 1;

    // E-04: si el reintento llegó con datos distintos a los de una orden que
    // ya se había emitido, no se pisó nada arriba -- pero queda dicho en la
    // bitácora, para que la administración decida si hay que regenerar el PDF
    // a mano. Se comparan los campos que de verdad puede corregir un técnico,
    // no el objeto entero: `_hoy`, `zona` derivada, etc. cambian solos entre
    // un intento y otro sin que el técnico haya tocado nada.
    if (!$nueva && $previa !== null && $previa['emitida_en'] !== null) {
        $previaOrden = json_decode((string) $previa['carga'], true) ?: [];
        $camposComparables = ['actividades', 'repuestos', 'observaciones', 'con_proveedor',
                               'equipos', 'estado_ot', 'atiempo', 'satisfaccion'];
        $cargaDistinta = false;
        foreach ($camposComparables as $campo) {
            if (json_encode($previaOrden[$campo] ?? null, JSON_UNESCAPED_UNICODE)
                !== json_encode($orden[$campo] ?? null, JSON_UNESCAPED_UNICODE)) {
                $cargaDistinta = true;
                break;
            }
        }
        if ($cargaDistinta) {
            Auth::bitacora('REINTENTO_TRAS_EMISION', 'ot', $uuid,
                           'el celular reenvió el mismo envío con datos distintos después de que la orden ya se había emitido; la carga NO se sobrescribió',
                           null, null, ['emitida_en' => $previa['emitida_en']], true);
            $anexos[] = 'Esta orden ya se había emitido: la corrección no reemplaza el PDF ya enviado. Avisa a la administración si hace falta uno nuevo.';
        }
    }

    if (!$nueva) {
        $anexos[] = 'Esta orden ya se había recibido: no se registró dos veces.';
    } else {
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
                    'aviso'              => $aviso,
                    'activo_fijo'        => $pen['activo_fijo'] ?? '',
                    'equipo_desc'        => $pen['equipo_desc'] ?? '',
                    'diagnostico'        => $pen['diagnostico'],
                    'parte'              => $pen['parte'] ?? '',
                    'deshabilitado'      => !empty($pen['deshabilitado']),
                    'abierto_ts'         => $t,
                    // H-11/D9: el diagnóstico pre-redactado que eligió (si lo
                    // hizo) y los repuestos estructurados. `Pendientes::abrir`
                    // los acepta o los ignora según lo que S3 tenga hecho; no
                    // rompe nada si todavía no los usa.
                    'diagnostico_codigo' => $pen['diagnostico_codigo'] ?? null,
                    'partes'             => is_array($pen['partes'] ?? null) ? $pen['partes'] : [],
                ]);
                $anexos[] = $ok ? $msg : ('No se pudo registrar el equipo trabado: ' . $msg
                                        . ' Quedó anotado dentro de la orden.');
            }
        }

        // Administrador del local (H-08): se suma a `locales_admin` para que
        // la próxima orden lo ofrezca en el datalist. Solo con orden NUEVA:
        // contar un reintento sería inflar «veces» sin que haya una firma más.
        $adminNombre = trim((string) ($orden['admin'] ?? ''));
        $localCod = trim((string) ($orden['local'] ?? ''));
        // El correo que escribió o eligió el técnico. Se aprende junto al
        // nombre del administrador para ofrecerlo en la próxima orden; un
        // `@industec.me` no se aprende, porque no es el correo del local.
        $correoEscrito = strtolower(trim((string) ($orden['correo_local'] ?? '')));
        $correoValido = $correoEscrito !== '' && filter_var($correoEscrito, FILTER_VALIDATE_EMAIL)
                        && !str_ends_with($correoEscrito, '@industec.me');
        if ($correoEscrito !== '' && !$correoValido) {
            $anexos[] = 'El correo del local que escribiste («' . mb_substr($correoEscrito, 0, 80)
                      . '») no parece un correo válido del local: la orden sale al correo del maestro.';
        }
        if ($adminNombre !== '' && $localCod !== '') {
            $correoParam = $correoValido ? mb_substr($correoEscrito, 0, 160) : null;
            try {
                // El correo anterior de ESTE administrador, para saber si el
                // que llega lo cambia (T2.28.3): se lee antes del upsert, en
                // la misma transacción, así que no hay carrera con otra orden.
                $previoAdmin = $correoParam !== null ? Db::uno(
                    'SELECT correo FROM locales_admin WHERE local_codigo = ? AND nombre = ?',
                    [mb_substr($localCod, 0, 12), mb_substr($adminNombre, 0, 120)]
                ) : null;
                Db::ejecutar(
                    "INSERT INTO locales_admin
                        (local_codigo, nombre, correo, veces, visto_ultimo, fuente, correo_veces, correo_visto)
                     VALUES (?, ?, ?, 1, NOW(), 'ORDEN', IF(? IS NOT NULL, 1, 0), IF(? IS NOT NULL, NOW(), NULL))
                     ON DUPLICATE KEY UPDATE veces = veces + 1, visto_ultimo = NOW(), activo = 1,
                                             correo = COALESCE(VALUES(correo), correo),
                                             /* Solo cuenta «visto» cuando la orden trajo un correo
                                                válido: un técnico que deja el campo vacío no debe
                                                borrar ni envejecer el que ya se había aprendido. */
                                             correo_veces = IF(VALUES(correo) IS NOT NULL, correo_veces + 1, correo_veces),
                                             correo_visto = IF(VALUES(correo) IS NOT NULL, NOW(), correo_visto)",
                    [mb_substr($localCod, 0, 12), mb_substr($adminNombre, 0, 120), $correoParam,
                     $correoParam, $correoParam]
                );
                $previoCorreo = $previoAdmin['correo'] ?? null;
                if ($correoParam !== null && $previoCorreo !== null && $previoCorreo !== $correoParam) {
                    Auth::bitacora('ADMIN_CORREO_CAMBIO', 'local', $localCod,
                        "$adminNombre ($localCod): el correo pasó de '$previoCorreo' a '$correoParam'",
                        $previoCorreo, $correoParam, ['nombre' => $adminNombre], true);
                }
            } catch (Throwable $ex) {
                // Sin la 009 no existe `locales_admin`, y sin la 013 no
                // existen `correo_veces`/`correo_visto` (T2.28.3): no es
                // motivo para perder la orden, que ya está guardada.
            }
        }

        // El correo del local que propone el técnico (T2.28.2, D-G): si es
        // válido, no es @industec.me y es distinto del que hoy tiene el
        // maestro, queda PROPUESTO para que la administración lo apruebe en
        // correos.php. Un error de tipeo no puede desviar las siguientes
        // órdenes de ese local: el maestro no cambia por sí solo (Catalogo::
        // fusionarCorreos() solo superpone lo APROBADO).
        if ($correoValido && $localCod !== '') {
            $correoMaestro = '';
            foreach ($catalogo['locales'] as $l) {
                if (($l['codigo'] ?? null) === $localCod) {
                    $correoMaestro = strtolower(trim((string) ($l['correo_local'] ?? '')));
                    break;
                }
            }
            if ($correoEscrito !== $correoMaestro) {
                try {
                    Db::ejecutar(
                        "INSERT INTO locales_correo_propuesto
                            (local_codigo, campo, correo, correo_anterior, envio_uuid, propuesto_por)
                         VALUES (?, 'LOCAL', ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE veces = veces + 1, envio_uuid = VALUES(envio_uuid)",
                        [mb_substr($localCod, 0, 12), mb_substr($correoEscrito, 0, 160),
                         $correoMaestro !== '' ? mb_substr($correoMaestro, 0, 160) : null, $uuid, (int) $u['usuario_id']]
                    );
                } catch (Throwable $ex) {
                    // Sin la 013 no existe `locales_correo_propuesto`: no es
                    // motivo para perder la orden, que ya está guardada.
                }
            }
        }

        // Equipo nuevo / no está en la lista (H-10, D8): cada equipo marcado
        // `nuevo: true` deja su fila en `equipos_propuestos`, visible para
        // todas las zonas desde que se propone (Catalogo::cargar() ya los
        // fusiona en el catálogo del local). Idempotente por `equipo_uuid`,
        // que genera el celular: el reintento no lo duplica.
        foreach ((array) ($orden['equipos'] ?? []) as $eq) {
            if (!is_array($eq) || empty($eq['nuevo'])) { continue; }
            $eqUuid = strtolower((string) ($eq['equipo_uuid'] ?? ''));
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $eqUuid)) {
                continue;                // sin uuid no hay cómo evitar duplicarlo: se queda solo en la carga
            }
            try {
                Db::ejecutar(
                    'INSERT INTO equipos_propuestos
                        (equipo_uuid, local_codigo, zona, tipo, marca, modelo, serie, activo_fijo, area,
                         envio_uuid, propuesto_por)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE equipo_uuid = equipo_uuid',
                    [
                        $eqUuid, $localCod !== '' ? $localCod : null,
                        in_array($orden['zona'] ?? '', ['UIO', 'LARB', 'CNLJ', 'OTRA'], true) ? $orden['zona'] : null,
                        mb_substr((string) ($eq['tipo'] ?? ''), 0, 80),
                        $eq['marca'] ?? null, $eq['modelo'] ?? null, $eq['serie'] ?? null,
                        $eq['codigo_activo'] ?? null, $eq['area'] ?? null,
                        $uuid, (int) $u['usuario_id'],
                    ]
                );
            } catch (Throwable $ex) {
                // Sin la 009 no existe `equipos_propuestos`: el equipo sigue
                // dentro de la carga JSON de la orden, solo que sin fila propia.
            }
        }

        // La ficha del equipo (T2.28.6, obs. 4): marca, modelo y serie que se
        // quedan para la próxima orden del mismo equipo. Clave: equipo_sap
        // real, o PROPUESTO:<uuid> para uno recién propuesto -la MISMA
        // convención que usa Catalogo::fusionarPropuestos(), así que la ficha
        // de un equipo nuevo ya queda con la clave que va a tener cuando el
        // catálogo lo fusione. Solo con orden NUEVA (§5.4, como locales_admin
        // y equipos_propuestos) y dentro de try/catch: sin la 014 no existe
        // `equipos_ficha`, y eso no es motivo para perder la orden.
        foreach ((array) ($orden['equipos'] ?? []) as $eq) {
            if (!is_array($eq)) { continue; }
            $claveEq = null;
            if (!empty($eq['equipo_sap'])) {
                $claveEq = mb_substr((string) $eq['equipo_sap'], 0, 60);
            } elseif (!empty($eq['nuevo'])) {
                $eqUuid = strtolower((string) ($eq['equipo_uuid'] ?? ''));
                if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $eqUuid)) {
                    $claveEq = 'PROPUESTO:' . $eqUuid;
                }
            }
            if ($claveEq === null || $localCod === '') { continue; }

            $marcaCruda  = isset($eq['marca'])  ? (string) $eq['marca']  : null;
            $modeloCrudo = isset($eq['modelo']) ? (string) $eq['modelo'] : null;
            $serieCruda  = isset($eq['serie'])  ? (string) $eq['serie']  : null;
            $marcaNueva  = Validacion::esMarcador($marcaCruda)  ? null : mb_substr(Validacion::normMarca($marcaCruda), 0, 80);
            $modeloNuevo = Validacion::esMarcador($modeloCrudo) ? null : mb_substr(trim((string) $modeloCrudo), 0, 80);
            $serieNueva  = Validacion::esMarcador($serieCruda)  ? null : mb_substr(trim((string) $serieCruda), 0, 80);
            $sinPlacaNueva = !empty($eq['sin_placa']);

            try {
                $previaFicha = Db::uno(
                    'SELECT marca, modelo, serie, sin_placa FROM equipos_ficha WHERE equipo_clave = ?', [$claveEq]
                );
                // COALESCE: un campo vacío o marcador (marcaNueva === null) NO
                // pisa lo que ya había -es el punto entero de la ficha: no se
                // borra un dato real con un vacío de una orden distinta.
                Db::ejecutar(
                    "INSERT INTO equipos_ficha
                        (equipo_clave, local_codigo, marca, modelo, serie, sin_placa, fuente,
                         actualizado_por, envio_uuid)
                     VALUES (?, ?, ?, ?, ?, ?, 'ORDEN', ?, ?)
                     ON DUPLICATE KEY UPDATE
                        local_codigo    = VALUES(local_codigo),
                        marca           = COALESCE(VALUES(marca), marca),
                        modelo          = COALESCE(VALUES(modelo), modelo),
                        serie           = COALESCE(VALUES(serie), serie),
                        sin_placa       = VALUES(sin_placa),
                        fuente          = 'ORDEN',
                        actualizado_por = VALUES(actualizado_por),
                        actualizado_en  = NOW(),
                        envio_uuid      = VALUES(envio_uuid)",
                    [$claveEq, mb_substr($localCod, 0, 12), $marcaNueva, $modeloNuevo, $serieNueva,
                     $sinPlacaNueva ? 1 : 0, (int) $u['usuario_id'], $uuid]
                );
                $antesMarca    = $previaFicha['marca'] ?? null;
                $antesModelo   = $previaFicha['modelo'] ?? null;
                $antesSerie    = $previaFicha['serie'] ?? null;
                $antesSinPlaca = $previaFicha !== null ? (bool) $previaFicha['sin_placa'] : false;
                $cambios = [];
                if ($marcaNueva !== null && $marcaNueva !== $antesMarca) { $cambios[] = ['marca', $antesMarca, $marcaNueva]; }
                if ($modeloNuevo !== null && $modeloNuevo !== $antesModelo) { $cambios[] = ['modelo', $antesModelo, $modeloNuevo]; }
                if ($serieNueva !== null && $serieNueva !== $antesSerie) { $cambios[] = ['serie', $antesSerie, $serieNueva]; }
                if ($sinPlacaNueva !== $antesSinPlaca) { $cambios[] = ['sin_placa', $antesSinPlaca ? '1' : '0', $sinPlacaNueva ? '1' : '0']; }
                foreach ($cambios as [$campo, $antesCambio, $despuesCambio]) {
                    Db::ejecutar(
                        'INSERT INTO equipos_ficha_cambios (equipo_clave, campo, antes, despues, por, envio_uuid)
                         VALUES (?, ?, ?, ?, ?, ?)',
                        [$claveEq, $campo, $antesCambio, $despuesCambio, (int) $u['usuario_id'], $uuid]
                    );
                }
                // La serie cambió desde un valor REAL (no la primera vez que se
                // conoce el equipo): posible reemplazo, y el jefe de zona no
                // tiene otra forma de enterarse que mirar la bitácora a mano.
                if ($serieNueva !== null && $antesSerie !== null && $serieNueva !== $antesSerie) {
                    Auth::bitacora('EQUIPO_SERIE_CAMBIO', 'equipo', $claveEq,
                        "$localCod: la serie pasó de '$antesSerie' a '$serieNueva' -- posible reemplazo del equipo",
                        $antesSerie, $serieNueva, ['local' => $localCod], true);
                }
            } catch (Throwable $ex) {
                // Sin la 014 no existe `equipos_ficha`: la orden ya está guardada.
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

/* -------------------------------------------------------------------------
   LA EMISION: número, PDF y correo. Lo ya hecho no se repite, así que el
   reintento de una orden emitida devuelve el mismo número.
   ------------------------------------------------------------------------- */
$em = Emision::emitir((int) ($fila['captura_id'] ?? 0));
$prueba = Emision::modo() === 'PRUEBA';
if ($em['error'] !== null) {
    Auth::bitacora('EMISION_FALLIDA', 'ot', $uuid, mb_substr((string) $em['error'], 0, 150),
                   null, null, ['captura_id' => $fila['captura_id'] ?? null], false);
} elseif ($nueva) {
    Auth::bitacora('EMISION', 'ot', (string) $em['id_industec'],
                   'PDF generado · correo ' . strtolower((string) $em['correo']), null, 'PROCESADA',
                   ['captura_id' => $fila['captura_id'] ?? null, 'modo' => Emision::modo()]);
}

/* -------------------------------------------------------------------------
   EL CASO SE MUEVE EN EL ACTO (T2.14.3). Con la orden emitida, el caso queda
   ATENDIDO con su orden de cierre (o ASIGNADO al firmante si no concluyó),
   sin esperar a la reconciliación nocturna: es lo que la administradora ve
   como «a registrar en SAP» en tiempo real. Va ANTES de resolver el pendiente
   porque `Pendientes` decide ATENDIDO/ASIGNADO mirando si ya hay `ot_cierre`.
   ------------------------------------------------------------------------- */
/* -------------------------------------------------------------------------
   Y ARRASTRA LA CADENA (T2.25.3). Cuando este caso es la continuación de otro
   —SAP cerró el aviso viejo a las 48 h y KFC abrió este por el mismo equipo—,
   la orden que concluye el trabajo cierra los dos, no uno. Antes el caso viejo
   se quedaba abierto para siempre: no había ninguna ruta que lo cerrara.

   Solo entran los avisos que el técnico enlazó A MANO desde la ficha del caso.
   `Casos::cadena()` no adivina nada: lee `continua_de`, que solo escribe una
   persona. Un caso no entra aquí por parecerse a otro.
   ------------------------------------------------------------------------- */
$cadena = [$aviso];
if ($aviso !== '') {
    try {
        $cadena = Casos::cadena($aviso);
    } catch (Throwable $ex) {
        error_log('envio.php: cadena: ' . $ex->getMessage());   // sin la 012, la cadena es el caso solo
    }
}

if ($em['error'] === null && $aviso !== '' && !empty($em['id_industec'])) {
    foreach ($cadena as $unAviso) {
        try {
            /* La zona del caso enlazado la pone `asegurar()` si le falta; la de
               la orden vale solo para el suyo. El resto de la regla es idéntico
               —lo que decidió una persona no se pisa—, así que un caso viejo ya
               RESUELTO o NO_COMPETE se queda como está. */
            Casos::atenderPorOrden($unAviso,
                                   $unAviso === $aviso ? ($orden['zona'] ?? null) : null,
                                   (string) $em['id_industec'],
                                   $concluida, (int) $u['usuario_id']);
        } catch (Throwable $ex) {
            error_log('envio.php: atenderPorOrden(' . $unAviso . '): ' . $ex->getMessage());
        }
    }
    if (count($cadena) > 1) {
        $anexos[] = 'Esta orden cierra también ' . (count($cadena) - 1) . ' caso'
                  . (count($cadena) === 2 ? '' : 's') . ' anterior'
                  . (count($cadena) === 2 ? '' : 'es') . ' del mismo trabajo: '
                  . implode(', ', array_slice($cadena, 1)) . '.';
    }
}

/* -------------------------------------------------------------------------
   LA ORDEN CONCLUIDA RESUELVE EL PENDIENTE SOLA (punto 7). Si el equipo del
   caso ya no está trabado, no tiene sentido que el jefe de zona lo siga
   viendo abierto porque nadie cerró el pendiente a mano. `Pendientes` (S3) es
   quien lo ofrece; hasta que exista, esta llamada es un no-op seguro.
   ------------------------------------------------------------------------- */
if ($concluida && $aviso !== '' && method_exists('Pendientes', 'resolverPorOrden')) {
    try {
        /* Con la cadena, no con el aviso solo (T2.25.3): el repuesto que el
           técnico fue a instalar hoy quedó trabado en el aviso VIEJO, el que
           SAP cerró a las 48 horas. Pasándole solo el aviso de la orden, ese
           pendiente seguía con el reloj corriendo sin que nada lo cerrara. */
        Pendientes::resolverPorOrden(
            $cadena, null, (string) ($em['id_industec'] ?? ($fila['captura_id'] ?? '')), (int) $u['usuario_id']
        );
    } catch (Throwable $ex) {
        error_log('envio.php: resolverPorOrden: ' . $ex->getMessage());
    }
}

/* Reintento oportunista de lo que quedó a medias (H-03, punto 7): unas pocas
   por envío, no todo el backlog -- para eso está `emitir_pendientes_cli.php`
   desde el cron cada 10 min (S6). */
if (method_exists('Emision', 'reintentarPendientes')) {
    try {
        Emision::reintentarPendientes(3);
    } catch (Throwable $ex) {
        error_log('envio.php: reintentarPendientes: ' . $ex->getMessage());
    }
}

responder(200, [
    'ok'     => true,
    'recibo' => [
        'numero'      => (int) ($fila['captura_id'] ?? 0),
        'recibida'    => $fila['recibida_en'] ?? null,
        // Se dice exactamente en qué quedó: emitida con su número, o guardada
        // y sin PDF todavía. Nada de «enviada correctamente» a secas.
        'estado'      => $em['pdf'] ? 'EMITIDA' : 'RECIBIDA',
        'id_industec' => $em['id_industec'],
        'correo'      => $em['correo'],
        'que_sigue'   => $em['pdf']
            ? 'Orden ' . $em['id_industec'] . ' emitida: el PDF está en tu historial. '
              . ($prueba ? 'Es el sistema en pruebas: el correo no se envió a nadie.'
                         : 'El correo al local sale de la cola.')
            : ($em['id_industec']
                ? 'La orden quedó guardada con el número ' . $em['id_industec']
                  . '. El PDF no se pudo generar todavía: se reintenta desde el servidor cada 10 minutos.'
                : 'La orden quedó guardada. Todavía no se le pudo asignar número: se reintenta desde el servidor cada 10 minutos.'),
        // Qué pasó con el equipo trabado, las novedades y las observaciones.
        // Va aparte de la orden porque son hechos distintos con destinos distintos.
        'anexos'     => $anexos,
    ],
]);
