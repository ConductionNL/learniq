# SPDX-License-Identifier: EUPL-1.2
# Copyright (C) 2026 Conduction B.V.
"""Boundary test for the example-set scripts under scripts/example-sets.

Each script runs as a program in a temporary copy of the repository layout, so
nothing in the working tree is written. A run must reproduce the committed
lib/Settings/profiles/<set>.json byte for byte, print what main() prints, and
give the exit code of each --check outcome (up to date, changed, missing).

The committed profile is the expected output: a script change that changes the
data regenerates that file in the same commit, and this test follows it.

Run: python3 -m unittest discover -s tests/scripts/example_sets -p 'test_*.py'
(EXAMPLE_SETS="he mbo" limits the run to those sets.)
"""

from __future__ import annotations

import glob
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
SCRIPTS = os.path.join(REPO, "scripts", "example-sets")
PROFILES = os.path.join(REPO, "lib", "Settings", "profiles")
PORTALS = os.path.join(REPO, "lib", "Settings", "portals")
SETS = tuple(os.environ.get("EXAMPLE_SETS", "corporate he mbo po training vo").split())


def written_summary(out: str, text: str) -> str:
    """What a write run prints: the object count, then each bucket's size."""
    data = json.loads(text)
    lines = [f"wrote {out}: {data['x-openregister']['profile']['objectCount']} objects"]
    for name, rows in data["x-openregister"]["seedData"]["objects"].items():
        lines.append(f"  {name}: {len(rows)}")
    return "\n".join(lines) + "\n"


def canonical(data: dict) -> str:
    return json.dumps(data, sort_keys=True, ensure_ascii=False)


class ExampleSetScriptsTest(unittest.TestCase):
    root: str

    @classmethod
    def setUpClass(cls) -> None:
        cls.root = tempfile.mkdtemp(prefix="example-sets-")
        target = os.path.join(cls.root, "scripts", "example-sets")
        os.makedirs(target)
        for path in glob.glob(os.path.join(SCRIPTS, "*.py")):
            shutil.copy(path, target)
        os.makedirs(os.path.join(cls.root, "lib", "Settings", "profiles"))
        shutil.copytree(PORTALS, os.path.join(cls.root, "lib", "Settings", "portals"))

    @classmethod
    def tearDownClass(cls) -> None:
        shutil.rmtree(cls.root, ignore_errors=True)

    def run_script(self, name: str, *args: str) -> subprocess.CompletedProcess:
        script = os.path.join(self.root, "scripts", "example-sets", f"{name}.py")
        return subprocess.run([sys.executable, script, *args], check=False, capture_output=True, text=True, timeout=300, cwd=self.root)

    def out_path(self, name: str) -> str:
        return os.path.join(self.root, "lib", "Settings", "profiles", f"{name}.json")

    def expected(self, name: str) -> str:
        with open(os.path.join(PROFILES, f"{name}.json"), encoding="utf-8", newline="") as handle:
            return handle.read()

    def written(self, name: str) -> str:
        with open(self.out_path(name), encoding="utf-8", newline="") as handle:
            return handle.read()

    def assert_run(self, run: subprocess.CompletedProcess, code: int, stdout: str, stderr: str) -> None:
        self.assertEqual((run.returncode, run.stdout, run.stderr), (code, stdout, stderr))

    def test_each_set_writes_its_profile_and_checks_it(self) -> None:
        for name in SETS:
            with self.subTest(set=name):
                self.check_one_set(name)

    def check_one_set(self, name: str) -> None:
        out = self.out_path(name)
        expected = self.expected(name)
        stale = f"{out} is out of date; run python3 scripts/example-sets/{name}.py\n"

        self.assert_run(self.run_script(name, "--check"), 1, "", stale)
        self.assertFalse(os.path.exists(out))

        self.assert_run(self.run_script(name), 0, written_summary(out, expected), "")
        self.assertEqual(self.written(name), expected)

        self.assert_run(self.run_script(name, "--check"), 0, f"{out} is up to date\n", "")

        with open(out, "a", encoding="utf-8") as handle:
            handle.write(" ")
        self.assert_run(self.run_script(name, "--check"), 1, "", stale)

        self.assert_run(self.run_script(name), 0, written_summary(out, expected), "")
        self.assertEqual(self.written(name), expected)

        self.check_without_portal(name, expected)

        unknown = self.run_script(name, "--nope")
        self.assertEqual((unknown.returncode, unknown.stdout), (2, ""))
        self.assertTrue(unknown.stderr.endswith("error: unrecognized arguments: --nope\n"), unknown.stderr)

    def check_without_portal(self, name: str, expected: str) -> None:
        """Without the portal declaration a pupil's group line is the group alone."""
        declaration = os.path.join(self.root, "lib", "Settings", "portals", f"{name}.json")
        if not os.path.exists(declaration):
            return
        moved = declaration + ".away"
        os.rename(declaration, moved)
        try:
            run = self.run_script(name)
        finally:
            os.rename(moved, declaration)
        data = json.loads(expected)
        for profile in data["x-openregister"]["seedData"]["objects"].get("learner-profile", []):
            if "groupLabel" in profile:
                profile["groupLabel"] = profile["groupLabel"].split(" · ")[0]
        self.assertEqual(run.returncode, 0)
        self.assertEqual(canonical(json.loads(self.written(name))), canonical(data))


class GroupLineTest(unittest.TestCase):
    """The group line rules no example set happens to exercise."""

    def test_the_newest_active_enrolment_in_a_known_cohort_wins(self) -> None:
        sys.path.insert(0, SCRIPTS)
        try:
            import example_set
        finally:
            sys.path.remove(SCRIPTS)
        b = example_set.Builder(example_set.SetIdentity("x", "99", ["cohort", "enrolment"]))
        rows = [
            {"learnerRef": "a", "cohortId": "c1", "lifecycle": "active", "inschrijvingDate": "2025-08-01"},
            {"learnerRef": "a", "cohortId": "c2", "lifecycle": "active", "inschrijvingDate": "2026-01-01"},
            {"learnerRef": "a", "cohortId": "c1", "lifecycle": "active", "inschrijvingDate": "2025-09-01"},
            {"learnerRef": "a", "cohortId": "c3", "lifecycle": "active", "inschrijvingDate": "2027-01-01"},
            {"learnerRef": "a", "cohortId": "c1", "lifecycle": "ended", "inschrijvingDate": "2027-01-01"},
            {"learnerRef": "", "cohortId": "c1", "lifecycle": "active", "inschrijvingDate": "2027-01-01"},
        ]
        for row in rows:
            b.add("enrolment", row)
        newest = example_set.newest_active_enrolments(b, {"c1": {}, "c2": {}})
        self.assertEqual({ref: e["slug"] for ref, e in newest.items()}, {"a": "x-enrolment-002"})


if __name__ == "__main__":
    unittest.main()
