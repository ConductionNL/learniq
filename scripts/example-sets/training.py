#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
# Copyright (C) 2026 Conduction B.V.
"""Build lib/Settings/profiles/training.json, the training institute example set.

One fictional training institute, the Warmtepompacademie (a trade academy for
installers, "De Warmtepompacademie" on its site) in the fictional town of
Zuiddrecht, through one complete year (2025-2026): one
location, a public catalogue of fourteen courses with prices and editions,
a leadership programme with an intake and a waiting list, 150 participants
sent by six client companies or enrolled on their own, trainers who teach the
editions, a morning and an afternoon session per training day with every
participant marked present or not, knowledge tests with resits, signed
attestations, certificates with their expiry and renewal, course evaluations
per quarter with the quality scores and improvement actions they led to, and
the course package imports and exports the institute ran. On top of that year sits
the story of the portal designs (add_story): Jansen Installatietechniek BV and its
four installers in week 41 of 2026.

WHY A SCRIPT. The set is several thousand objects that must agree with each
other: a participant who misses a morning of a certificate course is rebooked
into a later edition that still has room, is marked by the trainer who teaches
that edition, only gets a certificate after the edition that they attended in
full and whose test they passed, and is only invited to evaluate what they
followed. Hand-editing that is how sets drift. The script is deterministic
(fixed seed), so running it again produces the same file byte for byte, and a
reviewer reads the rules here rather than megabytes of JSON.

THE CONTRACT. openspec/changes/archive/2026-09-28-segment-wizard-choice/contract.md. Every rule is
checked by tests/Unit/Settings/ExampleSetDescriptorContractTest.php; the story
is checked by tests/Unit/Settings/TrainingExampleSetTest.php. Run both after
regenerating.

WHAT IS LEFT OUT, ON PURPOSE.
- DataExchangeJob: decision D7 moves data exchange to integriq.
- Order, OrderLine, PaymentTransaction and Entitlement: decision D19 retires the
  first three; an Entitlement needs an OrderLine. Prices live on FeeItem, which
  D19 keeps.
- AVG as a Regulation row: learniq_register.json seeds it, and a second row
  with the same code would be a duplicate. The other regulations are rows here
  since decision D29 let the contract take a Regulation's slug from the object.
- Guardians: participants are adults.

Usage:
    python3 scripts/example-sets/training.py            write the file
    python3 scripts/example-sets/training.py --check    exit 1 when the file on disk differs

Nothing here is real: no real institute, company, person, address or code. The
town Zuiddrecht and every company named after it are invented, postcodes start with
0 (never issued), web addresses end in .example (reserved), IP addresses come
from the documentation ranges, and signatures read "voorbeeld" and verify
nothing. A private training institute has no DUO BRIN; School.brin is required,
so the set uses 00X6, which ends in a digit and is never assigned.
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import random
import sys
from xml.sax.saxutils import escape
from zoneinfo import ZoneInfo

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
OUT = os.path.join(ROOT, "lib", "Settings", "profiles", "training.json")
AMS = ZoneInfo("Europe/Amsterdam")
TENANT = "00000000-0000-4000-8000-000000000000"
SET = "training"
SET_NUMBER = "06"
YEAR = "2025-2026"
FIRST_DAY = dt.date(2025, 8, 18)
LAST_DAY = dt.date(2026, 7, 10)
FIRST_TRAINING_DAY = dt.date(2025, 9, 1)
LAST_TRAINING_DAY = dt.date(2026, 7, 3)
# The DID agrees with scripts/example-sets/corporate.py, where the same academy is an outside provider.
INSTITUTE = "Warmtepompacademie"
TOWN = "Zuiddrecht"
DOMAIN = "warmtepompacademie.example"
ISSUER_DID = f"did:web:{DOMAIN}"
PLANNER = "training-planner-01"
QUALITY = "training-kwaliteit-01"

# Schema number (the TTTT group of the uuid) per bucket, in load order.
# Parents come before children; removal runs in reverse.
SCHEMAS = [
    "school",
    "vestiging",
    "room",
    "course",
    "lesson",
    "course-package-import-report",
    "programme",
    "fee-item",
    "item-bank",
    "item",
    "staff",
    "learner-profile",
    "cohort",
    "enrolment",
    "admissions-round",
    "admission",
    "subjectteacherassignment",
    "session",
    "exam",
    "assessment-result",
    "excuse-request",
    "attendance-record",
    "attestation",
    "credential",
    "evaluation-campaign",
    "evaluation-invitation",
    "course-evaluation-response",
    "course-quality-score",
    "improvement-action",
    # Appended, not inserted: a uuid encodes its schema's position in this list, so
    # inserting a schema would move every later uuid and a re-load would duplicate
    # the set on an install that already has it (example-set-regulation-rows).
    "regulation",
    # The client company and its bookings (employer-portal-audience).
    "client-organisation",
    "course-booking",
]

CLOSED = [
    ("Kerstsluiting", dt.date(2025, 12, 22), dt.date(2026, 1, 2)),
    ("Goede Vrijdag", dt.date(2026, 4, 3), dt.date(2026, 4, 3)),
    ("Tweede Paasdag", dt.date(2026, 4, 6), dt.date(2026, 4, 6)),
    ("Koningsdag", dt.date(2026, 4, 27), dt.date(2026, 4, 27)),
    ("Bevrijdingsdag", dt.date(2026, 5, 5), dt.date(2026, 5, 5)),
    ("Hemelvaart en brugdag", dt.date(2026, 5, 14), dt.date(2026, 5, 15)),
    ("Tweede Pinksterdag", dt.date(2026, 5, 25), dt.date(2026, 5, 25)),
]
CLOSED_DAYS = set()
for _name, _start, _end in CLOSED:
    _d = _start
    while _d <= _end:
        CLOSED_DAYS.add(_d)
        _d += dt.timedelta(days=1)

WEEKDAYS = ["monday", "tuesday", "wednesday", "thursday", "friday"]
MAAND = ["januari", "februari", "maart", "april", "mei", "juni", "juli", "augustus", "september", "oktober", "november", "december"]
# A training day has a morning and an afternoon session; an online half day is a little shorter.
SLOTS = {"ochtend": ((9, 0), (12, 30)), "middag": ((13, 15), (16, 30))}
ONLINE_SLOTS = {"ochtend": ((9, 30), (12, 30)), "middag": ((13, 30), (16, 30))}
QUARTERS = [
    ("Kwartaal 1", dt.date(2025, 9, 1), dt.date(2025, 11, 30)),
    ("Kwartaal 2", dt.date(2025, 12, 1), dt.date(2026, 2, 28)),
    ("Kwartaal 3", dt.date(2026, 3, 1), dt.date(2026, 5, 31)),
    ("Kwartaal 4", dt.date(2026, 6, 1), LAST_DAY),
]

ADULT_M = ["Mark", "Peter", "Jeroen", "Bas", "Martijn", "Erik", "Rick", "Dennis", "Tarik", "Hasan", "Joost", "Wouter", "Sander",
           "Niels", "Arjen", "Pieter", "Kevin", "Mohamed", "Stefan", "Frank", "Johan", "Thijs", "Daan", "Lars", "Tim", "Youssef",
           "Emre", "Bart", "Koen", "Ramon", "Vincent", "Jasper", "Marco", "Robin", "Gerrit", "Hamza"]
ADULT_F = ["Linda", "Sanne", "Esther", "Marloes", "Anouk", "Kim", "Iris", "Fatima", "Laura", "Nicole", "Petra", "Judith", "Ingrid",
           "Samira", "Eline", "Mirjam", "Chantal", "Naima", "Lisa", "Femke", "Anna", "Eva", "Julia", "Sophie", "Merel", "Yvonne",
           "Monique", "Hatice", "Priya", "Dewi", "Lotte", "Nadia", "Karin", "Wendy", "Joyce", "Ilse"]
SURNAME_HEAD = ["Kompas", "Baken", "Getij", "Roer", "Boei", "Zeil", "Anker", "Kaap", "Sluis", "Golf", "Mast", "Steiger", "Vlot", "Kade",
                "Haven", "Wadden", "Kiel", "Luik", "Tros", "Meerpaal"]
SURNAME_TAIL = ["hoven", "stede", "werf", "kamp", "horst", "brink", "hout", "gaard", "rade", "velde", "weerd", "lanen"]

ROOMS = {
    "noord": {"name": "Trainingsruimte Noord", "code": "HK-N1", "capacity": 16, "kind": "classroom",
              "facilities": ["beamer", "whiteboard"], "buildingCode": "00X600", "floor": "1"},
    "zuid": {"name": "Trainingsruimte Zuid", "code": "HK-Z1", "capacity": 16, "kind": "classroom",
             "facilities": ["beamer", "whiteboard", "flip-over"], "buildingCode": "00X600", "floor": "1"},
    "kaap": {"name": "Vergaderzaal De Kaap", "code": "HK-K2", "capacity": 14, "kind": "classroom",
             "facilities": ["beamer", "flip-over", "gespreksruimte voor rollenspel"], "buildingCode": "00X600", "floor": "2"},
    "computer": {"name": "Computerlokaal", "code": "HK-C0", "capacity": 12, "kind": "lab",
                 "facilities": ["laptops", "beamer"], "buildingCode": "00X600", "floor": "0"},
    "oefenhal": {"name": "Oefenhal", "code": "HK-OEF", "capacity": 20, "kind": "other",
                 "facilities": ["brandoefenopstelling", "reanimatiepoppen", "AED-trainer"], "buildingCode": "00X600", "floor": "0"},
    "parcours": {"name": "Heftruckparcours", "code": "HK-HEF", "capacity": 8, "kind": "other",
                 "facilities": ["twee heftrucks", "stellingen", "hellingbaan"], "buildingCode": "00X600", "floor": "0"},
    "online": {"name": "Online klaslokaal", "code": "HK-ONLINE", "capacity": 30, "kind": "online",
               "facilities": ["videobellen", "gedeeld whiteboard"], "buildingCode": None, "floor": None},
}

TRAINERS = {
    "training-trainer-01": ["instructeur bedrijfshulpverlening", "reanimatie-instructeur"],
    "training-trainer-02": ["instructeur bedrijfshulpverlening", "hogere veiligheidskundige"],
    "training-trainer-03": ["docent VCA", "middelbare veiligheidskundige"],
    "training-trainer-04": ["instructeur interne transportmiddelen"],
    "training-trainer-05": ["trainer leiderschap", "registercoach"],
    "training-trainer-06": ["trainer communicatie en gesprekstechniek"],
    "training-trainer-07": ["trainer projectmatig werken"],
    "training-trainer-08": ["privacyjurist", "trainer informatiebeveiliging"],
    "training-trainer-09": ["trainer kantoorsoftware"],
}

# The public catalogue. `half` is the dagdeel of a half-day course; `spacing`
# is how the days of one edition follow each other; `start` the preferred
# weekday (0 = Monday); `full` whether a certificate needs every session.
COURSES = [
    {"key": "BHV-B", "name": "BHV basis", "days": 2, "half": None, "spacing": "consecutive", "start": 1,
     "trainers": ["training-trainer-01", "training-trainer-02"], "room": "oefenhal", "max": 12, "full": True,
     "regulation": "ARBOWET-BHV", "credential": "certificate", "validity": 12, "exam": "bhv", "price": 395, "mandatory": True,
     "renewal": "BHV-H", "tags": ["veiligheid", "bedrijfshulpverlening"],
     "months": [(2025, 9), (2025, 10), (2025, 11), (2026, 1), (2026, 3), (2026, 5)],
     "description": "Twee dagen bedrijfshulpverlening: brand bestrijden, ontruimen, eerste hulp en reanimeren met een AED. Afsluiting met een kennistoets.",
     "lessons": [
         ("Voorbereiding: brand en ontruiming", None, 60, "Lees vooraf de basis van brandpreventie en het ontruimingsplan van je eigen werkplek."),
         ("Dag 1: brand en ontruiming", 1, 390, "Brandklassen, kleine blusmiddelen, alarmeren en een ontruiming oefenen in de oefenhal."),
         ("Dag 2: eerste hulp en reanimatie", 2, 390, "Levensreddende handelingen, reanimatie met een AED en eerste hulp bij veelvoorkomend letsel."),
         ("Afsluiting en verklaring", 2, 30, "Kennistoets, nabespreking en je verklaring dat je de training hebt gevolgd en begrepen."),
     ]},
    {"key": "BHV-H", "name": "BHV herhaling", "days": 1, "half": None, "spacing": "consecutive", "start": 3,
     "trainers": ["training-trainer-01", "training-trainer-02"], "room": "oefenhal", "max": 12, "full": True,
     "regulation": "ARBOWET-BHV", "credential": "certificate", "validity": 12, "exam": "bhv-herhaling", "price": 225, "mandatory": True,
     "renewal": "BHV-H", "tags": ["veiligheid", "bedrijfshulpverlening", "herhaling"],
     "months": [(2025, 9), (2025, 11), (2026, 2), (2026, 4), (2026, 6)],
     "description": "Een dag om je BHV-certificaat te verlengen: de praktijk van brand, ontruiming en reanimatie opnieuw geoefend.",
     "lessons": [
         ("Herhaling brand en ontruiming", 1, 180, "Blussen met kleine blusmiddelen en een ontruiming met een onverwacht scenario."),
         ("Herhaling eerste hulp en reanimatie", 1, 180, "Reanimeren met een AED en eerste hulp in drie praktijkscenario's."),
         ("Afsluiting en verklaring", 1, 30, "Kennistoets en je verklaring dat je de herhaling hebt gevolgd en begrepen."),
     ]},
    {"key": "VCA-B", "name": "VCA basis", "days": 1, "half": None, "spacing": "consecutive", "start": 0,
     "trainers": ["training-trainer-03"], "room": "noord", "max": 12, "full": True,
     "regulation": "VCA", "credential": "certificate", "validity": 120, "exam": "vca", "price": 275, "mandatory": True,
     "renewal": None, "tags": ["veiligheid", "vca"],
     "months": [(2025, 9), (2025, 11), (2026, 1), (2026, 3), (2026, 6)],
     "description": "Een dag veilig werken voor operationele medewerkers: risico's herkennen, persoonlijke beschermingsmiddelen en melden, met een kennistoets.",
     "lessons": [
         ("Risico's herkennen en de LMRA", 1, 180, "Wetgeving in het kort, de laatste minuut risicoanalyse en veilig gedrag op de werkplek."),
         ("Gevaarlijke stoffen, gereedschap en werken op hoogte", 1, 150, "Pictogrammen, het veiligheidsinformatieblad, valgevaar en elektrisch gereedschap."),
         ("Afsluiting en verklaring", 1, 45, "Kennistoets van twintig vragen en je verklaring van deelname."),
     ]},
    {"key": "HEF", "name": "Heftruckchauffeur", "days": 2, "half": None, "spacing": "consecutive", "start": 0,
     "trainers": ["training-trainer-04"], "room": "parcours", "max": 6, "full": True,
     "regulation": "ARBOBESLUIT-HEFTRUCK", "credential": "certificate", "validity": 60, "exam": "heftruck", "price": 595, "mandatory": True,
     "renewal": None, "tags": ["veiligheid", "intern transport"],
     "months": [(2025, 10), (2026, 1), (2026, 4), (2026, 6)],
     "description": "Twee dagen veilig werken met de heftruck: dagelijkse controle, het lastdiagram en veel rijtijd op het parcours.",
     "lessons": [
         ("Dag 1: de heftruck en het lastdiagram", 1, 390, "Dagelijkse controle, stabiliteit, het lastzwaartepunt en de eerste rijoefeningen."),
         ("Dag 2: rijden met last", 2, 360, "Stapelen in stellingen, rijden op de hellingbaan en veilig parkeren."),
         ("Afsluiting en verklaring", 2, 30, "Kennistoets en je verklaring van deelname."),
     ]},
    {"key": "PREV", "name": "Preventiemedewerker", "days": 2, "half": None, "spacing": "weekly", "start": 2,
     "trainers": ["training-trainer-02"], "room": "zuid", "max": 12, "full": True,
     "regulation": "ARBOWET-PREVENTIE", "credential": "certificate", "validity": None, "exam": None, "price": 545, "mandatory": False,
     "renewal": None, "tags": ["veiligheid", "arbo"],
     "months": [(2025, 10), (2026, 3)],
     "description": "Twee dagen, een week uit elkaar, voor wie de risico-inventarisatie van het eigen bedrijf gaat bijhouden.",
     "lessons": [
         ("Dag 1: de risico-inventarisatie en evaluatie", 1, 390, "De taken van de preventiemedewerker en een RI&E van je eigen werkplek."),
         ("Dag 2: het plan van aanpak", 2, 360, "Maatregelen kiezen, het plan van aanpak bijhouden en samenwerken met de arbodienst."),
         ("Afsluiting en verklaring", 2, 30, "Presentatie van je eigen plan van aanpak en je verklaring van deelname."),
     ]},
    {"key": "AVG", "name": "AVG in de praktijk", "days": 1, "half": "ochtend", "spacing": "consecutive", "start": 3,
     "trainers": ["training-trainer-08"], "room": "online", "max": 16, "full": True,
     "regulation": "AVG", "credential": "badge", "validity": 24, "exam": None, "price": 145, "mandatory": True,
     "renewal": "AVG", "tags": ["privacy", "online"],
     "months": [(2025, 9), (2025, 12), (2026, 2), (2026, 5)],
     "description": "Een online ochtend over persoonsgegevens in je dagelijkse werk: wat mag, wat niet, en wat je doet bij een datalek.",
     "lessons": [
         ("Persoonsgegevens in je werk", 1, 120, "Grondslagen, bewaartermijnen en delen met anderen, aan de hand van voorbeelden uit je sector."),
         ("Afsluiting en verklaring", 1, 30, "Wat je doet bij een datalek en je verklaring dat je de training hebt gevolgd."),
     ]},
    {"key": "NIS2", "name": "Informatiebeveiliging voor medewerkers", "days": 1, "half": "middag", "spacing": "consecutive", "start": 3,
     "trainers": ["training-trainer-08"], "room": "online", "max": 16, "full": True,
     "regulation": "NIS2", "credential": "badge", "validity": 12, "exam": None, "price": 145, "mandatory": True,
     "renewal": "NIS2", "tags": ["informatiebeveiliging", "online"],
     "months": [(2025, 10), (2026, 1), (2026, 4)],
     "description": "Een online middag over phishing, sterke wachtwoorden en het melden van incidenten.",
     "lessons": [
         ("Phishing, wachtwoorden en incidenten", 1, 150, "Echte en nagemaakte berichten herkennen, een wachtwoordmanager gebruiken en incidenten melden."),
         ("Afsluiting en verklaring", 1, 30, "Je verklaring dat je de training hebt gevolgd en begrepen."),
     ]},
    {"key": "LG-B", "name": "Leidinggeven basis", "days": 3, "half": None, "spacing": "fortnight", "start": 1,
     "trainers": ["training-trainer-05"], "room": "kaap", "max": 12, "full": False, "min_ratio": 0.6,
     "regulation": None, "credential": "badge", "validity": None, "exam": None, "price": None, "mandatory": False,
     "renewal": None, "tags": ["leiderschap", "leergang"], "months": [],
     "description": "Module 1 van de leergang Leidinggeven: je rol als leidinggevende, doelen stellen en feedback geven.",
     "lessons": [
         ("Je rol als leidinggevende", 1, 390, "Van collega naar leidinggevende: verwachtingen, grenzen en je eigen stijl."),
         ("Doelen stellen en bijsturen", 2, 390, "Heldere afspraken maken en bijsturen zonder het werk over te nemen."),
         ("Feedback geven en ontvangen", 3, 390, "Feedback die aankomt, geoefend met een trainingsacteur."),
     ]},
    {"key": "LG-C", "name": "Coachend leidinggeven", "days": 2, "half": None, "spacing": "fortnight", "start": 1,
     "trainers": ["training-trainer-05"], "room": "kaap", "max": 12, "full": False, "min_ratio": 0.6,
     "regulation": None, "credential": "badge", "validity": None, "exam": None, "price": None, "mandatory": False,
     "renewal": None, "tags": ["leiderschap", "leergang"], "months": [],
     "description": "Module 2 van de leergang Leidinggeven: coachende gesprekken voeren en je team laten groeien.",
     "lessons": [
         ("Coachende gesprekken", 1, 390, "Vragen stellen in plaats van oplossingen geven, met oefengesprekken in drietallen."),
         ("Je team laten groeien", 2, 390, "Ontwikkelgesprekken voeren en taken delegeren."),
     ]},
    {"key": "GESP", "name": "Lastige gesprekken voeren", "days": 1, "half": None, "spacing": "consecutive", "start": 2,
     "trainers": ["training-trainer-06"], "room": "kaap", "max": 10, "full": False,
     "regulation": None, "credential": "badge", "validity": None, "exam": None, "price": 395, "mandatory": False,
     "renewal": None, "tags": ["communicatie", "leergang"],
     "months": [(2025, 11), (2026, 4)],
     "description": "Een dag slecht nieuws brengen, grenzen stellen en omgaan met weerstand, geoefend met een trainingsacteur.",
     "lessons": [
         ("Slecht nieuws en weerstand", 1, 390, "Het slechtnieuwsgesprek in drie stappen en wat je doet als de ander boos wordt."),
     ]},
    {"key": "PROJ", "name": "Projectmatig werken", "days": 2, "half": None, "spacing": "weekly", "start": 2,
     "trainers": ["training-trainer-07"], "room": "zuid", "max": 10, "full": False,
     "regulation": None, "credential": "badge", "validity": None, "exam": None, "price": 695, "mandatory": False,
     "renewal": None, "tags": ["projectmanagement"],
     "months": [(2025, 10), (2026, 3)],
     "description": "Twee dagen, een week uit elkaar: een project opzetten, plannen, bewaken en afsluiten met je eigen project als casus.",
     "lessons": [
         ("Dag 1: een project opzetten", 1, 390, "Opdracht, resultaat, fasen en de projectomgeving in kaart."),
         ("Dag 2: plannen, bewaken en afsluiten", 2, 390, "Planning, risico's, voortgang rapporteren en goed afsluiten."),
     ]},
    {"key": "SPR", "name": "Werken met spreadsheets, gevorderd", "days": 1, "half": None, "spacing": "consecutive", "start": 4,
     "trainers": ["training-trainer-09"], "room": "computer", "max": 10, "full": False,
     "regulation": None, "credential": "badge", "validity": None, "exam": None, "price": 325, "mandatory": False,
     "renewal": None, "tags": ["kantoorsoftware"],
     "months": [(2025, 9), (2026, 1), (2026, 5)],
     "description": "Een dag formules, draaitabellen en grafieken voor wie al dagelijks met spreadsheets werkt.",
     "lessons": [
         ("Formules en verwijzingen", 1, 120, "Zoekfuncties, geneste formules en absolute en relatieve verwijzingen."),
         ("Draaitabellen", 1, 120, "Gegevens samenvatten en filteren met draaitabellen."),
         ("Grafieken en voorwaardelijke opmaak", 1, 90, "De juiste grafiek kiezen en afwijkingen laten opvallen."),
         ("Afsluiting en eigen casus", 1, 60, "Een spreadsheet uit je eigen werk verbeteren met wat je vandaag leerde."),
     ]},
    {"key": "PLAN", "name": "Effectief plannen en prioriteren", "days": 1, "half": None, "spacing": "consecutive", "start": 4,
     "trainers": ["training-trainer-07"], "room": "zuid", "max": 12, "full": False,
     "regulation": None, "credential": "badge", "validity": None, "exam": None, "price": 345, "mandatory": False,
     "renewal": None, "tags": ["persoonlijke effectiviteit"],
     "months": [(2025, 11), (2026, 5)],
     "description": "Een dag grip op je werkweek: prioriteren, plannen in blokken en nee zeggen zonder schuldgevoel.",
     "lessons": [
         ("Prioriteren op urgent en belangrijk", 1, 180, "Je takenlijst ordenen en bewust kiezen wat vandaag niet gebeurt."),
         ("Plannen in blokken", 1, 150, "Focusblokken, buffertijd en afspraken met jezelf."),
         ("Afsluiting: je eigen weekplanning", 1, 60, "Je eigen weekplanning voor de komende maand."),
     ]},
    {"key": "PRES", "name": "Presenteren met impact", "days": 1, "half": None, "spacing": "consecutive", "start": 2,
     "trainers": ["training-trainer-06"], "room": "kaap", "max": 8, "full": False,
     "regulation": None, "credential": "badge", "validity": None, "exam": None, "price": 425, "mandatory": False,
     "renewal": None, "tags": ["communicatie"],
     "months": [(2025, 12), (2026, 6)],
     "description": "Een dag presenteren voor een kleine groep, met video-opnames en persoonlijke feedback.",
     "lessons": [
         ("Opbouw en boodschap", 1, 180, "Een presentatie opbouwen rond een boodschap die blijft hangen."),
         ("Oefenen met video", 1, 180, "Twee keer presenteren op video, met feedback van de groep en de trainer."),
     ]},
]
BY_KEY = {c["key"]: c for c in COURSES}

PROGRAMME = {
    "name": "Leergang Leidinggeven", "code": "LG", "price": 2950,
    "modules": [("LG-B", 3), ("LG-C", 2), ("GESP", 1)],
    "trainer": {"LG-B": "training-trainer-05", "LG-C": "training-trainer-05", "GESP": "training-trainer-06"},
    "starts": [dt.date(2025, 9, 9), dt.date(2026, 2, 10)],
    "description": "Zes dagen in drie modules, om de twee weken, voor nieuwe en aankomende leidinggevenden. Toelating na een intakegesprek.",
}

# Client companies: name, employees, units, contact person, and how many
# enrolments per course they buy. Private individuals come last.
CLIENTS = [
    ("Bouwbedrijf Zuiddrecht B.V.", 30, ["Uitvoering", "Werkvoorbereiding", "Kantoor"], ("Marloes", "Kadestede"),
     {"VCA-B": 20, "BHV-B": 12, "BHV-H": 8, "HEF": 6, "PREV": 4, "LG": 2, "PLAN": 2}),
    ("Voorbeeld Logistiek Zuiddrecht B.V.", 28, ["Magazijn", "Transport", "Planning"], ("Hasan", "Roerbrink"),
     {"HEF": 12, "VCA-B": 14, "BHV-B": 10, "BHV-H": 8, "PREV": 4, "LG": 3, "PLAN": 4, "SPR": 4}),
    ("Zorggroep Zuiddrecht", 30, ["Locatie De Veenhoeve", "Thuiszorg", "Facilitair"], ("Esther", "Baakhoven"),
     {"AVG": 22, "BHV-B": 14, "BHV-H": 14, "NIS2": 10, "GESP": 8, "LG": 5, "PRES": 2}),
    ("Gemeente Zuiddrecht", 24, ["Publiekszaken", "Ruimte", "Bedrijfsvoering"], ("Pieter", "Getijwerf"),
     {"NIS2": 18, "AVG": 16, "PROJ": 8, "SPR": 8, "BHV-H": 8, "LG": 6, "BHV-B": 6, "PRES": 4, "PLAN": 4}),
    ("Installatietechniek Zuiddrecht B.V.", 16, ["Montage", "Service"], ("Kim", "Ankerhorst"),
     {"VCA-B": 16, "BHV-B": 6, "BHV-H": 6, "PREV": 4, "HEF": 2, "LG": 2}),
    ("Stichting Welzijn Zuiddrecht", 12, ["Buurtwerk", "Jongerenwerk"], ("Naima", "Sluisgaard"),
     {"AVG": 12, "GESP": 6, "BHV-B": 6, "BHV-H": 6, "NIS2": 6, "PLAN": 4, "PRES": 2, "LG": 2}),
    (None, 10, [], None,
     {"SPR": 8, "PROJ": 6, "PLAN": 4, "PRES": 4, "LG": 4, "PREV": 2, "GESP": 2}),
]
PRIOR_EDUCATION = ["mbo 4 Logistiek", "hbo Bedrijfskunde", "hbo Verpleegkunde", "mbo 4 Bouwkunde", "wo Bestuurskunde",
                   "hbo Social Work", "mbo 4 Installatietechniek", "hbo Communicatie"]

# Knowledge test items: (question, [options A, B, C], correct letter).
ITEMS = {
    "bhv": ("BHV kennistoets", "Bedrijfshulpverlening", [
        ("Wat is bij een brand de eerste handeling van een BHV'er?", ["Zelf blussen", "Alarm slaan en de brand melden", "De ramen openen"], "B"),
        ("Welk blusmiddel gebruik je niet bij een elektrische installatie onder spanning?", ["CO2-blusser", "Water", "Poederblusser"], "B"),
        ("Welk nummer bel je bij een levensbedreigende situatie?", ["112", "911", "999"], "A"),
        ("Hoe controleer je of een slachtoffer normaal ademt?", ["Kijken, luisteren en voelen, maximaal tien seconden", "De pols voelen", "Het slachtoffer rechtop zetten"], "A"),
        ("Wat is de verhouding borstcompressies en beademingen bij een volwassene?", ["15 om 2", "30 om 2", "5 om 1"], "B"),
        ("Wanneer sluit je een AED aan?", ["Alleen als het slachtoffer nog ademt", "Zodra het slachtoffer niet reageert en niet normaal ademt", "Pas na tien minuten reanimeren"], "B"),
        ("Gebruik je bij een ontruiming de lift?", ["Nee, nooit", "Alleen voor bezoekers", "Als de trap vol is"], "A"),
        ("Waar verzamelen medewerkers zich na een ontruiming?", ["Bij de hoofdingang", "Op de afgesproken verzamelplaats", "In de kantine"], "B"),
        ("Hoe koel je een brandwond?", ["Tien minuten met lauw, zacht stromend water", "Met ijs", "Met zalf"], "A"),
        ("Voor wie is de stabiele zijligging bedoeld?", ["Een slachtoffer dat niet ademt", "Een bewusteloos slachtoffer dat normaal ademt", "Een slachtoffer met een gebroken been"], "B"),
        ("Wie geeft tijdens een incident leiding aan de BHV-organisatie?", ["De hoofd-BHV'er", "De directeur", "De eerste medewerker die het ziet"], "A"),
        ("De kleding van een collega vat vlam. Wat doe je?", ["Naar water laten rennen", "Laten stoppen, liggen en rollen, of afdekken met een blusdeken", "Met een poederblusser in het gezicht spuiten"], "B"),
    ]),
    "vca": ("VCA basis kennistoets", "Veilig werken", [
        ("Wat is het doel van een laatste minuut risicoanalyse?", ["Vlak voor de start de risico's op de werkplek beoordelen", "De jaarlijkse risico-inventarisatie vervangen", "Het werk sneller afronden"], "A"),
        ("Welke kleur heeft een gebodsbord?", ["Rood", "Blauw", "Geel"], "B"),
        ("Wat betekent een geel driehoekig bord?", ["Een gebod", "Een waarschuwing voor gevaar", "Een reddingsmiddel"], "B"),
        ("Wie is verantwoordelijk voor het dragen van je beschermingsmiddelen?", ["Alleen de werkgever", "Jij zelf", "De uitvoerder"], "B"),
        ("Vanaf welke hoogte zijn maatregelen tegen valgevaar verplicht?", ["1,5 meter", "2,5 meter", "4 meter"], "B"),
        ("Wat doe je met een beschadigde ladder?", ["Voorzichtig gebruiken", "Markeren en buiten gebruik stellen", "Zelf repareren met tape"], "B"),
        ("Waar staat de afkorting PBM voor?", ["Persoonlijke beschermingsmiddelen", "Preventief beleid medewerkers", "Periodiek bedrijfsmedisch onderzoek"], "A"),
        ("Je ziet een onveilige situatie. Wat doe je?", ["Niets, het is niet jouw taak", "Stoppen, melden en waar het kan veilig maken", "Het later bespreken in het werkoverleg"], "B"),
        ("Vanaf welk dagelijks geluidsniveau is gehoorbescherming verplicht?", ["70 dB(A)", "80 dB(A)", "85 dB(A)"], "C"),
        ("Wat is een werkvergunning?", ["Schriftelijke toestemming voor risicovol werk met afgesproken maatregelen", "Een rijbewijs voor bouwmachines", "Een contract met de opdrachtgever"], "A"),
        ("Welke volgorde van maatregelen is juist?", ["Beschermingsmiddelen, organisatie, bron", "Bron, collectief, individueel, beschermingsmiddelen", "Collectief, beschermingsmiddelen, bron"], "B"),
        ("Hoe til je een zware last?", ["Met gestrekte benen en gebogen rug", "Door de knieën, rug recht, last dicht bij je lichaam", "Snel en met een draai"], "B"),
        ("Wat moet er gebeuren voor je in een besloten ruimte gaat werken?", ["Meten, een werkvergunning en een mangatwacht", "Alleen een zaklamp meenemen", "Even luchten, verder niets"], "A"),
        ("Wat betekent een pictogram met een vlam?", ["Oxiderend", "Ontvlambaar", "Giftig"], "B"),
        ("Waar vind je de gevaren van een stof?", ["In het veiligheidsinformatieblad", "Op de factuur", "In de cao"], "A"),
        ("Er gebeurt een ongeval op de bouwplaats. Wat doe je?", ["Doorwerken en later melden", "Hulp inroepen, de plek veilig maken en melden", "Het slachtoffer naar huis sturen"], "B"),
        ("Wanneer mag je een steiger gebruiken?", ["Als hij is goedgekeurd en er een steigerkaart hangt", "Als hij er stevig uitziet", "Altijd, als je een helm draagt"], "A"),
        ("Mag je onder een hangende last staan?", ["Ja, als je snel bent", "Nee, nooit", "Alleen bij droog weer"], "B"),
        ("Wat doe je met gereedschap met een beschadigde kabel?", ["Afplakken en doorgaan", "Niet gebruiken en melden", "Alleen buiten gebruiken"], "B"),
        ("Wie stelt de risico-inventarisatie en -evaluatie op?", ["De werkgever", "De gemeente", "De vakbond"], "A"),
    ]),
    "heftruck": ("Heftruck kennistoets", "Intern transport", [
        ("Wat controleer je voor je met de heftruck begint?", ["Alleen de brandstof", "Remmen, hydrauliek, banden, verlichting en claxon", "Niets, dat doet de monteur"], "B"),
        ("Hoe rijd je met een last die je zicht belemmert?", ["Achteruit", "Vooruit, iets sneller", "Met de vorken hoog"], "A"),
        ("Op welke hoogte rijd je met de vorken?", ["Zo hoog mogelijk", "Net boven de grond, ongeveer vijftien centimeter", "Op ooghoogte"], "B"),
        ("Mag een collega meerijden op de vorken?", ["Ja, als het kort is", "Nee, nooit", "Alleen met een helm"], "B"),
        ("Wat bepaalt het lastzwaartepunt?", ["Hoeveel je bij een hefhoogte maximaal mag heffen", "De snelheid van de heftruck", "Het onderhoudsschema"], "A"),
        ("Hoe rijd je met een last een helling op?", ["Met de last aan de bovenkant van de helling", "Met de last aan de onderkant van de helling", "Dwars op de helling"], "A"),
        ("De heftruck dreigt te kantelen. Wat doe je?", ["Eruit springen", "Blijven zitten, gordel om, vasthouden en van de kantelrichting af leunen", "Gas geven"], "B"),
        ("Hoe parkeer je de heftruck na gebruik?", ["Voor een nooduitgang", "Op de vaste plek, vorken op de grond, sleutel eruit", "Midden in het gangpad"], "B"),
        ("Wat lees je af op het lastdiagram?", ["Het maximale gewicht bij hefhoogte en lastzwaartepunt", "De brandstofstand", "De rijsnelheid"], "A"),
        ("Wanneer gebruik je de claxon?", ["Bij onoverzichtelijke punten en kruisingen", "Om collega's te groeten", "Nooit binnen"], "A"),
        ("Hoe wissel je de gasfles van een heftruck?", ["Met de motor aan", "Buiten, motor uit, met handschoenen en zonder open vuur", "Binnen naast de acculader"], "B"),
        ("Wat doe je met een beschadigde pallet?", ["Gewoon heffen", "Niet heffen en apart zetten", "Heffen met de vorken extra hoog"], "B"),
    ]),
}
# Exam per course: (item family, how many items, pass mark, minutes).
EXAMS = {"bhv": ("bhv", 12, 8, 30), "bhv-herhaling": ("bhv", 10, 7, 20), "vca": ("vca", 20, 14, 45), "heftruck": ("heftruck", 12, 9, 30)}

EVALUATION_QUESTIONS = [
    ("q1", "De training sloot aan bij mijn werk.", "The training matched my work.", "likert-5"),
    ("q2", "De trainer legde de stof duidelijk uit.", "The trainer explained the material clearly.", "likert-5"),
    ("q3", "Er was genoeg tijd om te oefenen.", "There was enough time to practise.", "likert-5"),
    ("q4", "De organisatie en de locatie waren goed geregeld.", "Organisation and venue were well arranged.", "likert-5"),
    ("q5", "Mijn algemene oordeel over de training.", "My overall rating of the training.", "likert-5"),
    ("q6", "Wat kan er beter?", "What could be better?", "free-text"),
]
# Mean overall score per course; a (course, quarter) pair after an improvement action scores the second value.
QUALITY_BASE = {"SPR": (3.2, 4.2), "HEF": (3.4, 4.2)}
FREE_TEXT = {
    "SPR": ["Het tempo lag voor mij te hoog.", "Meer tijd voor de draaitabellen graag.", "Fijn dat we met eigen bestanden mochten werken."],
    "HEF": ["Te weinig rijtijd, we stonden veel te wachten.", "Goede uitleg van het lastdiagram.", "Meer oefenen op de hellingbaan."],
    "BHV-B": ["De oefenhal was koud in de ochtend.", "Heel praktisch, veel geoefend.", "De reanimatie-oefening was erg leerzaam."],
    "BHV-H": ["Goed om weer te oefenen met de AED.", "Scenario's waren realistisch."],
    "AVG": ["Meer voorbeelden uit de zorg graag.", "Kort en duidelijk.", "De casus over het datalek was nuttig."],
}
IMPROVEMENTS = [
    ("SPR", "Kwartaal 1", "Kwartaal 2", "done",
     "Deelnemers vinden het tempo hoog en het niveauverschil in de groep groot.",
     "Vooraf een korte niveautest, twee niveaugroepen binnen de training en de oefenbestanden een week vooraf beschikbaar."),
    ("HEF", "Kwartaal 1", "Kwartaal 2", "done",
     "Te weinig rijtijd per deelnemer op het parcours; deelnemers staan veel te wachten.",
     "Een tweede heftruck op het parcours en per deelnemer een vast rijschema."),
    ("BHV-B", "Kwartaal 2", "Kwartaal 3", "done",
     "Opmerkingen over de koude oefenhal in de wintermaanden.",
     "De verwarming in de oefenhal laten nakijken en de theorie van de ochtend in Trainingsruimte Noord geven."),
    ("AVG", "Kwartaal 3", "Kwartaal 4", "in-progress",
     "Online deelnemers uit de zorg missen voorbeelden uit hun eigen werk.",
     "Casussen per sector toevoegen, te beginnen met zorg en gemeente."),
]


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
    """A local Amsterdam timestamp with its offset, e.g. 2025-09-01T09:00:00+02:00."""
    return dt.datetime(day.year, day.month, day.day, hour, minute, tzinfo=AMS).isoformat()


def open_day(day: dt.date) -> bool:
    """A weekday the institute is open, inside the training season."""
    return day.weekday() < 5 and day not in CLOSED_DAYS and FIRST_TRAINING_DAY <= day <= LAST_TRAINING_DAY


def office_day(day: dt.date) -> bool:
    """A weekday the office is open, up to the last day of the year."""
    return day.weekday() < 5 and day not in CLOSED_DAYS and day <= LAST_DAY


def next_office_day(day: dt.date) -> dt.date:
    while not office_day(day):
        day += dt.timedelta(days=1)
    return day


def add_months(day: dt.date, months: int) -> dt.date:
    month = day.month - 1 + months
    year = day.year + month // 12
    month = month % 12 + 1
    return dt.date(year, month, min(day.day, 28))


def slot_times(course: dict, dagdeel: str) -> tuple[tuple[int, int], tuple[int, int]]:
    return (ONLINE_SLOTS if course["room"] == "online" else SLOTS)[dagdeel]


def minutes_of(course: dict, dagdeel: str) -> int:
    (h1, m1), (h2, m2) = slot_times(course, dagdeel)
    return (h2 * 60 + m2) - (h1 * 60 + m1)


def surname(rng: random.Random, used: set[str]) -> str:
    while True:
        name = rng.choice(SURNAME_HEAD) + rng.choice(SURNAME_TAIL)
        if name not in used or len(used) > 200:
            used.add(name)
            return name


def qti(identifier: str, title: str, question: str, options: list[str], correct: str) -> str:
    """A QTI 2.1 choice item, as the app's own item editor writes it (src/views/ItemAuthorView.vue buildQtiBody).

    The take view, the draw resolver and the item analysis read `simpleChoice`
    elements in the imsqti_v2p1 namespace (the dialect learniq labels QTI 2.1
    since learniq #1127). The QTI 3.0 markup this set wrote before rendered
    as placeholder options and never got a distractor analysis.
    """
    def attr(text: str) -> str:
        return escape(text, {'"': "&quot;"})

    choices = "".join(f'<simpleChoice identifier="{"ABC"[i]}">{escape(text)}</simpleChoice>' for i, text in enumerate(options))
    return (
        '<?xml version="1.0" encoding="UTF-8"?><assessmentItem xmlns="http://www.imsglobal.org/xsd/imsqti_v2p1" '
        f'identifier="{attr(identifier)}" title="{attr(title)}" adaptive="false" timeDependent="false">'
        f'<responseDeclaration identifier="RESPONSE" cardinality="single" baseType="identifier"><correctResponse><value>{escape(correct)}</value></correctResponse></responseDeclaration>'
        '<outcomeDeclaration identifier="SCORE" cardinality="single" baseType="float"><defaultValue><value>1</value></defaultValue></outcomeDeclaration>'
        f'<itemBody><p>{escape(question)}</p><choiceInteraction responseIdentifier="RESPONSE" shuffle="false" maxChoices="1">{choices}</choiceInteraction></itemBody></assessmentItem>'
    )


class Scheduler:
    """Places editions on open days without double-booking a trainer or a room."""

    def __init__(self) -> None:
        self.busy: dict[tuple[dt.date, str], set[str]] = {}

    def free(self, slots: list[tuple[dt.date, str]], resources: list[str]) -> bool:
        return all(not (set(resources) & self.busy.get(slot, set())) for slot in slots)

    def book(self, slots: list[tuple[dt.date, str]], resources: list[str]) -> None:
        for slot in slots:
            self.busy.setdefault(slot, set()).update(resources)

    @staticmethod
    def days_from(course: dict, first: dt.date) -> list[dt.date] | None:
        days = [first]
        while len(days) < course["days"]:
            step = {"consecutive": 1, "weekly": 7, "fortnight": 14}[course["spacing"]]
            nxt = days[-1] + dt.timedelta(days=step)
            while not open_day(nxt):
                nxt += dt.timedelta(days=step)
                if nxt > LAST_TRAINING_DAY:
                    return None
            days.append(nxt)
        return days

    @staticmethod
    def slots_of(course: dict, days: list[dt.date]) -> list[tuple[dt.date, str]]:
        if course["half"]:
            return [(d, course["half"]) for d in days]
        return [(d, part) for d in days for part in ("ochtend", "middag")]

    def place(self, course: dict, year: int, month: int, trainer: str) -> list[dt.date]:
        start = dt.date(year, month, 8)
        for preferred in (True, False):
            day = start
            while day <= start + dt.timedelta(days=40):
                if open_day(day) and (not preferred or day.weekday() == course["start"]):
                    days = self.days_from(course, day)
                    if days is not None:
                        slots = self.slots_of(course, days)
                        resources = [trainer, "room:" + course["room"]]
                        if self.free(slots, resources):
                            self.book(slots, resources)
                            return days
                day += dt.timedelta(days=1)
        raise RuntimeError(f"no room for {course['key']} in {year}-{month}")


# --- the story layer: Jansen Installatietechniek in week 41 of 2026 -----------------
# The Warmtepompacademie portal designs tell one story, pinned to Monday 5 October
# 2026 (week 41). It sits on top of the 2025-2026 year: add_story() runs after every
# other object is built and draws no random number, so no existing uuid or value moves.
# Spec: openspec/changes/example-sets-are-the-four-schools/specs/example-sets/spec.md
STORY_TODAY = dt.date(2026, 10, 5)
STORY_YEAR = "2026-2027"
STORY_COMPANY = "Jansen Installatietechniek BV"
# Staff names live in Nextcloud display names, not in the register.
STORY_STAFF = {
    PLANNER: "Sophie van Dam",
    "training-administratie-01": "Rob Maas",
    "training-trainer-10": "Henk Dekker",
    "training-trainer-11": "Fatima Ouali",
}
HENK = "training-trainer-10"
FATIMA = "training-trainer-11"
# A day in the praktijkhal: theory in the morning, the hall after lunch.
HALL_SLOTS = {"ochtend": ((8, 30), (12, 0)), "middag": ((12, 45), (16, 30))}
# The external exam institution; the same name and DID as in corporate.py.
EXAM_COOLING = ("Voorbeeld Examencentrum Koudetechniek", "did:web:examencentrum-koudetechniek.example")

# No regulationSlug on the story: corporate.py ships the FGASSEN regulation, and the
# shared-code rule (SharedCodeFilterTest) pins the codes the two sets share to VCA and NIS2.
STORY_COURSES = {
    "FGAS-1": {"name": "F-gassen categorie 1", "regulation": None, "mandatory": True, "tags": ["warmtepompen", "f-gassen", "certificaat"],
               "description": "De opleiding met examen voor wie aan koelcircuits van warmtepompen werkt. Het certificaat verleng je met de herhaling.",
               "lessons": []},
    "BRL6000": {"name": "BRL 6000-21, bovengronds deel", "regulation": None, "mandatory": False, "tags": ["bodemenergie", "certificaat"],
                "description": "Voor monteurs die bodemenergiesystemen aanleggen: het bovengrondse deel van de installatie, met examen.",
                "lessons": []},
    "FGAS-H": {"name": "F-gassen: herhaling en examen", "regulation": None, "mandatory": True, "tags": ["warmtepompen", "f-gassen", "herhaling", "examen"],
               "description": "Een dag om je F-gassencertificaat categorie 1 te verlengen: de regels en de lekcontrole opnieuw, daarna het examen in theorie en praktijk.",
               "lessons": [("Theorie: regels, koudemiddelen en lekcontrole", 1, "ochtend",
                            "Wat de regels vragen, welke koudemiddelen er zijn en hoe je een lekcontrole doet en vastlegt."),
                           ("Examen: theorie en praktijk in de hal", 1, "middag",
                            "Het examen van de exameninstelling: eerst de vragen, daarna de praktijkopdracht aan een opstelling in de hal.")]},
    "WP-B": {"name": "Warmtepompen installeren: basis", "regulation": None, "mandatory": False, "tags": ["warmtepompen", "basis"],
             "description": "Twee dagen voor monteurs die beginnen met warmtepompen: plaatsen, aansluiten en opstarten op echte opstellingen in de praktijkhal.",
             "lessons": [("Dag 1: zo werkt een warmtepomp", 1, None,
                          "De koudekringloop, de soorten warmtepompen en een buitenunit plaatsen in de praktijkhal."),
                         ("Dag 2: aansluiten en opstarten", 2, None,
                          "Een warmtepomp aansluiten op de cv-installatie, vullen, ontluchten en voor het eerst opstarten.")]},
    "WP-LW": {"name": "Lucht-water warmtepomp: ontwerp en inbedrijfstelling", "regulation": None, "mandatory": False,
              "tags": ["warmtepompen", "lucht-water", "gevorderd"],
              "description": "Drie dagen: het juiste vermogen kiezen, de installatie ontwerpen, hem in bedrijf stellen en overdragen aan de bewoner.",
              "lessons": [("Dag 1: warmteverlies en het juiste vermogen", 1, None,
                           "Het warmteverlies van een woning berekenen en een lucht-water warmtepomp kiezen die past."),
                          ("Dag 2: het ontwerp van de installatie", 2, None,
                           "Afgifte, buffervat en warm tapwater: een ontwerp maken voor een echte woning."),
                          ("Dag 3: inbedrijfstelling en overdracht", 3, None,
                           "De installatie in bedrijf stellen, de instellingen vastleggen en de bewoner uitleggen hoe hij werkt.")]},
    "WZI": {"name": "Waterzijdig inregelen", "regulation": None, "mandatory": False, "tags": ["warmtepompen", "inregelen", "basis"],
            "description": "Een dag inregelen: het debiet per radiator of groep berekenen, instellen en meten, zodat het hele huis warm wordt.",
            "lessons": [("Waterzijdig inregelen in de praktijk", 1, None,
                         "Het debiet per radiator of groep berekenen, de installatie inregelen en het resultaat meten.")]},
}

# The company's register entries (invented: a KvK number starting with 0 is never issued).
STORY_KVK = "09412000"
# The identity reference the eHerkenning stub returns for Linda; the employer account is invited with it.
STORY_EHERKENNING = "eherkenning-jansen-installatietechniek"
# When the planner confirmed the places: (day, hour, minute) per booking number.
STORY_CONFIRMED = {377: (dt.date(2026, 9, 9), 11, 0), 412: (dt.date(2026, 9, 16), 10, 15), 425: (dt.date(2026, 9, 23), 9, 30)}

# The four employees of Jansen Installatietechniek. Youssef's birth date is missing on
# purpose: the portal asks Linda for it before the F-gassen exam (birthDate absent).
STORY_PEOPLE = [
    ("tom", "Tom", "Verbeek", "1988-06-12"),
    ("youssef", "Youssef", "El Amrani", None),
    ("sanne", "Sanne", "Kok", "1993-02-27"),
    ("daan", "Daan", "Visser", "2001-09-04"),
]

# The editions: (course, inschrijving number, trainer, days, people, enrolment lifecycle, enrolled at).
# The inschrijving number I-2026-NNNN has no field of its own; Enrolment.volgnummer carries NNNN.
STORY_EDITIONS = [
    ("WZI", 377, FATIMA, [dt.date(2026, 10, 1)], ["sanne"], "completed", (dt.date(2026, 9, 8), 10, 20)),
    ("FGAS-H", 412, HENK, [dt.date(2026, 10, 8)], ["tom", "youssef", "sanne"], "active", (dt.date(2026, 9, 14), 9, 40)),
    ("WZI", None, FATIMA, [dt.date(2026, 10, 15)], [], None, None),
    ("WP-B", 425, FATIMA, [dt.date(2026, 10, 20), dt.date(2026, 10, 21)], ["daan"], "active", (dt.date(2026, 9, 21), 14, 5)),
    ("WP-LW", 431, HENK, [dt.date(2026, 11, 3), dt.date(2026, 11, 4), dt.date(2026, 11, 10)], ["tom", "sanne"], "pending",
     (dt.date(2026, 10, 2), 15, 20)),
]


def story_credential(b: Builder, profile: dict, course: dict, kind: str, issued: str, issuer: tuple[str, str], fields: dict) -> dict:
    """A credential in the shape the set's own credential() writes, for a story course and any issuer."""
    name, did = issuer
    domain = did.removeprefix("did:web:")
    obj = b.add("credential", {
        "learnerId": profile["uuid"], "learnerUserId": profile["ncUserId"], "courseId": course["uuid"], "kind": kind,
        "issuedAt": issued, "issuerDid": did, "signature": "", "openbadges3Payload": {}, "issuedBy": name, **fields,
    })
    obj["signature"] = f"voorbeeld.{obj['slug']}.niet-geldig"
    obj["verificationUrl"] = f"https://{domain}/verify/{obj['uuid']}"
    obj["openbadges3Payload"] = {
        "@context": ["https://www.w3.org/ns/credentials/v2", "https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json"],
        "id": f"urn:uuid:{obj['uuid']}", "type": ["VerifiableCredential", "OpenBadgeCredential"],
        "issuer": {"id": did, "type": ["Profile"], "name": name}, "validFrom": issued,
        "credentialSubject": {"type": ["AchievementSubject"], "achievement": {
            "id": f"https://{domain}/achievements/{course['code'].lower()}", "type": ["Achievement"], "name": course["name"],
            "description": course["description"]}},
    }
    if obj.get("expiresAt"):
        obj["openbadges3Payload"]["validUntil"] = obj["expiresAt"]
    return obj


NL_WEEKDAYS = ["maandag", "dinsdag", "woensdag", "donderdag", "vrijdag", "zaterdag", "zondag"]
EXAM_TAGS = {"examen", "certificaat"}


def employer_booking_facts(booking: dict, course: dict, sessions: list[dict], participants: list[tuple[dict, dict]],
                           renewed: dict, trainer: str | None, place: str | None) -> dict:
    """A port of lib/Service/Portal/EmployerBookingFacts.php::derive(); EmployerBookingFactsTest checks the two agree
    on the seeded bookings (employer-portal-audience)."""
    def parse(value):
        return dt.datetime.fromisoformat(value).astimezone(AMS) if value else None

    def day_and_date(d):
        return f"{NL_WEEKDAYS[d.weekday()]} {d.day} {MAAND[d.month - 1]}"

    def list_of(items):
        return items[0] if len(items) == 1 else ", ".join(items[:-1]) + " en " + items[-1]

    def due(first):
        if first is None:
            return None
        d = first - dt.timedelta(days=1)
        while d.weekday() > 4:
            d -= dt.timedelta(days=1)
        return stamp(d, 12, 0)

    days = sorted({parse(x["startsAt"]).date() for x in sessions})
    first = days[0] if days else None
    participants = sorted([p for p in participants if p[0].get("lifecycle") != "withdrawn"], key=lambda p: p[0]["uuid"])
    states = [e.get("lifecycle", "pending") for e, _p in participants]
    if not states:
        lifecycle = booking.get("lifecycle") if booking.get("lifecycle") in ("cancelled", "confirmed") else "received"
    elif all(x in ("completed", "failed") for x in states):
        lifecycle = "completed"
    else:
        lifecycle = "confirmed" if "active" in states else "received"
    needs = bool(EXAM_TAGS & {t.lower() for t in course.get("tags", [])}) and lifecycle in ("received", "confirmed")
    enrolments, names, refs, missing = {}, [], [], 0
    for e, p in participants:
        name = f"{p.get('givenName', '')} {p.get('familyName', '')}".strip()
        credential = renewed.get(e["uuid"])
        line = None
        if credential and credential.get("expiresAt"):
            x = parse(credential["expiresAt"])
            line = f"Certificaat geldig tot {x.day} {MAAND[x.month - 1]} {x.year}"
        fields = {"detailsStatus": "complete", "openTask": None, "openTaskNote": None, "openTaskDueAt": None, "certificateLine": line}
        if needs and not p.get("birthDate"):
            given = p.get("givenName", "").strip()
            weekday = f" {NL_WEEKDAYS[first.weekday()]}" if first else ""
            fields.update({"detailsStatus": "birth-date-missing", "openTask": f"Vul de geboortedatum van {name} in",
                           "openTaskNote": f"{given} doet{weekday} examen. Zonder geboortedatum kunnen wij {given} niet aanmelden.",
                           "openTaskDueAt": due(first)})
            missing += 1
        enrolments[e["uuid"]] = fields
        names.append(name)
        refs.append(p["uuid"])
    places = max(1, int(booking.get("participantCount") or len(participants)))
    open_places = max(0, places - len(participants))
    if lifecycle in ("completed", "cancelled"):
        status = lifecycle
    else:
        status = "waiting-for-you" if open_places or missing else lifecycle
    if status == "waiting-for-you" and open_places:
        note = "Vul de naam van 1 deelnemer in" if open_places == 1 else f"Vul de namen van {open_places} deelnemers in"
    elif status == "waiting-for-you":
        note = f"Geboortedatum van {missing} {'deelnemer' if missing == 1 else 'deelnemers'} ontbreekt"
    elif lifecycle == "confirmed":
        note = "De plek staat vast" if places == 1 else "De plekken staan vast"
    elif lifecycle == "received" and booking.get("requestedAt"):
        d, count = parse(booking["requestedAt"]).date(), 2
        while count:
            d += dt.timedelta(days=1)
            if d.weekday() < 5:
                count -= 1
        note = "Bevestiging uiterlijk " + day_and_date(d)
    else:
        note = "Afgerond" if lifecycle == "completed" else None
    if not days:
        label = None
    elif len(days) <= 2:
        parts = []
        for i, d in enumerate(days):
            same = i + 1 < len(days) and days[i + 1].strftime("%Y-%m") == d.strftime("%Y-%m")
            parts.append(f"{NL_WEEKDAYS[d.weekday()]} {d.day}" if same else day_and_date(d))
        label = " en ".join(parts)
    else:
        months: dict[str, list[str]] = {}
        for d in days:
            months.setdefault(MAAND[d.month - 1], []).append(str(d.day))
        label = list_of([f"{list_of(v)} {k}" for k, v in months.items()])
    first_sessions = [x for x in sessions if first and parse(x["startsAt"]).date() == first]
    time_label = None
    if first_sessions:
        time_label = (min(parse(x["startsAt"]).strftime("%H.%M") for x in first_sessions) + " tot "
                      + max(parse(x["endsAt"]).strftime("%H.%M") for x in first_sessions) + " uur")
    course_name = (course.get("name") or "").strip() or None
    day = {"firstDay": first.isoformat() if first else None, "dayLabel": label, "timeLabel": time_label, "placeLabel": place,
           "trainerName": trainer, "upcoming": lifecycle in ("received", "confirmed")}
    for key in enrolments:
        enrolments[key].update(day)
    return {
        "booking": {
            "courseName": course_name,
            "bookingLabel": ", ".join(x for x in [course.get("name", "").strip(), label or ""] if x) or None,
            "upcoming": lifecycle in ("received", "confirmed"),
            "firstDay": first.isoformat() if first else None, "dayLabel": label, "timeLabel": time_label,
            "placeLabel": place, "trainerName": trainer, "participantRefs": refs, "participantNames": ", ".join(names) or None,
            "missingDetailsCount": missing, "lifecycle": lifecycle, "employerStatus": status, "statusNote": note,
            "detailsDueAt": due(first),
        },
        "enrolments": enrolments,
    }


def stamp_employer_copies(b: Builder) -> None:
    """The readable copies ReadableCopies writes on a live save (employer-portal-audience): every profile's
    fullName, and every enrolment's learnerName, courseName and organisationRef. Runs last; no random number."""
    profiles = {p["uuid"]: p for p in b.buckets["learner-profile"]}
    courses = {c["uuid"]: c for c in b.buckets["course"]}
    for p in profiles.values():
        p["fullName"] = f"{p.get('givenName', '')} {p.get('familyName', '')}".strip() or None
    for e in b.buckets["enrolment"]:
        p = profiles.get(e.get("learnerRef") or "")
        e["learnerName"] = p["fullName"] if p else None
        e["courseName"] = (courses.get(e.get("courseId") or "", {}).get("name") or "").strip() or None
        e["organisationRef"] = p.get("organisationRef") if p else None


def stamp_certificate_copies(b: Builder) -> None:
    """The readable copies CertificateCopies writes on a live save (portal-certificates): holder, course,
    employer, "Geldig tot ..." and the booked renewal. Runs last; no random number."""
    profiles = {p["uuid"]: p for p in b.buckets["learner-profile"]}
    courses = {c["uuid"]: c for c in b.buckets["course"]}
    enrolments = {e["uuid"]: e for e in b.buckets["enrolment"]}
    first_day: dict[str, dt.date] = {}
    for x in b.buckets["session"]:
        d = dt.datetime.fromisoformat(x["startsAt"]).astimezone(AMS).date()
        if x["cohortId"] not in first_day or d < first_day[x["cohortId"]]:
            first_day[x["cohortId"]] = d
    for c in b.buckets["credential"]:
        p = profiles.get(c.get("learnerId") or "")
        name = f"{p.get('givenName', '')} {p.get('familyName', '')}".strip() if p else ""
        c["learnerName"] = name or None
        c["courseName"] = (courses.get(c.get("courseId") or "", {}).get("name") or "").strip() or None
        c["organisationRef"] = p.get("organisationRef") if p else None
        valid = None
        if c.get("expiresAt"):
            x = dt.datetime.fromisoformat(c["expiresAt"]).astimezone(AMS)
            valid = f"Geldig tot {x.day} {MAAND[x.month - 1]} {x.year}"
        c["validUntilLabel"] = valid
        renewal = None
        e = enrolments.get(c.get("renewalEnrolmentId") or "")
        if e and e.get("lifecycle") in ("pending", "active") and e.get("cohortId") in first_day:
            d = first_day[e["cohortId"]]
            renewal = f"Herhaling op {d.day} {MAAND[d.month - 1]}"
        c["renewalLine"] = renewal


def add_story(b: Builder, school: dict, location: dict) -> None:
    """Jansen Installatietechniek BV and its four installers at the Warmtepompacademie, week 41 of 2026.

    Linda Jansen is the employer and training contact. The set models a client
    company as a contact person (a learner profile with the manager role and the
    company as department) whose ncUserId is the managerId of its employees, so
    Linda is that, and the four installers are participants. Every date is
    absolute and comes from the portal designs.
    """
    # Rooms in the praktijkhal.
    lokaal = b.add("room", {"name": "Lokaal 2", "code": "WPA-L2", "capacity": 12, "kind": "classroom",
                            "facilities": ["beamer", "whiteboard"], "buildingCode": "00X600", "floor": "0"})
    hal = b.add("room", {"name": "Praktijkhal", "code": "WPA-HAL", "capacity": 12, "kind": "other",
                         "facilities": ["werkende lucht-water warmtepompen", "hybride opstellingen", "bodemwarmtepomp", "meetapparatuur"],
                         "buildingCode": "00X600", "floor": "0"})
    room_of = {"ochtend": lokaal, "middag": hal}

    # The catalogue courses of the story, with their lessons.
    courses: dict[str, dict] = {}
    lessons: dict[str, list[tuple[dict, int, str | None]]] = {}
    for key, spec in STORY_COURSES.items():
        fields = {
            "code": f"{key}-2627", "name": spec["name"], "name_nl": spec["name"], "description": spec["description"], "level": "corporate",
            "language": "nl", "tags": list(spec["tags"]), "mandatoryTraining": spec["mandatory"], "lifecycle": "published",
        }
        if spec["regulation"]:
            fields["regulationSlug"] = spec["regulation"]
        courses[key] = b.add("course", fields)
        lessons[key] = []
        for order, (title, day, part, text) in enumerate(spec["lessons"], start=1):
            if part is None:
                minutes = sum((h2 * 60 + m2) - (h1 * 60 + m1) for (h1, m1), (h2, m2) in HALL_SLOTS.values())
            else:
                (h1, m1), (h2, m2) = HALL_SLOTS[part]
                minutes = (h2 * 60 + m2) - (h1 * 60 + m1)
            row = b.add("lesson", {
                "courseId": courses[key]["uuid"], "name": title, "order": order, "contentType": "text",
                "blocks": [{"blockId": "b1", "type": "richText", "order": 1, "text": text}],
                "durationMinutes": minutes, "learningObjectives": [text], "mandatoryTraining": spec["mandatory"], "lifecycle": "published",
            })
            if spec["regulation"]:
                row["regulationSlug"] = spec["regulation"]
            lessons[key].append((row, day, part))
    courses["FGAS-1"]["renewalCourseSlug"] = courses["FGAS-H"]["slug"]

    # Linda Jansen and her four installers.
    contacts = [p for p in b.buckets["learner-profile"] if p["ncUserId"].startswith("training-contactpersoon-")]
    participants = [p for p in b.buckets["learner-profile"] if p["ncUserId"].startswith("training-deelnemer-")]
    linda = b.add("learner-profile", {
        "ncUserId": f"training-contactpersoon-{len(contacts) + 1:03d}", "givenName": "Linda", "familyName": "Jansen",
        "roles": ["manager"], "eduPersonAffiliation": ["affiliate"], "department": STORY_COMPANY,
        "parentIds": [], "guardianRefs": [], "lifecycle": "active",
    })
    company = b.add("client-organisation", {
        "name": STORY_COMPANY, "kvkNumber": STORY_KVK, "eherkenningRef": STORY_EHERKENNING, "contactName": "Linda Jansen",
        "contactEmail": "linda.jansen@jansen-installatietechniek.example", "locationId": location["uuid"], "lifecycle": "active",
    })
    people: dict[str, dict] = {}
    for n, (key, given, family, birth) in enumerate(STORY_PEOPLE, start=len(participants) + 1):
        fields = {"ncUserId": f"training-deelnemer-{n:03d}", "givenName": given, "familyName": family}
        if birth is not None:
            fields["birthDate"] = birth
        fields.update({
            "schoolId": school["uuid"], "roles": ["learner"], "eduPersonAffiliation": ["affiliate"], "managerId": linda["ncUserId"],
            "department": f"{STORY_COMPANY}/Montage", "parentIds": [], "guardianRefs": [], "lifecycle": "active",
            "organisationRef": company["uuid"],
        })
        people[key] = b.add("learner-profile", fields)

    # Staff: the planner and the administration exist; the two trainers are new.
    working: dict[str, set[int]] = {}
    for _key, _number, trainer, days, _who, _state, _at in STORY_EDITIONS:
        working.setdefault(trainer, set()).update(d.weekday() for d in days)
    for trainer, quals in ((HENK, ["trainer F-gassen en koudetechniek", "trainer warmtepompen"]),
                           (FATIMA, ["trainer warmtepompen", "trainer inregelen en hydrauliek"])):
        b.add("staff", {"ncUserId": trainer, "roles": ["teacher"], "qualifications": quals,
                        "workingDays": [WEEKDAYS[i] for i in sorted(working[trainer])]})

    # Editions: a cohort, who teaches it, a morning and an afternoon session per day, the enrolments.
    enrolments: dict[tuple[int, str], dict] = {}
    bookings: list[tuple[dict, dict, list[dict], list[dict]]] = []
    for key, number, trainer, days, who, state, enrolled in STORY_EDITIONS:
        course = courses[key]
        done = days[-1] < STORY_TODAY
        first = days[0]
        cohort = b.add("cohort", {
            "name": f"{course['name']}, {first.day} {MAAND[first.month - 1]} {first.year}",
            "period": f"{MAAND[first.month - 1].capitalize()} {first.year}", "academicYear": STORY_YEAR,
            "teacherIds": [trainer], "learnerIds": [people[p]["ncUserId"] for p in who],
            "lifecycle": "completed" if done else "planned", "locationId": location["uuid"],
            "notes": "In de praktijkhal: theorie in Lokaal 2, na de lunch in de hal.", "kind": "teaching", "courseId": course["uuid"],
        })
        b.add("subjectteacherassignment", {"cohortId": cohort["uuid"], "courseId": course["uuid"], "teacherId": trainer})
        sessions = []
        for day_number, d in enumerate(days, start=1):
            for part in ("ochtend", "middag"):
                (h1, m1), (h2, m2) = HALL_SLOTS[part]
                lesson = next((row for row, day, p in lessons[key] if day == day_number and p in (None, part)), None)
                when = f"{d.day} {MAAND[d.month - 1]} {d.year}"
                title = f"{course['name']}, {part} ({when})" if len(days) == 1 else f"{course['name']}, dag {day_number} {part} ({when})"
                sessions.append((b.add("session", {
                    "cohortId": cohort["uuid"], "courseId": course["uuid"], "lessonId": lesson["uuid"] if lesson else None, "title": title,
                    "startsAt": stamp(d, h1, m1), "endsAt": stamp(d, h2, m2), "location": f"{room_of[part]['name']}, {location['name']}",
                    "roomId": room_of[part]["uuid"], "lifecycle": "completed" if done else "scheduled",
                }), d, part))
        booking = None
        if number is not None:
            confirmed = STORY_CONFIRMED.get(number)
            booking = b.add("course-booking", {
                "organisationRef": company["uuid"], "bookingNumber": f"I-2026-{number:04d}", "cohortId": cohort["uuid"],
                "courseId": course["uuid"], "participantCount": len(who), "requestedAt": stamp(*enrolled),
                "requestedByName": "Linda Jansen", "confirmedAt": stamp(*confirmed) if confirmed else None, "lifecycle": "received",
            })
            bookings.append((booking, course, [s for s, _d, _p in sessions], []))
        for p in who:
            profile = people[p]
            fields = {
                "learnerId": profile["ncUserId"], "learnerRef": profile["uuid"], "courseId": course["uuid"], "source": "hr",
                "mandatory": bool(STORY_COURSES[key]["mandatory"]), "managerId": linda["ncUserId"], "cohortId": cohort["uuid"],
                "lifecycle": state, "locationId": location["uuid"], "volgnummer": number,
                "requestedAt": stamp(*enrolled),
            }
            if STORY_COURSES[key]["regulation"]:
                fields["regulationSlug"] = STORY_COURSES[key]["regulation"]
            if booking is not None:
                fields["bookingRef"] = booking["uuid"]
                fields["organisationRef"] = company["uuid"]
            enrolments[(number, p)] = b.add("enrolment", fields)
            if booking is not None:
                bookings[-1][3].append(enrolments[(number, p)])
            if not done:
                continue
            for session, d, part in sessions:
                (h1, m1), (h2, m2) = HALL_SLOTS[part]
                b.add("attendance-record", {
                    "sessionId": session["uuid"], "learnerId": profile["ncUserId"], "learnerRef": profile["uuid"], "cohortId": cohort["uuid"],
                    "status": "present", "minutesAttended": (h2 * 60 + m2) - (h1 * 60 + m1), "markedBy": trainer,
                    "markedAt": stamp(d, h1, m1 + 10), "reason": None, "excuseRequestId": None,
                })

    # Sanne's proof of participation for Waterzijdig inregelen, ready on Thursday 1 October at 16.52.
    story_credential(b, people["sanne"], courses["WZI"], "badge", stamp(dt.date(2026, 10, 1), 16, 52), (INSTITUTE, ISSUER_DID),
                     {"source": "auto", "enrolmentId": enrolments[(377, "sanne")]["uuid"], "lifecycle": "issued"})
    # The certificates the company holds. F-gassen categorie 1 runs out on 30 November 2026, eight weeks after
    # today; the herhaling on 8 October is its renewal. OpenRegister computes expiryStatus from expiresAt.
    for p in ("tom", "youssef", "sanne"):
        story_credential(b, people[p], courses["FGAS-1"], "certificate", stamp(dt.date(2021, 11, 30), 10, 0), EXAM_COOLING, {
            "expiresAt": stamp(dt.date(2026, 11, 30), 23, 59), "source": "manual",
            "renewalEnrolmentId": enrolments[(412, p)]["uuid"], "lifecycle": "issued",
        })
    story_credential(b, people["sanne"], courses["BRL6000"], "certificate", stamp(dt.date(2023, 3, 12), 10, 0), EXAM_COOLING, {
        "expiresAt": stamp(dt.date(2028, 3, 12), 23, 59), "source": "manual", "lifecycle": "issued",
    })

    # What each booking says to Linda, as EmployerBookingProjection writes it.
    renewed = {c["renewalEnrolmentId"]: c for c in b.buckets["credential"] if c.get("renewalEnrolmentId")}
    trainers = {HENK: STORY_STAFF[HENK], FATIMA: STORY_STAFF[FATIMA]}
    cohorts = {c["uuid"]: c for c in b.buckets["cohort"]}
    for booking, course, sessions, rows in bookings:
        cohort = cohorts[booking["cohortId"]]
        facts = employer_booking_facts(booking, course, sessions, [(e, next(p for p in people.values() if p["uuid"] == e["learnerRef"])) for e in rows],
                                       renewed, trainers.get(cohort["teacherIds"][0]), f"{location['name']}, {location['street']}")
        booking.update(facts["booking"])
        for e in rows:
            e.update(facts["enrolments"][e["uuid"]])


def build() -> dict:
    rng = random.Random(20250901)
    b = Builder()
    sched = Scheduler()

    # --- institute, location, rooms -------------------------------------------
    school = b.add("school", {"brin": "00X6", "name": INSTITUTE, "pedagogicalConcept": "other"})
    location = b.add("vestiging", {
        "schoolId": school["uuid"], "vestigingscode": "00X600", "onderwijslocatiecode": None,
        "name": "Praktijkhal Zuiddrecht", "street": "Energieweg 8", "postalCode": "0612 EW", "city": TOWN,
    })
    rooms = {key: b.add("room", dict(spec)) for key, spec in ROOMS.items()}

    # --- the catalogue ----------------------------------------------------------
    courses: dict[str, dict] = {}
    lessons: dict[str, list[dict]] = {}

    def add_course(spec: dict, name: str, code: str, description: str, lifecycle: str) -> tuple[dict, list[dict]]:
        fields = {
            "code": code, "name": name, "name_nl": name, "description": description, "level": "corporate", "language": "nl",
            "tags": list(spec["tags"]), "mandatoryTraining": spec["mandatory"], "lifecycle": lifecycle,
        }
        if spec["regulation"]:
            fields["regulationSlug"] = spec["regulation"]
        course = b.add("course", fields)
        rows = []
        for order, (title, day, minutes, text) in enumerate(spec["lessons"], start=1):
            row = b.add("lesson", {
                "courseId": course["uuid"], "name": title, "order": order, "contentType": "text",
                "blocks": [{"blockId": "b1", "type": "richText", "order": 1, "text": text}],
                "durationMinutes": minutes, "learningObjectives": [text],
                "mandatoryTraining": spec["mandatory"], "lifecycle": "published" if lifecycle == "published" else "draft",
            })
            if spec["regulation"]:
                row["regulationSlug"] = spec["regulation"]
            row["_day"] = day
            rows.append(row)
        return course, rows

    for spec in COURSES:
        courses[spec["key"]], lessons[spec["key"]] = add_course(spec, spec["name"], spec["key"] + "-2526", spec["description"], "published")
    for spec in COURSES:
        if spec["renewal"]:
            courses[spec["key"]]["renewalCourseSlug"] = courses[spec["renewal"]]["slug"]

    # Next year's versions, made by exporting this year's course and reading it back in.
    drafts = {}
    for key in ("BHV-B", "LG-B"):
        spec = BY_KEY[key]
        drafts[key] = add_course(
            spec, spec["name"] + " 2026-2027 (concept)", key + "-2627",
            "Concept voor 2026-2027, gemaakt door de cursus van 2025-2026 te exporteren en opnieuw in te lezen.", "draft",
        )

    # --- course package imports and the export round trips ---------------------
    spr, plan = lessons["SPR"], lessons["PLAN"]
    b.add("course-package-import-report", {
        "sourceFormat": "common-cartridge-1.3", "sourceFilename": "spreadsheets-gevorderd-partnerpakket.imscc",
        "courseId": courses["SPR"]["uuid"], "importedBy": QUALITY, "importedAt": stamp(dt.date(2025, 8, 19), 10, 12),
        "lifecycle": "partial", "resourcesTotal": 6, "resourcesImported": 4, "resourcesDegraded": 1, "resourcesDropped": 1,
        "entries": [
            *[{"resourceIdentifier": f"res-{i:02d}", "resourceType": "webcontent", "title": lesson["name"], "outcome": "imported",
               "targetType": "lesson", "targetId": lesson["uuid"], "reason": None} for i, lesson in enumerate(spr, start=1)],
            {"resourceIdentifier": "res-05", "resourceType": "imswl_xmlv1p3", "title": "Naslag: functies op een rij", "outcome": "degraded",
             "targetType": "lesson", "targetId": spr[0]["uuid"],
             "reason": "Weblink omgezet naar een tekstblok in de les; het adres zelf is niet gecontroleerd."},
            {"resourceIdentifier": "res-06", "resourceType": "imsbasiclti_xmlv1p3", "title": "Oefenomgeving van de partner", "outcome": "dropped",
             "targetType": None, "targetId": None,
             "reason": "LTI-koppeling naar de oefenomgeving van de partner vraagt een eigen LTI-registratie en is niet overgenomen."},
        ],
    })
    b.add("course-package-import-report", {
        "sourceFormat": "moodle-backup", "sourceFilename": "effectief-plannen-oude-leeromgeving.mbz",
        "courseId": courses["PLAN"]["uuid"], "importedBy": QUALITY, "importedAt": stamp(dt.date(2025, 8, 20), 14, 5),
        "lifecycle": "succeeded", "resourcesTotal": len(plan), "resourcesImported": len(plan), "resourcesDegraded": 0, "resourcesDropped": 0,
        "entries": [{"resourceIdentifier": f"mod-page-{i}", "resourceType": "mod_page", "title": lesson["name"], "outcome": "imported",
                     "targetType": "lesson", "targetId": lesson["uuid"], "reason": None} for i, lesson in enumerate(plan, start=1)],
    })
    for day, (key, (draft, draft_lessons)) in zip((dt.date(2026, 6, 15), dt.date(2026, 6, 16)), drafts.items()):
        entries = [{"resourceIdentifier": "course", "resourceType": "course", "title": draft["name"], "outcome": "imported",
                    "targetType": "course", "targetId": draft["uuid"], "reason": None}]
        entries += [{"resourceIdentifier": f"lesson-{lesson['order']}", "resourceType": "lesson", "title": lesson["name"],
                     "outcome": "imported", "targetType": "lesson", "targetId": lesson["uuid"], "reason": None} for lesson in draft_lessons]
        b.add("course-package-import-report", {
            "sourceFormat": "scholiq-json", "sourceFilename": f"{key.lower()}-2025-2026.scholiq.json",
            "courseId": draft["uuid"], "importedBy": QUALITY, "importedAt": stamp(day, 11, 30), "lifecycle": "succeeded",
            "resourcesTotal": len(entries), "resourcesImported": len(entries), "resourcesDegraded": 0, "resourcesDropped": 0,
            "entries": entries,
        })

    # --- the leadership programme ----------------------------------------------
    programme = b.add("programme", {
        "name": PROGRAMME["name"], "code": PROGRAMME["code"], "level": "corporate", "description": PROGRAMME["description"],
        "courseIds": [courses[key]["uuid"] for key, _days in PROGRAMME["modules"]], "lifecycle": "published",
    })
    for key, _days in PROGRAMME["modules"]:
        courses[key]["programmeIds"] = [programme["uuid"]]

    # --- prices -----------------------------------------------------------------
    for spec in COURSES:
        if spec["price"] is None:
            continue
        b.add("fee-item", {
            "name": f"Deelname {spec['name']}", "description": "Prijs per deelnemer, inclusief lesmateriaal, lunch en certificaat.",
            "kind": "course-enrolment", "amount": float(spec["price"]), "currency": "EUR", "voluntary": False,
            "taxPosture": "education-exempt", "linkedCourseId": courses[spec["key"]]["uuid"], "academicYear": YEAR,
            "validFrom": FIRST_DAY.isoformat(), "validUntil": LAST_DAY.isoformat(), "lifecycle": "active",
        })
    b.add("fee-item", {
        "name": f"Deelname {PROGRAMME['name']}", "description": "Prijs per deelnemer voor alle drie de modules, inclusief intake en lesmateriaal.",
        "kind": "other", "amount": float(PROGRAMME["price"]), "currency": "EUR", "voluntary": False, "taxPosture": "education-exempt",
        "academicYear": YEAR, "validFrom": FIRST_DAY.isoformat(), "validUntil": LAST_DAY.isoformat(), "lifecycle": "active",
    })

    # --- item banks and items ---------------------------------------------------
    items: dict[str, list[dict]] = {}
    for family, (bank_name, subject, questions) in ITEMS.items():
        bank = b.add("item-bank", {"name": bank_name, "description": f"Kennisvragen voor de afsluitende toets, onderwerp {subject.lower()}.",
                                   "subject": subject, "itemIds": [], "lifecycle": "published"})
        items[family] = []
        for n, (question, options, correct) in enumerate(questions, start=1):
            item = b.add("item", {
                "itemBankId": bank["uuid"], "title": f"{bank_name}, vraag {n}", "interactionType": "choice",
                "qtiBody": qti(f"{family}-{n:02d}", f"{bank_name}, vraag {n}", question, options, correct),
                "correctResponse": {"value": correct}, "maxScore": 1, "subjectTags": [family],
                "difficulty": round(0.15 + 0.5 * rng.random(), 2), "lifecycle": "published",
            })
            item["_correct"] = correct
            items[family].append(item)
        bank["itemIds"] = [item["uuid"] for item in items[family]]

    # --- people -----------------------------------------------------------------
    used_names: set[str] = set()
    contacts: dict[str, dict] = {}
    participants: list[dict] = []
    for index, (company, size, units, contact, _demand) in enumerate(CLIENTS):
        if company:
            contacts[company] = b.add("learner-profile", {
                "ncUserId": f"training-contactpersoon-{len(contacts) + 1:03d}", "givenName": contact[0], "familyName": contact[1],
                "roles": ["manager"], "eduPersonAffiliation": ["affiliate"], "department": company,
                "parentIds": [], "guardianRefs": [], "lifecycle": "active",
            })
            used_names.add(contact[1])
        for _ in range(size):
            female = rng.random() < 0.5
            participants.append({
                "company": company, "client": index, "unit": rng.choice(units) if units else None,
                "given": rng.choice(ADULT_F if female else ADULT_M), "family": surname(rng, used_names),
                "birth": dt.date(1962, 1, 1) + dt.timedelta(days=rng.randrange(365 * 43)),
                "ability": rng.gauss(0, 1), "load": 0,
            })
    for n, p in enumerate(participants, start=1):
        p["nc"] = f"training-deelnemer-{n:03d}"
        p["contact"] = contacts.get(p["company"])
        p["profile"] = b.add("learner-profile", {
            "ncUserId": p["nc"], "givenName": p["given"], "familyName": p["family"], "birthDate": p["birth"].isoformat(),
            "schoolId": school["uuid"], "roles": ["learner"], "eduPersonAffiliation": ["affiliate"],
            "managerId": p["contact"]["ncUserId"] if p["contact"] else None,
            "department": f"{p['company']}/{p['unit']}" if p["company"] else "Particulier",
            "parentIds": [], "guardianRefs": [], "lifecycle": "active",
        })

    # --- who follows what: each client spreads its bookings over its staff ------
    demand: dict[str, list[dict]] = {key: [] for key in list(BY_KEY) + ["LG"]}
    for index, (_company, _size, _units, _contact, wanted) in enumerate(CLIENTS):
        staff_of = [p for p in participants if p["client"] == index]
        for key, count in wanted.items():
            taken = {id(p) for p in demand[key]}
            exclude = {id(p) for p in demand["BHV-H"]} if key == "BHV-B" else {id(p) for p in demand["BHV-B"]} if key == "BHV-H" else set()
            pool = [p for p in staff_of if id(p) not in taken and id(p) not in exclude]
            pool.sort(key=lambda p: (p["load"], rng.random()))
            for p in pool[:count]:
                demand[key].append(p)
                p["load"] += 3 if key == "LG" else 1

    # --- schedule: the programme first, then every open edition ----------------
    editions: list[dict] = []
    cohort_rows: list[tuple[dict, dict]] = []
    for start_index, first in enumerate(PROGRAMME["starts"]):
        day = first
        prog_days: list[tuple[str, dt.date]] = []
        for key, count in PROGRAMME["modules"]:
            for _ in range(count):
                while not open_day(day):
                    day += dt.timedelta(days=14)
                prog_days.append((key, day))
                day += dt.timedelta(days=14)
        last = prog_days[-1][1]
        cohort = {"programme": True, "first": first, "last": last, "start_index": start_index,
                  "name": f"{PROGRAMME['name']}, start {MAAND[first.month - 1]} {first.year}",
                  "period": f"{MAAND[first.month - 1].capitalize()} tot {MAAND[last.month - 1]} {last.year}"}
        for key, _count in PROGRAMME["modules"]:
            course = BY_KEY[key]
            days = [d for k, d in prog_days if k == key]
            trainer = PROGRAMME["trainer"][key]
            slots = sched.slots_of(course, days)
            resources = [trainer, "room:" + course["room"]]
            if not sched.free(slots, resources):
                raise RuntimeError(f"programme day taken: {key} {days}")
            sched.book(slots, resources)
            editions.append({"course": course, "cohort": cohort, "days": days, "trainer": trainer, "participants": [],
                             "rebooked": [], "programme": True})
        cohort_rows.append((cohort, {}))
    for spec in COURSES:
        for n, (year, month) in enumerate(spec["months"]):
            trainer = spec["trainers"][n % len(spec["trainers"])]
            days = sched.place(spec, year, month, trainer)
            cohort = {"programme": False, "first": days[0], "last": days[-1],
                      "name": f"{spec['name']}, {MAAND[days[0].month - 1]} {days[0].year}",
                      "period": f"{MAAND[days[0].month - 1].capitalize()} {days[0].year}"}
            editions.append({"course": spec, "cohort": cohort, "days": days, "trainer": trainer, "participants": [],
                             "rebooked": [], "programme": False})
            cohort_rows.append((cohort, {}))

    def first_of(edition: dict) -> dt.date:
        return edition["days"][0]

    # A storm cancels the January VCA day; it was held one week later.
    storm = next(e for e in editions if e["course"]["key"] == "VCA-B" and e["days"][0].month == 1)
    storm_day = storm["days"][0] - dt.timedelta(days=7)
    storm_slots = sched.slots_of(storm["course"], [storm_day])
    if not (open_day(storm_day) and sched.free(storm_slots, [storm["trainer"], "room:" + storm["course"]["room"]])):
        raise RuntimeError("the storm story needs a free day a week before the January VCA edition")
    sched.book(storm_slots, [storm["trainer"], "room:" + storm["course"]["room"]])
    storm["cancelled_day"] = storm_day
    # The trainer of the January BHV basis is ill on day 1; the other BHV trainer steps in.
    sick = next(e for e in editions if e["course"]["key"] == "BHV-B" and e["days"][0].month == 1)
    stand_in = next(t for t in sick["course"]["trainers"] if t != sick["trainer"])
    sick_slots = sched.slots_of(sick["course"], sick["days"][:1])
    if not sched.free(sick_slots, [stand_in]):
        raise RuntimeError("the stand-in trainer is not free on day 1 of the January BHV basis")
    sched.book(sick_slots, [stand_in])
    sick["substitute"] = stand_in

    # --- fill the editions: a company's people sit together ---------------------
    by_course: dict[str, list[dict]] = {}
    for e in editions:
        by_course.setdefault(e["course"]["key"] if not e["programme"] else "LG:" + e["course"]["key"], []).append(e)
    for key, people in demand.items():
        if key == "LG" or not people:
            continue
        eds = sorted(by_course.get(key, []), key=first_of)
        order = sorted(range(len(CLIENTS)), key=lambda _i: rng.random())
        queue = [p for i in order for p in people if p["client"] == i]
        base, extra = divmod(len(queue), len(eds))
        at = 0
        for n, e in enumerate(eds):
            take = min(base + (1 if n < extra else 0), e["course"]["max"] - 1)
            e["participants"] = queue[at:at + take]
            at += take
        if at != len(queue):
            raise RuntimeError(f"{key}: {len(queue) - at} people do not fit")
    lg = demand["LG"]
    rng.shuffle(lg)
    lg_cohorts = [lg[:12], lg[12:24]]
    for e in editions:
        if e["programme"]:
            e["participants"] = list(lg_cohorts[e["cohort"]["start_index"]])

    # --- the year, edition by edition -----------------------------------------
    enrolment_rows: list[dict] = []
    marks: list[dict] = []
    tests: list[dict] = []
    for e in sorted(editions, key=lambda e: (first_of(e), e["course"]["key"])):
        course = e["course"]
        slots = sched.slots_of(course, e["days"])
        for p in e["participants"]:
            forced = any(p is r for r in e["rebooked"])
            day_state = {}
            for d in e["days"]:
                r = rng.random()
                day_state[d] = "present" if forced or r >= 0.027 else ("ill" if r < 0.022 else "noshow")
            own = []
            for (d, part) in slots:
                length = minutes_of(course, part)
                first_part = (course["half"] is not None) or part == "ochtend"
                last_part = (course["half"] is not None) or part == "middag"
                if day_state[d] == "ill":
                    mark = ("absent-excused", "Ziek gemeld", None)
                elif day_state[d] == "noshow":
                    mark = ("absent-unexcused", "Niet verschenen en niet afgemeld", None)
                elif first_part and rng.random() < 0.04:
                    late = rng.choice([5, 10, 15, 20])
                    mark = ("late", rng.choice(["Vertraging in het openbaar vervoer", "File op de snelweg", "Parkeerplaats gezocht"]), length - late)
                elif last_part and rng.random() < 0.012:
                    early = rng.choice([30, 45, 60])
                    mark = ("left-early", rng.choice(["Afspraak bij de huisarts", "Kind ophalen van de opvang", "Dringend terug naar het werk"]), length - early)
                else:
                    mark = ("present", None, length)
                own.append({"p": p, "edition": e, "slot": (d, part), "status": mark[0], "reason": mark[1], "minutes": mark[2]})
            marks.extend(own)
            attended = sum(1 for m in own if m["status"] in ("present", "late", "left-early"))
            eligible = attended == len(own) if course["full"] else attended / len(own) >= course.get("min_ratio", 0.75)
            row = {"p": p, "edition": e, "rebooked": forced, "status": "completed", "reason": None, "tests": []}
            if not eligible:
                row["status"] = "withdrawn"
                why = "Ziek tijdens de training" if any(v == "ill" for v in day_state.values()) else "Niet verschenen zonder afmelding"
                later = None
                if not e["programme"]:
                    later = next((x for x in sorted(by_course[course["key"]], key=first_of)
                                  if first_of(x) > e["days"][-1] and len(x["participants"]) < course["max"]), None)
                if later is not None:
                    later["participants"].append(p)
                    later["rebooked"].append(p)
                    row["reason"] = f"{why}; omgeboekt naar de editie van {later['days'][0].day} {MAAND[later['days'][0].month - 1]} {later['days'][0].year}."
                elif e["programme"]:
                    row["reason"] = f"{why}; de module wordt ingehaald in de volgende leergang."
                else:
                    row["reason"] = f"{why}; dit jaar is er geen latere editie met plaats."
            elif course["exam"]:
                family, count, pass_mark, _minutes = EXAMS[course["exam"]]
                for attempt in (1, 2):
                    answers = []
                    for item in items[family][:count]:
                        chance = 1 / (1 + pow(2.718281828, -(1.6 + 0.9 * p["ability"] + 0.5 * attempt - 3 * (item["difficulty"] - 0.4))))
                        answers.append((item, rng.random() < chance))
                    score = sum(1 for _i, ok in answers if ok)
                    test = {"p": p, "edition": e, "attempt": attempt, "answers": answers, "score": score, "passed": score >= pass_mark}
                    tests.append(test)
                    row["tests"].append(test)
                    if test["passed"]:
                        break
                if not row["tests"][-1]["passed"]:
                    row["status"] = "failed"
                    row["reason"] = "Kennistoets twee keer niet gehaald."
            enrolment_rows.append(row)

    # --- staff, derived from the timetable -------------------------------------
    teaching_days: dict[str, set[int]] = {}
    for (d, _part), used in sched.busy.items():
        for resource in used:
            if resource.startswith("training-trainer-"):
                teaching_days.setdefault(resource, set()).add(d.weekday())
    b.add("staff", {"ncUserId": "training-directeur-01", "roles": ["administrator"], "qualifications": ["directeur"], "workingDays": WEEKDAYS})
    b.add("staff", {"ncUserId": PLANNER, "roles": ["coordinator"], "qualifications": ["opleidingsplanner"], "workingDays": WEEKDAYS})
    b.add("staff", {"ncUserId": QUALITY, "roles": ["coordinator"], "qualifications": ["kwaliteitszorg", "examencommissie"],
                    "workingDays": ["monday", "tuesday", "thursday"]})
    b.add("staff", {"ncUserId": "training-administratie-01", "roles": ["administrator", "support-staff"], "qualifications": ["cursusadministratie"],
                    "workingDays": WEEKDAYS[:4]})
    for trainer, quals in TRAINERS.items():
        b.add("staff", {"ncUserId": trainer, "roles": ["teacher"], "qualifications": quals,
                        "workingDays": [WEEKDAYS[i] for i in sorted(teaching_days.get(trainer, set()))]})

    # --- cohorts ------------------------------------------------------------------
    for cohort, _unused in cohort_rows:
        eds = [e for e in editions if e["cohort"] is cohort]
        learners: list[str] = []
        for e in eds:
            for p in e["participants"]:
                if p["nc"] not in learners:
                    learners.append(p["nc"])
        teachers = []
        for e in eds:
            for t in [e["trainer"]] + ([e["substitute"]] if e.get("substitute") else []):
                if t not in teachers:
                    teachers.append(t)
        notes = None
        if storm in eds:
            notes = f"De eerste lesdag op {storm_day.day} {MAAND[storm_day.month - 1]} is vervallen door code oranje; de training is een week later gegeven."
        if sick in eds:
            notes = "Op dag 1 was de vaste trainer ziek; een collega-instructeur heeft de dag overgenomen."
        if eds[0]["course"]["room"] == "online":
            notes = "Online training in het online klaslokaal."
        fields = {
            "name": cohort["name"], "period": cohort["period"], "academicYear": YEAR, "teacherIds": teachers,
            "learnerIds": learners, "lifecycle": "completed", "locationId": location["uuid"], "notes": notes, "kind": "teaching",
        }
        if cohort["programme"]:
            fields["programmeId"] = programme["uuid"]
        else:
            fields["courseId"] = courses[eds[0]["course"]["key"]]["uuid"]
        cohort["row"] = b.add("cohort", fields)

    # --- enrolments ---------------------------------------------------------------
    renewal_of: dict[int, dict] = {}
    for row in sorted(enrolment_rows, key=lambda r: (first_of(r["edition"]), r["edition"]["course"]["key"], r["p"]["nc"])):
        p, e = row["p"], row["edition"]
        course = e["course"]
        if e["programme"]:
            source = "admission"
        elif course["key"] == "BHV-H" and not row["rebooked"]:
            source = "credential-renewal"
        elif p["company"]:
            source = "hr"
        else:
            source = "self"
        fields = {
            "learnerId": p["nc"], "learnerRef": p["profile"]["uuid"], "courseId": courses[course["key"]]["uuid"], "source": source,
            "mandatory": bool(course["mandatory"] and p["company"]), "managerId": p["contact"]["ncUserId"] if p["contact"] else None,
            "cohortId": e["cohort"]["row"]["uuid"], "lifecycle": row["status"], "locationId": location["uuid"],
        }
        if course["regulation"]:
            fields["regulationSlug"] = course["regulation"]
        if row["reason"]:
            fields["reason"] = row["reason"]
        if source == "credential-renewal":
            # The old certificate expired a few weeks before this edition; the renewal was booked on expiry.
            expired = first_of(e) - dt.timedelta(days=rng.randint(10, 40))
            row["old_expiry"] = expired
            fields["dueDate"] = (expired + dt.timedelta(days=90)).isoformat()
        row["obj"] = b.add("enrolment", fields)
        if source == "credential-renewal":
            renewal_of[id(p)] = row

    # --- intake rounds, applications and the waiting list ------------------------
    rounds = []
    for first, deadline, lifecycle, year in ((PROGRAMME["starts"][0], dt.date(2025, 8, 29), "closed", YEAR),
                                             (PROGRAMME["starts"][1], dt.date(2026, 1, 23), "closed", YEAR),
                                             (dt.date(2026, 9, 8), dt.date(2026, 8, 28), "open", "2026-2027")):
        rounds.append(b.add("admissions-round", {
            "name": f"{PROGRAMME['name']}, start {MAAND[first.month - 1]} {first.year}", "kind": "generic",
            "programmeId": programme["uuid"], "level": "corporate", "academicYear": year,
            "applicationDeadline": deadline.isoformat(), "mandatoryIntake": True, "capacity": 12, "lifecycle": lifecycle,
        }))

    def applicant(round_obj: dict, given: str, family: str, birth: dt.date, submitted: dt.date, lifecycle: str, **extra) -> dict:
        fields = {
            "applicantGivenName": given, "applicantFamilyName": family, "applicantBirthDate": birth.isoformat(),
            "priorSchool": rng.choice(PRIOR_EDUCATION), "admissionsRoundId": round_obj["uuid"], "programmeId": programme["uuid"],
            "submittedAuthLevel": "basic", "submittedAt": stamp(submitted, rng.randint(8, 21), rng.choice([5, 20, 35, 50])),
            "lifecycle": lifecycle,
        }
        fields.update(extra)
        return b.add("admission", fields)

    def intake(submitted: dt.date) -> dict:
        day = next_office_day(submitted + dt.timedelta(days=rng.randint(3, 7)))
        return {"intakeScheduledAt": stamp(day, rng.choice([10, 11, 14, 15]), 0), "intakeConductedBy": "training-trainer-05",
                "intakeCompleted": True, "_day": day}

    lg_rows = {id(r["p"]): [] for r in enrolment_rows if r["edition"]["programme"]}
    for r in enrolment_rows:
        if r["edition"]["programme"]:
            lg_rows[id(r["p"])].append(r)
    notes_ok = ["Wil groeien van teamleider naar afdelingshoofd; motivatie helder.", "Net leidinggevende geworden; zoekt houvast bij lastige gesprekken.",
                "Heeft al ervaring als projectleider; leergang sluit goed aan.", "Werkgever steunt de aanmelding; doelen voor het eerste jaar besproken."]
    moved = lg_cohorts[1][:2]
    for start_index, round_obj in enumerate(rounds[:2]):
        deadline = dt.date.fromisoformat(round_obj["applicationDeadline"])
        people = lg_cohorts[start_index]
        if start_index == 0:
            people = people + moved
        for n, p in enumerate(people):
            submitted = deadline - dt.timedelta(days=rng.randint(4, 11) if start_index == 0 else rng.randint(5, 40))
            meet = intake(submitted)
            decided = next_office_day(meet["_day"] + dt.timedelta(days=2))
            if start_index == 0 and any(p is m for m in moved):
                applicant(round_obj, p["given"], p["family"], p["birth"], submitted, "withdrawn",
                          intakeScheduledAt=meet["intakeScheduledAt"], intakeConductedBy=meet["intakeConductedBy"], intakeCompleted=True,
                          intakeNotes="Geschikt; de groep is vol.", decisionType="waitlisted",
                          decisionReason="Groep vol; doorgeschoven naar de start in februari 2026.", decidedBy=PLANNER, decidedAt=stamp(decided, 16, 0))
                continue
            converted = [r["obj"]["uuid"] for r in sorted(lg_rows[id(p)], key=lambda r: first_of(r["edition"]))]
            applicant(round_obj, p["given"], p["family"], p["birth"], submitted, "converted",
                      intakeScheduledAt=meet["intakeScheduledAt"], intakeConductedBy=meet["intakeConductedBy"], intakeCompleted=True,
                      intakeNotes=rng.choice(notes_ok), decisionType="placed", decidedBy=PLANNER, decidedAt=stamp(decided, 16, 0),
                      convertedLearnerProfileId=p["profile"]["uuid"], convertedEnrolmentIds=converted)
        if start_index == 1:
            for _ in range(3):
                submitted = deadline - dt.timedelta(days=rng.randint(1, 10))
                meet = intake(submitted)
                decided = next_office_day(meet["_day"] + dt.timedelta(days=2))
                female = rng.random() < 0.5
                applicant(round_obj, rng.choice(ADULT_F if female else ADULT_M), surname(rng, used_names),
                          dt.date(1970, 1, 1) + dt.timedelta(days=rng.randrange(365 * 30)), submitted, "waitlisted",
                          intakeScheduledAt=meet["intakeScheduledAt"], intakeConductedBy=meet["intakeConductedBy"], intakeCompleted=True,
                          intakeNotes="Geschikt; wacht op een plek.", decisionType="waitlisted",
                          decisionReason="Groep vol; eerste keus voor de start in september 2026.", decidedBy=PLANNER, decidedAt=stamp(decided, 16, 0))
            female = rng.random() < 0.5
            applicant(round_obj, rng.choice(ADULT_F if female else ADULT_M), surname(rng, used_names),
                      dt.date(1970, 1, 1) + dt.timedelta(days=rng.randrange(365 * 30)), deadline - dt.timedelta(days=20), "withdrawn",
                      decisionReason="Zelf afgemeld voor het intakegesprek: andere functie aangenomen.")
    for n in range(4):
        submitted = dt.date(2026, 6, 1) + dt.timedelta(days=rng.randint(0, 25))
        female = rng.random() < 0.5
        extra = {}
        lifecycle = "submitted"
        if n < 2:
            lifecycle = "intake-scheduled"
            extra = {"intakeScheduledAt": stamp(next_office_day(min(submitted + dt.timedelta(days=7), dt.date(2026, 7, 9))), 14, 0),
                     "intakeConductedBy": "training-trainer-05"}
        applicant(rounds[2], rng.choice(ADULT_F if female else ADULT_M), surname(rng, used_names),
                  dt.date(1970, 1, 1) + dt.timedelta(days=rng.randrange(365 * 30)), submitted, lifecycle, **extra)

    # --- who teaches what, per cohort ------------------------------------------
    for e in editions:
        b.add("subjectteacherassignment", {"cohortId": e["cohort"]["row"]["uuid"], "courseId": courses[e["course"]["key"]]["uuid"],
                                           "teacherId": e["trainer"]})

    # --- sessions ---------------------------------------------------------------
    planned = []
    for e in editions:
        course = e["course"]
        for index, (d, part) in enumerate(sched.slots_of(course, e["days"])):
            planned.append((d, part, e, False))
        if e.get("cancelled_day"):
            for part in ("ochtend", "middag"):
                planned.append((e["cancelled_day"], part, e, True))
    planned.sort(key=lambda x: (x[0], x[1] != "ochtend", x[2]["course"]["key"]))
    session_of: dict[tuple[int, dt.date, str], dict] = {}
    for d, part, e, cancelled in planned:
        course = e["course"]
        (h1, m1), (h2, m2) = slot_times(course, part)
        day_number = (e["days"].index(d) + 1) if d in e["days"] else 1
        lesson = next((row for row in lessons[course["key"]] if row["_day"] == day_number), None)
        when = f"{d.day} {MAAND[d.month - 1]} {d.year}"
        if course["half"]:
            title = f"{course['name']} ({when})"
        elif course["days"] == 1:
            title = f"{course['name']}, {part} ({when})"
        else:
            title = f"{course['name']}, dag {day_number} {part} ({when})"
        if e["programme"]:
            title = f"{PROGRAMME['name']}: {title}"
        fields = {
            "cohortId": e["cohort"]["row"]["uuid"], "courseId": courses[course["key"]]["uuid"],
            "lessonId": lesson["uuid"] if lesson else None, "title": title,
            "startsAt": stamp(d, h1, m1), "endsAt": stamp(d, h2, m2), "location": ROOMS[course["room"]]["name"],
            "roomId": rooms[course["room"]]["uuid"], "lifecycle": "cancelled" if cancelled else "completed",
        }
        learners = [p["nc"] for p in e["participants"] if not any(p is r for r in e["rebooked"])]
        if cancelled:
            fields.update({"changeReasonKind": "other", "changeReason": "Code oranje voor zware storm; de training is een week later gegeven.",
                           "affectedLearnerIds": learners, "changedAt": stamp(d - dt.timedelta(days=1), 17, 0)})
        elif e.get("substitute") and d == e["days"][0]:
            fields.update({"substituteTeacherId": e["substitute"], "changeReasonKind": "teacher-absence",
                           "changeReason": "De vaste trainer is ziek; een collega-instructeur neemt de dag over.",
                           "affectedLearnerIds": learners, "changedAt": stamp(d, 7, 45)})
        row = b.add("session", fields)
        if not cancelled:
            session_of[(id(e), d, part)] = row

    def trainer_on(e: dict, d: dt.date) -> str:
        return e["substitute"] if e.get("substitute") and d == e["days"][0] else e["trainer"]

    # --- knowledge tests --------------------------------------------------------
    exam_of: dict[int, dict] = {}
    for e in sorted(editions, key=first_of):
        course = e["course"]
        if not course["exam"]:
            continue
        family, count, pass_mark, minutes = EXAMS[course["exam"]]
        last_day = e["days"][-1]
        last = session_of[(id(e), last_day, "middag")]
        until = min(last_day + dt.timedelta(days=14), LAST_DAY)
        exam_of[id(e)] = b.add("exam", {
            "title": f"Kennistoets {course['name']}, {MAAND[e['days'][0].month - 1]} {e['days'][0].year}",
            "description": f"{count} meerkeuzevragen; geslaagd vanaf {pass_mark} goed. Een herkansing binnen twee weken.",
            "courseId": courses[course["key"]]["uuid"], "sessionId": last["uuid"], "cohortId": e["cohort"]["row"]["uuid"],
            "itemRefs": [{"itemId": item["uuid"], "points": 1} for item in items[family][:count]], "itemSelectionMode": "fixed",
            "shuffleItemOrder": True, "shuffleAnswerOptions": True, "scoringScheme": "passMark", "passMark": pass_mark,
            "timeLimitMinutes": minutes, "maxAttempts": 2, "keepScore": "best",
            "availableFrom": stamp(last_day, 15, 30), "availableUntil": stamp(until, 17, 0), "lifecycle": "closed",
        })
        e["exam_until"] = until
    for test in sorted(tests, key=lambda t: (first_of(t["edition"]), t["attempt"], t["p"]["nc"])):
        e = test["edition"]
        last_day = e["days"][-1]
        if test["attempt"] == 1:
            day, start = last_day, (15, 30)
        else:
            day = last_day + dt.timedelta(days=7)
            while not office_day(day):
                day += dt.timedelta(days=1)
            if day > e["exam_until"]:
                day = e["exam_until"]
                while not office_day(day):
                    day -= dt.timedelta(days=1)
            start = (10, 0)
        took = rng.randint(12, EXAMS[e["course"]["exam"]][3] - 2)
        responses = []
        for item, ok in test["answers"]:
            wrong = rng.choice([x for x in "ABC" if x != item["_correct"]])
            responses.append({"itemId": item["uuid"], "response": {"value": item["_correct"] if ok else wrong}, "autoScore": 1.0 if ok else 0.0})
        test["obj"] = b.add("assessment-result", {
            "assessmentId": exam_of[id(e)]["uuid"], "learnerId": test["p"]["nc"], "attemptNumber": test["attempt"], "responses": responses,
            "startedAt": stamp(day, *start), "submittedAt": stamp(day, start[0], start[1] + took) if start[1] + took < 60
            else stamp(day, start[0] + 1, start[1] + took - 60), "lifecycle": "graded",
        })
        test["day"] = day

    # --- excuses and attendance -------------------------------------------------
    excuse_of: dict[tuple[str, dt.date], dict] = {}
    for m in sorted(marks, key=lambda m: (m["slot"][0], m["p"]["nc"])):
        d = m["slot"][0]
        if m["status"] != "absent-excused" or (m["p"]["nc"], d) in excuse_of:
            continue
        p = m["p"]
        by_contact = p["contact"] is not None and rng.random() < 0.3
        submitter = p["contact"] if by_contact else p["profile"]
        excuse_of[(p["nc"], d)] = b.add("excuse-request", {
            "learnerId": p["nc"], "learnerRef": p["profile"]["uuid"], "submittedBy": submitter["ncUserId"], "submittedByRef": submitter["uuid"],
            "dateFrom": d.isoformat(), "dateTo": d.isoformat(), "reason": rng.choice(["Griep", "Koorts", "Migraine", "Rugklachten", "Buikgriep"]),
            "reasonKind": "illness", "submittedAuthLevel": "basic", "decidedBy": PLANNER, "decidedAt": stamp(d, 8, 45),
            "decisionNote": None, "lifecycle": "approved",
        })
    for m in sorted(marks, key=lambda m: (m["slot"][0], m["slot"][1] != "ochtend", m["edition"]["course"]["key"], m["p"]["nc"])):
        e, (d, part), p = m["edition"], m["slot"], m["p"]
        session = session_of[(id(e), d, part)]
        (h1, m1), _end = slot_times(e["course"], part)
        marked = h1 * 60 + m1 + (25 if m["status"] == "late" else 10)
        b.add("attendance-record", {
            "sessionId": session["uuid"], "learnerId": p["nc"], "learnerRef": p["profile"]["uuid"], "cohortId": e["cohort"]["row"]["uuid"],
            "status": m["status"], "minutesAttended": m["minutes"], "markedBy": trainer_on(e, d), "markedAt": stamp(d, marked // 60, marked % 60),
            "reason": m["reason"], "excuseRequestId": excuse_of[(p["nc"], d)]["uuid"] if m["status"] == "absent-excused" else None,
        })

    # --- attestations and certificates -----------------------------------------
    issued_rows = [r for r in enrolment_rows if r["status"] == "completed"]
    issued_rows.sort(key=lambda r: (r["edition"]["days"][-1], r["edition"]["course"]["key"], r["p"]["nc"]))
    for row in issued_rows:
        e, p, course = row["edition"], row["p"], row["edition"]["course"]
        if not course["regulation"]:
            continue
        best = max((t["score"] for t in row["tests"]), default=None)
        fields = {
            "learnerId": p["nc"], "lessonId": lessons[course["key"]][-1]["uuid"], "courseId": courses[course["key"]]["uuid"],
            "regulationSlug": course["regulation"],
            "actorIp": f"198.51.100.{10 + int(p['nc'][-3:]) % 200}" if course["room"] == "online" else "192.0.2.24",
            "signature": f"voorbeeld-hmac-{len(b.buckets['attestation']) + 1:06d}-niet-geldig", "signingKeyId": "voorbeeld-sleutel-2025",
            "lifecycle": "signed",
        }
        if best is not None:
            fields["score"] = float(best)
        b.add("attestation", fields)

    def credential(p: dict, course_key: str, issued: dt.datetime | dt.date, fields: dict) -> dict:
        spec = BY_KEY[course_key]
        obj = b.add("credential", {
            # learnerId is the LearnerProfile uuid; learnerUserId its Nextcloud user id, the read rule's key.
            "learnerId": p["profile"]["uuid"], "learnerUserId": p["profile"]["ncUserId"],
            "courseId": courses[course_key]["uuid"], "kind": spec["credential"],
            "issuedAt": issued, "issuerDid": ISSUER_DID, "signature": "", "openbadges3Payload": {}, "issuedBy": INSTITUTE,
            **fields,
        })
        obj["signature"] = f"voorbeeld.{obj['slug']}.niet-geldig"
        obj["verificationUrl"] = f"https://{DOMAIN}/verify/{obj['uuid']}"
        obj["openbadges3Payload"] = {
            "@context": ["https://www.w3.org/ns/credentials/v2", "https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json"],
            "id": f"urn:uuid:{obj['uuid']}", "type": ["VerifiableCredential", "OpenBadgeCredential"],
            "issuer": {"id": ISSUER_DID, "type": ["Profile"], "name": INSTITUTE}, "validFrom": obj["issuedAt"],
            "credentialSubject": {"type": ["AchievementSubject"], "achievement": {
                "id": f"https://{DOMAIN}/achievements/{course_key.lower()}", "type": ["Achievement"], "name": spec["name"],
                "description": spec["description"]}},
        }
        if obj.get("expiresAt"):
            obj["openbadges3Payload"]["validUntil"] = obj["expiresAt"]
        return obj

    # Last year's BHV certificates, migrated from the old learning environment, expired and renewed this year.
    for row in sorted(renewal_of.values(), key=lambda r: (r["old_expiry"], r["p"]["nc"])):
        expired = row["old_expiry"]
        issued = add_months(expired, -12)
        credential(row["p"], rng.choice(["BHV-B", "BHV-H"]), stamp(issued, 10, 0), {
            "expiresAt": stamp(expired, 10, 0), "source": "migrated", "regulationSlug": "ARBOWET-BHV",
            "renewalEnrolmentId": row["obj"]["uuid"], "lifecycle": "expired",
        })
    for row in issued_rows:
        e, p, course = row["edition"], row["p"], row["edition"]["course"]
        done = row["tests"][-1]["day"] if row["tests"] else e["days"][-1]
        issued = next_office_day(done + dt.timedelta(days=2))
        fields = {"source": "auto", "enrolmentId": row["obj"]["uuid"], "lifecycle": "issued"}
        if course["validity"]:
            fields["expiresAt"] = stamp(add_months(issued, course["validity"]), 10, 0)
        if course["regulation"]:
            fields["regulationSlug"] = course["regulation"]
        row["credential"] = credential(p, course["key"], stamp(issued, 10, 0), fields)

    # --- course evaluations and what they led to --------------------------------
    def quarter_of(day: dt.date) -> int:
        return next(i for i, (_label, start, end) in enumerate(QUARTERS) if start <= day <= end)

    campaigns = []
    for index, (label, start, end) in enumerate(QUARTERS):
        eds = [e for e in editions if quarter_of(e["days"][-1]) == index]
        closes = min(end + dt.timedelta(days=21), LAST_DAY)
        campaigns.append(b.add("evaluation-campaign", {
            "name": f"Cursusevaluatie {label.lower()} {YEAR}", "closesAt": stamp(closes, 23, 59),
            "courseIds": list(dict.fromkeys(courses[e["course"]["key"]]["uuid"] for e in eds)),
            "cohortIds": list(dict.fromkeys(e["cohort"]["row"]["uuid"] for e in eds)), "academicYear": YEAR, "period": label,
            "instrumentKind": "built-in",
            "questions": [{"questionId": q, "text": {"nl": nl, "en": en}, "kind": kind, "required": kind != "free-text"}
                          for q, nl, en, kind in EVALUATION_QUESTIONS],
            "anonymityPolicy": "fully-anonymous", "reminderSchedule": {"enabled": True, "leadDays": 5}, "lifecycle": "closed",
        }))
        campaigns[-1]["_closes"] = closes
    after_action: set[tuple[str, int]] = set()
    for key, _q, target, _state, _f, _a in IMPROVEMENTS:
        t = [label for label, _s, _e in QUARTERS].index(target)
        for later in range(t, len(QUARTERS)):
            after_action.add((key, later))
    invited = [r for r in enrolment_rows if r["status"] in ("completed", "failed")]
    invited.sort(key=lambda r: (r["edition"]["days"][-1], r["edition"]["course"]["key"], r["p"]["nc"]))
    responses = []
    for row in invited:
        e, p, course = row["edition"], row["p"], row["edition"]["course"]
        q = quarter_of(e["days"][-1])
        campaign = campaigns[q]
        answered = rng.random() < 0.68
        when = None
        if answered:
            day = min(e["days"][-1] + dt.timedelta(days=rng.randint(0, 12)), campaign["_closes"])
            when = stamp(day, rng.randint(8, 21), rng.choice([2, 17, 33, 48]))
        b.add("evaluation-invitation", {
            "campaignId": campaign["uuid"], "courseId": courses[course["key"]]["uuid"], "cohortId": e["cohort"]["row"]["uuid"],
            "learnerId": p["nc"], "hasResponded": answered, "respondedAt": when, "campaignClosesAt": campaign["closesAt"],
            "academicYear": YEAR, "period": QUARTERS[q][0],
        })
        if answered:
            responses.append((row, q, when))
    rng.shuffle(responses)
    responses.sort(key=lambda x: (x[1], x[0]["edition"]["course"]["key"], x[2]))
    scores: dict[tuple[str, int], list[tuple[int, str]]] = {}
    for row, q, when in responses:
        e, course = row["edition"], row["edition"]["course"]
        base = QUALITY_BASE.get(course["key"], (4.2, 4.3))[1 if (course["key"], q) in after_action else 0]
        overall = max(1, min(5, round(rng.gauss(base, 0.7))))
        answers = []
        for qid, _nl, _en, kind in EVALUATION_QUESTIONS:
            if kind == "likert-5":
                penalty = 0.6 if qid == "q3" and course["key"] == "HEF" and (course["key"], q) not in after_action else 0
                value = overall if qid == "q5" else max(1, min(5, round(overall + rng.gauss(0, 0.6) - penalty)))
                answers.append({"questionId": qid, "ratingValue": value})
            elif rng.random() < 0.3 and course["key"] in FREE_TEXT:
                answers.append({"questionId": qid, "textValue": rng.choice(FREE_TEXT[course["key"]])})
        b.add("course-evaluation-response", {
            "campaignId": campaigns[q]["uuid"], "courseId": courses[course["key"]]["uuid"], "cohortId": e["cohort"]["row"]["uuid"],
            "teacherId": e["trainer"], "academicYear": YEAR, "period": QUARTERS[q][0], "overallScore": float(overall), "answers": answers,
            "submittedAt": when, "lifecycle": "submitted",
        })
        scores.setdefault((course["key"], q), []).append((overall, when))
    invitations: dict[tuple[str, int], int] = {}
    for row in invited:
        key = (row["edition"]["course"]["key"], quarter_of(row["edition"]["days"][-1]))
        invitations[key] = invitations.get(key, 0) + 1
    quality: dict[tuple[str, int], dict] = {}
    for key in sorted(invitations, key=lambda k: (k[1], [c["key"] for c in COURSES].index(k[0]))):
        got = scores.get(key, [])
        quality[key] = b.add("course-quality-score", {
            "courseId": courses[key[0]]["uuid"], "teacherId": None, "academicYear": YEAR, "period": QUARTERS[key[1]][0],
            "responseCount": len(got), "invitationCount": invitations[key],
            "averageOverallScore": round(sum(s for s, _w in got) / len(got), 2) if got else None,
            "responseRate": round(len(got) / invitations[key], 2), "lastRecomputedAt": max((w for _s, w in got), default=None),
        })
    for key, label, target, state, findings, action in IMPROVEMENTS:
        q = [lab for lab, _s, _e in QUARTERS].index(label)
        if (key, q) not in quality:
            raise RuntimeError(f"improvement action for {key} in {label} has no quality score to answer")
        reviewed = next_office_day(campaigns[q]["_closes"] + dt.timedelta(days=7))
        b.add("improvement-action", {
            "campaignId": campaigns[q]["uuid"], "courseId": courses[key]["uuid"], "reviewedBy": QUALITY, "reviewedAt": stamp(reviewed, 14, 0),
            "findings": findings, "actionDescription": action, "targetPeriod": target, "lifecycle": state,
        })

    stamp_group_labels(b)

    # --- assemble -----------------------------------------------------------------
    for rows in b.buckets.values():
        for obj in rows:
            for private in [k for k in obj if k.startswith("_")]:
                del obj[private]
    # --- the regulations the courses and certificates answer to (D29) ------------
    # Regulation's own slug is its code (^[A-Z0-9_-]+$), and the contract takes the
    # slug from the object for such a schema. AVG is seeded by the register. The
    # institute trains people for their employers and obliges none of them itself,
    # so no audience is set: the rows name the certificate and how long it lasts.
    def regulation(code: str, name: str, description: str, criteria: str, renewal: int | None, annual: bool) -> None:
        fields = {"slug": code, "name": name, "description": description, "applicabilityCriteria": criteria,
                  "audienceScope": "role-specific", "audienceRoles": [], "requiresAnnualRenewal": annual}
        if renewal is not None:
            fields["renewalCycleMonths"] = renewal
        fields.update({"active": True, "ragRedThreshold": 70, "ragAmberThreshold": 90, "lifecycle": "published"})
        b.add("regulation", fields)

    regulation("VCA", "VCA, veiligheid, gezondheid en milieu",
               "Het VCA-diploma voor operationele medewerkers, tien jaar geldig.",
               "Wie op locatie werkt, als de werkgever of opdrachtgever het vraagt.", 120, False)
    regulation("ARBOWET-BHV", "Bedrijfshulpverlening (Arbowet artikel 15)",
               "Het BHV-certificaat: eerste hulp, brand blussen en ontruimen, met een herhaling elk jaar.",
               "Bedrijfshulpverleners die hun werkgever aanwijst.", 12, True)
    regulation("ARBOWET-PREVENTIE", "Preventiemedewerker (Arbowet artikel 13)",
               "De preventiemedewerker helpt de werkgever met de risico-inventarisatie en het plan van aanpak.",
               "Medewerkers die hun werkgever als preventiemedewerker aanwijst.", None, False)
    regulation("ARBOBESLUIT-HEFTRUCK", "Heftruckcertificaat (Arbobesluit artikel 7.32)",
               "Een heftruck bedienen mag alleen met aantoonbare deskundigheid; het certificaat is vijf jaar geldig.",
               "Heftruckchauffeurs in magazijn, bouw en installatie.", 60, False)
    regulation("NIS2", "NIS2, informatiebeveiliging voor medewerkers",
               "Wat de NIS2-richtlijn van medewerkers vraagt: phishing herkennen, incidenten melden en veilig werken.",
               "Medewerkers van organisaties die onder NIS2 vallen, zoals zorg en gemeenten.", 12, True)

    add_story(b, school, location)
    stamp_employer_copies(b)
    stamp_certificate_copies(b)

    shipped = {r["slug"] for r in b.buckets["regulation"]}
    for rows in b.buckets.values():
        for row in rows:
            code = row.get("regulationSlug")
            if code is not None and code != "AVG" and code not in shipped:
                raise RuntimeError(f"{row['slug']} points at regulation {code}, which this set does not ship")

    objects = {name: rows for name, rows in b.buckets.items() if rows}
    total = sum(len(rows) for rows in objects.values())
    return {
        "openapi": "3.0.0",
        "info": {
            "title": "Learniq example set: Training institute",
            "version": "1.2.0",
            "description": (f"{INSTITUTE}, a fictional trade academy for installers in the fictional town of {TOWN}, through the 2025-2026 year, "
                            "and the autumn of 2026 for one client company, Jansen Installatietechniek BV, as the portal designs show it."),
        },
        "x-openregister": {
            "type": "profile",
            "app": "learniq",
            "profile": {
                "id": SET,
                "segment": SET,
                "label": "Training institute",
                "description": "A fictional training institute with open courses, participants from client companies and a full year.",
                "order": 6,
                "objectCount": total,
                "icon": "AccountSchoolOutline",
            },
            "description": (
                "An example set an operator picks in the first-time setup wizard (ADR-042, decision D21). NEVER imported on install. "
                "Every object carries @self.configuration/register/schema and a fixed uuid in the ee06 namespace, so the import resolves "
                "the live learniq register without this descriptor declaring components.registers (which would re-point the register at "
                "this profile config id and overwrite its authorization block), a second load adds nothing, and occ "
                "learniq:example-set:remove training removes exactly these objects. Generated by scripts/example-sets/training.py; the "
                "contract is openspec/changes/archive/2026-09-28-segment-wizard-choice/contract.md. Every person, company, address and code in it is fictional."
            ),
            "seedData": {
                "description": (
                    "One training institute with one location and a public catalogue of fourteen courses with prices, about 150 "
                    "participants from six client companies and private individuals, trainers who teach every edition, a morning and "
                    "an afternoon session per training day with every participant marked, knowledge tests with resits, signed attestations, "
                    "certificates with expiry and renewal, a leadership programme with intake rounds and a waiting list, quarterly course "
                    "evaluations with quality scores and improvement actions, the course package imports and export round trips, "
                    "the regulations the certificates answer to, and the autumn of 2026 for one client company: Jansen Installatietechniek BV, "
                    "its four installers, their F-gassen and heat pump courses in October and November, and the certificates they hold."
                ),
                "objects": objects,
            },
        },
        "paths": {},
        "components": {},
    }


def stamp_group_labels(b: Builder) -> None:
    """Every pupil's group line, as LearnerGroupLabel writes it on a live save
    (school-portals-use-the-new-blocks): the group of the newest active enrolment,
    and " · " with the first teacher's display name when the portal declaration
    names that teacher (lib/Settings/portals/<set>.json accounts; the load command
    gives those accounts that name). Runs last and draws no random number."""
    declaration = os.path.join(ROOT, "lib", "Settings", "portals", f"{SET}.json")
    names = {}
    if os.path.exists(declaration):
        with open(declaration, encoding="utf-8") as handle:
            names = {a["userId"]: a["displayName"] for a in json.load(handle).get("accounts", [])}
    cohorts = {c["uuid"]: c for c in b.buckets.get("cohort", [])}
    newest: dict[str, dict] = {}
    for enrolment in b.buckets.get("enrolment", []):
        ref = enrolment.get("learnerRef")
        if enrolment.get("lifecycle") != "active" or not ref or enrolment.get("cohortId") not in cohorts:
            continue
        if ref not in newest or str(enrolment.get("inschrijvingDate", "")) > str(newest[ref].get("inschrijvingDate", "")):
            newest[ref] = enrolment
    for profile in b.buckets.get("learner-profile", []):
        enrolment = newest.get(profile["uuid"])
        if "learner" not in (profile.get("roles") or []) or enrolment is None:
            continue
        cohort = cohorts[enrolment["cohortId"]]
        teacher = names.get(((cohort.get("teacherIds") or [None])[0]) or "")
        profile["groupLabel"] = cohort["name"] + (" · " + teacher if teacher else "")


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
            print(f"{OUT} is out of date; run python3 scripts/example-sets/training.py", file=sys.stderr)
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
