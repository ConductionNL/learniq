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
employee, engagement scores, points and leaderboards, and entitlements for the
three paid courses. The company pays those through shillinq (D19,
payments-to-shillinq-migration), so the set carries no orders or payments of
its own: a paid enrolment has an active entitlement with the day the payment
settled, an unpaid one a pending entitlement, a cancelled one none.

WHY A SCRIPT. The set is several thousand objects that must agree with each
other: a renewal enrolment starts on the day the old certificate expires, the
new certificate is issued at the end of the session the employee attended, a
skills gap is exactly a required competency without an attainment, and a
leaderboard total is exactly the sum of that employee's point awards.
Hand-editing that is how sets drift. The script is deterministic (fixed seed),
so running it again produces the same file byte for byte, and a reviewer reads
the rules here rather than megabytes of JSON.

THE CONTRACT. openspec/changes/archive/2026-09-28-segment-wizard-choice/contract.md. Every rule is
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

import calendar
import datetime as dt
import random
import sys
from dataclasses import dataclass
from zoneinfo import ZoneInfo

from example_set import Builder, Script, SetIdentity, main

AMS = ZoneInfo("Europe/Amsterdam")
SET = "corporate"
SET_NUMBER = "05"
YEAR = "2025-2026"
FIRST_DAY = dt.date(2025, 8, 18)
LAST_DAY = dt.date(2026, 7, 10)

COMPANY = "Voorbeeldbedrijf Esdoorn Techniek B.V."
TOWN = "Esdoornhaven"
COMPANY_DID = "did:web:esdoorn-techniek.example"
UNSIGNED = "voorbeeldgegevens-niet-ondertekend"
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
    "entitlement",
    # Appended, not inserted: a uuid encodes its schema's position in this list, so
    # inserting a schema would move every later uuid and a re-load would duplicate
    # the set on an install that already has it (example-set-regulation-rows).
    "regulation",
]
IDENTITY = SetIdentity(SET, SET_NUMBER, SCHEMAS)

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

# code, title, description, parent code, order, and the roles that require it if any.
COMPETENCIES: list[tuple] = [
    ("VEI", "Veilig werken", "Veilig werken op locatie, in de werkplaats en in het magazijn.", None, 1),
    ("VEI-01", "Veilig werken volgens VCA", "Risico's op de werkplek herkennen en de VCA-regels toepassen.", "VEI", 1),
    ("VEI-02", "Bedrijfshulpverlening", "Eerste hulp verlenen, een beginnende brand blussen en een ontruiming leiden.", "VEI", 2),
    ("VEI-03", "Elektrotechnisch veilig werken (NEN 3140)", "Veilig werken aan en nabij elektrische installaties als voldoend onderricht persoon.", "VEI", 3),
    ("VEI-04", "Heftruck veilig bedienen", "Een heftruck veilig bedienen, laden en lossen.", "VEI", 4),
    ("VAK", "Vakmanschap installatietechniek", "Het technische vak van de servicemonteur.", None, 2),
    ("VAK-01", "Warmtepompen installeren en in bedrijf stellen", "Een warmtepomp plaatsen, aansluiten, inregelen en overdragen aan de klant.", "VAK", 1),
    ("VAK-02", "Werken aan koelcircuits met F-gassen", "Gecertificeerd werken aan koelcircuits volgens de F-gassenregels.", "VAK", 2),
    ("VAK-03", "Storingen analyseren en verhelpen", "Een storing systematisch opsporen en blijvend verhelpen.", "VAK", 3),
    ("DIG", "Digitaal en veilig werken", "Veilig en handig werken met systemen en gegevens.", None, 3),
    ("DIG-01", "Phishing en social engineering herkennen", "Verdachte berichten herkennen en een incident direct melden.", "DIG", 1, ["learner"]),
    ("DIG-02", "Zorgvuldig omgaan met persoonsgegevens", "Gegevens van klanten en collega's verwerken volgens de AVG.", "DIG", 2, ["compliance-officer"]),
    ("DIG-03", "Werken met spreadsheets", "Gegevens analyseren met draaitabellen, zoekfuncties en grafieken.", "DIG", 3),
    ("LEI", "Leidinggeven en samenwerken", "Samenwerken met collega's en klanten, en een team leiden.", None, 4),
    ("LEI-01", "Coachend leidinggeven", "Medewerkers helpen groeien met vragen, feedback en duidelijke afspraken.", "LEI", 1, ["manager"]),
    ("LEI-02", "Verzuimgesprekken voeren", "Een verzuimgesprek voeren volgens de Wet verbetering poortwachter.", "LEI", 2, ["manager"]),
    ("LEI-03", "Klantgericht communiceren", "Een klantvraag helder uitvragen en een verwachting waarmaken.", "LEI", 3),
    ("LEI-04", "Integer handelen volgens de gedragscode", "Een dilemma herkennen, bespreekbaar maken en de gedragscode toepassen.", "LEI", 4),
]
# key, name, description, tags, mandatory, regulation slug, competency codes, and the parent course key if any.
COURSES: list[tuple] = [
    ("ONB", "Welkom bij Esdoorn Techniek", "Onboarding voor nieuwe medewerkers: wie we zijn, hoe we werken en hoe je veilig begint.",
        ["onboarding"], True, None, ["VEI-01"]),
    ("GED", "Gedragscode en integriteit", "Jaarlijkse e-learning over de gedragscode, met dilemma's uit de praktijk.",
        ["compliance", "jaarlijks"], True, "GEDRAGSCODE", ["LEI-04"]),
    ("IBV", "Informatiebeveiliging en phishing", "Jaarlijkse e-learning: phishing herkennen, veilig inloggen en een incident melden.",
        ["compliance", "jaarlijks", "informatiebeveiliging"], True, "INFORMATIEBEVEILIGING", ["DIG-01"]),
    ("AVG", "AVG in de praktijk", "Zorgvuldig omgaan met gegevens van klanten en collega's, voor iedereen op kantoor.",
        ["compliance", "privacy"], True, "AVG", ["DIG-02"]),
    ("BHV-B", "BHV basis", "Tweedaagse basisopleiding bedrijfshulpverlening: eerste hulp, brand blussen en ontruimen.",
        ["veiligheid", "bhv"], True, "BHV", ["VEI-02"]),
    ("BHV-H", "BHV herhaling", "Jaarlijkse herhalingsdag bedrijfshulpverlening.", ["veiligheid", "bhv", "jaarlijks"], True, "BHV", ["VEI-02"]),
    ("LL-SM", "Leerlijn servicemonteur", "De leerlijn voor monteurs: veilig werken, elektrotechniek, F-gassen en warmtepompen.",
        ["leerlijn", "techniek"], False, None, []),
    ("VCA-B", "VCA Basis", "Eendaagse cursus met examen voor veilig werken op locatie.", ["veiligheid", "vca"], True, "VCA", ["VEI-01"], "LL-SM"),
    ("VCA-V", "VCA VOL", "VCA voor operationeel leidinggevenden, met examen.", ["veiligheid", "vca"], True, "VCA", ["VEI-01"], "LL-SM"),
    ("NEN-B", "NEN 3140 basis voldoend onderricht persoon", "Eendaagse opleiding tot voldoend onderricht persoon, met aanwijzing door de werkgever.",
        ["veiligheid", "elektrotechniek"], True, "NEN3140", ["VEI-03"], "LL-SM"),
    ("NEN-H", "NEN 3140 herinstructie", "Herinstructie voor voldoend onderricht personen, elke drie jaar.",
        ["veiligheid", "elektrotechniek"], True, "NEN3140", ["VEI-03"], "LL-SM"),
    ("FGAS", "F-gassen certificering", "Tweedaagse cursus met examen voor werken aan koelcircuits van warmtepompen.",
        ["techniek", "f-gassen"], True, "FGASSEN", ["VAK-02"], "LL-SM"),
    ("WP", "Praktijkcursus warmtepompen", "Tweedaagse praktijkcursus: plaatsen, inregelen en in bedrijf stellen van warmtepompen.",
        ["techniek", "warmtepompen", "betaald"], False, None, ["VAK-01"], "LL-SM"),
    ("HEF-B", "Heftruckcertificaat basis", "Eendaagse opleiding met praktijkexamen voor heftruckchauffeurs.",
        ["veiligheid", "magazijn"], True, "HEFTRUCK", ["VEI-04"]),
    ("HEF-H", "Heftruck herhaling", "Herhalingstraining heftruck, elke vijf jaar.", ["veiligheid", "magazijn"], True, "HEFTRUCK", ["VEI-04"]),
    ("LEI", "Coachend leidinggeven", "Drie middagen over coachende gesprekken, feedback en verzuimbegeleiding.",
        ["leidinggeven"], False, None, ["LEI-01", "LEI-02"]),
    ("KLA", "Klantgericht communiceren", "Een voorbereidende les en een trainingsdag over het klantgesprek.",
        ["verkoop", "klantenservice"], False, None, ["LEI-03"]),
    ("EXC", "Excel gevorderd", "E-learning over draaitabellen, zoekfuncties en dashboards.", ["digitaal", "betaald"], False, None, ["DIG-03"]),
    ("PRJ", "Projectmatig werken", "Driedaagse cursus met examen over plannen, risico's en projectoverleg.", ["projecten", "betaald"], False, None, []),
]

