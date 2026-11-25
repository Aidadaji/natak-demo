<?php
// One-off: makes the server re-read the PHP files after a deploy. Removed right after use.
if (!hash_equals('4684d2fe8dd3d3f9d0c21aaf', (string) ($_GET['k'] ?? ''))) { http_response_code(404); exit; }
header('Content-Type: application/json'); header('Cache-Control: no-store');
echo json_encode(['had' => function_exists('opcache_get_status') ? (bool) @opcache_get_status(false) : null, 'reset' => function_exists('opcache_reset') ? @opcache_reset() : null,
  'api_mtime' => date('c', filemtime(__DIR__ . '/api.php')), 'api_has_tasks' => str_contains((string) file_get_contents(__DIR__ . '/api.php'), 'task_claim'), 'revalidate' => ini_get('opcache.revalidate_freq'), 'validate' => ini_get('opcache.validate_timestamps')]);
