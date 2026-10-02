<?php
declare(strict_types=1);

/**
 * liberar_ot_piloto_cli.php — Manda a Grupo KFC una lista EXPLÍCITA de OT INDUSTEC
 * de la serie 9000 que nunca salieron, y deja sus casos pendientes de SAP
 * (T2.29.8, pedido de Andrés del 2026-10-01).
 *
 * POR QUÉ EXISTE
 * Del 24 al 29-sep-2026 la app emitió 18 OT en modo PRUEBA (serie 9000, correo
 * RETENIDO). Los técnicos las tomaron por reales y la administradora cerró en
 * SAP doce órdenes con esos números, que Grupo KFC nunca recibió. Andrés pidió
 * revisar, orden por orden, si el formulario viejo ya había reportado el
 * trabajo: si sí, el caso se resuelve con ese informe; si no, la OT que existe
 * «solo a nivel del sistema» se envía y el caso queda pendiente de SAP.
 *
 * Esta consola es el segundo camino, y nada más. La lista de OT NO sale de aquí:
 * la arma la estación con evidencia independiente (el buzón por IMAP, la base de
 * la estación y los archivos del formulario viejo) y la trae en un JSON. Aquí se
 * vuelve a comprobar todo lo que se puede comprobar desde el servidor.
 *
 * QUÉ ESCRIBE, POR OT (solo con --ejecutar)
 *   1. `ot_capturadas.liberada_en/por/nota` (migración 024): desde ese momento
 *      `Emision::esDePrueba()` ya no la marca «del piloto». Sin esto el
 *      despachador la devuelve a RETENIDO, el buzón dice «no enviada a KFC» y la
 *      orden no se atiende (D-7).
 *   2. Su correo en `email_queue`: de RETENIDO a PENDIENTE, con los
 *      destinatarios de HOY (Destinatarios::resolver, igual que una OT nueva),
 *      no los que se congelaron el día del piloto.
 *   3. Una fila OT_LIBERADA en la bitácora, a nombre de quien autoriza.
 *   4. Se despacha con Correo::despachar(): los mismos topes, reintentos y la
 *      misma cuenta que cualquier OT real. No hay un segundo camino de envío.
 *   5. Con el correo ENVIADO, el caso:
 *        - OT concluida y caso RESUELTO con esta OT como cierre (lo cerró la
 *          administradora cuando la OT no existía para KFC): vuelve a ATENDIDO,
 *          que es «pendiente de SAP», y se anota lo que tenía (veredicto, quién,
 *          cuándo). Es una orden expresa de Andrés: la máquina de estados no
 *          tiene esa transición a propósito (ASG-18).
 *        - OT concluida y caso abierto: Casos::atenderPorOrden() lo pasa a
 *          ATENDIDO como a cualquier OT real.
 *        - OT sin concluir: el caso no cambia; la OT queda como evaluación.
 *        - Lo que decidió una persona (revisión, no compete, espera de
 *          repuesto) no se toca.
 *
 * RESPALDO ANTES. `--ejecutar` crea ~/respaldos/liberar_ot_piloto_<fecha>/ con
 * las filas de antes (captura, correo, caso, archivo), los PDF y su SHA256SUMS.
 *
 * GUARDAS (el simulacro, que es la omisión, las comprueba todas y no escribe):
 *   - la lista trae solo OT de la serie 9000, sin repetidos y con su captura;
 *   - la lista es de hace poco (--max-edad-min, 90 por omisión): la evidencia
 *     de «el viejo no lo reportó» caduca;
 *   - la captura existe con ese número y ese aviso, está EMITIDA (o ya ENVIADA
 *     por esta misma consola) y no está liberada todavía;
 *   - el PDF en disco tiene la huella que dice la lista y la del Archivo;
 *   - su correo es UNO, RETENIDO, con su PDF adjunto, y hoy tiene destinatarios;
 *   - la migración 024 está aplicada (solo para --ejecutar).
 * Cualquier cosa que no cuadre aborta sin escribir nada (I-10).
 *
 * IDEMPOTENTE: una segunda corrida con la misma lista no reenvía lo ENVIADO ni
 * vuelve a anotar el caso; completa lo que haya quedado a medias.
 *
 * DÓNDE VIVE. Como el resto de `pruebas/servidor/*.php`: se sube por scp a
 * `~/respaldos/` (fuera de la web) y se corre con el directorio de trabajo en
 * `ot/`. Por eso usa getcwd() y no __DIR__ (errores nº 42 y 47).
 *
 * USO (en el servidor, DESDE la carpeta de la app):
 *   cd domains/darkviolet-armadillo-872352.hostingersite.com/public_html/ot
 *   php ~/respaldos/liberar_ot_piloto_cli.php --lista=~/respaldos/ot_a_liberar.json --a-nombre-de=abasantes
 *   php ~/respaldos/liberar_ot_piloto_cli.php --lista=... --a-nombre-de=abasantes --ejecutar
 *   (--sin-enviar: libera y deja PENDIENTE, sin despachar)
 *   php ~/respaldos/liberar_ot_piloto_cli.php --lista=... --a-nombre-de=abasantes --probar-sql
 *   (--probar-sql: corre las escrituras de verdad dentro de UNA transacción y la REVIERTE; no despacha ni
 *    envía nada. Sirve para comprobar las sentencias contra el esquema real antes de mandar a Grupo KFC)
 *
 * Código de salida: 0 bien; 1 no cuadra o falló; 2 quedó algo sin enviar; 3 uso.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$APP = getcwd();
if (!is_file($APP . '/nucleo/Emision.php') || !is_file($APP . '/nucleo/Correo.php')) {
    fwrite(STDERR, "corre esta consola desde la carpeta de la app (ot/)\n");
    exit(3);
}
require_once $APP . '/nucleo/Db.php';
require_once $APP . '/nucleo/Emision.php';
require_once $APP . '/nucleo/Destinatarios.php';
require_once $APP . '/nucleo/Casos.php';
require_once $APP . '/nucleo/Correo.php';

const ACTOR = 'liberar_ot_piloto_cli';      // bitacora.equipo
const PEDIDO = 'pedido de Andrés del 2026-10-01';

/* ---------------------------------------------------------------- argumentos */
$ejecutar   = in_array('--ejecutar', $argv, true);
$sinEnviar  = in_array('--sin-enviar', $argv, true);
$probarSql  = in_array('--probar-sql', $argv, true);
$opcion = static function (string $n) use ($argv): ?string {
    foreach ($argv as $a) {
        if (str_starts_with($a, $n . '=')) { return substr($a, strlen($n) + 1); }
    }
    return null;
};
function salir(string $mensaje, int $codigo = 1): never
{
    fwrite(STDERR, $mensaje . "\n");
    exit($codigo);
}
function ruta(string $r): string
{
    return str_starts_with($r, '~/') ? rtrim((string) getenv('HOME'), '/') . substr($r, 1) : $r;
}

