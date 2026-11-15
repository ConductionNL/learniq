# Proposal: a teacher reads a pupil by name

## Why

Seen on the primary-school instance in the review of 2026-10-04: a teacher's lists name the pupil by Nextcloud user id. "Bookings to answer" and the slot list of a conference round, the absence reports and a lesson's attendance all read `po-leerling-147` where the teacher expects "Vera Hulstkamp".

The cause is in the register. The learner profile schema declared no display name (`configuration.objectNameField`), so OpenRegister fell back to the uuid for `@self.name`. A list that resolves a pupil reference had nothing readable to show, so the lists showed the user id instead.

## What changes

- The learner profile names itself from `{{ givenName }} {{ familyName }}`, OpenRegister's twig-like `objectNameField`. A profile saved from now on gets that name.
- OpenRegister computes `@self.name` only on save. A new repair step, `BackfillLearnerProfileNames`, saves every stored profile whose name is not its given and family name yet, unchanged otherwise. It runs without a session (`_rbac: false`, `_multitenancy: false`), so it also reaches merged and deleted profiles and every tenant. A second run saves nothing. The app version moves so `occ upgrade` runs it.
- The teacher lists that showed `learnerId` now show the pupil's learner profile (`learnerRef`) through the `fkResolve` cell, which reads the profile's name: the round page's "Bookings to answer" and slot list, the absence reports, the attendance records and a lesson's attendance. The absence reports and attendance records now declare their columns; neither shows a uuid or a user id. The attendance records list names the group the same way.
- The values those lists show read as words: the absence report's kind of reason and status, and the attendance status, get labels. Every label has a Dutch entry already.
- Register 0.34.39. Learner profile 0.3.6, excuse request 0.4.2, attendance record 0.4.1.

## Not changed

- No property, no enum value, no stored data except the computed name.
- The roll-call already showed names (RollCallService builds them).
- Lists outside this review (enrolments, grades, report cards, assessment results and the like) still show `learnerId`. The same column change applies to them once their schema carries `learnerRef`; listed as a follow-up.
