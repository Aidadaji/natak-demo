<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** Digits only; accepts +91 or a leading 0 in front of the 10-digit number. */
function norm_phone(mixed $v): string {
    $p = preg_replace('/\D/', '', is_string($v) || is_int($v) ? (string) $v : '');
    if (strlen($p) === 12 && str_starts_with($p, '91')) $p = substr($p, 2);
    elseif (strlen($p) === 11 && $p[0] === '0') $p = substr($p, 1);
    return $p;
}
function out(array $d, int $code = 200): never { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function fail(int $code, string $msg): never { if (tx_active()) tx_rollback(); out(['error' => $msg], $code); }

$a = (string) ($_GET['a'] ?? 'state');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$in = [];
if ($post) {
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'natak') fail(403, 'Request blocked.');
    $in = json_decode((string) file_get_contents('php://input', false, null, 0, 20000), true);
    if (!is_array($in)) $in = [];
}
$readOnly = ['state', 'health', 'wallet'];
if (!$post && !in_array($a, $readOnly, true)) fail(405, 'Use POST.');

try {
    if ($a === 'health') out(['ok' => true, 'php' => PHP_VERSION, 'sqlite' => in_array('sqlite', PDO::getAvailableDrivers(), true), 'storage' => str_contains(data_dir(), '_data') ? 'inside' : 'outside', 'schema' => setting('schema')]);

    $u = current_user(true);
    $uid = (int) $u['id'];
    $show = null; $ep = 0; $extra = [];
    if (isset($in['show'])) {
        $show = is_string($in['show']) ? get_show($in['show']) : null;
        if (!$show) fail(404, 'Show not found.');
        $ep = (int) ($in['ep'] ?? 0);
    }
    $needEp = function () use (&$show, &$ep) { if (!$show || $ep < 1 || $ep > (int) $show['eps']) fail(400, 'Episode not found.'); };
    $epLabel = fn() => $show['title'] . ', episode ' . $ep;

    switch ($a) {
    case 'state':
        break;

    case 'wallet':
        coins_expire($uid);
        $q = db()->prepare('SELECT delta, reason, label, balance, created FROM coin_tx WHERE user_id=? ORDER BY id DESC LIMIT 80');
        $q->execute([$uid]);
        $extra['history'] = array_map(fn($r) => ['delta' => (int) $r['delta'], 'reason' => $r['reason'], 'label' => $r['label'], 'balance' => (int) $r['balance'], 'at' => (int) $r['created']], $q->fetchAll());
        break;

    case 'signup':
        if (!$u['is_guest']) fail(400, 'You are already signed in.');
        $name = trim(is_string($in['name'] ?? null) ? $in['name'] : '');
        $phone = norm_phone($in['phone'] ?? '');
        $pass = is_string($in['password'] ?? null) ? $in['password'] : '';
        if (mb_strlen($name) < 1 || mb_strlen($name) > 40) fail(400, 'Enter your name (up to 40 letters).');
        if (!preg_match('/^[6-9]\d{9}$/', $phone)) fail(400, 'Enter a 10-digit Indian mobile number.');
        if (strlen($pass) < 6 || strlen($pass) > 100) fail(400, 'Password needs at least 6 characters.');
        tx_begin();
        $q = db()->prepare('SELECT 1 FROM users WHERE phone=?'); $q->execute([$phone]);
        if ($q->fetchColumn()) fail(409, 'This number already has an account. Sign in instead.');
        db()->prepare('UPDATE users SET name=?, phone=?, pass_hash=?, is_guest=0 WHERE id=?')->execute([$name, $phone, password_hash($pass, PASSWORD_DEFAULT), $uid]);
        coins_grant($uid, ECON['welcome'], 'bonus', ECON['bonus_days'], 'welcome', 'Welcome bonus');
        $ref = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($in['ref'] ?? '')));
        if ($ref !== '') {     // the inviter is paid later, once this friend finishes an episode
            $q = db()->prepare('SELECT id FROM users WHERE ref_code=? AND is_guest=0 AND id<>?'); $q->execute([$ref, $uid]);
            if ($inv = $q->fetchColumn()) db()->prepare('INSERT OR IGNORE INTO referrals(inviter_id, friend_id, created, rewarded) VALUES(?,?,?,0)')->execute([$inv, $uid, now()]);
        }
        db()->prepare('DELETE FROM sessions WHERE user_id=?')->execute([$uid]);
        tx_commit();
        set_session($uid);
        break;

    case 'login':
        $phone = norm_phone($in['phone'] ?? '');
        $pass = is_string($in['password'] ?? null) ? $in['password'] : '';
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
        tx_begin();
        db()->prepare('UPDATE users SET fails=0, locked_until=0 WHERE id=?')->execute([$aid]);
        if ($u['is_guest'] && $uid !== $aid) {   // carry the guest's activity and coins into the account
            db()->prepare('INSERT OR IGNORE INTO unlocks(user_id,show_id,ep,method,created) SELECT ?,show_id,ep,method,created FROM unlocks WHERE user_id=?')->execute([$aid, $uid]);
            db()->prepare('INSERT OR IGNORE INTO likes(user_id,show_id) SELECT ?,show_id FROM likes WHERE user_id=?')->execute([$aid, $uid]);
            db()->prepare('INSERT OR IGNORE INTO saves(user_id,show_id) SELECT ?,show_id FROM saves WHERE user_id=?')->execute([$aid, $uid]);
            db()->prepare('UPDATE views SET user_id=? WHERE user_id=?')->execute([$aid, $uid]);
            db()->prepare('UPDATE shares SET user_id=? WHERE user_id=?')->execute([$aid, $uid]);
            coins_expire($uid);
            $moved = coins_total($uid);
            if ($moved > 0) {
                coins_log($uid, -$moved, 'merge', 'Moved to your account');
                db()->prepare('UPDATE coin_lots SET user_id=? WHERE user_id=? AND remaining>0')->execute([$aid, $uid]);
                coins_log($aid, $moved, 'merge', 'Coins from this phone before sign-in');
            }
            // the account takes on today's ad count from the guest, so signing in never resets the daily cap
            if ($u['ads_day'] === today()) {
                $accUsed = $acc['ads_day'] === today() ? (int) $acc['ads_used'] : 0;
                db()->prepare('UPDATE users SET ads_day=?, ads_used=? WHERE id=?')->execute([today(), min(AD_CAP, $accUsed + (int) $u['ads_used']), $aid]);
            }
            foreach (['unlocks', 'likes', 'saves', 'ads'] as $t) db()->prepare("DELETE FROM $t WHERE user_id=?")->execute([$uid]);
            db()->prepare('DELETE FROM sessions WHERE user_id=?')->execute([$uid]);
            db()->prepare('DELETE FROM users WHERE id=? AND is_guest=1')->execute([$uid]);
        }
        tx_commit();
        set_session($aid);
        $uid = $aid;
        break;

    case 'reset':        // set a new password with a one-time code issued from the admin page
        $phone = norm_phone($in['phone'] ?? '');
        $code = preg_replace('/\D/', '', is_string($in['code'] ?? null) ? $in['code'] : '');
        $pass = is_string($in['password'] ?? null) ? $in['password'] : '';
        if (strlen($pass) < 6 || strlen($pass) > 200) fail(400, 'Use a password of 6 characters or more.');
        usleep(400000);
        tx_begin();
        $q = db()->prepare('SELECT * FROM users WHERE phone=? AND is_guest=0'); $q->execute([$phone]);
        $acc = $q->fetch();
        $live = $acc && $acc['reset_hash'] && (int) $acc['reset_until'] > now();
        if (!$live || strlen($code) !== 6 || !hash_equals((string) $acc['reset_hash'], hash('sha256', $code))) {
            if ($live) {   // five wrong tries burn the code
                $f = (int) $acc['reset_fails'] + 1;
                if ($f >= 5) db()->prepare('UPDATE users SET reset_hash=NULL, reset_until=0, reset_fails=0 WHERE id=?')->execute([$acc['id']]);
                else db()->prepare('UPDATE users SET reset_fails=? WHERE id=?')->execute([$f, $acc['id']]);
            }
            tx_commit();
            out(['error' => 'That code is wrong or has expired. Ask for a new one.'], 401);
        }
        db()->prepare('UPDATE users SET pass_hash=?, reset_hash=NULL, reset_until=0, reset_fails=0, fails=0, locked_until=0 WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $acc['id']]);
        db()->prepare('DELETE FROM sessions WHERE user_id=?')->execute([$acc['id']]);   // signs the account out everywhere
        tx_commit();
        if ($uid === (int) $acc['id']) { $u = current_user(true); $uid = (int) $u['id']; }
        $extra['reset'] = true;
        break;

    case 'task_claim':
        if ($u['is_guest']) fail(401, 'Create a free account to earn task coins.');
        $tid = is_string($in['task'] ?? null) ? $in['task'] : '';
        if (!isset(TASKS[$tid])) fail(400, 'Unknown task.');
        tx_begin();
        $mine = null; foreach (tasks_for($u) as $t) if ($t['id'] === $tid) $mine = $t;
        if ($mine['claimed']) fail(409, 'Already claimed today.');
        if ($mine['have'] < $mine['need']) fail(400, 'Not finished yet: ' . $mine['have'] . ' of ' . $mine['need'] . '.');
        $c = db()->prepare('INSERT OR IGNORE INTO task_claims(user_id,task,day,created) VALUES(?,?,?,?)'); $c->execute([$uid, $tid, today(), now()]);
        if (!$c->rowCount()) fail(409, 'Already claimed today.');
        coins_grant($uid, $mine['coins'], 'bonus', ECON['bonus_days'], 'task', 'Task: ' . $mine['label']);
        tx_commit();
        $extra['earned'] = $mine['coins'];
        break;

    case 'feedback':
        $msg = trim(is_string($in['msg'] ?? null) ? $in['msg'] : '');
        $contact = trim(mb_substr(is_string($in['contact'] ?? null) ? $in['contact'] : '', 0, 120));
        if (mb_strlen($msg) < 5) fail(400, 'Please write a few words about the problem.');
        if (mb_strlen($msg) > 1000) fail(400, 'Please keep it under 1000 characters.');
        $q = db()->prepare('SELECT COUNT(*) FROM feedback WHERE user_id=? AND created>?'); $q->execute([$uid, now() - 86400]);
        if ((int) $q->fetchColumn() >= 5) fail(429, 'You have sent 5 messages today. Try again tomorrow.');
        db()->prepare('INSERT INTO feedback(user_id,name,contact,msg,created) VALUES(?,?,?,?,?)')->execute([$uid, $u['is_guest'] ? 'Guest' : $u['name'], $contact !== '' ? $contact : (string) ($u['phone'] ?? ''), $msg, now()]);
        $extra['sent'] = true;
        break;

    case 'delete_account':   // removes the account and everything tied to it; payment records are kept without the person
        if ($u['is_guest']) fail(401, 'You are not signed in.');
        $pass = is_string($in['password'] ?? null) ? $in['password'] : '';
        if (!password_verify($pass, (string) $u['pass_hash'])) { usleep(400000); fail(401, 'Wrong password.'); }
        tx_begin();
        coins_expire($uid);
        $lost = coins_total($uid);   // the wallet is written off in the ledger, so coins sold still match rupees received
        db()->prepare('UPDATE coin_lots SET remaining=0 WHERE user_id=?')->execute([$uid]);
        if ($lost > 0) coins_log($uid, -$lost, 'closed', 'Account deleted');
        foreach (['sessions', 'unlocks', 'passes', 'likes', 'saves', 'ads', 'coin_lots', 'task_claims'] as $t) db()->prepare("DELETE FROM $t WHERE user_id=?")->execute([$uid]);
        db()->prepare('UPDATE coin_tx SET user_id=0 WHERE user_id=?')->execute([$uid]);
        db()->prepare('DELETE FROM referrals WHERE friend_id=? OR inviter_id=?')->execute([$uid, $uid]);
        foreach (['views', 'shares', 'orders'] as $t) db()->prepare("UPDATE $t SET user_id=0 WHERE user_id=?")->execute([$uid]);
        db()->prepare("UPDATE feedback SET user_id=0, name='Deleted account', contact='' WHERE user_id=?")->execute([$uid]);
        db()->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
        tx_commit();
        setcookie(COOKIE, '', ['expires' => 1, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
        unset($_COOKIE[COOKIE]);
        $u = current_user(true); $uid = (int) $u['id'];
        $extra['deleted'] = true;
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
        $done = empty($in['done']) ? 0 : 1;
        tx_begin();
        $q = db()->prepare('SELECT id FROM views WHERE user_id=? AND show_id=? AND ep=? AND created>? ORDER BY id DESC LIMIT 1');
        $q->execute([$uid, $show['id'], $ep, now() - 1800]);
        $vid = $q->fetchColumn();
        if (!$vid) db()->prepare('INSERT INTO views(user_id,show_id,ep,seconds,completed,created) VALUES(?,?,?,?,?,?)')->execute([$uid, $show['id'], $ep, $secs, $done, now()]);
        else db()->prepare('UPDATE views SET seconds=MAX(seconds,?), completed=MAX(completed,?) WHERE id=?')->execute([$secs, $done, $vid]);
        if ($done && !$u['is_guest']) {   // first finished episode by an invited friend pays the inviter
            $r = db()->prepare('UPDATE referrals SET rewarded=1 WHERE friend_id=? AND rewarded=0'); $r->execute([$uid]);
            if ($r->rowCount()) {
                $q = db()->prepare('SELECT inviter_id FROM referrals WHERE friend_id=?'); $q->execute([$uid]);
                coins_grant((int) $q->fetchColumn(), ECON['invite'], 'bonus', ECON['bonus_days'], 'invite', 'A friend you invited joined');
            }
        }
        tx_commit();
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

    case 'unlock':       // spend coins on one episode
        $needEp();
        tx_begin();
        $u = user_by_id($uid);
        if (can_watch($u, $show, $ep)) fail(400, 'Episode is already open.');
        if (!coins_spend($uid, (int) $show['ep_coins'], 'unlock', 'Unlocked ' . $epLabel(), $show['id'], $ep)) fail(402, 'Not enough coins. This episode costs ' . (int) $show['ep_coins'] . '.');
        grant_unlock($uid, $show['id'], $ep, 'coins');
        tx_commit();
        $extra['spent'] = (int) $show['ep_coins'];
        break;

    case 'ad_start':
        $needEp();
        if (can_watch($u, $show, $ep)) fail(400, 'Episode is already open.');
        if (ads_left($u) < 1) fail(429, 'No ad unlocks left today. They reset at midnight.');
        db()->prepare('INSERT INTO ads(user_id,show_id,ep,started) VALUES(?,?,?,?)')->execute([$uid, $show['id'], $ep, now()]);
        break;

    case 'ad_done':      // a finished ad pays exactly one episode's price, which is spent on that episode at once
        $needEp();
        tx_begin();
        $u = user_by_id($uid);
        if (ads_left($u) < 1) fail(429, 'No ad unlocks left today.');
        if (can_watch($u, $show, $ep)) fail(400, 'Episode is already open.');
        $q = db()->prepare('SELECT id, started FROM ads WHERE user_id=? AND show_id=? AND ep=? AND done=0 ORDER BY id DESC LIMIT 1');
        $q->execute([$uid, $show['id'], $ep]);
        $ad = $q->fetch();
        if (!$ad || now() - (int) $ad['started'] < 4 || now() - (int) $ad['started'] > 300) fail(400, 'Watch the full ad to open the episode.');
        db()->prepare('UPDATE ads SET done=1 WHERE user_id=? AND show_id=? AND ep=?')->execute([$uid, $show['id'], $ep]);
        $used = $u['ads_day'] === today() ? (int) $u['ads_used'] + 1 : 1;
        db()->prepare('UPDATE users SET ads_day=?, ads_used=? WHERE id=?')->execute([today(), $used, $uid]);
        $price = (int) $show['ep_coins'];
        $bal = coins_total($uid);
        $ins = db()->prepare('INSERT INTO coin_tx(user_id,delta,reason,label,show_id,ep,balance,created) VALUES(?,?,?,?,?,?,?,?)');
        $ins->execute([$uid, $price, 'ad', 'Watched an ad', $show['id'], $ep, $bal + $price, now()]);
        $ins->execute([$uid, -$price, 'unlock', 'Unlocked ' . $epLabel() . ' with the ad', $show['id'], $ep, $bal, now()]);
        grant_unlock($uid, $show['id'], $ep, 'ad');
        tx_commit();
        $extra['earned'] = $price; $extra['spent'] = $price;
        break;

    case 'checkin':
        if ($u['is_guest']) fail(401, 'Create a free account to collect daily coins.');
        tx_begin();
        $u = user_by_id($uid);
        $c = checkin_next($u);
        if ($c['done']) fail(400, 'You have already checked in today. Come back tomorrow.');
        db()->prepare('UPDATE users SET streak=?, last_checkin=? WHERE id=?')->execute([$c['day'], today(), $uid]);
        coins_grant($uid, $c['reward'], 'bonus', ECON['bonus_days'], 'checkin', 'Daily check-in, day ' . $c['day']);
        tx_commit();
        $extra['earned'] = $c['reward'];
        break;

    case 'order':
        if ($u['is_guest']) fail(401, 'Create a free account to pay.');
        $kind = is_string($in['kind'] ?? null) ? $in['kind'] : '';
        $pack = null; $sid = null;
        if ($kind === 'coins') {
            $pack = is_string($in['pack'] ?? null) ? $in['pack'] : '';
            if (!isset(PACKS[$pack])) fail(400, 'Unknown coin pack.');
            $amount = PACKS[$pack]['rs'];
        } elseif (isset(PLANS[$kind])) {
            $amount = PLANS[$kind]['rs'];
        } elseif ($kind === 'pass') {
            if (!$show) fail(400, 'Show not found.');
            $q = db()->prepare('SELECT 1 FROM passes WHERE user_id=? AND show_id=?'); $q->execute([$uid, $show['id']]);
            if ($q->fetchColumn()) fail(400, 'You already own this show.');
            $amount = (int) $show['pass_rs']; $sid = $show['id'];
            if ($amount < 1) fail(400, 'This show has no pass.');
        } else fail(400, 'Unknown product.');
        db()->prepare('INSERT INTO orders(user_id,kind,show_id,ep,amount,status,created,pack) VALUES(?,?,?,?,?,?,?,?)')->execute([$uid, $kind, $sid, null, $amount, 'created', now(), $pack]);
        $extra = ['order' => (int) db()->lastInsertId(), 'amount' => $amount];
        break;

    case 'pay_test':   // test mode only: stands in for the payment gateway's verified callback
        if ($u['is_guest']) fail(401, 'Create a free account to pay.');
        tx_begin();
        $u = user_by_id($uid);
        $q = db()->prepare("SELECT * FROM orders WHERE id=? AND user_id=? AND status='created'");
        $q->execute([(int) ($in['order'] ?? 0), $uid]);
        $o = $q->fetch();
        if (!$o) fail(404, 'Order not found or already paid.');
        if ($o['kind'] === 'coins') {
            $p = PACKS[$o['pack']] ?? null;
            if (!$p) fail(400, 'Unknown coin pack.');
            $q = db()->prepare("SELECT COUNT(*) FROM orders WHERE user_id=? AND kind='coins' AND status='paid_test' AND created>?"); $q->execute([$uid, now() - 86400]);
            if ((int) $q->fetchColumn() >= ECON['max_packs_day']) fail(429, 'You can buy ' . ECON['max_packs_day'] . ' coin packs a day. Try again tomorrow.');
            if (coins_wallet($uid)['paid'] + $p['coins'] > ECON['max_paid_balance']) fail(400, 'Your wallet is full. Use some coins first.');
            db()->prepare("UPDATE orders SET status='paid_test' WHERE id=?")->execute([$o['id']]);
            coins_grant($uid, $p['coins'], 'paid', 0, 'purchase', 'Bought ' . $p['coins'] . ' coins for Rs ' . $p['rs']);
            coins_grant($uid, $p['bonus'], 'bonus', ECON['pack_bonus_days'], 'pack_bonus', 'Bonus coins with your pack');
            $first = 0;
            if (!(int) $u['first_bonus']) {   // one time per account
                $first = intdiv($p['coins'] * FIRST_BONUS_PCT, 100);
                db()->prepare('UPDATE users SET first_bonus=1 WHERE id=?')->execute([$uid]);
                coins_grant($uid, $first, 'bonus', ECON['pack_bonus_days'], 'first_bonus', 'First top-up bonus');
            }
            $extra['earned'] = $p['coins'] + $p['bonus'] + $first;
        } elseif ($o['kind'] === 'pass') {
            db()->prepare("UPDATE orders SET status='paid_test' WHERE id=?")->execute([$o['id']]);
            db()->prepare('INSERT OR IGNORE INTO passes(user_id,show_id,created) VALUES(?,?,?)')->execute([$uid, $o['show_id'], now()]);
        } elseif (isset(PLANS[$o['kind']])) {
            db()->prepare("UPDATE orders SET status='paid_test' WHERE id=?")->execute([$o['id']]);
            db()->prepare('UPDATE users SET vip_until=? WHERE id=?')->execute([max(now(), (int) $u['vip_until']) + PLANS[$o['kind']]['days'] * 86400, $uid]);
        } else fail(400, 'This order can no longer be paid.');
        tx_commit();
        break;

    default:
        fail(404, 'Unknown action.');
    }

    $u = user_by_id($uid);
    out(['state' => state($u)] + $extra);
} catch (Throwable $e) {
    if (tx_active()) tx_rollback();
    error_log('natak api: ' . $e->getMessage());
    out(['error' => 'Something went wrong. Please try again.'], 500);
}

function state(array $u): array {
    $uid = (int) $u['id'];
    coins_expire($uid);
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
    foreach (db()->query('SELECT show_id, ep, video_file FROM episodes WHERE video_file IS NOT NULL ORDER BY ep') as $r) if (video_path($r['video_file'])) $vids[$r['show_id']][] = (int) $r['ep'];
    $subs = [];
    foreach (db()->query('SELECT show_id, ep FROM subs ORDER BY ep') as $r) $subs[$r['show_id']][] = (int) $r['ep'];
    $titles = [];
    foreach (db()->query('SELECT show_id, ep, title FROM ep_meta') as $r) $titles[$r['show_id']][(int) $r['ep']] = $r['title'];
    $shows = [];
    foreach (db()->query('SELECT * FROM shows ORDER BY sort') as $s) {
        $id = $s['id'];
        $locked = max(0, (int) $s['eps'] - (int) $s['free_eps']);
        $coinRoute = $locked * (int) $s['ep_coins'];
        $shows[] = ['id' => $id, 'title' => $s['title'], 'genre' => $s['genre'], 'line' => $s['line'], 'eps' => (int) $s['eps'], 'free' => (int) $s['free_eps'],
            'c' => [$s['c0'], $s['c1']], 'who' => json_decode($s['who'], true), 'caps' => json_decode($s['caps'], true), 'shelf' => explode(',', (string) $s['shelf']),
            'views' => (int) ($views[$id] ?? 0), 'likes' => (int) ($likes[$id] ?? 0), 'saves' => (int) ($saves[$id] ?? 0), 'shares' => (int) ($shares[$id] ?? 0),
            'liked' => isset($liked[$id]), 'saved' => isset($saved[$id]), 'pass' => isset($pass[$id]), 'open' => $open[$id] ?? [], 'videos' => $vids[$id] ?? [], 'subs' => $subs[$id] ?? [], 'last' => (int) ($last[$id] ?? 0),
            'poster' => poster_path($id) ? 'poster.php?show=' . $id . '&v=' . filemtime(poster_path($id)) : null, 'titles' => (object) ($titles[$id] ?? []),
            'price' => (int) $s['ep_coins'], 'passRs' => (int) $s['pass_rs'],
            // the pass is only offered where it beats buying the locked episodes with coins
            'passWorth' => (int) $s['pass_rs'] > 0 && (int) $s['pass_rs'] < $coinRoute * ECON['rs_per_coin_ref']];
    }
    $c = checkin_next($u);
    $packs = []; foreach (PACKS as $id => $p) $packs[] = ['id' => $id] + $p;
    $plans = []; foreach (PLANS as $id => $p) $plans[] = ['id' => $id] + $p;
    return ['me' => ['guest' => (bool) $u['is_guest'], 'name' => $u['name'], 'vip' => (int) $u['vip_until'] > now(), 'vipUntil' => (int) $u['vip_until'],
        'firstOffer' => !(int) ($u['first_bonus'] ?? 0), 'adsLeft' => ads_left($u), 'adCap' => AD_CAP, 'ref' => $u['is_guest'] ? null : $u['ref_code'], 'coins' => coins_wallet($uid),
        'checkin' => ['done' => $c['done'], 'day' => $c['day'], 'reward' => $c['reward']]],
        'support' => (string) (setting('support') ?? ''),
        'tasks' => tasks_for($u),
        'econ' => ['packs' => $packs, 'plans' => $plans, 'firstPct' => FIRST_BONUS_PCT, 'welcome' => ECON['welcome'], 'invite' => ECON['invite'], 'ladder' => ECON['checkin'], 'bonusDays' => ECON['bonus_days'], 'packBonusDays' => ECON['pack_bonus_days']],
        'shows' => $shows];
}
