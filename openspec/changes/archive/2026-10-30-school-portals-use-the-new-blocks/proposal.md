---
kind: spec
depends_on: [example-portal-declares-its-site, site-guardian-portal-design]
---

# Proposal: school-portals-use-the-new-blocks

## Why

The portaliq blocks and chrome the school boards need are merged (portaliq #1195, #1198, #1200, #1205; lane L2's block contract and lane L1's chrome contract, 5 October 2026). learniq declared its pages before those keys existed, and a few things the boards show had no data behind them:

- The guardian's menu shows each child with "Groep 7 · Meester Daan" under the name. Portaliq reads that line from the record's own fields, and the learner profile had none.
- The child's page shows the latest report as a bar per subject. A report card keeps its subjects in one nested list, and a list block cannot split it.
- A new grade never reached the guardian's inbox: its notice did not even carry the child it was about, and it may lie in the future.
- The public website could not show news: no item said it was public, for which portal, or for whom.

## What changes

- **Chrome (lane L1):** each portal declares `accountLabel`, `footer.contact`, `authentication.signInPage` and the mode-label icons from the boards; the academy declares `residentMenu.cardLabel`. An existing portal gets them when its own fields are empty.
- **Website (lane L2):** the "Direct regelen" tiles use portaliq's line-icon names; every public news item carries `public`, `portal` and `audienceLabel` ("hele school", "groep 7").
- **Guardian pages (lane L2):**
  - The greeting takes `label` and one target.
  - The open task gets a button.
  - The children cards and the switcher show the group line.
  - A tile opens the open child's own page (`page`, `withRecord`).
  - The lists get `limit` and `sort`.
  - The messages block reads every inbox about the open child.
  - The reports read with status tones.
  - The child's page sits in "Mijn kinderen" with its subline (`group` + `records.subtitleFields`).
  - The conversations page carries a badge.
  - The absence form sums up the answers (`summary`) and confirms (`confirmation`).
- **A pupil's group line:** `LearnerProfile.groupLabel`, a readable copy written on every profile save and again after an enrolment moves (deferred), backfilled by the repair step and seeded in the example sets.
- **New grades in the inbox:** a grade notice carries `learnerRef` and `courseName`; `parentGradeInbox` and `studentInbox` use `visibleFromField`, so a held-back grade stays out until its moment. The notice never carries the grade.
- **The latest report as rows:** a new schema `report-subject-grade`, written when a report card is published to parents and replaced by the next one. The report card is never changed. `parentReportSubjectGrades` feeds the bars on the child's page.

## Not in this change

- `organisationName` in the employer's session: learniq has no employer audience and no organisation record yet (wave 2, W2-2), so it cannot supply one honestly.
- Preselecting the open child in the absence form (`recordField` on an action): portaliq keeps `recordField` on blocks only.
- "Gezien door de leerkracht" on a report: no staff action records it yet.
