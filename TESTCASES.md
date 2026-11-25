# Test cases and results (25 Nov 2026)

Run on a copy of the app on a test server (PHP 8.3, 8 workers), not on the live site. 253 of 253 passed.

Server sections call the app's server directly. On-screen sections drive the app in a phone-size browser.

Password reset has 24 further server checks and a screen walk-through in `reset_test.py`; all passed on 25 Nov.

Not covered: real payments, a real ad network, a real phone (Android or iPhone), OTP, the installed APK, and form submissions on the live Hostinger site.


## 1. Guest and registration

| # | Test | Expected | Result |
| --- | --- | --- | --- |
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
| --- | --- | --- | --- |
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
| --- | --- | --- | --- |
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
| --- | --- | --- | --- |
| 64 | Guest tries to buy coins | 401 create an account first | Pass |
| 65 | Unknown coin pack | 400 | Pass |
| 66 | Unknown product | 400 | Pass |
| 67 | Old single-episode rupee purchase | 400 (replaced by coins) | Pass |
| 68 | Order with a tampered price | server price Rs 99 used | Pass |
| 69 | Paying someone else's order | 404 | Pass |
| 70 | Buy the Rs 99 pack | +200 bought +30 bonus +100 first top-up bonus: 62 + 330 = 392 (200 bought, 192 bonus) | Pass |
| 71 | Paying the same order twice | 404, balance stays 392 | Pass |
| 72 | Spend uses bonus coins first | after -10: 200 bought untouched, 182 bonus | Pass |
| 73 | Bonus coins past their date expire | all 82 bonus removed, 200 bought kept | Pass |
| 74 | Expiry is written in the history | a line of -182 "Bonus coins expired", balance 200 | Pass |
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
| --- | --- | --- | --- |
| 92 | 12 taps at once on one episode with 10 coins | one unlock, balance 0, never below zero | Pass |
| 93 | 12 different episodes at once with 10 coins | exactly one opens, balance 0 | Pass |
| 94 | 10 payment confirmations at once for one order | coins added once: 60 + 30 first top-up bonus = 90 | Pass |
| 95 | 10 check-ins at once | paid once: 90 + 2 = 92 | Pass |
| 96 | SQL injection text as a show id | 404, nothing broken | Pass |
| 97 | Path trick on the video address | 403 | Pass |
| 98 | Internal file lib.php | no content served | Pass |
| 99 | Internal file import.php | no content served | Pass |
| 100 | Unknown action | 404 | Pass |

## 6. Admin

| # | Test | Expected | Result |
| --- | --- | --- | --- |
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

## 8. Tasks and first top-up offer

| # | Test | Expected | Result |
| --- | --- | --- | --- |
| 113 | State lists the daily tasks | 3 tasks, none claimed, progress 0 | Pass |
| 114 | New visitor is offered the first top-up bonus | firstOffer true, 50% | Pass |
| 115 | Guest claims a task | 401 | Pass |
| 116 | Claim before the task is done | 400 with progress, no coins | Pass |
| 117 | Unknown task | 400 | Pass |
| 118 | Finishing the same episode three times | counts as 1 | Pass |
| 119 | Three different episodes finished, one only started | watch3 shows 3 of 3; watch10 shows 3 of 10 | Pass |
| 120 | Claim the finished task | +2 coins: 20 + 2 = 22, logged as a task | Pass |
| 121 | Claim the same task again | 409, balance stays 22 | Pass |
| 122 | Claim the 10-episode task at 3 of 10 | 400 | Pass |
| 123 | 10 claims at once for the share task | paid once: 22 + 2 = 24 | Pass |
| 124 | First coin pack, Rs 29 | 60 bought + 30 first top-up bonus = 90 added | Pass |
| 125 | First top-up bonus in the history | its own line, +30 | Pass |
| 126 | Second coin pack | no extra: 60 added | Pass |

## 9. Help and feedback

