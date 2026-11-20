---
kind: code
depends_on: []
---

# Proposal: portal-refusals-name-their-fields

## Why

Portaliq's change `site-action-forms` (task 7) draws a form for every endpoint action that needs input and shows a refusal on the field it names. Learniq's portal endpoints answered a refused form with `422 {error: 'incomplete'}` and no field, so the form could only say "Niet alles is goed ingevuld" without saying where. And `approveHourWeek` declared no schema, so portaliq drew "Hours you approve" as a text box and sent the hours as text.

## What changes

- `approveHourWeek` (`TrainerSitePages`) declares `schema: bpv-hour-week`, the way `enrolEmployees` declares its schema only so portaliq shapes the inputs. `hoursApproved` becomes a number input and is sent as a number.
- The portal endpoints name the fields of a refused form. A new `PortalAnswers` reads the answers field by field and words the 422:
  - `errors: {field: ''}` for an answer left empty (portaliq words it "<label> is verplicht");
  - `invalid: {field: kind}` for an answer of the wrong kind: `integer` for a number of places that is not whole, `range` for a number outside 1 to 12, `date` for a birth date that is not a real one.
- The endpoints: approve a week of hours (`hourWeekId`), submit a werkproces assessment (`bpvPlacementId`), book places (`cohortId`, `participantCount`), name a participant (`bookingRef`, `learnerRef`) and supply a birth date (`learnerRef`, `birthDate`).
- The `error` code and status of every refusal stay as they were. A client that ignores `errors` and `invalid` behaves as before.

## Impact

- `lib/Portal/TrainerSitePages.php`, new `lib/Service/Portal/PortalAnswers.php`, `PortalHourWeekApproval`, `PortalWerkprocesAssessment`, `PortalEmployerBookings`.
- No schema, register or screen of learniq changes. The forms are portaliq's.
