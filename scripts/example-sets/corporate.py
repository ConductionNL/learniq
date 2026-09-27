#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
# Copyright (C) 2026 Conduction B.V.
"""Build lib/Settings/profiles/corporate.json, the company example set.

One fictional company, Voorbeeldbedrijf Esdoorn Techniek B.V. in the fictional
town of Esdoornhaven, an installation and service firm, through one complete
training year (2025-2026): a head office and a workshop and warehouse site,
eight departments as cohorts, 200 employees with their managers, an HR adviser,
a compliance officer, a training coordinator and two in-house trainers on
Staff. Mandatory compliance e-learning and classroom certification issue
credentials; a credential that expires during the year opens a renewal
enrolment, the way CredentialRenewalListener does it. Around that: toolbox
meetings with their attendance, external training records, a competency
framework with the skills gaps it shows, a personal development plan per
employee, engagement scores, points and leaderboards, and orders with
entitlements for the three paid courses.

WHY A SCRIPT. The set is several thousand objects that must agree with each
other: a renewal enrolment starts on the day the old certificate expires, the
new certificate is issued at the end of the session the employee attended, a
skills gap is exactly a required competency without an attainment, and a
leaderboard total is exactly the sum of that employee's point awards.
Hand-editing that is how sets drift. The script is deterministic (fixed seed),
so running it again produces the same file byte for byte, and a reviewer reads
the rules here rather than megabytes of JSON.

THE CONTRACT. openspec/changes/segment-wizard-choice/contract.md. Every rule is
checked by tests/Unit/Settings/ExampleSetDescriptorContractTest.php; run it
after regenerating. The story is checked by CorporateExampleSetTest.

Usage:
    python3 scripts/example-sets/corporate.py            write the file
    python3 scripts/example-sets/corporate.py --check    exit 1 when the file on disk differs

Nothing here is real: no real company, person, address or certificate.
Postcodes start with 0, which the Netherlands never issues; e-mail addresses
and issuer DIDs use the reserved .example domain; the BRIN-shaped company code
00X5 ends in a digit, which DUO never assigns; every credential carries the
signature "voorbeeldgegevens-niet-ondertekend", so no verifier can take one for
a real certificate.
"""

from __future__ import annotations

import argparse
import calendar
import datetime as dt
import json
import os
import random
import sys
from zoneinfo import ZoneInfo

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
OUT = os.path.join(ROOT, "lib", "Settings", "profiles", "corporate.json")
AMS = ZoneInfo("Europe/Amsterdam")
TENANT = "00000000-0000-4000-8000-000000000000"
SET = "corporate"
SET_NUMBER = "05"
YEAR = "2025-2026"
FIRST_DAY = dt.date(2025, 8, 18)
LAST_DAY = dt.date(2026, 7, 10)

COMPANY = "Voorbeeldbedrijf Esdoorn Techniek B.V."
TOWN = "Esdoornhaven"
COMPANY_DID = "did:web:esdoorn-techniek.example"
UNSIGNED = "voorbeeldgegevens-niet-ondertekend"
PAYER = COMPANY + ", afdeling HR"
PAYER_EMAIL = "opleidingen@esdoorn-techniek.example"
OB3_CONTEXT = ["https://www.w3.org/ns/credentials/v2", "https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json"]
VERB_COMPLETED = "http://adlnet.gov/expapi/verbs/completed"
VERB_PASSED = "http://adlnet.gov/expapi/verbs/passed"

DIRECTOR = "corporate-directeur-01"
HR = "corporate-hr-01"
COMPLIANCE = "corporate-compliance-01"
COORDINATOR = "corporate-opleidingen-01"
TRAINER_BHV = "corporate-trainer-01"
TRAINER_TECH = "corporate-trainer-02"

# External issuers: (display name, DID).
EXAM_SAFETY = ("Voorbeeld Examencentrum Veiligheid", "did:web:examencentrum-veiligheid.example")
EXAM_COOLING = ("Voorbeeld Examencentrum Koudetechniek", "did:web:examencentrum-koudetechniek.example")
FORKLIFT_SCHOOL = ("Voorbeeld Heftruckopleidingen", "did:web:heftruckopleidingen.example")
HEAT_PUMP_ACADEMY = ("Voorbeeld Warmtepompacademie", "did:web:warmtepompacademie.example")
PROJECT_ACADEMY = ("Voorbeeld Projectacademie", "did:web:projectacademie.example")
OWN = (COMPANY, COMPANY_DID)

# Bucket order is load order: parents before children, removal in reverse.
SCHEMAS = [
    "school",
    "vestiging",
    "room",
    "competency-framework",
    "competency",
    "course",
    "lesson",
    "programme",
    "cohort",
    "staff",
    "learner-profile",
    "learning-plan-template",
    "enrolment",
    "session",
    "attendance-record",
    "lesson-completion",
    "credential",
    "external-training-record",
    "competency-attainment",
    "learning-plan",
    "learning-plan-evaluation",
    "engagement-risk-threshold",
    "engagement-score",
    "engagement-risk-flag",
    "point-rule",
    "engagement-level",
    "point-award",
    "learner-engagement",
    "leaderboard",
    "fee-item",
    "order",
    "order-line",
    "payment-transaction",
    "entitlement",
]

# Public holidays and the Christmas closure: no training, no toolbox meeting.
CLOSED = {
    dt.date(2025, 12, 25), dt.date(2025, 12, 26), dt.date(2026, 1, 1), dt.date(2026, 1, 2),
    dt.date(2026, 4, 6), dt.date(2026, 4, 27), dt.date(2026, 5, 14), dt.date(2026, 5, 15), dt.date(2026, 5, 25),
}
WEEKDAYS = ["monday", "tuesday", "wednesday", "thursday", "friday"]
MAAND = ["januari", "februari", "maart", "april", "mei", "juni", "juli", "augustus", "september", "oktober", "november", "december"]

# key, cohort name, department path (LearnerProfile.department), site, size, head.
DEPARTMENTS = [
    ("directie", "Directie en staf", "Directie en staf", "hoofd", 10, DIRECTOR),
    ("financien", "Financiën en administratie", "Bedrijfsvoering/Financiën en administratie", "hoofd", 14, "corporate-manager-01"),
    ("ict", "ICT", "Bedrijfsvoering/ICT", "hoofd", 12, "corporate-manager-02"),
    ("verkoop", "Verkoop en klantenservice", "Commercie/Verkoop en klantenservice", "hoofd", 30, "corporate-manager-03"),
    ("planning", "Planning en werkvoorbereiding", "Operatie/Planning en werkvoorbereiding", "hoofd", 16, "corporate-manager-04"),
    ("installatie", "Installatie en service", "Operatie/Installatie en service", "haven", 72, "corporate-manager-05"),
    ("magazijn", "Magazijn en logistiek", "Operatie/Magazijn en logistiek", "haven", 32, "corporate-manager-06"),
    ("werkplaats", "Werkplaats", "Operatie/Werkplaats", "haven", 14, "corporate-manager-07"),
]
DEPARTMENT_NOTES = {
    "directie": "Directie, HR, compliance en de opleidingscoördinator.",
    "verkoop": "Binnendienst, accountmanagers en de klantenservice.",
    "installatie": "Monteurs werken vanuit de bus. Toolboxmeeting op de eerste dinsdag van de maand om 07.30 uur in de kantine.",
    "magazijn": "Ontvangst, orderpicking en de eigen bezorgdienst. Toolboxmeeting op de eerste dinsdag van de maand om 08.00 uur in de kantine.",
    "werkplaats": "Prefab, reparatie en de praktijkhal waar de interne trainingen draaien. Toolboxmeeting op de eerste dinsdag van de maand om 07.30 uur in de praktijkhal.",
}
OFFICE = ["directie", "financien", "ict", "verkoop", "planning"]
OPERATIONS = ["planning", "installatie", "magazijn", "werkplaats"]
TECHNICIANS = ["installatie", "werkplaats"]
# Toolbox meetings: department, room key, start, end. All end before the first training starts at 08:30.
TOOLBOX = [("installatie", "kantine", (7, 30), (8, 0)), ("magazijn", "kantine", (8, 0), (8, 30)), ("werkplaats", "praktijk", (7, 30), (8, 0))]
# People with a role beyond "learner", per department, after the head.
SPECIAL = {
    "directie": [(HR, ["learner", "hr"]), (COMPLIANCE, ["learner", "compliance-officer"]), (COORDINATOR, ["learner", "hr"])],
    "installatie": [(TRAINER_TECH, ["learner", "instructor"])],
    "werkplaats": [(TRAINER_BHV, ["learner", "instructor"])],
}
# Joiners during the year, per department, with their first working day.
NEW_HIRES = [
    ("installatie", dt.date(2025, 9, 1)), ("verkoop", dt.date(2025, 9, 15)), ("magazijn", dt.date(2025, 10, 1)),
    ("installatie", dt.date(2025, 10, 13)), ("ict", dt.date(2025, 11, 3)), ("installatie", dt.date(2025, 11, 17)),
    ("planning", dt.date(2026, 1, 5)), ("verkoop", dt.date(2026, 1, 12)), ("magazijn", dt.date(2026, 2, 2)),
    ("installatie", dt.date(2026, 2, 16)), ("financien", dt.date(2026, 3, 2)), ("werkplaats", dt.date(2026, 3, 16)),
    ("installatie", dt.date(2026, 4, 1)), ("verkoop", dt.date(2026, 4, 13)), ("magazijn", dt.date(2026, 5, 4)),
    ("installatie", dt.date(2026, 5, 18)),
]
TOOLBOX_TOPICS = [
    "werken op hoogte", "ladders en steigers", "veilig werken in de meterkast", "gladheid en winters weer",
    "tillen en ergonomie", "gevaarlijke stoffen", "brandveiligheid in de bus", "werken in kruipruimten",
    "hitte en zon", "incidenten en bijna-ongevallen melden",
]

GIVEN_M = [
    "Mark", "Peter", "Jeroen", "Bas", "Martijn", "Erik", "Rick", "Dennis", "Tarik", "Hasan", "Joost", "Wouter", "Sander",
    "Niels", "Arjen", "Pieter", "Ricardo", "Karim", "Thijs", "Koen", "Stefan", "Vincent", "Jasper", "Remco", "Ahmed",
    "Youssef", "Kevin", "Robin", "Tom", "Marco", "Frank", "Hans", "Gerard", "Ronald", "Wim", "Bart", "Luuk", "Daan",
]
GIVEN_F = [
    "Linda", "Sanne", "Esther", "Marloes", "Anouk", "Kim", "Iris", "Fatima", "Laura", "Nicole", "Petra", "Judith",
    "Ingrid", "Samira", "Eline", "Mirjam", "Chantal", "Naima", "Femke", "Lotte", "Maaike", "Renate", "Yvonne",
    "Priya", "Sevda", "Hanneke", "Monique", "Sabine", "Wendy", "Jolanda", "Nadia", "Roos",
]
SURNAME_HEAD = ["Reiger", "Kievit", "Merel", "Lijster", "Zwaluw", "Specht", "Havik", "Wulp", "Grutto", "Buizerd",
                "Fazant", "Gors", "Kwartel", "Roerdomp", "Tureluur", "Plevier", "Sperwer", "Ijsvogel"]
