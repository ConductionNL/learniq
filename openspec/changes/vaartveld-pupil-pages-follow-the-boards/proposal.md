---
kind: code
depends_on: [site-pupil-portal-design, student-portal-reads-like-the-boards]
---

# Proposal: vaartveld-pupil-pages-follow-the-boards

## Why

Portal proof run 3 (9 October) put Noor Bakker's overview and grades beside the Vaartveld boards MijnOverzicht and MijnLijst:

- The overview stood in one column with no card frames; "Hele week" was a button under the timetable; homework were tinted cards with "Openen" buttons; "Laatste cijfers" was a table; the absence was three tiles with a red border. Live also showed "Werk inleveren" and "Afwezig melden" buttons and a messages block, which the board does not have.
- The grades page was a flat table of every grade, with no grouping per subject, no average, no tabs; clicking a grade opened a panel of raw keys.

Portaliq's PQ-MIJN stack adds the keys the boards need (`mijn-overview-follows-the-boards`, `mijn-lists-follow-the-boards`, portaliq #1426 and #1429). The pupil's pages are declared in `StudentPortalPages`.

## What changes

- **Overview** (`studentOverview`): the greeting with the week (`showWeek`); today's timetable in the main column, framed, with "Hele week" in its heading (`more`); homework and tests as four rows in the side column, by due date, with "Alles van deze week" under it; the three newest grades as rows (subject, date, grade) with "Alle cijfers"; the absence as one tinted strip ("1 dag ziek, 2 keer te laat, 0 dagen zonder melding") with "Bekijken". The two buttons and the messages block go; no figure is marked red.
- **Grades** (`studentGrades`): one block grouped per subject (`display: chips`, `groupField: courseName`), each grade a chip, the average per subject, a grade under 5,5 marked (`lowBelow`), the summary "Je staat {pass} vakken voldoende en {fail} onvoldoende." and the tabs Periode 1, Heel het schooljaar, Schoolexamen. No table and no raw detail panel.
- **Words**: Dutch for the new labels in `l10n`; `PortalLabelTranslator` translates `stripLabel` and `summaryText`, and leaves a tab's `values` (stored values it filters on) alone.

## Depends on

- Portaliq PQ-MIJN (#1423, #1426, #1429): until those merge, portaliq drops the new keys and draws the blocks as before, without the buttons and messages block.

## Not in this change (requests to lane FIX-L: schemas and the provider)

- The board's first-lesson card ("Je eerste les · 08.30 uur, Nederlands in lokaal 1.12") has no block; the timetable marks the first lesson.
- The timetable's summary line ("2 wijzigingen: ...", "Je bent vandaag om 14.20 uur uit.") and teacher names per lesson (`session` carries no teacher).
- Homework rows: the subject ("Vandaag · Nederlands") needs a subject name copy on `assignment`; the pills "Nog inleveren" and "Toets" need a state field. The board leaves the Engels SO out of this list (it is a pill on the lesson); the declaration shows the first four by due date.
- Grade rows: the test's name ("Leestoets") needs a copy of the component label on `grade-entry`; "Nieuw" needs an unseen flag; the teacher under the subject needs a teacher name copy and a salutation.
- CKV and LO in words ("voldoende", "V"): the grades sit on a band scale as 2 and 3; the portal needs the band label.
- "Periode 1" also catches last school year's period 1 grades: a grade needs its school year (or the tab a second filter).
- "0 uur zonder melding": the summary counts days without a report, not hours.
- The subject page (`rowPage`) follows once FIX-P's record detail lands.
- The mbo student overview reads the same page until the mbo lane adds its own variant.
