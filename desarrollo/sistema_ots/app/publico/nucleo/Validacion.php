<?php
declare(strict_types=1);

/**
 * Validacion.php - Los controles del formato unico, del lado del servidor.
 *
 * Espejo exacto de scripts/t2_5_validacion.py. Los dos corren el MISMO fixture
 * (pruebas/fixture_validacion.json), que es la unica forma de que no se separen
 * con el tiempo: si uno cambia y el otro no, el fixture lo delata.
 *
 * POR QUE HAY DOS COPIAS DE LAS REGLAS Y NO UNA:
 * Python no existe en el hosting compartido -- hace falta root, y el compartido
 * no lo da. Asi que la captura tiene que validar en PHP. Duplicar reglas es un
 * riesgo conocido; atarlas a un fixture compartido es lo que lo controla.
 *
 * ESTA VALIDACION ES LA QUE MANDA. El navegador valida tambien, para que el
 * tecnico se entere en el momento y no despues de subir 35 fotos, pero eso es
 * comodidad. Un cliente se puede modificar; el servidor nunca confia en el.
 */

final class Hallazgo
{
    public function __construct(
        public readonly string $campo,
        public readonly string $regla,
        public readonly string $severidad,
        public readonly string $mensaje,
    ) {}
}

final class Validacion
{
    public const BLOQUEA  = 'BLOQUEA';
    public const ADVIERTE = 'ADVIERTE';
    public const INFORMA  = 'INFORMA';

    /**
     * Las ocho ortografias de "no se uso repuesto" que aparecen en los datos.
     * No es una suposicion: salio de contar los valores del campo. Suman 2.168
     * de las 4.359 filas con repuesto anotado, el 50%.
     */
    private const SIN_REPUESTO = [
        'S/N', 'SN', 'NINGUNO', 'NINGUNA', 'SINREPUESTOS', 'SINREPUESTO',
        '-', 'N/A', 'NA', 'SINRESPUESTOS', '0', 'NINGUN', 'SINREPUESTOSUSADOS',
    ];

    public const SEVERIDADES = [
        'LOCAL_REQUERIDO' => self::BLOQUEA,
        'LOCAL_FUERA_DE_CATALOGO' => self::BLOQUEA,
        'ZONA_CRUZADA' => self::INFORMA,
        'AVISO_VACIO_SIN_DECLARAR' => self::BLOQUEA,
        'AVISO_MAL_FORMADO' => self::BLOQUEA,
        'TIPO_INVALIDO' => self::BLOQUEA,
        'DIA_REQUERIDO_EN_PREVENTIVO' => self::BLOQUEA,
        'DIA_FUERA_DE_RANGO' => self::BLOQUEA,
        'DIA_EN_CORRECTIVO' => self::ADVIERTE,
        'SIN_EQUIPO' => self::BLOQUEA,
        'EQUIPO_DE_OTRO_LOCAL' => self::BLOQUEA,
        'EQUIPO_SIN_IDENTIFICAR' => self::BLOQUEA,
        'TIPO_FUERA_DE_CATALOGO' => self::ADVIERTE,
        'EQUIPO_ELEGIBLE_POR_ACTIVO' => self::INFORMA,
        'REPUESTO_NO_DECLARADO' => self::BLOQUEA,
        'REPUESTO_MARCADO_SIN_DETALLE' => self::BLOQUEA,
        'REPUESTO_CONTRADICTORIO' => self::ADVIERTE,
        'FECHA_FUTURA' => self::BLOQUEA,
        'FIN_NO_POSTERIOR_A_INICIO' => self::BLOQUEA,
        'DURACION_INVEROSIMIL' => self::ADVIERTE,
        'TIEMPOS_INCOMPLETOS' => self::ADVIERTE,
        'SIN_TRABAJO_REALIZADO' => self::BLOQUEA,
        'TRABAJO_DEMASIADO_ESCUETO' => self::ADVIERTE,
        'SIN_FIRMA' => self::BLOQUEA,
        'SIN_FOTOS' => self::ADVIERTE,
        'SIN_TECNICO' => self::BLOQUEA,
        'TECNICO_NO_VIGENTE' => self::BLOQUEA,
        'TECNICO_YA_NO_VIGENTE' => self::INFORMA,
        'VARIOS_TECNICOS_EN_UN_CAMPO' => self::INFORMA,
        // T2.14.1 (H-10, D8): un equipo que no estaba en el catalogo del local
        // se acepta -- no bloquea el envio de la orden-- pero queda marcado
        // para que la administracion lo revise antes de sumarlo al maestro.
        'EQUIPO_NUEVO_PROPUESTO' => self::ADVIERTE,
        // T2.14.1 (H-18, D10): la casilla "trabajo con otro proveedor" abre el
        // nombre del proveedor; marcarla sin decir quien es deja un dato inutil
        // para la administracion.
        'CON_PROVEEDOR_SIN_NOMBRE' => self::BLOQUEA,
        // T2.28.3 (obs. 2): solo advierte -se corrige en correos.php, no
        // corta el envío- y solo en CAPTURA: el histórico no tiene el campo.
        'CORREO_INVALIDO' => self::ADVIERTE,
        // T2.28.6 (obs. 4): la ficha del equipo, solo en CAPTURA con
        // formulario_v >= 2 (§5.4, misma razón que CORREO_INVALIDO).
        'EQUIPO_SIN_DATOS_DE_PLACA' => self::BLOQUEA,
        'EQUIPO_SIN_SERIE' => self::ADVIERTE,
    ];

