<?php

declare(strict_types=1);

/**
 * Router pro vestavěný PHP server — vývoj a CI, ne produkce.
 *
 *   php -S 127.0.0.1:8080 api/bin/dev-server-router.php
 *
 * Zrcadlí jen ta pravidla z .htaccess / web.config, která potřebuje aplikace
 * doběhnout: /api/* jde na Slim front controller, zbytek je statika z web/dist
 * se SPA fallbackem. Bezpečnostní hlavičky, blokace citlivých adresářů ani
 * cache politiku NEŘEŠÍ — od toho je v produkci webserver.
 *
 * V CI slouží k tomu, aby doběhly integrační testy, které mluví na běžící
 * instanci přes HTTP (BearerAuthTest a spol.).
 */

$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($path, '/api/')) {
    $_SERVER['SCRIPT_NAME'] = '/api/public/index.php';
    require $root . '/api/public/index.php';
    return true;
}

$dist = $root . '/web/dist';
$candidate = realpath($dist . $path);
if ($candidate !== false
    && is_file($candidate)
    && str_starts_with($candidate, (string) realpath($dist))
) {
    return false; // statiku obslouží vestavěný server sám
}

$index = $dist . '/index.html';
if (is_file($index)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($index);
    return true;
}

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error' => ['code' => 'not_found', 'message' => 'Not found.']]);
return true;
