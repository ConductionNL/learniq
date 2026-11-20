#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
# Copyright (C) 2026 Conduction B.V.
"""Build lib/Settings/profiles/he.json, the higher education example set.

One fictional university of applied sciences, Hogeschool Zuiddrecht in the
fictional town of Zuiddrecht, through one complete academic year
(2025-2026): two faculties as locations, four bachelor programmes with learning
outcomes on the Dublin descriptors, courses with ECTS credits, a cohort per
programme per study year, 400 students with their course enrolments, a digital
exam per programme built from an item bank (with item statistics), grade
entries and final grades, binding study advice (BSA) for every first-year
student with the flags and warnings before it, study advisers on Staff, a peer
reviewed group project, internship portfolios shared with workplace assessors,
one proctoring session and learning record exports.

WHY A SCRIPT. The set is several thousand objects that must agree with each
other: a final grade is exactly what the grade engine computes from the
published entries of its course, a BSA decision counts exactly the credits of
the passed final grades, a negative decision follows an issued warning, an item
statistic is computed from the stored responses. Hand-editing that is how sets
drift. The script is deterministic (fixed seed), so running it again produces
the same file byte for byte, and a reviewer reads the rules here rather than
megabytes of JSON.

THE CONTRACT. openspec/changes/archive/2026-09-28-segment-wizard-choice/contract.md. Every rule is
checked by tests/Unit/Settings/ExampleSetDescriptorContractTest.php; the story
is checked by tests/Unit/Settings/HigherEducationExampleSetTest.php. Run both
after regenerating.

Usage:
    python3 scripts/example-sets/he.py            write the file
    python3 scripts/example-sets/he.py --check    exit 1 when the file on disk differs

Nothing here is real: no real institution, BRIN, person, company, address or
phone number. Postcodes start with 0 and phone numbers with 06-0, which the
Netherlands never issues; the BRIN 00X4 ends in a digit, which DUO never
assigns; e-mail addresses use the reserved .example domain.
"""

from __future__ import annotations

import datetime as dt
import math
import random
import sys
from dataclasses import dataclass
from decimal import ROUND_HALF_UP, Decimal
from typing import cast
from xml.sax.saxutils import escape
from zoneinfo import ZoneInfo

from example_set import Builder, Script, SetIdentity, document, main

AMS = ZoneInfo("Europe/Amsterdam")
SET = "he"
SET_NUMBER = "04"
YEAR = "2025-2026"
PASS = 5.5
BSA_NORM = 45.0
BSA_INTERIM_NORM = 30.0
BSA_CHECK = dt.date(2026, 2, 9)
WITHDRAWAL_DAY = dt.date(2026, 1, 16)

# Schema number (the TTTT group of the uuid) per bucket, in load order.
# Parents come before children; removal runs in reverse. The only forward
# references are back-pointers of a pair (a programme lists its courses, a
# result names its grade entry), which the importer stores as plain values.
SCHEMAS = [
    "school",
    "vestiging",
    "room",
    "grade-scale",
    "competency-framework",
    "competency",
    "programme",
    "course",
    "curriculum-plan",
    "cohort",
    "staff",
    "learner-profile",
    "enrolment",
    "subjectteacherassignment",
    "session",
    "excuse-request",
    "attendance-record",
    "attendance-threshold",
    "attendance-flag",
    "item-bank",
    "item",
    "exam",
    "exam-accommodation",
    "assessment-result",
    "proctoring-session",
    "item-statistics",
    "assessment-reliability",
    "item-revision-flag",
    "rubric",
    "assignment",
    "submission",
    "peer-review",
    "peer-feedback-summary",
    "portfolio-template",
    "external-assessor",
    "portfolio",
    "portfolio-entry",
    "portfolio-share",
    "exemption-case",
    "fraud-case",
    "grade-entry",
    "final-grade",
    "bsa-trajectory",
    "bsa-progress-flag",
    "bsa-warning",
    "bsa-decision",
    "learning-record-export",
]
IDENTITY = SetIdentity(SET, SET_NUMBER, SCHEMAS)

HOLIDAYS = [
    ("Herfstvakantie", dt.date(2025, 10, 20), dt.date(2025, 10, 24)),
    ("Kerstvakantie", dt.date(2025, 12, 22), dt.date(2026, 1, 2)),
    ("Voorjaarsvakantie", dt.date(2026, 2, 16), dt.date(2026, 2, 20)),
    ("Goede Vrijdag en Tweede Paasdag", dt.date(2026, 4, 3), dt.date(2026, 4, 6)),
    ("Koningsdag en meivakantie", dt.date(2026, 4, 27), dt.date(2026, 5, 1)),
    ("Hemelvaart", dt.date(2026, 5, 14), dt.date(2026, 5, 15)),
    ("Tweede Pinksterdag", dt.date(2026, 5, 25), dt.date(2026, 5, 25)),
]

# Blocks (onderwijsperiodes): id, label, start, end, teaching weeks (Mondays),
# first-attempt results day, resit results day.
BLOCKS = [
    ("B1", "Blok 1", dt.date(2025, 9, 1), dt.date(2025, 11, 14),
     [dt.date(2025, 9, 1), dt.date(2025, 9, 8), dt.date(2025, 9, 15), dt.date(2025, 9, 22),
      dt.date(2025, 9, 29), dt.date(2025, 10, 6), dt.date(2025, 10, 13), dt.date(2025, 10, 27)],
     dt.date(2025, 11, 21), dt.date(2026, 2, 6)),
    ("B2", "Blok 2", dt.date(2025, 11, 17), dt.date(2026, 2, 6),
     [dt.date(2025, 11, 17), dt.date(2025, 11, 24), dt.date(2025, 12, 1), dt.date(2025, 12, 8),
      dt.date(2025, 12, 15), dt.date(2026, 1, 5), dt.date(2026, 1, 12), dt.date(2026, 1, 19)],
     dt.date(2026, 2, 6), dt.date(2026, 5, 8)),
    ("B3", "Blok 3", dt.date(2026, 2, 9), dt.date(2026, 4, 24),
     [dt.date(2026, 2, 9), dt.date(2026, 2, 23), dt.date(2026, 3, 2), dt.date(2026, 3, 9),
      dt.date(2026, 3, 16), dt.date(2026, 3, 23), dt.date(2026, 3, 30), dt.date(2026, 4, 6)],
     dt.date(2026, 5, 8), dt.date(2026, 7, 6)),
    ("B4", "Blok 4", dt.date(2026, 5, 4), dt.date(2026, 7, 3),
     [dt.date(2026, 5, 4), dt.date(2026, 5, 11), dt.date(2026, 5, 18), dt.date(2026, 5, 25),
      dt.date(2026, 6, 1), dt.date(2026, 6, 8)],
     dt.date(2026, 6, 30), dt.date(2026, 7, 6)),
]
SEMESTERS = [
    ("S1", "Semester 1", dt.date(2025, 9, 1), dt.date(2026, 2, 6), dt.date(2026, 2, 6), dt.date(2026, 5, 8)),
    ("S2", "Semester 2", dt.date(2026, 2, 9), dt.date(2026, 7, 3), dt.date(2026, 6, 30), dt.date(2026, 7, 6)),
]
WEEKDAYS = ["monday", "tuesday", "wednesday", "thursday", "friday"]
DAG = ["maandag", "dinsdag", "woensdag", "donderdag", "vrijdag", "zaterdag", "zondag"]
MAAND = ["januari", "februari", "maart", "april", "mei", "juni", "juli", "augustus", "september", "oktober", "november", "december"]
DUBLIN = [
    ("DD1", "Kennis en inzicht"),
    ("DD2", "Toepassen van kennis en inzicht"),
    ("DD3", "Oordeelsvorming"),
    ("DD4", "Communicatie"),
    ("DD5", "Leervaardigheden"),
]
LEVELS = [
    {"levelId": "niet-aangetoond", "label": "Nog niet aangetoond", "order": 1, "minPercent": 0},
    {"levelId": "propedeuse", "label": "Propedeuseniveau", "order": 2, "minPercent": 55},
    {"levelId": "hoofdfase", "label": "Hoofdfaseniveau", "order": 3, "minPercent": 70},
    {"levelId": "eindniveau", "label": "Eindniveau bachelor", "order": 4, "minPercent": 85},
]

MEN = [
    "Daan", "Sem", "Thomas", "Lars", "Tim", "Jesse", "Bram", "Luuk", "Milan", "Ruben", "Stijn", "Thijs", "Niels", "Koen",
    "Jasper", "Rick", "Max", "Sven", "Tom", "Joris", "Mohamed", "Youssef", "Omar", "Kevin", "Dylan", "Robin", "Nick",
    "Jelle", "Wessel", "Gijs", "Mees", "Ilias", "Rayan", "Casper", "Floris", "Bas", "Jort", "Tygo", "Hamza", "Pim",
]
WOMEN = [
    "Emma", "Lisa", "Sanne", "Anna", "Julia", "Lotte", "Eva", "Fleur", "Iris", "Anouk", "Femke", "Isa", "Lieke", "Maud",
    "Noor", "Sara", "Esmee", "Romy", "Demi", "Kim", "Laura", "Britt", "Floor", "Yasmina", "Fatima", "Amira", "Naomi",
    "Nina", "Merel", "Jasmijn", "Zoë", "Selin", "Hanna", "Roos", "Veerle", "Nadia", "Elif", "Mila", "Tess", "Jente",
]
PARENTS = ["Marleen", "Peter", "Ingrid", "Rob", "Monique", "Hans", "Saskia", "Karim", "Yvonne", "Ahmed", "Carla", "Erik"]
SURNAME_HEAD = [
    "Esdoorn", "Beuken", "Eiken", "Populier", "Zwaluw", "Reiger", "Leeuwerik", "Kievit", "Zilver", "Koper",
    "Tarwe", "Rogge", "Haver", "Klaproos", "Vlinder", "Merel", "Distel", "Brem", "Wilde", "Korenbloem",
]
SURNAME_TAIL = ["horst", "veld", "kamp", "wijk", "dijk", "berg", "hoven", "laar", "broek", "stede", "donk", "rade", "mond", "hage"]
STREETS = [
    "Esdoornlaan", "Beukenhof", "Populierenweg", "Zwaluwstraat", "Reigerpad", "Kievitlaan", "Zilverschoon", "Tarweveld",
    "Klaproosstraat", "Vlinderhof", "Distelweg", "Korenbloemlaan", "Stationsplein Voorbeeldhoven", "Campuslaan",
]
TOWNS = ["Zuiddrecht", "Zuiddrecht", "Zuiddrecht", "Voorbeeldhoven", "Voorbeeldveen"]
# The students the learniq boards LqBsaBesluit and LqStudievoortgang draw, on the
# user ids of the first-year HBO-V students flagged at the interim BSA check:
# he-student-008 holds the negative decision. Set after numbering, so the order,
# every draw and every uuid stay the same.
BOARD_NAMES = {
    "he-student-004": ("Lucas", "Peters"),
    "he-student-006": ("Ravi", "Doekhie"),
    "he-student-007": ("Sara", "El Khatib"),
    "he-student-008": ("Femke", "de Boer"),
    "he-student-017": ("Zoë", "Vermeulen"),
    "he-student-022": ("Milan", "de Jong"),
}

# --- the four programmes ------------------------------------------------------
# key, short name, name, code, faculty key, werkgroep weekday (0 = Monday),
# werkgroep room key, exam day in week 45, resit day in week 6, difficulty offset.
PROGRAMMES = [
    ("VPK", "HBO-V", "HBO-V Verpleegkunde", "B-VPK", "zuid", 1, "skillslab", dt.date(2025, 11, 4), dt.date(2026, 2, 3), 0.0),
    ("SW", "Social Work", "Social Work", "B-SW", "zuid", 2, "z112", dt.date(2025, 11, 5), dt.date(2026, 2, 4), 0.15),
    ("ICT", "HBO-ICT", "HBO-ICT", "B-ICT", "noord", 3, "ictlab", dt.date(2025, 11, 6), dt.date(2026, 2, 5), -0.1),
    ("WTB", "Werktuigbouwkunde", "Werktuigbouwkunde", "B-WTB", "noord", 0, "werkplaats", dt.date(2025, 11, 3), dt.date(2026, 2, 2), -0.25),
]
PROGRAMME_DESCRIPTION = {
    "VPK": "Vierjarige bachelor verpleegkunde met stages in het ziekenhuis, de wijk en de langdurige zorg.",
    "SW": "Vierjarige bachelor sociaal werk voor wijkteams, jeugdhulp en welzijn.",
    "ICT": "Vierjarige bachelor ICT met software engineering, infrastructuur en werken voor echte opdrachtgevers.",
    "WTB": "Vierjarige bachelor werktuigbouwkunde met ontwerpen, rekenen en maken in de werkplaats.",
}
STUDENTS_PER_YEAR = {1: 34, 2: 24, 3: 22, 4: 20}

# Ten learning outcomes per programme, two under each Dublin descriptor.
OUTCOMES = {
    "VPK": [
        "Beschrijft de anatomie, fysiologie en ziekteleer die verpleegkundig handelen vraagt.",
        "Kent de richtlijnen en het wettelijk kader van het verpleegkundig beroep.",
        "Stelt samen met de zorgvrager een verpleegplan op met doelen en interventies.",
        "Voert verpleegtechnische handelingen veilig en volgens protocol uit.",
        "Redeneert klinisch en onderbouwt keuzes met bewijs en ervaring.",
        "Herkent ethische dilemma's en weegt de belangen van de zorgvrager af.",
        "Voert gesprekken met zorgvragers en hun naasten.",
        "Rapporteert en draagt gestructureerd over aan collega's.",
        "Reflecteert op het eigen handelen en stelt leerdoelen.",
        "Zoekt en beoordeelt wetenschappelijke literatuur voor de praktijk.",
    ],
    "SW": [
        "Kent theorieën over sociale problemen en over hulpverlening.",
        "Kent de sociale wetgeving en de voorzieningen in de gemeente.",
        "Werkt methodisch met individuen en gezinnen.",
        "Organiseert groepswerk en participatie in de wijk.",
        "Weegt belangen af in complexe situaties.",
        "Handelt volgens de beroepscode voor sociaal werkers.",
        "Voert motiverende gesprekken met inwoners.",
        "Werkt samen met ketenpartners en rapporteert helder.",
        "Reflecteert op eigen normen, waarden en handelen.",
        "Doet praktijkgericht onderzoek in het sociaal domein.",
    ],
    "ICT": [
        "Kent de principes van programmeren en datastructuren.",
        "Kent netwerken, besturingssystemen en informatiebeveiliging.",
        "Ontwerpt en bouwt software die aan de eisen van een opdrachtgever voldoet.",
        "Modelleert gegevens en beheert een database.",
        "Analyseert een probleem en kiest een passende technische oplossing.",
        "Beoordeelt kwaliteit, privacy en veiligheid van software.",
        "Werkt in een scrumteam samen met een opdrachtgever.",
        "Documenteert en presenteert technische keuzes.",
        "Houdt de eigen kennis bij en leert nieuwe technologie.",
        "Onderzoekt technologie met een onderbouwde aanpak.",
    ],
    "WTB": [
        "Kent de mechanica, sterkteleer en materiaalkunde.",
        "Kent productietechnieken en hun beperkingen.",
        "Ontwerpt een werktuigbouwkundig product met CAD.",
        "Berekent de sterkte en stijfheid van constructies.",
        "Kiest materialen en processen op onderbouwde criteria.",
        "Beoordeelt veiligheid en duurzaamheid van een ontwerp.",
        "Werkt in een projectteam samen met een opdrachtgever.",
        "Maakt technische tekeningen en rapporten.",
        "Reflecteert op de eigen ontwikkeling als ingenieur.",
        "Voert experimenten uit en verwerkt meetgegevens.",
    ],
}

