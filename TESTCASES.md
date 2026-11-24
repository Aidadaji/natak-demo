# Test cases and results (24 Nov 2026)

Run on a copy of the app on a test server (PHP 8.3, 8 workers), not on the live site. 174 of 174 passed.

Sections 1 to 7 call the server directly. Sections 8 and 9 drive the app in a phone-size browser (360 x 740).

Not covered: real payments, a real ad network, real iPhone Safari, OTP, and form submissions on the live Hostinger site.


## 1. Guest and registration

| # | Test | Expected | Result |
|---|---|---|---|
| 1 | Health check | ok, SQLite on, data outside web folder, schema 3 | Pass |
| 2 | First visit creates a guest | guest, 0 coins, 6 shows, Thornbound first | Pass |
| 3 | Thornbound set-up | 10 episodes, 5 free, 10 coins per locked episode, no show pass offered | Pass |
| 4 | Long show offers the pass | Ichchadhari: 42 locked x 10 coins is worth more than Rs 149, so pass is offered | Pass |
| 5 | Sign-up rejected: empty name | 400 with a message, no account made | Pass |
| 6 | Sign-up rejected: name over 40 | 400 with a message, no account made | Pass |
| 7 | Sign-up rejected: short number | 400 with a message, no account made | Pass |
| 8 | Sign-up rejected: number starting with 5 | 400 with a message, no account made | Pass |
| 9 | Sign-up rejected: letters as number | 400 with a message, no account made | Pass |
| 10 | Sign-up rejected: 11 digits | 400 with a message, no account made | Pass |
| 11 | Sign-up rejected: short password | 400 with a message, no account made | Pass |
| 12 | Sign-up rejected: password over 100 | 400 with a message, no account made | Pass |
| 13 | Sign-up rejected: wrong data types | 400 with a message, no account made | Pass |
| 14 | Sign-up rejected: empty form | 400 with a message, no account made | Pass |
| 15 | No account from rejected sign-ups | still a guest with 0 coins | Pass |
| 16 | Sign-up with +91 and spaces | account made, name kept as typed, 20 welcome coins | Pass |
| 17 | Welcome coins expire in 7 days | 20 coins, expiry 7 days from now | Pass |
| 18 | History shows the welcome bonus | one line: +20, balance 20 | Pass |
| 19 | Second sign-up on a signed-in session | 400, no extra coins | Pass |
| 20 | Same number again (typed with leading 0) | 409 number already has an account | Pass |
| 21 | Sign out | back to a guest with 0 coins | Pass |
| 22 | Sign-in with wrong password | 401 | Pass |
| 23 | Sign-in with 91 prefix | signed in, 20 coins still there | Pass |
| 24 | Eight wrong passwords lock the account | 429 for 15 minutes even with the right password | Pass |
| 25 | Request without the app header | 403 | Pass |
| 26 | Write action sent as GET | 405 | Pass |

## 2. Unlocking episodes with coins

| # | Test | Expected | Result |
|---|---|---|---|
| 27 | Free episode 5 streams | not blocked (404 here only because no file in the test copy) | Pass |
| 28 | Locked episode 6 does not stream | 403 | Pass |
| 29 | Locked episode cannot be counted as watched | 403 | Pass |
| 30 | Unlock on a free episode | 400, no coins taken | Pass |
| 31 | Unlock episode 6 with coins | 20 - 10 = 10 coins left, episode opened | Pass |
| 32 | Episode 6 now allowed to stream | no longer 403 | Pass |
| 33 | Unlock the same episode twice | 400, balance stays 10 | Pass |
| 34 | Unlock episode 7 | 10 - 10 = 0 coins left | Pass |
| 35 | Unlock with 0 coins | 402 not enough coins, nothing opened | Pass |
| 36 | Unlock with bad input {"show": "thornbound", "ep": 0} | 400 or 404, no charge | Pass |
| 37 | Unlock with bad input {"show": "thornbound", "ep": 11} | 400 or 404, no charge | Pass |
| 38 | Unlock with bad input {"show": "thornbound", "ep": -3} | 400 or 404, no charge | Pass |
| 39 | Unlock with bad input {"show": "thornbound", "ep": "x"} | 400 or 404, no charge | Pass |
| 40 | Unlock with bad input {"show": "nope", "ep": 6} | 400 or 404, no charge | Pass |
| 41 | Unlock with bad input {"show": ["a"], "ep": 6} | 400 or 404, no charge | Pass |
| 42 | Unlock with bad input {} | 400 or 404, no charge | Pass |
| 43 | History lists both unlocks with running balance | -10 (=10) then -10 (=0), newest first | Pass |