    /**
     * Las grafías de "no hay dato" en la placa de un equipo o en lo que
     * teclea el técnico. Mismo contrato que reglas.js::esMarcador() y
     * t2_5_validacion.py::es_marcador() -con casos en el fixture-, para que
     * las tres no se separen.
     */
    private const MARCADORES = [
        'S/N', 'SN', 'S/M', 'SM', 'N/A', 'NA', 'XXX', '-', '—', '--',
        'NO TIENE', 'SIN SERIE', 'SIN PLACA', '0',
    ];

    public static function esMarcador(?string $s): bool
    {
        $n = self::sinTildes((string) $s);
        $n = rtrim(strtoupper(trim($n)), '.');
        return $n === '' || in_array($n, self::MARCADORES, true);
    }

    /**
     * Mayúsculas, sin espacios dobles, sin tildes; con un mapa de sinónimos
     * opcional (de marcas.json, p. ej. TRUE REFRIGERATOR -> TRUE) que
     * t2_28_marcas.py arma para que una persona lo revise -nunca se adivina
     * aquí (I-7).
     */
    public static function normMarca(?string $s, array $sinonimos = []): string
    {
        $n = preg_replace('/\s+/', ' ', trim(self::sinTildes((string) $s)));
        $n = strtoupper((string) $n);
        return $sinonimos[$n] ?? $n;
    }

    private static function sinTildes(string $s): string
    {
        $sin = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return $sin === false ? preg_replace('/[^\x20-\x7E]/', '', $s) ?? '' : $sin;
    }

    /** @var array{locales:array,equipos:array,tipos:array,tecnicos:array} */
    private array $cat;

    public function __construct(array $catalogo)
    {
        $locales = [];
        foreach ($catalogo['locales'] as $l) {
            $locales[$l['codigo']] = $l;
        }
        $tecnicos = [];
        foreach ($catalogo['tecnicos'] as $t) {
            $tecnicos[] = self::tokensNombre($t['nombre']);
        }
        $this->cat = [
            'locales'  => $locales,
            'equipos'  => $catalogo['equipos'] ?? [],
            'tipos'    => array_flip(array_map('strtoupper', $catalogo['tipos'] ?? [])),
            'tecnicos' => $tecnicos,
        ];
    }

