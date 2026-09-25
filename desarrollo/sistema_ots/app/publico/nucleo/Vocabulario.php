<?php
declare(strict_types=1);

/**
 * Vocabulario.php — Las palabras con que el sistema nombra cada cosa, de una
 * sola fuente: `vocabulario.json`, en la raíz del sitio.
 *
 * POR QUE EXISTE
 * El 24-sep-2026 Isabel pidió que el panel «Por zona» hablara como ella habla
 * con SAP y con KFC (ÓRDENES ABIERTAS, ÓRDENES A ESPERA DE INFORME TÉCNICO,
 * EQUIPOS DESHABILITADOS, TOTAL DE ÓRDENES ABIERTAS), y Andrés exigió que esos
 * mismos términos se usen en TODO el sistema y para TODOS los roles. Hasta ese
 * día cada pantalla escribía sus estados a mano: el mismo ASIGNADO se leía
 * «asignado», «Pendientes», «En manos del equipo» y «N órdenes abiertas» según
 * dónde se mirara, y el inventario contó 1.224 textos de estado repartidos
 * entre PHP, JS, Python, PDF y correos.
 *
 * LA REGLA: el código conoce CLAVES de concepto (ESPERA_INFORME, ASIGNADA), no
 * textos. El texto vive en el JSON y se cambia ahí. Las tres puertas leen el
 * mismo archivo y son iguales para todos los roles:
 *   PHP     Vocabulario::t(), ::titulo(), ::ayuda(), ::corto(), ::deEstado()
 *   JS      UI.T(), UI.T.titulo(), UI.T.ayuda(), UI.T.corto(), UI.T.deEstado()  (ui.js)
 *   Python  termino(), titulo(), ayuda(), corto(), de_estado()                (agentes/scripts/comun.py)
 *
 * NUNCA INVENTA TEXTO (I-7). Una clave que no existe lanza una excepción con
 * la clave y la versión del diccionario. Devolver la clave cruda o un texto
 * «parecido» es justo lo que dejaba ASIGNADO en mayúsculas y con guion bajo
 * delante del cliente; un error ruidoso en la prueba se corrige el mismo día.
 *
 * LO QUE NO HACE: decidir qué se cuenta. La partición de la tarjeta por zona
 * (D-A, D-B) la hace una sola función, fuera de esta clase; aquí solo están
 * los nombres.
 *
 * DESPLIEGUE: esta clase y `vocabulario.json` tienen que viajar juntos en la
 * lista blanca de t2_10_desplegar.py. Las listas centrales de Ui, Pendientes y
 * Novedades no dependen de ellos (van generadas como constantes), pero toda
 * pantalla que llame a Vocabulario:: sí.
 */
final class VocabularioError extends RuntimeException
{
}

final class Vocabulario
{
    /** Dónde vive el mapa estado de la base -> concepto de cada dominio. El
     *  preventivo guarda el suyo dentro de `preventivo.estados` porque al lado
     *  lleva la leyenda del jefe técnico (EJECUTADO · PENDIENTE · ATRASADO). */
    private const DOMINIOS = [
        'caso'          => ['estados_caso'],
        'pendiente'     => ['estados_pendiente'],
        'novedad'       => ['estados_novedad'],
        'preventivo'    => ['preventivo', 'estados'],
        'via'           => ['via'],
        'decision_kfc'  => ['decision_kfc'],
        'estado_ot'     => ['estado_ot'],
        'estado_equipo' => ['estado_equipo'],
        'zona'          => ['zona'],
        'semaforo'      => ['semaforo'],
    ];

    /** @var array<string,mixed>|null */
    private static ?array $dic = null;

    /** La ruta del JSON. Vive en la raíz del sitio, no en `nucleo/`, porque el
     *  navegador también lo pide (ui.js) y `nucleo/` se sirve con 403. */
    public static function ruta(): string
    {
        return dirname(__DIR__) . '/vocabulario.json';
    }