# Courses per programme: key, study year, period id, ECTS, name, description,
# components (id, label, kind, weight), learning outcome numbers (1-based),
# docent index (0-5) who teaches it.
Y1_COMPONENTS = [("kennistoets", "Kennistoets", "assessment", 1), ("beroepsproduct", "Beroepsproduct", "assignment", 1)]
PROJECT_COMPONENTS = [("projectverslag", "Projectverslag (groep)", "assignment", 2), ("presentatie", "Individuele presentatie", "assessment", 1)]
COURSES = {
    "VPK": [
        ("B1", 1, "B1", 15, "Basis van de verpleegkundige zorg", "Vitale functies, hygiëne, veiligheid en het verpleegplan.", Y1_COMPONENTS, [1, 2, 4], 0),
        ("B2", 1, "B2", 15, "Anatomie, fysiologie en ziekteleer", "Het menselijk lichaam en de ziektebeelden die je het vaakst ziet.", Y1_COMPONENTS, [1, 5], 1),
        ("B3", 1, "B3", 15, "Communicatie en klinisch redeneren", "Gesprekken met zorgvragers en naasten, rapporteren en overdragen.", Y1_COMPONENTS, [5, 7, 8], 2),
        ("B4", 1, "B4", 15, "Project zorg in de wijk", "In een projectgroep een zorgvraag in de wijk onderzoeken en een plan presenteren.", PROJECT_COMPONENTS, [3, 7, 9], 3),
        ("S1", 2, "S1", 30, "Acute en klinische zorg", "Zorg voor acuut zieke zorgvragers in het ziekenhuis.", [("integrale-toets", "Integrale toets", "assessment", 1)], [4, 5, 8], 4),
        ("S2", 2, "S2", 30, "Chronische zorg en eigen regie", "Zorg voor mensen met een chronische aandoening, thuis en in de langdurige zorg.", [("integrale-toets", "Integrale toets", "assessment", 1)], [3, 6, 7], 5),
        ("STAGE", 3, "S1", 30, "Stage ziekenhuis of wijkteam", "Twintig weken meewerken in de praktijk, met een stageportfolio.", [("stageportfolio", "Stageportfolio", "assignment", 1)], [3, 4, 7, 9], 2),
        ("MINOR", 3, "S2", 30, "Minor zorg en technologie", "Verdieping in zorgtechnologie, e-health en innovatie.", [("minorbeoordeling", "Minorbeoordeling", "assignment", 1)], [6, 10], 5),
        ("VERD", 4, "S1", 30, "Verpleegkundig leiderschap", "Coördineren van zorg, kwaliteit en samenwerken in een team.", [("beroepsopdracht", "Beroepsopdracht", "assignment", 1)], [6, 8], 4),
        ("AFST", 4, "S2", 30, "Afstuderen verpleegkunde", "Een praktijkonderzoek en de afstudeerbeoordeling in de beroepspraktijk.", [("afstudeerwerk", "Afstudeerwerk en verdediging", "assessment", 1)], [5, 9, 10], 3),
    ],
    "SW": [
        ("B1", 1, "B1", 15, "Oriëntatie op het sociaal werk", "Het beroep, de wetgeving en de voorzieningen in het sociaal domein.", Y1_COMPONENTS, [1, 2], 0),
        ("B2", 1, "B2", 15, "Gespreksvoering en contact", "Contact maken, luisteren en motiverende gesprekken voeren.", Y1_COMPONENTS, [7, 9], 1),
        ("B3", 1, "B3", 15, "Recht en sociaal beleid", "Participatiewet, Wmo en jeugdwet in de praktijk van het wijkteam.", Y1_COMPONENTS, [2, 5, 6], 2),
        ("B4", 1, "B4", 15, "Project de wijk in beeld", "In een projectgroep een wijk verkennen en een plan met bewoners maken.", PROJECT_COMPONENTS, [4, 8, 10], 3),
        ("S1", 2, "S1", 30, "Methodisch werken met gezinnen", "Hulpverleningsplannen, gezinsgesprekken en veiligheid.", [("integrale-toets", "Integrale toets", "assessment", 1)], [3, 5], 4),
        ("S2", 2, "S2", 30, "Groepswerk en participatie", "Groepen begeleiden en bewoners laten meedoen.", [("integrale-toets", "Integrale toets", "assessment", 1)], [4, 7], 5),
        ("STAGE", 3, "S1", 30, "Stage sociaal werk", "Twintig weken meewerken in een wijkteam of jeugdhulp, met een stageportfolio.", [("stageportfolio", "Stageportfolio", "assignment", 1)], [3, 6, 7, 9], 2),
        ("MINOR", 3, "S2", 30, "Minor armoede en schulden", "Verdieping in schuldhulp, bestaanszekerheid en preventie.", [("minorbeoordeling", "Minorbeoordeling", "assignment", 1)], [2, 5], 5),
        ("VERD", 4, "S1", 30, "Praktijkgericht onderzoek", "Een onderzoeksvraag uit de praktijk uitwerken met een opdrachtgever.", [("beroepsopdracht", "Beroepsopdracht", "assignment", 1)], [8, 10], 4),
        ("AFST", 4, "S2", 30, "Afstuderen sociaal werk", "Een beroepsproduct voor de praktijk en de afstudeerbeoordeling.", [("afstudeerwerk", "Afstudeerwerk en verdediging", "assessment", 1)], [5, 9, 10], 3),
    ],
    "ICT": [
        ("B1", 1, "B1", 15, "Programmeren basis", "Variabelen, lussen, functies en testen.", Y1_COMPONENTS, [1, 3], 0),
        ("B2", 1, "B2", 15, "Databases en datamodellen", "Gegevens modelleren, SQL en een database beheren.", Y1_COMPONENTS, [4, 5], 1),
        ("B3", 1, "B3", 15, "Webontwikkeling", "Front-end en back-end van een webapplicatie bouwen.", Y1_COMPONENTS, [3, 6], 2),
        ("B4", 1, "B4", 15, "Project een app voor de opdrachtgever", "In een scrumteam een app bouwen voor een echte opdrachtgever.", PROJECT_COMPONENTS, [3, 7, 8], 3),
        ("S1", 2, "S1", 30, "Software engineering", "Ontwerpen, testen en onderhouden van grotere systemen.", [("integrale-toets", "Integrale toets", "assessment", 1)], [3, 5, 6], 4),
        ("S2", 2, "S2", 30, "Infrastructuur en security", "Netwerken, cloud en informatiebeveiliging.", [("integrale-toets", "Integrale toets", "assessment", 1)], [2, 6], 5),
        ("STAGE", 3, "S1", 30, "Stage ICT", "Twintig weken meewerken bij een ICT-bedrijf of -afdeling, met een stageportfolio.", [("stageportfolio", "Stageportfolio", "assignment", 1)], [3, 7, 8, 9], 2),
        ("MINOR", 3, "S2", 30, "Minor data en AI", "Verdieping in data-analyse en het verantwoord inzetten van AI.", [("minorbeoordeling", "Minorbeoordeling", "assignment", 1)], [5, 6], 5),
        ("VERD", 4, "S1", 30, "Architectuur en kwaliteit", "Softwarearchitectuur, kwaliteitseisen en technische schuld.", [("beroepsopdracht", "Beroepsopdracht", "assignment", 1)], [5, 6, 8], 4),
        ("AFST", 4, "S2", 30, "Afstuderen ICT", "Een afstudeeropdracht bij een bedrijf en de verdediging.", [("afstudeerwerk", "Afstudeerwerk en verdediging", "assessment", 1)], [3, 9, 10], 3),
    ],
    "WTB": [
        ("B1", 1, "B1", 15, "Mechanica en sterkteleer", "Krachten, momenten, evenwicht en spanning.", Y1_COMPONENTS, [1, 4], 0),
        ("B2", 1, "B2", 15, "Technisch tekenen en CAD", "Normen, aanzichten en modelleren in CAD.", Y1_COMPONENTS, [3, 8], 1),
        ("B3", 1, "B3", 15, "Materiaalkunde en productie", "Metalen, kunststoffen en productieprocessen.", Y1_COMPONENTS, [2, 5], 2),
        ("B4", 1, "B4", 15, "Project ontwerp een hijsinrichting", "In een projectgroep een hijsinrichting ontwerpen, berekenen en presenteren.", PROJECT_COMPONENTS, [3, 4, 7], 3),
        ("S1", 2, "S1", 30, "Thermodynamica en stroming", "Warmte, energie en stroming in machines.", [("integrale-toets", "Integrale toets", "assessment", 1)], [1, 10], 4),
        ("S2", 2, "S2", 30, "Regeltechniek en mechatronica", "Sensoren, aandrijvingen en regelsystemen.", [("integrale-toets", "Integrale toets", "assessment", 1)], [5, 10], 5),
        ("STAGE", 3, "S1", 30, "Stage werktuigbouwkunde", "Twintig weken meewerken bij een technisch bedrijf, met een stageportfolio.", [("stageportfolio", "Stageportfolio", "assignment", 1)], [3, 7, 8, 9], 2),
        ("MINOR", 3, "S2", 30, "Minor duurzame energie", "Verdieping in energietechniek en circulair ontwerpen.", [("minorbeoordeling", "Minorbeoordeling", "assignment", 1)], [5, 6], 5),
        ("VERD", 4, "S1", 30, "Productontwikkeling", "Van eisen naar prototype, met kostprijs en maakbaarheid.", [("beroepsopdracht", "Beroepsopdracht", "assignment", 1)], [3, 5, 6], 4),
        ("AFST", 4, "S2", 30, "Afstuderen werktuigbouwkunde", "Een afstudeeropdracht bij een bedrijf en de verdediging.", [("afstudeerwerk", "Afstudeerwerk en verdediging", "assessment", 1)], [4, 9, 10], 3),
    ],
}

# Item banks for each programme's block 1 knowledge test: sixteen closed items
# (choice with the correct letter, or a text entry with its answer) and two
# open questions. The main exam uses C1 to C11 and O1, the resit C6 to C16 and O2.
# `b` is the item's difficulty on the ability scale; three items per bank are
# deliberately flawed so the item analysis has something to find.
ITEMS = {
    "VPK": [
        ("choice", "Wat is een normale ademfrequentie van een volwassene in rust?", ["6 tot 8 per minuut", "12 tot 20 per minuut", "25 tot 30 per minuut", "35 tot 40 per minuut"], "B", -0.6),
        ("choice", "Wanneer desinfecteer je je handen in ieder geval?", ["Alleen na het eten", "Voor en na elk contact met een zorgvrager", "Alleen aan het einde van de dienst", "Alleen als je handen zichtbaar vuil zijn"], "B", -1.0),
        ("choice", "Waar is een vroege waarschuwingsscore voor bedoeld?", ["Pijn meten", "Achteruitgang van een zorgvrager op tijd zien", "De voedingstoestand bepalen", "De mobiliteit meten"], "B", 0.2),
        ("choice", "Welke houding helpt een benauwde zorgvrager?", ["Plat op de rug", "Rechtop zitten met steun voor de armen", "Op de buik liggen", "Met de benen omhoog"], "B", -3.5),
        ("text", "Hoe heet de gestructureerde overdracht met situatie, achtergrond, beoordeling en advies? Geef de afkorting.", None, "SBAR", 0.0),
        ("choice", "Wat is een vroeg teken van uitdroging?", ["Dorst en een droge mond", "Oedeem aan de enkels", "Een lage hartslag", "Blozende wangen"], "A", -0.3),
        ("choice", "Wat doe je als eerste bij een zorgvrager die gevallen is?", ["Direct overeind helpen", "Beoordelen of de zorgvrager gewond is", "De familie bellen", "Het incident melden"], "B", None),
        ("choice", "Vanaf welke bloeddruk spreek je bij een volwassene van hypertensie?", ["100/60 mmHg", "120/70 mmHg", "140/90 mmHg", "160/110 mmHg"], "C", 0.6),
        ("choice", "Welk begrip hoort bij het ontstaan van decubitus?", ["Schuifkracht", "Hyperventilatie", "Bradycardie", "Obstipatie"], "A", 2.7),
        ("choice", "Welke wet regelt de registratie van verpleegkundigen?", ["Wet BIG", "Jeugdwet", "Participatiewet", "Omgevingswet"], "A", -0.2),
        ("text", "Wat is de normale lichaamstemperatuur in graden Celsius, afgerond op hele graden?", None, "37", -0.8),
        ("choice", "Wat is het doel van een verpleegplan?", ["Het rooster van de afdeling vastleggen", "Doelen en interventies voor de zorgvrager vastleggen", "Medicijnen bestellen", "Het ontslag regelen"], "B", -0.5),
        ("choice", "Welke saturatie is normaal bij een gezonde volwassene?", ["70 tot 80 procent", "80 tot 85 procent", "95 tot 100 procent", "100 tot 110 procent"], "C", 0.1),
        ("choice", "Wat is een teken van een geïnfecteerde wond?", ["Roodheid en warmte rond de wond", "Een droge korst", "Minder pijn", "Een koele huid"], "A", -0.4),
        ("choice", "Waarom controleer je de identiteit van een zorgvrager voor je medicatie geeft?", ["Voor de administratie", "Om verwisseling te voorkomen", "Omdat de familie dat vraagt", "Om tijd te besparen"], "B", -0.9),
        ("text", "Hoeveel slagen per minuut is de ondergrens van een normale hartslag in rust bij een volwassene?", None, "60", 0.4),
        ("open", "Een zorgvrager is na een operatie onrustig en heeft een verhoogde hartslag. Beschrijf hoe je klinisch redeneert en welke stappen je zet.", None, None, 0.3),
        ("open", "Beschrijf hoe je een gesprek voert met de naaste van een zorgvrager die boos is over de zorg.", None, None, 0.2),
    ],
    "SW": [
        ("choice", "Wat is de kern van de Participatiewet?", ["Wie kan werken, vindt werk, met steun waar dat nodig is", "Alleen jongeren krijgen een uitkering", "Gemeenten geven geen bijstand meer", "Werkgevers bepalen de bijstand"], "A", -0.3),
        ("choice", "Welke wet regelt ondersteuning thuis door de gemeente?", ["Wmo 2015", "Wet BIG", "Omgevingswet", "Wet op het hoger onderwijs"], "A", -0.9),
        ("choice", "Wat bedoelen we met presentie in het sociaal werk?", ["Zo snel mogelijk oplossen", "Aanwezig zijn en aansluiten bij wat iemand nodig heeft", "Alleen op kantoor werken", "Het dossier compleet maken"], "B", 0.1),
        ("choice", "Wat is een ecogram?", ["Een schema van iemands sociale netwerk", "Een rapport over energieverbruik", "Een formulier voor een uitkering", "Een wetsartikel"], "A", -3.5),
        ("choice", "Wat is het doel van motiverende gespreksvoering?", ["De cliënt overtuigen met argumenten", "De eigen motivatie van de cliënt voor verandering versterken", "Advies geven zonder te luisteren", "Een diagnose stellen"], "B", 0.2),
        ("text", "Hoe heet het gesprek waarin de gemeente samen met de inwoner de hulpvraag verkent?", None, "keukentafelgesprek", 0.0),
        ("choice", "Welk signaal kan wijzen op huiselijk geweld bij een kind?", ["Het kind is vaak moe en schrikachtig", "Het kind heeft veel vrienden", "Het kind haalt hoge cijfers", "Het kind sport graag"], "A", None),
        ("choice", "Welke stap hoort als eerste bij de meldcode huiselijk geweld?", ["Direct de politie bellen", "Signalen in kaart brengen", "De cliënt uitschrijven", "Wachten tot er bewijs is"], "B", 0.4),
        ("choice", "Hoe heet de methode waarin een netwerk zelf een plan maakt voor een gezin?", ["Eigen Kracht-conferentie", "Intakegesprek", "Casemanagement", "Groepstraining"], "A", 2.7),
        ("choice", "Wat is een voorbeeld van een collectieve voorziening?", ["Een buurthuis", "Een persoonlijke rolstoel", "Een individuele uitkering", "Een privécoach"], "A", -0.5),
        ("text", "Hoe heet het plan waarin de gemeente vastlegt welke ondersteuning een inwoner krijgt? Eén woord.", None, "ondersteuningsplan", 0.5),
        ("choice", "Wat bedoelen we met eigen kracht?", ["Wat iemand zelf en met zijn netwerk kan", "De kracht van de gemeente", "De mening van de hulpverlener", "Een fysieke test"], "A", -0.7),
        ("choice", "Welke houding past bij een professionele relatie?", ["Vriendschap sluiten met de cliënt", "Betrokken zijn en grenzen bewaken", "Eigen problemen delen", "Cadeaus aannemen"], "B", -0.4),
        ("choice", "Wat is schuldhulpverlening?", ["Hulp bij het oplossen van problematische schulden", "Een lening van de gemeente", "Een boete innen", "Een belastingaangifte"], "A", -0.8),
        ("choice", "Wat regelt de Algemene verordening gegevensbescherming?", ["De bescherming van persoonsgegevens", "De hoogte van de bijstand", "De toegang tot jeugdhulp", "De arbeidsvoorwaarden van gemeenten"], "A", -0.2),
        ("choice", "Wat is empowerment?", ["Mensen versterken in de regie over hun leven", "Mensen straffen", "Beslissen namens de cliënt", "Alleen financiële hulp"], "A", -0.6),
        ("open", "Een jongere vertelt dat hij thuis niet meer welkom is. Beschrijf hoe je dit eerste gesprek voert en welke stappen je daarna zet.", None, None, 0.3),
        ("open", "Beschrijf hoe je met een gezin met schulden een plan maakt en welke partners je daarbij betrekt.", None, None, 0.2),
    ],
    "ICT": [
        ("choice", "Wat is de waarde van x na: x = 3; x = x * 2 + 1?", ["6", "7", "8", "9"], "B", -0.4),
        ("text", "Welk datatype gebruik je voor waar of onwaar?", None, "boolean", -0.6),
        ("choice", "Wat doet een for-lus?", ["Een blok code herhalen", "Een variabele verwijderen", "Een bestand openen", "Een fout negeren"], "A", -1.0),
        ("choice", "Welke index heeft het eerste element van een array in de meeste talen?", ["0", "1", "-1", "Dat verschilt per element"], "A", -3.5),
        ("choice", "Wat is een functie?", ["Een benoemd stuk code dat je kunt aanroepen", "Een foutmelding", "Een soort database", "Een netwerkprotocol"], "A", -0.7),
        ("text", "Wat is de uitkomst van 17 % 5?", None, "2", 0.3),
        ("choice", "Wat gebeurt er bij een oneindige lus?", ["Het programma stopt direct", "Het programma blijft draaien zonder te eindigen", "De computer wordt sneller", "De code wordt gecompileerd"], "B", None),
        ("choice", "Wat is in veel talen het verschil tussen = en ==?", ["Geen verschil", "= kent een waarde toe, == vergelijkt", "== kent toe, = vergelijkt", "Beide vergelijken"], "B", 0.2),
        ("choice", "Welke tijdscomplexiteit heeft binair zoeken in een gesorteerde lijst van n elementen?", ["O(1)", "O(log n)", "O(n)", "O(n²)"], "B", 2.7),
        ("choice", "Wat is een object in objectgeoriënteerd programmeren?", ["Een instantie van een klasse", "Een commentaarregel", "Een lege variabele", "Een bestandstype"], "A", 0.4),
        ("choice", "Waarvoor gebruik je versiebeheer?", ["Wijzigingen in code bijhouden en samenwerken", "Een website hosten", "Een database back-uppen", "Code sneller maken"], "A", -0.5),
        ("choice", "Wat is een string?", ["Een reeks tekens", "Een geheel getal", "Een lijst van getallen", "Een lus"], "A", -0.9),
        ("choice", "Wat is recursie?", ["Een functie die zichzelf aanroept", "Een fout in de syntax", "Een soort database", "Een variabele zonder waarde"], "A", 0.3),
        ("choice", "Welke waarde levert len([4, 8, 15]) op in Python?", ["2", "3", "4", "15"], "B", -0.3),
        ("choice", "Wat doet een compiler?", ["Broncode vertalen naar machinecode", "Code opmaken", "Tests uitvoeren", "Netwerkverkeer versturen"], "A", 0.1),
        ("text", "Welke HTTP-statuscode betekent dat een pagina niet gevonden is?", None, "404", -0.2),
        ("open", "Schrijf in pseudocode een functie die het grootste getal uit een lijst teruggeeft en leg uit hoe je hem test.", None, None, 0.4),
        ("open", "Leg uit hoe je een programma opdeelt in functies en waarom dat het onderhoud makkelijker maakt.", None, None, 0.2),
    ],
    "WTB": [
        ("choice", "Wat is de eenheid van kracht?", ["Newton", "Joule", "Watt", "Pascal"], "A", -1.0),
        ("choice", "Wat is een moment?", ["Kracht maal arm", "Massa maal versnelling", "Druk maal oppervlak", "Arbeid gedeeld door tijd"], "A", -0.3),
        ("text", "Een kracht van 200 N werkt op een arm van 0,5 m. Hoe groot is het moment in Nm?", None, "100", 0.1),
        ("choice", "Wanneer is een lichaam in evenwicht?", ["Als de som van krachten en momenten nul is", "Als het eenparig versnelt", "Als alle krachten even groot zijn", "Als er geen zwaartekracht werkt"], "A", -3.5),
        ("choice", "Wat is spanning in de sterkteleer?", ["Kracht per oppervlakte", "Lengte per tijd", "Massa per volume", "Energie per seconde"], "A", -0.2),
        ("choice", "Welk materiaal heeft de hoogste elasticiteitsmodulus?", ["Staal", "Aluminium", "Hout", "Rubber"], "A", 0.2),
        ("choice", "Wat is een vrijlichaamsschema?", ["Een schets van een lichaam met alle krachten erop", "Een productietekening", "Een planning", "Een stuklijst"], "A", None),
        ("choice", "Wat gebeurt er met staal boven de vloeigrens?", ["Het vervormt blijvend", "Het vervormt alleen elastisch", "Het wordt harder zonder te vervormen", "Er gebeurt niets"], "A", 0.5),
        ("choice", "Hoe groot is de kritieke knikkracht van een staaf die aan beide uiteinden is ingeklemd, vergeleken met scharnierend?", ["Twee keer zo groot", "Vier keer zo groot", "Even groot", "Half zo groot"], "B", 2.7),
        ("choice", "Hoe bereken je de trekspanning in een staaf?", ["Kracht gedeeld door doorsnede", "Doorsnede gedeeld door kracht", "Kracht maal lengte", "Lengte gedeeld door kracht"], "A", -0.4),
        ("choice", "Wat neemt een scharnieroplegging op?", ["Krachten, maar geen moment", "Alleen een moment", "Niets", "Alleen een horizontale kracht"], "A", 0.3),
        ("choice", "Wat is de zwaartekracht op een massa van 10 kg bij g = 9,81 m/s²?", ["9,81 N", "98,1 N", "981 N", "10 N"], "B", -0.6),
        ("choice", "Waar is het traagheidsmoment van een doorsnede een maat voor?", ["De weerstand tegen buigen", "De massa van een onderdeel", "De snelheid van een as", "De temperatuur van een materiaal"], "A", 0.4),
        ("text", "Hoeveel newton is 1 kN?", None, "1000", -0.9),
        ("choice", "Wat is knik?", ["Uitbuigen van een slanke staaf onder druk", "Breuk door trek", "Slijtage door wrijving", "Corrosie"], "A", 0.0),
        ("choice", "Welke factor vangt onzekerheid in een sterkteberekening op?", ["De veiligheidsfactor", "De wrijvingscoëfficiënt", "Het rendement", "De overbrengingsverhouding"], "A", -0.5),
        ("open", "Een balk van 2 m ligt op twee steunpunten en draagt in het midden 1 kN. Beschrijf hoe je de oplegreacties en het grootste moment bepaalt.", None, None, 0.4),
        ("open", "Leg uit hoe je een materiaal kiest voor een hijsoog en welke berekeningen je maakt.", None, None, 0.3),
    ],
}
OPEN_ANSWERS = [
    "Ik begin met het verzamelen van gegevens en zet daarna de stappen op een rij. Ik controleer mijn keuze en leg uit waarom.",
    "Eerst breng ik de situatie in kaart. Dan kies ik een aanpak, voer die uit en kijk of het resultaat klopt.",
    "Ik zou het stap voor stap aanpakken, maar ik weet niet zeker welke stap eerst moet.",
    "Mijn aanpak: observeren, analyseren, een plan maken en evalueren. Bij elke stap noem ik wat ik controleer.",
]

