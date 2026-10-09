---
kind: spec
depends_on: [employer-portal-audience, participant-portal]
---

# Proposal: past-course-days-drop-off

## Why

Proof run 3: on Friday 9 October, Tom's "Uw volgende cursusdag" still showed Thursday 8 October. A booking's `upcoming` flag, which the participant's coming days and the employer's coming course days both read, only followed the booking's state (received or confirmed). It never looked at the course days, so a day of yesterday stayed the next one.

## What changes

- `EmployerBookingFacts::derive()` takes today. A booking, and each participant's course day on it, is coming while it is open and its last course day is today or later. A two-day course is still coming on its second day. Without today, the days are left out of it, as the example sets write the copies, so the seeded copies still agree with the server.
- `EmployerBookingProjection` passes today from the time factory.
- **`PastCourseDaysJob`** (hourly) re-derives every booking still marked as coming whose first day lies before today. So yesterday's course day drops off within the hour, and Tom's next course day is the next one from today.

## Not in this change

- The example sets still seed the story week's Thursday course as coming. After the load, the job corrects it the first hour after that Thursday.
