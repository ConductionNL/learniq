---
kind: spec
depends_on: []
---

# Proposal: school-activities-staff-side

## Why

A school runs term-long activities: a club, a sport, a trip. Guardians sign their child up on the school's portal. The staff side is everything that happens after: how many places there are, who is coming, who may not be photographed, and who was there each week.

Portaliq's `portaliq-cms` spec carried that staff side, next to the guardian's sign-up. Ruben decided on 3 November 2026 (decision 286) that the staff side belongs to learniq, on board `learniq/LqActiviteiten`. Learniq already owns the pupils, the groups and the attendance of lessons, so a supervisor finds the activity roster where they take every other register. The guardian's side stays in portaliq: the list of activities, the sign-up, the waiting list and the consent.

## What changes

- A new capability `school-activities` in learniq with the three staff requirements that portaliq's `portaliq-cms` spec held:
  - places set by capacity and supervision, and an activity that does not open without a place;
  - the roster, with each child's photo consent where photos are taken;
  - attendance per session, for a child with a confirmed place only.
- `portaliq-cms` keeps a short reference to this change for each of the three (portaliq PR, same day).
- The staff page `Activiteiten` is drawn on `learniq/LqActiviteiten`.

## Assumption, for Ruben to confirm

The activity records stay where they are: portaliq's register holds `activityOffer`, its sign-ups and the attendance rows, and portaliq's guardian side reads them. Learniq's staff page reads and writes those records through OpenRegister. Moving the records into learniq's register is a larger build change and is not part of this proposal.

## What this does not do

- No guardian screens: signing up, the waiting list, withdrawing and consent stay in portaliq.
- No payment: raising the contribution for confirmed places stays where it is specified, in portaliq through shillinq.