WORKPLACES = {
    "VPK": [("Zorggroep Voorbeeldhof", "voorbeeldhof.example", "Hilde", "Beukenhage"),
            ("Thuiszorg Voorbeeldwijk", "thuiszorg-voorbeeldwijk.example", "Wim", "Distelkamp")],
    "SW": [("Wijkteam Voorbeeldstad-Oost", "wijkteam-voorbeeldstad.example", "Ayse", "Korenbloemveld"),
           ("Jeugdhulp Voorbeeldhuis", "jeugdhulp-voorbeeldhuis.example", "Gerard", "Bremstede")],
    "ICT": [("Voorbeeldsoft B.V.", "voorbeeldsoft.example", "Sandra", "Zilverdijk"),
            ("Voorbeeld Datalab B.V.", "voorbeelddatalab.example", "Mehmet", "Kopermond")],
    "WTB": [("Voorbeeld Machinefabriek B.V.", "voorbeeldmachinefabriek.example", "Jolanda", "Haverbroek"),
            ("Voorbeeld Techniek B.V.", "voorbeeldtechniek.example", "Frank", "Roggelaar")],
}


def stamp(day: dt.date, hour: int, minute: int = 0) -> str:
    """A local Amsterdam timestamp with its offset, e.g. 2025-09-01T08:30:00+02:00."""
    return dt.datetime(day.year, day.month, day.day, hour, minute, tzinfo=AMS).isoformat()


def closed_days() -> set[dt.date]:
    off = set()
    for _name, start, end in HOLIDAYS:
        d = start
        while d <= end:
            off.add(d)
            d += dt.timedelta(days=1)
    return off


def php_round(value: float, digits: int) -> float:
    """PHP's round(): half away from zero on the shortest decimal form, not Python's half-to-even."""
    return float(Decimal(repr(value)).quantize(Decimal(1).scaleb(-digits), rounding=ROUND_HALF_UP))


def clamp_grade(value: float) -> float:
    return php_round(max(1.0, min(10.0, value)), 1)


def logistic(x: float) -> float:
    return 1.0 / (1.0 + math.exp(-x))


def pearson(xs: list[float], ys: list[float]) -> float:
    """ItemAnalysisService::pearsonCorrelation(): 0.0 when either vector has no variance."""
    n = len(xs)
    if n < 2:
        return 0.0
    mx, my = sum(xs) / n, sum(ys) / n
    num, sxx, syy = 0.0, 0.0, 0.0
    for x, y in zip(xs, ys):
        num += (x - mx) * (y - my)
        sxx += (x - mx) * (x - mx)
        syy += (y - my) * (y - my)
    if sxx <= 0.0 or syy <= 0.0:
        return 0.0
    return num / math.sqrt(sxx * syy)


def variance(xs: list[float]) -> float:
    """ItemAnalysisService::sampleVariance()."""
    n = len(xs)
    m = sum(xs) / n
    return sum((x - m) ** 2 for x in xs) / (n - 1)


def flag_reasons(p_value: float, correlation: float) -> list[str]:
    """ItemAnalysisRecomputeHandler::determineFlagReasons() with the default thresholds."""
    reasons = []
    if p_value < 0.2:
        reasons.append("too-difficult")
    elif p_value > 0.95:
        reasons.append("too-easy")
    if correlation < 0:
        reasons.append("negative-discrimination")
    elif correlation < 0.1:
        reasons.append("low-discrimination")
    return reasons


def exam_grade(score: float, max_score: float, cesuur: float) -> float:
    """Dutch cut-score conversion: the cut score maps to 5.5, zero to 1, full marks to 10."""
    if score >= cesuur:
        return clamp_grade(5.5 + 4.5 * (score - cesuur) / (max_score - cesuur))
    return clamp_grade(1.0 + 4.5 * score / cesuur)


def aggregate(entries: list[dict], components: dict[str, dict]) -> tuple[float | None, dict]:
    """What lib/Grading/GradeAggregationEngine computes for the best-of-n formula.

    Best published entry per component, then the weighted average; an
    exemption entry satisfies its component without counting.
    """
    tally = WeightedTally()
    for entry in best_per_component(entries).values():
        tally.add(entry, components)
    if tally.total == 0.0:
        return None, tally.parts
    breakdown = {
        "periods": {p: (php_round(s / w, 4) if w > 0 else None) for p, (s, w) in tally.periods.items()},
        "components": tally.parts,
    }
    return php_round(tally.weighted / tally.total, 4), breakdown


def best_per_component(entries: list[dict]) -> dict[str, dict]:
    best: dict[str, dict] = {}
    for entry in entries:
        cid = entry["componentId"]
        if cid not in best or entry_value(entry) > entry_value(best[cid]):
            best[cid] = entry
    return best


def entry_value(entry: dict) -> float:
    return float(entry.get("value") or 0)


class WeightedTally:
    """The running sums of one aggregation: weighted overall, per period and per component."""

    def __init__(self) -> None:
        self.weighted, self.total = 0.0, 0.0
        self.periods: dict[str, list[float]] = {}
        self.parts: dict[str, dict] = {}

    def add(self, entry: dict, components: dict[str, dict]) -> None:
        cid = entry["componentId"]
        if entry.get("sourceKind") == "exemption":
            self.parts[cid] = {"exempt": True}
            return
        value = float(entry["value"])
        weight = float(components[cid]["weight"])
        self.weighted += value * weight
        self.total += weight
        sums = self.periods.setdefault(str(entry.get("period") or "unknown"), [0.0, 0.0])
        sums[0] += value * weight
        sums[1] += weight
        self.parts[cid] = {"value": value, "weight": weight, "contribution": value * weight}


PROGRAMME_OF = {p[0]: p for p in PROGRAMMES}
COURSE_META = {(key, c[0]): c for key in COURSES for c in COURSES[key]}
PERIOD_META = {bl[0]: (bl[1], bl[2], bl[3]) for bl in BLOCKS} | {s[0]: (s[1], s[2], s[3]) for s in SEMESTERS}
RESULTS_DAY = {bl[0]: bl[5] for bl in BLOCKS} | {s[0]: s[4] for s in SEMESTERS}
RESIT_DAY = {bl[0]: bl[6] for bl in BLOCKS} | {s[0]: s[5] for s in SEMESTERS}
CHECK_UNTIL = BSA_CHECK - dt.timedelta(days=1)
B1_WEIGHTS = {c[0]: {"weight": c[3]} for c in Y1_COMPONENTS}

