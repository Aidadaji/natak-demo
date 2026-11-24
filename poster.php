<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
$p = poster_path((string) ($_GET['show'] ?? ''));
if (!$p) { http_response_code(404); exit; }
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($p));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($p);
