<?php
// Copia esto a config.php y pon las credenciales reales.
// config.php NO va a git y NO se sirve por web (ver nucleo/.htaccess).
return [
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'industec_app',
    'db_user' => 'CAMBIAR',
    'db_pass' => 'CAMBIAR',

    /* Secreto compartido con la estacion, para sync_casos.php.
     * Tiene que ser EXACTAMENTE el mismo que SYNC_SECRETO en el .env de la
     * estacion. Se genera una vez con:
     *     php -r "echo bin2hex(random_bytes(32));"
     * Si falta o tiene menos de 32 caracteres, el endpoint devuelve 500 y no
     * recibe nada: preferimos que no funcione a que funcione sin proteccion. */
    'sync_secreto' => 'PEGA_AQUI_EL_SECRETO_DE_64_CARACTERES',

    /* --- La emisión (la 008 y la 009). Todas opcionales; sin ellas la app corre
     *     en modo PRUEBA: serie 9000+, franja «DOCUMENTO DE PRUEBA» y correo
     *     RETENIDO (nunca sale). Solo el corte a producción las completa (T2.16). */
    // 'emision_modo'    => 'PRUEBA',                  // 'PRODUCCION' solo con correlativos cargados y correos definidos
    // 'correo_fijos'    => ['reclutamiento@…', '…'],  // van en TODAS las órdenes emitidas
    // 'correo_por_zona' => ['UIO' => [], 'LARB' => [], 'CNLJ' => [], 'OTRA' => []],
    // 'dompdf_autoload' => '/home/<usuario>/lib/ot/vendor/autoload.php',
    // 'enlace_secreto'  => 'OTRO_SECRETO_DE_64_CARACTERES',  // firma los enlaces compartidos de PDF; si falta se usa sync_secreto
    // 'smtp_host' => 'smtp.titan.email', 'smtp_puerto' => 465, 'smtp_usuario' => '…', 'smtp_clave' => '…', 'smtp_de' => '…',
    // 'correo_tope_dia' => 900,                        // Titan corta en 1.000 por buzón y día
    // 'cdn_rangos'      => [],                         // rangos del CDN desde los que se confía en X-Forwarded-For
];
