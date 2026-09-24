<?php
declare(strict_types=1);
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Auth.php';       // la bitácora de la regeneración y del correo sin destino
require_once __DIR__ . '/Catalogo.php';
require_once __DIR__ . '/Destinatarios.php';   // T2.28.2: a quién va la cola y qué imprime el PDF

/**
 * Emision.php — Convierte una orden recibida en una orden de trabajo: su número,
 * su PDF y su correo (T2.13, la migración 008).
 *
 * ============================================================================
 * EL ORDEN IMPORTA (DISENO_APP_OTS.md §3)
 *
 *   la orden ya está guardada (envio.php) -> el número -> el PDF -> el correo
 *
 * El número se reserva recién aquí, con la orden validada y guardada: un
 * formulario abandonado no quema correlativos, como los 87 envíos en blanco de
 * producción. Como la orden ya es una fila, el PDF es una representación que se
 * puede volver a generar, y un SMTP caído no hace desaparecer una orden: el
 * correo queda en la cola.
 *
 * `emitir()` se puede llamar las veces que haga falta y no repite lo hecho: el
 * número se reserva una sola vez por orden, el PDF se genera solo si falta y el
 * correo tiene clave única por orden.
 *
 * ============================================================================
 * EN EL SITIO DE PRUEBAS —modo PRUEBA, el que rige mientras config.php no diga
 * 'emision_modo' => 'PRODUCCION'—:
 *
 *   - las series arrancan en 9000: una OT de prueba no puede llevar un número
 *     que exista o vaya a existir pronto en producción (UIO va por el 2.4xx);
 *   - el PDF lleva una franja «DOCUMENTO DE PRUEBA»;
 *   - el correo queda RETENIDO y no sale nunca. Producción manda cada orden al
 *     local, a Grupo KFC y al buzón de la administradora, que se lee de forma
 *     automática: un correo de prueba llegaría como una orden real.
 *
 * AL PASAR A PRODUCCION hay que cargar a mano, con la emisión detenida, los
 * contadores reales (`counter_{zona}.txt` de cada módulo) en `correlativos`, y
 * los destinatarios fijos y por zona en config.php (`correo_fijos`,
 * `correo_por_zona`): no se escriben en el código porque son personas. En modo
 * PRODUCCION una serie sin contador no se inventa: la emisión se detiene y lo dice.
 * ============================================================================
 */
final class Emision
{
    public const SERIE_PRUEBA = 9000;
    /* OTRA existe en el esquema y en cuatro locales reales (Pollo Gus fuera de
       las tres zonas): sin ella esas órdenes nunca recibían número (E-01, D7). Su
       serie es propia (CORRECTIVO:OTRA) y el sufijo del nombre, -OTRA. Qué
       contador lleva producción para ellos lo confirma Andrés en el corte. */
    private const ZONAS = ['UIO', 'LARB', 'CNLJ', 'OTRA'];

    private static ?array $cat = null;

    public static function modo(): string
    {
        return ((Db::config()['emision_modo'] ?? '') === 'PRODUCCION') ? 'PRODUCCION' : 'PRUEBA';
    }

    /** Donde quedan los PDF. Los sirve pdf.php, con sesión y alcance. */
    public static function dirPdf(): string
    {
        return dirname(__DIR__) . '/ordenes_pdf';
    }

    /** Donde quedan las fotos, recodificadas. No se sirven por web. */
    public static function dirFotos(): string
    {
        return dirname(__DIR__) . '/ordenes_fotos';
    }

    /**
     * El patrón canónico de nombre de OT, en sus cuatro formas (más la zona
     * OTRA, que existe en el esquema y en cuatro locales reales). Es la misma
     * expresión que valida pdf.php: si cambia una, cambia la otra.
     */
    public const PATRON_OT = '/^OT-\d{3,5}-[A-Z]{1,2}\d{2,4}(EC)?(-\d{6,10})?(-D\d{1,2})?-(UIO|LARB|CNLJ|OTRA)$/';

