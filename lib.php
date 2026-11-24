<?php
declare(strict_types=1);

const AD_CAP = 5;            // rewarded-ad unlocks per user per day
const INVITE_REWARD = 3;     // free unlocks for the inviter when a friend signs up
const PRICE = ['ep' => 5, 'pass' => 149, 'quarter' => 399];
const COOKIE = 'nt';

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
