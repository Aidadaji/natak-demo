<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
const ACOOKIE = 'nta';
function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

$hash = setting('admin_hash');
$tok = (string) ($_COOKIE[ACOOKIE] ?? '');
$sess = setting('admin_session');           // "sha256(token)|expiry"
$authed = false;
if ($hash && $sess && preg_match('/^[a-f0-9]{48}$/', $tok)) {
    [$sh, $exp] = explode('|', $sess) + [null, 0];
    $authed = hash_equals((string) $sh, hash('sha256', $tok)) && (int) $exp > now();
}
$csrf = $authed ? hash_hmac('sha256', 'csrf', $tok) : '';
$msg = ''; $err = '';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$act = (string) ($_POST['act'] ?? '');

function start_admin_session(): void {
    $t = bin2hex(random_bytes(24));
    set_setting('admin_session', hash('sha256', $t) . '|' . (now() + 43200));
    setcookie(ACOOKIE, $t, ['expires' => now() + 43200, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']);
}

try {
    if ($post && !$hash && $act === 'setup') {
        $p = (string) ($_POST['password'] ?? '');
        if (strlen($p) < 10) $err = 'Use at least 10 characters.';
        elseif ($p !== (string) ($_POST['password2'] ?? '')) $err = 'The two passwords do not match.';
        else {
            db()->beginTransaction();
            if (!setting('admin_hash')) { set_setting('admin_hash', password_hash($p, PASSWORD_DEFAULT)); db()->commit(); start_admin_session(); header('Location: admin.php'); exit; }
            db()->rollBack(); $err = 'Admin was already set up.';
        }
    } elseif ($post && $hash && !$authed && $act === 'login') {
        $lock = (int) (setting('admin_lock') ?? 0);
        if ($lock > now()) $err = 'Too many wrong tries. Wait 15 minutes.';
        elseif (password_verify((string) ($_POST['password'] ?? ''), $hash)) { set_setting('admin_fails', '0'); start_admin_session(); header('Location: admin.php'); exit; }
        else {
            usleep(500000);
            $f = (int) (setting('admin_fails') ?? 0) + 1;
            if ($f >= 6) { set_setting('admin_lock', (string) (now() + 900)); $f = 0; }
            set_setting('admin_fails', (string) $f);
            $err = 'Wrong password.';
        }
    } elseif ($post && $authed) {
        if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Form expired. Go back and reload.'); }
        $show = get_show((string) ($_POST['show'] ?? ''));
        $ep = (int) ($_POST['ep'] ?? 0);
        if ($act === 'logout') { set_setting('admin_session', null); header('Location: admin.php'); exit; }
        elseif ($act === 'free' && $show) {
            $n = max(0, min((int) $show['eps'], (int) ($_POST['free'] ?? 8)));
            db()->prepare('UPDATE shows SET free_eps=? WHERE id=?')->execute([$n, $show['id']]);
            $msg = h($show['title']) . ": first $n episodes are now free.";
        } elseif ($act === 'delete' && $show && $ep) {
            $q = db()->prepare('SELECT video_file FROM episodes WHERE show_id=? AND ep=?'); $q->execute([$show['id'], $ep]);
            $f = (string) $q->fetchColumn();
            if ($f && !str_starts_with($f, 'bundle:')) @unlink(data_dir() . '/videos/' . basename($f));
            db()->prepare('DELETE FROM episodes WHERE show_id=? AND ep=?')->execute([$show['id'], $ep]);
            $msg = 'Video removed from episode ' . $ep . '.';
        } elseif ($act === 'upload') {
            $f = $_FILES['video'] ?? null;
            if (!$show || $ep < 1 || $ep > (int) $show['eps']) $err = 'Pick a show and a valid episode number.';
            elseif (!$f || $f['error'] !== UPLOAD_ERR_OK) $err = ($f && in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) ? 'File is larger than the upload limit shown below.' : 'No file received. Try again.';
            else {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
                $extMap = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mp4'];
                if (!isset($extMap[$mime])) $err = 'Upload an MP4 or WebM video. This file looks like ' . h((string) $mime) . '.';
                else {
                    $name = bin2hex(random_bytes(10)) . '.' . $extMap[$mime];
                    if (!move_uploaded_file($f['tmp_name'], data_dir() . '/videos/' . $name)) $err = 'Could not save the file.';
                    else {
                        $q = db()->prepare('SELECT video_file FROM episodes WHERE show_id=? AND ep=?'); $q->execute([$show['id'], $ep]);
                        $old = (string) $q->fetchColumn();
                        if ($old && !str_starts_with($old, 'bundle:')) @unlink(data_dir() . '/videos/' . basename($old));
                        db()->prepare('INSERT INTO episodes(show_id,ep,video_file) VALUES(?,?,?) ON CONFLICT(show_id,ep) DO UPDATE SET video_file=excluded.video_file')->execute([$show['id'], $ep, $name]);
                        $msg = h($show['title']) . ' episode ' . $ep . ' uploaded (' . round($f['size'] / 1048576, 1) . ' MB).';
                    }
                }
            }
        }
    }
} catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); error_log('natak admin: ' . $e->getMessage()); $err = 'Something went wrong.'; }