$rutaLista = $opcion('--lista');
$login     = $opcion('--a-nombre-de');
if ($rutaLista === null || $login === null) {
    salir("uso: php liberar_ot_piloto_cli.php --lista=ARCHIVO.json --a-nombre-de=LOGIN [--ejecutar] [--sin-enviar] [--max-edad-min=N]", 3);
}
$maxEdad = (int) ($opcion('--max-edad-min') ?? 90);

$u = Db::uno("SELECT usuario_id, usuario, nombre FROM usuarios WHERE usuario = ? AND rol = 'SUPERADMIN' AND activo = 1", [$login]);
if ($u === null) { salir("--a-nombre-de debe ser el login de un SUPERADMIN activo: esto queda a nombre de una persona", 3); }

/* --------------------------------------------------------------------- lista */
$rl = ruta($rutaLista);
if (!is_file($rl)) { salir("no existe la lista: $rl", 3); }
$lista = json_decode((string) file_get_contents($rl), true);
if (!is_array($lista) || !isset($lista['ot']) || !is_array($lista['ot']) || $lista['ot'] === []) {
    salir('la lista no trae «ot» o está vacía', 3);
}
if (count($lista['ot']) > 20) { salir('la lista trae más de 20 OT: esta consola es para un puñado, no para un lote', 3); }
$generado = strtotime((string) ($lista['generado_en'] ?? ''));
$edadMin = $generado === false ? null : (time() - $generado) / 60;
$errores = [];
if ($edadMin === null) {
    $errores[] = 'la lista no trae `generado_en` con una fecha válida';
} elseif ($edadMin > $maxEdad || $edadMin < -5) {
    $errores[] = sprintf('la lista es de hace %.0f min (máximo %d): la evidencia de «el formulario viejo no lo reportó» caduca; vuelve a armarla', $edadMin, $maxEdad);
}