FACULTIES = [
    ("zuid", "00X400", "Faculteit Gezondheid en Welzijn, Campus Zuid", "Campuslaan 10", "0611 CZ"),
    ("noord", "00X401", "Faculteit Techniek en ICT, Campus Noord", "Ingenieursweg 3", "0614 CN"),
]
ROOMS = [
    ("skillslab", "Skillslab verpleegkunde Z1.02", "Z1.02", 24, "lab", ["oefenbedden", "simulatiepop"], "zuid", "1"),
    ("z112", "Werkgroepruimte Z1.12", "Z1.12", 32, "classroom", ["beamer", "whiteboard"], "zuid", "1"),
    ("z114", "Werkgroepruimte Z1.14", "Z1.14", 32, "classroom", ["beamer", "whiteboard"], "zuid", "1"),
    ("collegezuid", "Collegezaal Z0.01", "Z0.01", 180, "auditorium", ["beamer", "microfoon"], "zuid", "0"),
    ("tentamenzuid", "Tentamenzaal Z0.20", "Z0.20", 120, "other", ["toetslaptops", "surveillance"], "zuid", "0"),
    ("ictlab", "ICT-lab N2.05", "N2.05", 30, "lab", ["werkstations", "testnetwerk"], "noord", "2"),
    ("werkplaats", "Werkplaats N0.10", "N0.10", 20, "lab", ["draaibanken", "3D-printers"], "noord", "0"),
    ("n108", "Werkgroepruimte N1.08", "N1.08", 32, "classroom", ["beamer", "whiteboard"], "noord", "1"),
    ("collegenoord", "Collegezaal N0.02", "N0.02", 160, "auditorium", ["beamer", "microfoon"], "noord", "0"),
    ("tentamennoord", "Tentamenzaal N0.30", "N0.30", 100, "other", ["toetslaptops", "surveillance"], "noord", "0"),
    ("stilte", "Stilteruimte N1.01", "N1.01", 4, "other", ["toetslaptop", "geluiddempend"], "noord", "1"),
]
DOCENT_TASKS = ["Studieloopbaanbegeleider propedeuse", "Studieloopbaanbegeleider jaar 2", "Stagecoördinator", "Afstudeercoördinator"]
CHAIRS = {"zuid": "he-examencommissie-01", "noord": "he-examencommissie-02"}
OTHER_STAFF = [
    (CHAIRS["zuid"], ["coordinator"], ["Voorzitter examencommissie Gezondheid en Welzijn", "Seniorkwalificatie examinering (SKE)"], ["tuesday", "thursday"]),
    (CHAIRS["noord"], ["coordinator"], ["Voorzitter examencommissie Techniek en ICT", "Seniorkwalificatie examinering (SKE)"], ["monday", "wednesday"]),
    ("he-directeur-01", ["administrator"], ["Faculteitsdirecteur Gezondheid en Welzijn"], WEEKDAYS),
    ("he-directeur-02", ["administrator"], ["Faculteitsdirecteur Techniek en ICT"], WEEKDAYS),
    ("he-decaan-01", ["coordinator"], ["Studentendecaan, studeren met een functiebeperking"], ["monday", "wednesday", "thursday"]),
    ("he-administratie-01", ["administrator"], ["Studentenadministratie"], WEEKDAYS),
    ("he-toetsbureau-01", ["support-staff"], ["Toetscoördinator digitale toetsing en itembanken"], ["tuesday", "wednesday", "thursday"]),
    ("he-surveillant-01", ["support-staff"], ["Surveillant digitale toetsing"], ["monday", "tuesday", "wednesday", "thursday"]),
]
PERIOD_LABEL = {1: "Studiejaar 1 (propedeuse)", 2: "Studiejaar 2", 3: "Studiejaar 3", 4: "Studiejaar 4"}
COHORT_NOTES = {
    1: "Propedeuse met bindend studieadvies. Werkgroepen hebben aanwezigheidsplicht.",
    3: "Eerste semester stage, tweede semester minor.",
}
SICK_NOTES = ["Ziek gemeld", "Ziek gemeld via het studentportaal", "Huisartsbezoek"]
# Per sitting of the knowledge test: title, the PROGRAMMES column with its day, start and end.
EXAM_SITTINGS = {
    "main": ("Kennistoets {}", 7, (9, 30), (11, 30)),
    "resit": ("Herkansing kennistoets {}", 8, (13, 30), (15, 30)),
    "adapted": ("Kennistoets {}, aangepaste afname", 7, (9, 30), (12, 0)),
}
INTERACTION = {"choice": "choice", "text": "textEntry", "open": "extendedText"}
FLAG_STATES = ["revised", "acknowledged", "dismissed", "open"]
RUBRIC_LEVELS = [("onvoldoende", "Onvoldoende", 2.0), ("voldoende", "Voldoende", 6.0), ("goed", "Goed", 8.0), ("uitstekend", "Uitstekend", 10.0)]
RUBRIC_CRITERIA = [("probleemanalyse", "Probleemanalyse"), ("onderbouwing", "Onderbouwing van keuzes"), ("resultaat", "Resultaat voor de opdrachtgever"), ("samenwerking", "Samenwerking en planning")]
PEER_COMMENTS = [
    "Duidelijke opbouw. De keuzes in hoofdstuk drie mogen beter onderbouwd.",
    "Goed dat jullie de opdrachtgever hebben gesproken. De planning ontbreekt nog.",
    "Het resultaat is bruikbaar. Voeg bronnen toe bij de analyse.",
    "Prettig leesbaar verslag. De conclusie herhaalt vooral de inleiding.",
]
# Per portfolio reflection: title, section, criterion, the learning outcome it shows and the texts drawn from.
REFLECTIONS = [
    ("Reflectie: beroepshandelen in de praktijk", "praktijk", "praktijk-1", 2, [
        "Bij {org} nam ik vanaf week zes zelfstandig taken over. Ik leerde vooraf te plannen en achteraf te controleren wat ik had afgesproken.",
        "In mijn stage bij {org} merkte ik dat overleg met collega's mijn werk beter maakte. Ik vraag nu eerder feedback.",
        "Bij {org} kreeg ik een eigen opdracht. Het lastigste was kiezen wat eerst moest; een weekplanning hielp.",
    ]),
    ("Reflectie: professionele ontwikkeling", "ontwikkeling", "ontwikkeling-1", 8, [
        "Mijn leerdoel was duidelijker communiceren. In de laatste evaluatie gaf mijn begeleider aan dat dat gelukt is. Mijn volgende doel is leiding nemen in overleg.",
        "Ik heb geleerd mijn eigen grenzen aan te geven. Voor de minor wil ik verder werken aan het onderbouwen van keuzes met literatuur.",
        "Ik ben zelfstandiger geworden. Ik wil nog beter leren omgaan met onverwachte situaties.",
    ]),
]
# Exam board exemption cases: whose case, course, component, grounds, description, decision day, granted, rationale.
EXEMPTIONS = [
    ("ICT", "B1", "kennistoets", "prior-diploma", "Mbo-diploma Software developer (niveau 4) met de onderdelen programmeren en testen.",
     dt.date(2025, 10, 2), True, "Het mbo-diploma dekt de leerdoelen van de kennistoets programmeren aantoonbaar. Vrijstelling voor dit onderdeel; het beroepsproduct blijft verplicht."),
    ("WTB", "B2", "kennistoets", "certificate", "Certificaat technisch tekenen en CAD van een eerdere hbo-opleiding, behaald in 2024.",
     dt.date(2025, 11, 27), True, "Het certificaat dekt de normen en aanzichten van de kennistoets. Vrijstelling voor dit onderdeel."),
    ("SW", "B3", "beroepsproduct", "work-experience", "Twee jaar werkervaring als ambulant begeleider bij een zorgaanbieder.",
     dt.date(2026, 3, 5), False, "Werkervaring alleen toont de juridische kennis van dit beroepsproduct niet aan. De student kan het beroepsproduct maken."),
]
# Grade entry reference field per source the raw grade names.
SOURCE_FIELDS = [("submissionId", "submission"), ("assessmentResultId", "result"), ("exemptionCaseId", "case"),
                 ("fraudCaseId", "fraud"), ("portfolioId", "portfolio")]
# The field on each source that points back at its grade entry.
ENTRY_LINKS = [("result", "gradeEntryId"), ("portfolio", "gradeEntryId"), ("case", "resultingGradeEntryId"), ("fraud", "contestedGradeEntryId")]
PROFILE = {
    "openapi": "3.0.0",
    "info": {
        "title": "Learniq example set: Higher education",
        "version": "1.0.0",
        "description": "Hogeschool Zuiddrecht, a fictional university of applied sciences in the fictional town of Zuiddrecht, through the 2025-2026 academic year.",
    },
    "x-openregister": {
        "type": "profile",
        "app": "learniq",
        "profile": {
            "id": SET,
            "segment": SET,
            "label": "Higher education (HBO or university)",
            "description": "A fictional university of applied sciences with programmes, students, study credits and one full academic year.",
            "order": 4,
            "objectCount": 0,
            "icon": "CertificateOutline",
        },
        "description": (
            "An example set an operator picks in the first-time setup wizard (ADR-042, decision D21). NEVER imported on install. "
            "Every object carries @self.configuration/register/schema and a fixed uuid in the ee04 namespace, so the import resolves "
            "the live learniq register without this descriptor declaring components.registers (which would re-point the register at "
            "this profile config id and overwrite its authorization block), a second load adds nothing, and occ "
            "learniq:example-set:remove he removes exactly these objects. Generated by scripts/example-sets/he.py; the contract is "
            "openspec/changes/archive/2026-09-28-segment-wizard-choice/contract.md. Every person, address, institution, company and code in it is fictional."
        ),
        "seedData": {
            "description": (
                "One university of applied sciences with two faculties, four bachelor programmes with learning outcomes on the Dublin "
                "descriptors, courses with ECTS credits, a cohort per programme per study year, 400 students with their enrolments, "
                "item bank exams with item statistics, grade entries and final grades, binding study advice with flags and warnings, "
                "study advisers, a peer reviewed group project, internship portfolios, a proctoring session and learning record exports."
            ),
            "objects": {},
        },
    },
    "paths": {},
    "components": {},
}


# The steps of one run, in the order they draw random numbers and add objects.
STEPS = (
    "add_institution", "add_rooms", "add_learning_outcomes", "add_programmes", "add_courses", "add_curriculum_plans",
    "add_staff", "add_students", "group_students", "add_cohorts", "add_course_grades", "add_enrolments",
    "add_teacher_assignments", "add_werkgroep_sessions", "add_exam_sessions", "draw_attendance_marks", "add_excuses",
    "add_attendance_records", "add_attendance_flags", "pick_accommodated", "add_item_banks", "add_exams",
    "add_accommodations", "sit_main_exams", "add_proctoring", "add_item_statistics", "sit_knowledge_resits",
    "add_resit_reliability", "add_revision_flags", "add_group_projects", "add_peer_reviews", "add_project_grades",
    "add_assessors", "add_portfolio_templates", "add_portfolios", "add_exemption_cases", "add_fraud_case",
    "add_resits", "settle_first_years", "add_grade_entries", "settle_enrolments", "add_bsa_trajectories",
    "count_final_ects", "add_bsa_warnings", "pick_bsa_exceptions", "add_bsa_decisions", "add_record_exports",
)


@dataclass(frozen=True)
class ExamSample:
    """The main sittings of one exam: the exam, its result rows, each row's total and the high and low groups."""

    exam: dict
    rows: list
    totals: list[float]
    groups: tuple[list[int], list[int]]


def build() -> dict:
    return AcademicYear().build()