SURNAME_TAIL = ["hof", "kamp", "veld", "horst", "brink", "dal", "meer", "wijk", "berg", "stein", "gaard", "hout", "laar", "rode"]


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


def moment(day: dt.date, hour: int, minute: int) -> dt.datetime:
    """A local Amsterdam moment."""
    return dt.datetime(day.year, day.month, day.day, hour, minute, tzinfo=AMS)


def iso(value: dt.datetime) -> str:
    """ISO 8601 with its offset, e.g. 2025-09-01T08:30:00+02:00."""
    return value.isoformat()


def add_months(value: dt.datetime, months: int) -> dt.datetime:
    """The same local time `months` later; the 31st falls back to the month's last day."""
    total = value.month - 1 + months
    year, month = value.year + total // 12, total % 12 + 1
    day = min(value.day, calendar.monthrange(year, month)[1])
    return dt.datetime(year, month, day, value.hour, value.minute, tzinfo=AMS)


def is_workday(day: dt.date) -> bool:
    return day.weekday() < 5 and day not in CLOSED


def workdays(start: dt.date, end: dt.date) -> list[dt.date]:
    days = []
    day = start
    while day <= end:
        if is_workday(day):
            days.append(day)
        day += dt.timedelta(days=1)
    return days


def on_or_after(day: dt.date) -> dt.date:
    while not is_workday(day):
        day += dt.timedelta(days=1)
    return day


def plus_workdays(day: dt.date, n: int) -> dt.date:
    day = on_or_after(day)
    for _ in range(n):
        day = on_or_after(day + dt.timedelta(days=1))
    return day


def first_tuesday(year: int, month: int) -> dt.date:
    day = dt.date(year, month, 1)
    while day.weekday() != 1:
        day += dt.timedelta(days=1)
    return on_or_after(day)


