<?php

/**
 * Punto de entrada de la API Laravel (todo dentro de public_html).
 *
 * public_html/
 *   index.html, assets/   -> frontend React
 *   laravel.php           -> este archivo (recibe /api/* desde .htaccess)
 *   appsanzac/            -> aplicación Laravel (bloqueada para acceso web)
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$appPath = __DIR__.'/appsanzac';

if (file_exists($maintenance = $appPath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $appPath.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $appPath.'/bootstrap/app.php';

$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
