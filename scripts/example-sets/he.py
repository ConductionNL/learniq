#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
# Copyright (C) 2026 Conduction B.V.
"""Build lib/Settings/profiles/he.json, the higher education example set.

One fictional university of applied sciences, Voorbeeldhogeschool Esdoornstad
in the fictional town of Esdoornstad, through one complete academic year
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

THE CONTRACT. openspec/changes/segment-wizard-choice/contract.md. Every rule is
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

import argparse
import datetime as dt
import json
import math
import os
import random
import sys
from decimal import ROUND_HALF_UP, Decimal
from xml.sax.saxutils import escape
from zoneinfo import ZoneInfo

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
OUT = os.path.join(ROOT, "lib", "Settings", "profiles", "he.json")
AMS = ZoneInfo("Europe/Amsterdam")
TENANT = "00000000-0000-4000-8000-000000000000"
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
TOWNS = ["Esdoornstad", "Esdoornstad", "Esdoornstad", "Voorbeeldhoven", "Voorbeeldveen"]

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
    best: dict[str, dict] = {}
    for entry in entries:
        cid = entry["componentId"]
        if cid not in best or float(entry.get("value") or 0) > float(best[cid].get("value") or 0):
            best[cid] = entry
    weighted, total = 0.0, 0.0
    periods: dict[str, list[float]] = {}
    parts: dict[str, dict] = {}
    for entry in best.values():
        cid = entry["componentId"]
        if entry.get("sourceKind") == "exemption":
            parts[cid] = {"exempt": True}
            continue
        value = float(entry["value"])
        weight = float(components[cid]["weight"])
        weighted += value * weight
        total += weight
        period = str(entry.get("period") or "unknown")
        periods.setdefault(period, [0.0, 0.0])
        periods[period][0] += value * weight
        periods[period][1] += weight
        parts[cid] = {"value": value, "weight": weight, "contribution": value * weight}
    if total == 0.0:
        return None, parts
    breakdown = {
        "periods": {p: (php_round(s / w, 4) if w > 0 else None) for p, (s, w) in periods.items()},
        "components": parts,
    }
    return php_round(weighted / total, 4), breakdown


def build() -> dict:
    rng = random.Random(20250901)
    b = Builder()
    off = closed_days()

    # --- institution, faculties, rooms --------------------------------------
    school = b.add("school", {"brin": "00X4", "name": "Voorbeeldhogeschool Esdoornstad", "pedagogicalConcept": "regular"})
    faculties = {
        "zuid": b.add("vestiging", {
            "schoolId": school["uuid"], "vestigingscode": "00X400", "onderwijslocatiecode": "00X400-A",
            "name": "Faculteit Gezondheid en Welzijn, Campus Zuid", "street": "Campuslaan 10", "postalCode": "0611 CZ", "city": "Esdoornstad",
        }),
        "noord": b.add("vestiging", {
            "schoolId": school["uuid"], "vestigingscode": "00X401", "onderwijslocatiecode": "00X401-A",
            "name": "Faculteit Techniek en ICT, Campus Noord", "street": "Ingenieursweg 3", "postalCode": "0614 CN", "city": "Esdoornstad",
        }),
    }
    room_rows = [
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
    rooms = {}
    for key, name, code, capacity, kind, facilities, fac, floor in room_rows:
        rooms[key] = b.add("room", {"name": name, "code": code, "capacity": capacity, "kind": kind, "facilities": facilities,
                                    "buildingCode": faculties[fac]["vestigingscode"], "floor": floor})

    scale = b.add("grade-scale", {"name": "Nederlandse cijferschaal 1 tot 10", "kind": "numeric", "min": 1.0, "max": 10.0,
                                  "passThreshold": PASS, "roundingRule": "half-up-1dp", "lifecycle": "active"})

    # --- learning outcomes (Dublin descriptors) ------------------------------
    frameworks, outcomes = {}, {}
    for key, short, name, *_rest in PROGRAMMES:
        frameworks[key] = b.add("competency-framework", {
            "name": f"Leeruitkomsten {short}", "sourceAuthority": "school-defined", "sourceRef": "Dublin-descriptoren, bachelor",
            "edition": "2025", "level": "hbo",
            "description": f"De tien leeruitkomsten van {name}, geordend onder de vijf Dublin-descriptoren.",
            "proficiencyLevels": LEVELS, "lifecycle": "published",
        })
    for key, *_rest in PROGRAMMES:
        descriptors = []
        for order, (code, title) in enumerate(DUBLIN, start=1):
            descriptors.append(b.add("competency", {
                "frameworkId": frameworks[key]["uuid"], "parentId": None, "code": f"{key}-{code}", "title": title,
                "description": f"Dublin-descriptor {order} voor de bachelor {key}.", "order": order, "lifecycle": "published",
            }))
        outcomes[key] = []
        for n, text in enumerate(OUTCOMES[key], start=1):
            outcomes[key].append(b.add("competency", {
                "frameworkId": frameworks[key]["uuid"], "parentId": descriptors[(n - 1) // 2]["uuid"], "code": f"{key}-LU{n:02d}",
                "title": f"Leeruitkomst {n}", "description": text, "order": n, "requiredForRoles": ["learner"], "lifecycle": "published",
            }))

    # --- programmes, courses, curriculum plans -------------------------------
    programmes, courses, plans = {}, {}, {}
    for key, short, name, code, fac, *_rest in PROGRAMMES:
        programmes[key] = b.add("programme", {
            "name": name, "code": code, "level": "hbo", "description": PROGRAMME_DESCRIPTION[key],
            "curriculumPlanId": None, "courseIds": [], "credentialTemplateId": None,
            "requiredCompetencyIds": [o["uuid"] for o in outcomes[key]], "lifecycle": "published",
        })
    period_meta = {bl[0]: (bl[1], bl[2], bl[3]) for bl in BLOCKS} | {s[0]: (s[1], s[2], s[3]) for s in SEMESTERS}
    for key, short, name, code, fac, *_rest in PROGRAMMES:
        for ckey, year, period, ects, cname, desc, comps, lus, _docent in COURSES[key]:
            courses[(key, ckey)] = b.add("course", {
                "code": f"{key}-{ckey}-2526", "name": cname, "name_nl": cname, "description": desc, "level": "hbo", "language": "nl",
                "tags": [short, f"jaar {year}"], "lifecycle": "published", "curriculumPlanId": None,
                "programmeIds": [programmes[key]["uuid"]], "ectsCredits": ects,
                "competencyIds": [outcomes[key][n - 1]["uuid"] for n in lus],
            })
        programmes[key]["courseIds"] = [courses[(key, c[0])]["uuid"] for c in COURSES[key]]
    for key, short, name, *_rest in PROGRAMMES:
        oer = b.add("curriculum-plan", {
            "name": f"Onderwijs- en examenregeling {short} {YEAR}", "kind": "oer", "formula": "all-must-pass",
            "requiredCourseIds": programmes[key]["courseIds"], "electiveCourseIds": [], "components": [],
            "gradeScaleId": scale["uuid"], "passRules": [{"componentId": None, "minValue": PASS}],
            "periods": [{"periodId": bl[0], "label": bl[1], "startDate": bl[2].isoformat(), "endDate": bl[3].isoformat()} for bl in BLOCKS],
            "lifecycle": "published",
        })
        programmes[key]["curriculumPlanId"] = oer["uuid"]
        for ckey, year, period, ects, cname, desc, comps, lus, _docent in COURSES[key]:
            label, start, end = period_meta[period]
            plans[(key, ckey)] = b.add("curriculum-plan", {
                "name": f"Toetsplan {cname} {YEAR}", "kind": "oer", "formula": "best-of-n",
                "requiredCourseIds": [courses[(key, ckey)]["uuid"]], "electiveCourseIds": [],
                "components": [{"componentId": cid, "label": lab, "weight": w, "period": period, "kind": kind} for cid, lab, kind, w in comps],
                "gradeScaleId": scale["uuid"], "passRules": [{"componentId": None, "minValue": PASS}],
                "periods": [{"periodId": period, "label": label, "startDate": start.isoformat(), "endDate": end.isoformat()}],
                "lifecycle": "published",
            })
            courses[(key, ckey)]["curriculumPlanId"] = plans[(key, ckey)]["uuid"]
    course_meta = {(key, c[0]): c for key in COURSES for c in COURSES[key]}

    # --- staff ----------------------------------------------------------------
    docents: dict[str, list[str]] = {}
    staff_rows = []
    n = 0
    for key, *_rest in PROGRAMMES:
        docents[key] = []
        for i in range(6):
            n += 1
            nc = f"he-docent-{n:02d}"
            docents[key].append(nc)
            roles = ["teacher", "mentor"] if i < 4 else ["teacher"]
            qual = ["Basiskwalificatie didactische bekwaamheid (BDB)", "Basiskwalificatie examinering (BKE)"]
            if i == 3:
                qual.append("Seniorkwalificatie examinering (SKE)")
            if i < 4:
                qual.append(["Studieloopbaanbegeleider propedeuse", "Studieloopbaanbegeleider jaar 2", "Stagecoördinator", "Afstudeercoördinator"][i])
            staff_rows.append((nc, roles, qual, WEEKDAYS if i % 2 == 0 else WEEKDAYS[:4]))
    advisers = {}
    for i, (key, short, *_rest) in enumerate(PROGRAMMES, start=1):
        advisers[key] = f"he-studieadviseur-{i:02d}"
        staff_rows.append((advisers[key], ["coordinator"], [f"Studieadviseur {short}"], ["monday", "tuesday", "thursday", "friday"]))
    chairs = {"zuid": "he-examencommissie-01", "noord": "he-examencommissie-02"}
    staff_rows += [
        (chairs["zuid"], ["coordinator"], ["Voorzitter examencommissie Gezondheid en Welzijn", "Seniorkwalificatie examinering (SKE)"], ["tuesday", "thursday"]),
        (chairs["noord"], ["coordinator"], ["Voorzitter examencommissie Techniek en ICT", "Seniorkwalificatie examinering (SKE)"], ["monday", "wednesday"]),
        ("he-directeur-01", ["administrator"], ["Faculteitsdirecteur Gezondheid en Welzijn"], WEEKDAYS),
        ("he-directeur-02", ["administrator"], ["Faculteitsdirecteur Techniek en ICT"], WEEKDAYS),
        ("he-decaan-01", ["coordinator"], ["Studentendecaan, studeren met een functiebeperking"], ["monday", "wednesday", "thursday"]),
        ("he-administratie-01", ["administrator"], ["Studentenadministratie"], WEEKDAYS),
        ("he-toetsbureau-01", ["support-staff"], ["Toetscoördinator digitale toetsing en itembanken"], ["tuesday", "wednesday", "thursday"]),
        ("he-surveillant-01", ["support-staff"], ["Surveillant digitale toetsing"], ["monday", "tuesday", "wednesday", "thursday"]),
    ]
    for nc, roles, qual, work in staff_rows:
        b.add("staff", {"ncUserId": nc, "roles": roles, "qualifications": qual, "workingDays": work})

    def teacher_of(key: str, ckey: str) -> str:
        return docents[key][course_meta[(key, ckey)][8]]

    def slb_of(key: str, year: int) -> str:
        return docents[key][year - 1]

    # --- students ---------------------------------------------------------------
    used_names = set()
    students = []
    for key, short, name, code, fac, *_rest in PROGRAMMES:
        offset = PROGRAMMES[[p[0] for p in PROGRAMMES].index(key)][9]
        for year, count in STUDENTS_PER_YEAR.items():
            for _ in range(count):
                man = rng.random() < (0.35 if key in ("VPK", "SW") else 0.8)
                while True:
                    given = rng.choice(MEN if man else WOMEN)
                    family = rng.choice(SURNAME_HEAD) + rng.choice(SURNAME_TAIL)
                    if (given, family) not in used_names:
                        used_names.add((given, family))
                        break
                older = rng.random() < 0.18
                birth_year = 2007 - year + 1 - (rng.randint(2, 5) if older else rng.randint(0, 1))
                birth = dt.date(birth_year, 1, 1) + dt.timedelta(days=rng.randrange(365))
                students.append({"programme": key, "year": year, "man": man, "given": given, "family": family, "birth": birth,
                                 "mbo": older, "ability": rng.gauss(0, 1) + offset, "absence": rng.random()})
    students.sort(key=lambda s: ([p[0] for p in PROGRAMMES].index(s["programme"]), s["year"], s["family"], s["given"]))
    for n, s in enumerate(students, start=1):
        s["nc"] = f"he-student-{n:03d}"
        emergency = []
        if rng.random() < 0.3:
            emergency = [{"name": f"{rng.choice(PARENTS)} {s['family']}", "relationship": "ouder", "phone": f"06-0000{rng.randint(1000, 9999)}", "priority": 1}]
        s["profile"] = b.add("learner-profile", {
            "ncUserId": s["nc"], "givenName": s["given"], "familyName": s["family"], "birthDate": s["birth"].isoformat(),
            "schoolId": school["uuid"], "eduPersonAffiliation": ["student"], "roles": ["learner"],
            "parentIds": [], "guardianRefs": [],
            "address": {"street": rng.choice(STREETS), "houseNumber": str(rng.randint(1, 180)),
                        "postalCode": f"06{rng.randint(10, 39)} {rng.choice('ABDEGHKLMNPRSTWZ')}{rng.choice('ABDEGHKLMNPRSTWZ')}",
                        "city": rng.choice(TOWNS), "country": "NL"},
            "emergencyContacts": emergency, "lifecycle": "active",
        })
    by_group: dict[tuple[str, int], list[dict]] = {}
    for s in students:
        by_group.setdefault((s["programme"], s["year"]), []).append(s)
    # One first-year student per programme stops in January, before the BSA
    # applies: the weakest one.
    for key, *_rest in PROGRAMMES:
        weakest = min(by_group[(key, 1)], key=lambda s: s["ability"])
        weakest["withdrawn"] = True

    # --- cohorts ------------------------------------------------------------------
    cohorts = {}
    period_label = {1: "Studiejaar 1 (propedeuse)", 2: "Studiejaar 2", 3: "Studiejaar 3", 4: "Studiejaar 4"}
    for key, short, name, code, fac, *_rest in PROGRAMMES:
        for year in (1, 2, 3, 4):
            intake = 2026 - year
            notes = None
            if year == 1:
                notes = "Propedeuse met bindend studieadvies. Werkgroepen hebben aanwezigheidsplicht."
            if year == 3:
                notes = "Eerste semester stage, tweede semester minor."
            cohorts[(key, year)] = b.add("cohort", {
                "name": f"{short} cohort {intake}", "programmeId": programmes[key]["uuid"], "teacherIds": [slb_of(key, year)],
                "learnerIds": [s["nc"] for s in by_group[(key, year)]], "period": period_label[year], "academicYear": YEAR,
                "lifecycle": "active", "locationId": faculties[fac]["uuid"], "notes": notes, "kind": "teaching",
            })

    # --- grades, simulated first so enrolments can carry their outcome ---------
    # Each entry spec: (student, course key, component, value, gradedAt, source).
    raw: dict[tuple[str, str, str], list[dict]] = {}

    def add_raw(s: dict, ckey: str, cid: str, value: float | None, when: dt.date, kind: str, **extra) -> dict:
        spec = {"student": s, "ckey": ckey, "cid": cid, "value": value, "when": when, "kind": kind} | extra
        raw.setdefault((s["nc"], ckey, cid), []).append(spec)
        return spec

    blocks = {bl[0]: bl for bl in BLOCKS}
    semesters = {sm[0]: sm for sm in SEMESTERS}
    results_day = {k: v[5] for k, v in blocks.items()} | {k: v[4] for k, v in semesters.items()}
    resit_day = {k: v[6] for k, v in blocks.items()} | {k: v[5] for k, v in semesters.items()}

    def draw(s: dict, base: float, spread: float = 0.75) -> float:
        return clamp_grade(base + 0.9 * s["ability"] + rng.gauss(0, spread))

    # Components graded by the item bank exam (block 1 kennistoets) and the
    # group project are simulated below; the rest here.
    for s in students:
        key = s["programme"]
        for ckey, year, period, ects, cname, desc, comps, lus, _docent in COURSES[key]:
            if year != s["year"]:
                continue
            if s.get("withdrawn") and ckey != "B1":
                continue
            for cid, _label, _kind, _w in comps:
                if (ckey, cid) in (("B1", "kennistoets"), ("B4", "projectverslag")):
                    continue
                if ckey == "STAGE":
                    add_raw(s, ckey, cid, draw(s, 7.2, 0.6), results_day[period], "portfolio")
                    continue
                add_raw(s, ckey, cid, draw(s, 6.7 if year == 1 else 7.0), results_day[period], "manual")

    # --- enrolments ---------------------------------------------------------------
    # Placeholders now, outcome filled in once final grades are known.
    enrolments = {}
    for s in students:
        key = s["programme"]
        cohort = cohorts[(key, s["year"])]
        for ckey, year, *_rest in COURSES[key]:
            if year != s["year"]:
                continue
            enrolments[(s["nc"], ckey)] = b.add("enrolment", {
                "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"], "courseId": courses[(key, ckey)]["uuid"],
                "source": "admission" if year == 1 else "bulk", "cohortId": cohort["uuid"], "lifecycle": "active",
                "locationId": cohort["locationId"], "reason": None,
            })

    for key, *_rest in PROGRAMMES:
        for ckey, year, *_rest2 in COURSES[key]:
            b.add("subjectteacherassignment", {"cohortId": cohorts[(key, year)]["uuid"], "courseId": courses[(key, ckey)]["uuid"],
                                               "teacherId": teacher_of(key, ckey)})

    # --- werkgroep sessions and attendance (first year) -----------------------
    sessions = []
    for key, short, name, code, fac, weekday, room_key, *_rest in PROGRAMMES:
        cohort = cohorts[(key, 1)]
        for bid, _label, _start, _end, weeks, *_r in BLOCKS:
            for monday in weeks:
                day = monday + dt.timedelta(days=weekday)
                if day in off:
                    continue
                course = courses[(key, bid)]
                sessions.append((key, bid, day, b.add("session", {
                    "cohortId": cohort["uuid"], "courseId": course["uuid"],
                    "title": f"Werkgroep {course['name']}, {DAG[day.weekday()]} {day.day} {MAAND[day.month - 1]} {day.year}",
                    "startsAt": stamp(day, 9, 0), "endsAt": stamp(day, 12, 0),
                    "location": rooms[room_key]["name"], "roomId": rooms[room_key]["uuid"], "lifecycle": "completed",
                })))
    exam_sessions = {}
    for key, short, name, code, fac, weekday, room_key, exam_day, resit_date, _off in PROGRAMMES:
        hall = rooms["tentamenzuid" if fac == "zuid" else "tentamennoord"]
        course = courses[(key, "B1")]
        cohort = cohorts[(key, 1)]
        exam_sessions[(key, "main")] = b.add("session", {
            "cohortId": cohort["uuid"], "courseId": course["uuid"], "title": f"Kennistoets {course['name']}",
            "startsAt": stamp(exam_day, 9, 30), "endsAt": stamp(exam_day, 11, 30),
            "location": hall["name"], "roomId": hall["uuid"], "lifecycle": "completed",
        })
        exam_sessions[(key, "resit")] = b.add("session", {
            "cohortId": cohort["uuid"], "courseId": course["uuid"], "title": f"Herkansing kennistoets {course['name']}",
            "startsAt": stamp(resit_date, 13, 30), "endsAt": stamp(resit_date, 15, 30),
            "location": hall["name"], "roomId": hall["uuid"], "lifecycle": "completed",
        })
    ict_exam_day = PROGRAMMES[2][7]
    exam_sessions[("ICT", "adapted")] = b.add("session", {
        "cohortId": cohorts[("ICT", 1)]["uuid"], "courseId": courses[("ICT", "B1")]["uuid"],
        "title": f"Kennistoets {courses[('ICT', 'B1')]['name']}, aangepaste afname",
        "startsAt": stamp(ict_exam_day, 9, 30), "endsAt": stamp(ict_exam_day, 12, 0),
        "location": rooms["stilte"]["name"], "roomId": rooms["stilte"]["uuid"], "lifecycle": "completed",
    })

    session_by_cohort: dict[str, list[tuple[str, dt.date, dict]]] = {}
    for key, bid, day, sess in sessions:
        session_by_cohort.setdefault(key, []).append((bid, day, sess))
    marks: dict[str, dict[str, tuple[str, str, int]]] = {}
    for s in by_group_all(by_group, 1):
        key = s["programme"]
        own = [(bid, day, sess) for bid, day, sess in session_by_cohort[key] if not s.get("withdrawn") or day < WITHDRAWAL_DAY]
        count = rng.choices([0, 1, 2, 3, 4], weights=[42, 32, 16, 7, 3])[0]
        if s.get("withdrawn"):
            count = 5
        if s["absence"] > 0.93:
            count += 2
        chosen = rng.sample(own, min(count, len(own)))
        marks[s["nc"]] = {}
        for bid, day, sess in chosen:
            roll = rng.random()
            if roll < 0.5:
                marks[s["nc"]][sess["uuid"]] = ("absent-excused", rng.choice(["Ziek gemeld", "Ziek gemeld via het studentportaal", "Huisartsbezoek"]), 0)
            elif roll < 0.62:
                marks[s["nc"]][sess["uuid"]] = ("absent-unexcused", "Afwezig zonder bericht", 0)
            else:
                late = rng.choice([10, 15, 20, 30])
                marks[s["nc"]][sess["uuid"]] = ("late", f"{late} minuten te laat", 180 - late)

    session_info = {sess["uuid"]: (key, bid, day, sess) for key, bid, day, sess in sessions}
    excuses: dict[tuple[str, str], dict] = {}
    excused = [(nc, su) for nc in sorted(marks) for su, m in sorted(marks[nc].items()) if m[0] == "absent-excused"]
    student_by_nc = {s["nc"]: s for s in students}
    for nc, su in rng.sample(excused, min(24, len(excused))):
        s = student_by_nc[nc]
        key, bid, day, _sess = session_info[su]
        excuses[(nc, su)] = b.add("excuse-request", {
            "learnerId": nc, "learnerRef": s["profile"]["uuid"], "submittedBy": nc, "submittedByRef": s["profile"]["uuid"],
            "dateFrom": day.isoformat(), "dateTo": day.isoformat(), "reason": rng.choice(["Griep", "Koorts", "Migraine", "Buikgriep"]),
            "reasonKind": "illness", "submittedAuthLevel": "basic", "decidedBy": slb_of(key, 1),
            "decidedAt": stamp(day, 14, 0), "decisionNote": None, "lifecycle": "approved",
        })
    records: dict[str, list[dict]] = {}
    for nc in sorted(marks, key=lambda x: int(x.rsplit("-", 1)[1])):
        s = student_by_nc[nc]
        for su in sorted(marks[nc], key=lambda u: session_info[u][2]):
            key, bid, day, sess = session_info[su]
            status, reason, minutes = marks[nc][su]
            rec = b.add("attendance-record", {
                "sessionId": su, "learnerId": nc, "learnerRef": s["profile"]["uuid"], "cohortId": cohorts[(key, 1)]["uuid"],
                "status": status, "minutesAttended": minutes, "markedBy": teacher_of(key, bid),
                "markedAt": stamp(day, 9, 20 if status == "late" else 10), "reason": reason,
                "excuseRequestId": excuses[(nc, su)]["uuid"] if (nc, su) in excuses else None,
            })
            records.setdefault(nc, []).append(rec | {"_block": bid})
    threshold = b.add("attendance-threshold", {
        "name": "Aanwezigheidsplicht werkgroepen: minimaal 80 procent per blok", "kind": "college-aanwezigheid", "scope": "per-learner",
        "cohortId": None, "window": {"type": "fixed-term", "weeks": None, "termId": "blok"}, "metric": "attendance-percent-below",
        "limit": 80, "onCross": {"notify": True, "notifyRoles": ["mentor", "coordinator"], "createFlag": True, "dataExchangeTarget": None},
        "active": True, "lifecycle": "active",
    })
    for s in by_group_all(by_group, 1):
        key = s["programme"]
        for bid, _label, start, end, *_r in BLOCKS:
            held = [sess for b_, day, sess in session_by_cohort[key] if b_ == bid and (not s.get("withdrawn") or day < WITHDRAWAL_DAY)]
            if not held:
                continue
            absent = [r for r in records.get(s["nc"], []) if r["_block"] == bid and r["status"].startswith("absent")]
            percent = php_round(100 * (len(held) - len(absent)) / len(held), 1)
            if percent >= 80:
                continue
            last = max(session_info[r["sessionId"]][2] for r in absent)
            b.add("attendance-flag", {
                "learnerId": s["nc"], "attendanceThresholdId": threshold["uuid"], "cohortId": cohorts[(key, 1)]["uuid"],
                "windowStart": start.isoformat(), "windowEnd": end.isoformat(), "metricValue": percent,
                "breachingRecordIds": [r["uuid"] for r in absent], "mentorId": slb_of(key, 1),
                "interventions": [{"recordedBy": slb_of(key, 1), "recordedAt": stamp(last + dt.timedelta(days=2), 15, 0),
                                   "note": "Gesprek over de gemiste werkgroepen; de student maakt een vervangende opdracht.",
                                   "lifecycleAtRecording": "open"}],
                "lifecycle": "in-handling" if s.get("withdrawn") else "resolved",
            })

    # --- item banks, the block 1 knowledge test, statistics --------------------
    accommodations = {}
    extra_time_pool = [s for s in by_group_all(by_group, 1) if not s.get("withdrawn")]
    rng.shuffle(extra_time_pool)
    exempt_ict = next(s for s in by_group[("ICT", 1)] if s["mbo"] and not s.get("withdrawn"))
    adapted_ict = next(s for s in by_group[("ICT", 1)] if s is not exempt_ict and not s.get("withdrawn") and not s["mbo"])
    extra_time = [s for s in extra_time_pool if s is not exempt_ict and s is not adapted_ict][:9]

    bank_items, exams, results_by_exam = {}, {}, {}
    for key, short, name, code, fac, *_rest in PROGRAMMES:
        course = courses[(key, "B1")]
        bank = b.add("item-bank", {"name": f"Itembank {course['name']}", "description": f"Kennisvragen voor de kennistoets van {course['name']} in {short}.",
                                   "subject": course["name"], "itemIds": [], "lifecycle": "published"})
        items = []
        for k, (itype, stem, options, answer, difficulty) in enumerate(ITEMS[key], start=1):
            open_q = itype == "open"
            item = b.add("item", {
                "itemBankId": bank["uuid"], "title": f"Kennistoets {short}, vraag {k}",
                "interactionType": {"choice": "choice", "text": "textEntry", "open": "extendedText"}[itype], "qtiBody": "",
                "correctResponse": None if open_q else {"value": answer}, "maxScore": 4.0 if open_q else 1.0,
                "subjectTags": [short, course["name"]],
                "competencyIds": [outcomes[key][4]["uuid"]] if open_q else [outcomes[key][0]["uuid"]],
                "difficulty": None if difficulty is None else php_round(logistic(difficulty), 2),
                "variantGroupId": None, "lifecycle": "published",
            })
            item["qtiBody"] = qti(item["slug"], item["title"], itype, stem, options, answer, item["maxScore"])
            items.append({"obj": item, "type": itype, "options": options, "answer": answer,
                          "b": difficulty, "max": 4.0 if open_q else 1.0})
        bank["itemIds"] = [i["obj"]["uuid"] for i in items]
        bank_items[key] = items
    for key, short, name, code, fac, weekday, room_key, exam_day, resit_date, _off in PROGRAMMES:
        course = courses[(key, "B1")]
        cohort = cohorts[(key, 1)]
        items = bank_items[key]
        main_set = items[0:11] + [items[16]]
        resit_set = items[5:16] + [items[17]]
        for variant, item_set, when, title in (
            ("main", main_set, exam_day, f"Kennistoets {course['name']}"),
            ("resit", resit_set, resit_date, f"Herkansing kennistoets {course['name']}"),
        ) + ((("adapted", main_set, exam_day, f"Kennistoets {course['name']}, aangepaste afname"),) if key == "ICT" else ()):
            sess = exam_sessions[(key, variant)]
            exams[(key, variant)] = {"items": item_set, "obj": b.add("exam", {
                "title": title, "description": "Digitale kennistoets met gesloten vragen en een open vraag. Cesuur 55 procent.",
                "courseId": course["uuid"], "sessionId": sess["uuid"], "cohortId": cohort["uuid"],
                "curriculumPlanComponentId": "kennistoets", "itemRefs": [{"itemId": i["obj"]["uuid"], "points": i["max"]} for i in item_set],
                "itemSelectionMode": "fixed", "shuffleItemOrder": False, "shuffleAnswerOptions": False,
                "scoringScheme": "passMark", "passMark": 8.25, "timeLimitMinutes": 120 if variant == "adapted" else 90,
                "maxAttempts": 1, "keepScore": "best", "availableFrom": sess["startsAt"], "availableUntil": sess["endsAt"],
                "accessCode": None,
                "proctoring": {"nativeTestMode": True, "navigationLock": True, "flagReviewMode": "manual", "lockdownBrowser": False, "recordWebcam": False} if variant == "adapted" else None,
                "gradeEntryComponentId": "kennistoets", "competencyIds": [outcomes[key][0]["uuid"], outcomes[key][4]["uuid"]],
                "lifecycle": "closed",
            })}
    for s in extra_time:
        accommodations[s["nc"]] = b.add("exam-accommodation", {
            "learnerId": s["nc"], "submittedBy": s["nc"], "assessmentId": None, "accommodationKind": "extra-time-percentage", "value": 25,
            "evidenceRef": None, "approvedBy": "he-decaan-01", "lifecycle": "active",
        })
    b.add("exam-accommodation", {
        "learnerId": adapted_ict["nc"], "submittedBy": adapted_ict["nc"], "assessmentId": exams[("ICT", "adapted")]["obj"]["uuid"],
        "accommodationKind": "separate-room", "value": None, "evidenceRef": None, "approvedBy": "he-decaan-01", "lifecycle": "active",
    })

    def sit(s: dict, key: str, variant: str, boost: float) -> dict:
        exam = exams[(key, variant)]
        sess_start = dt.datetime.fromisoformat(exam["obj"]["availableFrom"])
        responses, score, drawn = [], 0.0, []
        for i in exam["items"]:
            item = i["obj"]
            drawn.append({"itemId": item["uuid"], "points": i["max"]})
            if i["type"] == "open":
                points = float(max(0, min(4, round(2.6 + 1.1 * (s["ability"] + boost) + rng.gauss(0, 0.8)))))
                responses.append({"itemId": item["uuid"], "response": {"value": rng.choice(OPEN_ANSWERS)}, "autoScore": None, "manualScore": points})
                score += points
                continue
            if i["b"] is None:
                p = logistic(-0.9 * (s["ability"] + boost))
            else:
                p = logistic(1.7 * (s["ability"] + boost - i["b"]))
            correct = rng.random() < p
            if i["type"] == "choice":
                wrong = [letter for letter in "ABCD" if letter != i["answer"]]
                value = i["answer"] if correct else rng.choice(wrong)
            else:
                value = i["answer"] if correct else rng.choice(["weet ik niet", "", i["answer"] + "0"])
            responses.append({"itemId": item["uuid"], "response": {"value": value}, "autoScore": 1.0 if correct else 0.0, "manualScore": None})
            score += 1.0 if correct else 0.0
        minutes = rng.randint(45, 88) if s["nc"] not in accommodations else rng.randint(70, 110)
        result = b.add("assessment-result", {
            "assessmentId": exam["obj"]["uuid"], "learnerId": s["nc"], "attemptNumber": 1, "responses": responses, "drawnItemRefs": drawn,
            "startedAt": sess_start.isoformat(), "submittedAt": (sess_start + dt.timedelta(minutes=minutes)).isoformat(),
            "proctoringSessionId": None, "teacherIds": [teacher_of(key, "B1")], "gradeEntryId": None, "lifecycle": "graded",
        })
        results_by_exam.setdefault((key, variant), []).append((s, result, score))
        return {"result": result, "score": score, "grade": exam_grade(score, 15.0, 8.25)}

    for key, *_rest in PROGRAMMES:
        for s in by_group[(key, 1)]:
            if s is exempt_ict:
                continue
            variant = "adapted" if s is adapted_ict else "main"
            sitting = sit(s, key, variant, 0.5)
            add_raw(s, "B1", "kennistoets", sitting["grade"], results_day["B1"], "assessment-result", result=sitting["result"])
    proctored = results_by_exam[("ICT", "adapted")][0][1]
    started = dt.datetime.fromisoformat(proctored["startedAt"])
    proctoring = b.add("proctoring-session", {
        "assessmentResultId": proctored["uuid"], "learnerId": adapted_ict["nc"], "provider": "native-test-mode", "providerSessionId": None,
        "status": "ended", "recordedArtefactRefs": [],
        "flags": [{"flagId": "flag-1", "kind": "window-blur", "occurredAt": (started + dt.timedelta(minutes=41)).isoformat(),
                   "severity": "low", "reviewDecision": "allowed", "reviewedBy": "he-surveillant-01",
                   "reviewedAt": stamp(ict_exam_day, 12, 30)}],
        "lifecycle": "ended",
    })
    proctored["proctoringSessionId"] = proctoring["uuid"]

    # Item statistics and reliability for the main sittings, computed from the
    # stored responses the way ItemAnalysisService does (no rounding; a
    # degenerate correlation is 0.0; the top and bottom 27 percent by total).
    flag_states = ["revised", "acknowledged", "dismissed", "open"]
    flag_n = 0
    for key, *_rest in PROGRAMMES:
        exam = exams[(key, "main")]
        rows = results_by_exam[(key, "main")]

        def scored(response: dict) -> float:
            return response["manualScore"] if response["manualScore"] is not None else response["autoScore"]

        totals = [sum(scored(r) for r in res["responses"]) for _s, res, _sc in rows]
        n_rows = len(rows)
        group = min(max(1, int(php_round(n_rows * 0.27, 0))), n_rows // 2)
        order = sorted(range(n_rows), key=lambda i: -totals[i])
        high, low = order[:group], order[-group:]
        matrix = []
        computed = stamp(results_day["B1"], 8, 0)
        for idx, i in enumerate(exam["items"]):
            scores = [scored(res["responses"][idx]) for _s, res, _sc in rows]
            matrix.append(scores)
            corr = pearson(scores, [t - sc for t, sc in zip(totals, scores)])
            p_value = sum(1 for sc in scores if sc >= i["max"] - 1e-9) / n_rows
            distractors = None
            if i["type"] == "choice":
                picks = [res["responses"][idx]["response"]["value"] for _s, res, _sc in rows]
                distractors = [{"optionId": letter,
                                "selectedByHighGroup": sum(1 for j in high if picks[j] == letter),
                                "selectedByLowGroup": sum(1 for j in low if picks[j] == letter)}
                               for letter in "ABCD"]
            stat = b.add("item-statistics", {
                "itemId": i["obj"]["uuid"], "assessmentId": exam["obj"]["uuid"], "sampleSize": n_rows, "pValue": p_value,
                "itemTotalCorrelation": corr, "distractorAnalysis": distractors, "insufficientData": n_rows < 20, "computedAt": computed,
            })
            flags_for = []
            for reason in flag_reasons(p_value, corr):
                flags_for.append((reason, flag_states[flag_n % len(flag_states)]))
                flag_n += 1
            if flags_for:
                stat["_flags"] = flags_for
        k = len(exam["items"])
        alpha = (k / (k - 1)) * (1 - sum(variance(col) for col in matrix) / variance(totals))
        b.add("assessment-reliability", {"assessmentId": exam["obj"]["uuid"], "sampleSize": n_rows, "itemCount": k,
                                         "cronbachAlpha": alpha, "insufficientData": False, "computedAt": computed})

    # Resits of the knowledge test for everyone whose block 1 course would fail.
    b1_components = {c[0]: {"weight": c[3]} for c in Y1_COMPONENTS}
    for key, short, name, code, fac, weekday, room_key, exam_day, resit_date, _off in PROGRAMMES:
        for s in by_group[(key, 1)]:
            if s.get("withdrawn") or s is exempt_ict:
                continue
            first = raw[(s["nc"], "B1", "kennistoets")][0]
            other = raw[(s["nc"], "B1", "beroepsproduct")][0]
            value, _bd = aggregate([{"componentId": "kennistoets", "value": first["value"]}, {"componentId": "beroepsproduct", "value": other["value"]}], b1_components)
            if value >= PASS or first["value"] >= PASS or rng.random() > 0.9:
                continue
            sitting = sit(s, key, "resit", 0.8)
            add_raw(s, "B1", "kennistoets", sitting["grade"], resit_day["B1"], "assessment-result", result=sitting["result"])
    for key, *_rest in PROGRAMMES:
        exam = exams[(key, "resit")]
        rows = results_by_exam.get((key, "resit"), [])
        b.add("assessment-reliability", {"assessmentId": exam["obj"]["uuid"], "sampleSize": len(rows), "itemCount": len(exam["items"]),
                                         "cronbachAlpha": None, "insufficientData": True, "computedAt": stamp(resit_day["B1"], 8, 0)})
    for stat in b.buckets["item-statistics"]:
        for reason, state in stat.pop("_flags", []):
            b.add("item-revision-flag", {"itemId": stat["itemId"], "itemStatisticsId": stat["uuid"], "reason": reason,
                                         "pValueAtFlag": stat["pValue"], "itemTotalCorrelationAtFlag": stat["itemTotalCorrelation"],
                                         "flaggedAt": stat["computedAt"], "lifecycle": state})

    # --- the peer reviewed group project (block 4) -------------------------------
    project_grade: dict[str, tuple[float, dict]] = {}
    submissions_by_key = {}
    for key, short, *_rest in PROGRAMMES:
        course = courses[(key, "B4")]
        levels = [("onvoldoende", "Onvoldoende", 2.0), ("voldoende", "Voldoende", 6.0), ("goed", "Goed", 8.0), ("uitstekend", "Uitstekend", 10.0)]
        criteria = [("probleemanalyse", "Probleemanalyse"), ("onderbouwing", "Onderbouwing van keuzes"), ("resultaat", "Resultaat voor de opdrachtgever"), ("samenwerking", "Samenwerking en planning")]
        rubric = b.add("rubric", {
            "name": f"Beoordelingsrubric {course['name']}", "description": "Vier criteria, elk op vier niveaus.",
            "criteria": [{"criterionId": cid, "label": lab, "weight": 1, "levels": [{"levelId": lid, "label": ll, "points": pts} for lid, ll, pts in levels]} for cid, lab in criteria],
            "maxPoints": 40, "lifecycle": "active",
        })
        assignment = b.add("assignment", {
            "title": f"Projectverslag {course['name']}",
            "instructions": "Lever als projectgroep één verslag in. Beoordeel daarna de verslagen van twee andere groepen met dezelfde rubric.",
            "courseId": course["uuid"], "cohortId": cohorts[(key, 1)]["uuid"], "curriculumPlanComponentId": "projectverslag",
            "dueAt": stamp(dt.date(2026, 6, 12), 17, 0), "maxPoints": 40, "allowLateSubmission": False, "latePenaltyPercent": 0,
            "rubricId": rubric["uuid"], "groupSubmission": True, "visibleFrom": stamp(dt.date(2026, 5, 4), 8, 0),
            "competencyIds": [outcomes[key][6]["uuid"], outcomes[key][7]["uuid"]], "peerReviewEnabled": True, "selfAssessmentEnabled": False,
            "peerReviewersPerSubmission": 2, "peerReviewAnonymity": "blind", "peerReviewAllocationStrategy": "round-robin",
            "peerReviewDueAt": stamp(dt.date(2026, 6, 19), 17, 0), "peerReviewWeightPercent": 20, "lifecycle": "closed",
        })
        members = [s for s in by_group[(key, 1)] if not s.get("withdrawn")]
        members = sorted(members, key=lambda s: s["nc"])
        rng.shuffle(members)
        groups = [members[i:i + 4] for i in range(0, len(members), 4)]
        if len(groups) > 1 and len(groups[-1]) < 3:
            groups[-2].extend(groups.pop())
        subs = []
        for g_index, group in enumerate(groups):
            mean = sum(s["ability"] for s in group) / len(group)
            scores = []
            for cid, _lab in criteria:
                q = mean + rng.gauss(0.3, 0.7)
                lid, _ll, pts = levels[0 if q < -1.2 else 1 if q < 0.2 else 2 if q < 1.2 else 3]
                scores.append({"criterionId": cid, "levelId": lid, "points": pts})
            total = sum(x["points"] for x in scores)
            sub = b.add("submission", {
                "assignmentId": assignment["uuid"], "learnerIds": [s["nc"] for s in group], "learnerRefs": [s["profile"]["uuid"] for s in group],
                "attachmentRefs": [], "submittedAt": stamp(dt.date(2026, 6, 12), rng.randint(9, 16), rng.choice([5, 20, 35, 50])),
                "feedbackText": ("Sterk verslag met een heldere analyse." if total >= 30 else "Voldoende verslag; werk de onderbouwing verder uit." if total >= 22 else "De analyse is te dun. Werk het verslag om in de herkansing."),
                "gradeEntryId": None, "rubricScores": scores, "proposedGrade": total, "lifecycle": "returned", "plagiarismStatus": "not-requested",
            })
            subs.append((group, sub))
            for s in group:
                project_grade[s["nc"]] = (clamp_grade(max(1.0, total / 4)), sub)
        submissions_by_key[key] = (assignment, rubric, subs)
    for key, *_rest in PROGRAMMES:
        assignment, rubric, subs = submissions_by_key[key]
        reviews_for = {}
        for g_index, (group, sub) in enumerate(subs):
            for step in (1, 2):
                reviewer_group = subs[(g_index + step) % len(subs)][0]
                reviewer = reviewer_group[(g_index + step) % len(reviewer_group)]
                scores = []
                for criterion in sub["rubricScores"]:
                    shift = rng.choice([-1, 0, 0, 1])
                    points = [2.0, 6.0, 8.0, 10.0]
                    idx = max(0, min(3, points.index(criterion["points"]) + shift))
                    scores.append({"criterionId": criterion["criterionId"], "levelId": ["onvoldoende", "voldoende", "goed", "uitstekend"][idx], "points": points[idx]})
                review = b.add("peer-review", {
                    "assignmentId": assignment["uuid"], "submissionId": sub["uuid"], "reviewerId": reviewer["nc"], "rubricScores": scores,
                    "totalScore": sum(x["points"] for x in scores),
                    "comments": rng.choice([
                        "Duidelijke opbouw. De keuzes in hoofdstuk drie mogen beter onderbouwd.",
                        "Goed dat jullie de opdrachtgever hebben gesproken. De planning ontbreekt nog.",
                        "Het resultaat is bruikbaar. Voeg bronnen toe bij de analyse.",
                        "Prettig leesbaar verslag. De conclusie herhaalt vooral de inleiding.",
                    ]),
                    "lifecycle": "released",
                })
                reviews_for.setdefault(sub["uuid"], []).append(review)
        for group, sub in subs:
            reviews = reviews_for[sub["uuid"]]
            b.add("peer-feedback-summary", {
                "submissionId": sub["uuid"], "assignmentId": assignment["uuid"], "reviewCount": len(reviews),
                "averageScore": sum(r["totalScore"] for r in reviews) / len(reviews),
                "feedbackItems": [{"comments": r["comments"], "rubricScores": r["rubricScores"], "reviewerId": None} for r in reviews],
                "lastComputedAt": assignment["peerReviewDueAt"],
            })
    for s in by_group_all(by_group, 1):
        if s["nc"] in project_grade:
            value, sub = project_grade[s["nc"]]
            add_raw(s, "B4", "projectverslag", value, results_day["B4"], "assignment-submission", submission=sub)

    # --- internship portfolios (third year, semester 1) -----------------------
    assessors = {}
    for key, short, *_rest in PROGRAMMES:
        assessors[key] = []
        for org, domain, given, family in WORKPLACES[key]:
            assessors[key].append((org, b.add("external-assessor", {
                "givenName": given, "familyName": family, "email": f"{given.lower()}.{family.lower()}@{domain}",
                "organisationName": org, "active": True,
            })))
    templates = {}
    for key, short, *_rest in PROGRAMMES:
        lus = outcomes[key]
        templates[key] = b.add("portfolio-template", {
            "name": f"Stageportfolio {short} {YEAR}", "kind": "course-bound",
            "description": "Laat met bewijs en reflectie zien hoe je in de praktijk aan je leeruitkomsten werkte.",
            "sections": [
                {"sectionId": "praktijk", "label": "Beroepshandelen in de praktijk", "order": 1,
                 "helpText": "Beschrijf twee situaties waarin je zelfstandig handelde.",
                 "criteria": [{"criterionId": "praktijk-1", "label": "Plannen en uitvoeren", "description": lus[2]["description"], "competencyId": lus[2]["uuid"]},
                              {"criterionId": "praktijk-2", "label": "Samenwerken en communiceren", "description": lus[6]["description"], "competencyId": lus[6]["uuid"]}]},
                {"sectionId": "ontwikkeling", "label": "Professionele ontwikkeling", "order": 2,
                 "helpText": "Laat zien wat je leerde en wat je volgende stap is.",
                 "criteria": [{"criterionId": "ontwikkeling-1", "label": "Reflectie en leerdoelen", "description": lus[8]["description"], "competencyId": lus[8]["uuid"]}]},
            ],
            "rubricId": None, "lifecycle": "active",
        })
    portfolios = {}
    for s in by_group_all(by_group, 3):
        key = s["programme"]
        org, assessor = assessors[key][int(s["nc"].rsplit("-", 1)[1]) % 2]
        spec = raw[(s["nc"], "STAGE", "stageportfolio")][0]
        portfolio = b.add("portfolio", {
            "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"], "kind": "course-bound", "templateId": templates[key]["uuid"],
            "courseId": courses[(key, "STAGE")]["uuid"], "curriculumPlanId": plans[(key, "STAGE")]["uuid"],
            "curriculumPlanComponentId": "stageportfolio", "title": f"Stageportfolio {s['given']} {s['family']}",
            "description": f"Stage bij {org}.", "dueAt": stamp(dt.date(2026, 1, 23), 17, 0), "gradeValue": spec["value"],
            "gradeEntryId": None, "lifecycle": "graded",
        })
        portfolios[s["nc"]] = portfolio
        spec["portfolio"] = portfolio
        lus = outcomes[key]
        b.add("portfolio-entry", {
            "portfolioId": portfolio["uuid"], "learnerId": s["nc"], "title": "Reflectie: beroepshandelen in de praktijk", "evidenceKind": "reflection",
            "reflectionText": rng.choice([
                f"Bij {org} nam ik vanaf week zes zelfstandig taken over. Ik leerde vooraf te plannen en achteraf te controleren wat ik had afgesproken.",
                f"In mijn stage bij {org} merkte ik dat overleg met collega's mijn werk beter maakte. Ik vraag nu eerder feedback.",
                f"Bij {org} kreeg ik een eigen opdracht. Het lastigste was kiezen wat eerst moest; een weekplanning hielp.",
            ]),
            "sectionId": "praktijk", "criterionId": "praktijk-1", "competencyId": lus[2]["uuid"],
        })
        b.add("portfolio-entry", {
            "portfolioId": portfolio["uuid"], "learnerId": s["nc"], "title": "Reflectie: professionele ontwikkeling", "evidenceKind": "reflection",
            "reflectionText": rng.choice([
                "Mijn leerdoel was duidelijker communiceren. In de laatste evaluatie gaf mijn begeleider aan dat dat gelukt is. Mijn volgende doel is leiding nemen in overleg.",
                "Ik heb geleerd mijn eigen grenzen aan te geven. Voor de minor wil ik verder werken aan het onderbouwen van keuzes met literatuur.",
                "Ik ben zelfstandiger geworden. Ik wil nog beter leren omgaan met onverwachte situaties.",
            ]),
            "sectionId": "ontwikkeling", "criterionId": "ontwikkeling-1", "competencyId": lus[8]["uuid"],
        })
        b.add("portfolio-share", {
            "portfolioId": portfolio["uuid"], "entryIds": None, "sharedWithKind": "external-assessor", "sharedWithTeacherId": None,
            "sharedWithPracticalTrainerId": None, "sharedWithExternalAssessorId": assessor["uuid"], "sharedBy": s["nc"],
            "expiresAt": stamp(dt.date(2026, 2, 27), 23, 59), "lifecycle": "active",
        })

    # --- exam board cases -------------------------------------------------------
    exemption_granted = []
    wtb_exempt = next(s for s in by_group[("WTB", 1)] if s["mbo"] and not s.get("withdrawn"))
    sw_rejected = next(s for s in by_group[("SW", 1)] if s["mbo"] and not s.get("withdrawn"))
    for s, ckey, cid, grounds, desc, decided, granted, rationale in (
        (exempt_ict, "B1", "kennistoets", "prior-diploma", "Mbo-diploma Software developer (niveau 4) met de onderdelen programmeren en testen.",
         dt.date(2025, 10, 2), True, "Het mbo-diploma dekt de leerdoelen van de kennistoets programmeren aantoonbaar. Vrijstelling voor dit onderdeel; het beroepsproduct blijft verplicht."),
        (wtb_exempt, "B2", "kennistoets", "certificate", "Certificaat technisch tekenen en CAD van een eerdere hbo-opleiding, behaald in 2024.",
         dt.date(2025, 11, 27), True, "Het certificaat dekt de normen en aanzichten van de kennistoets. Vrijstelling voor dit onderdeel."),
        (sw_rejected, "B3", "beroepsproduct", "work-experience", "Twee jaar werkervaring als ambulant begeleider bij een zorgaanbieder.",
         dt.date(2026, 3, 5), False, "Werkervaring alleen toont de juridische kennis van dit beroepsproduct niet aan. De student kan het beroepsproduct maken."),
    ):
        key = s["programme"]
        case = b.add("exemption-case", {
            "learnerId": s["profile"]["uuid"], "curriculumPlanId": plans[(key, ckey)]["uuid"], "componentId": cid, "groundsKind": grounds,
            "groundsDescription": desc, "submittedAt": stamp(decided - dt.timedelta(days=21), 11, 0), "decisionRationale": rationale,
            "policyReference": f"Onderwijs- en examenregeling {YEAR}, paragraaf vrijstellingen", "decidedBy": chairs[PROGRAMMES[[p[0] for p in PROGRAMMES].index(key)][4]],
            "decidedAt": stamp(decided, 16, 0), "resultingGradeEntryId": None, "lifecycle": "granted" if granted else "rejected",
        })
        if granted:
            raw.pop((s["nc"], ckey, cid), None)
            exemption_granted.append(add_raw(s, ckey, cid, None, decided, "exemption", case=case))

    # Fraud: an ICT student copied part of a block 2 report without citing it.
    fraud_student = sorted((s for s in by_group[("ICT", 1)] if not s.get("withdrawn") and s is not exempt_ict and s is not adapted_ict),
                           key=lambda s: s["nc"])[3]
    contested = raw[(fraud_student["nc"], "B2", "beroepsproduct")][0]
    contested["value"] = max(contested["value"], 6.8)
    fraud = b.add("fraud-case", {
        "reporterId": teacher_of("ICT", "B2"), "accusedLearnerId": fraud_student["profile"]["uuid"], "sourceKind": "manual",
        "allegation": "Twee pagina's van het individuele verslag over het datamodel zijn letterlijk overgenomen van een openbare website, zonder bronvermelding.",
        "reportedAt": stamp(dt.date(2026, 2, 2), 10, 0), "hearingDate": stamp(dt.date(2026, 2, 19), 14, 0),
        "hearingRecords": [{"heldAt": stamp(dt.date(2026, 2, 19), 14, 0), "attendees": [fraud_student["nc"], teacher_of("ICT", "B2"), chairs["noord"]],
                            "notes": "De student erkent dat de passage is overgenomen en zegt de bronvermelding vergeten te zijn.", "evidenceRefs": []}],
        "verdict": "fraud-proven",
        "decisionRationale": "De overgenomen passage is omvangrijk en vormt de kern van het verslag. De examencommissie verklaart het beroepsproduct ongeldig; de student levert in de herkansing een nieuw verslag in.",
        "decidedBy": chairs["noord"], "decidedAt": stamp(dt.date(2026, 3, 2), 16, 0), "sanctionType": "resubmission-required",
        "sanctionDurationMonths": None, "sanctionScope": "single-assessment", "appealDeadline": stamp(dt.date(2026, 4, 13), 16, 0),
        "appealLodged": False, "appealOutcome": None, "lifecycle": "decided",
    })
    contested["kind"] = "manual"
    contested["lifecycle"] = "invalidated"
    contested["fraud"] = fraud
    add_raw(fraud_student, "B2", "beroepsproduct", clamp_grade(6.4 + 0.5 * fraud_student["ability"]), resit_day["B2"], "manual",
            comment="Nieuw verslag na het besluit van de examencommissie.")

    # --- resits and the outcome of every course ------------------------------
    def published(specs: list[dict], until: dt.date | None = None) -> list[dict]:
        rows = [x for x in specs if x.get("lifecycle", "published") == "published" and (until is None or x["when"] <= until)]
        return [{"componentId": x["cid"], "value": x["value"], "sourceKind": x["kind"], "period": course_meta[(x["student"]["programme"], x["ckey"])][2]} for x in rows]

    def course_specs(nc: str, key: str, ckey: str) -> list[dict]:
        out = []
        for cid, *_rest in course_meta[(key, ckey)][6]:
            out.extend(raw.get((nc, ckey, cid), []))
        return out

    def components_of(key: str, ckey: str) -> dict[str, dict]:
        return {c[0]: {"weight": c[3]} for c in course_meta[(key, ckey)][6]}

    for s in students:
        key = s["programme"]
        for ckey, year, period, *_rest in COURSES[key]:
            if year != s["year"] or s.get("withdrawn") or ckey == "STAGE":
                continue
            specs = course_specs(s["nc"], key, ckey)
            value, _bd = aggregate(published(specs), components_of(key, ckey))
            if value is None or value >= PASS or rng.random() > 0.9:
                continue
            for cid, *_c in course_meta[(key, ckey)][6]:
                if (ckey, cid) == ("B1", "kennistoets"):
                    continue  # the knowledge test resit ran through the item bank above
                firsts = [x for x in raw.get((s["nc"], ckey, cid), []) if x.get("lifecycle", "published") == "published"]
                if firsts and all(x["value"] < PASS for x in firsts):
                    add_raw(s, ckey, cid, clamp_grade(6.1 + 0.6 * s["ability"] + rng.gauss(0.2, 0.7)), resit_day[period], "manual")

    def passed_by(s: dict, ckey: str, until: dt.date | None) -> bool:
        """Passed at a moment: every component graded by then, and the engine's value at least 5.5."""
        key = s["programme"]
        pub = published(course_specs(s["nc"], key, ckey), until)
        if {row["componentId"] for row in pub} != set(components_of(key, ckey)):
            return False
        value, _bd = aggregate(pub, components_of(key, ckey))
        return value is not None and value >= PASS

    def ects(s: dict, until: dt.date | None = None) -> float:
        key = s["programme"]
        return float(sum(c[3] for c in COURSES[key] if c[1] == 1 and passed_by(s, c[0], until)))

    first_years = [s for s in by_group_all(by_group, 1) if not s.get("withdrawn")]
    check_until = BSA_CHECK - dt.timedelta(days=1)
    warned = {s["nc"] for s in first_years if ects(s, check_until) < BSA_INTERIM_NORM}
    # A negative advice needs an earlier warning. A student who was on track in
    # February but ends below the norm passes the resit of the last block they
    # failed, so the set never shows an unwarned negative advice.
    for s in first_years:
        if s["nc"] in warned or ects(s) >= BSA_NORM:
            continue
        key = s["programme"]
        for ckey in ("B4", "B3"):
            if passed_by(s, ckey, None):
                continue
            for cid, *_c in course_meta[(key, ckey)][6]:
                specs = [x for x in raw[(s["nc"], ckey, cid)] if x.get("lifecycle", "published") == "published"]
                if max(x["value"] for x in specs) >= PASS:
                    continue
                resits = [x for x in specs if x["when"] == resit_day[ckey]]
                if resits:
                    resits[-1]["value"] = 7.0
                else:
                    add_raw(s, ckey, cid, 7.0, resit_day[ckey], "manual")
            break

    # --- grade entries and final grades ------------------------------------------
    entry_of_result, entry_of_portfolio = {}, {}
    finals: dict[tuple[str, str], dict] = {}
    for s in students:
        key = s["programme"]
        cohort = cohorts[(key, s["year"])]
        for ckey, year, period, *_rest in COURSES[key]:
            if year != s["year"]:
                continue
            specs = sorted(course_specs(s["nc"], key, ckey), key=lambda x: (x["when"], x["cid"]))
            for x in specs:
                source = x["kind"]
                # Only the reference that names this entry's source is written;
                # the others stay unset rather than carrying four nulls each.
                sources = {"submissionId": x.get("submission"), "assessmentResultId": x.get("result"), "exemptionCaseId": x.get("case"),
                           "fraudCaseId": x.get("fraud"), "portfolioId": x.get("portfolio")}
                fields = {
                    "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"], "curriculumPlanId": plans[(key, ckey)]["uuid"],
                    "componentId": x["cid"], "courseId": courses[(key, ckey)]["uuid"], "cohortId": cohort["uuid"], "sourceKind": source,
                } | {field: ref["uuid"] for field, ref in sources.items() if ref is not None} | {
                    "value": x["value"], "gradeScaleId": scale["uuid"], "period": period,
                    "grader": chairs[PROGRAMMES[[p[0] for p in PROGRAMMES].index(key)][4]] if source == "exemption" else teacher_of(key, ckey),
                    "gradedAt": stamp(x["when"], 16, 0) if source == "exemption" else stamp(x["when"], 10, 0),
                    "lifecycle": x.get("lifecycle", "published"),
                }
                if x.get("comment"):
                    fields["comment"] = x["comment"]
                entry = b.add("grade-entry", fields)
                if "result" in x:
                    x["result"]["gradeEntryId"] = entry["uuid"]
                if "portfolio" in x:
                    x["portfolio"]["gradeEntryId"] = entry["uuid"]
                if "case" in x:
                    x["case"]["resultingGradeEntryId"] = entry["uuid"]
                if "fraud" in x:
                    x["fraud"]["contestedGradeEntryId"] = entry["uuid"]
            pub = published(specs)
            if not pub:
                continue
            value, breakdown = aggregate(pub, components_of(key, ckey))
            last = max(x["when"] for x in specs if x.get("lifecycle", "published") == "published")
            finals[(s["nc"], ckey)] = b.add("final-grade", {
                "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"], "courseId": courses[(key, ckey)]["uuid"],
                "curriculumPlanId": plans[(key, ckey)]["uuid"], "gradeScaleId": scale["uuid"], "value": value,
                "passed": value is not None and value >= PASS, "breakdown": breakdown, "lastRecomputedAt": stamp(last, 10, 0),
            })

    # Enrolment outcomes.
    for (nc, ckey), enrolment in enrolments.items():
        s = student_by_nc[nc]
        final = finals.get((nc, ckey))
        if s.get("withdrawn") and final is None:
            enrolment["lifecycle"] = "withdrawn"
            enrolment["reason"] = f"Uitgeschreven per {WITHDRAWAL_DAY.day} januari 2026, voor de BSA-peildatum."
        elif final is not None and final["passed"]:
            enrolment["lifecycle"] = "completed"
        else:
            enrolment["lifecycle"] = "failed"
            enrolment["reason"] = "Onderdeel niet behaald, ook niet na de herkansing."

    # --- binding study advice ------------------------------------------------------
    trajectories, flags, warnings = {}, {}, {}
    for key, *_rest in PROGRAMMES:
        trajectories[key] = b.add("bsa-trajectory", {
            "programmeId": programmes[key]["uuid"], "academicYear": YEAR, "kind": "he-eerstejaar-bsa", "cohortId": cohorts[(key, 1)]["uuid"],
            "normEcts": BSA_NORM, "window": {"mode": "fixed-date", "date": BSA_CHECK.isoformat()}, "interimNormEcts": BSA_INTERIM_NORM,
            "onAtRisk": {"notify": True, "notifyRoles": ["study-advisor", "exam-board"], "createFlag": True}, "lifecycle": "active",
        })
    final_ects = {s["nc"]: ects(s) for s in first_years}
    for s in first_years:
        if s["nc"] not in warned:
            continue
        key = s["programme"]
        flags[s["nc"]] = b.add("bsa-progress-flag", {
            "learnerId": s["nc"], "programmeId": programmes[key]["uuid"], "bsaTrajectoryId": trajectories[key]["uuid"], "academicYear": YEAR,
            "ectsEarned": ects(s, check_until), "ectsRequiredAtCheck": BSA_INTERIM_NORM, "flaggedAt": stamp(BSA_CHECK, 8, 0),
            "lifecycle": "resolved" if final_ects[s["nc"]] >= BSA_NORM else "warned",
        })
    for s in first_years:
        if s["nc"] not in warned:
            continue
        key = s["programme"]
        warnings[s["nc"]] = b.add("bsa-warning", {
            "learnerId": s["nc"], "programmeId": programmes[key]["uuid"], "academicYear": YEAR, "bsaProgressFlagId": flags[s["nc"]]["uuid"],
            "warningDate": dt.date(2026, 2, 13).isoformat(), "ectsEarnedAtWarning": ects(s, check_until), "ectsNormAtWarning": BSA_NORM,
            "improvementPeriod": {"startDate": "2026-02-16", "endDate": "2026-07-03"},
            "offeredGuidance": "Maandelijks gesprek met de studieadviseur, een studieplan voor de herkansingen en een training plannen en studeren.",
            "personalCircumstancesNote": None, "signature": None, "signingKeyId": None,
            "lifecycle": rng.choice(["issued", "acknowledged", "acknowledged"]),
        })
    below = [s for s in first_years if final_ects[s["nc"]] < BSA_NORM]
    postponed = {max(below, key=lambda s: sum(1 for r in records.get(s["nc"], []) if r["status"] == "absent-excused"))["nc"]} if below else set()
    recommended = set()
    for fac in ("zuid", "noord"):
        pool = [s for s in below if s["nc"] not in postponed and PROGRAMMES[[p[0] for p in PROGRAMMES].index(s["programme"])][4] == fac]
        if pool:
            recommended.add(pool[0]["nc"])
    appealed = next((s["nc"] for s in below if s["nc"] not in postponed and s["nc"] not in recommended), None)
    for s in first_years:
        key = s["programme"]
        fac = PROGRAMMES[[p[0] for p in PROGRAMMES].index(key)][4]
        earned = final_ects[s["nc"]]
        warning = warnings.get(s["nc"])
        fields = {
            "learnerId": s["nc"], "programmeId": programmes[key]["uuid"], "academicYear": YEAR, "ectsAchieved": earned,
            "ectsNormRequired": BSA_NORM, "warningIds": [warning["uuid"]] if warning else [], "personalCircumstancesConsidered": False,
            "personalCircumstancesNote": None, "studentHeardAt": None, "studentResponse": None, "rationale": None,
            "decidedBy": chairs[fac], "decisionDate": stamp(dt.date(2026, 7, 8), 10, 0), "signature": None, "signingKeyId": None,
            "lifecycle": "decided",
        }
        if earned >= BSA_NORM:
            fields["decisionType"] = "positive"
        elif s["nc"] in postponed:
            fields |= {
                "decisionType": "postponed", "personalCircumstancesConsidered": True,
                "personalCircumstancesNote": "Langdurige ziekte in blok 2 en 3, gemeld bij de studentendecaan.",
                "studentHeardAt": stamp(dt.date(2026, 7, 1), 11, 0),
                "studentResponse": "De student wil het tweede jaar gebruiken om de ontbrekende blokken te halen.",
                "rationale": f"Behaald: {earned:g} van de {BSA_NORM:g} studiepunten. Door de gemelde ziekte stelt de examencommissie het advies een jaar uit.",
            }
        else:
            kind = "negative-with-recommendation" if s["nc"] in recommended else "negative"
            fields |= {
                "decisionType": kind, "personalCircumstancesConsidered": True,
                "personalCircumstancesNote": "Geen persoonlijke omstandigheden gemeld bij de studentendecaan.",
                "studentHeardAt": stamp(dt.date(2026, 7, 1), 11, 0),
                "studentResponse": "De student geeft aan dat de combinatie met een bijbaan te zwaar was.",
                "rationale": f"Behaald: {earned:g} van de {BSA_NORM:g} studiepunten. De student is in februari gewaarschuwd en kreeg begeleiding van de studieadviseur."
                + (" De examencommissie adviseert de associate degree binnen dezelfde faculteit." if kind == "negative-with-recommendation" else ""),
            }
            if s["nc"] == appealed:
                fields["lifecycle"] = "appealed"
        b.add("bsa-decision", fields)

    # --- learning record exports (graduates) ---------------------------------------
    for key, *_rest in PROGRAMMES:
        graduates = [s for s in by_group[(key, 4)] if finals.get((s["nc"], "AFST")) and finals[(s["nc"], "AFST")]["passed"]]
        for i, s in enumerate(graduates[:2]):
            requested = dt.date(2026, 7, 6) + dt.timedelta(days=i)
            coverage = []
            for ckey in ("VERD", "AFST"):
                final = finals[(s["nc"], ckey)]
                coverage.append({"sourceSchema": "final-grade", "sourceId": final["uuid"], "sourceTitle": courses[(key, ckey)]["name"],
                                 "outcome": "included", "reason": None})
            generated = i == 0 or key in ("VPK", "ICT")
            b.add("learning-record-export", {
                "learnerId": s["nc"], "learnerRef": s["profile"]["uuid"], "requestedBy": s["nc"], "requestedAt": stamp(requested, 20, 15),
                "generatedAt": stamp(requested, 20, 16) if generated else None, "periodFrom": None, "periodTo": None,
                "coverageReport": coverage if generated else [],
                "bundleRef": f"Leerdossier/leerdossier-{requested.isoformat()}.json" if generated else None,
                "bundleSignature": None, "issuerDid": None, "errorMessage": None, "lifecycle": "generated" if generated else "requested",
            })

    # --- assemble -------------------------------------------------------------------
    objects = {name: rows for name, rows in b.buckets.items() if rows}
    total = sum(len(rows) for rows in objects.values())
    return {
        "openapi": "3.0.0",
        "info": {
            "title": "Learniq example set: Higher education",
            "version": "1.0.0",
            "description": "Voorbeeldhogeschool Esdoornstad, a fictional university of applied sciences in the fictional town of Esdoornstad, through the 2025-2026 academic year.",
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
                "objectCount": total,
                "icon": "CertificateOutline",
            },
            "description": (
                "An example set an operator picks in the first-time setup wizard (ADR-042, decision D21). NEVER imported on install. "
                "Every object carries @self.configuration/register/schema and a fixed uuid in the ee04 namespace, so the import resolves "
                "the live learniq register without this descriptor declaring components.registers (which would re-point the register at "
                "this profile config id and overwrite its authorization block), a second load adds nothing, and occ "
                "learniq:example-set:remove he removes exactly these objects. Generated by scripts/example-sets/he.py; the contract is "
                "openspec/changes/segment-wizard-choice/contract.md. Every person, address, institution, company and code in it is fictional."
            ),
            "seedData": {
                "description": (
                    "One university of applied sciences with two faculties, four bachelor programmes with learning outcomes on the Dublin "
                    "descriptors, courses with ECTS credits, a cohort per programme per study year, 400 students with their enrolments, "
                    "item bank exams with item statistics, grade entries and final grades, binding study advice with flags and warnings, "
                    "study advisers, a peer reviewed group project, internship portfolios, a proctoring session and learning record exports."
                ),
                "objects": objects,
            },
        },
        "paths": {},
        "components": {},
    }


