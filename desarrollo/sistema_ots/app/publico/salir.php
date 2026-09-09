<?php
declare(strict_types=1);
require_once __DIR__ . '/nucleo/Auth.php';
Auth::salir();
header('Location: login.php');
