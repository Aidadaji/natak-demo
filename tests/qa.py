"""QA suite for the Natak app: registration, coins arithmetic, unlocks, payments, limits, races, admin.
Run against a local PHP server. Writes a results table to stdout and to TESTCASES.md."""
import json, urllib.request, urllib.parse, http.cookiejar, time, sys, sqlite3, re, threading, os
B = 'http://127.0.0.1:8765/'
DB = '/home/claude/t/data/natak.sqlite'
results = []          # (area, id, what, expected, ok, info)
area = ['']
def sec(name): area[0] = name
def chk(what, expected, cond, info=''):
    results.append((area[0], len(results) + 1, what, expected, bool(cond), '' if cond else str(info)[:160]))

class C:
    def __init__(s):
        s.jar = http.cookiejar.CookieJar(); s.o = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(s.jar))
    def call(s, a, body=None, hdr=True, raw=False, headers=None, path=None):
        h = {'Content-Type': 'application/json'}
        if hdr: h['X-Requested-With'] = 'natak'
        if headers: h.update(headers)
        r = urllib.request.Request(B + (path or 'api.php?a=' + a), data=json.dumps(body).encode() if body is not None else None, headers=h)
        try:
            x = s.o.open(r); code = x.status; d = x.read(); hd = x.headers
        except urllib.error.HTTPError as e:
            code = e.code; d = e.read(); hd = e.headers
        if raw: return code, d, hd
        try: return code, json.loads(d)
        except Exception: return code, {'raw': d[:200]}
    def st(s): return s.call('state')[1]['state']
    def coins(s): return s.st()['me']['coins']
    def hist(s): return s.call('wallet')[1]['history']
def sh(st, i): return [x for x in st['shows'] if x['id'] == i][0]
def db(sql, args=()):
    con = sqlite3.connect(DB, timeout=10); cur = con.execute(sql, args); rows = cur.fetchall(); con.commit(); con.close(); return rows
def uid_of(phone): return db('SELECT id FROM users WHERE phone=?', (phone,))[0][0]
def signup(c, name, phone, pw='secret12', ref=None):
    b = {'name': name, 'phone': phone, 'password': pw}
    if ref: b['ref'] = ref
    return c.call('signup', b)
def buy(c, kind, **kw):
    code, d = c.call('order', dict(kind=kind, **kw))
    if code != 200: return code, d
    return c.call('pay_test', {'order': d['order']})
def ad(c, show, ep):
    c.call('ad_start', {'show': show, 'ep': ep}); time.sleep(4.2); return c.call('ad_done', {'show': show, 'ep': ep})

T = 'thornbound'

# ---------------------------------------------------------------- 1. Guest and registration
sec('1. Guest and registration')
a = C(); c, d = a.call('health'); chk('Health check', 'ok, SQLite on, data outside web folder, schema 3', c == 200 and d['sqlite'] and d['storage'] == 'outside' and d['schema'] == '3', d)
s = a.st(); chk('First visit creates a guest', 'guest, 0 coins, 6 shows, Thornbound first', s['me']['guest'] and s['me']['coins']['total'] == 0 and len(s['shows']) == 6 and s['shows'][0]['id'] == T, s['me'])
chk('Thornbound set-up', '10 episodes, 5 free, 10 coins per locked episode, no show pass offered', sh(s, T)['eps'] == 10 and sh(s, T)['free'] == 5 and sh(s, T)['price'] == 10 and not sh(s, T)['passWorth'], sh(s, T))
chk('Long show offers the pass', 'Ichchadhari: 42 locked x 10 coins is worth more than Rs 149, so pass is offered', sh(s, 'ichcha')['passWorth'])
for bad, why in [({'name': '', 'phone': '9876543210', 'password': 'secret1'}, 'empty name'), ({'name': 'x' * 41, 'phone': '9876543210', 'password': 'secret1'}, 'name over 40'),
                 ({'name': 'A', 'phone': '12345', 'password': 'secret1'}, 'short number'), ({'name': 'A', 'phone': '5876543210', 'password': 'secret1'}, 'number starting with 5'),
                 ({'name': 'A', 'phone': 'abcdefghij', 'password': 'secret1'}, 'letters as number'), ({'name': 'A', 'phone': '98765432101', 'password': 'secret1'}, '11 digits'),
                 ({'name': 'A', 'phone': '9876543210', 'password': '123'}, 'short password'), ({'name': 'A', 'phone': '9876543210', 'password': 'x' * 101}, 'password over 100'),
                 ({'name': ['a'], 'phone': {'x': 1}, 'password': 5}, 'wrong data types'), ({}, 'empty form')]:
    c, d = a.call('signup', bad); chk('Sign-up rejected: ' + why, '400 with a message, no account made', c == 400 and 'error' in d, (c, d))