| # | Test | Expected | Result |
| --- | --- | --- | --- |
| 127 | Message too short | 400 | Pass |
| 128 | Message over 1000 characters | 400 | Pass |
| 129 | Message without the app header | 403 | Pass |
| 130 | Send a message | saved with the sender name and number | Pass |
| 131 | Guest sends a message with a contact | saved as Guest | Pass |
| 132 | Sixth message in a day | 429 | Pass |
| 133 | Admin sees the messages, script shown as text | listed and escaped | Pass |
| 134 | Admin marks a message handled | saved | Pass |

## 10. Subtitles

| # | Test | Expected | Result |
| --- | --- | --- | --- |
| 135 | Upload an .srt for a free episode | saved | Pass |
| 136 | Upload a .vtt for a locked episode | saved | Pass |
| 137 | Upload a file that is not subtitles | refused | Pass |
| 138 | Subtitle upload with a bad form token | 403 | Pass |
| 139 | App is told which episodes have subtitles | Thornbound: episodes 1 and 7 | Pass |
| 140 | Subtitles for a free episode | served as WebVTT, .srt times converted, Hindi text intact | Pass |
| 141 | Subtitles for a locked episode, as a guest | 403: the dialogue is not leaked | Pass |
| 142 | Subtitles for an episode that has none | 404 | Pass |
| 143 | Path trick on the subtitle address | 403 | Pass |
| 144 | Remove subtitles | episode 7 no longer listed | Pass |

## 11. Delete account

| # | Test | Expected | Result |
| --- | --- | --- | --- |
| 145 | Guest tries to delete an account | 401 | Pass |
| 146 | Delete with the wrong password | 401, account still there | Pass |
| 147 | Delete without the app header | 403 | Pass |
| 148 | Delete with the right password | done; the phone is a guest again with 0 coins | Pass |
| 149 | Nothing personal is left behind | no rows for that account in any table | Pass |
| 150 | Deleted wallet is written off in the ledger | a "closed" line for the coins given up | Pass |
| 151 | Order record is kept without the person | 1 paid order with no owner | Pass |
| 152 | View count is unchanged | the total stays | Pass |
| 153 | Sign in to the deleted account | 401 | Pass |
| 154 | Same number signs up again | a fresh account with 20 welcome coins | Pass |

## 12. Pages and install files

| # | Test | Expected | Result |
| --- | --- | --- | --- |
| 155 | Page legal.php?p=privacy | 200 and the right content | Pass |
| 156 | Page legal.php?p=terms | 200 and the right content | Pass |
| 157 | Page legal.php?p=zzz | 200 and the right content | Pass |
| 158 | Page manifest.webmanifest | 200 and the right content | Pass |
| 159 | Page sw.js | 200 and the right content | Pass |
| 160 | Page icons/icon-192.png | 200 | Pass |

## 7. Coin accounting across the whole database

| # | Test | Expected | Result |
| --- | --- | --- | --- |
| 161 | Coins issued = spent + expired + still held | 3133 = 145 + 282 + 2706 (after the 2 manual test edits) | Pass |
| 162 | Merge lines cancel out | sum of "merge" entries is 0 | Pass |
| 163 | Every wallet: history adds up line by line and matches the balance | no mismatches in any account | Pass |
| 164 | No wallet below zero | minimum lot is 0 or more | Pass |
| 165 | Each unlocked episode was paid for once | coin unlock lines = coin and ad unlocks (deleted accounts aside) | Pass |
| 166 | Rupees taken for packs match coins sold | Rs 1021 for 2060 bought coins | Pass |

## 8. On-screen flows (phone size)

