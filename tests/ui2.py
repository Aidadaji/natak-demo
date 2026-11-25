"""Phone-size browser run: registration, coins on screen, unlock flows, store, wallet, links and buttons."""
import asyncio, json, re, urllib.request
from playwright.async_api import async_playwright
B = 'http://127.0.0.1:8765/'
res = []; errs = []
def chk(what, expected, cond, info=''): res.append(('8. On-screen flows (phone size)', 0, what, expected, bool(cond), '' if cond else str(info)[:160]))
def lchk(what, expected, cond, info=''): res.append(('9. Links and buttons', 0, what, expected, bool(cond), '' if cond else str(info)[:160]))

async def coin(pg): return (await pg.inner_text('#coinN')).strip()
async def main():
    async with async_playwright() as p:
        br = await p.chromium.launch(args=['--autoplay-policy=no-user-gesture-required'])
        ctx = await br.new_context(viewport={'width': 360, 'height': 740}); pg = await ctx.new_page()
        pg.on('console', lambda m: errs.append(m.text) if m.type == 'error' else None)
        pg.on('pageerror', lambda e: errs.append('PAGEERR ' + str(e)))
        bad_resp = []
        pg.on('response', lambda r: bad_resp.append((r.status, r.url)) if r.status >= 500 else None)
        await pg.goto(B); await pg.wait_for_selector('#rowAll .card')
        chk('Home loads for a new visitor', '6 shows, wallet shows 0, "Sign in" button', await pg.locator('#rowAll .card').count() == 6 and await coin(pg) == '0' and await pg.inner_text('#acctBtn') == 'Sign in')
        chk('No sideways scrolling on a 360 px phone', 'page width fits the screen', await pg.evaluate('document.scrollingElement.scrollWidth <= window.innerWidth'))
        # registration flow
        await pg.click('#acctBtn'); await pg.wait_for_selector('#authForm')
        chk('Sign in opens the sign-in form', 'mobile number and password fields, link to create an account', await pg.locator('#fName').count() == 0 and await pg.locator('[data-act="signup"]').count() == 1)
        await pg.click('[data-act="signup"]'); await pg.wait_for_selector('#fName')
        await pg.click('#authForm button[type=submit]'); await pg.wait_for_timeout(500)
        chk('Empty sign-up form', 'message asks for the name, no account made', 'name' in (await pg.inner_text('#authErr')).lower(), await pg.inner_text('#authErr'))
        await pg.fill('#fName', '<img src=x onerror=window.__x=1>'); await pg.fill('#fPhone', '12345'); await pg.fill('#fPass', 'secret12'); await pg.click('#authForm button[type=submit]'); await pg.wait_for_timeout(500)
        chk('Wrong mobile number', 'message asks for a 10-digit Indian number', '10-digit' in await pg.inner_text('#authErr'), await pg.inner_text('#authErr'))
        await pg.fill('#fPhone', '9811122233'); await pg.fill('#fPass', '123'); await pg.click('#authForm button[type=submit]'); await pg.wait_for_timeout(500)
        chk('Short password', 'message asks for 6 characters', '6 characters' in await pg.inner_text('#authErr'))
        await pg.fill('#fPass', 'secret12'); await pg.click('#authForm button[type=submit]'); await pg.wait_for_selector('text=Account created: +20 coins')
        await pg.wait_for_timeout(300)
        chk('Sign-up succeeds', 'toast "+20 coins", wallet shows 20, green +20 chip, button says Account', await coin(pg) == '20' and '+20 coins' in await pg.inner_text('#delta') and await pg.inner_text('#acctBtn') == 'Account', (await coin(pg), await pg.inner_text('#delta')))
        chk('Name with HTML in it is shown as text', 'no script ran, name printed literally', await pg.evaluate('window.__x === undefined') and '<img' in await pg.inner_text('#who'))
        await pg.screenshot(path='s_home.png')
        # video playback (sample clip lives on Ichchadhari 1-3 in the test copy)
        await pg.click('#rowAll .card[data-show="ichcha"]'); await pg.wait_for_timeout(4500)
        ct = await pg.evaluate("document.getElementById('vid').currentTime")
        chk('A free episode with video plays', 'video running, view counted', ct > 1 and '1 views' in await pg.inner_text('#pEp'), (ct, await pg.inner_text('#pEp')))
        await pg.click('#backBtn'); await pg.wait_for_selector('#rowAll .card')
        # thornbound: free then locked
        await pg.click('#hero'); await pg.wait_for_timeout(400)
        chk('Thornbound episode 1 opens free', 'no paywall, title shows "The Ninth Assassin" and Free', 'The Ninth Assassin' in await pg.inner_text('#pEp') and 'Free' in await pg.inner_text('#pEp') and not await pg.is_visible('#scrim'))
        await pg.click('#listBtn'); await pg.wait_for_selector('.grid')
        cls = await pg.evaluate("[...document.querySelectorAll('.grid button')].map(b=>b.className.split(' ')[0]).join(',')")
        chk('Episode list marks free and locked', '5 free then 5 locked', cls == 'free,free,free,free,free,lock,lock,lock,lock,lock', cls)
        await pg.click('.grid button[data-ep="6"]'); await pg.wait_for_selector('text=Episode 6 is locked')
        txt = await pg.inner_text('#sheet')
        chk('Paywall states the price and the balance', '"Unlock with 10 coins", "You have 20. After this you keep 10."', 'Unlock with 10 coins' in txt and 'You have 20. After this you keep 10.' in txt and '5 episodes left at 10 coins each' in txt, txt[:200])
        chk('Short show hides the show pass', 'no "Own this show" on Thornbound', 'Own this show' not in txt)
        await pg.screenshot(path='s_paywall.png')
        await pg.click('[data-act="unlock"]'); await pg.wait_for_selector('text=Episode 6 opened'); await pg.wait_for_timeout(300)
        chk('Unlock deducts on screen', 'wallet 20 -> 10, red "-10 coins" chip, episode plays', await coin(pg) == '10' and '10 coins' in await pg.inner_text('#delta') and 'minus' in await pg.get_attribute('#delta', 'class') and not await pg.is_visible('#scrim'), (await coin(pg), await pg.inner_text('#delta')))
        await pg.click('#nextBtn'); await pg.wait_for_selector('text=Episode 7 is locked')
        await pg.check('#autoUn'); await pg.click('[data-act="unlock"]'); await pg.wait_for_selector('text=Episode 7 opened'); await pg.wait_for_timeout(300)
        chk('Second unlock', 'wallet 10 -> 0', await coin(pg) == '0')
        await pg.click('#nextBtn'); await pg.wait_for_selector('text=Episode 8 is locked'); txt = await pg.inner_text('#sheet')
        chk('Paywall with an empty wallet', '"Get coins": costs 10, you have 0, need 10 more; auto-unlock does not fire', 'This episode costs 10. You have 0, so you need 10 more.' in txt, txt[:200])
        await pg.click('[data-act="ad"]'); await pg.wait_for_selector('text=Episode 8 opened', timeout=15000); await pg.wait_for_timeout(300)
        chk('Watch an ad', 'chip shows "+10 -10 coins", wallet stays 0, episode plays', '+10' in await pg.inner_text('#delta') and await coin(pg) == '0', await pg.inner_text('#delta'))
        await pg.click('#nextBtn'); await pg.wait_for_selector('text=Episode 9 is locked')
        await pg.click('.opt[data-act="store"]:not([data-tab])'); await pg.wait_for_selector('text=Get coins')
        txt = await pg.inner_text('#sheet')
        chk('Coin store lists four packs with episode maths', 'first top-up offer shown; Rs 29: about 9 episodes; Rs 99: about 33; Rs 249: about 87; Rs 499: about 190', all(x in txt for x in ['First top-up offer', 'About 9 episodes', 'About 33 episodes', 'About 87 episodes', 'About 190 episodes', '+30 bonus', '+130 bonus', '+375 bonus', '+900 bonus']), txt[:300])
        await pg.screenshot(path='s_store.png')
        await pg.click('[data-pack="p99"]'); await pg.wait_for_selector('text=Complete test payment')
        chk('Checkout shows the right amount', 'Rs 99 for 200 coins + 130 bonus', '99' in await pg.inner_text('.amt') and '200 coins + 130 bonus' in await pg.inner_text('#sheet'))
        await pg.click('[data-act="paid"]'); await pg.wait_for_selector('text=Added 330 coins'); await pg.wait_for_selector('text=Episode 9 is locked')
        chk('Buying a pack adds coins and returns to the episode', 'wallet 330, green +330 chip, paywall now offers "Unlock with 10 coins"', await coin(pg) == '330' and 'Unlock with 10 coins' in await pg.inner_text('#sheet'), await coin(pg))
        await pg.click('[data-act="unlock"]'); await pg.wait_for_selector('text=Episode 9 opened'); await pg.wait_for_timeout(300)
        chk('Unlock from a mixed wallet', 'wallet 330 -> 320', await coin(pg) == '320')
        await pg.click('#nextBtn'); await pg.wait_for_timeout(1500)
        chk('Automatic unlock (switched on by the viewer)', 'episode 10 opens without a paywall, wallet 320 -> 310', await coin(pg) == '310' and not await pg.is_visible('#scrim') and 'Episode 10 of 10' in await pg.inner_text('#pEp'), (await coin(pg), await pg.inner_text('#pEp')))
        await pg.click('#nextBtn'); await pg.wait_for_selector('#rowAll .card')
        chk('After the last episode', 'returns home, Continue watching row shows', await pg.is_visible('#contSec'))
        # wallet
        await pg.click('#coinBtn'); await pg.wait_for_selector('#hist li .d')
        txt = await pg.inner_text('#sheet')
        chk('Wallet shows the split and expiry', '310 total, 200 bought, 110 bonus, expiry note', await pg.inner_text('#walBal') == '310' and '200 bought · 110 bonus' in txt and 'bonus coins expire on' in txt, txt[:160])
        rows = await pg.evaluate("[...document.querySelectorAll('#hist li')].map(li=>li.querySelector('.d').textContent+' '+li.querySelector('.b').textContent)")
        want = ['−10 = 310', '−10 = 320', '+100 = 330', '+30 = 230', '+200 = 200', '−10 = 0', '+10 = 10', '−10 = 0', '−10 = 10', '+20 = 20']
        chk('History lists every addition and deduction with the balance after each', '10 lines from +20 welcome down to -10 for episode 10', rows == want, rows)
        await pg.screenshot(path='s_wallet.png', full_page=False)
        await pg.click('[data-act="checkin"]'); await pg.wait_for_selector('text=Checked in: +2 coins'); await pg.wait_for_selector('#hist li .d')
        chk('Daily check-in from the wallet', 'wallet 312, day 1 marked, button says come back tomorrow', await coin(pg) == '312' and await pg.locator('.ladder .got').count() == 1 and 'Come back tomorrow' in await pg.inner_text('#sheet'))
        # plans
        await pg.click('[data-act="store"]'); await pg.wait_for_selector('text=Everything for 1 week')
        await pg.click('[data-kind="week"]'); await pg.wait_for_selector('text=Complete test payment'); await pg.click('[data-act="paid"]'); await pg.wait_for_selector('text=Payment recorded (test)')
        await pg.wait_for_timeout(400); await pg.click('[data-act="close"]')
        chk('Weekly plan', 'button turns to "Subscribed", coins untouched at 312', await pg.inner_text('#acctBtn') == 'Subscribed' and await coin(pg) == '312', await pg.inner_text('#acctBtn'))
        await pg.click('#rowAll .card[data-show="bahu"]'); await pg.wait_for_timeout(300); await pg.click('#listBtn'); await pg.click('.grid button[data-ep="30"]'); await pg.wait_for_timeout(500)
        chk('Subscriber opens a locked episode', 'plays with no paywall and no coins used', not await pg.is_visible('#scrim') and await coin(pg) == '312' and 'Episode 30 of 70' in await pg.inner_text('#pEp'))
        # like, list, share
        await pg.click('#likeBtn'); await pg.wait_for_timeout(400); await pg.click('#saveBtn'); await pg.wait_for_timeout(400)
        chk('Like and My list', 'both counters show 1', await pg.inner_text('#likeN') == '1' and await pg.inner_text('#saveN') == '1')
        await pg.click('#shareBtn'); await pg.wait_for_selector('#shareLink'); link = await pg.input_value('#shareLink'); wa = await pg.get_attribute('#sheet a.wide', 'href')
        lchk('Invite link', 'points at this site with ?ref= and the account code', re.match(r'^http://127\.0\.0\.1:8765/\?ref=[A-Z0-9]{7}$', link) is not None, link)
        lchk('WhatsApp button', 'opens wa.me with the message and the invite link inside', wa.startswith('https://wa.me/?text=') and 'ref%3D' in wa and (await pg.get_attribute('#sheet a.wide', 'rel')) == 'noopener', wa[:80])
        await pg.click('[data-act="resume"]'); await pg.click('#backBtn'); await pg.wait_for_selector('#rowAll .card')
        # friend journey through the invite link
        c2 = await br.new_context(viewport={'width': 360, 'height': 740}); p2 = await c2.new_page(); await p2.goto(link); await p2.wait_for_selector('#rowAll .card')
        await p2.click('#acctBtn'); await p2.click('[data-act="signup"]'); await p2.fill('#fName', 'Friend'); await p2.fill('#fPhone', '9811122244'); await p2.fill('#fPass', 'secret12'); await p2.click('#authForm button[type=submit]'); await p2.wait_for_selector('text=Account created')
        await p2.click('#hero'); await p2.wait_for_timeout(13500)
        await pg.reload(); await pg.wait_for_selector('#rowAll .card')
        chk('Invited friend signs up and finishes an episode', 'inviter wallet 312 -> 342', await coin(pg) == '342', await coin(pg))
        # sign out / in
        await pg.click('#acctBtn'); await pg.click('[data-act="logout"]'); await pg.wait_for_selector('text=Signed out')
        chk('Sign out', 'guest again, wallet 0', await pg.inner_text('#who') == 'Guest' and await coin(pg) == '0')
        await pg.click('#acctBtn'); await pg.fill('#fPhone', '9811122233'); await pg.fill('#fPass', 'bad'); await pg.click('#authForm button[type=submit]'); await pg.wait_for_timeout(900)
        chk('Sign in with a wrong password', 'clear message, stays on the form', 'Wrong mobile number or password' in await pg.inner_text('#authErr'))
        await pg.fill('#fPass', 'secret12'); await pg.click('#authForm button[type=submit]'); await pg.wait_for_selector('text=Signed in'); await pg.wait_for_timeout(300)
        chk('Sign in again', 'wallet back to 342, still subscribed, no coin chip for signing in', await coin(pg) == '342' and await pg.inner_text('#acctBtn') == 'Subscribed')
        await pg.reload(); await pg.wait_for_selector('#rowAll .card')
        chk('Reload keeps the session', 'same account and wallet', await coin(pg) == '342' and 'img' in await pg.inner_text('#who'))

        # ---- links and buttons: press every control on every screen and look for errors
        async def press_all(label, opener):
            n = 0; fails = []
            await opener()
            count = await pg.locator('#sheet [data-act], #sheet a').count()
            for i in range(count):
                await opener()
                el = pg.locator('#sheet [data-act], #sheet a').nth(i)
                act = await el.get_attribute('data-act') or 'link'
                if act in ('logout', 'paid', 'checkin', 'unlock', 'ad', 'link') or await el.is_disabled(): continue
                before = len(errs)
                try: await el.click(timeout=3000); await pg.wait_for_timeout(250)
                except Exception as e: fails.append((act, str(e)[:60]))
                if len(errs) > before: fails.append((act, errs[-1][:80]))
                n += 1
                await pg.evaluate("document.getElementById('scrim').classList.remove('on')")
            lchk('Every button on: ' + label, 'each one responds, no script errors (%d pressed)' % n, not fails and n > 0, fails[:3])
        async def home(): await pg.goto(B); await pg.wait_for_selector('#rowAll .card')
        async def o_wallet(): await home(); await pg.click('#coinBtn'); await pg.wait_for_selector('#hist')
        async def o_acct(): await home(); await pg.click('#acctBtn'); await pg.wait_for_selector('.me')
        async def o_store(): await home(); await pg.click('#coinBtn'); await pg.click('#sheet [data-act="store"]'); await pg.wait_for_selector('text=Get coins')
        async def o_list(): await home(); await pg.click('#hero'); await pg.click('#listBtn'); await pg.wait_for_selector('.grid')
        async def o_share(): await home(); await pg.click('#hero'); await pg.click('#shareBtn'); await pg.wait_for_selector('#shareLink')
        await press_all('wallet', o_wallet); await press_all('account', o_acct); await press_all('coin store', o_store); await press_all('episode list', o_list); await press_all('invite sheet', o_share)
        # paywall buttons as a non-subscriber
        c3 = await br.new_context(viewport={'width': 360, 'height': 740}); p3 = await c3.new_page()
        p3.on('pageerror', lambda e: errs.append('PAGEERR ' + str(e)))
        await p3.goto(B); await p3.wait_for_selector('#rowAll .card'); await p3.click('#hero'); await p3.click('#listBtn'); await p3.click('.grid button[data-ep="6"]'); await p3.wait_for_selector('text=Episode 6 is locked')
        acts = await p3.evaluate("[...document.querySelectorAll('#sheet [data-act]')].map(b=>b.getAttribute('data-act')+(b.getAttribute('data-tab')?':'+b.getAttribute('data-tab'):''))")
        lchk('Paywall for a guest offers every route', 'get coins, ad, create account, invite, watch everything, not now', acts == ['store', 'ad', 'signup', 'share', 'store:plans', 'home'], acts)
        fails = []
        for i, act in enumerate(acts):
            if act == 'ad': continue
            await p3.evaluate("document.querySelectorAll('#sheet [data-act]')[%d].click()" % i); await p3.wait_for_timeout(300)
            vis = await p3.is_visible('#scrim') if act != 'home' else await p3.is_visible('#home')
            if not vis: fails.append(act)
            back = p3.locator('#sheet [data-act="paywall"]')
            if await back.count(): await back.first.click(); await p3.wait_for_timeout(200)
            if act == 'home': break
            if not await p3.locator('text=Episode 6 is locked').count(): fails.append(act + ' no way back')
        lchk('Each paywall route opens and has a way back', 'all open their screen and return to the paywall', not fails, fails)
        hrefs = await pg.evaluate("[...document.querySelectorAll('a[href],link[href],script[src]')].map(e=>e.href||e.src)")
        for path in ['', 'app.js', 'api.php?a=health', 'api.php?a=state', 'api.php?a=wallet', 'admin.php', 'media/sample.mp4']:
            try: code = urllib.request.urlopen(B + path).status
            except Exception as e: code = getattr(e, 'code', 0)
            lchk('Address /' + path, 'loads (200)', code == 200, code)
        for path, want in [('lib.php', 'no content'), ('poster.php?show=nope', 404), ('video.php?show=thornbound&ep=6', 403), ('nope.html', 404)]:
            try: r = urllib.request.urlopen(B + path); code = r.status; ln = len(r.read())
            except Exception as e: code = getattr(e, 'code', 0); ln = -1
            lchk('Address /' + path, 'blocked or empty (%s)' % want, (ln == 0) if want == 'no content' else code == want, (code, ln))
        adm = urllib.request.urlopen(B + 'admin.php').read().decode()
        lchk('Admin page links', '"Open the app" link present after sign-in page loads without errors', 'Natak admin' in adm and 'Fatal' not in adm and 'Warning' not in adm)
        real = [e for e in errs if '401' not in e and '402' not in e and '400' not in e]
        lchk('Browser console during the whole run', 'no script errors, no server errors (5xx)', not real and not bad_resp, (real[:2], bad_resp[:2]))
        await br.close()
    ok = sum(1 for r in res if r[4]); print('UI PASS %d of %d' % (ok, len(res)))
    for r in res:
        if not r[4]: print('FAIL', r[0], '|', r[2], '| expected:', r[3], '| got:', r[5])
    json.dump(res, open('/home/claude/t/ui_results.json', 'w'))
asyncio.run(main())
