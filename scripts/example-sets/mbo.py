#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
# Copyright (C) 2026 Conduction B.V.
"""Build lib/Settings/profiles/mbo.json, the vocational college (MBO) example set.

One fictional college, Esdoornveen in the fictional town of
Zuiddrecht, through one complete school year (2025-2026): two locations, three
programmes (Logistiek medewerker niveau 2, Verzorgende IG niveau 3, Software
developer niveau 4) with a kwalificatiedossier-style framework each, eight
classes (one per programme and leerjaar), 250 students, staff with a
studieloopbaanbegeleider per class, a timetable of school days around the work
placement days, the absences a college records, unit results with resits and
final grades, work placements (BPV) with praktijkopleiders, signed
praktijkovereenkomsten, weeks of realised BPV hours, visit reports and
werkproces assessments, the
first-year study advice (flags, warnings, a decision per first-year student),
exam board cases and a stagecoordinator on Staff.

THE STORY (example-sets-are-the-four-schools). On top of that year sits the
esdoornveen design's story, pinned to Monday 5 October 2026: a fourth
programme, Mechatronica niveau 4 (crebo 25743, the code the design board
names), with class MT4-2A in leerjaar 2; Milan de Groot on his work placement
at Bakker Techniek BV with praktijkopleider Petra Bakker and BPV-begeleider
Ruud Hermans; his weeks of hours (96 approved, 16 waiting, 8 sent back); his
tussenbeoordeling, voortgangsgesprek, exam and this week's lessons; and Aylin
Demir as Petra's second student. add_story() builds it after every other
object and draws no random number, so no earlier uuid or value moves. The
spec: openspec/changes/example-sets-are-the-four-schools/specs/example-sets/spec.md.

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
assigns; crebo codes start with 9, outside the range of the SBB dossiers,
except the story programme's 25743, which the design board prints; company
e-mail addresses use the reserved .example domain.
"""

from __future__ import annotations

import datetime as dt
import random
import sys
from zoneinfo import ZoneInfo

from example_set import Builder, Script, SetIdentity, document, main, stamp_group_labels

AMS = ZoneInfo("Europe/Amsterdam")
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
    # internship-hours. Appended, never inserted: the TTTT group of every uuid
    # is this list's position, so a slug in the middle would move every later
    # schema's uuids and `occ learniq:example-set:remove mbo` would no longer
    # name the rows it loaded. Its parent, bpv-placement, is already above it,
    # and removal runs in reverse, so the load order still holds.
    "bpv-hour-week",
    # example-sets-are-the-four-schools: the Esdoornveen story's conversation
    # with the studieloopbaanbegeleider and its exam sitting. Appended for the
    # same reason; each parent sits above its child.
    "conference-round",
    "conference-slot",
    "exam-period",
    "exam",
    "exam-sitting",
    # board-data-the-schemas-lacked: Milan's own record of his work processes
    # (hours and his estimate, board Detail). Appended for the same reason;
    # its parent, bpv-placement, sits above it.
    "werkproces-progress",
]
IDENTITY = SetIdentity(SET, SET_NUMBER, SCHEMAS)

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
TOWNS = ["Zuiddrecht", "Zuiddrecht", "Zuiddrecht", "Zuiddrecht", "Oosterkade"]

LOCATIONS = {
    "centrum": ("Locatie Centrum", "00X300", None, "Esdoornlaan 40", "0510 EZ"),
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
    "LOG": [("Distributiecentrum Zuiddrecht", "distributiecentrum-zuiddrecht"), ("Groothandel Vaartkade", "groothandel-vaartkade"),
            ("Koeltransport Zuiddrecht", "koeltransport-zuiddrecht"), ("Bouwmaterialen Zuiddrecht", "bouwmaterialen-zuiddrecht"),
            ("Webwinkel Oosterkade", "webwinkel-oosterkade")],
    "VIG": [("Zorgcentrum De Vaartoever", "zorgcentrum-vaartoever"), ("Woonzorg Vaarthof", "woonzorg-vaarthof"),
            ("Thuiszorg Zuiddrecht", "thuiszorg-zuiddrecht"), ("Verpleeghuis De Sluiswachter", "verpleeghuis-sluiswachter"),
            ("Revalidatiecentrum Oosterkade", "revalidatie-oosterkade"), ("Woongroep Polderrand", "woongroep-polderrand"),
            ("Zorgboerderij Zuiddrecht", "zorgboerderij-zuiddrecht")],
    "SD": [("Softwarehuis Zuiddrecht", "softwarehuis-zuiddrecht"), ("Webbureau Vaartsluis", "webbureau-vaartsluis"),
           ("Appstudio Oosterkade", "appstudio-oosterkade"), ("Datakade Zuiddrecht", "datakade-zuiddrecht"),
           ("Automatisering Zuiddrecht", "automatisering-zuiddrecht"), ("ICT-afdeling Ziekenhuis Zuiddrecht", "ziekenhuis-zuiddrecht")],
}

# Staff: ncUserId => (display name, roles, qualifications). Working days follow
# from the timetable, placement visits and a fixed pattern for the others.
STAFF = {
    "mbo-teamleider-01": ("Hanneke Kadewerf", ["administrator"], ["teamleider opleidingen techniek"], WEEKDAYS),
    "mbo-teamleider-02": ("Erik Sluisoord", ["administrator"], ["teamleider opleidingen zorg"], WEEKDAYS),
    "mbo-stagecoordinator-01": ("Mirjam Polderrand", ["coordinator"], ["stagecoördinator", "BPV-coördinatie alle opleidingen"], [MON, TUE, WED, THU]),
    "mbo-examencommissie-01": ("Arjen Kreekstee", ["coordinator"], ["voorzitter examencommissie"], [MON, WED, THU]),
    "mbo-examencommissie-02": ("Karin de Boer", ["coordinator"], ["secretaris examencommissie"], [MON, TUE, THU]),
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


def evaluate_final(entries: list[dict], graded_by: tuple[dict, float]) -> tuple[float | None, bool | None, dict]:
    """What GradeFormulaEvaluator computes for one learner and plan (lib/Grading), given the plan and the scale threshold.

    Published entries only; last-attempt keeps the newest entry per component;
    exemption entries are skipped; the value is the weighted average rounded to
    four decimals; passed needs the scale threshold and, for all-must-pass, an
    entry for every pass-rule component.
    """
    plan, threshold = graded_by
    published = published_entries(entries)
    if not published:
        return None, None, {}
    effective = newest_per_component(published) if plan["formula"] == "last-attempt" else published
    sums = weighted_sums(effective, plan)
    if sums.total == 0.0:
        return None, None, sums.components
    value = round(sums.weighted / sums.total, 4)
    return value, value >= threshold and meets_pass_rules(plan, effective), breakdown_of(sums)


def breakdown_of(sums: WeightedSums) -> dict:
    return {"periods": {p: round(s / w, 4) for p, (s, w) in sums.periods.items()}, "components": sums.components}


def published_entries(entries: list[dict]) -> list[dict]:
    return [e for e in entries if e["lifecycle"] == "published"]


def newest_per_component(entries: list[dict]) -> list[dict]:
    newest: dict[str, dict] = {}
    for e in entries:
        cid = e["componentId"]
        if cid not in newest or e["gradedAt"] > newest[cid]["gradedAt"]:
            newest[cid] = e
    return list(newest.values())


def weighted_sums(effective: list[dict], plan: dict) -> WeightedSums:
    sums = WeightedSums({c["componentId"]: c["weight"] for c in plan["components"]})
    for e in effective:
        sums.add(e)
    return sums


def meets_pass_rules(plan: dict, effective: list[dict]) -> bool:
    """For all-must-pass with pass rules, every rule's component needs an entry."""
    if plan["formula"] != "all-must-pass" or not plan.get("passRules"):
        return True
    present = {e["componentId"] for e in effective}
    return all(rule["componentId"] in present for rule in plan["passRules"])


class WeightedSums:
    """The running sums of one evaluation: weighted overall, per period and per component."""

    def __init__(self, weights: dict[str, float]) -> None:
        self.weights = weights
        self.total = self.weighted = 0.0
        self.periods: dict[str, list[float]] = {}
        self.components: dict[str, dict] = {}

    def add(self, e: dict) -> None:
        cid = e["componentId"]
        if e.get("sourceKind") == "exemption":
            self.components[cid] = {"exempt": True}
            return
        weight = float(self.weights.get(cid, 1))
        value = float(e["value"])
        self.weighted += value * weight
        self.total += weight
        bucket = self.periods.setdefault(e.get("period", "unknown"), [0.0, 0.0])
        bucket[0] += value * weight
        bucket[1] += weight
        self.components[cid] = {"value": value, "weight": weight, "contribution": value * weight}


UNIT_META = {code: {"programme": key, "leerjaar": lj, "semester": sem, "name": name, "credits": credits, "teachers": teachers, "extra": extra}
             for code, key, lj, sem, name, credits, teachers, _kerntaken, extra in UNITS}
# 28 placement hours per credit, the same convention HourPlan uses below.
BPV_META = {code: {"programme": key, "leerjaar": lj, "coach": coach, "kerntaken": kerntaken, "agreedHours": credits * 28}
            for code, key, lj, _name, credits, coach, kerntaken in BPV_UNITS}
PERIOD_ROWS = [{"periodId": s[0], "label": s[1], "startDate": s[2].isoformat(), "endDate": s[3].isoformat()} for s in SEMESTERS]
SLB = {c[0]: c[5] for c in COHORTS}
BOY_SHARE = {"LOG": 0.8, "VIG": 0.15, "SD": 0.85}
START_DAY = {1: dt.date(2025, 8, 18), 2: dt.date(2024, 8, 19), 3: dt.date(2023, 8, 21)}
REPLACEMENT_COMPANY = "Zorgcentrum De Vaartoever"
VISIT_TEXT = {
    "LOG": ("Werkt veilig in het magazijn en houdt zich aan de looproutes.", "Oefent verder met het scannen van inkomende zendingen."),
    "VIG": ("Neemt contact op met cliënten op een rustige, respectvolle manier.", "Rapporteert nog te kort in het zorgdossier; oefent met observeren en beschrijven."),
    "SD": ("Draait mee in de dagelijkse stand-up en pakt taken op uit het sprintbord.", "Schrijft testen bij nieuwe code voordat een taak op klaar gaat."),
}
WINDOWS = {"S1": (dt.date(2026, 1, 19), dt.date(2026, 1, 23)), "S2": (dt.date(2026, 6, 1), dt.date(2026, 6, 12)),
           "J": (dt.date(2026, 6, 8), dt.date(2026, 6, 12))}
RESIT_WINDOWS = {"S1": (dt.date(2026, 2, 9), dt.date(2026, 2, 13)), "S2": (dt.date(2026, 6, 22), dt.date(2026, 6, 26)),
                 "J": (dt.date(2026, 6, 22), dt.date(2026, 6, 26))}
HOUR_WEEK_NOTES = {
    "LOG": "Vrijdagmiddag eerder weg na het legen van de stellingen.",
    "VIG": "Donderdag twee uur eerder weg, in overleg met de teamleider.",
    "SD": "Woensdag een halve dag; de sprintdemo verviel.",
}
LEERPLICHT_THRESHOLD = {
    "name": "Leerplicht en kwalificatieplicht: 16 uur ongeoorloofd verzuim in 4 weken", "kind": "leerplicht-16uur",
    "scope": "per-learner", "window": {"type": "rolling-weeks", "weeks": 4, "termId": None}, "metric": "unexcused-lesuren",
    "limit": 16, "lessonHourMinutes": 60,
    "onCross": {"notify": True, "notifyRoles": ["mentor", "coordinator"], "createFlag": True, "dataExchangeTarget": None},
    "active": True, "lifecycle": "active",
}
PERCENT_THRESHOLD = {
    "name": "Aanwezigheid onder 80 procent in een semester", "kind": "college-aanwezigheid", "scope": "per-learner",
    "window": {"type": "fixed-term", "weeks": None, "termId": "S1"}, "metric": "attendance-percent-below", "limit": 80,
    "lessonHourMinutes": 60,
    "onCross": {"notify": True, "notifyRoles": ["mentor", "coordinator"], "createFlag": True, "dataExchangeTarget": None},
    "active": True, "lifecycle": "active",
}
# Exam board exemption requests: class, position in the class, plan, component, grounds, description,
# submitted (day, hour, minute), rationale, article of the exam rules, decision day, outcome.
EXEMPTION_REQUESTS = [
    ("SD4-2A", 3, "SD-2.3", "SD-2.3-ENG", "prior-diploma", "Havodiploma 2024 met Engels op havoniveau, hoger dan het niveau dat de opleiding vraagt.",
     (dt.date(2025, 9, 15), 10, 12), "Het diploma toont Engels aan op een hoger niveau dan de exameneis; vrijstelling voor het onderdeel Engels.",
     "7.3", dt.date(2025, 10, 6), "granted"),
    ("SD4-3A", 5, "SD-3.3", "SD-3.3-ENG", "certificate", "Certificaat Engels op niveau B2 van het Europees referentiekader, behaald in 2025.",
     (dt.date(2025, 9, 22), 9, 40), "Het certificaat is recent en ligt boven het vereiste niveau; vrijstelling voor het onderdeel Engels.",
     "7.3", dt.date(2025, 10, 6), "granted"),
    ("LOG2-2A", 7, "LOG-BPV", "LOG-BPV-PVB-B1-K1", "work-experience", "Twee zomers vakantiewerk in een distributiecentrum.",
     (dt.date(2025, 9, 29), 14, 3), "De werkervaring dekt het opslaan van goederen, maar niet het ontvangen en controleren of het voorraadbeheer. De proeve blijft nodig.",
     "7.4", dt.date(2025, 10, 20), "rejected"),
    ("VIG3-2A", 9, "VIG-2.3", "VIG-2.3-EB", "prior-diploma", "Diploma Helpende zorg en welzijn (niveau 2), behaald in 2024.",
     (dt.date(2025, 9, 8), 11, 20), "Het diploma dekt Nederlands op het vereiste niveau, maar niet het burgerschapsdeel van deze eenheid. Geen vrijstelling.",
     "7.3", dt.date(2025, 9, 29), "rejected"),
]
POSTPONED_VERDICT = {
    "decisionType": "postponed", "personalCircumstancesConsidered": True,
    "personalCircumstancesNote": "Langdurige ziekte in het voorjaar, gedocumenteerd door de studentbegeleider.",
    "rationale": "Door de ziekte kon de student de eenheid taal, rekenen en burgerschap niet afronden. Het advies wordt uitgesteld tot 1 februari 2027.",
    "studentHeardAt": stamp(dt.date(2026, 7, 1), 10, 0),
    "studentResponse": "Wil de opleiding afmaken en de eenheid in het najaar inhalen.",
}
# Support requests: whose, raised by, domain, description, urgency, lifecycle.
SUPPORT_REQUESTS = [
    ("low", SLB["SD4-1A"], "Aanwezigheid en welbevinden",
     "Aanwezigheid in semester 1 onder de 80 procent. Vraag om begeleiding bij planning, werk naast school en slaap.", "high", "closed"),
    ("struggling", STUDENT_COUNSELLOR, "Studievoortgang en planning",
     "Na de studievoortgangswaarschuwing: een studieplan voor semester 2 en hulp bij het voorbereiden van de herkansingen.", "medium", "decided"),
    ("dyslexic", STUDENT_COUNSELLOR, "Dyslexie en examenfaciliteiten",
     "Aanvraag van extra tijd en een aparte ruimte bij toetsen, op basis van de dyslexieverklaring.", "low", "decided"),
    ("terminated", "mbo-docent-05", "Begeleiding na een afgebroken stage",
     "De eerste stage stopte in november. Vraag om extra begeleiding bij de start op het nieuwe leerbedrijf.", "medium", "closed"),
]
# Dossier notes: whose, author (None: the student's studieloopbaanbegeleider), date, category, body, confidentiality.
DOSSIER_NOTES = [
    ("low", None, "2025-11-18", "concern", "Mist vaak de maandag en donderdag. Zegt dat hij veel avonden werkt in de horeca.", "care-team-only"),
    ("low", STUDENT_COUNSELLOR, "2026-01-13", "conversation", "Weekplanning gemaakt; werkuren teruggebracht naar twee avonden per week.", "care-team-only"),
    ("leerplicht", None, "2026-03-11", "phone-call-home", "Ouder gebeld over de afwezigheid deze week; geen gehoor, voicemail ingesproken.", "care-team-only"),
    ("leerplicht", ATTENDANCE_OFFICER, "2026-03-19", "conversation", "Gesprek met student en ouder. Afspraak: ziekmelden voor 08.15 uur en elke vrijdag kort contact met de SLB'er.", "care-team-only"),
    ("terminated", "mbo-docent-05", "2025-11-07", "conversation", "Stage bij het eerste leerbedrijf stopt in overleg. De student wil wel verder in de zorg.", "team-visible"),
    ("terminated", STAGECOORDINATOR, "2025-11-24", "observation", "Nieuw leerbedrijf gevonden; kennismaking was positief, start op 3 december.", "team-visible"),
    ("struggling", None, "2026-02-26", "conversation", "Waarschuwing studievoortgang besproken. Studieplan opgesteld met vaste werkmomenten op school.", "care-team-only"),
    ("struggling", None, "2026-06-30", "conversation", "Resultaten na de herkansingen besproken; de norm is niet gehaald. Gesprek over andere opleidingen gepland.", "care-team-only"),
    ("fraud", None, "2026-06-22", "conversation", "Uitspraak van de examencommissie besproken; de student maakt de opdracht opnieuw in de herkansingsweek.", "care-team-only"),
    ("VIG3-3A", None, "2026-06-29", "positive", "Alle proeven behaald en een baan aangeboden gekregen bij het leerbedrijf.", "team-visible"),
    ("SD4-3A", None, "2026-06-30", "positive", "Afstudeerproject gepresenteerd aan de opdrachtgever; mooi eindresultaat.", "team-visible"),
    ("dyslexic", STUDENT_COUNSELLOR, "2025-09-24", "observation", "Intake: dyslexieverklaring aanwezig, examenfaciliteiten aangevraagd bij de examencommissie.", "care-team-only"),
]
PROFILE = {
    "openapi": "3.0.0",
    "info": {
        "title": "Learniq example set: Vocational college",
        "version": "1.0.0",
        "description": "Esdoornveen, a fictional MBO college in the fictional town of Zuiddrecht, through the 2025-2026 school year, with a story student on his work placement in October 2026.",
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
            "objectCount": 0,
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
                "praktijkovereenkomsten, weeks of realised BPV hours with one still waiting for the praktijkopleider and one she "
                "corrected, visit reports and werkproces assessments, first-year study advice and exam board cases. On top of that "
                "year, the Esdoornveen story of October 2026: Mechatronica niveau 4 with class MT4-2A, Milan de Groot and Aylin "
                "Demir on their work placement at Bakker Techniek BV with weeks of hours, a planned tussenbeoordeling, a "
                "voortgangsgesprek, an exam sitting and the lessons of week 41."
            ),
            "objects": {},
        },
    },
    "paths": {},
    "components": {},
}
# The steps of one run, in the order they draw random numbers and add objects.
STEPS = (
    "add_college", "add_rooms", "add_frameworks", "add_competencies", "add_grade_scales", "add_programmes",
    "add_programme_courses", "add_unit_courses", "add_bpv_courses", "add_examination_plans", "add_unit_plans",
    "add_bpv_plans", "list_programme_courses", "plan_timetable", "add_cohorts", "add_staff", "add_students",
    "add_enrolments", "add_teacher_assignments", "add_report_periods", "add_trainers", "add_placements",
    "add_agreements", "add_parent_signatures", "add_visits", "add_werkproces_assessments", "settle_working_days",
    "add_sessions", "draw_marks", "mark_leerplicht_case", "mark_low_attendance_case", "add_sick_excuses",
    "add_rejected_excuse", "add_attendance_records", "add_leerplicht_flag", "add_attendance_flag",
    "add_accommodations", "add_exemption_cases", "pick_result_cases", "add_unit_results", "add_pvb_entries",
    "add_fraud_case", "add_dismissed_fraud_case", "add_final_grades", "add_trajectories", "add_progress_flags",
    "add_warnings", "add_bsa_decisions", "settle_diplomas", "add_support_requests", "add_dossier_notes",
    "add_hour_plans", "add_assessor_shares", "add_hour_weeks", "add_story_and_stamps",
)


