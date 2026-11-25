"""Walk the school bus parent flow against the local demo API (http://127.0.0.1:8011)."""
import json
import math
import sys
import urllib.request
from datetime import datetime, timedelta, timezone

BASE = "http://127.0.0.1:8011/api/v1/auth"
HEAD = {"Accept": "application/json", "Content-Type": "application/json", "X-App-Key": "demo-komiut-app"}
STOP = (-1.1932, 36.9353)       # Kahawa Sukari Gate, the parent's stop
START = (-1.1856, 36.9038)      # Kahawa West Junction, where the bus comes from


def call(method, path, token=None, body=None):
    headers = dict(HEAD)
    if token:
        headers["Authorization"] = "Bearer " + token
    req = urllib.request.Request(BASE + path, method=method, headers=headers,
                                 data=None if body is None else json.dumps(body).encode())
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            raw = r.read().decode()
            return r.status, (json.loads(raw) if raw else None)
    except urllib.error.HTTPError as e:
        raw = e.read().decode()
        try:
            return e.code, json.loads(raw)
        except Exception:
            return e.code, raw[:300]


def show(title, status, body):
    print(f"\n### {title}\nHTTP {status}")
    print(json.dumps(body, indent=2)[:1800] if body is not None else "(no body)")


def login(email):
    status, body = call("POST", "/login", body={"email": email, "password": "password"})
    assert status == 200, (email, status, body)
    return body["access_token"]


def metres(a, b):
    r = 6371008.8
    p1, p2 = math.radians(a[0]), math.radians(b[0])
    dp, dl = p2 - p1, math.radians(b[1] - a[1])
    h = math.sin(dp / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(dl / 2) ** 2
    return 2 * r * math.asin(math.sqrt(h))


print("=" * 70)
print("SCHOOL BUS TRACKING -- local demo (Greenfield Demo Academy, bus KSB 101A)")
print("=" * 70)

admin = login("admin@greenfield.school.test")
parent = login("parent@greenfield.school.test")
status, body = call("POST", "/driver/login", body={"phone": "0711000202", "plate": "KSB 101A"})
assert status == 200, (status, body)
driver = body["access_token"]
print("Signed in: school admin, parent (passenger account), driver (phone + plate).")

# 1. School office: its buses and the codes it hands to parents.
status, body = call("GET", "/school/buses", admin)
show("1. School admin -> GET school/buses (codes to share with parents)", status, body)
code = next(b["follow_code"] for b in body["buses"] if b["plate"] == "KSB 101A")

# Start the parent from scratch (the seed had them following already).
call("DELETE", "/school-buses/1/follow", parent)

# 2. Parent searches the plate, typed any way.
status, body = call("GET", "/school-buses/search?plate=ksb101a", parent)
show("2. Parent -> GET school-buses/search?plate=ksb101a (no location, no code)", status, body)
bus_id = body["buses"][0]["id"]

# 3. Wrong code.
status, body = call("POST", f"/school-buses/{bus_id}/follow", parent, {"code": "WRONG123"})
show("3. Parent -> follow with a WRONG code", status, body)

# 4. Right code, with their stop.
status, body = call("POST", f"/school-buses/{bus_id}/follow", parent, {"code": code.lower(), "stop_place_id": 2})
show("4. Parent -> follow with the school's code (typed in lower case) + stop 'Kahawa Sukari Gate'", status, body)

# 5. The bus is not running yet.
status, body = call("GET", "/school-buses/following", parent)
show("5. Parent -> GET school-buses/following (bus not live yet)", status, body)

# 6. Driver goes live on the morning run and drives towards the parent's stop at ~30 km/h.
print("\n### 6. Driver goes live on 'Greenfield morning run' and drives towards the parent's stop")
total = metres(START, STOP)
start_at = 1600.0                       # metres before the stop
step = 25.0                             # metres per fix
gap = 3                                 # seconds between fixes -> 30 km/h
n = int((start_at - 700) / step) + 1    # until ~700 m out
now = datetime.now(timezone.utc)
alerted_at = None


def approaching_count():
    _, notes = call("GET", "/notifications", parent)
    items = (notes.get("message") or {}).get("items", []) if isinstance(notes, dict) else []
    return sum(1 for x in items if isinstance(x, dict) and x.get("type") == "bus_approaching")


before = approaching_count()
for i in range(n):
    remaining = start_at - i * step
    f = 1 - remaining / total
    lat = START[0] + (STOP[0] - START[0]) * f
    lng = START[1] + (STOP[1] - START[1]) * f
    fixed_at = (now - timedelta(seconds=(n - 1 - i) * gap)).isoformat()
    status, ack = call("POST", "/book_a_ride/location", driver,
                       {"latitude": round(lat, 6), "longitude": round(lng, 6), "route_id": 1, "fixed_at": fixed_at})
    if i == 0:
        print(f"first ping -> HTTP {status} {json.dumps(ack)}")
    if alerted_at is None and approaching_count() > before:
            alerted_at = remaining
            print(f"  ...push sent when the bus was {int(remaining)} m from the stop (ping {i + 1}/{n})")
print(f"last ping  -> HTTP {status} {json.dumps(ack)}")
print(f"pushes for this run: {approaching_count() - before} (once per run, however many pings)")

# 7. What the parent sees now.
status, body = call("GET", "/school-buses/following", parent)
show("7. Parent -> GET school-buses/following (live, speed, distance and ETA to their stop)", status, body)

# 8. The push the parent got.
status, body = call("GET", "/notifications", parent)
items = (body.get("message") or {}).get("items", []) if isinstance(body, dict) else body
items = [x for x in items if isinstance(x, dict) and x.get("type") == "bus_approaching"] if isinstance(items, list) else items
show("8. Parent -> the 'bus_approaching' notification (sent once for this run)", status, items)

# 9. School office view while the bus runs.
status, body = call("GET", "/school/buses", admin)
show("9. School admin -> GET school/buses (now live, 1 follower)", status, body)
status, body = call("GET", f"/school/buses/{bus_id}/followers", admin)
show("10. School admin -> followers of KSB 101A (masked phone, no home pins)", status, body)

# 11. Ordinary passengers never see the school bus.
status, body = call("GET", "/book_a_ride/queues?page=1", parent)
plates = [q.get("vehicle", {}).get("plate") for q in (body.get("queues", []) if isinstance(body, dict) else [])]
show("11. Any passenger -> GET book_a_ride/queues (school bus must be absent)", status, {"plates_listed": plates})

# 12. Parent unfollows.
status, body = call("DELETE", f"/school-buses/{bus_id}/follow", parent)
show("12. Parent -> unfollow", status, body)
