"""Walk the saved-bus flow against the local demo API (http://127.0.0.1:8011).

A passenger (here the demo parent) finds school bus KSB 101A by plate, keeps
its id as the app would in its local saved list, and reads it by id: not live.
The driver goes live and drives; the same read now carries the position, the
bus's own smoothed speed and the run. See docs/school-bus-tracking.md.
"""
import json
import math
import os
import sys
import urllib.error
import urllib.request
from datetime import datetime, timedelta, timezone

BASE = os.environ.get("DEMO_API", "http://127.0.0.1:8011") + "/api/v1/auth"
HEAD = {"Accept": "application/json", "Content-Type": "application/json", "X-App-Key": "demo-komiut-app"}
START = (-1.1856, 36.9038)      # Kahawa West Junction
STOP = (-1.1932, 36.9353)       # Kahawa Sukari Gate


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


print("=" * 70)
print("SAVED BUSES -- local demo (Greenfield Demo Academy, bus KSB 101A)")
print("=" * 70)

admin = login("admin@greenfield.school.test")
passenger = login("parent@greenfield.school.test")
status, body = call("POST", "/driver/login", body={"phone": "0711000202", "plate": "KSB 101A"})
assert status == 200, (status, body)
driver = body["access_token"]
print("Signed in: school admin, passenger (any account), driver (phone + plate).")

# 1. The passenger finds the bus by plate, typed any way. No position here.
status, body = call("GET", "/buses/search?plate=ksb101a", passenger)
show("1. Passenger -> GET buses/search?plate=ksb101a", status, body)
assert status == 200 and body["buses"], body
bus_id = body["buses"][0]["id"]
print(f"   (the app saves id {bus_id} in its local list; the server stores nothing)")

# 2. Read the saved list by id: the bus is not running yet.
status, body = call("GET", f"/buses/live?ids={bus_id},999999", passenger)
show("2. Passenger -> GET buses/live (not live yet; 999999 comes back under missing)", status, body)

# 3. The driver goes live on the morning run and drives at ~30 km/h.
print("\n### 3. Driver goes live on 'Greenfield morning run' and drives towards Kahawa Sukari Gate")
_, routes = call("GET", "/driver/routes", driver)
route_id = next((r["id"] for r in (routes or {}).get("routes") or []
                 if "morning" in str(r.get("name", "")).lower()), 1)
n, gap = 8, 3                   # 8 fixes, 3 s apart
step = 25.0                     # metres per fix -> 30 km/h
total = 3600.0                  # metres from START to STOP, roughly
now = datetime.now(timezone.utc)
for i in range(n):
    f = (i * step) / total
    lat = START[0] + (STOP[0] - START[0]) * f
    lng = START[1] + (STOP[1] - START[1]) * f
    fixed_at = (now - timedelta(seconds=(n - 1 - i) * gap)).isoformat()
    status, ack = call("POST", "/book_a_ride/location", driver,
                       {"latitude": round(lat, 6), "longitude": round(lng, 6), "route_id": route_id, "fixed_at": fixed_at})
    if i in (0, n - 1):
        print(f"ping {i + 1}/{n} -> HTTP {status} {json.dumps(ack)}")

# 4. The same read now has the position, the bus's own speed and the run.
status, body = call("GET", f"/buses/live?ids={bus_id}", passenger)
show("4. Passenger -> GET buses/live (live: position, speed_kmh, trip, channel)", status, body)
live = (body.get("buses") or [{}])[0].get("live") if isinstance(body, dict) else None
if live and live.get("speed_kmh"):
    r = 6371008.8
    p1, p2 = math.radians(live["latitude"]), math.radians(STOP[0])
    dp, dl = p2 - p1, math.radians(STOP[1] - live["longitude"])
    h = math.sin(dp / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(dl / 2) ** 2
    metres = 2 * r * math.asin(math.sqrt(h))
    eta = metres / (max(10.0, live["speed_kmh"]) * 1000 / 3600)
    print(f"   The app's own estimate: {int(metres)} m to Kahawa Sukari Gate, about {math.ceil(eta / 60)} min.")

# 5. School office view while the bus runs.
status, body = call("GET", "/school/buses", admin)
show("5. School admin -> GET school/buses (now live)", status, body)

# 6. Ordinary passenger lists never offer the school bus.
status, body = call("GET", "/book_a_ride/queues?page=1", passenger)
plates = [q.get("vehicle", {}).get("plate") for q in (body.get("queues", []) if isinstance(body, dict) else [])]
show("6. Any passenger -> GET book_a_ride/queues (school bus must be absent)", status, {"plates_listed": plates})
if "KSB 101A" in plates:
    sys.exit("school bus was offered to passengers")
