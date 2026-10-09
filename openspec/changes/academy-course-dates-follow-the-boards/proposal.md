---
kind: spec
depends_on: [example-sets-are-the-four-schools, board-data-the-schemas-lacked]
---

# Proposal: academy-course-dates-follow-the-boards

## Why

Proof run 3 (9 October) found three courses in the Warmtepompacademie's catalogue where board Zoeken shows nine, no "ook op" dates, and places that only came out right because each date's capacity was set to what the board wanted to read ("Nog 1 plek" from a capacity of 4). The planner's board (LqBord) counts every date against a group of twelve, and the home page says "Een groep is nooit groter dan twaalf".

## What changes

Only `scripts/example-sets/training.py` and the file it writes:

- Two catalogue courses the story's editions did not use: "Hybride warmtepomp installeren en inregelen" (basis) and "Storingen zoeken aan warmtepompen" (gevorderd), with the board's summary lines.
- The board's summary lines for "F-gassen: herhaling en examen", "Waterzijdig inregelen" and "Warmtepompen installeren: basis".
- Nine more course dates with a capacity of 12: F-gassen 22 October, 5 and 19 November; Waterzijdig 12 November; basis 17 and 18 November; Hybride 22 October and 26 November; Storingen 27 and 28 October, 24 and 25 November.
- Every story date counts against 12. The places other companies hold, as LqBord counts them, are loose enrolments of the year's participants, so the catalogue reads "Nog 1 plek" (8 October), "7 plekken vrij" (22 October, as board Artikel), "6 plekken vrij", "Nog 3 plekken", "8 plekken vrij" and "5 plekken vrij".
- Everything is appended after the story, so no existing uuid moves.

## Not in this change

- Courses seven to nine of "Negen cursussen": the boards name "Propaan (R290): veilig werken" (7 October, full) but not its level or later dates, and no board names the other two or their dates. Asked of the coordinator; the catalogue shows six courses with dates until then.
- The price per participant (board: `[PRIJS]`): academy-invoices-on-the-portal (`course.price`).
- The level as a filter ("Niveau") and the price on the card: PublicCourseIndex (lane FIX-L).
- The facets "Bij u op de zaak" and "Theorie online": no run is held there in the story.
