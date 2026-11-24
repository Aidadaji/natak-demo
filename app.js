(function () {
"use strict";
var EP_MS = 12000;               // length of the stand-in picture when an episode has no video
var ST = null, cur = null, ep = 1;
var raf = null, startAt = 0, elapsed = 0, paused = false, mode = "ph";
var viewSent = false, adT = null, pendingBuy = null, toastT = null, REF = "";

var $ = function (id) { return document.getElementById(id); };
var vid = $("vid"), stage = $("stage");
function esc(s) { return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;" }[c]; }); }
function fmt(n) { n = +n || 0; if (n < 1000) return String(n); if (n < 1e6) return (n / 1e3).toFixed(n < 1e4 ? 1 : 0).replace(/\.0$/, "") + "K"; return (n / 1e6).toFixed(1).replace(/\.0$/, "") + "M"; }
function show(id) { for (var i = 0; i < ST.shows.length; i++) if (ST.shows[i].id === id) return ST.shows[i]; return null; }
function bg(c) { return "linear-gradient(155deg," + c[0] + "," + c[1] + ")"; }
function art(s) { return s.poster ? "linear-gradient(to top,rgba(18,10,26,.9),rgba(18,10,26,.05) 60%),url(" + s.poster + ") center/cover no-repeat," + bg(s.c) : bg(s.c); }
function canWatch(s, e) { return e <= s.free || ST.me.vip || s.pass || s.open.indexOf(e) > -1; }
function inPlayer() { return $("player").classList.contains("on"); }

try { REF = new URLSearchParams(location.search).get("ref") || ""; if (REF) localStorage.setItem("nt_ref", REF); else REF = localStorage.getItem("nt_ref") || ""; } catch (e) { /* storage may be blocked */ }

function api(a, body) {
  return fetch("api.php?a=" + a, {
    method: body ? "POST" : "GET", credentials: "same-origin",
    headers: { "Content-Type": "application/json", "X-Requested-With": "natak" },
    body: body ? JSON.stringify(body) : undefined
  }).then(function (r) {
    return r.json().catch(function () { return {}; }).then(function (d) {
      if (!r.ok) { var e = new Error(d.error || "Could not reach the server. Check your connection."); e.status = r.status; throw e; }
      if (d.state) ST = d.state;
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
  $("rowHidden").innerHTML = by(function (s) { return s.shelf.indexOf("hidden") > -1; }).map(function (s) { return card(s); }).join("");
  $("rowRevenge").innerHTML = by(function (s) { return s.shelf.indexOf("revenge") > -1; }).map(function (s) { return card(s); }).join("");
  $("foot").textContent = "The first " + h.free + " episodes of " + h.title + " are free. Later episodes open with an ad, an invite, or a payment.";
  $("acctBtn").textContent = me.guest ? "Sign in" : (me.vip ? "Subscribed" : "Account");
  $("acctBtn").className = "pill" + (me.vip ? " vip" : "");
  $("who").textContent = me.guest ? "Guest" : me.name;
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
  if (!canWatch(s, e)) { stage.classList.remove("hasvid"); stage.classList.add("paused"); paywall(); return; }
  closeSheet(); stage.classList.remove("paused");
  if (s.videos.indexOf(e) > -1) {
    mode = "vid"; stage.classList.add("hasvid");
    vid.src = "video.php?show=" + encodeURIComponent(sid) + "&ep=" + e;
    var p = vid.play();
    if (p && p.catch) p.catch(function () { stage.classList.add("paused"); });
  } else { startPlaceholder(); }
}
function startPlaceholder() {
  mode = "ph"; stage.classList.remove("hasvid"); vid.removeAttribute("src");
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
vid.addEventListener("pause", function () { if (mode === "vid" && !vid.ended) stage.classList.add("paused"); });
vid.addEventListener("ended", function () { if (mode === "vid") onEnd(vid.duration); });
vid.addEventListener("error", function () { if (mode === "vid" && vid.getAttribute("src")) { toast("This video could not load. Showing the sample picture.", true); startPlaceholder(); } });

/* ---------- SHEETS ---------- */
function openSheet(html) { $("sheet").innerHTML = html; $("scrim").classList.add("on"); var b = $("sheet").querySelector("input,button,a"); if (b) b.focus(); }
function closeSheet() { clearInterval(adT); $("scrim").classList.remove("on"); $("sheet").innerHTML = ""; }
function opt(act, kind, title, sub, price, cls, dis) {
  return '<button class="opt' + (cls ? " " + cls : "") + '" type="button" data-act="' + act + '"' + (kind ? ' data-kind="' + kind + '"' : "") + (dis ? " disabled" : "") + '><div class="t"><b>' + title + '</b><span>' + sub + '</span></div><div class="p' + (price === "Free" ? " free" : "") + '">' + price + '</div></button>';
}
function paywall() {
  var s = show(cur), me = ST.me, P = ST.price;
  openSheet('<h4>Episode ' + ep + ' is locked</h4><p class="sub">' + esc(s.title) + '. ' + (s.eps - ep + 1) + ' episodes left. Pick a way to keep watching.</p>' +
    opt("ad", "", "Watch an ad", "Opens this episode. " + me.adsLeft + " of " + me.adCap + " left today.", "Free", "", me.adsLeft <= 0) +
    (me.credits > 0 ? opt("credit", "", "Use a free unlock", "You have " + me.credits + " from friends you invited.", "Free") : "") +
    opt("share", "", "Invite a friend", "Get " + ST.inviteReward + " free unlocks when they create an account.", "Free") +
    opt("buy", "ep", "This episode only", "One tap by UPI.", "₹" + P.ep) +
    opt("buy", "pass", "Whole show pass", "All " + s.eps + " episodes of this show, yours to rewatch.", "₹" + P.pass, "best") +
    opt("buy", "quarter", "Every show, 3 months", "One payment covers every show for 90 days.", "₹" + P.quarter) +
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
function buy(kind) {
  if (ST.me.guest) { pendingBuy = kind; authSheet("signup", "Create a free account to pay. Your watch history comes with you."); return; }
  pendingBuy = null;
  var s = show(cur);
  api("order", { kind: kind, show: cur, ep: ep }).then(function (d) {
    var label = kind === "ep" ? "Episode " + ep + " of " + s.title : kind === "pass" ? "Whole show pass: " + s.title : "All shows for 3 months";
    openSheet('<h4>Pay by UPI</h4><p class="sub">' + esc(label) + '</p><div class="pay">Order #' + d.order + '<div class="amt">₹' + d.amount + '</div>Test mode. No money moves and no UPI app opens.</div>' +
      '<button class="wide" type="button" data-act="paid" data-order="' + d.order + '">Complete test payment</button><button class="close" type="button" data-act="paywall">Back</button>');
  }).catch(oops);
}
function inviteLink() { return location.origin + location.pathname + "?ref=" + ST.me.ref; }
function shareScreen() {
  var s = cur ? show(cur) : ST.shows[0], back = cur && inPlayer() ? "resume" : "close";
  if (ST.me.guest) {
    openSheet('<h4>Invite a friend</h4><p class="sub">Create a free account to get your own invite link. You get ' + ST.inviteReward + ' free unlocks for every friend who joins.</p><button class="wide" type="button" data-act="signup">Create free account</button><button class="close" type="button" data-act="' + back + '">Back</button>');
    return;
  }
  var link = inviteLink(), text = "Watch \"" + s.title + "\" with me. The first " + s.free + " episodes are free: " + link;
  api("share", { show: s.id }).catch(function () {});
  openSheet('<h4>Invite a friend</h4><p class="sub">You get ' + ST.inviteReward + ' free unlocks when a friend creates an account from your link.</p>' +
    '<input class="copybox" id="shareLink" readonly aria-label="Your invite link" value="' + esc(link) + '">' +
    '<button class="wide" type="button" data-act="copy">Copy link</button>' +
    '<a class="wide alt" href="https://wa.me/?text=' + encodeURIComponent(text) + '" target="_blank" rel="noopener">Send on WhatsApp</a>' +
    '<button class="close" type="button" data-act="' + back + '">Back</button>');
}
function listScreen() {
  var s = show(cur), h = "";
  for (var e = 1; e <= s.eps; e++) {
    var cls = e <= s.free ? "free" : canWatch(s, e) ? "open" : "lock";
    h += '<button type="button" class="' + cls + (e === ep ? " now" : "") + '" data-act="ep" data-ep="' + e + '" aria-label="Episode ' + e + (cls === "lock" ? ", locked" : "") + '">' + e + '</button>';
  }
  openSheet('<h4>' + esc(s.title) + '</h4><p class="sub">' + esc(s.line) + '</p><p class="stats">' + fmt(s.views) + ' views · ' + fmt(s.likes) + ' likes · ' + fmt(s.saves) + ' saves · ' + fmt(s.shares) + ' shares</p>' +
    '<div class="key" style="margin-top:10px"><span><i style="background:var(--leaf)"></i>Free</span><span><i style="background:var(--marigold)"></i>Opened</span><span><i style="background:var(--sindoor)"></i>Locked</span></div><div class="grid">' + h + '</div><button class="close" type="button" data-act="resume">Close</button>');
}
function authSheet(kind, why) {
  var up = kind === "signup";
  openSheet('<h4>' + (up ? "Create free account" : "Sign in") + '</h4><p class="sub">' + esc(why || (up ? "Keep your history and unlocks on any phone." : "Welcome back.")) + '</p>' +
    '<form id="authForm" data-kind="' + kind + '" novalidate>' +
    (up ? '<label class="field"><span>Your name</span><input id="fName" name="name" maxlength="40" autocomplete="name" required></label>' : "") +
    '<label class="field"><span>Mobile number</span><input id="fPhone" name="phone" inputmode="numeric" maxlength="10" autocomplete="tel-national" placeholder="10 digits" required></label>' +
    '<label class="field"><span>Password' + (up ? " (6 characters or more)" : "") + '</span><input id="fPass" name="password" type="password" autocomplete="' + (up ? "new-password" : "current-password") + '" required></label>' +
    '<div class="formerr" id="authErr" role="alert"></div><button class="wide" type="submit">' + (up ? "Create account" : "Sign in") + '</button></form>' +
    '<button class="linkbtn" type="button" data-act="' + (up ? "login" : "signup") + '">' + (up ? "I already have an account" : "Create a new account") + '</button>' +
    '<button class="close" type="button" data-act="' + (cur && inPlayer() ? "resume" : "close") + '">Not now</button>');
}
function acctScreen() {
  var me = ST.me;
  if (me.guest) { authSheet("signin"); return; }
  var until = me.vip ? new Date(me.vipUntil * 1000).toLocaleDateString("en-IN", { day: "numeric", month: "short", year: "numeric" }) : "";
  var passes = ST.shows.filter(function (s) { return s.pass; }).map(function (s) { return s.title; });
  var n = 0; ST.shows.forEach(function (s) { n += s.open.length; });
  openSheet('<h4>' + esc(me.name) + '</h4><p class="sub">Your account</p><div class="me"><dl>' +
    '<dt>Plan</dt><dd>' + (me.vip ? "All shows until " + until : "Free") + '</dd>' +
    '<dt>Show passes</dt><dd>' + (passes.length ? esc(passes.join(", ")) : "None") + '</dd>' +
    '<dt>Single episodes opened</dt><dd>' + n + '</dd>' +
    '<dt>Free unlocks from invites</dt><dd>' + me.credits + '</dd>' +
    '<dt>Ad unlocks left today</dt><dd>' + me.adsLeft + ' of ' + me.adCap + '</dd></dl></div>' +
    '<button class="wide" type="button" data-act="share" style="margin-top:14px">Invite a friend</button>' +
    '<button class="wide alt" type="button" data-act="logout">Sign out</button><button class="close" type="button" data-act="close">Close</button>');
}
function afterAuth() {
  closeSheet(); if (!inPlayer()) renderHome(); else { updateSide(); $("who").textContent = ST.me.guest ? "Guest" : ST.me.name; }
  if (pendingBuy && cur) { var k = pendingBuy; var s = show(cur); if (canWatch(s, ep)) { pendingBuy = null; play(cur, ep); } else buy(k); }
  else if (cur && inPlayer()) resume();
}

/* ---------- EVENTS ---------- */
document.addEventListener("click", function (ev) {
  var t = ev.target.closest("[data-show]");
  if (t) { play(t.getAttribute("data-show"), parseInt(t.getAttribute("data-ep"), 10) || 1); return; }
  var a = ev.target.closest("[data-act]");
  if (!a || a.disabled) return;
  var act = a.getAttribute("data-act");
  if (act === "ad") adScreen();
  else if (act === "credit") api("use_credit", { show: cur, ep: ep }).then(function () { toast("Episode " + ep + " opened"); play(cur, ep); }).catch(oops);
  else if (act === "share") shareScreen();
  else if (act === "buy") buy(a.getAttribute("data-kind"));
  else if (act === "paid") { a.disabled = true; api("pay_test", { order: +a.getAttribute("data-order") }).then(function () { toast("Payment recorded (test)"); play(cur, ep); }).catch(function (e) { a.disabled = false; oops(e); }); }
  else if (act === "paywall") paywall();
  else if (act === "home") { closeSheet(); pendingBuy = null; go("home"); }
  else if (act === "close") { closeSheet(); pendingBuy = null; }
  else if (act === "resume") { closeSheet(); pendingBuy = null; resume(); }
  else if (act === "ep") play(cur, parseInt(a.getAttribute("data-ep"), 10));
  else if (act === "signup" || act === "login") authSheet(act === "login" ? "signin" : "signup");
  else if (act === "logout") api("logout", {}).then(function () { closeSheet(); cur = null; go("home"); toast("Signed out"); }).catch(oops);
  else if (act === "copy") {
    var box = $("shareLink");
    var done = function () { toast("Link copied"); };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(box.value).then(done).catch(function () { box.select(); toast("Press and hold the link to copy it"); });
    else { box.select(); toast("Press and hold the link to copy it"); }
  }
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
    toast(up ? "Account created" : "Signed in"); afterAuth();
  }).catch(function (e) { btn.disabled = false; var el = $("authErr"); if (el) el.textContent = e.message; });
});
$("backBtn").addEventListener("click", function () { closeSheet(); go("home"); });
$("nextBtn").addEventListener("click", function () { play(cur, ep + 1); });
$("tap").addEventListener("click", togglePause);
$("tap").addEventListener("keydown", function (e) { if (e.key === " " || e.key === "Enter") { e.preventDefault(); togglePause(); } });
$("listBtn").addEventListener("click", function () { hold(); listScreen(); });
$("shareBtn").addEventListener("click", function () { hold(); shareScreen(); });
$("likeBtn").addEventListener("click", function () { api("like", { show: cur }).then(updateSide).catch(oops); });
$("saveBtn").addEventListener("click", function () { api("save", { show: cur }).then(function () { updateSide(); toast(show(cur).saved ? "Added to My list" : "Removed from My list"); }).catch(oops); });
$("acctBtn").addEventListener("click", acctScreen);
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