def build() -> dict:
    return SchoolYear().build()


class SchoolYear:
    """One run of the set. The steps run in a fixed order and share one random
    generator, so every draw, and therefore the file, is the same on each run."""

    def __init__(self) -> None:
        self.rng = random.Random(20250818)
        self.b = Builder(IDENTITY)
        self.days = school_days()

    def build(self) -> dict:
        for step in STEPS:
            getattr(self, step)()
        return document(PROFILE, self.b)

    # --- college, locations, rooms ------------------------------------------
    def add_college(self) -> None:
        self.school = self.b.add("school", {"brin": "00X3", "name": "Esdoornveen", "pedagogicalConcept": "regular"})
        self.locations = {}
        for key, (name, code, olc, street, postcode) in LOCATIONS.items():
            self.locations[key] = self.b.add("vestiging", {
                "schoolId": self.school["uuid"], "vestigingscode": code, "onderwijslocatiecode": olc, "name": name,
                "street": street, "postalCode": postcode, "city": "Zuiddrecht",
            })

    def add_rooms(self) -> None:
        self.rooms = {}
        for cname, programme, _lj, _size, (room_name, room_code, kind, facilities), *_rest in COHORTS:
            loc = self.locations[PROGRAMMES[programme][4]]
            self.rooms[cname] = self.b.add("room", {
                "name": room_name, "code": room_code, "capacity": 40 if kind == "lab" else 36, "kind": kind,
                "facilities": facilities, "buildingCode": loc["vestigingscode"], "floor": room_code[1],
            })

    # --- competency frameworks: kerntaken and werkprocessen -----------------
    def add_frameworks(self) -> None:
        self.frameworks = {}
        for key, (pname, niveau, _years, crebo, _loc, fname) in PROGRAMMES.items():
            self.frameworks[key] = self.b.add("competency-framework", {
                "name": fname, "sourceAuthority": "sbb-kwalificatiedossier", "sourceRef": crebo,
                "edition": "2025", "level": "mbo",
                "description": f"Kerntaken en werkprocessen van de opleiding {pname} (niveau {niveau}), in de codering van een kwalificatiedossier. Een verzonnen voorbeeld, geen officieel dossier.",
                "proficiencyLevels": [
                    {"levelId": "nog-niet-competent", "label": "Nog niet competent", "order": 1, "minPercent": 0},
                    {"levelId": "competent", "label": "Competent", "order": 2, "minPercent": 100},
                ],
                "lifecycle": "published",
            })

    def add_competencies(self) -> None:
        self.competencies: dict[tuple[str, str], dict] = {}
        for key, kerntaken in FRAMEWORKS.items():
            order = 0
            for kerntaak in kerntaken:
                order = self.add_kerntaak(key, (order, kerntaak))

    def add_kerntaak(self, key: str, placed: tuple[int, tuple]) -> int:
        """Adds a kerntaak and its werkprocessen after `order`; returns the last order used."""
        order, (code, title, werkprocessen) = placed
        parent = self.b.add("competency", {
            "frameworkId": self.frameworks[key]["uuid"], "code": code, "title": title,
            "description": f"Kerntaak {code} van de opleiding {PROGRAMMES[key][0]}.", "order": order + 1,
            "requiredForRoles": ["learner"], "lifecycle": "published",
        })
        self.competencies[(key, code)] = parent
        for i, wp in enumerate(werkprocessen, start=1):
            self.competencies[(key, f"{code}-W{i}")] = self.b.add("competency", {
                "frameworkId": self.frameworks[key]["uuid"], "parentId": parent["uuid"], "code": f"{code}-W{i}", "title": wp,
                "description": f"Werkproces {code}-W{i}, beoordeeld in de beroepspraktijk.", "order": order + 1 + i,
                "requiredForRoles": ["learner"], "lifecycle": "published",
            })
        return order + 1 + len(werkprocessen)

    # --- grade scales ---------------------------------------------------------
    def add_grade_scales(self) -> None:
        self.numeric = self.b.add("grade-scale", {
            "name": "Cijfer 1 tot en met 10", "kind": "numeric", "min": 1, "max": 10, "passThreshold": 5.5,
            "roundingRule": "half-up-1dp", "lifecycle": "active",
        })
        self.competent_scale = self.b.add("grade-scale", {
            "name": "Competent of nog niet competent", "kind": "pass-fail", "min": 0, "max": 1, "passThreshold": 1,
            "roundingRule": "none", "lifecycle": "active",
            "bands": [
                {"bandId": "nog-niet-competent", "label": "Nog niet competent", "minValue": 0, "maxValue": 0.99, "pass": False},
                {"bandId": "competent", "label": "Competent", "minValue": 1, "maxValue": 1, "pass": True},
            ],
        })

    # --- programmes, courses, curriculum plans -------------------------------
    def add_programmes(self) -> None:
        self.programmes = {}
        for key, (pname, niveau, years, crebo, _loc, _f) in PROGRAMMES.items():
            self.programmes[key] = self.b.add("programme", {
                "name": pname, "code": f"{key}{niveau}", "level": "mbo",
                "description": f"Niveau {niveau}, beroepsopleidende leerweg (bol), {years} jaar. Kerntaken en werkprocessen volgens het voorbeeld-kwalificatiedossier met crebo {crebo}.",
                "courseIds": [], "requiredCompetencyIds": [c["uuid"] for (k, code), c in self.competencies.items() if k == key and "-W" in code],
                "lifecycle": "published",
            })

    def add_programme_courses(self) -> None:
        self.courses: dict[str, dict] = {}
        for key, (pname, niveau, years, _crebo, _loc, _f) in PROGRAMMES.items():
            self.courses[f"{key}-OPL"] = self.b.add("course", {
                "code": f"{key}-OPL", "name": f"{pname} (niveau {niveau}, bol)", "name_nl": f"{pname} (niveau {niveau}, bol)",
                "description": f"Inschrijving voor de opleiding {pname}, {years} jaar.", "level": "mbo", "language": "nl",
                "tags": ["mbo", f"niveau {niveau}", "bol"], "lifecycle": "published", "order": 0,
                "programmeIds": [self.programmes[key]["uuid"]], "competencyIds": [], "prerequisiteCourseIds": [],
            })
        self.order = {k: 0 for k in PROGRAMMES}

    def add_unit_courses(self) -> None:
        for code, key, lj, sem, name, credits, _teachers, kerntaken, _extra in UNITS:
            self.order[key] += 1
            self.courses[code] = self.b.add("course", {
                "code": code, "name": name, "name_nl": name,
                "description": f"Onderwijseenheid in leerjaar {lj} van {PROGRAMMES[key][0]}, " + ("het hele jaar." if sem == "J" else f"semester {sem[1]}."),
                "level": "mbo", "language": "nl", "tags": ["mbo", f"niveau {PROGRAMMES[key][1]}", f"leerjaar {lj}"],
                "lifecycle": "published", "parentCourseId": self.courses[f"{key}-OPL"]["uuid"], "order": self.order[key],
                "programmeIds": [self.programmes[key]["uuid"]], "ectsCredits": credits,
                "competencyIds": [self.competencies[(key, k)]["uuid"] for k in kerntaken], "prerequisiteCourseIds": [],
            })

    def add_bpv_courses(self) -> None:
        for code, key, lj, name, credits, _coach, kerntaken in BPV_UNITS:
            self.order[key] += 1
            self.courses[code] = self.b.add("course", {
                "code": code, "name": name, "name_nl": name,
                "description": f"Beroepspraktijkvorming in leerjaar {lj}: werkprocessen van {', '.join(kerntaken)} bij een erkend leerbedrijf.",
                "level": "mbo", "language": "nl", "tags": ["mbo", "bpv", f"leerjaar {lj}"], "lifecycle": "published",
                "parentCourseId": self.courses[f"{key}-OPL"]["uuid"], "order": self.order[key], "programmeIds": [self.programmes[key]["uuid"]],
                "ectsCredits": credits, "competencyIds": [self.competencies[(key, k)]["uuid"] for k in kerntaken], "prerequisiteCourseIds": [],
            })

    def add_examination_plans(self) -> None:
        self.plans: dict[str, dict] = {}
        for key, (pname, *_rest) in PROGRAMMES.items():
            unit_codes = programme_unit_codes(key)
            self.plans[f"{key}-OER"] = self.b.add("curriculum-plan", {
                "name": f"Examenplan {pname}, {YEAR}", "kind": "oer",
                "requiredCourseIds": [self.courses[c]["uuid"] for c in unit_codes], "electiveCourseIds": [],
                "components": examination_components(unit_codes), "formula": "all-must-pass", "gradeScaleId": self.numeric["uuid"], "passRules": [],
                "periods": PERIOD_ROWS, "lifecycle": "published",
            })
            self.programmes[key]["curriculumPlanId"] = self.plans[f"{key}-OER"]["uuid"]
            self.courses[f"{key}-OPL"]["curriculumPlanId"] = self.plans[f"{key}-OER"]["uuid"]

    def add_unit_plans(self) -> None:
        for code, meta in UNIT_META.items():
            components = unit_plan_components(code)
            self.plans[code] = self.b.add("curriculum-plan", {
                "name": f"{code} {meta['name']}, {YEAR}", "kind": "oer", "requiredCourseIds": [self.courses[code]["uuid"]],
                "electiveCourseIds": [], "components": components, "formula": "last-attempt", "gradeScaleId": self.numeric["uuid"],
                "passRules": [{"componentId": c["componentId"], "minValue": 5.5} for c in components],
                "periods": unit_periods(meta["semester"]),
                "lifecycle": "published",
            })
            self.courses[code]["curriculumPlanId"] = self.plans[code]["uuid"]

    def add_bpv_plans(self) -> None:
        for code, meta in BPV_META.items():
            components = pvb_components(code)
            self.plans[code] = self.b.add("curriculum-plan", {
                "name": f"Proeven van bekwaamheid {PROGRAMMES[meta['programme']][0]} leerjaar {meta['leerjaar']}, {YEAR}", "kind": "oer",
                "requiredCourseIds": [self.courses[code]["uuid"]], "electiveCourseIds": [], "components": components,
                "formula": "all-must-pass", "gradeScaleId": self.competent_scale["uuid"],
                "passRules": [{"componentId": c["componentId"], "minValue": 1} for c in components],
                "periods": PERIOD_ROWS, "lifecycle": "published",
            })
            self.courses[code]["curriculumPlanId"] = self.plans[code]["uuid"]

    def list_programme_courses(self) -> None:
        for key in PROGRAMMES:
            self.programmes[key]["courseIds"] = [c["uuid"] for code, c in self.courses.items() if code.startswith(key + "-")]

    # --- timetable: which unit a class has on which school day --------------
    def plan_timetable(self) -> None:
        self.cohort_days: dict[str, list[tuple[dt.date, str]]] = {}
        for cname, _p, _lj, _size, _room, _slb, timetable, _bpv in COHORTS:
            self.cohort_days[cname] = lesson_days(timetable, self.days)
        self.teaching_days: dict[str, set[str]] = {nc: set() for nc in STAFF}
        for cname, *_rest in COHORTS:
            for day, unit in self.cohort_days[cname]:
                self.teaching_days[session_teacher(unit, day)].add(WEEKDAYS[day.weekday()])

    # --- classes (cohorts) ------------------------------------------------------
    def add_cohorts(self) -> None:
        self.cohorts = {}
        for cname, key, lj, _size, _room, slb, timetable, bpv in COHORTS:
            self.cohorts[cname] = self.b.add("cohort", {
                "name": cname, "programmeId": self.programmes[key]["uuid"], "courseId": self.courses[f"{key}-OPL"]["uuid"],
                "teacherIds": [], "learnerIds": [], "period": "Schooljaar", "academicYear": YEAR,
                "lifecycle": "completed" if lj == PROGRAMMES[key][2] else "active",
                "locationId": self.locations[PROGRAMMES[key][4]]["uuid"],
                "teacherAssignments": [{"teacherId": slb, "role": "primary", "days": weekdays_of(timetable)}],
                "notes": cohort_notes(bpv), "kind": "teaching", "programmeYear": lj,
            })

    # --- staff (working days filled in once visits are planned) -------------
    def add_staff(self) -> None:
        self.staff_rows = {}
        for nc, (_name, roles, quals, fixed) in STAFF.items():
            self.staff_rows[nc] = self.b.add("staff", {"ncUserId": nc, "roles": roles, "qualifications": quals, "workingDays": list(fixed)})

    # --- students -----------------------------------------------------------
    def add_students(self) -> None:
        students = self.new_students()
        students.sort(key=lambda s: ([c[0] for c in COHORTS].index(s["class"]), s["surname"], s["given"]))
        for n, s in enumerate(students, start=1):
            self.add_learner(s, n)
        self.students = students
        self.student_by_nc = {s["nc"]: s for s in students}
        self.group_by_class()

    def new_students(self) -> list[dict]:
        self.used_names: set[tuple[str, str]] = set()
        students: list[dict] = []
        for cohort in COHORTS:
            students.extend(self.new_student(cohort) for _ in range(cohort[3]))
        return students

    def group_by_class(self) -> None:
        self.by_class: dict[str, list[dict]] = {}
        for s in self.students:
            self.by_class.setdefault(s["class"], []).append(s)
        for cname, cohort in self.cohorts.items():
            cohort["learnerIds"] = [s["nc"] for s in self.by_class[cname]]

    def new_student(self, cohort: tuple) -> dict:
        cname, key, lj = cohort[0], cohort[1], cohort[2]
        rng = self.rng
        boy = rng.random() < BOY_SHARE[key]
        extra = rng.choices([0, 1, 2, 4], weights=[68, 22, 7, 3])[0]
        birth = dt.date(2009 - (lj - 1) - extra, 1, 1) + dt.timedelta(days=rng.randrange(365))
        given, surname = self.unused_name(boy)
        return {"class": cname, "programme": key, "leerjaar": lj, "boy": boy, "given": given, "surname": surname,
                "birth": birth, "ability": rng.gauss(0, 1), "punctual": rng.random()}

    def unused_name(self, boy: bool) -> tuple[str, str]:
        while True:
            given = self.rng.choice(BOYS if boy else GIRLS)
            surname = self.rng.choice(SURNAME_HEAD) + self.rng.choice(SURNAME_TAIL)
            if (given, surname) not in self.used_names:
                self.used_names.add((given, surname))
                return given, surname

    def add_learner(self, s: dict, n: int) -> None:
        s["nc"] = f"mbo-student-{n:03d}"
        s["minor"] = age_on(s["birth"], FIRST_DAY) < 18
        address = self.draw_address()
        contacts = self.draw_contacts(s)
        s["profile"] = self.b.add("learner-profile", {
            "ncUserId": s["nc"], "givenName": s["given"], "familyName": s["surname"], "birthDate": s["birth"].isoformat(),
            "schoolId": self.school["uuid"], "eduPersonAffiliation": ["student"], "roles": ["learner"],
            # A minor's parent (the emergency contact) has an account, so they can co-sign a praktijkovereenkomst.
            "parentIds": ([f"mbo-ouder-{n:03d}"] if s["minor"] else []),
            "guardianRefs": [], "address": address, "emergencyContacts": contacts, **self.draw_care_and_consent(), "lifecycle": "active",
        })

    def draw_care_and_consent(self) -> dict:
        rng = self.rng
        return {
            "allergies": (["noten"] if rng.random() < 0.04 else None),
            "medicalConditions": (["diabetes type 1"] if rng.random() < 0.02 else None),
            "beeldmateriaalConsent": {k: rng.random() < 0.75 for k in ["website", "socialMedia", "schoolgids", "classPhoto", "video"]},
        }

    def draw_address(self) -> dict:
        rng = self.rng
        town = rng.choice(TOWNS)
        return {"street": rng.choice(STREETS), "houseNumber": str(rng.randint(1, 180)),
                "postalCode": f"05{rng.randint(10, 39)} {rng.choice('ABDEGHKLMNPRSTWZ')}{rng.choice('ABDEGHKLMNPRSTWZ')}",
                "city": town, "country": "NL"}

    def draw_contacts(self, s: dict) -> list[dict]:
        rng = self.rng
        if s["minor"]:
            female = rng.random() < 0.6
            return [{"name": f"{rng.choice(ADULT_F if female else ADULT_M)} {s['surname']}", "relationship": "ouder",
                     "phone": f"06-0000{rng.randint(1000, 9999)}", "priority": 1}]
        if rng.random() < 0.5:
            return [{"name": f"{rng.choice(ADULT_F + ADULT_M)} {s['surname']}", "relationship": rng.choice(["ouder", "partner"]),
                     "phone": f"06-0000{rng.randint(1000, 9999)}", "priority": 1}]
        return []

    # --- enrolments (lifecycle settled at the end of the year) --------------
    def add_enrolments(self) -> None:
        by_start = sorted(self.students, key=lambda s: (START_DAY[s["leerjaar"]], s["nc"]))
        for volgnummer, s in enumerate(by_start, start=1):
            s["volgnummer"] = volgnummer
        for s in self.students:
            cohort = self.cohorts[s["class"]]
            s["enrolment"] = self.b.add("enrolment", {
                "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"], "courseId": self.courses[f"{s['programme']}-OPL"]["uuid"],
                "source": "admission", "cohortId": cohort["uuid"], "lifecycle": "active",
                "inschrijvingDate": START_DAY[s["leerjaar"]].isoformat(), "volgnummer": s["volgnummer"],
                "locationId": cohort["locationId"], "leerjaar": s["leerjaar"],
            })

    # --- subject teachers -----------------------------------------------------
    def add_teacher_assignments(self) -> None:
        for cname, _key, _lj, _size, _room, slb, timetable, bpv in COHORTS:
            teacher_ids = [slb]
            for unit in taught_units(timetable):
                self.assign_unit_teachers(cname, (unit, teacher_ids))
            if bpv is not None:
                self.assign_unit_teachers(cname, (bpv[0], teacher_ids))
            self.cohorts[cname]["teacherIds"] = teacher_ids

    def assign_unit_teachers(self, cname: str, assigned: tuple[str, list[str]]) -> None:
        """Assigns a unit's teachers (a BPV unit: its coach) to the class and collects them in teacher_ids."""
        unit, teacher_ids = assigned
        teachers = [BPV_META[unit]["coach"]] if unit in BPV_META else UNIT_META[unit]["teachers"]
        for teacher in teachers:
            self.b.add("subjectteacherassignment", {"cohortId": self.cohorts[cname]["uuid"], "courseId": self.courses[unit]["uuid"], "teacherId": teacher})
            if teacher not in teacher_ids:
                teacher_ids.append(teacher)

    # --- report periods (the semester calendar) -----------------------------
    def add_report_periods(self) -> None:
        for pid, label, start, end in SEMESTERS:
            plan_ids = self.plans_in(pid)
            self.b.add("report-period", {
                "name": label, "academicYear": YEAR, "periodCode": pid, "startDate": start.isoformat(), "endDate": end.isoformat(),
                "curriculumPlanIds": plan_ids, "cohortIds": [c["uuid"] for c in self.cohorts.values()],
                "lockDate": stamp(end + dt.timedelta(days=14), 17, 0), "attendanceIncluded": True, "lifecycle": "archived",
                "holidays": holidays_between(start, end), "studyDays": study_days_between(start, end),
            })

    def plans_in(self, pid: str) -> list[str]:
        """The plans a semester reports on: its units, the whole-year units and every BPV unit."""
        return [self.plans[c]["uuid"] for c, m in UNIT_META.items() if m["semester"] in (pid, "J")] + [self.plans[c]["uuid"] for c in BPV_META]

    # --- praktijkopleiders ----------------------------------------------------
    def add_trainers(self) -> None:
        self.trainers: dict[str, list[dict]] = {}
        self.company_codes: dict[str, tuple[str, str]] = {}
        serial = 0
        for key, companies in COMPANIES.items():
            for name, domain in companies:
                serial += 1
                self.company_codes[name] = (f"0000{serial:04d}", f"000{serial + 40:05d}")
                self.trainers[name] = [self.add_trainer(key, (name, domain)) for _ in range(2)]

    def add_trainer(self, key: str, company: tuple[str, str]) -> dict:
        name, domain = company
        female = self.rng.random() < (0.7 if key == "VIG" else 0.3)
        given = self.rng.choice(ADULT_F if female else ADULT_M)
        family = self.rng.choice(SURNAME_HEAD) + self.rng.choice(SURNAME_TAIL)
        return self.b.add("praktijkopleider", {
            "givenName": given, "familyName": family,
            "email": f"{given.lower()}.{family.lower()}@{domain}.example", "phone": f"06-0000{self.rng.randint(1000, 9999)}",
            "trainingCompanyName": name, "trainingCompanyKvkNumber": self.company_codes[name][0], "active": True,
        })

    # --- placements, praktijkovereenkomsten, signatures, visits, assessments --
    def add_placements(self) -> None:
        self.placements: list[dict] = []
        self.terminated_student: dict | None = None
        for cohort in COHORTS:
            if cohort[7] is not None:
                self.place_class(cohort)

    def place_class(self, cohort: tuple) -> None:
        for i, s in enumerate(self.by_class[cohort[0]]):
            for spell in self.spells_of(cohort, (i, s)):
                self.add_placement(s, (cohort, spell))

    def spells_of(self, cohort: tuple, member: tuple[int, dict]) -> list[tuple]:
        """The student's placement spells: one, or a terminated one and its replacement."""
        i, s = member
        cname, key, (_unit, _weekdays, p_from, p_to) = cohort[0], cohort[1], cohort[7]
        companies = COMPANIES[key]
        company = companies[i % len(companies)][0]
        trainer = self.trainers[company][(i // len(companies)) % 2]
        if cname == "VIG3-2A" and self.terminated_student is None and company == "Woonzorg Vaarthof":
            self.terminated_student = s
            return [(company, trainer, p_from, dt.date(2025, 11, 14), "terminated"),
                    (REPLACEMENT_COMPANY, self.trainers[REPLACEMENT_COMPANY][1], dt.date(2025, 12, 3), p_to, "completed")]
        return [(company, trainer, p_from, p_to, "completed")]

    def add_placement(self, s: dict, placed: tuple[tuple, tuple]) -> None:
        cohort, (company, trainer, f, t, state) = placed
        unit, weekdays_by_sem = cohort[7][0], cohort[7][1]
        row = self.b.add("bpv-placement", self.placement_fields(s, placed))
        self.placements.append({"row": row, "student": s, "unit": unit, "weekdays": weekdays_of(weekdays_by_sem), "from": f, "to": t,
                                "state": state, "trainer": trainer, "company": company, "coach": BPV_META[unit]["coach"], "class": cohort[0]})

    def placement_fields(self, s: dict, placed: tuple[tuple, tuple]) -> dict:
        cohort, (company, trainer, f, t, state) = placed
        unit = cohort[7][0]
        kvk, erkenning = self.company_codes[company]
        return {
            "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"], "programmeId": self.programmes[cohort[1]]["uuid"],
            "curriculumPlanId": self.plans[unit]["uuid"], "practicalTrainerId": trainer["uuid"], "schoolCoachId": BPV_META[unit]["coach"],
            "trainingCompanyName": company, "trainingCompanyKvkNumber": kvk, "periodFrom": f.isoformat(), "periodTo": t.isoformat(),
            # What the praktijkovereenkomst states, so "312 van 640" has
            # a real denominator (internship-hours).
            "agreedHours": BPV_META[unit]["agreedHours"], "hoursApprovedTotal": 0,
            "trainingCompanyVerification": {"provider": "sbb", "status": "verified", "erkenningNumber": erkenning,
                                            "verifiedAt": stamp(f - dt.timedelta(days=21), 10, 0),
                                            "expiresAt": stamp(dt.date(2027, 12, 31), 23, 59)},
            "lifecycle": state,
        }

    def add_agreements(self) -> None:
        for p in self.placements:
            p["pok"] = self.b.add("praktijkovereenkomst", agreement_fields(p))
            for signer, role, before, hour, level, method in signers_of(p):
                self.b.add("pok-signature", {
                    "subjectId": p["pok"]["uuid"], "subjectVersion": 1, "signerId": signer, "signerRole": role,
                    "signedAt": stamp(p["from"] - dt.timedelta(days=before), hour, 5), "assuranceLevel": level, "method": method,
                })

    # Parent co-signatures, appended after the three standing signatures so no earlier uuid moves.
    def add_parent_signatures(self) -> None:
        for p in self.placements:
            if p["pok"]["parentSignatureRequired"]:
                self.b.add("pok-signature", {
                    "subjectId": p["pok"]["uuid"], "subjectVersion": 1, "signerId": p["student"]["profile"]["parentIds"][0],
                    "signerRole": "parent", "signedAt": stamp(p["from"] - dt.timedelta(days=11), 20, 5), "assuranceLevel": "basic",
                    "method": "Nextcloud-account",
                })

    def add_visits(self) -> None:
        self.visits_by_coach: dict[str, set[str]] = {}
        for p in self.placements:
            for planned in self.planned_visits(p):
                self.add_visit(p, planned)

    def planned_visits(self, p: dict) -> list[tuple[str, dt.date]]:
        if p["state"] == "terminated":
            return [("voortgangsbezoek", p["from"] + dt.timedelta(weeks=5)), ("incident", dt.date(2025, 11, 7))]
        visits = [("voortgangsbezoek", p["from"] + dt.timedelta(weeks=6))]
        # A mid-term conversation only where the workplace asks for one.
        if (p["to"] - p["from"]).days > 250 and self.rng.random() < 0.2:
            visits.append(("tussentijds-gesprek", dt.date(2026, 2, 11)))
        visits.append(("eindgesprek", p["to"] - dt.timedelta(weeks=2)))
        return visits

    def add_visit(self, p: dict, planned: tuple[str, dt.date]) -> None:
        kind, target = planned
        day = placement_day_near(target, p)
        self.visits_by_coach.setdefault(p["coach"], set()).add(WEEKDAYS[day.weekday()])
        narrative, actions = visit_texts(kind, p["student"]["programme"])
        self.b.add("bpv-visit-report", {
            "bpvPlacementId": p["row"]["uuid"], "learnerRef": p["student"]["profile"]["uuid"], "visitDate": day.isoformat(),
            "visitKind": kind, "attendees": visit_attendees(p), "schoolCoachId": p["coach"], "narrative": narrative,
            "actionPoints": actions, "lifecycle": "finalized",
        })

    # Werkproces assessments: most competent at once, some after a retake, a
    # few not yet competent at the end of the year.
    def add_werkproces_assessments(self) -> None:
        completed = [p for p in self.placements if p["state"] == "completed"]
        self.pick_retakes(completed)
        self.pvb_results: list[dict] = []
        for p in completed:
            for kerntaak in BPV_META[p["unit"]]["kerntaken"]:
                self.assess_kerntaak(p, kerntaak)

    def pick_retakes(self, completed: list[dict]) -> None:
        pool = [(p, k) for p in completed for k in BPV_META[p["unit"]]["kerntaken"]]
        self.rng.shuffle(pool)
        self.retakes = {(id(p), k) for p, k in pool[:18]}
        self.fail_final = {(id(p), k) for p, k in pool[18:21]}

    def assess_kerntaak(self, p: dict, kerntaak: str) -> None:
        window = ASSESSMENT_WINDOWS[(p["unit"], kerntaak)]
        day = placement_day_near(window + dt.timedelta(days=self.rng.randrange(3)), p)
        rows = sorted(self.kerntaak_rows(p, (kerntaak, day)), key=lambda r: r["assessedAt"])
        added = [self.b.add("werkproces-assessment", {k: v for k, v in r.items() if v is not None}) for r in rows]
        last = added[-1]
        self.pvb_results.append({"placement": p, "kerntaak": kerntaak, "last": last,
                                 "value": 1.0 if last["assessment"] == "competent" else 0.0})

    def kerntaak_rows(self, p: dict, assessed: tuple[str, dt.date]) -> list[dict]:
        kerntaak, day = assessed
        werkprocessen = werkprocessen_of(p["student"]["programme"], kerntaak)
        return [row for i, label in enumerate(werkprocessen, start=1)
                for row in self.werkproces_rows(p, (kerntaak, i, label, day, i == len(werkprocessen)))]

    def werkproces_rows(self, p: dict, werkproces: tuple) -> list[dict]:
        """One werkproces's assessments; the last werkproces of a kerntaak may need a retake or stay not yet competent."""
        kerntaak, i, label, day, is_last = werkproces
        base = self.werkproces_base(p, (kerntaak, i, label))
        if is_last and (id(p), kerntaak) in self.retakes:
            first = placement_day_near(day - dt.timedelta(weeks=3), p)
            return [dict(base, assessedAt=first.isoformat(), assessment="nog-niet-competent",
                         notes="Voert het werkproces nog niet zelfstandig uit; herbeoordeling over drie weken afgesproken."),
                    dict(base, assessedAt=day.isoformat(), assessment="competent",
                         notes="Herbeoordeling: voert het werkproces nu zelfstandig en volgens de afspraken uit.")]
        if is_last and (id(p), kerntaak) in self.fail_final:
            return [dict(base, assessedAt=day.isoformat(), assessment="nog-niet-competent",
                         notes="Nog niet zelfstandig; de proeve wordt in het volgende schooljaar opnieuw afgenomen.")]
        earlier = placement_day_near(day - dt.timedelta(days=7), p)
        return [dict(base, assessedAt=earlier.isoformat(), assessment="competent", notes=None)]

    def werkproces_base(self, p: dict, werkproces: tuple[str, int, str]) -> dict:
        kerntaak, i, label = werkproces
        key, code = p["student"]["programme"], f"{kerntaak}-W{i}"
        return {
            "bpvPlacementId": p["row"]["uuid"], "curriculumPlanId": self.plans[p["unit"]]["uuid"],
            "componentId": f"{p['unit']}-PVB-{kerntaak}", "kwalificatiedossierCode": PROGRAMMES[key][3], "coreTaskCode": kerntaak,
            "werkprocesCode": code, "werkprocesLabel": label, "competencyId": self.competencies[(key, code)]["uuid"],
            "assessorId": p["trainer"]["uuid"], "lifecycle": "confirmed",
        }

    # Working days: teaching days, placement visits, and the fixed pattern for the rest.
    def settle_working_days(self) -> None:
        for nc, row in self.staff_rows.items():
            if not STAFF[nc][3]:
                days_set = self.teaching_days[nc] | self.visits_by_coach.get(nc, set())
                row["workingDays"] = [d for d in WEEKDAYS if d in days_set]

    # --- sessions ---------------------------------------------------------------
    def add_sessions(self) -> None:
        self.sessions: dict[tuple[str, dt.date], dict] = {}
        for cname, *_rest in COHORTS:
            for lesson in self.cohort_days[cname]:
                self.sessions[(cname, lesson[0])] = self.add_session(cname, lesson)

    def add_session(self, cname: str, lesson: tuple[dt.date, str]) -> dict:
        day, unit = lesson
        friday, room = day.weekday() == 4, self.rooms[cname]
        return self.b.add("session", {
            "cohortId": self.cohorts[cname]["uuid"], "courseId": self.courses[unit]["uuid"],
            "title": f"{cname}, {UNIT_META[unit]['name']}, {dutch_date(day)}",
            "startsAt": stamp(day, 9, 0) if friday else stamp(day, 8, 30),
            "endsAt": stamp(day, 13, 0) if friday else stamp(day, 15, 0),
            "location": room["name"], "roomId": room["uuid"], "lifecycle": "completed",
        })

    def teacher_of(self, cname: str, day: dt.date) -> str:
        return session_teacher(dict(self.cohort_days[cname])[day], day)

    # --- attendance marks: exceptions only ------------------------------------
    def draw_marks(self) -> None:
        self.marks: dict[str, dict[dt.date, tuple[str, str, int]]] = {}
        for s in self.students:
            own = [d for d, _u in self.cohort_days[s["class"]]]
            pm: dict[dt.date, tuple[str, str, int]] = {}
            self.mark_sick_spells(own, pm)
            self.mark_left_early(own, pm)
            self.mark_late(s, (own, pm))
            self.mark_unexcused(own, pm)
            self.marks[s["nc"]] = pm

    def mark_sick_spells(self, own: list[dt.date], pm: dict) -> None:
        winter = winter_days(own)
        for _ in range(min(4, max(0, int(self.rng.gauss(1.2, 1.1))))):
            i = own.index(self.sick_start(own, winter))
            for d in own[i:i + self.rng.choices([1, 2, 3], weights=[45, 35, 20])[0]]:
                pm[d] = ("absent-excused", "Ziek gemeld", 0)

    def sick_start(self, own: list[dt.date], winter: list[dt.date]) -> dt.date:
        return self.rng.choice(winter if winter and self.rng.random() < 0.6 else own)

    def mark_left_early(self, own: list[dt.date], pm: dict) -> None:
        for _ in range(self.rng.choices([0, 1, 2], weights=[60, 30, 10])[0]):
            d = self.rng.choice(own)
            if d not in pm:
                pm[d] = ("left-early", self.rng.choice(["Huisartsafspraak", "Tandarts", "Afspraak in het ziekenhuis"]), lesson_minutes(d) - 120)

    def mark_late(self, s: dict, marked: tuple[list[dt.date], dict]) -> None:
        own, pm = marked
        lates = self.rng.choices([0, 1, 2, 3, 6], weights=[45, 25, 15, 10, 5])[0] if s["punctual"] < 0.85 else 0
        for _ in range(lates):
            d = self.rng.choice(own)
            if d not in pm:
                pm[d] = ("late", self.rng.choice(["Te laat, bus of trein vertraagd", "Te laat binnengekomen"]), lesson_minutes(d) - self.rng.choice([10, 15, 20, 30]))

    def mark_unexcused(self, own: list[dt.date], pm: dict) -> None:
        if self.rng.random() < 0.18:
            for _ in range(self.rng.randint(1, 2)):
                d = self.rng.choice(own)
                if d not in pm:
                    pm[d] = ("absent-unexcused", "Niet afgemeld", 0)

    # The leerplicht case: a minor in LOG2-1A misses a week in March unexcused.
    def mark_leerplicht_case(self) -> None:
        self.leerplicht = next(s for s in self.by_class["LOG2-1A"] if age_on(s["birth"], dt.date(2026, 3, 31)) < 17)
        self.lp_days = days_between([d for d, _u in self.cohort_days["LOG2-1A"]], (dt.date(2026, 3, 9), dt.date(2026, 3, 13)))
        for d in self.lp_days:
            self.marks[self.leerplicht["nc"]][d] = ("absent-unexcused", "Niet afgemeld; ouder niet bereikbaar", 0)

    # The attendance case: an adult SD4-1A student drops below 80 percent in semester 1.
    def mark_low_attendance_case(self) -> None:
        self.low = next(s for s in self.by_class["SD4-1A"] if not s["minor"])
        self.s1_days = self.lesson_days_until("SD4-1A", S1_END)
        for d in self.s1_days[20:36]:
            mark = low_attendance_mark(d)
            if mark:
                self.marks[self.low["nc"]][d] = mark

    def lesson_days_until(self, cname: str, last: dt.date) -> list[dt.date]:
        return [d for d, _u in self.cohort_days[cname] if d <= last]

    # Students report illness themselves; the attendance officer decides.
    def add_sick_excuses(self) -> None:
        self.excuses: dict[tuple[str, dt.date], dict] = {}
        for s in self.rng.sample(self.excuse_owners(), 16):
            run = self.first_sick_run(s)
            req = self.b.add("excuse-request", {
                "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"], "submittedBy": s["nc"], "submittedByRef": s["profile"]["uuid"],
                "dateFrom": run[0].isoformat(), "dateTo": run[-1].isoformat(),
                "reason": self.rng.choice(["Griep", "Koorts", "Buikgriep", "Migraine"]), "reasonKind": "illness", "submittedAuthLevel": "basic",
                "decidedBy": ATTENDANCE_OFFICER, "decidedAt": stamp(run[0], 11, 0), "lifecycle": "approved",
            })
            for d in run:
                self.excuses[(s["nc"], d)] = req

    def excuse_owners(self) -> list[dict]:
        return [s for s in self.students if s is not self.leerplicht and s is not self.low and self.was_sick(s)]

    def was_sick(self, s: dict) -> bool:
        return any(m[0] == "absent-excused" for m in self.marks[s["nc"]].values())

    def first_sick_run(self, s: dict) -> list[dt.date]:
        """The first run of consecutive lesson days the student was ill."""
        spell = self.sick_days(s)
        own = [d for d, _u in self.cohort_days[s["class"]]]
        run = [spell[0]]
        for d in spell[1:]:
            if own.index(d) != own.index(run[-1]) + 1:
                break
            run.append(d)
        return run

    def sick_days(self, s: dict) -> list[dt.date]:
        return sorted(d for d, m in self.marks[s["nc"]].items() if m[0] == "absent-excused")

    def add_rejected_excuse(self) -> None:
        owner, day = self.rejected_request()
        self.marks[owner["nc"]][day] = ("absent-unexcused", "Verlof voor rijexamen niet toegekend", 0)
        self.excuses[(owner["nc"], day)] = self.b.add("excuse-request", {
            "learnerId": owner["nc"], "learnerRef": owner["profile"]["uuid"], "submittedBy": owner["nc"],
            "submittedByRef": owner["profile"]["uuid"], "dateFrom": day.isoformat(), "dateTo": day.isoformat(),
            "reason": "Rijexamen", "reasonKind": "other", "submittedAuthLevel": "basic", "decidedBy": ATTENDANCE_OFFICER,
            "decidedAt": stamp(day - dt.timedelta(days=4), 11, 0),
            "decisionNote": "Een rijexamen valt buiten de verlofregeling; plan het buiten lestijd of op een dag zonder lessen.",
            "lifecycle": "rejected",
        })

    def rejected_request(self) -> tuple[dict, dt.date]:
        """A VIG3-3A student without an excuse asks leave for a driving test on a day without a mark."""
        excused = {k[0] for k in self.excuses}
        owner = next(s for s in self.by_class["VIG3-3A"] if s["nc"] not in excused)
        return owner, self.free_day_from(owner, dt.date(2026, 3, 23))

    def free_day_from(self, s: dict, first: dt.date) -> dt.date:
        """The student's first lesson day from `first` without an attendance mark."""
        return next(d for d, _u in self.cohort_days[s["class"]] if d >= first and d not in self.marks[s["nc"]])

    def add_attendance_records(self) -> None:
        self.records_by_student: dict[str, list[dict]] = {}
        for s in self.students:
            for d in sorted(self.marks[s["nc"]]):
                self.records_by_student.setdefault(s["nc"], []).append(self.add_attendance_record(s, d))

    def add_attendance_record(self, s: dict, d: dt.date) -> dict:
        status, reason, minutes = self.marks[s["nc"]][d]
        return self.b.add("attendance-record", {
            "sessionId": self.sessions[(s["class"], d)]["uuid"], "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"],
            "cohortId": self.cohorts[s["class"]]["uuid"], "status": status, "minutesAttended": minutes,
            "markedBy": self.teacher_of(s["class"], d), "markedAt": marked_at(d, status), "reason": reason,
            "excuseRequestId": (self.excuses[(s["nc"], d)]["uuid"] if (s["nc"], d) in self.excuses else None),
        })

    def add_leerplicht_flag(self) -> None:
        self.threshold_lp = self.b.add("attendance-threshold", LEERPLICHT_THRESHOLD)
        self.threshold_pct = self.b.add("attendance-threshold", PERCENT_THRESHOLD)
        mentor = self.cohorts["LOG2-1A"]["teacherAssignments"][0]["teacherId"]
        self.b.add("attendance-flag", {
            "learnerId": self.leerplicht["nc"], "attendanceThresholdId": self.threshold_lp["uuid"], "cohortId": self.cohorts["LOG2-1A"]["uuid"],
            "windowStart": "2026-03-02", "windowEnd": "2026-03-27",
            "metricValue": round(sum(lesson_minutes(d) for d in self.lp_days) / 60, 2), "breachingRecordIds": [r["uuid"] for r in self.leerplicht_records()],
            "mentorId": mentor, "flagKind": "signal-verzuim", "lifecycle": "resolved",
            "interventions": leerplicht_interventions(mentor),
        })

    def leerplicht_records(self) -> list[dict]:
        return [r for r in self.records_by_student[self.leerplicht["nc"]] if r["status"] == "absent-unexcused"
                and dt.date(2026, 3, 9) <= self.session_day(r) <= dt.date(2026, 3, 13)]

    def session_day(self, record: dict) -> dt.date:
        return dt.date.fromisoformat(sessions_by_uuid_date(self.sessions, record["sessionId"]))

    def add_attendance_flag(self) -> None:
        absent_s1 = [r for r in self.records_by_student[self.low["nc"]] if self.session_day(r) <= S1_END and r["status"].startswith("absent")]
        pct = round(100 * (len(self.s1_days) - len(absent_s1)) / len(self.s1_days), 1)
        mentor = self.cohorts["SD4-1A"]["teacherAssignments"][0]["teacherId"]
        self.b.add("attendance-flag", {
            "learnerId": self.low["nc"], "attendanceThresholdId": self.threshold_pct["uuid"], "cohortId": self.cohorts["SD4-1A"]["uuid"],
            "windowStart": FIRST_DAY.isoformat(), "windowEnd": S1_END.isoformat(), "metricValue": pct,
            "breachingRecordIds": [r["uuid"] for r in absent_s1], "mentorId": mentor,
            "flagKind": "attendance-requirement", "lifecycle": "resolved", "interventions": low_attendance_interventions(mentor),
        })
        self.low["attendance_pct"] = pct

    # --- exam accommodations --------------------------------------------------
    def add_accommodations(self) -> None:
        self.dyslexic = self.rng.sample([s for s in self.students if s is not self.low and s is not self.leerplicht], 6)
        for s in self.dyslexic:
            self.b.add("exam-accommodation", {
                "learnerId": s["nc"], "submittedBy": STUDENT_COUNSELLOR, "assessmentId": None, "accommodationKind": "extra-time-percentage",
                "value": 25, "evidenceRef": "Dyslexieverklaring in het studentdossier", "approvedBy": EXAM_SECRETARY, "lifecycle": "active",
            })
        self.b.add("exam-accommodation", {
            "learnerId": self.dyslexic[0]["nc"], "submittedBy": STUDENT_COUNSELLOR, "assessmentId": None, "accommodationKind": "separate-room",
            "value": None, "evidenceRef": "Advies studentbegeleider na intake", "approvedBy": EXAM_SECRETARY, "lifecycle": "active",
        })

    # --- exam board: exemptions (decided before the results they replace) ----
    def add_exemption_cases(self) -> None:
        self.exemption_rows: dict[str, dict] = {}
        for request in EXEMPTION_REQUESTS:
            s = self.by_class[request[0]][request[1]]
            case = self.b.add("exemption-case", exemption_fields(s, (self.plans[request[2]], request)))
            if request[10] == "granted":
                self.exemption_rows[s["nc"]] = case
        self.vig_request = self.by_class["VIG3-2A"][9]

    # --- unit results --------------------------------------------------------
    def pick_result_cases(self) -> None:
        self.first_years = [s for s in self.students if s["leerjaar"] == 1]
        weak = {key: self.weakest_first_years(key) for key in PROGRAMMES}
        self.flagged = weak["LOG"][:2] + weak["VIG"][:3] + weak["SD"][:3]
        self.negative = [weak["LOG"][0], weak["SD"][0]]
        self.with_advice = [weak["VIG"][0]]
        self.postponed = [weak["SD"][1]]
        self.fraud_student = self.pick_fraud_student()

    def weakest_first_years(self, key: str) -> list[dict]:
        return sorted([s for s in self.first_years if s["programme"] == key and s is not self.low], key=lambda s: s["ability"])

    def pick_fraud_student(self) -> dict:
        return next(s for s in self.by_class["SD4-1A"] if s not in self.flagged and s is not self.low and s["ability"] > 0.3)

    def present_days(self, s: dict, unit: str) -> list[dt.date]:
        own = [d for d, u in self.cohort_days[s["class"]] if u == unit]
        return [d for d in own if not self.marks[s["nc"]].get(d, ("present",))[0].startswith("absent")]

    def exam_day(self, s: dict, sitting: tuple[str, tuple[dt.date, dt.date]]) -> dt.date:
        """The student's test day: the first lesson of the unit in the window that the student attended.

        A student absent on every lesson in the window sat the test at the last lesson before it.
        """
        unit, window = sitting
        present = self.present_days(s, unit)
        inside = days_between(present, window)
        if inside:
            return inside[0]
        return max(d for d in present if d < window[0])

    def add_entry(self, s: dict, entry: dict) -> dict:
        """A published manual grade entry: entry holds unit, component, value, day, grader and optional extra fields."""
        unit = entry["unit"]
        graded = stamp(entry["day"] + dt.timedelta(days=4), 16, 0)
        fields = {
            "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"], "curriculumPlanId": self.plans[unit]["uuid"], "componentId": entry["component"],
            "courseId": self.courses[unit]["uuid"], "cohortId": self.cohorts[s["class"]]["uuid"], "sourceKind": "manual",
            "sessionId": self.sessions[(s["class"], entry["day"])]["uuid"], "value": entry["value"], "gradeScaleId": self.numeric["uuid"],
            "period": "S1" if UNIT_META[unit]["semester"] == "S1" else "S2",
            "grader": entry["grader"], "gradedAt": graded, "visibleFrom": graded, "lifecycle": "published",
        }
        fields.update(entry.get("extra", {}))
        row = self.b.add("grade-entry", fields)
        self.entries_by.setdefault((s["nc"], unit), []).append(row)
        return row

    def add_unit_results(self) -> None:
        self.entries_by: dict[tuple[str, str], list[dict]] = {}
        for s in self.students:
            for unit in units_of(s):
                for component in unit_components(unit):
                    self.grade_component(s, (unit, component))

    def grade_component(self, s: dict, graded: tuple[str, str]) -> None:
        if graded[1].endswith("-ENG") and s["nc"] in self.exemption_rows:
            self.add_exempt_entry(s, graded)
        else:
            self.sit_component(s, graded)

    def add_exempt_entry(self, s: dict, graded: tuple[str, str]) -> None:
        unit, component = graded
        case = self.exemption_rows[s["nc"]]
        row = self.b.add("grade-entry", {
            "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"], "curriculumPlanId": self.plans[unit]["uuid"],
            "componentId": component, "courseId": self.courses[unit]["uuid"], "cohortId": self.cohorts[s["class"]]["uuid"],
            "sourceKind": "exemption", "exemptionCaseId": case["uuid"], "gradeScaleId": self.numeric["uuid"], "period": "S2",
            "grader": EXAM_CHAIR, "gradedAt": case["decidedAt"], "visibleFrom": case["decidedAt"],
            "comment": "Vrijstelling verleend door de examencommissie.", "lifecycle": "published",
        })
        case["resultingGradeEntryId"] = row["uuid"]
        self.entries_by.setdefault((s["nc"], unit), []).append(row)

    def sit_component(self, s: dict, graded: tuple[str, str]) -> None:
        unit, component = graded
        sem = UNIT_META[unit]["semester"]
        day = self.exam_day(s, (unit, WINDOWS[sem]))
        grader = component_teacher(unit, (component, day))
        entry = {"unit": unit, "component": component, "value": self.first_value(s, (unit, sem)), "day": day, "grader": grader}
        if s is self.fraud_student and unit == "SD-1.2":
            self.add_fraud_entries(s, entry)
        else:
            self.add_entry(s, entry)
            if entry["value"] < 5.5:
                self.add_resit(s, entry)

    def failing_semesters(self, s: dict) -> set[str]:
        """The semesters in which the story needs this student to fail."""
        semesters = set()
        if s in self.flagged:
            semesters.add("S1")
        if s in self.negative or s in self.with_advice:
            semesters.add("S2")
        if s in self.postponed:
            semesters.add("J")
        return semesters

    def first_value(self, s: dict, unit_semester: tuple[str, str]) -> float:
        unit, sem = unit_semester
        value = clamp_grade(6.9 + s["ability"] + UNIT_OFFSET.get(unit, 0.0) + self.rng.gauss(0, 0.7))
        if sem in self.failing_semesters(s):
            value = min(value, round(4.0 + self.rng.random() * 1.2, 1))
        return value

    def add_fraud_entries(self, s: dict, entry: dict) -> None:
        self.fraud_entry = self.add_entry(s, entry | {"value": max(entry["value"], 7.8), "extra": {"lifecycle": "invalidated"}})
        resit_day = self.exam_day(s, (entry["unit"], RESIT_WINDOWS[UNIT_META[entry["unit"]]["semester"]]))
        self.add_entry(s, entry | {"value": 6.1, "day": resit_day, "extra": {"comment": "Herkansing na de uitspraak van de examencommissie."}})

    def add_resit(self, s: dict, entry: dict) -> None:
        """A resit; first-years pass their resits unless the story needs them not to."""
        sem = UNIT_META[entry["unit"]]["semester"]
        resit_day = self.exam_day(s, (entry["unit"], RESIT_WINDOWS[sem]))
        resit = clamp_grade(6.2 + 0.8 * s["ability"] + self.rng.gauss(0, 0.6))
        if sem in self.failing_semesters(s):
            resit = min(resit, round(4.4 + self.rng.random(), 1))
        elif s["leerjaar"] == 1 or s["leerjaar"] > 1 and self.rng.random() < 0.85:
            resit = max(resit, round(5.5 + self.rng.random() * 1.5, 1))
        self.add_entry(s, entry | {"value": resit, "day": resit_day, "extra": {"comment": "Herkansing."}})

    # PVB results, as WerkprocesGradeEmitHandler writes them (value of the last
    # confirmed werkproces assessment of the component), then published.
    def add_pvb_entries(self) -> None:
        self.pvb_entries: dict[tuple[str, str], list[dict]] = {}
        for r in self.pvb_results:
            p = r["placement"]
            s = p["student"]
            self.pvb_entries.setdefault((s["nc"], p["unit"]), []).append(self.add_pvb_entry(r))

    def add_pvb_entry(self, r: dict) -> dict:
        p, s = r["placement"], r["placement"]["student"]
        day = dt.date.fromisoformat(r["last"]["assessedAt"])
        graded = stamp(day, 17, 0)
        return self.b.add("grade-entry", {
            "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"], "curriculumPlanId": self.plans[p["unit"]]["uuid"],
            "componentId": f"{p['unit']}-PVB-{r['kerntaak']}", "courseId": self.courses[p["unit"]]["uuid"],
            "cohortId": self.cohorts[s["class"]]["uuid"], "sourceKind": "manual", "value": r["value"],
            "gradeScaleId": self.competent_scale["uuid"], "period": semester_of(day), "grader": "praktijkopleider",
            "gradedAt": graded, "visibleFrom": graded, "lifecycle": "published",
        })

    # --- exam board: fraud cases (after the result they contest) -------------
    def add_fraud_case(self) -> None:
        fraud = self.b.add("fraud-case", proven_fraud_fields(self.fraud_student, self.fraud_entry))
        self.fraud_entry["fraudCaseId"] = fraud["uuid"]

    def add_dismissed_fraud_case(self) -> None:
        suspect = next(s for s in self.by_class["VIG3-2A"] if s is not self.terminated_student and s is not self.vig_request)
        contested = self.entries_by[(suspect["nc"], "VIG-2.1")][0]
        self.b.add("fraud-case", {
            "reporterId": "mbo-docent-03", "accusedLearnerId": suspect["profile"]["uuid"], "accusedLearnerUserId": suspect["profile"]["ncUserId"], "sourceKind": "manual",
            "sessionId": contested["sessionId"], "contestedGradeEntryId": contested["uuid"],
            "allegation": "Tijdens de kennistoets lag een telefoon op tafel onder een etui.",
            "reportedAt": stamp(self.session_day(contested), 13, 0),
            "hearingDate": stamp(dt.date(2026, 2, 4), 14, 0),
            "hearingRecords": [{"heldAt": stamp(dt.date(2026, 2, 4), 14, 0), "attendees": [suspect["nc"], EXAM_CHAIR, "mbo-docent-03"],
                                "notes": "De telefoon stond uit; de surveillant heeft geen gebruik gezien.", "evidenceRefs": []}],
            "verdict": "unfounded", "decisionRationale": "Geen fraude aangetoond; het resultaat blijft staan.",
            "decidedBy": EXAM_CHAIR, "decidedAt": stamp(dt.date(2026, 2, 5), 16, 0), "appealLodged": False, "lifecycle": "dismissed",
        })

    # --- final grades, as the grade engine computes them ----------------------
    def add_final_grades(self) -> None:
        self.finals: dict[tuple[str, str], dict] = {}
        for (nc, unit), entries in list(self.entries_by.items()) + list(self.pvb_entries.items()):
            self.finals[(nc, unit)] = self.add_final_grade(self.student_by_nc[nc], (unit, entries))

    def add_final_grade(self, s: dict, graded: tuple[str, list[dict]]) -> dict:
        unit, entries = graded
        plan = self.plans[unit]
        scale = self.competent_scale if unit in BPV_META else self.numeric
        value, passed, breakdown = evaluate_final(entries, (plan, float(scale["passThreshold"])))
        newest = max((e for e in entries if e["lifecycle"] == "published"), key=lambda e: e["gradedAt"])
        return self.b.add("final-grade", {
            "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"], "courseId": self.courses[unit]["uuid"],
            "programmeId": self.programmes[s["programme"]]["uuid"], "curriculumPlanId": plan["uuid"], "gradeScaleId": scale["uuid"],
            "value": value, "passed": passed, "breakdown": breakdown, "lastRecomputedAt": newest["gradedAt"],
        })

    def passed_units(self, s: dict) -> list[str]:
        return [unit for (nc, unit), row in self.finals.items() if nc == s["nc"] and unit in UNIT_META and row["passed"] is True]

    def last_graded(self, key: tuple[str, str]) -> str:
        return max(e["gradedAt"] for e in self.entries_by[key] if e["lifecycle"] == "published")

    def credits_passed(self, s: dict, until: str) -> int:
        return sum(UNIT_META[unit]["credits"] for unit in self.passed_units(s) if self.last_graded((s["nc"], unit)) <= until)

    # --- first-year study advice ----------------------------------------------
    def add_trajectories(self) -> None:
        self.trajectories = {}
        for key in PROGRAMMES:
            self.trajectories[key] = self.b.add("bsa-trajectory", {
                "programmeId": self.programmes[key]["uuid"], "academicYear": YEAR, "kind": "mbo-studieadvies", "normEcts": 35,
                "window": {"mode": "relative-months", "referenceDate": FIRST_DAY.isoformat(), "afterMonths": 6, "notEarlierThanMonths": 3},
                "interimNormEcts": 15, "onAtRisk": {"notify": True, "notifyRoles": ["mentor", "coordinator"], "createFlag": True},
                "lifecycle": "active",
            })

    def add_progress_flags(self) -> None:
        check_at = stamp(INTERIM_CHECK, 7, 0)
        for s in self.first_years:
            earned = self.credits_passed(s, check_at)
            if earned < 15:
                s["flag"] = self.b.add("bsa-progress-flag", {
                    "learnerId": s["nc"], "programmeId": self.programmes[s["programme"]]["uuid"], "bsaTrajectoryId": self.trajectories[s["programme"]]["uuid"],
                    "academicYear": YEAR, "ectsEarned": earned, "ectsRequiredAtCheck": 15, "flaggedAt": check_at, "lifecycle": "warned",
                })
                s["earned_at_check"] = earned

    def add_warnings(self) -> None:
        self.warnings = {}
        for s in self.first_years:
            if "flag" in s:
                self.warnings[s["nc"]] = self.b.add("bsa-warning", self.warning_fields(s))

    def warning_fields(self, s: dict) -> dict:
        note = "Langdurig ziek geweest in het voorjaar; afspraken met de studentbegeleider over een aangepast rooster." if s in self.postponed else None
        return {
            "learnerId": s["nc"], "programmeId": self.programmes[s["programme"]]["uuid"], "academicYear": YEAR,
            "bsaProgressFlagId": s["flag"]["uuid"], "warningDate": "2026-02-25", "ectsEarnedAtWarning": s["earned_at_check"],
            "ectsNormAtWarning": 35, "improvementPeriod": {"startDate": "2026-03-02", "endDate": "2026-06-26"},
            "offeredGuidance": "Wekelijks gesprek met de SLB'er, een studieplan voor semester 2 en de herkansingen in juni.",
            **({"personalCircumstancesNote": note} if note else {}), "lifecycle": "acknowledged",
        }

    def add_bsa_decisions(self) -> None:
        decided = stamp(DECISION_DAY, 14, 0)
        for s in self.first_years:
            earned = self.credits_passed(s, decided)
            fields = {
                "learnerId": s["nc"], "programmeId": self.programmes[s["programme"]]["uuid"], "academicYear": YEAR,
                "ectsAchieved": earned, "ectsNormRequired": 35, "warningIds": [self.warnings[s["nc"]]["uuid"]] if s["nc"] in self.warnings else [],
                "decidedBy": TEAMLEADER[s["programme"]], "decisionDate": decided, "lifecycle": "decided",
            }
            fields.update(self.bsa_verdict(s, earned))
            self.b.add("bsa-decision", fields)

    def bsa_verdict(self, s: dict, earned: int) -> dict:
        if earned >= 35:
            return {"decisionType": "positive"}
        if s in self.postponed:
            return POSTPONED_VERDICT
        return self.negative_verdict(s)

    def negative_verdict(self, s: dict) -> dict:
        """A negative advice, after a warning; the student is withdrawn at the end of the year."""
        if s["nc"] not in self.warnings:
            raise ValueError(f"{s['nc']} would get a negative advice without a warning")
        advice = s in self.with_advice
        s["enrolment"]["lifecycle"] = "withdrawn"
        s["enrolment"]["reason"] = "Uitgeschreven per 31 juli 2026 na een negatief bindend studieadvies."
        return {
            "decisionType": "negative-with-recommendation" if advice else "negative",
            "personalCircumstancesConsidered": True, "personalCircumstancesNote": "Geen omstandigheden aangevoerd die het resultaat verklaren.",
            "rationale": ("De student haalde met de herkansingen minder dan de norm van het eerste jaar. "
                          + ("Advies: de opleiding Helpende zorg en welzijn (niveau 2) past beter bij het tempo en de interesses van de student."
                             if advice else "De opleiding kan niet worden voortgezet.")),
            "studentHeardAt": stamp(dt.date(2026, 7, 1), 11, 0),
            "studentResponse": "Begrijpt het besluit en wil in gesprek over een andere opleiding.",
        }

    # Diploma year: enrolment completed when every unit and the work placement passed.
    def settle_diplomas(self) -> None:
        for s in self.students:
            if s["leerjaar"] == PROGRAMMES[s["programme"]][2] and self.passed_everything(s):
                s["enrolment"]["lifecycle"] = "completed"

    def passed_everything(self, s: dict) -> bool:
        own = [row for (nc, _u), row in self.finals.items() if nc == s["nc"]]
        return bool(own) and all(row["passed"] is True for row in own)

    # --- support requests and dossier notes -----------------------------------
    def case_student(self, who: str) -> dict:
        """The student a support request or dossier note is about."""
        if who in self.by_class:
            return self.by_class[who][0]
        cases = {"low": self.low, "leerplicht": self.leerplicht, "terminated": self.terminated_student,
                 "struggling": self.negative[0], "fraud": self.fraud_student, "dyslexic": self.dyslexic[0]}
        return cases[who]

    def add_support_requests(self) -> None:
        for who, raised_by, domain, description, urgency, lifecycle in SUPPORT_REQUESTS:
            self.b.add("support-request", {
                "learnerId": self.case_student(who)["nc"], "raisedBy": raised_by, "supportDomain": domain,
                "description": description, "urgency": urgency, "lifecycle": lifecycle,
            })

    def add_dossier_notes(self) -> None:
        for who, author, date, category, body, confidentiality in DOSSIER_NOTES:
            s = self.case_student(who)
            slb = SLB[s["class"]]
            care = [STUDENT_COUNSELLOR] + ([slb] if slb != STUDENT_COUNSELLOR else [])
            self.b.add("dossier-note", {"learnerId": s["nc"], "authorId": author or slb, "date": date, "category": category, "body": body,
                                        "confidentiality": confidentiality, "careTeamUserIds": care})

    # --- hour plans (timetabling-multi-year-hour-plan) --------------------------
    # One active plan per programme and intake year that has a class this year,
    # so the teaching activities of 2025-2026 list every class; a draft for the
    # next Software developer intake shows the copy. 16 contact hours per credit
    # for a unit, 28 placement hours per credit for BPV.
    def add_hour_plans(self) -> None:
        for key in PROGRAMMES:
            lines = self.unit_hour_lines(key) + self.bpv_hour_lines(key)
            for start, lifecycle in hour_plan_intakes(key):
                self.add_hour_plan(key, (lines, start, lifecycle))

    def unit_hour_lines(self, key: str) -> list[dict]:
        return [{"courseId": self.courses[code]["uuid"], "programmeYear": lj, "periodCode": None if sem == "J" else sem,
                 "contactHours": credits * 16, "otherHours": 0, "activityKind": "lesson"}
                for code, pkey, lj, sem, _name, credits, _teachers, _kt, _extra in UNITS if pkey == key]

    def bpv_hour_lines(self, key: str) -> list[dict]:
        return [{"courseId": self.courses[code]["uuid"], "programmeYear": lj, "periodCode": None,
                 "contactHours": 0, "otherHours": credits * 28, "activityKind": "work-placement"}
                for code, pkey, lj, _name, credits, _coach, _kt in BPV_UNITS if pkey == key]

    def add_hour_plan(self, key: str, plan: tuple[list[dict], int, str]) -> None:
        lines, start, lifecycle = plan
        pname, years = PROGRAMMES[key][0], PROGRAMMES[key][2]
        intake = f"{start}-{start + 1}"
        self.b.add("hour-plan", {
            "name": f"Urenplan {pname}, instroom {intake}", "programmeId": self.programmes[key]["uuid"],
            "intakeYear": intake, "durationYears": years,
            "periodsPerYear": [{"periodCode": "S1", "label": "Semester 1"}, {"periodCode": "S2", "label": "Semester 2"}],
            "lines": lines, "yearNorms": [{"programmeYear": y, "contactHours": 600, "totalHours": 1000} for y in range(1, years + 1)],
            "lifecycle": lifecycle,
        })

    # --- an external assessor, and the portfolios shared with him -------------
    # The examenportaal needs a person to sign in as and something to read:
    # without a share the assessor's portal is empty (invite-a-trainer-and-an-assessor).
    def add_assessor_shares(self) -> None:
        assessor = self.b.add("external-assessor", {
            "givenName": "Ruud", "familyName": "Jansen",
            "email": "ruud.jansen@examinering-zuiddrecht.example",
            "organisationName": "Examinering Zuiddrecht", "active": True,
        })
        # Two students who are actually on a placement, so the portfolios belong to
        # people the rest of the set knows.
        for index, s in enumerate([p["student"] for p in self.placements][:2], start=1):
            self.share_portfolio(s, (index, assessor))

    def share_portfolio(self, s: dict, shared: tuple[int, dict]) -> None:
        index, assessor = shared
        portfolio = self.b.add("portfolio", {
            "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"], "kind": "course-bound",
            "title": f"Proeve van bekwaamheid {index}", "description": "Bewijsstukken voor de proeve.", "lifecycle": "submitted",
        })
        self.b.add("portfolio-entry", {
            "portfolioId": portfolio["uuid"], "learnerId": s["nc"], "title": "Reflectie op de proeve", "evidenceKind": "reflection",
            "reflectionText": "Ik heb de meterkast aangesloten en daarna zelf nagemeten.",
        })
        self.b.add("portfolio-share", {
            # The readable copies ReadableCopyStamp writes on a live save.
            "portfolioId": portfolio["uuid"], "portfolioTitle": portfolio["title"], "learnerName": f"{s['given']} {s['surname']}",
            "entryIds": [], "sharedWithKind": "external-assessor", "sharedWithExternalAssessorId": assessor["uuid"],
            "sharedBy": EXAM_SECRETARY, "expiresAt": stamp(dt.date(2026, 10, 16), 23, 59), "lifecycle": "active",
        })

    # --- weeks of realised BPV hours (internship-hours) -----------------------
    # Only the first two placements of every BPV unit carry weeks. That is
    # enough for every praktijkopleider in the set to have one week waiting for
    # her, for a student to read a correction on her own page, and for the
    # progress card to have a real numerator; seeding every week of every
    # placement would add thousands of rows to a set that already has 6,263.
    #
    # The pattern per placement, in the order a year really goes: four weeks she
    # approved as entered, one she corrected downwards with her reason, and one
    # still waiting for her. `hoursApprovedTotal` on the placement is the sum of
    # the decided weeks, which is exactly what HourWeekTotalRollup would write.
    def add_hour_weeks(self) -> None:
        by_unit: dict[str, list[dict]] = {}
        for p in self.placements:
            if p["state"] == "completed":
                by_unit.setdefault(p["unit"], []).append(p)
        for unit in sorted(by_unit):
            for p in by_unit[unit][:2]:
                self.add_placement_weeks(p)

    def add_placement_weeks(self, p: dict) -> None:
        approved_total = 0
        for index in range(6):
            fields = hour_week(p, index)
            approved_total += fields.get("hoursApproved", 0)
            self.b.add("bpv-hour-week", fields)
        p["row"]["hoursApprovedTotal"] = approved_total

    # --- the Esdoornveen story (after everything else, so nothing moves) -------
    def add_story_and_stamps(self) -> None:
        add_story(self)
        stamp_hour_totals(self.b)
        stamp_group_labels(self.b)