# Per course key: the months a certificate stays valid (None: no expiry) and who issues it.
AWARDS = {
    "GED": (12, OWN), "IBV": (12, OWN), "AVG": (24, OWN), "BHV-B": (12, OWN), "BHV-H": (12, OWN),
    "VCA-B": (120, EXAM_SAFETY), "VCA-V": (120, EXAM_SAFETY), "NEN-B": (36, OWN), "NEN-H": (36, OWN),
    "HEF-B": (60, FORKLIFT_SCHOOL), "HEF-H": (60, FORKLIFT_SCHOOL), "FGAS": (60, EXAM_COOLING),
    "WP": (None, HEAT_PUMP_ACADEMY), "PRJ": (None, PROJECT_ACADEMY),
}


@dataclass(frozen=True)
class Issue:
    """One certificate to issue: who holds it, for which course key, when, and how it came in."""

    person: dict
    key: str
    issued: dt.datetime
    source: str


@dataclass(frozen=True)
class GroupSpec:
    """A training group: its course key, its name, its (site, room) and its trainer (None: an outside trainer)."""

    key: str
    name: str
    place: tuple[str, str]
    trainer: str | None


@dataclass(frozen=True)
class Schedule:
    """When a training group meets: its days, the (start, end) clock times of each day, a note and its lifecycle."""

    days: list[dt.date]
    hours: tuple[tuple[int, int], tuple[int, int]] = ((8, 30), (16, 30))
    notes: str | None = None
    lifecycle: str = "completed"


@dataclass(frozen=True)
class Activity:
    """Training followed outside the company: its title, provider, kind and day."""

    title: str
    provider: str
    kind: str
    day: dt.date


@dataclass(frozen=True)
class Review:
    """Who submitted an outside training record, who verified it, the evidence note and the record's lifecycle."""

    submitted_by: str
    verified_by: str | None
    evidence: str | None
    lifecycle: str = "verified"


@dataclass(frozen=True)
class RecordExtras:
    """The optional parts of an outside training record."""

    regulation: str | None = None
    valid_months: int | None = None
    batch: str | None = None
    rejection: str | None = None


@dataclass(frozen=True)
class ExternalRecord:
    """An outside training record, the same for each learner it is written for."""

    activity: Activity
    review: Review
    extras: RecordExtras = RecordExtras()


PATH_OF = {key: path for key, _name, path, _site, _size, _head in DEPARTMENTS}
# code, name, description, applicability, audience scope, renewal months, annual renewal, and the audience
# departments or roles if the scope names them.
REGULATIONS: list[tuple] = [
    ("GEDRAGSCODE", "Gedragscode Esdoorn Techniek",
        "De gedragscode van het bedrijf: integriteit, omgang met klanten en collega's en het melden van misstanden.",
        "Iedereen die bij Esdoorn Techniek werkt, elk jaar opnieuw.", "all-employees", 12, True),
    ("INFORMATIEBEVEILIGING", "Informatiebeveiligingsbeleid",
        "Het beleid voor veilig werken met systemen en gegevens: phishing herkennen, veilig inloggen en incidenten melden.",
        "Iedereen met een account, elk jaar opnieuw.", "all-employees", 12, True),
    ("VCA", "VCA, veiligheid, gezondheid en milieu",
        "Veilig werken op locatie en in de werkplaats, met een VCA-diploma dat tien jaar geldig is.",
        "Iedereen in de operatie: planning, installatie, magazijn en werkplaats.", "department", 120, False,
        {"audienceDepartments": ["Operatie"]}),
    ("NEN3140", "NEN 3140, veilig werken aan elektrische installaties",
        "Voldoend onderricht personen die aan of bij elektrische installaties werken, met herinstructie elke drie jaar.",
        "Monteurs in installatie en service en in de werkplaats.", "department", 36, False,
        {"audienceDepartments": [PATH_OF["installatie"], PATH_OF["werkplaats"]]}),
    ("HEFTRUCK", "Heftruckcertificaat",
        "Een heftruck bedienen mag alleen met een geldig certificaat, dat vijf jaar geldig is.",
        "Iedereen in het magazijn.", "department", 60, False, {"audienceDepartments": [PATH_OF["magazijn"]]}),
    ("FGASSEN", "F-gassen, EU-verordening 2024/573",
        "Werken aan koelcircuits van warmtepompen mag alleen met een F-gassencertificaat.",
        "Aangewezen monteurs die koelcircuits openen; de leidinggevende houdt de lijst bij.", "role-specific", None, False,
        {"audienceRoles": []}),
    ("BHV", "Bedrijfshulpverlening (Arbowet artikel 15)",
        "Genoeg opgeleide bedrijfshulpverleners op elke vestiging, met een herhaling elk jaar.",
        "Aangewezen bedrijfshulpverleners op beide vestigingen, niet iedere medewerker.", "role-specific", 12, True,
        {"audienceRoles": []}),
    ("NIS2", "NIS2, cyberbeveiliging voor bestuurders",
        "Bestuurders en leidinggevenden kennen de zorgplicht en meldplicht uit de NIS2-richtlijn.",
        "De directie, de leidinggevenden en de compliance officer.", "board", 12, True,
        {"audienceRoles": ["manager", "compliance-officer"]}),

]


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
    return CompanyYear().build()