/* ---------------------------------------------------------------- el esquema */
$tiene024 = (int) (Db::uno("SELECT COUNT(*) n FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ot_capturadas' AND COLUMN_NAME = 'liberada_en'")['n'] ?? 0) === 1;

/* ----------------------------------------------------------- comprobar cada OT */
$plan = [];
$vistos = [];
foreach ($lista['ot'] as $i => $it) {
    $n = $i + 1;
    $id  = strtoupper(trim((string) ($it['id_industec'] ?? '')));
    $cap = (int) ($it['captura_id'] ?? 0);
    $aviso = trim((string) ($it['aviso'] ?? ''));
    $sha = strtolower(trim((string) ($it['pdf_sha256'] ?? '')));
    $concluidaLista = (bool) ($it['concluida'] ?? false);
    $quien = "#$n ($id)";
    if (!preg_match('/^OT-9\d{3}-[A-Z0-9-]+$/', $id)) { $errores[] = "$quien: no es una OT de la serie 9000"; continue; }
    if ($cap <= 0 || !preg_match('/^\d{8}$/', $aviso) || !preg_match('/^[0-9a-f]{64}$/', $sha)) {
        $errores[] = "$quien: faltan captura_id, aviso (8 cifras) o pdf_sha256";
        continue;
    }
    if (isset($vistos[$id]) || isset($vistos['c' . $cap])) { $errores[] = "$quien: repetida en la lista"; continue; }
    $vistos[$id] = true; $vistos['c' . $cap] = true;

    $c = Db::uno('SELECT * FROM ot_capturadas WHERE captura_id = ?', [$cap]);
    if ($c === null) { $errores[] = "$quien: la captura $cap no existe"; continue; }
    if (strtoupper((string) $c['id_industec']) !== $id) { $errores[] = "$quien: la captura $cap es {$c['id_industec']}"; continue; }
    if ((string) $c['aviso'] !== $aviso) { $errores[] = "$quien: la captura dice aviso {$c['aviso']}, la lista $aviso"; continue; }
    if ((bool) $c['concluida'] !== $concluidaLista) { $errores[] = "$quien: concluida no coincide (captura " . (int) $c['concluida'] . ')'; continue; }

    $liberada = $tiene024 && $c['liberada_en'] !== null;
    $estadoCap = (string) $c['estado'];
    if (!in_array($estadoCap, ['EMITIDA', 'ENVIADA'], true)) { $errores[] = "$quien: estado $estadoCap (esperaba EMITIDA)"; continue; }
    if ($estadoCap === 'ENVIADA' && !$liberada) { $errores[] = "$quien: ya figura ENVIADA sin haberla liberado esta consola"; continue; }

    $ruta = Emision::dirPdf() . '/' . $id . '.pdf';
    if (!is_file($ruta)) { $errores[] = "$quien: no está el PDF en el servidor"; continue; }
    $huella = hash_file('sha256', $ruta);
    if ($huella !== $sha) { $errores[] = "$quien: el PDF en disco ($huella) no es el que vio la estación ($sha)"; continue; }
    $arch = Db::uno('SELECT sha256, en_servidor FROM ot_archivo WHERE id_industec = ?', [$id]);
    $huellasValidas = array_filter([$c['pdf_sha256_regen'] ?? null, $c['pdf_sha256'] ?? null, $arch['sha256'] ?? null]);
    if (!in_array($huella, $huellasValidas, true)) { $errores[] = "$quien: la huella del PDF no coincide con la de la captura ni con la del Archivo"; continue; }

    $colas = Db::todos("SELECT * FROM email_queue WHERE captura_id = ? AND tipo = 'EMISION'", [$cap]);
    if (count($colas) !== 1) { $errores[] = "$quien: esperaba UN correo de emisión y hay " . count($colas); continue; }
    $q = $colas[0];
    if ((string) $q['adjunto'] !== $id . '.pdf') { $errores[] = "$quien: el correo adjunta {$q['adjunto']}"; continue; }
    if (!$liberada && $q['estado'] !== 'RETENIDO') { $errores[] = "$quien: su correo está {$q['estado']} (esperaba RETENIDO)"; continue; }

    $orden = json_decode((string) $c['carga'], true) ?: [];
    $zona = (string) $c['zona'];
    $dest = Destinatarios::resolver('ORDEN', $zona, (string) $c['local_codigo'] !== '' ? (string) $c['local_codigo'] : null,
                                    (string) ($c['cadena'] ?? '') !== '' ? (string) $c['cadena'] : null, $orden['correo_local'] ?? null);
    if ($dest['para'] === []) { $errores[] = "$quien: hoy no tiene destinatario «Para» (ni correo del local ni configurado)"; continue; }

    $g = Db::uno('SELECT * FROM casos_gestion WHERE aviso = ?', [$aviso]);
    $marcado = (bool) Db::uno("SELECT 1 FROM bitacora WHERE accion = 'CASO_POR_ENVIO_TARDIO' AND referencia = ? AND detalle LIKE ? LIMIT 1",
                              [$aviso, '%' . $id . '%']);
    $vivos = (int) (Db::uno("SELECT COUNT(*) n FROM pendientes WHERE aviso = ? AND estado NOT IN ('RESUELTO','CANCELADO')", [$aviso])['n'] ?? 0);

    // Qué le pasa al caso (informativo en el simulacro; lo mismo que hace --ejecutar).
    $estadoCaso = (string) ($g['estado'] ?? '(sin fila)');
    $cierreCaso = (string) ($g['ot_cierre'] ?? '');
    if ($marcado) {
        $caso = 'ya anotado por esta consola';
    } elseif (!$concluidaLista) {
        $caso = 'sin cambio (la OT queda como evaluación; el caso sigue ' . $estadoCaso . ')';
    } elseif ($estadoCaso === 'RESUELTO' && strtoupper($cierreCaso) === $id) {
        $caso = 'RESUELTO → ATENDIDO (pendiente de SAP; se anota el veredicto anterior)';
    } elseif ($estadoCaso === 'RESUELTO') {
        $caso = 'RESUELTO con OTRA OT de cierre (' . $cierreCaso . '): NO se toca';
    } elseif (in_array($estadoCaso, ['EN_REVISION', 'NO_COMPETE', 'ESPERA_REPUESTO'], true)) {
        $caso = "$estadoCaso: lo decidió una persona, NO se toca";
    } else {
        $caso = $estadoCaso . ' → ATENDIDO (Casos::atenderPorOrden)';
    }

    $plan[] = ['id' => $id, 'cap' => $cap, 'aviso' => $aviso, 'c' => $c, 'q' => $q, 'g' => $g, 'orden' => $orden, 'zona' => $zona,
               'dest' => $dest, 'huella' => $huella, 'bytes' => filesize($ruta), 'ruta' => $ruta, 'liberada' => $liberada,
               'concluida' => $concluidaLista, 'caso' => $caso, 'marcado' => $marcado, 'vivos' => $vivos,
               'estadoCaso' => $estadoCaso, 'cierreCaso' => $cierreCaso, 'arch' => $arch];
}