def programme_unit_codes(key: str) -> list[str]:
    return [u[0] for u in UNITS if u[1] == key] + [u[0] for u in BPV_UNITS if u[1] == key]


def examination_components(unit_codes: list[str]) -> list[dict]:
    """The programme's exam plan: each unit's final assessment and each proeve van bekwaamheid."""
    components = []
    for code in unit_codes:
        if code in UNIT_META:
            components.append({"componentId": f"{code}-EB", "label": f"{code} {UNIT_META[code]['name']}", "weight": 1,
                               "period": "S1" if UNIT_META[code]["semester"] == "S1" else "S2", "kind": "assessment"})
        else:
            components.extend(pvb_components(code))
    return components


def pvb_components(code: str) -> list[dict]:
    return [{"componentId": f"{code}-PVB-{k}", "label": f"Proeve van bekwaamheid {k}", "weight": 1, "period": "S2",
             "kind": "assessment"} for k in BPV_META[code]["kerntaken"]]


def unit_plan_components(code: str) -> list[dict]:
    meta = UNIT_META[code]
    period = "S1" if meta["semester"] == "S1" else "S2"
    if meta["extra"] == "ENG":
        return [
            {"componentId": f"{code}-NED", "label": "Nederlands", "weight": 1, "period": period, "kind": "assessment"},
            {"componentId": f"{code}-ENG", "label": "Engels", "weight": 1, "period": period, "kind": "assessment"},
        ]
    return [{"componentId": f"{code}-EB", "label": f"Eindbeoordeling {meta['name'].lower()}", "weight": 1,
             "period": period, "kind": "assessment"}]


