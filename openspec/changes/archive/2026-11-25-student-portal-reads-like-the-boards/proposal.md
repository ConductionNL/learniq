---
kind: spec
depends_on: [site-pupil-portal-design, internship-hours]
---

# Proposal: student-portal-reads-like-the-boards

## Why

Portal proof run 1 (06 Oct) put the vaartveld and esdoornveen student pages next to their design boards. Three things read differently from the boards:

- The grades table showed field keys ("Course name", "Method block", "Component id") as headers (defect 10).
- The BPV hours page was not in the student's menu; the esdoornveen board has "BPV en uren" (defect 12).
- A week the trainer sent back read "Afgewezen"; the boards say "Teruggestuurd" (defect 13).

## What changes

- `studentGrades` declares its columns: subject, date, grade.
- `StudentPortalPages::MENU_PAGES` holds `studentHourWeeks` as "BPV and hours" ("BPV en uren").
- `PortalValueLabels::HOUR_WEEK_STATUS` reads `rejected` as "Sent back" ("Teruggestuurd").

## Not in this change

- The heading "Laatste cijfers" above the overview's grade list. The overview block already carries `label: Latest grades`; portaliq's collection block drops a block label and shows the collection's own label ("Mijn cijfers"). That is a portaliq change (lane FIX-P).
- The test's own name ("Leestoets") on a grade row: it is a curriculum-plan component label with no readable copy on the grade entry yet.
- One student contribution serves vo and mbo, so a vo pupil also sees "BPV en uren" in the menu, with an empty list. A per-portal menu needs a portaliq mechanism (a page shown only when its collection has rows).
