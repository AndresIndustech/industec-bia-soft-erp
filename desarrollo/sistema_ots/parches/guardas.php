<?php
/**
 * guardas.php - Guardas minimas para submit.php del sistema de OTs.
 *
 * NO CAMBIA NADA DE LO QUE VE NI HACE EL TECNICO. Un envio legitimo desde el
 * formulario pasa por aqui sin enterarse. Lo unico que corta son tres cosas que
 * hoy el sistema acepta y no deberia.
 *
 * DONDE VA: junto a submit.php, en cada uno de los 5 modulos:
 *   ot/pruebas/ot_normal_v3/{uio,larb,cnlj}/guardas.php
 *   ot/produccion/ot_mantenimiento/guardas.php
 *   ot/pruebas/ot_normal_otros/guardas.php
 *
 * COMO SE ENGANCHA: una sola linea en submit.php, inmediatamente despues del
 *   require_once __DIR__ . '/config.php';
 * se agrega
 *   require_once __DIR__ . '/guardas.php';
 * y donde submit.php hace
 *   $zona = $campos['zona'] ?? '';
 * pasa a decir
 *   $zona = zona_valida($campos['zona'] ?? '');
 *
 * QUE ARREGLA CADA GUARDA, con el caso real que la motivo:
 *
 * 1. RECHAZO DE LO QUE NO ES POST.
 *    Hoy un GET a submit.php entra con $_POST vacio y el flujo sigue igual:
 *    incrementa el contador, genera un PDF en blanco y lo manda por correo. Se
 *    puede contar cuantas veces paso: los contadores de zona vacia
 *    (contadores/counter_.txt) marcan 27 en UIO, 31 en LARB, 14 en CNLJ y 15 en
 *    mantenimiento = 87 envios vacios. Los de UIO y LARB llevan destinatario fijo
 *    miguel.vasquez@kfc.com.ec, o sea que 58 correos en blanco ya llegaron al
 *    Jefe Tecnico de Mantenimiento de Grupo KFC. Ademas cada uno quema un
 *    correlativo, lo que explica huecos en la numeracion.
 *
 * 2. LISTA BLANCA DE ZONA.
 *    `zona` es el unico campo del formulario que submit.php NO limpia (local e
 *    idorden si pasan por preg_replace). Ese valor crudo se concatena en
 *    "counter_{$zona}.txt" y en el nombre del PDF, ambos escritos con
 *    file_put_contents. Un POST anonimo con zona=../../../algo escribe archivos
 *    fuera del directorio del modulo, incluido sobrescribir los contadores de
 *    las otras zonas -- que son la unica fuente del correlativo de toda la
 *    empresa. No da ejecucion de codigo porque la extension queda fija, pero
 *    permite destruir la numeracion desde fuera.
 *
 * 3. ESCAPE DE LAS IMAGENES EN LA PLANTILLA.
 *    La firma se valida solo mirando que el texto empiece por "data:image"
 *    (submit.php) y despues se interpola sin escapar dentro de un atributo:
 *    <img src="<?= $firma ?>">. Con Dompdf corriendo con isRemoteEnabled=true,
 *    un valor como  data:image/png;base64,AAA" ><img src="http://interno/x" x="
 *    rompe el atributo, inyecta HTML y hace que el servidor de Hostinger emita
 *    una peticion saliente. En el correctivo las fotos pasan antes por
 *    optimizarImagenDataURI(), que re-codifica y neutraliza el payload; en
 *    ot_mantenimiento NO, y ahi el vector queda abierto.
 */

// -------------------------------------------------------------------------
// 1. Solo POST. Un GET no es un envio: es un bot, un enlace compartido o un
//    reintento del navegador. Se responde 405 y se corta antes de tocar el
//    contador. 405 y no 403 porque el metodo es el problema, no el permiso.
// -------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/html; charset=UTF-8');
    exit('<!doctype html><meta charset="utf-8"><title>Metodo no permitido</title>'
       . '<p>Este formulario solo acepta envios. Abre la orden desde el enlace del formulario.</p>');
}

// -------------------------------------------------------------------------
// 2. Un POST que llego vacio tampoco es un envio. Pasa de verdad: el preventivo
//    con 7 equipos y 35 fotos puede rondar los 48 MB en base64 contra un
//    post_max_size de 64M (user.ini); si se pasa, PHP descarta $_POST ENTERO en
//    silencio y el sistema genera igual un PDF en blanco. Es el origen de los
//    OT-0011---Dia -.pdf. Aqui se detecta y se le dice al tecnico que reintente
//    con menos fotos, en vez de mandarle una orden vacia al cliente.
// -------------------------------------------------------------------------
if (empty($_POST)) {
    $excedido = isset($_SERVER['CONTENT_LENGTH'])
        && (int)$_SERVER['CONTENT_LENGTH'] > 0;
    http_response_code(400);
    header('Content-Type: text/html; charset=UTF-8');
    error_log('GUARDA: POST vacio. content_length='
        . ($_SERVER['CONTENT_LENGTH'] ?? 'n/d') . ' post_max_size=' . ini_get('post_max_size'));
    exit('<!doctype html><meta charset="utf-8"><title>Orden no enviada</title>'
       . '<h1>La orden no se envio</h1><p>'
       . ($excedido
            ? 'El envio supero el tamano maximo permitido. Vuelve a intentarlo con menos fotos o con fotos mas livianas.'
            : 'No llego ningun dato del formulario. Vuelve a abrir el formulario e intentalo de nuevo.')
       . '</p><p>No se genero ninguna orden ni se consumio ningun numero.</p>');
}

/**
 * Devuelve la zona solo si es una de las tres reales. Cualquier otra cosa
 * corta el envio en vez de seguir con una zona inventada: una OT sin zona no
 * se puede archivar, ni cruzar con SAP, ni asignar a un jefe (I-6, si no hay
 * dato se dice).
 */
function zona_valida($valor)
{
    $z = strtoupper(trim((string)$valor));
    $permitidas = ['UIO', 'LARB', 'CNLJ'];
    if (!in_array($z, $permitidas, true)) {
        http_response_code(400);
        header('Content-Type: text/html; charset=UTF-8');
        error_log('GUARDA: zona invalida recibida: ' . substr((string)$valor, 0, 120));
        exit('<!doctype html><meta charset="utf-8"><title>Zona no valida</title>'
           . '<h1>La orden no se envio</h1>'
           . '<p>El formulario no indico una zona valida. Vuelve a abrirlo desde el enlace '
           . 'de tu zona (Quito, Latacunga-Ambato-Riobamba-Banos o Cuenca-Loja).</p>');
    }
    return $z;
}

/**
 * Escapa un data URI para meterlo dentro de un atributo HTML.
 *
 * Ademas de escapar, valida la forma: solo se acepta un data URI de imagen con
 * base64 limpio. Si no lo es, devuelve cadena vacia -- y la plantilla ya sabe
 * mostrar "Sin firma" / "Sin fotos" cuando el valor viene vacio, asi que el PDF
 * sale bien formado en vez de romperse.
 */
function src_imagen_segura($valor)
{
    $v = (string)$valor;
    if (!preg_match('#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/]+={0,2}$#', $v)) {
        return '';
    }
    return htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
