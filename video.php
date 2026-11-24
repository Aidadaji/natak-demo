<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

// Streams an episode only to a viewer who is allowed to watch it.
$show = get_show((string) ($_GET['show'] ?? ''));
$ep = (int) ($_GET['ep'] ?? 0);
if (!$show || !can_watch(current_user(false), $show, $ep)) { http_response_code(403); exit('Locked'); }
$q = db()->prepare('SELECT video_file FROM episodes WHERE show_id=? AND ep=?');
$q->execute([$show['id'], $ep]);
$file = $q->fetchColumn();
$path = $file ? video_path((string) $file) : null;
if (!$path) { http_response_code(404); exit('No video'); }

$size = filesize($path); $start = 0; $end = $size - 1;
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
header('Content-Type: ' . ($ext === 'webm' ? 'video/webm' : 'video/mp4'));
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') { $start = max(0, $size - (int) $m[2]); }
    else { $start = (int) $m[1]; if ($m[2] !== '') $end = min((int) $m[2], $size - 1); }
    if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;
@set_time_limit(0);
while (ob_get_level()) ob_end_clean();
$fh = fopen($path, 'rb'); fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh) && !connection_aborted()) {
    $buf = fread($fh, (int) min(262144, $left));
    if ($buf === false) break;
    echo $buf; flush();
    $left -= strlen($buf);
}
fclose($fh);
