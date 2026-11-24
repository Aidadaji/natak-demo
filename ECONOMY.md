# Coin economy (test build, 24 Nov 2026)

All numbers below live in `lib.php` (`ECON`, `PACKS`, `PLANS`) and, per show, in the admin page.

## The rules

| Thing | Value |
|---|---|
| Price of a locked episode | 10 coins (each show can be changed in admin) |
| Coin packs | Rs 29 = 60 coins. Rs 99 = 200 + 30 bonus. Rs 249 = 500 + 125 bonus. Rs 499 = 1,000 + 400 bonus |
| Rupees per episode by pack | Rs 4.83, Rs 4.30, Rs 3.98, Rs 3.56 |
| Plans (no coins needed) | Rs 99 for 1 week, Rs 399 for 3 months, Rs 899 for 1 year |
| Show pass | Rs 149 by default, offered only where it is cheaper than buying the locked episodes with coins |
| Free coins | 20 on sign-up. Daily check-in 2, 2, 3, 3, 4, 4, 10 over a 7-day streak. 30 to the inviter when an invited friend finishes an episode |
| Rewarded ad | Earns exactly one episode's price and opens that episode. 5 a day |
| Expiry | Bought coins never expire. Earned coins expire after 7 days. Pack bonus coins after 30 days. Bonus coins are spent first, soonest expiry first |
| Limits | 5 packs a day and 20,000 bought coins per account. Guests cannot buy or check in |
| Automatic unlock | Off by default. The viewer can switch it on |

Every change to a wallet is one line in `coin_tx` with the balance after it, so the app can show each addition and deduction, and the admin page can check that coins issued = coins spent + coins expired + coins still held.

## Why these numbers

- Coins, a subscription and rewarded ads side by side is the standard set-up for this kind of app. ReelShort prices an episode at 60 coins with 500 coins for $4.99, adds bonus coins to bigger packs, lets earned coins expire in about a week while bought coins do not, and caps ad unlocks at about five a day.
- In India, Kuku TV charges Rs 399 for a quarter and Rs 899 for a year and also sells coins. The plans here match those two prices so the app is not the expensive one.
- The Rs 29 pack is the first purchase: it covers the 5 locked Thornbound episodes with 10 coins left over.
- A 50-episode show with 8 free episodes needs 420 coins, which is Rs 198 to Rs 249 in packs. The Rs 149 show pass and the Rs 399 quarter are both better deals, which is the point: coins make the bigger products look cheap.
- Automatic unlock is off by default because wallets that drain themselves are a common complaint about ReelShort.

## Does it make money

It depends on numbers nobody has measured for this app yet. Using the assumptions already in the MicroDrama Playbook (18% GST, 8.5% payment fees, Rs 0.60 ad income per non-paying install, Rs 0.10 delivery per install), each rupee collected leaves Rs 0.775, and the average paying viewer must spend at least:

| Cost per install | Share who pay | Each payer must spend |
|---|---|---|
| Rs 2 (unpaid reach) | 5% | Rs 40 |
| Rs 9 (paid ads) | 5% | Rs 220 |
| Rs 9 (paid ads) | 3% | Rs 366 |
| Rs 12 (paid ads) | 4% | Rs 372 |

What that means for this price list:

- With unpaid reach (Shorts, Reels, WhatsApp shares), even a viewer who buys one Rs 99 pack is profitable.
- With paid installs at Rs 9, a payer who buys only the Rs 29 or Rs 99 pack loses money. The system only works if the typical payer reaches the Rs 249 pack, the Rs 399 quarter, or buys twice. That is why the store highlights those two, and why the weekly plan is Rs 99 and not Rs 49.
- Free viewing is capped at about 5 episodes a day (5 ads plus the check-in). An ad unlock is assumed to earn about Rs 0.06, against Rs 3.56 to Rs 4.83 for a coin unlock, so ads keep non-payers watching but do not pay the bills.
- A short show like Thornbound (5 locked episodes) can be finished free in one day with the 5 ads. Short shows work as free samples that lead into long ones, not as earners.

## Not settled

- The share of viewers who pay and what they spend are unknown until real viewers use it. Read them off the admin page after a test.
- Mobile numbers are not verified, so one person can make several accounts and collect the 20 welcome coins each time. Add OTP before real money or real ad spend.
- Payments and ads are in test mode. No Razorpay, no ad network.
- Plans do not renew by themselves yet.

## Sources

- ReelShort coin and pack prices: https://geekvibesnation.com/cost-to-finish-short-drama/ and https://lootbar.gg/blog/en/reelshort-subscription-price-explained.html
- Earned coins expire, bought coins do not, automatic unlock: https://www.qwe.edu.pl/tutorial/reelshort-free-watch-full-series/
- Five rewarded ads a day and the coins, ads, subscription mix: https://spyro-soft.com/blog/media-and-entertainment/microdrama-monetisation-models-why-the-real-innovation-isnt-the-content-its-the-payment-stack and https://www.revenuecat.com/blog/growth/short-drama-mobile-acquisition-monetization
- Kuku TV prices: https://www.hollywoodreporterindia.com/features/insight/indias-microdrama-boom-how-data-desire-and-aritifical-intelligence-are-reshaping-entertainment
