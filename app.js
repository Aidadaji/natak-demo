(function () {
"use strict";
var EP_MS = 12000;               // length of the stand-in picture when an episode has no video
var ST = null, cur = null, ep = 1;
var raf = null, startAt = 0, elapsed = 0, paused = false, mode = "ph";
var viewSent = false, adT = null, pendingBuy = null, toastT = null, deltaT = null, REF = "", AUTO = false;
var Q = "", G = "", CC = true, swipedAt = 0, preFor = "", pre = document.createElement("video");
pre.preload = "auto"; pre.muted = true;

var $ = function (id) { return document.getElementById(id); };
var vid = $("vid"), stage = $("stage");
function esc(s) { return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;" }[c]; }); }
function fmt(n) { n = +n || 0; if (n < 1000) return String(n); if (n < 1e6) return (n / 1e3).toFixed(n < 1e4 ? 1 : 0).replace(/\.0$/, "") + "K"; return (n / 1e6).toFixed(1).replace(/\.0$/, "") + "M"; }
function show(id) { for (var i = 0; i < ST.shows.length; i++) if (ST.shows[i].id === id) return ST.shows[i]; return null; }
function bg(c) { return "linear-gradient(155deg," + c[0] + "," + c[1] + ")"; }
function art(s) { return s.poster ? "linear-gradient(to top,rgba(18,10,26,.9),rgba(18,10,26,.05) 60%),url(" + s.poster + ") center/cover no-repeat," + bg(s.c) : bg(s.c); }
function canWatch(s, e) { return e <= s.free || ST.me.vip || s.pass || s.open.indexOf(e) > -1; }
function inPlayer() { return $("player").classList.contains("on"); }
function coins() { return ST ? ST.me.coins.total : 0; }
function day(ts) { return new Date(ts * 1000).toLocaleDateString("en-IN", { day: "numeric", month: "short" }); }
function when(ts) { return new Date(ts * 1000).toLocaleString("en-IN", { day: "numeric", month: "short", hour: "numeric", minute: "2-digit" }); }
var COIN = '<i class="ci"></i>';

try {
  REF = new URLSearchParams(location.search).get("ref") || "";
  if (REF) localStorage.setItem("nt_ref", REF); else REF = localStorage.getItem("nt_ref") || "";
  AUTO = localStorage.getItem("nt_auto") === "1";
  CC = localStorage.getItem("nt_cc") !== "0";
} catch (e) { /* storage may be blocked */ }

/* Every change to the wallet is shown: the coin count updates and a +n / -n chip floats up. */
function showDelta(text, cls) {
  var d = $("delta"); d.textContent = text; d.className = "delta " + cls; void d.offsetWidth; d.className = "delta on " + cls;
  clearTimeout(deltaT); deltaT = setTimeout(function () { d.className = "delta"; }, 2000);
  ["coinBtn", "coinBtn2"].forEach(function (id) { var b = $(id); b.classList.remove("bump"); void b.offsetWidth; b.classList.add("bump"); });
}
function paintCoins() { $("coinN").textContent = fmt(coins()); $("coinN2").textContent = fmt(coins()); }
function api(a, body) {
  var before = ST ? coins() : null;
  return fetch("api.php?a=" + a, {
    method: body ? "POST" : "GET", credentials: "same-origin",
    headers: { "Content-Type": "application/json", "X-Requested-With": "natak" },
    body: body ? JSON.stringify(body) : undefined
  }).then(function (r) {
    return r.json().catch(function () { return {}; }).then(function (d) {
      if (!r.ok) { var e = new Error(d.error || "Could not reach the server. Check your connection."); e.status = r.status; throw e; }
      if (d.state) {
        ST = d.state; paintCoins();
        var diff = coins() - (before == null ? coins() : before), quiet = a === "reset" || a === "delete_account" || a === "login" || a === "logout" || a === "state" || a === "wallet";
        if (a === "ad_done") showDelta("+" + d.earned + "  −" + d.spent + " coins", "both");
        else if (!quiet && diff > 0) showDelta("+" + diff + " coins", "plus");
        else if (!quiet && diff < 0) showDelta("−" + (-diff) + " coins", "minus");
      }
      return d;
    });
  });
}
function toast(msg, bad) { var t = $("toast"); t.textContent = msg; t.className = "toast on" + (bad ? " err" : ""); clearTimeout(toastT); toastT = setTimeout(function () { t.className = "toast"; }, 3000); }
function oops(e) { toast(e.message || "Something went wrong.", true); }

/* ---------- HOME ---------- */
function card(s, label, e) {
  return '<button class="card" type="button" data-show="' + s.id + '" data-ep="' + (e || 1) + '"><div class="poster" style="background:' + art(s) + '"><div class="glow"></div><span class="tag">' + esc(s.genre) + '</span><b>' + esc(s.title) + '</b></div><span>' + (label || s.eps + " episodes · " + fmt(s.views) + " views") + '</span></button>';
}
function renderHome() {
  var h = ST.shows[0], me = ST.me;
  $("hero").style.background = art(h);
  $("hero").setAttribute("data-show", h.id); $("hero").setAttribute("data-ep", h.last || 1);
  $("hero").innerHTML = '<div class="glow"></div><h2>' + esc(h.title) + '</h2><p>' + esc(h.line) + '</p><span class="cta">&#9654; ' + (h.last ? "Continue episode " + h.last : "Watch episode 1 free") + '</span>';
  var by = function (f) { return ST.shows.filter(f); };
  var cont = by(function (s) { return s.last > 0; }), list = by(function (s) { return s.saved; });
  $("contSec").hidden = !cont.length; $("rowCont").innerHTML = cont.map(function (s) { return card(s, "Continue episode " + s.last, s.last); }).join("");
  $("listSec").hidden = !list.length; $("rowList").innerHTML = list.map(function (s) { return card(s); }).join("");
  $("rowAll").innerHTML = ST.shows.map(function (s) { return card(s); }).join("");
  $("rowTrend").innerHTML = ST.shows.slice().sort(function (a, b) { return b.views - a.views; }).slice(0, 6).map(function (s, i) { return card(s, "#" + (i + 1) + " · " + fmt(s.views) + " views"); }).join("");
  var genres = []; ST.shows.forEach(function (s) { if (genres.indexOf(s.genre) < 0) genres.push(s.genre); });
  if (G && genres.indexOf(G) < 0) G = "";
  $("chips").innerHTML = '<button type="button" data-genre=""' + (G ? "" : ' class="on" aria-pressed="true"') + '>All</button>' + genres.map(function (g) { return '<button type="button" data-genre="' + esc(g) + '"' + (G === g ? ' class="on" aria-pressed="true"' : "") + '>' + esc(g) + '</button>'; }).join("");
  applyFilter();
  $("rowHidden").innerHTML = by(function (s) { return s.shelf.indexOf("hidden") > -1; }).map(function (s) { return card(s); }).join("");
  $("rowRevenge").innerHTML = by(function (s) { return s.shelf.indexOf("revenge") > -1; }).map(function (s) { return card(s); }).join("");
  $("foot").textContent = "The first " + h.free + " episodes of " + h.title + " are free. Later episodes cost " + h.price + " coins each. Earn coins with a daily check-in, an ad, or by inviting a friend, or buy a pack.";
  $("acctBtn").textContent = me.guest ? "Sign in" : (me.vip ? "Subscribed" : "Account");
  $("acctBtn").className = "pill" + (me.vip ? " vip" : "");
  $("who").textContent = me.guest ? "Guest" : me.name;
  paintCoins();
}
/* Search and genre chips narrow the catalogue to a result grid; with neither set the shelves show. */
function applyFilter() {
  var q = Q.trim().toLowerCase(), on = !!(q || G);
  $("home").classList.toggle("searching", on); $("resSec").hidden = !on;
  if (!on) return;
  var hits = ST.shows.filter(function (s) { return (!G || s.genre === G) && (!q || (s.title + " " + s.genre + " " + s.line).toLowerCase().indexOf(q) > -1); });
  $("resH").textContent = (G || "Results") + (q ? ' for "' + Q.trim() + '"' : "") + " (" + hits.length + ")";
  $("rowRes").innerHTML = hits.map(function (s) { return card(s); }).join("");
  $("resEmpty").hidden = hits.length > 0;
}
function go(view) {
  $("home").classList.toggle("on", view === "home");
  $("player").classList.toggle("on", view === "player");
  if (view === "home") { stop(); renderHome(); }
}

/* ---------- PLAYER ---------- */
function stop() { if (raf) { cancelAnimationFrame(raf); raf = null; } try { vid.pause(); } catch (e) { /* not started */ } }
function sendView(done, secs) { api("view", { show: cur, ep: ep, seconds: Math.round(secs || 0), done: !!done }).then(updateSide).catch(function () { /* counting a view must never interrupt playback */ }); }
function updateSide() {
  var s = cur && show(cur); if (!s) return;
  $("likeBtn").classList.toggle("on", s.liked); $("likeN").textContent = fmt(s.likes);
  $("saveBtn").classList.toggle("on", s.saved); $("saveN").textContent = fmt(s.saves);
  $("pEp").textContent = "Episode " + ep + " of " + s.eps + (s.titles[ep] ? " · " + s.titles[ep] : "") + (ep <= s.free ? " · Free" : "") + " · " + fmt(s.views) + " views";
}
function drawStage(s, e) {
  $("home").classList.remove("on"); $("player").classList.add("on");
  $("pShow").textContent = s.title;
  var sc = $("scene"); sc.style.background = bg(s.c);
  sc.innerHTML = '<i style="width:60%;height:38%;left:-8%;top:8%;background:#f5a524"></i><i style="width:55%;height:40%;right:-10%;bottom:14%;background:' + s.c[0] + ';animation-delay:-4s"></i><i style="width:40%;height:28%;left:30%;top:40%;background:#fff;opacity:.16;animation-delay:-2s"></i>';
  $("namecard").innerHTML = e === 1 ? "<b>" + esc(s.who[0]) + "</b>" + esc(s.who[1]) : "";
  $("namecard").style.display = e === 1 ? "block" : "none";
  $("cap").innerHTML = s.caps[0]; $("cap").setAttribute("data-i", "0");
  $("prog").style.width = "0%";
  updateSide();
}
function play(sid, e) {
  var s = show(sid);
  if (!s) return;
  if (e > s.eps) { closeSheet(); toast("That was the last episode"); go("home"); return; }
  cur = sid; ep = e; stop(); viewSent = false; elapsed = 0; paused = false;
  drawStage(s, e);
  if (!canWatch(s, e)) {
    stage.classList.remove("hasvid"); stage.classList.add("paused");
    if (AUTO && coins() >= s.price) { unlockNow(true); return; }   // the viewer chose to spend coins automatically
    paywall(); return;
  }
  closeSheet(); stage.classList.remove("paused");
  if (s.videos.indexOf(e) > -1) {
    mode = "vid"; stage.classList.add("hasvid");
    vid.src = vurl(sid, e);
    setSubs(s, e);
    var p = vid.play();
    if (p && p.catch) p.catch(function () { stage.classList.add("paused"); });
  } else { startPlaceholder(); }
}
function vurl(sid, e) { return "video.php?show=" + encodeURIComponent(sid) + "&ep=" + e; }
/* Subtitles: one text track per episode when the admin uploaded a file; the CC button shows or hides it. */
function setSubs(s, e) {
  while (vid.firstChild) vid.removeChild(vid.firstChild);
  var has = !!s && (s.subs || []).indexOf(e) > -1, b = $("ccBtn");
  b.hidden = !has; b.classList.toggle("on", has && CC); b.setAttribute("aria-pressed", CC ? "true" : "false");
  if (!has) return;
  var tr = document.createElement("track");
  tr.kind = "subtitles"; tr.srclang = "en"; tr.label = "Subtitles"; tr.src = "sub.php?show=" + encodeURIComponent(s.id) + "&ep=" + e;
  vid.appendChild(tr); tr.track.mode = CC ? "showing" : "hidden";
}
/* Once an episode is playing, start fetching the next one so the swipe to it does not wait. */
function preloadNext() {
  var s = cur && show(cur), n = ep + 1, key = cur + ":" + n;
  if (!s || preFor === key) return;
  if (n <= s.eps && canWatch(s, n) && s.videos.indexOf(n) > -1) { preFor = key; pre.src = vurl(cur, n); pre.load(); }
}
function nextEp() { play(cur, ep + 1); }
function prevEp() { if (ep > 1) play(cur, ep - 1); else toast("This is the first episode"); }
function startPlaceholder() {
  mode = "ph"; stage.classList.remove("hasvid"); vid.removeAttribute("src"); setSubs(null, 0);
  startAt = performance.now(); raf = requestAnimationFrame(tick);
}
function tick(now) {
  if (!paused) elapsed += now - startAt;
  startAt = now;
  var s = show(cur), f = Math.min(1, elapsed / EP_MS);
  $("prog").style.width = (f * 100).toFixed(1) + "%";
  var i = Math.min(s.caps.length - 1, Math.floor(f * s.caps.length));
  if ($("cap").getAttribute("data-i") !== String(i)) { $("cap").setAttribute("data-i", i); $("cap").innerHTML = s.caps[i]; }
  if (!viewSent && elapsed >= 3000) { viewSent = true; sendView(false, 3); }
  if (f >= 1) { raf = null; onEnd(EP_MS / 1000); return; }
  raf = requestAnimationFrame(tick);
}
function onEnd(secs) {
  sendView(true, secs);
  if ($("autoplay").checked) play(cur, ep + 1);
  else { paused = true; stage.classList.add("paused"); }
}
function togglePause() {
  var s = show(cur); if (!s || !canWatch(s, ep)) return;
  if (mode === "vid") { if (vid.ended) play(cur, ep); else if (vid.paused) vid.play().catch(function () {}); else vid.pause(); return; }
  if (!raf && elapsed >= EP_MS) { play(cur, ep); return; }
  paused = !paused; stage.classList.toggle("paused", paused);
}
function hold() { if (mode === "vid") { try { vid.pause(); } catch (e) {} } else { paused = true; } stage.classList.add("paused"); }
function resume() {
  var s = show(cur); if (!s) return;
  if (!canWatch(s, ep)) { paywall(); return; }
  if (mode === "vid") vid.play().catch(function () {}); else { paused = false; stage.classList.remove("paused"); }
}
vid.addEventListener("timeupdate", function () {
  if (mode !== "vid" || !vid.duration) return;
  $("prog").style.width = (vid.currentTime / vid.duration * 100).toFixed(1) + "%";
  if (!viewSent && vid.currentTime >= 3) { viewSent = true; sendView(false, vid.currentTime); }
});
vid.addEventListener("play", function () { stage.classList.remove("paused"); });
vid.addEventListener("playing", function () { if (mode === "vid") setTimeout(preloadNext, 1200); });
vid.addEventListener("pause", function () { if (mode === "vid" && !vid.ended) stage.classList.add("paused"); });
vid.addEventListener("ended", function () { if (mode === "vid") onEnd(vid.duration); });
vid.addEventListener("error", function () { if (mode === "vid" && vid.getAttribute("src")) { toast("This video could not load. Showing the sample picture.", true); startPlaceholder(); } });

/* ---------- SHEETS ---------- */
function openSheet(html) { $("sheet").innerHTML = html; $("scrim").classList.add("on"); $("sheet").scrollTop = 0; var b = $("sheet").querySelector("input:not([type=checkbox]),button,a"); if (b) b.focus(); }
function closeSheet() { clearInterval(adT); $("scrim").classList.remove("on"); $("sheet").innerHTML = ""; }
function opt(act, attrs, title, sub, price, cls, dis) {
  return '<button class="opt' + (cls ? " " + cls : "") + '" type="button" data-act="' + act + '"' + (attrs || "") + (dis ? " disabled" : "") + '><div class="t"><b>' + title + '</b><span>' + sub + '</span></div><div class="p' + (price === "Free" ? " free" : "") + '">' + price + '</div></button>';
}
function backAct() { return cur && inPlayer() ? (canWatch(show(cur), ep) ? "resume" : "paywall") : "close"; }

function unlockNow(silent) {
  var s = cur, e = ep;
  api("unlock", { show: s, ep: e }).then(function () { if (!silent) toast("Episode " + e + " opened"); play(s, e); })
    .catch(function (err) { if (err.status === 402) { store("You need more coins for this episode."); } else { oops(err); paywall(); } });
}
function paywall() {
  var s = show(cur), me = ST.me, have = coins(), need = s.price, c = me.checkin, o = "";
  if (have >= need) o += opt("unlock", "", "Unlock with " + need + " coins", "You have " + have + ". After this you keep " + (have - need) + ".", COIN + " " + need, "best");
  else o += opt("store", "", "Get coins", "This episode costs " + need + ". You have " + have + ", so you need " + (need - have) + " more.", COIN + " " + need, "best");
  o += opt("ad", "", "Watch an ad", "Earns " + need + " coins and opens this episode. " + me.adsLeft + " of " + me.adCap + " left today.", "Free", "", me.adsLeft <= 0);
  if (me.guest) o += opt("signup", "", "Create a free account", "Get " + ST.econ.welcome + " coins now, plus coins every day you check in.", "+" + ST.econ.welcome);
  else if (!c.done) o += opt("checkin", "", "Daily check-in", "Day " + c.day + " of 7. Come back every day for more.", "+" + c.reward);
  o += opt("share", "", "Invite a friend", "Get " + ST.econ.invite + " coins when they join and finish an episode.", "+" + ST.econ.invite);
  o += opt("store", ' data-tab="plans"', "Watch everything", "Every show, no coins needed, from ₹" + ST.econ.plans[0].rs + " a week.", "₹" + ST.econ.plans[0].rs + "+");
  if (s.passWorth) o += opt("buy", ' data-kind="pass"', "Own this show", "All " + s.eps + " episodes, yours to rewatch.", "₹" + s.passRs);
  openSheet('<h4>Episode ' + ep + ' is locked</h4><p class="sub">' + esc(s.title) + (s.titles[ep] ? ": " + esc(s.titles[ep]) : "") + '. ' + (s.eps - ep + 1) + ' episodes left at ' + need + ' coins each.</p>' + o +
    '<label class="check" for="autoUn"><input type="checkbox" id="autoUn"' + (AUTO ? " checked" : "") + '> Unlock the next episodes automatically while I have coins</label>' +
    '<button class="close" type="button" data-act="home">Not now</button>');
}
function adScreen() {
  var s = cur, e = ep, n = 5;
  api("ad_start", { show: s, ep: e }).then(function () {
    openSheet('<h4>Ad break</h4><p class="sub">A real ad would play here.</p><div class="adbox"><span>Episode opens in</span><b id="adN">' + n + '</b></div><p class="note">Test mode: no ad network is connected yet.</p>');
    adT = setInterval(function () {
      n--; var el = $("adN"); if (!el) { clearInterval(adT); return; }
      el.textContent = Math.max(0, n);
      if (n <= 0) {
        clearInterval(adT);
        api("ad_done", { show: s, ep: e }).then(function () { toast("Episode " + e + " opened"); play(s, e); }).catch(function (err) { oops(err); paywall(); });
      }
    }, 1000);
  }).catch(oops);
}
function store(why, tab) {
  var E = ST.econ, s = cur ? show(cur) : ST.shows[0], per = s.price, h = "", first = ST.me.firstOffer;
  var packs = E.packs.map(function (p, i) {
    var extra = first ? Math.floor(p.coins * E.firstPct / 100) : 0, total = p.coins + p.bonus + extra, epsN = Math.floor(total / per);
    return opt("buy", ' data-kind="coins" data-pack="' + p.id + '"', COIN + " " + p.coins + (p.bonus + extra ? ' <span style="display:inline;color:var(--leaf);font-weight:700">+' + (p.bonus + extra) + " bonus</span>" : ""),
      "About " + epsN + " episodes. ₹" + (p.rs / epsN).toFixed(2) + " an episode.", "₹" + p.rs, i === 2 ? "best" : "");
  }).join("");
  var plans = E.plans.map(function (p, i) {
    return opt("buy", ' data-kind="' + p.id + '"', "Everything for " + p.label, "Every show and every episode. No coins, no ads.", "₹" + p.rs, i === 1 ? "best" : "");
  }).join("");
  h += '<h4>' + (tab === "plans" ? "Watch everything" : "Get coins") + '</h4><p class="sub">' + esc(why || "You have " + coins() + " coins. One episode costs " + per + ".") + '</p>';
  var offer = first ? '<div class="offer">First top-up offer: ' + E.firstPct + '% extra coins on the pack you choose. One time only.</div>' : "";
  h += tab === "plans" ? plans + '<p class="sec">Or buy coins</p>' + offer + packs : offer + packs + '<p class="sec">Or skip coins</p>' + plans;
  h += '<p class="note">Coins you buy never expire. Bonus coins that come with a pack last ' + E.packBonusDays + ' days and are used first. Test mode: no money moves.</p>';
  h += '<button class="close" type="button" data-act="' + backAct() + '">Back</button>';
  openSheet(h);
}
function buy(kind, pack) {
  if (ST.me.guest) { pendingBuy = { kind: kind, pack: pack }; authSheet("signup", "Create a free account to pay. You also get " + ST.econ.welcome + " coins."); return; }
  pendingBuy = null;
  var s = cur ? show(cur) : null, body = { kind: kind };
  if (kind === "coins") body.pack = pack; else if (kind === "pass") body.show = cur;
  api("order", body).then(function (d) {
    var label;
    if (kind === "coins") { var p = ST.econ.packs.filter(function (x) { return x.id === pack; })[0]; var fx = ST.me.firstOffer ? Math.floor(p.coins * ST.econ.firstPct / 100) : 0; label = p.coins + " coins" + (p.bonus + fx ? " + " + (p.bonus + fx) + " bonus" : ""); }
    else if (kind === "pass") label = "Own " + s.title;
    else label = "Everything for " + ST.econ.plans.filter(function (x) { return x.id === kind; })[0].label;
    openSheet('<h4>Pay by UPI</h4><p class="sub">' + esc(label) + '</p><div class="pay">Order #' + d.order + '<div class="amt">₹' + d.amount + '</div>Test mode. No money moves and no UPI app opens.</div>' +
      '<button class="wide" type="button" data-act="paid" data-order="' + d.order + '">Complete test payment</button><button class="close" type="button" data-act="store">Back</button>');
  }).catch(oops);
}
function inviteLink() { return location.origin + location.pathname + "?ref=" + ST.me.ref; }
function shareScreen() {
  var s = cur ? show(cur) : ST.shows[0], back = backAct();
  if (ST.me.guest) {
    openSheet('<h4>Invite a friend</h4><p class="sub">Create a free account to get your own invite link. You get ' + ST.econ.invite + ' coins for every friend who joins and finishes an episode.</p><button class="wide" type="button" data-act="signup">Create free account</button><button class="close" type="button" data-act="' + back + '">Back</button>');
    return;
  }
  var link = inviteLink(), text = "Watch \"" + s.title + "\" with me. The first " + s.free + " episodes are free: " + link;
  api("share", { show: s.id }).catch(function () {});
  openSheet('<h4>Invite a friend</h4><p class="sub">You get ' + ST.econ.invite + ' coins when a friend creates an account from your link and finishes an episode. They get ' + ST.econ.welcome + ' coins to start.</p>' +
    '<input class="copybox" id="shareLink" readonly aria-label="Your invite link" value="' + esc(link) + '">' +
    '<button class="wide" type="button" data-act="copy">Copy link</button>' +
    '<a class="wide alt" href="https://wa.me/?text=' + encodeURIComponent(text) + '" target="_blank" rel="noopener">Send on WhatsApp</a>' +
    '<button class="close" type="button" data-act="' + back + '">Back</button>');
}
function listScreen() {
  var s = show(cur), h = "";
  for (var e = 1; e <= s.eps; e++) {
    var cls = e <= s.free ? "free" : canWatch(s, e) ? "open" : "lock";
    h += '<button type="button" class="' + cls + (e === ep ? " now" : "") + '" data-act="ep" data-ep="' + e + '" aria-label="Episode ' + e + (cls === "lock" ? ", locked, " + s.price + " coins" : "") + '">' + e + '</button>';
  }
  openSheet('<h4>' + esc(s.title) + '</h4><p class="sub">' + esc(s.line) + '</p><p class="stats">' + fmt(s.views) + ' views · ' + fmt(s.likes) + ' likes · ' + fmt(s.saves) + ' saves · ' + fmt(s.shares) + ' shares</p>' +
    '<div class="key" style="margin-top:10px"><span><i style="background:var(--leaf)"></i>Free</span><span><i style="background:var(--marigold)"></i>Opened</span><span><i style="background:var(--sindoor)"></i>Locked, ' + s.price + ' coins</span></div><div class="grid">' + h + '</div><button class="close" type="button" data-act="resume">Close</button>');
}
function walletScreen() {
  var me = ST.me, w = me.coins, c = me.checkin, E = ST.econ, lad = "";
  E.ladder.forEach(function (n, i) {
    var d = i + 1, cls = c.done ? (d <= c.day ? "got" : "") : (d < c.day ? "got" : d === c.day ? "today" : "");
    lad += '<div class="' + cls + '">Day ' + d + '<b>+' + n + '</b></div>';
  });
  var check = me.guest ? '<button class="wide" type="button" data-act="signup">Create free account: +' + E.welcome + ' coins</button>'
    : c.done ? '<button class="wide alt" type="button" disabled>Checked in today. Come back tomorrow.</button>'
    : '<button class="wide" type="button" data-act="checkin">Check in: +' + c.reward + ' coins</button>';
  openSheet('<h4>My coins</h4><div class="bal">' + '<b id="walBal">' + w.total + '</b><span class="sub" style="margin:0">' + w.paid + ' bought · ' + w.bonus + ' bonus</span></div>' +
    (w.next ? '<p class="note" style="margin:0 0 6px">' + w.next.n + ' bonus coins expire on ' + day(w.next.at) + '. Bonus coins are used first.</p>' : '<p class="note" style="margin:0 0 6px">Coins you buy never expire. Coins you earn last ' + E.bonusDays + ' days.</p>') +
    '<p class="sec">Daily check-in</p><div class="ladder">' + lad + '</div>' + check +
    '<p class="sec">Today\'s tasks</p>' + (ST.tasks || []).map(function (t) {
      var ready = t.have >= t.need;
      return '<div class="task"><div class="t">' + esc(t.label) + '<span>' + t.have + ' of ' + t.need + ' done · +' + t.coins + ' coins</span></div><button type="button" data-act="task" data-task="' + t.id + '"' + (t.claimed || !ready || me.guest ? " disabled" : "") + '>' + (t.claimed ? "Claimed" : ready ? (me.guest ? "Sign in" : "Claim +" + t.coins) : "Not yet") + '</button></div>';
    }).join("") +
    '<button class="wide alt" type="button" data-act="store">Get coins</button><button class="wide alt" type="button" data-act="share">Invite a friend: +' + E.invite + ' coins</button>' +
    '<label class="check" for="autoUn" style="margin-top:12px"><input type="checkbox" id="autoUn"' + (AUTO ? " checked" : "") + '> Unlock the next episodes automatically while I have coins</label>' +
    '<p class="sec">History</p><ul class="hist" id="hist"><li><div class="w">Loading...</div></li></ul>' +
    '<button class="close" type="button" data-act="' + backAct() + '">Close</button>');
  api("wallet").then(function (d) {
    var el = $("hist"); if (!el) return;
    if (!d.history.length) { el.innerHTML = '<li><div class="w">Nothing yet. Coins you earn and spend will be listed here.</div></li>'; return; }
    el.innerHTML = d.history.map(function (t) {
      return '<li><div class="w">' + esc(t.label) + '<span>' + when(t.at) + '</span></div><div class="d ' + (t.delta >= 0 ? "p" : "m") + '">' + (t.delta >= 0 ? "+" : "−") + Math.abs(t.delta) + '</div><div class="b">= ' + t.balance + '</div></li>';
    }).join("");
  }).catch(function () { var el = $("hist"); if (el) el.innerHTML = '<li><div class="w">Could not load the history.</div></li>'; });
}
function authSheet(kind, why) {
  var up = kind === "signup";
  openSheet('<h4>' + (up ? "Create free account" : "Sign in") + '</h4><p class="sub">' + esc(why || (up ? "Get " + ST.econ.welcome + " coins and keep your history on any phone." : "Welcome back.")) + '</p>' +
    '<form id="authForm" data-kind="' + kind + '" novalidate>' +
    (up ? '<label class="field"><span>Your name</span><input id="fName" name="name" maxlength="40" autocomplete="name" required></label>' : "") +
    '<label class="field"><span>Mobile number</span><input id="fPhone" name="phone" inputmode="numeric" maxlength="14" autocomplete="tel-national" placeholder="10 digits" required></label>' +
    '<label class="field"><span>Password' + (up ? " (6 characters or more)" : "") + '</span><input id="fPass" name="password" type="password" autocomplete="' + (up ? "new-password" : "current-password") + '" required></label>' +
    '<div class="formerr" id="authErr" role="alert"></div><button class="wide" type="submit">' + (up ? "Create account" : "Sign in") + '</button></form>' +
    (up ? '<p class="fine">By creating an account you agree to the <a href="legal.php?p=terms">Terms</a> and <a href="legal.php?p=privacy">Privacy policy</a>.</p>' : "") +
    '<button class="linkbtn" type="button" data-act="' + (up ? "login" : "signup") + '">' + (up ? "I already have an account" : "Create a new account") + '</button>' +
    (up ? "" : '<button class="linkbtn" type="button" data-act="forgot">Forgot password?</button>') +
    '<button class="close" type="button" data-act="' + backAct() + '">Not now</button>');
}
function forgotSheet() {
  var sup = ST.support ? "Contact support: " + ST.support + "." : "Contact support.";
  openSheet('<h4>Reset your password</h4><p class="sub">' + esc(sup + " They will check it is you and send a 6-digit code. It works once, for 30 minutes.") + '</p>' +
    '<form id="resetForm" novalidate>' +
    '<label class="field"><span>Mobile number</span><input id="rPhone" inputmode="numeric" maxlength="14" autocomplete="tel-national" placeholder="10 digits" required></label>' +
    '<label class="field"><span>6-digit code</span><input id="rCode" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required></label>' +
    '<label class="field"><span>New password (6 characters or more)</span><input id="rPass" type="password" autocomplete="new-password" required></label>' +
    '<div class="formerr" id="resetErr" role="alert"></div><button class="wide" type="submit">Set new password</button></form>' +
    '<button class="linkbtn" type="button" data-act="login">Back to sign in</button>');
}
function acctScreen() {
  var me = ST.me;
  if (me.guest) { authSheet("signin"); return; }
  var until = me.vip ? new Date(me.vipUntil * 1000).toLocaleDateString("en-IN", { day: "numeric", month: "short", year: "numeric" }) : "";
  var passes = ST.shows.filter(function (s) { return s.pass; }).map(function (s) { return s.title; });
  var n = 0; ST.shows.forEach(function (s) { n += s.open.length; });
  openSheet('<h4>' + esc(me.name) + '</h4><p class="sub">Your account</p><div class="me"><dl>' +
    '<dt>Plan</dt><dd>' + (me.vip ? "All shows until " + until : "Free") + '</dd>' +
    '<dt>Coins</dt><dd>' + me.coins.total + '</dd>' +
    '<dt>Shows you own</dt><dd>' + (passes.length ? esc(passes.join(", ")) : "None") + '</dd>' +
    '<dt>Episodes unlocked</dt><dd>' + n + '</dd>' +
    '<dt>Ad unlocks left today</dt><dd>' + me.adsLeft + ' of ' + me.adCap + '</dd></dl></div>' +
    '<button class="wide" type="button" data-act="wallet" style="margin-top:14px">My coins and history</button>' +
    '<button class="wide alt" type="button" data-act="share">Invite a friend</button>' +
    '<button class="wide alt" type="button" data-act="feedback">Help and feedback</button>' +
    '<button class="wide alt" type="button" data-act="logout">Sign out</button>' +
    '<button class="wide danger" type="button" data-act="delacct">Delete account</button>' +
    '<p class="fine"><a href="legal.php?p=privacy">Privacy policy</a> · <a href="legal.php?p=terms">Terms of use</a></p>' +
    '<button class="close" type="button" data-act="close">Close</button>');
}
function feedbackSheet() {
  var me = ST.me;
  openSheet('<h4>Help and feedback</h4><p class="sub">' + esc(ST.support ? "Tell us what went wrong or what you would like. You can also reach us at " + ST.support + "." : "Tell us what went wrong or what you would like.") + '</p>' +
    '<form id="fbForm" novalidate><label class="field"><span>Your message</span><textarea id="fbMsg" maxlength="1000" required></textarea></label>' +
    (me.guest ? '<label class="field"><span>Mobile number or email, if you want a reply</span><input id="fbContact" maxlength="120" autocomplete="email"></label>' : "") +
    '<div class="formerr" id="fbErr" role="alert"></div><button class="wide" type="submit">Send</button></form>' +
    '<button class="close" type="button" data-act="' + backAct() + '">Close</button>');
}
function deleteSheet() {
  var me = ST.me;
  openSheet('<h4>Delete your account</h4><p class="sub">This removes your account, your ' + me.coins.total + ' coins, every episode you unlocked, any plan or show pass, your list and your history. It happens at once and cannot be undone.</p>' +
    '<form id="delForm" novalidate><label class="field"><span>Enter your password to confirm</span><input id="delPass" type="password" autocomplete="current-password" required></label>' +
    '<div class="formerr" id="delErr" role="alert"></div><button class="wide danger" type="submit">Delete my account for ever</button></form>' +
    '<button class="wide" type="button" data-act="close">Keep my account</button>');
}
function afterAuth() {
  closeSheet(); if (!inPlayer()) renderHome(); else { updateSide(); $("who").textContent = ST.me.guest ? "Guest" : ST.me.name; }
  if (pendingBuy) { var k = pendingBuy; pendingBuy = null; buy(k.kind, k.pack); }
  else if (cur && inPlayer()) resume();
}
function afterPaid() {
  if (cur && inPlayer()) { var s = show(cur); if (canWatch(s, ep)) play(cur, ep); else paywall(); }
  else { closeSheet(); renderHome(); walletScreen(); }
}

/* ---------- EVENTS ---------- */
document.addEventListener("click", function (ev) {
  var g = ev.target.closest("[data-genre]");
  if (g) { G = g.getAttribute("data-genre"); renderHome(); return; }
  var t = ev.target.closest("[data-show]");
  if (t) { play(t.getAttribute("data-show"), parseInt(t.getAttribute("data-ep"), 10) || 1); return; }
  var a = ev.target.closest("[data-act]");
  if (!a || a.disabled) return;
  var act = a.getAttribute("data-act");
  if (act === "ad") adScreen();
  else if (act === "unlock") { a.disabled = true; unlockNow(false); }
  else if (act === "checkin") { a.disabled = true; api("checkin", {}).then(function (d) { toast("Checked in: +" + d.earned + " coins"); if (cur && inPlayer() && !canWatch(show(cur), ep)) paywall(); else walletScreen(); }).catch(function (e) { a.disabled = false; oops(e); }); }
  else if (act === "store") store("", a.getAttribute("data-tab"));
  else if (act === "wallet") walletScreen();
  else if (act === "share") shareScreen();
  else if (act === "buy") buy(a.getAttribute("data-kind"), a.getAttribute("data-pack"));
  else if (act === "paid") { a.disabled = true; api("pay_test", { order: +a.getAttribute("data-order") }).then(function (d) { toast(d.earned ? "Added " + d.earned + " coins" : "Payment recorded (test)"); afterPaid(); }).catch(function (e) { a.disabled = false; oops(e); }); }
  else if (act === "paywall") paywall();
  else if (act === "home") { closeSheet(); pendingBuy = null; go("home"); }
  else if (act === "close") { closeSheet(); pendingBuy = null; }
  else if (act === "resume") { closeSheet(); pendingBuy = null; resume(); }
  else if (act === "ep") play(cur, parseInt(a.getAttribute("data-ep"), 10));
  else if (act === "forgot") forgotSheet();
  else if (act === "feedback") feedbackSheet();
  else if (act === "delacct") deleteSheet();
  else if (act === "task") { a.disabled = true; api("task_claim", { task: a.getAttribute("data-task") }).then(function (d) { toast("Task done: +" + d.earned + " coins"); walletScreen(); }).catch(function (e) { a.disabled = false; oops(e); }); }
  else if (act === "signup" || act === "login") authSheet(act === "login" ? "signin" : "signup");
  else if (act === "logout") api("logout", {}).then(function () { closeSheet(); cur = null; go("home"); toast("Signed out"); }).catch(oops);
  else if (act === "copy") {
    var box = $("shareLink");
    var done = function () { toast("Link copied"); };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(box.value).then(done).catch(function () { box.select(); toast("Press and hold the link to copy it"); });
    else { box.select(); toast("Press and hold the link to copy it"); }
  }
});
document.addEventListener("change", function (ev) {
  if (ev.target.id !== "autoUn") return;
  AUTO = ev.target.checked;
  try { localStorage.setItem("nt_auto", AUTO ? "1" : "0"); } catch (e) { /* storage may be blocked */ }
  toast(AUTO ? "Next episodes will unlock with your coins" : "You will be asked before coins are spent");
});
document.addEventListener("submit", function (ev) {
  var f = ev.target; if (f.id !== "fbForm" && f.id !== "delForm") return;
  ev.preventDefault();
  var btn = f.querySelector("button[type=submit]"), fb = f.id === "fbForm", err = $(fb ? "fbErr" : "delErr");
  btn.disabled = true; err.textContent = "";
  var call = fb ? api("feedback", { msg: $("fbMsg").value, contact: $("fbContact") ? $("fbContact").value : "" }) : api("delete_account", { password: $("delPass").value });
  call.then(function () {
    if (fb) { closeSheet(); toast("Thank you. Your message was sent."); if (cur && inPlayer()) resume(); return; }
    closeSheet(); cur = null; pendingBuy = null; go("home"); toast("Your account was deleted");
  }).catch(function (e) { btn.disabled = false; var x = $(fb ? "fbErr" : "delErr"); if (x) x.textContent = e.message; });
});
document.addEventListener("submit", function (ev) {
  var f = ev.target; if (f.id !== "resetForm") return;
  ev.preventDefault();
  var btn = f.querySelector("button[type=submit]"), el = $("resetErr");
  var body = { phone: $("rPhone").value.trim(), code: $("rCode").value.trim(), password: $("rPass").value };
  if (body.password.length < 6) { el.textContent = "Use a password of 6 characters or more."; return; }
  btn.disabled = true; el.textContent = "";
  api("reset", body).then(function () { authSheet("signin", "Password changed. Sign in with the new one."); toast("Password changed"); })
    .catch(function (e) { btn.disabled = false; var x = $("resetErr"); if (x) x.textContent = e.message; });
});
document.addEventListener("submit", function (ev) {
  var f = ev.target; if (f.id !== "authForm") return;
  ev.preventDefault();
  var up = f.getAttribute("data-kind") === "signup", btn = f.querySelector("button[type=submit]");
  var body = { phone: $("fPhone").value.trim(), password: $("fPass").value };
  if (up) { body.name = $("fName").value.trim(); body.ref = REF; }
  btn.disabled = true; $("authErr").textContent = "";
  api(up ? "signup" : "login", body).then(function () {
    if (up) { try { localStorage.removeItem("nt_ref"); } catch (e) {} REF = ""; }
    toast(up ? "Account created: +" + ST.econ.welcome + " coins" : "Signed in"); afterAuth();
  }).catch(function (e) { btn.disabled = false; var el = $("authErr"); if (el) el.textContent = e.message; });
});
$("backBtn").addEventListener("click", function () { closeSheet(); go("home"); });
$("nextBtn").addEventListener("click", nextEp);
$("tap").addEventListener("click", function () { if (Date.now() - swipedAt > 500) togglePause(); });
$("tap").addEventListener("keydown", function (e) {
  if (e.key === " " || e.key === "Enter") { e.preventDefault(); togglePause(); }
  else if (e.key === "ArrowDown") { e.preventDefault(); nextEp(); }
  else if (e.key === "ArrowUp") { e.preventDefault(); prevEp(); }
});
/* Swipe up for the next episode, down for the one before. */
(function () {
  var y0 = null, x0 = 0, tap = $("tap");
  tap.addEventListener("touchstart", function (e) { if (e.touches.length === 1) { y0 = e.touches[0].clientY; x0 = e.touches[0].clientX; } }, { passive: true });
  tap.addEventListener("touchend", function (e) {
    if (y0 === null) return;
    var t = e.changedTouches[0], dy = t.clientY - y0, dx = t.clientX - x0; y0 = null;
    if (Math.abs(dy) < 70 || Math.abs(dy) < Math.abs(dx) * 1.5) return;
    swipedAt = Date.now(); if (dy < 0) nextEp(); else prevEp();
  }, { passive: true });
  tap.addEventListener("touchcancel", function () { y0 = null; }, { passive: true });
})();
$("ccBtn").addEventListener("click", function () {
  CC = !CC; try { localStorage.setItem("nt_cc", CC ? "1" : "0"); } catch (e) { /* storage may be blocked */ }
  var b = $("ccBtn"); b.classList.toggle("on", CC); b.setAttribute("aria-pressed", CC ? "true" : "false");
  if (vid.textTracks[0]) vid.textTracks[0].mode = CC ? "showing" : "hidden";
  toast(CC ? "Subtitles on" : "Subtitles off");
});
$("q").addEventListener("input", function () { Q = this.value; applyFilter(); });
$("listBtn").addEventListener("click", function () { hold(); listScreen(); });
$("shareBtn").addEventListener("click", function () { hold(); shareScreen(); });
$("likeBtn").addEventListener("click", function () { api("like", { show: cur }).then(updateSide).catch(oops); });
$("saveBtn").addEventListener("click", function () { api("save", { show: cur }).then(function () { updateSide(); toast(show(cur).saved ? "Added to My list" : "Removed from My list"); }).catch(oops); });
$("acctBtn").addEventListener("click", acctScreen);
$("coinBtn").addEventListener("click", walletScreen);
$("coinBtn2").addEventListener("click", function () { hold(); walletScreen(); });
$("scrim").addEventListener("click", function (e) {
  if (e.target !== $("scrim")) return;
  if (cur && inPlayer()) { var s = show(cur); if (canWatch(s, ep)) { closeSheet(); resume(); } }
  else closeSheet();
});

api("state").then(renderHome).catch(function (e) {
  $("foot").textContent = "The app could not load. " + e.message;
  toast(e.message, true);
});
})();