    /**
     * ¿Existe el PDF de esa orden en el servidor?
     *
     * Las pantallas pintaban «Ver PDF» sin mirar: atenciones.json referencia
     * 130 órdenes y en disco hay 120, así que el botón llevaba a un 404. Antes
     * de ofrecer el enlace se pregunta aquí; si no está, la pantalla lo dice.
     */
    public static function existePdf(string $ot): bool
    {
        $ot = strtoupper(trim($ot));
        if (!preg_match(self::PATRON_OT, $ot)) {
            return false;
        }
        return is_file(self::dirPdf() . '/' . $ot . '.pdf');
    }

    /**
     * El siguiente número de la serie, en una sola sentencia atómica.
     *
     * Nada de leer y después escribir: con dos envíos a la vez los dos leen el
     * mismo valor, que es como producción entrega números repetidos. El UPDATE
     * con LAST_INSERT_ID(expr) suma y deja el valor en la conexión en el mismo
     * paso, con la fila bloqueada hasta que termine la transacción.
     */
    public static function reservar(string $serie): int
    {
        if (self::modo() === 'PRUEBA') {
            Db::ejecutar('INSERT INTO correlativos (serie, ultimo, nota) VALUES (?, ?, ?)
                          ON DUPLICATE KEY UPDATE serie = serie',
                         [$serie, self::SERIE_PRUEBA,
                          'sitio de pruebas: serie desde ' . self::SERIE_PRUEBA . ', no se cruza con la de producción']);
        }
        if (Db::ejecutar('UPDATE correlativos SET ultimo = LAST_INSERT_ID(ultimo + 1) WHERE serie = ?',
                         [$serie]) !== 1) {
            throw new RuntimeException("la serie $serie no tiene contador: hay que cargarle el de producción");
        }
        return (int) Db::uno('SELECT LAST_INSERT_ID() AS n')['n'];
    }

    /** El nombre canónico de la orden (PLAN, invariantes): OT-{n:4}-{LOCAL}[-{AVISO}][-D{día}]-{ZONA}. */
    public static function idIndustec(int $n, string $local, ?string $aviso, ?int $dia, string $zona): string
    {
        return 'OT-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT) . '-' . $local
             . ($aviso !== null && $aviso !== '' ? '-' . $aviso : '')
             . ($dia ? '-D' . $dia : '') . '-' . $zona;
    }

