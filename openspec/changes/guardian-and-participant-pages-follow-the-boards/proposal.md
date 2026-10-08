---
kind: spec
depends_on: [school-portals-match-their-boards, participant-portal]
---

# Proposal: guardian-and-participant-pages-follow-the-boards

## Why

Proof run 2 (08 Oct) compared the signed-in pages with the boards (REPORT-2 items 4, 5, 6 and 10):

- **De Wilgenboom overview:** a child switcher and a "Vera" heading sat above the greeting. The "Mijn kinderen" cards were missing. Both conference rounds asked for a time, although Vera's time was already booked. "Deze maand" had no line under its tiles.
- **De Wilgenboom absence page:** it was per child, so the board's page about both children could not be opened.
- **De Wilgenboom search:** "Zo werkt de ouderavond dit jaar" was not found. Events filled the first page of results.
- **Vaartveld:** "BPV en uren" showed in a pupil's menu, but vo pupils have no placement.
- **Academy participant:** "Uw volgende cursusdag" showed twice, once as a highlight per coming day and once as the list's heading.

## What changes

- **Guardian overview:** no `records`. The page is about both children, so the cards and the calendar show both. The task card leaves out a round where her child already has a time: a lookup on the guardian's own conversation times, then `excludeWhen`. The due day stands in the card's line. School events show their description under the tile.
- **Absence page:** one page for both children. It holds the form and every report, newest first. It is no longer per child.
- **po `/zoeken`:** the catalogue searches news only, as the board ("Nieuws en documenten").
- **vo portal:** `residentMenu.leaveOut` also names `learniq:studentHourWeeks`. The mbo portal keeps the page.
- **Participant overview:** the highlight shows the next course day only (`limit: 1`). The list under it is "Daarna" ("After that") and declares `skip: 1`; portaliq has been asked to support `skip`.

## Not in this change

- The board's task title ("Kies een tijd voor het oudergesprek van Sami") names the child. A round belongs to a group, not to a child. The name needs a portaliq lookup by a row field.
- A child's name on each absence report and in the figures table needs the same lookup by a row field (portaliq). Until then the absence page shows no figures.
- The child-card status chips come from portaliq #1391 and learniq #1839.