/* ------------------------------------------------------------- el simulacro */
echo "== liberar_ot_piloto_cli · " . ($ejecutar ? 'EJECUTAR' : 'SIMULACRO (no escribe)') . " · a nombre de {$u['usuario']} · " . PEDIDO . "\n";
echo '   lista: ' . count($lista['ot']) . ' OT · generada ' . ($lista['generado_en'] ?? '?')
   . ($edadMin !== null ? sprintf(' (hace %.0f min)', $edadMin) : '') . "\n";
echo '   migración 024: ' . ($tiene024 ? 'aplicada' : 'PENDIENTE (el simulacro sigue; --ejecutar no)') . "\n";
foreach ($lista['fuentes'] ?? [] as $f) { echo '   fuente: ' . $f . "\n"; }
echo "\n";
foreach ($plan as $p) {
    $g = Correo::aQuienSale($p['dest']['para'], $p['dest']['cc']);
    $grupos = array_keys(array_filter(['el local' => $g['local'], 'Grupo KFC' => $g['kfc'], 'la administración' => $g['industec']]));
    printf("%s  captura %d · aviso %s · %s · %s\n", $p['id'], $p['cap'], $p['aviso'], $p['zona'], $p['concluida'] ? 'concluida' : 'sin concluir');
    printf("    PDF %s bytes · sha256 %s… · correo %s%s\n", number_format($p['bytes'], 0, ',', '.'), substr($p['huella'], 0, 12),
           $p['q']['estado'], $p['liberada'] ? ' · YA LIBERADA' : '');
    printf("    Para: %s\n    Cc  : %s\n    Sale a: %s\n", implode(', ', $p['dest']['para']), implode(', ', $p['dest']['cc']) ?: '(ninguna)',
           implode(', ', $grupos) ?: '(nadie)');
    printf("    Antes guardado en la cola → Para: %s · Cc: %s\n", $p['q']['para'], $p['q']['cc'] ?? 'NULL');
    printf("    Caso: %s\n", $p['caso']);
    if ($p['vivos'] > 0) { printf("    OJO: el aviso tiene %d solicitud(es) de repuesto viva(s); esta consola no las toca\n", $p['vivos']); }
    echo "\n";
}
if ($errores !== []) {
    fwrite(STDERR, "NO CUADRA — no se escribió nada:\n  - " . implode("\n  - ", $errores) . "\n");
    exit(1);
}
if (!$ejecutar && !$probarSql) {
    echo "Todo cuadra. NO se escribió nada. Agrega --ejecutar para liberar y enviar estas " . count($plan) . " OT.\n";
    exit(0);
}
if (!$tiene024) { salir("falta aplicar la migración 024 (php aplicar_sql.php sql/024_ot_liberadas.sql): no se escribió nada"); }

