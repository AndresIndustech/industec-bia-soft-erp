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
];
