import requests, re, sqlite3, hashlib, time
B="http://127.0.0.1:8766/"; H={"X-Requested-With":"natak"}
res=[]
def t(name, ok, info=""): res.append(ok); print(("PASS" if ok else "FAIL"), name, info)
def api(s,a,body=None):
    r = s.post(B+"api.php?a="+a, json=body or {}, headers=H) if body is not None else s.get(B+"api.php?a="+a)
    return r.status_code, r.json()
u=requests.Session(); api(u,"state")
c,j=api(u,"signup",{"name":"Asha","phone":"9876500001","password":"oldpass1"}); t("signup",c==200,c)
c,j=api(u,"state"); coins=j["me"]["coins"]["total"] if "me" in j else j["state"]["me"]["coins"]["total"]
# admin
a=requests.Session()
r=a.post(B+"admin.php",data={"act":"setup","password":"adminpass123","password2":"adminpass123"}); t("admin setup",r.status_code==200)
csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',r.text).group(1)
t("admin page has reset form",'value="resetcode"' in r.text)
r=a.post(B+"admin.php",data={"csrf":csrf,"act":"resetcode","phone":"9999999999"}); t("unknown number refused","No account uses" in r.text)
r=a.post(B+"admin.php",data={"act":"resetcode","phone":"9876500001"}); t("no csrf blocked",r.status_code==403)
r=requests.post(B+"admin.php",data={"csrf":csrf,"act":"resetcode","phone":"9876500001"}); t("not signed in: no code",not re.search(r'>\d{6}</b>',r.text))
def mk(phone="+91 98765 00001"):
    r=a.post(B+"admin.php",data={"csrf":csrf,"act":"resetcode","phone":phone}); m=re.search(r'>(\d{6})</b>',r.text); return m and m.group(1)
code=mk(); t("code issued (accepts +91 format)",bool(code),code)
db=sqlite3.connect("/home/claude/t/rdata/natak.sqlite")
row=db.execute("select reset_hash,reset_until from users where phone='9876500001'").fetchone()
t("stored hashed, not plain",row[0]==hashlib.sha256(code.encode()).hexdigest() and code not in str(row[0]))
t("expires in ~30 min",1700<row[1]-time.time()<=1800)
g=requests.Session(); api(g,"state")
wrong="000000" if code!="000000" else "111111"
c,j=api(g,"reset",{"phone":"9876500001","code":wrong,"password":"newpass1"}); t("wrong code refused",c==401,j.get("error"))
c,j=api(g,"reset",{"phone":"9999999999","code":code,"password":"newpass1"}); t("unknown phone same message",c==401 and "wrong or has expired" in j["error"])
c,j=api(g,"reset",{"phone":"9876500001","code":code,"password":"123"}); t("short password refused",c==400)
r=g.post(B+"api.php?a=reset",json={"phone":"9876500001","code":code,"password":"newpass1"}); t("no header blocked",r.status_code==403)
c,j=api(g,"reset",{"phone":"9876500001","code":code,"password":"newpass1"}); t("right code works",c==200,c)
c,j=api(u,"state"); st=j.get("state",j); t("old session signed out",st["me"]["guest"] is True)
c,j=api(g,"reset",{"phone":"9876500001","code":code,"password":"hack1234"}); t("code is single use",c==401)
n=requests.Session(); api(n,"state")
c,j=api(n,"login",{"phone":"9876500001","password":"oldpass1"}); t("old password dead",c==401)
c,j=api(n,"login",{"phone":"9876500001","password":"newpass1"}); st=j.get("state",j); t("new password signs in",c==200 and st["me"]["guest"] is False)
t("coins kept",st["me"]["coins"]["total"]==coins,(st["me"]["coins"]["total"],coins))
# five wrong tries burn the code
code2=mk("9876500001")
for i in range(5): api(g,"reset",{"phone":"9876500001","code":wrong,"password":"zzzzzz1"})
c,j=api(g,"reset",{"phone":"9876500001","code":code2,"password":"zzzzzz1"}); t("5 wrong tries burn the code",c==401)
# expiry
code3=mk("9876500001"); db.execute("update users set reset_until=? where phone='9876500001'",(int(time.time())-1,)); db.commit()
c,j=api(g,"reset",{"phone":"9876500001","code":code3,"password":"zzzzzz1"}); t("expired code refused",c==401)
# lockout cleared by reset
for i in range(8): api(g,"login",{"phone":"9876500001","password":"bad"})
c,j=api(g,"login",{"phone":"9876500001","password":"newpass1"}); t("account locked after 8 bad tries",c==429)
code4=mk("9876500001"); c,j=api(g,"reset",{"phone":"9876500001","code":code4,"password":"final123"}); 
c,j=api(g,"login",{"phone":"9876500001","password":"final123"}); t("reset clears the lock",c==200,c)
r=a.post(B+"admin.php",data={"csrf":csrf,"act":"support","support":"WhatsApp 90000 00000"}); c,j=api(g,"state"); t("support contact reaches app",j.get("state",j).get("support")=="WhatsApp 90000 00000")
print(sum(res),"of",len(res),"passed")