/* ----------------------------------------------------------------- escrituras */
function anotar(array $u, string $accion, string $entidad, string $ref, ?string $antes, ?string $despues, string $detalle, array $datos): void
{
    Db::ejecutar(
        "INSERT INTO bitacora (usuario_id, usuario, accion, entidad, referencia, estado_antes, estado_despues, exito, detalle, datos, ip, equipo)
         VALUES (?,?,?,?,?,?,?,1,?,?,'consola',?)",
        [$u['usuario_id'], $u['usuario'], $accion, $entidad, $ref, $antes, $despues, $detalle,
         json_encode($datos, JSON_UNESCAPED_UNICODE), ACTOR]
    );
}

/** 1. Libera la OT y deja su correo PENDIENTE con los destinatarios de hoy. Lanza si algo ya no cuadra. */
function liberarYEncolar(array $p, array $u, string $nota, string $respaldo): void
{
    $n = Db::ejecutar("UPDATE ot_capturadas SET liberada_en = NOW(), liberada_por = ?, liberada_nota = ?
                        WHERE captura_id = ? AND liberada_en IS NULL AND estado = 'EMITIDA'",
                      [$u['usuario_id'], $nota, $p['cap']]);
    if ($n !== 1) { throw new RuntimeException('la captura ya no estaba EMITIDA y sin liberar'); }
    $m = Db::ejecutar("UPDATE email_queue
                          SET para = ?, cc = ?, estado = 'PENDIENTE', motivo = NULL, intentos = 0,
                              proximo_intento_en = NULL, error_ultimo = NULL, tomado_en = NULL
                        WHERE correo_id = ? AND estado = 'RETENIDO'",
                      [json_encode($p['dest']['para'], JSON_UNESCAPED_UNICODE),
                       $p['dest']['cc'] !== [] ? json_encode($p['dest']['cc'], JSON_UNESCAPED_UNICODE) : null,
                       $p['q']['correo_id']]);
    if ($m !== 1) { throw new RuntimeException('el correo ya no estaba RETENIDO'); }
    anotar($u, 'OT_LIBERADA', 'ot', $p['id'], 'RETENIDO', 'PENDIENTE',
           'OT INDUSTEC del piloto liberada para enviarla a Grupo KFC (' . PEDIDO . ')',
           ['captura_id' => $p['cap'], 'aviso' => $p['aviso'], 'pdf_sha256' => $p['huella'], 'para' => $p['dest']['para'],
            'cc' => $p['dest']['cc'], 'para_antes' => json_decode((string) $p['q']['para'], true),
            'cc_antes' => json_decode((string) ($p['q']['cc'] ?? 'null'), true), 'respaldo' => $respaldo]);
}

