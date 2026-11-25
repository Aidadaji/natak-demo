<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
$p = ($_GET['p'] ?? '') === 'terms' ? 'terms' : 'privacy';
$support = (string) (setting('support') ?? '');
$contact = $support !== '' ? htmlspecialchars($support, ENT_QUOTES, 'UTF-8') : 'the Help and feedback form inside the app';
header('Cache-Control: no-cache');
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $p === 'terms' ? 'Terms of use' : 'Privacy policy' ?> | Natak</title>
<style>
:root{color-scheme:dark}
body{margin:0;background:#1b1026;color:#f6ecdf;font:16px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif}
main{max-width:680px;margin:0 auto;padding:24px 18px 60px}
h1{font-size:26px;margin:8px 0 4px}h2{font-size:17px;margin:26px 0 6px;color:#f5a524}
p,li{color:#e6d9ea}a{color:#f5a524}small{color:#b9a6c4}ul{padding-left:20px}
.draft{border:1px solid #4a3560;border-radius:10px;padding:10px 12px;color:#b9a6c4;font-size:14px}
</style></head><body><main>
<p><a href="./">&#8249; Back to the app</a></p>
<?php if ($p === 'privacy'): ?>
<h1>Privacy policy</h1><small>Last updated 25 November 2026</small>
<p class="draft">Working draft for the test build. It describes what this app does today. Have it checked by a lawyer before a public launch.</p>
<h2>Who we are</h2>
<p>Natak is a short-drama app run by AIdadaji (aidadaji.com). Contact: <?= $contact ?>.</p>
<h2>What we collect</h2>
<ul>
<li><b>Account details you give us:</b> your name, mobile number and a password. The password is stored only in scrambled (hashed) form; we cannot read it.</li>
<li><b>What you do in the app:</b> episodes you watch and finish, likes, My list, shares, daily check-ins and tasks.</li>
<li><b>Coins and payments:</b> coins you earn, buy and spend, and a record of each order. Card or UPI details are handled by the payment provider and are not stored by us.</li>
<li><b>Messages you send us</b> through Help and feedback.</li>
<li><b>One cookie</b> that keeps you signed in on this device. Guests get one too, so their free episodes and coins are remembered.</li>
</ul>
<h2>Why we use it</h2>
<ul><li>To run your account and wallet, and unlock the episodes you paid for or earned.</li><li>To count views and show you where you stopped.</li><li>To prevent abuse of free coins and to answer your messages.</li></ul>
<h2>Who sees it</h2>
<p>We do not sell your data. It is stored on our hosting provider's servers. When real payments and ads are switched on, the payment provider and the ad network will receive the information they need to process a payment or show an ad, under their own policies.</p>
<h2>How long we keep it</h2>
<p>For as long as you have an account. When you delete your account, your profile, coins, unlocks, likes and list are removed at once. View counts, coin totals and order records are kept without your identity, as anonymous figures and where tax law requires records to be kept.</p>
<h2>Your choices</h2>
<ul><li><b>Delete your account:</b> Account, then Delete account. It is immediate and cannot be undone.</li><li><b>Ask for a copy or a correction</b> of your data: contact us.</li></ul>
<h2>Children</h2>
<p>The app is not meant for children under 13. Some stories contain mature themes.</p>
<h2>Changes</h2>
<p>If this policy changes, the date above changes and the new text appears here.</p>
<?php else: ?>
<h1>Terms of use</h1><small>Last updated 25 November 2026</small>
<p class="draft">Working draft for the test build. Have it checked by a lawyer before a public launch.</p>
<h2>The service</h2>
<p>Natak lets you watch short drama episodes. The first episodes of a show are free. Later episodes are opened with coins, a rewarded ad, a show pass or a plan.</p>
<h2>Your account</h2>
<p>Keep your password private. One person per account. You are responsible for what is done from your account.</p>
<h2>Coins</h2>
<ul>
<li>Coins are for use inside this app only. They cannot be exchanged for money or transferred to another person.</li>
<li>Coins you buy do not expire. Bonus coins (welcome, check-in, tasks, invites and bonus coins that come with a pack) expire on the date shown in My coins, and are used first.</li>
<li>An episode opened with coins stays open on your account.</li>
<li>We may change coin prices and rewards for future purchases. Coins you already hold keep their value in episodes.</li>
</ul>
<h2>Plans and passes</h2>
<p>A plan opens every show until the date shown in your account. A show pass opens one show permanently on your account.</p>
<h2>Payments and refunds</h2>
<p>This is a test build: no real money is taken. When payments go live, prices will be shown before you pay and will include taxes. If you are charged and do not receive what you bought, contact us and we will correct it or refund it.</p>
<h2>Fair use</h2>
<p>Do not copy, download, record or redistribute the videos. Do not create fake accounts or use automated tools to collect free coins. We may remove coins gained this way and close such accounts.</p>
<h2>Content</h2>
<p>Stories are fiction. Some contain mature themes and are not suitable for children.</p>
<h2>Changes and contact</h2>
<p>We may update these terms; the date above will change. Contact: <?= $contact ?>.</p>
<?php endif; ?>
</main></body></html>
