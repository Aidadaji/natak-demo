<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

// Subtitles for an episode, only for a viewer who may watch it.
$show = get_show((string) ($_GET['show'] ?? ''));
$ep = (int) ($_GET['ep'] ?? 0);
if (!$show || !can_watch(current_user(false), $show, $ep)) { http_response_code(403); exit('Locked'); }
$p = sub_path($show['id'], $ep);
if (!$p) { http_response_code(404); exit('No subtitles'); }
header('Content-Type: text/vtt; charset=utf-8');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($p);