    /**
     * Número, PDF y correo de la orden recibida.
     *
     * @return array{id_industec:?string, pdf:bool, correo:?string, error:?string}
     */
    public static function emitir(int $capturaId): array
    {
        $r = ['id_industec' => null, 'pdf' => false, 'correo' => null, 'error' => null];
        $c = Db::uno('SELECT * FROM ot_capturadas WHERE captura_id = ?', [$capturaId]);
        if ($c === null) { $r['error'] = 'la orden no existe'; return $r; }
        $orden  = json_decode((string) $c['carga'], true) ?: [];
        $zona   = (string) ($c['zona'] ?? '');
        $local  = (string) ($c['local_codigo'] ?? '');
        $modulo = (string) ($c['modulo'] ?? '');
        $aviso  = (string) ($c['aviso'] ?? '');
        $dia    = $modulo === 'PREVENTIVO' ? (int) ($orden['dia_intervencion'] ?? 0) : 0;

        // 1. El número, una sola vez por orden.
        $id = $c['id_industec'];
        if ($id === null) {
            if (!in_array($zona, self::ZONAS, true) || $local === ''
                || !in_array($modulo, ['CORRECTIVO', 'PREVENTIVO'], true)) {
                return self::falla($capturaId, $r, 'sin zona, local o tipo de trabajo válidos no se le puede dar número');
            }
            $pdo = Db::conn();
            try {
                $pdo->beginTransaction();
                // FOR UPDATE: dos reintentos del mismo envío a la vez no sacan dos números.
                $id = Db::uno('SELECT id_industec FROM ot_capturadas WHERE captura_id = ? FOR UPDATE',
                              [$capturaId])['id_industec'] ?? null;
                if ($id === null) {
                    $id = self::idIndustec(self::reservar($modulo . ':' . $zona), $local,
                                           $aviso !== '' ? $aviso : null, $dia ?: null, $zona);
                    // NUMERADA: con correlativo y todavía sin PDF (E-12).
                    Db::ejecutar("UPDATE ot_capturadas SET id_industec = ?, estado = 'NUMERADA' WHERE captura_id = ?",
                                 [$id, $capturaId]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                return self::falla($capturaId, $r, 'no se pudo reservar el número: ' . $e->getMessage());
            }
        }
        $r['id_industec'] = $id;

        // 2. El PDF. Se genera si falta: la fila es el registro y el PDF, su representación.
        $ruta = self::dirPdf() . '/' . $id . '.pdf';
        if ($c['emitida_en'] === null || !is_file($ruta)) {
            $pdo = Db::conn();
            try {
                /* Candado sobre la fila mientras se arma el PDF (E-21): dos reintentos
                   simultáneos chocaban en el rename y uno anotaba un error falso. Si
                   al tomarlo el archivo ya existe y la orden ya está emitida, no se
                   regenera. El .tmp lleva sufijo aleatorio por lo mismo. */
                $pdo->beginTransaction();
                $fila = Db::uno('SELECT emitida_en, pdf_sha256 FROM ot_capturadas WHERE captura_id = ? FOR UPDATE', [$capturaId]);
                if (($fila['emitida_en'] ?? null) !== null && is_file($ruta)) {
                    $pdo->commit();
                } else {
                    $pdf = self::pdf($c, $orden, $id);
                    if (!is_dir(self::dirPdf())) { mkdir(self::dirPdf(), 0755, true); }
                    $tmp = $ruta . '.' . bin2hex(random_bytes(4)) . '.tmp';
                    if (file_put_contents($tmp, $pdf) !== strlen($pdf) || !rename($tmp, $ruta)) {
                        @unlink($tmp);
                        throw new RuntimeException('no se pudo escribir el archivo');
                    }
                    $huella = hash('sha256', $pdf);
                    /* Regenerar un PDF perdido conserva la fecha y la huella del que
                       recibió KFC; la nueva va aparte (E-10). */
                    $regen = ($fila['emitida_en'] ?? null) !== null;
                    Db::ejecutar("UPDATE ot_capturadas
                                     SET emitida_en = COALESCE(emitida_en, NOW()),
                                         pdf_sha256 = COALESCE(pdf_sha256, ?),
                                         pdf_sha256_regen = ?,
                                         estado = 'EMITIDA', emision_error = NULL
                                   WHERE captura_id = ?",
                                 [$huella, $regen && ($fila['pdf_sha256'] ?? null) !== $huella ? $huella : null, $capturaId]);
                    if ($regen) {
                        Auth::bitacora('REGENERAR_PDF', 'ot', $id, 'PDF regenerado desde la fila', null, null,
                                       ['sha256_original' => $fila['pdf_sha256'] ?? null, 'sha256_nuevo' => $huella]);
                    }
                    $pdo->commit();
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                return self::falla($capturaId, $r, 'no se pudo generar el PDF: ' . $e->getMessage());
            }
        }
        $r['pdf'] = true;

        // 3. El correo, a la cola. En el sitio de pruebas queda retenido.
        try {
            $r['correo'] = self::encolar($c, $orden, $id);
        } catch (Throwable $e) {
            return self::falla($capturaId, $r, 'no se pudo encolar el correo: ' . $e->getMessage());
        }
        return $r;
    }

    /** Anota por qué no salió, para que se vea y se reintente. La orden sigue guardada. */
    private static function falla(int $capturaId, array $r, string $motivo): array
    {
        error_log('Emision, captura ' . $capturaId . ': ' . $motivo);
        try {
            // FALLIDA solo si todavía no hay PDF: un fallo del correo no borra la
            // emisión. El reemisor (emitir_pendientes_cli.php) recoge las dos cosas.
            Db::ejecutar("UPDATE ot_capturadas
                             SET emision_error = ?,
                                 estado = IF(emitida_en IS NULL, 'FALLIDA', estado)
                           WHERE captura_id = ?",
                         [mb_substr($motivo, 0, 300), $capturaId]);
        } catch (Throwable $e) {
            // Sin la 008 no hay dónde anotarlo; queda el registro de errores.
        }
        $r['error'] = $motivo;
        return $r;
    }

    /**
     * El correo del local para ESTA orden: el que escribió o eligió el técnico,
     * si es válido y no es un buzón de INDUSTEC; si no, el del maestro.
     * Hasta el 2026-09-23 el formulario dejaba editar... nada: el campo era de
     * solo lectura y, aunque no lo fuera, la cola y el PDF leían el maestro,
     * donde 95 de 100 locales tienen `servicioalcliente@industec.me` (error nº 40:
     * un campo editable no sirve si el que envía lee otra fuente).
     */
    public static function correoLocal(array $orden, array $local): string
    {
        $escrito = strtolower(trim((string) ($orden['correo_local'] ?? '')));
        if ($escrito !== '' && filter_var($escrito, FILTER_VALIDATE_EMAIL)
            && !str_ends_with($escrito, '@industec.me')) {
            return $escrito;
        }
        return trim((string) ($local['correo_local'] ?? ''));
    }

    /**
     * @return string el estado en que quedó el correo
     *
     * A quién va la orden lo decide `Destinatarios::resolver()` (T2.28.2): el
     * correo del local, el buzón del jefe de zona, las copias internas y las
     * del cliente que configuró la administración en `correos.php`. Sin la
     * 013 aplicada o sin filas todavía, `resolver()` hace exactamente lo de
     * antes (maestro + config.php), así que esta función no necesita saber
     * si la migración llegó o no.
     */
    private static function encolar(array $c, array $orden, string $id): string
    {
        $local  = self::local((string) ($c['local_codigo'] ?? ''));
        $zona   = (string) ($c['zona'] ?? '');
        $cadena = (string) ($local['cadena'] ?? ($c['cadena'] ?? ''));
        $codLocal = (string) ($c['local_codigo'] ?? '');
        $dest = Destinatarios::resolver('ORDEN', $zona, $codLocal !== '' ? $codLocal : null,
                                        $cadena !== '' ? $cadena : null, $orden['correo_local'] ?? null);
        $para = $dest['para'];
        $cc   = $dest['cc'];
        $prueba = self::modo() === 'PRUEBA';
        /* En producción una orden sin destinatarios no se encola como si fuera a
           salir: queda FALLIDO con el motivo y en la bitácora (E-15). */
        $sinDestino = !$prueba && $para === [];
        if ($sinDestino) {
            Auth::bitacora('CORREO_SIN_DESTINATARIO', 'ot', $id,
                           'el local no tiene correo y no hay ningún destinatario configurado', null, null, [], false);
        }
        $cuerpo = "Se ha generado una nueva OT: $id\nZona: $zona\nLocal: " . ($c['local_codigo'] ?? '')
                . "\nORDEN SAP: " . ($c['aviso'] ?? 'sin aviso')
                . "\nTipo de Trabajo: " . ucfirst(strtolower((string) $c['modulo']))
                . "\nEstado de OT: " . ($orden['estado_ot'] ?? '') . "\nTécnico: " . ($orden['tecnico'] ?? '');
        // `cc` es copia congelada al encolar (T2.28.2): si mañana cambia la
        // configuración, la bitácora de este correo sigue diciendo a quién fue
        // de verdad. NULL en vez de '[]' cuando no hay copias, para no ensuciar
        // el reporte de correos.php con corchetes vacíos.
        Db::ejecutar("INSERT INTO email_queue (captura_id, id_industec, tipo, para, cc, asunto, cuerpo, adjunto, estado, motivo)
                      VALUES (?, ?, 'EMISION', ?, ?, ?, ?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE
                        para     = IF(estado = 'FALLIDO' AND VALUES(estado) = 'PENDIENTE', VALUES(para), para),
                        cc       = IF(estado = 'FALLIDO' AND VALUES(estado) = 'PENDIENTE', VALUES(cc), cc),
                        estado   = IF(estado = 'FALLIDO' AND VALUES(estado) = 'PENDIENTE', 'PENDIENTE', estado),
                        intentos = IF(estado = 'FALLIDO' AND VALUES(estado) = 'PENDIENTE', 0, intentos),
                        proximo_intento_en = IF(estado = 'FALLIDO' AND VALUES(estado) = 'PENDIENTE', NULL, proximo_intento_en),
                        motivo   = IF(estado = 'FALLIDO' AND VALUES(estado) = 'PENDIENTE', NULL, motivo)",
                     [(int) $c['captura_id'], $id, json_encode($para, JSON_UNESCAPED_UNICODE),
                      $cc !== [] ? json_encode($cc, JSON_UNESCAPED_UNICODE) : null,
                      'ORDEN DE TRABAJO INDUSTEC - ' . $id, $cuerpo, $id . '.pdf',
                      $prueba ? 'RETENIDO' : ($sinDestino ? 'FALLIDO' : 'PENDIENTE'),
                      $prueba ? 'sitio de pruebas: los correos no salen (irían al local, a Grupo KFC y a las copias configuradas)'
                              : ($sinDestino ? 'sin destinatarios: el local no tiene correo y no hay ningún destinatario configurado' : null)]);
        return (string) Db::uno("SELECT estado FROM email_queue WHERE id_industec = ? AND tipo = 'EMISION'", [$id])['estado'];
    }

    /**
     * Reintenta las emisiones que quedaron a medias: sin número, sin PDF o con
     * un error anotado (H-03, E-02). Lo llama envio.php al recibir una orden
     * —de forma oportunista, pocas— y emitir_pendientes_cli.php desde el cron.
     * Hasta la 009 nadie las reintentaba: el celular ya había marcado ENVIADA.
     *
     * @return int cuántas quedaron emitidas en esta pasada
     */
    public static function reintentarPendientes(int $max = 3): int
    {
        $filas = Db::todos(
            "SELECT captura_id FROM ot_capturadas
              WHERE estado IN ('RECIBIDA', 'NUMERADA', 'FALLIDA') OR emision_error IS NOT NULL
              ORDER BY recibida_en LIMIT " . max(1, min(200, $max))
        );
        $ok = 0;
        foreach ($filas as $f) {
            $r = self::emitir((int) $f['captura_id']);
            if ($r['pdf'] && $r['error'] === null) { $ok++; }
        }
        return $ok;
    }

    /** El PDF, con dompdf y la plantilla de producción. */
    public static function pdf(array $c, array $orden, string $id): string
    {
        self::cargarDompdf();
        $opt = new \Dompdf\Options();
        // Nada remoto: el logo, las fotos y la firma van embebidos. Producción lo
        // tiene encendido, que es la puerta a que un PDF pida archivos de afuera.
        $opt->set('isRemoteEnabled', false);
        $opt->set('isHtml5ParserEnabled', true);
        $opt->set('defaultFont', 'DejaVu Sans');
        $opt->set('dpi', 96);
        $d = new \Dompdf\Dompdf($opt);
        $d->loadHtml(self::html($c, $orden, $id), 'UTF-8');
        $d->setPaper('A4', 'portrait');
        $d->render();
        return (string) $d->output();
    }

    /** El HTML de la orden: los datos que pinta plantilla_ot.php. */
    public static function html(array $c, array $orden, string $id): string
    {
        $codLocal = (string) ($c['local_codigo'] ?? '');
        $local = self::local($codLocal);
        $delLocal = [];
        foreach ((self::catalogo()['equipos'][$codLocal] ?? []) as $e) {
            $delLocal[(string) ($e['equipo_sap'] ?? '')] = $e;
        }
        $equipos = [];
        foreach ((array) ($orden['equipos'] ?? []) as $eq) {
            if (!is_array($eq)) { continue; }
            $cat = isset($eq['equipo_sap']) ? ($delLocal[(string) $eq['equipo_sap']] ?? []) : [];
            $equipos[] = [
                'tipo'          => (string) ($eq['tipo'] ?? '') ?: (string) ($cat['tipo'] ?? ''),
                'clase'         => $cat['clase'] ?? null,
                'equipo_sap'    => $eq['equipo_sap'] ?? null,
                'codigo_activo' => $cat['codigo_activo'] ?? null,
                'ubicacion'     => $cat['ubicacion_tecnica'] ?? null,
                // El maestro no los trae: salen solo si el técnico los escribió.
                'marca'         => $eq['marca'] ?? null,
                'modelo'        => $eq['modelo'] ?? null,
                'serie'         => $eq['serie'] ?? null,
                'estado'        => $eq['estado'] ?? null,
                'obs'           => $eq['obs'] ?? null,
            ];
        }
        $fotos = [];
        foreach (Db::todos('SELECT ruta FROM ot_fotos WHERE envio_uuid = ? ORDER BY orden_n, foto_id',
                           [(string) $c['envio_uuid']]) as $f) {
            $p = self::dirFotos() . '/' . $f['ruta'];
            if (is_file($p)) { $fotos[] = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($p)); }
        }
        $logo = __DIR__ . '/logo-industec.png';
        $d = [
            'id'              => $id,
            'prueba'          => self::modo() === 'PRUEBA',
            'logo'            => is_file($logo) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logo)) : '',
            'aviso'           => (string) ($c['aviso'] ?? ''),
            'modulo'          => (string) ($c['modulo'] ?? ''),
            'dia'             => $orden['dia_intervencion'] ?? null,
            'fecha'           => $orden['fecha_atencion'] ?? '',
            'cliente'         => (string) ($local['cadena'] ?? ($c['cadena'] ?? '')),
            'local'           => trim($codLocal . ' · ' . ($local['nombre'] ?? ''), ' ·'),
            'tecnico'         => (string) ($orden['tecnico'] ?? ''),
            'admin'           => (string) ($orden['admin'] ?? ''),
            'correo_local'    => self::correoLocal($orden, $local),
            // T2.28.2 (D-G): el jefe de operaciones de KFC, no el buzón de zona
            // de INDUSTEC (ese va siempre en copia, no en esta línea). Casi
            // ningún local lo tiene todavía: «sin configurar» es lo esperado
            // hasta que la administración lo cargue en correos.php.
            'correo_jefe_op'  => Destinatarios::jefeOperaciones($codLocal, (string) ($local['cadena'] ?? '')) ?? 'sin configurar',
            'equipos'         => $equipos,
            'inicio'          => $orden['inicio'] ?? null,
            'fin'             => $orden['fin'] ?? null,
            'actividades'     => (string) ($orden['actividades'] ?? ''),
            'repuestos'       => !empty($orden['uso_repuesto']) ? (string) ($orden['repuestos'] ?? '') : 'No se usaron repuestos.',
            // D10: cuando el trabajo lo hizo otro proveedor, la orden lo dice
            // con nombre; INDUSTEC registra y acompaña, no lo firma como suyo.
            'con_proveedor'   => trim((string) ($c['con_proveedor'] ?? ($orden['con_proveedor'] ?? ''))),
            'observaciones'   => (string) ($orden['observaciones'] ?? ''),
            'estado_ot'       => (string) ($orden['estado_ot'] ?? ''),
            'atiempo'         => (string) ($orden['atiempo'] ?? ''),
            'satisfaccion'    => max(0, min(10, (int) ($orden['satisfaccion'] ?? 0))),
            'fotos'           => $fotos,
            'fotos_esperadas' => count((array) ($orden['fotos'] ?? [])),
            'firma'           => self::firmaValida((string) ($orden['firma_png'] ?? '')),
            'emitida'         => date('Y-m-d H:i'),
        ];
        ob_start();
        include __DIR__ . '/plantilla_ot.php';
        return (string) ob_get_clean();
    }

    /** La firma, solo si de verdad es una imagen PNG de tamaño razonable: va dentro del PDF. */
    private static function firmaValida(string $uri): string
    {
        $pre = 'data:image/png;base64,';
        if (!str_starts_with($uri, $pre) || strlen($uri) > 400000) { return ''; }
        $bin = base64_decode(substr($uri, strlen($pre)), true);
        return ($bin !== false && @getimagesizefromstring($bin) !== false) ? $uri : '';
    }

    private static function catalogo(): array
    {
        return self::$cat ??= (Catalogo::cargar() ?? ['locales' => [], 'equipos' => []]);
    }

    private static function local(string $codigo): array
    {
        foreach (self::catalogo()['locales'] as $l) {
            if (($l['codigo'] ?? null) === $codigo) { return $l; }
        }
        return [];
    }

    /** dompdf vive fuera de la carpeta web (app/lib/LEEME.md). */
    private static function cargarDompdf(): void
    {
        if (class_exists(\Dompdf\Dompdf::class)) { return; }
        $cfg = Db::config();
        foreach (array_filter([
            $cfg['dompdf_autoload'] ?? null,
            dirname(__DIR__, 5) . '/lib/ot/vendor/autoload.php',   // Hostinger: ~/lib/ot, fuera de public_html
            dirname(__DIR__, 2) . '/lib/vendor/autoload.php',      // estación: app/lib
        ]) as $a) {
            if (is_file($a)) { require_once $a; return; }
        }
        throw new RuntimeException('falta dompdf: hay que instalarlo (app/lib/LEEME.md)');
    }
}
