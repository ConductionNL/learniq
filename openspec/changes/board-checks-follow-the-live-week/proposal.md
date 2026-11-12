---
kind: spec
depends_on: [portal-board-checks-run-on-a-real-instance, employer-portal-audience, guardian-tasks-per-child-and-self-assessment]
---

# Proposal: board-checks-follow-the-live-week

## Why

Proof run 3 passed 40 of 48 board checks. Of the 8 failures:
- 6 were checks that no longer matched the portal or the week they run in;
- 2 were the employer's booking detail: the check never opened the booking. When I opened it by its route, the detail showed its fields under English schema titles.

## What changes

- **wilgenboom MijnOverzicht** expects the board's task "Kies een tijd voor het oudergesprek van Sami" (one task per child, #1874), and no task for Vera, who has a time.
- **wilgenboom MijnLijst** opens the Afwezigheid page (`parentAbsence`) before it looks for the reports. It used to read the overview.
- **warmtepompacademie Home:**
  - The block order follows the board's reading order: heading, paragraph, steps, button, then the content block. The sign-in card stands beside them.
  - The course days come from the live index, so once the F-gassen day has passed the check expects Waterzijdig inregelen with "6 plekken vrij". "Nog 1 plek" was missing on desktop and phone alike. Desktop only passed because its text check ran before the live list replaced the declared items.
- **warmtepompacademie MijnLijst and Detail:**
  - The check opens the F-gassen booking by its record route. A row of a dated list (`display: rows`) draws no link in portaliq, so there is nothing to click, and the old click hit the footer's "F-gassen" link.
  - Live, the opened booking shows "Geboortedatum ontbreekt". The data and the declaration were right.
- **Tom's and Linda's overviews** read the F-gassen day from the instance. Once it has passed, Tom's next course day is the lucht-water course, and the F-gassen booking is no longer among Linda's coming days (past-course-days-drop-off).
- **An opened booking names every field in words.** `EmployerSitePages::bookingFieldConfigs()` labels every projected booking field. Portaliq showed a field without a label by its schema title, in English ("Booking number", "Days", "Booked on"), and the state as `confirmed`.

## For portaliq

- A row of `display: rows` on a record page cannot be opened, although the page says "Open een naam om alles daarover te zien".
