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
];
