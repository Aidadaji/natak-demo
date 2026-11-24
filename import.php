<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
// Temporary one-time importer for Thornbound season 1. Removed once the episodes are in.
header('Content-Type: application/json'); header('Cache-Control: no-store');
if (!hash_equals('49a5e757f6388fb5a0e90bfa5b8f410731d43eb53749cc1df24fa28150b16077', hash('sha256', (string) ($_GET['key'] ?? '')))) { http_response_code(404); exit('{}'); }
@set_time_limit(240);
$ep = (int) ($_GET['ep'] ?? 0);
if (!isset(THORNBOUND[$ep])) { echo json_encode(['ok' => false, 'msg' => 'bad episode']); exit; }
db();
$name = bin2hex(random_bytes(10)) . '.mp4';
$dest = data_dir() . '/videos/' . $name;
$why = drive_fetch(THORNBOUND[$ep][1], $dest, 400 * 1048576);
$mime = $why ? null : (new finfo(FILEINFO_MIME_TYPE))->file($dest);
if (!$why && $mime !== 'video/mp4') { @unlink($dest); $why = 'Drive did not send a video (got ' . $mime . '). The file is probably not shared by link.'; }
if ($why) { echo json_encode(['ok' => false, 'ep' => $ep, 'msg' => $why]); exit; }
$q = db()->prepare('SELECT video_file FROM episodes WHERE show_id=? AND ep=?'); $q->execute(['thornbound', $ep]);
$old = (string) $q->fetchColumn();
if ($old && !str_starts_with($old, 'bundle:')) @unlink(data_dir() . '/videos/' . basename($old));
db()->prepare('INSERT INTO episodes(show_id,ep,video_file) VALUES(?,?,?) ON CONFLICT(show_id,ep) DO UPDATE SET video_file=excluded.video_file')->execute(['thornbound', $ep, $name]);
$poster = 'kept';
if ($ep === 1) {
    @mkdir(data_dir() . '/posters', 0700, true);
    $pd = data_dir() . '/posters/thornbound.jpg';
    $pw = drive_fetch(THORNBOUND[1][2], $pd, 5 * 1048576);
    if ($pw || (new finfo(FILEINFO_MIME_TYPE))->file($pd) !== 'image/jpeg') { @unlink($pd); $poster = 'failed'; } else $poster = 'saved';
}
echo json_encode(['ok' => true, 'ep' => $ep, 'bytes' => filesize($dest), 'md5' => md5_file($dest), 'poster' => $poster]);