| # | Test | Expected | Result |
| --- | --- | --- | --- |
| 167 | Home loads for a new visitor | 6 shows, wallet shows 0, "Sign in" button | Pass |
| 168 | No sideways scrolling on a 360 px phone | page width fits the screen | Pass |
| 169 | Sign in opens the sign-in form | mobile number and password fields, link to create an account | Pass |
| 170 | Empty sign-up form | message asks for the name, no account made | Pass |
| 171 | Wrong mobile number | message asks for a 10-digit Indian number | Pass |
| 172 | Short password | message asks for 6 characters | Pass |
| 173 | Sign-up succeeds | toast "+20 coins", wallet shows 20, green +20 chip, button says Account | Pass |
| 174 | Name with HTML in it is shown as text | no script ran, name printed literally | Pass |
| 175 | A free episode with video plays | video running, view counted | Pass |
| 176 | Thornbound episode 1 opens free | no paywall, title shows "The Ninth Assassin" and Free | Pass |
| 177 | Episode list marks free and locked | 5 free then 5 locked | Pass |
| 178 | Paywall states the price and the balance | "Unlock with 10 coins", "You have 20. After this you keep 10." | Pass |
| 179 | Short show hides the show pass | no "Own this show" on Thornbound | Pass |
| 180 | Unlock deducts on screen | wallet 20 -> 10, red "-10 coins" chip, episode plays | Pass |
| 181 | Second unlock | wallet 10 -> 0 | Pass |
| 182 | Paywall with an empty wallet | "Get coins": costs 10, you have 0, need 10 more; auto-unlock does not fire | Pass |
| 183 | Watch an ad | chip shows "+10 -10 coins", wallet stays 0, episode plays | Pass |
| 184 | Coin store lists four packs with episode maths | first top-up offer shown; Rs 29: about 9 episodes; Rs 99: about 33; Rs 249: about 87; Rs 499: about 190 | Pass |
| 185 | Checkout shows the right amount | Rs 99 for 200 coins + 130 bonus | Pass |
| 186 | Buying a pack adds coins and returns to the episode | wallet 330, green +330 chip, paywall now offers "Unlock with 10 coins" | Pass |
| 187 | Unlock from a mixed wallet | wallet 330 -> 320 | Pass |
| 188 | Automatic unlock (switched on by the viewer) | episode 10 opens without a paywall, wallet 320 -> 310 | Pass |
| 189 | After the last episode | returns home, Continue watching row shows | Pass |
| 190 | Wallet shows the split and expiry | 310 total, 200 bought, 110 bonus, expiry note | Pass |
| 191 | History lists every addition and deduction with the balance after each | 10 lines from +20 welcome down to -10 for episode 10 | Pass |
| 192 | Daily check-in from the wallet | wallet 312, day 1 marked, button says come back tomorrow | Pass |
| 193 | Weekly plan | button turns to "Subscribed", coins untouched at 312 | Pass |
| 194 | Subscriber opens a locked episode | plays with no paywall and no coins used | Pass |
| 195 | Like and My list | both counters show 1 | Pass |

## 9. Links and buttons

| # | Test | Expected | Result |
| --- | --- | --- | --- |
| 196 | Invite link | points at this site with ?ref= and the account code | Pass |
| 197 | WhatsApp button | opens wa.me with the message and the invite link inside | Pass |

## 8. On-screen flows (phone size)

| # | Test | Expected | Result |
| --- | --- | --- | --- |
| 198 | Invited friend signs up and finishes an episode | inviter wallet 312 -> 342 | Pass |
| 199 | Sign out | guest again, wallet 0 | Pass |
| 200 | Sign in with a wrong password | clear message, stays on the form | Pass |
| 201 | Sign in again | wallet back to 342, still subscribed, no coin chip for signing in | Pass |
| 202 | Reload keeps the session | same account and wallet | Pass |

## 9. Links and buttons