chk('No account from rejected sign-ups', 'still a guest with 0 coins', a.st()['me']['guest'] and a.coins()['total'] == 0)
c, d = signup(a, 'Asha <b>', '+91 98765 43210'); chk('Sign-up with +91 and spaces', 'account made, name kept as typed, 20 welcome coins', c == 200 and not d['state']['me']['guest'] and d['state']['me']['coins'] == {'total': 20, 'paid': 0, 'bonus': 20, 'next': d['state']['me']['coins']['next']}, d.get('state', d).get('me') if isinstance(d.get('state'), dict) else d)
nx = d['state']['me']['coins']['next']; chk('Welcome coins expire in 7 days', '20 coins, expiry 7 days from now', nx and nx['n'] == 20 and abs(nx['at'] - time.time() - 7 * 86400) < 60, nx)
h = a.hist(); chk('History shows the welcome bonus', 'one line: +20, balance 20', len(h) == 1 and h[0]['delta'] == 20 and h[0]['balance'] == 20 and h[0]['reason'] == 'welcome', h)
c, d = signup(a, 'Again', '9876543211'); chk('Second sign-up on a signed-in session', '400, no extra coins', c == 400 and a.coins()['total'] == 20)
b = C(); b.st(); c, d = signup(b, 'Dup', '09876543210'); chk('Same number again (typed with leading 0)', '409 number already has an account', c == 409, (c, d))
c, d = a.call('logout', {}); chk('Sign out', 'back to a guest with 0 coins', d['state']['me']['guest'] and d['state']['me']['coins']['total'] == 0)
c, d = a.call('login', {'phone': '9876543210', 'password': 'wrong'}); chk('Sign-in with wrong password', '401', c == 401)
c, d = a.call('login', {'phone': '919876543210', 'password': 'secret12'}); chk('Sign-in with 91 prefix', 'signed in, 20 coins still there', c == 200 and d['state']['me']['name'] == 'Asha <b>' and d['state']['me']['coins']['total'] == 20, d)
z = C(); z.st()
for i in range(8): z.call('login', {'phone': '9876543210', 'password': 'x'})
c, d = z.call('login', {'phone': '9876543210', 'password': 'secret12'}); chk('Eight wrong passwords lock the account', '429 for 15 minutes even with the right password', c == 429, c)
db('UPDATE users SET locked_until=0, fails=0')
c, d = a.call('like', {'show': T}, hdr=False); chk('Request without the app header', '403', c == 403)
c, d = a.call('unlock'); chk('Write action sent as GET', '405', c == 405)

# ---------------------------------------------------------------- 2. Spending coins on episodes
sec('2. Unlocking episodes with coins')
A = uid_of('9876543210')
c, b_, h_ = a.call('', raw=True, path='video.php?show=thornbound&ep=5'); chk('Free episode 5 streams', 'not blocked (404 here only because no file in the test copy)', c in (200, 404), c)
c, b_, h_ = a.call('', raw=True, path='video.php?show=thornbound&ep=6'); chk('Locked episode 6 does not stream', '403', c == 403, c)
c, d = a.call('view', {'show': T, 'ep': 6, 'seconds': 3}); chk('Locked episode cannot be counted as watched', '403', c == 403)
c, d = a.call('unlock', {'show': T, 'ep': 3}); chk('Unlock on a free episode', '400, no coins taken', c == 400 and a.coins()['total'] == 20)
c, d = a.call('unlock', {'show': T, 'ep': 6}); chk('Unlock episode 6 with coins', '20 - 10 = 10 coins left, episode opened', c == 200 and d['state']['me']['coins']['total'] == 10 and 6 in sh(d['state'], T)['open'] and d['spent'] == 10, d)
c, b_, h_ = a.call('', raw=True, path='video.php?show=thornbound&ep=6'); chk('Episode 6 now allowed to stream', 'no longer 403', c != 403, c)
c, d = a.call('unlock', {'show': T, 'ep': 6}); chk('Unlock the same episode twice', '400, balance stays 10', c == 400 and a.coins()['total'] == 10)
c, d = a.call('unlock', {'show': T, 'ep': 7}); chk('Unlock episode 7', '10 - 10 = 0 coins left', c == 200 and d['state']['me']['coins']['total'] == 0)
c, d = a.call('unlock', {'show': T, 'ep': 8}); chk('Unlock with 0 coins', '402 not enough coins, nothing opened', c == 402 and 8 not in sh(a.st(), T)['open'], (c, d))
for bad in [{'show': T, 'ep': 0}, {'show': T, 'ep': 11}, {'show': T, 'ep': -3}, {'show': T, 'ep': 'x'}, {'show': 'nope', 'ep': 6}, {'show': ['a'], 'ep': 6}, {}]:
    c, d = a.call('unlock', bad); chk('Unlock with bad input ' + json.dumps(bad), '400 or 404, no charge', c in (400, 404) and a.coins()['total'] == 0, (c, d))
h = a.hist(); chk('History lists both unlocks with running balance', '-10 (=10) then -10 (=0), newest first', [(x['delta'], x['balance']) for x in h[:3]] == [(-10, 0), (-10, 10), (20, 20)], h[:3])

# ---------------------------------------------------------------- 3. Earning coins
sec('3. Earning coins')
g = C(); g.st(); c, d = g.call('checkin', {}); chk('Guest tries the daily check-in', '401 create an account first', c == 401)
c, d = a.call('checkin', {}); chk('Check-in day 1', '+2 coins, balance 2', c == 200 and d['earned'] == 2 and d['state']['me']['coins']['total'] == 2 and d['state']['me']['checkin']['done'], d)
c, d = a.call('checkin', {}); chk('Second check-in the same day', '400, balance stays 2', c == 400 and a.coins()['total'] == 2)
ladder = [2, 2, 3, 3, 4, 4, 10]; total = 2; ok = True; got = [2]
import datetime
ist = datetime.timezone(datetime.timedelta(hours=5, minutes=30))
yday = (datetime.datetime.now(ist) - datetime.timedelta(days=1)).strftime('%Y-%m-%d')
for dayn in range(2, 9):
    db('UPDATE users SET last_checkin=? WHERE id=?', (yday, A))
    c, d = a.call('checkin', {}); got.append(d.get('earned')); total += d.get('earned', 0)
