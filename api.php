<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out(array $d, int $code = 200): never { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function fail(int $code, string $msg): never { out(['error' => $msg], $code); }

$a = (string) ($_GET['a'] ?? 'state');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$in = [];
if ($post) {
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'natak') fail(403, 'Request blocked.');
    $in = json_decode((string) file_get_contents('php://input', false, null, 0, 20000), true);
    if (!is_array($in)) $in = [];
}
$readOnly = ['state', 'health'];
if (!$post && !in_array($a, $readOnly, true)) fail(405, 'Use POST.');

try {
    if ($a === 'health') out(['ok' => true, 'php' => PHP_VERSION, 'sqlite' => in_array('sqlite', PDO::getAvailableDrivers(), true), 'storage' => str_contains(data_dir(), '_data') ? 'inside' : 'outside']);

    $u = current_user(true);
    $uid = (int) $u['id'];
    $show = null; $ep = 0;
    if (isset($in['show'])) {
        $show = is_string($in['show']) ? get_show($in['show']) : null;
        if (!$show) fail(404, 'Show not found.');
        $ep = (int) ($in['ep'] ?? 0);
    }
    $needEp = function () use (&$show, &$ep) { if (!$show || $ep < 1 || $ep > (int) $show['eps']) fail(400, 'Episode not found.'); };

    switch ($a) {
    case 'state':
        break;

    case 'signup':
        if (!$u['is_guest']) fail(400, 'You are already signed in.');
        $name = trim((string) ($in['name'] ?? ''));
        $phone = preg_replace('/\D/', '', (string) ($in['phone'] ?? ''));
        $pass = (string) ($in['password'] ?? '');
        if (mb_strlen($name) < 1 || mb_strlen($name) > 40) fail(400, 'Enter your name (up to 40 letters).');
        if (!preg_match('/^[6-9]\d{9}$/', $phone)) fail(400, 'Enter a 10-digit Indian mobile number.');
        if (strlen($pass) < 6 || strlen($pass) > 100) fail(400, 'Password needs at least 6 characters.');
        $q = db()->prepare('SELECT 1 FROM users WHERE phone=?'); $q->execute([$phone]);
        if ($q->fetchColumn()) fail(409, 'This number already has an account. Sign in instead.');
        db()->beginTransaction();
        db()->prepare('UPDATE users SET name=?, phone=?, pass_hash=?, is_guest=0 WHERE id=?')->execute([$name, $phone, password_hash($pass, PASSWORD_DEFAULT), $uid]);
        $ref = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($in['ref'] ?? '')));
        if ($ref !== '') {
            $q = db()->prepare('SELECT id FROM users WHERE ref_code=? AND is_guest=0 AND id<>?'); $q->execute([$ref, $uid]);
            if ($inv = $q->fetchColumn()) {
                $r = db()->prepare('INSERT OR IGNORE INTO referrals(inviter_id, friend_id, created) VALUES(?,?,?)'); $r->execute([$inv, $uid, now()]);
                if ($r->rowCount()) db()->prepare('UPDATE users SET credits=credits+? WHERE id=?')->execute([INVITE_REWARD, $inv]);
            }
        }
        db()->prepare('DELETE FROM sessions WHERE user_id=?')->execute([$uid]);
        db()->commit();
        set_session($uid);
        break;

    case 'login':
        $phone = preg_replace('/\D/', '', (string) ($in['phone'] ?? ''));
        $pass = (string) ($in['password'] ?? '');
        $q = db()->prepare('SELECT * FROM users WHERE phone=? AND is_guest=0'); $q->execute([$phone]);
        $acc = $q->fetch();
        if ($acc && (int) $acc['locked_until'] > now()) fail(429, 'Too many wrong tries. Try again in 15 minutes.');
        if (!$acc || !password_verify($pass, (string) $acc['pass_hash'])) {
            usleep(400000);
            if ($acc) {
                $f = (int) $acc['fails'] + 1;
                db()->prepare('UPDATE users SET fails=?, locked_until=? WHERE id=?')->execute([$f >= 8 ? 0 : $f, $f >= 8 ? now() + 900 : 0, $acc['id']]);
            }
            fail(401, 'Wrong mobile number or password.');
        }
        $aid = (int) $acc['id'];
        db()->beginTransaction();
        db()->prepare('UPDATE users SET fails=0, locked_until=0 WHERE id=?')->execute([$aid]);
        if ($u['is_guest'] && $uid !== $aid) {   // carry the guest's activity into the account
            db()->prepare('INSERT OR IGNORE INTO unlocks(user_id,show_id,ep,method,created) SELECT ?,show_id,ep,method,created FROM unlocks WHERE user_id=?')->execute([$aid, $uid]);
            db()->prepare('INSERT OR IGNORE INTO likes(user_id,show_id) SELECT ?,show_id FROM likes WHERE user_id=?')->execute([$aid, $uid]);
            db()->prepare('INSERT OR IGNORE INTO saves(user_id,show_id) SELECT ?,show_id FROM saves WHERE user_id=?')->execute([$aid, $uid]);
            db()->prepare('UPDATE views SET user_id=? WHERE user_id=?')->execute([$aid, $uid]);
            db()->prepare('UPDATE shares SET user_id=? WHERE user_id=?')->execute([$aid, $uid]);
            foreach (['unlocks', 'likes', 'saves', 'ads'] as $t) db()->prepare("DELETE FROM $t WHERE user_id=?")->execute([$uid]);
            db()->prepare('DELETE FROM sessions WHERE user_id=?')->execute([$uid]);
            db()->prepare('DELETE FROM users WHERE id=? AND is_guest=1')->execute([$uid]);
        }
        db()->commit();
        set_session($aid);
        $u = user_by_id($aid); $uid = $aid;
        break;

    case 'logout':
        $tok = (string) ($_COOKIE[COOKIE] ?? '');
        db()->prepare('DELETE FROM sessions WHERE token_hash=?')->execute([hash('sha256', $tok)]);
        setcookie(COOKIE, '', ['expires' => 1, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
        unset($_COOKIE[COOKIE]);
        $u = current_user(true); $uid = (int) $u['id'];
        break;

    case 'view':
        $needEp();
        if (!can_watch($u, $show, $ep)) fail(403, 'Episode is locked.');
        $secs = max(0, min(3600, (int) ($in['seconds'] ?? 0)));
        $q = db()->prepare('SELECT id FROM views WHERE user_id=? AND show_id=? AND ep=? AND created>? ORDER BY id DESC LIMIT 1');
        $q->execute([$uid, $show['id'], $ep, now() - 1800]);
        $vid = $q->fetchColumn();
        if (!$vid) db()->prepare('INSERT INTO views(user_id,show_id,ep,seconds,completed,created) VALUES(?,?,?,?,?,?)')->execute([$uid, $show['id'], $ep, $secs, empty($in['done']) ? 0 : 1, now()]);
        else db()->prepare('UPDATE views SET seconds=MAX(seconds,?), completed=MAX(completed,?) WHERE id=?')->execute([$secs, empty($in['done']) ? 0 : 1, $vid]);
        break;

    case 'like': case 'save':
        if (!$show) fail(400, 'Show not found.');
        $t = $a === 'like' ? 'likes' : 'saves';
        $d = db()->prepare("DELETE FROM $t WHERE user_id=? AND show_id=?"); $d->execute([$uid, $show['id']]);
        if (!$d->rowCount()) db()->prepare("INSERT INTO $t(user_id,show_id) VALUES(?,?)")->execute([$uid, $show['id']]);
        break;

    case 'share':
        if (!$show) fail(400, 'Show not found.');
        $q = db()->prepare('SELECT COUNT(*) FROM shares WHERE user_id=? AND created>?'); $q->execute([$uid, now() - 3600]);
        if ((int) $q->fetchColumn() < 20) db()->prepare('INSERT INTO shares(user_id,show_id,created) VALUES(?,?,?)')->execute([$uid, $show['id'], now()]);
        break;

    case 'ad_start':
        $needEp();
        if (can_watch($u, $show, $ep)) fail(400, 'Episode is already open.');
        if (ads_left($u) < 1) fail(429, 'No ad unlocks left today. They reset at midnight.');
        db()->prepare('INSERT INTO ads(user_id,show_id,ep,started) VALUES(?,?,?,?)')->execute([$uid, $show['id'], $ep, now()]);
        break;

    case 'ad_done':
        $needEp();
        if (ads_left($u) < 1) fail(429, 'No ad unlocks left today.');
        $q = db()->prepare('SELECT id, started FROM ads WHERE user_id=? AND show_id=? AND ep=? AND done=0 ORDER BY id DESC LIMIT 1');
        $q->execute([$uid, $show['id'], $ep]);
        $ad = $q->fetch();
        if (!$ad || now() - (int) $ad['started'] < 4 || now() - (int) $ad['started'] > 300) fail(400, 'Watch the full ad to open the episode.');
        db()->beginTransaction();
        db()->prepare('UPDATE ads SET done=1 WHERE id=?')->execute([$ad['id']]);
        $used = $u['ads_day'] === today() ? (int) $u['ads_used'] + 1 : 1;
        db()->prepare('UPDATE users SET ads_day=?, ads_used=? WHERE id=?')->execute([today(), $used, $uid]);
        grant_unlock($uid, $show['id'], $ep, 'ad');
        db()->commit();
        break;

    case 'use_credit':
        $needEp();
        if (can_watch($u, $show, $ep)) fail(400, 'Episode is already open.');
        $c = db()->prepare('UPDATE users SET credits=credits-1 WHERE id=? AND credits>0'); $c->execute([$uid]);
        if (!$c->rowCount()) fail(400, 'No free unlocks left.');
        grant_unlock($uid, $show['id'], $ep, 'invite');
        break;

    case 'order':
        if ($u['is_guest']) fail(401, 'Create a free account to pay.');
        $kind = (string) ($in['kind'] ?? '');
        if (!isset(PRICE[$kind])) fail(400, 'Unknown product.');
        if ($kind !== 'quarter' && !$show) fail(400, 'Show not found.');
        if ($kind === 'ep') { $needEp(); if (can_watch($u, $show, $ep)) fail(400, 'Episode is already open.'); }
        db()->prepare('INSERT INTO orders(user_id,kind,show_id,ep,amount,status,created) VALUES(?,?,?,?,?,?,?)')
            ->execute([$uid, $kind, $show['id'] ?? null, $kind === 'ep' ? $ep : null, PRICE[$kind], 'created', now()]);
        $extra = ['order' => (int) db()->lastInsertId(), 'amount' => PRICE[$kind]];
        break;

    case 'pay_test':   // test mode only: stands in for the payment gateway's verified callback
        if ($u['is_guest']) fail(401, 'Create a free account to pay.');
        $q = db()->prepare("SELECT * FROM orders WHERE id=? AND user_id=? AND status='created'");
        $q->execute([(int) ($in['order'] ?? 0), $uid]);
        $o = $q->fetch();
        if (!$o) fail(404, 'Order not found or already paid.');
        db()->beginTransaction();
        $m = db()->prepare("UPDATE orders SET status='paid_test' WHERE id=? AND status='created'"); $m->execute([$o['id']]);
        if ($m->rowCount()) {
            if ($o['kind'] === 'ep') grant_unlock($uid, $o['show_id'], (int) $o['ep'], 'paid');
            elseif ($o['kind'] === 'pass') db()->prepare('INSERT OR IGNORE INTO passes(user_id,show_id,created) VALUES(?,?,?)')->execute([$uid, $o['show_id'], now()]);
            else db()->prepare('UPDATE users SET vip_until=? WHERE id=?')->execute([max(now(), (int) $u['vip_until']) + 90 * 86400, $uid]);
        }
        db()->commit();
        break;

    default:
        fail(404, 'Unknown action.');
    }

    $u = user_by_id($uid);
    out(['state' => state($u)] + ($extra ?? []));
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('natak api: ' . $e->getMessage());
    fail(500, 'Something went wrong. Please try again.');
}

