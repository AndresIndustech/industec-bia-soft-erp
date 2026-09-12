<?php
declare(strict_types=1);

require_once __DIR__ . '/Ui.php';

/**
 * Novedades.php — Lo que el técnico ve en la visita y no era su orden.
 *
 * ============================================================================
 * DE DONDE SALE ESTO
 *
 * El técnico entra a hacer un preventivo y ve cosas que no son su preventivo:
 * una plancha que ya está fallando y va a necesitar correctivo, un
 * tomacorriente recalentado, un extractor que no jala, un desagüe tapado bajo
 * la freidora. Nada de eso es la orden que fue a hacer, y hasta hoy termina
 * —cuando termina— en una foto por WhatsApp o en un comentario de pasillo.
 *
 * Lo que se pierde ahí es doble:
 *
 *   1. El correctivo que se veía venir. Cuando el equipo se para de verdad, es
 *      una urgencia de 48 horas que se pudo planificar con semanas.
 *   2. Lo que NO le toca a INDUSTEC. Una instalación eléctrica mal hecha, una
 *      ventilación insuficiente o un desagüe tapado hacen fallar equipos una y
 *      otra vez. Sin registro, INDUSTEC queda como la que no sabe reparar,
 *      cuando la causa es de otra área del local.
 *
 * ============================================================================
 * EL RECORRIDO, Y DONDE ESTA LA DECISION
 *
 *   El técnico REPORTA lo que vio, con su riesgo y de quién cree que es.
 *   -> La administradora y el jefe de zona lo VEN y DECIDEN cómo reportarlo.
 *   -> Si procede, se le pide el aviso a Grupo KFC y el número vuelve aquí.
 *
 * El sistema NO crea nada en SAP y no tiene por qué: el aviso lo abre el
 * cliente en su propio sistema. Lo que sí hace es que la novedad no se pierda
 * entre la visita y esa decisión, y guardar el número de aviso cuando exista
 * para poder demostrar que se avisó y cuándo.
 *
 * LA CLASIFICACION LA PROPONE EL TECNICO, NO LA DECIDE. `responsable_prop` es
 * lo que él cree; el veredicto es del jefe o de la administración. Es la misma
 * regla que las alertas de alcance del buzón: el sistema marca, la persona
 * resuelve.
 */
final class Novedades
{
    /** Las áreas que la operación nombró como causantes de fallas repetidas. */
    public const TIPOS = [
        'EQUIPO_CORRECTIVO' => ['Equipo — necesita correctivo', 'Un equipo que todavía opera pero va a fallar. Le toca a INDUSTEC.'],
        'ELECTRICO'         => ['Instalación eléctrica',        'Tomacorrientes, tableros, cableado. Hace fallar equipos una y otra vez.'],
        'VENTILACION'       => ['Ventilación o extracción',     'Campanas, extractores, inyección. Sin extracción los equipos se recalientan.'],
        'DESAGUE'           => ['Desagüe',                      'Sifones, canaletas, tuberías tapadas bajo los equipos.'],
        'AGUA'              => ['Suministro de agua',           'Presión, filtros, fugas.'],
        'GAS'               => ['Gas',                          'Tuberías, reguladores, fugas.'],
        'REFRIGERACION'     => ['Refrigeración del local',      'Cuartos fríos y sistemas centrales.'],
        'CONSTRUCTIVO'      => ['Obra civil',                   'Pisos, techos, mesones, estructura donde asientan los equipos.'],
        'SEGURIDAD'         => ['Riesgo para las personas',     'Lo que puede lastimar a alguien. Se mira el mismo día.'],
        'OTRO'              => ['Otra cosa',                    'Lo que no entra en las anteriores.'],
    ];

    public const ESTADOS = [
        'REPORTADA'        => ['reportada',      'El técnico la registró; nadie la ha revisado'],
        'EN_REVISION'      => ['en revisión',    'Alguien la está mirando'],
        'DERIVADA_SAP'     => ['con aviso SAP',  'Se le pidió el aviso a Grupo KFC y ya tiene número'],
        'ASUMIDA_INDUSTEC' => ['la asume INDUSTEC', 'Entra en nuestra planificación sin aviso nuevo'],
        'DESCARTADA'       => ['descartada',     'Se revisó y no procedía, con su motivo'],
        'RESUELTA'         => ['resuelta',       'Ya se atendió'],
    ];

