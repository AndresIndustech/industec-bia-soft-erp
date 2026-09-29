<?php
declare(strict_types=1);
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Auth.php';       // la bitácora de la regeneración y del correo sin destino
require_once __DIR__ . '/Catalogo.php';
require_once __DIR__ . '/Destinatarios.php';   // T2.28.2: a quién va la cola y qué imprime el PDF

/**
 * Emision.php — Convierte una OT INDUSTEC recibida del celular en un documento
 * emitido: su número OT-NNNN, su PDF y su correo (T2.13, la migración 008).
 *
 * VOCABULARIO (24-sep-2026): lo que se emite aquí es la «OT INDUSTEC», no «la
 * orden» (esa palabra es del trabajo que pide KFC). Los mensajes que alguien lee
 * —el error que queda anotado en la captura— usan ese término. NO cambian, porque
 * son contrato (contratos_externos de vocabulario.json): el asunto «ORDEN DE
 * TRABAJO INDUSTEC - OT-…» y el cuerpo del correo («nueva OT:», «Zona:», «Local:»,
 * «ORDEN SAP:», «Tipo de Trabajo:», «Estado de OT:»), que t2_11_informes_ot.py lee
 * campo por campo; por eso «Zona:» lleva el código (CNLJ), no el rótulo.
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
 *   - el correo queda RETENIDO y no sale nunca. Producción manda cada orden al
 *     local, a Grupo KFC y al buzón de la administradora, que se lee de forma
 *     automática: un correo de prueba llegaría como una orden real.
 *
 * El PDF YA NO lleva la franja «DOCUMENTO DE PRUEBA» (decisión de Andrés del
 * 28-sep-2026: «de ahora en adelante ninguna orden salga con esa franja»), en
 * ningún modo. Lo que avisa ahora que la OT es del piloto no está en el
 * documento sino alrededor de él, y se queda: la caja naranja y las dos franjas
 * del formulario del técnico, la marca «del piloto · no enviada a KFC» en el
 * Archivo, el buzón, Repuestos y el historial (`esDePrueba()`, por el número),
 * y el Archivo sin «Compartir». La plantilla (`plantilla_ot.php`, contrato
 * externo) conserva el bloque; `html()` le pasa `prueba = false` siempre.
 *
 * ============================================================================
 * DOS DATOS QUE UNA REGENERACIÓN NO PUEDE CAMBIAR (28-sep-2026)
 *
 *   - La fecha de emisión: «Documento generado automáticamente el …» es
 *     `emitida_en` cuando la orden ya se emitió (`fechaEmision()`). Antes era la
 *     hora de la regeneración, y una copia de un PDF perdido parecía un
 *     documento nuevo.
 *   - La fecha de atención vale como la corrigió la administración, si la
 *     corrigió (`fechaAtencion()`): la corrección va en la carga, en la clave
 *     `correccion_admin` {fecha_atencion, antes, por, en, motivo}, al lado del
 *     `fecha_atencion` que escribió el técnico, que no se toca. La leen de ahí
 *     `html()` y `archivo_indexar_cli.php` —el índice que se rehace cada noche—
 *     por la misma función: una sola regla, y ninguna de las dos rehace la
 *     fecha vieja.
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

    /**
     * ¿Es una OT INDUSTEC del piloto? Por el NÚMERO: la serie arranca en
     * SERIE_PRUEBA (9000) solo en modo PRUEBA, y la de producción va por el
     * 2.6xx en la zona más adelantada (CNLJ, 28-sep-2026). Medido ese día: de
     * 299 OT del correo y 7.545 del histórico, ninguna pasa de 2.621; las 14
     * que emitió la app son las 14 de la serie 9000.
     *
     * Por qué el número y no el modo ni la bitácora: el 24 al 27-sep-2026 los
     * técnicos de UIO tomaron 14 OT del piloto (OT-9125 a OT-9152) por reales,
     * ninguna llegó a Grupo KFC, y la del aviso 10356500 dejó una solicitud de
     * repuesto colgando de un documento que KFC nunca vio. Lo que la vuelve
     * inválida es haber salido de la serie de pruebas, y eso lo lleva el propio
     * nombre en TODAS las fuentes —ot_capturadas, ot_archivo, casos_gestion.
     * ot_cierre, atenciones.json—, sin consultar nada. El modo cambia el día del
     * corte; una OT-9147 sigue siendo del piloto después. La bitácora (EMISION,
     * datos.modo) dice lo mismo, pero solo para lo emitido desde el 21-sep y a
     * costa de una consulta por documento.
     *
     * Los nombres que no empiezan por un número (`OT-Cajun-10280653-CNLJ-023`,
     * el segundo patrón de la empresa) no son del piloto.
     */
    public static function esDePrueba(?string $id): bool
    {
        // trim solo de espacios y sin tope de cifras: exactamente lo que hace
        // `sqlEsDePrueba()` con TRIM() y su REGEXP. Hasta la revisión del
        // 28-sep-2026 el PHP cortaba en 7 cifras y quitaba tabuladores, y el
        // SQL no: un `OT-10356500-…` (el aviso en el lugar del número) o un
        // espacio al inicio se clasificaba distinto en el Archivo que en el
        // buzón. El largo va primero para que un número de 19+ cifras no
        // desborde el (int).
        if (!preg_match('/^OT-0*(\d+)-/', strtoupper(trim((string) $id, ' ')), $m)) {
            return false;
        }
        return strlen($m[1]) > 18 || (int) $m[1] >= self::SERIE_PRUEBA;
    }

    /**
     * El mismo criterio de `esDePrueba()`, para una columna dentro de un SQL.
     *
     * REGEXP y no CAST: en un UPDATE con modo estricto, `CAST('Cajun' AS
     * UNSIGNED)` aborta la sentencia con el error 1292 en vez de dar 0. La
     * expresión está escrita para SERIE_PRUEBA = 9000 (cuatro cifras desde el
     * 9, o cinco o más); `prueba_ot_piloto.php` falla si la constante cambia
     * sin cambiar esto. Devuelve NULL con una columna NULL: quien la use pone
     * antes su `IS NOT NULL`. UPPER(TRIM()) por lo mismo que el PHP: las
     * columnas son `_ci` hoy (28-sep-2026), pero el criterio no puede depender
     * de la colación de una tabla que alguien cree mañana.
     */
    public static function sqlEsDePrueba(string $columna): string
    {
        return "(UPPER(TRIM($columna)) REGEXP '^OT-0*(9[0-9]{3}|[1-9][0-9]{4,})-')";
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
        $modo = self::modo();
        if ($modo === 'PRUEBA') {
            Db::ejecutar('INSERT INTO correlativos (serie, ultimo, nota) VALUES (?, ?, ?)
                          ON DUPLICATE KEY UPDATE serie = serie',
                         [$serie, self::SERIE_PRUEBA,
                          'sitio de pruebas: serie desde ' . self::SERIE_PRUEBA . ', no se cruza con la de producción']);
        }
        if (Db::ejecutar('UPDATE correlativos SET ultimo = LAST_INSERT_ID(ultimo + 1) WHERE serie = ?',
                         [$serie]) !== 1) {
            throw new RuntimeException("la serie $serie no tiene contador: hay que cargarle el de producción");
        }
        $n = (int) Db::uno('SELECT LAST_INSERT_ID() AS n')['n'];
        // Se lanza DENTRO de la transacción de emitir(): el rollBack devuelve
        // el contador y la OT queda FALLIDA con este motivo, para reintentar.
        $error = self::errorDeSerie($serie, $n, $modo);
        if ($error !== null) { throw new RuntimeException($error); }
        return $n;
    }

    /**
     * ¿El número reservado contradice el modo? Todo el criterio del piloto es
     * el NÚMERO (esDePrueba: 9000 o más), así que el número y el modo tienen
     * que decir lo mismo, o la OT se clasifica al revés sin que nada falle:
     *
     *   - PRODUCCION con un número de la serie de pruebas. El día del corte, si
     *     no se carga a mano el contador real en `correlativos`, CORRECTIVO:UIO
     *     sigue en 9152 y la primera OT que SÍ llega a Grupo KFC saldría como
     *     OT-9153: el sistema la trataría como del piloto (no atiende la
     *     orden, no se comparte, no cuenta en las cifras) y el técnico vería
     *     «NO llegó a Grupo KFC» sobre una que llegó. Revisión del 28-sep-2026.
     *   - PRUEBA con un número de producción: una fila de `correlativos` que
     *     alguien cargó antes de cambiar el modo. La OT no sale a nadie, pero
     *     con un número de producción el sistema la daría por válida.
     *
     * null si el número vale; si no, el motivo que queda en la captura.
     * Pura, para probarla sin base (`prueba_ot_piloto.php`).
     */
    public static function errorDeSerie(string $serie, int $n, string $modo): ?string
    {
        $dePrueba = $n >= self::SERIE_PRUEBA;
        if ($modo === 'PRODUCCION' && $dePrueba) {
            return "la serie $serie va en el $n, que es de la numeración de pruebas (" . self::SERIE_PRUEBA
                 . ' en adelante): hay que cargarle el contador de producción antes de emitir';
        }
        if ($modo !== 'PRODUCCION' && !$dePrueba) {
            return "la serie $serie va en el $n en el sitio de pruebas: un número por debajo de "
                 . self::SERIE_PRUEBA . ' se confundiría con una OT INDUSTEC de producción';
        }
        return null;
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
        if ($c === null) { $r['error'] = 'la OT INDUSTEC no existe'; return $r; }
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
                    /* Un solo instante para la emisión (28-sep-2026): el que
                       imprime el PDF («Documento generado automáticamente el …»)
                       es el que se graba en emitida_en. Antes el PDF tomaba
                       date() antes de dompdf y la columna NOW() después: si el
                       render cruzaba un cambio de minuto, toda regeneración
                       —que imprime emitida_en— discrepaba del original en esa
                       línea. Si la orden ya estaba emitida (PDF perdido, E-10),
                       vale la emitida_en leída bajo el candado. */
                    $emitidaEn = ($fila['emitida_en'] ?? null) !== null ? (string) $fila['emitida_en'] : date('Y-m-d H:i:s');
                    $pdf = self::pdf(['emitida_en' => $emitidaEn] + $c, $orden, $id);
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
                                     SET emitida_en = COALESCE(emitida_en, ?),
                                         pdf_sha256 = COALESCE(pdf_sha256, ?),
                                         pdf_sha256_regen = ?,
                                         estado = 'EMITIDA', emision_error = NULL
                                   WHERE captura_id = ?",
                                 [$emitidaEn, $huella, $regen && ($fila['pdf_sha256'] ?? null) !== $huella ? $huella : null, $capturaId]);
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
                // T2.28.6 (obs. 4): la placa se pudo leer, o no se pudo -el
                // PDF lo dice con esas palabras en vez de dejar los tres
                // campos en blanco, que I-7 leería como "no se preguntó".
                'sin_placa'     => !empty($eq['sin_placa']),
                'estado'        => $eq['estado'] ?? null,
                'obs'           => $eq['obs'] ?? null,
            ];
        }
        // T2.28.7 (obs. 1): las fotos con `equipo_n`/`momento` (formulario_v >= 2)
        // se agrupan por equipo y momento para la plantilla; las que no lo
        // traen (NULL = de antes de la 015, o de una app vieja en caché) van
        // al bloque de siempre en `$fotos`, exactamente como imprimía antes.
        $fotos = [];
        $fotosPorEquipo = [];
        foreach (Db::todos(
            "SELECT ruta, equipo_n, momento FROM ot_fotos WHERE envio_uuid = ?
              ORDER BY equipo_n, FIELD(momento,'ANTES','DESPUES','REPUESTO'), orden_n, foto_id",
            [(string) $c['envio_uuid']]) as $f) {
            $uri = self::fotoReducidaParaPdf((string) $f['ruta']);
            if ($uri === null) { continue; }
            if ($f['equipo_n'] === null || $f['momento'] === null) {
                $fotos[] = $uri;
                continue;
            }
            $fotosPorEquipo[(int) $f['equipo_n']][(string) $f['momento']][] = $uri;
        }
        $logo = __DIR__ . '/logo-industec.png';
        $d = [
            'id'              => $id,
            /* Decisión de Andrés del 28-sep-2026: «de ahora en adelante ninguna
               orden salga con esa franja». Ningún PDF lleva ya la franja
               «DOCUMENTO DE PRUEBA», tampoco en modo PRUEBA. El modo sigue
               rigiendo lo demás (serie 9000, correo RETENIDO) y lo que avisa
               que la OT es del piloto está en la app y en las pantallas, no en
               el documento. La clave se queda porque plantilla_ot.php la lee y
               no se toca (contrato externo). */
            'prueba'          => false,
            'logo'            => is_file($logo) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logo)) : '',
            'aviso'           => (string) ($c['aviso'] ?? ''),
            'modulo'          => (string) ($c['modulo'] ?? ''),
            'dia'             => $orden['dia_intervencion'] ?? null,
            // La corregida por la administración, si la hay (28-sep-2026).
            'fecha'           => self::fechaAtencion($orden),
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
            'fotos_por_equipo' => $fotosPorEquipo,
            'fotos_esperadas' => count((array) ($orden['fotos'] ?? [])),
            'firma'           => self::firmaValida((string) ($orden['firma_png'] ?? '')),
            // La de la emisión original, si ya se emitió: regenerar no la mueve.
            'emitida'         => self::fechaEmision(isset($c['emitida_en']) ? (string) $c['emitida_en'] : null),
        ];
        ob_start();
        include __DIR__ . '/plantilla_ot.php';
        return (string) ob_get_clean();
    }

    /** La clave de la carga donde la administración deja una corrección. */
    public const CORRECCION = 'correccion_admin';

    /**
     * La fecha de atención que vale: la que corrigió la administración, si la
     * corrigió; si no, la que registró el técnico.
     *
     * El 28-sep-2026 Andrés pidió corregir la fecha de 6 OT del piloto que no
     * coincidía con el inicio y el fin de la visita (el técnico la emitió días
     * después y dejó la fecha del día). La corrección no pisa el registro: va en
     * `carga.correccion_admin` {fecha_atencion, antes, por, en, motivo}, y
     * `carga.fecha_atencion` queda como la escribió el técnico. Esta función es
     * la ÚNICA que decide cuál vale: la usan `html()` —así cualquier
     * regeneración del PDF la conserva— y `archivo_indexar_cli.php`, que rehace
     * `ot_archivo.fecha_atencion` cada noche y, sin esto, devolvería la vieja.
     *
     * Una corrección que no sea una fecha AAAA-MM-DD válida no cuenta: se usa la
     * del técnico, no se inventa otra.
     */
    public static function fechaAtencion(array $orden): string
    {
        $corr = $orden[self::CORRECCION] ?? null;
        $f = is_array($corr) ? ($corr['fecha_atencion'] ?? null) : null;
        if (is_string($f) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $f, $m)
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $f;
        }
        return (string) ($orden['fecha_atencion'] ?? '');
    }

    /**
     * «Documento generado automáticamente el …»: la hora de la emisión original
     * si la orden ya se emitió (`ot_capturadas.emitida_en`). En la primera
     * emisión, `emitir()` fija un solo instante y lo pasa aquí y a `emitida_en`
     * (así el original y toda regeneración dicen lo mismo); la hora de ahora
     * queda solo para quien llame sin fila (vistas previas, pruebas). Hasta el
     * 28-sep-2026 era siempre la de ahora, así que regenerar un PDF perdido
     * (E-10) le cambiaba la fecha a un documento que ya se había entregado.
     * Mismo formato que antes: AAAA-MM-DD HH:MM.
     */
    public static function fechaEmision(?string $emitidaEn): string
    {
        if ($emitidaEn !== null
            && preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/', trim($emitidaEn), $m)) {
            return $m[1] . ' ' . $m[2];
        }
        return date('Y-m-d H:i');
    }

    /**
     * Una foto del disco, reducida a 900 px por el lado mayor y JPEG calidad
     * 70 (T2.28.7, obs. 1), lista como data URI para el PDF.
     *
     * `foto.php` ya la había dejado en máximo 1.200 px al subirla -- esta es
     * una SEGUNDA reducción, solo para el PDF. Con una preventiva de 7 equipos
     * y hasta 40 fotos, incrustar la copia de 1.200 px hacía que el PDF
     * pesara varios MB y que dompdf, que arma todo el documento en memoria,
     * se quedara sin ella a mitad de camino (criterio de T2.28.7: 35 fotos en
     * menos de 30 s y sin error en el log). `null` si el archivo no está o no
     * se pudo decodificar -- se salta, nunca revienta la emisión entera por
     * una foto suelta.
     */
    private static function fotoReducidaParaPdf(string $ruta): ?string
    {
        $p = self::dirFotos() . '/' . $ruta;
        if (!is_file($p)) { return null; }
        $img = @imagecreatefromstring((string) file_get_contents($p));
        if ($img === false) { return null; }
        $w = imagesx($img);
        $h = imagesy($img);
        $lado = 900;
        $escala = min(1, $lado / max($w, $h));
        if ($escala < 1) {
            $nw = max(1, (int) round($w * $escala));
            $nh = max(1, (int) round($h * $escala));
            $chica = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($chica, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $img = $chica;
        }
        ob_start();
        imagejpeg($img, null, 70);
        $jpg = (string) ob_get_clean();
        imagedestroy($img);
        return 'data:image/jpeg;base64,' . base64_encode($jpg);
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