def unit_periods(semester: str) -> list[dict]:
    return [row for row in PERIOD_ROWS if semester == "J" or row["periodId"] == semester]


def lesson_days(timetable: dict, days: list[dt.date]) -> list[tuple[dt.date, str]]:
    """The class's school days with the unit it has on each."""
    own = []
    for day in days:
        unit = timetable.get(semester_of(day), {}).get(WEEKDAYS[day.weekday()])
        if unit is not None:
            own.append((day, unit))
    return own


def session_teacher(unit: str, day: dt.date) -> str:
    """The teacher of a unit on a day; two-teacher units alternate by ISO week."""
    teachers = UNIT_META[unit]["teachers"]
    return teachers[day.isocalendar()[1] % len(teachers)]


def weekdays_of(by_semester: dict) -> list[str]:
    """Every weekday a per-semester day plan names, in week order."""
    return sorted({wd for sem in by_semester.values() for wd in sem}, key=WEEKDAYS.index)


def cohort_notes(bpv: tuple | None) -> str | None:
    if bpv is None:
        return None
    placement_days = weekdays_of(bpv[1])
    return (f"Beroepspraktijkvorming op {', '.join(DAG[WEEKDAYS.index(d)] for d in placement_days)}"
            f" van {dutch_date(bpv[2])} tot en met {dutch_date(bpv[3])}; op die dagen staan geen lessen op het rooster.")