class CompanyYear:
    """One run of the company set. The steps run in a fixed order and share one random
    generator, so every draw, and therefore the file, is the same on each run."""

    def pick_day(self, lo: dt.date, hi: dt.date) -> dt.date:
        return self.rng.choice(workdays(lo, hi))

    def clock(self) -> tuple[int, int]:
        return self.rng.randint(8, 15), self.rng.choice([0, 10, 15, 20, 30, 40, 45, 50])

    def __init__(self) -> None:
        self.rng = random.Random(20250901)
        self.b = Builder(IDENTITY)

    def build(self) -> dict:
        self.add_company_and_rooms()
        self.add_competency_framework()
        self.add_courses_and_programmes()
        self.add_people()
        self.add_onboarding()
        self.add_gedragscode()
        self.add_informatiebeveiliging()
        self.add_avg()
        self.add_bhv()
        self.add_vca()
        self.add_nen_3140()
        self.add_heftruck()
        self.add_f_gassen()
        self.add_leadership_and_paid_courses()
        self.add_toolbox_meetings()
        self.add_external_records()
        self.add_attainments()
        self.add_development_plans()
        self.add_engagement()
        self.add_gamification()
        self.add_fees_and_entitlements()
        self.strip_private_fields()
        return self.assemble()

    def add_company_and_rooms(self) -> None:
        """The company, its sites and its rooms."""
        self.company = self.b.add("school", {"brin": "00X5", "name": COMPANY})
        self.sites = {
            "hoofd": self.b.add("vestiging", {
                "schoolId": self.company["uuid"], "vestigingscode": "00X500", "onderwijslocatiecode": None,
                "name": "Hoofdkantoor", "street": "Esdoornlaan 1", "postalCode": "0512 EH", "city": TOWN,
            }),
            "haven": self.b.add("vestiging", {
                "schoolId": self.company["uuid"], "vestigingscode": "00X501", "onderwijslocatiecode": None,
                "name": "Werkplaats en magazijn Havenkwartier", "street": "Havenkade 40", "postalCode": "0514 HK", "city": TOWN,
            }),
        }
        self.rooms = {
            "training": self.b.add("room", {"name": "Trainingsruimte Esdoorn", "code": "TR-1", "capacity": 16, "kind": "classroom",
                                            "facilities": ["beamer", "whiteboard"], "buildingCode": "00X500", "floor": "1"}),
            "vergader": self.b.add("room", {"name": "Vergaderzaal Linde", "code": "VZ-2", "capacity": 10, "kind": "classroom",
                                            "facilities": ["scherm", "videobellen"], "buildingCode": "00X500", "floor": "2"}),
            "praktijk": self.b.add("room", {"name": "Praktijkhal", "code": "PH-1", "capacity": 20, "kind": "lab",
                                            "facilities": ["installatiewand", "warmtepompopstelling", "meterkastopstelling"],
                                            "buildingCode": "00X501", "floor": "0"}),
            "kantine": self.b.add("room", {"name": "Kantine Havenkwartier", "code": "KH-0", "capacity": 80, "kind": "other",
                                           "facilities": ["beamer"], "buildingCode": "00X501", "floor": "0"}),
            "online": self.b.add("room", {"name": "Online via Talk", "code": "ONLINE", "capacity": 100, "kind": "online", "facilities": []}),
        }

    def add_competencies(self) -> None:
        for row in COMPETENCIES:
            code, title, description, parent, order, *rest = row
            roles = rest[0] if rest else None
            fields = {"frameworkId": self.framework["uuid"]}
            if parent is not None:
                fields["parentId"] = self.comp[parent]["uuid"]
            fields.update({"code": code, "title": title, "description": description, "order": order,
                           "requiredForRoles": roles or [], "lifecycle": "published"})
            self.comp[code] = self.b.add("competency", fields)

    def add_competency_framework(self) -> None:
        """The competency framework."""
        self.framework = self.b.add("competency-framework", {
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
        self.comp: dict[str, dict] = {}

        self.add_competencies()

    def add_courses(self) -> None:
        for row in COURSES:
            key, name, description, tags, mandatory, regulation, competencies, *parents = row
            parent = parents[0] if parents else None
            fields = {"code": "ESD-" + key, "name": name, "name_nl": name, "description": description, "level": "corporate",
                      "language": "nl", "tags": tags, "mandatoryTraining": mandatory}
            if regulation is not None:
                fields["regulationSlug"] = regulation
            fields["lifecycle"] = "published"
            if parent is not None:
                fields["parentCourseId"] = self.courses[parent]["uuid"]
            fields["competencyIds"] = [self.comp[c]["uuid"] for c in competencies]
            self.courses[key] = self.b.add("course", fields)

    def add_courses_and_programmes(self) -> None:
        """Courses, lessons and programmes."""
        self.courses: dict[str, dict] = {}

        self.add_courses()
        for key, renewal in (("GED", "GED"), ("IBV", "IBV"), ("AVG", "AVG"), ("BHV-B", "BHV-H"), ("BHV-H", "BHV-H"), ("VCA-B", "VCA-B"),
                             ("VCA-V", "VCA-V"), ("NEN-B", "NEN-H"), ("NEN-H", "NEN-H"), ("HEF-B", "HEF-H"), ("HEF-H", "HEF-H")):
            self.courses[key]["renewalCourseSlug"] = self.courses[renewal]["slug"]

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
        self.lessons: dict[str, list[dict]] = {}
        for key, rows in lesson_plan.items():
            c = self.courses[key]
            self.lessons[key] = []
            for order, (name, kind, minutes, objective) in enumerate(rows, start=1):
                fields = {"courseId": c["uuid"], "name": name, "order": order, "contentType": kind, "durationMinutes": minutes,
                          "learningObjectives": [objective], "competencyIds": c["competencyIds"], "mandatoryTraining": c["mandatoryTraining"]}
                if "regulationSlug" in c:
                    fields["regulationSlug"] = c["regulationSlug"]
                fields["lifecycle"] = "published"
                self.lessons[key].append(self.b.add("lesson", fields))

        self.technician_programme = self.b.add("programme", {
            "name": "Vakopleiding servicemonteur", "code": "ESD-PRG-SM", "level": "corporate",
            "description": "Wat een servicemonteur moet kunnen en welke cursussen daarbij horen.",
            "courseIds": [self.courses[k]["uuid"] for k in ("LL-SM", "FGAS", "WP")],
            "requiredCompetencyIds": [self.comp[c]["uuid"] for c in ("VEI-01", "VEI-03", "VAK-01", "VAK-03")],
            "lifecycle": "published",
        })
        self.leadership_programme = self.b.add("programme", {
            "name": "Leiderschapsprogramma", "code": "ESD-PRG-LEI", "level": "corporate",
            "description": "Het programma voor iedereen die een afdeling of ploeg leidt.",
            "courseIds": [self.courses["LEI"]["uuid"]],
            "requiredCompetencyIds": [self.comp["LEI-01"]["uuid"], self.comp["LEI-02"]["uuid"]],
            "lifecycle": "published",
        })
        for key in ("LL-SM", "FGAS", "WP"):
            self.courses[key]["programmeIds"] = [self.technician_programme["uuid"]]
        self.courses["LEI"]["programmeIds"] = [self.leadership_programme["uuid"]]

    def person(self, nc: str, dept: str, roles: list[str], manager: str | None) -> dict:
        female = self.rng.random() < (0.2 if dept in TECHNICIANS or dept == "magazijn" else 0.55)
        while True:
            surname = self.rng.choice(SURNAME_HEAD) + self.rng.choice(SURNAME_TAIL)
            if surname not in self.used_names or len(self.used_names) > 240:
                self.used_names.add(surname)
                break
        p = {"nc": nc, "dept": dept, "roles": roles, "manager": manager, "start": None, "female": female,
             "given": self.rng.choice(GIVEN_F if female else GIVEN_M), "family": surname,
             "birth": dt.date(self.rng.randint(1961, 2003), self.rng.randint(1, 12), self.rng.randint(1, 28)),
             "ability": self.rng.gauss(0, 1), "done": {}}
        self.people.append(p)
        return p

    def existing(self, p: dict) -> bool:
        return p["start"] is None

    def plain_existing(self, dept: str) -> list[dict]:
        return [p for p in self.members[dept] if p["roles"] == ["learner"] and self.existing(p)]

    def add_people(self) -> None:
        """The people."""
        self.people: list[dict] = []
        self.members: dict[str, list[dict]] = {}
        self.used_names: set[str] = set()
        counter = 0

        for key, _name, _path, _site, size, head in DEPARTMENTS:
            group = [self.person(head, key, ["learner", "manager"], None if head == DIRECTOR else DIRECTOR)]
            for nc, roles in SPECIAL.get(key, []):
                group.append(self.person(nc, key, roles, head))
            while len(group) < size:
                counter += 1
                group.append(self.person(f"corporate-medewerker-{counter:03d}", key, ["learner"], head))
            self.members[key] = group
        for key in [d[0] for d in DEPARTMENTS]:
            starts = [start for dept, start in NEW_HIRES if dept == key]
            if starts:
                plain = [p for p in self.members[key] if p["roles"] == ["learner"]]
                for p, start in zip(plain[-len(starts):], starts):
                    p["start"] = start

        self.by_nc = {p["nc"]: p for p in self.people}
        self.dept_of = {d[0]: d for d in DEPARTMENTS}

        for p in self.people:
            fields = {"ncUserId": p["nc"], "givenName": p["given"], "familyName": p["family"], "birthDate": p["birth"].isoformat(),
                      "schoolId": self.company["uuid"], "eduPersonAffiliation": ["employee"], "roles": p["roles"]}
            if p["manager"] is not None:
                fields["managerId"] = p["manager"]
            fields["department"] = self.dept_of[p["dept"]][2]
            fields["lifecycle"] = "active"
            p["profile"] = self.b.add("learner-profile", fields)

        self.cohorts: dict[str, dict] = {}
        for key, name, _path, site, _size, head in DEPARTMENTS:
            self.cohorts[key] = self.b.add("cohort", {
                "name": name, "period": "Opleidingsjaar", "academicYear": YEAR, "teacherIds": [head],
                "learnerIds": [p["nc"] for p in self.members[key]], "lifecycle": "active", "locationId": self.sites[site]["uuid"],
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
            self.b.add("staff", {"ncUserId": nc, "roles": roles, "qualifications": qualifications, "workingDays": days})

        self.template = self.b.add("learning-plan-template", {
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

    def enrol(self, p: dict, key: str, source: str, cohort: dict, due: dt.date | None = None) -> dict:
        c = self.courses[key]
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
        e = self.b.add("enrolment", fields)
        e["_p"], e["_key"], e["_done"] = p, key, None
        return e

    def complete(self, e: dict, when: dt.datetime) -> None:
        e["lifecycle"] = "completed"
        e["_done"] = when
        e["_p"]["done"][e["_key"]] = when

    def quiz_score(self, p: dict) -> int:
        return max(60, min(100, round(80 + 9 * p["ability"] + self.rng.gauss(0, 5))))

    def elearn(self, e: dict, start: dt.date, finish: dt.date | None, partial: int = 0) -> None:
        """Lesson completions on workdays from `start`; the last one on `finish`.

        With `finish` None the learner stopped after `partial` lessons and the
        enrolment stays active."""
        p, rows = e["_p"], self.lessons[e["_key"]]
        count = len(rows) if finish is not None else partial
        if count:
            last = finish if finish is not None else self.pick_day(start, min(plus_workdays(start, 15), LAST_DAY))
            days = sorted(self.pick_day(start, last) for _ in range(count - 1)) + [last]
            score = self.quiz_score(p)
            hour, minute = self.clock()
            previous = None
            e["_times"] = []
            for lesson, day in zip(rows, days):
                when = moment(day, hour, minute)
                if previous is not None and when <= previous:
                    when = previous + dt.timedelta(minutes=self.rng.choice([20, 25, 35]))
                previous = when
                fields = {"learnerId": p["nc"], "learnerRef": p["profile"]["uuid"], "lessonId": lesson["uuid"],
                          "courseId": lesson["courseId"], "enrolmentId": e["uuid"], "source": "xapi",
                          "verb": VERB_PASSED if lesson["contentType"] == "quiz" else VERB_COMPLETED}
                if lesson["contentType"] == "quiz":
                    fields["score"] = score
                fields["completedAt"] = iso(when)
                self.b.add("lesson-completion", fields)
                e["_times"].append((lesson, when))
            e["_score"] = score
        e["progressPercent"] = round(100 * count / len(rows))
        if finish is not None:
            self.complete(e, e["_times"][-1][1])

    def credential(self, issue: Issue, link: tuple[str, dict] | None = None) -> dict:
        """Issues the certificate; link names the enrolment field and the enrolment it points at."""
        p, key, issued, source = issue.person, issue.key, issue.issued, issue.source
        months, issuer = AWARDS[key]
        c = self.courses[key]
        expires = add_months(issued, months) if months else None
        payload = {"@context": OB3_CONTEXT, "type": ["VerifiableCredential", "OpenBadgeCredential"],
                   "issuer": {"id": issuer[1], "type": ["Profile"], "name": issuer[0]}, "validFrom": iso(issued)}
        if expires is not None:
            payload["validUntil"] = iso(expires)
        payload["credentialSubject"] = {"type": ["AchievementSubject"], "achievement": {"type": ["Achievement"], "name": c["name"]}}
        # learnerId is the LearnerProfile uuid; learnerUserId its Nextcloud user id, the read rule's key.
        fields = {"learnerId": p["profile"]["uuid"], "learnerUserId": p["profile"]["ncUserId"], "courseId": c["uuid"],
                  "kind": "certificate", "issuedAt": iso(issued)}
        if expires is not None:
            fields["expiresAt"] = iso(expires)
        fields.update({"issuerDid": issuer[1], "signature": UNSIGNED, "openbadges3Payload": payload, "issuedBy": issuer[0], "source": source})
        if "regulationSlug" in c:
            fields["regulationSlug"] = c["regulationSlug"]
        if link is not None:
            fields[link[0]] = link[1]["uuid"]
        fields["competencyIds"] = c["competencyIds"]
        fields["lifecycle"] = "expired" if expires is not None and expires.date() <= LAST_DAY else "issued"
        cred = self.b.add("credential", fields)
        cred["_p"], cred["_key"], cred["_expires"] = p, key, expires
        return cred

    def training_group(self, spec: GroupSpec, plan: Schedule) -> dict:
        key, name, (site, room), trainer = spec.key, spec.name, spec.place, spec.trainer
        days, (start, end), notes, lifecycle = plan.days, plan.hours, plan.notes, plan.lifecycle
        c = self.courses[key]
        group = self.b.add("cohort", {
            "name": name, "period": "Opleidingsjaar", "academicYear": YEAR, "courseId": c["uuid"],
            "teacherIds": [trainer] if trainer else [], "learnerIds": [], "lifecycle": lifecycle,
            "locationId": self.sites[site]["uuid"], "notes": notes, "kind": "teaching",
        })
        group["_sessions"] = []
        group["_marker"] = trainer or COORDINATOR
        for index, day in enumerate(days, start=1):
            title = f"{name}, {day.day} {MAAND[day.month - 1]} {day.year}" if len(days) == 1 else f"{name}, dag {index} ({day.day} {MAAND[day.month - 1]} {day.year})"
            s = self.b.add("session", {
                "cohortId": group["uuid"], "courseId": c["uuid"], "title": title,
                "startsAt": iso(moment(day, *start)), "endsAt": iso(moment(day, *end)),
                "location": self.rooms[room]["name"], "roomId": self.rooms[room]["uuid"], "lifecycle": "completed",
            })
            s["_day"], s["_start"], s["_end"] = day, moment(day, *start), moment(day, *end)
            s["_minutes"] = (end[0] - start[0]) * 60 + end[1] - start[1]
            group["_sessions"].append(s)
        return group

    def attend(self, group: dict, e: dict, absent: tuple[int, ...] = ()) -> None:
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
            self.b.add("attendance-record", fields)

    def first_group(self, groups: list[dict], day: dt.date) -> dict | None:
        return next((g for g in groups if g["_sessions"][0]["_day"] >= day), None)

    def add_onboarding(self) -> None:
        """Onboarding."""
        for p in self.people:
            if not self.existing(p):
                e = self.enrol(p, "ONB", "hr", self.cohorts[p["dept"]], p["start"] + dt.timedelta(days=14))
                self.elearn(e, p["start"], plus_workdays(p["start"], self.rng.randint(0, 7)))

    def add_gedragscode(self) -> None:
        """Gedragscode: last year's certificates expire, renewal enrolments follow."""
        renewals: list[dict] = []
        self.olds = [p for p in self.people if self.existing(p)]
        shuffled = list(self.olds)
        self.rng.shuffle(shuffled)
        campaign_2024 = shuffled[:160]
        campaign_ids = {p["nc"] for p in campaign_2024}
        ged_stuck = self.rng.sample([p for p in self.olds if p["roles"] == ["learner"]], 4)
        self.ged_stuck_ids = {p["nc"] for p in ged_stuck}
        for p in self.people:
            if self.existing(p):
                lo, hi = (dt.date(2024, 9, 16), dt.date(2024, 11, 15)) if p["nc"] in campaign_ids else (dt.date(2024, 12, 2), dt.date(2025, 6, 30))
                issued = moment(self.pick_day(lo, hi), *self.clock())
                expiry = add_months(issued, 12)
                e = self.enrol(p, "GED", "credential-renewal", self.cohorts[p["dept"]])
                self.credential(Issue(p, "GED", issued, "migrated"), ("renewalEnrolmentId", e))
                start = on_or_after(expiry.date() + dt.timedelta(days=1))
                if p["nc"] in self.ged_stuck_ids:
                    self.elearn(e, start, None, partial=1 if ged_stuck.index(p) < 2 else 0)
                    continue
                finish = min(plus_workdays(start, self.rng.choices([1, 3, 6, 10, 15, 30], weights=[20, 30, 20, 15, 10, 5])[0]), LAST_DAY)
            else:
                start = p["start"]
                e = self.enrol(p, "GED", "hr", self.cohorts[p["dept"]], start + dt.timedelta(days=30))
                finish = plus_workdays(start, self.rng.randint(2, 18))
            self.elearn(e, start, finish)
            self.credential(Issue(p, "GED", e["_done"], "auto"), ("enrolmentId", e))

    def add_informatiebeveiliging(self) -> None:
        """Informatiebeveiliging: new this year, bulk enrolment in October."""
        ibv_campaign = dt.date(2025, 10, 6)
        ibv_due = dt.date(2026, 1, 31)
        candidates = [p for p in self.olds if p["roles"] == ["learner"] and p["nc"] not in self.ged_stuck_ids]
        self.ibv_stuck = self.rng.sample(candidates, 5)
        ibv_stuck_ids = {p["nc"] for p in self.ibv_stuck}
        self.ibv_enrolments = []
        for p in self.people:
            if self.existing(p):
                start, due = ibv_campaign, ibv_due
                e = self.enrol(p, "IBV", "bulk", self.cohorts[p["dept"]], due)
            else:
                start, due = p["start"], p["start"] + dt.timedelta(days=30)
                e = self.enrol(p, "IBV", "hr", self.cohorts[p["dept"]], due)
            self.ibv_enrolments.append(e)
            if p["nc"] in ibv_stuck_ids:
                self.elearn(e, start, None, partial=1 if self.ibv_stuck.index(p) < 3 else 0)
                continue
            if self.rng.random() < 0.88:
                finish = self.pick_day(start, min(due, LAST_DAY))
            else:
                finish = self.pick_day(due + dt.timedelta(days=1), min(due + dt.timedelta(days=60), LAST_DAY))
            self.elearn(e, start, finish)
            self.credential(Issue(p, "IBV", e["_done"], "auto"), ("enrolmentId", e))

    def add_avg(self) -> None:
        """AVG for the office departments, campaign in March."""
        avg_campaign = dt.date(2026, 3, 2)
        office = [p for d in OFFICE for p in self.members[d]]
        avg_stuck = self.rng.sample([p for p in office if p["roles"] == ["learner"] and self.existing(p)], 3)
        avg_stuck_ids = {p["nc"] for p in avg_stuck}
        for p in office:
            if self.existing(p) or p["start"] <= avg_campaign:
                start, due = avg_campaign, dt.date(2026, 4, 30)
            else:
                start, due = p["start"], p["start"] + dt.timedelta(days=60)
            e = self.enrol(p, "AVG", "hr", self.cohorts[p["dept"]], due)
            if p["nc"] in avg_stuck_ids:
                self.elearn(e, start, None, partial=1)
                continue
            finish = self.pick_day(start, due) if self.rng.random() < 0.9 else self.pick_day(due + dt.timedelta(days=1), LAST_DAY)
            self.elearn(e, start, min(finish, LAST_DAY))
            self.credential(Issue(p, "AVG", e["_done"], "auto"), ("enrolmentId", e))

    def add_bhv(self) -> None:
        """BHV: last September's certificates expire, herhaling in October and November."""
        bhv_team = []
        for dept, n in (("installatie", 8), ("magazijn", 5), ("werkplaats", 3), ("directie", 2), ("financien", 2), ("ict", 1), ("verkoop", 5), ("planning", 3)):
            bhv_team += self.rng.sample(self.plain_existing(dept), n)
        haven = [p for p in bhv_team if self.dept_of[p["dept"]][3] == "haven"]
        hoofd = [p for p in bhv_team if self.dept_of[p["dept"]][3] == "hoofd"]
        bhv_sets = [(haven[:8], dt.date(2024, 9, 10)), (haven[8:], dt.date(2024, 9, 12)), (hoofd, dt.date(2024, 9, 17))]
        bhv_groups = [
            self.training_group(GroupSpec("BHV-H", "BHV herhaling, groep 1", ("haven", "praktijk"), TRAINER_BHV), Schedule([dt.date(2025, 10, 7)])),
            self.training_group(GroupSpec("BHV-H", "BHV herhaling, groep 2", ("haven", "praktijk"), TRAINER_BHV), Schedule([dt.date(2025, 10, 21)])),
            self.training_group(GroupSpec("BHV-H", "BHV herhaling, groep 3", ("hoofd", "training"), TRAINER_BHV), Schedule([dt.date(2025, 11, 4)])),
        ]
        catch_up = self.training_group(GroupSpec("BHV-H", "BHV herhaling, inhaaldag", ("hoofd", "training"), TRAINER_BHV),
                                       Schedule([dt.date(2026, 1, 20)]))
        for (team, day), group in zip(bhv_sets, bhv_groups):
            for index, p in enumerate(team):
                issued = moment(day, 16, 30)
                # The long-term ill employee in group 3 misses the day and is not rescheduled this year.
                long_term = group is bhv_groups[2] and index == 0
                rescheduled = (group is bhv_groups[1] and index == 0) or (group is bhv_groups[0] and index == 1)
                e = self.enrol(p, "BHV-H", "credential-renewal", catch_up if rescheduled else group)
                self.credential(Issue(p, "BHV-H", issued, "migrated"), ("renewalEnrolmentId", e))
                if long_term:
                    self.attend(group, e, absent=(0,))
                    continue
                if rescheduled:
                    self.attend(group, e, absent=(0,))
                    self.attend(catch_up, e)
                    done = catch_up["_sessions"][-1]["_end"]
                else:
                    self.attend(group, e)
                    done = group["_sessions"][-1]["_end"]
                self.complete(e, done)
                self.credential(Issue(p, "BHV-H", done, "manual"), ("enrolmentId", e))
        bhv_ids = {p["nc"] for p in bhv_team}
        new_bhv = []
        for dept, n in (("installatie", 2), ("magazijn", 1), ("verkoop", 1), ("financien", 1), ("planning", 1)):
            new_bhv += self.rng.sample([p for p in self.plain_existing(dept) if p["nc"] not in bhv_ids], n)
        basis = self.training_group(GroupSpec("BHV-B", "BHV basis, februari", ("hoofd", "training"), TRAINER_BHV),
                                    Schedule([dt.date(2026, 2, 10), dt.date(2026, 2, 11)]))
        for p in new_bhv:
            e = self.enrol(p, "BHV-B", "hr", basis)
            self.attend(basis, e)
            self.complete(e, basis["_sessions"][-1]["_end"])
            self.credential(Issue(p, "BHV-B", e["_done"], "manual"), ("enrolmentId", e))

    def add_vca(self) -> None:
        """VCA for everyone in Operatie."""
        vca_groups = [
            self.training_group(GroupSpec("VCA-B", "VCA Basis, groep 1", ("hoofd", "training"), None),
                                Schedule([dt.date(2025, 10, 14)], ((8, 30), (17, 0)),
                                         notes="Externe docent; het examen neemt Voorbeeld Examencentrum Veiligheid af.")),
            self.training_group(GroupSpec("VCA-B", "VCA Basis, groep 2", ("hoofd", "training"), None),
                                Schedule([dt.date(2026, 3, 17)], ((8, 30), (17, 0)),
                                         notes="Externe docent; het examen neemt Voorbeeld Examencentrum Veiligheid af.")),
        ]
        vca_next = self.training_group(GroupSpec("VCA-B", "VCA Basis, groep 3 (september 2026)", ("hoofd", "training"), None),
                                       Schedule([], notes="Gepland voor september 2026, voor wie na maart in dienst kwam.", lifecycle="planned"))
        vol = [self.members[d][0] for d in ("installatie", "magazijn", "werkplaats")]
        vol += self.plain_existing("installatie")[:6] + self.plain_existing("magazijn")[:1] + [self.by_nc[TRAINER_BHV], self.by_nc[TRAINER_TECH]]
        vol_ids = {p["nc"] for p in vol}
        vca_renew = self.plain_existing("installatie")[6:9] + self.plain_existing("magazijn")[1:2] + self.plain_existing("planning")[:1]
        vca_renew_ids = {p["nc"] for p in vca_renew}
        vca_failed_once = next(p for p in self.people if p["dept"] == "installatie" and p["start"] == dt.date(2025, 9, 1))
        for dept in OPERATIONS:
            for p in self.members[dept]:
                if self.existing(p):
                    key = "VCA-V" if p["nc"] in vol_ids else "VCA-B"
                    if p["nc"] in vca_renew_ids:
                        issued = moment(self.pick_day(dt.date(2015, 9, 1), dt.date(2016, 2, 26)), 16, 45)
                        expiry = add_months(issued, 120)
                        group = self.first_group(vca_groups, expiry.date() + dt.timedelta(days=1))
                        e = self.enrol(p, "VCA-B", "credential-renewal", group)
                        self.credential(Issue(p, key, issued, "migrated"), ("renewalEnrolmentId", e))
                        self.attend(group, e)
                        self.complete(e, group["_sessions"][-1]["_end"])
                        self.credential(Issue(p, "VCA-B", e["_done"], "manual"), ("enrolmentId", e))
                    else:
                        issued = moment(self.pick_day(dt.date(2016, 8, 1), dt.date(2025, 6, 30)), 16, 45)
                        self.credential(Issue(p, key, issued, "migrated"))
                    continue
                group = self.first_group(vca_groups, p["start"] + dt.timedelta(days=7))
                if group is None:
                    e = self.enrol(p, "VCA-B", "hr", vca_next, p["start"] + dt.timedelta(days=90))
                    vca_next["learnerIds"].append(p["nc"])
                    continue
                if p is vca_failed_once:
                    failed = self.enrol(p, "VCA-B", "hr", group, p["start"] + dt.timedelta(days=90))
                    self.attend(group, failed)
                    failed["lifecycle"] = "failed"
                    failed["reason"] = "Examen niet gehaald; herkansing in groep 2."
                    group = vca_groups[1]
                e = self.enrol(p, "VCA-B", "hr", group, p["start"] + dt.timedelta(days=90) if p is not vca_failed_once else dt.date(2026, 3, 31))
                self.attend(group, e)
                self.complete(e, group["_sessions"][-1]["_end"])
                self.credential(Issue(p, "VCA-B", e["_done"], "manual"), ("enrolmentId", e))

    def add_nen_3140(self) -> None:
        """NEN 3140 for the technicians."""
        nen_groups = [self.training_group(GroupSpec("NEN-H", f"NEN 3140 herinstructie, groep {i}", ("haven", "praktijk"), TRAINER_TECH),
                                          Schedule([day], ((8, 30), (12, 30))))
                      for i, day in enumerate([dt.date(2025, 10, 2), dt.date(2026, 1, 15), dt.date(2026, 4, 9), dt.date(2026, 6, 11)], start=1)]
        nen_basis = [self.training_group(GroupSpec("NEN-B", f"NEN 3140 basis, groep {i}", ("haven", "praktijk"), TRAINER_TECH), Schedule([day]))
                     for i, day in enumerate([dt.date(2025, 11, 20), dt.date(2026, 5, 21)], start=1)]
        for dept in TECHNICIANS:
            for p in self.members[dept]:
                if self.existing(p):
                    day = dt.date(2025, 3, 3) if p["nc"] == TRAINER_TECH else self.pick_day(dt.date(2022, 8, 18), dt.date(2025, 7, 10))
                    issued = moment(day, 12, 30)
                    expiry = add_months(issued, 36)
                    if expiry.date() > LAST_DAY:
                        self.credential(Issue(p, "NEN-B", issued, "migrated"))
                        continue
                    group = self.first_group(nen_groups, expiry.date() + dt.timedelta(days=1))
                    e = self.enrol(p, "NEN-H", "credential-renewal", group or self.cohorts[dept])
                    self.credential(Issue(p, "NEN-B", issued, "migrated"), ("renewalEnrolmentId", e))
                    if group is None:
                        continue
                    self.attend(group, e)
                    self.complete(e, group["_sessions"][-1]["_end"])
                    self.credential(Issue(p, "NEN-H", e["_done"], "manual"), ("enrolmentId", e))
                    continue
                group = self.first_group(nen_basis, p["start"] + dt.timedelta(days=7))
                e = self.enrol(p, "NEN-B", "hr", group or self.cohorts[dept], p["start"] + dt.timedelta(days=90))
                if group is None:
                    continue
                self.attend(group, e)
                self.complete(e, group["_sessions"][-1]["_end"])
                self.credential(Issue(p, "NEN-B", e["_done"], "manual"), ("enrolmentId", e))

    def add_heftruck(self) -> None:
        """Heftruck for the warehouse."""
        forklift_renew = [self.training_group(GroupSpec("HEF-H", f"Heftruck herhaling, groep {i}", ("haven", "kantine"), None),
                                              Schedule([day], ((8, 30), (12, 30)), notes="Externe instructeur van Voorbeeld Heftruckopleidingen."))
                          for i, day in enumerate([dt.date(2026, 1, 22), dt.date(2026, 6, 4)], start=1)]
        forklift_basis = [self.training_group(GroupSpec("HEF-B", f"Heftruckcertificaat basis, groep {i}", ("haven", "kantine"), None),
                                              Schedule([day], notes="Externe instructeur van Voorbeeld Heftruckopleidingen."))
                          for i, day in enumerate([dt.date(2025, 10, 23), dt.date(2026, 5, 28)], start=1)]
        for p in self.members["magazijn"]:
            if self.existing(p):
                issued = moment(self.pick_day(dt.date(2020, 8, 18), dt.date(2025, 7, 10)), 16, 0)
                expiry = add_months(issued, 60)
                if expiry.date() > LAST_DAY:
                    self.credential(Issue(p, "HEF-B", issued, "migrated"))
                    continue
                group = self.first_group(forklift_renew, expiry.date() + dt.timedelta(days=1))
                e = self.enrol(p, "HEF-H", "credential-renewal", group or self.cohorts["magazijn"])
                self.credential(Issue(p, "HEF-B", issued, "migrated"), ("renewalEnrolmentId", e))
                if group is None:
                    continue
                self.attend(group, e)
                self.complete(e, group["_sessions"][-1]["_end"])
                self.credential(Issue(p, "HEF-H", e["_done"], "manual"), ("enrolmentId", e))
                continue
            group = self.first_group(forklift_basis, p["start"] + dt.timedelta(days=7))
            e = self.enrol(p, "HEF-B", "hr", group, p["start"] + dt.timedelta(days=60))
            self.attend(group, e)
            self.complete(e, group["_sessions"][-1]["_end"])
            self.credential(Issue(p, "HEF-B", e["_done"], "manual"), ("enrolmentId", e))

    def add_f_gassen(self) -> None:
        """F-gassen, warmtepompen, and the technician leerlijn."""
        self.installers = self.plain_existing("installatie")
        fgas_holders = self.rng.sample(self.installers[9:], 14)
        fgas_ids = {p["nc"] for p in fgas_holders}
        for p in fgas_holders:
            self.credential(Issue(p, "FGAS", moment(self.pick_day(dt.date(2021, 9, 1), dt.date(2025, 6, 30)), 16, 0), "migrated"))
        fgas_new = self.rng.sample([p for p in self.installers if p["nc"] not in fgas_ids], 6)
        fgas_group = self.training_group(GroupSpec("FGAS", "F-gassen certificering, november", ("haven", "praktijk"), None),
                                         Schedule([dt.date(2025, 11, 25), dt.date(2025, 11, 26)],
                                                  notes="Externe docent en examen door Voorbeeld Examencentrum Koudetechniek."))
        for p in fgas_new:
            e = self.enrol(p, "FGAS", "manager", fgas_group)
            self.attend(fgas_group, e)
            self.complete(e, fgas_group["_sessions"][-1]["_end"])
            self.credential(Issue(p, "FGAS", e["_done"], "manual"), ("enrolmentId", e))
        self.heat_pump = self.rng.sample(self.installers, 12)
        wp_groups = [
            self.training_group(GroupSpec("WP", "Praktijkcursus warmtepompen, januari", ("haven", "praktijk"), None),
                                Schedule([dt.date(2026, 1, 27), dt.date(2026, 1, 28)],
                                         notes="Externe cursus van Voorbeeld Warmtepompacademie, in onze eigen praktijkhal.")),
            self.training_group(GroupSpec("WP", "Praktijkcursus warmtepompen, april", ("haven", "praktijk"), None),
                                Schedule([dt.date(2026, 4, 14), dt.date(2026, 4, 15)],
                                         notes="Externe cursus van Voorbeeld Warmtepompacademie, in onze eigen praktijkhal.")),
        ]
        self.paid: list[tuple[dict, str, dt.date]] = []  # (enrolment, fee key, order day)
        for index, p in enumerate(self.heat_pump):
            group = wp_groups[0] if index < 6 else wp_groups[1]
            e = self.enrol(p, "WP", "manager", group)
            self.attend(group, e)
            self.complete(e, group["_sessions"][-1]["_end"])
            self.credential(Issue(p, "WP", e["_done"], "manual"), ("enrolmentId", e))
            self.paid.append((e, "WP", dt.date(2026, 1, 5) if index < 6 else dt.date(2026, 3, 23)))
        for p in self.members["installatie"]:
            self.enrol(p, "LL-SM", "manager", self.cohorts["installatie"])

    def add_leadership_and_paid_courses(self) -> None:
        """Leadership, customer contact, paid courses."""
        self.leaders = [self.members[d][0] for d in [d[0] for d in DEPARTMENTS] if d != "directie"]
        lei = self.training_group(GroupSpec("LEI", "Coachend leidinggeven", ("hoofd", "vergader"), None),
                                  Schedule([dt.date(2025, 10, 9), dt.date(2025, 12, 4), dt.date(2026, 2, 5)], ((13, 0), (17, 0)),
                                           notes="Drie middagen met een externe coach."))
        for p in self.leaders:
            e = self.enrol(p, "LEI", "hr", lei)
            self.attend(lei, e, absent=(1,) if p["nc"] == "corporate-manager-03" else ())
            self.complete(e, lei["_sessions"][-1]["_end"])

        sales = [p for p in self.members["verkoop"] if p["roles"] == ["learner"] and (self.existing(p) or p["start"] <= dt.date(2026, 1, 12))]
        kla_groups = [
            self.training_group(GroupSpec("KLA", "Klantgericht communiceren, groep 1", ("hoofd", "training"), None),
                                Schedule([dt.date(2025, 11, 13)], ((9, 0), (16, 0)))),
            self.training_group(GroupSpec("KLA", "Klantgericht communiceren, groep 2", ("hoofd", "training"), None),
                                Schedule([dt.date(2026, 1, 22)], ((9, 0), (16, 0)))),
        ]
        for index, p in enumerate(sales):
            group = kla_groups[0] if index < len(sales) // 2 and self.existing(p) else kla_groups[1]
            e = self.enrol(p, "KLA", "manager", group)
            day = group["_sessions"][0]["_day"]
            prep = self.pick_day(day - dt.timedelta(days=10), day - dt.timedelta(days=1))
            when = moment(prep, *self.clock())
            self.b.add("lesson-completion", {"learnerId": p["nc"], "learnerRef": p["profile"]["uuid"], "lessonId": self.lessons["KLA"][0]["uuid"],
                                             "courseId": self.courses["KLA"]["uuid"], "enrolmentId": e["uuid"], "source": "xapi",
                                             "verb": VERB_COMPLETED, "completedAt": iso(when)})
            e["progressPercent"] = 100
            self.attend(group, e)
            self.complete(e, group["_sessions"][-1]["_end"])

        spreadsheet = self.plain_existing("financien")[:4] + self.plain_existing("planning")[-2:] + self.plain_existing("ict")[:1] + self.plain_existing("verkoop")[-1:]
        order_days = [dt.date(2025, 10, 6), dt.date(2025, 10, 20), dt.date(2025, 11, 10), dt.date(2025, 12, 1),
                      dt.date(2026, 1, 19), dt.date(2026, 2, 9), dt.date(2026, 3, 9), dt.date(2026, 6, 29)]
        for index, (p, day) in enumerate(zip(spreadsheet, order_days)):
            e = self.enrol(p, "EXC", "self", self.cohorts[p["dept"]])
            self.paid.append((e, "EXC", day))
            if index == 7:
                e["lifecycle"] = "pending"
                e["progressPercent"] = 0
                continue
            start = plus_workdays(day, 2)
            if index == 5:
                self.elearn(e, start, None, partial=2)
            else:
                self.elearn(e, start, self.pick_day(plus_workdays(start, 10), plus_workdays(start, 40)))

        project = self.plain_existing("planning")[1:4] + self.plain_existing("ict")[-2:]
        prj = self.training_group(GroupSpec("PRJ", "Projectmatig werken, maart", ("hoofd", "vergader"), None),
                                  Schedule([dt.date(2026, 3, 3), dt.date(2026, 3, 10), dt.date(2026, 3, 17)], ((9, 0), (16, 0)),
                                           notes="Externe trainer en examen van Voorbeeld Projectacademie."))
        for index, p in enumerate(project):
            e = self.enrol(p, "PRJ", "manager", prj)
            self.paid.append((e, "PRJ", dt.date(2026, 2, 9)))
            if index == 4:
                e["lifecycle"] = "withdrawn"
                e["reason"] = "Afgemeld wegens projectdrukte; volgend jaar opnieuw."
                continue
            self.attend(prj, e)
            self.complete(e, prj["_sessions"][-1]["_end"])
            self.credential(Issue(p, "PRJ", e["_done"], "manual"), ("enrolmentId", e))

    def add_toolbox_meetings(self) -> None:
        """Toolbox meetings: sessions every month, marks only for exceptions."""
        for dept, room, begin, finish in TOOLBOX:
            head = self.members[dept][0]
            for month_index, (year, month) in enumerate([(2025, 9), (2025, 10), (2025, 11), (2025, 12), (2026, 1),
                                                         (2026, 2), (2026, 3), (2026, 4), (2026, 5), (2026, 6)]):
                day = first_tuesday(year, month)
                s = self.b.add("session", {
                    "cohortId": self.cohorts[dept]["uuid"], "title": f"Toolboxmeeting {self.dept_of[dept][1].lower()}, {MAAND[month - 1]} {year}: {TOOLBOX_TOPICS[month_index]}",
                    "startsAt": iso(moment(day, *begin)), "endsAt": iso(moment(day, *finish)),
                    "location": self.rooms[room]["name"], "roomId": self.rooms[room]["uuid"], "lifecycle": "completed",
                })
                for p in self.members[dept][1:]:
                    if not self.existing(p) and p["start"] > day:
                        continue
                    roll = self.rng.random()
                    if roll < 0.07:
                        status, minutes, reason = "absent-excused", 0, self.rng.choice(["Verlof", "Ziek gemeld", "Vrije dag"])
                    elif roll < 0.08:
                        status, minutes, reason = "absent-unexcused", 0, "Niet afgemeld"
                    elif roll < 0.11:
                        late = self.rng.choice([5, 10])
                        status, minutes, reason = "late", 30 - late, "Te laat door file"
                    else:
                        continue
                    self.b.add("attendance-record", {
                        "sessionId": s["uuid"], "learnerId": p["nc"], "learnerRef": p["profile"]["uuid"], "cohortId": self.cohorts[dept]["uuid"],
                        "status": status, "minutesAttended": minutes, "markedBy": head["nc"], "markedAt": iso(moment(day, *finish) + dt.timedelta(minutes=5)), "reason": reason,
                    })

    def external(self, p: dict, record: ExternalRecord) -> None:
        title, provider, kind, day = record.activity.title, record.activity.provider, record.activity.kind, record.activity.day
        submitted_by, verified_by, evidence, lifecycle = (record.review.submitted_by, record.review.verified_by,
                                                          record.review.evidence, record.review.lifecycle)
        regulation, valid_months, batch, rejection = (record.extras.regulation, record.extras.valid_months,
                                                      record.extras.batch, record.extras.rejection)
        completed = moment(day, 17, 0)
        # learnerId is the LearnerProfile uuid; learnerUserId its Nextcloud user id, the read rule's key.
        fields = {"learnerId": p["profile"]["uuid"], "learnerUserId": p["profile"]["ncUserId"], "learnerRef": p["profile"]["uuid"],
                  "title": title, "provider": provider, "kind": kind}
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
        self.b.add("external-training-record", fields)

    def add_external_records(self) -> None:
        """External training records (the promoted NIS2 seed row first)."""
        board = [self.by_nc[DIRECTOR]] + self.leaders + [self.by_nc[COMPLIANCE]]
        nis2 = ExternalRecord(Activity("NIS2 board awareness session", "Voorbeeld SecureBoard B.V.", "classroom", dt.date(2026, 3, 10)),
                              Review(COMPLIANCE, COMPLIANCE, "Getekende presentielijst bijgevoegd"),
                              RecordExtras(regulation="NIS2", valid_months=12, batch="nis2-bestuur-2026-03"))
        for p in board:
            self.external(p, nis2)
        fair = [self.members["installatie"][0]] + self.rng.sample(self.installers, 5)
        trade_fair = ExternalRecord(Activity("Vakbeurs installatietechniek", "Voorbeeld Beursorganisatie", "conference", dt.date(2025, 9, 25)),
                                    Review(self.members["installatie"][0]["nc"], COORDINATOR, "Toegangsbewijs en bezoekbevestiging"),
                                    RecordExtras(batch="vakbeurs-2025"))
        for p in fair:
            self.external(p, trade_fair)
        congress = Activity("Congres duurzame inzetbaarheid", "Voorbeeld Congresbureau", "conference", dt.date(2025, 11, 20))
        for nc in (HR, COORDINATOR):
            self.external(self.by_nc[nc], ExternalRecord(congress, Review(nc, DIRECTOR, "Deelnamebevestiging van de organisator")))
        self.external(self.by_nc[COMPLIANCE], ExternalRecord(
            Activity("Webinar AVG-actualiteiten voor privacy officers", "Voorbeeld Privacyacademie", "external-elearning", dt.date(2026, 1, 28)),
            Review(COMPLIANCE, HR, "Certificaat van deelname"), RecordExtras(regulation="AVG", valid_months=24)))
        cloud = ExternalRecord(Activity("Praktijkcursus cloudbeheer", "Voorbeeld IT-opleidingen", "classroom", dt.date(2026, 2, 12)),
                               Review(self.members["ict"][0]["nc"], COORDINATOR, "Certificaat van deelname"),
                               RecordExtras(batch="cloudbeheer-2026-02"))
        for p in self.plain_existing("ict")[1:3]:
            self.external(p, cloud)
        driving = ExternalRecord(Activity("Rijvaardigheidstraining bestelbus", "Voorbeeld Rijopleidingen Esdoornhaven", "other", dt.date(2026, 5, 12)),
                                 Review(COORDINATOR, COORDINATOR, "Presentielijst van de rijschool"), RecordExtras(batch="rijvaardigheid-2026-05"))
        for p in self.rng.sample(self.installers, 6):
            self.external(p, driving)
        factory_day = ExternalRecord(Activity("Meeloopdag bij een warmtepompfabrikant", "Voorbeeld Warmtepompfabriek", "on-the-job", dt.date(2026, 3, 19)),
                                     Review(self.members["installatie"][0]["nc"], COORDINATOR, "Verslag van de meeloopdag"),
                                     RecordExtras(batch="meeloopdag-2026-03"))
        for p in self.heat_pump[:3]:
            self.external(p, factory_day)
        rejected = self.plain_existing("verkoop")[2]
        self.external(rejected, ExternalRecord(
            Activity("Online cursus timemanagement", "Voorbeeld Online Leerplatform", "external-elearning", dt.date(2026, 2, 26)),
            Review(rejected["nc"], None, None, "rejected"), RecordExtras(rejection="Er is geen certificaat of deelnamebewijs aangeleverd.")))
        waiting = self.plain_existing("financien")[5]
        self.external(waiting, ExternalRecord(
            Activity("Workshop werken met macro's", "Voorbeeld Rekenacademie", "external-elearning", dt.date(2026, 6, 25)),
            Review(waiting["nc"], None, "Deelnamebewijs volgt per mail", "submitted")))

    def valid_at_year_end(self, p: dict, keys: tuple[str, ...]) -> dict | None:
        for cred in self.b.buckets["credential"]:
            if cred["_p"] is p and cred["_key"] in keys and (cred["_expires"] is None or cred["_expires"].date() > LAST_DAY):
                return cred
        return None

    def attain(self, p: dict, code: str, level: str, when: dt.datetime) -> None:
        self.b.add("competency-attainment", {"learnerId": p["nc"], "learnerRef": p["profile"]["uuid"], "competencyId": self.comp[code]["uuid"],
                                             "frameworkId": self.framework["uuid"], "proficiencyLevelId": level, "gradeEntryIds": [],
                                             "assessmentResultIds": [], "werkprocesAssessmentIds": [], "submissionIds": [],
                                             "lastRecomputedAt": iso(when)})

    def level_for(self, score: int) -> str:
        return "expert" if score >= 85 else "gevorderd" if score >= 65 else "basis"

    def add_attainments(self) -> None:
        """Competency attainments: what the skills gap dashboard reads."""
        review = moment(dt.date(2025, 9, 15), 10, 0)
        for e in self.ibv_enrolments:
            if e["lifecycle"] == "completed":
                self.attain(e["_p"], "DIG-01", self.level_for(e["_score"]), e["_done"])
        for e in self.b.buckets["enrolment"]:
            if e["_key"] == "AVG" and e["lifecycle"] == "completed":
                self.attain(e["_p"], "DIG-02", self.level_for(e["_score"]), e["_done"])
        for dept in TECHNICIANS:
            for p in self.members[dept]:
                vca = self.valid_at_year_end(p, ("VCA-B", "VCA-V"))
                if vca is not None:
                    self.attain(p, "VEI-01", "gevorderd" if vca["_key"] == "VCA-V" else "basis", dt.datetime.fromisoformat(vca["issuedAt"]))
                nen = self.valid_at_year_end(p, ("NEN-B", "NEN-H"))
                if nen is not None:
                    self.attain(p, "VEI-03", "expert" if p["nc"] == TRAINER_TECH else "basis", dt.datetime.fromisoformat(nen["issuedAt"]))
                if "WP" in p["done"]:
                    self.attain(p, "VAK-01", "gevorderd", p["done"]["WP"])
                elif self.existing(p) and dept == "installatie" and self.rng.random() < 0.45:
                    self.attain(p, "VAK-01", self.rng.choice(["gevorderd", "expert"]), review)
                fgas = self.valid_at_year_end(p, ("FGAS",))
                if fgas is not None:
                    self.attain(p, "VAK-02", "gevorderd", dt.datetime.fromisoformat(fgas["issuedAt"]))
                if self.existing(p) and self.rng.random() < 0.8:
                    self.attain(p, "VAK-03", self.rng.choice(["basis", "gevorderd", "gevorderd", "expert"]), review)
        for index, p in enumerate(self.leaders):
            self.attain(p, "LEI-01", "gevorderd" if index % 3 == 0 else "basis", p["done"]["LEI"])
            if index < 4:
                self.attain(p, "LEI-02", "basis", p["done"]["LEI"])

    def add_development_plans(self) -> None:
        """Personal development plans and their mid-year review."""
        attained = {}
        for row in self.b.buckets["competency-attainment"]:
            attained.setdefault(row["learnerId"], set()).add(row["competencyId"])
        code_of = {c["uuid"]: code for code, c in self.comp.items()}
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
        for e in self.b.buckets["enrolment"]:
            enrolments_of.setdefault(e["learnerId"], []).append(e)
        for p in self.people:
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
                measures.append({"measureId": f"maatregel-{len(measures) + 1}", "description": f"Tijd en opleidingsbudget voor {self.courses[e['_key']]['name']}",
                                 "responsibleId": coordinator, "startDate": created.isoformat(), "endDate": "2026-06-30"})
                outcomes_by_goal.append((goal_id, e))
            required = set()
            for e in enrolments_of[p["nc"]]:
                for programme in (self.technician_programme, self.leadership_programme):
                    if programme["uuid"] in self.courses[e["_key"]].get("programmeIds", []):
                        required |= set(programme["requiredCompetencyIds"])
            required |= {c["uuid"] for c in self.comp.values() if set(c["requiredForRoles"]) & set(p["roles"])}
            for uuid in sorted(required - attained.get(p["nc"], set()), key=lambda u: code_of[u]):
                goal_id = f"doel-{len(goals) + 1}"
                goals.append({"goalId": goal_id, "description": f"{self.comp[code_of[uuid]]['title']}: aan de slag in het volgende opleidingsjaar",
                              "domain": {"LEI": "leidinggeven", "DIG": "digitaal werken", "VEI": "veiligheid"}.get(code_of[uuid][:3], "vakmanschap"),
                              "targetDate": "2026-12-31", "status": "open"})
                outcomes_by_goal.append((goal_id, None))
            if not goals:
                domain, text = generic[p["dept"]]
                goals.append({"goalId": "doel-1", "description": text, "domain": domain, "targetDate": "2026-06-30",
                              "status": "met" if self.rng.random() < 0.6 else "open"})
                outcomes_by_goal.append(("doel-1", None))
            plan = self.b.add("learning-plan", {
                "learnerId": p["nc"], "kind": "pdp", "templateId": self.template["uuid"], "cohortId": self.cohorts[p["dept"]]["uuid"],
                "coordinatorId": coordinator, "goals": goals, "supportMeasures": measures, "period": YEAR,
                "reviewCadenceMonths": 6, "nextReviewAt": "2026-09-30", "version": 1, "lifecycle": "active",
            })
            if not self.existing(p) and p["start"] > dt.date(2026, 1, 12):
                continue
            if self.rng.random() > 0.7:
                continue
            evaluated = self.pick_day(dt.date(2026, 2, 2), dt.date(2026, 3, 27))
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
            self.b.add("learning-plan-evaluation", {
                "learningPlanId": plan["uuid"], "evaluatedAt": evaluated.isoformat(), "evaluatedBy": coordinator, "goalOutcomes": outcomes,
                "narrative": self.rng.choice([
                    "Goed gesprek over de voortgang. De afspraken blijven staan.",
                    "Tussenstand besproken; de planning voor het voorjaar is concreet gemaakt.",
                    "Voortgang is op koers. We kijken in september samen terug op het hele jaar.",
                ]),
                "attendeeIds": [p["nc"], coordinator], "nextReviewAt": "2026-09-30", "lifecycle": "recorded",
            })

    def add_engagement(self) -> None:
        """Engagement in the informatiebeveiliging e-learning."""
        threshold = self.b.add("engagement-risk-threshold", {
            "name": "Dertig dagen geen activiteit in een verplichte e-learning", "kind": "low-engagement", "scope": "per-learner",
            "metric": "recency-days-above", "limit": 30,
            "onAtRisk": {"notify": True, "notifyRoles": ["manager", "compliance-officer"], "createFlag": True}, "lifecycle": "active",
        })
        total_minutes = sum(lesson["durationMinutes"] for lesson in self.lessons["IBV"])
        for e in self.ibv_enrolments:
            times = e.get("_times", [])
            if not times:
                continue
            spent = round(sum(lesson["durationMinutes"] for lesson, _when in times) * self.rng.uniform(0.85, 1.35), 1)
            gap = (times[-1][1] - times[-2][1]).days if len(times) > 1 else 0
            recency = 1 - min(1, gap / 14)
            score = max(0, min(100, round(min(1, spent / total_minutes) * 70 + recency * 30)))
            row = self.b.add("engagement-score", {"learnerId": e["learnerId"], "learnerRef": e["learnerRef"], "courseId": e["courseId"],
                                                  "timeOnTaskMinutes": spent, "lastActivityAt": iso(times[-1][1]), "score": score})
            if e["lifecycle"] != "completed":
                flagged = times[-1][1] + dt.timedelta(days=31)
                self.b.add("engagement-risk-flag", {"learnerId": e["learnerId"], "courseId": e["courseId"], "engagementRiskThresholdId": threshold["uuid"],
                                                    "engagementScoreId": row["uuid"], "metricValueAtFlag": 31, "flaggedAt": iso(flagged),
                                                    "lifecycle": "open" if e["learnerId"] == self.ibv_stuck[-3]["nc"] else "in-handling"})

    def add_gamification(self) -> None:
        """Points, levels, leaderboards."""
        rule = self.b.add("point-rule", {"name": "Training afgerond", "kind": "enrolment-completed", "points": 50, "active": True, "lifecycle": "active"})
        self.b.add("point-rule", {"name": "Zeven dagen achter elkaar leren", "kind": "streak-milestone", "points": 25, "milestoneDays": 7,
                                  "active": False, "lifecycle": "draft"})
        levels = [self.b.add("engagement-level", {"name": name, "order": order, "minPoints": points, "icon": icon})
                  for order, (name, points, icon) in enumerate([("Starter", 0, "FlagOutline"), ("Op weg", 100, "StarOutline"),
                                                               ("Gevorderd", 150, "MedalOutline"), ("Kampioen", 250, "TrophyOutline")], start=1)]
        awards: dict[str, list[dt.datetime]] = {}
        for e in self.b.buckets["enrolment"]:
            if e["lifecycle"] == "completed":
                self.b.add("point-award", {"learnerId": e["learnerId"], "pointRuleId": rule["uuid"], "points": 50, "sourceKind": "enrolment",
                                           "sourceObjectId": e["uuid"], "awardedAt": iso(e["_done"])})
                awards.setdefault(e["learnerId"], []).append(e["_done"])
        for p in self.people:
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
            self.b.add("learner-engagement", fields)
        self.b.add("leaderboard", {"name": "Leerkampioenen Esdoorn Techniek", "topN": 10, "lifecycle": "active"})
        self.b.add("leaderboard", {"name": "Leerkampioenen installatie en service", "cohortId": self.cohorts["installatie"]["uuid"], "topN": 5, "lifecycle": "active"})
        self.b.add("leaderboard", {"name": "Leerkampioenen verkoop en klantenservice", "cohortId": self.cohorts["verkoop"]["uuid"], "topN": 5, "lifecycle": "active"})

    def add_fees_and_entitlements(self) -> None:
        """Paid courses: fee items and entitlements; payment is shillinq's (D19)."""
        fees = {
            "WP": self.b.add("fee-item", {"name": "Praktijkcursus warmtepompen", "description": "Deelname aan de tweedaagse externe praktijkcursus.",
                                          "kind": "course-enrolment", "amount": 895.0, "currency": "EUR", "voluntary": False, "taxPosture": "standard-rate",
                                          "linkedCourseId": self.courses["WP"]["uuid"], "academicYear": YEAR, "validFrom": FIRST_DAY.isoformat(),
                                          "validUntil": LAST_DAY.isoformat(), "lifecycle": "active"}),
            "EXC": self.b.add("fee-item", {"name": "Licentie e-learning Excel gevorderd", "description": "Een jaar toegang tot de e-learning.",
                                           "kind": "course-enrolment", "amount": 149.0, "currency": "EUR", "voluntary": False, "taxPosture": "standard-rate",
                                           "linkedCourseId": self.courses["EXC"]["uuid"], "academicYear": YEAR, "validFrom": FIRST_DAY.isoformat(),
                                           "validUntil": LAST_DAY.isoformat(), "lifecycle": "active"}),
            "PRJ": self.b.add("fee-item", {"name": "Projectmatig werken, cursus met examen", "description": "Drie cursusdagen en het examen.",
                                           "kind": "course-enrolment", "amount": 695.0, "currency": "EUR", "voluntary": False, "taxPosture": "standard-rate",
                                           "linkedCourseId": self.courses["PRJ"]["uuid"], "academicYear": YEAR, "validFrom": FIRST_DAY.isoformat(),
                                           "validUntil": LAST_DAY.isoformat(), "lifecycle": "active"}),
        }
        for e, key, day in self.paid:
            fee, p = fees[key], e["_p"]
            if e["lifecycle"] == "withdrawn":
                continue
            entitlement = {"feeItemId": fee["uuid"], "learnerId": p["nc"], "grantedResourceKind": "course-access",
                           "grantedResourceId": self.courses[key]["uuid"]}
            if e["lifecycle"] == "pending":
                self.b.add("entitlement", {**entitlement, "lifecycle": "pending"})
                continue
            paid_at = moment(plus_workdays(day, 1), 11, 4)
            self.b.add("entitlement", {**entitlement, "paymentSettledAt": iso(paid_at), "grantedAt": iso(paid_at), "lifecycle": "active"})

    def strip_private_fields(self) -> None:
        """Drops the private working fields every object carried while the set was built."""
        for rows in self.b.buckets.values():
            for row in rows:
                for key in [k for k in row if k.startswith("_")]:
                    del row[key]

    # Regulation's own slug is its code (^[A-Z0-9_-]+$), and the contract takes the slug from the
    # object for such a schema. AVG is left out: learniq_register.json seeds it, and a second row
    # with the same code would be a duplicate. Each audience follows who this script trains, so the
    # Compliance overview counts what the data shows (example-set-regulation-rows, design).
    def add_regulations(self) -> None:
        for row in REGULATIONS:
            code, name, description, criteria, scope, renewal, annual, *audience = row
            fields = {"slug": code, "name": name, "description": description, "applicabilityCriteria": criteria,
                      "audienceScope": scope}
            for named in audience:
                fields.update(named)
            fields["requiresAnnualRenewal"] = annual
            if renewal is not None:
                fields["renewalCycleMonths"] = renewal
            fields.update({"active": True, "ragRedThreshold": 70, "ragAmberThreshold": 90, "lifecycle": "published"})
            self.b.add("regulation", fields)

    def assemble(self) -> dict:
        """The regulations the courses, credentials and records point at (D29), then the profile file's content."""
        self.add_regulations()

        shipped = {r["slug"] for r in self.b.buckets["regulation"]}
        for rows in self.b.buckets.values():
            for row in rows:
                code = row.get("regulationSlug")
                if code is not None and code != "AVG" and code not in shipped:
                    raise RuntimeError(f"{row['slug']} points at regulation {code}, which this set does not ship")

        objects = {name: rows for name, rows in self.b.buckets.items() if rows}
        total = sum(len(rows) for rows in objects.values())
        return {
            "openapi": "3.0.0",
            "info": {
                "title": "Learniq example set: Company",
                "version": "1.1.0",
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
                    "contract is openspec/changes/archive/2026-09-28-segment-wizard-choice/contract.md. Every person, address, company and certificate in it is fictional."
                ),
                "seedData": {
                    "description": (
                        "One company with a head office and a workshop and warehouse site, eight departments as cohorts, 200 employees with "
                        "their managers, HR, compliance and training staff, compliance e-learning and classroom certification with the "
                        "credentials they issue and the renewals their expiry opens, toolbox meetings with their attendance, external training "
                        "records, a competency framework with the gaps it shows, a development plan per employee, engagement scores, points "
                        "and leaderboards, orders with entitlements for three paid courses, and the regulations the courses answer to."
                    ),
                    "objects": objects,
                },
            },
            "paths": {},
            "components": {},
        }


if __name__ == "__main__":
    sys.exit(main(Script(SET, __doc__, build)))
