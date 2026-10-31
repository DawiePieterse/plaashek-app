"""Demo herd for Kudde, through its API on :8030: camps, ~60 head, weights,
treatments (two still in withdrawal), calvings, moves, a sale and a death."""
import json, random, urllib.request, uuid
from datetime import date, timedelta
random.seed(5)
BASE = "http://localhost:8030"
def api(path, body=None, method=None):
    req = urllib.request.Request(BASE + path, data=json.dumps(body).encode() if body is not None else None,
                                 headers={"Content-Type": "application/json"}, method=method or ("POST" if body is not None else "GET"))
    try:
        return json.loads(urllib.request.urlopen(req, timeout=30).read() or "null")
    except urllib.error.HTTPError as e:
        print("ERR", path, e.code, e.read()[:200]); return None
TODAY = date(2026, 10, 31)
d = lambda days: (TODAY - timedelta(days=days)).isoformat()

api("/api/farm", {"farm_name": "Plaashek Demo Farm", "farmer_name": "J. van der Merwe", "phone_number": "082 555 0100", "gps_lat": -25.45, "gps_lng": 30.95}, method="PUT")
CAMPS = ["Rivierkamp", "Bult", "Dam camp", "Kraal", "Noordkamp"]
for c in CAMPS: api("/api/camps", {"name": c})

animals = []
def animal(tag, sex, born_days, breed, camp, name="", castrated=False, sire=None, dam=None):
    a = {"tag": tag, "name": name, "breed": breed, "sex": sex, "birth_date": d(born_days), "castrated": castrated, "sire_tag": sire, "dam_tag": dam}
    animals.append((a, camp))
BREEDS = ["Bonsmara", "Bonsmara", "Brahman", "Nguni", "Bonsmara x Brahman"]
# Two bulls, 28 cows, 8 heifers, 10 steers, a few calves born this year.
animal("B1", "male", 2200, "Bonsmara", "Rivierkamp", "Kaptein"); animal("B2", "male", 1500, "Brahman", "Bult", "Rooi")
for i in range(1, 29):
    animal(f"C{i}", "female", random.randint(1400, 3600), random.choice(BREEDS), random.choice(["Rivierkamp", "Bult", "Dam camp"]), sire="B1" if i % 3 else None)
for i in range(1, 9):
    animal(f"H{i}", "female", random.randint(500, 900), random.choice(BREEDS), "Noordkamp", dam=f"C{random.randint(1, 28)}", sire="B1")
for i in range(1, 11):
    animal(f"S{i}", "male", random.randint(450, 800), random.choice(BREEDS), "Dam camp", castrated=True, dam=f"C{random.randint(1, 28)}", sire="B2")
for a, camp in animals:
    api("/api/animals", a)
    api("/api/events", {"tag": a["tag"], "kind": "movement", "event_date": d(600), "location": camp, "client_uuid": uuid.uuid4().hex})
print("animals", len(animals))

# Weights: most weighed within 182 days, a dozen overdue.
for a, camp in animals:
    base = 520 if a["tag"].startswith("B") else 420 if a["tag"].startswith("C") else 300
    for days in (520, 340, 160):
        if days == 160 and random.random() < 0.2: continue   # the overdue ones
        w = base + random.randint(-40, 60) + (0 if days < 200 else -random.randint(10, 40))
        api("/api/events", {"tag": a["tag"], "kind": "weight", "event_date": d(days + random.randint(0, 6)), "value": w, "client_uuid": uuid.uuid4().hex})
# Treatments: a whole-herd dose in March, and two recent ones still in withdrawal.
api("/api/events/treatment/bulk", {"tags": [a["tag"] for a, _ in animals], "event_date": d(230), "product": "Ivermectin", "dose": "1 ml / 50 kg", "withdrawal_days": 28, "note": "Autumn dose", "client_uuid": uuid.uuid4().hex})
api("/api/events", {"tag": "C7", "kind": "treatment", "event_date": d(9), "product": "Oxytetracycline", "dose": "20 ml", "withdrawal_days": 28, "note": "Foot abscess, left front", "client_uuid": uuid.uuid4().hex})
api("/api/events", {"tag": "S4", "kind": "treatment", "event_date": d(3), "product": "Penicillin", "dose": "15 ml", "withdrawal_days": 21, "note": "Lumpy jaw, watch", "client_uuid": uuid.uuid4().hex})
# Calvings this spring.
for i, (dam, days) in enumerate([("C3", 45), ("C11", 31), ("C18", 20), ("C5", 12), ("C22", 4)]):
    sex = "female" if i % 2 else "male"
    api("/api/calvings", {"dam_tag": dam, "event_date": d(days), "camp": "Rivierkamp", "note": "", "client_uuid": uuid.uuid4().hex,
                          "calf": {"tag": f"K{26 + i}", "name": "", "breed": "Bonsmara", "sex": sex, "sire_tag": "B1", "dam_tag": dam}})
# Moves: the cows from Bult to Noordkamp last week; a sale and a death.
api("/api/events/movement/bulk", {"tags": [a["tag"] for a, c in animals if c == "Bult" and a["tag"].startswith("C")], "event_date": d(6), "location": "Noordkamp", "note": "Grazing finished on Bult", "client_uuid": uuid.uuid4().hex})
api("/api/events", {"tag": "S9", "kind": "sale", "event_date": d(15), "note": "Sold to local abattoir, 486 kg", "client_uuid": uuid.uuid4().hex})
api("/api/events", {"tag": "S10", "kind": "sale", "event_date": d(15), "note": "Sold to local abattoir, 471 kg", "client_uuid": uuid.uuid4().hex})
api("/api/events", {"tag": "C26", "kind": "death", "event_date": d(40), "note": "Snakebite, found in the river camp", "client_uuid": uuid.uuid4().hex})
print(json.dumps(api("/api/dashboard"))[:400])