def taught_units(timetable: dict) -> list[str]:
    """The units on a class's timetable, semester 1 first, each once."""
    taught: list[str] = []
    for sem in ("S1", "S2"):
        for unit in timetable.get(sem, {}).values():
            if unit not in taught:
                taught.append(unit)
    return taught


def holidays_between(start: dt.date, end: dt.date) -> list[dict]:
    return [{"name": h[0], "startDate": h[1].isoformat(), "endDate": h[2].isoformat()} for h in HOLIDAYS if start <= h[1] <= end]


def study_days_between(start: dt.date, end: dt.date) -> list[dict]:
    return [{"date": d.isoformat(), "description": text} for d, text in STUDY_DAYS if start <= d <= end]


def trainer_name(t: dict) -> str:
    return f"{t['givenName']} {t['familyName']}"


def placement_day_near(target: dt.date, p: dict) -> dt.date:
    """The placement day closest to target (earlier first): a placement weekday, not closed, inside the period."""
    for delta in range(0, 60):
        for d in (target - dt.timedelta(days=delta), target + dt.timedelta(days=delta)):
            if is_placement_day(d, p):
                return d
    raise ValueError(f"no placement day near {target}")


def is_placement_day(d: dt.date, p: dict) -> bool:
    return p["from"] <= d <= p["to"] and d.weekday() < 5 and WEEKDAYS[d.weekday()] in p["weekdays"] and d not in CLOSED