class AcademicYear:
    """One run of the set. The steps run in a fixed order and share one random
    generator, so every draw, and therefore the file, is the same on each run."""

    def __init__(self) -> None:
        self.rng = random.Random(20250901)
        self.b = Builder(IDENTITY)
        self.off = closed_days()
        self.raw: dict[tuple[str, str, str], list[dict]] = {}
        self.results_by_exam: dict[tuple[str, str], list] = {}
        self.flag_n = 0

    def build(self) -> dict:
        for step in STEPS:
            getattr(self, step)()
        return document(PROFILE, self.b)

    # --- institution, faculties, rooms --------------------------------------
    def add_institution(self) -> None:
        self.school = self.b.add("school", {"brin": "00X4", "name": "Hogeschool Zuiddrecht", "pedagogicalConcept": "regular"})
        self.faculties = {}
        for key, code, name, street, postcode in FACULTIES:
            self.faculties[key] = self.b.add("vestiging", {
                "schoolId": self.school["uuid"], "vestigingscode": code, "onderwijslocatiecode": f"{code}-A",
                "name": name, "street": street, "postalCode": postcode, "city": "Zuiddrecht",
            })

    def add_rooms(self) -> None:
        self.rooms = {}
        for key, name, code, capacity, kind, facilities, fac, floor in ROOMS:
            self.rooms[key] = self.b.add("room", {"name": name, "code": code, "capacity": capacity, "kind": kind, "facilities": facilities,
                                                  "buildingCode": self.faculties[fac]["vestigingscode"], "floor": floor})
        self.scale = self.b.add("grade-scale", {"name": "Nederlandse cijferschaal 1 tot 10", "kind": "numeric", "min": 1.0, "max": 10.0,
                                                "passThreshold": PASS, "roundingRule": "half-up-1dp", "lifecycle": "active"})

    # --- learning outcomes (Dublin descriptors) ------------------------------
    def add_learning_outcomes(self) -> None:
        self.frameworks: dict[str, dict] = {}
        self.outcomes: dict[str, list[dict]] = {}
        for key, short, name, *_rest in PROGRAMMES:
            self.frameworks[key] = self.b.add("competency-framework", {
                "name": f"Leeruitkomsten {short}", "sourceAuthority": "school-defined", "sourceRef": "Dublin-descriptoren, bachelor",
                "edition": "2025", "level": "hbo",
                "description": f"De tien leeruitkomsten van {name}, geordend onder de vijf Dublin-descriptoren.",
                "proficiencyLevels": LEVELS, "lifecycle": "published",
            })
        for key, *_rest in PROGRAMMES:
            self.add_outcomes_of(key)

    def add_outcomes_of(self, key: str) -> None:
        framework = self.frameworks[key]["uuid"]
        descriptors = []
        for order, (code, title) in enumerate(DUBLIN, start=1):
            descriptors.append(self.b.add("competency", {
                "frameworkId": framework, "parentId": None, "code": f"{key}-{code}", "title": title,
                "description": f"Dublin-descriptor {order} voor de bachelor {key}.", "order": order, "lifecycle": "published",
            }))
        self.outcomes[key] = []
        for n, text in enumerate(OUTCOMES[key], start=1):
            self.outcomes[key].append(self.b.add("competency", {
                "frameworkId": framework, "parentId": descriptors[(n - 1) // 2]["uuid"], "code": f"{key}-LU{n:02d}",
                "title": f"Leeruitkomst {n}", "description": text, "order": n, "requiredForRoles": ["learner"], "lifecycle": "published",
            }))

    # --- programmes, courses, curriculum plans -------------------------------
    def add_programmes(self) -> None:
        self.programmes = {}
        for key, _short, name, code, *_rest in PROGRAMMES:
            self.programmes[key] = self.b.add("programme", {
                "name": name, "code": code, "level": "hbo", "description": PROGRAMME_DESCRIPTION[key],
                "curriculumPlanId": None, "courseIds": [], "credentialTemplateId": None,
                "requiredCompetencyIds": [o["uuid"] for o in self.outcomes[key]], "lifecycle": "published",
            })

    def add_courses(self) -> None:
        self.courses: dict[tuple[str, str], dict] = {}
        for key, *_rest in PROGRAMMES:
            for course in COURSES[key]:
                self.add_course(key, course)
            self.programmes[key]["courseIds"] = [self.courses[(key, c[0])]["uuid"] for c in COURSES[key]]

    def add_course(self, key: str, course: tuple) -> None:
        ckey, year, _period, ects, cname, desc, _comps, lus, _docent = course
        self.courses[(key, ckey)] = self.b.add("course", {
            "code": f"{key}-{ckey}-2526", "name": cname, "name_nl": cname, "description": desc, "level": "hbo", "language": "nl",
            "tags": [PROGRAMME_OF[key][1], f"jaar {year}"], "lifecycle": "published", "curriculumPlanId": None,
            "programmeIds": [self.programmes[key]["uuid"]], "ectsCredits": ects,
            "competencyIds": [self.outcomes[key][n - 1]["uuid"] for n in lus],
        })

    def add_curriculum_plans(self) -> None:
        self.plans: dict[tuple[str, str], dict] = {}
        for key, *_rest in PROGRAMMES:
            oer = self.b.add("curriculum-plan", self.examination_rules(key))
            self.programmes[key]["curriculumPlanId"] = oer["uuid"]
            for course in COURSES[key]:
                self.add_course_plan(key, course)

    def examination_rules(self, key: str) -> dict:
        return {
            "name": f"Onderwijs- en examenregeling {PROGRAMME_OF[key][1]} {YEAR}", "kind": "oer", "formula": "all-must-pass",
            "requiredCourseIds": self.programmes[key]["courseIds"], "electiveCourseIds": [], "components": [],
            "gradeScaleId": self.scale["uuid"], "passRules": [{"componentId": None, "minValue": PASS}],
            "periods": [{"periodId": bl[0], "label": bl[1], "startDate": bl[2].isoformat(), "endDate": bl[3].isoformat()} for bl in BLOCKS],
            "lifecycle": "published",
        }

    def add_course_plan(self, key: str, course: tuple) -> None:
        ckey, _year, period, _ects, cname, _desc, comps, *_rest = course
        label, start, end = PERIOD_META[period]
        self.plans[(key, ckey)] = self.b.add("curriculum-plan", {
            "name": f"Toetsplan {cname} {YEAR}", "kind": "oer", "formula": "best-of-n",
            "requiredCourseIds": [self.courses[(key, ckey)]["uuid"]], "electiveCourseIds": [],
            "components": [{"componentId": cid, "label": lab, "weight": w, "period": period, "kind": kind} for cid, lab, kind, w in comps],
            "gradeScaleId": self.scale["uuid"], "passRules": [{"componentId": None, "minValue": PASS}],
            "periods": [{"periodId": period, "label": label, "startDate": start.isoformat(), "endDate": end.isoformat()}],
            "lifecycle": "published",
        })
        self.courses[(key, ckey)]["curriculumPlanId"] = self.plans[(key, ckey)]["uuid"]

    # --- staff ----------------------------------------------------------------
    def add_staff(self) -> None:
        self.docents: dict[str, list[str]] = {}
        rows: list[tuple] = []
        for index, (key, *_rest) in enumerate(PROGRAMMES):
            self.docents[key] = [f"he-docent-{index * 6 + i + 1:02d}" for i in range(6)]
            rows.extend(docent_row(nc, i) for i, nc in enumerate(self.docents[key]))
        for nc, roles, qual, work in rows + adviser_rows() + OTHER_STAFF:
            self.b.add("staff", {"ncUserId": nc, "roles": roles, "qualifications": qual, "workingDays": work})

    def teacher_of(self, key: str, ckey: str) -> str:
        return self.docents[key][COURSE_META[(key, ckey)][8]]

    def slb_of(self, key: str, year: int) -> str:
        return self.docents[key][year - 1]

    # --- students ---------------------------------------------------------------
    def add_students(self) -> None:
        self.used_names: set[tuple[str, str]] = set()
        students: list[dict] = []
        for key, *_rest in PROGRAMMES:
            for year, count in STUDENTS_PER_YEAR.items():
                students.extend(self.new_student(key, year) for _ in range(count))
        self.number_students(students)

    def number_students(self, students: list[dict]) -> None:
        students.sort(key=lambda s: ([p[0] for p in PROGRAMMES].index(s["programme"]), s["year"], s["family"], s["given"]))
        for n, s in enumerate(students, start=1):
            s["nc"] = f"he-student-{n:03d}"
            s["given"], s["family"] = BOARD_NAMES.get(s["nc"], (s["given"], s["family"]))
            s["profile"] = self.add_learner_profile(s)
        self.students = students
        self.student_by_nc = {s["nc"]: s for s in students}

    def new_student(self, key: str, year: int) -> dict:
        rng = self.rng
        man = rng.random() < (0.35 if key in ("VPK", "SW") else 0.8)
        given, family = self.unused_name(man)
        older = rng.random() < 0.18
        birth_year = 2007 - year + 1 - (rng.randint(2, 5) if older else rng.randint(0, 1))
        birth = dt.date(birth_year, 1, 1) + dt.timedelta(days=rng.randrange(365))
        return {"programme": key, "year": year, "man": man, "given": given, "family": family, "birth": birth,
                "mbo": older, "ability": rng.gauss(0, 1) + PROGRAMME_OF[key][9], "absence": rng.random()}

    def unused_name(self, man: bool) -> tuple[str, str]:
        while True:
            given = self.rng.choice(MEN if man else WOMEN)
            family = self.rng.choice(SURNAME_HEAD) + self.rng.choice(SURNAME_TAIL)
            if (given, family) not in self.used_names:
                self.used_names.add((given, family))
                return given, family

    def add_learner_profile(self, s: dict) -> dict:
        rng = self.rng
        emergency = []
        if rng.random() < 0.3:
            emergency = [{"name": f"{rng.choice(PARENTS)} {s['family']}", "relationship": "ouder", "phone": f"06-0000{rng.randint(1000, 9999)}", "priority": 1}]
        return self.b.add("learner-profile", {
            "ncUserId": s["nc"], "givenName": s["given"], "familyName": s["family"], "birthDate": s["birth"].isoformat(),
            "schoolId": self.school["uuid"], "eduPersonAffiliation": ["student"], "roles": ["learner"],
            "parentIds": [], "guardianRefs": [],
            "address": {"street": rng.choice(STREETS), "houseNumber": str(rng.randint(1, 180)),
                        "postalCode": f"06{rng.randint(10, 39)} {rng.choice('ABDEGHKLMNPRSTWZ')}{rng.choice('ABDEGHKLMNPRSTWZ')}",
                        "city": rng.choice(TOWNS), "country": "NL"},
            "emergencyContacts": emergency, "lifecycle": "active",
        })

    def group_students(self) -> None:
        self.by_group: dict[tuple[str, int], list[dict]] = {}
        for s in self.students:
            self.by_group.setdefault((s["programme"], s["year"]), []).append(s)
        # One first-year student per programme stops in January, before the BSA
        # applies: the weakest one.
        for key, *_rest in PROGRAMMES:
            weakest = min(self.by_group[(key, 1)], key=lambda s: s["ability"])
            weakest["withdrawn"] = True

    # --- cohorts ------------------------------------------------------------------
    def add_cohorts(self) -> None:
        self.cohorts: dict[tuple[str, int], dict] = {}
        for key, *_rest in PROGRAMMES:
            for year in (1, 2, 3, 4):
                self.cohorts[(key, year)] = self.add_cohort(key, year)

    def add_cohort(self, key: str, year: int) -> dict:
        return self.b.add("cohort", {
            "name": f"{PROGRAMME_OF[key][1]} cohort {2026 - year}", "programmeId": self.programmes[key]["uuid"],
            "teacherIds": [self.slb_of(key, year)], "learnerIds": [s["nc"] for s in self.by_group[(key, year)]],
            "period": PERIOD_LABEL[year], "academicYear": YEAR, "lifecycle": "active",
            "locationId": self.faculties[PROGRAMME_OF[key][4]]["uuid"], "notes": COHORT_NOTES.get(year), "kind": "teaching",
        })

    # --- grades, simulated first so enrolments can carry their outcome ---------
    def add_raw(self, spec: dict) -> dict:
        """Files one raw grade: student, course key, component, value, day, source kind and any source object."""
        self.raw.setdefault((spec["student"]["nc"], spec["ckey"], spec["cid"]), []).append(spec)
        return spec

    def draw(self, s: dict, base: float) -> float:
        return clamp_grade(base + 0.9 * s["ability"] + self.rng.gauss(0, 0.75))

    def draw_portfolio(self, s: dict) -> float:
        return clamp_grade(7.2 + 0.9 * s["ability"] + self.rng.gauss(0, 0.6))

    # Components graded by the item bank exam (block 1 kennistoets) and the
    # group project are simulated further on; the rest here.
    def add_course_grades(self) -> None:
        for s in self.students:
            for course in COURSES[s["programme"]]:
                if graded_by_component(s, course):
                    self.add_component_grades(s, course)

    def add_component_grades(self, s: dict, course: tuple) -> None:
        ckey, year, period = course[0], course[1], course[2]
        for cid, _label, _kind, _w in course[6]:
            if (ckey, cid) in (("B1", "kennistoets"), ("B4", "projectverslag")):
                continue
            if ckey == "STAGE":
                value, kind = self.draw_portfolio(s), "portfolio"
            else:
                value, kind = self.draw(s, 6.7 if year == 1 else 7.0), "manual"
            self.add_raw({"student": s, "ckey": ckey, "cid": cid, "value": value, "when": RESULTS_DAY[period], "kind": kind})

    # --- enrolments ---------------------------------------------------------------
    # Placeholders now, outcome filled in once final grades are known.
    def add_enrolments(self) -> None:
        self.enrolments: dict[tuple[str, str], dict] = {}
        for s in self.students:
            cohort = self.cohorts[(s["programme"], s["year"])]
            for ckey, year, *_rest in COURSES[s["programme"]]:
                if year == s["year"]:
                    self.enrolments[(s["nc"], ckey)] = self.b.add("enrolment", {
                        "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"], "courseId": self.courses[(s["programme"], ckey)]["uuid"],
                        "source": "admission" if year == 1 else "bulk", "cohortId": cohort["uuid"], "lifecycle": "active",
                        "locationId": cohort["locationId"], "reason": None,
                    })

    def add_teacher_assignments(self) -> None:
        for key, *_rest in PROGRAMMES:
            for ckey, year, *_rest2 in COURSES[key]:
                self.b.add("subjectteacherassignment", {"cohortId": self.cohorts[(key, year)]["uuid"], "courseId": self.courses[(key, ckey)]["uuid"],
                                                        "teacherId": self.teacher_of(key, ckey)})

    # --- werkgroep sessions and attendance (first year) -----------------------
    def add_werkgroep_sessions(self) -> None:
        self.sessions: list[tuple[str, str, dt.date, dict]] = []
        for key, *_rest in PROGRAMMES:
            for bid, _label, _start, _end, weeks, *_r in BLOCKS:
                self.sessions.extend((key, bid, day, self.add_werkgroep((key, bid, day))) for day in self.werkgroep_days(key, weeks))

    def werkgroep_days(self, key: str, weeks: list[dt.date]) -> list[dt.date]:
        days = [monday + dt.timedelta(days=PROGRAMME_OF[key][5]) for monday in weeks]
        return [day for day in days if day not in self.off]

    def add_werkgroep(self, slot: tuple[str, str, dt.date]) -> dict:
        key, bid, day = slot
        course, room = self.courses[(key, bid)], self.rooms[PROGRAMME_OF[key][6]]
        return self.b.add("session", {
            "cohortId": self.cohorts[(key, 1)]["uuid"], "courseId": course["uuid"],
            "title": f"Werkgroep {course['name']}, {DAG[day.weekday()]} {day.day} {MAAND[day.month - 1]} {day.year}",
            "startsAt": stamp(day, 9, 0), "endsAt": stamp(day, 12, 0),
            "location": room["name"], "roomId": room["uuid"], "lifecycle": "completed",
        })

    def add_exam_sessions(self) -> None:
        self.exam_sessions: dict[tuple[str, str], dict] = {}
        for key, *_rest in PROGRAMMES:
            for variant in ("main", "resit"):
                self.exam_sessions[(key, variant)] = self.add_exam_session((key, variant))
        self.exam_sessions[("ICT", "adapted")] = self.add_exam_session(("ICT", "adapted"))

    def add_exam_session(self, sitting: tuple[str, str]) -> dict:
        key, variant = sitting
        title, day_column, start, end = EXAM_SITTINGS[variant]
        course, day = self.courses[(key, "B1")], cast(dt.date, PROGRAMME_OF[key][day_column])
        hall = self.rooms[exam_room(key, variant)]
        return self.b.add("session", {
            "cohortId": self.cohorts[(key, 1)]["uuid"], "courseId": course["uuid"], "title": title.format(course["name"]),
            "startsAt": stamp(day, *start), "endsAt": stamp(day, *end),
            "location": hall["name"], "roomId": hall["uuid"], "lifecycle": "completed",
        })

    def draw_attendance_marks(self) -> None:
        self.session_by_cohort: dict[str, list[tuple[str, dt.date, dict]]] = {}
        for key, bid, day, sess in self.sessions:
            self.session_by_cohort.setdefault(key, []).append((bid, day, sess))
        self.marks: dict[str, dict[str, tuple[str, str, int]]] = {}
        for s in by_group_all(self.by_group, 1):
            self.marks[s["nc"]] = self.marks_of(s)

    def marks_of(self, s: dict) -> dict[str, tuple[str, str, int]]:
        own = self.attended_sessions(s)
        count = self.rng.choices([0, 1, 2, 3, 4], weights=[42, 32, 16, 7, 3])[0]
        if s.get("withdrawn"):
            count = 5
        if s["absence"] > 0.93:
            count += 2
        chosen = self.rng.sample(own, min(count, len(own)))
        return {sess["uuid"]: self.mark() for _bid, _day, sess in chosen}

    def attended_sessions(self, s: dict) -> list[tuple[str, dt.date, dict]]:
        return [(bid, day, sess) for bid, day, sess in self.session_by_cohort[s["programme"]] if attends(s, day)]

    def mark(self) -> tuple[str, str, int]:
        roll = self.rng.random()
        if roll < 0.5:
            return ("absent-excused", self.rng.choice(SICK_NOTES), 0)
        if roll < 0.62:
            return ("absent-unexcused", "Afwezig zonder bericht", 0)
        late = self.rng.choice([10, 15, 20, 30])
        return ("late", f"{late} minuten te laat", 180 - late)

    def add_excuses(self) -> None:
        self.session_info = {sess["uuid"]: (key, bid, day, sess) for key, bid, day, sess in self.sessions}
        self.excuses: dict[tuple[str, str], dict] = {}
        excused = self.excused_marks()
        for nc, su in self.rng.sample(excused, min(24, len(excused))):
            self.excuses[(nc, su)] = self.add_excuse(nc, su)

    def excused_marks(self) -> list[tuple[str, str]]:
        return [(nc, su) for nc in sorted(self.marks) for su, m in sorted(self.marks[nc].items()) if m[0] == "absent-excused"]

    def add_excuse(self, nc: str, su: str) -> dict:
        s = self.student_by_nc[nc]
        key, _bid, day, _sess = self.session_info[su]
        return self.b.add("excuse-request", {
            "learnerId": nc, "learnerRef": s["profile"]["uuid"], "submittedBy": nc, "submittedByRef": s["profile"]["uuid"],
            "dateFrom": day.isoformat(), "dateTo": day.isoformat(), "reason": self.rng.choice(["Griep", "Koorts", "Migraine", "Buikgriep"]),
            "reasonKind": "illness", "submittedAuthLevel": "basic", "decidedBy": self.slb_of(key, 1),
            "decidedAt": stamp(day, 14, 0), "decisionNote": None, "lifecycle": "approved",
        })

    def add_attendance_records(self) -> None:
        self.records: dict[str, list[dict]] = {}
        for nc in sorted(self.marks, key=lambda x: int(x.rsplit("-", 1)[1])):
            for su in sorted(self.marks[nc], key=lambda u: self.session_info[u][2]):
                self.records.setdefault(nc, []).append(self.add_attendance_record(nc, su) | {"_block": self.session_info[su][1]})

    def add_attendance_record(self, nc: str, su: str) -> dict:
        key, bid, day, _sess = self.session_info[su]
        status, reason, minutes = self.marks[nc][su]
        return self.b.add("attendance-record", {
            "sessionId": su, "learnerId": nc, "learnerRef": self.student_by_nc[nc]["profile"]["uuid"], "cohortId": self.cohorts[(key, 1)]["uuid"],
            "status": status, "minutesAttended": minutes, "markedBy": self.teacher_of(key, bid),
            "markedAt": stamp(day, 9, 20 if status == "late" else 10), "reason": reason,
            "excuseRequestId": self.excuses[(nc, su)]["uuid"] if (nc, su) in self.excuses else None,
        })

    def add_attendance_flags(self) -> None:
        self.threshold = self.b.add("attendance-threshold", {
            "name": "Aanwezigheidsplicht werkgroepen: minimaal 80 procent per blok", "kind": "college-aanwezigheid", "scope": "per-learner",
            "cohortId": None, "window": {"type": "fixed-term", "weeks": None, "termId": "blok"}, "metric": "attendance-percent-below",
            "limit": 80, "onCross": {"notify": True, "notifyRoles": ["mentor", "coordinator"], "createFlag": True, "dataExchangeTarget": None},
            "active": True, "lifecycle": "active",
        })
        for s in by_group_all(self.by_group, 1):
            for block in BLOCKS:
                self.flag_block(s, block)

    def flag_block(self, s: dict, block: tuple) -> None:
        held = self.held_sessions(s, block[0])
        if not held:
            return
        absent = self.absences(s, block[0])
        percent = php_round(100 * (len(held) - len(absent)) / len(held), 1)
        if percent < 80:
            self.add_attendance_flag(s, {"block": block, "percent": percent, "absent": absent})

    def held_sessions(self, s: dict, bid: str) -> list[dict]:
        return [sess for b_, day, sess in self.session_by_cohort[s["programme"]] if b_ == bid and attends(s, day)]

    def absences(self, s: dict, bid: str) -> list[dict]:
        return [r for r in self.records.get(s["nc"], []) if r["_block"] == bid and r["status"].startswith("absent")]

    def add_attendance_flag(self, s: dict, breach: dict) -> None:
        key, (_bid, _label, start, end, *_r) = s["programme"], breach["block"]
        last = max(self.session_info[r["sessionId"]][2] for r in breach["absent"])
        self.b.add("attendance-flag", {
            "learnerId": s["nc"], "attendanceThresholdId": self.threshold["uuid"], "cohortId": self.cohorts[(key, 1)]["uuid"],
            "windowStart": start.isoformat(), "windowEnd": end.isoformat(), "metricValue": breach["percent"],
            "breachingRecordIds": [r["uuid"] for r in breach["absent"]], "mentorId": self.slb_of(key, 1),
            "flagKind": "attendance-requirement",
            "interventions": [{"recordedBy": self.slb_of(key, 1), "recordedAt": stamp(last + dt.timedelta(days=2), 15, 0),
                               "note": "Gesprek over de gemiste werkgroepen; de student maakt een vervangende opdracht.",
                               "lifecycleAtRecording": "open"}],
            "lifecycle": "in-handling" if s.get("withdrawn") else "resolved",
        })

    # --- item banks, the block 1 knowledge test, statistics --------------------
    def pick_accommodated(self) -> None:
        pool = self.extra_time_pool()
        self.exempt_ict = first_with_mbo(self.by_group[("ICT", 1)])
        self.adapted_ict = next(s for s in self.by_group[("ICT", 1)] if takes_adapted_exam(s, self.exempt_ict))
        self.extra_time = self.without_ict_exceptions(pool)[:9]

    def without_ict_exceptions(self, pool: list[dict]) -> list[dict]:
        return [s for s in pool if s is not self.exempt_ict and s is not self.adapted_ict]

    def extra_time_pool(self) -> list[dict]:
        pool = [s for s in by_group_all(self.by_group, 1) if not s.get("withdrawn")]
        self.rng.shuffle(pool)
        return pool

    def add_item_banks(self) -> None:
        self.bank_items: dict[str, list[dict]] = {}
        for key, short, *_rest in PROGRAMMES:
            course = self.courses[(key, "B1")]
            bank = self.b.add("item-bank", {"name": f"Itembank {course['name']}", "description": f"Kennisvragen voor de kennistoets van {course['name']} in {short}.",
                                            "subject": course["name"], "itemIds": [], "lifecycle": "published"})
            items = [self.add_item(bank, (key, k)) for k in range(1, len(ITEMS[key]) + 1)]
            bank["itemIds"] = [i["obj"]["uuid"] for i in items]
            self.bank_items[key] = items

    def add_item(self, bank: dict, ref: tuple[str, int]) -> dict:
        key, k = ref
        itype, _stem, options, answer, difficulty = ITEMS[key][k - 1]
        item = self.b.add("item", self.item_fields(bank, ref))
        item["qtiBody"] = qti(item, ITEMS[key][k - 1])
        return {"obj": item, "type": itype, "options": options, "answer": answer,
                "b": difficulty, "max": 4.0 if itype == "open" else 1.0}

    def item_fields(self, bank: dict, ref: tuple[str, int]) -> dict:
        key, k = ref
        itype, _stem, _options, answer, difficulty = ITEMS[key][k - 1]
        open_q, short = itype == "open", PROGRAMME_OF[key][1]
        return {
            "itemBankId": bank["uuid"], "title": f"Kennistoets {short}, vraag {k}",
            "interactionType": INTERACTION[itype], "qtiBody": "",
            "correctResponse": None if open_q else {"value": answer}, "maxScore": 4.0 if open_q else 1.0,
            "subjectTags": [short, self.courses[(key, "B1")]["name"]],
            "competencyIds": [self.outcomes[key][4]["uuid"]] if open_q else [self.outcomes[key][0]["uuid"]],
            "difficulty": None if difficulty is None else php_round(logistic(difficulty), 2),
            "variantGroupId": None, "lifecycle": "published",
        }

    def add_exams(self) -> None:
        self.exams: dict[tuple[str, str], dict] = {}
        for key, *_rest in PROGRAMMES:
            items = self.bank_items[key]
            sets = {"main": items[0:11] + [items[16]], "resit": items[5:16] + [items[17]]}
            sets["adapted"] = sets["main"]
            for variant in ("main", "resit") + (("adapted",) if key == "ICT" else ()):
                self.exams[(key, variant)] = {"items": sets[variant], "obj": self.b.add("exam", self.exam_fields((key, variant), sets[variant]))}

    def exam_fields(self, sitting: tuple[str, str], item_set: list[dict]) -> dict:
        key, variant = sitting
        course, sess, adapted = self.courses[(key, "B1")], self.exam_sessions[sitting], variant == "adapted"
        return {
            "title": EXAM_SITTINGS[variant][0].format(course["name"]), "description": "Digitale kennistoets met gesloten vragen en een open vraag. Cesuur 55 procent.",
            "courseId": course["uuid"], "sessionId": sess["uuid"], "cohortId": self.cohorts[(key, 1)]["uuid"],
            "curriculumPlanComponentId": "kennistoets", "itemRefs": [{"itemId": i["obj"]["uuid"], "points": i["max"]} for i in item_set],
            "itemSelectionMode": "fixed", "shuffleItemOrder": False, "shuffleAnswerOptions": False,
            "scoringScheme": "passMark", "passMark": 8.25, "timeLimitMinutes": 120 if adapted else 90,
            "maxAttempts": 1, "keepScore": "best", "availableFrom": sess["startsAt"], "availableUntil": sess["endsAt"],
            "accessCode": None,
            "proctoring": {"nativeTestMode": True, "navigationLock": True, "flagReviewMode": "manual", "lockdownBrowser": False, "recordWebcam": False} if adapted else None,
            "gradeEntryComponentId": "kennistoets", "competencyIds": [self.outcomes[key][0]["uuid"], self.outcomes[key][4]["uuid"]],
            "lifecycle": "closed",
        }

    def add_accommodations(self) -> None:
        self.accommodations = {}
        for s in self.extra_time:
            self.accommodations[s["nc"]] = self.b.add("exam-accommodation", {
                "learnerId": s["nc"], "submittedBy": s["nc"], "assessmentId": None, "accommodationKind": "extra-time-percentage", "value": 25,
                "evidenceRef": None, "approvedBy": "he-decaan-01", "lifecycle": "active",
            })
        self.b.add("exam-accommodation", {
            "learnerId": self.adapted_ict["nc"], "submittedBy": self.adapted_ict["nc"], "assessmentId": self.exams[("ICT", "adapted")]["obj"]["uuid"],
            "accommodationKind": "separate-room", "value": None, "evidenceRef": None, "approvedBy": "he-decaan-01", "lifecycle": "active",
        })

    def sit(self, s: dict, sitting: tuple[str, str, float]) -> dict:
        """One sitting of the knowledge test: sitting is the programme, the exam variant and the ability boost."""
        key, variant, boost = sitting
        exam = self.exams[(key, variant)]
        ability = s["ability"] + boost
        responses = [self.respond(i, ability) for i in exam["items"]]
        score = sum(scored(r) for r in responses)
        result = self.b.add("assessment-result", self.result_fields(s, (exam, responses)))
        self.results_by_exam.setdefault((key, variant), []).append((s, result, score))
        return {"result": result, "score": score, "grade": exam_grade(score, 15.0, 8.25)}

    def result_fields(self, s: dict, answered: tuple[dict, list[dict]]) -> dict:
        exam, responses = answered
        sess_start = dt.datetime.fromisoformat(exam["obj"]["availableFrom"])
        minutes = self.rng.randint(45, 88) if s["nc"] not in self.accommodations else self.rng.randint(70, 110)
        return {
            "assessmentId": exam["obj"]["uuid"], "learnerId": s["nc"], "attemptNumber": 1, "responses": responses,
            "drawnItemRefs": [{"itemId": i["obj"]["uuid"], "points": i["max"]} for i in exam["items"]],
            "startedAt": sess_start.isoformat(), "submittedAt": (sess_start + dt.timedelta(minutes=minutes)).isoformat(),
            "proctoringSessionId": None, "teacherIds": [self.teacher_of(s["programme"], "B1")], "gradeEntryId": None, "lifecycle": "graded",
        }

    def respond(self, i: dict, ability: float) -> dict:
        item = i["obj"]
        if i["type"] == "open":
            points = float(max(0, min(4, round(2.6 + 1.1 * ability + self.rng.gauss(0, 0.8)))))
            return {"itemId": item["uuid"], "response": {"value": self.rng.choice(OPEN_ANSWERS)}, "autoScore": None, "manualScore": points}
        p = logistic(-0.9 * ability) if i["b"] is None else logistic(1.7 * (ability - i["b"]))
        correct = self.rng.random() < p
        value = i["answer"] if correct else self.wrong_answer(i)
        return {"itemId": item["uuid"], "response": {"value": value}, "autoScore": 1.0 if correct else 0.0, "manualScore": None}

    def wrong_answer(self, i: dict) -> str:
        if i["type"] == "choice":
            return self.rng.choice([letter for letter in "ABCD" if letter != i["answer"]])
        return self.rng.choice(["weet ik niet", "", i["answer"] + "0"])

    def record_sitting(self, s: dict, sitting: tuple[str, str, float]) -> None:
        taken = self.sit(s, sitting)
        when = RESIT_DAY["B1"] if sitting[1] == "resit" else RESULTS_DAY["B1"]
        self.add_raw({"student": s, "ckey": "B1", "cid": "kennistoets", "value": taken["grade"], "when": when,
                      "kind": "assessment-result", "result": taken["result"]})

    def sit_main_exams(self) -> None:
        for key, *_rest in PROGRAMMES:
            for s in self.by_group[(key, 1)]:
                if s is not self.exempt_ict:
                    self.record_sitting(s, (key, "adapted" if s is self.adapted_ict else "main", 0.5))

    def add_proctoring(self) -> None:
        proctored = self.results_by_exam[("ICT", "adapted")][0][1]
        started = dt.datetime.fromisoformat(proctored["startedAt"])
        proctoring = self.b.add("proctoring-session", {
            "assessmentResultId": proctored["uuid"], "learnerId": self.adapted_ict["nc"], "provider": "native-test-mode", "providerSessionId": None,
            "status": "ended", "recordedArtefactRefs": [],
            "flags": [{"flagId": "flag-1", "kind": "window-blur", "occurredAt": (started + dt.timedelta(minutes=41)).isoformat(),
                       "severity": "low", "reviewDecision": "allowed", "reviewedBy": "he-surveillant-01",
                       "reviewedAt": stamp(PROGRAMME_OF["ICT"][7], 12, 30)}],
            "lifecycle": "ended",
        })
        proctored["proctoringSessionId"] = proctoring["uuid"]

    # Item statistics and reliability for the main sittings, computed from the
    # stored responses the way ItemAnalysisService does (no rounding; a
    # degenerate correlation is 0.0; the top and bottom 27 percent by total).
    def add_item_statistics(self) -> None:
        for key, *_rest in PROGRAMMES:
            self.analyse_exam(key)

    def analyse_exam(self, key: str) -> None:
        exam, rows = self.exams[(key, "main")], self.results_by_exam[(key, "main")]
        totals = [sum(scored(r) for r in res["responses"]) for _s, res, _sc in rows]
        sample = ExamSample(exam, rows, totals, high_and_low(totals))
        matrix = [self.add_item_statistic(sample, idx) for idx in range(len(exam["items"]))]
        k = len(exam["items"])
        alpha = (k / (k - 1)) * (1 - sum(variance(col) for col in matrix) / variance(totals))
        self.b.add("assessment-reliability", {"assessmentId": exam["obj"]["uuid"], "sampleSize": len(rows), "itemCount": k,
                                              "cronbachAlpha": alpha, "insufficientData": False, "computedAt": stamp(RESULTS_DAY["B1"], 8, 0)})

    def add_item_statistic(self, sample: ExamSample, idx: int) -> list[float]:
        i, n_rows = sample.exam["items"][idx], len(sample.rows)
        scores = [scored(res["responses"][idx]) for _s, res, _sc in sample.rows]
        corr = pearson(scores, [t - sc for t, sc in zip(sample.totals, scores)])
        p_value = full_marks_share(scores, i["max"])
        stat = self.b.add("item-statistics", {
            "itemId": i["obj"]["uuid"], "assessmentId": sample.exam["obj"]["uuid"], "sampleSize": n_rows, "pValue": p_value,
            "itemTotalCorrelation": corr, "distractorAnalysis": distractors(sample, idx), "insufficientData": n_rows < 20,
            "computedAt": stamp(RESULTS_DAY["B1"], 8, 0),
        })
        flags_for = self.revision_flags(p_value, corr)
        if flags_for:
            stat["_flags"] = flags_for
        return scores

    def revision_flags(self, p_value: float, correlation: float) -> list[tuple[str, str]]:
        flags_for = []
        for reason in flag_reasons(p_value, correlation):
            flags_for.append((reason, FLAG_STATES[self.flag_n % len(FLAG_STATES)]))
            self.flag_n += 1
        return flags_for

    # Resits of the knowledge test for everyone whose block 1 course would fail.
    def sit_knowledge_resits(self) -> None:
        for key, *_rest in PROGRAMMES:
            for s in self.by_group[(key, 1)]:
                if self.needs_knowledge_resit(s):
                    self.record_sitting(s, (key, "resit", 0.8))

    def needs_knowledge_resit(self, s: dict) -> bool:
        if s.get("withdrawn") or s is self.exempt_ict:
            return False
        first = self.raw[(s["nc"], "B1", "kennistoets")][0]
        other = self.raw[(s["nc"], "B1", "beroepsproduct")][0]
        value, _bd = aggregate([{"componentId": "kennistoets", "value": first["value"]}, {"componentId": "beroepsproduct", "value": other["value"]}], B1_WEIGHTS)
        return not (value >= PASS or first["value"] >= PASS or self.rng.random() > 0.9)

    def add_resit_reliability(self) -> None:
        for key, *_rest in PROGRAMMES:
            exam = self.exams[(key, "resit")]
            rows = self.results_by_exam.get((key, "resit"), [])
            self.b.add("assessment-reliability", {"assessmentId": exam["obj"]["uuid"], "sampleSize": len(rows), "itemCount": len(exam["items"]),
                                                  "cronbachAlpha": None, "insufficientData": True, "computedAt": stamp(RESIT_DAY["B1"], 8, 0)})

    def add_revision_flags(self) -> None:
        for stat in self.b.buckets["item-statistics"]:
            for reason, state in stat.pop("_flags", []):
                self.b.add("item-revision-flag", {"itemId": stat["itemId"], "itemStatisticsId": stat["uuid"], "reason": reason,
                                                  "pValueAtFlag": stat["pValue"], "itemTotalCorrelationAtFlag": stat["itemTotalCorrelation"],
                                                  "flaggedAt": stat["computedAt"], "lifecycle": state})

    # --- the peer reviewed group project (block 4) -------------------------------
    def add_group_projects(self) -> None:
        self.project_grade: dict[str, tuple[float, dict]] = {}
        self.submissions_by_key: dict[str, tuple[dict, list]] = {}
        for key, *_rest in PROGRAMMES:
            rubric = self.b.add("rubric", project_rubric(self.courses[(key, "B4")]))
            assignment = self.b.add("assignment", self.project_assignment(key, rubric))
            subs = [self.add_group_submission(assignment, group) for group in self.project_groups(key)]
            self.submissions_by_key[key] = (assignment, subs)

    def project_assignment(self, key: str, rubric: dict) -> dict:
        course = self.courses[(key, "B4")]
        return {
            "title": f"Projectverslag {course['name']}",
            "instructions": "Lever als projectgroep één verslag in. Beoordeel daarna de verslagen van twee andere groepen met dezelfde rubric.",
            "courseId": course["uuid"], "cohortId": self.cohorts[(key, 1)]["uuid"], "curriculumPlanComponentId": "projectverslag",
            "dueAt": stamp(dt.date(2026, 6, 12), 17, 0), "maxPoints": 40, "allowLateSubmission": False, "latePenaltyPercent": 0,
            "rubricId": rubric["uuid"], "groupSubmission": True, "visibleFrom": stamp(dt.date(2026, 5, 4), 8, 0),
            "competencyIds": [self.outcomes[key][6]["uuid"], self.outcomes[key][7]["uuid"]], "peerReviewEnabled": True, "selfAssessmentEnabled": False,
            "peerReviewersPerSubmission": 2, "peerReviewAnonymity": "blind", "peerReviewAllocationStrategy": "round-robin",
            "peerReviewDueAt": stamp(dt.date(2026, 6, 19), 17, 0), "peerReviewWeightPercent": 20, "lifecycle": "closed",
        }

    def project_groups(self, key: str) -> list[list[dict]]:
        members = sorted(still_enrolled(self.by_group[(key, 1)]), key=lambda s: s["nc"])
        self.rng.shuffle(members)
        groups = [members[i:i + 4] for i in range(0, len(members), 4)]
        if len(groups) > 1 and len(groups[-1]) < 3:
            groups[-2].extend(groups.pop())
        return groups

    def add_group_submission(self, assignment: dict, group: list[dict]) -> tuple[list[dict], dict]:
        scores = self.score_group(group)
        total = sum(x["points"] for x in scores)
        sub = self.b.add("submission", {
            "assignmentId": assignment["uuid"], "learnerIds": nc_ids(group), "learnerRefs": profile_refs(group),
            "attachmentRefs": [], "submittedAt": stamp(dt.date(2026, 6, 12), self.rng.randint(9, 16), self.rng.choice([5, 20, 35, 50])),
            "feedbackText": project_feedback(total),
            "gradeEntryId": None, "rubricScores": scores, "proposedGrade": total, "lifecycle": "returned", "plagiarismStatus": "not-requested",
        })
        for s in group:
            self.project_grade[s["nc"]] = (clamp_grade(max(1.0, total / 4)), sub)
        return (group, sub)

    def score_group(self, group: list[dict]) -> list[dict]:
        mean = sum(s["ability"] for s in group) / len(group)
        return [self.criterion_score(mean, cid) for cid, _lab in RUBRIC_CRITERIA]

    def criterion_score(self, mean: float, cid: str) -> dict:
        q = mean + self.rng.gauss(0.3, 0.7)
        lid, _ll, pts = RUBRIC_LEVELS[0 if q < -1.2 else 1 if q < 0.2 else 2 if q < 1.2 else 3]
        return {"criterionId": cid, "levelId": lid, "points": pts}

    def add_peer_reviews(self) -> None:
        for key, *_rest in PROGRAMMES:
            assignment, subs = self.submissions_by_key[key]
            reviews_for = {}
            for g_index, (_group, sub) in enumerate(subs):
                reviews_for[sub["uuid"]] = [self.add_peer_review(assignment, (subs, g_index, step)) for step in (1, 2)]
            for _group, sub in subs:
                self.add_feedback_summary(assignment, (sub, reviews_for[sub["uuid"]]))

    def add_peer_review(self, assignment: dict, pairing: tuple[list, int, int]) -> dict:
        subs, g_index, step = pairing
        sub = subs[g_index][1]
        reviewer_group = subs[(g_index + step) % len(subs)][0]
        reviewer = reviewer_group[(g_index + step) % len(reviewer_group)]
        scores = [self.reviewed_score(criterion) for criterion in sub["rubricScores"]]
        return self.b.add("peer-review", {
            "assignmentId": assignment["uuid"], "submissionId": sub["uuid"], "reviewerId": reviewer["nc"], "rubricScores": scores,
            "totalScore": sum(x["points"] for x in scores), "comments": self.rng.choice(PEER_COMMENTS), "lifecycle": "released",
        })

    def reviewed_score(self, criterion: dict) -> dict:
        shift = self.rng.choice([-1, 0, 0, 1])
        points = [level[2] for level in RUBRIC_LEVELS]
        idx = max(0, min(3, points.index(criterion["points"]) + shift))
        return {"criterionId": criterion["criterionId"], "levelId": RUBRIC_LEVELS[idx][0], "points": points[idx]}

    def add_feedback_summary(self, assignment: dict, reviewed: tuple[dict, list[dict]]) -> None:
        sub, reviews = reviewed
        self.b.add("peer-feedback-summary", {
            "submissionId": sub["uuid"], "assignmentId": assignment["uuid"], "learnerIds": sub["learnerIds"],
            "reviewCount": len(reviews), "averageScore": sum(r["totalScore"] for r in reviews) / len(reviews),
            "feedbackItems": [{"comments": r["comments"], "rubricScores": r["rubricScores"]} for r in reviews],
            "lastComputedAt": assignment["peerReviewDueAt"],
        })

    def add_project_grades(self) -> None:
        for s in by_group_all(self.by_group, 1):
            if s["nc"] in self.project_grade:
                value, sub = self.project_grade[s["nc"]]
                self.add_raw({"student": s, "ckey": "B4", "cid": "projectverslag", "value": value, "when": RESULTS_DAY["B4"],
                              "kind": "assignment-submission", "submission": sub})

    # --- internship portfolios (third year, semester 1) -----------------------
    def add_assessors(self) -> None:
        self.assessors: dict[str, list[tuple[str, dict]]] = {}
        for key, *_rest in PROGRAMMES:
            self.assessors[key] = [(org, self.b.add("external-assessor", {
                "givenName": given, "familyName": family, "email": f"{given.lower()}.{family.lower()}@{domain}",
                "organisationName": org, "active": True,
            })) for org, domain, given, family in WORKPLACES[key]]

    def add_portfolio_templates(self) -> None:
        self.templates = {}
        for key, short, *_rest in PROGRAMMES:
            self.templates[key] = self.b.add("portfolio-template", {
                "name": f"Stageportfolio {short} {YEAR}", "kind": "course-bound",
                "description": "Laat met bewijs en reflectie zien hoe je in de praktijk aan je leeruitkomsten werkte.",
                "sections": portfolio_sections(self.outcomes[key]), "rubricId": None, "lifecycle": "active",
            })

    def add_portfolios(self) -> None:
        for s in by_group_all(self.by_group, 3):
            org, assessor = self.assessors[s["programme"]][int(s["nc"].rsplit("-", 1)[1]) % 2]
            portfolio = self.add_portfolio(s, org)
            for reflection in REFLECTIONS:
                self.add_reflection(s, (portfolio, org, reflection))
            self.b.add("portfolio-share", {
                "portfolioId": portfolio["uuid"], "entryIds": None, "sharedWithKind": "external-assessor", "sharedWithTeacherId": None,
                "sharedWithPracticalTrainerId": None, "sharedWithExternalAssessorId": assessor["uuid"], "sharedBy": s["nc"],
                "expiresAt": stamp(dt.date(2026, 2, 27), 23, 59), "lifecycle": "active",
            })

    def add_portfolio(self, s: dict, org: str) -> dict:
        key = s["programme"]
        spec = self.raw[(s["nc"], "STAGE", "stageportfolio")][0]
        spec["portfolio"] = self.b.add("portfolio", {
            "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"], "kind": "course-bound", "templateId": self.templates[key]["uuid"],
            "courseId": self.courses[(key, "STAGE")]["uuid"], "curriculumPlanId": self.plans[(key, "STAGE")]["uuid"],
            "curriculumPlanComponentId": "stageportfolio", "title": f"Stageportfolio {s['given']} {s['family']}",
            "description": f"Stage bij {org}.", "dueAt": stamp(dt.date(2026, 1, 23), 17, 0), "gradeValue": spec["value"],
            "gradeEntryId": None, "lifecycle": "graded",
        })
        return spec["portfolio"]

    def add_reflection(self, s: dict, entry: tuple) -> None:
        portfolio, org, (title, section, criterion, outcome, texts) = entry
        self.b.add("portfolio-entry", {
            "portfolioId": portfolio["uuid"], "learnerId": s["nc"], "title": title, "evidenceKind": "reflection",
            "reflectionText": self.rng.choice(texts).format(org=org),
            "sectionId": section, "criterionId": criterion, "competencyId": self.outcomes[s["programme"]][outcome]["uuid"],
        })

    # --- exam board cases -------------------------------------------------------
    def add_exemption_cases(self) -> None:
        applicants = {"ICT": self.exempt_ict, "WTB": first_with_mbo(self.by_group[("WTB", 1)]), "SW": first_with_mbo(self.by_group[("SW", 1)])}
        for who, *case in EXEMPTIONS:
            self.add_exemption_case(applicants[who], tuple(case))

    def add_exemption_case(self, s: dict, case: tuple) -> None:
        ckey, cid, grounds, desc, decided, granted, rationale = case
        key = s["programme"]
        decision = self.b.add("exemption-case", {
            "learnerId": s["profile"]["uuid"], "learnerUserId": s["profile"]["ncUserId"], "curriculumPlanId": self.plans[(key, ckey)]["uuid"], "componentId": cid, "groundsKind": grounds,
            "groundsDescription": desc, "submittedAt": stamp(decided - dt.timedelta(days=21), 11, 0), "decisionRationale": rationale,
            "policyReference": f"Onderwijs- en examenregeling {YEAR}, paragraaf vrijstellingen", "decidedBy": CHAIRS[PROGRAMME_OF[key][4]],
            "decidedAt": stamp(decided, 16, 0), "resultingGradeEntryId": None, "lifecycle": "granted" if granted else "rejected",
        })
        if granted:
            self.raw.pop((s["nc"], ckey, cid), None)
            self.add_raw({"student": s, "ckey": ckey, "cid": cid, "value": None, "when": decided, "kind": "exemption", "case": decision})

    # Fraud: an ICT student copied part of a block 2 report without citing it.
    def add_fraud_case(self) -> None:
        student = self.fraud_student()
        contested = self.raw[(student["nc"], "B2", "beroepsproduct")][0]
        contested["value"] = max(contested["value"], 6.8)
        fraud = self.b.add("fraud-case", fraud_case(student, self.teacher_of("ICT", "B2")))
        contested["kind"] = "manual"
        contested["lifecycle"] = "invalidated"
        contested["fraud"] = fraud
        self.add_raw({"student": student, "ckey": "B2", "cid": "beroepsproduct", "value": clamp_grade(6.4 + 0.5 * student["ability"]),
                      "when": RESIT_DAY["B2"], "kind": "manual", "comment": "Nieuw verslag na het besluit van de examencommissie."})

    def fraud_student(self) -> dict:
        candidates = (s for s in self.by_group[("ICT", 1)] if not s.get("withdrawn") and s is not self.exempt_ict and s is not self.adapted_ict)
        return sorted(candidates, key=lambda s: s["nc"])[3]

    # --- resits and the outcome of every course ------------------------------
    def course_specs(self, s: dict, ckey: str) -> list[dict]:
        out = []
        for cid, *_rest in COURSE_META[(s["programme"], ckey)][6]:
            out.extend(self.raw.get((s["nc"], ckey, cid), []))
        return out

    def published_raw(self, grade_key: tuple[str, str, str]) -> list[dict]:
        return [x for x in self.raw.get(grade_key, []) if is_published(x)]

    def add_resits(self) -> None:
        for s in self.students:
            for course in COURSES[s["programme"]]:
                if self.needs_resit(s, course):
                    self.add_course_resits(s, course)

    def needs_resit(self, s: dict, course: tuple) -> bool:
        if not graded_this_year(s, course):
            return False
        value, _bd = aggregate(published(self.course_specs(s, course[0])), components_of(s["programme"], course[0]))
        return not (value is None or value >= PASS or self.rng.random() > 0.9)

    def add_course_resits(self, s: dict, course: tuple) -> None:
        ckey, period = course[0], course[2]
        for cid, *_c in course[6]:
            if (ckey, cid) == ("B1", "kennistoets"):
                continue  # the knowledge test resit ran through the item bank above
            firsts = self.published_raw((s["nc"], ckey, cid))
            if all_failed(firsts):
                self.add_raw({"student": s, "ckey": ckey, "cid": cid, "value": clamp_grade(6.1 + 0.6 * s["ability"] + self.rng.gauss(0.2, 0.7)),
                              "when": RESIT_DAY[period], "kind": "manual"})

    def passed_by(self, s: dict, moment: tuple[str, dt.date | None]) -> bool:
        """Passed a course by a day: every component graded by then, and the engine's value at least 5.5."""
        ckey, until = moment
        pub = published(self.course_specs(s, ckey), until)
        if {row["componentId"] for row in pub} != set(components_of(s["programme"], ckey)):
            return False
        value, _bd = aggregate(pub, components_of(s["programme"], ckey))
        return value is not None and value >= PASS

    def ects(self, s: dict, until: dt.date | None = None) -> float:
        return float(sum(c[3] for c in COURSES[s["programme"]] if c[1] == 1 and self.passed_by(s, (c[0], until))))

    def settle_first_years(self) -> None:
        self.first_years = still_enrolled(by_group_all(self.by_group, 1))
        self.warned = self.warned_in_february()
        # A negative advice needs an earlier warning. A student who was on track in
        # February but ends below the norm passes the resit of the last block they
        # failed, so the set never shows an unwarned negative advice.
        for s in self.first_years:
            if s["nc"] not in self.warned and self.ects(s) < BSA_NORM:
                self.pass_last_failed_block(s)

    def warned_in_february(self) -> set[str]:
        return {s["nc"] for s in self.first_years if self.ects(s, CHECK_UNTIL) < BSA_INTERIM_NORM}

    def pass_last_failed_block(self, s: dict) -> None:
        for ckey in ("B4", "B3"):
            if not self.passed_by(s, (ckey, None)):
                self.pass_failed_components(s, ckey)
                return

    def pass_failed_components(self, s: dict, ckey: str) -> None:
        for cid, *_c in COURSE_META[(s["programme"], ckey)][6]:
            specs = self.published_raw((s["nc"], ckey, cid))
            if not all_failed(specs):
                continue
            resits = graded_on(specs, RESIT_DAY[ckey])
            if resits:
                resits[-1]["value"] = 7.0
            else:
                self.add_raw({"student": s, "ckey": ckey, "cid": cid, "value": 7.0, "when": RESIT_DAY[ckey], "kind": "manual"})

    # --- grade entries and final grades ------------------------------------------
    def add_grade_entries(self) -> None:
        self.finals: dict[tuple[str, str], dict] = {}
        for s in self.students:
            for course in COURSES[s["programme"]]:
                if course[1] == s["year"]:
                    self.grade_course(s, course)

    def grade_course(self, s: dict, course: tuple) -> None:
        specs = sorted(self.course_specs(s, course[0]), key=lambda x: (x["when"], x["cid"]))
        for x in specs:
            link_entry(x, self.b.add("grade-entry", self.grade_entry_fields(s, x)))
        if published(specs):
            self.add_final_grade(s, (course[0], specs))

    def grade_entry_fields(self, s: dict, x: dict) -> dict:
        key, ckey, exempt = s["programme"], x["ckey"], x["kind"] == "exemption"
        fields = {
            "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"], "curriculumPlanId": self.plans[(key, ckey)]["uuid"],
            "componentId": x["cid"], "courseId": self.courses[(key, ckey)]["uuid"], "cohortId": self.cohorts[(key, s["year"])]["uuid"],
            "sourceKind": x["kind"],
        } | source_refs(x) | {
            "value": x["value"], "gradeScaleId": self.scale["uuid"], "period": COURSE_META[(key, ckey)][2],
            "grader": CHAIRS[PROGRAMME_OF[key][4]] if exempt else self.teacher_of(key, ckey),
            "gradedAt": stamp(x["when"], 16, 0) if exempt else stamp(x["when"], 10, 0),
            "lifecycle": x.get("lifecycle", "published"),
        }
        if x.get("comment"):
            fields["comment"] = x["comment"]
        return fields

    def add_final_grade(self, s: dict, graded: tuple[str, list[dict]]) -> None:
        ckey, specs = graded
        key = s["programme"]
        value, breakdown = aggregate(published(specs), components_of(key, ckey))
        last = max(x["when"] for x in specs if is_published(x))
        self.finals[(s["nc"], ckey)] = self.b.add("final-grade", {
            "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"], "courseId": self.courses[(key, ckey)]["uuid"],
            "curriculumPlanId": self.plans[(key, ckey)]["uuid"], "gradeScaleId": self.scale["uuid"], "value": value,
            "passed": value is not None and value >= PASS, "breakdown": breakdown, "lastRecomputedAt": stamp(last, 10, 0),
        })

    def settle_enrolments(self) -> None:
        for (nc, ckey), enrolment in self.enrolments.items():
            enrolment.update(enrolment_outcome(self.student_by_nc[nc], self.finals.get((nc, ckey))))

    # --- binding study advice ------------------------------------------------------
    def add_bsa_trajectories(self) -> None:
        self.trajectories = {}
        for key, *_rest in PROGRAMMES:
            self.trajectories[key] = self.b.add("bsa-trajectory", {
                "programmeId": self.programmes[key]["uuid"], "academicYear": YEAR, "kind": "he-eerstejaar-bsa", "cohortId": self.cohorts[(key, 1)]["uuid"],
                "normEcts": BSA_NORM, "window": {"mode": "fixed-date", "date": BSA_CHECK.isoformat()}, "interimNormEcts": BSA_INTERIM_NORM,
                "onAtRisk": {"notify": True, "notifyRoles": ["study-advisor", "exam-board"], "createFlag": True}, "lifecycle": "active",
            })

    def count_final_ects(self) -> None:
        self.final_ects = {s["nc"]: self.ects(s) for s in self.first_years}

    def add_bsa_warnings(self) -> None:
        warned = [s for s in self.first_years if s["nc"] in self.warned]
        self.flags = {s["nc"]: self.add_bsa_flag(s) for s in warned}
        self.warnings = {s["nc"]: self.add_bsa_warning(s) for s in warned}

    def add_bsa_flag(self, s: dict) -> dict:
        key = s["programme"]
        return self.b.add("bsa-progress-flag", {
            "learnerId": s["nc"], "programmeId": self.programmes[key]["uuid"], "bsaTrajectoryId": self.trajectories[key]["uuid"], "academicYear": YEAR,
            "ectsEarned": self.ects(s, CHECK_UNTIL), "ectsRequiredAtCheck": BSA_INTERIM_NORM, "flaggedAt": stamp(BSA_CHECK, 8, 0),
            "lifecycle": "resolved" if self.final_ects[s["nc"]] >= BSA_NORM else "warned",
        })

    def add_bsa_warning(self, s: dict) -> dict:
        return self.b.add("bsa-warning", {
            "learnerId": s["nc"], "programmeId": self.programmes[s["programme"]]["uuid"], "academicYear": YEAR, "bsaProgressFlagId": self.flags[s["nc"]]["uuid"],
            "warningDate": dt.date(2026, 2, 13).isoformat(), "ectsEarnedAtWarning": self.ects(s, CHECK_UNTIL), "ectsNormAtWarning": BSA_NORM,
            "improvementPeriod": {"startDate": "2026-02-16", "endDate": "2026-07-03"},
            "offeredGuidance": "Maandelijks gesprek met de studieadviseur, een studieplan voor de herkansingen en een training plannen en studeren.",
            "personalCircumstancesNote": None, "signature": None, "signingKeyId": None,
            "lifecycle": self.rng.choice(["issued", "acknowledged", "acknowledged"]),
        })

    def pick_bsa_exceptions(self) -> None:
        below = [s for s in self.first_years if self.final_ects[s["nc"]] < BSA_NORM]
        self.postponed = self.postponed_advice(below)
        self.recommended = self.recommended_per_faculty(below)
        self.appealed = next((s["nc"] for s in below if s["nc"] not in self.postponed | self.recommended), None)

    def postponed_advice(self, below: list[dict]) -> set[str]:
        """The student below the norm with the most excused absences gets a postponed advice."""
        return {max(below, key=self.excused_count)["nc"]} if below else set()

    def excused_count(self, s: dict) -> int:
        return sum(1 for r in self.records.get(s["nc"], []) if r["status"] == "absent-excused")

    def recommended_per_faculty(self, below: list[dict]) -> set[str]:
        recommended = set()
        for fac in ("zuid", "noord"):
            pool = self.faculty_pool(below, fac)
            if pool:
                recommended.add(pool[0]["nc"])
        return recommended

    def faculty_pool(self, below: list[dict], fac: str) -> list[dict]:
        return [s for s in below if s["nc"] not in self.postponed and PROGRAMME_OF[s["programme"]][4] == fac]

    def add_bsa_decisions(self) -> None:
        for s in self.first_years:
            self.b.add("bsa-decision", self.bsa_decision(s) | self.bsa_verdict(s))

    def bsa_decision(self, s: dict) -> dict:
        warning = self.warnings.get(s["nc"])
        return {
            "learnerId": s["nc"], "programmeId": self.programmes[s["programme"]]["uuid"], "academicYear": YEAR, "ectsAchieved": self.final_ects[s["nc"]],
            "ectsNormRequired": BSA_NORM, "warningIds": [warning["uuid"]] if warning else [], "personalCircumstancesConsidered": False,
            "personalCircumstancesNote": None, "studentHeardAt": None, "studentResponse": None, "rationale": None,
            "decidedBy": CHAIRS[PROGRAMME_OF[s["programme"]][4]], "decisionDate": stamp(dt.date(2026, 7, 8), 10, 0), "signature": None, "signingKeyId": None,
            "lifecycle": "decided",
        }

    def bsa_verdict(self, s: dict) -> dict:
        earned = self.final_ects[s["nc"]]
        if earned >= BSA_NORM:
            return {"decisionType": "positive"}
        if s["nc"] in self.postponed:
            return postponed_verdict(earned)
        kind = "negative-with-recommendation" if s["nc"] in self.recommended else "negative"
        verdict = negative_verdict(earned, kind)
        if s["nc"] == self.appealed:
            verdict["lifecycle"] = "appealed"
        return verdict

    # --- learning record exports (graduates) ---------------------------------------
    def add_record_exports(self) -> None:
        for key, *_rest in PROGRAMMES:
            graduates = [s for s in self.by_group[(key, 4)] if self.graduated(s)]
            for i, s in enumerate(graduates[:2]):
                self.add_record_export(s, i)

    def graduated(self, s: dict) -> bool:
        final = self.finals.get((s["nc"], "AFST"))
        return final is not None and final["passed"]

    def add_record_export(self, s: dict, i: int) -> None:
        key = s["programme"]
        requested = dt.date(2026, 7, 6) + dt.timedelta(days=i)
        coverage = self.export_coverage(s)
        fields = {
            "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"], "requestedBy": s["nc"], "requestedAt": stamp(requested, 20, 15),
            "generatedAt": None, "periodFrom": None, "periodTo": None, "coverageReport": [], "bundleRef": None,
            "bundleSignature": None, "issuerDid": None, "errorMessage": None, "lifecycle": "requested",
        }
        if i == 0 or key in ("VPK", "ICT"):
            fields.update({"generatedAt": stamp(requested, 20, 16), "coverageReport": coverage,
                           "bundleRef": f"Leerdossier/leerdossier-{requested.isoformat()}.json", "lifecycle": "generated"})
        self.b.add("learning-record-export", fields)


    def export_coverage(self, s: dict) -> list[dict]:
        return [{"sourceSchema": "final-grade", "sourceId": self.finals[(s["nc"], ckey)]["uuid"], "sourceTitle": self.courses[(s["programme"], ckey)]["name"],
                 "outcome": "included", "reason": None} for ckey in ("VERD", "AFST")]


