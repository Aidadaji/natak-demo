<?php
declare(strict_types=1);

const AD_CAP = 5;            // rewarded-ad unlocks per user per day
const INVITE_REWARD = 3;     // free unlocks for the inviter when a friend signs up
const COOKIE = 'nt';

/* ---------- Coin economy (see ECONOMY.md for the reasoning) ---------- */
const EP_COINS_DEFAULT = 10;                 // coins to unlock one episode; each show can override
const ECON = [
    'welcome' => 20,                         // one-time bonus on creating an account (2 episodes)
    'invite' => 30,                          // to the inviter once the invited friend finishes an episode (3 episodes)
    'checkin' => [2, 2, 3, 3, 4, 4, 10],     // daily check-in ladder, day 1 to day 7, then it restarts
    'bonus_days' => 7,                       // earned coins expire after this many days
    'pack_bonus_days' => 30,                 // bonus coins that come with a pack expire after this many days
    'max_packs_day' => 5,                    // coin packs one account may buy per day
    'max_paid_balance' => 20000,             // paid coins one account may hold
    'rs_per_coin_ref' => 0.43,               // reference value of a coin (the Rs 99 pack), used to judge the show pass
];
const PACKS = [
    'p29' => ['rs' => 29, 'coins' => 60, 'bonus' => 0],
    'p99' => ['rs' => 99, 'coins' => 200, 'bonus' => 30],
    'p249' => ['rs' => 249, 'coins' => 500, 'bonus' => 125],
    'p499' => ['rs' => 499, 'coins' => 1000, 'bonus' => 400],
];
const PLANS = [
    'week' => ['rs' => 99, 'days' => 7, 'label' => '1 week'],
    'quarter' => ['rs' => 399, 'days' => 90, 'label' => '3 months'],
    'year' => ['rs' => 899, 'days' => 365, 'label' => '1 year'],
];

function data_dir(): string {
    static $d = null;
    if ($d !== null) return $d;
    $env = getenv('NATAK_DATA');
    $cands = $env ? [$env] : [dirname(__DIR__, 2) . '/natak-data', __DIR__ . '/_data'];
    foreach ($cands as $c) {
        if (!is_dir($c)) @mkdir($c, 0700, true);
        if (is_dir($c) && is_writable($c)) {
            if (!is_dir("$c/videos")) @mkdir("$c/videos", 0700, true);
            if (!file_exists("$c/.htaccess")) @file_put_contents("$c/.htaccess", "Require all denied\nDeny from all\n");
            return $d = $c;
        }
    }
    http_response_code(500);
    exit('Storage folder is not writable.');
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . data_dir() . '/natak.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL;');
    $pdo->exec('
      CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY, name TEXT, phone TEXT UNIQUE, pass_hash TEXT,
        is_guest INTEGER NOT NULL DEFAULT 1, ref_code TEXT UNIQUE, credits INTEGER NOT NULL DEFAULT 0,
        ads_day TEXT, ads_used INTEGER NOT NULL DEFAULT 0, vip_until INTEGER NOT NULL DEFAULT 0,
        fails INTEGER NOT NULL DEFAULT 0, locked_until INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL);
      CREATE TABLE IF NOT EXISTS sessions(token_hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL, created INTEGER NOT NULL);
      CREATE TABLE IF NOT EXISTS shows(id TEXT PRIMARY KEY, title TEXT, genre TEXT, line TEXT, eps INTEGER, free_eps INTEGER,
        c0 TEXT, c1 TEXT, who TEXT, caps TEXT, shelf TEXT, sort INTEGER);
      CREATE TABLE IF NOT EXISTS episodes(show_id TEXT, ep INTEGER, video_file TEXT, PRIMARY KEY(show_id, ep));
      CREATE TABLE IF NOT EXISTS unlocks(user_id INTEGER, show_id TEXT, ep INTEGER, method TEXT, created INTEGER, PRIMARY KEY(user_id, show_id, ep));
      CREATE TABLE IF NOT EXISTS passes(user_id INTEGER, show_id TEXT, created INTEGER, PRIMARY KEY(user_id, show_id));
      CREATE TABLE IF NOT EXISTS likes(user_id INTEGER, show_id TEXT, PRIMARY KEY(user_id, show_id));
      CREATE TABLE IF NOT EXISTS saves(user_id INTEGER, show_id TEXT, PRIMARY KEY(user_id, show_id));
      CREATE TABLE IF NOT EXISTS views(id INTEGER PRIMARY KEY, user_id INTEGER, show_id TEXT, ep INTEGER, seconds INTEGER DEFAULT 0, completed INTEGER DEFAULT 0, created INTEGER);
      CREATE INDEX IF NOT EXISTS views_show ON views(show_id, ep);
      CREATE INDEX IF NOT EXISTS views_user ON views(user_id, created);
      CREATE TABLE IF NOT EXISTS shares(id INTEGER PRIMARY KEY, user_id INTEGER, show_id TEXT, created INTEGER);
      CREATE TABLE IF NOT EXISTS ads(id INTEGER PRIMARY KEY, user_id INTEGER, show_id TEXT, ep INTEGER, started INTEGER, done INTEGER DEFAULT 0);
      CREATE TABLE IF NOT EXISTS orders(id INTEGER PRIMARY KEY, user_id INTEGER, kind TEXT, show_id TEXT, ep INTEGER, amount INTEGER, status TEXT, created INTEGER);
      CREATE TABLE IF NOT EXISTS referrals(inviter_id INTEGER, friend_id INTEGER UNIQUE, created INTEGER);
      CREATE TABLE IF NOT EXISTS settings(k TEXT PRIMARY KEY, v TEXT);
    ');
    if (!$pdo->query('SELECT COUNT(*) FROM shows')->fetchColumn()) seed($pdo);
    migrate($pdo);
    return $pdo;
}