def agreement_fields(p: dict) -> dict:
    s, f, t = p["student"], p["from"], p["to"]
    placement_days = ", ".join(DAG[WEEKDAYS.index(d)] for d in p["weekdays"])
    return {
        "bpvPlacementId": p["row"]["uuid"], "periodFrom": f.isoformat(), "periodTo": t.isoformat(),
        "terms": (f"Beroepspraktijkvorming bij {p['company']} van {dutch_date(f)} tot en met {dutch_date(t)}, op {placement_days}. "
                  f"Praktijkopleider {trainer_name(p['trainer'])}, BPV-docent {STAFF[p['coach']][0]}. "
                  "Het leerbedrijf is erkend door SBB; de student volgt de werktijden en huisregels van het leerbedrijf."),
        "version": 1,
        # PokParentSignatureRule: a parent co-signs when the student is under 18 on the day they sign (12 days before the start).
        "parentSignatureRequired": age_on(s["birth"], f - dt.timedelta(days=12)) < 18,
        "lifecycle": p["state"],
    }


def signers_of(p: dict) -> list[tuple]:
    """Who signs a praktijkovereenkomst: role, days before the start, hour, assurance level and method."""
    return [(p["student"]["nc"], "student", 12, 19, "basic", "Nextcloud-account"),
            (STAGECOORDINATOR, "school", 10, 10, "substantial", "Nextcloud-account met tweestapsverificatie"),
            (p["trainer"]["uuid"], "praktijkopleider", 9, 14, "basic", "Ondertekenlink per e-mail")]


def visit_attendees(p: dict) -> list[dict]:
    s = p["student"]
    return [{"role": "student", "name": f"{s['given']} {s['surname']}"},
            {"role": "praktijkopleider", "name": trainer_name(p["trainer"])},
            {"role": "bpv-docent", "name": STAFF[p["coach"]][0]}]


def visit_texts(kind: str, key: str) -> tuple[str, str]:
    """A visit report's narrative and action points."""
    good, work_on = VISIT_TEXT[key]
    if kind == "incident":
        return (("Gesprek na twee meldingen van het leerbedrijf: de student kwam te laat op vroege diensten en voelt zich niet "
                 "thuis in het team. Leerbedrijf en student willen stoppen."),
                "Stage stopt op 14 november 2025. Stagecoördinator zoekt een nieuw leerbedrijf; start uiterlijk in december.")
    if kind == "eindgesprek":
        return (f"Eindgesprek. {good} De werkprocessen zijn beoordeeld; de praktijkopleider is tevreden over de groei.",
                "Geen actiepunten; de beoordelingen gaan naar de examencommissie.")
    if kind == "tussentijds-gesprek":
        return f"Tussentijds gesprek op verzoek van het leerbedrijf, over de planning en de aanwezigheid. {good}", work_on
    return f"Eerste bezoek op de werkplek. {good}", work_on


def werkprocessen_of(key: str, kerntaak: str) -> list[str]:
    return [wp for wp in FRAMEWORKS[key] if wp[0] == kerntaak][0][2]


def winter_days(own: list[dt.date]) -> list[dt.date]:
    return [d for d in own if d.month in (11, 12, 1, 2, 3)]


def low_attendance_mark(day: dt.date) -> tuple[str, str, int] | None:
    if day.weekday() in (0, 3):
        return ("absent-unexcused", "Niet afgemeld", 0)
    if day.weekday() == 2:
        return ("absent-excused", "Ziek gemeld", 0)
    return None


def marked_at(day: dt.date, status: str) -> str:
    late = status == "late"
    if day.weekday() == 4:
        return stamp(day, 9, 25 if late else 15)
    return stamp(day, 9 if late else 8, 5 if late else 45)


def leerplicht_interventions(mentor: str) -> list[dict]:
    return [
        {"recordedBy": mentor, "recordedAt": stamp(dt.date(2026, 3, 11), 12, 30),
         "note": "Student en ouder gebeld; geen gehoor, bericht ingesproken en een e-mail gestuurd."},
        {"recordedBy": ATTENDANCE_OFFICER, "recordedAt": stamp(dt.date(2026, 3, 16), 10, 0),
         "note": "Verzuim gemeld bij DUO; de leerplichtambtenaar is op de hoogte."},
        {"recordedBy": ATTENDANCE_OFFICER, "recordedAt": stamp(dt.date(2026, 3, 19), 15, 0),
         "note": "Gesprek met student en ouder op school; afspraken over ziekmelden en een wekelijks gesprek met de SLB'er."},
    ]


def low_attendance_interventions(mentor: str) -> list[dict]:
    return [
        {"recordedBy": mentor, "recordedAt": stamp(dt.date(2025, 12, 9), 13, 0),
         "note": "Gesprek over de aanwezigheid; de student werkt veel avonden en slaapt slecht."},
        {"recordedBy": STUDENT_COUNSELLOR, "recordedAt": stamp(dt.date(2026, 1, 13), 11, 0),
         "note": "Doorverwezen naar de studentbegeleider; weekplanning gemaakt met minder werkuren."},
    ]


def exemption_fields(s: dict, request: tuple[dict, tuple]) -> dict:
    plan, (_cls, _pos, _plan, component, grounds, description, (day, hour, minute), rationale, article, decided, lifecycle) = request
    return {
        "learnerId": s["profile"]["uuid"], "learnerUserId": s["profile"]["ncUserId"], "curriculumPlanId": plan["uuid"], "componentId": component,
        "groundsKind": grounds, "groundsDescription": description, "submittedAt": stamp(day, hour, minute),
        "decisionRationale": rationale, "policyReference": f"Examenreglement Esdoornveen 2025-2026, artikel {article}",
        "decidedBy": EXAM_CHAIR, "decidedAt": stamp(decided, 16, 0), "lifecycle": lifecycle,
    }


def days_between(days: list[dt.date], window: tuple[dt.date, dt.date]) -> list[dt.date]:
    return [d for d in days if window[0] <= d <= window[1]]


def units_of(s: dict) -> list[str]:
    return [c for c, m in UNIT_META.items() if m["programme"] == s["programme"] and m["leerjaar"] == s["leerjaar"]]


def unit_components(unit: str) -> list[str]:
    return [f"{unit}-NED", f"{unit}-ENG"] if UNIT_META[unit]["extra"] == "ENG" else [f"{unit}-EB"]


def component_teacher(unit: str, sitting: tuple[str, dt.date]) -> str:
    """Who grades a component: the session's teacher, or for a language unit the teacher of that language."""
    component, day = sitting
    meta = UNIT_META[unit]
    return session_teacher(unit, day) if meta["extra"] != "ENG" else meta["teachers"][0 if component.endswith("NED") else 1]


def proven_fraud_fields(student: dict, entry: dict) -> dict:
    return {
        "reporterId": "mbo-docent-08", "accusedLearnerId": student["profile"]["uuid"], "accusedLearnerUserId": student["profile"]["ncUserId"], "sourceKind": "manual",
        "contestedGradeEntryId": entry["uuid"],
        "allegation": "De ingeleverde webapplicatie is voor een groot deel gelijk aan de code van een andere student, inclusief dezelfde fouten.",
        "reportedAt": stamp(dt.date.fromisoformat(entry["gradedAt"][:10]), 17, 30),
        "hearingDate": stamp(dt.date(2026, 6, 17), 10, 0),
        "hearingRecords": proven_fraud_hearing(student),
        "verdict": "fraud-proven",
        "decisionRationale": "Fraude vastgesteld. Het resultaat vervalt; de student mag de opdracht in de herkansingsweek opnieuw maken.",
        "decidedBy": EXAM_CHAIR, "decidedAt": stamp(dt.date(2026, 6, 19), 15, 0), "sanctionType": "grade-annulment",
        "sanctionScope": "single-assessment", "appealDeadline": stamp(dt.date(2026, 7, 31), 17, 0), "appealLodged": False,
        "lifecycle": "decided",
    }


def proven_fraud_hearing(student: dict) -> list[dict]:
    return [{"heldAt": stamp(dt.date(2026, 6, 17), 10, 0),
             "attendees": [student["nc"], EXAM_CHAIR, EXAM_SECRETARY, "mbo-docent-08"],
             "notes": "De student erkent dat de code van een klasgenoot is overgenomen, omdat het eigen project vastliep.",
             "evidenceRefs": ["Vergelijking van de twee opgeleverde codebases"]}]


def hour_plan_intakes(key: str) -> list[tuple[int, str]]:
    """An active plan per intake year with a class this year; the next Software developer intake as a draft."""
    year_start = int(YEAR[:4])
    plan_rows = [(start, "active") for start in sorted({year_start - (c[2] - 1) for c in COHORTS if c[1] == key})]
    if key == "SD":
        plan_rows.append((year_start + 1, "draft"))
    return plan_rows


def hour_week(p: dict, index: int) -> dict:
    """Week `index` of a placement's hours: four approved, one corrected, the sixth still submitted."""
    s, per_week = p["student"], len(p["weekdays"]) * 8
    week_monday = p["from"] - dt.timedelta(days=p["from"].weekday()) + dt.timedelta(weeks=index)
    iso_year, iso_week, _day = week_monday.isocalendar()
    friday = week_monday + dt.timedelta(days=4)
    fields = {
        "bpvPlacementId": p["row"]["uuid"], "learnerRef": s["profile"]["uuid"], "isoWeek": f"{iso_year}-W{iso_week:02d}",
        "hoursSubmitted": per_week, "submittedBy": s["profile"]["uuid"], "submittedAt": stamp(friday, 17, 10),
    }
    if index == 5:
        # Still with her: this is what her overview puts first.
        fields["lifecycle"] = "submitted"
    else:
        fields.update(hour_decision(p, (index == 4, friday)))
    return fields


def hour_decision(p: dict, week: tuple[bool, dt.date]) -> dict:
    corrected, friday = week
    per_week = len(p["weekdays"]) * 8
    decision = {
        "hoursApproved": (per_week - 2) if corrected else per_week, "approvedBy": p["trainer"]["uuid"],
        "approvedByName": trainer_name(p["trainer"]), "approvedAt": stamp(friday + dt.timedelta(days=3), 9, 20),
        "assuranceLevel": "basic", "lifecycle": "corrected" if corrected else "approved",
    }
    if corrected:
        decision["note"] = HOUR_WEEK_NOTES[p["student"]["programme"]]
    return decision