    /**
     * Tokens de un nombre, sin tildes y sin particulas cortas.
     *
     * Hace falta porque la nomina guarda el nombre legal completo
     * (ANTHONY MEDARDO JUMBO ROJANO) y el tecnico se firma como se le conoce
     * (Anthony Jumbo). Comparar cadenas enteras marcaba como desconocido al 92%
     * de los tecnicos, que es falso: son la misma persona.
     */
    public static function tokensNombre(?string $s): array
    {
        $s = (string) $s;
        // iconv con //TRANSLIT quita las tildes. Se fija el locale a C porque
        // en algunos servidores el translit depende del locale y devuelve '?'.
        $sinTildes = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($sinTildes === false) {
            $sinTildes = preg_replace('/[^\x20-\x7E]/', '', $s) ?? '';
        }
        $partes = preg_split('/[^A-Za-z]+/', strtoupper($sinTildes)) ?: [];
        $out = [];
        foreach ($partes as $p) {
            if (strlen($p) > 2) {
                $out[$p] = true;
            }
        }
        return $out;
    }

    private static function esSinRepuesto(mixed $v): bool
    {
        if ($v === null || $v === '' || $v === false) {
            return true;
        }
        $n = strtoupper(preg_replace('/[.\s]/', '', (string) $v) ?? '');
        return in_array($n, self::SIN_REPUESTO, true);
    }

    /** Un array de tokens esta contenido en otro. */
    private static function contenido(array $a, array $b): bool
    {
        foreach ($a as $k => $_) {
            if (!isset($b[$k])) {
                return false;
            }
        }
        return $a !== [];
    }

