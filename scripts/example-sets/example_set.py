# SPDX-License-Identifier: EUPL-1.2
# Copyright (C) 2026 Conduction B.V.
"""What every example-set script shares: the object builder, the profile file
layout, the group line a pupil's profile carries, and the command line.

A script imports this module from its own directory and ends with
``sys.exit(main(Script(SET, __doc__, build)))``.
"""

from __future__ import annotations

import argparse
import copy
import json
import os
import sys
from collections.abc import Callable
from dataclasses import dataclass

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
TENANT = "00000000-0000-4000-8000-000000000000"


@dataclass(frozen=True)
class SetIdentity:
    """A set's name, its two-digit uuid number and its schemas in load order."""

    name: str
    number: str
    schemas: list[str]


@dataclass(frozen=True)
class Script:
    """One example-set script: the set it writes, its docstring and its build step."""

    name: str
    doc: str | None
    build: Callable[[], dict]


class Builder:
    """Collects objects per bucket and hands out uuids and slugs."""

    def __init__(self, identity: SetIdentity) -> None:
        self.identity = identity
        self.buckets: dict[str, list[dict]] = {name: [] for name in identity.schemas}
        self.counters: dict[str, int] = {name: 0 for name in identity.schemas}

    def add(self, schema: str, fields: dict) -> dict:
        self.counters[schema] += 1
        n = self.counters[schema]
        number = self.identity.schemas.index(schema) + 1
        obj = {
            "@self": {"configuration": "learniq", "register": "learniq", "schema": schema},
            "uuid": f"ee{self.identity.number}{number:04x}-0000-4000-8000-{n:012d}",
            "slug": f"{self.identity.name}-{schema}-{n:03d}",
        }
        obj.update(fields)
        if "tenant_id" not in obj:
            obj["tenant_id"] = TENANT
        self.buckets[schema].append(obj)
        return obj


def document(template: dict, b: Builder) -> dict:
    """The profile file's content: the template with every non-empty bucket and the object count."""
    objects = {name: rows for name, rows in b.buckets.items() if rows}
    data = copy.deepcopy(template)
    data["x-openregister"]["profile"]["objectCount"] = sum(len(rows) for rows in objects.values())
    data["x-openregister"]["seedData"]["objects"] = objects
    return data


def stamp_group_labels(b: Builder) -> None:
    """Every pupil's group line, as LearnerGroupLabel writes it on a live save
    (school-portals-use-the-new-blocks): the group of the newest active enrolment,
    and " · " with the first teacher's display name when the portal declaration
    names that teacher (lib/Settings/portals/<set>.json accounts; the load command
    gives those accounts that name). Runs last and draws no random number."""
    names = portal_display_names(b.identity.name)
    cohorts = {c["uuid"]: c for c in b.buckets.get("cohort", [])}
    newest = newest_active_enrolments(b, cohorts)
    for profile in b.buckets.get("learner-profile", []):
        enrolment = newest.get(profile["uuid"])
        if "learner" not in (profile.get("roles") or []) or enrolment is None:
            continue
        profile["groupLabel"] = group_label(cohorts[enrolment["cohortId"]], names)


def portal_display_names(name: str) -> dict[str, str]:
    """Display name per account user id in the set's portal declaration, if it has one."""
    declaration = os.path.join(ROOT, "lib", "Settings", "portals", f"{name}.json")
    if not os.path.exists(declaration):
        return {}
    with open(declaration, encoding="utf-8") as handle:
        return {a["userId"]: a["displayName"] for a in json.load(handle).get("accounts", [])}


def newest_active_enrolments(b: Builder, cohorts: dict) -> dict[str, dict]:
    """Per learner, the active enrolment in a known cohort with the latest inschrijving date."""
    newest: dict[str, dict] = {}
    for enrolment in b.buckets.get("enrolment", []):
        if not counts_for_group(enrolment, cohorts):
            continue
        ref = enrolment["learnerRef"]
        if is_newer(enrolment, newest.get(ref)):
            newest[ref] = enrolment
    return newest


def counts_for_group(enrolment: dict, cohorts: dict) -> bool:
    return enrolment.get("lifecycle") == "active" and bool(enrolment.get("learnerRef")) and enrolment.get("cohortId") in cohorts


def is_newer(enrolment: dict, current: dict | None) -> bool:
    return current is None or str(enrolment.get("inschrijvingDate", "")) > str(current.get("inschrijvingDate", ""))


def group_label(cohort: dict, names: dict[str, str]) -> str:
    teacher = names.get(((cohort.get("teacherIds") or [None])[0]) or "")
    return cohort["name"] + (" · " + teacher if teacher else "")


def render(data: dict) -> str:
    """Pretty JSON with one compact object per line in each seed bucket.

    Thousands of objects pretty-printed field by field made a 115,000-line file
    nobody could review; one object per line keeps a diff to the objects that
    changed and halves the size. Still strict JSON.
    """
    buckets = data["x-openregister"]["seedData"]["objects"]
    data["x-openregister"]["seedData"]["objects"] = "__OBJECTS__"
    text = json.dumps(data, indent=2, ensure_ascii=False)
    parts = [bucket_text(name, rows) for name, rows in buckets.items()]
    return text.replace('"__OBJECTS__"', "{\n" + ",\n".join(parts) + "\n      }") + "\n"


def bucket_text(name: str, rows: list[dict]) -> str:
    lines = ",\n".join("          " + json.dumps(row, ensure_ascii=False, separators=(", ", ": ")) for row in rows)
    return f"        {json.dumps(name)}: [\n{lines}\n        ]"


def profile_path(name: str) -> str:
    return os.path.join(ROOT, "lib", "Settings", "profiles", f"{name}.json")


def main(script: Script) -> int:
    parser = argparse.ArgumentParser(description=(script.doc or "").splitlines()[0])
    parser.add_argument("--check", action="store_true", help="exit 1 when the file on disk differs")
    args = parser.parse_args()
    text = render(script.build())
    if args.check:
        return check(script.name, text)
    write(script.name, text)
    return 0


def check(name: str, text: str) -> int:
    """0 when the profile on disk equals `text`, else 1 with a hint on stderr."""
    out = profile_path(name)
    current = ""
    if os.path.exists(out):
        with open(out, encoding="utf-8") as handle:
            current = handle.read()
    if current != text:
        print(f"{out} is out of date; run python3 scripts/example-sets/{name}.py", file=sys.stderr)
        return 1
    print(f"{out} is up to date")
    return 0


def write(name: str, text: str) -> None:
    """Writes the profile and prints its object count per bucket."""
    out = profile_path(name)
    os.makedirs(os.path.dirname(out), exist_ok=True)
    with open(out, "w", encoding="utf-8") as handle:
        handle.write(text)
    data = json.loads(text)
    print(f"wrote {out}: {data['x-openregister']['profile']['objectCount']} objects")
    for bucket, rows in data["x-openregister"]["seedData"]["objects"].items():
        print(f"  {bucket}: {len(rows)}")
