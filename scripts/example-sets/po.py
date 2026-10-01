#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
# Copyright (C) 2026 Conduction B.V.
"""Build lib/Settings/profiles/po.json, the primary school example set.

One fictional school, Voorbeeldschool De Wilgenboom in the fictional town of
Wilgendam, through one complete school year (2025-2026): two locations, seven
classes for groups 1 to 8 (5 and 6 share a class), about 200 pupils with their
guardians, staff with subject assignments, a school day per class per day with
the absences, late arrivals and early departures a school records, two report
periods with report cards, Cito and doorstroomtoets results, dossier notes,
support requests and a group plan.

WHY A SCRIPT. The set is several thousand objects that must agree with each
other: an absence falls on a school day of the pupil's own class, is marked by
the teacher who works that weekday, and is counted in that pupil's report card.
Hand-editing that is how sets drift. The script is deterministic (fixed seed),
so running it again produces the same file byte for byte, and a reviewer reads
the rules here rather than four megabytes of JSON.

THE CONTRACT. openspec/changes/archive/2026-09-28-segment-wizard-choice/contract.md. Every rule is
checked by tests/Unit/Settings/ExampleSetDescriptorContractTest.php; run it
after regenerating.

Usage:
    python3 scripts/example-sets/po.py            write the file
    python3 scripts/example-sets/po.py --check    exit 1 when the file on disk differs

Nothing here is real: no real school, BRIN, person, address or phone number.
Postcodes start with 0 and phone numbers with 06-0, which the Netherlands never
issues; the BRIN 00X1 ends in a digit, which DUO never assigns.
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import random
import sys
from zoneinfo import ZoneInfo

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
OUT = os.path.join(ROOT, "lib", "Settings", "profiles", "po.json")
AMS = ZoneInfo("Europe/Amsterdam")
TENANT = "00000000-0000-4000-8000-000000000000"
SET = "po"
SET_NUMBER = "01"
YEAR = "2025-2026"
FIRST_DAY = dt.date(2025, 8, 18)
LAST_DAY = dt.date(2026, 7, 10)

# Schema number (the TTTT group of the uuid) per bucket, in load order.
# Parents come before children; removal runs in reverse.
SCHEMAS = [
    "school",
    "vestiging",
    "room",
    "course",
    "curriculum-plan",
    "cohort",
    "staff",
    "learner-profile",
    "enrolment",
    "subjectteacherassignment",
    "report-period",
    # Reserved slot: data-exchange-job left learniq for integriq (data-exchange-to-integriq). The slot keeps
    # every later schema's uuid namespace where it was; the bucket stays empty and is never rendered.
    "data-exchange-job",
    "session",
    "excuse-request",
    "attendance-record",
    "attendance-threshold",
    "attendance-flag",
    "lvs-result",
    "report-card",
    "group-plan",
    "group-plan-subgroup",
    "group-plan-evaluation",
    "support-request",
    "dossier-note",
]

HOLIDAYS = [
    ("Herfstvakantie", dt.date(2025, 10, 20), dt.date(2025, 10, 24)),
    ("Kerstvakantie", dt.date(2025, 12, 22), dt.date(2026, 1, 2)),
    ("Voorjaarsvakantie", dt.date(2026, 2, 16), dt.date(2026, 2, 20)),
    ("Goede Vrijdag en Tweede Paasdag", dt.date(2026, 4, 3), dt.date(2026, 4, 6)),
    ("Meivakantie", dt.date(2026, 4, 27), dt.date(2026, 5, 8)),
    ("Hemelvaart", dt.date(2026, 5, 14), dt.date(2026, 5, 15)),
    ("Tweede Pinksterdag", dt.date(2026, 5, 25), dt.date(2026, 5, 25)),
]
STUDY_DAYS = [
    (dt.date(2025, 11, 14), "Studiedag team"),
    (dt.date(2026, 3, 11), "Studiedag rekenen"),
    (dt.date(2026, 6, 19), "Studiedag afsluiting schooljaar"),
]
PERIODS = [
    ("1", "Rapport 1", FIRST_DAY, dt.date(2026, 1, 30)),
    ("2", "Rapport 2", dt.date(2026, 2, 2), LAST_DAY),
]
WEEKDAYS = ["monday", "tuesday", "wednesday", "thursday", "friday"]
DAG = ["maandag", "dinsdag", "woensdag", "donderdag", "vrijdag", "zaterdag", "zondag"]
MAAND = ["januari", "februari", "maart", "april", "mei", "juni", "juli", "augustus", "september", "oktober", "november", "december"]

BOYS = [
    "Daan", "Sem", "Luuk", "Bram", "Finn", "Milan", "Levi", "Noah", "Jesse", "Thijs", "Mees", "Siem", "Julian", "Ruben",
    "Jayden", "Lars", "Thomas", "Tim", "Stijn", "Gijs", "Adam", "Mohammed", "Yusuf", "Liam", "Olivier", "Hugo", "Otis",
    "Teun", "Boaz", "Jens", "Guus", "Hidde", "Floris", "Vince", "Ravi", "Koen", "Joep", "Sven", "Niek", "Mats",
]
GIRLS = [
    "Emma", "Julia", "Mila", "Tess", "Sophie", "Zoë", "Sara", "Nora", "Yara", "Eva", "Evi", "Liv", "Lotte", "Noor",
    "Saar", "Lieke", "Fleur", "Anna", "Nina", "Maud", "Isa", "Roos", "Fenna", "Lina", "Amira", "Hanna", "Jasmijn",
    "Floor", "Ilse", "Vera", "Femke", "Esmee", "Norah", "Elin", "Luna", "Fay", "Mirthe", "Sanne", "Iris", "Merel",
]
ADULT_M = ["Mark", "Peter", "Jeroen", "Bas", "Martijn", "Erik", "Rick", "Dennis", "Tarik", "Hasan", "Joost", "Wouter", "Sander", "Niels", "Arjen", "Pieter", "Ricardo", "Karim"]
ADULT_F = ["Linda", "Sanne", "Esther", "Marloes", "Anouk", "Kim", "Iris", "Fatima", "Laura", "Nicole", "Petra", "Judith", "Ingrid", "Samira", "Eline", "Mirjam", "Chantal", "Naima"]
SURNAME_HEAD = ["Wilgen", "Berken", "Linden", "Kastanje", "Hazel", "Vlier", "Meidoorn", "Riet", "Heide", "Klaver", "Duin", "Beek", "Veen", "Molen", "Weide", "Esdoorn", "Lijsterbes", "Iepen", "Walnoot", "Hulst"]
SURNAME_TAIL = ["hof", "kamp", "veld", "hout", "laan", "brink", "horst", "dal", "meer", "wijk", "berg", "rode", "stein", "gaard"]
STREETS = ["Wilgenlaan", "Berkenstraat", "Kastanjehof", "Molenweg", "Hazelaarpad", "Vlierbes", "Rietkraag", "Heidestraat", "Klaverweide", "Duinroos", "Beekdal", "Veenpluis", "Esdoornlaan", "Iepenhof"]

# Class, leerjaren, size (split for 5/6), location key, room key.
CLASSES = [
    ("Groep 1", [1], [24], "noorderpark", "lokaal-1"),
    ("Groep 2", [2], [27], "noorderpark", "lokaal-2"),
    ("Groep 3", [3], [29], "noorderpark", "lokaal-3"),
    ("Groep 4", [4], [28], "hoofd", "lokaal-4"),
    ("Groep 5/6", [5, 6], [15, 15], "hoofd", "lokaal-5"),
    ("Groep 7", [7], [30], "hoofd", "lokaal-7"),
    ("Groep 8", [8], [30], "hoofd", "lokaal-8"),
]
# Teachers per class: (ncUserId, role, days). 5/6 keeps the promoted duo split.
TEACHERS = {
    "Groep 1": [("po-leerkracht-01", "primary", WEEKDAYS)],
    "Groep 2": [("po-leerkracht-02", "primary", WEEKDAYS[:3]), ("po-leerkracht-03", "duo-partner", WEEKDAYS[3:])],
    "Groep 3": [("po-leerkracht-04", "primary", WEEKDAYS)],
    "Groep 4": [("po-leerkracht-07", "primary", WEEKDAYS)],
    "Groep 5/6": [("po-leerkracht-05", "primary", WEEKDAYS[:3]), ("po-leerkracht-06", "duo-partner", WEEKDAYS[3:])],
    "Groep 7": [("po-leerkracht-09", "primary", WEEKDAYS)],
    "Groep 8": [("po-leerkracht-10", "primary", WEEKDAYS[:2]), ("po-leerkracht-11", "duo-partner", WEEKDAYS[2:])],
}
GYM_TEACHER = "po-vakleerkracht-01"
IB = "po-ib-01"
REPORT_SUBJECTS = [
    ("PO-REK", "Rekenen", 0.0),
    ("PO-TAAL", "Taal", 0.1),
    ("PO-SPEL", "Spelling", -0.1),
    ("PO-TL", "Technisch lezen", 0.0),
    ("PO-BL", "Begrijpend lezen", -0.2),
    ("PO-WO", "Wereldoriëntatie", 0.3),
]
LVS_INSTRUMENTS = [("Rekenen-Wiskunde", 0.0), ("Begrijpend lezen", -0.1), ("Spelling", 0.05)]


class Builder:
    """Collects objects per bucket and hands out uuids and slugs."""

    def __init__(self) -> None:
        self.buckets: dict[str, list[dict]] = {name: [] for name in SCHEMAS}
        self.counters: dict[str, int] = {name: 0 for name in SCHEMAS}

    def add(self, schema: str, fields: dict) -> dict:
        self.counters[schema] += 1
        n = self.counters[schema]
        number = SCHEMAS.index(schema) + 1
        obj = {
            "@self": {"configuration": "learniq", "register": "learniq", "schema": schema},
            "uuid": f"ee{SET_NUMBER}{number:04x}-0000-4000-8000-{n:012d}",
            "slug": f"{SET}-{schema}-{n:03d}",
        }
        obj.update(fields)
        if "tenant_id" not in obj:
            obj["tenant_id"] = TENANT
        self.buckets[schema].append(obj)
        return obj


def stamp(day: dt.date, hour: int, minute: int) -> str:
    """A local Amsterdam timestamp with its offset, e.g. 2025-09-01T08:30:00+02:00."""
    return dt.datetime(day.year, day.month, day.day, hour, minute, tzinfo=AMS).isoformat()


def school_days() -> list[dt.date]:
    off = set()
    for _name, start, end in HOLIDAYS:
        d = start
        while d <= end:
            off.add(d)
            d += dt.timedelta(days=1)
    off |= {d for d, _ in STUDY_DAYS}
    days = []
    d = FIRST_DAY
    while d <= LAST_DAY:
        if d.weekday() < 5 and d not in off:
            days.append(d)
        d += dt.timedelta(days=1)
    return days


def minutes_of(day: dt.date) -> int:
    """Wednesday is a short day (08:30 to 12:15), the others run 08:30 to 14:15."""
    return 225 if day.weekday() == 2 else 345


def build() -> dict:
    rng = random.Random(20250818)
    b = Builder()
    days = school_days()

    # --- school, locations, rooms -------------------------------------------
    school = b.add("school", {"brin": "00X1", "name": "Voorbeeldschool De Wilgenboom", "pedagogicalConcept": "regular"})
    locations = {
        "hoofd": b.add("vestiging", {
            "schoolId": school["uuid"], "vestigingscode": "00X100", "onderwijslocatiecode": None,
            "name": "Hoofdlocatie", "street": "Wilgenlaan 1", "postalCode": "0421 WD", "city": "Wilgendam",
        }),
        "noorderpark": b.add("vestiging", {
            "schoolId": school["uuid"], "vestigingscode": "00X101", "onderwijslocatiecode": "00X101-A",
            "name": "Dependance Noorderpark", "street": "Noorderpark 12", "postalCode": "0423 NP", "city": "Wilgendam",
        }),
    }
    rooms = {}
    for name, _lj, _sizes, loc, room in CLASSES:
        rooms[room] = b.add("room", {
            "name": "Lokaal " + name.lower(), "code": room.upper(), "capacity": 32, "kind": "classroom",
            "facilities": ["digibord"], "buildingCode": locations[loc]["vestigingscode"], "floor": "0",
        })
    b.add("room", {"name": "Gymzaal", "code": "GYM", "capacity": 35, "kind": "gym", "facilities": ["kleedkamers"],
                   "buildingCode": "00X100", "floor": "0"})

    # --- courses and curriculum plans ---------------------------------------
    def course(code: str, name: str, description: str) -> dict:
        return b.add("course", {"code": code, "name": name, "name_nl": name, "description": description, "level": "po",
                                "language": "nl", "tags": ["basisonderwijs"], "lifecycle": "published"})

    basis = course("PO-BAS", "Basisonderwijs", "Inschrijving in het basisonderwijs van groep 1 tot en met 8.")
    subject_courses = {code: course(code, name, "Vak in groep 3 tot en met 8.") for code, name, _o in REPORT_SUBJECTS}
    english = course("PO-ENG", "Engels", "Engels in groep 7 en 8.")
    gym = course("PO-BEW", "Bewegingsonderwijs", "Gymles door de vakleerkracht, twee keer per week.")
    plans = {}
    for code, name, _offset in REPORT_SUBJECTS:
        plans[code] = b.add("curriculum-plan", {
            "name": f"{name} groep 3 tot en met 8, {YEAR}", "kind": "generic", "formula": "weighted-average",
            "requiredCourseIds": [subject_courses[code]["uuid"]], "electiveCourseIds": [],
            "passRules": [{"componentId": None, "minValue": 5.5}],
            "periods": [{"periodId": p[0], "label": p[1], "startDate": p[2].isoformat(), "endDate": p[3].isoformat()} for p in PERIODS],
            "lifecycle": "published",
        })

    # --- pupils per class ---------------------------------------------------
    pupils = []
    for name, leerjaren, sizes, _loc, _room in CLASSES:
        for leerjaar, size in zip(leerjaren, sizes):
            for _ in range(size):
                boy = rng.random() < 0.5
                # Born in the school year that makes them `leerjaar + 3` on 1 October 2025.
                start = dt.date(2025 - (leerjaar + 4), 10, 1)
                birth = start + dt.timedelta(days=rng.randrange(365))
                pupils.append({"class": name, "leerjaar": leerjaar, "boy": boy,
                               "given": rng.choice(BOYS if boy else GIRLS), "birth": birth,
                               "ability": rng.gauss(0, 1), "punctual": rng.random()})

    # Families: siblings sit in different leerjaren.
    by_leerjaar: dict[int, list[dict]] = {}
    for p in pupils:
        by_leerjaar.setdefault(p["leerjaar"], []).append(p)
    for pool in by_leerjaar.values():
        rng.shuffle(pool)
    families = []
    while any(by_leerjaar.values()):
        size = rng.choices([1, 2, 3], weights=[62, 32, 6])[0]
        open_years = [lj for lj, pool in by_leerjaar.items() if pool]
        chosen = rng.sample(open_years, min(size, len(open_years)))
        families.append([by_leerjaar[lj].pop() for lj in chosen])

    guardian_counter = 0
    used_names = set()
    for index, kids in enumerate(families):
        while True:
            surname = rng.choice(SURNAME_HEAD) + rng.choice(SURNAME_TAIL)
            if surname not in used_names or len(used_names) > 250:
                used_names.add(surname)
                break
        address = {"street": rng.choice(STREETS), "houseNumber": str(rng.randint(1, 140)),
                   "postalCode": f"04{rng.randint(10, 39)} {rng.choice('ABDEGHKLMNPRSTWZ')}{rng.choice('ABDEGHKLMNPRSTWZ')}",
                   "city": "Wilgendam", "country": "NL"}
        single = rng.random() < 0.15
        guardians = []
        for g in range(1 if single else 2):
            guardian_counter += 1
            female = (g == 0) if not single else rng.random() < 0.75
            guardians.append(b.add("learner-profile", {
                "ncUserId": f"po-ouder-{guardian_counter:03d}",
                "givenName": rng.choice(ADULT_F if female else ADULT_M),
                "familyName": surname,
                "roles": ["parent"],
                "hasParentalAuthority": True,
                "address": address,
                "parentIds": [], "guardianRefs": [], "emergencyContacts": [],
                "lifecycle": "active",
            }))
        for kid in kids:
            kid["family"] = index
            kid["surname"] = surname
            kid["address"] = address
            kid["guardians"] = guardians

    # Guardians are all added above; pupils follow in class order.
    pupils.sort(key=lambda p: (CLASSES.index(next(c for c in CLASSES if c[0] == p["class"])), p["leerjaar"], p["surname"], p["given"]))
    for n, p in enumerate(pupils, start=1):
        p["nc"] = f"po-leerling-{n:03d}"
        # Enrolled on the fourth birthday, or on the first school day of the year for anyone older.
        fourth = dt.date(p["birth"].year + 4, p["birth"].month, min(p["birth"].day, 28))
        while fourth.weekday() >= 5:
            fourth += dt.timedelta(days=1)
        p["enrolled"] = fourth
        emergency = []
        if rng.random() < 0.4:
            emergency = [{"name": ("Oma " if rng.random() < 0.6 else "Opa ") + p["surname"], "relationship": "grootouder",
                          "phone": f"06-0000{rng.randint(1000, 9999)}", "priority": 1}]
        consent = {k: (None if rng.random() < 0.08 else rng.random() < 0.8) for k in ["website", "socialMedia", "schoolgids", "classPhoto", "video"]}
        p["profile"] = b.add("learner-profile", {
            "ncUserId": p["nc"],
            "givenName": p["given"],
            "familyName": p["surname"],
            "birthDate": p["birth"].isoformat(),
            "schoolId": school["uuid"],
            "roles": ["learner"],
            "parentIds": [g["ncUserId"] for g in p["guardians"]],
            "guardianRefs": [g["uuid"] for g in p["guardians"]],
            "address": p["address"],
            "emergencyContacts": emergency,
            "allergies": (["pinda's"] if rng.random() < 0.05 else None),
            "medicalConditions": (["astma"] if rng.random() < 0.03 else None),
            "beeldmateriaalConsent": consent,
            "lifecycle": "active",
        })

    # --- classes (cohorts) --------------------------------------------------
    cohorts = {}
    for name, _lj, _sizes, loc, room in CLASSES:
        teachers = TEACHERS[name]
        notes = None
        if name == "Groep 5/6":
            notes = "Combinatiegroep met een duo: de eerste leerkracht op maandag tot en met woensdag, de tweede op donderdag en vrijdag."
        if name == "Groep 7":
            notes = "Extra aandacht voor rekenen dit jaar; twee keer per week verlengde instructie."
        cohorts[name] = b.add("cohort", {
            "name": name, "period": "Schooljaar", "academicYear": YEAR, "courseId": basis["uuid"],
            "teacherIds": [t[0] for t in teachers],
            "learnerIds": [p["nc"] for p in pupils if p["class"] == name],
            "lifecycle": "active", "locationId": locations[loc]["uuid"],
            "teacherAssignments": [{"teacherId": t[0], "role": t[1], "days": t[2]} for t in teachers],
            "notes": notes, "kind": "teaching",
        })
        cohorts[name]["_room"] = rooms[room]["uuid"]

    # --- staff --------------------------------------------------------------
    staff_rows = [("po-directeur-01", ["administrator"], ["schoolleider"], WEEKDAYS),
                  (IB, ["coordinator"], ["intern begeleider", "bevoegdheid groep 1-8"], ["monday", "tuesday", "thursday"])]
    for name, *_rest in CLASSES:
        for teacher, _role, work in TEACHERS[name]:
            qual = ["bevoegdheid groep 1-8"]
            if name in ("Groep 1", "Groep 2"):
                qual.append("jonge kind specialist")
            if name == "Groep 5/6":
                qual = ["bevoegdheid groep 5-8"]
            staff_rows.append((teacher, ["teacher", "mentor"], qual, work))
    staff_rows += [(GYM_TEACHER, ["teacher"], ["vakbekwaamheid bewegingsonderwijs"], ["tuesday", "thursday"]),
                   ("po-onderwijsassistent-01", ["teaching-assistant"], ["onderwijsassistent"], ["monday", "tuesday", "thursday", "friday"]),
                   ("po-administratie-01", ["administrator"], ["BHV"], ["monday", "wednesday", "friday"])]
    for nc, roles, qual, work in staff_rows:
        b.add("staff", {"ncUserId": nc, "roles": roles, "qualifications": qual, "workingDays": work})

    # --- enrolments ---------------------------------------------------------
    by_date = sorted(pupils, key=lambda p: (p["enrolled"], p["nc"]))
    for volgnummer, p in enumerate(by_date, start=1):
        p["volgnummer"] = volgnummer
    for p in pupils:
        cohort = cohorts[p["class"]]
        b.add("enrolment", {
            "learnerId": p["nc"], "learnerRef": p["profile"]["uuid"], "courseId": basis["uuid"], "source": "admission",
            "cohortId": cohort["uuid"], "lifecycle": "active", "inschrijvingDate": p["enrolled"].isoformat(),
            "volgnummer": p["volgnummer"], "locationId": cohort["locationId"], "leerjaar": p["leerjaar"],
        })

    # --- subject teachers ---------------------------------------------------
    for name, leerjaren, *_rest in CLASSES:
        cohort = cohorts[name]
        teachers = TEACHERS[name]
        first, second = teachers[0][0], teachers[-1][0]
        if max(leerjaren) >= 3:
            b.add("subjectteacherassignment", {"cohortId": cohort["uuid"], "courseId": subject_courses["PO-REK"]["uuid"], "teacherId": first})
            b.add("subjectteacherassignment", {"cohortId": cohort["uuid"], "courseId": subject_courses["PO-TAAL"]["uuid"], "teacherId": second})
        if max(leerjaren) >= 7:
            b.add("subjectteacherassignment", {"cohortId": cohort["uuid"], "courseId": english["uuid"], "teacherId": second})
        b.add("subjectteacherassignment", {"cohortId": cohort["uuid"], "courseId": gym["uuid"], "teacherId": GYM_TEACHER})

    # --- report periods -----------------------------------------------------
    periods = []
    for code, label, start, end in PERIODS:
        periods.append(b.add("report-period", {
            "name": label, "academicYear": YEAR, "periodCode": code, "startDate": start.isoformat(), "endDate": end.isoformat(),
            "curriculumPlanIds": [plan["uuid"] for plan in plans.values()],
            "cohortIds": [c["uuid"] for c in cohorts.values()],
            "lockDate": stamp(end + dt.timedelta(days=7), 17, 0), "attendanceIncluded": True, "lifecycle": "composed",
            "holidays": [{"name": h[0], "startDate": h[1].isoformat(), "endDate": h[2].isoformat()} for h in HOLIDAYS if start <= h[1] <= end],
            "studyDays": [{"date": s[0].isoformat(), "description": s[1]} for s in STUDY_DAYS if start <= s[0] <= end],
        }))

    # --- sessions and attendance --------------------------------------------
    sessions: dict[tuple[str, dt.date], dict] = {}
    for name, *_rest in CLASSES:
        cohort = cohorts[name]
        for day in days:
            end_h, end_m = (12, 15) if day.weekday() == 2 else (14, 15)
            sessions[(name, day)] = b.add("session", {
                "cohortId": cohort["uuid"], "courseId": basis["uuid"],
                "title": f"{name}, {DAG[day.weekday()]} {day.day} {MAAND[day.month - 1]} {day.year}",
                "startsAt": stamp(day, 8, 30), "endsAt": stamp(day, end_h, end_m),
                "location": "Lokaal " + name.lower(), "roomId": cohort["_room"],
                "lifecycle": "completed",
            })

    def teacher_on(name: str, day: dt.date) -> str:
        weekday = WEEKDAYS[day.weekday()]
        return next(t[0] for t in TEACHERS[name] if weekday in t[2])

    # Marks per pupil: illness spells (excused), appointments (left early),
    # late arrivals, and a handful of unexcused absences.
    marks: dict[str, dict[dt.date, tuple[str, str, int | None]]] = {}
    for p in pupils:
        own = [d for d in days if d >= p["enrolled"]]
        if not own:
            continue
        pupil_marks: dict[dt.date, tuple[str, str, int | None]] = {}
        for _ in range(min(6, max(0, int(rng.gauss(2.2, 1.3))))):
            winter = [d for d in own if d.month in (11, 12, 1, 2, 3)]
            start = rng.choice(winter if winter and rng.random() < 0.65 else own)
            length = rng.choices([1, 2, 3, 4, 5], weights=[30, 30, 20, 12, 8])[0]
            i = own.index(start)
            for d in own[i:i + length]:
                pupil_marks[d] = ("absent-excused", "Ziek gemeld door ouder", 0)
        for _ in range(rng.choices([0, 1, 2], weights=[55, 35, 10])[0]):
            d = rng.choice(own)
            if d not in pupil_marks:
                pupil_marks[d] = ("left-early", rng.choice(["Tandarts", "Huisarts", "Orthodontist"]), minutes_of(d) - 90)
        lates = rng.choices([0, 1, 2, 4, 9], weights=[40, 30, 15, 10, 5])[0] if p["punctual"] < 0.9 else 0
        for _ in range(lates):
            d = rng.choice(own)
            if d not in pupil_marks:
                late_by = rng.choice([5, 10, 15, 20])
                pupil_marks[d] = ("late", "Te laat binnengekomen", minutes_of(d) - late_by)
        marks[p["nc"]] = pupil_marks

    # Extra leave refused before the May holiday: two families take it anyway.
    refused = [p for p in pupils if p["class"] in ("Groep 4", "Groep 7")][:3]
    for p in refused:
        for d in (dt.date(2026, 4, 23), dt.date(2026, 4, 24)):
            marks[p["nc"]][d] = ("absent-unexcused", "Extra verlof niet toegekend, toch afwezig", 0)

    # The leerplicht case: a pupil in groep 7 misses most of three weeks in March.
    signal = next(p for p in pupils if p["class"] == "Groep 7" and p["enrolled"] <= FIRST_DAY and p["nc"] not in {r["nc"] for r in refused})
    signal_days = [d for d in days if dt.date(2026, 3, 2) <= d <= dt.date(2026, 3, 20) and d.weekday() != 2][:7]
    for d in signal_days:
        marks[signal["nc"]][d] = ("absent-unexcused", "Niet ziek gemeld, ouders niet bereikbaar", 0)

    # Guardians report some illness spells through the portal: an excuse request each.
    excuses: dict[tuple[str, dt.date], dict] = {}
    spell_owners = [p for p in pupils if any(m[0] == "absent-excused" for m in marks.get(p["nc"], {}).values())]
    for p in rng.sample(spell_owners, 15):
        spell = sorted(d for d, m in marks[p["nc"]].items() if m[0] == "absent-excused")
        start = spell[0]
        run = [start]
        for d in spell[1:]:
            if days.index(d) == days.index(run[-1]) + 1:
                run.append(d)
            else:
                break
        guardian = p["guardians"][0]
        req = b.add("excuse-request", {
            "learnerId": p["nc"], "learnerRef": p["profile"]["uuid"],
            "submittedBy": guardian["ncUserId"], "submittedByRef": guardian["uuid"],
            "dateFrom": run[0].isoformat(), "dateTo": run[-1].isoformat(),
            "reason": rng.choice(["Griep", "Koorts", "Buikgriep", "Waterpokken"]), "reasonKind": "illness",
            "submittedAuthLevel": "basic", "decidedBy": teacher_on(p["class"], run[0]),
            "decidedAt": stamp(run[0], 10, 15), "decisionNote": None, "lifecycle": "approved",
        })
        for d in run:
            excuses[(p["nc"], d)] = req

    signal_records = []
    for p in pupils:
        for d in sorted(marks.get(p["nc"], {})):
            status, reason, minutes = marks[p["nc"]][d]
            rec = b.add("attendance-record", {
                "sessionId": sessions[(p["class"], d)]["uuid"], "learnerId": p["nc"], "learnerRef": p["profile"]["uuid"],
                "cohortId": cohorts[p["class"]]["uuid"], "status": status,
                "minutesAttended": minutes, "markedBy": teacher_on(p["class"], d),
                "markedAt": stamp(d, 8, 50 if status == "late" else 40), "reason": reason,
                "excuseRequestId": (excuses[(p["nc"], d)]["uuid"] if (p["nc"], d) in excuses else None),
            })
            if p is signal and status == "absent-unexcused":
                signal_records.append(rec["uuid"])

    threshold = b.add("attendance-threshold", {
        "name": "Leerplicht: 16 uur ongeoorloofd verzuim in 4 weken", "kind": "leerplicht-16uur", "scope": "per-learner",
        "window": {"type": "rolling-weeks", "weeks": 4, "termId": None}, "metric": "unexcused-lesuren", "limit": 16,
        "lessonHourMinutes": 60,
        "onCross": {"notify": True, "notifyRoles": ["mentor", "coordinator"], "createFlag": True, "dataExchangeTarget": None},
        "active": True, "lifecycle": "active",
    })
    b.add("attendance-flag", {
        "learnerId": signal["nc"], "attendanceThresholdId": threshold["uuid"], "cohortId": cohorts["Groep 7"]["uuid"],
        "windowStart": dt.date(2026, 3, 2).isoformat(), "windowEnd": dt.date(2026, 3, 27).isoformat(),
        "metricValue": round(sum(minutes_of(d) for d in signal_days) / 60, 2), "breachingRecordIds": signal_records,
        "mentorId": TEACHERS["Groep 7"][0][0], "flagKind": "signal-verzuim", "lifecycle": "resolved",
        "interventions": [
            {"recordedBy": IB, "recordedAt": stamp(dt.date(2026, 3, 23), 15, 30),
             "note": "Gesprek met ouders op school; afspraken gemaakt over ziekmelden en de ochtendroutine."},
        ],
    })

    # --- LVS results --------------------------------------------------------
    # The import jobs these results arrived through live in integriq now (data-exchange-to-integriq), so the
    # results carry no dataExchangeJobId.

    def level(z: float) -> str:
        return "I" if z > 0.84 else "II" if z > 0.25 else "III" if z > -0.25 else "IV" if z > -0.84 else "V"

    for p in pupils:
        if p["leerjaar"] < 3 or p["enrolled"] > dt.date(2026, 1, 12):
            continue
        moments = [("M", dt.date(2026, 1, 19))]
        if p["leerjaar"] < 8:
            moments.append(("E", dt.date(2026, 6, 1)))
        for code, base_day in moments:
            for instrument, offset in LVS_INSTRUMENTS:
                z = max(-2.5, min(2.5, p["ability"] + offset + rng.gauss(0, 0.45)))
                months = (p["leerjaar"] - 3) * 10 + (5 if code == "M" else 10)
                b.add("lvs-result", {
                    "provider": "cito", "instrument": instrument, "moment": f"{code}{p['leerjaar']}",
                    "takenAt": (base_day + dt.timedelta(days=rng.randrange(10))).isoformat(),
                    "rawScore": None, "vaardigheidsscore": round(40 + months * 1.6 + z * 9, 1),
                    "niveau": level(z), "referentieniveau": None, "dle": round(max(1, months + z * 5)),
                    "learnerId": p["nc"], "learnerRef": p["profile"]["uuid"], "dataExchangeJobId": None, "lifecycle": "verified",
                })
        if p["leerjaar"] == 8:
            z = max(-2.5, min(2.5, p["ability"] + rng.gauss(0, 0.3)))
            b.add("lvs-result", {
                "provider": "iep", "instrument": "Doorstroomtoets", "moment": "februari 2026",
                "takenAt": dt.date(2026, 2, 10).isoformat(), "rawScore": None, "vaardigheidsscore": round(80 + z * 7, 1),
                "niveau": level(z), "referentieniveau": ("2F/1S" if z > 0.4 else "1F" if z > -1.2 else "onder 1F"),
                "dle": None, "learnerId": p["nc"], "learnerRef": p["profile"]["uuid"], "dataExchangeJobId": None, "lifecycle": "verified",
            })

    # --- report cards -------------------------------------------------------
    for (code, _label, start, end), period in zip(PERIODS, periods):
        for p in pupils:
            own = [d for d in days if start <= d <= end and d >= p["enrolled"]]
            if not own:
                continue
            pm = marks.get(p["nc"], {})
            summary = {
                "absentExcusedCount": sum(1 for d in own if pm.get(d, ("",))[0] == "absent-excused"),
                "absentUnexcusedCount": sum(1 for d in own if pm.get(d, ("",))[0] == "absent-unexcused"),
                "lateCount": sum(1 for d in own if pm.get(d, ("",))[0] == "late"),
                "leftEarlyCount": sum(1 for d in own if pm.get(d, ("",))[0] == "left-early"),
            }
            absent = summary["absentExcusedCount"] + summary["absentUnexcusedCount"]
            summary["presentCount"] = len(own) - absent
            summary["attendancePercent"] = round(100 * (len(own) - absent) / len(own), 1)
            he = "hij" if p["boy"] else "zij"
            grades = []
            if p["leerjaar"] >= 3:
                for subject, _name, offset in REPORT_SUBJECTS:
                    avg = round(max(4.0, min(9.8, 7.1 + 0.9 * p["ability"] + offset + rng.gauss(0, 0.35) + (0.1 if code == "2" else 0))), 1)
                    grades.append({"curriculumPlanId": plans[subject]["uuid"], "courseId": subject_courses[subject]["uuid"],
                                   "periodAverage": avg, "passed": avg >= 5.5, "teacherComment": None, "sourceGradeEntryIds": []})
                weakest = min(grades, key=lambda g: g["periodAverage"])
                weakest_name = next(s[1] for s in REPORT_SUBJECTS if plans[s[0]]["uuid"] == weakest["curriculumPlanId"])
                if weakest["periodAverage"] < 6.0:
                    weakest["teacherComment"] = f"Hier oefenen we samen extra mee; thuis voorlezen of samen oefenen helpt {p['given']} ook."
                comment = rng.choice([
                    f"{p['given']} werkt zelfstandig en helpt anderen graag. Bij {weakest_name.lower()} mag {he} nog iets meer tempo maken.",
                    f"{p['given']} is betrokken in de klas en stelt goede vragen. We werken dit halfjaar extra aan {weakest_name.lower()}.",
                    f"Het is fijn om te zien hoe {p['given']} groeit. {he.capitalize()} kan trots zijn op deze periode.",
                    f"{p['given']} heeft een goede werkhouding. Plannen en afmaken van het weektaakwerk vraagt nog aandacht.",
                ])
            else:
                comment = rng.choice([
                    f"{p['given']} speelt graag in de bouwhoek en maakt makkelijk contact met andere kinderen.",
                    f"{p['given']} luistert goed in de kring en vertelt enthousiast over wat {he} heeft meegemaakt.",
                    f"{p['given']} is gewend in de groep. We oefenen nog met het zelf aan- en uittrekken van de jas.",
                    f"{p['given']} knipt, plakt en tekent met veel plezier en werkt steeds langer geconcentreerd.",
                ])
            b.add("report-card", {
                "learnerId": p["nc"], "learnerRef": p["profile"]["uuid"], "reportPeriodId": period["uuid"],
                "cohortId": cohorts[p["class"]]["uuid"], "subjectGrades": grades, "attendanceSummary": summary,
                "mentorComment": comment, "composedAt": stamp(end + dt.timedelta(days=5), 16, 0),
                "lifecycle": "published-to-parents",
            })

    # --- group plan (promoted from the register's curated seed) ------------
    fivesix = [p for p in pupils if p["class"] == "Groep 5/6"]
    ranked = sorted(fivesix, key=lambda p: p["ability"])
    closed = b.add("group-plan", {
        "cohortId": cohorts["Groep 5/6"]["uuid"], "subject": "technisch lezen", "period": "2025-2026 blok 3",
        "periodEndDate": "2026-04-15", "coordinatorId": "po-leerkracht-05",
        "resultsAnalysis": {"narrative": "Cito-toetsronde blok 3: methodetoetsen tonen een groep leerlingen die achterblijft op AVI/DMT.", "evidenceRefs": []},
        "goals": [{"goalId": "goal-1", "description": "90% van de groep behaalt het AVI-niveau passend bij leerjaar aan het einde van blok 3.", "status": "met"}],
        "supersedesId": None, "lifecycle": "closed",
    })
    basis_blok3 = b.add("group-plan-subgroup", {
        "groupPlanId": closed["uuid"], "name": "Basis, technisch lezen (blok 3)", "instructieniveau": "basis",
        "learnerIds": [ranked[0]["nc"], ranked[2]["nc"]],
        "differentiatedGoal": "AVI-niveau een stap verhogen via extra leestijd.",
        "approach": "Drie keer per week begeleid lezen in kleine groep.",
        "intendedOutcome": "Beide leerlingen behalen het volgende AVI-niveau.",
    })
    evaluation = b.add("group-plan-evaluation", {
        "groupPlanId": closed["uuid"], "evaluatedAt": "2026-04-14", "evaluatedBy": "po-leerkracht-05",
        "outcomes": [{"subgroupId": basis_blok3["uuid"], "outcome": "met", "narrative": "Beide leerlingen behaalden het beoogde AVI-niveau."}],
        "narrative": "Blok 3 geëvalueerd; de groep wordt voor blok 1 van volgend jaar opnieuw ingedeeld op basis van deze uitkomst.",
    })
    # Buckets are emitted in SCHEMAS order, so this plan loads before its subgroups either way.
    active = b.add("group-plan", {
        "cohortId": cohorts["Groep 5/6"]["uuid"], "subject": "technisch lezen", "period": "2026-2027 blok 1",
        "periodEndDate": "2026-10-16", "coordinatorId": "po-leerkracht-05",
        "resultsAnalysis": {"narrative": "Vervolg op blok 3 (zie de gekoppelde evaluatie): drie duidelijke instructieniveaus binnen de klas.", "evidenceRefs": [evaluation["uuid"]]},
        "goals": [{"goalId": "goal-1", "description": "Alle subgroepen tonen aantoonbare vooruitgang op AVI-niveau bij de volgende meting.", "status": "open"}],
        "supersedesId": closed["uuid"], "lifecycle": "active",
    })
    intensief = b.add("group-plan-subgroup", {
        "groupPlanId": active["uuid"], "name": "Intensief, technisch lezen", "instructieniveau": "intensief",
        "learnerIds": [ranked[0]["nc"], ranked[1]["nc"]],
        "differentiatedGoal": "Dagelijks 1-op-1 leesbegeleiding om de leesachterstand in te lopen.",
        "approach": "Dagelijks 15 minuten remedial teaching met de intern begeleider, aanvullend op de klassikale les.",
        "intendedOutcome": "Substantiële groei op DMT/AVI binnen dit blok; een ondersteuningsvraag als dat onvoldoende blijkt.",
    })
    b.add("group-plan-subgroup", {
        "groupPlanId": active["uuid"], "name": "Basis, technisch lezen", "instructieniveau": "basis",
        "learnerIds": [p["nc"] for p in ranked[2:-3]],
        "differentiatedGoal": "Leerlijn-tempo vasthouden.",
        "approach": "Reguliere klassikale leesinstructie volgens de methode.",
        "intendedOutcome": "Leerlingen blijven op het leerlijn-tempo.",
    })
    b.add("group-plan-subgroup", {
        "groupPlanId": active["uuid"], "name": "Verdiept, technisch lezen", "instructieniveau": "verdiept",
        "learnerIds": [p["nc"] for p in ranked[-3:]],
        "differentiatedGoal": "Uitdagende teksten boven leerjaarniveau.",
        "approach": "Verrijkingsopdrachten en zelfstandig verwerken van moeilijkere teksten.",
        "intendedOutcome": "Leerlingen blijven gemotiveerd en lezen boven leerjaarniveau.",
    })

    # --- support requests and dossier notes ---------------------------------
    struggling = ranked[0]
    b.add("support-request", {
        "learnerId": struggling["nc"], "originGroupPlanSubgroupId": intensief["uuid"], "raisedBy": IB,
        "supportDomain": "Lezen en spelling (vermoeden van dyslexie)",
        "description": "Na twee blokken intensieve leesbegeleiding blijft de groei op DMT en AVI achter. Aanvraag voor een dyslexieonderzoek.",
        "urgency": "medium", "lifecycle": "submitted",
    })
    behaviour = next(p for p in pupils if p["class"] == "Groep 4")
    b.add("support-request", {
        "learnerId": behaviour["nc"], "raisedBy": IB, "supportDomain": "Gedrag en werkhouding",
        "description": "Vraag om observatie in de klas en advies over rust en structuur tijdens zelfstandig werken.",
        "urgency": "low", "lifecycle": "decided",
    })
    b.add("support-request", {
        "learnerId": signal["nc"], "raisedBy": IB, "supportDomain": "Verzuim en welbevinden",
        "description": "Na het verzuim in maart: afstemming met de jeugdverpleegkundige over wat er thuis speelt.",
        "urgency": "high", "lifecycle": "draft",
    })
    notes = [
        (struggling, "po-leerkracht-05", "2025-10-09", "observation", "Leest langzaam en raadt woorden bij het hardop lezen. Besproken met de intern begeleider.", "care-team-only"),
        (struggling, IB, "2025-11-20", "conversation", "Oudergesprek over de leesontwikkeling; ouders lezen thuis dagelijks tien minuten samen.", "care-team-only"),
        (signal, TEACHERS["Groep 7"][0][0], "2026-03-04", "phone-call-home", "Ouders gebeld over de afwezigheid; geen gehoor, voicemail ingesproken.", "care-team-only"),
        (signal, IB, "2026-03-23", "conversation", "Gesprek met ouders over het verzuim. Afspraken over ziekmelden voor 08.15 uur.", "care-team-only"),
        (behaviour, "po-leerkracht-07", "2025-09-25", "concern", "Heeft moeite om bij de les te blijven na de pauze; zit onrustig.", "team-visible"),
        (behaviour, "po-leerkracht-07", "2026-02-12", "positive", "Werkt met de time-timer veel geconcentreerder. Mooie vooruitgang.", "team-visible"),
        (ranked[-1], "po-leerkracht-06", "2026-01-15", "positive", "Leest boven niveau en helpt klasgenoten bij het voorlezen.", "team-visible"),
        (pupils[3], "po-leerkracht-01", "2025-09-05", "observation", "Went goed in de groep; speelt veel met de blokken en in de huishoek.", "team-visible"),
    ]
    for p, author, date, category, body, confidentiality in notes:
        b.add("dossier-note", {"learnerId": p["nc"], "authorId": author, "date": date, "category": category, "body": body,
                               "confidentiality": confidentiality, "careTeamUserIds": [IB, teacher_on(p["class"], dt.date.fromisoformat(date))]})

    # --- assemble -----------------------------------------------------------
    for cohort in cohorts.values():
        del cohort["_room"]
    objects = {name: rows for name, rows in b.buckets.items() if rows}
    total = sum(len(rows) for rows in objects.values())
    return {
        "openapi": "3.0.0",
        "info": {
            "title": "Learniq example set: Primary school",
            "version": "1.0.0",
            "description": "Voorbeeldschool De Wilgenboom, a fictional primary school in the fictional town of Wilgendam, through the 2025-2026 school year.",
        },
        "x-openregister": {
            "type": "profile",
            "app": "learniq",
            "profile": {
                "id": SET,
                "segment": SET,
                "label": "Primary school",
                "description": "A fictional primary school with groups 1 to 8, pupils, guardians and one full school year.",
                "order": 1,
                "objectCount": total,
                "icon": "SchoolOutline",
            },
            "description": (
                "An example set an operator picks in the first-time setup wizard (ADR-042, decision D21). NEVER imported on install. "
                "Every object carries @self.configuration/register/schema and a fixed uuid in the ee01 namespace, so the import resolves "
                "the live learniq register without this descriptor declaring components.registers (which would re-point the register at "
                "this profile config id and overwrite its authorization block), a second load adds nothing, and occ "
                "learniq:example-set:remove po removes exactly these objects. Generated by scripts/example-sets/po.py; the contract is "
                "openspec/changes/archive/2026-09-28-segment-wizard-choice/contract.md. Every person, address, school and code in it is fictional."
            ),
            "seedData": {
                "description": (
                    "One school with two locations, seven classes for groups 1 to 8 (5 and 6 combined), about 200 pupils and their "
                    "guardians, staff and subject teachers, a school day per class per day of 2025-2026 with the absences recorded, "
                    "two report periods with report cards, Cito and doorstroomtoets results, a group plan, support requests and dossier notes."
                ),
                "objects": objects,
            },
        },
        "paths": {},
        "components": {},
    }


def render(data: dict) -> str:
    """Pretty JSON with one compact object per line in each seed bucket.

    Thousands of objects pretty-printed field by field made a 115,000-line file
    nobody could review; one object per line keeps a diff to the objects that
    changed and halves the size. Still strict JSON.
    """
    buckets = data["x-openregister"]["seedData"]["objects"]
    data["x-openregister"]["seedData"]["objects"] = "__OBJECTS__"
    text = json.dumps(data, indent=2, ensure_ascii=False)
    parts = []
    for name, rows in buckets.items():
        lines = ",\n".join("          " + json.dumps(row, ensure_ascii=False, separators=(", ", ": ")) for row in rows)
        parts.append(f"        {json.dumps(name)}: [\n{lines}\n        ]")
    return text.replace('"__OBJECTS__"', "{\n" + ",\n".join(parts) + "\n      }") + "\n"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--check", action="store_true", help="exit 1 when the file on disk differs")
    args = parser.parse_args()
    text = render(build())
    if args.check:
        current = open(OUT, encoding="utf-8").read() if os.path.exists(OUT) else ""
        if current != text:
            print(f"{OUT} is out of date; run python3 scripts/example-sets/po.py", file=sys.stderr)
            return 1
        print(f"{OUT} is up to date")
        return 0
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, "w", encoding="utf-8") as handle:
        handle.write(text)
    data = json.loads(text)
    counts = {k: len(v) for k, v in data["x-openregister"]["seedData"]["objects"].items()}
    print(f"wrote {OUT}: {data['x-openregister']['profile']['objectCount']} objects")
    for name, n in counts.items():
        print(f"  {name}: {n}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