    /**
     * Valida una orden completa. Array vacio = todo bien.
     *
     * $contexto cambia lo que significa un mismo hecho segun cuando se mira:
     *   CAPTURA    una orden que se esta enviando ahora. El desplegable solo
     *              ofrece tecnicos vigentes, asi que uno desconocido se corta.
     *   HISTORICO  una orden ya emitida. INDUSTEC tiene alta rotacion, y una
     *              orden vieja firmada por alguien que ya se fue es historia
     *              correcta, no dato sucio.
     *
     * @return Hallazgo[]
     */
    public function validar(array $o, string $contexto = 'CAPTURA'): array
    {
        $h = [];
        $add = function (string $campo, string $regla, string $msg) use (&$h): void {
            $h[] = new Hallazgo($campo, $regla, self::SEVERIDADES[$regla], $msg);
        };

        $tipo = strtoupper((string) ($o['tipo'] ?? ''));

        // --- LOCAL. La regla que sola elimina las 158 grafias. ---------------
        $local = $o['local'] ?? null;
        if ($local === null || $local === '') {
            $add('local', 'LOCAL_REQUERIDO',
                 'sin local no se puede archivar, cruzar ni facturar la orden');
            $local = null;
        } elseif (!isset($this->cat['locales'][$local])) {
            $add('local', 'LOCAL_FUERA_DE_CATALOGO',
                 "'$local' no esta en el maestro de " . count($this->cat['locales']) . ' locales');
        }

        // --- ZONA. No se valida: se deriva. Por eso no puede estar cruzada. --
        $info = $local !== null ? ($this->cat['locales'][$local] ?? null) : null;
        if ($info && !empty($o['zona']) && $o['zona'] !== $info['zona']) {
            $add('zona', 'ZONA_CRUZADA',
                 "el envio dice {$o['zona']} y el local $local es de {$info['zona']}; manda el maestro");
        }

        // --- AVISO SAP. Ocho digitos, o declararlo ausente a proposito. ------
        $aviso = $o['aviso'] ?? null;
        if ($aviso === null || $aviso === '' || $aviso === '0' || $aviso === 0) {
            if (empty($o['sin_aviso'])) {
                // Una orden puede nacer sin aviso -- el tecnico ya estaba en
                // sitio y el aviso no existia todavia (regla del cliente,
                // 2026-09-04). Pero tiene que ser una decision declarada, no un
                // campo que quedo vacio.
                $add('aviso', 'AVISO_VACIO_SIN_DECLARAR',
                     "marca 'esta orden nace sin aviso' o escribe el aviso");
            }
        } elseif (!preg_match('/^\d{8}$/', (string) $aviso)) {
            $add('aviso', 'AVISO_MAL_FORMADO', "'$aviso' no son 8 digitos");
        }

        // --- TIPO y DIA DE INTERVENCION. -------------------------------------
        if ($tipo !== 'CORRECTIVO' && $tipo !== 'PREVENTIVO') {
            $add('tipo', 'TIPO_INVALIDO', "'$tipo' no es CORRECTIVO ni PREVENTIVO");
        }
        $dia = $o['dia_intervencion'] ?? null;
        $diaVacio = ($dia === null || $dia === '' || $dia === 0 || $dia === '0');
        if ($tipo === 'PREVENTIVO') {
            if ($diaVacio) {
                $add('dia_intervencion', 'DIA_REQUERIDO_EN_PREVENTIVO',
                     'un preventivo pertenece a un dia del ingreso');
            } elseif (!ctype_digit((string) $dia) || (int) $dia < 1 || (int) $dia > 5) {
                $add('dia_intervencion', 'DIA_FUERA_DE_RANGO', "'$dia' no esta entre 1 y 5");
            }
        } elseif (!$diaVacio) {
            $add('dia_intervencion', 'DIA_EN_CORRECTIVO',
                 'un correctivo no tiene dia de intervencion');
        }

        // --- EQUIPOS. Bloque repetible, minimo 1, en los dos tipos. ----------
        $equipos = $o['equipos'] ?? [];
        if (!$equipos) {
            $add('equipos', 'SIN_EQUIPO', 'toda orden interviene al menos un equipo');
        }
        $delLocal   = $local !== null ? ($this->cat['equipos'][$local] ?? []) : [];
        $activos    = [];
        $tiposLocal = [];
        foreach ($delLocal as $e) {
            $activos[(string) $e['equipo_sap']] = true;
            $tiposLocal[strtoupper($e['tipo'])]  = true;
        }
        foreach ($equipos as $i => $eq) {
            $n    = $i + 1;
            $ref  = isset($eq['equipo_sap']) ? (string) $eq['equipo_sap'] : '';
            $tipoEq = strtoupper((string) ($eq['tipo'] ?? ''));
            $esNuevo = !empty($eq['nuevo']);
            if ($ref !== '') {
                if ($activos && !isset($activos[$ref])) {
                    $add("equipos[$n]", 'EQUIPO_DE_OTRO_LOCAL',
                         "el activo $ref no pertenece a $local");
                }
            } elseif ($tipoEq === '') {
                $add("equipos[$n]", 'EQUIPO_SIN_IDENTIFICAR',
                     'elige el activo del local, o al menos su tipo');
            } elseif ($esNuevo) {
                // «Equipo nuevo / no está en la lista» (H-10, D8): el técnico
                // eligió un tipo del catálogo pero el activo no existe todavía
                // en el maestro del local. No bloquea -- el trabajo se hizo--
                // pero queda para que la administración lo apruebe.
                $add("equipos[$n]", 'EQUIPO_NUEVO_PROPUESTO',
                     "'$tipoEq' se registra como equipo nuevo del local $local; la administración lo revisa");
            } elseif (!isset($this->cat['tipos'][$tipoEq])) {
                // No bloquea: los 6 locales sin activos catalogados y los tipos
                // que SAP todavia no registro son un caso real. Se marca para
                // que el catalogo crezca, no para frenar al tecnico.
                $add("equipos[$n]", 'TIPO_FUERA_DE_CATALOGO',
                     "'$tipoEq' no esta entre los " . count($this->cat['tipos']) . ' tipos conocidos');
            }
            if ($tiposLocal && $tipoEq !== '' && $ref === '' && !$esNuevo && isset($tiposLocal[$tipoEq])) {
                $add("equipos[$n]", 'EQUIPO_ELEGIBLE_POR_ACTIVO',
                     "$local tiene activos de tipo '$tipoEq' en el catalogo; conviene elegir cual");
            }
            // --- FICHA DEL EQUIPO (T2.28.6, obs. 4). Solo en CAPTURA y con
            // formulario_v >= 2: el histórico y una app vieja en caché no
            // traen estos campos (§5.4). «Hay equipo elegido» es lo mismo
            // que ya decide EQUIPO_SIN_IDENTIFICAR arriba.
            $hayEquipoElegido = $ref !== '' || $tipoEq !== '';
            if ($contexto === 'CAPTURA' && (int) ($o['formulario_v'] ?? 0) >= 2
                && $hayEquipoElegido && empty($eq['sin_placa'])) {
                $faltaMarca  = self::esMarcador($eq['marca'] ?? null);
                $faltaModelo = self::esMarcador($eq['modelo'] ?? null);
                if ($faltaMarca || $faltaModelo) {
                    $add("equipos[$n]", 'EQUIPO_SIN_DATOS_DE_PLACA',
                         'falta la marca o el modelo del equipo; marca «sin placa o ilegible» si no se puede leer');
                }
                if (self::esMarcador($eq['serie'] ?? null)) {
                    $add("equipos[$n]", 'EQUIPO_SIN_SERIE', 'falta la serie del equipo');
                }
            }
        }

        // --- TRABAJO CON OTRO PROVEEDOR (H-18, D10). -------------------------
        if (!empty($o['con_proveedor_marcado']) && trim((string) ($o['con_proveedor'] ?? '')) === '') {
            $add('con_proveedor', 'CON_PROVEEDOR_SIN_NOMBRE',
                 'marcaste que el trabajo lo hizo otro proveedor pero falta su nombre');
        }

        // --- CORREO DEL LOCAL (T2.28.3, obs. 2). ------------------------------
        // Solo en CAPTURA: el histórico no trae este campo, y marcarlo ahí
        // inventaría un defecto que la orden nunca tuvo. No bloquea: sigue el
        // envío y Emision::correoLocal() cae al correo del maestro.
        $correoLocal = (string) ($o['correo_local'] ?? '');
        if ($contexto === 'CAPTURA' && $correoLocal !== ''
            && !preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $correoLocal)) {
            $add('correo_local', 'CORREO_INVALIDO',
                 "'$correoLocal' no parece un correo válido; la orden saldrá al correo del maestro");
        }

