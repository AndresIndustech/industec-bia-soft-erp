<?php
/**
 * config.php para Hostinger.
 *
 * SUBE ESTE ARCHIVO COMO `nucleo/config.php` Y COMPLETA LAS DOS CLAVES.
 * No va a git y no se sirve por web: nucleo/ tiene su propio .htaccess que
 * niega todo. PHP lo lee por ruta de sistema de archivos, no por HTTP.
 */
return [
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_name' => 'u671729428_ots',
    'db_user' => 'u671729428_ots_app',

    // 1. La contrasena que genero hPanel al crear la base.
    'db_pass' => 'PEGA-AQUI-LA-CLAVE-DE-LA-BASE',

    // 2. Una palabra cualquiera que inventes tu. Solo sirve para que nadie mas
    //    pueda correr instalar.php. Se usa asi:  instalar.php?clave=lo-que-pongas
    //    Se borra de aqui en cuanto termines de instalar.
    'clave_instalacion' => 'INVENTA-ALGO-AQUI',

    /* Secreto compartido con la estacion, para sync_casos.php.
     * Tiene que ser EXACTAMENTE el mismo que SYNC_SECRETO en el .env de la
     * estacion. Se genera una vez con:
     *     php -r "echo bin2hex(random_bytes(32));"
     * Si falta o tiene menos de 32 caracteres, el endpoint devuelve 500 y no
     * recibe nada: preferimos que no funcione a que funcione sin proteccion. */
    'sync_secreto' => 'PEGA_AQUI_EL_SECRETO_DE_64_CARACTERES',
];
