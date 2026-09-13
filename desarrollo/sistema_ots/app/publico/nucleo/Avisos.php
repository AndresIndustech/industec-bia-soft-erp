<?php
declare(strict_types=1);
require_once __DIR__ . '/Pendientes.php';
require_once __DIR__ . '/Novedades.php';

/**
 * Avisos — el buzón del técnico, sin tabla nueva (T2.13.5).
 *
 * QUE LE AVISA
 * Lo que le cambia el día y hoy le llega por WhatsApp, cuando le llega:
 *   - le asignaron un caso;
 *   - le quitaron un caso: se lo dieron a otro, o se derivó a otra zona;
 *   - le respondieron, o decidieron, sobre un equipo que quedó trabado;
 *   - resolvieron una novedad que reportó.
 *
 * POR QUE SIN TABLA
 * Todo eso ya queda registrado en otra parte: la asignación en `casos_gestion`,
 * el hilo en `pendiente_notas`, el veredicto en `novedades` y quién tenía antes
 * el caso en la bitácora. Una tabla de avisos sería una segunda copia que habría
 * que mantener al día con la primera, y la copia que se olvida es la que miente.
 *
 * «VISTO HASTA» es su último `CONSULTAR bandeja`, que mis.php deja cada vez que
 * la abre. No se marca leído aviso por aviso: el técnico ve qué pasó desde la
 * última vez que miró, que es la pregunta que se hace al sacar el celular.
 */