## 3. Earning coins

| # | Test | Expected | Result |
|---|---|---|---|
| 44 | Guest tries the daily check-in | 401 create an account first | Pass |
| 45 | Check-in day 1 | +2 coins, balance 2 | Pass |
| 46 | Second check-in the same day | 400, balance stays 2 | Pass |
| 47 | Seven-day streak pays the ladder, then restarts | 2,2,3,3,4,4,10 then day 8 pays 2 (total 30) | Pass |
| 48 | Missed days: the app shows day 1 again | next check-in is day 1 worth 2 | Pass |
| 49 | Check-in after a broken streak | +2 (day 1), balance 32 | Pass |
| 50 | Claiming an ad that was never started | 400 | Pass |
| 51 | Claiming an ad before it finished | 400, episode stays locked | Pass |
| 52 | Finished ad | +10 earned, -10 spent, balance unchanged at 32, episode 8 open, 4 ads left | Pass |
| 53 | Claiming the same ad again | 400 | Pass |
| 54 | History shows the ad as +10 then -10 | two lines, balances 42 then 32 | Pass |
| 55 | Ad on a free episode | 400 | Pass |
| 56 | Daily ad limit (guest) | exactly 5 ad unlocks, the 6th is refused, coins stay 0 | Pass |
| 57 | Guest with 5 ads used signs in | account keeps the ad count (0 left) and gains the 5 unlocked episodes | Pass |
| 58 | Friend signs up from the invite link | friend gets 20 welcome coins; inviter not paid yet | Pass |
| 59 | Friend starts but does not finish an episode | inviter still 32 | Pass |
| 60 | Friend finishes an episode | inviter +30 = 62 | Pass |
| 61 | Friend finishes another episode | no second reward, inviter stays 62 | Pass |
| 62 | Sign-up with a made-up invite code | account made, nobody rewarded | Pass |
| 63 | Invite code of a guest (no account) | not accepted as an inviter | Pass |

## 4. Buying coins and plans

| # | Test | Expected | Result |
|---|---|---|---|
| 64 | Guest tries to buy coins | 401 create an account first | Pass |
| 65 | Unknown coin pack | 400 | Pass |
| 66 | Unknown product | 400 | Pass |
| 67 | Old single-episode rupee purchase | 400 (replaced by coins) | Pass |
| 68 | Order with a tampered price | server price Rs 99 used | Pass |
| 69 | Paying someone else's order | 404 | Pass |
| 70 | Buy the Rs 99 pack | +200 bought +30 bonus: 62 + 230 = 292 (200 bought, 92 bonus) | Pass |
| 71 | Paying the same order twice | 404, balance stays 292 | Pass |
| 72 | Spend uses bonus coins first | after -10: 200 bought untouched, 82 bonus | Pass |
| 73 | Bonus coins past their date expire | all 82 bonus removed, 200 bought kept | Pass |
| 74 | Expiry is written in the history | a line of -82 "Bonus coins expired", balance 200 | Pass |
| 75 | Spend from bought coins when no bonus is left | 200 - 10 = 190 | Pass |
| 76 | Buy pack p29 | Rs 29 gives 60 + 0 bonus | Pass |
| 77 | Buy pack p249 | Rs 249 gives 500 + 125 bonus | Pass |
| 78 | Buy pack p499 | Rs 499 gives 1000 + 400 bonus | Pass |
| 79 | Fifth pack in a day | allowed | Pass |
| 80 | Sixth pack in a day | 429 daily limit, balance unchanged | Pass |
| 81 | Wallet limit of 20,000 bought coins | 400 wallet full, nothing added | Pass |
| 82 | Buy the weekly plan (Rs 99) | everything open for 7 days | Pass |
| 83 | Subscriber watches a locked episode | allowed, no coins used (still 20) | Pass |
| 84 | Subscriber taps unlock | 400 already open, no coins taken | Pass |
| 85 | Add the 3-month plan on top | end date moves out by 90 more days | Pass |
| 86 | Yearly plan (Rs 899) | accepted | Pass |
| 87 | Plan ended | locked episodes are locked again (403) | Pass |
| 88 | Show pass order | Rs 149 from the server | Pass |
| 89 | Own the show | all 50 Ichchadhari episodes open without coins | Pass |
| 90 | Buying the same show again | 400 already owned | Pass |
| 91 | Pass covers one show only | another show stays locked | Pass |

