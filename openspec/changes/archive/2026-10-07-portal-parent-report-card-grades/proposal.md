# Proposal: guardians read the grades on their child's published report cards

## Why

Found testing a primary school on a clean install (2026-10-01). A primary school records no grade entries; its grades are on the report cards (`report-card.subjectGrades`). The parent portal's grades collection (`parentGrades`) reads `grade-entry`, so it was empty for every primary school guardian. Ruben chose to show the report card grades there (2026-10-01).

## Can portaliq show `subjectGrades` as rows?

No. Checked against portaliq `development` (e168b416) and its open pull requests:

- A collection's rows are OpenRegister objects, one row per object. Nothing in the contribution contract expands a nested list into rows (`CollectionConfigNormaliser`, `PortalObjectReader`).
- `fields` projects top-level keys only, without dot paths (`PortalFieldProjector`), and portaliq passes no `_extend`, so a referenced course or report period is never resolved.
- The site writes a nested list into ONE cell and leaves every uuid out (`src/site/components/collections/cells.js`, `readable()`). A `subjectGrades` item names its subject and period only by uuid, so a guardian read "7,9, Yes; 8,3, Yes" with no subject and no period.
- `itemList` and `timeline` are per-object lists behind a detail view, shaped for dossier publications and case history.
- None of the open portaliq pull requests (#1039, #1032, #1030, #1029, #1026, #1022, #663 and the dependency bumps) changes how a collection renders rows; #1030 only aligns header cells.

## What we chose, and why

No flattened read model of report card grades exists in learniq (`final-grade` is the yearly computed grade and does not follow the report card's publication; `lvs-result` is pupil tracking, Cito). So the smallest learniq-side shape is two readable copies on the report card itself:

- `periodName`: the report period's name ("Rapport 1").
- `gradeLines`: one line per subject, "Rekenen: 7,9", the subject by its course name (else its curriculum plan name), the grade with one decimal and a decimal comma as a Dutch report card writes it.

The portal shows them in a new parent collection, `parentReportCardGrades` ("My child's report card grades"): one row per published report card, columns Period and Grades. It reads `report-card` through the same reverse `via` join as every parent read (REQ-PCON-004/005) and behind the same lifecycle filter as `parentReportCards` (`published-to-parents`), so a draft or a card in review never reaches a guardian.

Not chosen: a column per subject (the subjects differ per group and per school); a new flattened schema (a second copy of every grade, with its own lifecycle to keep in step; only worth it if one already existed); changing portaliq (a contract change for every app, outside this change).

## What changes

- `ReportCard` gets `periodName` and `gradeLines` (register 0.34.26). The server writes them on every create and update (`ReportCardGradeLinesStamp`, through `ReportCardGradeLines`); a value a caller sends is replaced.
- `BackfillReportCardGradeLines` (post-migration repair) writes them on report cards composed before.
- The po and vo example sets carry them (`scripts/example-sets/po.py`, `vo.py`), so a fresh install shows them without a repair run.
- The parent contribution gains `parentReportCardGrades`. Every other collection and every other audience is unchanged (the student, praktijkopleider and external-assessor manifests compared byte for byte before and after).

## Out of scope

- `parentReportCards` keeps its columns, including the nested `subjectGrades` cell. Showing `gradeLines` there too is a one-line follow-up.
- Pupil tracking (Cito) results in the portal.