function seed(PDO $pdo): void {
    $shows = [
      ['ichcha', 'Ichchadhari Ki Dulhan', 'Fantasy', 'Sold into a cursed royal family, she finds out what her husband becomes on the new moon.', 50, '#0f6b5c', '#7a1f6b',
        ['Meera', 'The bride nobody asked'],
        ['They sold me for <em>forty lakh</em>.', 'Nobody in this palace looks at the groom.', '"Do not open his door on <em>amavasya</em>."', 'Her mangalsutra starts to <em>glow</em>.', 'His eyes turn to <em>slits</em>.'], 'hidden'],
      ['naukar', 'Naukar Nahi, Malik Hoon', 'Hidden identity', 'The family driver they mock at the engagement has just bought all their debt.', 60, '#8a2a12', '#3a1552',
        ['Arjun', 'Driver, Rathore Palace Hotel'],
        ['"Drivers eat <em>outside</em>."', 'He picks the glass off the floor himself.', 'One call: "Buy the <em>whole</em> loan."', 'The bank file reaches the stage.', 'He signs the <em>foreclosure</em> in front of them.'], 'hidden,revenge'],
      ['bahu', 'Bahu Number 2', 'Comeback', 'Thrown out with her son, she returns seven years later as the company\'s new MD.', 70, '#a31f4a', '#2b1a5a',
        ['Kavya', 'The bahu they threw out'],
        ['Seven years ago they kept her <em>bangles</em>.', 'Today the board waits for the new MD.', '"Madam is here." Every chair turns.', 'Her ex-husband drops his pen.', 'Her son looks up: "<em>Papa?</em>"'], 'revenge'],
      ['janam', 'Pichhla Janam', 'Second chance', 'Killed by her best friend and fiance, she wakes up a year earlier.', 60, '#1d4f8a', '#5a1747',
        ['Riya', 'Intern, one year too early'],
        ['The last thing she saw was <em>her friend\'s smile</em>.', 'She wakes up. The calendar is wrong.', 'Same office. Same lift. Same day.', 'He walks in with the same line ready.', 'She <em>slaps</em> him before he speaks.'], 'revenge'],
      ['akhada', 'Akhada Ka Raja', 'Hidden identity', 'A quiet wrestling coach is the national champion who vanished after a scandal.', 55, '#7a5a10', '#3b1f1f',
        ['Balwan', 'Coach, village akhada'],
        ['"Old man, <em>clean the mat</em>."', 'His daughter clenches her fists.', 'An old medal falls out of the trunk.', 'The politician\'s son steps into the ring.', '"<em>Mere papa ko koi nahi hara sakta.</em>"'], 'hidden'],
    ];
    $st = $pdo->prepare('INSERT INTO shows(id,title,genre,line,eps,free_eps,c0,c1,who,caps,shelf,sort) VALUES(?,?,?,?,?,8,?,?,?,?,?,?)');
    foreach ($shows as $i => $s) $st->execute([$s[0], $s[1], $s[2], $s[3], $s[4], $s[5], $s[6], json_encode($s[7]), json_encode($s[8]), $s[9], $i]);
    // One bundled sample clip so the real video player can be tested before any upload.
    $e = $pdo->prepare('INSERT INTO episodes(show_id, ep, video_file) VALUES(?,?,?)');
    foreach ([1, 2, 3] as $n) $e->execute(['ichcha', $n, 'bundle:sample.mp4']);
}