    public const RIESGOS = ['ALTO' => 'Alto', 'MEDIO' => 'Medio', 'BAJO' => 'Bajo'];
    public const PENDIENTES = ['REPORTADA', 'EN_REVISION'];

    public static function disponible(): bool
    {
        static $hay = null;
        if ($hay !== null) { return $hay; }
        try { Db::todos('SELECT 1 FROM novedades LIMIT 1'); return $hay = true; }
        catch (Throwable $e) { return $hay = false; }
    }

    public static function etiquetaTipo(?string $t): string
    {
        return self::TIPOS[strtoupper((string) $t)][0] ?? (string) $t;
    }
    public static function etiquetaEstado(?string $e): string
    {
        return self::ESTADOS[strtoupper((string) $e)][0] ?? strtolower(str_replace('_', ' ', (string) $e));
    }

    /**
     * El alcance, en el WHERE y no al dibujar.
     *
     * El técnico ve las que él reportó: le sirve para saber si le hicieron caso,
     * que es la razón por la que la gente deja de reportar cosas.
     */
    private static function alcance(): array
    {
        $u = Auth::actual();
        if (!$u) { return ['1=0', []]; }
        if ($u['rol'] === 'TECNICO') { return ['n.reportada_por = ?', [(int) $u['usuario_id']]]; }
        $z = Auth::zonaAlcance();
        if ($z !== null) { return ['n.zona = ?', [$z]]; }
        return ['1=1', []];
    }

    public static function lista(array $f = []): array
    {
        if (!self::disponible()) { return []; }
        [$donde, $par] = self::alcance();

        $g = (string) ($f['grupo'] ?? 'pendientes');
        if ($g === 'pendientes') {
            $donde .= " AND n.estado IN ('" . implode("','", self::PENDIENTES) . "')";
        } elseif ($g === 'resueltas') {
            $donde .= " AND n.estado IN ('DERIVADA_SAP','ASUMIDA_INDUSTEC','RESUELTA')";
        } elseif ($g === 'descartadas') {
            $donde .= " AND n.estado = 'DESCARTADA'";
        } elseif ($g === 'ajenas') {
            // Lo que hace fallar equipos y NO le toca a INDUSTEC. Es la vista
            // que sostiene la conversación con Grupo KFC sobre por qué un local
            // repite averías.
            $donde .= " AND n.tipo <> 'EQUIPO_CORRECTIVO'";
        }
        if (!empty($f['tipo']) && isset(self::TIPOS[$f['tipo']])) {
            $donde .= ' AND n.tipo = ?';
            $par[] = $f['tipo'];
        }
        if (!empty($f['q'])) {
            $donde .= ' AND (n.descripcion LIKE ? OR n.local_codigo LIKE ? OR n.equipo_desc LIKE ?)';
            $like = '%' . $f['q'] . '%';
            $par[] = $like; $par[] = $like; $par[] = $like;
        }

        return Db::todos(
            "SELECT n.*, r.nombre AS reporto, v.nombre AS decidio
               FROM novedades n
               JOIN usuarios r ON r.usuario_id = n.reportada_por
          LEFT JOIN usuarios v ON v.usuario_id = n.veredicto_por
              WHERE $donde
              /* El riesgo manda sobre la fecha: una novedad de riesgo alto de
                 hace tres días importa más que una baja de esta mañana. */
              ORDER BY FIELD(n.riesgo,'ALTO','MEDIO','BAJO'), n.reportada_en DESC",
            $par
        );
    }

    /**
     * Registrar lo que se vio.
     *
     * Idempotente por el UUID que genera el celular antes del primer envío.
     * No se usa (aviso, equipo, tipo) como clave porque en una misma visita
     * puede haber dos hallazgos eléctricos distintos y legítimos, y una clave
     * así se comería el segundo.
     */
    public static function reportar(array $d): array
    {
        if (!self::disponible()) {
            return [false, 'El módulo de novedades todavía no está instalado en la base.', null];
        }
        $u = Auth::actual();
        $uuid = (string) ($d['novedad_uuid'] ?? '');
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            $uuid = self::uuid();
        }
        $desc = trim((string) ($d['descripcion'] ?? ''));
        if ($desc === '') { return [false, 'Describe qué viste.', null]; }

