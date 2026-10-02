<?php
declare(strict_types=1);

require_once __DIR__ . '/Ui.php';   // la tabla de tildes de la búsqueda: una sola en todo el sistema

/**
 * T2.28.11 (obs. 9) — El enlace a Parts Town de un equipo o de un repuesto,
 * siguiendo la «Guía de uso Parts Town» de INDUSTEC: marca y modelo de la
 * placa → despiece del modelo → número de parte.
 *
 * GEMELA DE partstown.js: la misma entrada da el mismo enlace en el navegador
 * (el formulario del técnico) y en el servidor (Repuestos). prueba_contratos.mjs
 * ejecuta las dos y compara; si se cambia una, se cambia la otra.
 *
 * NO CONSULTA PARTS TOWN. No tiene API pública y su sitio lo protege
 * Cloudflare contra navegadores automáticos (medido el 2026-09-30: 403). Solo
 * se arma el enlace; nada de precios ni de raspar el sitio (prohibido en el
 * plan). De dónde sale cada marca y cada prefijo: partstown_marcas.json.
 *
 * QUÉ ENLACE, EN ESTE ORDEN:
 *   1. FICHA del repuesto, si el número es de Parts Town: prefijo conocido
 *      (HEN, FM, LCI…) seguido del número (`Hen22455` → henny-penny/hen22455).
 *      El prefijo manda, no la marca que escribió el técnico: las piezas LCI
 *      que el catálogo de KFC atribuye a Casadio están en Parts Town bajo
 *      Cimbali.
 *   2. DESPIECE del modelo, si la marca está en la tabla
 *      (`HENNY PENNY` + `PFG-690` → henny-penny/pfg-690/parts).
 *   3. BUSCAR: la portada, con el término copiado para pegarlo en su buscador.
 * Sin marca o sin modelo no hay enlace: la guía los pide antes de buscar. Cuenta como
 * «sin» el vacío, los signos y los rellenos del histórico (N/V, NO VISIBLE…).
 */
final class PartsTown
{
    public const FALTA = 'Anota marca y modelo de la placa: la guía de INDUSTEC lo pide antes de buscar.';
    private const BASE = 'https://www.partstown.com/es/';
    private static ?array $tabla = null;

    /** La tabla de partstown_marcas.json; [] si falta (entonces todo es BUSCAR). */
    public static function tabla(): array
    {
        if (self::$tabla === null) {
            $j = json_decode((string) @file_get_contents(__DIR__ . '/partstown_marcas.json'), true);
            self::$tabla = is_array($j) ? $j : [];
        }
        return self::$tabla;
    }

    /** Lo que se busca en `marcas`: mayúsculas, sin tildes, solo letras y números
        (`Turbo Aire` y `TURBOAIRE` son la misma clave). */
    public static function claveMarca(?string $marca): string
    {
        return strtoupper(Ui::normalizarBusqueda($marca));
    }