function now(): int { return time(); }
function today(): string { return (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d'); }
function is_https(): bool { return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'; }

function set_session(int $uid): void {
    $tok = bin2hex(random_bytes(24));
    db()->prepare('INSERT INTO sessions(token_hash,user_id,created) VALUES(?,?,?)')->execute([hash('sha256', $tok), $uid, now()]);
    setcookie(COOKIE, $tok, ['expires' => now() + 86400 * 365, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    $_COOKIE[COOKIE] = $tok;
}

function ref_code(): string {
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $c = '';
        for ($i = 0; $i < 7; $i++) $c .= $abc[random_int(0, strlen($abc) - 1)];
        $q = db()->prepare('SELECT 1 FROM users WHERE ref_code=?');
        $q->execute([$c]);
    } while ($q->fetchColumn());
    return $c;
}

function current_user(bool $create = true): ?array {
    $tok = $_COOKIE[COOKIE] ?? '';
    if (is_string($tok) && preg_match('/^[a-f0-9]{48}$/', $tok)) {
        $q = db()->prepare('SELECT u.* FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token_hash=?');
        $q->execute([hash('sha256', $tok)]);
        if ($u = $q->fetch()) return $u;
    }
    if (!$create) return null;
    db()->prepare('INSERT INTO users(is_guest, ref_code, created) VALUES(1,?,?)')->execute([ref_code(), now()]);
    $id = (int) db()->lastInsertId();
    set_session($id);
    return user_by_id($id);
}

function user_by_id(int $id): array {
    $q = db()->prepare('SELECT * FROM users WHERE id=?');
    $q->execute([$id]);
    return $q->fetch();
}

function get_show(string $id): ?array {
    $q = db()->prepare('SELECT * FROM shows WHERE id=?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

function can_watch(?array $u, array $show, int $ep): bool {
    if ($ep < 1 || $ep > (int) $show['eps']) return false;
    if ($ep <= (int) $show['free_eps']) return true;
    if (!$u) return false;
    if ((int) $u['vip_until'] > now()) return true;
    $q = db()->prepare('SELECT 1 FROM passes WHERE user_id=? AND show_id=?');
    $q->execute([$u['id'], $show['id']]);
    if ($q->fetchColumn()) return true;
    $q = db()->prepare('SELECT 1 FROM unlocks WHERE user_id=? AND show_id=? AND ep=?');
    $q->execute([$u['id'], $show['id'], $ep]);
    return (bool) $q->fetchColumn();
}

function ads_left(array $u): int { return $u['ads_day'] === today() ? max(0, AD_CAP - (int) $u['ads_used']) : AD_CAP; }

function grant_unlock(int $uid, string $show, int $ep, string $method): void {
    db()->prepare('INSERT OR IGNORE INTO unlocks(user_id,show_id,ep,method,created) VALUES(?,?,?,?,?)')->execute([$uid, $show, $ep, $method, now()]);
}

function setting(string $k): ?string {
    $q = db()->prepare('SELECT v FROM settings WHERE k=?');
    $q->execute([$k]);
    $v = $q->fetchColumn();
    return $v === false ? null : (string) $v;
}
function set_setting(string $k, ?string $v): void {
    if ($v === null) db()->prepare('DELETE FROM settings WHERE k=?')->execute([$k]);
    else db()->prepare('INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute([$k, $v]);
}

function video_path(string $file): ?string {
    if (str_starts_with($file, 'bundle:')) $p = __DIR__ . '/media/' . basename(substr($file, 7));
    else $p = data_dir() . '/videos/' . basename($file);
    return is_file($p) ? $p : null;
}

/* ---------- Thornbound, season 1 (his own series; files live in his Google Drive) ---------- */
const THORNBOUND = [
  1 => ['The Ninth Assassin', '1ss7SUOcozIpdW8YRoyo1JYdjkdKV8OKF', '1g5hzK1QFG0MEVakbepxNmWc9kxx_Tpcv'],
  2 => ["Say You're Mine", '1ZCFlpnRnRH5D1XpoIkyoRxIpJaTdA-PM', '1bcKG_yBujsbujuUiW09XkbjeB_ucDyKE'],
  3 => ['The Black Mirror', '16Gr7C0ZDR3wLkWsGPtMRfi1odo1CCbC4', '1zM8Pr-r3WYCFo46fK3HOKSTzkHUWD455'],
  4 => ['The Garden of Ash', '1rBaHBvdwHyel27xEf-bIQzUY-T8S69HJ', '1BIdtPx8XBzpou_P7887AuOyXeKT5HRfN'],
  5 => ['Moon Blood', '1mxdTosICV0B9HM2sVah_WjKH0jyFJ2NF', '1lhDJo6VovPQNg8kJSY3x3aEhGiXAU8pK'],
  6 => ['The Thorn Ring', '1oDcF7l7LMT6dO_U6FsbDP2ApwJv9OqdB', '1by-1Va_JXXTcaF81wP_v70mCm1BUB_1m'],
  7 => ['Ashvale', '1i5ZBC08T_vGh_4AU5xhF5JxiNf4muIUT', '1Hgi50uJEc_NBQQKZhMIllCiMVADN-eUY'],
  8 => ["The Witch's Gift", '138j5L157NmgnPARflrUATzNIORZ2xbcB', '1RrTRvRd0glhyH0L4SRJvK4-OiKv0Dhwv'],
  9 => ['Blood on the Throne', '1z7sdhFShQHrGE_8W-oDlkDvxZnQoUj8s', '1fD0SG_awS7MgRRaF9zv45ciz8m6YXR7V'],
  10 => ['Hello, Daughter', '13Mx-TybzGaIiKkqyy2v70ag5_ica-KKY', '1wdwvR9Hmp4xX13f6oP7D4PP2Vl9MyBoP'],
];

function add_col(PDO $pdo, string $t, string $c, string $def): void {
    foreach ($pdo->query("PRAGMA table_info($t)") as $r) if ($r['name'] === $c) return;
    $pdo->exec("ALTER TABLE $t ADD COLUMN $c $def");
}

function migrate(PDO $pdo): void {
    $v = $pdo->query("SELECT v FROM settings WHERE k='schema'")->fetchColumn();
    if ($v === '3') return;
    $pdo->exec('CREATE TABLE IF NOT EXISTS ep_meta(show_id TEXT, ep INTEGER, title TEXT, PRIMARY KEY(show_id, ep))');
    $q = $pdo->prepare('SELECT 1 FROM shows WHERE id=?'); $q->execute(['thornbound']);
    if (!$q->fetchColumn()) {
        $pdo->prepare('INSERT INTO shows(id,title,genre,line,eps,free_eps,c0,c1,who,caps,shelf,sort) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
            'thornbound', 'Thornbound', 'Dark fantasy', 'She was sent to kill the Thorn King. His touch turns every living thing to ash, except her.', 10, 5, '#5a0f24', '#150a22',
            json_encode(['Lyra', 'The ninth assassin']),
            json_encode(['She slips into the <em>Thorn King\'s</em> chamber.', 'He catches her blade with his cursed hand.', 'She does not turn to <em>ash</em>.', 'The door creaks open on torchlight.', '"Tell him you\'re <em>mine</em>."']),
            'fantasy', -1]);
        $m = $pdo->prepare('INSERT OR IGNORE INTO ep_meta(show_id, ep, title) VALUES(?,?,?)');
        foreach (THORNBOUND as $n => $e) $m->execute(['thornbound', $n, $e[0]]);
    }
    // schema 3: the coin economy
    add_col($pdo, 'shows', 'ep_coins', 'INTEGER NOT NULL DEFAULT ' . EP_COINS_DEFAULT);
    add_col($pdo, 'shows', 'pass_rs', 'INTEGER NOT NULL DEFAULT 149');
    add_col($pdo, 'users', 'streak', 'INTEGER NOT NULL DEFAULT 0');
    add_col($pdo, 'users', 'last_checkin', 'TEXT');
    add_col($pdo, 'orders', 'pack', 'TEXT');
    add_col($pdo, 'referrals', 'rewarded', 'INTEGER NOT NULL DEFAULT 1');
    $pdo->exec('
      CREATE TABLE IF NOT EXISTS coin_lots(id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, kind TEXT NOT NULL, remaining INTEGER NOT NULL, expires INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL);
      CREATE INDEX IF NOT EXISTS lots_user ON coin_lots(user_id, remaining);
      CREATE TABLE IF NOT EXISTS coin_tx(id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, delta INTEGER NOT NULL, reason TEXT NOT NULL, label TEXT, show_id TEXT, ep INTEGER, balance INTEGER NOT NULL, created INTEGER NOT NULL);
      CREATE INDEX IF NOT EXISTS tx_user ON coin_tx(user_id, id);
    ');
    // free unlocks earned before coins existed become coins, one episode's worth each
    foreach ($pdo->query('SELECT id, credits FROM users WHERE credits > 0')->fetchAll() as $u) {
        coins_grant((int) $u['id'], (int) $u['credits'] * EP_COINS_DEFAULT, 'bonus', ECON['bonus_days'], 'invite', 'Free unlocks from invites, now coins');
        $pdo->prepare('UPDATE users SET credits=0 WHERE id=?')->execute([$u['id']]);
    }
    $pdo->exec("INSERT INTO settings(k,v) VALUES('schema','3') ON CONFLICT(k) DO UPDATE SET v='3'");
}

function poster_path(string $show): ?string {
    $p = data_dir() . '/posters/' . preg_replace('/[^a-z0-9_-]/', '', $show) . '.jpg';
    return is_file($p) ? $p : null;
}

/** Pull a link-shared Google Drive file straight onto this server. Returns null on success or a reason. */
function drive_fetch(string $id, string $dest, int $maxBytes): ?string {
    if (!preg_match('/^[A-Za-z0-9_-]{20,80}$/', $id)) return 'That does not look like a Google Drive file link.';
    if (!function_exists('curl_init')) return 'This hosting has no download tool (curl) switched on.';
    $fh = fopen($dest, 'wb');
    if (!$fh) return 'Could not write to storage.';
    $ch = curl_init('https://drive.usercontent.google.com/download?id=' . $id . '&export=download&confirm=t');
    curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 170, CURLOPT_USERAGENT => 'Mozilla/5.0 (natak import)',
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => function ($c, $dt, $dn) use ($maxBytes) { return $dn > $maxBytes ? 1 : 0; }]);
    $ok = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch); fclose($fh);
    if (!$ok || $code !== 200) { @unlink($dest); return $code === 404 ? 'Google Drive says the file was not found.' : ($code === 401 || $code === 403 ? 'Google Drive refused. Share the file as "Anyone with the link".' : 'Download failed (' . ($err ?: 'status ' . $code) . ').'); }
    return null;
}

/* ---------- Transactions and the coin wallet ---------- */
function tx_begin(): void { db()->exec('BEGIN IMMEDIATE'); $GLOBALS['__tx'] = true; }
function tx_commit(): void { db()->exec('COMMIT'); $GLOBALS['__tx'] = false; }
function tx_active(): bool { return !empty($GLOBALS['__tx']); }
function tx_rollback(): void { try { db()->exec('ROLLBACK'); } catch (Throwable $e) { /* nothing open */ } $GLOBALS['__tx'] = false; }

function coins_total(int $uid): int {
    $q = db()->prepare('SELECT COALESCE(SUM(remaining),0) FROM coin_lots WHERE user_id=? AND remaining>0 AND (expires=0 OR expires>?)');
    $q->execute([$uid, now()]);
    return (int) $q->fetchColumn();
}
function coins_log(int $uid, int $delta, string $reason, string $label, ?string $show = null, ?int $ep = null): void {
    db()->prepare('INSERT INTO coin_tx(user_id,delta,reason,label,show_id,ep,balance,created) VALUES(?,?,?,?,?,?,?,?)')
        ->execute([$uid, $delta, $reason, $label, $show, $ep, coins_total($uid), now()]);
}
/** Writes off bonus coins whose time has run out, and records it in the history. */
function coins_expire(int $uid): void {
    $q = db()->prepare('SELECT COALESCE(SUM(remaining),0) FROM coin_lots WHERE user_id=? AND remaining>0 AND expires>0 AND expires<=?');
    $q->execute([$uid, now()]);
    $n = (int) $q->fetchColumn();
    if ($n < 1) return;
    db()->prepare('UPDATE coin_lots SET remaining=0 WHERE user_id=? AND remaining>0 AND expires>0 AND expires<=?')->execute([$uid, now()]);
    coins_log($uid, -$n, 'expired', 'Bonus coins expired');
}
function coins_grant(int $uid, int $n, string $kind, int $days, string $reason, string $label, ?string $show = null, ?int $ep = null): void {
    if ($n < 1) return;
    db()->prepare('INSERT INTO coin_lots(user_id,kind,remaining,expires,created) VALUES(?,?,?,?,?)')->execute([$uid, $kind, $n, $days > 0 ? now() + $days * 86400 : 0, now()]);
    coins_log($uid, $n, $reason, $label, $show, $ep);
}
/** Spends bonus coins first (soonest to expire first), then paid coins. False when the wallet is short. */
function coins_spend(int $uid, int $n, string $reason, string $label, ?string $show = null, ?int $ep = null): bool {
    coins_expire($uid);
    if ($n < 1 || coins_total($uid) < $n) return false;
    $q = db()->prepare("SELECT id, remaining FROM coin_lots WHERE user_id=? AND remaining>0 ORDER BY (kind='bonus') DESC, (expires=0), expires, id");
    $q->execute([$uid]);
    $left = $n;
    $u = db()->prepare('UPDATE coin_lots SET remaining=remaining-? WHERE id=?');
    foreach ($q->fetchAll() as $lot) {
        $take = min($left, (int) $lot['remaining']);
        $u->execute([$take, $lot['id']]);
        $left -= $take;
        if ($left < 1) break;
    }
    coins_log($uid, -$n, $reason, $label, $show, $ep);
    return true;
}
function coins_wallet(int $uid): array {
    $q = db()->prepare("SELECT kind, COALESCE(SUM(remaining),0) n FROM coin_lots WHERE user_id=? AND remaining>0 AND (expires=0 OR expires>?) GROUP BY kind");
    $q->execute([$uid, now()]);
    $by = $q->fetchAll(PDO::FETCH_KEY_PAIR);
    $q = db()->prepare('SELECT expires, SUM(remaining) n FROM coin_lots WHERE user_id=? AND remaining>0 AND expires>? GROUP BY expires ORDER BY expires LIMIT 1');
    $q->execute([$uid, now()]);
    $nx = $q->fetch();
    $paid = (int) ($by['paid'] ?? 0); $bonus = (int) ($by['bonus'] ?? 0);
    return ['total' => $paid + $bonus, 'paid' => $paid, 'bonus' => $bonus, 'next' => $nx ? ['n' => (int) $nx['n'], 'at' => (int) $nx['expires']] : null];
}
function checkin_next(array $u): array {
    $ladder = ECON['checkin'];
    $yesterday = (new DateTime('yesterday', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
    $done = $u['last_checkin'] === today();
    $streak = (int) $u['streak'];
    if (!$done && $u['last_checkin'] !== $yesterday) $streak = 0;              // streak broken
    $day = $done ? $streak : ($streak % count($ladder)) + 1;                    // the day being, or just, claimed
    return ['done' => $done, 'day' => $day, 'reward' => $ladder[$day - 1] ?? $ladder[0]];
}