chk('Seven-day streak pays the ladder, then restarts', '2,2,3,3,4,4,10 then day 8 pays 2 (total 30)', got == ladder + [2] and a.coins()['total'] == 30, (got, a.coins()))
old = (datetime.datetime.now(ist) - datetime.timedelta(days=3)).strftime('%Y-%m-%d')
db('UPDATE users SET last_checkin=?, streak=5 WHERE id=?', (old, A))
chk('Missed days: the app shows day 1 again', 'next check-in is day 1 worth 2', a.st()['me']['checkin'] == {'done': False, 'day': 1, 'reward': 2}, a.st()['me']['checkin'])
c, d = a.call('checkin', {}); chk('Check-in after a broken streak', '+2 (day 1), balance 32', d.get('earned') == 2 and a.coins()['total'] == 32, d)
# ads
c, d = a.call('ad_done', {'show': T, 'ep': 8}); chk('Claiming an ad that was never started', '400', c == 400)
a.call('ad_start', {'show': T, 'ep': 8}); c, d = a.call('ad_done', {'show': T, 'ep': 8}); chk('Claiming an ad before it finished', '400, episode stays locked', c == 400 and 8 not in sh(a.st(), T)['open'])
time.sleep(4.2); c, d = a.call('ad_done', {'show': T, 'ep': 8}); chk('Finished ad', '+10 earned, -10 spent, balance unchanged at 32, episode 8 open, 4 ads left', c == 200 and d['earned'] == 10 and d['spent'] == 10 and d['state']['me']['coins']['total'] == 32 and 8 in sh(d['state'], T)['open'] and d['state']['me']['adsLeft'] == 4, d)
c, d = a.call('ad_done', {'show': T, 'ep': 8}); chk('Claiming the same ad again', '400', c == 400)
h = a.hist(); chk('History shows the ad as +10 then -10', 'two lines, balances 42 then 32', [(x['delta'], x['reason'], x['balance']) for x in h[:2]] == [(-10, 'unlock', 32), (10, 'ad', 42)], h[:2])
c, d = a.call('ad_start', {'show': T, 'ep': 2}); chk('Ad on a free episode', '400', c == 400)
gg = C(); gg.st(); n = 0
for e in range(9, 15):
    c, d = gg.call('ad_start', {'show': 'ichcha', 'ep': e})
    if c == 429: break
    time.sleep(4.1); c, d = gg.call('ad_done', {'show': 'ichcha', 'ep': e}); n += 1 if c == 200 else 0
chk('Daily ad limit (guest)', 'exactly 5 ad unlocks, the 6th is refused, coins stay 0', n == 5 and c == 429 and gg.coins()['total'] == 0, (n, c))
# guest who used ads signs in: cap carries over
c, d = gg.call('login', {'phone': '9876543210', 'password': 'secret12'}); chk('Guest with 5 ads used signs in', 'account keeps the ad count (0 left) and gains the 5 unlocked episodes', c == 200 and d['state']['me']['adsLeft'] == 0 and set([9, 10, 11, 12, 13]) <= set(sh(d['state'], 'ichcha')['open']), d['state']['me'])
db("UPDATE users SET ads_used=1 WHERE id=?", (A,))
# invites
ref = a.st()['me']['ref']
f = C(); f.st(); c, d = signup(f, 'Friend', '9123456780', ref=ref.lower()); chk('Friend signs up from the invite link', 'friend gets 20 welcome coins; inviter not paid yet', c == 200 and d['state']['me']['coins']['total'] == 20 and a.coins()['total'] == 32, a.coins())
f.call('view', {'show': T, 'ep': 1, 'seconds': 3}); chk('Friend starts but does not finish an episode', 'inviter still 32', a.coins()['total'] == 32)
f.call('view', {'show': T, 'ep': 1, 'seconds': 60, 'done': True}); chk('Friend finishes an episode', 'inviter +30 = 62', a.coins()['total'] == 62, a.coins())
f.call('view', {'show': T, 'ep': 2, 'seconds': 60, 'done': True}); chk('Friend finishes another episode', 'no second reward, inviter stays 62', a.coins()['total'] == 62)
s2 = C(); s2.st(); c, d = signup(s2, 'Solo', '9000000001', ref='ZZZZZZZ'); chk('Sign-up with a made-up invite code', 'account made, nobody rewarded', c == 200 and db('SELECT COUNT(*) FROM referrals')[0][0] == 1)
s3 = C(); gref = db('SELECT ref_code FROM users WHERE is_guest=1 LIMIT 1')[0][0]; s3.st(); signup(s3, 'G', '9000000002', ref=gref); chk("Invite code of a guest (no account)", 'not accepted as an inviter', db('SELECT COUNT(*) FROM referrals')[0][0] == 1)

