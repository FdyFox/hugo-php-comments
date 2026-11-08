#!/usr/bin/env python3
"""End-to-end test of the PHP backend against a database with the old schema."""
import http.cookiejar, json, re, sqlite3, sys, threading, time, urllib.error, urllib.parse, urllib.request

BASE, DB, PASSWORD, GUARD_BASE = sys.argv[1:5]
API = BASE + "/comments/api.php"
ADMIN = BASE + "/comments/admin.php"
fails = 0


def check(name, ok, detail=""):
    global fails
    print(("ok   " if ok else "FAIL ") + name + (f"  -> {detail}" if not ok else ""))
    fails += not ok


def rows():
    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row
    result = [dict(x) for x in con.execute("select * from comments order by id")]
    con.close()
    return result


def sql(statement, *args):
    con = sqlite3.connect(DB, timeout=5)
    con.execute(statement, args)
    con.commit()
    con.close()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


def request(opener, url, data=None, headers=None):
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    req = urllib.request.Request(url, data=body, headers=headers or {})
    try:
        r = opener.open(req)
    except urllib.error.HTTPError as e:
        r = e
    return r.getcode(), r.read().decode(), r.headers


plain = urllib.request.build_opener(NoRedirect)


def listing(page="hello"):
    return json.loads(request(plain, f"{API}?section=posts&page={page}")[1])["comments"]


before = rows()
check("starts with the old schema", "parent_id" not in before[0], str(before[0].keys()))

codes = []
threads = [threading.Thread(target=lambda: codes.append(request(plain, f"{API}?section=posts&page=hello")[0])) for _ in range(12)]
[t.start() for t in threads]
[t.join() for t in threads]
check("12 parallel first requests succeed", codes == [200] * 12, str(codes))

after = rows()
check("parent_id column added", "parent_id" in after[0])
check("no row lost", len(after) == len(before))
for b, a in zip(before, after):
    same = all(a[k] == b[k] for k in b if k != "ip_hash")
    check(f"comment {b['id']} unchanged", same and a["parent_id"] is None, str(a))
by_name = {r["name"]: r for r in after}
check("expired IP hash cleared on fetch", by_name["Free"]["ip_hash"] == "")
check("recent IP hash kept", by_name["Recent"]["ip_hash"] == "recent")

data = listing()
check("approved comments with empty replies", data and all(c["replies"] == [] for c in data), str(data))
check("no ids or emails exposed", set(data[0]) == {"name", "website", "comment", "date", "replies"}, str(data[0]))

jar = http.cookiejar.CookieJar()
admin = urllib.request.build_opener(NoRedirect, urllib.request.HTTPCookieProcessor(jar))
_, html, _ = request(admin, ADMIN, {"do": "login", "password": "wrong"})
check("wrong password rejected", "Wrong password" in html, html[:200])
sql("update login_failures set created_at = '2000-01-01T00:00:00+00:00'")
request(plain, f"{API}?section=posts&page=hello")
con = sqlite3.connect(DB)
left = con.execute("select count(*) from login_failures").fetchone()[0]
con.close()
check("expired login failures cleared on fetch", left == 0, str(left))

code, html, _ = request(admin, ADMIN, {"do": "login", "password": PASSWORD})
check("correct password with special characters accepted", code == 303, f"{code} {html[:200]}")
_, html, _ = request(admin, ADMIN + "?status=all")
csrf = re.search(r'name="csrf" value="(\w+)"', html).group(1)
check("admin page shows reply form", 'value="reply"' in html)
check("confirm dialog is valid HTML-escaped JS", 'return confirm(&quot;Really delete' in html, html[:300])


def act(do, id_, **kw):
    return request(admin, ADMIN + "?status=all", {"csrf": csrf, "do": do, "id": id_, **kw})


open_id, free_id = by_name["Open"]["id"], by_name["Free"]["id"]
request(admin, ADMIN + "?status=all", {"csrf": "wrong", "do": "reply", "id": free_id, "comment": "x"})
check("reply without valid CSRF token rejected", len(rows()) == len(before))
act("reply", free_id, comment="   ")
check("empty reply rejected", len(rows()) == len(before))

act("reply", open_id, comment="Reply <b>HTML</b>")
r = rows()
reply = r[-1]
check("reply created", reply["parent_id"] == open_id and reply["name"] == "Tester" and reply["status"] == "approved", str(reply))
check("replying approves a pending comment", [x for x in r if x["id"] == open_id][0]["status"] == "approved")