## 5. Races and abuse

| # | Test | Expected | Result |
|---|---|---|---|
| 92 | 12 taps at once on one episode with 10 coins | one unlock, balance 0, never below zero | Pass |
| 93 | 12 different episodes at once with 10 coins | exactly one opens, balance 0 | Pass |
| 94 | 10 payment confirmations at once for one order | coins added once: 60 | Pass |
| 95 | 10 check-ins at once | paid once: 60 + 2 = 62 | Pass |
| 96 | SQL injection text as a show id | 404, nothing broken | Pass |
| 97 | Path trick on the video address | 403 | Pass |
| 98 | Internal file lib.php | no content served | Pass |
| 99 | Internal file import.php | no content served | Pass |
| 100 | Unknown action | 404 | Pass |

## 6. Admin

| # | Test | Expected | Result |
|---|---|---|---|
| 101 | Admin password too short | refused | Pass |
| 102 | Admin passwords do not match | refused | Pass |
| 103 | Set the admin password | dashboard opens | Pass |
| 104 | Someone else tries to set the admin password afterwards | refused, sees only the sign-in form | Pass |
| 105 | Settings change without signing in | ignored | Pass |
| 106 | Settings change with a bad form token | 403 | Pass |
| 107 | Coin books balance on the admin page | issued = spent + expired + in wallets | Pass |
| 108 | Admin sets Bahu Number 2 to 15 coins an episode | saved | Pass |
| 109 | App shows the new price | Bahu Number 2 costs 15 a locked episode, pass Rs 199 | Pass |
| 110 | Unlock at the new price | 20 - 15 = 5 left | Pass |
| 111 | 5 coins is not enough for a 15-coin episode | 402, balance stays 5 | Pass |
| 112 | Admin lowers Thornbound to 3 free episodes | episode 4 now locked for a new guest | Pass |

## 7. Coin accounting across the whole database

| # | Test | Expected | Result |
|---|---|---|---|
| 113 | Coins issued = spent + expired + still held | 2699 = 135 + 82 + 2482 (after the 2 manual test edits) | Pass |
| 114 | Merge lines cancel out | sum of "merge" entries is 0 | Pass |
| 115 | Every wallet: history adds up line by line and matches the balance | no mismatches in any account | Pass |
| 116 | No wallet below zero | minimum lot is 0 or more | Pass |
| 117 | Each unlocked episode was paid for once | coin unlock lines = coin and ad unlocks | Pass |
| 118 | Rupees taken for packs match coins sold | Rs 934 for 1880 bought coins | Pass |

## 8. On-screen flows (phone size)