        $tipo = strtoupper((string) ($d['tipo'] ?? 'EQUIPO_CORRECTIVO'));
        if (!isset(self::TIPOS[$tipo])) { $tipo = 'OTRO'; }
        $riesgo = strtoupper((string) ($d['riesgo'] ?? 'MEDIO'));
        if (!isset(self::RIESGOS[$riesgo])) { $riesgo = 'MEDIO'; }
        $resp = strtoupper((string) ($d['responsable'] ?? 'INDUSTEC'));
        if (!in_array($resp, ['INDUSTEC', 'CLIENTE', 'TERCERO'], true)) { $resp = 'INDUSTEC'; }

        $zona = strtoupper((string) ($d['zona'] ?? ''));
        if (!in_array($zona, ['UIO', 'LARB', 'CNLJ', 'OTRA'], true)) { $zona = null; }
        // Quien tiene alcance de zona solo reporta en la suya. Sin esto, un POST
        // a mano mete novedades en la bandeja del jefe de otra zona (antes solo
        // se forzaba al técnico; un jefe de zona podía escribir en cualquiera).
        $za = Auth::zonaAlcance();
        if ($za !== null) { $zona = $za !== '' ? $za : null; }

        Db::ejecutar(
            'INSERT INTO novedades
                (novedad_uuid, aviso_origen, ot_origen, modulo_origen, local_codigo, zona, cadena,
                 tipo, activo_fijo, equipo_desc, descripcion, riesgo, responsable_prop,
                 reportada_por, detectada_en)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,FROM_UNIXTIME(?))
             /* El reintento del mismo UUID solo refresca lo del mismo autor, y
                mientras nadie la haya revisado: una novedad ya decidida no se
                reescribe desde un celular. */
             ON DUPLICATE KEY UPDATE
                descripcion = IF(reportada_por = VALUES(reportada_por) AND estado = "REPORTADA",
                                 VALUES(descripcion), descripcion),
                riesgo      = IF(reportada_por = VALUES(reportada_por) AND estado = "REPORTADA",
                                 VALUES(riesgo), riesgo)',
            [$uuid,
             ($d['aviso'] ?? '') !== '' ? mb_substr((string) $d['aviso'], 0, 20) : null,
             ($d['ot'] ?? '') !== '' ? mb_substr((string) $d['ot'], 0, 60) : null,
             in_array($d['modulo'] ?? '', ['CORRECTIVO', 'PREVENTIVO'], true) ? $d['modulo'] : null,
             ($d['local'] ?? '') !== '' ? mb_substr((string) $d['local'], 0, 12) : null,
             $zona,
             ($d['cadena'] ?? '') !== '' ? mb_substr((string) $d['cadena'], 0, 40) : null,
             $tipo,
             mb_substr(trim((string) ($d['activo_fijo'] ?? '')), 0, 60) ?: null,
             mb_substr(trim((string) ($d['equipo_desc'] ?? '')), 0, 160) ?: null,
             mb_substr($desc, 0, 800), $riesgo, $resp,
             (int) $u['usuario_id'],
             // En el reloj de la base, como `reportada_en`: FROM_UNIXTIME, no date().
             isset($d['detectada_ts']) ? (int) $d['detectada_ts']
                 : ((($d['detectada_en'] ?? '') !== '' && strtotime((string) $d['detectada_en']) !== false)
                     ? strtotime((string) $d['detectada_en']) : null)]
        );

        $f = Db::uno('SELECT novedad_id FROM novedades WHERE novedad_uuid = ?', [$uuid]);
        $id = (int) ($f['novedad_id'] ?? 0);

        Auth::bitacora('NOVEDAD_REPORTA', 'novedad', (string) $id,
                       self::etiquetaTipo($tipo) . ' · ' . mb_substr($desc, 0, 100),
                       null, 'REPORTADA',
                       ['tipo' => $tipo, 'riesgo' => $riesgo, 'responsable' => $resp,
                        'local' => $d['local'] ?? null, 'zona' => $zona]);

        return [true, $riesgo === 'ALTO'
            ? 'Novedad registrada como riesgo alto. Tu jefe de zona la ve arriba de su lista.'
            : 'Novedad registrada. La revisa tu jefe de zona o la administración.', $id];
    }

    /**
     * El veredicto: cómo se reporta esta novedad.
     *
     * DERIVADA_SAP exige el número de aviso. Sin el número, «se le pidió a KFC»
     * es una afirmación que no se puede sostener tres semanas después, y este
     * sistema existe justamente para poder sostenerlas.
     */
    public static function resolver(int $id, string $estado, string $nota, string $avisoSap): array
    {
        if (!Ui::puedeModulo('novedades.gestionar', ['SUPERADMIN', 'ADMIN', 'JEFE_ZONA'], Auth::actual())) {
            Auth::bitacora('DENEGADO', 'novedad', (string) $id, 'resolver sin permiso',
                           null, $estado, [], false);
            return [false, 'No tienes permiso para resolver novedades.'];
        }
        if (!isset(self::ESTADOS[$estado])) { return [false, 'Estado no válido.']; }

        [$donde, $par] = self::alcance();
        array_unshift($par, $id);
        $n = Db::uno("SELECT n.* FROM novedades n WHERE n.novedad_id = ? AND ($donde)", $par);
        if (!$n) {
            // El rechazo por alcance deja rastro igual que el de permiso: un POST
            // fabricado contra otra zona tiene que quedar en la bitácora (T2.12.5).
            Auth::bitacora('DENEGADO', 'novedad', (string) $id, 'resolver fuera de alcance o inexistente',
                           null, $estado, [], false);
            return [false, 'Esa novedad no existe o no está en tu alcance.'];
        }

        $avisoSap = trim($avisoSap);
        if ($estado === 'DERIVADA_SAP' && $avisoSap === '') {
            return [false, 'Para darla por derivada hace falta el número de aviso que creó Grupo KFC.'];
        }
        if ($estado === 'DESCARTADA' && trim($nota) === '') {
            return [false, 'Para descartarla hace falta el motivo: el técnico la reportó y merece saber por qué no procede.'];
        }

        Db::ejecutar(
            'UPDATE novedades
                SET estado = ?, aviso_sap = NULLIF(?, ""), veredicto_por = ?,
                    veredicto_en = NOW(), veredicto_nota = NULLIF(?, "")
              WHERE novedad_id = ?',
            [$estado, mb_substr($avisoSap, 0, 20), (int) Auth::actual()['usuario_id'],
             mb_substr(trim($nota), 0, 600), $id]
        );

        Auth::bitacora('NOVEDAD_RESUELVE', 'novedad', (string) $id,
                       self::etiquetaEstado($estado) . ($avisoSap !== '' ? ' · aviso ' . $avisoSap : ''),
                       $n['estado'], $estado,
                       ['aviso_sap' => $avisoSap ?: null, 'nota' => $nota,
                        'dias_desde_reporte' => Ui::dias((string) $n['reportada_en'])]);

        return [true, 'Novedad ' . self::etiquetaEstado($estado) . '.'];
    }

    public static function contadores(): array
    {
        $cero = ['pendientes' => 0, 'alto' => 0, 'ajenas' => 0, 'con_aviso' => 0];
        if (!self::disponible()) { return $cero; }
        [$donde, $par] = self::alcance();
        $p = "'" . implode("','", self::PENDIENTES) . "'";
        $f = Db::uno(
            "SELECT SUM(n.estado IN ($p))                                    AS pendientes,
                    SUM(n.estado IN ($p) AND n.riesgo = 'ALTO')              AS alto,
                    SUM(n.estado IN ($p) AND n.tipo <> 'EQUIPO_CORRECTIVO')  AS ajenas,
                    SUM(n.aviso_sap IS NOT NULL)                             AS con_aviso
               FROM novedades n WHERE $donde", $par
        );
        foreach ($cero as $k => $_) { $cero[$k] = (int) ($f[$k] ?? 0); }
        return $cero;
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