/**
 * 3. El caso, con el correo ya ENVIADO. Devuelve la fila del resumen. Lanza si el caso cambió mientras tanto.
 * @return array{caso_despues:string,nota:string}
 */
function marcarCaso(array $p, array $u, string $respaldo): array
{
    $g = Db::uno('SELECT estado, ot_cierre, atendido_en, veredicto_por, veredicto_en, veredicto_motivo, nota FROM casos_gestion WHERE aviso = ?', [$p['aviso']]);
    $antes = (string) ($g['estado'] ?? '');
    $despues = $antes;
    if ($p['concluida'] && $antes === 'RESUELTO' && strtoupper((string) ($g['ot_cierre'] ?? '')) === $p['id']) {
        $textoNota = date('Y-m-d') . ': ' . $p['id'] . ' se envió hoy a Grupo KFC (antes solo estaba generada en el sistema). '
                   . 'Estaba cerrada en SAP con ese número: confirma en SAP y vuelve a marcarla.';
        $n = Db::ejecutar("UPDATE casos_gestion
                              SET estado = 'ATENDIDO', veredicto_por = NULL, veredicto_en = NULL, veredicto_motivo = NULL,
                                  nota = CONCAT_WS(' · ', NULLIF(nota, ''), ?)
                            WHERE aviso = ? AND estado = 'RESUELTO' AND ot_cierre = ?",
                          [$textoNota, $p['aviso'], $p['id']]);
        if ($n !== 1) { throw new RuntimeException('el caso cambió mientras tanto; no se reabrió'); }
        $despues = 'ATENDIDO';
        $nota = 'reabierto para SAP (antes RESUELTO por usuario ' . ($g['veredicto_por'] ?? '?') . ' el ' . ($g['veredicto_en'] ?? '?') . ')';
    } else {
        Casos::atenderPorOrden($p['aviso'], $p['zona'], $p['id'], $p['concluida'], (int) $p['c']['usuario_id']);
        $despues = (string) (Db::uno('SELECT estado FROM casos_gestion WHERE aviso = ?', [$p['aviso']])['estado'] ?? $antes);
        $nota = $p['concluida'] ? 'Casos::atenderPorOrden' : 'sin concluir: queda como evaluación';
    }
    anotar($u, 'CASO_POR_ENVIO_TARDIO', 'caso', $p['aviso'], $antes, $despues, $p['id'] . ' enviada a Grupo KFC (' . PEDIDO . ')',
           ['ot' => $p['id'], 'antes' => $g, 'concluida' => $p['concluida'], 'respaldo' => $respaldo]);
    return ['caso_despues' => $despues, 'nota' => $nota];
}