def build() -> dict:
    rng = random.Random(20250901)
    b = Builder()

    def pick_day(lo: dt.date, hi: dt.date) -> dt.date:
        return rng.choice(workdays(lo, hi))

    def clock() -> tuple[int, int]:
        return rng.randint(8, 15), rng.choice([0, 10, 15, 20, 30, 40, 45, 50])

    # --- company, sites, rooms ----------------------------------------------
    company = b.add("school", {"brin": "00X5", "name": COMPANY})
    sites = {
        "hoofd": b.add("vestiging", {
            "schoolId": company["uuid"], "vestigingscode": "00X500", "onderwijslocatiecode": None,
            "name": "Hoofdkantoor", "street": "Esdoornlaan 1", "postalCode": "0512 EH", "city": TOWN,
        }),
        "haven": b.add("vestiging", {
            "schoolId": company["uuid"], "vestigingscode": "00X501", "onderwijslocatiecode": None,
            "name": "Werkplaats en magazijn Havenkwartier", "street": "Havenkade 40", "postalCode": "0514 HK", "city": TOWN,
        }),
    }
    rooms = {
        "training": b.add("room", {"name": "Trainingsruimte Esdoorn", "code": "TR-1", "capacity": 16, "kind": "classroom",
                                   "facilities": ["beamer", "whiteboard"], "buildingCode": "00X500", "floor": "1"}),
        "vergader": b.add("room", {"name": "Vergaderzaal Linde", "code": "VZ-2", "capacity": 10, "kind": "classroom",
                                   "facilities": ["scherm", "videobellen"], "buildingCode": "00X500", "floor": "2"}),
        "praktijk": b.add("room", {"name": "Praktijkhal", "code": "PH-1", "capacity": 20, "kind": "lab",
                                   "facilities": ["installatiewand", "warmtepompopstelling", "meterkastopstelling"],
                                   "buildingCode": "00X501", "floor": "0"}),
        "kantine": b.add("room", {"name": "Kantine Havenkwartier", "code": "KH-0", "capacity": 80, "kind": "other",
                                  "facilities": ["beamer"], "buildingCode": "00X501", "floor": "0"}),
        "online": b.add("room", {"name": "Online via Talk", "code": "ONLINE", "capacity": 100, "kind": "online", "facilities": []}),
    }

    # --- competency framework -------------------------------------------------
    framework = b.add("competency-framework", {
        "name": "Competentieprofiel Esdoorn Techniek", "sourceAuthority": "other",
        "sourceRef": "Eigen competentieprofiel, vastgesteld door directie en HR", "edition": "2025", "level": "corporate",
        "description": "Wat het bedrijf per rol verwacht: veilig werken, vakmanschap, digitaal werken en leidinggeven.",
        "proficiencyLevels": [
            {"levelId": "basis", "label": "Basis", "order": 1, "minPercent": 0},
            {"levelId": "gevorderd", "label": "Gevorderd", "order": 2, "minPercent": 65},
            {"levelId": "expert", "label": "Expert", "order": 3, "minPercent": 85},
        ],
        "lifecycle": "published",
    })
    comp: dict[str, dict] = {}

    def competency(code: str, title: str, description: str, parent: str | None, order: int, roles: list[str] | None = None) -> None:
        fields = {"frameworkId": framework["uuid"]}
        if parent is not None:
            fields["parentId"] = comp[parent]["uuid"]
        fields.update({"code": code, "title": title, "description": description, "order": order,
                       "requiredForRoles": roles or [], "lifecycle": "published"})
        comp[code] = b.add("competency", fields)

    competency("VEI", "Veilig werken", "Veilig werken op locatie, in de werkplaats en in het magazijn.", None, 1)
    competency("VEI-01", "Veilig werken volgens VCA", "Risico's op de werkplek herkennen en de VCA-regels toepassen.", "VEI", 1)
    competency("VEI-02", "Bedrijfshulpverlening", "Eerste hulp verlenen, een beginnende brand blussen en een ontruiming leiden.", "VEI", 2)
    competency("VEI-03", "Elektrotechnisch veilig werken (NEN 3140)", "Veilig werken aan en nabij elektrische installaties als voldoend onderricht persoon.", "VEI", 3)
    competency("VEI-04", "Heftruck veilig bedienen", "Een heftruck veilig bedienen, laden en lossen.", "VEI", 4)
    competency("VAK", "Vakmanschap installatietechniek", "Het technische vak van de servicemonteur.", None, 2)
    competency("VAK-01", "Warmtepompen installeren en in bedrijf stellen", "Een warmtepomp plaatsen, aansluiten, inregelen en overdragen aan de klant.", "VAK", 1)
    competency("VAK-02", "Werken aan koelcircuits met F-gassen", "Gecertificeerd werken aan koelcircuits volgens de F-gassenregels.", "VAK", 2)
    competency("VAK-03", "Storingen analyseren en verhelpen", "Een storing systematisch opsporen en blijvend verhelpen.", "VAK", 3)
    competency("DIG", "Digitaal en veilig werken", "Veilig en handig werken met systemen en gegevens.", None, 3)
    competency("DIG-01", "Phishing en social engineering herkennen", "Verdachte berichten herkennen en een incident direct melden.", "DIG", 1, ["learner"])
    competency("DIG-02", "Zorgvuldig omgaan met persoonsgegevens", "Gegevens van klanten en collega's verwerken volgens de AVG.", "DIG", 2, ["compliance-officer"])
    competency("DIG-03", "Werken met spreadsheets", "Gegevens analyseren met draaitabellen, zoekfuncties en grafieken.", "DIG", 3)
    competency("LEI", "Leidinggeven en samenwerken", "Samenwerken met collega's en klanten, en een team leiden.", None, 4)
    competency("LEI-01", "Coachend leidinggeven", "Medewerkers helpen groeien met vragen, feedback en duidelijke afspraken.", "LEI", 1, ["manager"])
    competency("LEI-02", "Verzuimgesprekken voeren", "Een verzuimgesprek voeren volgens de Wet verbetering poortwachter.", "LEI", 2, ["manager"])
    competency("LEI-03", "Klantgericht communiceren", "Een klantvraag helder uitvragen en een verwachting waarmaken.", "LEI", 3)
    competency("LEI-04", "Integer handelen volgens de gedragscode", "Een dilemma herkennen, bespreekbaar maken en de gedragscode toepassen.", "LEI", 4)

    # --- courses, lessons, programmes -------------------------------------------
    courses: dict[str, dict] = {}

    def course(key: str, name: str, description: str, tags: list[str], mandatory: bool, regulation: str | None,
               competencies: list[str], parent: str | None = None) -> None:
        fields = {"code": "ESD-" + key, "name": name, "name_nl": name, "description": description, "level": "corporate",
                  "language": "nl", "tags": tags, "mandatoryTraining": mandatory}
        if regulation is not None:
            fields["regulationSlug"] = regulation
        fields["lifecycle"] = "published"
        if parent is not None:
            fields["parentCourseId"] = courses[parent]["uuid"]
        fields["competencyIds"] = [comp[c]["uuid"] for c in competencies]
        courses[key] = b.add("course", fields)

    course("ONB", "Welkom bij Esdoorn Techniek", "Onboarding voor nieuwe medewerkers: wie we zijn, hoe we werken en hoe je veilig begint.",
           ["onboarding"], True, None, ["VEI-01"])
    course("GED", "Gedragscode en integriteit", "Jaarlijkse e-learning over de gedragscode, met dilemma's uit de praktijk.",
           ["compliance", "jaarlijks"], True, "GEDRAGSCODE", ["LEI-04"])
    course("IBV", "Informatiebeveiliging en phishing", "Jaarlijkse e-learning: phishing herkennen, veilig inloggen en een incident melden.",
           ["compliance", "jaarlijks", "informatiebeveiliging"], True, "INFORMATIEBEVEILIGING", ["DIG-01"])
    course("AVG", "AVG in de praktijk", "Zorgvuldig omgaan met gegevens van klanten en collega's, voor iedereen op kantoor.",
           ["compliance", "privacy"], True, "AVG", ["DIG-02"])
    course("BHV-B", "BHV basis", "Tweedaagse basisopleiding bedrijfshulpverlening: eerste hulp, brand blussen en ontruimen.",
           ["veiligheid", "bhv"], True, "BHV", ["VEI-02"])
    course("BHV-H", "BHV herhaling", "Jaarlijkse herhalingsdag bedrijfshulpverlening.", ["veiligheid", "bhv", "jaarlijks"], True, "BHV", ["VEI-02"])
    course("LL-SM", "Leerlijn servicemonteur", "De leerlijn voor monteurs: veilig werken, elektrotechniek, F-gassen en warmtepompen.",
           ["leerlijn", "techniek"], False, None, [])
    course("VCA-B", "VCA Basis", "Eendaagse cursus met examen voor veilig werken op locatie.", ["veiligheid", "vca"], True, "VCA", ["VEI-01"], "LL-SM")
    course("VCA-V", "VCA VOL", "VCA voor operationeel leidinggevenden, met examen.", ["veiligheid", "vca"], True, "VCA", ["VEI-01"], "LL-SM")
    course("NEN-B", "NEN 3140 basis voldoend onderricht persoon", "Eendaagse opleiding tot voldoend onderricht persoon, met aanwijzing door de werkgever.",
           ["veiligheid", "elektrotechniek"], True, "NEN3140", ["VEI-03"], "LL-SM")
    course("NEN-H", "NEN 3140 herinstructie", "Herinstructie voor voldoend onderricht personen, elke drie jaar.",
           ["veiligheid", "elektrotechniek"], True, "NEN3140", ["VEI-03"], "LL-SM")
    course("FGAS", "F-gassen certificering", "Tweedaagse cursus met examen voor werken aan koelcircuits van warmtepompen.",
           ["techniek", "f-gassen"], True, "FGASSEN", ["VAK-02"], "LL-SM")
    course("WP", "Praktijkcursus warmtepompen", "Tweedaagse praktijkcursus: plaatsen, inregelen en in bedrijf stellen van warmtepompen.",
           ["techniek", "warmtepompen", "betaald"], False, None, ["VAK-01"], "LL-SM")
    course("HEF-B", "Heftruckcertificaat basis", "Eendaagse opleiding met praktijkexamen voor heftruckchauffeurs.",
           ["veiligheid", "magazijn"], True, "HEFTRUCK", ["VEI-04"])
    course("HEF-H", "Heftruck herhaling", "Herhalingstraining heftruck, elke vijf jaar.", ["veiligheid", "magazijn"], True, "HEFTRUCK", ["VEI-04"])
    course("LEI", "Coachend leidinggeven", "Drie middagen over coachende gesprekken, feedback en verzuimbegeleiding.",
           ["leidinggeven"], False, None, ["LEI-01", "LEI-02"])
    course("KLA", "Klantgericht communiceren", "Een voorbereidende les en een trainingsdag over het klantgesprek.",
           ["verkoop", "klantenservice"], False, None, ["LEI-03"])
    course("EXC", "Excel gevorderd", "E-learning over draaitabellen, zoekfuncties en dashboards.", ["digitaal", "betaald"], False, None, ["DIG-03"])
    course("PRJ", "Projectmatig werken", "Driedaagse cursus met examen over plannen, risico's en projectoverleg.", ["projecten", "betaald"], False, None, [])
    for key, renewal in (("GED", "GED"), ("IBV", "IBV"), ("AVG", "AVG"), ("BHV-B", "BHV-H"), ("BHV-H", "BHV-H"), ("VCA-B", "VCA-B"),
                         ("VCA-V", "VCA-V"), ("NEN-B", "NEN-H"), ("NEN-H", "NEN-H"), ("HEF-B", "HEF-H"), ("HEF-H", "HEF-H")):
        courses[key]["renewalCourseSlug"] = courses[renewal]["slug"]

    lesson_plan = {
        "ONB": [("Wie we zijn en hoe we werken", "video", 15, "Je kent de afdelingen en je eerste aanspreekpunten."),
                ("Veilig beginnen op locatie en in de werkplaats", "text", 20, "Je weet welke veiligheidsregels vanaf dag een gelden."),
                ("Afsluitende vragen", "quiz", 10, "Je laat zien dat je de belangrijkste afspraken kent.")],
        "GED": [("De gedragscode in tien minuten", "video", 12, "Je kent de vijf kernregels van de gedragscode."),
                ("Dilemma's uit de praktijk", "quiz", 15, "Je kiest in een dilemma de juiste vervolgstap.")],
        "IBV": [("Phishing herkennen", "video", 15, "Je herkent de kenmerken van een phishingbericht."),
                ("Wachtwoorden, MFA en je telefoon", "text", 15, "Je beveiligt je accounts met MFA en een wachtwoordmanager."),
                ("Eindtoets informatiebeveiliging", "quiz", 10, "Je meldt een incident op de juiste manier.")],
        "AVG": [("Wat zijn persoonsgegevens?", "text", 15, "Je herkent persoonsgegevens en bijzondere persoonsgegevens."),
                ("Klantgegevens in de praktijk", "video", 15, "Je deelt klantgegevens alleen met wie ze nodig heeft."),
                ("Eindtoets AVG", "quiz", 10, "Je herkent een datalek en weet wie je belt.")],
        "EXC": [("Draaitabellen", "video", 30, "Je vat een grote tabel samen met een draaitabel."),
                ("Zoek- en verwijzingsfuncties", "video", 30, "Je koppelt twee tabellen met X.ZOEKEN."),
                ("Grafieken en dashboards", "video", 30, "Je bouwt een dashboard dat zichzelf bijwerkt."),
                ("Eindopdracht", "quiz", 30, "Je maakt een maandrapportage uit ruwe gegevens.")],
        "KLA": [("Voorbereiding op de trainingsdag", "video", 20, "Je brengt twee eigen klantgesprekken mee naar de training.")],
    }
    lessons: dict[str, list[dict]] = {}
    for key, rows in lesson_plan.items():
        c = courses[key]
        lessons[key] = []
        for order, (name, kind, minutes, objective) in enumerate(rows, start=1):
            fields = {"courseId": c["uuid"], "name": name, "order": order, "contentType": kind, "durationMinutes": minutes,
                      "learningObjectives": [objective], "competencyIds": c["competencyIds"], "mandatoryTraining": c["mandatoryTraining"]}
            if "regulationSlug" in c:
                fields["regulationSlug"] = c["regulationSlug"]
            fields["lifecycle"] = "published"
            lessons[key].append(b.add("lesson", fields))

    technician_programme = b.add("programme", {
        "name": "Vakopleiding servicemonteur", "code": "ESD-PRG-SM", "level": "corporate",
        "description": "Wat een servicemonteur moet kunnen en welke cursussen daarbij horen.",
        "courseIds": [courses[k]["uuid"] for k in ("LL-SM", "FGAS", "WP")],
        "requiredCompetencyIds": [comp[c]["uuid"] for c in ("VEI-01", "VEI-03", "VAK-01", "VAK-03")],
        "lifecycle": "published",
    })
    leadership_programme = b.add("programme", {
        "name": "Leiderschapsprogramma", "code": "ESD-PRG-LEI", "level": "corporate",
        "description": "Het programma voor iedereen die een afdeling of ploeg leidt.",
        "courseIds": [courses["LEI"]["uuid"]],
        "requiredCompetencyIds": [comp["LEI-01"]["uuid"], comp["LEI-02"]["uuid"]],
        "lifecycle": "published",
    })
    for key in ("LL-SM", "FGAS", "WP"):
        courses[key]["programmeIds"] = [technician_programme["uuid"]]
    courses["LEI"]["programmeIds"] = [leadership_programme["uuid"]]

    # --- people -------------------------------------------------------------------
    people: list[dict] = []
    members: dict[str, list[dict]] = {}
    used_names: set[str] = set()
    counter = 0

    def person(nc: str, dept: str, roles: list[str], manager: str | None) -> dict:
        female = rng.random() < (0.2 if dept in TECHNICIANS or dept == "magazijn" else 0.55)
        while True:
            surname = rng.choice(SURNAME_HEAD) + rng.choice(SURNAME_TAIL)
            if surname not in used_names or len(used_names) > 240:
                used_names.add(surname)
                break
        p = {"nc": nc, "dept": dept, "roles": roles, "manager": manager, "start": None, "female": female,
             "given": rng.choice(GIVEN_F if female else GIVEN_M), "family": surname,
             "birth": dt.date(rng.randint(1961, 2003), rng.randint(1, 12), rng.randint(1, 28)),
             "ability": rng.gauss(0, 1), "done": {}}
        people.append(p)
        return p

    for key, _name, _path, _site, size, head in DEPARTMENTS:
        group = [person(head, key, ["learner", "manager"], None if head == DIRECTOR else DIRECTOR)]
        for nc, roles in SPECIAL.get(key, []):
            group.append(person(nc, key, roles, head))
        while len(group) < size:
            counter += 1
            group.append(person(f"corporate-medewerker-{counter:03d}", key, ["learner"], head))
        members[key] = group
    for key in [d[0] for d in DEPARTMENTS]:
        starts = [start for dept, start in NEW_HIRES if dept == key]
        if starts:
            plain = [p for p in members[key] if p["roles"] == ["learner"]]
            for p, start in zip(plain[-len(starts):], starts):
                p["start"] = start

    by_nc = {p["nc"]: p for p in people}
    dept_of = {d[0]: d for d in DEPARTMENTS}

    def existing(p: dict) -> bool:
        return p["start"] is None

    def plain_existing(dept: str) -> list[dict]:
        return [p for p in members[dept] if p["roles"] == ["learner"] and existing(p)]

    for p in people:
        fields = {"ncUserId": p["nc"], "givenName": p["given"], "familyName": p["family"], "birthDate": p["birth"].isoformat(),
                  "schoolId": company["uuid"], "eduPersonAffiliation": ["employee"], "roles": p["roles"]}
        if p["manager"] is not None:
            fields["managerId"] = p["manager"]
        fields["department"] = dept_of[p["dept"]][2]
        fields["lifecycle"] = "active"
        p["profile"] = b.add("learner-profile", fields)

    cohorts: dict[str, dict] = {}
    for key, name, _path, site, _size, head in DEPARTMENTS:
        cohorts[key] = b.add("cohort", {
            "name": name, "period": "Opleidingsjaar", "academicYear": YEAR, "teacherIds": [head],
            "learnerIds": [p["nc"] for p in members[key]], "lifecycle": "active", "locationId": sites[site]["uuid"],
            "notes": DEPARTMENT_NOTES.get(key), "kind": "teaching",
        })

    for nc, roles, qualifications, days in [
        (DIRECTOR, ["administrator"], ["algemeen directeur"], WEEKDAYS),
        (HR, ["coordinator"], ["HR-adviseur", "ontwikkelgesprekken en opleidingsbudget"], WEEKDAYS[:4]),
        (COMPLIANCE, ["coordinator"], ["compliance officer", "privacy officer"], WEEKDAYS),
        (COORDINATOR, ["coordinator", "administrator"], ["opleidingscoördinator", "beheer leeromgeving"], ["monday", "tuesday", "thursday", "friday"]),
        (TRAINER_BHV, ["teacher"], ["BHV-instructeur", "toolboxbegeleider"], ["tuesday", "wednesday", "thursday"]),
        (TRAINER_TECH, ["teacher"], ["instructeur NEN 3140", "F-gassen gecertificeerd"], WEEKDAYS),
    ]:
        b.add("staff", {"ncUserId": nc, "roles": roles, "qualifications": qualifications, "workingDays": days})

    template = b.add("learning-plan-template", {
        "name": "Persoonlijk ontwikkelplan (POP)", "kind": "pdp",
        "description": "Het ontwikkelplan dat medewerker en leidinggevende elk jaar samen opstellen en halverwege bespreken.",
        "sections": [
            {"sectionId": "terugblik", "label": "Terugblik op het afgelopen jaar", "order": 1},
            {"sectionId": "ambities", "label": "Ambities", "order": 2},
            {"sectionId": "doelen", "label": "Ontwikkeldoelen", "order": 3, "helpText": "Maak een doel concreet en zet er een datum bij."},
            {"sectionId": "afspraken", "label": "Afspraken over tijd en budget", "order": 4},
        ],
        "goalDomains": ["veiligheid", "vakmanschap", "digitaal werken", "samenwerken", "leidinggeven"],
        "requiredSignerRoles": [], "defaultReviewCadenceMonths": 6, "lifecycle": "active",
    })

    # --- enrolment, e-learning, classroom and credential helpers -------------------
    def enrol(p: dict, key: str, source: str, cohort: dict, due: dt.date | None = None) -> dict:
        c = courses[key]
        fields = {"learnerId": p["nc"], "learnerRef": p["profile"]["uuid"], "courseId": c["uuid"], "mandatory": c["mandatoryTraining"]}
        if due is not None:
            fields["dueDate"] = due.isoformat()
        fields["source"] = source
        if p["manager"] is not None:
            fields["managerId"] = p["manager"]
        if "regulationSlug" in c:
            fields["regulationSlug"] = c["regulationSlug"]
        fields["cohortId"] = cohort["uuid"]
        fields["lifecycle"] = "active"
        e = b.add("enrolment", fields)
        e["_p"], e["_key"], e["_done"] = p, key, None
        return e

    def complete(e: dict, when: dt.datetime) -> None:
        e["lifecycle"] = "completed"
        e["_done"] = when
        e["_p"]["done"][e["_key"]] = when

    def quiz_score(p: dict) -> int:
        return max(60, min(100, round(80 + 9 * p["ability"] + rng.gauss(0, 5))))

    def elearn(e: dict, start: dt.date, finish: dt.date | None, partial: int = 0) -> None:
        """Lesson completions on workdays from `start`; the last one on `finish`.

        With `finish` None the learner stopped after `partial` lessons and the
        enrolment stays active."""
        p, rows = e["_p"], lessons[e["_key"]]
        count = len(rows) if finish is not None else partial
        if count:
            last = finish if finish is not None else pick_day(start, min(plus_workdays(start, 15), LAST_DAY))
            days = sorted(pick_day(start, last) for _ in range(count - 1)) + [last]
            score = quiz_score(p)
            hour, minute = clock()
            previous = None
            e["_times"] = []
            for lesson, day in zip(rows, days):
                when = moment(day, hour, minute)
                if previous is not None and when <= previous:
                    when = previous + dt.timedelta(minutes=rng.choice([20, 25, 35]))
                previous = when
                fields = {"learnerId": p["nc"], "learnerRef": p["profile"]["uuid"], "lessonId": lesson["uuid"],
                          "courseId": lesson["courseId"], "enrolmentId": e["uuid"], "source": "xapi",
                          "verb": VERB_PASSED if lesson["contentType"] == "quiz" else VERB_COMPLETED}
                if lesson["contentType"] == "quiz":
                    fields["score"] = score
                fields["completedAt"] = iso(when)
                b.add("lesson-completion", fields)
                e["_times"].append((lesson, when))
            e["_score"] = score
        e["progressPercent"] = round(100 * count / len(rows))
        if finish is not None:
            complete(e, e["_times"][-1][1])

    def credential(p: dict, key: str, issued: dt.datetime, months: int | None, source: str, issuer: tuple[str, str] = OWN,
                   enrolment: dict | None = None, renewal: dict | None = None) -> dict:
        c = courses[key]
        expires = add_months(issued, months) if months else None
        payload = {"@context": OB3_CONTEXT, "type": ["VerifiableCredential", "OpenBadgeCredential"],
                   "issuer": {"id": issuer[1], "type": ["Profile"], "name": issuer[0]}, "validFrom": iso(issued)}
        if expires is not None:
            payload["validUntil"] = iso(expires)
        payload["credentialSubject"] = {"type": ["AchievementSubject"], "achievement": {"type": ["Achievement"], "name": c["name"]}}
        fields = {"learnerId": p["profile"]["uuid"], "courseId": c["uuid"], "kind": "certificate", "issuedAt": iso(issued)}
        if expires is not None:
            fields["expiresAt"] = iso(expires)
        fields.update({"issuerDid": issuer[1], "signature": UNSIGNED, "openbadges3Payload": payload, "issuedBy": issuer[0], "source": source})
        if "regulationSlug" in c:
            fields["regulationSlug"] = c["regulationSlug"]
        if enrolment is not None:
            fields["enrolmentId"] = enrolment["uuid"]
        if renewal is not None:
            fields["renewalEnrolmentId"] = renewal["uuid"]
        fields["competencyIds"] = c["competencyIds"]
        fields["lifecycle"] = "expired" if expires is not None and expires.date() <= LAST_DAY else "issued"
        cred = b.add("credential", fields)
        cred["_p"], cred["_key"], cred["_expires"] = p, key, expires
        return cred

    def training_group(key: str, name: str, site: str, room: str, trainer: str | None, days: list[dt.date],
                       start: tuple[int, int] = (8, 30), end: tuple[int, int] = (16, 30), notes: str | None = None,
                       lifecycle: str = "completed") -> dict:
        c = courses[key]
        group = b.add("cohort", {
            "name": name, "period": "Opleidingsjaar", "academicYear": YEAR, "courseId": c["uuid"],
            "teacherIds": [trainer] if trainer else [], "learnerIds": [], "lifecycle": lifecycle,
            "locationId": sites[site]["uuid"], "notes": notes, "kind": "teaching",
        })
        group["_sessions"] = []
        group["_marker"] = trainer or COORDINATOR
        for index, day in enumerate(days, start=1):
            title = f"{name}, {day.day} {MAAND[day.month - 1]} {day.year}" if len(days) == 1 else f"{name}, dag {index} ({day.day} {MAAND[day.month - 1]} {day.year})"
            s = b.add("session", {
                "cohortId": group["uuid"], "courseId": c["uuid"], "title": title,
                "startsAt": iso(moment(day, *start)), "endsAt": iso(moment(day, *end)),
                "location": rooms[room]["name"], "roomId": rooms[room]["uuid"], "lifecycle": "completed",
            })
            s["_day"], s["_start"], s["_end"] = day, moment(day, *start), moment(day, *end)
            s["_minutes"] = (end[0] - start[0]) * 60 + end[1] - start[1]
            group["_sessions"].append(s)
        return group

    def attend(group: dict, e: dict, absent: tuple[int, ...] = ()) -> None:
        p = e["_p"]
        if p["nc"] not in group["learnerIds"]:
            group["learnerIds"].append(p["nc"])
        for index, s in enumerate(group["_sessions"]):
            away = index in absent
            fields = {"sessionId": s["uuid"], "learnerId": p["nc"], "learnerRef": p["profile"]["uuid"], "cohortId": group["uuid"],
                      "status": "absent-excused" if away else "present", "minutesAttended": 0 if away else s["_minutes"],
                      "markedBy": group["_marker"], "markedAt": iso(s["_start"] + dt.timedelta(minutes=15))}
            if away:
                fields["reason"] = "Ziek gemeld"
            b.add("attendance-record", fields)

    def first_group(groups: list[dict], day: dt.date) -> dict | None:
        return next((g for g in groups if g["_sessions"][0]["_day"] >= day), None)

    # --- onboarding -----------------------------------------------------------------
    for p in people:
        if not existing(p):
            e = enrol(p, "ONB", "hr", cohorts[p["dept"]], p["start"] + dt.timedelta(days=14))
            elearn(e, p["start"], plus_workdays(p["start"], rng.randint(0, 7)))

    # --- gedragscode: last year's certificates expire, renewal enrolments follow -----
    renewals: list[dict] = []
    olds = [p for p in people if existing(p)]
    shuffled = list(olds)
    rng.shuffle(shuffled)
    campaign_2024 = shuffled[:160]
    campaign_ids = {p["nc"] for p in campaign_2024}
    ged_stuck = rng.sample([p for p in olds if p["roles"] == ["learner"]], 4)
    ged_stuck_ids = {p["nc"] for p in ged_stuck}
    for p in people:
        if existing(p):
            lo, hi = (dt.date(2024, 9, 16), dt.date(2024, 11, 15)) if p["nc"] in campaign_ids else (dt.date(2024, 12, 2), dt.date(2025, 6, 30))
            issued = moment(pick_day(lo, hi), *clock())
            expiry = add_months(issued, 12)
            e = enrol(p, "GED", "credential-renewal", cohorts[p["dept"]])
            credential(p, "GED", issued, 12, "migrated", renewal=e)
            start = on_or_after(expiry.date() + dt.timedelta(days=1))
            if p["nc"] in ged_stuck_ids:
                elearn(e, start, None, partial=1 if ged_stuck.index(p) < 2 else 0)
                continue
            finish = min(plus_workdays(start, rng.choices([1, 3, 6, 10, 15, 30], weights=[20, 30, 20, 15, 10, 5])[0]), LAST_DAY)
        else:
            start = p["start"]
            e = enrol(p, "GED", "hr", cohorts[p["dept"]], start + dt.timedelta(days=30))
            finish = plus_workdays(start, rng.randint(2, 18))
        elearn(e, start, finish)
        credential(p, "GED", e["_done"], 12, "auto", enrolment=e)

    # --- informatiebeveiliging: new this year, bulk enrolment in October ------------
    ibv_campaign = dt.date(2025, 10, 6)
    ibv_due = dt.date(2026, 1, 31)
    candidates = [p for p in olds if p["roles"] == ["learner"] and p["nc"] not in ged_stuck_ids]
    ibv_stuck = rng.sample(candidates, 5)
    ibv_stuck_ids = {p["nc"] for p in ibv_stuck}
    ibv_enrolments = []
    for p in people:
        if existing(p):
            start, due = ibv_campaign, ibv_due
            e = enrol(p, "IBV", "bulk", cohorts[p["dept"]], due)
        else:
            start, due = p["start"], p["start"] + dt.timedelta(days=30)
            e = enrol(p, "IBV", "hr", cohorts[p["dept"]], due)
        ibv_enrolments.append(e)
        if p["nc"] in ibv_stuck_ids:
            elearn(e, start, None, partial=1 if ibv_stuck.index(p) < 3 else 0)
            continue
        if rng.random() < 0.88:
            finish = pick_day(start, min(due, LAST_DAY))
        else:
            finish = pick_day(due + dt.timedelta(days=1), min(due + dt.timedelta(days=60), LAST_DAY))
        elearn(e, start, finish)
        credential(p, "IBV", e["_done"], 12, "auto", enrolment=e)

    # --- AVG for the office departments, campaign in March ------------------------------
    avg_campaign = dt.date(2026, 3, 2)
    office = [p for d in OFFICE for p in members[d]]
    avg_stuck = rng.sample([p for p in office if p["roles"] == ["learner"] and existing(p)], 3)
    avg_stuck_ids = {p["nc"] for p in avg_stuck}
    for p in office:
        if existing(p) or p["start"] <= avg_campaign:
            start, due = avg_campaign, dt.date(2026, 4, 30)
        else:
            start, due = p["start"], p["start"] + dt.timedelta(days=60)
        e = enrol(p, "AVG", "hr", cohorts[p["dept"]], due)
        if p["nc"] in avg_stuck_ids:
            elearn(e, start, None, partial=1)
            continue
        finish = pick_day(start, due) if rng.random() < 0.9 else pick_day(due + dt.timedelta(days=1), LAST_DAY)
        elearn(e, start, min(finish, LAST_DAY))
        credential(p, "AVG", e["_done"], 24, "auto", enrolment=e)

    # --- BHV: last September's certificates expire, herhaling in October and November --
    bhv_team = []
    for dept, n in (("installatie", 8), ("magazijn", 5), ("werkplaats", 3), ("directie", 2), ("financien", 2), ("ict", 1), ("verkoop", 5), ("planning", 3)):
        bhv_team += rng.sample(plain_existing(dept), n)
    haven = [p for p in bhv_team if dept_of[p["dept"]][3] == "haven"]
    hoofd = [p for p in bhv_team if dept_of[p["dept"]][3] == "hoofd"]
    bhv_sets = [(haven[:8], dt.date(2024, 9, 10)), (haven[8:], dt.date(2024, 9, 12)), (hoofd, dt.date(2024, 9, 17))]
    bhv_groups = [
        training_group("BHV-H", "BHV herhaling, groep 1", "haven", "praktijk", TRAINER_BHV, [dt.date(2025, 10, 7)]),
        training_group("BHV-H", "BHV herhaling, groep 2", "haven", "praktijk", TRAINER_BHV, [dt.date(2025, 10, 21)]),
        training_group("BHV-H", "BHV herhaling, groep 3", "hoofd", "training", TRAINER_BHV, [dt.date(2025, 11, 4)]),
    ]
    catch_up = training_group("BHV-H", "BHV herhaling, inhaaldag", "hoofd", "training", TRAINER_BHV, [dt.date(2026, 1, 20)])
    for (team, day), group in zip(bhv_sets, bhv_groups):
        for index, p in enumerate(team):
            issued = moment(day, 16, 30)
            # The long-term ill employee in group 3 misses the day and is not rescheduled this year.
            long_term = group is bhv_groups[2] and index == 0
            rescheduled = (group is bhv_groups[1] and index == 0) or (group is bhv_groups[0] and index == 1)
            e = enrol(p, "BHV-H", "credential-renewal", catch_up if rescheduled else group)
            credential(p, "BHV-H", issued, 12, "migrated", renewal=e)
            if long_term:
                attend(group, e, absent=(0,))
                continue
            if rescheduled:
                attend(group, e, absent=(0,))
                attend(catch_up, e)
                done = catch_up["_sessions"][-1]["_end"]
            else:
                attend(group, e)
                done = group["_sessions"][-1]["_end"]
            complete(e, done)
            credential(p, "BHV-H", done, 12, "manual", enrolment=e)
    bhv_ids = {p["nc"] for p in bhv_team}
    new_bhv = []
    for dept, n in (("installatie", 2), ("magazijn", 1), ("verkoop", 1), ("financien", 1), ("planning", 1)):
        new_bhv += rng.sample([p for p in plain_existing(dept) if p["nc"] not in bhv_ids], n)
    basis = training_group("BHV-B", "BHV basis, februari", "hoofd", "training", TRAINER_BHV, [dt.date(2026, 2, 10), dt.date(2026, 2, 11)])
    for p in new_bhv:
        e = enrol(p, "BHV-B", "hr", basis)
        attend(basis, e)
        complete(e, basis["_sessions"][-1]["_end"])
        credential(p, "BHV-B", e["_done"], 12, "manual", enrolment=e)

    # --- VCA for everyone in Operatie -----------------------------------------------------
    vca_groups = [
        training_group("VCA-B", "VCA Basis, groep 1", "hoofd", "training", None, [dt.date(2025, 10, 14)], end=(17, 0),
                       notes="Externe docent; het examen neemt Voorbeeld Examencentrum Veiligheid af."),
        training_group("VCA-B", "VCA Basis, groep 2", "hoofd", "training", None, [dt.date(2026, 3, 17)], end=(17, 0),
                       notes="Externe docent; het examen neemt Voorbeeld Examencentrum Veiligheid af."),
    ]
    vca_next = training_group("VCA-B", "VCA Basis, groep 3 (september 2026)", "hoofd", "training", None, [],
                              notes="Gepland voor september 2026, voor wie na maart in dienst kwam.", lifecycle="planned")
    vol = [members[d][0] for d in ("installatie", "magazijn", "werkplaats")]
    vol += plain_existing("installatie")[:6] + plain_existing("magazijn")[:1] + [by_nc[TRAINER_BHV], by_nc[TRAINER_TECH]]
    vol_ids = {p["nc"] for p in vol}
    vca_renew = plain_existing("installatie")[6:9] + plain_existing("magazijn")[1:2] + plain_existing("planning")[:1]
    vca_renew_ids = {p["nc"] for p in vca_renew}
    vca_failed_once = next(p for p in people if p["dept"] == "installatie" and p["start"] == dt.date(2025, 9, 1))
    for dept in OPERATIONS:
        for p in members[dept]:
            if existing(p):
                key = "VCA-V" if p["nc"] in vol_ids else "VCA-B"
                if p["nc"] in vca_renew_ids:
                    issued = moment(pick_day(dt.date(2015, 9, 1), dt.date(2016, 2, 26)), 16, 45)
                    expiry = add_months(issued, 120)
                    group = first_group(vca_groups, expiry.date() + dt.timedelta(days=1))
                    e = enrol(p, "VCA-B", "credential-renewal", group)
                    credential(p, key, issued, 120, "migrated", EXAM_SAFETY, renewal=e)
                    attend(group, e)
                    complete(e, group["_sessions"][-1]["_end"])
                    credential(p, "VCA-B", e["_done"], 120, "manual", EXAM_SAFETY, enrolment=e)
                else:
                    issued = moment(pick_day(dt.date(2016, 8, 1), dt.date(2025, 6, 30)), 16, 45)
                    credential(p, key, issued, 120, "migrated", EXAM_SAFETY)
                continue
            group = first_group(vca_groups, p["start"] + dt.timedelta(days=7))
            if group is None:
                e = enrol(p, "VCA-B", "hr", vca_next, p["start"] + dt.timedelta(days=90))
                vca_next["learnerIds"].append(p["nc"])
                continue
            if p is vca_failed_once:
                failed = enrol(p, "VCA-B", "hr", group, p["start"] + dt.timedelta(days=90))
                attend(group, failed)
                failed["lifecycle"] = "failed"
                failed["reason"] = "Examen niet gehaald; herkansing in groep 2."
                group = vca_groups[1]
            e = enrol(p, "VCA-B", "hr", group, p["start"] + dt.timedelta(days=90) if p is not vca_failed_once else dt.date(2026, 3, 31))
            attend(group, e)
            complete(e, group["_sessions"][-1]["_end"])
            credential(p, "VCA-B", e["_done"], 120, "manual", EXAM_SAFETY, enrolment=e)

    # --- NEN 3140 for the technicians -------------------------------------------------------
    nen_groups = [training_group("NEN-H", f"NEN 3140 herinstructie, groep {i}", "haven", "praktijk", TRAINER_TECH, [day], end=(12, 30))
                  for i, day in enumerate([dt.date(2025, 10, 2), dt.date(2026, 1, 15), dt.date(2026, 4, 9), dt.date(2026, 6, 11)], start=1)]
    nen_basis = [training_group("NEN-B", f"NEN 3140 basis, groep {i}", "haven", "praktijk", TRAINER_TECH, [day])
                 for i, day in enumerate([dt.date(2025, 11, 20), dt.date(2026, 5, 21)], start=1)]
    for dept in TECHNICIANS:
        for p in members[dept]:
            if existing(p):
                day = dt.date(2025, 3, 3) if p["nc"] == TRAINER_TECH else pick_day(dt.date(2022, 8, 18), dt.date(2025, 7, 10))
                issued = moment(day, 12, 30)
                expiry = add_months(issued, 36)
                if expiry.date() > LAST_DAY:
                    credential(p, "NEN-B", issued, 36, "migrated")
                    continue
                group = first_group(nen_groups, expiry.date() + dt.timedelta(days=1))
                e = enrol(p, "NEN-H", "credential-renewal", group or cohorts[dept])
                credential(p, "NEN-B", issued, 36, "migrated", renewal=e)
                if group is None:
                    continue
                attend(group, e)
                complete(e, group["_sessions"][-1]["_end"])
                credential(p, "NEN-H", e["_done"], 36, "manual", enrolment=e)
                continue
            group = first_group(nen_basis, p["start"] + dt.timedelta(days=7))
            e = enrol(p, "NEN-B", "hr", group or cohorts[dept], p["start"] + dt.timedelta(days=90))
            if group is None:
                continue
            attend(group, e)
            complete(e, group["_sessions"][-1]["_end"])
            credential(p, "NEN-B", e["_done"], 36, "manual", enrolment=e)

    # --- heftruck for the warehouse -----------------------------------------------------------
    forklift_renew = [training_group("HEF-H", f"Heftruck herhaling, groep {i}", "haven", "kantine", None, [day], end=(12, 30),
                                     notes="Externe instructeur van Voorbeeld Heftruckopleidingen.")
                      for i, day in enumerate([dt.date(2026, 1, 22), dt.date(2026, 6, 4)], start=1)]
    forklift_basis = [training_group("HEF-B", f"Heftruckcertificaat basis, groep {i}", "haven", "kantine", None, [day],
                                     notes="Externe instructeur van Voorbeeld Heftruckopleidingen.")
                      for i, day in enumerate([dt.date(2025, 10, 23), dt.date(2026, 5, 28)], start=1)]
    for p in members["magazijn"]:
        if existing(p):
            issued = moment(pick_day(dt.date(2020, 8, 18), dt.date(2025, 7, 10)), 16, 0)
            expiry = add_months(issued, 60)
            if expiry.date() > LAST_DAY:
                credential(p, "HEF-B", issued, 60, "migrated", FORKLIFT_SCHOOL)
                continue
            group = first_group(forklift_renew, expiry.date() + dt.timedelta(days=1))
            e = enrol(p, "HEF-H", "credential-renewal", group or cohorts["magazijn"])
            credential(p, "HEF-B", issued, 60, "migrated", FORKLIFT_SCHOOL, renewal=e)
            if group is None:
                continue
            attend(group, e)
            complete(e, group["_sessions"][-1]["_end"])
            credential(p, "HEF-H", e["_done"], 60, "manual", FORKLIFT_SCHOOL, enrolment=e)
            continue
        group = first_group(forklift_basis, p["start"] + dt.timedelta(days=7))
        e = enrol(p, "HEF-B", "hr", group, p["start"] + dt.timedelta(days=60))
        attend(group, e)
        complete(e, group["_sessions"][-1]["_end"])
        credential(p, "HEF-B", e["_done"], 60, "manual", FORKLIFT_SCHOOL, enrolment=e)

    # --- F-gassen, warmtepompen, and the technician leerlijn ------------------------------------
    installers = plain_existing("installatie")
    fgas_holders = rng.sample(installers[9:], 14)
    fgas_ids = {p["nc"] for p in fgas_holders}
    for p in fgas_holders:
        credential(p, "FGAS", moment(pick_day(dt.date(2021, 9, 1), dt.date(2025, 6, 30)), 16, 0), 60, "migrated", EXAM_COOLING)
    fgas_new = rng.sample([p for p in installers if p["nc"] not in fgas_ids], 6)
    fgas_group = training_group("FGAS", "F-gassen certificering, november", "haven", "praktijk", None,
                                [dt.date(2025, 11, 25), dt.date(2025, 11, 26)], notes="Externe docent en examen door Voorbeeld Examencentrum Koudetechniek.")
    for p in fgas_new:
        e = enrol(p, "FGAS", "manager", fgas_group)
        attend(fgas_group, e)
        complete(e, fgas_group["_sessions"][-1]["_end"])
        credential(p, "FGAS", e["_done"], 60, "manual", EXAM_COOLING, enrolment=e)
    heat_pump = rng.sample(installers, 12)
    wp_groups = [
        training_group("WP", "Praktijkcursus warmtepompen, januari", "haven", "praktijk", None, [dt.date(2026, 1, 27), dt.date(2026, 1, 28)],
                       notes="Externe cursus van Voorbeeld Warmtepompacademie, in onze eigen praktijkhal."),
        training_group("WP", "Praktijkcursus warmtepompen, april", "haven", "praktijk", None, [dt.date(2026, 4, 14), dt.date(2026, 4, 15)],
                       notes="Externe cursus van Voorbeeld Warmtepompacademie, in onze eigen praktijkhal."),
    ]
    paid: list[tuple[dict, str, dt.date]] = []  # (enrolment, fee key, order day)
    for index, p in enumerate(heat_pump):
        group = wp_groups[0] if index < 6 else wp_groups[1]
        e = enrol(p, "WP", "manager", group)
        attend(group, e)
        complete(e, group["_sessions"][-1]["_end"])
        credential(p, "WP", e["_done"], None, "manual", HEAT_PUMP_ACADEMY, enrolment=e)
        paid.append((e, "WP", dt.date(2026, 1, 5) if index < 6 else dt.date(2026, 3, 23)))
    for p in members["installatie"]:
        enrol(p, "LL-SM", "manager", cohorts["installatie"])

    # --- leadership, customer contact, paid courses ----------------------------------------------
    leaders = [members[d][0] for d in [d[0] for d in DEPARTMENTS] if d != "directie"]
    lei = training_group("LEI", "Coachend leidinggeven", "hoofd", "vergader", None,
                         [dt.date(2025, 10, 9), dt.date(2025, 12, 4), dt.date(2026, 2, 5)], start=(13, 0), end=(17, 0),
                         notes="Drie middagen met een externe coach.")
    for p in leaders:
        e = enrol(p, "LEI", "hr", lei)
        attend(lei, e, absent=(1,) if p["nc"] == "corporate-manager-03" else ())
        complete(e, lei["_sessions"][-1]["_end"])

    sales = [p for p in members["verkoop"] if p["roles"] == ["learner"] and (existing(p) or p["start"] <= dt.date(2026, 1, 12))]
    kla_groups = [
        training_group("KLA", "Klantgericht communiceren, groep 1", "hoofd", "training", None, [dt.date(2025, 11, 13)], start=(9, 0), end=(16, 0)),
        training_group("KLA", "Klantgericht communiceren, groep 2", "hoofd", "training", None, [dt.date(2026, 1, 22)], start=(9, 0), end=(16, 0)),
    ]
    for index, p in enumerate(sales):
        group = kla_groups[0] if index < len(sales) // 2 and existing(p) else kla_groups[1]
        e = enrol(p, "KLA", "manager", group)
        day = group["_sessions"][0]["_day"]
        prep = pick_day(day - dt.timedelta(days=10), day - dt.timedelta(days=1))
        when = moment(prep, *clock())
        b.add("lesson-completion", {"learnerId": p["nc"], "learnerRef": p["profile"]["uuid"], "lessonId": lessons["KLA"][0]["uuid"],
                                    "courseId": courses["KLA"]["uuid"], "enrolmentId": e["uuid"], "source": "xapi",
                                    "verb": VERB_COMPLETED, "completedAt": iso(when)})
        e["progressPercent"] = 100
        attend(group, e)
        complete(e, group["_sessions"][-1]["_end"])

    spreadsheet = plain_existing("financien")[:4] + plain_existing("planning")[-2:] + plain_existing("ict")[:1] + plain_existing("verkoop")[-1:]
    order_days = [dt.date(2025, 10, 6), dt.date(2025, 10, 20), dt.date(2025, 11, 10), dt.date(2025, 12, 1),
                  dt.date(2026, 1, 19), dt.date(2026, 2, 9), dt.date(2026, 3, 9), dt.date(2026, 6, 29)]
    for index, (p, day) in enumerate(zip(spreadsheet, order_days)):
        e = enrol(p, "EXC", "self", cohorts[p["dept"]])
        paid.append((e, "EXC", day))
        if index == 7:
            e["lifecycle"] = "pending"
            e["progressPercent"] = 0
            continue
        start = plus_workdays(day, 2)
        if index == 5:
            elearn(e, start, None, partial=2)
        else:
            elearn(e, start, pick_day(plus_workdays(start, 10), plus_workdays(start, 40)))

    project = plain_existing("planning")[1:4] + plain_existing("ict")[-2:]
    prj = training_group("PRJ", "Projectmatig werken, maart", "hoofd", "vergader", None,
                         [dt.date(2026, 3, 3), dt.date(2026, 3, 10), dt.date(2026, 3, 17)], start=(9, 0), end=(16, 0),
                         notes="Externe trainer en examen van Voorbeeld Projectacademie.")
    for index, p in enumerate(project):
        e = enrol(p, "PRJ", "manager", prj)
        paid.append((e, "PRJ", dt.date(2026, 2, 9)))
        if index == 4:
            e["lifecycle"] = "withdrawn"
            e["reason"] = "Afgemeld wegens projectdrukte; volgend jaar opnieuw."
            continue
        attend(prj, e)
        complete(e, prj["_sessions"][-1]["_end"])
        credential(p, "PRJ", e["_done"], None, "manual", PROJECT_ACADEMY, enrolment=e)

    # --- toolbox meetings: sessions every month, marks only for exceptions -----------------------
    for dept, room, begin, finish in TOOLBOX:
        head = members[dept][0]
        for month_index, (year, month) in enumerate([(2025, 9), (2025, 10), (2025, 11), (2025, 12), (2026, 1),
                                                     (2026, 2), (2026, 3), (2026, 4), (2026, 5), (2026, 6)]):
            day = first_tuesday(year, month)
            s = b.add("session", {
                "cohortId": cohorts[dept]["uuid"], "title": f"Toolboxmeeting {dept_of[dept][1].lower()}, {MAAND[month - 1]} {year}: {TOOLBOX_TOPICS[month_index]}",
                "startsAt": iso(moment(day, *begin)), "endsAt": iso(moment(day, *finish)),
                "location": rooms[room]["name"], "roomId": rooms[room]["uuid"], "lifecycle": "completed",
            })
            for p in members[dept][1:]:
                if not existing(p) and p["start"] > day:
                    continue
                roll = rng.random()
                if roll < 0.07:
                    status, minutes, reason = "absent-excused", 0, rng.choice(["Verlof", "Ziek gemeld", "Vrije dag"])
                elif roll < 0.08:
                    status, minutes, reason = "absent-unexcused", 0, "Niet afgemeld"
                elif roll < 0.11:
                    late = rng.choice([5, 10])
                    status, minutes, reason = "late", 30 - late, "Te laat door file"
                else:
                    continue
                b.add("attendance-record", {
                    "sessionId": s["uuid"], "learnerId": p["nc"], "learnerRef": p["profile"]["uuid"], "cohortId": cohorts[dept]["uuid"],
                    "status": status, "minutesAttended": minutes, "markedBy": head["nc"], "markedAt": iso(moment(day, *finish) + dt.timedelta(minutes=5)), "reason": reason,
                })

    # --- external training records (the promoted NIS2 seed row first) ----------------------------
    def external(p: dict, title: str, provider: str, kind: str, day: dt.date, submitted_by: str, verified_by: str | None,
                 evidence: str | None, regulation: str | None = None, valid_months: int | None = None, batch: str | None = None,
                 lifecycle: str = "verified", rejection: str | None = None) -> None:
        completed = moment(day, 17, 0)
        fields = {"learnerId": p["profile"]["uuid"], "learnerRef": p["profile"]["uuid"], "title": title, "provider": provider, "kind": kind}
        if regulation is not None:
            fields["regulationSlug"] = regulation
        fields["completedAt"] = iso(completed)
        if valid_months is not None:
            fields["validUntil"] = iso(add_months(completed, valid_months))
        if evidence is not None:
            fields["evidenceNote"] = evidence
        fields["submittedBy"] = submitted_by
        if lifecycle == "verified" and verified_by is not None:
            fields["verifiedBy"] = verified_by
            fields["verifiedAt"] = iso(moment(plus_workdays(day, 1), 9, 0))
        if rejection is not None:
            fields["rejectionReason"] = rejection
        if batch is not None:
            fields["batchId"] = batch
        fields["lifecycle"] = lifecycle
        b.add("external-training-record", fields)

    board = [by_nc[DIRECTOR]] + leaders + [by_nc[COMPLIANCE]]
    for p in board:
        external(p, "NIS2 board awareness session", "Voorbeeld SecureBoard B.V.", "classroom", dt.date(2026, 3, 10), COMPLIANCE, COMPLIANCE,
                 "Getekende presentielijst bijgevoegd", "NIS2", 12, "nis2-bestuur-2026-03")
    fair = [members["installatie"][0]] + rng.sample(installers, 5)
    for p in fair:
        external(p, "Vakbeurs installatietechniek", "Voorbeeld Beursorganisatie", "conference", dt.date(2025, 9, 25), members["installatie"][0]["nc"],
                 COORDINATOR, "Toegangsbewijs en bezoekbevestiging", batch="vakbeurs-2025")
    for nc in (HR, COORDINATOR):
        external(by_nc[nc], "Congres duurzame inzetbaarheid", "Voorbeeld Congresbureau", "conference", dt.date(2025, 11, 20), nc, DIRECTOR,
                 "Deelnamebevestiging van de organisator")
    external(by_nc[COMPLIANCE], "Webinar AVG-actualiteiten voor privacy officers", "Voorbeeld Privacyacademie", "external-elearning",
             dt.date(2026, 1, 28), COMPLIANCE, HR, "Certificaat van deelname", "AVG", 24)
    for p in plain_existing("ict")[1:3]:
        external(p, "Praktijkcursus cloudbeheer", "Voorbeeld IT-opleidingen", "classroom", dt.date(2026, 2, 12), members["ict"][0]["nc"],
                 COORDINATOR, "Certificaat van deelname", batch="cloudbeheer-2026-02")
    for p in rng.sample(installers, 6):
        external(p, "Rijvaardigheidstraining bestelbus", "Voorbeeld Rijopleidingen Esdoornhaven", "other", dt.date(2026, 5, 12),
                 COORDINATOR, COORDINATOR, "Presentielijst van de rijschool", batch="rijvaardigheid-2026-05")
    for p in heat_pump[:3]:
        external(p, "Meeloopdag bij een warmtepompfabrikant", "Voorbeeld Warmtepompfabriek", "on-the-job", dt.date(2026, 3, 19),
                 members["installatie"][0]["nc"], COORDINATOR, "Verslag van de meeloopdag", batch="meeloopdag-2026-03")
    rejected = plain_existing("verkoop")[2]
    external(rejected, "Online cursus timemanagement", "Voorbeeld Online Leerplatform", "external-elearning", dt.date(2026, 2, 26),
             rejected["nc"], None, None, lifecycle="rejected", rejection="Er is geen certificaat of deelnamebewijs aangeleverd.")
    waiting = plain_existing("financien")[5]
    external(waiting, "Workshop werken met macro's", "Voorbeeld Rekenacademie", "external-elearning", dt.date(2026, 6, 25),
             waiting["nc"], None, "Deelnamebewijs volgt per mail", lifecycle="submitted")

    # --- competency attainments: what the skills gap dashboard reads ---------------------------------
    def valid_at_year_end(p: dict, keys: tuple[str, ...]) -> dict | None:
        for cred in b.buckets["credential"]:
            if cred["_p"] is p and cred["_key"] in keys and (cred["_expires"] is None or cred["_expires"].date() > LAST_DAY):
                return cred
        return None

    def attain(p: dict, code: str, level: str, when: dt.datetime) -> None:
        b.add("competency-attainment", {"learnerId": p["nc"], "learnerRef": p["profile"]["uuid"], "competencyId": comp[code]["uuid"],
                                        "frameworkId": framework["uuid"], "proficiencyLevelId": level, "gradeEntryIds": [],
                                        "assessmentResultIds": [], "werkprocesAssessmentIds": [], "submissionIds": [],
                                        "lastRecomputedAt": iso(when)})

    def level_for(score: int) -> str:
        return "expert" if score >= 85 else "gevorderd" if score >= 65 else "basis"

    review = moment(dt.date(2025, 9, 15), 10, 0)
    for e in ibv_enrolments:
        if e["lifecycle"] == "completed":
            attain(e["_p"], "DIG-01", level_for(e["_score"]), e["_done"])
    for e in b.buckets["enrolment"]:
        if e["_key"] == "AVG" and e["lifecycle"] == "completed":
            attain(e["_p"], "DIG-02", level_for(e["_score"]), e["_done"])
    for dept in TECHNICIANS:
        for p in members[dept]:
            vca = valid_at_year_end(p, ("VCA-B", "VCA-V"))
            if vca is not None:
                attain(p, "VEI-01", "gevorderd" if vca["_key"] == "VCA-V" else "basis", dt.datetime.fromisoformat(vca["issuedAt"]))
            nen = valid_at_year_end(p, ("NEN-B", "NEN-H"))
            if nen is not None:
                attain(p, "VEI-03", "expert" if p["nc"] == TRAINER_TECH else "basis", dt.datetime.fromisoformat(nen["issuedAt"]))
            if "WP" in p["done"]:
                attain(p, "VAK-01", "gevorderd", p["done"]["WP"])
            elif existing(p) and dept == "installatie" and rng.random() < 0.45:
                attain(p, "VAK-01", rng.choice(["gevorderd", "expert"]), review)
            fgas = valid_at_year_end(p, ("FGAS",))
            if fgas is not None:
                attain(p, "VAK-02", "gevorderd", dt.datetime.fromisoformat(fgas["issuedAt"]))
            if existing(p) and rng.random() < 0.8:
                attain(p, "VAK-03", rng.choice(["basis", "gevorderd", "gevorderd", "expert"]), review)
    for index, p in enumerate(leaders):
        attain(p, "LEI-01", "gevorderd" if index % 3 == 0 else "basis", p["done"]["LEI"])
        if index < 4:
            attain(p, "LEI-02", "basis", p["done"]["LEI"])

    # --- personal development plans and their mid-year review --------------------------------------
    attained = {}
    for row in b.buckets["competency-attainment"]:
        attained.setdefault(row["learnerId"], set()).add(row["competencyId"])
    code_of = {c["uuid"]: code for code, c in comp.items()}
    development = {
        "WP": ("vakmanschap", "Praktijkcursus warmtepompen afronden"),
        "FGAS": ("vakmanschap", "F-gassencertificaat halen"),
        "BHV-B": ("veiligheid", "Opleiding tot bedrijfshulpverlener volgen"),
        "LEI": ("leidinggeven", "Coachend leidinggeven in de praktijk brengen"),
        "KLA": ("samenwerken", "Klantgesprekken voeren volgens de nieuwe aanpak"),
        "EXC": ("digitaal werken", "Excel gevorderd afronden"),
        "PRJ": ("samenwerken", "Cursus projectmatig werken met examen afronden"),
    }
    generic = {
        "directie": ("samenwerken", "Het jaarplan per kwartaal met het team bespreken"),
        "financien": ("vakmanschap", "De maandafsluiting binnen vijf werkdagen rond krijgen"),
        "ict": ("digitaal werken", "Certificering voor cloudbeheer voorbereiden"),
        "verkoop": ("samenwerken", "Offertes binnen twee werkdagen opvolgen"),
        "planning": ("vakmanschap", "De planningssoftware volledig benutten"),
        "installatie": ("vakmanschap", "Storingsanalyse bij hybride warmtepompen oefenen"),
        "magazijn": ("vakmanschap", "Cyclisch tellen zelfstandig uitvoeren"),
        "werkplaats": ("vakmanschap", "Prefab-werk voor de monteurs verder standaardiseren"),
    }
    enrolments_of: dict[str, list[dict]] = {}
    for e in b.buckets["enrolment"]:
        enrolments_of.setdefault(e["learnerId"], []).append(e)
    for p in people:
        coordinator = p["manager"] or HR
        created = p["start"] or dt.date(2025, 9, 29)
        goals, measures, outcomes_by_goal = [], [], []
        for e in enrolments_of[p["nc"]]:
            if e["_key"] not in development:
                continue
            domain, text = development[e["_key"]]
            status = "met" if e["lifecycle"] == "completed" else "dropped" if e["lifecycle"] == "withdrawn" else "open"
            goal_id = f"doel-{len(goals) + 1}"
            goals.append({"goalId": goal_id, "description": text, "domain": domain, "targetDate": "2026-06-30", "status": status})
            measures.append({"measureId": f"maatregel-{len(measures) + 1}", "description": f"Tijd en opleidingsbudget voor {courses[e['_key']]['name']}",
                             "responsibleId": coordinator, "startDate": created.isoformat(), "endDate": "2026-06-30"})
            outcomes_by_goal.append((goal_id, e))
        required = set()
        for e in enrolments_of[p["nc"]]:
            for programme in (technician_programme, leadership_programme):
                if programme["uuid"] in courses[e["_key"]].get("programmeIds", []):
                    required |= set(programme["requiredCompetencyIds"])
        required |= {c["uuid"] for c in comp.values() if set(c["requiredForRoles"]) & set(p["roles"])}
        for uuid in sorted(required - attained.get(p["nc"], set()), key=lambda u: code_of[u]):
            goal_id = f"doel-{len(goals) + 1}"
            goals.append({"goalId": goal_id, "description": f"{comp[code_of[uuid]]['title']}: aan de slag in het volgende opleidingsjaar",
                          "domain": {"LEI": "leidinggeven", "DIG": "digitaal werken", "VEI": "veiligheid"}.get(code_of[uuid][:3], "vakmanschap"),
                          "targetDate": "2026-12-31", "status": "open"})
            outcomes_by_goal.append((goal_id, None))
        if not goals:
            domain, text = generic[p["dept"]]
            goals.append({"goalId": "doel-1", "description": text, "domain": domain, "targetDate": "2026-06-30",
                          "status": "met" if rng.random() < 0.6 else "open"})
            outcomes_by_goal.append(("doel-1", None))
        plan = b.add("learning-plan", {
            "learnerId": p["nc"], "kind": "pdp", "templateId": template["uuid"], "cohortId": cohorts[p["dept"]]["uuid"],
            "coordinatorId": coordinator, "goals": goals, "supportMeasures": measures, "period": YEAR,
            "reviewCadenceMonths": 6, "nextReviewAt": "2026-09-30", "version": 1, "lifecycle": "active",
        })
        if not existing(p) and p["start"] > dt.date(2026, 1, 12):
            continue
        if rng.random() > 0.7:
            continue
        evaluated = pick_day(dt.date(2026, 2, 2), dt.date(2026, 3, 27))
        outcomes = []
        for goal_id, e in outcomes_by_goal:
            done = e["_done"] if e is not None else None
            if e is not None and e["lifecycle"] == "withdrawn" and evaluated >= dt.date(2026, 2, 20):
                outcome, note = "dropped", "Afgemeld; volgend jaar opnieuw inplannen."
            elif done is not None and done.date() <= evaluated:
                outcome, note = "met", "Afgerond."
            else:
                outcome, note = "continued", "Loopt door in de tweede helft van het jaar."
            outcomes.append({"goalId": goal_id, "outcome": outcome, "note": note})
        b.add("learning-plan-evaluation", {
            "learningPlanId": plan["uuid"], "evaluatedAt": evaluated.isoformat(), "evaluatedBy": coordinator, "goalOutcomes": outcomes,
            "narrative": rng.choice([
                "Goed gesprek over de voortgang. De afspraken blijven staan.",
                "Tussenstand besproken; de planning voor het voorjaar is concreet gemaakt.",
                "Voortgang is op koers. We kijken in september samen terug op het hele jaar.",
            ]),
            "attendeeIds": [p["nc"], coordinator], "nextReviewAt": "2026-09-30", "lifecycle": "recorded",
        })

    # --- engagement in the informatiebeveiliging e-learning --------------------------------------------
    threshold = b.add("engagement-risk-threshold", {
        "name": "Dertig dagen geen activiteit in een verplichte e-learning", "kind": "low-engagement", "scope": "per-learner",
        "metric": "recency-days-above", "limit": 30,
        "onAtRisk": {"notify": True, "notifyRoles": ["manager", "compliance-officer"], "createFlag": True}, "lifecycle": "active",
    })
    total_minutes = sum(lesson["durationMinutes"] for lesson in lessons["IBV"])
    for e in ibv_enrolments:
        times = e.get("_times", [])
        if not times:
            continue
        spent = round(sum(lesson["durationMinutes"] for lesson, _when in times) * rng.uniform(0.85, 1.35), 1)
        gap = (times[-1][1] - times[-2][1]).days if len(times) > 1 else 0
        recency = 1 - min(1, gap / 14)
        score = max(0, min(100, round(min(1, spent / total_minutes) * 70 + recency * 30)))
        row = b.add("engagement-score", {"learnerId": e["learnerId"], "learnerRef": e["learnerRef"], "courseId": e["courseId"],
                                         "timeOnTaskMinutes": spent, "lastActivityAt": iso(times[-1][1]), "score": score})
        if e["lifecycle"] != "completed":
            flagged = times[-1][1] + dt.timedelta(days=31)
            b.add("engagement-risk-flag", {"learnerId": e["learnerId"], "courseId": e["courseId"], "engagementRiskThresholdId": threshold["uuid"],
                                           "engagementScoreId": row["uuid"], "metricValueAtFlag": 31, "flaggedAt": iso(flagged),
                                           "lifecycle": "open" if e["learnerId"] == ibv_stuck[-3]["nc"] else "in-handling"})

    # --- points, levels, leaderboards -----------------------------------------------------------------
    rule = b.add("point-rule", {"name": "Training afgerond", "kind": "enrolment-completed", "points": 50, "active": True, "lifecycle": "active"})
    b.add("point-rule", {"name": "Zeven dagen achter elkaar leren", "kind": "streak-milestone", "points": 25, "milestoneDays": 7,
                         "active": False, "lifecycle": "draft"})
    levels = [b.add("engagement-level", {"name": name, "order": order, "minPoints": points, "icon": icon})
              for order, (name, points, icon) in enumerate([("Starter", 0, "FlagOutline"), ("Op weg", 100, "StarOutline"),
                                                           ("Gevorderd", 150, "MedalOutline"), ("Kampioen", 250, "TrophyOutline")], start=1)]
    awards: dict[str, list[dt.datetime]] = {}
    for e in b.buckets["enrolment"]:
        if e["lifecycle"] == "completed":
            b.add("point-award", {"learnerId": e["learnerId"], "pointRuleId": rule["uuid"], "points": 50, "sourceKind": "enrolment",
                                  "sourceObjectId": e["uuid"], "awardedAt": iso(e["_done"])})
            awards.setdefault(e["learnerId"], []).append(e["_done"])
    for p in people:
        moments = sorted(awards.get(p["nc"], []))
        total = 50 * len(moments)
        level = [lv for lv in levels if lv["minPoints"] <= total][-1]
        fields = {"learnerId": p["nc"], "totalPoints": total, "levelId": level["uuid"], "currentStreakDays": 0}
        dates = sorted({m.date() for m in moments})
        longest, run = (1 if dates else 0), 1
        for before, after in zip(dates, dates[1:]):
            run = run + 1 if (after - before).days == 1 else 1
            longest = max(longest, run)
        fields["longestStreakDays"] = longest
        if moments:
            fields["lastActivityDate"] = moments[-1].date().isoformat()
            fields["lastRecomputedAt"] = iso(moments[-1])
        b.add("learner-engagement", fields)
    b.add("leaderboard", {"name": "Leerkampioenen Esdoorn Techniek", "topN": 10, "lifecycle": "active"})
    b.add("leaderboard", {"name": "Leerkampioenen installatie en service", "cohortId": cohorts["installatie"]["uuid"], "topN": 5, "lifecycle": "active"})
    b.add("leaderboard", {"name": "Leerkampioenen verkoop en klantenservice", "cohortId": cohorts["verkoop"]["uuid"], "topN": 5, "lifecycle": "active"})

    # --- paid courses: fee items, orders, payments, entitlements ---------------------------------------
    fees = {
        "WP": b.add("fee-item", {"name": "Praktijkcursus warmtepompen", "description": "Deelname aan de tweedaagse externe praktijkcursus.",
                                 "kind": "course-enrolment", "amount": 895.0, "currency": "EUR", "voluntary": False, "taxPosture": "standard-rate",
                                 "linkedCourseId": courses["WP"]["uuid"], "academicYear": YEAR, "validFrom": FIRST_DAY.isoformat(),
                                 "validUntil": LAST_DAY.isoformat(), "lifecycle": "active"}),
        "EXC": b.add("fee-item", {"name": "Licentie e-learning Excel gevorderd", "description": "Een jaar toegang tot de e-learning.",
                                  "kind": "course-enrolment", "amount": 149.0, "currency": "EUR", "voluntary": False, "taxPosture": "standard-rate",
                                  "linkedCourseId": courses["EXC"]["uuid"], "academicYear": YEAR, "validFrom": FIRST_DAY.isoformat(),
                                  "validUntil": LAST_DAY.isoformat(), "lifecycle": "active"}),
        "PRJ": b.add("fee-item", {"name": "Projectmatig werken, cursus met examen", "description": "Drie cursusdagen en het examen.",
                                  "kind": "course-enrolment", "amount": 695.0, "currency": "EUR", "voluntary": False, "taxPosture": "standard-rate",
                                  "linkedCourseId": courses["PRJ"]["uuid"], "academicYear": YEAR, "validFrom": FIRST_DAY.isoformat(),
                                  "validUntil": LAST_DAY.isoformat(), "lifecycle": "active"}),
    }
    payment = 0
    for index, (e, key, day) in enumerate(paid):
        fee, p = fees[key], e["_p"]
        state = "cancelled" if e["lifecycle"] == "withdrawn" else "open" if e["lifecycle"] == "pending" else "paid"
        order = b.add("order", {"payerKind": "employer", "payerName": PAYER, "payerEmail": PAYER_EMAIL, "learnerId": p["nc"],
                                "learnerRef": p["profile"]["uuid"], "totalAmount": fee["amount"], "currency": "EUR",
                                "dueDate": (day + dt.timedelta(days=30)).isoformat(), "notes": f"Kostenplaats {dept_of[p['dept']][1]}",
                                "lifecycle": state, "paymentRequestSentAt": iso(moment(day, 10, 0)), "paymentRequestSentBy": COORDINATOR})
        line = b.add("order-line", {"orderId": order["uuid"], "feeItemId": fee["uuid"], "description": fee["name"], "quantity": 1,
                                    "unitAmount": fee["amount"], "lineTotal": fee["amount"]})
        if state != "paid":
            if state == "open":
                b.add("entitlement", {"feeItemId": fee["uuid"], "orderLineId": line["uuid"], "learnerId": p["nc"],
                                      "grantedResourceKind": "course-access", "grantedResourceId": courses[key]["uuid"], "lifecycle": "pending"})
            continue
        started = moment(plus_workdays(day, 1), 11, 0)
        if index == 2:
            payment += 1
            b.add("payment-transaction", {"orderId": order["uuid"], "pspProvider": "mollie", "pspPaymentId": f"tr_VOORBEELD{payment:04d}",
                                          "amount": fee["amount"], "currency": "EUR", "initiatedBy": COORDINATOR,
                                          "initiatedAt": iso(started - dt.timedelta(hours=1)), "lifecycle": "failed"})
        payment += 1
        paid_at = started + dt.timedelta(minutes=4)
        b.add("payment-transaction", {"orderId": order["uuid"], "pspProvider": "mollie", "pspPaymentId": f"tr_VOORBEELD{payment:04d}",
                                      "amount": fee["amount"], "currency": "EUR", "initiatedBy": COORDINATOR, "initiatedAt": iso(started),
                                      "completedAt": iso(paid_at), "lifecycle": "succeeded"})
        b.add("entitlement", {"feeItemId": fee["uuid"], "orderLineId": line["uuid"], "learnerId": p["nc"], "grantedResourceKind": "course-access",
                              "grantedResourceId": courses[key]["uuid"], "grantedAt": iso(paid_at), "lifecycle": "active"})

    # --- assemble -------------------------------------------------------------------------------------
    for rows in b.buckets.values():
        for row in rows:
            for key in [k for k in row if k.startswith("_")]:
                del row[key]
    objects = {name: rows for name, rows in b.buckets.items() if rows}
    total = sum(len(rows) for rows in objects.values())
    return {
        "openapi": "3.0.0",
        "info": {
            "title": "Learniq example set: Company",
            "version": "1.0.0",
            "description": "Voorbeeldbedrijf Esdoorn Techniek B.V., a fictional installation and service company in the fictional town of Esdoornhaven, through the 2025-2026 training year.",
        },
        "x-openregister": {
            "type": "profile",
            "app": "learniq",
            "profile": {
                "id": SET,
                "segment": SET,
                "label": "Company",
                "description": "A fictional company with two sites, employees, compliance training, certificates and one full training year.",
                "order": 5,
                "objectCount": total,
                "icon": "OfficeBuilding",
            },
            "description": (
                "An example set an operator picks in the first-time setup wizard (ADR-042, decision D21). NEVER imported on install. "
                "Every object carries @self.configuration/register/schema and a fixed uuid in the ee05 namespace, so the import resolves "
                "the live learniq register without this descriptor declaring components.registers (which would re-point the register at "
                "this profile config id and overwrite its authorization block), a second load adds nothing, and occ "
                "learniq:example-set:remove corporate removes exactly these objects. Generated by scripts/example-sets/corporate.py; the "
                "contract is openspec/changes/segment-wizard-choice/contract.md. Every person, address, company and certificate in it is fictional."
            ),
            "seedData": {
                "description": (
                    "One company with a head office and a workshop and warehouse site, eight departments as cohorts, 200 employees with "
                    "their managers, HR, compliance and training staff, compliance e-learning and classroom certification with the "
                    "credentials they issue and the renewals their expiry opens, toolbox meetings with their attendance, external training "
                    "records, a competency framework with the gaps it shows, a development plan per employee, engagement scores, points "
                    "and leaderboards, and orders with entitlements for three paid courses."
                ),
                "objects": objects,
            },
        },
        "paths": {},
        "components": {},
    }


def render(data: dict) -> str:
    """Pretty JSON with one compact object per line in each seed bucket (see po.py)."""
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
            print(f"{OUT} is out of date; run python3 scripts/example-sets/corporate.py", file=sys.stderr)
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
