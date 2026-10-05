#!/usr/bin/env python3
# SPDX-License-Identifier: EUPL-1.2
# Copyright (C) 2026 Conduction B.V.
"""Build lib/Settings/profiles/vo.json, the secondary school example set.

One fictional havo/vwo school, Vaartveld College in the fictional town of
Zuiddrecht, through one complete school year (2025-2026): an
onderbouw building for years 1 and 2 and a main building for years 3 to 6,
eleven classes from the havo/vwo brugklas to havo 5 and vwo 6, about 300
pupils with their guardians, a mentor and subject teachers per class, a school
day per class per day with the absences, late arrivals and early departures a
secondary school records, three verzuim flags, three report periods ending in
three toetsweken, the leerjaar 3 profielkeuze, the exam classes' schoolexamen
(SE) grades on a PTA with their SE final grades, one schooladvies received at
intake, and the decaan, zorgcoordinator and attendance desk at work.

THE STORY LAYER. On top of that year sits one pupil's autumn of 2026-2027,
pinned to Monday 5 October 2026 (week 41), the day the Vaartveld portal
designs show: Noor Bakker of havo 3 (class H3b) moved up to 4 havo, class H4b,
with the economie en maatschappij profile she chose in the spring. Her mentor
Sanne Kramer, her Monday lessons with a room change and a cancelled lesson,
the grades behind her averages, this week's homework and tests, her absence,
the H4b mentor-talk round and the school calendar are added by add_story()
after every other object, with no draw from the main random stream, so no
earlier uuid or value moves. See openspec/changes/example-sets-are-the-four-schools/specs/example-sets/spec.md.

WHY A SCRIPT. The set is several thousand objects that must agree with each
other: an absence falls on a school day of the pupil's own class, a late
arrival is marked by a teacher of that class who works that weekday, a grade
is never given on a day the pupil was absent, an SE final grade is the
weighted average of its SE grades, and a report card counts that pupil's marks
and shows that pupil's grades. Hand-editing that is how sets drift. The script
is deterministic (fixed seed), so running it again produces the same file byte
for byte, and a reviewer reads the rules here rather than megabytes of JSON.

THE CONTRACT. openspec/changes/archive/2026-09-28-segment-wizard-choice/contract.md. Every rule is
checked by tests/Unit/Settings/ExampleSetDescriptorContractTest.php, and the
story by tests/Unit/Settings/SecondarySchoolExampleSetTest.php; run both after
regenerating.

Usage:
    python3 scripts/example-sets/vo.py            write the file
    python3 scripts/example-sets/vo.py --check    exit 1 when the file on disk differs

Nothing here is real: no real school, BRIN, person, address or phone number.
Postcodes start with 0 and phone numbers with 06-0, which the Netherlands never
issues; the BRIN 00X2 ends in a digit, which DUO never assigns.
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import random
from decimal import ROUND_HALF_UP, Decimal
import sys
from zoneinfo import ZoneInfo

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
OUT = os.path.join(ROOT, "lib", "Settings", "profiles", "vo.json")
AMS = ZoneInfo("Europe/Amsterdam")
TENANT = "00000000-0000-4000-8000-000000000000"
SET = "vo"
SET_NUMBER = "02"
YEAR = "2025-2026"
NEXT_YEAR = "2026-2027"
FIRST_DAY = dt.date(2025, 8, 18)
LAST_DAY = dt.date(2026, 7, 10)
# The exam classes stop before the central exam (CE) starts in May.
EXAM_LAST_DAY = dt.date(2026, 4, 17)

# Schema number (the TTTT group of the uuid) per bucket, in load order.
# Parents come before children; removal runs in reverse.
SCHEMAS = [
    "school",
    "vestiging",
    "room",
    "grade-scale",
    "course",
    "curriculum-plan",
    "programme",
    "cohort",
    "staff",
    "learner-profile",
    "enrolment",
    "subjectteacherassignment",
    "report-period",
    "admissions-round",
    "admission",
    "school-advies",
    "subject-choice",
    "session",
    "excuse-request",
    "attendance-record",
    "attendance-threshold",
    "attendance-flag",
    "exam",
    "exam-accommodation",
    "grade-entry",
    "final-grade",
    "report-card",
    "support-request",
    "dossier-note",
    "standby-slot",
    "lesson-note",
    "timetable-visibility-policy",
    # Appended, not inserted, so every earlier bucket keeps its uuid group.
    "session-change-batch",
    "display-screen",
    "elective-offer",
    "elective-sign-up",
    "enrolment-forecast",
]

# The same fictional region as the primary school set, so both sets agree.
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
    (dt.date(2025, 10, 1), "Studiedag team"),
    (dt.date(2025, 11, 26), "Rapportvergaderingen periode 1"),
    (dt.date(2026, 3, 11), "Rapportvergaderingen periode 2"),
    (dt.date(2026, 6, 29), "Rapportvergaderingen overgang"),
]
PERIODS = [
    ("1", "Periode 1", FIRST_DAY, dt.date(2025, 11, 21)),
    ("2", "Periode 2", dt.date(2025, 11, 24), dt.date(2026, 3, 6)),
    ("3", "Periode 3", dt.date(2026, 3, 9), LAST_DAY),
]
# Toetsweek per period for the non-exam classes; the exam classes sit their
# SE1 and SE2 in the first two and SE3 in their own SE week before Easter.
TOETSWEKEN = {
    "1": (dt.date(2025, 11, 10), dt.date(2025, 11, 14)),
    "2": (dt.date(2026, 3, 2), dt.date(2026, 3, 6)),
    "3": (dt.date(2026, 6, 15), dt.date(2026, 6, 19)),
}
SE_WEKEN = {
    "1": TOETSWEKEN["1"],
    "2": TOETSWEKEN["2"],
    "3": (dt.date(2026, 3, 30), dt.date(2026, 4, 2)),
}
# Toets slots in a toetsweek: start, end.
SLOTS = [((8, 30), (10, 10)), ((10, 40), (12, 20)), ((13, 0), (14, 40))]
# The bell: lesson hour n runs from BELL[n-1][0] to BELL[n-1][1], 50 minutes.
BELL = [((8, 30), (9, 20)), ((9, 20), (10, 10)), ((10, 30), (11, 20)), ((11, 20), (12, 10)),
        ((12, 40), (13, 30)), ((13, 30), (14, 20)), ((14, 30), (15, 20)), ((15, 20), (16, 10))]
LESSON = 50
WEEKDAYS = ["monday", "tuesday", "wednesday", "thursday", "friday"]
DAG = ["maandag", "dinsdag", "woensdag", "donderdag", "vrijdag", "zaterdag", "zondag"]
MAAND = ["januari", "februari", "maart", "april", "mei", "juni", "juli", "augustus", "september", "oktober", "november", "december"]

BOYS = [
    "Daan", "Sem", "Luuk", "Bram", "Finn", "Milan", "Levi", "Noah", "Jesse", "Thijs", "Mees", "Siem", "Julian", "Ruben",
    "Jayden", "Lars", "Thomas", "Tim", "Stijn", "Gijs", "Adam", "Mohammed", "Yusuf", "Liam", "Olivier", "Hugo", "Otis",
    "Teun", "Boaz", "Jens", "Guus", "Hidde", "Floris", "Vince", "Ravi", "Koen", "Joep", "Sven", "Niek", "Mats", "Ayoub",
    "Rayan", "Dex", "Kaan", "Wessel", "Jurre", "Owen", "Stan",
]
GIRLS = [
    "Emma", "Julia", "Mila", "Tess", "Sophie", "Zoë", "Sara", "Nora", "Yara", "Eva", "Evi", "Liv", "Lotte", "Noor",
    "Saar", "Lieke", "Fleur", "Anna", "Nina", "Maud", "Isa", "Roos", "Fenna", "Lina", "Amira", "Hanna", "Jasmijn",
    "Floor", "Ilse", "Vera", "Femke", "Esmee", "Norah", "Elin", "Luna", "Fay", "Mirthe", "Sanne", "Iris", "Merel", "Ines",
    "Salma", "Demi", "Puck", "Romy", "Silke", "Hira", "Jade",
]
ADULT_M = ["Mark", "Peter", "Jeroen", "Bas", "Martijn", "Erik", "Rick", "Dennis", "Tarik", "Hasan", "Joost", "Wouter",
           "Sander", "Niels", "Arjen", "Pieter", "Ricardo", "Karim", "Vincent", "Maarten", "Yilmaz", "Edwin"]
ADULT_F = ["Linda", "Sanne", "Esther", "Marloes", "Anouk", "Kim", "Iris", "Fatima", "Laura", "Nicole", "Petra", "Judith",
           "Ingrid", "Samira", "Eline", "Mirjam", "Chantal", "Naima", "Monique", "Hatice", "Carolien", "Wendy"]
SURNAME_HEAD = ["Esdoorn", "Varen", "Brem", "Tijm", "Salie", "Lavendel", "Vlas", "Rogge", "Kamille", "Hop", "Mos",
                "Heuvel", "Kreek", "Schelp", "Duinriet", "Zegge", "Wederik", "Boekweit", "Munt", "Klaver"]
SURNAME_TAIL = ["hof", "kamp", "veld", "hout", "laan", "brink", "horst", "dal", "meer", "wijk", "berg", "rode", "stein", "gaard"]
STREETS = ["Esdoornlaan", "Varenstraat", "Bremweg", "Tijmhof", "Saliepad", "Lavendelhof", "Vlasakker", "Roggeveld",
           "Kamillestraat", "Hoppepad", "Mosveen", "Heuvelrug", "Kreekoever", "Schelpenpad"]
PRIOR_SCHOOLS = ["Voorbeeldschool De Wilgenboom", "Voorbeeldschool Het Kompas", "Voorbeeldschool De Vlinderhof",
                 "Voorbeeldschool De Regenboog", "Voorbeeldschool Het Mozaïek", "Voorbeeldschool De Esdoornhoeve"]

# Subject code: (course name, teaching language, grading offset).
SUBJECTS = {
    "NE": ("Nederlands", "nl", 0.0),
    "EN": ("Engels", "en", 0.2),
    "FA": ("Frans", "fr", -0.2),
    "DU": ("Duits", "de", -0.1),
    "WI": ("Wiskunde", "nl", -0.1),
    "WA": ("Wiskunde A", "nl", -0.1),
    "WB": ("Wiskunde B", "nl", -0.3),
    "GS": ("Geschiedenis", "nl", 0.1),
    "AK": ("Aardrijkskunde", "nl", 0.1),
    "EC": ("Economie", "nl", 0.0),
    "BE": ("Bedrijfseconomie", "nl", 0.0),
    "BI": ("Biologie", "nl", 0.1),
    "NASK": ("Natuur- en scheikunde", "nl", -0.1),
    "NA": ("Natuurkunde", "nl", -0.3),
    "SK": ("Scheikunde", "nl", -0.2),
    "MA": ("Maatschappijleer", "nl", 0.3),
    "CKV": ("Culturele en kunstzinnige vorming", "nl", 0.5),
    "LO": ("Lichamelijke opvoeding", "nl", 0.6),
    "BV": ("Beeldende vorming", "nl", 0.4),
    "MU": ("Muziek", "nl", 0.4),
}
ONDERBOUW_SUBJECTS = {
    1: ["NE", "EN", "FA", "WI", "GS", "AK", "BI", "LO", "BV", "MU"],
    2: ["NE", "EN", "FA", "DU", "WI", "GS", "AK", "BI", "NASK", "LO", "BV", "MU"],
    3: ["NE", "EN", "FA", "DU", "WI", "GS", "AK", "EC", "BI", "NA", "SK", "LO", "BV"],
}
# Gemeenschappelijk deel per stream and leerjaar (bovenbouw), before the package.
COMMON = {
    ("havo", 4): ["NE", "EN", "MA", "CKV", "LO"],
    ("havo", 5): ["NE", "EN", "LO"],
    ("vwo", 4): ["NE", "EN", "MA", "CKV", "LO"],
    ("vwo", 5): ["NE", "EN", "CKV", "LO"],
    ("vwo", 6): ["NE", "EN", "LO"],
}
KERNVAKKEN = ["NE", "EN", "WI"]
PROFILES = ["CM", "EM", "NG", "NT"]
PROFILE_WEIGHTS = [20, 35, 25, 20]
# Package electives per stream and profile; vwo adds a second foreign language.
PACKAGES = {
    "havo": {
        "CM": [["GS", "AK", "DU", "BV"], ["GS", "AK", "FA", "EC"]],
        "EM": [["EC", "WA", "GS", "BE"], ["EC", "WA", "AK", "BE"], ["EC", "WA", "GS", "DU"]],
        "NG": [["BI", "SK", "WA", "AK"], ["BI", "SK", "WB", "NA"]],
        "NT": [["NA", "SK", "WB", "BI"], ["NA", "SK", "WB", "EC"]],
    },
    "vwo": {
        "CM": [["GS", "WA", "AK", "BV"], ["GS", "WA", "AK", "EC"]],
        "EM": [["EC", "WA", "GS", "BE"], ["EC", "WB", "AK", "BE"]],
        "NG": [["BI", "SK", "WA", "NA"], ["BI", "SK", "WB", "AK"]],
        "NT": [["NA", "SK", "WB", "BI"], ["NA", "SK", "WB", "EC"]],
    },
}
ELECTIVES = ["FA", "DU", "WA", "WB", "GS", "AK", "EC", "BE", "BI", "NA", "SK", "BV"]
PACKAGE_RULES = {"havo": (4, 5), "vwo": (5, 6)}

# Class, leerjaar, stream (hv = havo/vwo brugklas), size, location key, room key,
# lesson hours per weekday (Monday to Friday).
CLASSES = [
    ("1HV1", 1, "hv", 28, "onderbouw", "O1.01", [7, 6, 5, 7, 6]),
    ("1HV2", 1, "hv", 28, "onderbouw", "O1.02", [6, 7, 5, 6, 7]),
    ("2H1", 2, "havo", 28, "onderbouw", "O1.03", [7, 7, 5, 6, 6]),
    ("2V1", 2, "vwo", 27, "onderbouw", "O1.04", [7, 6, 6, 7, 6]),
    ("3H1", 3, "havo", 27, "hoofd", "H1.01", [8, 7, 5, 7, 6]),
    ("3V1", 3, "vwo", 26, "hoofd", "H1.02", [7, 8, 6, 7, 6]),
    ("4H1", 4, "havo", 28, "hoofd", "H1.03", [8, 7, 6, 7, 6]),
    ("4V1", 4, "vwo", 26, "hoofd", "H1.04", [8, 8, 6, 7, 6]),
    ("5H1", 5, "havo", 26, "hoofd", "H1.05", [8, 7, 6, 8, 5]),
    ("5V1", 5, "vwo", 25, "hoofd", "H1.06", [8, 8, 6, 7, 6]),
    ("6V1", 6, "vwo", 25, "hoofd", "H1.07", [8, 7, 6, 8, 5]),
]
EXAM_CLASSES = {"5H1": "havo 5", "6V1": "vwo 6"}
# The class name people read, in Vaartveld College's scheme (stream, leerjaar,
# letter: H4b). Only a display string: the keys above stay the script's own.
# Havo 3 is H3b because its pupils move up to H4b in the story layer.
CLASS_NAMES = {
    "1HV1": "HV1a", "1HV2": "HV1b", "2H1": "H2a", "2V1": "V2a", "3H1": "H3b", "3V1": "V3a",
    "4H1": "H4a", "4V1": "V4a", "5H1": "H5a", "5V1": "V5a", "6V1": "V6a",
}
MENTORS = {
    "1HV1": "vo-docent-02", "1HV2": "vo-docent-16", "2H1": "vo-docent-04", "2V1": "vo-docent-11",
    "3H1": "vo-docent-08", "3V1": "vo-docent-12", "4H1": "vo-docent-13", "4V1": "vo-docent-15",
    "5H1": "vo-docent-10", "5V1": "vo-docent-05", "6V1": "vo-docent-01",
}
# Teachers: user id, display name, subjects, degree (1 = eerstegraads), working days.
TEACHERS = [
    ("vo-docent-01", "Sanne Kramer", ["NE"], 1, WEEKDAYS),
    ("vo-docent-02", "Jeroen Bremhof", ["NE"], 2, ["monday", "tuesday", "wednesday", "thursday"]),
    ("vo-docent-03", "Ingrid Jansen", ["EN"], 1, WEEKDAYS),
    ("vo-docent-04", "Karim Saliedal", ["EN"], 2, ["monday", "tuesday", "thursday", "friday"]),
    ("vo-docent-05", "Esther Lavendelmeer", ["FA"], 1, ["monday", "tuesday", "wednesday", "thursday"]),
    ("vo-docent-06", "Pieter Vlasbrink", ["DU"], 1, ["tuesday", "wednesday", "thursday", "friday"]),
    ("vo-docent-07", "Emre Demir", ["WB", "WA"], 1, WEEKDAYS),
    ("vo-docent-08", "Bas Kamillehorst", ["WI"], 2, WEEKDAYS),
    ("vo-docent-09", "Laura Hopgaard", ["WA", "WI"], 1, ["monday", "wednesday", "thursday", "friday"]),
    ("vo-docent-10", "Arjen Willems", ["GS"], 1, WEEKDAYS),
    ("vo-docent-11", "Naima Vos", ["GS", "MA"], 2, ["monday", "tuesday", "wednesday", "friday"]),
    ("vo-docent-12", "Wouter Mulder", ["AK"], 1, WEEKDAYS),
    ("vo-docent-13", "Thomas de Boer", ["EC"], 1, ["monday", "tuesday", "thursday", "friday"]),
    ("vo-docent-14", "Ellen Hendriks", ["BE", "EC"], 1, ["monday", "tuesday", "wednesday", "thursday"]),
    ("vo-docent-15", "Judith Zeggeveld", ["BI"], 1, WEEKDAYS),
    ("vo-docent-16", "Niels Wederikdal", ["BI"], 2, ["tuesday", "wednesday", "thursday", "friday"]),
    ("vo-docent-17", "Mirjam Boekweitkamp", ["NA", "NASK"], 1, WEEKDAYS),
    ("vo-docent-18", "Ricardo Munthout", ["SK"], 1, ["monday", "tuesday", "wednesday", "thursday"]),
    ("vo-docent-19", "Bart Dijkstra", ["BV", "CKV"], 1, ["monday", "tuesday", "thursday", "friday"]),
    ("vo-docent-20", "Maarten Esdoornhorst", ["MU"], 2, ["monday", "wednesday", "friday"]),
    ("vo-docent-21", "Youssef El Idrissi", ["LO"], 1, WEEKDAYS),
    ("vo-docent-22", "Edwin Bremstein", ["LO"], 2, ["monday", "tuesday", "wednesday", "thursday"]),
]
TEACHER_BY_ID = {t[0]: t for t in TEACHERS}
# Who teaches a subject: the onderbouw (years 1 to 3) and the bovenbouw teacher.
SUBJECT_TEACHER = {
    "NE": ("vo-docent-02", "vo-docent-01"), "EN": ("vo-docent-04", "vo-docent-03"), "FA": ("vo-docent-05", "vo-docent-05"),
    "DU": ("vo-docent-06", "vo-docent-06"), "WI": ("vo-docent-08", "vo-docent-09"), "WA": ("vo-docent-09", "vo-docent-09"),
    "WB": ("vo-docent-07", "vo-docent-07"), "GS": ("vo-docent-11", "vo-docent-10"), "AK": ("vo-docent-12", "vo-docent-12"),
    "EC": ("vo-docent-14", "vo-docent-13"), "BE": ("vo-docent-14", "vo-docent-14"), "BI": ("vo-docent-16", "vo-docent-15"),
    "NASK": ("vo-docent-17", "vo-docent-17"), "NA": ("vo-docent-17", "vo-docent-17"), "SK": ("vo-docent-18", "vo-docent-18"),
    "MA": ("vo-docent-11", "vo-docent-11"), "CKV": ("vo-docent-19", "vo-docent-19"), "BV": ("vo-docent-19", "vo-docent-19"),
    "MU": ("vo-docent-20", "vo-docent-20"), "LO": ("vo-docent-22", "vo-docent-21"),
}
DESK = "vo-verzuim-01"
DECAAN = "vo-decaan-01"
DECAAN_VWO = "vo-decaan-02"
ZORG = "vo-zorgcoordinator-01"
EXAMSEC = "vo-examensecretaris-01"
TEAMLEIDER_OB = "vo-teamleider-01"
TEAMLEIDER_BB = "vo-teamleider-02"
ADMIN = "vo-administratie-01"


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


def grade_lines(grades: list[dict], names_by_uuid: dict) -> list[str]:
    """The readable grade lines the server writes from subjectGrades
    (lib/Service/ReportCardGradeLines.php): the subject by its course name,
    else its curriculum plan name, and the period average with one decimal
    and a decimal comma, rounded half up as PHP's number_format() does."""
    lines = []
    for grade in grades:
        name = names_by_uuid.get(grade.get("courseId")) or names_by_uuid.get(grade.get("curriculumPlanId"))
        if not name:
            continue
        average = grade.get("periodAverage")
        if average is None:
            lines.append(name)
            continue
        rounded = Decimal(str(average)).quantize(Decimal("0.1"), rounding=ROUND_HALF_UP)
        lines.append(f"{name}: {rounded}".replace(".", ","))
    return lines


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