def by_group_all(by_group: dict[tuple[str, int], list[dict]], year: int) -> list[dict]:
    """Every student of one study year, programme by programme."""
    out = []
    for key, *_rest in PROGRAMMES:
        out.extend(by_group[(key, year)])
    return out


def qti(identifier: str, title: str, itype: str, stem: str, options: list[str] | None, answer: str | None, max_score: float) -> str:
    """The QTI item XML the app's own item editor writes (src/views/ItemAuthorView.vue buildQtiBody).

    The take view, the draw resolver and the item analysis all read
    `simpleChoice` elements, so an item in any other dialect would render as
    two placeholder options and never get a distractor analysis.
    """
    def attr(text: str) -> str:
        return escape(text, {'"': "&quot;"})

    head = ('<?xml version="1.0" encoding="UTF-8"?><assessmentItem xmlns="http://www.imsglobal.org/xsd/imsqtiasi_v3p0" '
            f'identifier="{attr(identifier)}" title="{attr(title)}" adaptive="false" timeDependent="false">')
    score = f'<outcomeDeclaration identifier="SCORE" cardinality="single" baseType="float"><defaultValue><value>{max_score:g}</value></defaultValue></outcomeDeclaration>'
    if itype == "choice":
        declaration = f'<responseDeclaration identifier="RESPONSE" cardinality="single" baseType="identifier"><correctResponse><value>{escape(answer or "")}</value></correctResponse></responseDeclaration>'
        choices = "".join(f'<simpleChoice identifier="{letter}">{escape(text)}</simpleChoice>' for letter, text in zip("ABCD", options or []))
        body = f'<p>{escape(stem)}</p><choiceInteraction responseIdentifier="RESPONSE" shuffle="false" maxChoices="1">{choices}</choiceInteraction>'
    elif itype == "text":
        declaration = f'<responseDeclaration identifier="RESPONSE" cardinality="single" baseType="string"><correctResponse><value>{escape(answer or "")}</value></correctResponse></responseDeclaration>'
        body = f'<p>{escape(stem)}</p><textEntryInteraction responseIdentifier="RESPONSE" expectedLength="20" />'
    else:
        declaration = ""
        body = f'<p>{escape(stem)}</p><extendedTextInteraction responseIdentifier="RESPONSE" expectedLength="500" />'
    return head + declaration + score + "<itemBody>" + body + "</itemBody></assessmentItem>"


def render(data: dict) -> str:
    """Pretty JSON with one compact object per line in each seed bucket.

    Thousands of objects pretty-printed field by field make a file nobody can
    review; one object per line keeps a diff to the objects that changed.
    Still strict JSON.
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
            print(f"{OUT} is out of date; run python3 scripts/example-sets/he.py", file=sys.stderr)
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
