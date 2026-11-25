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
            tx_begin();
            if (!setting('admin_hash')) { set_setting('admin_hash', password_hash($p, PASSWORD_DEFAULT)); tx_commit(); start_admin_session(); header('Location: admin.php'); exit; }
            tx_rollback(); $err = 'Admin was already set up.';
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
            $c = max(1, min(500, (int) ($_POST['coins'] ?? $show['ep_coins'])));
            $pr = max(0, min(5000, (int) ($_POST['pass'] ?? $show['pass_rs'])));
            db()->prepare('UPDATE shows SET free_eps=?, ep_coins=?, pass_rs=? WHERE id=?')->execute([$n, $c, $pr, $show['id']]);
            $msg = h($show['title']) . ": first $n episodes free, $c coins per locked episode, show pass Rs $pr.";
        } elseif ($act === 'resetcode') {
            $phone = preg_replace('/\D/', '', (string) ($_POST['phone'] ?? ''));
            if (strlen($phone) === 12 && str_starts_with($phone, '91')) $phone = substr($phone, 2);
            elseif (strlen($phone) === 11 && $phone[0] === '0') $phone = substr($phone, 1);
            $q = db()->prepare('SELECT id, name FROM users WHERE phone=? AND is_guest=0'); $q->execute([$phone]);
            $acc = $q->fetch();
            if (!$acc) $err = 'No account uses that mobile number.';
            else {
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                db()->prepare('UPDATE users SET reset_hash=?, reset_until=?, reset_fails=0 WHERE id=?')->execute([hash('sha256', $code), now() + 1800, $acc['id']]);
                $msg = 'Reset code for ' . h((string) $acc['name']) . ' (' . h($phone) . '): <b style="font-size:20px;letter-spacing:2px">' . $code . '</b>. Valid for 30 minutes, one use. It is shown only once. Give it only to the person who owns this number.';
            }
        } elseif ($act === 'subs') {
            $f = $_FILES['sub'] ?? null;
            if (!$show || $ep < 1 || $ep > (int) $show['eps']) $err = 'Pick a show and a valid episode number.';
            elseif (!empty($_POST['remove'])) {
                $old = sub_path($show['id'], $ep); if ($old) @unlink($old);
                db()->prepare('DELETE FROM subs WHERE show_id=? AND ep=?')->execute([$show['id'], $ep]);
                $msg = 'Subtitles removed from episode ' . $ep . '.';
            }
            elseif (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > 300000) $err = 'Choose a .vtt or .srt file under 300 KB.';
            else {
                $txt = (string) file_get_contents($f['tmp_name']);
                $txt = preg_replace('/^\xEF\xBB\xBF/', '', str_replace(["\r\n", "\r"], "\n", $txt));
                if (!mb_check_encoding($txt, 'UTF-8') || !str_contains($txt, '-->')) $err = 'That does not look like a subtitle file. Save it as UTF-8 .vtt or .srt.';
                else {
                    if (!str_starts_with(ltrim($txt), 'WEBVTT')) $txt = "WEBVTT\n\n" . preg_replace('/(\d\d:\d\d:\d\d),(\d{3})/', '$1.$2', $txt);   // .srt to .vtt
                    @mkdir(data_dir() . '/subs', 0700, true);
                    $name = bin2hex(random_bytes(10)) . '.vtt';
                    $old = sub_path($show['id'], $ep);
                    file_put_contents(data_dir() . '/subs/' . $name, $txt);
                    if ($old) @unlink($old);
                    db()->prepare('INSERT INTO subs(show_id,ep,file) VALUES(?,?,?) ON CONFLICT(show_id,ep) DO UPDATE SET file=excluded.file')->execute([$show['id'], $ep, $name]);
                    $msg = h($show['title']) . ' episode ' . $ep . ': subtitles saved.';
                }
            }
        } elseif ($act === 'fbdone') {
            db()->prepare('UPDATE feedback SET done=1 WHERE id=?')->execute([(int) ($_POST['id'] ?? 0)]);
            $msg = 'Marked as handled.';
        } elseif ($act === 'support') {
            $t = trim(mb_substr((string) ($_POST['support'] ?? ''), 0, 120));
            set_setting('support', $t === '' ? null : $t);
            $msg = $t === '' ? 'Support contact removed.' : 'Support contact saved.';
        } elseif ($act === 'delete' && $show && $ep) {
            $q = db()->prepare('SELECT video_file FROM episodes WHERE show_id=? AND ep=?'); $q->execute([$show['id'], $ep]);
            $f = (string) $q->fetchColumn();
            if ($f && !str_starts_with($f, 'bundle:')) @unlink(data_dir() . '/videos/' . basename($f));
            db()->prepare('DELETE FROM episodes WHERE show_id=? AND ep=?')->execute([$show['id'], $ep]);
            $msg = 'Video removed from episode ' . $ep . '.';
        } elseif ($act === 'import') {
            @set_time_limit(240);
            $link = trim((string) ($_POST['link'] ?? ''));
            $id = preg_match('~(?:/d/|[?&]id=)([A-Za-z0-9_-]{20,80})~', $link, $mm) ? $mm[1] : $link;
            if ($show && $show['id'] === 'thornbound' && $link === '' && isset(THORNBOUND[$ep])) $id = THORNBOUND[$ep][1];
            if (!$show || $ep < 1 || $ep > (int) $show['eps']) $err = 'Pick a show and a valid episode number.';
            else {
                $name = bin2hex(random_bytes(10)) . '.mp4';
                $dest = data_dir() . '/videos/' . $name;
                $why = drive_fetch($id, $dest, 400 * 1048576);
                if (!$why) {
                    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($dest);
                    if (!in_array($mime, ['video/mp4', 'video/webm', 'video/quicktime'], true)) { @unlink($dest); $why = str_starts_with((string) $mime, 'text/') ? 'Google Drive sent a web page, not the video. Share the file as "Anyone with the link" and try again.' : 'That file is not an MP4 or WebM video.'; }
                }
                if ($why) $err = 'Episode ' . $ep . ': ' . $why;
                else {
                    $q = db()->prepare('SELECT video_file FROM episodes WHERE show_id=? AND ep=?'); $q->execute([$show['id'], $ep]);
                    $old = (string) $q->fetchColumn();
                    if ($old && !str_starts_with($old, 'bundle:')) @unlink(data_dir() . '/videos/' . basename($old));
                    db()->prepare('INSERT INTO episodes(show_id,ep,video_file) VALUES(?,?,?) ON CONFLICT(show_id,ep) DO UPDATE SET video_file=excluded.video_file')->execute([$show['id'], $ep, $name]);
                    $msg = h($show['title']) . ' episode ' . $ep . ' imported (' . round(filesize($dest) / 1048576, 1) . ' MB).';
                    if ($show['id'] === 'thornbound' && $ep === 1 && !poster_path('thornbound')) {   // episode 1 thumbnail becomes the show poster
                        @mkdir(data_dir() . '/posters', 0700, true);
                        $pd = data_dir() . '/posters/thornbound.jpg';
                        if (drive_fetch(THORNBOUND[1][2], $pd, 5 * 1048576) || (new finfo(FILEINFO_MIME_TYPE))->file($pd) !== 'image/jpeg') @unlink($pd);
                    }
                }
            }
            if (!empty($_POST['ajax'])) { header('Content-Type: application/json'); echo json_encode(['ok' => !$err, 'msg' => $err ?: strip_tags($msg)]); exit; }
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
} catch (Throwable $e) { if (tx_active()) tx_rollback(); error_log('natak admin: ' . $e->getMessage()); $err = 'Something went wrong.'; }

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
    <div class="card tile"><b><?= (int) ($unl['coins'] ?? 0) + (int) ($unl['paid'] ?? 0) + (int) ($unl['invite'] ?? 0) ?> / <?= (int) ($unl['ad'] ?? 0) ?></b><span>Episodes unlocked with coins / with an ad</span></div>
  </div>

  <h2>Accounts: password reset</h2>
  <form class="card" method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="resetcode">
    <p class="sub">When a viewer forgets their password, check it is really them, then make a one-time code here and send it to them. They enter it under "Forgot password?" in the app.</p>
    <label>Viewer's mobile number <input name="phone" inputmode="numeric" maxlength="14" required></label>
    <button type="submit">Make reset code</button>
  </form>
  <form class="card" method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="support">
    <label>Support contact shown to viewers who forget their password (WhatsApp number or email) <input name="support" maxlength="120" value="<?= h((string) (setting('support') ?? '')) ?>"></label>
    <button type="submit">Save</button>
  </form>

  <h2>Coins</h2>
  <?php
    $tx = $by("SELECT reason, COALESCE(SUM(delta),0) FROM coin_tx GROUP BY reason");
    $sold = (int) ($tx['purchase'] ?? 0); $packBonus = (int) ($tx['pack_bonus'] ?? 0);
    $free = (int) ($tx['welcome'] ?? 0) + (int) ($tx['checkin'] ?? 0) + (int) ($tx['invite'] ?? 0) + (int) ($tx['task'] ?? 0);
    $packBonus += (int) ($tx['first_bonus'] ?? 0);
    $adCoins = (int) ($tx['ad'] ?? 0); $spent = -(int) ($tx['unlock'] ?? 0); $expired = -(int) ($tx['expired'] ?? 0) - (int) ($tx['closed'] ?? 0);
    $held = $by("SELECT kind, COALESCE(SUM(remaining),0) FROM coin_lots WHERE remaining>0 AND (expires=0 OR expires>" . now() . ") GROUP BY kind");
    $packRs = $one("SELECT COALESCE(SUM(amount),0) FROM orders WHERE status='paid_test' AND kind='coins'");
    $planRs = $one("SELECT COALESCE(SUM(amount),0) FROM orders WHERE status='paid_test' AND kind NOT IN ('coins','pass','ep')");
    $passRs = $one("SELECT COALESCE(SUM(amount),0) FROM orders WHERE status='paid_test' AND kind='pass'");
    $issued = $sold + $packBonus + $free + $adCoins;
    $heldAll = (int) ($held['paid'] ?? 0) + (int) ($held['bonus'] ?? 0);
  ?>
  <div class="tiles">
    <div class="card tile"><b><?= $sold ?></b><span>Coins sold in packs (₹<?= $packRs ?>)</span></div>
    <div class="card tile"><b><?= $packBonus ?></b><span>Bonus coins given with packs (first top-up bonus: <?= (int) ($tx['first_bonus'] ?? 0) ?>)</span></div>
    <div class="card tile"><b><?= $free ?></b><span>Free coins: welcome <?= (int) ($tx['welcome'] ?? 0) ?>, check-in <?= (int) ($tx['checkin'] ?? 0) ?>, invites <?= (int) ($tx['invite'] ?? 0) ?>, tasks <?= (int) ($tx['task'] ?? 0) ?></span></div>
    <div class="card tile"><b><?= $adCoins ?></b><span>Coins earned by watching ads</span></div>
    <div class="card tile"><b><?= $spent ?></b><span>Coins spent on episodes</span></div>
    <div class="card tile"><b><?= $expired ?></b><span>Coins that expired unused, or were given up when an account was deleted (<?= -(int) ($tx['closed'] ?? 0) ?>)</span></div>
    <div class="card tile"><b><?= (int) ($held['paid'] ?? 0) ?> + <?= (int) ($held['bonus'] ?? 0) ?></b><span>Coins still in wallets: paid + bonus</span></div>
    <div class="card tile"><b><?= $sold > 0 ? '₹' . number_format($packRs / max(1, $sold + $packBonus), 2) : 'n/a' ?></b><span>Average price received per coin</span></div>
    <div class="card tile"><b>₹<?= $planRs ?> / ₹<?= $passRs ?></b><span>Test income from plans / show passes</span></div>
  </div>
  <p class="sub" style="margin-top:8px">Check: coins issued (<?= $issued ?>) = spent (<?= $spent ?>) + expired (<?= $expired ?>) + still in wallets (<?= $heldAll ?>). <?= $issued === $spent + $expired + $heldAll ? 'The books balance.' : 'These do not add up. Tell Claude.' ?></p>
  <div class="card scroll"><table><tr><th>Coin pack</th><th>Price</th><th>Coins</th><th>Bonus</th><th>Price per coin</th><th>Bought (test)</th></tr>
  <?php $pc = $by("SELECT pack, COUNT(*) FROM orders WHERE status='paid_test' AND kind='coins' GROUP BY pack"); foreach (PACKS as $pid => $p): ?>
    <tr><td><?= h($pid) ?></td><td class="n">₹<?= $p['rs'] ?></td><td class="n"><?= $p['coins'] ?></td><td class="n"><?= $p['bonus'] ?></td><td class="n">₹<?= number_format($p['rs'] / ($p['coins'] + $p['bonus']), 2) ?></td><td class="n"><?= (int) ($pc[$pid] ?? 0) ?></td></tr>
  <?php endforeach; ?></table></div>
  <p class="sub">Free coins: <?= ECON['welcome'] ?> on sign-up, <?= implode(', ', ECON['checkin']) ?> across a 7-day check-in streak, <?= ECON['invite'] ?> per invited friend who finishes an episode. They expire after <?= ECON['bonus_days'] ?> days; pack bonus after <?= ECON['pack_bonus_days'] ?> days; paid coins never expire. Limits: <?= ECON['max_packs_day'] ?> packs a day and <?= ECON['max_paid_balance'] ?> paid coins per account, <?= AD_CAP ?> ad unlocks a day.</p>

  <h2>Shows</h2>
  <div class="card scroll"><table><tr><th>Show</th><th>Views</th><th>Finished</th><th>Likes</th><th>Saves</th><th>Shares</th><th>Free episodes / coins per episode / pass Rs</th></tr>
  <?php foreach ($shows as $s): $id = $s['id']; ?>
    <tr><td><?= h($s['title']) ?></td><td class="n"><?= (int) ($views[$id] ?? 0) ?></td><td class="n"><?= (int) ($done[$id] ?? 0) ?></td><td class="n"><?= (int) ($likes[$id] ?? 0) ?></td><td class="n"><?= (int) ($saves[$id] ?? 0) ?></td><td class="n"><?= (int) ($shares[$id] ?? 0) ?></td>
    <td><form class="in" method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="free"><input type="hidden" name="show" value="<?= h($id) ?>">
      <input aria-label="Free episodes for <?= h($s['title']) ?>" style="width:70px;padding:5px" type="number" name="free" min="0" max="<?= (int) $s['eps'] ?>" value="<?= (int) $s['free_eps'] ?>">
      <input aria-label="Coins per locked episode for <?= h($s['title']) ?>" style="width:70px;padding:5px" type="number" name="coins" min="1" max="500" value="<?= (int) $s['ep_coins'] ?>">
      <input aria-label="Show pass price in rupees for <?= h($s['title']) ?>" style="width:80px;padding:5px" type="number" name="pass" min="0" max="5000" value="<?= (int) $s['pass_rs'] ?>"> <button class="ghost" type="submit">Save</button></form></td></tr>
  <?php endforeach; ?></table></div>

  <h2>Upload an episode video</h2>
  <form class="card" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="upload">
    <div class="row"><div><label for="us">Show</label><select id="us" name="show"><?php foreach ($shows as $s): ?><option value="<?= h($s['id']) ?>"><?= h($s['title']) ?></option><?php endforeach; ?></select></div>
    <div><label for="ue">Episode number</label><input id="ue" type="number" name="ep" min="1" value="1" required></div></div>
    <label for="uf">Video file (vertical MP4 works best)</label><input id="uf" type="file" name="video" accept="video/mp4,video/webm,video/quicktime" required>
    <p class="sub" style="margin:8px 0 0">Upload limit on this hosting: <?= h(ini_get('upload_max_filesize')) ?> per file. Uploading again replaces the episode's video.</p>
    <button type="submit">Upload</button></form>

  <h2>Subtitles</h2>
  <form class="card" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="subs">
    <p class="sub" style="margin:0 0 4px">One .vtt or .srt file per episode. Viewers switch them on with the CC button. Episodes with subtitles: <?php $sb = $by('SELECT show_id, COUNT(*) FROM subs GROUP BY show_id'); echo $sb ? h(implode(', ', array_map(fn($k, $v) => "$k ($v)", array_keys($sb), $sb))) : 'none'; ?>.</p>
    <div class="row"><div><label for="ss">Show</label><select id="ss" name="show"><?php foreach ($shows as $s): ?><option value="<?= h($s['id']) ?>"><?= h($s['title']) ?></option><?php endforeach; ?></select></div>
    <div><label for="se">Episode number</label><input id="se" type="number" name="ep" min="1" value="1" required></div></div>
    <label>Subtitle file <input type="file" name="sub" accept=".vtt,.srt,text/vtt"></label>
    <label style="display:block;margin:6px 0"><input type="checkbox" name="remove" value="1"> Remove this episode's subtitles instead</label>
    <button type="submit">Save subtitles</button></form>

  <h2>Messages from viewers</h2>
  <div class="card scroll"><table><tr><th>When</th><th>From</th><th>Contact</th><th>Message</th><th></th></tr>
  <?php $fb = db()->query('SELECT * FROM feedback ORDER BY done, id DESC LIMIT 40')->fetchAll(); if (!$fb): ?><tr><td colspan="5">No messages yet.</td></tr><?php endif; foreach ($fb as $m): ?>
    <tr<?= $m['done'] ? ' style="opacity:.5"' : '' ?>><td><?= date('j M H:i', (int) $m['created']) ?></td><td><?= h((string) $m['name']) ?></td><td><?= h((string) $m['contact']) ?></td><td style="white-space:pre-wrap"><?= h((string) $m['msg']) ?></td>
    <td><?php if (!$m['done']): ?><form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="fbdone"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><button class="ghost" type="submit">Handled</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></table></div>

  <h2>Import from Google Drive</h2>
  <div class="card">
    <p class="sub" style="margin:0 0 4px">The files must be shared in Drive as "Anyone with the link". This server downloads them directly, so nothing passes through your phone.</p>
    <button type="button" id="impAll">Import all 10 Thornbound episodes</button>
    <div id="impLog" style="font-size:14px;margin-top:10px;white-space:pre-line" role="status"></div>
  </div>
  <form class="card" method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="import">
    <div class="row"><div><label for="is">Show</label><select id="is" name="show"><?php foreach ($shows as $s): ?><option value="<?= h($s['id']) ?>"><?= h($s['title']) ?></option><?php endforeach; ?></select></div>
    <div><label for="ie">Episode number</label><input id="ie" type="number" name="ep" min="1" value="1" required></div></div>
    <label for="il">Google Drive link to one video file</label><input id="il" type="url" name="link" placeholder="https://drive.google.com/file/d/.../view" required>
    <button type="submit">Import this one</button></form>
  <script>
  document.getElementById('impAll').addEventListener('click', async function () {
    var b = this, log = document.getElementById('impLog'); b.disabled = true; log.textContent = '';
    for (var ep = 1; ep <= 10; ep++) {
      log.textContent += 'Episode ' + ep + ': downloading...\n';
      var fd = new FormData(); fd.append('csrf', '<?= $csrf ?>'); fd.append('act', 'import'); fd.append('ajax', '1'); fd.append('show', 'thornbound'); fd.append('ep', ep);
      var line;
      try { var r = await fetch('admin.php', { method: 'POST', body: fd, credentials: 'same-origin' }); var d = await r.json(); line = (d.ok ? 'Done. ' : 'Failed. ') + d.msg; if (!d.ok) { log.textContent = log.textContent.replace(/[^\n]*\n$/, line + '\n') + 'Stopped. Fix the problem above and tap the button again.'; b.disabled = false; return; } }
      catch (e) { log.textContent += 'Stopped: the server did not answer. Tap the button again to retry.'; b.disabled = false; return; }
      log.textContent = log.textContent.replace(/[^\n]*\n$/, line + '\n');
    }
    log.textContent += 'All 10 imported. Reload this page to see them listed.'; b.disabled = false;
  });
  </script>

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
