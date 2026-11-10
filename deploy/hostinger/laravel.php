<?php

/**
 * Punto de entrada de Laravel para Hostinger (hosting compartido, todo en un dominio).
 *
 * public_html/            -> frontend React (index.html, assets/) + este archivo + .htaccess
 * ../appsanzac/           -> aplicación Laravel (fuera de la carpeta pública)
 *
 * El .htaccess envía /api/* y /up a este archivo; el resto lo atiende la SPA.
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$appPath = dirname(__DIR__).'/appsanzac';

if (file_exists($maintenance = $appPath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $appPath.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $appPath.'/bootstrap/app.php';

$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