# ---------------------------------------------------------------- 4. Buying coins and plans
sec('4. Buying coins and plans')
gq = C(); gq.st(); c, d = gq.call('order', {'kind': 'coins', 'pack': 'p99'}); chk('Guest tries to buy coins', '401 create an account first', c == 401)
c, d = a.call('order', {'kind': 'coins', 'pack': 'p1'}); chk('Unknown coin pack', '400', c == 400)
c, d = a.call('order', {'kind': 'bogus'}); chk('Unknown product', '400', c == 400)
c, d = a.call('order', {'kind': 'ep', 'show': T, 'ep': 9}); chk('Old single-episode rupee purchase', '400 (replaced by coins)', c == 400)
c, d = a.call('order', {'kind': 'coins', 'pack': 'p99', 'amount': 1, 'coins': 99999}); chk('Order with a tampered price', 'server price Rs 99 used', c == 200 and d['amount'] == 99, d); o1 = d['order']
c, d = f.call('pay_test', {'order': o1}); chk("Paying someone else's order", '404', c == 404)
c, d = a.call('pay_test', {'order': o1}); chk('Buy the Rs 99 pack', '+200 bought +30 bonus +100 first top-up bonus: 62 + 330 = 392 (200 bought, 192 bonus)', c == 200 and d['earned'] == 330 and d['state']['me']['coins']['total'] == 392 and d['state']['me']['coins']['paid'] == 200 and d['state']['me']['coins']['bonus'] == 192, d['state']['me']['coins'])
c, d = a.call('pay_test', {'order': o1}); chk('Paying the same order twice', '404, balance stays 392', c == 404 and a.coins()['total'] == 392)
# spend order: bonus first
c, d = a.call('unlock', {'show': T, 'ep': 9}); w = d['state']['me']['coins']; chk('Spend uses bonus coins first', 'after -10: 200 bought untouched, 182 bonus', w == {'total': 382, 'paid': 200, 'bonus': 182, 'next': w['next']}, w)
# expiry
db('UPDATE coin_lots SET expires=? WHERE user_id=? AND kind=? AND expires>0', (int(time.time()) - 5, A, 'bonus'))
w = a.coins(); chk('Bonus coins past their date expire', 'all 82 bonus removed, 200 bought kept', w['total'] == 200 and w['bonus'] == 0 and w['paid'] == 200, w)
h = a.hist(); chk('Expiry is written in the history', 'a line of -182 "Bonus coins expired", balance 200', h[0]['delta'] == -182 and h[0]['reason'] == 'expired' and h[0]['balance'] == 200, h[0])
c, d = a.call('unlock', {'show': T, 'ep': 10}); chk('Spend from bought coins when no bonus is left', '200 - 10 = 190', d['state']['me']['coins']['total'] == 190 and d['state']['me']['coins']['paid'] == 190)
for pid, rs, coins, bonus in [('p29', 29, 60, 0), ('p249', 249, 500, 125), ('p499', 499, 1000, 400)]:
    before = a.coins()['total']; c, d = buy(a, 'coins', pack=pid); chk('Buy pack ' + pid, 'Rs %d gives %d + %d bonus' % (rs, coins, bonus), c == 200 and d['state']['me']['coins']['total'] == before + coins + bonus and d['earned'] == coins + bonus, d)
c, d = buy(a, 'coins', pack='p29'); chk('Fifth pack in a day', 'allowed', c == 200, (c, d))
before = a.coins()['total']; c, d = buy(a, 'coins', pack='p29'); chk('Sixth pack in a day', '429 daily limit, balance unchanged', c == 429 and a.coins()['total'] == before, (c, d))
m = C(); m.st(); signup(m, 'Max', '9000000003'); M = uid_of('9000000003')
db('INSERT INTO coin_lots(user_id,kind,remaining,expires,created) VALUES(?,?,?,0,?)', (M, 'paid', 19900, int(time.time())))
before = m.coins()['total']; c, d = buy(m, 'coins', pack='p249'); chk('Wallet limit of 20,000 bought coins', '400 wallet full, nothing added', c == 400 and m.coins()['total'] == before, (c, d))
db('DELETE FROM coin_lots WHERE user_id=? AND remaining=19900', (M,))
# plans
c, d = buy(m, 'week'); vu = d['state']['me']['vipUntil']; chk('Buy the weekly plan (Rs 99)', 'everything open for 7 days', c == 200 and d['state']['me']['vip'] and abs(vu - time.time() - 7 * 86400) < 60, d['state']['me'])
c, d = m.call('view', {'show': 'akhada', 'ep': 55, 'seconds': 3}); chk('Subscriber watches a locked episode', 'allowed, no coins used (still 20)', c == 200 and m.coins()['total'] == 20)
c, d = m.call('unlock', {'show': T, 'ep': 6}); chk('Subscriber taps unlock', '400 already open, no coins taken', c == 400 and m.coins()['total'] == 20)
c, d = buy(m, 'quarter'); chk('Add the 3-month plan on top', 'end date moves out by 90 more days', abs(d['state']['me']['vipUntil'] - vu - 90 * 86400) < 5, d['state']['me'])
c, d = buy(m, 'year'); chk('Yearly plan (Rs 899)', 'accepted', c == 200)
db('UPDATE users SET vip_until=? WHERE id=?', (int(time.time()) - 10, M))
c, d = m.call('view', {'show': 'akhada', 'ep': 54, 'seconds': 3}); chk('Plan ended', 'locked episodes are locked again (403)', c == 403 and not m.st()['me']['vip'])
# show pass
c, d = a.call('order', {'kind': 'pass', 'show': 'ichcha'}); chk('Show pass order', 'Rs 149 from the server', c == 200 and d['amount'] == 149, d)
c, d = a.call('pay_test', {'order': d['order']}); chk('Own the show', 'all 50 Ichchadhari episodes open without coins', sh(d['state'], 'ichcha')['pass'] and a.call('view', {'show': 'ichcha', 'ep': 50, 'seconds': 3})[0] == 200)
c, d = a.call('order', {'kind': 'pass', 'show': 'ichcha'}); chk('Buying the same show again', '400 already owned', c == 400)
c, d = a.call('view', {'show': 'bahu', 'ep': 9, 'seconds': 3}); chk('Pass covers one show only', 'another show stays locked', c == 403)