$nota = 'Liberada ' . date('Y-m-d') . ', ' . PEDIDO . ': el formulario viejo no reportó este trabajo; la OT existía solo en el sistema';

/* ------------------------------------------------------- --probar-sql: ROLLBACK */
if ($probarSql) {
    if (!$tiene024) { salir('falta aplicar la migración 024: no se puede probar'); }
    $pdo = Db::conn();
    $pdo->beginTransaction();
    echo "== --probar-sql: las escrituras de verdad, dentro de UNA transacción que se revierte al final\n";
    try {
        foreach ($plan as $p) {
            if ($p['liberada']) { echo "  {$p['id']}: ya liberada, se salta\n"; continue; }
            liberarYEncolar($p, $u, $nota, 'prueba-sql');
            echo "  {$p['id']}: liberada + correo PENDIENTE + OT_LIBERADA ✓\n";
        }
        Emision::olvidarLiberadas();
        foreach ($plan as $p) {
            if ($p['liberada'] || $p['marcado']) { continue; }
            $esPiloto = Emision::esDePrueba($p['id']);
            $r = marcarCaso($p, $u, 'prueba-sql');
            echo "  {$p['id']}: esDePrueba tras liberar = " . ($esPiloto ? 'SÍ (MAL)' : 'no') . " · caso {$p['estadoCaso']} → {$r['caso_despues']} · {$r['nota']}\n";
            if ($esPiloto) { throw new RuntimeException('la liberación no surtió efecto en esDePrueba()'); }
        }
        $dentro = Db::uno("SELECT COUNT(*) n FROM email_queue WHERE estado = 'PENDIENTE'")['n'];
        echo "  (dentro de la transacción: $dentro correos PENDIENTE; el despachador NO se ejecuta en este modo)\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        Emision::olvidarLiberadas();
        salir('FALLÓ la prueba de las sentencias: ' . $e->getMessage() . ' (todo revertido)');
    }
    $pdo->rollBack();
    Emision::olvidarLiberadas();
    $despues = Db::uno("SELECT (SELECT COUNT(*) FROM ot_capturadas WHERE liberada_en IS NOT NULL) lib,
                               (SELECT COUNT(*) FROM email_queue WHERE estado = 'PENDIENTE') pen,
                               (SELECT COUNT(*) FROM email_queue WHERE estado = 'RETENIDO') ret");
    echo "REVERTIDO. Estado real ahora: liberadas {$despues['lib']} · correos PENDIENTE {$despues['pen']} · RETENIDO {$despues['ret']}\n";
    exit(0);
}

/* ------------------------------------------------------------------ candado */
$candado = @fopen(sys_get_temp_dir() . '/industec_liberar_ot.lock', 'c');
if ($candado === false || !flock($candado, LOCK_EX | LOCK_NB)) { salir('otra corrida de esta consola sigue en marcha'); }

/* ------------------------------------------------------------------ respaldo */
$aPendiente = array_values(array_filter($plan, static fn(array $p): bool => !$p['liberada']));
$dirBackup = rtrim((string) getenv('HOME'), '/') . '/respaldos/liberar_ot_piloto_' . date('Ymd_His');
if (!@mkdir($dirBackup, 0700, true) && !is_dir($dirBackup)) { salir("no pude crear el respaldo $dirBackup"); }
$filasAntes = [];
$sumas = '';
foreach ($plan as $p) {
    $filasAntes[$p['id']] = [
        'ot_capturadas' => $p['c'], 'email_queue' => $p['q'], 'casos_gestion' => $p['g'], 'ot_archivo' => $p['arch'],
        'pendientes_vivos' => $p['vivos'],
    ];
    $copia = $dirBackup . '/' . $p['id'] . '.pdf';
    if (!@copy($p['ruta'], $copia) || hash_file('sha256', $copia) !== $p['huella']) { salir('no pude respaldar el PDF de ' . $p['id']); }
    $sumas .= $p['huella'] . '  ' . $p['id'] . ".pdf\n";
}
file_put_contents($dirBackup . '/filas_antes.json', json_encode($filasAntes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR));
file_put_contents($dirBackup . '/SHA256SUMS', $sumas);
file_put_contents($dirBackup . '/lista.json', (string) file_get_contents($rl));
echo "Respaldo: $dirBackup (" . count($plan) . " PDF + filas_antes.json + SHA256SUMS)\n";

/* -------------------------------------------------------- 1. liberar y encolar */
$pdo = Db::conn();
foreach ($aPendiente as $p) {
    $pdo->beginTransaction();
    try {
        liberarYEncolar($p, $u, $nota, basename($dirBackup));
        $pdo->commit();
        echo "  liberada y en cola: {$p['id']}\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        salir("FALLÓ {$p['id']}: " . $e->getMessage() . ' (esa OT no cambió; las anteriores quedaron liberadas y en cola)');
    }
}
Emision::olvidarLiberadas();

/* ------------------------------------------------------------------ 2. enviar */
if ($sinEnviar) {
    echo "--sin-enviar: quedan PENDIENTE; las despachará la próxima OT, el cron o «Enviar ahora» en Correos.\n";
} else {
    $r = Correo::despachar(max(1, count($plan)), 150);
    echo 'Despacho: ' . $r['estado'] . ' · enviados ' . $r['enviados'] . ' · temporales ' . $r['temporales']
       . ' · fallidos ' . $r['fallidos'] . ' · retenidos ' . $r['retenidos'] . "\n";
    foreach ($r['lineas'] as $l) { echo $l . "\n"; }
}

/* ------------------------------------------------------------ 3. marcar casos */
$resumen = [];
$sinTerminar = 0;
foreach ($plan as $p) {
    $q = Db::uno('SELECT estado, enviado_en, enviado_desde, intentos, error_ultimo FROM email_queue WHERE correo_id = ?', [$p['q']['correo_id']]);
    $fila = ['ot' => $p['id'], 'correo' => $q['estado'], 'enviado_en' => $q['enviado_en'], 'enviado_desde' => $q['enviado_desde'],
             'caso_antes' => $p['estadoCaso'], 'caso_despues' => $p['estadoCaso'], 'nota' => ''];
    if ($q['estado'] !== 'ENVIADO') {
        $sinTerminar++;
        $fila['nota'] = 'el correo no salió todavía (' . ($q['error_ultimo'] ?? $q['estado']) . '): el caso no se tocó';
        $resumen[] = $fila;
        continue;
    }
    $yaAnotado = (bool) Db::uno("SELECT 1 FROM bitacora WHERE accion = 'CASO_POR_ENVIO_TARDIO' AND referencia = ? AND detalle LIKE ? LIMIT 1",
                                [$p['aviso'], '%' . $p['id'] . '%']);
    if ($yaAnotado) { $fila['nota'] = 'caso ya anotado antes'; $resumen[] = $fila; continue; }
    try {
        $r = marcarCaso($p, $u, basename($dirBackup));
        $fila['caso_despues'] = $r['caso_despues'];
        $fila['nota'] = $r['nota'];
    } catch (Throwable $e) {
        $fila['nota'] = 'FALLÓ el caso: ' . $e->getMessage();
        $sinTerminar++;
    }
    $resumen[] = $fila;
}

file_put_contents($dirBackup . '/resultado.json', json_encode($resumen, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "\n== Resultado\n";
foreach ($resumen as $f) {
    printf("  %-34s correo %-9s %s · caso %s → %s · %s\n", $f['ot'], $f['correo'], $f['enviado_en'] ?? '—', $f['caso_antes'], $f['caso_despues'], $f['nota']);
}
echo "\nRespaldo y resultado en $dirBackup\n";
flock($candado, LOCK_UN);
exit($sinTerminar > 0 ? 2 : 0);