| # | Test | Expected | Result |
|---|---|---|---|
| 119 | Home loads for a new visitor | 6 shows, wallet shows 0, "Sign in" button | Pass |
| 120 | No sideways scrolling on a 360 px phone | page width fits the screen | Pass |
| 121 | Sign in opens the sign-in form | mobile number and password fields, link to create an account | Pass |
| 122 | Empty sign-up form | message asks for the name, no account made | Pass |
| 123 | Wrong mobile number | message asks for a 10-digit Indian number | Pass |
| 124 | Short password | message asks for 6 characters | Pass |
| 125 | Sign-up succeeds | toast "+20 coins", wallet shows 20, green +20 chip, button says Account | Pass |
| 126 | Name with HTML in it is shown as text | no script ran, name printed literally | Pass |
| 127 | A free episode with video plays | video running, view counted | Pass |
| 128 | Thornbound episode 1 opens free | no paywall, title shows "The Ninth Assassin" and Free | Pass |
| 129 | Episode list marks free and locked | 5 free then 5 locked | Pass |
| 130 | Paywall states the price and the balance | "Unlock with 10 coins", "You have 20. After this you keep 10." | Pass |
| 131 | Short show hides the show pass | no "Own this show" on Thornbound | Pass |
| 132 | Unlock deducts on screen | wallet 20 -> 10, red "-10 coins" chip, episode plays | Pass |
| 133 | Second unlock | wallet 10 -> 0 | Pass |
| 134 | Paywall with an empty wallet | "Get coins": costs 10, you have 0, need 10 more; auto-unlock does not fire | Pass |
| 135 | Watch an ad | chip shows "+10 -10 coins", wallet stays 0, episode plays | Pass |
| 136 | Coin store lists four packs with episode maths | Rs 29: about 6 episodes; Rs 99: about 23; Rs 249: about 62; Rs 499: about 140 | Pass |
| 137 | Checkout shows the right amount | Rs 99 for 200 coins + 30 bonus | Pass |
| 138 | Buying a pack adds coins and returns to the episode | wallet 230, green +230 chip, paywall now offers "Unlock with 10 coins" | Pass |
| 139 | Unlock from a mixed wallet | wallet 230 -> 220 | Pass |
| 140 | Automatic unlock (switched on by the viewer) | episode 10 opens without a paywall, wallet 220 -> 210 | Pass |
| 141 | After the last episode | returns home, Continue watching row shows | Pass |
| 142 | Wallet shows the split and expiry | 210 total, 200 bought, 10 bonus, expiry note | Pass |
| 143 | History lists every addition and deduction with the balance after each | 9 lines from +20 welcome down to -10 for episode 10 | Pass |
| 144 | Daily check-in from the wallet | wallet 212, day 1 marked, button says come back tomorrow | Pass |
| 145 | Weekly plan | button turns to "Subscribed", coins untouched at 212 | Pass |
| 146 | Subscriber opens a locked episode | plays with no paywall and no coins used | Pass |
| 147 | Like and My list | both counters show 1 | Pass |

## 9. Links and buttons

| # | Test | Expected | Result |
|---|---|---|---|
| 148 | Invite link | points at this site with ?ref= and the account code | Pass |
| 149 | WhatsApp button | opens wa.me with the message and the invite link inside | Pass |

## 8. On-screen flows (phone size)

| # | Test | Expected | Result |
|---|---|---|---|
| 150 | Invited friend signs up and finishes an episode | inviter wallet 212 -> 242 | Pass |
| 151 | Sign out | guest again, wallet 0 | Pass |
| 152 | Sign in with a wrong password | clear message, stays on the form | Pass |
| 153 | Sign in again | wallet back to 242, still subscribed, no coin chip for signing in | Pass |
| 154 | Reload keeps the session | same account and wallet | Pass |

## 9. Links and buttons

| # | Test | Expected | Result |
|---|---|---|---|
| 155 | Every button on: wallet | each one responds, no script errors (3 pressed) | Pass |
| 156 | Every button on: account | each one responds, no script errors (3 pressed) | Pass |
| 157 | Every button on: coin store | each one responds, no script errors (8 pressed) | Pass |
| 158 | Every button on: episode list | each one responds, no script errors (11 pressed) | Pass |
| 159 | Every button on: invite sheet | each one responds, no script errors (2 pressed) | Pass |
| 160 | Paywall for a guest offers every route | get coins, ad, create account, invite, watch everything, not now | Pass |
| 161 | Each paywall route opens and has a way back | all open their screen and return to the paywall | Pass |
| 162 | Address / | loads (200) | Pass |
| 163 | Address /app.js | loads (200) | Pass |
| 164 | Address /api.php?a=health | loads (200) | Pass |
| 165 | Address /api.php?a=state | loads (200) | Pass |
| 166 | Address /api.php?a=wallet | loads (200) | Pass |
| 167 | Address /admin.php | loads (200) | Pass |
| 168 | Address /media/sample.mp4 | loads (200) | Pass |
| 169 | Address /lib.php | blocked or empty (no content) | Pass |
| 170 | Address /poster.php?show=nope | blocked or empty (404) | Pass |
| 171 | Address /video.php?show=thornbound&ep=6 | blocked or empty (403) | Pass |
| 172 | Address /nope.html | blocked or empty (404) | Pass |
| 173 | Admin page links | "Open the app" link present after sign-in page loads without errors | Pass |
| 174 | Browser console during the whole run | no script errors, no server errors (5xx) | Pass |