# ---------------------------------------------------------------- 5. Races and abuse
sec('5. Races and abuse')
r = C(); r.st(); signup(r, 'Racer', '9000000004'); R = uid_of('9000000004')
db('UPDATE coin_lots SET remaining=10 WHERE user_id=?', (R,))
codes = []
def hit(ep): codes.append(r.call('unlock', {'show': 'janam', 'ep': ep})[0])
ths = [threading.Thread(target=hit, args=(9,)) for _ in range(12)]; [t.start() for t in ths]; [t.join() for t in ths]
chk('12 taps at once on one episode with 10 coins', 'one unlock, balance 0, never below zero', codes.count(200) == 1 and r.coins()['total'] == 0 and db('SELECT MIN(remaining) FROM coin_lots')[0][0] >= 0, sorted(codes))
db('UPDATE coin_lots SET remaining=10 WHERE user_id=?', (R,)); codes = []
ths = [threading.Thread(target=hit, args=(e,)) for e in range(10, 22)]; [t.start() for t in ths]; [t.join() for t in ths]
chk('12 different episodes at once with 10 coins', 'exactly one opens, balance 0', codes.count(200) == 1 and r.coins()['total'] == 0 and len(sh(r.st(), 'janam')['open']) == 2, sorted(codes))
c, d = r.call('order', {'kind': 'coins', 'pack': 'p29'}); oid = d['order']; codes = []
def pay(): codes.append(r.call('pay_test', {'order': oid})[0])
ths = [threading.Thread(target=pay) for _ in range(10)]; [t.start() for t in ths]; [t.join() for t in ths]
chk('10 payment confirmations at once for one order', 'coins added once: 60 + 30 first top-up bonus = 90', codes.count(200) == 1 and r.coins()['total'] == 90, (sorted(codes), r.coins()))
db('UPDATE users SET last_checkin=NULL, streak=0 WHERE id=?', (R,)); codes = []
def ci(): codes.append(r.call('checkin', {})[0])
ths = [threading.Thread(target=ci) for _ in range(10)]; [t.start() for t in ths]; [t.join() for t in ths]
chk('10 check-ins at once', 'paid once: 90 + 2 = 92', codes.count(200) == 1 and r.coins()['total'] == 92, (sorted(codes), r.coins()))
c, d = r.call('like', {'show': "x' OR 1=1--"}); chk('SQL injection text as a show id', '404, nothing broken', c == 404)
c, b_, h_ = r.call('', raw=True, path='video.php?show=../lib&ep=1'); chk('Path trick on the video address', '403', c == 403)
for pth in ['lib.php', 'import.php']:
    c, b_, h_ = r.call('', raw=True, path=pth); chk('Internal file ' + pth, 'no content served', len(b_) == 0 or c == 404, (c, len(b_)))
c, d = r.call('nosuch', {}); chk('Unknown action', '404', c == 404)

# ---------------------------------------------------------------- 6. Admin settings feed the coin maths
sec('6. Admin')
o = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def apost(f, opener=o):
    try: x = opener.open(urllib.request.Request(B + 'admin.php', data=urllib.parse.urlencode(f).encode())); return x.status, x.read().decode()
    except urllib.error.HTTPError as e: return e.code, e.read().decode()
c, h = apost({'act': 'setup', 'password': 'short', 'password2': 'short'}); chk('Admin password too short', 'refused', 'at least 10' in h)
c, h = apost({'act': 'setup', 'password': 'correct-horse-9', 'password2': 'different-one-9'}); chk('Admin passwords do not match', 'refused', 'do not match' in h)
c, h = apost({'act': 'setup', 'password': 'correct-horse-9', 'password2': 'correct-horse-9'}); chk('Set the admin password', 'dashboard opens', 'Upload an episode video' in h)
csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', h).group(1)
o2 = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
c, h2 = apost({'act': 'setup', 'password': 'attacker-pass-1', 'password2': 'attacker-pass-1'}, o2); chk('Someone else tries to set the admin password afterwards', 'refused, sees only the sign-in form', 'Sign in to manage' in h2 and 'Upload an episode' not in h2)
c, h2 = apost({'act': 'free', 'show': T, 'free': '10', 'coins': '1', 'pass': '1', 'csrf': csrf}, o2); chk('Settings change without signing in', 'ignored', sh(r.st(), T)['free'] == 5)
c, h2 = apost({'act': 'free', 'show': T, 'free': '10', 'csrf': 'bad'}); chk('Settings change with a bad form token', '403', c == 403)
chk('Coin books balance on the admin page', 'issued = spent + expired + in wallets', 'The books balance.' in h or 'The books balance.' in apost({'act': 'x', 'csrf': csrf})[1])
c, h = apost({'act': 'free', 'show': 'bahu', 'free': '8', 'coins': '15', 'pass': '199', 'csrf': csrf}); chk('Admin sets Bahu Number 2 to 15 coins an episode', 'saved', '15 coins per locked episode' in h)
p = C(); p.st(); signup(p, 'Pricey', '9000000005')
chk('App shows the new price', 'Bahu Number 2 costs 15 a locked episode, pass Rs 199', sh(p.st(), 'bahu')['price'] == 15 and sh(p.st(), 'bahu')['passRs'] == 199)
c, d = p.call('unlock', {'show': 'bahu', 'ep': 9}); chk('Unlock at the new price', '20 - 15 = 5 left', c == 200 and d['state']['me']['coins']['total'] == 5, d)
c, d = p.call('unlock', {'show': 'bahu', 'ep': 10}); chk('5 coins is not enough for a 15-coin episode', '402, balance stays 5', c == 402 and p.coins()['total'] == 5)
c, h = apost({'act': 'free', 'show': T, 'free': '3', 'coins': '10', 'pass': '149', 'csrf': csrf}); chk('Admin lowers Thornbound to 3 free episodes', 'episode 4 now locked for a new guest', C().call('view', {'show': T, 'ep': 4, 'seconds': 3})[0] == 403)
apost({'act': 'free', 'show': T, 'free': '5', 'coins': '10', 'pass': '149', 'csrf': csrf})

