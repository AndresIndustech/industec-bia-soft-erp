<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Auth.php';

/**
 * yo.php — Quién está usando la aplicación.
 *
 * ============================================================================
 * POR QUE EXISTE
 *
 * El formulario abría con un desplegable de 19 técnicos y el técnico tenía que
 * buscarse en la lista. Eso es dos cosas malas a la vez:
 *
 *   - Un gesto de más en cada orden, al empezar, cuando lo que quiere es
 *     resolver y salir del local.
 *   - Un agujero de trazabilidad. Un desplegable con los 19 nombres permite
 *     firmar como cualquiera de ellos, y la firma de la orden es lo que
 *     después identifica quién hizo el trabajo ante Grupo KFC.
 *
 * Ahora el formulario pregunta aquí quién está adentro y lo muestra, sin poder
 * cambiarlo. `envio.php` **vuelve a tomar la identidad de la sesión** al
 * recibir y descarta lo que venga en la orden: aunque alguien edite el HTML,
 * la orden se firma con quien tenía la sesión abierta.
 *
 * ============================================================================
 * SE CACHEA A PROPOSITO, Y ESO ES SEGURO
 *
 * El trabajador de servicio guarda esta respuesta para que la aplicación abra
 * sin señal sabiendo quién es. No es un riesgo: la caché vive en el celular de
 * esa persona, no lleva nada secreto —ni clave, ni permiso ejecutable— y el
 * servidor no confía en ella para nada. Es un nombre para pintar en pantalla.
 */

header('Content-Type: application/json; charset=utf-8');
// `private` y no `no-store`: el trabajador de servicio tiene que poder
// guardarla para el arranque sin señal, pero ningún proxy compartido debe.
header('Cache-Control: private, max-age=300');

$u = Auth::actual();
if ($u === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'motivo' => 'sin sesión'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok'      => true,
    'id'      => (int) $u['usuario_id'],
    'usuario' => $u['usuario'],
    'nombre'  => $u['nombre'],
    'rol'     => $u['rol'],
    'zona'    => $u['zona'] ?? null,
    // Lo que la interfaz usa para decidir qué ofrecer. NO es control de acceso:
    // cada endpoint revalida por su cuenta. Sirve para no dibujar un botón que
    // va a devolver 403.
    'puede'   => [
        'crear'     => Auth::puede('ots.crear'),
        'pendiente' => Auth::puede('repuestos.pedir'),
        'novedad'   => Auth::puede('novedades.reportar'),
    ],
], JSON_UNESCAPED_UNICODE);
