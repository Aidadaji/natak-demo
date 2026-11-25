from playwright.sync_api import sync_playwright
import re, requests, json
B="http://127.0.0.1:8765/"; res=[]
def chk(what, exp, ok, info=""): res.append((what,exp,bool(ok),"" if ok else str(info)[:200])); print(("PASS" if ok else "FAIL"), what, "" if ok else "| got: "+str(info)[:200])
a=requests.Session(); r=a.post(B+"admin.php",data={"act":"setup","password":"correct-horse-9","password2":"correct-horse-9"})
if 'name="csrf"' not in r.text: a.post(B+"admin.php",data={"act":"login","password":"correct-horse-9"}); r=a.get(B+"admin.php")
csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',r.text).group(1)
r=a.post(B+"admin.php",data={"csrf":csrf,"act":"subs","show":"ichcha","ep":"1"},files={"sub":("e1.vtt","WEBVTT\n\n00:00:00.000 --> 00:00:09.000\nSubtitle test line\n","text/vtt")})
assert "subtitles saved" in r.text
SW="""(dy)=>{const el=document.getElementById('tap');const r=el.getBoundingClientRect();const x=r.left+r.width/2,y=r.top+r.height/2;
const mk=(yy)=>new Touch({identifier:1,target:el,clientX:x,clientY:yy});
el.dispatchEvent(new TouchEvent('touchstart',{touches:[mk(y)],changedTouches:[mk(y)],bubbles:true}));
el.dispatchEvent(new TouchEvent('touchend',{touches:[],changedTouches:[mk(y+dy)],bubbles:true}));}"""
with sync_playwright() as p:
    b=p.chromium.launch(); ctx=b.new_context(viewport={"width":390,"height":844},has_touch=True,is_mobile=True); pg=ctx.new_page(); errs=[]; reqs=[]
    pg.on("pageerror",lambda e:errs.append(str(e))); pg.on("request",lambda r:reqs.append(r.url))
    pg.goto(B); pg.wait_for_selector("#rowAll .card")
    chips=pg.eval_on_selector_all("#chips button","x=>x.map(b=>b.textContent)")
    chk("Home has search, genre chips and a Trending row","search box, All + one chip per genre, 6 trending cards",pg.is_visible("#q") and chips[0]=="All" and len(chips)>=4 and pg.locator("#rowTrend .card").count()==6,chips)
    chk("Home fits the phone width","no sideways scroll",pg.evaluate("document.documentElement.scrollWidth<=document.documentElement.clientWidth"))
    pg.fill("#q","thorn")
    chk("Search for 'thorn'","1 result (Thornbound), shelves and hero hidden",pg.locator("#rowRes .card").count()==1 and "Thornbound" in pg.inner_text("#rowRes") and not pg.is_visible("#hero") and not pg.is_visible("#rowAll") and '(1)' in pg.inner_text("#resH"),pg.inner_text("#resH"))
    pg.fill("#q","zzzz"); chk("Search with no match","'No show matches' message",pg.is_visible("#resEmpty") and pg.locator("#rowRes .card").count()==0)
    pg.fill("#q",""); chk("Clear the search","shelves come back",pg.is_visible("#hero") and pg.is_visible("#rowAll") and not pg.is_visible("#resSec"))
    g=chips[1]; pg.click("#chips button >> nth=1")
    tags=pg.eval_on_selector_all("#rowRes .card .tag","x=>x.map(t=>t.textContent)")
    chk("Tap a genre chip","only shows of that genre, chip highlighted",len(tags)>=1 and all(t==g for t in tags) and pg.get_attribute("#chips button.on","data-genre")==g,(g,tags))
    pg.click("#chips button >> nth=0"); chk("Tap All","shelves come back",pg.is_visible("#rowAll") and not pg.is_visible("#resSec"))
    pg.screenshot(path="n_home.png")
    hrefs=pg.eval_on_selector_all(".links a","x=>x.map(a=>a.getAttribute('href'))")
    chk("Footer links","Privacy and Terms",hrefs==["legal.php?p=privacy","legal.php?p=terms"],hrefs)
    # feedback as guest
    pg.click('.links [data-act="feedback"]'); pg.wait_for_selector("#fbForm"); pg.click("#fbForm button[type=submit]"); pg.wait_for_function("document.getElementById('fbErr').textContent.length>0")
    chk("Guest sends empty feedback","error under the form",'few words' in pg.inner_text("#fbErr"),pg.inner_text("#fbErr"))
    pg.fill("#fbMsg","The app is nice but episode 2 was slow."); pg.fill("#fbContact","me@example.com"); pg.click("#fbForm button[type=submit]"); pg.wait_for_selector("text=Your message was sent")
    chk("Guest sends feedback","thank-you message, sheet closes",not pg.is_visible("#scrim"))
    # sign up
    pg.click("#acctBtn"); pg.click('[data-act="signup"]'); pg.wait_for_selector("#fName")
    chk("Sign-up sheet links the terms","Terms and Privacy policy links",pg.locator("#sheet .fine a").count()==2)
    pg.fill("#fName","Nita"); pg.fill("#fPhone","9000000031"); pg.fill("#fPass","secret12"); pg.click("#authForm button[type=submit]"); pg.wait_for_selector("text=Account created")
    # tasks
    pg.click("#coinBtn"); pg.wait_for_selector(".task")
    t=pg.eval_on_selector_all(".task button","x=>x.map(b=>b.textContent+(b.disabled?' (off)':''))")
    chk("Wallet lists today's tasks","3 tasks, all 'Not yet' and disabled",t==["Not yet (off)"]*3,t)
    pg.evaluate("""async()=>{for(const e of [1,2,3]) await fetch('api.php?a=view',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Requested-With':'natak'},body:JSON.stringify({show:'thornbound',ep:e,seconds:60,done:true})})}""")
    pg.reload(); pg.wait_for_selector("#rowAll .card"); pg.click("#coinBtn"); pg.wait_for_selector(".task")
    chk("After finishing 3 episodes","first task says 'Claim +2', shows 3 of 3",pg.inner_text(".task >> nth=0").find("3 of 3")>-1 and pg.inner_text(".task button >> nth=0")=="Claim +2",pg.inner_text(".task >> nth=0"))
    pg.screenshot(path="n_tasks.png")
    pg.click(".task button >> nth=0"); pg.wait_for_selector("text=Task done: +2 coins"); pg.wait_for_function("document.querySelector('.task button').textContent==='Claimed'")
    chk("Claim the task","wallet 20 -> 22, green +2 chip, button says Claimed",pg.inner_text("#coinN")=="22" and "+2" in pg.inner_text("#delta"),pg.inner_text("#coinN"))
    # store offer
    pg.click('#sheet [data-act="store"]'); pg.wait_for_selector(".offer")
    chk("Store shows the first top-up offer","banner present",'50% extra' in pg.inner_text(".offer")); pg.screenshot(path="n_store.png")
    pg.click('[data-pack="p29"]'); pg.wait_for_selector("text=Complete test payment"); pg.click('[data-act="paid"]'); pg.wait_for_selector("text=Added 90 coins")
    pg.wait_for_selector("#walBal"); pg.click('#sheet [data-act="store"]'); pg.wait_for_selector("text=Get coins")
    chk("After the first pack","wallet 112, offer banner gone",pg.inner_text("#coinN")=="112" and pg.locator(".offer").count()==0,pg.inner_text("#coinN"))
    pg.click('#sheet .close')
    # player: subtitles, preload, swipe
    del reqs[:]
    pg.click('#rowAll .card[data-show="ichcha"]'); pg.wait_for_function("document.getElementById('vid').currentTime>0.3")
    chk("Episode with subtitles","CC button shown and on, subtitle track showing",pg.is_visible("#ccBtn") and pg.evaluate("document.getElementById('vid').textTracks.length===1 && document.getElementById('vid').textTracks[0].mode==='showing'"))
    pg.wait_for_function("document.getElementById('vid').textTracks[0].cues && document.getElementById('vid').textTracks[0].cues.length===1",timeout=8000)
    chk("Subtitle text loads","1 cue with the uploaded line",pg.evaluate("document.getElementById('vid').textTracks[0].cues[0].text")=="Subtitle test line")
    pg.screenshot(path="n_player.png")
    pg.click("#ccBtn"); chk("Tap CC","subtitles hidden, message shown",pg.evaluate("document.getElementById('vid').textTracks[0].mode")=="hidden" and pg.is_visible("text=Subtitles off"))
    pg.wait_for_timeout(2200)
    chk("Next episode is fetched early","a request for episode 2's video while episode 1 plays",any("video.php?show=ichcha&ep=2" in u for u in reqs),[u for u in reqs if 'video' in u])
    pg.evaluate(SW,-200); pg.wait_for_function("document.getElementById('pEp').textContent.indexOf('Episode 2 of')===0")
    chk("Swipe up","goes to episode 2, CC button hidden (no subtitles there)","Episode 2 of" in pg.inner_text("#pEp") and not pg.is_visible("#ccBtn"),pg.inner_text("#pEp"))
    pg.wait_for_timeout(700); pg.evaluate(SW,200); pg.wait_for_function("document.getElementById('pEp').textContent.indexOf('Episode 1 of')===0")
    chk("Swipe down","back to episode 1, subtitles stay off as chosen",pg.evaluate("document.getElementById('vid').textTracks[0].mode")=="hidden")
    pg.wait_for_timeout(700); pg.evaluate(SW,200); pg.wait_for_selector("text=This is the first episode"); chk("Swipe down on episode 1","stays, says it is the first episode","Episode 1 of" in pg.inner_text("#pEp"))
    pg.wait_for_timeout(700); pg.evaluate(SW,-30); pg.wait_for_timeout(300); chk("A small finger move","does not change episode","Episode 1 of" in pg.inner_text("#pEp"))
    pg.focus("#tap"); pg.keyboard.press("ArrowDown"); pg.wait_for_function("document.getElementById('pEp').textContent.indexOf('Episode 2 of')===0"); chk("Down arrow key","next episode",True)
    pg.click("#backBtn"); pg.wait_for_selector("#rowAll .card")
    # account: links + delete
    pg.click("#acctBtn"); pg.wait_for_selector('[data-act="delacct"]')
    chk("Account sheet","Help and feedback, Delete account, Privacy and Terms links",pg.is_visible('#sheet [data-act="feedback"]') and pg.locator("#sheet .fine a").count()==2)
    pg.click('[data-act="delacct"]'); pg.wait_for_selector("#delForm"); txt=pg.inner_text("#sheet")
    chk("Delete screen warns clearly","names the coins that will be lost and that it cannot be undone","112 coins" in txt and "cannot be undone" in txt,txt[:160]); pg.screenshot(path="n_delete.png")
    pg.fill("#delPass","wrongpass"); pg.click("#delForm button[type=submit]"); pg.wait_for_function("document.getElementById('delErr').textContent.length>0")
    chk("Wrong password","'Wrong password.', still signed in",pg.inner_text("#delErr")=="Wrong password.")
    pg.fill("#delPass","secret12"); pg.click("#delForm button[type=submit]"); pg.wait_for_selector("text=Your account was deleted")
    chk("Right password","account gone: button says Sign in, wallet 0, no coin chip",pg.inner_text("#acctBtn")=="Sign in" and pg.inner_text("#coinN")=="0" and "on" not in (pg.get_attribute("#delta","class") or ""),(pg.inner_text("#acctBtn"),pg.inner_text("#coinN"),pg.get_attribute("#delta","class")))
    pg.goto(B+"legal.php?p=privacy"); chk("Privacy page on a phone","readable, no sideways scroll, link back to the app",pg.is_visible("h1") and pg.evaluate("document.documentElement.scrollWidth<=document.documentElement.clientWidth") and pg.get_attribute("main a","href")=="./"); pg.screenshot(path="n_privacy.png")
    chk("No script errors in the whole run","none",not errs,errs)
    b.close()
print("UI3 PASS %d of %d"%(sum(1 for r in res if r[2]),len(res))); json.dump(res,open("ui3_results.json","w"))