# ---------------------------------------------------------------- 8. Tasks, first top-up offer, feedback, subtitles, account deletion
sec('8. Tasks and first top-up offer')
g = C(); st0 = g.st()
chk('State lists the daily tasks', '3 tasks, none claimed, progress 0', len(st0['tasks']) == 3 and all(t['have'] == 0 and not t['claimed'] for t in st0['tasks']), st0.get('tasks'))
chk('New visitor is offered the first top-up bonus', 'firstOffer true, 50%', st0['me']['firstOffer'] is True and st0['econ']['firstPct'] == 50)
c, d = g.call('task_claim', {'task': 'watch3'}); chk('Guest claims a task', '401', c == 401, (c, d))
n = C(); n.st(); signup(n, 'Tasker', '9000000021')
c, d = n.call('task_claim', {'task': 'watch3'}); chk('Claim before the task is done', '400 with progress, no coins', c == 400 and '0 of 3' in d['error'] and n.coins()['total'] == 20, (c, d))
c, d = n.call('task_claim', {'task': 'nosuch'}); chk('Unknown task', '400', c == 400)
for i in range(3): n.call('view', {'show': T, 'ep': 1, 'seconds': 60, 'done': True})
t = {x['id']: x for x in n.st()['tasks']}; chk('Finishing the same episode three times', 'counts as 1', t['watch3']['have'] == 1, t['watch3'])
for e in (2, 3): n.call('view', {'show': T, 'ep': e, 'seconds': 60, 'done': True})
n.call('view', {'show': T, 'ep': 4, 'seconds': 5, 'done': False})
t = {x['id']: x for x in n.st()['tasks']}; chk('Three different episodes finished, one only started', 'watch3 shows 3 of 3; watch10 shows 3 of 10', t['watch3']['have'] == 3 and t['watch10']['have'] == 3, t)
c, d = n.call('task_claim', {'task': 'watch3'}); chk('Claim the finished task', '+2 coins: 20 + 2 = 22, logged as a task', c == 200 and d['earned'] == 2 and d['state']['me']['coins']['total'] == 22 and n.hist()[0]['reason'] == 'task', (c, d.get('earned')))
c, d = n.call('task_claim', {'task': 'watch3'}); chk('Claim the same task again', '409, balance stays 22', c == 409 and n.coins()['total'] == 22, (c, d))
c, d = n.call('task_claim', {'task': 'watch10'}); chk('Claim the 10-episode task at 3 of 10', '400', c == 400)
n.call('share', {'show': T})
codes = []
def claim(): codes.append(n.call('task_claim', {'task': 'share1'})[0])
th = [threading.Thread(target=claim) for _ in range(10)]; [x.start() for x in th]; [x.join() for x in th]
chk('10 claims at once for the share task', 'paid once: 22 + 2 = 24', codes.count(200) == 1 and n.coins()['total'] == 24, (sorted(codes), n.coins()))
c, d = buy(n, 'coins', pack='p29'); chk('First coin pack, Rs 29', '60 bought + 30 first top-up bonus = 90 added', c == 200 and d['earned'] == 90 and d['state']['me']['coins']['paid'] == 60 and d['state']['me']['firstOffer'] is False, (c, d.get('earned')))
chk('First top-up bonus in the history', 'its own line, +30', any(h['reason'] == 'first_bonus' and h['delta'] == 30 for h in n.hist()))
c, d = buy(n, 'coins', pack='p29'); chk('Second coin pack', 'no extra: 60 added', c == 200 and d['earned'] == 60, (c, d.get('earned')))

sec('9. Help and feedback')
c, d = n.call('feedback', {'msg': 'hi'}); chk('Message too short', '400', c == 400)
c, d = n.call('feedback', {'msg': 'x' * 1001}); chk('Message over 1000 characters', '400', c == 400)
c, b_, h_ = n.call('feedback', {'msg': 'Episode 6 will not play'}, hdr=False, raw=True); chk('Message without the app header', '403', c == 403)
c, d = n.call('feedback', {'msg': 'Episode 6 will not play <script>alert(1)</script>'}); chk('Send a message', 'saved with the sender name and number', c == 200 and db("SELECT name, contact FROM feedback ORDER BY id DESC LIMIT 1")[0] == ('Tasker', '9000000021'), (c, d.get('error')))
c, d = g.call('feedback', {'msg': 'I am a guest with a question', 'contact': 'me@example.com'}); chk('Guest sends a message with a contact', 'saved as Guest', c == 200 and db("SELECT name, contact FROM feedback ORDER BY id DESC LIMIT 1")[0] == ('Guest', 'me@example.com'))
for i in range(4): n.call('feedback', {'msg': 'another message %d' % i})
c, d = n.call('feedback', {'msg': 'sixth message today'}); chk('Sixth message in a day', '429', c == 429, c)
c, h = apost({'act': 'x', 'csrf': csrf}); chk('Admin sees the messages, script shown as text', 'listed and escaped', 'Episode 6 will not play &lt;script&gt;' in h and '<script>alert(1)' not in h)
fid = db("SELECT id FROM feedback ORDER BY id LIMIT 1")[0][0]
c, h = apost({'act': 'fbdone', 'id': fid, 'csrf': csrf}); chk('Admin marks a message handled', 'saved', db("SELECT done FROM feedback WHERE id=?", (fid,))[0][0] == 1)

