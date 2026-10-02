---
kind: code
depends_on: [site-guardian-portal-design]
---

# Proposal: site-pupil-portal-design

## Why

Ruben approved the pupil overview on a phone, `LearniqPupil.dc.html`, on 2026-10-02. The persona is `noa-van-leeuwen` in hydra `personas/`: 14, havo 3, phone only, impatient. She checks three things: what to hand in, today's timetable and her newest grades.

What the student audience offers today (`PortalContributionProvider::studentContribution()`):

- Collections: `studentGrades`, `studentFinalGrades`, `studentAttendance`, `studentEnrolments`, `studentSubmissions` (with the `handIn` row action), `studentExcuseRequests`, `studentInbox` (grade notices) and `studentTests` (the timed task).
- Actions: hand in an assignment, report an absence, the five timed-test steps, the catalogue, work groups and lesson check-in.
- No `pages`. Portaliq synthesises one default page per collection, so the pupil's menu is a list of eight collection names.
- No translation. The labels are English ("My grades", "My submissions") on a Dutch site. Only the parent manifest passes through `PortalLabelTranslator`.
- No timetable. `session` rows exist (the vo set has about 2,000), but no student collection reads them.
- No list of work to hand in. The pupil sees her own submissions, not the published assignments of her group.

## What changes

Existing data, new declarations:

- **An overview page** `studentOverview`, "Overzicht", first in the menu: "Inleveren", "Je rooster vandaag", four quick actions, "Nieuwste cijfers" and "Berichten".
- **A short menu**: Overzicht, Rooster, Inleveren, Cijfers, Toetsen, Afwezig melden, Berichten. The default collection pages leave the menu and keep their routes.
- **Dutch labels** for the whole student manifest, through `PortalLabelTranslator`, in the "je" form.
- **Weight on a grade**: `weight` joins the `studentGrades` projection ("Telt 2 keer mee"). The readable subject comes from `GradeEntry.courseName`, added by `site-guardian-portal-design`.

New work, clearly marked in the specs:

- **NEW: the pupil's own timetable.** A `studentSessions` collection over `session`, through a join on the pupil's own enrolments (`enrolment.learnerRef` to `cohortId`). It shows today's lessons with time, course, location and a cancelled lesson as "valt uit".
- **NEW: work to hand in.** A `studentHomework` collection over published `assignment` rows whose `learnerRefs` holds the pupil, with handed in or open from her own submission. The guardian's `parentHomework` already reads the same list.

## Depends on

Portaliq (lane pq of the portal-design programme; referenced, not respecified):

- `site-mijn-omgeving-components`: the task row with a deadline and "vandaag" badge, the quick-action tiles, the "show the latest N" list, the data badge "Nieuw", the menu keys `menu.group` and `menu.hidden`, a `range: today` on the calendar block or a `timetable` block.
- `site-multi-step-forms`: the absence form on a phone.

Learniq:

- `site-guardian-portal-design` for `GradeEntry.courseName` and the menu keys it asks portaliq for.

## Not in this change

- **"Herkansen kan tot 16 oktober."** No schema holds a resit window for a grade or a test. A resit rule belongs to the examination regulation, not to the portal. It needs its own change.
- **The teacher's name in the timetable** ("mevrouw De Boer"). `session` holds no teacher; the teacher lives on the cohort and on `subject-teacher-assignment`. A readable teacher copy on the session is possible, but the timetable source (`timetable-source`) writes sessions and would have to stamp it. Out of scope here.
- **The room name.** `session.roomId` points at a `room`. This change shows `session.location`, the free-text place the timetable already writes. A room lookup comes later if `location` proves empty.
- **Feedback as a message** ("Feedback op je werkstuk"). `submission.feedbackText` is already projected and shows on the hand-in. Turning returned feedback into an inbox notice needs a new notice record. Out of scope.
- **Messages to a mentor.** Portaliq owns conversations.
- **A pupil hiding data from parents.** The student and parent audiences already read separate collections. This change adds nothing a guardian can read.

## Impact

- `lib/Portal/PortalContributionProvider.php` (student pages, two collections, projection), a new `lib/Portal/StudentPortalPages.php`, `lib/Portal/PortalLabelTranslator.php`, `l10n/nl.json`.
- No register change. No new scope rule beyond the enrolment join.