function state(array $u): array {
    $uid = (int) $u['id'];
    $col = function (string $sql, array $p = []) { $q = db()->prepare($sql); $q->execute($p); return $q->fetchAll(PDO::FETCH_KEY_PAIR); };
    $views = $col('SELECT show_id, COUNT(*) FROM views GROUP BY show_id');
    $likes = $col('SELECT show_id, COUNT(*) FROM likes GROUP BY show_id');
    $saves = $col('SELECT show_id, COUNT(*) FROM saves GROUP BY show_id');
    $shares = $col('SELECT show_id, COUNT(*) FROM shares GROUP BY show_id');
    $liked = $col('SELECT show_id, 1 FROM likes WHERE user_id=?', [$uid]);
    $saved = $col('SELECT show_id, 1 FROM saves WHERE user_id=?', [$uid]);
    $pass = $col('SELECT show_id, 1 FROM passes WHERE user_id=?', [$uid]);
    $last = $col('SELECT show_id, ep FROM views WHERE id IN (SELECT MAX(id) FROM views WHERE user_id=? GROUP BY show_id)', [$uid]);
    $open = []; $q = db()->prepare('SELECT show_id, ep FROM unlocks WHERE user_id=?'); $q->execute([$uid]);
    foreach ($q as $r) $open[$r['show_id']][] = (int) $r['ep'];
    $vids = [];
    foreach (db()->query('SELECT show_id, ep, video_file FROM episodes WHERE video_file IS NOT NULL') as $r) if (video_path($r['video_file'])) $vids[$r['show_id']][] = (int) $r['ep'];
    $shows = [];
    foreach (db()->query('SELECT * FROM shows ORDER BY sort') as $s) {
        $id = $s['id'];
        $shows[] = ['id' => $id, 'title' => $s['title'], 'genre' => $s['genre'], 'line' => $s['line'], 'eps' => (int) $s['eps'], 'free' => (int) $s['free_eps'],
            'c' => [$s['c0'], $s['c1']], 'who' => json_decode($s['who'], true), 'caps' => json_decode($s['caps'], true), 'shelf' => explode(',', (string) $s['shelf']),
            'views' => (int) ($views[$id] ?? 0), 'likes' => (int) ($likes[$id] ?? 0), 'saves' => (int) ($saves[$id] ?? 0), 'shares' => (int) ($shares[$id] ?? 0),
            'liked' => isset($liked[$id]), 'saved' => isset($saved[$id]), 'pass' => isset($pass[$id]), 'open' => $open[$id] ?? [], 'videos' => $vids[$id] ?? [], 'last' => (int) ($last[$id] ?? 0)];
    }
    return ['me' => ['guest' => (bool) $u['is_guest'], 'name' => $u['name'], 'vip' => (int) $u['vip_until'] > now(), 'vipUntil' => (int) $u['vip_until'],
        'adsLeft' => ads_left($u), 'adCap' => AD_CAP, 'credits' => (int) $u['credits'], 'ref' => $u['is_guest'] ? null : $u['ref_code']],
        'price' => PRICE, 'inviteReward' => INVITE_REWARD, 'shows' => $shows];
}