def school_days() -> list[dt.date]:
    off = closed_days()
    days = []
    d = FIRST_DAY
    while d <= LAST_DAY:
        if d.weekday() < 5 and d not in off:
            days.append(d)
        d += dt.timedelta(days=1)
    return days


def week_days(start: dt.date, end: dt.date, days: list[dt.date]) -> list[dt.date]:
    return [d for d in days if start <= d <= end]


def dutch_date(day: dt.date) -> str:
    return f"{DAG[day.weekday()]} {day.day} {MAAND[day.month - 1]} {day.year}"


def weighted_average(entries: list[dict], weights: dict[str, float]) -> tuple[float, dict]:
    """Mirror GradeAggregationEngine::weightedAverage(): value and breakdown."""
    total = 0.0
    weight_sum = 0.0
    periods: dict[str, list[float]] = {}
    components: dict[str, dict] = {}
    for entry in entries:
        value = float(entry["value"])
        weight = float(weights[entry["componentId"]])
        total += value * weight
        weight_sum += weight
        acc = periods.setdefault(entry["period"], [0.0, 0.0])
        acc[0] += value * weight
        acc[1] += weight
        components[entry["componentId"]] = {"value": value, "weight": weight, "contribution": value * weight}
    breakdown = {
        "periods": {p: round(acc[0] / acc[1], 4) for p, acc in periods.items()},
        "components": components,
    }
    return round(total / weight_sum, 4), breakdown