        // --- REPUESTOS. La casilla que elimina el 50% del ruido. -------------
        $uso     = array_key_exists('uso_repuesto', $o) ? $o['uso_repuesto'] : null;
        $detalle = $o['repuestos'] ?? null;
        if ($uso === null) {
            $add('uso_repuesto', 'REPUESTO_NO_DECLARADO', 'responde si se uso repuesto o no');
        } elseif ($uso && self::esSinRepuesto($detalle)) {
            $add('repuestos', 'REPUESTO_MARCADO_SIN_DETALLE',
                 'dice que se uso repuesto pero no dice cual');
        } elseif (!$uso && $detalle && !self::esSinRepuesto($detalle)) {
            $add('repuestos', 'REPUESTO_CONTRADICTORIO',
                 "marca que no hubo repuesto pero anota '" . mb_substr((string) $detalle, 0, 40) . "'");
        }

        // --- FECHA. Una orden no se atiende en el futuro. --------------------
        $fecha = $o['fecha_atencion'] ?? null;
        if ($fecha) {
            $hoy = $o['_hoy'] ?? date('Y-m-d');
            if (substr((string) $fecha, 0, 10) > substr((string) $hoy, 0, 10)) {
                $add('fecha_atencion', 'FECHA_FUTURA', "$fecha es posterior a hoy");
            }
        }