# --- the Esdoornveen story (example-sets-are-the-four-schools) --------------
# Monday 5 October 2026, ISO week 41. Milan de Groot is in leerjaar 2 of
# Mechatronica niveau 4 and on his work placement at Bakker Techniek BV. These
# objects sit on top of the 2025-2026 year: they are added after every other
# object and draw no random number, so no earlier uuid or value moves.
STORY_YEAR = "2026-2027"
STORY_CREBO = "25743"  # the crebo the design board names; the rest of the set uses fictional 9xxxx codes
STORY_STAFF = {
    # ncUserId => (display name, roles, qualifications, working days)
    "mbo-docent-15": ("Ruud Hermans", ["teacher"], ["tweedegraads bevoegdheid techniek", "BPV-docent mechatronica"],
                      [MON, TUE, WED, THU]),
    "mbo-docent-16": ("Fenna Yilmaz", ["teacher", "mentor"], ["studieloopbaanbegeleider", "tweedegraads bevoegdheid techniek"],
                      [MON, TUE, THU, FRI]),
}
STORY_COACH = "mbo-docent-15"
STORY_SLB = "mbo-docent-16"
STORY_KERNTAKEN = [
    ("B1-K1", "Realiseert mechatronische systemen", [
        "Bereidt het werk voor", "Maakt onderdelen", "Bouwt mechatronische systemen op", "Test en stelt systemen af"]),
    ("B1-K2", "Onderhoudt mechatronische systemen en verhelpt storingen", [
        "Lokaliseert storingen", "Voert onderhoud uit"]),
]
# Units of leerjaar 2: code, name, credits, teachers, kerntaken, components.
STORY_UNITS = [
    ("MT-2.1", "PLC-programmeren", 10, [STORY_COACH], ["B1-K1"], None),
    ("MT-2.2", "Elektrotechniek", 10, [STORY_COACH], ["B1-K1"], None),
    ("MT-2.3", "Pneumatiek en hydrauliek", 5, [STORY_COACH], ["B1-K2"], None),
    ("MT-2.4", "Loopbaan en burgerschap", 5, [STORY_SLB], [], None),
    ("MT-2.5", "Nederlands", 5, ["mbo-docent-13"], [], [("LL", "Lezen en luisteren"), ("SC", "Schrijven"), ("SP", "Spreken en gesprekken voeren")]),
    ("MT-2.6", "Engels", 5, ["mbo-docent-14"], [], None),
    ("MT-2.7", "Rekenen", 5, ["mbo-docent-11"], [], None),
]


def story_days(monday: dt.date, count: int, questions: dict) -> list[dict]:
    """`count` day lines from `monday`, each 08.00 to 16.30 with 30 minutes break (8 hours) on the
    placement's first work processes; `questions` puts the trainer's question on a day by its index."""
    lines = []
    for i in range(count):
        line = {"date": (monday + dt.timedelta(days=i)).isoformat(), "startTime": "08:00", "endTime": "16:30",
                "breakMinutes": 30, "hours": 8, "werkprocesCodes": [STORY_PROGRESS[2][0]],
                "description": "Aan de transportbandopstelling gewerkt."}
        if i in questions:
            line["returnedQuestion"] = questions[i]
        lines.append(line)
    return lines


def stamp_hour_totals(b: Builder) -> None:
    """Every placement's waiting and returned hours, as HourWeekTotalRollup keeps them on a live save
    (bpv-hours-match-the-board): waiting is the hours submitted on a week still `submitted`, returned the
    hours submitted on a `rejected` week less what was approved. Runs last and draws no random number, so
    it only adds two values to each placement."""
    for placement in b.buckets["bpv-placement"]:
        weeks = [w for w in b.buckets["bpv-hour-week"] if w["bpvPlacementId"] == placement["uuid"]]
        placement["hoursWaitingTotal"] = waiting_hours(weeks)
        placement["hoursReturnedTotal"] = returned_hours(weeks)


def waiting_hours(weeks: list[dict]) -> int:
    return sum(w["hoursSubmitted"] for w in weeks if w["lifecycle"] == "submitted")


def returned_hours(weeks: list[dict]) -> int:
    return sum(max(0, w["hoursSubmitted"] - (w.get("hoursApproved") or 0)) for w in weeks if w["lifecycle"] == "rejected")

STORY_S1 = (dt.date(2026, 8, 31), dt.date(2027, 1, 29))
STORY_S2 = (dt.date(2027, 2, 1), dt.date(2027, 7, 9))
STORY_PERIOD_ROWS = [{"periodId": "S1", "label": "Semester 1", "startDate": STORY_S1[0].isoformat(), "endDate": STORY_S1[1].isoformat()},
                     {"periodId": "S2", "label": "Semester 2", "startDate": STORY_S2[0].isoformat(), "endDate": STORY_S2[1].isoformat()}]
# Rooms: code, name, capacity, kind, facilities, location, floor.
STORY_ROOMS = [
    ("T0.14", "Mechatronicalab T0.14", 24, "lab", ["PLC-trainers", "pneumatiekpanelen", "transportbandopstelling"], "techniekpark", "0"),
    ("B1.08", "Lokaal B1.08", 32, "classroom", ["digibord"], "centrum", "1"),
    ("B2.11", "Spreekkamer B2.11", 4, "other", ["tafel voor gesprekken"], "centrum", "2"),
]
STORY_PVB_COMPONENTS = [{"componentId": f"MT-BPV2-PVB-{k}", "label": f"Proeve van bekwaamheid {k}", "weight": 1, "period": "S2",
                         "kind": "assessment"} for k, _t, _w in STORY_KERNTAKEN]
MILAN, AYLIN = "mbo-student-251", "mbo-student-252"
# The two story students: given name, family name, birth date, address, emergency contacts.
STORY_PEOPLE = {
    MILAN: ("Milan", "de Groot", "2008-03-14",
            {"street": "Esdoornlaan", "houseNumber": "112", "postalCode": "0511 KM", "city": "Zuiddrecht", "country": "NL"},
            [{"name": "Sandra de Groot", "relationship": "ouder", "phone": "06-00004417", "priority": 1}]),
    AYLIN: ("Aylin", "Demir", "2005-06-02",
            {"street": "Lindehof", "houseNumber": "7", "postalCode": "0512 AD", "city": "Zuiddrecht", "country": "NL"},
            [{"name": "Emre Demir", "relationship": "partner", "phone": "06-00006230", "priority": 1}]),
}
STORY_KVK, STORY_ERKENNING = "00000019", "00000059"
# Placements at Bakker Techniek BV: student, start, end, agreed hours, terms, signing day.
STORY_PLACEMENTS = [
    (MILAN, dt.date(2026, 8, 31), dt.date(2027, 1, 29), 480,
     ("Beroepspraktijkvorming bij Bakker Techniek BV van maandag 31 augustus 2026 tot en met vrijdag 29 januari 2027, "
      "op maandag, dinsdag en woensdag van 08.00 tot 16.30 uur, samen 480 uur. Praktijkopleider Petra Bakker, BPV-begeleider "
      "Ruud Hermans. Het leerbedrijf is erkend door SBB; de student volgt de werktijden en huisregels van het leerbedrijf."),
     dt.date(2026, 8, 27)),
    (AYLIN, dt.date(2026, 8, 24), dt.date(2027, 7, 2), 640,
     ("Beroepspraktijkvorming in de beroepsbegeleidende leerweg (bbl) bij Bakker Techniek BV, als eerste monteur mechatronica, "
      "van maandag 24 augustus 2026 tot en met vrijdag 2 juli 2027, op maandag tot en met donderdag, samen 640 uur. "
      "Praktijkopleider Petra Bakker, BPV-begeleider Ruud Hermans."),
     dt.date(2026, 8, 17)),
]
STORY_ATTENDEES = [{"role": "student", "name": "Milan de Groot"}, {"role": "praktijkopleider", "name": "Petra Bakker"},
                   {"role": "bpv-docent", "name": "Ruud Hermans"}]
# Milan's own record per work process: code, label, hours spent, his own estimate (the last two still open).
STORY_PROGRESS = [
    ("B1-K1-W1", "Bereidt het werk voor", 14, "goed"),
    ("B1-K1-W2", "Maakt onderdelen", 22, "voldoende"),
    ("B1-K1-W3", "Bouwt mechatronische systemen op", 38, "goed"),
    ("B1-K1-W4", "Test en stelt systemen af", 26, "voldoende"),
    ("B1-K2-W1", "Lokaliseert storingen", 12, None),
    ("B1-K2-W2", "Voert onderhoud uit", 8, None),
]
# This week's school days: Thursday 8 October (4 lessons) and Friday
# 9 October (3 lessons, Engels cancelled on Wednesday 30 September).
STORY_LESSONS = [
    (dt.date(2026, 10, 8), (8, 30), (10, 0), "MT-2.1", "T0.14", None),
    (dt.date(2026, 10, 8), (10, 15), (11, 45), "MT-2.2", "T0.14", None),
    (dt.date(2026, 10, 8), (12, 30), (13, 45), "MT-2.3", "T0.14", None),
    (dt.date(2026, 10, 8), (14, 0), (15, 0), "MT-2.4", "T0.14", None),
    (dt.date(2026, 10, 9), (8, 30), (9, 45), "MT-2.5", "B1.08", None),
    (dt.date(2026, 10, 9), (10, 0), (11, 0), "MT-2.6", "B1.08", "cancelled"),
    (dt.date(2026, 10, 9), (11, 15), (12, 15), "MT-2.7", "B1.08", None),
]
STORY_UNIT_NAMES = {c[0]: c[1] for c in STORY_UNITS}


def add_story(year: SchoolYear) -> dict:
    """Milan de Groot's autumn at Esdoornveen, as the esdoornveen boards show it.

    Returns the story objects by name, for the report and for nothing else.
    """
    return StoryAutumn(year).add()