def docent_row(nc: str, i: int) -> tuple:
    """A docent's staff row: the first four mentor a study year or coordinate, the fourth also examines senior."""
    roles = ["teacher", "mentor"] if i < 4 else ["teacher"]
    qual = ["Basiskwalificatie didactische bekwaamheid (BDB)", "Basiskwalificatie examinering (BKE)"]
    if i == 3:
        qual.append("Seniorkwalificatie examinering (SKE)")
    if i < 4:
        qual.append(DOCENT_TASKS[i])
    return (nc, roles, qual, WEEKDAYS if i % 2 == 0 else WEEKDAYS[:4])


def adviser_rows() -> list[tuple]:
    return [(f"he-studieadviseur-{i:02d}", ["coordinator"], [f"Studieadviseur {short}"], ["monday", "tuesday", "thursday", "friday"])
            for i, (_key, short, *_rest) in enumerate(PROGRAMMES, start=1)]


def graded_by_component(s: dict, course: tuple) -> bool:
    """A course of the student's year; a withdrawn student is only graded in block 1."""
    return course[1] == s["year"] and not (s.get("withdrawn") and course[0] != "B1")


def attends(s: dict, day: dt.date) -> bool:
    """A withdrawn student only attends before the withdrawal day."""
    return not s.get("withdrawn") or day < WITHDRAWAL_DAY