sec('10. Subtitles')
def aup(fields, fname, content):
    bd = '----natakqa'; body = b''
    for k, v in fields.items(): body += ('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (bd, k, v)).encode()
    body += ('--%s\r\nContent-Disposition: form-data; name="sub"; filename="%s"\r\nContent-Type: text/plain\r\n\r\n' % (bd, fname)).encode() + content + ('\r\n--%s--\r\n' % bd).encode()
    rq = urllib.request.Request(B + 'admin.php', data=body, headers={'Content-Type': 'multipart/form-data; boundary=' + bd})
    try: x = o.open(rq); return x.status, x.read().decode()
    except urllib.error.HTTPError as e: return e.code, e.read().decode()
SRT = b'\xef\xbb\xbf1\r\n00:00:01,000 --> 00:00:03,500\r\nShe was sent to kill him.\r\n\r\n2\r\n00:00:04,000 --> 00:00:06,000\r\n\xe0\xa4\xa8\xe0\xa4\xae\xe0\xa4\xb8\xe0\xa5\x8d\xe0\xa4\xa4\xe0\xa5\x87\r\n'
c, h = aup({'csrf': csrf, 'act': 'subs', 'show': T, 'ep': '1'}, 'ep1.srt', SRT); chk('Upload an .srt for a free episode', 'saved', 'subtitles saved' in h, c)
c, h = aup({'csrf': csrf, 'act': 'subs', 'show': T, 'ep': '7'}, 'ep7.vtt', b'WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nLocked line\n'); chk('Upload a .vtt for a locked episode', 'saved', 'subtitles saved' in h)
c, h = aup({'csrf': csrf, 'act': 'subs', 'show': T, 'ep': '2'}, 'x.vtt', b'this is not a subtitle file'); chk('Upload a file that is not subtitles', 'refused', 'does not look like a subtitle file' in h)
c, h = aup({'csrf': 'bad', 'act': 'subs', 'show': T, 'ep': '2'}, 'x.vtt', b'WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nx\n'); chk('Subtitle upload with a bad form token', '403', c == 403)
chk('App is told which episodes have subtitles', 'Thornbound: episodes 1 and 7', sh(g.st(), T)['subs'] == [1, 7], sh(g.st(), T)['subs'])
c, b_, h_ = g.call('', raw=True, path='sub.php?show=%s&ep=1' % T); txt = b_.decode()
chk('Subtitles for a free episode', 'served as WebVTT, .srt times converted, Hindi text intact', c == 200 and h_['Content-Type'].startswith('text/vtt') and txt.startswith('WEBVTT') and '00:00:01.000 --> 00:00:03.500' in txt and 'नमस्ते' in txt and '\r' not in txt, (c, txt[:80]))
c, b_, h_ = g.call('', raw=True, path='sub.php?show=%s&ep=7' % T); chk('Subtitles for a locked episode, as a guest', '403: the dialogue is not leaked', c == 403 and b'Locked line' not in b_, c)
c, b_, h_ = g.call('', raw=True, path='sub.php?show=%s&ep=2' % T); chk('Subtitles for an episode that has none', '404', c == 404)
c, b_, h_ = g.call('', raw=True, path='sub.php?show=../lib&ep=1'); chk('Path trick on the subtitle address', '403', c == 403)
c, h = aup({'csrf': csrf, 'act': 'subs', 'show': T, 'ep': '7', 'remove': '1'}, '', b''); chk('Remove subtitles', 'episode 7 no longer listed', sh(g.st(), T)['subs'] == [1], h[:0])

sec('11. Delete account')
c, d = g.call('delete_account', {'password': 'x'}); chk('Guest tries to delete an account', '401', c == 401)
z = C(); z.st(); signup(z, 'Leaver', '9000000022'); buy(z, 'coins', pack='p29'); z.call('unlock', {'show': T, 'ep': 6}); z.call('like', {'show': T}); z.call('view', {'show': T, 'ep': 1, 'seconds': 30, 'done': True})
zid = db("SELECT id FROM users WHERE phone='9000000022'")[0][0]; views_before = sh(z.st(), T)['views']
c, d = z.call('delete_account', {'password': 'wrong-one'}); chk('Delete with the wrong password', '401, account still there', c == 401 and not z.st()['me']['guest'], c)
c, b_, h_ = z.call('delete_account', {'password': 'secret12'}, hdr=False, raw=True); chk('Delete without the app header', '403', c == 403)
c, d = z.call('delete_account', {'password': 'secret12'}); st = d.get('state', {})
chk('Delete with the right password', 'done; the phone is a guest again with 0 coins', c == 200 and d.get('deleted') and st['me']['guest'] and st['me']['coins']['total'] == 0, (c, d.get('error')))
left = {t: db('SELECT COUNT(*) FROM %s WHERE user_id=?' % t, (zid,))[0][0] for t in ('unlocks', 'likes', 'coin_lots', 'coin_tx', 'views', 'orders')}
chk('Nothing personal is left behind', 'no rows for that account in any table', sum(left.values()) == 0 and db("SELECT COUNT(*) FROM users WHERE phone='9000000022'")[0][0] == 0 and db("SELECT COUNT(*) FROM sessions s JOIN users u ON u.id=s.user_id WHERE u.is_guest=0 AND s.user_id=?", (zid,))[0][0] == 0, left)
chk('Deleted wallet is written off in the ledger', 'a "closed" line for the coins given up', db("SELECT COUNT(*), SUM(delta) FROM coin_tx WHERE reason='closed' AND user_id=0")[0][0] == 1, db("SELECT COUNT(*), SUM(delta) FROM coin_tx WHERE reason='closed'"))
chk('Order record is kept without the person', '1 paid order with no owner', db("SELECT COUNT(*) FROM orders WHERE user_id=0 AND status='paid_test'")[0][0] >= 1)
chk('View count is unchanged', 'the total stays', sh(g.st(), T)['views'] == views_before, (sh(g.st(), T)['views'], views_before))
c, d = C().call('login', {'phone': '9000000022', 'password': 'secret12'}); chk('Sign in to the deleted account', '401', c == 401)
y = C(); y.st(); c, d = signup(y, 'Back Again', '9000000022'); chk('Same number signs up again', 'a fresh account with 20 welcome coins', c == 200 and d['state']['me']['coins']['total'] == 20 and sh(d['state'], T)['open'] == [], c)

sec('12. Pages and install files')
for pth, must in [('legal.php?p=privacy', 'Privacy policy'), ('legal.php?p=terms', 'Terms of use'), ('legal.php?p=zzz', 'Privacy policy'), ('manifest.webmanifest', '"standalone"'), ('sw.js', 'fetch'), ('icons/icon-192.png', None)]:
    c, b_, h_ = g.call('', raw=True, path=pth); chk('Page ' + pth, '200' + (' and the right content' if must else ''), c == 200 and (must is None or must.encode() in b_), c)

# ---------------------------------------------------------------- 7. The books: every coin accounted for
sec('7. Coin accounting across the whole database')
tx = dict(db('SELECT reason, SUM(delta) FROM coin_tx GROUP BY reason'))
issued = sum(tx.get(k, 0) for k in ('purchase', 'pack_bonus', 'first_bonus', 'welcome', 'checkin', 'invite', 'task', 'ad'))
spent = -tx.get('unlock', 0); expired = -tx.get('expired', 0) - tx.get('closed', 0)
held = db('SELECT COALESCE(SUM(remaining),0) FROM coin_lots WHERE remaining>0 AND (expires=0 OR expires>?)', (int(time.time()),))[0][0]
# two test set-ups above edited wallets directly in the database; account for them explicitly
manual = -10 + (10 - 0)  # racer: 20 welcome set to 10 (-10), then reset from 0 to 10 (+10)
chk('Coins issued = spent + expired + still held', '%d = %d + %d + %d (after the 2 manual test edits)' % (issued, spent, expired, held), issued + manual == spent + expired + held, (issued, manual, spent, expired, held, tx))
chk('Merge lines cancel out', 'sum of "merge" entries is 0', tx.get('merge', 0) == 0, tx.get('merge'))
bad = []
for (u,) in db('SELECT DISTINCT user_id FROM coin_tx'):
    if u == R: continue      # the racer's wallet was edited by hand for the race tests
    rows = db('SELECT delta, balance, reason FROM coin_tx WHERE user_id=? ORDER BY id', (u,)); run = 0
    for dlt, bal, rsn in rows:
        run += dlt
        if run != bal: bad.append((u, rsn, run, bal)); break
    live = db('SELECT COALESCE(SUM(remaining),0) FROM coin_lots WHERE user_id=? AND remaining>0 AND (expires=0 OR expires>?)', (u, int(time.time())))[0][0]
    exists = db('SELECT COUNT(*) FROM users WHERE id=?', (u,))[0][0]
    if exists and run != live: bad.append((u, 'final', run, live))
chk('Every wallet: history adds up line by line and matches the balance', 'no mismatches in any account', not bad, bad[:3])
chk('No wallet below zero', 'minimum lot is 0 or more', db('SELECT COALESCE(MIN(remaining),0) FROM coin_lots')[0][0] >= 0)
chk('Each unlocked episode was paid for once', 'coin unlock lines = coin and ad unlocks (deleted accounts aside)', db("SELECT COUNT(*) FROM coin_tx WHERE reason='unlock' AND user_id>0")[0][0] == db("SELECT COUNT(*) FROM unlocks WHERE method IN ('coins','ad')")[0][0], (db("SELECT COUNT(*) FROM coin_tx WHERE reason='unlock'"), db("SELECT method, COUNT(*) FROM unlocks GROUP BY method")))
rs = db("SELECT COALESCE(SUM(amount),0) FROM orders WHERE status='paid_test' AND kind='coins'")[0][0]
chk('Rupees taken for packs match coins sold', 'Rs %d for %d bought coins' % (rs, tx.get('purchase', 0)), rs == 99 + 29 + 249 + 499 + 29 + 29 + 3 * 29 and tx.get('purchase', 0) == 200 + 60 + 500 + 1000 + 60 + 60 + 3 * 60, (rs, tx.get('purchase')))

ok = sum(1 for x in results if x[4]); bad = [x for x in results if not x[4]]
print('QA PASS %d of %d' % (ok, len(results)))
for x in bad: print('FAIL', x[1], x[0], '|', x[2], '| expected:', x[3], '| got:', x[5])
with open('/home/claude/t/api_results.json', 'w') as fh: json.dump(results, fh)