class StoryAutumn:
    """The story objects, added in a fixed order on top of the school year; no random draws."""

    def __init__(self, year: SchoolYear) -> None:
        self.year = year
        self.b = year.b

    def add(self) -> dict:
        for step in (self.add_staff_and_rooms, self.add_framework, self.add_competencies, self.add_programme,
                     self.add_unit_courses, self.add_plans, self.add_class, self.add_students, self.add_teachers,
                     self.add_placements, self.add_visits, self.add_progress, self.add_hour_weeks, self.add_lessons,
                     self.add_conference, self.add_exam):
            step()
        return {"milan": self.profiles[MILAN], "aylin": self.profiles[AYLIN], "petra": self.petra, "cohort": self.cohort,
                "milan_placement": self.placements[MILAN], "aylin_placement": self.placements[AYLIN]}

    # Staff: the BPV-begeleider and the studieloopbaanbegeleider. Their names
    # live in Nextcloud display names; the exam board secretary is the existing
    # mbo-examencommissie-02 (display name Karin de Boer).
    def add_staff_and_rooms(self) -> None:
        for nc, (_name, roles, quals, working) in STORY_STAFF.items():
            self.b.add("staff", {"ncUserId": nc, "roles": roles, "qualifications": quals, "workingDays": list(working)})
        self.rooms = {}
        for code, name, capacity, kind, facilities, location, floor in STORY_ROOMS:
            self.rooms[code] = self.b.add("room", {"name": name, "code": code, "capacity": capacity, "kind": kind, "facilities": facilities,
                                                   "buildingCode": self.year.locations[location]["vestigingscode"], "floor": floor})

    # The programme, its kwalificatiedossier, courses and plans.
    def add_framework(self) -> None:
        self.framework = self.b.add("competency-framework", {
            "name": "Kwalificatiedossier Mechatronica", "sourceAuthority": "sbb-kwalificatiedossier", "sourceRef": STORY_CREBO,
            "edition": "2026", "level": "mbo",
            "description": "Kerntaken en werkprocessen van de opleiding Mechatronica (niveau 4), in de codering van een kwalificatiedossier. Een verzonnen voorbeeld, geen officieel dossier.",
            "proficiencyLevels": [
                {"levelId": "nog-niet-competent", "label": "Nog niet competent", "order": 1, "minPercent": 0},
                {"levelId": "competent", "label": "Competent", "order": 2, "minPercent": 100},
            ],
            "lifecycle": "published",
        })

    def add_competencies(self) -> None:
        self.competencies: dict[str, dict] = {}
        order = 0
        for kerntaak in STORY_KERNTAKEN:
            order = self.add_kerntaak(order, kerntaak)

    def add_kerntaak(self, order: int, kerntaak: tuple) -> int:
        """Adds a kerntaak and its werkprocessen after `order`; returns the last order used."""
        code, title, werkprocessen = kerntaak
        parent = self.b.add("competency", {
            "frameworkId": self.framework["uuid"], "code": code, "title": title,
            "description": f"Kerntaak {code} van de opleiding Mechatronica.", "order": order + 1,
            "requiredForRoles": ["learner"], "lifecycle": "published",
        })
        self.competencies[code] = parent
        for i, wp in enumerate(werkprocessen, start=1):
            self.competencies[f"{code}-W{i}"] = self.b.add("competency", {
                "frameworkId": self.framework["uuid"], "parentId": parent["uuid"], "code": f"{code}-W{i}", "title": wp,
                "description": f"Werkproces {code}-W{i}, beoordeeld in de beroepspraktijk.", "order": order + 1 + i,
                "requiredForRoles": ["learner"], "lifecycle": "published",
            })
        return order + 1 + len(werkprocessen)

    def add_programme(self) -> None:
        self.programme = self.b.add("programme", {
            "name": "Mechatronica", "code": "MT4", "level": "mbo",
            "description": f"Niveau 4, beroepsopleidende leerweg (bol) en beroepsbegeleidende leerweg (bbl), 4 jaar. Kerntaken en werkprocessen volgens het kwalificatiedossier met crebo {STORY_CREBO}.",
            "courseIds": [], "requiredCompetencyIds": [c["uuid"] for code, c in self.competencies.items() if "-W" in code],
            "lifecycle": "published",
        })
        self.courses: dict[str, dict] = {}
        self.courses["MT-OPL"] = self.b.add("course", {
            "code": "MT-OPL", "name": "Mechatronica (niveau 4)", "name_nl": "Mechatronica (niveau 4)",
            "description": f"Inschrijving voor de opleiding Mechatronica, crebo {STORY_CREBO}, 4 jaar.", "level": "mbo", "language": "nl",
            "tags": ["mbo", "niveau 4", "bol", "bbl"], "lifecycle": "published", "order": 0,
            "programmeIds": [self.programme["uuid"]], "competencyIds": [], "prerequisiteCourseIds": [],
        })

    def add_unit_courses(self) -> None:
        for n, (code, name, credits, _teachers, kerntaken, _parts) in enumerate(STORY_UNITS, start=1):
            self.courses[code] = self.b.add("course", {
                "code": code, "name": name, "name_nl": name, "description": f"Onderwijseenheid in leerjaar 2 van Mechatronica, schooljaar {STORY_YEAR}.",
                "level": "mbo", "language": "nl", "tags": ["mbo", "niveau 4", "leerjaar 2"], "lifecycle": "published",
                "parentCourseId": self.courses["MT-OPL"]["uuid"], "order": n, "programmeIds": [self.programme["uuid"]], "ectsCredits": credits,
                "competencyIds": [self.competencies[k]["uuid"] for k in kerntaken], "prerequisiteCourseIds": [],
            })
        self.courses["MT-BPV2"] = self.b.add("course", {
            "code": "MT-BPV2", "name": "Beroepspraktijkvorming mechatronica leerjaar 2", "name_nl": "Beroepspraktijkvorming mechatronica leerjaar 2",
            "description": "Beroepspraktijkvorming in leerjaar 2: werkprocessen van B1-K1 en B1-K2 bij een erkend leerbedrijf.",
            "level": "mbo", "language": "nl", "tags": ["mbo", "bpv", "leerjaar 2"], "lifecycle": "published",
            "parentCourseId": self.courses["MT-OPL"]["uuid"], "order": len(STORY_UNITS) + 1, "programmeIds": [self.programme["uuid"]],
            "ectsCredits": 20, "competencyIds": [self.competencies["B1-K1"]["uuid"], self.competencies["B1-K2"]["uuid"]], "prerequisiteCourseIds": [],
        })

    def add_plans(self) -> None:
        self.plans: dict[str, dict] = {}
        self.plans["MT-OER"] = self.b.add("curriculum-plan", {
            "name": f"Examenplan Mechatronica, {STORY_YEAR}", "kind": "oer",
            "requiredCourseIds": [self.courses[c[0]]["uuid"] for c in STORY_UNITS] + [self.courses["MT-BPV2"]["uuid"]], "electiveCourseIds": [],
            "components": [{"componentId": f"{c[0]}-EB", "label": f"{c[0]} {c[1]}", "weight": 1, "period": "S1", "kind": "assessment"} for c in STORY_UNITS] + STORY_PVB_COMPONENTS,
            "formula": "all-must-pass", "gradeScaleId": self.year.numeric["uuid"], "passRules": [], "periods": STORY_PERIOD_ROWS, "lifecycle": "published",
        })
        for unit in STORY_UNITS:
            self.add_unit_plan(unit)
        self.add_bpv_plan()

    def add_unit_plan(self, unit: tuple) -> None:
        code, name = unit[0], unit[1]
        components = story_unit_components(unit)
        self.plans[code] = self.b.add("curriculum-plan", {
            "name": f"{code} {name}, {STORY_YEAR}", "kind": "oer", "requiredCourseIds": [self.courses[code]["uuid"]], "electiveCourseIds": [],
            "components": components, "formula": "last-attempt", "gradeScaleId": self.year.numeric["uuid"],
            "passRules": [{"componentId": c["componentId"], "minValue": 5.5} for c in components],
            "periods": STORY_PERIOD_ROWS[:1], "lifecycle": "published",
        })
        self.courses[code]["curriculumPlanId"] = self.plans[code]["uuid"]

    def add_bpv_plan(self) -> None:
        self.plans["MT-BPV2"] = self.b.add("curriculum-plan", {
            "name": f"Proeven van bekwaamheid Mechatronica leerjaar 2, {STORY_YEAR}", "kind": "oer",
            "requiredCourseIds": [self.courses["MT-BPV2"]["uuid"]], "electiveCourseIds": [], "components": STORY_PVB_COMPONENTS,
            "formula": "all-must-pass", "gradeScaleId": self.year.competent_scale["uuid"],
            "passRules": [{"componentId": c["componentId"], "minValue": 1} for c in STORY_PVB_COMPONENTS], "periods": STORY_PERIOD_ROWS,
            "lifecycle": "published",
        })
        self.courses["MT-BPV2"]["curriculumPlanId"] = self.plans["MT-BPV2"]["uuid"]
        self.courses["MT-OPL"]["curriculumPlanId"] = self.plans["MT-OER"]["uuid"]
        self.programme["curriculumPlanId"] = self.plans["MT-OER"]["uuid"]
        self.programme["courseIds"] = [c["uuid"] for c in self.courses.values()]

    # The class and its two students.
    def add_class(self) -> None:
        self.cohort = self.b.add("cohort", {
            "name": "MT4-2A", "programmeId": self.programme["uuid"], "courseId": self.courses["MT-OPL"]["uuid"],
            "teacherIds": [STORY_SLB, STORY_COACH, "mbo-docent-13", "mbo-docent-14", "mbo-docent-11"],
            "learnerIds": [MILAN, AYLIN], "period": "Schooljaar", "academicYear": STORY_YEAR, "lifecycle": "active",
            "locationId": self.year.locations["techniekpark"]["uuid"],
            "teacherAssignments": [{"teacherId": STORY_SLB, "role": "primary", "days": [THU, FRI]}],
            "notes": ("Beroepspraktijkvorming op maandag, dinsdag en woensdag van maandag 31 augustus 2026 tot en met vrijdag 29 januari 2027; "
                      "op die dagen staan geen lessen op het rooster. Lessen op donderdag (Techniekpark) en vrijdag (Centrum)."),
            "kind": "teaching", "programmeYear": 2,
        })

    def add_students(self) -> None:
        self.profiles: dict[str, dict] = {}
        for volgnummer, person in enumerate(STORY_PEOPLE.items(), start=251):
            self.add_student(volgnummer, person)

    def add_student(self, volgnummer: int, person: tuple[str, tuple]) -> None:
        nc, (given, family, birth, address, contacts) = person
        self.profiles[nc] = self.b.add("learner-profile", {
            "ncUserId": nc, "givenName": given, "familyName": family, "birthDate": birth, "schoolId": self.year.school["uuid"],
            "eduPersonAffiliation": ["student"], "roles": ["learner"], "parentIds": [], "guardianRefs": [], "address": address,
            "emergencyContacts": contacts, "allergies": None, "medicalConditions": None,
            "beeldmateriaalConsent": {"website": True, "socialMedia": False, "schoolgids": True, "classPhoto": True, "video": False},
            "lifecycle": "active",
        })
        self.b.add("enrolment", {
            "learnerId": nc, "learnerRef": self.profiles[nc]["uuid"], "courseId": self.courses["MT-OPL"]["uuid"], "source": "admission",
            "cohortId": self.cohort["uuid"], "lifecycle": "active", "inschrijvingDate": "2025-08-18", "volgnummer": volgnummer,
            "locationId": self.year.locations["techniekpark"]["uuid"], "leerjaar": 2,
        })

    def add_teachers(self) -> None:
        for code, _name, _credits, teachers, _kerntaken, _parts in STORY_UNITS:
            for teacher in teachers:
                self.b.add("subjectteacherassignment", {"cohortId": self.cohort["uuid"], "courseId": self.courses[code]["uuid"], "teacherId": teacher})
        self.b.add("subjectteacherassignment", {"cohortId": self.cohort["uuid"], "courseId": self.courses["MT-BPV2"]["uuid"], "teacherId": STORY_COACH})

    # The leerbedrijf and its praktijkopleider. An active row with an e-mail
    # address, so `occ learniq:portal:invite-trainer` can invite her.
    def add_placements(self) -> None:
        self.petra = self.b.add("praktijkopleider", {
            "givenName": "Petra", "familyName": "Bakker", "email": "petra.bakker@bakker-techniek.example", "phone": "06-00002851",
            "trainingCompanyName": "Bakker Techniek BV", "trainingCompanyKvkNumber": STORY_KVK, "active": True,
        })
        self.placements = {spec[0]: self.place(spec) for spec in STORY_PLACEMENTS}
        # The agreements on Milan's placement page (board Detail, "Afspraken"):
        # his workdays, where he works and the qualification with its crebo.
        self.placements[MILAN].update({
            "workdaysLabel": "Maandag tot en met woensdag, 08.00 tot 16.30 uur",
            "workplaceAddress": "[adres leerbedrijf], Zuiddrecht",
            "qualificationName": "Mechatronica niveau 4",
            "crebo": "25743",
        })

    def place(self, spec: tuple) -> dict:
        nc, start, end, agreed, _terms, _signed = spec
        placement = self.b.add("bpv-placement", {
            "learnerId": nc, "learnerRef": self.profiles[nc]["uuid"], "programmeId": self.programme["uuid"],
            "curriculumPlanId": self.plans["MT-BPV2"]["uuid"], "practicalTrainerId": self.petra["uuid"], "schoolCoachId": STORY_COACH,
            "trainingCompanyName": "Bakker Techniek BV", "trainingCompanyKvkNumber": STORY_KVK,
            "periodFrom": start.isoformat(), "periodTo": end.isoformat(), "agreedHours": agreed, "hoursApprovedTotal": 0,
            "trainingCompanyVerification": {"provider": "sbb", "status": "verified", "erkenningNumber": STORY_ERKENNING,
                                            "verifiedAt": stamp(dt.date(2026, 8, 10), 10, 0),
                                            "expiresAt": stamp(dt.date(2028, 12, 31), 23, 59)},
            "lifecycle": "active",
        })
        self.add_agreement(placement, spec)
        return placement

    def add_agreement(self, placement: dict, spec: tuple) -> None:
        nc, start, end, _agreed, terms, signed = spec
        pok = self.b.add("praktijkovereenkomst", {
            "bpvPlacementId": placement["uuid"], "periodFrom": start.isoformat(), "periodTo": end.isoformat(), "terms": terms,
            "version": 1, "parentSignatureRequired": False, "lifecycle": "active",
        })
        for signer, role, hour, level, method in [(nc, "student", 10, "basic", "Nextcloud-account"),
                                                  (STORY_COACH, "school", 11, "substantial", "Nextcloud-account met tweestapsverificatie"),
                                                  (self.petra["uuid"], "praktijkopleider", 14, "basic", "Ondertekenlink per e-mail")]:
            self.b.add("pok-signature", {"subjectId": pok["uuid"], "subjectVersion": 1, "signerId": signer, "signerRole": role,
                                         "signedAt": stamp(signed, hour, 5), "assuranceLevel": level, "method": method})

    # Visits: the werkplan on 9 September, the tussenbeoordeling on 13 October
    # (still a draft: it has not happened yet).
    def add_visits(self) -> None:
        placement, profile = self.placements[MILAN], self.profiles[MILAN]
        self.b.add("bpv-visit-report", {
            "bpvPlacementId": placement["uuid"], "learnerRef": profile["uuid"], "visitDate": "2026-09-09",
            "visitKind": "voortgangsbezoek", "attendees": STORY_ATTENDEES, "schoolCoachId": STORY_COACH,
            "narrative": "Eerste bezoek op de werkplek. Samen het werkplan gemaakt: welke werkprocessen Milan tot januari oefent en bij welke opdrachten.",
            "actionPoints": "Milan schrijft zijn uren per dag en noemt het werkproces erbij. Tussenbeoordeling op dinsdag 13 oktober om 10.00 uur.",
            "lifecycle": "finalized",
        })
        self.b.add("bpv-visit-report", {
            "bpvPlacementId": placement["uuid"], "learnerRef": profile["uuid"], "visitDate": "2026-10-13",
            "visitKind": "tussentijds-gesprek", "attendees": STORY_ATTENDEES, "schoolCoachId": STORY_COACH,
            "narrative": "Tussenbeoordeling om 10.00 uur bij Bakker Techniek BV. Milan en Petra Bakker vullen vooraf elk een beoordeling in.",
            "lifecycle": "draft",
        })

    # Milan's own record per work process (board Detail, "Werkprocessen"):
    # the hours he spent on it and his own estimate; the last two he still has
    # to fill in. Petra's judgement comes in the werkproces assessment.
    def add_progress(self) -> None:
        for code, label, hours, own in STORY_PROGRESS:
            row = {"bpvPlacementId": self.placements[MILAN]["uuid"], "learnerId": MILAN, "learnerRef": self.profiles[MILAN]["uuid"],
                   "werkprocesCode": code, "werkprocesLabel": label, "hoursSpent": hours}
            if own is not None:
                row["selfAssessment"] = own
            self.b.add("werkproces-progress", row)

    # Weeks of BPV hours. Milan: weeks 36 to 39 approved (4 x 24 = 96). Week
    # 40 holds its days (bpv-hours-per-day): Monday 28, Tuesday 29 and
    # Wednesday 30 September, each 08.00 to 16.30 with 30 minutes break (24
    # hours). Petra sent the week back about the Tuesday with her question, so
    # nothing of it is approved yet (D-7: she approves the week, a return names
    # the day). Week 41 is a draft he keeps for later with Monday 5 October.
    # Aylin: weeks 35 to 39 approved (5 x 32 = 160) and week 40 waiting.
    def add_hour_weeks(self) -> None:
        self.add_approved_weeks(MILAN, (dt.date(2026, 8, 31), 4, 24, 2, (9, 20)))
        question = ("Je schreef 8 uur op dinsdag 29 september. Volgens mij ging je om 14.00 uur naar de tandarts. "
                    "Wil je de uren aanpassen? Dan keur ik de hele week goed.")
        returned = self.week(MILAN, (dt.date(2026, 9, 28), 24, 2, {
            "approved": 0, "at": stamp(dt.date(2026, 10, 2), 16, 42), "lifecycle": "rejected", "note": question,
        }))
        returned["days"] = story_days(dt.date(2026, 9, 28), 3, {1: question})
        returned["returnSubject"] = "Vraag over je uren van dinsdag 29 september"
        draft = self.week(MILAN, (dt.date(2026, 10, 5), 8, 0, None))
        draft["days"] = story_days(dt.date(2026, 10, 5), 1, {})
        draft["lifecycle"] = "draft"
        del draft["submittedAt"]
        self.add_approved_weeks(AYLIN, (dt.date(2026, 8, 24), 5, 32, 3, (9, 30)))
        self.week(AYLIN, (dt.date(2026, 9, 28), 32, 3, None))

    def add_approved_weeks(self, nc: str, run: tuple) -> None:
        """`count` weeks from `first`, each approved in full on the Friday; the placement's approved total is their sum."""
        first, count, hours, last_day, (hour, minute) = run
        approved = 0
        for i in range(count):
            monday = first + dt.timedelta(weeks=i)
            self.week(nc, (monday, hours, last_day, {"approved": hours, "at": stamp(monday + dt.timedelta(days=4), hour, minute), "lifecycle": "approved"}))
            approved += hours
        self.placements[nc]["hoursApprovedTotal"] = approved

    def week(self, nc: str, entered: tuple) -> dict:
        monday, hours, last_day, decided = entered
        iso_year, iso_week, _d = monday.isocalendar()
        fields = {
            "bpvPlacementId": self.placements[nc]["uuid"], "learnerRef": self.profiles[nc]["uuid"], "isoWeek": f"{iso_year}-W{iso_week:02d}",
            "hoursSubmitted": hours, "submittedBy": self.profiles[nc]["uuid"],
            "submittedAt": stamp(monday + dt.timedelta(days=last_day), 17, 10),
        }
        if decided is None:
            fields["lifecycle"] = "submitted"
        else:
            fields.update(self.week_decision(decided))
        return self.b.add("bpv-hour-week", fields)

    def week_decision(self, decided: dict) -> dict:
        decision = {"hoursApproved": decided["approved"], "approvedBy": self.petra["uuid"], "approvedByName": "Petra Bakker",
                    "approvedAt": decided["at"], "assuranceLevel": "basic", "lifecycle": decided["lifecycle"]}
        if decided.get("note"):
            decision["note"] = decided["note"]
        return decision

    def add_lessons(self) -> None:
        for lesson in STORY_LESSONS:
            self.add_lesson(lesson)

    def add_lesson(self, lesson: tuple) -> None:
        day, (h1, m1), (h2, m2), unit, room, state = lesson
        fields = {
            "cohortId": self.cohort["uuid"], "courseId": self.courses[unit]["uuid"], "title": f"MT4-2A, {STORY_UNIT_NAMES[unit]}, {dutch_date(day)}",
            "startsAt": stamp(day, h1, m1), "endsAt": stamp(day, h2, m2), "location": self.rooms[room]["name"],
            "roomId": self.rooms[room]["uuid"], "lifecycle": "scheduled",
        }
        if state == "cancelled":
            fields.update({"lifecycle": "cancelled", "changeReasonKind": "teacher-absence",
                           "changeReason": "De docent Engels is afwezig. De les vervalt.",
                           "affectedLearnerIds": [MILAN, AYLIN], "affectedParentIds": [],
                           "changedAt": stamp(dt.date(2026, 9, 30), 11, 30)})
        self.b.add("session", fields)

    # The voortgangsgesprek with the studieloopbaanbegeleider.
    def add_conference(self) -> None:
        round_row = self.b.add("conference-round", {
            "name": "Voortgangsgesprekken MT4-2A, oktober 2026", "cohortIds": [self.cohort["uuid"]], "teacherIds": [STORY_SLB],
            "slotDurationMinutes": 30, "bufferMinutes": 0, "bookingOpensAt": stamp(dt.date(2026, 9, 28), 8, 0),
            "bookingClosesAt": stamp(dt.date(2026, 10, 9), 17, 0), "invitedLearnerIds": [MILAN, AYLIN],
            "invitedLearnerRefs": [self.profiles[MILAN]["uuid"], self.profiles[AYLIN]["uuid"]], "lifecycle": "scheduled",
        })
        self.b.add("conference-slot", {
            "conferenceRoundId": round_row["uuid"], "teacherId": STORY_SLB, "learnerId": MILAN, "learnerRef": self.profiles[MILAN]["uuid"],
            "startsAt": stamp(dt.date(2026, 10, 15), 15, 15), "endsAt": stamp(dt.date(2026, 10, 15), 15, 45),
            "location": self.rooms["B2.11"]["name"], "teacherName": "Fenna Yilmaz",
            "slotLabel": "donderdag 15 oktober 2026, 15.15 uur, Fenna Yilmaz", "lifecycle": "confirmed",
        })

    # The exam: Nederlands lezen en luisteren, Tuesday 3 November 09.00 in B1.08.
    def add_exam(self) -> None:
        period = self.b.add("exam-period", {
            "name": "Examenweek november 2026", "startsOn": "2026-11-02", "endsOn": "2026-11-06", "cohortIds": [self.cohort["uuid"]],
            "courseIds": [self.courses["MT-2.5"]["uuid"]], "lifecycle": "published",
        })
        exam = self.b.add("exam", {
            "title": "Examen Nederlands lezen en luisteren", "description": "Centraal examen Nederlands, onderdelen lezen en luisteren. Neem je ID-bewijs mee.",
            "courseId": self.courses["MT-2.5"]["uuid"], "cohortId": self.cohort["uuid"], "curriculumPlanComponentId": "MT-2.5-LL",
            "gradeEntryComponentId": "MT-2.5-LL", "scoringScheme": "passMark", "passMark": 5.5, "timeLimitMinutes": 90, "maxAttempts": 1,
            "lifecycle": "published",
        })
        self.add_exam_sitting(period, exam)

    def add_exam_sitting(self, period: dict, exam: dict) -> None:
        self.b.add("exam-sitting", {
            "examPeriodId": period["uuid"], "assessmentId": exam["uuid"], "cohortIds": [self.cohort["uuid"]],
            "startsAt": stamp(dt.date(2026, 11, 3), 9, 0), "endsAt": stamp(dt.date(2026, 11, 3), 10, 30),
            "roomIds": [self.rooms["B1.08"]["uuid"]], "headcount": 2, "invigilatorsNeeded": 1, "lifecycle": "planned",
        })


def story_unit_components(unit: tuple) -> list[dict]:
    """A story unit's components: its named parts, or one final assessment."""
    code, name, parts = unit[0], unit[1], unit[5]
    if parts:
        return [{"componentId": f"{code}-{key}", "label": label, "weight": 1, "period": "S1", "kind": "assessment"} for key, label in parts]
    return [{"componentId": f"{code}-EB", "label": f"{code} {name}", "weight": 1, "period": "S1", "kind": "assessment"}]


def sessions_by_uuid_date(sessions: dict[tuple[str, dt.date], dict], uuid: str) -> str:
    """The date (YYYY-MM-DD) of the session with this uuid."""
    for (_c, day), row in sessions.items():
        if row["uuid"] == uuid:
            return day.isoformat()
    raise KeyError(uuid)


if __name__ == "__main__":
    sys.exit(main(Script(SET, __doc__, build)))