def exam_room(key: str, variant: str) -> str:
    if variant == "adapted":
        return "stilte"
    return "tentamenzuid" if PROGRAMME_OF[key][4] == "zuid" else "tentamennoord"


def still_enrolled(group: list[dict]) -> list[dict]:
    return [s for s in group if not s.get("withdrawn")]


def nc_ids(group: list[dict]) -> list[str]:
    return [s["nc"] for s in group]


def profile_refs(group: list[dict]) -> list[str]:
    return [s["profile"]["uuid"] for s in group]


def first_with_mbo(group: list[dict]) -> dict:
    return next(s for s in group if s["mbo"] and not s.get("withdrawn"))


def takes_adapted_exam(s: dict, exempt: dict) -> bool:
    return s is not exempt and not s.get("withdrawn") and not s["mbo"]


def scored(response: dict) -> float:
    return response["manualScore"] if response["manualScore"] is not None else response["autoScore"]


def full_marks_share(scores: list[float], max_score: float) -> float:
    return sum(1 for sc in scores if sc >= max_score - 1e-9) / len(scores)


def high_and_low(totals: list[float]) -> tuple[list[int], list[int]]:
    """Row indexes of the top and bottom 27 percent by total."""
    n_rows = len(totals)
    group = min(max(1, int(php_round(n_rows * 0.27, 0))), n_rows // 2)
    order = sorted(range(n_rows), key=lambda i: -totals[i])
    return order[:group], order[-group:]


def distractors(sample: ExamSample, idx: int) -> list[dict] | None:
    """How often the high and the low group picked each option of a choice item."""
    if sample.exam["items"][idx]["type"] != "choice":
        return None
    picks = [res["responses"][idx]["response"]["value"] for _s, res, _sc in sample.rows]
    return [option_row(letter, (picks, sample.groups)) for letter in "ABCD"]


def option_row(letter: str, picked: tuple[list[str], tuple[list[int], list[int]]]) -> dict:
    picks, (high, low) = picked
    return {"optionId": letter,
            "selectedByHighGroup": sum(1 for j in high if picks[j] == letter),
            "selectedByLowGroup": sum(1 for j in low if picks[j] == letter)}


def project_rubric(course: dict) -> dict:
    return {
        "name": f"Beoordelingsrubric {course['name']}", "description": "Vier criteria, elk op vier niveaus.",
        "criteria": [{"criterionId": cid, "label": lab, "weight": 1, "levels": [{"levelId": lid, "label": ll, "points": pts} for lid, ll, pts in RUBRIC_LEVELS]}
                     for cid, lab in RUBRIC_CRITERIA],
        "maxPoints": 40, "lifecycle": "active",
    }


def project_feedback(total: float) -> str:
    if total >= 30:
        return "Sterk verslag met een heldere analyse."
    if total >= 22:
        return "Voldoende verslag; werk de onderbouwing verder uit."
    return "De analyse is te dun. Werk het verslag om in de herkansing."


def portfolio_sections(lus: list[dict]) -> list[dict]:
    return [
        {"sectionId": "praktijk", "label": "Beroepshandelen in de praktijk", "order": 1,
         "helpText": "Beschrijf twee situaties waarin je zelfstandig handelde.",
         "criteria": [{"criterionId": "praktijk-1", "label": "Plannen en uitvoeren", "description": lus[2]["description"], "competencyId": lus[2]["uuid"]},
                      {"criterionId": "praktijk-2", "label": "Samenwerken en communiceren", "description": lus[6]["description"], "competencyId": lus[6]["uuid"]}]},
        {"sectionId": "ontwikkeling", "label": "Professionele ontwikkeling", "order": 2,
         "helpText": "Laat zien wat je leerde en wat je volgende stap is.",
         "criteria": [{"criterionId": "ontwikkeling-1", "label": "Reflectie en leerdoelen", "description": lus[8]["description"], "competencyId": lus[8]["uuid"]}]},
    ]


def fraud_case(student: dict, reporter: str) -> dict:
    return {
        "reporterId": reporter, "accusedLearnerId": student["profile"]["uuid"], "accusedLearnerUserId": student["profile"]["ncUserId"], "sourceKind": "manual",
        "allegation": "Twee pagina's van het individuele verslag over het datamodel zijn letterlijk overgenomen van een openbare website, zonder bronvermelding.",
        "reportedAt": stamp(dt.date(2026, 2, 2), 10, 0), "hearingDate": stamp(dt.date(2026, 2, 19), 14, 0),
        "hearingRecords": [{"heldAt": stamp(dt.date(2026, 2, 19), 14, 0), "attendees": [student["nc"], reporter, CHAIRS["noord"]],
                            "notes": "De student erkent dat de passage is overgenomen en zegt de bronvermelding vergeten te zijn.", "evidenceRefs": []}],
        "verdict": "fraud-proven",
        "decisionRationale": "De overgenomen passage is omvangrijk en vormt de kern van het verslag. De examencommissie verklaart het beroepsproduct ongeldig; de student levert in de herkansing een nieuw verslag in.",
        "decidedBy": CHAIRS["noord"], "decidedAt": stamp(dt.date(2026, 3, 2), 16, 0), "sanctionType": "resubmission-required",
        "sanctionDurationMonths": None, "sanctionScope": "single-assessment", "appealDeadline": stamp(dt.date(2026, 4, 13), 16, 0),
        "appealLodged": False, "appealOutcome": None, "lifecycle": "decided",
    }


def all_failed(specs: list[dict]) -> bool:
    return bool(specs) and all(x["value"] < PASS for x in specs)


def graded_on(specs: list[dict], day: dt.date) -> list[dict]:
    return [x for x in specs if x["when"] == day]


def is_published(spec: dict) -> bool:
    return spec.get("lifecycle", "published") == "published"


def published(specs: list[dict], until: dt.date | None = None) -> list[dict]:
    """The published raw grades (up to a day) as the aggregation engine reads them."""
    rows = published_until(specs, until)
    return [{"componentId": x["cid"], "value": x["value"], "sourceKind": x["kind"], "period": COURSE_META[(x["student"]["programme"], x["ckey"])][2]} for x in rows]


def published_until(specs: list[dict], until: dt.date | None) -> list[dict]:
    return [x for x in specs if is_published(x) and (until is None or x["when"] <= until)]


def components_of(key: str, ckey: str) -> dict[str, dict]:
    return {c[0]: {"weight": c[3]} for c in COURSE_META[(key, ckey)][6]}


def graded_this_year(s: dict, course: tuple) -> bool:
    """A course the student follows this year and is still graded in (the internship is graded by portfolio)."""
    return course[1] == s["year"] and not s.get("withdrawn") and course[0] != "STAGE"


def source_refs(x: dict) -> dict[str, str]:
    """Only the reference that names a grade entry's source is written; the others stay unset."""
    return {field: x[name]["uuid"] for field, name in SOURCE_FIELDS if x.get(name) is not None}


def link_entry(x: dict, entry: dict) -> None:
    for name, field in ENTRY_LINKS:
        if name in x:
            x[name][field] = entry["uuid"]


def enrolment_outcome(s: dict, final: dict | None) -> dict:
    if s.get("withdrawn") and final is None:
        return {"lifecycle": "withdrawn", "reason": f"Uitgeschreven per {WITHDRAWAL_DAY.day} januari 2026, voor de BSA-peildatum."}
    if final is not None and final["passed"]:
        return {"lifecycle": "completed"}
    return {"lifecycle": "failed", "reason": "Onderdeel niet behaald, ook niet na de herkansing."}


def postponed_verdict(earned: float) -> dict:
    return {
        "decisionType": "postponed", "personalCircumstancesConsidered": True,
        "personalCircumstancesNote": "Langdurige ziekte in blok 2 en 3, gemeld bij de studentendecaan.",
        "studentHeardAt": stamp(dt.date(2026, 7, 1), 11, 0),
        "studentResponse": "De student wil het tweede jaar gebruiken om de ontbrekende blokken te halen.",
        "rationale": f"Behaald: {earned:g} van de {BSA_NORM:g} studiepunten. Door de gemelde ziekte stelt de examencommissie het advies een jaar uit.",
    }


def negative_verdict(earned: float, kind: str) -> dict:
    return {
        "decisionType": kind, "personalCircumstancesConsidered": True,
        "personalCircumstancesNote": "Geen persoonlijke omstandigheden gemeld bij de studentendecaan.",
        "studentHeardAt": stamp(dt.date(2026, 7, 1), 11, 0),
        "studentResponse": "De student geeft aan dat de combinatie met een bijbaan te zwaar was.",
        "rationale": f"Behaald: {earned:g} van de {BSA_NORM:g} studiepunten. De student is in februari gewaarschuwd en kreeg begeleiding van de studieadviseur."
        + (" De examencommissie adviseert de associate degree binnen dezelfde faculteit." if kind == "negative-with-recommendation" else ""),
    }


def by_group_all(by_group: dict[tuple[str, int], list[dict]], year: int) -> list[dict]:
    """Every student of one study year, programme by programme."""
    out = []
    for key, *_rest in PROGRAMMES:
        out.extend(by_group[(key, year)])
    return out


def qti(item: dict, row: tuple) -> str:
    """The QTI item XML the app's own item editor writes (src/views/ItemAuthorView.vue buildQtiBody).

    The take view, the draw resolver and the item analysis all read
    `simpleChoice` elements, so an item in any other dialect would render as
    two placeholder options and never get a distractor analysis.
    """
    head = ('<?xml version="1.0" encoding="UTF-8"?><assessmentItem xmlns="http://www.imsglobal.org/xsd/imsqti_v2p1" '
            f'identifier="{xml_attr(item["slug"])}" title="{xml_attr(item["title"])}" adaptive="false" timeDependent="false">')
    score = f'<outcomeDeclaration identifier="SCORE" cardinality="single" baseType="float"><defaultValue><value>{item["maxScore"]:g}</value></defaultValue></outcomeDeclaration>'
    declaration, body = qti_interaction(row)
    return head + declaration + score + "<itemBody>" + body + "</itemBody></assessmentItem>"


def qti_choice(row: tuple) -> tuple[str, str]:
    _itype, stem, options, answer, _difficulty = row
    declaration = f'<responseDeclaration identifier="RESPONSE" cardinality="single" baseType="identifier"><correctResponse><value>{escape(answer or "")}</value></correctResponse></responseDeclaration>'
    choices = "".join(f'<simpleChoice identifier="{letter}">{escape(text)}</simpleChoice>' for letter, text in zip("ABCD", options or []))
    return declaration, f'<p>{escape(stem)}</p><choiceInteraction responseIdentifier="RESPONSE" shuffle="false" maxChoices="1">{choices}</choiceInteraction>'


def xml_attr(text: str) -> str:
    return escape(text, {'"': "&quot;"})


def qti_interaction(row: tuple) -> tuple[str, str]:
    """The response declaration and the item body for one item type."""
    itype, stem, _options, answer, _difficulty = row
    if itype == "choice":
        return qti_choice(row)
    if itype == "text":
        declaration = f'<responseDeclaration identifier="RESPONSE" cardinality="single" baseType="string"><correctResponse><value>{escape(answer or "")}</value></correctResponse></responseDeclaration>'
        return declaration, f'<p>{escape(stem)}</p><textEntryInteraction responseIdentifier="RESPONSE" expectedLength="20" />'
    return "", f'<p>{escape(stem)}</p><extendedTextInteraction responseIdentifier="RESPONSE" expectedLength="500" />'


if __name__ == "__main__":
    sys.exit(main(Script(SET, __doc__, build)))