        // --- TIEMPOS. --------------------------------------------------------
        $ini = !empty($o['inicio']) ? strtotime((string) $o['inicio']) : null;
        $fin = !empty($o['fin'])    ? strtotime((string) $o['fin'])    : null;
        if ($ini && $fin) {
            if ($fin <= $ini) {
                // Con hora sin fecha esto es indistinguible de una intervencion
                // que cruza medianoche, y por eso el formato unico captura
                // fecha+hora. Nunca se suman 24 h por verosimilitud (I-6).
                $add('fin', 'FIN_NO_POSTERIOR_A_INICIO',
                     'la hora de fin debe ser posterior a la de inicio');
            } elseif (($fin - $ini) > 12 * 3600) {
                $add('fin', 'DURACION_INVEROSIMIL',
                     sprintf('%.1f horas de atencion', ($fin - $ini) / 3600));
            }
        } else {
            $add('inicio', 'TIEMPOS_INCOMPLETOS',
                 'sin hora de inicio y fin no se puede medir la atencion');
        }

        // --- TRABAJO REALIZADO. ----------------------------------------------
        $act = trim((string) ($o['actividades'] ?? ''));
        if ($act === '') {
            $add('actividades', 'SIN_TRABAJO_REALIZADO', 'describe que se hizo');
        } elseif (mb_strlen($act) < 15) {
            $add('actividades', 'TRABAJO_DEMASIADO_ESCUETO',
                 "'$act' no alcanza a describir una intervencion");
        }

        // --- FIRMA Y EVIDENCIA. ----------------------------------------------
        if (empty($o['firma_presente'])) {
            $add('firma', 'SIN_FIRMA', 'la orden la firma el administrador del local');
        }
        if (empty($o['fotos_cantidad'])) {
            $add('fotos', 'SIN_FOTOS', 'sin fotos la orden no tiene evidencia de lo hecho');
        }

        // --- TECNICOS. --------------------------------------------------------
        $tec = trim((string) ($o['tecnico'] ?? ''));
        if ($tec === '') {
            $add('tecnico', 'SIN_TECNICO', 'sin responsable');
        } else {
            // Varios tecnicos en un mismo trabajo es un caso REAL: 1.113 ordenes
            // del historico, el 16%. El formato unico lleva una lista; aqui se
            // separa por coma para poder medir sin castigar el caso legitimo.
            $partes = preg_split('/\s*[,\/;]\s*|\s+y\s+/i', $tec) ?: [];
            $partes = array_values(array_filter(array_map('trim', $partes), fn($x) => $x !== ''));
            foreach ($partes as $parte) {
                $t = self::tokensNombre($parte);
                $encontrado = false;
                foreach ($this->cat['tecnicos'] as $n) {
                    if (self::contenido($t, $n)) {
                        $encontrado = true;
                        break;
                    }
                }
                if ($t && !$encontrado) {
                    if ($contexto === 'CAPTURA') {
                        $add('tecnico', 'TECNICO_NO_VIGENTE',
                             "'$parte' no esta entre los tecnicos vigentes");
                    } else {
                        $add('tecnico', 'TECNICO_YA_NO_VIGENTE',
                             "'$parte' no esta en la nomina actual; esperable por la rotacion");
                    }
                }
            }
            if (count($partes) > 1) {
                $add('tecnico', 'VARIOS_TECNICOS_EN_UN_CAMPO',
                     count($partes) . ' tecnicos en un campo de texto; el formato unico los lleva como lista');
            }
        }

        return $h;
    }

    /** @param Hallazgo[] $hallazgos */
    public static function bloquea(array $hallazgos): bool
    {
        foreach ($hallazgos as $x) {
            if ($x->severidad === self::BLOQUEA) {
                return true;
            }
        }
        return false;
    }
}