final class Avisos
{
    /**
     * Su última visita a la pestaña Avisos; null si nunca la abrió.
     *
     * H-12: hasta la 009, esto leía `accion = 'CONSULTAR'`, que mis.php anota
     * en TODA petición GET (abrir una ficha, cambiar de pestaña). El técnico
     * nunca alcanzaba a ver un aviso marcado «nuevo»: cualquier toque en la
     * bandeja ya contaba como si los hubiera leído. Ahora se lee la acción
     * `VER_AVISOS`, que mis.php anota solo cuando `$tab === 'avisos'`.
     */
    public static function visto(int $usuarioId): ?string
    {
        $f = Db::uno("SELECT MAX(cuando) AS v FROM bitacora
                       WHERE usuario_id = ? AND accion = 'VER_AVISOS' AND entidad = 'bandeja'", [$usuarioId]);
        return $f['v'] ?? null;
    }

    /**
     * Marca como vistos los pedidos de seguimiento (`casos_seguimientos`,
     * ASG-15) dirigidos a este técnico. Se llama junto con la visita a la
     * pestaña Avisos, no aparte: es el mismo gesto -- «entré a ver qué
     * pasó»-- y duplicar el marcador sería otro lugar donde desincronizarse.
     * Sin la 009 no existe la tabla: no es motivo para romper la bandeja.
     */
    public static function marcarSeguimientosVistos(int $usuarioId): void
    {
        try {
            Db::ejecutar(
                'UPDATE casos_seguimientos SET visto_en = NOW() WHERE tecnico_id = ? AND visto_en IS NULL',
                [$usuarioId]
            );
        } catch (Throwable $e) {
            // Sin la 009 no hay tabla que actualizar.
        }
    }

    /**
     * Lo que le pasó después de $desde, lo más nuevo primero.
     *
     * @return array<int,array{tipo:string,titulo:string,aviso:?string,texto:string,cuando:string}>
     */
    public static function delTecnico(int $usuarioId, string $desde, int $limite = 60): array
    {
        $ev = [];

        // Le asignaron un caso. Las del automatismo no cuentan: salen del PDF de
        // una orden que él mismo emitió, y avisarle de su propio trabajo es ruido.
        foreach (Db::todos("SELECT aviso, asignado_en FROM casos_gestion
                             WHERE asignado_a = ? AND tecnico_auto = 0 AND asignado_en > ?",
                           [$usuarioId, $desde]) as $r) {
            $ev[] = ['tipo' => 'ASIGNADO', 'titulo' => 'Te asignaron un caso', 'aviso' => (string) $r['aviso'],
                     'texto' => '', 'cuando' => (string) $r['asignado_en']];
        }

        // Le quitaron un caso: se le asignó a otro, o se derivó, uno cuya última
        // asignación era suya. La bitácora es el único lugar donde queda quién lo
        // tenía antes: `casos_gestion` ya dice el dueño nuevo. Por eso solo se
        // detecta si esa asignación anterior pasó por la bitácora —las de
        // casos.php—; las que puso el automatismo no dejan ASIGNAR.
        foreach (Db::todos("SELECT e.referencia AS aviso, e.accion, e.cuando
                              FROM bitacora e
                             WHERE e.entidad = 'caso' AND e.accion IN ('ASIGNAR','DERIVAR')
                               AND e.exito = 1 AND e.cuando > ?
                               AND COALESCE(CAST(JSON_VALUE(e.datos, '$.tecnico_id') AS UNSIGNED), 0) <> ?
                               AND (SELECT CAST(JSON_VALUE(a.datos, '$.tecnico_id') AS UNSIGNED)
                                      FROM bitacora a
                                     WHERE a.entidad = 'caso' AND a.referencia = e.referencia
                                       AND a.accion = 'ASIGNAR' AND a.exito = 1 AND a.id < e.id
                                     ORDER BY a.id DESC LIMIT 1) = ?",
                           [$desde, $usuarioId, $usuarioId]) as $r) {
            $ev[] = ['tipo' => 'QUITADO', 'titulo' => 'Te quitaron un caso', 'aviso' => (string) $r['aviso'],
                     'texto' => $r['accion'] === 'DERIVAR' ? 'Se derivó a otra zona.' : 'Se le asignó a otra persona.',
                     'cuando' => (string) $r['cuando']];
        }

        if (Pendientes::disponible()) {
            // Le respondieron o decidieron sobre un equipo suyo: uno que dejó
            // trabado él, o el de un caso que tiene asignado. Lo que escribe él
            // mismo no se le avisa.
            $TIT = ['RESPUESTA'     => 'Te respondieron',
                    'VEREDICTO'     => 'Decidieron qué se hace con un equipo',
                    'CAMBIO_ESTADO' => 'Avanzó un equipo trabado'];
            foreach (Db::todos("SELECT n.tipo, n.texto, n.creado_en, p.aviso, u.nombre,
                                       COALESCE(NULLIF(p.equipo_desc, ''), p.activo_fijo) AS equipo
                                  FROM pendiente_notas n
                                  JOIN pendientes p ON p.pendiente_id = n.pendiente_id
                                  JOIN usuarios u   ON u.usuario_id = n.usuario_id
                                 WHERE n.creado_en > ? AND n.usuario_id <> ?
                                   AND n.tipo IN ('RESPUESTA','VEREDICTO','CAMBIO_ESTADO')
                                   AND (p.abierto_por = ?
                                        OR p.aviso IN (SELECT aviso FROM casos_gestion WHERE asignado_a = ?))",
                               [$desde, $usuarioId, $usuarioId, $usuarioId]) as $r) {
                $ev[] = ['tipo' => $r['tipo'], 'titulo' => $TIT[$r['tipo']], 'aviso' => (string) $r['aviso'],
                         'texto' => ($r['equipo'] ? $r['equipo'] . ' · ' : '') . $r['nombre'] . ': '
                                  . mb_substr((string) $r['texto'], 0, 200),
                         'cuando' => (string) $r['creado_en']];
            }

            // Resolvieron una novedad que él reportó.
            foreach (Db::todos("SELECT estado, local_codigo, aviso_sap, veredicto_nota, veredicto_en
                                  FROM novedades WHERE reportada_por = ? AND veredicto_en > ?",
                               [$usuarioId, $desde]) as $r) {
                $ev[] = ['tipo' => 'NOVEDAD', 'titulo' => 'Resolvieron una novedad que reportaste', 'aviso' => null,
                         'texto' => ($r['local_codigo'] ? 'Local ' . $r['local_codigo'] . ' · ' : '')
                                  . Novedades::etiquetaEstado((string) $r['estado'])
                                  . ($r['aviso_sap'] ? ' · aviso SAP ' . $r['aviso_sap'] : '')
                                  . ($r['veredicto_nota'] ? ': ' . mb_substr((string) $r['veredicto_nota'], 0, 160) : ''),
                         'cuando' => (string) $r['veredicto_en']];
            }
        }

        // Le pidieron seguimiento de un caso (`casos_seguimientos`, ASG-15,
        // 009): un jefe de zona o la administración lo pide desde `casos.php`
        // y le aparece aquí, igual que cualquier otro aviso. Aparte del
        // `try/catch` de `Pendientes::disponible()` porque es una tabla
        // propia (S2), no del mismo paquete que las de repuestos.
        try {
            foreach (Db::todos(
                "SELECT s.aviso, s.texto, s.pedido_en, u.nombre
                   FROM casos_seguimientos s JOIN usuarios u ON u.usuario_id = s.pedido_por
                  WHERE s.tecnico_id = ? AND s.pedido_en > ?",
                [$usuarioId, $desde]
            ) as $r) {
                $ev[] = ['tipo' => 'SEGUIMIENTO', 'titulo' => 'Te pidieron seguimiento de un caso',
                         'aviso' => (string) $r['aviso'],
                         'texto' => $r['nombre'] . ': ' . mb_substr((string) $r['texto'], 0, 200),
                         'cuando' => (string) $r['pedido_en']];
            }
        } catch (Throwable $e) {
            // Sin la 009 no existe `casos_seguimientos` todavía.
        }

        usort($ev, static fn($a, $b) => strcmp($b['cuando'], $a['cuando']));
        return array_slice($ev, 0, $limite);
    }
}