    /**
     * El diccionario entero, leído una vez por petición.
     *
     * @return array<string,mixed>
     */
    public static function todo(): array
    {
        if (self::$dic !== null) {
            return self::$dic;
        }
        $ruta = self::ruta();
        $crudo = is_file($ruta) ? file_get_contents($ruta) : false;
        if ($crudo === false) {
            throw new VocabularioError("Vocabulario: no se pudo leer $ruta");
        }
        try {
            $d = json_decode($crudo, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new VocabularioError("Vocabulario: $ruta no es un JSON válido (" . $e->getMessage() . ')');
        }
        if (!is_array($d) || !is_array($d['conceptos'] ?? null) || !isset($d['version'])) {
            throw new VocabularioError("Vocabulario: a $ruta le falta «version» o «conceptos»");
        }
        return self::$dic = $d;
    }

    /** La versión del diccionario («2026-09-24.3»). Va en cada excepción para
     *  saber contra qué copia del JSON falló, que en el celular puede ser vieja. */
    public static function version(): string
    {
        return (string) self::todo()['version'];
    }

    /** @return array<string,mixed> */
    private static function concepto(string $clave): array
    {
        $c = self::todo()['conceptos'][$clave] ?? null;
        if (!is_array($c)) {
            throw new VocabularioError('Vocabulario: la clave «' . $clave
                . '» no existe en vocabulario.json (versión ' . self::version() . ')');
        }
        return $c;
    }

    /** Un campo de texto obligatorio de un concepto. Si el JSON lo trae vacío
     *  también es un error: un rótulo en blanco es un cero falso con otra cara. */
    private static function campo(string $clave, string $campo): string
    {
        $v = self::concepto($clave)[$campo] ?? null;
        if (!is_string($v) || $v === '') {
            throw new VocabularioError('Vocabulario: la clave «' . $clave . '» no tiene «' . $campo
                . '» en vocabulario.json (versión ' . self::version() . ')');
        }
        return $v;
    }

    /**
     * El término para una frase: singular si n = 1 y plural en cualquier otro
     * caso, también con n = 0 («0 órdenes»), que es como se dice en español.
     */
    public static function t(string $clave, int $n = 1): string
    {
        return self::campo($clave, $n === 1 ? 'termino' : 'plural');
    }

    /** El rótulo de botón, pestaña, columna o fila. */
    public static function titulo(string $clave): string
    {
        return self::campo($clave, 'titulo');
    }

    /** La frase que explica el concepto, la misma para todos los roles. */
    public static function ayuda(string $clave): string
    {
        return self::campo($clave, 'ayuda');
    }

    /** La forma corta para los espacios mínimos (barra del técnico, chip de
     *  zona). El JSON la trae solo donde hace falta; si no está, se usa el
     *  término, que es lo que manda la regla «corto» del diccionario. */
    public static function corto(string $clave): string
    {
        $c = self::concepto($clave)['corto'] ?? null;
        return is_string($c) && $c !== '' ? $c : self::t($clave);
    }

    /**
     * La clave del concepto que corresponde a un estado de la base.
     *
     * Se recorta y se pasa a MAYÚSCULAS antes de buscar: la base guarda
     * `ASIGNADO`, pero el semáforo llega en minúsculas («amarillo») y hay
     * columnas con espacios de sobra. Un estado que el diccionario no conoce
     * lanza la excepción: si aparece uno nuevo en el ENUM, se agrega al JSON.
     */
    public static function deEstado(string $estadoBd, string $dominio = 'caso'): string
    {
        $camino = self::DOMINIOS[$dominio] ?? null;
        if ($camino === null) {
            throw new VocabularioError('Vocabulario: el dominio «' . $dominio . '» no existe (hay: '
                . implode(', ', array_keys(self::DOMINIOS)) . '; versión ' . self::version() . ')');
        }
        $mapa = self::todo();
        foreach ($camino as $paso) {
            $mapa = is_array($mapa) ? ($mapa[$paso] ?? null) : null;
        }
        $e = strtoupper(trim($estadoBd));
        $clave = is_array($mapa) ? ($mapa[$e] ?? null) : null;
        if (!is_string($clave) || $clave === '') {
            throw new VocabularioError('Vocabulario: el estado «' . $e . '» del dominio «' . $dominio
                . '» no tiene concepto en vocabulario.json (versión ' . self::version() . ')');
        }
        return $clave;
    }

    /** Los dominios que entiende `deEstado()`, con su mapa. Lo usan el
     *  generador de las listas centrales y las pruebas.
     *
     *  @return array<string,array<string,string>> */
    public static function mapas(): array
    {
        $out = [];
        foreach (self::DOMINIOS as $dom => $camino) {
            $m = self::todo();
            foreach ($camino as $paso) { $m = $m[$paso] ?? []; }
            $out[$dom] = is_array($m) ? $m : [];
        }
        return $out;
    }

    /** Solo para las pruebas: vuelve a leer el archivo en la próxima llamada. */
    public static function olvidar(): void
    {
        self::$dic = null;
    }
}