| # | Test | Expected | Result |
| --- | --- | --- | --- |
| 203 | Every button on: wallet | each one responds, no script errors (4 pressed) | Pass |
| 204 | Every button on: account | each one responds, no script errors (5 pressed) | Pass |
| 205 | Every button on: coin store | each one responds, no script errors (8 pressed) | Pass |
| 206 | Every button on: episode list | each one responds, no script errors (11 pressed) | Pass |
| 207 | Every button on: invite sheet | each one responds, no script errors (2 pressed) | Pass |
| 208 | Paywall for a guest offers every route | get coins, ad, create account, invite, watch everything, not now | Pass |
| 209 | Each paywall route opens and has a way back | all open their screen and return to the paywall | Pass |
| 210 | Address / | loads (200) | Pass |
| 211 | Address /app.js | loads (200) | Pass |
| 212 | Address /api.php?a=health | loads (200) | Pass |
| 213 | Address /api.php?a=state | loads (200) | Pass |
| 214 | Address /api.php?a=wallet | loads (200) | Pass |
| 215 | Address /admin.php | loads (200) | Pass |
| 216 | Address /media/sample.mp4 | loads (200) | Pass |
| 217 | Address /lib.php | blocked or empty (no content) | Pass |
| 218 | Address /poster.php?show=nope | blocked or empty (404) | Pass |
| 219 | Address /video.php?show=thornbound&ep=6 | blocked or empty (403) | Pass |
| 220 | Address /nope.html | blocked or empty (404) | Pass |
| 221 | Admin page links | "Open the app" link present after sign-in page loads without errors | Pass |
| 222 | Browser console during the whole run | no script errors, no server errors (5xx) | Pass |

## 13. New features on screen (phone size, touch)

| # | Test | Expected | Result |
| --- | --- | --- | --- |
| 223 | Home has search, genre chips and a Trending row | search box, All + one chip per genre, 6 trending cards | Pass |
| 224 | Home fits the phone width | no sideways scroll | Pass |
| 225 | Search for 'thorn' | 1 result (Thornbound), shelves and hero hidden | Pass |
| 226 | Search with no match | 'No show matches' message | Pass |
| 227 | Clear the search | shelves come back | Pass |
| 228 | Tap a genre chip | only shows of that genre, chip highlighted | Pass |
| 229 | Tap All | shelves come back | Pass |
| 230 | Footer links | Privacy and Terms | Pass |
| 231 | Guest sends empty feedback | error under the form | Pass |
| 232 | Guest sends feedback | thank-you message, sheet closes | Pass |
| 233 | Sign-up sheet links the terms | Terms and Privacy policy links | Pass |
| 234 | Wallet lists today's tasks | 3 tasks, all 'Not yet' and disabled | Pass |
| 235 | After finishing 3 episodes | first task says 'Claim +2', shows 3 of 3 | Pass |
| 236 | Claim the task | wallet 20 -> 22, green +2 chip, button says Claimed | Pass |
| 237 | Store shows the first top-up offer | banner present | Pass |
| 238 | After the first pack | wallet 112, offer banner gone | Pass |
| 239 | Episode with subtitles | CC button shown and on, subtitle track showing | Pass |
| 240 | Subtitle text loads | 1 cue with the uploaded line | Pass |
| 241 | Tap CC | subtitles hidden, message shown | Pass |
| 242 | Next episode is fetched early | a request for episode 2's video while episode 1 plays | Pass |
| 243 | Swipe up | goes to episode 2, CC button hidden (no subtitles there) | Pass |
| 244 | Swipe down | back to episode 1, subtitles stay off as chosen | Pass |
| 245 | Swipe down on episode 1 | stays, says it is the first episode | Pass |
| 246 | A small finger move | does not change episode | Pass |
| 247 | Down arrow key | next episode | Pass |
| 248 | Account sheet | Help and feedback, Delete account, Privacy and Terms links | Pass |
| 249 | Delete screen warns clearly | names the coins that will be lost and that it cannot be undone | Pass |
| 250 | Wrong password | 'Wrong password.', still signed in | Pass |
| 251 | Right password | account gone: button says Sign in, wallet 0, no coin chip | Pass |
| 252 | Privacy page on a phone | readable, no sideways scroll, link back to the app | Pass |
| 253 | No script errors in the whole run | none | Pass |