    /** minúsculas, sin tildes, y cada tramo que no es letra ni número, un guion:
        `PFG-690` → `pfg-690`, `Bu32106.0001` → `bu32106-0001`,
        `WDYRC22 (P1331407M)` → `wdyrc22-p1331407m`, como las URL de Parts Town. */
    public static function slug(?string $s): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', Ui::sinTildes($s)), '-');
    }

    /** Lo que se escribió como número de parte, hasta el primer «/», «,» o «;»
        (en el catálogo de KFC hay celdas con dos números: `Fm8100705 /
        FM8100703`). NO se corta en los espacios: «HP FR21800» y «Hen 22455» son
        UN número, y cortarlos copiaba «HP» y «Hen» (revisión del 2026-10-01). */
    public static function primerNumero(?string $numero): string
    {
        $partes = preg_split('/[\/,;\n\r]+/', trim((string) $numero)) ?: [];
        return trim((string) ($partes[0] ?? ''));
    }

    /** El número como lo escribiría Parts Town para buscar su ficha: si lo que
        se tecleó es un prefijo de letras, un espacio y el número («Hen 22455»),
        se juntan. Cualquier otro espacio corta: «Hen22455 (original)» es
        `Hen22455`. */
    public static function numeroDeFicha(string $numero): string
    {
        $t = preg_split('/[ \t\n\r\f\x0B]+/', $numero, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($t) > 1 && preg_match('/^[A-Za-z]+$/', $t[0]) === 1 && preg_match('/^[A-Za-z0-9]/', $t[1]) === 1) {
            return $t[0] . $t[1];
        }
        return (string) ($t[0] ?? '');
    }

    /** Lo que NO es un dato de la placa: vacío, solo signos, con espacios de
        cualquier clase (el duro U+00A0 también) o uno de los rellenos que el
        histórico trae por miles: «N/V», «NO VISIBLE», «SIN MODELO», «XXXX»…
        (239 modelos y 8 marcas de relleno en las sugerencias del formulario, el
        2026-10-01). Con ellos se armaba un despiece inventado:
        …/henny-penny/no-visible/parts. Los primeros son los de
        Validacion::MARCADORES. */
    private const RELLENOS = [
        's/n', 'sn', 's/m', 'sm', 'n/a', 'na', '0', 'no tiene', 'sin serie', 'sin placa',
        'n/v', 'nv', 'n/o', 'no visible', 'no aplica', 'no legible', 'ilegible',
        'sin modelo', 'sin marca', 'sin dato', 'sin datos',
    ];

    public static function relleno(?string $s): bool
    {
        // Todo lo que no es letra, número o «/» pasa a espacio, y sin espacios junto a «/».
        $n = (string) preg_replace('/[^a-z0-9\/]+/', ' ', Ui::sinTildes($s));
        $n = trim((string) preg_replace('/ ?\/ ?/', '/', $n), ' ');
        return $n === '' || in_array($n, self::RELLENOS, true) || preg_match('/^x{2,}$/', $n) === 1;
    }

    /** `marca/sku` de la ficha si el número es de Parts Town, o null. El prefijo
        más largo que calce, seguido de letra o número, y con al menos un dígito
        después (así `buscar` no pasa por Bunn ni `true` solo por True). */
    public static function ficha(?string $numero, ?array $tabla = null): ?string
    {
        $t = $tabla ?? self::tabla();
        $n = self::slug(self::numeroDeFicha(self::primerNumero($numero)));
        $mejor = null;
        foreach ((array) ($t['prefijos'] ?? []) as $prefijo => $slugMarca) {
            $p = (string) $prefijo;
            $resto = substr($n, strlen($p));
            if ($p !== '' && strncmp($n, $p, strlen($p)) === 0 && strlen($resto) >= 2
                && preg_match('/^[a-z0-9]/', $resto) && preg_match('/\d/', $resto)
                && ($mejor === null || strlen($p) > strlen($mejor[0]))) {
                $mejor = [$p, (string) $slugMarca];
            }
        }
        return $mejor === null ? null : $mejor[1] . '/' . $n;
    }

    /**
     * @param array{marca?:?string,modelo?:?string,numero_parte?:?string,sku?:?string} $d
     * @return array{tipo:?string,url:?string,copiar:?string,falta:?string}
     *   tipo FICHA | MODELO | BUSCAR, o null con `falta` si no hay marca o modelo.
     *   `copiar` es lo que conviene pegar en el buscador si la página no aparece.
     */
    public static function enlace(array $d, ?array $tabla = null): array
    {
        $t = $tabla ?? self::tabla();
        $marca  = trim((string) ($d['marca'] ?? ''));
        $modelo = trim((string) ($d['modelo'] ?? ''));
        if (self::relleno($marca) || self::relleno($modelo)) {
            return ['tipo' => null, 'url' => null, 'copiar' => null, 'falta' => self::FALTA];
        }
        $numero = self::primerNumero((string) (trim((string) ($d['sku'] ?? '')) !== '' ? $d['sku'] : ($d['numero_parte'] ?? '')));
        $copiar = $numero !== '' ? $numero : $marca . ' ' . $modelo;
        $ficha = self::ficha($numero, $t);
        if ($ficha !== null) {
            return ['tipo' => 'FICHA', 'url' => self::BASE . $ficha, 'copiar' => $copiar, 'falta' => null];
        }
        $slugMarca = $t['marcas'][self::claveMarca($marca)] ?? null;
        $slugModelo = self::slug($modelo);
        if (is_string($slugMarca) && $slugMarca !== '' && $slugModelo !== '') {
            return ['tipo' => 'MODELO', 'url' => self::BASE . $slugMarca . '/' . $slugModelo . '/parts',
                    'copiar' => $copiar, 'falta' => null];
        }
        return ['tipo' => 'BUSCAR', 'url' => self::BASE, 'copiar' => $copiar, 'falta' => null];
    }

    /**
     * La marca y el modelo del equipo de una solicitud de repuesto (Repuestos,
     * paso 4 de la guía: validar el número en el despiece). La solicitud guarda
     * el código de activo; el catálogo del local da el equipo SAP y su ficha
     * (T2.28.6) la placa. Nada se adivina: si no hay un único equipo con ese
     * código, o su ficha no tiene marca y modelo, devuelve null.
     *
     * @param array<string,array<int,array>> $equiposPorLocal Catalogo::cargar()['equipos']
     * @param array<string,array>            $fichas          Catalogo::fichas()
     * @return array{marca:string,modelo:string}|null
     */
    public static function placaDe(string $local, string $activo, array $equiposPorLocal, array $fichas): ?array
    {
        $activo = trim($activo);
        if ($local === '' || $activo === '') { return null; }
        $claves = [];
        foreach ((array) ($equiposPorLocal[$local] ?? []) as $eq) {
            if (is_array($eq) && (string) ($eq['codigo_activo'] ?? '') === $activo && !empty($eq['equipo_sap'])) {
                $claves[(string) $eq['equipo_sap']] = true;
            }
        }
        // El aviso de SAP a veces trae el número de equipo en «Activo Fijo».
        if ($claves === [] && isset($fichas[$activo])) { $claves[$activo] = true; }
        if (count($claves) !== 1) { return null; }
        $f = $fichas[(string) array_key_first($claves)] ?? null;
        $marca = trim((string) ($f['marca'] ?? ''));
        $modelo = trim((string) ($f['modelo'] ?? ''));
        return ($marca === '' || $modelo === '') ? null : ['marca' => $marca, 'modelo' => $modelo];
    }

    /** Lo que se le dice a la persona al abrir el enlace. */
    public static function aviso(array $e): string
    {
        if (($e['tipo'] ?? null) === null) { return (string) ($e['falta'] ?? self::FALTA); }
        $pega = 'pega «' . $e['copiar'] . '» en el buscador de Parts Town';
        return match ($e['tipo']) {
            'FICHA'  => 'Se abre la ficha del repuesto en Parts Town. Si no aparece, ' . $pega . '.',
            'MODELO' => 'Se abre el despiece del modelo en Parts Town. Si no aparece, ' . $pega . '.',
            // Sin atribuirle nada a Parts Town: aquí también cae el formulario con
            // una caché vieja que no trae la tabla de marcas.
            default  => 'No tenemos el enlace directo de esta marca: ' . $pega . '.',
        };
    }
}
