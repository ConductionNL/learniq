#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
# Copyright (C) 2026 Conduction B.V.
"""Build lib/Settings/profiles/mbo.json, the vocational college (MBO) example set.

One fictional college, Voorbeeldcollege Vaartveld in the fictional town of
Vaartdam, through one complete school year (2025-2026): two locations, three
programmes (Logistiek medewerker niveau 2, Verzorgende IG niveau 3, Software
developer niveau 4) with a kwalificatiedossier-style framework each, eight
classes (one per programme and leerjaar), 250 students, staff with a
studieloopbaanbegeleider per class, a timetable of school days around the work
placement days, the absences a college records, unit results with resits and
final grades, work placements (BPV) with praktijkopleiders, signed
praktijkovereenkomsten, visit reports and werkproces assessments, the
first-year study advice (flags, warnings, a decision per first-year student),
exam board cases and a stagecoordinator on Staff.

WHY A SCRIPT. The objects must agree with each other: a session only falls on a
school day of its class (never on a placement day), an absence is marked by the
teacher of that session's unit, a final grade is what the grade engine computes
from the published entries, a first-year decision counts exactly the credits of
the units that student passed, and a negative decision always follows an issued
warning. The script is deterministic (fixed seed), so running it again produces
the same file byte for byte, and a reviewer reads the rules here rather than
megabytes of JSON.

THE CONTRACT. openspec/changes/archive/2026-09-28-segment-wizard-choice/contract.md. Every rule is
checked by tests/Unit/Settings/ExampleSetDescriptorContractTest.php; the story
by tests/Unit/Settings/VocationalCollegeExampleSetTest.php.

Usage:
    python3 scripts/example-sets/mbo.py            write the file
    python3 scripts/example-sets/mbo.py --check    exit 1 when the file on disk differs

Nothing here is real: no real college, BRIN, company, person, address or phone
number. Postcodes start with 0 and phone numbers with 06-0000, which the
Netherlands never issues; the BRIN 00X3 ends in a digit, which DUO never
assigns; crebo codes start with 9, outside the range of the SBB dossiers;
company e-mail addresses use the reserved .example domain.
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
OUT = os.path.join(ROOT, "lib", "Settings", "profiles", "mbo.json")
AMS = ZoneInfo("Europe/Amsterdam")
TENANT = "00000000-0000-4000-8000-000000000000"
SET = "mbo"
SET_NUMBER = "03"
YEAR = "2025-2026"
FIRST_DAY = dt.date(2025, 8, 18)
LAST_DAY = dt.date(2026, 7, 10)
S1_END = dt.date(2026, 1, 30)
S2_START = dt.date(2026, 2, 2)
INTERIM_CHECK = dt.date(2026, 2, 18)
DECISION_DAY = dt.date(2026, 7, 6)

# Schema number (the TTTT group of the uuid) per bucket, in load order.
# Parents come before children; removal runs in reverse.
SCHEMAS = [
    "school",
    "vestiging",
    "room",
    "competency-framework",
    "competency",
    "grade-scale",
    "programme",
    "course",
    "curriculum-plan",
    "cohort",
    "staff",
    "learner-profile",
    "enrolment",
    "subjectteacherassignment",
    "report-period",
    "praktijkopleider",
    "external-assessor",
    "portfolio",
    "portfolio-entry",
    "portfolio-share",
    "bpv-placement",
    "praktijkovereenkomst",
    "pok-signature",
    "bpv-visit-report",
    "werkproces-assessment",
    "session",
    "excuse-request",
    "attendance-record",
    "attendance-threshold",
    "attendance-flag",
    "exam-accommodation",
    "exemption-case",
    "grade-entry",
    "fraud-case",
    "final-grade",
    "bsa-trajectory",
    "bsa-progress-flag",
    "bsa-warning",
    "bsa-decision",
    "support-request",
    "dossier-note",
    "hour-plan",
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
    (dt.date(2025, 10, 1), "Studiedag teams"),
    (dt.date(2026, 3, 18), "Studiedag examinering"),
    (dt.date(2026, 6, 24), "Studiedag afsluiting schooljaar"),
]
SEMESTERS = [
    ("S1", "Semester 1", FIRST_DAY, S1_END),
    ("S2", "Semester 2", S2_START, LAST_DAY),
]
WEEKDAYS = ["monday", "tuesday", "wednesday", "thursday", "friday"]
MON, TUE, WED, THU, FRI = WEEKDAYS
DAG = ["maandag", "dinsdag", "woensdag", "donderdag", "vrijdag", "zaterdag", "zondag"]
MAAND = ["januari", "februari", "maart", "april", "mei", "juni", "juli", "augustus", "september", "oktober", "november", "december"]

BOYS = [
    "Daan", "Sem", "Luuk", "Bram", "Finn", "Milan", "Levi", "Noah", "Jesse", "Thijs", "Mees", "Julian", "Ruben", "Jayden",
    "Lars", "Thomas", "Tim", "Stijn", "Gijs", "Mohammed", "Yusuf", "Liam", "Kevin", "Dylan", "Rayan", "Joey", "Niels",
    "Koen", "Sven", "Niek", "Mats", "Tygo", "Ryan", "Senna", "Damian", "Omar", "Ilias", "Mika", "Tobias", "Dani",
]
GIRLS = [
    "Emma", "Julia", "Tess", "Sophie", "Sara", "Nora", "Yara", "Eva", "Lotte", "Noor", "Lieke", "Fleur", "Anna", "Nina",
    "Maud", "Isa", "Roos", "Fenna", "Lina", "Amira", "Jasmijn", "Floor", "Ilse", "Femke", "Esmee", "Luna", "Mirthe",
    "Sanne", "Iris", "Merel", "Demi", "Kimberly", "Naomi", "Chelsea", "Shanti", "Aya", "Imane", "Zoë", "Romy", "Jade",
]
ADULT_M = ["Mark", "Peter", "Jeroen", "Bas", "Martijn", "Erik", "Rick", "Dennis", "Tarik", "Hasan", "Joost", "Wouter", "Sander", "Arjen", "Pieter", "Karim", "Ronald", "Edwin"]
ADULT_F = ["Linda", "Esther", "Marloes", "Anouk", "Kim", "Fatima", "Laura", "Nicole", "Petra", "Judith", "Ingrid", "Samira", "Eline", "Mirjam", "Chantal", "Naima", "Monique", "Wendy"]
SURNAME_HEAD = ["Vaart", "Sluis", "Kade", "Polder", "Kreek", "Haven", "Brug", "Wetering", "Schor", "Plas", "Grift", "Kolk", "Waard", "Zijl", "Tocht", "Rietland", "Veer", "Stroom", "Gors", "Ley"]
SURNAME_TAIL = ["zicht", "oord", "werf", "rand", "stee", "hoeve", "wijde", "lint", "kant", "hoek", "velde", "dijk", "burg", "weer"]
STREETS = ["Kadestraat", "Sluisweg", "Havenkade", "Brugstraat", "Polderlaan", "Dijkgraafpad", "Weteringpad", "Schippersweg", "Lichtbaken", "Jaagpad", "Molenvliet", "Veerpont", "Kreekrug", "Zijlstroom"]
TOWNS = ["Vaartdam", "Vaartdam", "Vaartdam", "Vaartveld", "Oosterkade"]

LOCATIONS = {
    "centrum": ("Locatie Centrum", "00X300", None, "Stationsplein 4", "0510 VD"),
    "techniekpark": ("Locatie Techniekpark", "00X301", "00X301-A", "Werkplaatsweg 10", "0514 TP"),
}

# Programme key => name, niveau, years, crebo (fictional), location, framework name.
PROGRAMMES = {
    "LOG": ("Logistiek medewerker", 2, 2, "90201", "techniekpark", "Kwalificatiedossier Logistiek (voorbeeld)"),
    "VIG": ("Verzorgende IG", 3, 3, "90302", "centrum", "Kwalificatiedossier Verzorgende IG (voorbeeld)"),
    "SD": ("Software developer", 4, 3, "90403", "techniekpark", "Kwalificatiedossier Software developer (voorbeeld)"),
}

# Kerntaken and werkprocessen per framework, in the SBB coding style.
FRAMEWORKS = {
    "LOG": [
        ("B1-K1", "Ontvangt en slaat goederen op", [
            "Ontvangt en controleert goederen", "Slaat goederen op", "Houdt de voorraad bij"]),
        ("B1-K2", "Verzamelt en verzendt goederen", [
            "Verzamelt goederen voor een order", "Maakt goederen verzendklaar", "Laadt goederen en regelt de verzending"]),
    ],
    "VIG": [
        ("B1-K1", "Biedt zorg en ondersteuning", [
            "Stelt het zorgplan op en bespreekt het", "Voert zorgtaken uit", "Ondersteunt bij dagelijkse activiteiten",
            "Handelt bij onvoorziene en crisissituaties"]),
        ("B1-K2", "Werkt aan de eigen professionaliteit en de kwaliteit van zorg", [
            "Werkt samen met naasten en andere zorgverleners", "Werkt aan de eigen deskundigheid", "Draagt bij aan de kwaliteit van zorg"]),
        ("P1-K1", "Voert verpleegtechnische handelingen uit", [
            "Bereidt verpleegtechnische handelingen voor", "Voert verpleegtechnische handelingen uit en rapporteert"]),
    ],
    "SD": [
        ("B1-K1", "Realiseert software", [
            "Plant het werk en bewaakt de voortgang", "Ontwerpt software", "Realiseert software", "Test software"]),
        ("B1-K2", "Werkt in een ontwikkelteam", [
            "Overlegt met het team en de opdrachtgever", "Presenteert het opgeleverde werk", "Reflecteert op het werk"]),
    ],
}

# Units (onderwijseenheden): code, programme, leerjaar, semester (S1, S2 or J for
# the whole year), name, credits, teachers, kerntaken, extra components.
UNITS = [
    ("LOG-1.1", "LOG", 1, "S1", "Magazijn, veiligheid en ergonomie", 15, ["mbo-docent-01"], ["B1-K1"], None),
    ("LOG-1.2", "LOG", 1, "S2", "Goederen ontvangen en opslaan", 20, ["mbo-docent-02"], ["B1-K1"], None),
    ("LOG-1.3", "LOG", 1, "J", "Taal, rekenen en burgerschap 1", 15, ["mbo-docent-11"], [], None),
    ("LOG-2.1", "LOG", 2, "S1", "Orders verzamelen en verzenden", 15, ["mbo-docent-01"], ["B1-K2"], None),
    ("LOG-2.2", "LOG", 2, "S2", "Voorraadbeheer en logistieke systemen", 15, ["mbo-docent-02"], ["B1-K1"], None),
    ("LOG-2.3", "LOG", 2, "J", "Taal, rekenen en burgerschap 2", 10, ["mbo-docent-11"], [], None),
    ("VIG-1.1", "VIG", 1, "S1", "Mens en gezondheid", 15, ["mbo-docent-03"], ["B1-K1"], None),
    ("VIG-1.2", "VIG", 1, "S2", "Basiszorg in het skillslab", 20, ["mbo-docent-04"], ["B1-K1"], None),
    ("VIG-1.3", "VIG", 1, "J", "Taal, rekenen en burgerschap 1", 15, ["mbo-docent-12"], [], None),
    ("VIG-2.1", "VIG", 2, "S1", "Zorgplan en methodisch werken", 15, ["mbo-docent-03"], ["B1-K1"], None),
    ("VIG-2.2", "VIG", 2, "S2", "Verpleegtechnische handelingen", 15, ["mbo-docent-04"], ["P1-K1"], None),
    ("VIG-2.3", "VIG", 2, "J", "Taal, rekenen en burgerschap 2", 10, ["mbo-docent-12"], [], None),
    ("VIG-3.1", "VIG", 3, "S1", "Complexe zorg en ziektebeelden", 10, ["mbo-docent-05"], ["B1-K1", "P1-K1"], None),
    ("VIG-3.2", "VIG", 3, "S2", "Professionaliteit en kwaliteit van zorg", 10, ["mbo-docent-06"], ["B1-K2"], None),
    ("VIG-3.3", "VIG", 3, "J", "Taal, rekenen en burgerschap 3", 10, ["mbo-docent-12"], [], None),
    ("SD-1.1", "SD", 1, "S1", "Programmeren", 15, ["mbo-docent-07"], ["B1-K1"], None),
    ("SD-1.2", "SD", 1, "S2", "Databases en webapplicaties", 20, ["mbo-docent-08"], ["B1-K1"], None),
    ("SD-1.3", "SD", 1, "J", "Taal, rekenen en burgerschap 1", 15, ["mbo-docent-13"], [], None),
    ("SD-2.1", "SD", 2, "S1", "Werken in een ontwikkelteam", 20, ["mbo-docent-09"], ["B1-K2"], None),
    ("SD-2.3", "SD", 2, "J", "Taal, rekenen, Engels en burgerschap 2", 10, ["mbo-docent-13", "mbo-docent-14"], [], "ENG"),
    ("SD-3.2", "SD", 3, "S2", "Afstudeerproject", 20, ["mbo-docent-10"], ["B1-K1", "B1-K2"], None),
    ("SD-3.3", "SD", 3, "J", "Taal, rekenen, Engels en burgerschap 3", 10, ["mbo-docent-13", "mbo-docent-14"], [], "ENG"),
]
UNIT_OFFSET = {"LOG-1.1": 0.2, "LOG-1.2": -0.1, "VIG-1.1": -0.2, "VIG-1.2": 0.1, "SD-1.1": -0.1, "SD-1.2": -0.3, "SD-2.1": 0.2, "SD-3.2": 0.1}

# Work placement units: code, programme, leerjaar, name, credits, BPV-docent, kerntaken assessed.
BPV_UNITS = [
    ("LOG-BPV", "LOG", 2, "Beroepspraktijkvorming logistiek leerjaar 2", 20, "mbo-docent-02", ["B1-K1", "B1-K2"]),
    ("VIG-BPV2", "VIG", 2, "Beroepspraktijkvorming zorg leerjaar 2", 20, "mbo-docent-05", ["B1-K1"]),
    ("VIG-BPV3", "VIG", 3, "Beroepspraktijkvorming zorg leerjaar 3", 25, "mbo-docent-06", ["P1-K1", "B1-K2"]),
    ("SD-BPV2", "SD", 2, "Beroepspraktijkvorming software leerjaar 2", 20, "mbo-docent-09", ["B1-K2"]),
    ("SD-BPV3", "SD", 3, "Beroepspraktijkvorming software leerjaar 3", 20, "mbo-docent-10", ["B1-K1"]),
]

# Classes: name, programme, leerjaar, size, room, SLB (mentor), timetable per
# semester (weekday => unit), placement (unit, weekdays per semester, from, to).
COHORTS = [
    ("LOG2-1A", "LOG", 1, 28, ("Praktijkhal logistiek", "T0.02", "lab", ["stellingen", "heftruck-simulator"]), "mbo-docent-01",
     {"S1": {MON: "LOG-1.1", TUE: "LOG-1.3", WED: "LOG-1.1", THU: "LOG-1.3"},
      "S2": {MON: "LOG-1.2", TUE: "LOG-1.3", WED: "LOG-1.2", THU: "LOG-1.2"}}, None),
    ("LOG2-2A", "LOG", 2, 24, ("Lokaal T1.04", "T1.04", "classroom", ["digibord"]), "mbo-docent-02",
     {"S1": {MON: "LOG-2.1", TUE: "LOG-2.3"}, "S2": {MON: "LOG-2.2", TUE: "LOG-2.3"}},
     ("LOG-BPV", {"S1": [WED, THU, FRI], "S2": [WED, THU, FRI]}, dt.date(2025, 9, 3), dt.date(2026, 7, 3))),
    ("VIG3-1A", "VIG", 1, 34, ("Skillslab zorg", "C1.10", "lab", ["zorgbedden", "oefenpoppen"]), "mbo-docent-03",
     {"S1": {MON: "VIG-1.1", TUE: "VIG-1.3", WED: "VIG-1.1", THU: "VIG-1.1"},
      "S2": {MON: "VIG-1.2", TUE: "VIG-1.3", WED: "VIG-1.2", THU: "VIG-1.2"}}, None),
    ("VIG3-2A", "VIG", 2, 32, ("Lokaal C2.01", "C2.01", "classroom", ["digibord"]), "mbo-docent-05",
     {"S1": {MON: "VIG-2.1", TUE: "VIG-2.3"}, "S2": {MON: "VIG-2.2", TUE: "VIG-2.3"}},
     ("VIG-BPV2", {"S1": [WED, THU, FRI], "S2": [WED, THU, FRI]}, dt.date(2025, 9, 3), dt.date(2026, 7, 3))),
    ("VIG3-3A", "VIG", 3, 28, ("Lokaal C2.02", "C2.02", "classroom", ["digibord"]), "mbo-docent-06",
     {"S1": {MON: "VIG-3.1", TUE: "VIG-3.3"}, "S2": {MON: "VIG-3.2", TUE: "VIG-3.3"}},
     ("VIG-BPV3", {"S1": [WED, THU, FRI], "S2": [WED, THU, FRI]}, dt.date(2025, 9, 3), dt.date(2026, 6, 26))),
    ("SD4-1A", "SD", 1, 38, ("ICT-lab 1", "T2.01", "lab", ["werkplekken met twee schermen"]), "mbo-docent-07",
     {"S1": {MON: "SD-1.1", TUE: "SD-1.3", WED: "SD-1.1", THU: "SD-1.1"},
      "S2": {MON: "SD-1.2", TUE: "SD-1.3", WED: "SD-1.2", THU: "SD-1.2"}}, None),
    ("SD4-2A", "SD", 2, 34, ("ICT-lab 2", "T2.02", "lab", ["werkplekken met twee schermen"]), "mbo-docent-09",
     {"S1": {MON: "SD-2.1", TUE: "SD-2.3", WED: "SD-2.1", THU: "SD-2.1"}, "S2": {FRI: "SD-2.3"}},
     ("SD-BPV2", {"S2": [MON, TUE, WED, THU]}, dt.date(2026, 2, 2), dt.date(2026, 7, 2))),
    ("SD4-3A", "SD", 3, 32, ("Lokaal T1.05", "T1.05", "classroom", ["digibord"]), "mbo-docent-10",
     {"S1": {FRI: "SD-3.3"}, "S2": {MON: "SD-3.2", TUE: "SD-3.3", WED: "SD-3.2", THU: "SD-3.2"}},
     ("SD-BPV3", {"S1": [MON, TUE, WED, THU]}, dt.date(2025, 9, 1), dt.date(2026, 1, 29))),
]

# When each kerntaak of a placement is assessed: (unit, kerntaak) => window start.
ASSESSMENT_WINDOWS = {
    ("LOG-BPV", "B1-K1"): dt.date(2026, 1, 14),
    ("LOG-BPV", "B1-K2"): dt.date(2026, 6, 10),
    ("VIG-BPV2", "B1-K1"): dt.date(2026, 6, 3),
    ("VIG-BPV3", "P1-K1"): dt.date(2026, 1, 14),
    ("VIG-BPV3", "B1-K2"): dt.date(2026, 6, 3),
    ("SD-BPV2", "B1-K2"): dt.date(2026, 6, 15),
    ("SD-BPV3", "B1-K1"): dt.date(2026, 1, 12),
}

COMPANIES = {
    "LOG": [("Distributiecentrum Vaartdam", "distributiecentrum-vaartdam"), ("Groothandel Vaartkade", "groothandel-vaartkade"),
            ("Koeltransport Vaartdam", "koeltransport-vaartdam"), ("Bouwmaterialen Vaartveld", "bouwmaterialen-vaartveld"),
            ("Webwinkel Oosterkade", "webwinkel-oosterkade")],
    "VIG": [("Zorgcentrum De Vaartoever", "zorgcentrum-vaartoever"), ("Woonzorg Vaarthof", "woonzorg-vaarthof"),
            ("Thuiszorg Vaartdam", "thuiszorg-vaartdam"), ("Verpleeghuis De Sluiswachter", "verpleeghuis-sluiswachter"),
            ("Revalidatiecentrum Oosterkade", "revalidatie-oosterkade"), ("Woongroep Polderrand", "woongroep-polderrand"),
            ("Zorgboerderij Vaartveld", "zorgboerderij-vaartveld")],
    "SD": [("Softwarehuis Vaartdam", "softwarehuis-vaartdam"), ("Webbureau Vaartsluis", "webbureau-vaartsluis"),
           ("Appstudio Oosterkade", "appstudio-oosterkade"), ("Datakade Vaartdam", "datakade-vaartdam"),
           ("Automatisering Vaartveld", "automatisering-vaartveld"), ("ICT-afdeling Ziekenhuis Vaartdam", "ziekenhuis-vaartdam")],
}

# Staff: ncUserId => (display name, roles, qualifications). Working days follow
# from the timetable, placement visits and a fixed pattern for the others.
STAFF = {
    "mbo-teamleider-01": ("Hanneke Kadewerf", ["administrator"], ["teamleider opleidingen techniek"], WEEKDAYS),
    "mbo-teamleider-02": ("Erik Sluisoord", ["administrator"], ["teamleider opleidingen zorg"], WEEKDAYS),
    "mbo-stagecoordinator-01": ("Mirjam Polderrand", ["coordinator"], ["stagecoördinator", "BPV-coördinatie alle opleidingen"], [MON, TUE, WED, THU]),
    "mbo-examencommissie-01": ("Arjen Kreekstee", ["coordinator"], ["voorzitter examencommissie"], [MON, WED, THU]),
    "mbo-examencommissie-02": ("Samira Havenzicht", ["coordinator"], ["secretaris examencommissie"], [MON, TUE, THU]),
    "mbo-studentbegeleider-01": ("Judith Wetering", ["support-staff"], ["studentbegeleider", "zorgcoördinator"], [MON, TUE, WED, THU]),
    "mbo-verzuimcoordinator-01": ("Ronald Veerkant", ["support-staff"], ["verzuimcoördinator"], [MON, TUE, WED, THU, FRI]),
    "mbo-administratie-01": ("Wendy Grifthoek", ["administrator"], ["onderwijsadministratie"], [MON, TUE, THU, FRI]),
    "mbo-instructeur-01": ("Naima Zijlvelde", ["teaching-assistant"], ["instructeur skillslab zorg"], [MON, WED, THU]),
    "mbo-instructeur-02": ("Dennis Tochtburg", ["teaching-assistant"], ["instructeur praktijkhal logistiek", "heftruckcertificaat"], [MON, WED]),
    "mbo-docent-01": ("Bas Plasdijk", ["teacher", "mentor"], ["tweedegraads bevoegdheid logistiek"], []),
    "mbo-docent-02": ("Kim Vaartlint", ["teacher", "mentor"], ["tweedegraads bevoegdheid logistiek", "BPV-docent"], []),
    "mbo-docent-03": ("Esther Rietlandhoeve", ["teacher", "mentor"], ["tweedegraads bevoegdheid zorg en welzijn", "verpleegkundige"], []),
    "mbo-docent-04": ("Anouk Stroomwijde", ["teacher"], ["tweedegraads bevoegdheid zorg en welzijn", "verpleegkundige"], []),
    "mbo-docent-05": ("Petra Waardrand", ["teacher", "mentor"], ["tweedegraads bevoegdheid zorg en welzijn", "BPV-docent"], []),
    "mbo-docent-06": ("Hasan Gorszicht", ["teacher", "mentor"], ["tweedegraads bevoegdheid zorg en welzijn", "BPV-docent"], []),
    "mbo-docent-07": ("Martijn Leyoord", ["teacher", "mentor"], ["tweedegraads bevoegdheid informatica"], []),
    "mbo-docent-08": ("Laura Kolkwerf", ["teacher"], ["tweedegraads bevoegdheid informatica"], []),
    "mbo-docent-09": ("Tarik Brugstee", ["teacher", "mentor"], ["tweedegraads bevoegdheid informatica", "BPV-docent"], []),
    "mbo-docent-10": ("Ingrid Schorkant", ["teacher", "mentor"], ["tweedegraads bevoegdheid informatica", "BPV-docent"], []),
    "mbo-docent-11": ("Joost Veerhoek", ["teacher"], ["tweedegraads bevoegdheid Nederlands", "rekendocent"], []),
    "mbo-docent-12": ("Chantal Tochtvelde", ["teacher"], ["tweedegraads bevoegdheid Nederlands", "rekendocent"], []),
    "mbo-docent-13": ("Pieter Havenburg", ["teacher"], ["eerstegraads bevoegdheid Nederlands"], []),
    "mbo-docent-14": ("Eline Kadezicht", ["teacher"], ["tweedegraads bevoegdheid Engels"], []),
}
STAGECOORDINATOR = "mbo-stagecoordinator-01"
EXAM_CHAIR = "mbo-examencommissie-01"
EXAM_SECRETARY = "mbo-examencommissie-02"
STUDENT_COUNSELLOR = "mbo-studentbegeleider-01"
ATTENDANCE_OFFICER = "mbo-verzuimcoordinator-01"
TEAMLEADER = {"LOG": "mbo-teamleider-01", "SD": "mbo-teamleider-01", "VIG": "mbo-teamleider-02"}


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


def closed_days() -> set[dt.date]:
    off = set()
    for _name, start, end in HOLIDAYS:
        d = start
        while d <= end:
            off.add(d)
            d += dt.timedelta(days=1)
    return off | {d for d, _ in STUDY_DAYS}


CLOSED = closed_days()


def school_days() -> list[dt.date]:
    days = []
    d = FIRST_DAY
    while d <= LAST_DAY:
        if d.weekday() < 5 and d not in CLOSED:
            days.append(d)
        d += dt.timedelta(days=1)
    return days


def semester_of(day: dt.date) -> str:
    return "S1" if day <= S1_END else "S2"


def lesson_minutes(day: dt.date) -> int:
    """A school day runs 08:30 to 15:00 with a half-hour break; the Friday return day 09:00 to 13:00."""
    return 240 if day.weekday() == 4 else 360


def dutch_date(day: dt.date) -> str:
    return f"{DAG[day.weekday()]} {day.day} {MAAND[day.month - 1]} {day.year}"


def clamp_grade(value: float) -> float:
    return round(max(1.0, min(10.0, value)), 1)


def age_on(birth: dt.date, day: dt.date) -> int:
    return day.year - birth.year - ((day.month, day.day) < (birth.month, birth.day))


def evaluate_final(entries: list[dict], plan: dict, threshold: float) -> tuple[float | None, bool | None, dict]:
    """What GradeFormulaEvaluator computes for one learner and plan (lib/Grading).

    Published entries only; last-attempt keeps the newest entry per component;
    exemption entries are skipped; the value is the weighted average rounded to
    four decimals; passed needs the scale threshold and, for all-must-pass, an
    entry for every pass-rule component.
    """
    published = [e for e in entries if e["lifecycle"] == "published"]
    if not published:
        return None, None, {}
    weights = {c["componentId"]: c["weight"] for c in plan["components"]}
    if plan["formula"] == "last-attempt":
        newest: dict[str, dict] = {}
        for e in published:
            cid = e["componentId"]
            if cid not in newest or e["gradedAt"] > newest[cid]["gradedAt"]:
                newest[cid] = e
        effective = list(newest.values())
    else:
        effective = published
    total = weighted = 0.0
    periods: dict[str, list[float]] = {}
    components: dict[str, dict] = {}
    for e in effective:
        cid = e["componentId"]
        if e.get("sourceKind") == "exemption":
            components[cid] = {"exempt": True}
            continue
        weight = float(weights.get(cid, 1))
        value = float(e["value"])
        weighted += value * weight
        total += weight
        bucket = periods.setdefault(e.get("period", "unknown"), [0.0, 0.0])
        bucket[0] += value * weight
        bucket[1] += weight
        components[cid] = {"value": value, "weight": weight, "contribution": value * weight}
    if total == 0.0:
        return None, None, components
    value = round(weighted / total, 4)
    breakdown = {"periods": {p: round(s / w, 4) for p, (s, w) in periods.items()}, "components": components}
    passed = value >= threshold
    if passed and plan["formula"] == "all-must-pass" and plan.get("passRules"):
        present = {e["componentId"] for e in effective}
        passed = all(rule["componentId"] in present for rule in plan["passRules"])
    return value, passed, breakdown


def build() -> dict:
    rng = random.Random(20250818)
    b = Builder()
    days = school_days()

    # --- college, locations, rooms ------------------------------------------
    school = b.add("school", {"brin": "00X3", "name": "Voorbeeldcollege Vaartveld", "pedagogicalConcept": "regular"})
    locations = {}
    for key, (name, code, olc, street, postcode) in LOCATIONS.items():
        locations[key] = b.add("vestiging", {
            "schoolId": school["uuid"], "vestigingscode": code, "onderwijslocatiecode": olc, "name": name,
            "street": street, "postalCode": postcode, "city": "Vaartdam",
        })
    rooms = {}
    for cname, programme, *_rest in COHORTS:
        room_name, room_code, kind, facilities = _rest[2]
        loc = locations[PROGRAMMES[programme][4]]
        rooms[cname] = b.add("room", {
            "name": room_name, "code": room_code, "capacity": 40 if kind == "lab" else 36, "kind": kind,
            "facilities": facilities, "buildingCode": loc["vestigingscode"], "floor": room_code[1],
        })

    # --- competency frameworks: kerntaken and werkprocessen -----------------
    frameworks = {}
    competencies: dict[tuple[str, str], dict] = {}
    for key, (pname, niveau, _years, crebo, _loc, fname) in PROGRAMMES.items():
        frameworks[key] = b.add("competency-framework", {
            "name": fname, "sourceAuthority": "sbb-kwalificatiedossier", "sourceRef": crebo,
            "edition": "2025", "level": "mbo",
            "description": f"Kerntaken en werkprocessen van de opleiding {pname} (niveau {niveau}), in de codering van een kwalificatiedossier. Een verzonnen voorbeeld, geen officieel dossier.",
            "proficiencyLevels": [
                {"levelId": "nog-niet-competent", "label": "Nog niet competent", "order": 1, "minPercent": 0},
                {"levelId": "competent", "label": "Competent", "order": 2, "minPercent": 100},
            ],
            "lifecycle": "published",
        })
    for key, kerntaken in FRAMEWORKS.items():
        order = 0
        for code, title, werkprocessen in kerntaken:
            order += 1
            parent = b.add("competency", {
                "frameworkId": frameworks[key]["uuid"], "code": code, "title": title,
                "description": f"Kerntaak {code} van de opleiding {PROGRAMMES[key][0]}.", "order": order,
                "requiredForRoles": ["learner"], "lifecycle": "published",
            })
            competencies[(key, code)] = parent
            for i, wp in enumerate(werkprocessen, start=1):
                order += 1
                competencies[(key, f"{code}-W{i}")] = b.add("competency", {
                    "frameworkId": frameworks[key]["uuid"], "parentId": parent["uuid"], "code": f"{code}-W{i}", "title": wp,
                    "description": f"Werkproces {code}-W{i}, beoordeeld in de beroepspraktijk.", "order": order,
                    "requiredForRoles": ["learner"], "lifecycle": "published",
                })

    # --- grade scales ---------------------------------------------------------
    numeric = b.add("grade-scale", {
        "name": "Cijfer 1 tot en met 10", "kind": "numeric", "min": 1, "max": 10, "passThreshold": 5.5,
        "roundingRule": "half-up-1dp", "lifecycle": "active",
    })
    competent_scale = b.add("grade-scale", {
        "name": "Competent of nog niet competent", "kind": "pass-fail", "min": 0, "max": 1, "passThreshold": 1,
        "roundingRule": "none", "lifecycle": "active",
        "bands": [
            {"bandId": "nog-niet-competent", "label": "Nog niet competent", "minValue": 0, "maxValue": 0.99, "pass": False},
            {"bandId": "competent", "label": "Competent", "minValue": 1, "maxValue": 1, "pass": True},
        ],
    })

    # --- programmes, courses, curriculum plans -------------------------------
    programmes = {}
    for key, (pname, niveau, years, crebo, _loc, _f) in PROGRAMMES.items():
        programmes[key] = b.add("programme", {
            "name": pname, "code": f"{key}{niveau}", "level": "mbo",
            "description": f"Niveau {niveau}, beroepsopleidende leerweg (bol), {years} jaar. Kerntaken en werkprocessen volgens het voorbeeld-kwalificatiedossier met crebo {crebo}.",
            "courseIds": [], "requiredCompetencyIds": [c["uuid"] for (k, code), c in competencies.items() if k == key and "-W" in code],
            "lifecycle": "published",
        })

    courses: dict[str, dict] = {}
    for key, (pname, niveau, years, _crebo, _loc, _f) in PROGRAMMES.items():
        courses[f"{key}-OPL"] = b.add("course", {
            "code": f"{key}-OPL", "name": f"{pname} (niveau {niveau}, bol)", "name_nl": f"{pname} (niveau {niveau}, bol)",
            "description": f"Inschrijving voor de opleiding {pname}, {years} jaar.", "level": "mbo", "language": "nl",
            "tags": ["mbo", f"niveau {niveau}", "bol"], "lifecycle": "published", "order": 0,
            "programmeIds": [programmes[key]["uuid"]], "competencyIds": [], "prerequisiteCourseIds": [],
        })
    order = {k: 0 for k in PROGRAMMES}
    unit_meta: dict[str, dict] = {}
    for code, key, lj, sem, name, credits, teachers, kerntaken, extra in UNITS:
        order[key] += 1
        courses[code] = b.add("course", {
            "code": code, "name": name, "name_nl": name,
            "description": f"Onderwijseenheid in leerjaar {lj} van {PROGRAMMES[key][0]}, " + ("het hele jaar." if sem == "J" else f"semester {sem[1]}."),
            "level": "mbo", "language": "nl", "tags": ["mbo", f"niveau {PROGRAMMES[key][1]}", f"leerjaar {lj}"],
            "lifecycle": "published", "parentCourseId": courses[f"{key}-OPL"]["uuid"], "order": order[key],
            "programmeIds": [programmes[key]["uuid"]], "ectsCredits": credits,
            "competencyIds": [competencies[(key, k)]["uuid"] for k in kerntaken], "prerequisiteCourseIds": [],
        })
        unit_meta[code] = {"programme": key, "leerjaar": lj, "semester": sem, "name": name, "credits": credits,
                           "teachers": teachers, "extra": extra}
    bpv_meta: dict[str, dict] = {}
    for code, key, lj, name, credits, coach, kerntaken in BPV_UNITS:
        order[key] += 1
        courses[code] = b.add("course", {
            "code": code, "name": name, "name_nl": name,
            "description": f"Beroepspraktijkvorming in leerjaar {lj}: werkprocessen van {', '.join(kerntaken)} bij een erkend leerbedrijf.",
            "level": "mbo", "language": "nl", "tags": ["mbo", "bpv", f"leerjaar {lj}"], "lifecycle": "published",
            "parentCourseId": courses[f"{key}-OPL"]["uuid"], "order": order[key], "programmeIds": [programmes[key]["uuid"]],
            "ectsCredits": credits, "competencyIds": [competencies[(key, k)]["uuid"] for k in kerntaken], "prerequisiteCourseIds": [],
        })
        bpv_meta[code] = {"programme": key, "leerjaar": lj, "coach": coach, "kerntaken": kerntaken}

    period_rows = [{"periodId": s[0], "label": s[1], "startDate": s[2].isoformat(), "endDate": s[3].isoformat()} for s in SEMESTERS]
    plans: dict[str, dict] = {}
    for key, (pname, niveau, _years, _crebo, _loc, _f) in PROGRAMMES.items():
        unit_codes = [u[0] for u in UNITS if u[1] == key] + [u[0] for u in BPV_UNITS if u[1] == key]
        components = []
        for code in unit_codes:
            if code in unit_meta:
                components.append({"componentId": f"{code}-EB", "label": f"{code} {unit_meta[code]['name']}", "weight": 1,
                                   "period": "S1" if unit_meta[code]["semester"] == "S1" else "S2", "kind": "assessment"})
            else:
                for k in bpv_meta[code]["kerntaken"]:
                    components.append({"componentId": f"{code}-PVB-{k}", "label": f"Proeve van bekwaamheid {k}", "weight": 1,
                                       "period": "S2", "kind": "assessment"})
        plans[f"{key}-OER"] = b.add("curriculum-plan", {
            "name": f"Examenplan {pname}, {YEAR}", "kind": "oer",
            "requiredCourseIds": [courses[c]["uuid"] for c in unit_codes], "electiveCourseIds": [],
            "components": components, "formula": "all-must-pass", "gradeScaleId": numeric["uuid"], "passRules": [],
            "periods": period_rows, "lifecycle": "published",
        })
        programmes[key]["curriculumPlanId"] = plans[f"{key}-OER"]["uuid"]
        courses[f"{key}-OPL"]["curriculumPlanId"] = plans[f"{key}-OER"]["uuid"]
    for code, meta in unit_meta.items():
        period = "S1" if meta["semester"] == "S1" else "S2"
        if meta["extra"] == "ENG":
            components = [
                {"componentId": f"{code}-NED", "label": "Nederlands", "weight": 1, "period": period, "kind": "assessment"},
                {"componentId": f"{code}-ENG", "label": "Engels", "weight": 1, "period": period, "kind": "assessment"},
            ]
        else:
            components = [{"componentId": f"{code}-EB", "label": f"Eindbeoordeling {meta['name'].lower()}", "weight": 1,
                           "period": period, "kind": "assessment"}]
        plans[code] = b.add("curriculum-plan", {
            "name": f"{code} {meta['name']}, {YEAR}", "kind": "oer", "requiredCourseIds": [courses[code]["uuid"]],
            "electiveCourseIds": [], "components": components, "formula": "last-attempt", "gradeScaleId": numeric["uuid"],
            "passRules": [{"componentId": c["componentId"], "minValue": 5.5} for c in components],
            "periods": [row for row in period_rows if meta["semester"] == "J" or row["periodId"] == meta["semester"]],
            "lifecycle": "published",
        })
        courses[code]["curriculumPlanId"] = plans[code]["uuid"]
    for code, meta in bpv_meta.items():
        components = [{"componentId": f"{code}-PVB-{k}", "label": f"Proeve van bekwaamheid {k}", "weight": 1, "period": "S2",
                       "kind": "assessment"} for k in meta["kerntaken"]]
        plans[code] = b.add("curriculum-plan", {
            "name": f"Proeven van bekwaamheid {PROGRAMMES[meta['programme']][0]} leerjaar {meta['leerjaar']}, {YEAR}", "kind": "oer",
            "requiredCourseIds": [courses[code]["uuid"]], "electiveCourseIds": [], "components": components,
            "formula": "all-must-pass", "gradeScaleId": competent_scale["uuid"],
            "passRules": [{"componentId": c["componentId"], "minValue": 1} for c in components],
            "periods": period_rows, "lifecycle": "published",
        })
        courses[code]["curriculumPlanId"] = plans[code]["uuid"]
    for key in PROGRAMMES:
        programmes[key]["courseIds"] = [c["uuid"] for code, c in courses.items() if code.startswith(key + "-")]

    # --- timetable: which unit a class has on which school day --------------
    cohort_days: dict[str, list[tuple[dt.date, str]]] = {}
    for cname, _p, _lj, _size, _room, _slb, timetable, _bpv in COHORTS:
        own = []
        for day in days:
            unit = timetable.get(semester_of(day), {}).get(WEEKDAYS[day.weekday()])
            if unit is not None:
                own.append((day, unit))
        cohort_days[cname] = own

    def session_teacher(unit: str, day: dt.date) -> str:
        """The teacher of a unit on a day; two-teacher units alternate by ISO week."""
        teachers = unit_meta[unit]["teachers"]
        return teachers[day.isocalendar()[1] % len(teachers)]

    teaching_days: dict[str, set[str]] = {nc: set() for nc in STAFF}
    for cname, *_rest in COHORTS:
        for day, unit in cohort_days[cname]:
            teaching_days[session_teacher(unit, day)].add(WEEKDAYS[day.weekday()])

    # --- classes (cohorts) ------------------------------------------------------
    cohorts = {}
    for cname, key, lj, _size, _room, slb, timetable, bpv in COHORTS:
        school_weekdays = sorted({wd for sem in timetable.values() for wd in sem}, key=WEEKDAYS.index)
        notes = None
        if bpv is not None:
            placement_days = sorted({wd for sem in bpv[1].values() for wd in sem}, key=WEEKDAYS.index)
            notes = (f"Beroepspraktijkvorming op {', '.join(DAG[WEEKDAYS.index(d)] for d in placement_days)}"
                     f" van {dutch_date(bpv[2])} tot en met {dutch_date(bpv[3])}; op die dagen staan geen lessen op het rooster.")
        cohorts[cname] = b.add("cohort", {
            "name": cname, "programmeId": programmes[key]["uuid"], "courseId": courses[f"{key}-OPL"]["uuid"],
            "teacherIds": [], "learnerIds": [], "period": "Schooljaar", "academicYear": YEAR,
            "lifecycle": "completed" if lj == PROGRAMMES[key][2] else "active",
            "locationId": locations[PROGRAMMES[key][4]]["uuid"],
            "teacherAssignments": [{"teacherId": slb, "role": "primary", "days": school_weekdays}],
            "notes": notes, "kind": "teaching", "programmeYear": lj,
        })

    # --- staff (working days filled in once visits are planned) -------------
    staff_rows = {}
    for nc, (_name, roles, quals, fixed) in STAFF.items():
        staff_rows[nc] = b.add("staff", {"ncUserId": nc, "roles": roles, "qualifications": quals, "workingDays": list(fixed)})

    # --- students -----------------------------------------------------------
    students = []
    used_names: set[tuple[str, str]] = set()
    for cname, key, lj, size, *_rest in COHORTS:
        for _ in range(size):
            boy = rng.random() < {"LOG": 0.8, "VIG": 0.15, "SD": 0.85}[key]
            extra = rng.choices([0, 1, 2, 4], weights=[68, 22, 7, 3])[0]
            birth = dt.date(2009 - (lj - 1) - extra, 1, 1) + dt.timedelta(days=rng.randrange(365))
            while True:
                given = rng.choice(BOYS if boy else GIRLS)
                surname = rng.choice(SURNAME_HEAD) + rng.choice(SURNAME_TAIL)
                if (given, surname) not in used_names:
                    used_names.add((given, surname))
                    break
            students.append({"class": cname, "programme": key, "leerjaar": lj, "boy": boy, "given": given, "surname": surname,
                             "birth": birth, "ability": rng.gauss(0, 1), "punctual": rng.random()})
    students.sort(key=lambda s: ([c[0] for c in COHORTS].index(s["class"]), s["surname"], s["given"]))
    for n, s in enumerate(students, start=1):
        s["nc"] = f"mbo-student-{n:03d}"
        s["minor"] = age_on(s["birth"], FIRST_DAY) < 18
        town = rng.choice(TOWNS)
        address = {"street": rng.choice(STREETS), "houseNumber": str(rng.randint(1, 180)),
                   "postalCode": f"05{rng.randint(10, 39)} {rng.choice('ABDEGHKLMNPRSTWZ')}{rng.choice('ABDEGHKLMNPRSTWZ')}",
                   "city": town, "country": "NL"}
        contacts = []
        if s["minor"]:
            female = rng.random() < 0.6
            contacts.append({"name": f"{rng.choice(ADULT_F if female else ADULT_M)} {s['surname']}", "relationship": "ouder",
                             "phone": f"06-0000{rng.randint(1000, 9999)}", "priority": 1})
        elif rng.random() < 0.5:
            contacts.append({"name": f"{rng.choice(ADULT_F + ADULT_M)} {s['surname']}", "relationship": rng.choice(["ouder", "partner"]),
                             "phone": f"06-0000{rng.randint(1000, 9999)}", "priority": 1})
        s["profile"] = b.add("learner-profile", {
            "ncUserId": s["nc"], "givenName": s["given"], "familyName": s["surname"], "birthDate": s["birth"].isoformat(),
            "schoolId": school["uuid"], "eduPersonAffiliation": ["student"], "roles": ["learner"],
            # A minor's parent (the emergency contact above) has an account, so they can co-sign a praktijkovereenkomst.
            "parentIds": ([f"mbo-ouder-{n:03d}"] if s["minor"] else []),
            "guardianRefs": [], "address": address, "emergencyContacts": contacts,
            "allergies": (["noten"] if rng.random() < 0.04 else None),
            "medicalConditions": (["diabetes type 1"] if rng.random() < 0.02 else None),
            "beeldmateriaalConsent": {k: rng.random() < 0.75 for k in ["website", "socialMedia", "schoolgids", "classPhoto", "video"]},
            "lifecycle": "active",
        })
    by_class: dict[str, list[dict]] = {}
    for s in students:
        by_class.setdefault(s["class"], []).append(s)
    for cname, cohort in cohorts.items():
        cohort["learnerIds"] = [s["nc"] for s in by_class[cname]]

    # --- enrolments (lifecycle settled at the end of the year) --------------
    start_day = {1: dt.date(2025, 8, 18), 2: dt.date(2024, 8, 19), 3: dt.date(2023, 8, 21)}
    by_start = sorted(students, key=lambda s: (start_day[s["leerjaar"]], s["nc"]))
    for volgnummer, s in enumerate(by_start, start=1):
        s["volgnummer"] = volgnummer
    for s in students:
        cohort = cohorts[s["class"]]
        s["enrolment"] = b.add("enrolment", {
            "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"], "courseId": courses[f"{s['programme']}-OPL"]["uuid"],
            "source": "admission", "cohortId": cohort["uuid"], "lifecycle": "active",
            "inschrijvingDate": start_day[s["leerjaar"]].isoformat(), "volgnummer": s["volgnummer"],
            "locationId": cohort["locationId"], "leerjaar": s["leerjaar"],
        })

    # --- subject teachers -----------------------------------------------------
    for cname, key, _lj, _size, _room, slb, timetable, bpv in COHORTS:
        cohort = cohorts[cname]
        taught = []
        for sem in ("S1", "S2"):
            for unit in timetable.get(sem, {}).values():
                if unit not in taught:
                    taught.append(unit)
        teacher_ids = [slb]
        for unit in taught:
            for teacher in unit_meta[unit]["teachers"]:
                b.add("subjectteacherassignment", {"cohortId": cohort["uuid"], "courseId": courses[unit]["uuid"], "teacherId": teacher})
                if teacher not in teacher_ids:
                    teacher_ids.append(teacher)
        if bpv is not None:
            coach = bpv_meta[bpv[0]]["coach"]
            b.add("subjectteacherassignment", {"cohortId": cohort["uuid"], "courseId": courses[bpv[0]]["uuid"], "teacherId": coach})
            if coach not in teacher_ids:
                teacher_ids.append(coach)
        cohort["teacherIds"] = teacher_ids

    # --- report periods (the semester calendar) -----------------------------
    for pid, label, start, end in SEMESTERS:
        plan_ids = [plans[c]["uuid"] for c, m in unit_meta.items() if m["semester"] in (pid, "J")]
        plan_ids += [plans[c]["uuid"] for c in bpv_meta]
        b.add("report-period", {
            "name": label, "academicYear": YEAR, "periodCode": pid, "startDate": start.isoformat(), "endDate": end.isoformat(),
            "curriculumPlanIds": plan_ids, "cohortIds": [c["uuid"] for c in cohorts.values()],
            "lockDate": stamp(end + dt.timedelta(days=14), 17, 0), "attendanceIncluded": True, "lifecycle": "archived",
            "holidays": [{"name": h[0], "startDate": h[1].isoformat(), "endDate": h[2].isoformat()} for h in HOLIDAYS if start <= h[1] <= end],
            "studyDays": [{"date": d.isoformat(), "description": text} for d, text in STUDY_DAYS if start <= d <= end],
        })

    # --- praktijkopleiders ----------------------------------------------------
    trainers: dict[str, list[dict]] = {}
    company_codes: dict[str, tuple[str, str]] = {}
    serial = 0
    for key, companies in COMPANIES.items():
        for name, domain in companies:
            serial += 1
            kvk = f"0000{serial:04d}"
            company_codes[name] = (kvk, f"000{serial + 40:05d}")
            trainers[name] = []
            for _ in range(2):
                female = rng.random() < (0.7 if key == "VIG" else 0.3)
                given = rng.choice(ADULT_F if female else ADULT_M)
                family = rng.choice(SURNAME_HEAD) + rng.choice(SURNAME_TAIL)
                trainers[name].append(b.add("praktijkopleider", {
                    "givenName": given, "familyName": family,
                    "email": f"{given.lower()}.{family.lower()}@{domain}.example", "phone": f"06-0000{rng.randint(1000, 9999)}",
                    "trainingCompanyName": name, "trainingCompanyKvkNumber": kvk, "active": True,
                }))

    def trainer_name(t: dict) -> str:
        return f"{t['givenName']} {t['familyName']}"

    def bpv_day_near(target: dt.date, weekdays: list[str], lo: dt.date, hi: dt.date) -> dt.date:
        """The placement day closest to target (earlier first): a placement weekday, not closed, inside the period."""
        for delta in range(0, 60):
            for d in (target - dt.timedelta(days=delta), target + dt.timedelta(days=delta)):
                if lo <= d <= hi and d.weekday() < 5 and WEEKDAYS[d.weekday()] in weekdays and d not in CLOSED:
                    return d
        raise ValueError(f"no placement day near {target}")

    # --- placements, praktijkovereenkomsten, signatures, visits, assessments --
    placements = []
    visits_by_coach: dict[str, set[str]] = {}
    assessments_by_student: dict[str, list[dict]] = {}
    terminated_student = None
    for cname, key, lj, _size, _room, _slb, _timetable, bpv in COHORTS:
        if bpv is None:
            continue
        unit, weekdays_by_sem, p_from, p_to = bpv
        weekdays_all = sorted({wd for v in weekdays_by_sem.values() for wd in v}, key=WEEKDAYS.index)
        coach = bpv_meta[unit]["coach"]
        companies = COMPANIES[key]
        for i, s in enumerate(by_class[cname]):
            company = companies[i % len(companies)][0]
            trainer = trainers[company][(i // len(companies)) % 2]
            spells = [(company, trainer, p_from, p_to, "completed")]
            if cname == "VIG3-2A" and terminated_student is None and company == "Woonzorg Vaarthof":
                terminated_student = s
                replacement = "Zorgcentrum De Vaartoever"
                spells = [(company, trainer, p_from, dt.date(2025, 11, 14), "terminated"),
                          (replacement, trainers[replacement][1], dt.date(2025, 12, 3), p_to, "completed")]
            for company, trainer, f, t, state in spells:
                kvk, erkenning = company_codes[company]
                placement = b.add("bpv-placement", {
                    "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"], "programmeId": programmes[key]["uuid"],
                    "curriculumPlanId": plans[unit]["uuid"], "practicalTrainerId": trainer["uuid"], "schoolCoachId": coach,
                    "trainingCompanyName": company, "trainingCompanyKvkNumber": kvk, "periodFrom": f.isoformat(), "periodTo": t.isoformat(),
                    "trainingCompanyVerification": {"provider": "sbb", "status": "verified", "erkenningNumber": erkenning,
                                                    "verifiedAt": stamp(f - dt.timedelta(days=21), 10, 0),
                                                    "expiresAt": stamp(dt.date(2027, 12, 31), 23, 59)},
                    "lifecycle": state,
                })
                placements.append({"row": placement, "student": s, "unit": unit, "weekdays": weekdays_all, "from": f, "to": t,
                                   "state": state, "trainer": trainer, "company": company, "coach": coach, "class": cname})
    for p in placements:
        s, f, t = p["student"], p["from"], p["to"]
        placement_days = ", ".join(DAG[WEEKDAYS.index(d)] for d in p["weekdays"])
        pok = b.add("praktijkovereenkomst", {
            "bpvPlacementId": p["row"]["uuid"], "periodFrom": f.isoformat(), "periodTo": t.isoformat(),
            "terms": (f"Beroepspraktijkvorming bij {p['company']} van {dutch_date(f)} tot en met {dutch_date(t)}, op {placement_days}. "
                      f"Praktijkopleider {trainer_name(p['trainer'])}, BPV-docent {STAFF[p['coach']][0]}. "
                      "Het leerbedrijf is erkend door SBB; de student volgt de werktijden en huisregels van het leerbedrijf."),
            "version": 1,
            # PokParentSignatureRule: a parent co-signs when the student is under 18 on the day they sign (12 days before the start).
            "parentSignatureRequired": age_on(s["birth"], f - dt.timedelta(days=12)) < 18,
            "lifecycle": p["state"],
        })
        p["pok"] = pok
        signers = [(s["nc"], "student", 12, 19, "basic", "Nextcloud-account"),
                   (STAGECOORDINATOR, "school", 10, 10, "substantial", "Nextcloud-account met tweestapsverificatie"),
                   (p["trainer"]["uuid"], "praktijkopleider", 9, 14, "basic", "Ondertekenlink per e-mail")]
        for signer, role, before, hour, level, method in signers:
            b.add("pok-signature", {
                "subjectId": pok["uuid"], "subjectVersion": 1, "signerId": signer, "signerRole": role,
                "signedAt": stamp(f - dt.timedelta(days=before), hour, 5), "assuranceLevel": level, "method": method,
            })

    # Parent co-signatures, appended after the three standing signatures so no earlier uuid moves.
    for p in placements:
        if p["pok"]["parentSignatureRequired"]:
            b.add("pok-signature", {
                "subjectId": p["pok"]["uuid"], "subjectVersion": 1, "signerId": p["student"]["profile"]["parentIds"][0],
                "signerRole": "parent", "signedAt": stamp(p["from"] - dt.timedelta(days=11), 20, 5), "assuranceLevel": "basic",
                "method": "Nextcloud-account",
            })

    visit_text = {
        "LOG": ("Werkt veilig in het magazijn en houdt zich aan de looproutes.", "Oefent verder met het scannen van inkomende zendingen."),
        "VIG": ("Neemt contact op met cliënten op een rustige, respectvolle manier.", "Rapporteert nog te kort in het zorgdossier; oefent met observeren en beschrijven."),
        "SD": ("Draait mee in de dagelijkse stand-up en pakt taken op uit het sprintbord.", "Schrijft testen bij nieuwe code voordat een taak op klaar gaat."),
    }
    for p in placements:
        s, key = p["student"], p["student"]["programme"]
        attendees = [{"role": "student", "name": f"{s['given']} {s['surname']}"},
                     {"role": "praktijkopleider", "name": trainer_name(p["trainer"])},
                     {"role": "bpv-docent", "name": STAFF[p["coach"]][0]}]
        plan_visits = []
        if p["state"] == "terminated":
            plan_visits = [("voortgangsbezoek", p["from"] + dt.timedelta(weeks=5)), ("incident", dt.date(2025, 11, 7))]
        else:
            plan_visits.append(("voortgangsbezoek", p["from"] + dt.timedelta(weeks=6)))
            # A mid-term conversation only where the workplace asks for one.
            if (p["to"] - p["from"]).days > 250 and rng.random() < 0.2:
                plan_visits.append(("tussentijds-gesprek", dt.date(2026, 2, 11)))
            plan_visits.append(("eindgesprek", p["to"] - dt.timedelta(weeks=2)))
        good, work_on = visit_text[key]
        for kind, target in plan_visits:
            day = bpv_day_near(target, p["weekdays"], p["from"], p["to"])
            visits_by_coach.setdefault(p["coach"], set()).add(WEEKDAYS[day.weekday()])
            if kind == "incident":
                narrative = ("Gesprek na twee meldingen van het leerbedrijf: de student kwam te laat op vroege diensten en voelt zich niet "
                             "thuis in het team. Leerbedrijf en student willen stoppen.")
                actions = "Stage stopt op 14 november 2025. Stagecoördinator zoekt een nieuw leerbedrijf; start uiterlijk in december."
            elif kind == "eindgesprek":
                narrative = f"Eindgesprek. {good} De werkprocessen zijn beoordeeld; de praktijkopleider is tevreden over de groei."
                actions = "Geen actiepunten; de beoordelingen gaan naar de examencommissie."
            elif kind == "tussentijds-gesprek":
                narrative = f"Tussentijds gesprek op verzoek van het leerbedrijf, over de planning en de aanwezigheid. {good}"
                actions = work_on
            else:
                narrative = f"Eerste bezoek op de werkplek. {good}"
                actions = work_on
            b.add("bpv-visit-report", {
                "bpvPlacementId": p["row"]["uuid"], "learnerRef": s["profile"]["uuid"], "visitDate": day.isoformat(),
                "visitKind": kind, "attendees": attendees, "schoolCoachId": p["coach"], "narrative": narrative,
                "actionPoints": actions, "lifecycle": "finalized",
            })

    # Werkproces assessments: most competent at once, some after a retake, a
    # few not yet competent at the end of the year.
    completed = [p for p in placements if p["state"] == "completed"]
    retake_pool = [(p, k) for p in completed for k in bpv_meta[p["unit"]]["kerntaken"]]
    rng.shuffle(retake_pool)
    retakes = {(id(p), k) for p, k in retake_pool[:18]}
    fail_final = {(id(p), k) for p, k in retake_pool[18:21]}
    pvb_results: list[dict] = []
    for p in completed:
        s, key = p["student"], p["student"]["programme"]
        crebo = PROGRAMMES[key][3]
        for kerntaak in bpv_meta[p["unit"]]["kerntaken"]:
            window = ASSESSMENT_WINDOWS[(p["unit"], kerntaak)]
            day = bpv_day_near(window + dt.timedelta(days=rng.randrange(3)), p["weekdays"], p["from"], p["to"])
            werkprocessen = [wp for wp in FRAMEWORKS[key] if wp[0] == kerntaak][0][2]
            rows = []
            for i, label in enumerate(werkprocessen, start=1):
                code = f"{kerntaak}-W{i}"
                base = {
                    "bpvPlacementId": p["row"]["uuid"], "curriculumPlanId": plans[p["unit"]]["uuid"],
                    "componentId": f"{p['unit']}-PVB-{kerntaak}", "kwalificatiedossierCode": crebo, "coreTaskCode": kerntaak,
                    "werkprocesCode": code, "werkprocesLabel": label, "competencyId": competencies[(key, code)]["uuid"],
                    "assessorId": p["trainer"]["uuid"], "lifecycle": "confirmed",
                }
                last_wp = i == len(werkprocessen)
                if last_wp and (id(p), kerntaak) in retakes:
                    first = bpv_day_near(day - dt.timedelta(weeks=3), p["weekdays"], p["from"], p["to"])
                    rows.append(dict(base, assessedAt=first.isoformat(), assessment="nog-niet-competent",
                                     notes="Voert het werkproces nog niet zelfstandig uit; herbeoordeling over drie weken afgesproken."))
                    rows.append(dict(base, assessedAt=day.isoformat(), assessment="competent",
                                     notes="Herbeoordeling: voert het werkproces nu zelfstandig en volgens de afspraken uit."))
                elif last_wp and (id(p), kerntaak) in fail_final:
                    rows.append(dict(base, assessedAt=day.isoformat(), assessment="nog-niet-competent",
                                     notes="Nog niet zelfstandig; de proeve wordt in het volgende schooljaar opnieuw afgenomen."))
                else:
                    earlier = bpv_day_near(day - dt.timedelta(days=7), p["weekdays"], p["from"], p["to"])
                    rows.append(dict(base, assessedAt=earlier.isoformat(), assessment="competent", notes=None))
            rows.sort(key=lambda r: r["assessedAt"])
            added = [b.add("werkproces-assessment", {k: v for k, v in r.items() if v is not None}) for r in rows]
            assessments_by_student.setdefault(s["nc"], []).extend(added)
            last = added[-1]
            pvb_results.append({"placement": p, "kerntaak": kerntaak, "last": last,
                                "value": 1.0 if last["assessment"] == "competent" else 0.0})

    # Working days: teaching days, placement visits, and the fixed pattern for the rest.
    for nc, row in staff_rows.items():
        if STAFF[nc][3]:
            continue
        days_set = teaching_days[nc] | visits_by_coach.get(nc, set())
        row["workingDays"] = [d for d in WEEKDAYS if d in days_set]

    # --- sessions ---------------------------------------------------------------
    sessions: dict[tuple[str, dt.date], dict] = {}
    for cname, key, *_rest in COHORTS:
        cohort = cohorts[cname]
        room = rooms[cname]
        for day, unit in cohort_days[cname]:
            friday = day.weekday() == 4
            sessions[(cname, day)] = b.add("session", {
                "cohortId": cohort["uuid"], "courseId": courses[unit]["uuid"],
                "title": f"{cname}, {unit_meta[unit]['name']}, {dutch_date(day)}",
                "startsAt": stamp(day, 9, 0) if friday else stamp(day, 8, 30),
                "endsAt": stamp(day, 13, 0) if friday else stamp(day, 15, 0),
                "location": room["name"], "roomId": room["uuid"], "lifecycle": "completed",
            })

    def teacher_of(cname: str, day: dt.date) -> str:
        unit = dict(cohort_days[cname])[day]
        return session_teacher(unit, day)

    # --- attendance marks: exceptions only ------------------------------------
    marks: dict[str, dict[dt.date, tuple[str, str, int]]] = {}
    for s in students:
        own = [d for d, _u in cohort_days[s["class"]]]
        pm: dict[dt.date, tuple[str, str, int]] = {}
        for _ in range(min(4, max(0, int(rng.gauss(1.2, 1.1))))):
            winter = [d for d in own if d.month in (11, 12, 1, 2, 3)]
            start = rng.choice(winter if winter and rng.random() < 0.6 else own)
            i = own.index(start)
            for d in own[i:i + rng.choices([1, 2, 3], weights=[45, 35, 20])[0]]:
                pm[d] = ("absent-excused", "Ziek gemeld", 0)
        for _ in range(rng.choices([0, 1, 2], weights=[60, 30, 10])[0]):
            d = rng.choice(own)
            if d not in pm:
                pm[d] = ("left-early", rng.choice(["Huisartsafspraak", "Tandarts", "Afspraak in het ziekenhuis"]), lesson_minutes(d) - 120)
        lates = rng.choices([0, 1, 2, 3, 6], weights=[45, 25, 15, 10, 5])[0] if s["punctual"] < 0.85 else 0
        for _ in range(lates):
            d = rng.choice(own)
            if d not in pm:
                pm[d] = ("late", rng.choice(["Te laat, bus of trein vertraagd", "Te laat binnengekomen"]), lesson_minutes(d) - rng.choice([10, 15, 20, 30]))
        if rng.random() < 0.18:
            for _ in range(rng.randint(1, 2)):
                d = rng.choice(own)
                if d not in pm:
                    pm[d] = ("absent-unexcused", "Niet afgemeld", 0)
        marks[s["nc"]] = pm

    # The leerplicht case: a minor in LOG2-1A misses a week in March unexcused.
    leerplicht = next(s for s in by_class["LOG2-1A"] if age_on(s["birth"], dt.date(2026, 3, 31)) < 17)
    lp_days = [d for d, _u in cohort_days["LOG2-1A"] if dt.date(2026, 3, 9) <= d <= dt.date(2026, 3, 13)]
    for d in lp_days:
        marks[leerplicht["nc"]][d] = ("absent-unexcused", "Niet afgemeld; ouder niet bereikbaar", 0)
    # The attendance case: an adult SD4-1A student drops below 80 percent in semester 1.
    low = next(s for s in by_class["SD4-1A"] if not s["minor"])
    s1_days = [d for d, _u in cohort_days["SD4-1A"] if d <= S1_END]
    for d in s1_days[20:36]:
        if d.weekday() in (0, 3):
            marks[low["nc"]][d] = ("absent-unexcused", "Niet afgemeld", 0)
        elif d.weekday() == 2:
            marks[low["nc"]][d] = ("absent-excused", "Ziek gemeld", 0)

    # Students report illness themselves; the attendance officer decides.
    excuses: dict[tuple[str, dt.date], dict] = {}
    owners = [s for s in students if s is not leerplicht and s is not low
              and any(m[0] == "absent-excused" for m in marks[s["nc"]].values())]
    for s in rng.sample(owners, 16):
        spell = sorted(d for d, m in marks[s["nc"]].items() if m[0] == "absent-excused")
        own = [d for d, _u in cohort_days[s["class"]]]
        run = [spell[0]]
        for d in spell[1:]:
            if own.index(d) == own.index(run[-1]) + 1:
                run.append(d)
            else:
                break
        req = b.add("excuse-request", {
            "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"], "submittedBy": s["nc"], "submittedByRef": s["profile"]["uuid"],
            "dateFrom": run[0].isoformat(), "dateTo": run[-1].isoformat(),
            "reason": rng.choice(["Griep", "Koorts", "Buikgriep", "Migraine"]), "reasonKind": "illness", "submittedAuthLevel": "basic",
            "decidedBy": ATTENDANCE_OFFICER, "decidedAt": stamp(run[0], 11, 0), "lifecycle": "approved",
        })
        for d in run:
            excuses[(s["nc"], d)] = req
    rejected_owner = next(s for s in by_class["VIG3-3A"] if s["nc"] not in {k[0] for k in excuses})
    reject_day = next(d for d, _u in cohort_days["VIG3-3A"] if d >= dt.date(2026, 3, 23) and d not in marks[rejected_owner["nc"]])
    marks[rejected_owner["nc"]][reject_day] = ("absent-unexcused", "Verlof voor rijexamen niet toegekend", 0)
    rejected = b.add("excuse-request", {
        "learnerId": rejected_owner["nc"], "learnerRef": rejected_owner["profile"]["uuid"], "submittedBy": rejected_owner["nc"],
        "submittedByRef": rejected_owner["profile"]["uuid"], "dateFrom": reject_day.isoformat(), "dateTo": reject_day.isoformat(),
        "reason": "Rijexamen", "reasonKind": "other", "submittedAuthLevel": "basic", "decidedBy": ATTENDANCE_OFFICER,
        "decidedAt": stamp(reject_day - dt.timedelta(days=4), 11, 0),
        "decisionNote": "Een rijexamen valt buiten de verlofregeling; plan het buiten lestijd of op een dag zonder lessen.",
        "lifecycle": "rejected",
    })
    excuses[(rejected_owner["nc"], reject_day)] = rejected

    records_by_student: dict[str, list[dict]] = {}
    for s in students:
        for d in sorted(marks[s["nc"]]):
            status, reason, minutes = marks[s["nc"]][d]
            rec = b.add("attendance-record", {
                "sessionId": sessions[(s["class"], d)]["uuid"], "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"],
                "cohortId": cohorts[s["class"]]["uuid"], "status": status, "minutesAttended": minutes,
                "markedBy": teacher_of(s["class"], d),
                "markedAt": stamp(d, 9, 25 if status == "late" else 15) if d.weekday() == 4 else stamp(d, 9 if status == "late" else 8, 5 if status == "late" else 45),
                "reason": reason,
                "excuseRequestId": (excuses[(s["nc"], d)]["uuid"] if (s["nc"], d) in excuses else None),
            })
            records_by_student.setdefault(s["nc"], []).append(rec)

    threshold_lp = b.add("attendance-threshold", {
        "name": "Leerplicht en kwalificatieplicht: 16 uur ongeoorloofd verzuim in 4 weken", "kind": "leerplicht-16uur",
        "scope": "per-learner", "window": {"type": "rolling-weeks", "weeks": 4, "termId": None}, "metric": "unexcused-lesuren",
        "limit": 16, "lessonHourMinutes": 60,
        "onCross": {"notify": True, "notifyRoles": ["mentor", "coordinator"], "createFlag": True, "dataExchangeTarget": None},
        "active": True, "lifecycle": "active",
    })
    threshold_pct = b.add("attendance-threshold", {
        "name": "Aanwezigheid onder 80 procent in een semester", "kind": "college-aanwezigheid", "scope": "per-learner",
        "window": {"type": "fixed-term", "weeks": None, "termId": "S1"}, "metric": "attendance-percent-below", "limit": 80,
        "lessonHourMinutes": 60,
        "onCross": {"notify": True, "notifyRoles": ["mentor", "coordinator"], "createFlag": True, "dataExchangeTarget": None},
        "active": True, "lifecycle": "active",
    })
    lp_records = [r for r in records_by_student[leerplicht["nc"]] if r["status"] == "absent-unexcused"
                  and dt.date(2026, 3, 9) <= dt.date.fromisoformat(sessions_by_uuid_date(sessions, r["sessionId"])) <= dt.date(2026, 3, 13)]
    b.add("attendance-flag", {
        "learnerId": leerplicht["nc"], "attendanceThresholdId": threshold_lp["uuid"], "cohortId": cohorts["LOG2-1A"]["uuid"],
        "windowStart": "2026-03-02", "windowEnd": "2026-03-27",
        "metricValue": round(sum(lesson_minutes(d) for d in lp_days) / 60, 2), "breachingRecordIds": [r["uuid"] for r in lp_records],
        "mentorId": cohorts["LOG2-1A"]["teacherAssignments"][0]["teacherId"], "flagKind": "signal-verzuim", "lifecycle": "resolved",
        "interventions": [
            {"recordedBy": cohorts["LOG2-1A"]["teacherAssignments"][0]["teacherId"], "recordedAt": stamp(dt.date(2026, 3, 11), 12, 30),
             "note": "Student en ouder gebeld; geen gehoor, bericht ingesproken en een e-mail gestuurd."},
            {"recordedBy": ATTENDANCE_OFFICER, "recordedAt": stamp(dt.date(2026, 3, 16), 10, 0),
             "note": "Verzuim gemeld bij DUO; de leerplichtambtenaar is op de hoogte."},
            {"recordedBy": ATTENDANCE_OFFICER, "recordedAt": stamp(dt.date(2026, 3, 19), 15, 0),
             "note": "Gesprek met student en ouder op school; afspraken over ziekmelden en een wekelijks gesprek met de SLB'er."},
        ],
    })
    low_s1 = [r for r in records_by_student[low["nc"]] if dt.date.fromisoformat(sessions_by_uuid_date(sessions, r["sessionId"])) <= S1_END]
    absent_s1 = [r for r in low_s1 if r["status"].startswith("absent")]
    pct = round(100 * (len(s1_days) - len(absent_s1)) / len(s1_days), 1)
    b.add("attendance-flag", {
        "learnerId": low["nc"], "attendanceThresholdId": threshold_pct["uuid"], "cohortId": cohorts["SD4-1A"]["uuid"],
        "windowStart": FIRST_DAY.isoformat(), "windowEnd": S1_END.isoformat(), "metricValue": pct,
        "breachingRecordIds": [r["uuid"] for r in absent_s1], "mentorId": cohorts["SD4-1A"]["teacherAssignments"][0]["teacherId"],
        "flagKind": "attendance-requirement", "lifecycle": "resolved",
        "interventions": [
            {"recordedBy": cohorts["SD4-1A"]["teacherAssignments"][0]["teacherId"], "recordedAt": stamp(dt.date(2025, 12, 9), 13, 0),
             "note": "Gesprek over de aanwezigheid; de student werkt veel avonden en slaapt slecht."},
            {"recordedBy": STUDENT_COUNSELLOR, "recordedAt": stamp(dt.date(2026, 1, 13), 11, 0),
             "note": "Doorverwezen naar de studentbegeleider; weekplanning gemaakt met minder werkuren."},
        ],
    })
    low["attendance_pct"] = pct

    # --- exam accommodations --------------------------------------------------
    dyslexic = rng.sample([s for s in students if s is not low and s is not leerplicht], 6)
    for s in dyslexic:
        b.add("exam-accommodation", {
            "learnerId": s["nc"], "submittedBy": STUDENT_COUNSELLOR, "assessmentId": None, "accommodationKind": "extra-time-percentage",
            "value": 25, "evidenceRef": "Dyslexieverklaring in het studentdossier", "approvedBy": EXAM_SECRETARY, "lifecycle": "active",
        })
    b.add("exam-accommodation", {
        "learnerId": dyslexic[0]["nc"], "submittedBy": STUDENT_COUNSELLOR, "assessmentId": None, "accommodationKind": "separate-room",
        "value": None, "evidenceRef": "Advies studentbegeleider na intake", "approvedBy": EXAM_SECRETARY, "lifecycle": "active",
    })

    # --- exam board: exemptions (decided before the results they replace) ----
    sd2_exempt = by_class["SD4-2A"][3]
    sd3_exempt = by_class["SD4-3A"][5]
    exemption_rows = {}
    exemption_rows[sd2_exempt["nc"]] = b.add("exemption-case", {
        "learnerId": sd2_exempt["profile"]["uuid"], "learnerUserId": sd2_exempt["profile"]["ncUserId"], "curriculumPlanId": plans["SD-2.3"]["uuid"], "componentId": "SD-2.3-ENG",
        "groundsKind": "prior-diploma",
        "groundsDescription": "Havodiploma 2024 met Engels op havoniveau, hoger dan het niveau dat de opleiding vraagt.",
        "submittedAt": stamp(dt.date(2025, 9, 15), 10, 12),
        "decisionRationale": "Het diploma toont Engels aan op een hoger niveau dan de exameneis; vrijstelling voor het onderdeel Engels.",
        "policyReference": "Examenreglement Voorbeeldcollege Vaartveld 2025-2026, artikel 7.3", "decidedBy": EXAM_CHAIR,
        "decidedAt": stamp(dt.date(2025, 10, 6), 16, 0), "lifecycle": "granted",
    })
    exemption_rows[sd3_exempt["nc"]] = b.add("exemption-case", {
        "learnerId": sd3_exempt["profile"]["uuid"], "learnerUserId": sd3_exempt["profile"]["ncUserId"], "curriculumPlanId": plans["SD-3.3"]["uuid"], "componentId": "SD-3.3-ENG",
        "groundsKind": "certificate", "groundsDescription": "Certificaat Engels op niveau B2 van het Europees referentiekader, behaald in 2025.",
        "submittedAt": stamp(dt.date(2025, 9, 22), 9, 40),
        "decisionRationale": "Het certificaat is recent en ligt boven het vereiste niveau; vrijstelling voor het onderdeel Engels.",
        "policyReference": "Examenreglement Voorbeeldcollege Vaartveld 2025-2026, artikel 7.3", "decidedBy": EXAM_CHAIR,
        "decidedAt": stamp(dt.date(2025, 10, 6), 16, 0), "lifecycle": "granted",
    })
    log_request = by_class["LOG2-2A"][7]
    b.add("exemption-case", {
        "learnerId": log_request["profile"]["uuid"], "learnerUserId": log_request["profile"]["ncUserId"], "curriculumPlanId": plans["LOG-BPV"]["uuid"], "componentId": "LOG-BPV-PVB-B1-K1",
        "groundsKind": "work-experience", "groundsDescription": "Twee zomers vakantiewerk in een distributiecentrum.",
        "submittedAt": stamp(dt.date(2025, 9, 29), 14, 3),
        "decisionRationale": "De werkervaring dekt het opslaan van goederen, maar niet het ontvangen en controleren of het voorraadbeheer. De proeve blijft nodig.",
        "policyReference": "Examenreglement Voorbeeldcollege Vaartveld 2025-2026, artikel 7.4", "decidedBy": EXAM_CHAIR,
        "decidedAt": stamp(dt.date(2025, 10, 20), 16, 0), "lifecycle": "rejected",
    })
    vig_request = by_class["VIG3-2A"][9]
    b.add("exemption-case", {
        "learnerId": vig_request["profile"]["uuid"], "learnerUserId": vig_request["profile"]["ncUserId"], "curriculumPlanId": plans["VIG-2.3"]["uuid"], "componentId": "VIG-2.3-EB",
        "groundsKind": "prior-diploma", "groundsDescription": "Diploma Helpende zorg en welzijn (niveau 2), behaald in 2024.",
        "submittedAt": stamp(dt.date(2025, 9, 8), 11, 20),
        "decisionRationale": "Het diploma dekt Nederlands op het vereiste niveau, maar niet het burgerschapsdeel van deze eenheid. Geen vrijstelling.",
        "policyReference": "Examenreglement Voorbeeldcollege Vaartveld 2025-2026, artikel 7.3", "decidedBy": EXAM_CHAIR,
        "decidedAt": stamp(dt.date(2025, 9, 29), 16, 0), "lifecycle": "rejected",
    })

    # --- unit results --------------------------------------------------------
    first_years = [s for s in students if s["leerjaar"] == 1]
    by_programme_weak = {}
    for key in PROGRAMMES:
        pool = sorted([s for s in first_years if s["programme"] == key and s is not low], key=lambda s: s["ability"])
        by_programme_weak[key] = pool
    flagged = by_programme_weak["LOG"][:2] + by_programme_weak["VIG"][:3] + by_programme_weak["SD"][:3]
    negative = [by_programme_weak["LOG"][0], by_programme_weak["SD"][0]]
    with_advice = [by_programme_weak["VIG"][0]]
    postponed = [by_programme_weak["SD"][1]]
    fraud_student = next(s for s in by_class["SD4-1A"] if s not in flagged and s is not low and s["ability"] > 0.3)

    def exam_day(s: dict, unit: str, lo: dt.date, hi: dt.date) -> dt.date:
        """The student's test day: the first lesson of the unit in the window that the student attended.

        A student absent on every lesson in the window sat the test at the last lesson before it.
        """
        own = [d for d, u in cohort_days[s["class"]] if u == unit]
        present = [d for d in own if not marks[s["nc"]].get(d, ("present",))[0].startswith("absent")]
        inside = [d for d in present if lo <= d <= hi]
        if inside:
            return inside[0]
        return max(d for d in present if d < lo)

    windows = {"S1": (dt.date(2026, 1, 19), dt.date(2026, 1, 23)), "S2": (dt.date(2026, 6, 1), dt.date(2026, 6, 12)),
               "J": (dt.date(2026, 6, 8), dt.date(2026, 6, 12))}
    resit_windows = {"S1": (dt.date(2026, 2, 9), dt.date(2026, 2, 13)), "S2": (dt.date(2026, 6, 22), dt.date(2026, 6, 26)),
                     "J": (dt.date(2026, 6, 22), dt.date(2026, 6, 26))}
    entries_by: dict[tuple[str, str], list[dict]] = {}
    fraud_entry = None

    def add_entry(s: dict, unit: str, component: str, value: float | None, day: dt.date, grader: str, **extra) -> dict:
        period = "S1" if unit_meta[unit]["semester"] == "S1" else "S2"
        graded = stamp(day + dt.timedelta(days=4), 16, 0)
        fields = {
            "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"], "curriculumPlanId": plans[unit]["uuid"], "componentId": component,
            "courseId": courses[unit]["uuid"], "cohortId": cohorts[s["class"]]["uuid"], "sourceKind": "manual",
            "sessionId": sessions[(s["class"], day)]["uuid"], "value": value, "gradeScaleId": numeric["uuid"], "period": period,
            "grader": grader, "gradedAt": graded, "visibleFrom": graded, "lifecycle": "published",
        }
        fields.update(extra)
        row = b.add("grade-entry", fields)
        entries_by.setdefault((s["nc"], unit), []).append(row)
        return row

    for s in students:
        units = [c for c, m in unit_meta.items() if m["programme"] == s["programme"] and m["leerjaar"] == s["leerjaar"]]
        for unit in units:
            meta = unit_meta[unit]
            sem = meta["semester"]
            components = [f"{unit}-NED", f"{unit}-ENG"] if meta["extra"] == "ENG" else [f"{unit}-EB"]
            for component in components:
                if component.endswith("-ENG") and s["nc"] in exemption_rows:
                    case = exemption_rows[s["nc"]]
                    graded = case["decidedAt"]
                    row = b.add("grade-entry", {
                        "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"], "curriculumPlanId": plans[unit]["uuid"],
                        "componentId": component, "courseId": courses[unit]["uuid"], "cohortId": cohorts[s["class"]]["uuid"],
                        "sourceKind": "exemption", "exemptionCaseId": case["uuid"], "gradeScaleId": numeric["uuid"], "period": "S2",
                        "grader": EXAM_CHAIR, "gradedAt": graded, "visibleFrom": graded,
                        "comment": "Vrijstelling verleend door de examencommissie.", "lifecycle": "published",
                    })
                    case["resultingGradeEntryId"] = row["uuid"]
                    entries_by.setdefault((s["nc"], unit), []).append(row)
                    continue
                day = exam_day(s, unit, *windows[sem])
                teacher = session_teacher(unit, day) if meta["extra"] != "ENG" else meta["teachers"][0 if component.endswith("NED") else 1]
                value = clamp_grade(6.9 + s["ability"] + UNIT_OFFSET.get(unit, 0.0) + rng.gauss(0, 0.7))
                force_fail = False
                if s in flagged and sem == "S1":
                    force_fail = True
                if (s in negative or s in with_advice) and sem == "S2":
                    force_fail = True
                if s in postponed and sem == "J":
                    force_fail = True
                if force_fail:
                    value = min(value, round(4.0 + rng.random() * 1.2, 1))
                # First-years pass their resits unless the story needs them not to.
                must_pass_resit = s["leerjaar"] == 1 and not force_fail
                if s is fraud_student and unit == "SD-1.2":
                    fraud_entry = add_entry(s, unit, component, max(value, 7.8), day, teacher, lifecycle="invalidated")
                    resit_day = exam_day(s, unit, *resit_windows[sem])
                    add_entry(s, unit, component, 6.1, resit_day, teacher, comment="Herkansing na de uitspraak van de examencommissie.")
                    continue
                add_entry(s, unit, component, value, day, teacher)
                if value < 5.5:
                    resit_day = exam_day(s, unit, *resit_windows[sem])
                    resit = clamp_grade(6.2 + 0.8 * s["ability"] + rng.gauss(0, 0.6))
                    if force_fail:
                        resit = min(resit, round(4.4 + rng.random(), 1))
                    elif must_pass_resit or s["leerjaar"] > 1 and rng.random() < 0.85:
                        resit = max(resit, round(5.5 + rng.random() * 1.5, 1))
                    add_entry(s, unit, component, resit, resit_day, teacher, comment="Herkansing.")

    # PVB results, as WerkprocesGradeEmitHandler writes them (value of the last
    # confirmed werkproces assessment of the component), then published.
    pvb_entries: dict[tuple[str, str], list[dict]] = {}
    for r in pvb_results:
        p = r["placement"]
        s = p["student"]
        day = dt.date.fromisoformat(r["last"]["assessedAt"])
        graded = stamp(day, 17, 0)
        row = b.add("grade-entry", {
            "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"], "curriculumPlanId": plans[p["unit"]]["uuid"],
            "componentId": f"{p['unit']}-PVB-{r['kerntaak']}", "courseId": courses[p["unit"]]["uuid"],
            "cohortId": cohorts[s["class"]]["uuid"], "sourceKind": "manual", "value": r["value"],
            "gradeScaleId": competent_scale["uuid"], "period": semester_of(day), "grader": "praktijkopleider",
            "gradedAt": graded, "visibleFrom": graded, "lifecycle": "published",
        })
        pvb_entries.setdefault((s["nc"], p["unit"]), []).append(row)

    # --- exam board: fraud cases (after the result they contest) -------------
    fraud = b.add("fraud-case", {
        "reporterId": "mbo-docent-08", "accusedLearnerId": fraud_student["profile"]["uuid"], "accusedLearnerUserId": fraud_student["profile"]["ncUserId"], "sourceKind": "manual",
        "contestedGradeEntryId": fraud_entry["uuid"],
        "allegation": "De ingeleverde webapplicatie is voor een groot deel gelijk aan de code van een andere student, inclusief dezelfde fouten.",
        "reportedAt": stamp(dt.date.fromisoformat(fraud_entry["gradedAt"][:10]), 17, 30),
        "hearingDate": stamp(dt.date(2026, 6, 17), 10, 0),
        "hearingRecords": [{"heldAt": stamp(dt.date(2026, 6, 17), 10, 0),
                            "attendees": [fraud_student["nc"], EXAM_CHAIR, EXAM_SECRETARY, "mbo-docent-08"],
                            "notes": "De student erkent dat de code van een klasgenoot is overgenomen, omdat het eigen project vastliep.",
                            "evidenceRefs": ["Vergelijking van de twee opgeleverde codebases"]}],
        "verdict": "fraud-proven",
        "decisionRationale": "Fraude vastgesteld. Het resultaat vervalt; de student mag de opdracht in de herkansingsweek opnieuw maken.",
        "decidedBy": EXAM_CHAIR, "decidedAt": stamp(dt.date(2026, 6, 19), 15, 0), "sanctionType": "grade-annulment",
        "sanctionScope": "single-assessment", "appealDeadline": stamp(dt.date(2026, 7, 31), 17, 0), "appealLodged": False,
        "lifecycle": "decided",
    })
    fraud_entry["fraudCaseId"] = fraud["uuid"]
    suspect = next(s for s in by_class["VIG3-2A"] if s is not terminated_student and s is not vig_request)
    contested = entries_by[(suspect["nc"], "VIG-2.1")][0]
    b.add("fraud-case", {
        "reporterId": "mbo-docent-03", "accusedLearnerId": suspect["profile"]["uuid"], "accusedLearnerUserId": suspect["profile"]["ncUserId"], "sourceKind": "manual",
        "sessionId": contested["sessionId"], "contestedGradeEntryId": contested["uuid"],
        "allegation": "Tijdens de kennistoets lag een telefoon op tafel onder een etui.",
        "reportedAt": stamp(dt.date.fromisoformat(sessions_by_uuid_date(sessions, contested["sessionId"])), 13, 0),
        "hearingDate": stamp(dt.date(2026, 2, 4), 14, 0),
        "hearingRecords": [{"heldAt": stamp(dt.date(2026, 2, 4), 14, 0), "attendees": [suspect["nc"], EXAM_CHAIR, "mbo-docent-03"],
                            "notes": "De telefoon stond uit; de surveillant heeft geen gebruik gezien.", "evidenceRefs": []}],
        "verdict": "unfounded", "decisionRationale": "Geen fraude aangetoond; het resultaat blijft staan.",
        "decidedBy": EXAM_CHAIR, "decidedAt": stamp(dt.date(2026, 2, 5), 16, 0), "appealLodged": False, "lifecycle": "dismissed",
    })

    # --- final grades, as the grade engine computes them ----------------------
    finals: dict[tuple[str, str], dict] = {}
    for (nc, unit), entries in list(entries_by.items()) + list(pvb_entries.items()):
        s = next(x for x in students if x["nc"] == nc)
        plan = plans[unit]
        scale = competent_scale if unit in bpv_meta else numeric
        value, passed, breakdown = evaluate_final(entries, plan, float(scale["passThreshold"]))
        newest = max((e for e in entries if e["lifecycle"] == "published"), key=lambda e: e["gradedAt"])
        finals[(nc, unit)] = b.add("final-grade", {
            "learnerId": nc, "learnerRef": s["profile"]["uuid"], "courseId": courses[unit]["uuid"],
            "programmeId": programmes[s["programme"]]["uuid"], "curriculumPlanId": plan["uuid"], "gradeScaleId": scale["uuid"],
            "value": value, "passed": passed, "breakdown": breakdown, "lastRecomputedAt": newest["gradedAt"],
        })

    def credits_passed(s: dict, until: str) -> int:
        total = 0
        for (nc, unit), row in finals.items():
            if nc != s["nc"] or unit not in unit_meta or row["passed"] is not True:
                continue
            newest = max(e["gradedAt"] for e in entries_by[(nc, unit)] if e["lifecycle"] == "published")
            if newest <= until:
                total += unit_meta[unit]["credits"]
        return total

    # --- first-year study advice ----------------------------------------------
    trajectories = {}
    for key in PROGRAMMES:
        trajectories[key] = b.add("bsa-trajectory", {
            "programmeId": programmes[key]["uuid"], "academicYear": YEAR, "kind": "mbo-studieadvies", "normEcts": 35,
            "window": {"mode": "relative-months", "referenceDate": FIRST_DAY.isoformat(), "afterMonths": 6, "notEarlierThanMonths": 3},
            "interimNormEcts": 15, "onAtRisk": {"notify": True, "notifyRoles": ["mentor", "coordinator"], "createFlag": True},
            "lifecycle": "active",
        })
    check_at = stamp(INTERIM_CHECK, 7, 0)
    warnings = {}
    for s in first_years:
        earned = credits_passed(s, check_at)
        if earned >= 15:
            continue
        flag = b.add("bsa-progress-flag", {
            "learnerId": s["nc"], "programmeId": programmes[s["programme"]]["uuid"], "bsaTrajectoryId": trajectories[s["programme"]]["uuid"],
            "academicYear": YEAR, "ectsEarned": earned, "ectsRequiredAtCheck": 15, "flaggedAt": check_at, "lifecycle": "warned",
        })
        s["flag"] = flag
        s["earned_at_check"] = earned
    for s in first_years:
        if "flag" not in s:
            continue
        note = None
        if s in postponed:
            note = "Langdurig ziek geweest in het voorjaar; afspraken met de studentbegeleider over een aangepast rooster."
        warnings[s["nc"]] = b.add("bsa-warning", {
            "learnerId": s["nc"], "programmeId": programmes[s["programme"]]["uuid"], "academicYear": YEAR,
            "bsaProgressFlagId": s["flag"]["uuid"], "warningDate": "2026-02-25", "ectsEarnedAtWarning": s["earned_at_check"],
            "ectsNormAtWarning": 35, "improvementPeriod": {"startDate": "2026-03-02", "endDate": "2026-06-26"},
            "offeredGuidance": "Wekelijks gesprek met de SLB'er, een studieplan voor semester 2 en de herkansingen in juni.",
            **({"personalCircumstancesNote": note} if note else {}), "lifecycle": "acknowledged",
        })
    decided = stamp(DECISION_DAY, 14, 0)
    for s in first_years:
        earned = credits_passed(s, decided)
        warned = s["nc"] in warnings
        fields = {
            "learnerId": s["nc"], "programmeId": programmes[s["programme"]]["uuid"], "academicYear": YEAR,
            "ectsAchieved": earned, "ectsNormRequired": 35, "warningIds": [warnings[s["nc"]]["uuid"]] if warned else [],
            "decidedBy": TEAMLEADER[s["programme"]], "decisionDate": decided, "lifecycle": "decided",
        }
        if earned >= 35:
            fields["decisionType"] = "positive"
        elif s in postponed:
            fields.update(decisionType="postponed", personalCircumstancesConsidered=True,
                          personalCircumstancesNote="Langdurige ziekte in het voorjaar, gedocumenteerd door de studentbegeleider.",
                          rationale="Door de ziekte kon de student de eenheid taal, rekenen en burgerschap niet afronden. Het advies wordt uitgesteld tot 1 februari 2027.",
                          studentHeardAt=stamp(dt.date(2026, 7, 1), 10, 0),
                          studentResponse="Wil de opleiding afmaken en de eenheid in het najaar inhalen.")
        else:
            if not warned:
                raise ValueError(f"{s['nc']} would get a negative advice without a warning")
            advice = s in with_advice
            fields.update(decisionType="negative-with-recommendation" if advice else "negative",
                          personalCircumstancesConsidered=True, personalCircumstancesNote="Geen omstandigheden aangevoerd die het resultaat verklaren.",
                          rationale=("De student haalde met de herkansingen minder dan de norm van het eerste jaar. "
                                     + ("Advies: de opleiding Helpende zorg en welzijn (niveau 2) past beter bij het tempo en de interesses van de student."
                                        if advice else "De opleiding kan niet worden voortgezet.")),
                          studentHeardAt=stamp(dt.date(2026, 7, 1), 11, 0),
                          studentResponse="Begrijpt het besluit en wil in gesprek over een andere opleiding.")
            s["enrolment"]["lifecycle"] = "withdrawn"
            s["enrolment"]["reason"] = "Uitgeschreven per 31 juli 2026 na een negatief bindend studieadvies."
        b.add("bsa-decision", fields)

    # Diploma year: enrolment completed when every unit and the work placement passed.
    for s in students:
        if s["leerjaar"] != PROGRAMMES[s["programme"]][2]:
            continue
        own = [row for (nc, _u), row in finals.items() if nc == s["nc"]]
        if own and all(row["passed"] is True for row in own):
            s["enrolment"]["lifecycle"] = "completed"

    # --- support requests and dossier notes -----------------------------------
    struggling = negative[0]
    b.add("support-request", {
        "learnerId": low["nc"], "raisedBy": cohorts["SD4-1A"]["teacherAssignments"][0]["teacherId"],
        "supportDomain": "Aanwezigheid en welbevinden",
        "description": "Aanwezigheid in semester 1 onder de 80 procent. Vraag om begeleiding bij planning, werk naast school en slaap.",
        "urgency": "high", "lifecycle": "closed",
    })
    b.add("support-request", {
        "learnerId": struggling["nc"], "raisedBy": STUDENT_COUNSELLOR, "supportDomain": "Studievoortgang en planning",
        "description": "Na de studievoortgangswaarschuwing: een studieplan voor semester 2 en hulp bij het voorbereiden van de herkansingen.",
        "urgency": "medium", "lifecycle": "decided",
    })
    b.add("support-request", {
        "learnerId": dyslexic[0]["nc"], "raisedBy": STUDENT_COUNSELLOR, "supportDomain": "Dyslexie en examenfaciliteiten",
        "description": "Aanvraag van extra tijd en een aparte ruimte bij toetsen, op basis van de dyslexieverklaring.",
        "urgency": "low", "lifecycle": "decided",
    })
    b.add("support-request", {
        "learnerId": terminated_student["nc"], "raisedBy": "mbo-docent-05", "supportDomain": "Begeleiding na een afgebroken stage",
        "description": "De eerste stage stopte in november. Vraag om extra begeleiding bij de start op het nieuwe leerbedrijf.",
        "urgency": "medium", "lifecycle": "closed",
    })
    slb = {c[0]: c[5] for c in COHORTS}
    notes = [
        (low, slb["SD4-1A"], "2025-11-18", "concern", "Mist vaak de maandag en donderdag. Zegt dat hij veel avonden werkt in de horeca.", "care-team-only"),
        (low, STUDENT_COUNSELLOR, "2026-01-13", "conversation", "Weekplanning gemaakt; werkuren teruggebracht naar twee avonden per week.", "care-team-only"),
        (leerplicht, slb["LOG2-1A"], "2026-03-11", "phone-call-home", "Ouder gebeld over de afwezigheid deze week; geen gehoor, voicemail ingesproken.", "care-team-only"),
        (leerplicht, ATTENDANCE_OFFICER, "2026-03-19", "conversation", "Gesprek met student en ouder. Afspraak: ziekmelden voor 08.15 uur en elke vrijdag kort contact met de SLB'er.", "care-team-only"),
        (terminated_student, "mbo-docent-05", "2025-11-07", "conversation", "Stage bij het eerste leerbedrijf stopt in overleg. De student wil wel verder in de zorg.", "team-visible"),
        (terminated_student, STAGECOORDINATOR, "2025-11-24", "observation", "Nieuw leerbedrijf gevonden; kennismaking was positief, start op 3 december.", "team-visible"),
        (struggling, slb[struggling["class"]], "2026-02-26", "conversation", "Waarschuwing studievoortgang besproken. Studieplan opgesteld met vaste werkmomenten op school.", "care-team-only"),
        (struggling, slb[struggling["class"]], "2026-06-30", "conversation", "Resultaten na de herkansingen besproken; de norm is niet gehaald. Gesprek over andere opleidingen gepland.", "care-team-only"),
        (fraud_student, slb["SD4-1A"], "2026-06-22", "conversation", "Uitspraak van de examencommissie besproken; de student maakt de opdracht opnieuw in de herkansingsweek.", "care-team-only"),
        (by_class["VIG3-3A"][0], slb["VIG3-3A"], "2026-06-29", "positive", "Alle proeven behaald en een baan aangeboden gekregen bij het leerbedrijf.", "team-visible"),
        (by_class["SD4-3A"][0], slb["SD4-3A"], "2026-06-30", "positive", "Afstudeerproject gepresenteerd aan de opdrachtgever; mooi eindresultaat.", "team-visible"),
        (dyslexic[0], STUDENT_COUNSELLOR, "2025-09-24", "observation", "Intake: dyslexieverklaring aanwezig, examenfaciliteiten aangevraagd bij de examencommissie.", "care-team-only"),
    ]
    for s, author, date, category, body, confidentiality in notes:
        care = [STUDENT_COUNSELLOR] + ([slb[s["class"]]] if slb[s["class"]] != STUDENT_COUNSELLOR else [])
        b.add("dossier-note", {"learnerId": s["nc"], "authorId": author, "date": date, "category": category, "body": body,
                               "confidentiality": confidentiality, "careTeamUserIds": care})

    # --- hour plans (timetabling-multi-year-hour-plan) --------------------------
    # One active plan per programme and intake year that has a class this year,
    # so the teaching activities of 2025-2026 list every class; a draft for the
    # next Software developer intake shows the copy. 16 contact hours per credit
    # for a unit, 28 placement hours per credit for BPV.
    year_start = int(YEAR[:4])
    for key, (pname, niveau, years, _crebo, _loc, _f) in PROGRAMMES.items():
        lines = []
        for code, pkey, lj, sem, _name, credits, _teachers, _kt, _extra in UNITS:
            if pkey == key:
                lines.append({"courseId": courses[code]["uuid"], "programmeYear": lj, "periodCode": None if sem == "J" else sem,
                              "contactHours": credits * 16, "otherHours": 0, "activityKind": "lesson"})
        for code, pkey, lj, _name, credits, _coach, _kt in BPV_UNITS:
            if pkey == key:
                lines.append({"courseId": courses[code]["uuid"], "programmeYear": lj, "periodCode": None,
                              "contactHours": 0, "otherHours": credits * 28, "activityKind": "work-placement"})
        norms = [{"programmeYear": y, "contactHours": 600, "totalHours": 1000} for y in range(1, years + 1)]
        intakes = sorted({year_start - (c[2] - 1) for c in COHORTS if c[1] == key})
        plan_rows = [(start, "active") for start in intakes]
        if key == "SD":
            plan_rows.append((year_start + 1, "draft"))
        for start, lifecycle in plan_rows:
            intake = f"{start}-{start + 1}"
            b.add("hour-plan", {
                "name": f"Urenplan {pname}, instroom {intake}", "programmeId": programmes[key]["uuid"],
                "intakeYear": intake, "durationYears": years,
                "periodsPerYear": [{"periodCode": "S1", "label": "Semester 1"}, {"periodCode": "S2", "label": "Semester 2"}],
                "lines": lines, "yearNorms": norms, "lifecycle": lifecycle,
            })

    # --- an external assessor, and the portfolios shared with him -------------
    # The examenportaal needs a person to sign in as and something to read:
    # without a share the assessor's portal is empty (invite-a-trainer-and-an-assessor).
    assessor = b.add("external-assessor", {
        "givenName": "Ruud", "familyName": "Jansen",
        "email": "ruud.jansen@examinering-vaartdam.example",
        "organisationName": "Examinering Vaartdam", "active": True,
    })
    # Two students who are actually on a placement, so the portfolios belong to
    # people the rest of the set knows.
    shared_students = [p["student"] for p in placements][:2]
    for index, s in enumerate(shared_students, start=1):
        portfolio = b.add("portfolio", {
            "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"], "kind": "course-bound",
            "title": f"Proeve van bekwaamheid {index}", "description": "Bewijsstukken voor de proeve.",
            "lifecycle": "submitted",
        })
        b.add("portfolio-entry", {
            "portfolioId": portfolio["uuid"], "learnerId": s["nc"],
            "title": "Reflectie op de proeve", "evidenceKind": "reflection",
            "reflectionText": "Ik heb de meterkast aangesloten en daarna zelf nagemeten.",
        })
        b.add("portfolio-share", {
            "portfolioId": portfolio["uuid"],
            # The readable copies ReadableCopyStamp writes on a live save.
            "portfolioTitle": portfolio["title"],
            "learnerName": f"{s['given']} {s['surname']}",
            "entryIds": [], "sharedWithKind": "external-assessor",
            "sharedWithExternalAssessorId": assessor["uuid"],
            "sharedBy": EXAM_SECRETARY,
            "expiresAt": stamp(dt.date(2026, 10, 16), 23, 59),
            "lifecycle": "active",
        })

    # --- assemble -------------------------------------------------------------
    objects = {name: rows for name, rows in b.buckets.items() if rows}
    total = sum(len(rows) for rows in objects.values())
    return {
        "openapi": "3.0.0",
        "info": {
            "title": "Learniq example set: Vocational college",
            "version": "1.0.0",
            "description": "Voorbeeldcollege Vaartveld, a fictional MBO college in the fictional town of Vaartdam, through the 2025-2026 school year.",
        },
        "x-openregister": {
            "type": "profile",
            "app": "learniq",
            "profile": {
                "id": SET,
                "segment": SET,
                "label": "Vocational education (MBO)",
                "description": "A fictional MBO college with three programmes, students, work placements and one full school year.",
                "order": 3,
                "objectCount": total,
                "icon": "BriefcaseOutline",
            },
            "description": (
                "An example set an operator picks in the first-time setup wizard (ADR-042, decision D21). NEVER imported on install. "
                "Every object carries @self.configuration/register/schema and a fixed uuid in the ee03 namespace, so the import resolves "
                "the live learniq register without this descriptor declaring components.registers (which would re-point the register at "
                "this profile config id and overwrite its authorization block), a second load adds nothing, and occ "
                "learniq:example-set:remove mbo removes exactly these objects. Generated by scripts/example-sets/mbo.py; the contract is "
                "openspec/changes/archive/2026-09-28-segment-wizard-choice/contract.md. Every person, address, college, company and code in it is fictional."
            ),
            "seedData": {
                "description": (
                    "One college with two locations, three programmes (niveau 2, 3 and 4) with their kerntaken and werkprocessen, eight "
                    "classes, 250 students, staff with a studieloopbaanbegeleider per class, a school day per class around the placement days "
                    "of 2025-2026 with the absences recorded, unit results with resits and final grades, work placements with signed "
                    "praktijkovereenkomsten, visit reports and werkproces assessments, first-year study advice and exam board cases."
                ),
                "objects": objects,
            },
        },
        "paths": {},
        "components": {},
    }


def sessions_by_uuid_date(sessions: dict[tuple[str, dt.date], dict], uuid: str) -> str:
    """The date (YYYY-MM-DD) of the session with this uuid."""
    for (_c, day), row in sessions.items():
        if row["uuid"] == uuid:
            return day.isoformat()
    raise KeyError(uuid)


def render(data: dict) -> str:
    """Pretty JSON with one compact object per line in each seed bucket (as po.py)."""
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
            print(f"{OUT} is out of date; run python3 scripts/example-sets/mbo.py", file=sys.stderr)
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