$one = fn(string $sql) => (int) db()->query($sql)->fetchColumn();
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Natak admin</title>
<style>
:root{color-scheme:dark;--night:#1b1026;--velvet:#2a1a3a;--line:#4a3560;--paper:#f6ecdf;--dust:#b9a6c4;--marigold:#f5a524;--sindoor:#d8344a;--leaf:#3fbf8a}
*{box-sizing:border-box}body{margin:0;background:var(--night);color:var(--paper);font:16px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif}
main{max-width:860px;margin:0 auto;padding:20px 16px 60px}h1{font-size:24px;margin:0 0 4px}h2{font-size:17px;margin:28px 0 10px}
p.sub{color:var(--dust);margin:0 0 16px;font-size:14px}
.card{background:var(--velvet);border:1px solid var(--line);border-radius:12px;padding:14px;margin-bottom:12px}
label{display:block;font-size:13px;color:var(--dust);margin:10px 0 4px}
input,select{width:100%;padding:10px;border-radius:8px;border:1px solid var(--line);background:#1b1026;color:var(--paper);font:inherit}
button{font:inherit;font-weight:700;border:0;border-radius:999px;padding:10px 18px;background:var(--marigold);color:#1b1026;cursor:pointer;margin-top:12px}
button.ghost{background:none;color:var(--dust);border:1px solid var(--line);font-weight:500;padding:5px 12px;margin:0}
.ok{border-color:var(--leaf);color:var(--leaf)}.bad{border-color:var(--sindoor);color:#ff9aa8}
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px}.tile b{display:block;font-size:24px;font-variant-numeric:tabular-nums}.tile span{font-size:13px;color:var(--dust)}
.scroll{overflow-x:auto}table{border-collapse:collapse;width:100%;font-size:14px}th,td{text-align:left;padding:7px 10px 7px 0;border-bottom:1px solid var(--line);white-space:nowrap}th{color:var(--dust);font-weight:500}td.n{font-variant-numeric:tabular-nums}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}.row>div{flex:1;min-width:120px}form.in{display:inline}
.eps{display:flex;gap:6px;flex-wrap:wrap}.eps form{display:inline-flex;align-items:center;gap:4px;border:1px solid var(--line);border-radius:999px;padding:2px 4px 2px 10px;font-size:13px}
.eps button{margin:0;padding:2px 8px;background:none;color:var(--sindoor);font-weight:700}
a{color:var(--marigold)}
</style></head><body><main>
<h1>Natak admin</h1>
<?php if ($msg): ?><div class="card ok"><?= $msg ?></div><?php endif; if ($err): ?><div class="card bad"><?= h($err) ?></div><?php endif; ?>

<?php if (!$hash): ?>
  <p class="sub">First visit. Choose the admin password. This page locks to that password straight away.</p>
  <form class="card" method="post" autocomplete="off"><input type="hidden" name="act" value="setup">
    <label for="p1">New admin password (10 characters or more)</label><input id="p1" type="password" name="password" minlength="10" required autocomplete="new-password">
    <label for="p2">Type it again</label><input id="p2" type="password" name="password2" minlength="10" required autocomplete="new-password">
    <button type="submit">Set password</button></form>

<?php elseif (!$authed): ?>
  <p class="sub">Sign in to manage shows and videos.</p>
  <form class="card" method="post"><input type="hidden" name="act" value="login">
    <label for="p">Admin password</label><input id="p" type="password" name="password" required autocomplete="current-password">
    <button type="submit">Sign in</button></form>

<?php else:
  $shows = db()->query('SELECT * FROM shows ORDER BY sort')->fetchAll();
  $by = fn(string $sql) => db()->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR);
  $views = $by('SELECT show_id, COUNT(*) FROM views GROUP BY show_id'); $done = $by('SELECT show_id, SUM(completed) FROM views GROUP BY show_id');
  $likes = $by('SELECT show_id, COUNT(*) FROM likes GROUP BY show_id'); $saves = $by('SELECT show_id, COUNT(*) FROM saves GROUP BY show_id');
  $shares = $by('SELECT show_id, COUNT(*) FROM shares GROUP BY show_id'); $unl = $by('SELECT method, COUNT(*) FROM unlocks GROUP BY method');
  $vids = []; foreach (db()->query('SELECT show_id, ep, video_file FROM episodes ORDER BY ep') as $r) $vids[$r['show_id']][] = $r;
?>
  <p class="sub">Test build. Payments are test orders, no real money. <a href="./">Open the app</a></p>
  <div class="tiles">
    <div class="card tile"><b><?= $one('SELECT COUNT(*) FROM users WHERE is_guest=0') ?></b><span>Accounts</span></div>
    <div class="card tile"><b><?= $one('SELECT COUNT(*) FROM users WHERE is_guest=1') ?></b><span>Guests</span></div>
    <div class="card tile"><b><?= $one('SELECT COUNT(*) FROM views') ?></b><span>Episode views</span></div>
    <div class="card tile"><b><?= $one('SELECT COUNT(*) FROM referrals') ?></b><span>Friends joined by invite</span></div>
    <div class="card tile"><b>₹<?= $one("SELECT COALESCE(SUM(amount),0) FROM orders WHERE status='paid_test'") ?></b><span>Test payments (<?= $one("SELECT COUNT(*) FROM orders WHERE status='paid_test'") ?>)</span></div>
    <div class="card tile"><b><?= (int) ($unl['ad'] ?? 0) ?> / <?= (int) ($unl['paid'] ?? 0) ?> / <?= (int) ($unl['invite'] ?? 0) ?></b><span>Unlocks by ad / paid / invite</span></div>
  </div>

  <h2>Shows</h2>
  <div class="card scroll"><table><tr><th>Show</th><th>Views</th><th>Finished</th><th>Likes</th><th>Saves</th><th>Shares</th><th>Free episodes</th></tr>
  <?php foreach ($shows as $s): $id = $s['id']; ?>
    <tr><td><?= h($s['title']) ?></td><td class="n"><?= (int) ($views[$id] ?? 0) ?></td><td class="n"><?= (int) ($done[$id] ?? 0) ?></td><td class="n"><?= (int) ($likes[$id] ?? 0) ?></td><td class="n"><?= (int) ($saves[$id] ?? 0) ?></td><td class="n"><?= (int) ($shares[$id] ?? 0) ?></td>
    <td><form class="in" method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="free"><input type="hidden" name="show" value="<?= h($id) ?>">
      <input aria-label="Free episodes for <?= h($s['title']) ?>" style="width:70px;padding:5px" type="number" name="free" min="0" max="<?= (int) $s['eps'] ?>" value="<?= (int) $s['free_eps'] ?>"> <button class="ghost" type="submit">Save</button></form></td></tr>
  <?php endforeach; ?></table></div>

  <h2>Upload an episode video</h2>
  <form class="card" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="upload">
    <div class="row"><div><label for="us">Show</label><select id="us" name="show"><?php foreach ($shows as $s): ?><option value="<?= h($s['id']) ?>"><?= h($s['title']) ?></option><?php endforeach; ?></select></div>
    <div><label for="ue">Episode number</label><input id="ue" type="number" name="ep" min="1" value="1" required></div></div>
    <label for="uf">Video file (vertical MP4 works best)</label><input id="uf" type="file" name="video" accept="video/mp4,video/webm,video/quicktime" required>
    <p class="sub" style="margin:8px 0 0">Upload limit on this hosting: <?= h(ini_get('upload_max_filesize')) ?> per file. Uploading again replaces the episode's video.</p>
    <button type="submit">Upload</button></form>

  <h2>Episodes that have a video</h2>
  <?php foreach ($shows as $s): if (empty($vids[$s['id']])) continue; ?>
    <div class="card"><b><?= h($s['title']) ?></b><div class="eps" style="margin-top:8px">
    <?php foreach ($vids[$s['id']] as $v): ?>
      <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="delete"><input type="hidden" name="show" value="<?= h($s['id']) ?>"><input type="hidden" name="ep" value="<?= (int) $v['ep'] ?>">
        Ep <?= (int) $v['ep'] ?><?= str_starts_with((string) $v['video_file'], 'bundle:') ? ' (sample)' : '' ?> <button type="submit" aria-label="Remove video from episode <?= (int) $v['ep'] ?>">&times;</button></form>
    <?php endforeach; ?></div></div>
  <?php endforeach; ?>

  <form method="post" style="margin-top:24px"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="logout"><button class="ghost" type="submit">Sign out</button></form>
<?php endif; ?>
</main></body></html>