def build() -> dict:
    rng = random.Random(20250818)
    b = Builder()
    days = school_days()
    class_by_name = {c[0]: c for c in CLASSES}

    def class_days(name: str) -> list[dt.date]:
        return [d for d in days if d <= EXAM_LAST_DAY] if name in EXAM_CLASSES else days

    def day_minutes(name: str, day: dt.date) -> int:
        return class_by_name[name][6][day.weekday()] * LESSON

    # --- school, locations, rooms -------------------------------------------
    school = b.add("school", {"brin": "00X2", "name": "Vaartveld College", "pedagogicalConcept": "regular"})
    locations = {
        "hoofd": b.add("vestiging", {
            "schoolId": school["uuid"], "vestigingscode": "00X200", "onderwijslocatiecode": None,
            "name": "Hoofdgebouw", "street": "Vaartlaan 40", "postalCode": "0531 VL", "city": "Zuiddrecht",
        }),
        "onderbouw": b.add("vestiging", {
            "schoolId": school["uuid"], "vestigingscode": "00X201", "onderwijslocatiecode": "00X201-A",
            "name": "Onderbouwlocatie Varenhof", "street": "Varenhof 3", "postalCode": "0534 VH", "city": "Zuiddrecht",
        }),
    }
    rooms = {}
    for name, _lj, _stream, _size, loc, room, _hours in CLASSES:
        rooms[room] = b.add("room", {
            "name": f"Lokaal {room} ({CLASS_NAMES[name]})", "code": room, "capacity": 32, "kind": "classroom",
            "facilities": ["digibord", "chromebookkar"], "buildingCode": locations[loc]["vestigingscode"],
            "floor": room[1],
        })
    extra_rooms = [
        ("Practicumlokaal onderbouw", "O0.10", 30, "lab", ["zuurkast", "practicumtafels"], "onderbouw", "0"),
        ("Gymzaal onderbouw", "O-GYM", 35, "gym", ["kleedkamers"], "onderbouw", "0"),
        ("Practicumlokaal biologie", "H2.10", 30, "lab", ["microscopen", "practicumtafels"], "hoofd", "2"),
        ("Practicumlokaal natuurkunde", "H2.11", 30, "lab", ["practicumtafels", "meetopstellingen"], "hoofd", "2"),
        ("Practicumlokaal scheikunde", "H2.12", 30, "lab", ["zuurkast", "practicumtafels"], "hoofd", "2"),
        ("Gymzaal", "H-GYM", 35, "gym", ["kleedkamers", "klimwand"], "hoofd", "0"),
        ("Aula", "H0.01", 220, "auditorium", ["toetstafels", "geluidsinstallatie"], "hoofd", "0"),
        ("Mediatheek", "H1.20", 40, "other", ["studieplekken", "computers"], "hoofd", "1"),
    ]
    for rname, code, cap, kind, fac, loc, floor in extra_rooms:
        rooms[code] = b.add("room", {"name": rname, "code": code, "capacity": cap, "kind": kind, "facilities": fac,
                                     "buildingCode": locations[loc]["vestigingscode"], "floor": floor})
    aula = rooms["H0.01"]

    # --- grade scale, courses -----------------------------------------------
    scale = b.add("grade-scale", {
        "name": "Cijfer 1 tot en met 10", "kind": "numeric", "min": 1, "max": 10, "passThreshold": 5.5,
        "roundingRule": "half-up-1dp", "lifecycle": "active",
        "bands": [
            {"bandId": "onvoldoende", "label": "Onvoldoende", "minValue": 1, "maxValue": 5.4, "pass": False},
            {"bandId": "voldoende", "label": "Voldoende", "minValue": 5.5, "maxValue": 10, "pass": True},
        ],
    })
    streams = {
        "hv": b.add("course", {"code": "VO-BHV", "name": "Brugklas havo/vwo", "name_nl": "Brugklas havo/vwo",
                               "description": "Inschrijving in de gemengde brugklas havo/vwo (leerjaar 1).", "level": "vo",
                               "language": "nl", "tags": ["voortgezet onderwijs", "onderbouw"], "lifecycle": "published"}),
        "havo": b.add("course", {"code": "VO-HAVO", "name": "Havo", "name_nl": "Havo",
                                 "description": "Inschrijving in de havo, leerjaar 2 tot en met 5.", "level": "vo",
                                 "language": "nl", "tags": ["voortgezet onderwijs", "havo"], "lifecycle": "published"}),
        "vwo": b.add("course", {"code": "VO-VWO", "name": "Vwo", "name_nl": "Vwo",
                                "description": "Inschrijving in het vwo, leerjaar 2 tot en met 6.", "level": "vo",
                                "language": "nl", "tags": ["voortgezet onderwijs", "vwo"], "lifecycle": "published"}),
    }
    courses = {}
    for code, (name, language, _offset) in SUBJECTS.items():
        courses[code] = b.add("course", {"code": f"VO-{code}", "name": name, "name_nl": name,
                                         "description": f"Het vak {name.lower()} op havo en vwo.", "level": "vo",
                                         "language": language, "tags": ["voortgezet onderwijs", "vak"],
                                         "lifecycle": "published"})

    period_rows = [{"periodId": p[0], "label": p[1], "startDate": p[2].isoformat(), "endDate": p[3].isoformat()} for p in PERIODS]

    # --- pupils per class, profiles and packages -----------------------------
    year_start = {0: dt.date(2025, 8, 18), 1: dt.date(2024, 8, 19), 2: dt.date(2023, 8, 21), 3: dt.date(2022, 8, 22),
                  4: dt.date(2021, 8, 23), 5: dt.date(2020, 8, 17), 6: dt.date(2019, 8, 19)}
    pupils = []
    for name, leerjaar, stream, size, _loc, _room, _hours in CLASSES:
        for _ in range(size):
            boy = rng.random() < 0.5
            repeated = rng.random() < 0.08 and leerjaar > 1
            # Twelve on 1 October 2025 in leerjaar 1, one year older per leerjaar, and a year older again after a doublure.
            start = dt.date(2025 - (12 + leerjaar) - (1 if repeated else 0), 10, 1)
            birth = start + dt.timedelta(days=rng.randrange(365))
            ability = rng.gauss(0.35 if stream == "vwo" else 0.0, 0.9)
            pupil = {"class": name, "leerjaar": leerjaar, "stream": stream, "boy": boy, "birth": birth,
                     "given": rng.choice(BOYS if boy else GIRLS), "ability": ability, "punctual": rng.random(),
                     "repeated": repeated}
            if leerjaar >= 4:
                profile = rng.choices(PROFILES, weights=PROFILE_WEIGHTS)[0]
                pupil["profile"] = profile
                pupil["package"] = list(rng.choice(PACKAGES[stream][profile]))
                if stream == "vwo":
                    pupil["package"].insert(0, rng.choice(["FA", "DU"]))
            pupils.append(pupil)

    # Families: siblings sit in different leerjaren.
    by_leerjaar: dict[int, list[dict]] = {}
    for p in pupils:
        by_leerjaar.setdefault(p["leerjaar"], []).append(p)
    for pool in by_leerjaar.values():
        rng.shuffle(pool)
    families = []
    while any(by_leerjaar.values()):
        size = rng.choices([1, 2, 3], weights=[72, 25, 3])[0]
        open_years = [lj for lj, pool in by_leerjaar.items() if pool]
        chosen = rng.sample(open_years, min(size, len(open_years)))
        families.append([by_leerjaar[lj].pop() for lj in chosen])

    guardian_counter = 0
    used_names = set()
    for index, kids in enumerate(families):
        while True:
            surname = rng.choice(SURNAME_HEAD) + rng.choice(SURNAME_TAIL)
            if surname not in used_names or len(used_names) > 270:
                used_names.add(surname)
                break
        address = {"street": rng.choice(STREETS), "houseNumber": str(rng.randint(1, 160)),
                   "postalCode": f"05{rng.randint(30, 49)} {rng.choice('ABDEGHKLMNPRSTWZ')}{rng.choice('ABDEGHKLMNPRSTWZ')}",
                   "city": "Zuiddrecht", "country": "NL"}
        single = rng.random() < 0.2
        guardians = []
        for g in range(1 if single else 2):
            guardian_counter += 1
            female = (g == 0) if not single else rng.random() < 0.75
            guardians.append(b.add("learner-profile", {
                "ncUserId": f"vo-ouder-{guardian_counter:03d}",
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
    class_order = [c[0] for c in CLASSES]
    pupils.sort(key=lambda p: (class_order.index(p["class"]), p["surname"], p["given"], p["birth"]))
    # Three pupils joined during the year: a move in 2V1, one in 3H1, and an opstromer in 4H1 on the first day.
    latecomers = {"2V1": dt.date(2026, 1, 5), "3H1": dt.date(2025, 12, 1)}
    for n, p in enumerate(pupils, start=1):
        p["nc"] = f"vo-leerling-{n:03d}"
        first_year = year_start[p["leerjaar"] - 1 + (1 if p["repeated"] else 0)]
        p["enrolled"] = first_year
        p["moved_in"] = False
    for cname, joined in latecomers.items():
        joiner = [p for p in pupils if p["class"] == cname][-1]
        joiner["enrolled"] = joined
        joiner["moved_in"] = True
    opstromer = [p for p in pupils if p["class"] == "4H1"][-1]
    opstromer["enrolled"] = FIRST_DAY
    opstromer["moved_in"] = True

    def age_on(p: dict, day: dt.date) -> int:
        b_ = p["birth"]
        return day.year - b_.year - ((day.month, day.day) < (b_.month, b_.day))

    for p in pupils:
        emergency = []
        if rng.random() < 0.3:
            emergency = [{"name": ("Oma " if rng.random() < 0.6 else "Opa ") + p["surname"], "relationship": "grootouder",
                          "phone": f"06-0000{rng.randint(1000, 9999)}", "priority": 1}]
        consent = {k: (None if rng.random() < 0.08 else rng.random() < 0.75) for k in ["website", "socialMedia", "schoolgids", "classPhoto", "video"]}
        p["profile_obj"] = b.add("learner-profile", {
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
            "allergies": (["noten"] if rng.random() < 0.04 else None),
            "medicalConditions": (["astma"] if rng.random() < 0.03 else (["diabetes type 1"] if rng.random() < 0.01 else None)),
            "beeldmateriaalConsent": consent,
            "lifecycle": "active",
        })

    # --- subjects per pupil, teachers per class ------------------------------
    def subjects_of(p: dict) -> list[str]:
        if p["leerjaar"] <= 3:
            return list(ONDERBOUW_SUBJECTS[p["leerjaar"]])
        common = COMMON[(p["stream"], p["leerjaar"])]
        if p["class"] in EXAM_CLASSES:
            common = [c for c in common if c != "LO"]
        return common + [s for s in p["package"] if s not in common]

    for p in pupils:
        p["subjects"] = subjects_of(p)
    class_subjects: dict[str, list[str]] = {}
    for name, *_rest in CLASSES:
        taught = []
        for p in pupils:
            if p["class"] == name:
                for s in p["subjects"]:
                    if s not in taught:
                        taught.append(s)
        if name in EXAM_CLASSES:
            taught.append("LO")
        class_subjects[name] = sorted(taught, key=list(SUBJECTS).index)

    def teacher_for(cname: str, subject: str) -> str:
        onder, boven = SUBJECT_TEACHER[subject]
        return onder if class_by_name[cname][1] <= 3 else boven

    class_teachers: dict[str, list[str]] = {}
    for name, *_rest in CLASSES:
        teachers = [MENTORS[name]]
        for s in class_subjects[name]:
            t = teacher_for(name, s)
            if t not in teachers:
                teachers.append(t)
        assert MENTORS[name] in [teacher_for(name, s) for s in class_subjects[name]], name + " mentor teaches the class"
        class_teachers[name] = teachers

    # --- curriculum plans ----------------------------------------------------
    plans = {}
    for code, (name, _lang, _o) in SUBJECTS.items():
        plans[code] = b.add("curriculum-plan", {
            "name": f"Toetsplan {name.lower()}, {YEAR}", "kind": "generic", "formula": "weighted-average",
            "requiredCourseIds": [courses[code]["uuid"]], "electiveCourseIds": [], "gradeScaleId": scale["uuid"],
            "components": [{"componentId": f"tw{n}", "label": f"Toetsweek periode {n}", "weight": 1, "period": str(n),
                            "kind": "assessment"} for n in (1, 2, 3)],
            "passRules": [{"componentId": None, "minValue": 5.5}],
            "periods": period_rows, "lifecycle": "published",
        })
    pta_weights = {"se1": 2, "se2": 3, "se3": 3}
    pta = {}
    for cname, label in EXAM_CLASSES.items():
        for code in class_subjects[cname]:
            if code == "LO":
                continue
            name = SUBJECTS[code][0]
            pta[(cname, code)] = b.add("curriculum-plan", {
                "name": f"PTA {label} {name.lower()}, {YEAR}", "kind": "pta", "formula": "weighted-average",
                "requiredCourseIds": [courses[code]["uuid"]], "electiveCourseIds": [], "gradeScaleId": scale["uuid"],
                "components": [
                    {"componentId": "se1", "label": "SE1, schriftelijke toets in toetsweek 1", "weight": pta_weights["se1"], "period": "1", "kind": "assessment"},
                    {"componentId": "se2", "label": "SE2, schriftelijke toets in toetsweek 2", "weight": pta_weights["se2"], "period": "2", "kind": "assessment"},
                    {"componentId": "se3", "label": "SE3, schriftelijke toets in de SE-week voor Pasen", "weight": pta_weights["se3"], "period": "3", "kind": "assessment"},
                ],
                "passRules": [{"componentId": None, "minValue": 5.5}],
                "periods": period_rows, "lifecycle": "published",
            })
    package_plans = {}
    for stream, label in (("havo", "havo 4 en 5"), ("vwo", "vwo 4 tot en met 6")):
        low, high = PACKAGE_RULES[stream]
        package_plans[stream] = b.add("curriculum-plan", {
            "name": f"Vakkenpakket {label}, profielkeuze voor {NEXT_YEAR}", "kind": "generic", "formula": "weighted-average",
            "requiredCourseIds": [courses[c]["uuid"] for c in ["NE", "EN", "MA", "CKV", "LO"]],
            "electiveCourseIds": [courses[c]["uuid"] for c in ELECTIVES], "gradeScaleId": scale["uuid"],
            "electiveRules": {"minElectives": low, "maxElectives": high, "mandatoryCombinations": [],
                              "mutuallyExclusive": [[courses["WA"]["uuid"], courses["WB"]["uuid"]]], "capacityByCourseId": None},
            "passRules": [], "periods": [], "lifecycle": "published",
        })

    # --- programmes and classes ----------------------------------------------
    programmes = {
        "hv": b.add("programme", {"name": "Brugklas havo/vwo", "code": "BHV", "level": "vo",
                                  "description": "Het eerste leerjaar voor leerlingen met een havo- of vwo-advies.",
                                  "courseIds": [courses[c]["uuid"] for c in ONDERBOUW_SUBJECTS[1]], "lifecycle": "published"}),
        "havo": b.add("programme", {"name": "Havo", "code": "HAVO", "level": "vo",
                                    "description": "Hoger algemeen voortgezet onderwijs, leerjaar 2 tot en met 5, met een profiel in de bovenbouw.",
                                    "curriculumPlanId": package_plans["havo"]["uuid"],
                                    "courseIds": [courses[c]["uuid"] for c in SUBJECTS if c != "MU"], "lifecycle": "published"}),
        "vwo": b.add("programme", {"name": "Vwo", "code": "VWO", "level": "vo",
                                   "description": "Voorbereidend wetenschappelijk onderwijs, leerjaar 2 tot en met 6, met een profiel in de bovenbouw.",
                                   "curriculumPlanId": package_plans["vwo"]["uuid"],
                                   "courseIds": [courses[c]["uuid"] for c in SUBJECTS if c != "MU"], "lifecycle": "published"}),
    }
    notes_by_class = {
        "1HV1": "Gemengde brugklas havo/vwo; de mentor geeft ook Nederlands. Na periode 2 volgt het determinatieadvies.",
        "3H1": "Profielkeuzejaar: de decaan geeft in februari een voorlichting en houdt keuzegesprekken.",
        "3V1": "Profielkeuzejaar: de decaan geeft in februari een voorlichting en houdt keuzegesprekken.",
        "5H1": "Examenklas havo: laatste lesdag 17 april 2026, het centraal examen begint op 7 mei.",
        "6V1": "Examenklas vwo: laatste lesdag 17 april 2026, het centraal examen begint op 7 mei.",
    }
    cohorts = {}
    for name, leerjaar, stream, _size, loc, room, _hours in CLASSES:
        mentor = MENTORS[name]
        cohorts[name] = b.add("cohort", {
            "name": CLASS_NAMES[name], "programmeId": programmes[stream]["uuid"], "courseId": streams[stream]["uuid"],
            "teacherIds": class_teachers[name],
            "learnerIds": [p["nc"] for p in pupils if p["class"] == name],
            "period": "Schooljaar", "academicYear": YEAR, "lifecycle": "active", "locationId": locations[loc]["uuid"],
            "teacherAssignments": [{"teacherId": mentor, "role": "primary", "days": list(TEACHER_BY_ID[mentor][4])}],
            "notes": notes_by_class.get(name), "kind": "teaching", "programmeYear": leerjaar,
        })
        cohorts[name]["_room"] = rooms[room]

    # --- staff ----------------------------------------------------------------
    mentors = set(MENTORS.values())
    staff_rows = [
        ("vo-rector-01", "Anouk Tijmdal", ["administrator"], ["rector"], WEEKDAYS),
        (TEAMLEIDER_OB, "Hasan Kreekveld", ["coordinator"], ["teamleider onderbouw"], WEEKDAYS),
        (TEAMLEIDER_BB, "Jeroen Smit", ["coordinator"], ["teamleider bovenbouw"], WEEKDAYS),
        (DECAAN, "Marloes Peters", ["coordinator", "teacher"], ["decaan", "loopbaanoriëntatie en -begeleiding"], ["monday", "tuesday", "thursday", "friday"]),
        (ZORG, "Anouk Visser", ["coordinator"], ["zorgcoördinator", "orthopedagoog"], ["monday", "tuesday", "wednesday", "thursday"]),
        (DESK, "Wendy Vlashof", ["administrator"], ["verzuimcoördinator"], WEEKDAYS),
        (EXAMSEC, "Erik Roggebrink", ["administrator"], ["examensecretaris"], ["monday", "tuesday", "wednesday", "thursday"]),
        (ADMIN, "Hatice Kamilleveld", ["administrator"], ["leerlingadministratie"], ["monday", "wednesday", "thursday", "friday"]),
        ("vo-toa-01", "Sander Hopkamp", ["teaching-assistant"], ["technisch onderwijsassistent"], WEEKDAYS),
        ("vo-concierge-01", "Dennis Klaverhout", ["support-staff"], ["BHV", "EHBO"], WEEKDAYS),
    ]
    for tid, tname, subjects, degree, work in TEACHERS:
        graad = "eerstegraads" if degree == 1 else "tweedegraads"
        quals = [f"{graad} bevoegdheid {SUBJECTS[s][0].lower()}" for s in subjects if s not in ("WA", "WB", "NASK")]
        roles = ["teacher", "mentor"] if tid in mentors else ["teacher"]
        staff_rows.append((tid, tname, roles, quals, work))
    # The vwo decaan, also deputy exam secretary, carries the function tags of
    # staff-role-vocabulary-extension (a tag describes the job and grants no
    # access). Appended after the teachers so no earlier staff uuid moves.
    staff_rows.append((DECAAN_VWO, "Karin Beukendaal", ["teacher", "career-counsellor", "exam-secretary"],
                       ["tweedegraads bevoegdheid economie", "LOB-coördinator", "plaatsvervangend examensecretaris"],
                       ["monday", "tuesday", "thursday"]))
    staff_by_id = {}
    for nc, sname, roles, qual, work in staff_rows:
        staff_by_id[nc] = b.add("staff", {"name": sname, "ncUserId": nc, "roles": roles, "qualifications": qual,
                                          "workingDays": list(work)})

    # --- enrolments ------------------------------------------------------------
    by_date = sorted(pupils, key=lambda p: (p["enrolled"], p["nc"]))
    for volgnummer, p in enumerate(by_date, start=1):
        p["volgnummer"] = volgnummer
    for p in pupils:
        cohort = cohorts[p["class"]]
        p["enrolment"] = b.add("enrolment", {
            "learnerId": p["nc"], "learnerRef": p["profile_obj"]["uuid"], "courseId": streams[p["stream"]]["uuid"],
            "source": "admission", "cohortId": cohort["uuid"], "lifecycle": "active",
            "inschrijvingDate": p["enrolled"].isoformat(), "volgnummer": p["volgnummer"],
            "locationId": cohort["locationId"], "leerjaar": p["leerjaar"],
        })

    # --- subject teachers --------------------------------------------------------
    for name, *_rest in CLASSES:
        for s in class_subjects[name]:
            b.add("subjectteacherassignment", {"cohortId": cohorts[name]["uuid"], "courseId": courses[s]["uuid"],
                                               "teacherId": teacher_for(name, s)})

    # --- report periods ------------------------------------------------------------
    periods = []
    all_plans = list(plans.values()) + list(pta.values())
    for code, label, start, end in PERIODS:
        periods.append(b.add("report-period", {
            "name": label, "academicYear": YEAR, "periodCode": code, "startDate": start.isoformat(), "endDate": end.isoformat(),
            "curriculumPlanIds": [plan["uuid"] for plan in all_plans],
            "cohortIds": [c["uuid"] for c in cohorts.values()],
            "lockDate": stamp(end + dt.timedelta(days=7), 17, 0), "attendanceIncluded": True, "lifecycle": "composed",
            "holidays": [{"name": h[0], "startDate": h[1].isoformat(), "endDate": h[2].isoformat()} for h in HOLIDAYS if start <= h[1] <= end],
            "studyDays": [{"date": s[0].isoformat(), "description": s[1]} for s in STUDY_DAYS if start <= s[0] <= end],
        }))

    # --- intake: last year's converted applications, this year's round ---------------
    brugklas = [p for p in pupils if p["leerjaar"] == 1]
    past_round = b.add("admissions-round", {
        "name": "Aanmelding brugklas havo/vwo 2025-2026", "kind": "vo-schooladvies-doorstroomtoets",
        "programmeId": programmes["hv"]["uuid"], "level": "vo", "academicYear": YEAR,
        "applicationDeadline": "2025-03-14", "mandatoryIntake": False, "capacity": 60, "lifecycle": "archived",
    })
    next_round = b.add("admissions-round", {
        "name": "Aanmelding brugklas havo/vwo 2026-2027", "kind": "vo-schooladvies-doorstroomtoets",
        "programmeId": programmes["hv"]["uuid"], "level": "vo", "academicYear": NEXT_YEAR,
        "applicationDeadline": "2026-03-13", "mandatoryIntake": False, "capacity": 56, "lifecycle": "closed",
    })
    # The one advies the school received in reverse: raised from havo to vwo after the doorstroomtoets.
    advies_pupil = next(p for p in brugklas if p["class"] == "1HV1" and p["ability"] > 0.6 and not p["moved_in"])
    for p in brugklas:
        advice = "vwo" if p["ability"] > 0.25 else "havo"
        test_level = advice
        adjusted = None
        motivation = None
        if p is advies_pupil:
            advice, test_level, adjusted = "havo", "vwo", "vwo"
            motivation = "De doorstroomtoets kwam hoger uit dan het voorlopige advies; na heroverweging is het advies bijgesteld naar vwo."
        guardian = p["guardians"][0]
        submitted = dt.date(2025, 3, 3) + dt.timedelta(days=rng.randrange(10))
        p["admission"] = b.add("admission", {
            "applicantGivenName": p["given"], "applicantFamilyName": p["surname"], "applicantBirthDate": p["birth"].isoformat(),
            "priorSchool": rng.choice(PRIOR_SCHOOLS), "admissionsRoundId": past_round["uuid"], "programmeId": programmes["hv"]["uuid"],
            "guardianId": guardian["ncUserId"], "guardianRef": guardian["uuid"], "guardianGivenName": guardian["givenName"],
            "guardianFamilyName": guardian["familyName"], "submittedAuthLevel": "substantial",
            "schoolAdviceLevel": advice, "progressionTestLevel": test_level, "schoolAdviceAdjustedLevel": adjusted,
            "adjustmentMotivation": motivation, "decisionType": "placed", "decisionReason": "Advies past bij de brugklas havo/vwo.",
            "decidedBy": TEAMLEIDER_OB, "decidedAt": stamp(dt.date(2025, 4, 28), 10, 0),
            "convertedLearnerProfileId": p["profile_obj"]["uuid"], "convertedEnrolmentIds": [p["enrolment"]["uuid"]],
            "submittedAt": stamp(submitted, 19, 30), "lifecycle": "converted",
        })
    for i in range(18):
        boy = rng.random() < 0.5
        given = rng.choice(BOYS if boy else GIRLS)
        surname = rng.choice(SURNAME_HEAD) + rng.choice(SURNAME_TAIL)
        advice = rng.choice(["havo", "havo", "vwo", "vwo", "vwo"])
        test_level = advice if rng.random() < 0.8 else ("vwo" if advice == "havo" else "havo")
        adjusted = "vwo" if advice == "havo" and test_level == "vwo" else None
        decision, lifecycle, reason = "placed", "placed", "Advies past bij de brugklas havo/vwo."
        if i == 7:
            advice, test_level, adjusted = "vmbo-gt", "vmbo-gt", None
            decision, lifecycle = "rejected", "rejected"
            reason = "Het advies vmbo-gt past niet bij een havo/vwo-school; de ouders zijn gewezen op scholen met vmbo in de regio."
        elif i >= 16:
            decision, lifecycle, reason = "waitlisted", "waitlisted", "De brugklas is vol; de leerling staat op de wachtlijst."
        submitted = dt.date(2026, 3, 2) + dt.timedelta(days=rng.randrange(10))
        b.add("admission", {
            "applicantGivenName": given, "applicantFamilyName": surname,
            "applicantBirthDate": (dt.date(2013, 10, 1) + dt.timedelta(days=rng.randrange(365))).isoformat(),
            "priorSchool": rng.choice(PRIOR_SCHOOLS), "admissionsRoundId": next_round["uuid"], "programmeId": programmes["hv"]["uuid"],
            "guardianGivenName": rng.choice(ADULT_F + ADULT_M), "guardianFamilyName": surname,
            "guardianPhone": f"06-0000{rng.randint(1000, 9999)}", "submittedAuthLevel": "substantial",
            "schoolAdviceLevel": advice, "progressionTestLevel": test_level, "schoolAdviceAdjustedLevel": adjusted,
            "adjustmentMotivation": ("Doorstroomtoets hoger dan het advies; de basisschool heeft het advies bijgesteld." if adjusted else None),
            "decisionType": decision, "decisionReason": reason, "decidedBy": TEAMLEIDER_OB,
            "decidedAt": stamp(dt.date(2026, 4, 14), 11, 0), "submittedAt": stamp(submitted, 20, 0), "lifecycle": lifecycle,
        })
    b.add("school-advies", {
        "learnerId": advies_pupil["nc"], "academicYear": "2024-2025",
        "voorlopigAdviesLevel": "havo", "voorlopigAdviesDate": "2025-01-24", "voorlopigDeadline": "2025-01-31",
        "doorstroomtoetsResultLevel": "vwo", "doorstroomtoetsResultDate": "2025-03-21",
        "definitiefAdviesLevel": "vwo", "definitiefAdviesDate": "2025-04-18", "definitiefDeadline": "2025-04-24",
        "heroverwegingMotivation": "De doorstroomtoets kwam hoger uit dan het voorlopige advies; na heroverweging is het advies bijgesteld naar vwo.",
        "dataExchangeJobId": None, "lifecycle": "definitief",
    })

    # --- profielkeuze (leerjaar 3) -----------------------------------------------------
    choosers = [p for p in pupils if p["leerjaar"] == 3]
    revise_mutual = next(p for p in choosers if p["stream"] == "havo" and not p["moved_in"])
    revise_short = next(p for p in choosers if p["stream"] == "vwo")
    waiting = [p for p in choosers if p is not revise_mutual and p is not revise_short][-3:]
    for p in choosers:
        weights = [20, 35, 25, 20] if p["ability"] < 0.3 else [15, 30, 27, 28]
        profile = rng.choices(PROFILES, weights=weights)[0]
        package = list(rng.choice(PACKAGES[p["stream"]][profile]))
        if p["stream"] == "vwo":
            package.insert(0, rng.choice(["FA", "DU"]))
        errors: list[str] = []
        lifecycle = "approved"
        if p is revise_mutual:
            package = ["EC", "WA", "WB", "GS"]
            errors = [f"Mutually exclusive courses selected together: {courses['WA']['uuid']}, {courses['WB']['uuid']}."]
            lifecycle = "needs-revision"
        elif p is revise_short:
            package = package[1:]
            errors = [f"At least {PACKAGE_RULES['vwo'][0]} elective(s) required (selected {len(package)})."]
            lifecycle = "needs-revision"
        elif p in waiting:
            lifecycle = "validated"
        p["choice_profile"] = profile
        p["choice_package"] = package
        p["choice_state"] = lifecycle
        guardian = p["guardians"][0]
        p["choice"] = b.add("subject-choice", {
            "learnerId": p["nc"], "learnerRef": p["profile_obj"]["uuid"], "curriculumPlanId": package_plans[p["stream"]]["uuid"],
            "programmeId": programmes[p["stream"]]["uuid"], "academicYear": NEXT_YEAR,
            "selectedElectiveCourseIds": [courses[c]["uuid"] for c in package],
            "guardianConsentGiven": True, "guardianConsentBy": guardian["ncUserId"], "guardianConsentByRef": guardian["uuid"],
            "validationErrors": errors, "lifecycle": lifecycle,
        })

    # --- timetable: a school day per class ---------------------------------------------
    sessions: dict[tuple[str, dt.date], dict] = {}
    toets_days = set()
    for weeks in (TOETSWEKEN, SE_WEKEN):
        for start, end in weeks.values():
            toets_days |= set(week_days(start, end, days))
    for name, *_rest in CLASSES:
        cohort = cohorts[name]
        room = cohort["_room"]
        for day in class_days(name):
            hours = class_by_name[name][6][day.weekday()]
            end_h, end_m = BELL[hours - 1][1]
            in_toetsweek = (name in EXAM_CLASSES and any(s <= day <= e for s, e in SE_WEKEN.values())) or \
                (name not in EXAM_CLASSES and any(s <= day <= e for s, e in TOETSWEKEN.values()))
            label = ", toetsweek, " if in_toetsweek else ", "
            sessions[(name, day)] = b.add("session", {
                "cohortId": cohort["uuid"], "courseId": streams[class_by_name[name][2]]["uuid"],
                "title": f"{CLASS_NAMES[name]}{label}{dutch_date(day)}",
                "startsAt": stamp(day, 8, 30), "endsAt": stamp(day, end_h, end_m),
                "location": room["name"], "roomId": room["uuid"], "lifecycle": "completed",
            })

    # --- one change applied to several weeks (timetabling-bulk-change-weeks) -------------
    # Lokaal H1.03 gets a new digibord on three Tuesdays in March, so 4H1 moves to the
    # mediatheek for those days in one batch: one message to the class and its parents.
    moved_days = [d for d in class_days("4H1") if d.weekday() == 1 and d.month == 3][:3]
    batch_uuid = f"ee{SET_NUMBER}{SCHEMAS.index('session-change-batch') + 1:04x}-0000-4000-8000-{1:012d}"
    mediatheek = rooms["H1.20"]
    for day in moved_days:
        sessions[("4H1", day)].update({
            "roomId": mediatheek["uuid"], "location": mediatheek["name"], "changeBatchId": batch_uuid,
            "changeReasonKind": "room-unavailable", "changeReason": "Nieuw digibord in H1.03",
        })
    class_4h1 = [p for p in pupils if p["class"] == "4H1"]
    batch = b.add("session-change-batch", {
        "kind": "room", "sessionIds": [sessions[("4H1", d)]["uuid"] for d in moved_days], "roomId": mediatheek["uuid"],
        "changeReasonKind": "room-unavailable", "changeReason": "Nieuw digibord in H1.03",
        "results": [{"sessionId": sessions[("4H1", d)]["uuid"], "startsAt": sessions[("4H1", d)]["startsAt"],
                     "outcome": "applied", "reason": None} for d in moved_days],
        "appliedCount": len(moved_days),
        "lessonDates": ", ".join(f"{d.day}-{d.month}-{d.year}" for d in moved_days),
        "affectedLearnerIds": [p["nc"] for p in class_4h1],
        "affectedParentIds": sorted({g["ncUserId"] for p in class_4h1 for g in p["guardians"]}),
        "madeBy": TEAMLEIDER_BB,
    })
    assert batch["uuid"] == batch_uuid

    # --- the hall screen of the main building (timetabling-display-screens) --------------
    # The address is created on first use from the screen's page, so no token is seeded.
    b.add("display-screen", {
        "name": "Aula gebouw A", "vestigingId": locations["hoofd"]["uuid"], "roomIds": [], "cohortIds": [],
        "shows": "today", "showTeacherCodes": True, "status": "active",
    })

    # --- keuzewerktijd wiskunde (timetabling-elective-lesson-signup) ---------------------
    # Four Thursday lessons for havo 4 and 5, 24 places, sign-up from 7 days to 12 hours
    # before each lesson. Eleven pupils signed up for the first; one more was placed by
    # the teamleider after the deadline and one withdrew. The school year is over, so
    # the offer is closed.
    thursdays = [d for d in class_days("4H1") if d.weekday() == 3 and d.month == 2][:4]
    offer = b.add("elective-offer", {
        "name": "Keuzewerktijd wiskunde", "description": "Extra wiskunde op donderdag voor havo 4 en 5, met een docent erbij.",
        "sessionIds": [sessions[("4H1", d)]["uuid"] for d in thursdays], "timetableSessionRefs": [],
        "capacityPerLesson": 24, "eligibleCohortIds": [cohorts["4H1"]["uuid"], cohorts["5H1"]["uuid"]],
        "windowMode": "relative", "opensDaysBefore": 7, "closesHoursBefore": 12, "lifecycle": "closed",
    })
    first = sessions[("4H1", thursdays[0])]["uuid"]
    havo_upper = [p for p in pupils if p["class"] in ("4H1", "5H1")]
    for i, p in enumerate(havo_upper[:13]):
        status, made_by, via = "signed-up", p["nc"], "learner"
        if i == 11:
            status, made_by, via = "placed", TEAMLEIDER_BB, "coordinator"
        elif i == 12:
            status = "withdrawn"
        b.add("elective-sign-up", {
            "offerId": offer["uuid"], "sessionId": first, "timetableSessionRef": None, "learnerId": p["nc"],
            "status": status, "madeBy": made_by, "madeVia": via,
        })

    # --- next year's forecast (timetabling-enrolment-forecast) ----------------------------
    # A spring scenario for 2026-2027: progression rates for havo 3 to 5 and vwo 3 to 6,
    # and 140 expected in the brugklas. The subject choices of havo 3 above give the
    # subject table counted and estimated figures. Computed on first use, not seeded.
    rates = []
    for stream, years in (("havo", (3, 4, 5)), ("vwo", (3, 4, 5, 6))):
        for lj in years:
            final = lj == years[-1]
            up, repeat = (0.92, 0.0) if final else (0.88, 0.07)
            rates.append({"programmeId": programmes[stream]["uuid"], "programmeYear": lj,
                          "upRate": up, "repeatRate": repeat, "leaveRate": round(1 - up - repeat, 2)})
    b.add("enrolment-forecast", {
        "name": "Voorjaarsprognose 2026-2027", "targetYear": NEXT_YEAR, "rates": rates,
        "intake": [{"programmeId": programmes["hv"]["uuid"], "expected": 140}],
        "targetGroupSize": 28, "result": None, "lifecycle": "draft",
    })

    # First-hour teacher per class per weekday: a teacher of that class who works that day.
    first_hour: dict[tuple[str, int], list[str]] = {}
    for name, *_rest in CLASSES:
        for wd in range(5):
            on_duty = [t for t in class_teachers[name] if t in TEACHER_BY_ID and WEEKDAYS[wd] in TEACHER_BY_ID[t][4]]
            assert on_duty, f"{name} has a teacher on {WEEKDAYS[wd]}"
            first_hour[(name, wd)] = on_duty

    # --- marks: exceptions only ----------------------------------------------------------
    marks: dict[str, dict[dt.date, tuple[str, str, int, str]]] = {}
    for p in pupils:
        own = [d for d in class_days(p["class"]) if d >= p["enrolled"]]
        pupil_marks: dict[dt.date, tuple[str, str, int, str]] = {}
        for _ in range(min(5, max(0, int(rng.gauss(1.7, 1.2))))):
            winter = [d for d in own if d.month in (11, 12, 1, 2, 3)]
            start = rng.choice(winter if winter and rng.random() < 0.65 else own)
            length = rng.choices([1, 2, 3, 4, 5], weights=[35, 30, 18, 10, 7])[0]
            i = own.index(start)
            for d in own[i:i + length]:
                who = "leerling (18+)" if age_on(p, d) >= 18 else "ouder"
                pupil_marks[d] = ("absent-excused", f"Ziek gemeld door {who}", 0, DESK)
        for _ in range(rng.choices([0, 1, 2], weights=[55, 35, 10])[0]):
            d = rng.choice(own)
            if d not in pupil_marks:
                pupil_marks[d] = ("left-early", rng.choice(["Tandarts", "Orthodontist", "Huisarts", "Fysiotherapeut"]),
                                  day_minutes(p["class"], d) - 2 * LESSON, DESK)
        lates = rng.choices([0, 1, 2, 3, 5, 9], weights=[40, 27, 15, 9, 6, 3])[0] if p["punctual"] < 0.9 else 0
        for _ in range(lates):
            d = rng.choice(own)
            if d not in pupil_marks:
                late_by = rng.choice([5, 10, 15, 20])
                teacher = rng.choice(first_hour[(p["class"], d.weekday())])
                pupil_marks[d] = ("late", "Te laat in het eerste uur", day_minutes(p["class"], d) - late_by, teacher)
        if p["leerjaar"] >= 4 and rng.random() < 0.08:
            d = rng.choice(own)
            if d not in pupil_marks:
                pupil_marks[d] = ("absent-unexcused", "Niet afgemeld; geen geldige reden gegeven", 0, DESK)
        marks[p["nc"]] = pupil_marks

    excuses: dict[tuple[str, dt.date], dict] = {}

    def excuse(p: dict, first: dt.date, last: dt.date, reason: str, kind: str, lifecycle: str, submitted: dt.date,
               note: str | None) -> dict:
        adult = age_on(p, first) >= 18
        submitter = p["profile_obj"] if adult else p["guardians"][0]
        return b.add("excuse-request", {
            "learnerId": p["nc"], "learnerRef": p["profile_obj"]["uuid"],
            "submittedBy": submitter["ncUserId"], "submittedByRef": submitter["uuid"],
            "dateFrom": first.isoformat(), "dateTo": last.isoformat(), "reason": reason, "reasonKind": kind,
            "submittedAuthLevel": "basic", "decidedBy": DESK, "decidedAt": stamp(submitted, 10, 15),
            "decisionNote": note, "lifecycle": lifecycle,
        })

    # Extra leave refused before the May holiday: two pupils take it anyway.
    refused = [next(p for p in pupils if p["class"] == cname and not p["moved_in"]) for cname in ("2H1", "4V1")]
    for p in refused:
        excuse(p, dt.date(2026, 4, 23), dt.date(2026, 4, 24), "Extra vakantieverlof voor een verre reis", "family-circumstance",
               "rejected", dt.date(2026, 3, 30),
               "Verlof buiten de schoolvakanties kan alleen als het beroep van een ouder dat nodig maakt; dat is niet aangetoond.")
        for d in (dt.date(2026, 4, 23), dt.date(2026, 4, 24)):
            marks[p["nc"]][d] = ("absent-unexcused", "Vakantieverlof niet toegekend, toch afwezig", 0, DESK)

    # Suikerfeest (Friday 20 March 2026): leave granted for a religious obligation.
    feast = dt.date(2026, 3, 20)
    celebrants = [p for p in pupils if p["class"] not in EXAM_CLASSES and p not in refused
                  and p["given"] in ("Amira", "Yusuf", "Mohammed", "Ayoub", "Rayan", "Salma", "Hira", "Adam")][:3]
    assert celebrants, "at least one pupil celebrates Suikerfeest"
    for p in celebrants:
        req = excuse(p, feast, feast, "Suikerfeest", "religious-observance", "approved", dt.date(2026, 3, 9), None)
        marks[p["nc"]][feast] = ("absent-excused", "Suikerfeest, verlof toegekend", 0, DESK)
        excuses[(p["nc"], feast)] = req

    # Leerplicht case A (3H1, reported): five unexcused school days in four weeks of January.
    unexcused_a = next(p for p in pupils if p["class"] == "3H1" and not p["moved_in"] and age_on(p, dt.date(2026, 1, 12)) < 16)
    window_a = (dt.date(2026, 1, 12), dt.date(2026, 2, 6))
    days_a = [d for d in week_days(*window_a, days) if d.weekday() in (0, 1, 4)][:5]
    for d in days_a:
        marks[unexcused_a["nc"]][d] = ("absent-unexcused", "Niet afgemeld, ouders niet bereikbaar", 0, DESK)
    # Leerplicht case B (4H1, resolved after the report): four unexcused days before the toetsweek.
    unexcused_b = next(p for p in pupils if p["class"] == "4H1" and not p["moved_in"] and p is not opstromer)
    window_b = (dt.date(2025, 10, 27), dt.date(2025, 11, 21))
    days_b = [d for d in week_days(*window_b, days) if d.weekday() in (0, 3)][:4]
    for d in days_b:
        marks[unexcused_b["nc"]][d] = ("absent-unexcused", "Gespijbeld; later toegegeven in een gesprek met de mentor", 0, DESK)
    # Zorgwekend ziekteverzuim (2V1): a pupil who is often ill in periode 2.
    often_ill = next(p for p in pupils if p["class"] == "2V1" and not p["moved_in"])
    p2 = [d for d in days if PERIODS[1][2] <= d <= PERIODS[1][3]]
    ill_days = [p2[i] for i in (3, 4, 11, 12, 13, 22, 30, 31, 40, 47, 48, 55)]
    for d in ill_days:
        marks[often_ill["nc"]][d] = ("absent-excused", "Ziek gemeld door ouder (buikpijn, hoofdpijn)", 0, DESK)

    # Guardians (or pupils of 18 and over) report some illness spells through the portal.
    spell_owners = [p for p in pupils if any(m[0] == "absent-excused" and m[1].startswith("Ziek") for m in marks[p["nc"]].values())]
    for p in rng.sample(spell_owners, 20):
        spell = sorted(d for d, m in marks[p["nc"]].items() if m[0] == "absent-excused" and m[1].startswith("Ziek"))
        own = class_days(p["class"])
        run = [spell[0]]
        for d in spell[1:]:
            if own.index(d) == own.index(run[-1]) + 1:
                run.append(d)
            else:
                break
        req = excuse(p, run[0], run[-1], rng.choice(["Griep", "Koorts", "Buikgriep", "Migraine", "Verkouden en koorts"]),
                     "illness", "approved", run[0], None)
        for d in run:
            excuses[(p["nc"], d)] = req

    records_by_pupil: dict[str, list[dict]] = {}
    for p in pupils:
        rows = []
        for d in sorted(marks[p["nc"]]):
            status, reason, minutes, marker = marks[p["nc"]][d]
            rows.append(b.add("attendance-record", {
                "sessionId": sessions[(p["class"], d)]["uuid"], "learnerId": p["nc"], "learnerRef": p["profile_obj"]["uuid"],
                "cohortId": cohorts[p["class"]]["uuid"], "status": status, "minutesAttended": minutes, "markedBy": marker,
                "markedAt": stamp(d, 8, 40 if status == "late" else 20) if status != "left-early" else stamp(d, 11, 0),
                "reason": reason,
                "excuseRequestId": (excuses[(p["nc"], d)]["uuid"] if (p["nc"], d) in excuses else None),
                "_day": d,
            }))
        records_by_pupil[p["nc"]] = rows

    leerplicht = b.add("attendance-threshold", {
        "name": "Leerplicht: 16 uur ongeoorloofd verzuim in 4 weken", "kind": "leerplicht-16uur", "scope": "per-learner",
        "window": {"type": "rolling-weeks", "weeks": 4, "termId": None}, "metric": "unexcused-lesuren", "limit": 16,
        "lessonHourMinutes": 60,
        "onCross": {"notify": True, "notifyRoles": ["mentor", "coordinator"], "createFlag": True, "dataExchangeTarget": None},
        "active": True, "lifecycle": "active",
    })
    zorgwekend = b.add("attendance-threshold", {
        "name": "Zorgwekend ziekteverzuim: aanwezigheid onder 90 procent in een periode", "kind": "generic",
        "scope": "per-learner", "window": {"type": "fixed-term", "weeks": None, "termId": "2"},
        "metric": "attendance-percent-below", "limit": 90,
        "onCross": {"notify": True, "notifyRoles": ["mentor", "coordinator"], "createFlag": True, "dataExchangeTarget": None},
        "active": True, "lifecycle": "active",
    })

    def unexcused_flag(p: dict, window: tuple[dt.date, dt.date], lifecycle: str, interventions: list[dict]) -> None:
        rows = [r for r in records_by_pupil[p["nc"]] if r["status"] == "absent-unexcused" and window[0] <= r["_day"] <= window[1]]
        minutes = sum(day_minutes(p["class"], r["_day"]) for r in rows)
        b.add("attendance-flag", {
            "learnerId": p["nc"], "attendanceThresholdId": leerplicht["uuid"], "cohortId": cohorts[p["class"]]["uuid"],
            "windowStart": window[0].isoformat(), "windowEnd": window[1].isoformat(),
            "metricValue": round(minutes / 60, 2), "breachingRecordIds": [r["uuid"] for r in rows],
            "mentorId": MENTORS[p["class"]], "flagKind": "signal-verzuim", "lifecycle": lifecycle,
            "interventions": interventions,
        })

    unexcused_flag(unexcused_a, window_a, "reported", [
        {"recordedBy": MENTORS["3H1"], "recordedAt": stamp(dt.date(2026, 1, 21), 15, 30),
         "note": "Ouders gebeld over de afwezigheid; geen gehoor, voicemail ingesproken en een e-mail gestuurd.", "lifecycleAtRecording": "open"},
        {"recordedBy": ZORG, "recordedAt": stamp(dt.date(2026, 1, 28), 14, 0),
         "note": "Gesprek met de leerling: slaapt slecht en ziet op tegen school. Afspraak met ouders gepland.", "lifecycleAtRecording": "in-handling"},
        {"recordedBy": DESK, "recordedAt": stamp(dt.date(2026, 2, 9), 9, 0),
         "note": "Melding gedaan bij het DUO-verzuimloket; de leerplichtambtenaar nodigt leerling en ouders uit.", "lifecycleAtRecording": "in-handling"},
    ])
    unexcused_flag(unexcused_b, window_b, "resolved", [
        {"recordedBy": MENTORS["4H1"], "recordedAt": stamp(dt.date(2025, 11, 17), 15, 45),
         "note": "Gesprek met leerling en ouders; de leerling geeft toe te hebben gespijbeld. Afspraken over afmelden gemaakt.", "lifecycleAtRecording": "open"},
        {"recordedBy": DESK, "recordedAt": stamp(dt.date(2025, 11, 18), 9, 0),
         "note": "Melding gedaan bij het DUO-verzuimloket.", "lifecycleAtRecording": "in-handling"},
        {"recordedBy": MENTORS["4H1"], "recordedAt": stamp(dt.date(2026, 1, 16), 16, 0),
         "note": "Geen nieuw ongeoorloofd verzuim sinds de afspraken; dossier gesloten in overleg met de leerplichtambtenaar.", "lifecycleAtRecording": "reported"},
    ])

    # --- report card attendance per pupil per period ------------------------------------------------
    def summary_of(p: dict, start: dt.date, end: dt.date) -> dict | None:
        own = [d for d in class_days(p["class"]) if start <= d <= end and d >= p["enrolled"]]
        if not own:
            return None
        pm = marks[p["nc"]]
        summary = {
            "absentExcusedCount": sum(1 for d in own if pm.get(d, ("",))[0] == "absent-excused"),
            "absentUnexcusedCount": sum(1 for d in own if pm.get(d, ("",))[0] == "absent-unexcused"),
            "lateCount": sum(1 for d in own if pm.get(d, ("",))[0] == "late"),
            "leftEarlyCount": sum(1 for d in own if pm.get(d, ("",))[0] == "left-early"),
        }
        absent = summary["absentExcusedCount"] + summary["absentUnexcusedCount"]
        summary["presentCount"] = len(own) - absent
        summary["attendancePercent"] = round(100 * (len(own) - absent) / len(own), 1)
        return summary

    ill_summary = summary_of(often_ill, PERIODS[1][2], PERIODS[1][3])
    assert ill_summary is not None and ill_summary["attendancePercent"] < 90
    ill_rows = [r for r in records_by_pupil[often_ill["nc"]] if r["status"].startswith("absent") and PERIODS[1][2] <= r["_day"] <= PERIODS[1][3]]
    b.add("attendance-flag", {
        "learnerId": often_ill["nc"], "attendanceThresholdId": zorgwekend["uuid"], "cohortId": cohorts["2V1"]["uuid"],
        "windowStart": PERIODS[1][2].isoformat(), "windowEnd": PERIODS[1][3].isoformat(),
        "metricValue": ill_summary["attendancePercent"], "breachingRecordIds": [r["uuid"] for r in ill_rows],
        "mentorId": MENTORS["2V1"], "flagKind": "langdurig-relatief-verzuim", "lifecycle": "in-handling",
        "interventions": [
            {"recordedBy": MENTORS["2V1"], "recordedAt": stamp(dt.date(2026, 2, 5), 15, 0),
             "note": "Met ouders besproken dat het ziekteverzuim oploopt; ouders herkennen de klachten.", "lifecycleAtRecording": "open"},
            {"recordedBy": ZORG, "recordedAt": stamp(dt.date(2026, 3, 10), 13, 30),
             "note": "Aangemeld bij de jeugdarts volgens de MAZL-aanpak; ouders hebben toestemming gegeven.", "lifecycleAtRecording": "in-handling"},
        ],
    })

    # --- toetsweek papers, grades, final grades --------------------------------------------------------
    def is_absent(p: dict, day: dt.date) -> bool:
        return marks[p["nc"]].get(day, ("",))[0].startswith("absent")

    def graded_scope(cname: str) -> list[tuple[str, str, dict, dict]]:
        """(component, subject, plan, week) for every paper the class sits."""
        if cname in EXAM_CLASSES:
            return [(f"se{n}", s, pta[(cname, s)], SE_WEKEN[str(n)]) for n in (1, 2, 3)
                    for s in class_subjects[cname] if (cname, s) in pta]
        if class_by_name[cname][1] == 3:
            return [(f"tw{n}", s, plans[s], TOETSWEKEN[str(n)]) for n in (1, 2, 3) for s in KERNVAKKEN]
        return []

    papers: dict[tuple[str, str, str], dict] = {}
    for cname, *_rest in CLASSES:
        scope = graded_scope(cname)
        by_week: dict[str, list[tuple[str, str, dict, tuple]]] = {}
        for comp, s, plan, week in scope:
            by_week.setdefault(comp, []).append((comp, s, plan, week))
        for comp, items in by_week.items():
            week = items[0][3]
            wdays = week_days(week[0], week[1], class_days(cname))
            # Options a pupil never takes together share a slot: wiskunde A and B, Frans and Duits.
            slots: list[list[str]] = []
            for _c, s, _p, _w in items:
                partner = {"WB": "WA", "WA": "WB", "DU": "FA", "FA": "DU"}.get(s)
                shared = next((slot for slot in slots if partner in slot), None)
                if shared is not None:
                    shared.append(s)
                else:
                    slots.append([s])
            assert len(slots) <= len(wdays) * len(SLOTS), f"{cname} {comp} fits its toetsweek"
            for index, slot in enumerate(slots):
                day = wdays[index // len(SLOTS)]
                (sh, sm), (eh, em) = SLOTS[index % len(SLOTS)]
                for s in slot:
                    plan = next(pl for c_, s_, pl, _w in items if s_ == s)
                    number = comp[2]
                    subject = SUBJECTS[s][0]
                    if cname in EXAM_CLASSES:
                        title = f"SE{number} {subject.lower()} {EXAM_CLASSES[cname]}"
                        minutes = 100 if cname == "5H1" else 120
                    else:
                        title = f"Toetsweek {number}: {subject.lower()}, {CLASS_NAMES[cname]}"
                        minutes = 90
                    papers[(cname, s, comp)] = b.add("exam", {
                        "title": title,
                        "description": f"Schriftelijke toets in de aula, {dutch_date(day)}.",
                        "courseId": courses[s]["uuid"], "sessionId": sessions[(cname, day)]["uuid"],
                        "cohortId": cohorts[cname]["uuid"], "curriculumPlanComponentId": comp, "gradeEntryComponentId": comp,
                        "scoringScheme": "points", "timeLimitMinutes": minutes, "maxAttempts": 1,
                        "availableFrom": stamp(day, sh, sm), "availableUntil": stamp(day, eh, em), "lifecycle": "closed",
                    })
                    papers[(cname, s, comp)]["_day"] = day
                    papers[(cname, s, comp)]["_plan"] = plan

    # Exam accommodations: pupils with a dyslexia statement, and one who sits papers apart.
    dyslexic = sorted(rng.sample([p for p in pupils if not p["moved_in"]], 14), key=lambda p: p["nc"])
    for p in dyslexic:
        exam_class = p["class"] in EXAM_CLASSES
        b.add("exam-accommodation", {
            "learnerId": p["nc"], "submittedBy": ZORG, "accommodationKind": "extra-time-percentage", "value": 20,
            "evidenceRef": "Dyslexieverklaring in het leerlingdossier", "approvedBy": (EXAMSEC if exam_class else ZORG),
            "lifecycle": "active",
        })
    reader = dyslexic[0]
    b.add("exam-accommodation", {
        "learnerId": reader["nc"], "submittedBy": ZORG, "accommodationKind": "screen-reader-software",
        "evidenceRef": "Dyslexieverklaring in het leerlingdossier; voorleessoftware op een schoollaptop",
        "approvedBy": ZORG, "lifecycle": "active",
    })
    anxious = next(p for p in pupils if p["class"] == "6V1" and p not in dyslexic)
    b.add("exam-accommodation", {
        "learnerId": anxious["nc"], "submittedBy": ZORG, "accommodationKind": "separate-room",
        "evidenceRef": "Verklaring van de orthopedagoog over faalangst", "approvedBy": EXAMSEC, "lifecycle": "active",
    })

    entries_by: dict[tuple[str, str], list[dict]] = {}
    for p in pupils:
        for comp, s, plan, week in graded_scope(p["class"]):
            if s not in p["subjects"]:
                continue
            paper = papers[(p["class"], s, comp)]
            day = paper["_day"]
            if day < p["enrolled"]:
                continue
            comment = None
            if is_absent(p, day):
                # The inhaaltoets: the first school day after the week on which the pupil is in school.
                after = [d for d in class_days(p["class"]) if d > week[1] and not is_absent(p, d)]
                day = after[0]
                comment = "Inhaaltoets na afwezigheid in de toetsweek."
            # Exam class pupils made it through the bovenbouw, so they sit a little higher.
            lift = 0.3 if p["class"] in EXAM_CLASSES else 0.0
            z = max(-2.5, min(2.5, p["ability"] + lift + SUBJECTS[s][2] + rng.gauss(0, 0.55)))
            value = round(max(1.0, min(10.0, 6.5 + 1.15 * z)), 1)
            if value < 5.0 and comment is None:
                comment = "Onvoldoende; bespreek met je docent hoe je dit ophaalt."
            graded = day + dt.timedelta(days=6 if day.weekday() < 4 else 4)
            entry = b.add("grade-entry", {
                "learnerId": p["nc"], "learnerRef": p["profile_obj"]["uuid"], "curriculumPlanId": plan["uuid"],
                "componentId": comp, "courseId": courses[s]["uuid"], "cohortId": cohorts[p["class"]]["uuid"],
                "sourceKind": "manual", "sessionId": sessions[(p["class"], day)]["uuid"], "value": value,
                "gradeScaleId": scale["uuid"], "period": comp[2], "grader": teacher_for(p["class"], s),
                "gradedAt": stamp(graded, 16, 0), "lifecycle": "published",
            })
            if comment is not None:
                entry["comment"] = comment
            entries_by.setdefault((p["nc"], plan["uuid"]), []).append(entry)

    final_grades: dict[tuple[str, str], dict] = {}
    for p in pupils:
        for comp_s in sorted({(s, plan["uuid"]) for _c, s, plan, _w in graded_scope(p["class"]) if s in p["subjects"]}):
            s, plan_uuid = comp_s
            entries = entries_by.get((p["nc"], plan_uuid), [])
            if not entries:
                continue
            plan = next(pl for _c, s_, pl, _w in graded_scope(p["class"]) if pl["uuid"] == plan_uuid)
            weights = {c["componentId"]: c["weight"] for c in plan["components"]}
            value, breakdown = weighted_average(entries, weights)
            final_grades[(p["nc"], plan_uuid)] = b.add("final-grade", {
                "learnerId": p["nc"], "learnerRef": p["profile_obj"]["uuid"], "courseId": courses[s]["uuid"],
                "programmeId": programmes[p["stream"]]["uuid"], "curriculumPlanId": plan_uuid, "gradeScaleId": scale["uuid"],
                "value": value, "passed": value >= 5.5, "breakdown": breakdown,
                "lastRecomputedAt": max(e["gradedAt"] for e in entries),
            })

    # --- report cards ---------------------------------------------------------------------------------
    def plan_for(p: dict, s: str) -> dict:
        if p["class"] in EXAM_CLASSES and (p["class"], s) in pta:
            return pta[(p["class"], s)]
        return plans[s]

    names_by_uuid = {c["uuid"]: c["name"] for c in courses.values()}
    names_by_uuid.update({plan["uuid"]: plan["name"] for plan in all_plans if plan["uuid"] not in names_by_uuid})

    for (code, _label, start, end), period in zip(PERIODS, periods):
        for p in pupils:
            summary = summary_of(p, start, end)
            if summary is None:
                continue
            grades = []
            for s in p["subjects"]:
                plan = plan_for(p, s)
                final = final_grades.get((p["nc"], plan["uuid"]))
                if final is not None:
                    own = entries_by[(p["nc"], plan["uuid"])]
                    so_far = [e for e in own if int(e["period"]) <= int(code)]
                    weights = {c["componentId"]: c["weight"] for c in plan["components"]}
                    passed = weighted_average(so_far, weights)[0] >= 5.5 if so_far else None
                    grades.append({
                        "curriculumPlanId": plan["uuid"], "courseId": final["courseId"],
                        "periodAverage": final["breakdown"]["periods"].get(code), "passed": passed,
                        "sourceGradeEntryIds": [e["uuid"] for e in own if e["period"] == code],
                    })
                else:
                    avg = round(max(3.5, min(9.6, 6.7 + 0.85 * p["ability"] + SUBJECTS[s][2] + rng.gauss(0, 0.4))), 1)
                    # No stored grades behind this line: the teacher's period average, as in the po set.
                    grades.append({"curriculumPlanId": plan["uuid"], "courseId": courses[s]["uuid"], "periodAverage": avg,
                                   "passed": avg >= 5.5})
            averaged = [g for g in grades if g["periodAverage"] is not None]
            weakest = min(averaged, key=lambda g: g["periodAverage"]) if averaged else None
            weakest_name = None
            if weakest is not None:
                weakest_name = next(SUBJECTS[s][0] for s in p["subjects"] if plan_for(p, s)["uuid"] == weakest["curriculumPlanId"]).lower()
                if weakest["periodAverage"] < 5.5:
                    weakest["teacherComment"] = f"Onvoldoende voor {weakest_name}; maak een afspraak voor extra uitleg in het steunuur."
            he = "hij" if p["boy"] else "zij"
            if p["class"] in EXAM_CLASSES:
                options = [
                    f"{p['given']} werkt gericht naar het examen toe. Blijf {weakest_name} goed bijhouden.",
                    f"Een stabiele periode voor {p['given']}. Het examenrooster is bekend; plan de herhaling op tijd.",
                    f"{p['given']} is goed op weg. Voor {weakest_name} is het oefenen met oude examens aan te raden.",
                ]
            elif p["leerjaar"] == 3:
                options = [
                    f"{p['given']} heeft een duidelijk beeld van de profielkeuze. Let bij {weakest_name} op de huiswerkplanning.",
                    f"Een goede periode. {p['given']} bereidt de profielkeuze serieus voor, samen met de decaan.",
                    f"{p['given']} mag trotser zijn op de eigen inzet. Bij {weakest_name} helpt eerder beginnen met leren.",
                ]
            elif p["leerjaar"] == 1:
                options = [
                    f"{p['given']} is goed gewend in de brugklas en werkt steeds zelfstandiger.",
                    f"{p['given']} vindt de weg in de school. De agenda bijhouden gaat al beter; blijf dat oefenen.",
                    f"Een fijne start voor {p['given']}. Bij {weakest_name} mag {he} nog vaker vragen stellen in de les.",
                ]
            else:
                options = [
                    f"{p['given']} werkt goed mee in de les. Bij {weakest_name} is meer oefenen thuis nodig.",
                    f"{p['given']} heeft een goede werkhouding en helpt klasgenoten graag.",
                    f"Een wisselende periode voor {p['given']}. Het mentorgesprek gaat over plannen en {weakest_name}.",
                ]
            b.add("report-card", {
                "learnerId": p["nc"], "learnerRef": p["profile_obj"]["uuid"], "reportPeriodId": period["uuid"],
                "cohortId": cohorts[p["class"]]["uuid"], "subjectGrades": grades,
                # The readable copies the server writes on every save
                # (ReportCardGradeLines), so the parent portal shows them.
                "periodName": period["name"], "gradeLines": grade_lines(grades, names_by_uuid),
                "attendanceSummary": summary,
                "mentorComment": rng.choice(options), "composedAt": stamp(end + dt.timedelta(days=5), 16, 0),
                "lifecycle": "published-to-parents",
            })

    # --- care: support requests and dossier notes ------------------------------------------------------
    struggler = min((p for p in pupils if p["class"] == "1HV2"), key=lambda p: p["ability"])
    exam_anxious = anxious
    b.add("support-request", {
        "learnerId": often_ill["nc"], "raisedBy": ZORG, "supportDomain": "Zorgwekend ziekteverzuim",
        "description": "Veel ziekmeldingen in periode 2, vaak op maandag. Aangemeld bij de jeugdarts; afstemming met ouders en mentor.",
        "urgency": "medium", "lifecycle": "submitted",
    })
    b.add("support-request", {
        "learnerId": unexcused_a["nc"], "raisedBy": ZORG, "supportDomain": "Verzuim en welbevinden",
        "description": "Na het ongeoorloofde verzuim in januari: afstemming met de leerplichtambtenaar en het schoolmaatschappelijk werk.",
        "urgency": "high", "lifecycle": "submitted",
    })
    b.add("support-request", {
        "learnerId": struggler["nc"], "raisedBy": ZORG, "supportDomain": "Lezen en spelling (vermoeden van dyslexie)",
        "description": "Trage leessnelheid en veel spelfouten bij alle talen. Dyslexieonderzoek aangevraagd bij een extern bureau.",
        "urgency": "low", "lifecycle": "decided",
    })
    b.add("support-request", {
        "learnerId": exam_anxious["nc"], "raisedBy": ZORG, "supportDomain": "Faalangst bij toetsen",
        "description": "Blokkeert bij schriftelijke toetsen. Faalangstreductietraining in periode 1; toetsen in een aparte ruimte.",
        "urgency": "medium", "lifecycle": "decided",
    })

    def mentor_of(p: dict) -> str:
        return MENTORS[p["class"]]

    notes = [
        (unexcused_a, mentor_of(unexcused_a), "2026-01-21", "phone-call-home", "Ouders gebeld over de afwezigheid op maandag en dinsdag; geen gehoor.", "care-team-only"),
        (unexcused_a, ZORG, "2026-01-28", "conversation", "Gesprek met de leerling over slapen, schermtijd en tegenzin in school. Vervolgafspraak over twee weken.", "care-team-only"),
        (unexcused_b, mentor_of(unexcused_b), "2025-11-17", "conversation", "Gesprek met leerling en ouders over het spijbelen. Afspraak: afmelden gaat voortaan via ouders.", "care-team-only"),
        (often_ill, mentor_of(often_ill), "2026-02-05", "concern", "Opnieuw ziek gemeld op maandag. Ouders vertellen over buikpijn voor schooldagen.", "care-team-only"),
        (often_ill, ZORG, "2026-03-10", "conversation", "Overleg met ouders; aanmelding bij de jeugdarts besproken en verstuurd.", "care-team-only"),
        (revise_mutual, DECAAN, "2026-02-26", "conversation", "Profielkeuze besproken: wiskunde A en B tegelijk kan niet. Advies: wiskunde A bij het EM-profiel.", "team-visible"),
        (revise_short, DECAAN, "2026-03-03", "conversation", "Keuzeformulier mist de tweede moderne vreemde taal. Frans of Duits kiezen en opnieuw indienen.", "team-visible"),
        (choosers[5], DECAAN, "2026-02-12", "conversation", "Twijfelt tussen NG en NT. Meeloopdag op een hogeschool afgesproken voor de meivakantie.", "team-visible"),
        (struggler, mentor_of(struggler), "2025-10-09", "observation", "Leest langzaam bij de talen en maakt veel spelfouten; besproken met de zorgcoördinator.", "care-team-only"),
        (exam_anxious, ZORG, "2025-09-18", "conversation", "Intake faalangstreductietraining; start in week 40, zes bijeenkomsten.", "care-team-only"),
        (exam_anxious, mentor_of(exam_anxious), "2025-11-28", "positive", "SE1 gemaakt in een aparte ruimte; de leerling was rustiger en tevreden over de voorbereiding.", "team-visible"),
        (advies_pupil, mentor_of(advies_pupil), "2025-10-02", "positive", "Werkt op vwo-niveau en helpt klasgenoten bij wiskunde. Het bijgestelde advies past.", "team-visible"),
        (opstromer, mentor_of(opstromer), "2025-09-12", "observation", "Opgestroomd van vmbo-tl naar havo 4. Went goed; extra aandacht voor wiskunde A afgesproken.", "team-visible"),
        (celebrants[0], DESK, "2026-03-09", "observation", "Verlofaanvraag voor Suikerfeest ontvangen en toegekend.", "team-visible"),
    ]
    for p, author, date, category, body, confidentiality in notes:
        b.add("dossier-note", {"learnerId": p["nc"], "authorId": author, "date": date, "category": category, "body": body,
                               "confidentiality": confidentiality, "careTeamUserIds": [ZORG, mentor_of(p)]})

    # --- standby hours (timetabling-standby-slots) ------------------------------------------------------
    # Two teachers on standby in the second hour of every weekday and one in two
    # afternoon hours, at the main location, for the school year. Each is a
    # teacher who works that day; the class day blocks mean some also teach then,
    # which the substitution dialog shows as "has a lesson then".
    def hhmm(t: tuple[int, int]) -> str:
        return f"{t[0]:02d}:{t[1]:02d}"

    standby_plan = [(wd, 1) for wd in range(5) for _ in range(2)] + [(2, 4), (3, 5)]
    used: set[tuple[int, int, str]] = set()
    pool = [t for t in TEACHERS if t[0] not in MENTORS.values()] + [t for t in TEACHERS if t[0] in MENTORS.values()]
    for wd, hour in standby_plan:
        teacher = next(t for t in pool if WEEKDAYS[wd] in t[4] and (wd, hour, t[0]) not in used)
        used.add((wd, hour, teacher[0]))
        pool.append(pool.pop(pool.index(teacher)))
        b.add("standby-slot", {
            "teacherId": teacher[0], "weekday": WEEKDAYS[wd], "date": None,
            "startsAt": hhmm(BELL[hour][0]), "endsAt": hhmm(BELL[hour][1]),
            "vestigingId": locations["hoofd"]["uuid"],
            "validFrom": FIRST_DAY.isoformat(), "validUntil": LAST_DAY.isoformat(),
        })

    # --- lesson notes (timetabling-lesson-note): Wiskunde B in havo 4 ------------------------------------
    wb_teacher = SUBJECT_TEACHER["WB"][1]
    tuesdays = [d for d in class_days("4H1") if d.weekday() == 1 and d >= dt.date(2026, 3, 3)][:5]
    lesson_notes = [
        (tuesdays[0], "Hoofdstuk 4: kansrekening", "Neem je rekenmachine mee.", "learners"),
        (tuesdays[1], "Hoofdstuk 4: oefentoets", "Maak thuis opgave 1 tot en met 11; we bespreken ze in de les.", "learners"),
        (tuesdays[2], "Hoofdstuk 4: oefentoets", "Maak thuis opgave 1 tot en met 11; we bespreken ze in de les.", "learners"),
        (tuesdays[3], "Hoofdstuk 4: oefentoets", "Maak thuis opgave 1 tot en met 11; we bespreken ze in de les.", "learners"),
        (tuesdays[4], None, "Laat ze opgave 12 tot en met 18 maken. Eén leerling mag om 10:00 weg voor de tandarts.", "cover"),
    ]
    for day, topic, text, audience in lesson_notes:
        b.add("lesson-note", {
            "sessionId": sessions[("4H1", day)]["uuid"], "cohortId": cohorts["4H1"]["uuid"],
            "topic": topic, "text": text, "audience": audience, "authorId": wb_teacher,
        })

    # --- timetable visibility (timetabling-visibility-rules) ------------------------------------------
    # Pupils see their own class, the teachers of their own lessons and every
    # room; teachers see everything.
    b.add("timetable-visibility-policy", {
        "learnerSeesGroups": "own", "learnerSeesTeachers": "related", "learnerSeesRooms": "all",
        "instructorSeesGroups": "all", "instructorSeesTeachers": "all", "instructorSeesRooms": "all",
    })

    # --- assemble ------------------------------------------------------------------------------------
    for rows in b.buckets.values():
        for row in rows:
            for key in [k for k in row if k.startswith("_")]:
                del row[key]
    objects = {name: rows for name, rows in b.buckets.items() if rows}
    total = sum(len(rows) for rows in objects.values())
    return {
        "openapi": "3.0.0",
        "info": {
            "title": "Learniq example set: Secondary school",
            "version": "1.0.0",
            "description": "Vaartveld College, a fictional havo and vwo school in the fictional town of Zuiddrecht, through the 2025-2026 school year, with one pupil's autumn of 2026-2027 on top.",
        },
        "x-openregister": {
            "type": "profile",
            "app": "learniq",
            "profile": {
                "id": SET,
                "segment": SET,
                "label": "Secondary school",
                "description": "A fictional havo and vwo school with years 1 to 6, pupils, guardians and one full school year.",
                "order": 2,
                "objectCount": total,
                "icon": "School",
            },
            "description": (
                "An example set an operator picks in the first-time setup wizard (ADR-042, decision D21). NEVER imported on install. "
                "Every object carries @self.configuration/register/schema and a fixed uuid in the ee02 namespace, so the import resolves "
                "the live learniq register without this descriptor declaring components.registers (which would re-point the register at "
                "this profile config id and overwrite its authorization block), a second load adds nothing, and occ "
                "learniq:example-set:remove vo removes exactly these objects. Generated by scripts/example-sets/vo.py; the contract is "
                "openspec/changes/archive/2026-09-28-segment-wizard-choice/contract.md. Every person, address, school and code in it is fictional."
            ),
            "seedData": {
                "description": (
                    "One havo/vwo school with two locations, eleven classes from the brugklas to havo 5 and vwo 6, about 300 pupils and "
                    "their guardians, a mentor and subject teachers per class, a school day per class per day of 2025-2026 with the "
                    "absences recorded and three verzuim flags, three report periods with report cards, the leerjaar 3 profielkeuze, SE "
                    "grades and SE final grades for the exam classes, one incoming schooladvies with the intake rounds, exam "
                    "accommodations, support requests and dossier notes."
                ),
                "objects": objects,
            },
        },
        "paths": {},
        "components": {},
    }


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
            print(f"{OUT} is out of date; run python3 scripts/example-sets/vo.py", file=sys.stderr)
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