c = [x for x in listing() if x["name"] == "Open"][0]
check("reply nested in JSON", len(c["replies"]) == 1 and c["replies"][0]["comment"] == "Reply <b>HTML</b>", str(c))
check("reply not repeated at top level", not [x for x in listing() if x["name"] == "Tester"])

_, html, _ = request(admin, ADMIN + "?status=all")
check("HTML escaped in admin", "Reply &lt;b&gt;HTML&lt;/b&gt;" in html and "Reply <b>HTML" not in html)
_, html, _ = request(admin, ADMIN + "?status=approved")
tab = re.search(r"Approved <span>(\d+)</span>", html).group(1)
expected = sum(1 for x in rows() if x["status"] == "approved" and x["parent_id"] is None)
check("tab counter ignores replies", int(tab) == expected, tab)

n = len(rows())
act("reply", reply["id"], comment="Reply to reply")
check("reply to a reply rejected", len(rows()) == n)
act("save", reply["id"], name="Tester", comment="changed")
check("reply can be edited", rows()[-1]["comment"] == "changed" and rows()[-1]["parent_id"] == open_id)

act("unapprove", open_id)
check("hiding a comment hides its reply", not [x for x in listing() if x["name"] in ("Open", "Tester")])
act("approve", open_id)
check("approving again restores the reply", [x for x in listing() if x["name"] == "Open"][0]["replies"])

act("delete", reply["id"])
ids = [x["id"] for x in rows()]
check("deleting a reply keeps the comment", open_id in ids and reply["id"] not in ids)
act("reply", open_id, comment="second")
act("delete", open_id)
check("deleting a comment removes replies", not [x for x in rows() if open_id in (x["id"], x["parent_id"])])

# Mail links
_, html, _ = request(plain, f"{API}?action=approve&id={free_id}&token=wrong")
check("mail link with wrong token rejected", "Not found" in html)
token = [x for x in rows() if x["id"] == free_id][0]["token"]
_, html, _ = request(plain, f"{API}?action=delete&id={free_id}&token={token}")
check("mail link GET only asks for confirmation", free_id in [x["id"] for x in rows()] and "<form" in html)
stamp = [x for x in rows() if x["id"] == free_id][0]["created_at"]
sql("update comments set created_at = ? where id = ?", time.strftime("%Y-%m-%dT%H:%M:%S+00:00", time.gmtime(time.time() - 31 * 86400)), free_id)
request(plain, API, {"action": "delete", "id": free_id, "token": token})
check("expired mail link does nothing", free_id in [x["id"] for x in rows()])
sql("update comments set created_at = ? where id = ?", stamp, free_id)
act("reply", free_id, comment="third")
request(plain, API, {"action": "delete", "id": free_id, "token": token})
check("mail link delete removes replies", not [x for x in rows() if free_id in (x["id"], x["parent_id"])])

# Submitting
form = {"section": "posts", "page": "new", "path": "/posts/new/", "comment": "New"}
hdr = {"Accept": "application/json"}
code, body, _ = request(plain, API, {**form, "section": "secret"}, hdr)
check("section outside allowed_sections rejected", code == 400, f"{code} {body}")
code, _, h = request(plain, API, {**form, "page": "nojs"})
check("submitting without JavaScript redirects to thanks", code == 303 and h["Location"].endswith("#comment-thanks"), f"{code} {h.get('Location')}")
request(plain, API, {**form, "fax": "bot"}, hdr)
check("honeypot stores nothing", not [x for x in rows() if x["page"] == "new"])
results = [request(plain, API, form, hdr)[0] for _ in range(3)]
check("rate limit: 2 more ok, then 429", results == [200, 200, 429], str(results))
check("new comments are pending", all(x["status"] == "pending" and x["parent_id"] is None for x in rows() if x["page"] == "new"))

# Database inside the web root on a server that ignores .htaccess
code, body, _ = request(plain, f"{GUARD_BASE}/comments/api.php?section=posts&page=hello")
check("refuses to run with an unprotected data_dir", code == 500 and "disabled" in body, f"{code} {body[:200]}")

print(f"\n{fails} FAILED" if fails else "\nall backend tests passed")
sys.exit(1 if fails else 0)
