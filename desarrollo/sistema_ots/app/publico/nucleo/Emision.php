<?php
declare(strict_types=1);
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Auth.php';       // la bitácora de la regeneración y del correo sin destino
require_once __DIR__ . '/Catalogo.php';
require_once __DIR__ . '/Destinatarios.php';   // T2.28.2: a quién va la cola y qué imprime el PDF
require_once __DIR__ . '/EnvioZonas.php';      // T2.29: el modo de cada zona y el contador del formulario viejo

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
 * DOS MODOS, POR ZONA (T2.29, 29-sep-2026). El modo lo decide `emision_zonas`
 * —un superadministrador lo cambia desde Correos— salvo que config.php traiga
 * 'emision_modo' (PRUEBA es el freno de emergencia de todo el sitio). Hasta el
 * 29-sep era uno solo para todo el sitio y siempre PRUEBA.
 *
 * En modo PRUEBA (el piloto):
 *   - las series arrancan en 9000 (`PRUEBA:{serie}`): una OT de prueba no puede
 *     llevar un número que exista o vaya a existir en producción;
 *   - el correo queda RETENIDO y no sale nunca. Producción manda cada orden al
 *     local, a Grupo KFC y al buzón de la administradora, que se lee de forma
 *     automática: un correo de prueba llegaría como una orden real.
 *
 * En modo PRODUCCION la serie sigue la numeración del formulario viejo (se
 * siembra al activar la zona, EnvioZonas::activar()) y el correo queda
 * PENDIENTE: lo manda Correo::despachar() en cuanto se le responde al técnico.
 * Aun en una zona activada, emiten como piloto las cuentas de prueba y las OT
 * llenadas antes de activar (EnvioZonas::modoDeCaptura()), y una OT con número
 * del piloto nunca sale (encolar(), Correo::despachar()).
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
    /* La serie de las cuentas de prueba del arnés (modo ENSAYO, T2.29): por
       debajo de 9000 para que se comporten como producción —esDePrueba() no las
       marca—, y lejos de la real: la más adelantada (correctivo CNLJ) iba en
       2.645 el 29-sep-2026, a unas 220 por mes. Llegaría a 8000 hacia fines de
       2028; antes de eso hay que mover este rango (queda anotado en el plan). */
    public const SERIE_ENSAYO = 8000;
    /* OTRA existe en el esquema y en cuatro locales reales (Pollo Gus fuera de
       las tres zonas): sin ella esas órdenes nunca recibían número (E-01, D7). Su
       serie es propia (CORRECTIVO:OTRA) y el sufijo del nombre, -OTRA. Qué
       contador lleva producción para ellos lo confirma Andrés en el corte. */
    private const ZONAS = ['UIO', 'LARB', 'CNLJ', 'OTRA'];

    private static ?array $cat = null;
    /** @var array<string,true>|null OT con número del piloto liberadas a Grupo KFC (migración 024), por id en mayúsculas */
    private static ?array $liberadas = null;

    /**
     * El modo que rige para una zona (o, sin zona, para el sitio: PRODUCCION
     * solo si las tres zonas del contrato lo están). La regla completa está en
     * EnvioZonas::resolverModo(), que es pura y se prueba sin base.
     */
    public static function modo(?string $zona = null): string
    {
        return EnvioZonas::resolverModo(self::modoConfig(), EnvioZonas::estado(), $zona);
    }

    /**
     * Lo que fija config.php, si lo fija: 'PRUEBA', 'PRODUCCION' o null. null
     * es lo normal desde el 29-sep: manda `emision_zonas`.
     */
    public static function modoConfig(): ?string
    {
        $v = Db::config()['emision_modo'] ?? null;
        return in_array($v, ['PRUEBA', 'PRODUCCION'], true) ? $v : null;
    }

    /**
     * ¿Es este el sitio de pruebas, con cuentas y casos sintéticos del arnés?
     * Sí mientras config.php no lo declare sitio de producción definitivo
     * (T2.16). Que una zona tenga el envío real activo no lo cambia: las
     * cuentas «_prueba» siguen existiendo y siguen emitiendo como piloto.
     */
    public static function sitioDePruebas(): bool
    {
        return self::modoConfig() !== 'PRODUCCION';
    }

    /**
     * El modo con que se emite ESTA OT INDUSTEC: el de su zona, salvo las
     * cuentas de prueba y lo que el técnico llenó antes de activar la zona
     * (EnvioZonas::modoDeCaptura(), con el porqué).
     */
    public static function modoCaptura(array $c): string
    {
        $zona = (string) ($c['zona'] ?? '');
        $modoZona = self::modo($zona !== '' ? $zona : null);
        if ($zona === '' && self::modoConfig() === null) {
            $modoZona = 'PRUEBA';   // sin zona no hay serie ni destinatarios: nunca sale
        }
        $usuario = null;
        if (!empty($c['usuario_id'])) {
            $usuario = Db::uno('SELECT usuario FROM usuarios WHERE usuario_id = ?', [(int) $c['usuario_id']])['usuario'] ?? null;
        }
        $desde = self::modoConfig() === null ? (EnvioZonas::estado()[$zona]['desde'] ?? null) : null;
        $visto = (json_decode((string) ($c['carga'] ?? ''), true) ?: [])['modo_visto'] ?? null;
        return EnvioZonas::modoDeCaptura($modoZona, $desde, isset($c['capturada_en']) ? (string) $c['capturada_en'] : null,
                                         $usuario !== null ? (string) $usuario : null,
                                         is_string($visto) ? $visto : null);
    }

    /** La serie donde numera el piloto: aparte de la real desde la 023. */
    public static function seriePrueba(string $serie): string
    {
        return 'PRUEBA:' . $serie;
    }

    /** La serie donde numeran las cuentas de prueba del arnés (modo ENSAYO). */
    public static function serieEnsayo(string $serie): string
    {
        return 'ENSAYO:' . $serie;
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
        $limpio = strtoupper(trim((string) $id, ' '));
        if (!preg_match('/^OT-0*(\d+)-/', $limpio, $m)) {
            return false;
        }
        $deLaSerie = strlen($m[1]) > 18 || (int) $m[1] >= self::SERIE_PRUEBA;
        // Una del piloto que se LIBERÓ a Grupo KFC por decisión expresa dejó de
        // serlo (migración 024). Solo se consulta si el número es de la serie.
        return $deLaSerie && !isset(self::liberadas()[$limpio]);
    }

    /**
     * Las OT con número del piloto que se liberaron a Grupo KFC (T2.29.8,
     * migración 024): id_industec en mayúsculas → true.
     *
     * La excepción existe porque el criterio «es del piloto» es el NÚMERO y se
     * repite en la web y en el robot sin consultar nada; pero el 2026-10-01
     * Andrés pidió enviar diez OT de la serie 9000 que nunca salieron (trabajos
     * reales, sin informe del formulario viejo). Una OT-9125 que SÍ llegó a KFC
     * no puede seguir figurando «no enviada»: no atendería el caso, no contaría
     * en los reportes y el despachador la devolvería a RETENIDO.
     *
     * Una sola consulta por proceso, y solo si hay una base a la que llegar
     * (`nucleo/config.php` o INDUSTEC_CONFIG): las pruebas locales llaman a
     * `esDePrueba()` sin base y tienen que seguir viendo el criterio puro. Sin
     * la 024, sin base o con la base caída no hay ninguna liberada: manda el
     * número, que es lo de antes.
     *
     * @return array<string,true>
     */
    public static function liberadas(): array
    {
        if (self::$liberadas !== null) { return self::$liberadas; }
        self::$liberadas = [];
        if (!is_file(Db::rutaConfig())) { return self::$liberadas; }
        try {
            foreach (Db::todos('SELECT id_industec FROM ot_capturadas
                                 WHERE liberada_en IS NOT NULL AND id_industec IS NOT NULL') as $f) {
                $id = strtoupper(trim((string) $f['id_industec'], ' '));
                // Solo nombres canónicos: este texto termina dentro de un SQL (sqlEsDePrueba).
                if (preg_match('/^OT-[0-9A-Z-]{3,80}$/', $id)) { self::$liberadas[$id] = true; }
            }
        } catch (Throwable $e) {
            self::$liberadas = [];            // sin la 024: ninguna liberada
        }
        return self::$liberadas;
    }

    /** Para quien libera una OT y necesita que el resto de su proceso ya la vea liberada. */
    public static function olvidarLiberadas(): void
    {
        self::$liberadas = null;
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
        $criterio = "(UPPER(TRIM($columna)) REGEXP '^OT-0*(9[0-9]{3}|[1-9][0-9]{4,})-')";
        // Las liberadas (migración 024) dejaron de ser del piloto: mismo criterio
        // que `esDePrueba()`. Los nombres ya vienen validados por `liberadas()`.
        $liberadas = array_keys(self::liberadas());
        if ($liberadas === []) {
            return $criterio;
        }
        return "($criterio AND UPPER(TRIM($columna)) NOT IN ('" . implode("','", $liberadas) . "'))";
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
    public static function reservar(string $serie, ?string $modo = null): int
    {
        $modo = $modo ?? self::modo(explode(':', $serie)[1] ?? null);
        if ($modo === 'ENSAYO') {
            // Las cuentas de prueba: su propia serie, desde SERIE_ENSAYO.
            $clave = self::serieEnsayo($serie);
            Db::ejecutar('INSERT INTO correlativos (serie, ultimo, nota) VALUES (?, ?, ?)
                          ON DUPLICATE KEY UPDATE serie = serie',
                         [$clave, self::SERIE_ENSAYO, 'cuentas de prueba del arnés: serie desde ' . self::SERIE_ENSAYO . ', el correo no sale']);
            if (Db::ejecutar('UPDATE correlativos SET ultimo = LAST_INSERT_ID(ultimo + 1) WHERE serie = ?', [$clave]) !== 1) {
                throw new RuntimeException("la serie $clave no tiene contador");
            }
            $n = (int) Db::uno('SELECT LAST_INSERT_ID() AS n')['n'];
        } elseif ($modo !== 'PRODUCCION') {
            /* El piloto numera en su propia serie desde la 023 (T2.29): la real
               (`CORRECTIVO:UIO`) se siembra con el contador del formulario
               viejo al activar la zona, y las cuentas de prueba siguen
               emitiendo aunque la zona esté activada. Si la serie del piloto no
               existe todavía, arranca donde iba la real si esa estaba en la
               serie 9000 (sitio sin la 023): nunca repite un 9xxx ya emitido. */
            $clave = self::seriePrueba($serie);
            $real  = Db::uno('SELECT ultimo FROM correlativos WHERE serie = ?', [$serie]);
            $desde = max(self::SERIE_PRUEBA, $real !== null && (int) $real['ultimo'] >= self::SERIE_PRUEBA ? (int) $real['ultimo'] : 0);
            Db::ejecutar('INSERT INTO correlativos (serie, ultimo, nota) VALUES (?, ?, ?)
                          ON DUPLICATE KEY UPDATE serie = serie',
                         [$clave, $desde,
                          'piloto: serie desde ' . self::SERIE_PRUEBA . ', no se cruza con la de producción']);
            if (Db::ejecutar('UPDATE correlativos SET ultimo = LAST_INSERT_ID(ultimo + 1) WHERE serie = ?', [$clave]) !== 1) {
                throw new RuntimeException("la serie $clave no tiene contador");
            }
            $n = (int) Db::uno('SELECT LAST_INSERT_ID() AS n')['n'];
        } else {
            $n = self::reservarReal($serie);
        }
        // Se lanza DENTRO de la transacción de emitir(): el rollBack devuelve
        // el contador y la OT queda FALLIDA con este motivo, para reintentar.
        $error = self::errorDeSerie($serie, $n, $modo);
        if ($error !== null) { throw new RuntimeException($error); }
        return $n;
    }

    /**
     * El siguiente número REAL de la serie (modo PRODUCCION), sin repetir uno
     * que ya exista (T2.29, D-8):
     *
     *   1. Si el formulario viejo se volvió a usar y su contador pasó al de la
     *      app, la serie salta por encima. Solo se LEE el sistema viejo.
     *   2. Si el número ya figura en el Archivo para esa zona y tipo de trabajo,
     *      se salta. Medido el 2026-09-29: en UIO hay cuatro OT de julio con
     *      números por encima del contador viejo (2016, 2061, 2062 y 2064), y
     *      la numeración las alcanza en unas semanas.
     *
     * Cada salto queda en la bitácora. Todo ocurre con la fila de la serie
     * bloqueada, dentro de la transacción de emitir().
     */
    private static function reservarReal(string $serie): int
    {
        [$modulo, $zona] = array_pad(explode(':', $serie, 2), 2, '');
        $viejo = EnvioZonas::contadorViejo($serie);
        if ($viejo !== null) {
            $fila = Db::uno('SELECT ultimo FROM correlativos WHERE serie = ? FOR UPDATE', [$serie]);
            if ($fila !== null && (int) $fila['ultimo'] < $viejo && (int) $fila['ultimo'] < self::SERIE_PRUEBA) {
                Db::ejecutar('UPDATE correlativos SET ultimo = ? WHERE serie = ?', [$viejo, $serie]);
                Auth::bitacora('NUMERO_SOBRE_VIEJO', 'correlativo', $serie,
                               "el formulario viejo va en $viejo y la app iba en " . $fila['ultimo'] . ': la app sigue por encima',
                               (string) $fila['ultimo'], (string) $viejo, ['viejo' => $viejo], false);
            }
        }
        for ($i = 0; $i < 200; $i++) {
            if (Db::ejecutar('UPDATE correlativos SET ultimo = LAST_INSERT_ID(ultimo + 1) WHERE serie = ?', [$serie]) !== 1) {
                throw new RuntimeException("la serie $serie no tiene contador: hay que activar la zona desde Correos");
            }
            $n = (int) Db::uno('SELECT LAST_INSERT_ID() AS n')['n'];
            if ($n >= self::SERIE_PRUEBA || !self::numeroUsado($modulo, $zona, $n)) {
                return $n;   // errorDeSerie() decide sobre un 9xxx
            }
            Auth::bitacora('NUMERO_SALTADO', 'correlativo', $serie, "el número $n ya existe en el Archivo: se salta",
                           null, (string) $n, ['numero' => $n], false);
        }
        throw new RuntimeException("la serie $serie tiene 200 números seguidos ya usados: revisar el Archivo antes de emitir");
    }

    /**
     * ¿Ese número ya lo lleva alguna OT de esa zona y tipo de trabajo? En el
     * Archivo (histórico, correo y app) o en lo emitido por la app. Las filas
     * del correo no traen el módulo (NULL): cuentan para los dos, por
     * precaución — saltar un número de más no daña; repetirlo, sí.
     */
    public static function numeroUsado(string $modulo, string $zona, int $n): bool
    {
        $pref = array_unique(['OT-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT) . '-', 'OT-' . $n . '-']);
        foreach ($pref as $p) {
            $like = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $p) . '%';
            try {
                if (Db::uno('SELECT 1 FROM ot_archivo WHERE id_industec LIKE ? AND zona = ? AND (modulo = ? OR modulo IS NULL) LIMIT 1',
                            [$like, $zona, $modulo]) !== null) {
                    return true;
                }
            } catch (Throwable $e) {
                // sin la 009 no hay Archivo: queda lo emitido por la app
            }
            if (Db::uno('SELECT 1 FROM ot_capturadas WHERE id_industec LIKE ? AND zona = ? AND modulo = ? LIMIT 1',
                        [$like, $zona, $modulo]) !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * El mayor número REAL que ya emitió la app en esa zona y tipo: por debajo
     * de la serie de ensayo (8000) y de nadie con cuenta de prueba. Revisión
     * del 29-sep (T2.29): la primera versión solo quitaba la serie del piloto
     * (9000) y contaba las OT de ensayo del arnés (8001-8999) como reales;
     * activar una zona después de correr una batería habría sembrado la serie
     * real en 8001 y la primera OT a Grupo KFC habría salido como OT-8002.
     */
    public static function mayorNumeroReal(string $modulo, string $zona): int
    {
        $f = Db::uno("SELECT MAX(CAST(SUBSTRING_INDEX(SUBSTRING(c.id_industec, 4), '-', 1) AS UNSIGNED)) n
                        FROM ot_capturadas c
                        JOIN usuarios u ON u.usuario_id = c.usuario_id
                       WHERE c.zona = ? AND c.modulo = ? AND c.id_industec REGEXP '^OT-[0-9]+-'
                         AND u.usuario NOT LIKE '%\\_prueba%'
                         AND CAST(SUBSTRING_INDEX(SUBSTRING(c.id_industec, 4), '-', 1) AS UNSIGNED) < " . self::SERIE_ENSAYO,
                     [$zona, $modulo]);
        return (int) ($f['n'] ?? 0);
    }

    /**
     * El modo que corresponde a un número ya dado: el número manda sobre el
     * estado actual de la zona. Una OT real reemitida después de volver la
     * zona al piloto sigue siendo real (su correo sale y la orden se atiende);
     * una del piloto, del piloto. Revisión del 29-sep (T2.29).
     */
    public static function modoDeNumero(string $id): string
    {
        if (self::esDePrueba($id)) { return 'PRUEBA'; }
        if (preg_match('/^OT-0*(\d+)-/', strtoupper(trim($id, ' ')), $m)
            && (int) $m[1] > self::SERIE_ENSAYO && (int) $m[1] < self::SERIE_PRUEBA) {
            return 'ENSAYO';
        }
        return 'PRODUCCION';
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
        if ($modo === 'ENSAYO') {
            return ($n > self::SERIE_ENSAYO && $n < self::SERIE_PRUEBA) ? null
                : "la serie de ensayo de $serie va en el $n, fuera del rango " . self::SERIE_ENSAYO . '-' . (self::SERIE_PRUEBA - 1)
                  . ': hay que mover el rango de las cuentas de prueba';
        }
        $dePrueba = $n >= self::SERIE_PRUEBA;
        if ($modo === 'PRODUCCION' && !$dePrueba && $n >= self::SERIE_ENSAYO) {
            // La serie real nunca entra al rango de las cuentas de prueba: se
            // confundiría con sus OT (que se comportan como reales). Llegaría
            // por el uso normal hacia 2028: antes hay que mover SERIE_ENSAYO.
            return "la serie $serie llegó al $n, que es del rango de las cuentas de prueba (" . self::SERIE_ENSAYO
                 . '-' . (self::SERIE_PRUEBA - 1) . '): hay que mover ese rango antes de seguir emitiendo';
        }
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
        $r = ['id_industec' => null, 'pdf' => false, 'correo' => null, 'error' => null, 'modo' => 'PRUEBA'];
        $c = Db::uno('SELECT * FROM ot_capturadas WHERE captura_id = ?', [$capturaId]);
        if ($c === null) { $r['error'] = 'la OT INDUSTEC no existe'; return $r; }
        // Un solo modo para toda la emisión de esta OT (T2.29): el número, la
        // cola y lo que se le dice al técnico tienen que decir lo mismo.
        $modo = self::modoCaptura($c);
        $r['modo'] = $modo;
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
                    $id = self::idIndustec(self::reservar($modulo . ':' . $zona, $modo), $local,
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
        // Con número ya dado, el modo lo dice el número, no el estado actual de
        // la zona (D-7 y revisión del 29-sep): una OT del piloto reemitida
        // después de activar sigue siendo del piloto (su correo no sale), y una
        // real reemitida después de volver la zona al piloto sigue siendo real.
        $modo = self::modoDeNumero((string) $id);
        $r['modo'] = $modo;

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

        // 2b. El Archivo la ve en el momento (T2.29.10), no a las 03:50 del día siguiente.
        //     Nunca rompe la emisión: si falla queda en el log y el índice de la noche la pone.
        try {
            self::indexarEnArchivo($c, $orden, (string) $id, $ruta);
        } catch (Throwable $e) {
            error_log('Emision, Archivo (' . $id . '): ' . $e->getMessage());
        }

        // 3. El correo, a la cola. En el piloto queda retenido; en una zona
        //    activada queda PENDIENTE y lo manda Correo::despachar().
        try {
            $r['correo'] = self::encolar($c, $orden, $id, $modo);
        } catch (Throwable $e) {
            return self::falla($capturaId, $r, 'no se pudo encolar el correo: ' . $e->getMessage());
        }
        return $r;
    }

    /**
     * El upsert de `ot_archivo`. Es UNO solo, y lo usan el índice nocturno
     * (`archivo_indexar_cli.php`) y el momento de emitir (`indexarEnArchivo()`):
     * dos copias de este SQL se separan. Lo que llega vacío no pisa lo que ya
     * estaba (COALESCE), y APP manda sobre CORREO y sobre HISTORICO.
     *
     * @param array<string,mixed> $r id_industec, zona, origen y lo demás opcional
     */
    public static function archivoGuardar(array $r): void
    {
        Db::ejecutar(
            'INSERT INTO ot_archivo (id_industec, zona, local_codigo, local_nombre, cadena, aviso, modulo, dia,
                                     fecha_atencion, tecnico, origen, en_servidor, ruta, bytes, sha256, fuente_ruta)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                zona           = VALUES(zona),
                local_codigo   = COALESCE(VALUES(local_codigo), local_codigo),
                local_nombre   = COALESCE(VALUES(local_nombre), local_nombre),
                cadena         = COALESCE(VALUES(cadena), cadena),
                aviso          = COALESCE(VALUES(aviso), aviso),
                modulo         = COALESCE(VALUES(modulo), modulo),
                dia            = COALESCE(VALUES(dia), dia),
                fecha_atencion = COALESCE(VALUES(fecha_atencion), fecha_atencion),
                tecnico        = COALESCE(VALUES(tecnico), tecnico),
                -- APP manda sobre CORREO y sobre HISTORICO: es la fuente más rica.
                origen         = IF(origen = "APP", origen, VALUES(origen)),
                en_servidor    = GREATEST(en_servidor, VALUES(en_servidor)),
                ruta           = COALESCE(VALUES(ruta), ruta),
                bytes          = COALESCE(VALUES(bytes), bytes),
                sha256         = COALESCE(VALUES(sha256), sha256),
                fuente_ruta    = COALESCE(VALUES(fuente_ruta), fuente_ruta)',
            [$r['id_industec'], $r['zona'], $r['local_codigo'] ?? null, $r['local_nombre'] ?? null,
             $r['cadena'] ?? null, $r['aviso'] ?? null, $r['modulo'] ?? null, $r['dia'] ?? null,
             $r['fecha_atencion'] ?? null, $r['tecnico'] ?? null, $r['origen'], (int) ($r['en_servidor'] ?? 0),
             $r['ruta'] ?? null, $r['bytes'] ?? null, $r['sha256'] ?? null, $r['fuente_ruta'] ?? null]
        );
    }

    /**
     * La OT aparece en el Archivo en el momento de emitirla (T2.29.10, pedido
     * de Andrés del 2026-10-01). Hasta entonces `ot_archivo` lo llenaba solo el
     * índice de las 03:50: la OT-1952 de Anthony se emitió el 30-sep a las 13:11
     * y Andrés la buscó en el Archivo cinco veces, sin verla, con la OT ya
     * emitida y enviada. Los mismos campos que arma el índice nocturno para una
     * OT de la app (`archivo_indexar_cli.php`, fuentes a y b).
     */
    private static function indexarEnArchivo(array $c, array $orden, string $id, string $ruta): void
    {
        if (!is_file($ruta)) { return; }
        $loc = strtoupper((string) ($c['local_codigo'] ?? ''));
        $local = $loc !== '' ? self::local($loc) : [];
        $fecha = self::fechaAtencion($orden);
        $dia = (string) ($orden['dia'] ?? '');
        $dia = is_numeric($dia) ? (int) $dia
             : ((string) ($c['modulo'] ?? '') === 'PREVENTIVO' && (int) ($orden['dia_intervencion'] ?? 0) > 0 ? (int) $orden['dia_intervencion'] : null);
        $tecnico = Db::uno('SELECT nombre FROM usuarios WHERE usuario_id = ?', [(int) ($c['usuario_id'] ?? 0)]);
        self::archivoGuardar([
            'id_industec'    => strtoupper($id),
            'zona'           => (string) $c['zona'],
            'local_codigo'   => $loc !== '' ? $loc : null,
            'local_nombre'   => $local['nombre'] ?? null,
            'cadena'         => ((string) ($c['cadena'] ?? '')) !== '' ? (string) $c['cadena'] : ($local['cadena'] ?? null),
            'aviso'          => ((string) ($c['aviso'] ?? '')) !== '' ? (string) $c['aviso'] : null,
            'modulo'         => ((string) ($c['modulo'] ?? '')) !== '' ? (string) $c['modulo'] : null,
            'dia'            => $dia,
            'fecha_atencion' => preg_match('/^\d{4}-\d{2}-\d{2}/', $fecha, $m) ? $m[0] : date('Y-m-d'),
            'tecnico'        => $tecnico['nombre'] ?? null,
            'origen'         => 'APP',
            'en_servidor'    => 1,
            'ruta'           => 'ordenes_pdf/' . basename($ruta),
            'bytes'          => (int) filesize($ruta),
            'sha256'         => hash_file('sha256', $ruta) ?: null,
        ]);
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
    private static function encolar(array $c, array $orden, string $id, string $modo): string
    {
        $local  = self::local((string) ($c['local_codigo'] ?? ''));
        $zona   = (string) ($c['zona'] ?? '');
        $cadena = (string) ($local['cadena'] ?? ($c['cadena'] ?? ''));
        $codLocal = (string) ($c['local_codigo'] ?? '');
        $dest = Destinatarios::resolver('ORDEN', $zona, $codLocal !== '' ? $codLocal : null,
                                        $cadena !== '' ? $cadena : null, $orden['correo_local'] ?? null);
        $para = $dest['para'];
        $cc   = $dest['cc'];
        // Con número del piloto, nunca sale, se emita en el modo que se emita (D-7).
        $prueba = $modo !== 'PRODUCCION' || self::esDePrueba($id);
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
        //
        // `estado` va AL FINAL a propósito (T2.29): MariaDB evalúa las
        // asignaciones de izquierda a derecha y las siguientes ya ven el valor
        // nuevo. Con `estado` en el tercer lugar, un FALLIDO que volvía a
        // PENDIENTE dejaba `intentos`, `proximo_intento_en` y `motivo` como
        // estaban (la condición ya leía PENDIENTE), y el primer error del
        // reintento lo daba por agotado en el acto.
        Db::ejecutar("INSERT INTO email_queue (captura_id, id_industec, tipo, para, cc, asunto, cuerpo, adjunto, estado, motivo)
                      VALUES (?, ?, 'EMISION', ?, ?, ?, ?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE
                        para     = IF(estado = 'FALLIDO' AND VALUES(estado) = 'PENDIENTE', VALUES(para), para),
                        cc       = IF(estado = 'FALLIDO' AND VALUES(estado) = 'PENDIENTE', VALUES(cc), cc),
                        intentos = IF(estado = 'FALLIDO' AND VALUES(estado) = 'PENDIENTE', 0, intentos),
                        proximo_intento_en = IF(estado = 'FALLIDO' AND VALUES(estado) = 'PENDIENTE', NULL, proximo_intento_en),
                        motivo   = IF(estado = 'FALLIDO' AND VALUES(estado) = 'PENDIENTE', NULL, motivo),
                        estado   = IF(estado = 'FALLIDO' AND VALUES(estado) = 'PENDIENTE', 'PENDIENTE', estado)",
                     [(int) $c['captura_id'], $id, json_encode($para, JSON_UNESCAPED_UNICODE),
                      $cc !== [] ? json_encode($cc, JSON_UNESCAPED_UNICODE) : null,
                      'ORDEN DE TRABAJO INDUSTEC - ' . $id, $cuerpo, $id . '.pdf',
                      $prueba ? 'RETENIDO' : ($sinDestino ? 'FALLIDO' : 'PENDIENTE'),
                      $prueba ? ($modo === 'ENSAYO'
                                    ? 'cuenta de prueba: el correo no sale (iría al local, a Grupo KFC y a las copias configuradas)'
                                    : 'OT del piloto: el correo no sale (iría al local, a Grupo KFC y a las copias configuradas)')
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

    /**
     * Un PDF cualquiera desde HTML, con las mismas opciones que el de la orden
     * (nada remoto). Lo usa el correo de prueba de la cuenta de envío (T2.29):
     * así la prueba pasa por dompdf y por el adjunto, como una OT de verdad.
     */
    public static function pdfSimple(string $html): string
    {
        self::cargarDompdf();
        $opt = new \Dompdf\Options();
        $opt->set('isRemoteEnabled', false);
        $opt->set('defaultFont', 'DejaVu Sans');
        $d = new \Dompdf\Dompdf($opt);
        $d->loadHtml($html, 'UTF-8');
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
                // El del maestro manda; si el equipo es nuevo y no está ahí, el
                // que escribió el técnico. Hasta el 2026-10-01 el PDF ignoraba lo
                // que el técnico tecleaba y decía «sin dato en el maestro».
                'codigo_activo' => ($cat['codigo_activo'] ?? null) ?: (trim((string) ($eq['codigo_activo'] ?? '')) ?: null),
                'ubicacion'     => $cat['ubicacion_tecnica'] ?? null,
                // El área que el técnico escribe al crear un equipo nuevo.
                'area'          => trim((string) ($eq['area'] ?? '')) ?: null,
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
            'repuestos'       => !empty($orden['uso_repuesto'])
                                   ? implode("\n", self::lineasDeRepuestos((string) ($orden['repuestos'] ?? '')))
                                   : 'No se usaron repuestos.',
            // 2026-10-01: lo que el técnico escribió en «¿Quedó concluido el
            // trabajo?» -> «No»: el diagnóstico y los repuestos que HACEN FALTA.
            // El PDF nunca lo imprimió: la OT-1964 (primera con repuesto pedido)
            // salió a Grupo KFC diciendo «No se usaron repuestos» sin una palabra
            // del repuesto que el técnico había solicitado.
            'pendiente'       => self::pendienteParaPdf($orden),
            // El porqué de una OT sin aviso de SAP, que el formulario exige y el
            // PDF no mostraba.
            'motivo_sin_aviso' => !empty($orden['sin_aviso']) ? trim((string) ($orden['motivo_sin_aviso'] ?? '')) : '',
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

    /**
     * Los repuestos USADOS, uno por línea. El formulario los compone en un solo
     * texto («Termopila (Fm8101873); Tornillo x3 (T-10)») y así, pegados en una
     * línea, el PDF se leía como un párrafo. Se separan por el «; » con que los
     * une `compilarPartesTexto()` de app.js; una descripción que lleve «; »
     * adentro queda en dos líneas, que se lee igual. Un solo repuesto sale como
     * siempre.
     *
     * @return string[]
     */
    public static function lineasDeRepuestos(string $texto): array
    {
        $partes = preg_split('/;\s+|\r?\n/', trim($texto)) ?: [];
        return array_values(array_filter(array_map('trim', $partes), static fn($p) => $p !== ''));
    }

    /**
     * La solicitud de repuesto, tal como la escribió el técnico: lo que contesta
     * en «¿Quedó concluido el trabajo?» -> «No, el equipo no quedó operativo» (el
     * equipo, la falla, qué encontró, los repuestos que hacen falta y si el
     * equipo quedó deshabilitado). null si el trabajo quedó concluido.
     *
     * Si el técnico dijo que NO concluyó pero no escribió nada más, devuelve la
     * marca `sin_detalle`: el PDF lo dice en vez de callarlo (I-7).
     *
     * @return array{equipo:string,falla:string,diagnostico:string,deshabilitado:bool,
     *               partes:array<int,array{cantidad:int,descripcion:string,numero_parte:string,codigo:string}>,
     *               texto:string,sin_detalle:bool}|null
     */
    public static function pendienteParaPdf(array $orden): ?array
    {
        $p = is_array($orden['pendiente'] ?? null) ? $orden['pendiente'] : [];
        $noConcluida = array_key_exists('concluida', $orden) && $orden['concluida'] === false;
        $partes = [];
        foreach ((array) ($p['partes'] ?? []) as $pt) {
            if (!is_array($pt)) { continue; }
            $desc = trim((string) ($pt['descripcion'] ?? ''));
            if ($desc === '') { continue; }
            $partes[] = [
                'cantidad'     => max(1, (int) ($pt['cantidad'] ?? 1)),
                'descripcion'  => $desc,
                'numero_parte' => trim((string) ($pt['numero_parte'] ?? '')),
                'codigo'       => trim((string) ($pt['codigo'] ?? '')),
            ];
        }
        // Una app vieja en caché manda solo el texto ya compuesto (`parte`).
        $texto = $partes === [] ? trim((string) ($p['parte'] ?? '')) : '';
        $r = [
            'equipo'        => trim((string) ($p['equipo_desc'] ?? '')),
            'falla'         => self::tituloDeFalla($p['diagnostico_codigo'] ?? null),
            'diagnostico'   => trim((string) ($p['diagnostico'] ?? '')),
            'deshabilitado' => !empty($p['deshabilitado']),
            'partes'        => $partes,
            'texto'         => $texto,
        ];
        $hayAlgo = $r['equipo'] !== '' || $r['diagnostico'] !== '' || $partes !== [] || $texto !== '' || $r['deshabilitado'];
        if (!$hayAlgo && !$noConcluida) { return null; }
        $r['sin_detalle'] = !$hayAlgo;
        return $r;
    }

    /** El título de una falla conocida (`diagnosticos`, 009), o '' si no hay o la tabla no está. */
    private static function tituloDeFalla($codigo): string
    {
        $codigo = trim((string) $codigo);
        if ($codigo === '') { return ''; }
        try {
            $f = Db::uno('SELECT titulo FROM diagnosticos WHERE codigo = ?', [$codigo]);
        } catch (Throwable $e) {
            return '';
        }
        return trim((string) ($f['titulo'] ?? ''));
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
