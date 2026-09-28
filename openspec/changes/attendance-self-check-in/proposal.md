---
kind: code
depends_on: []
---

# Proposal: attendance-self-check-in

## Summary

A teacher opens self check-in for a lesson from the register screen. The screen shows a QR code that changes every thirty seconds, or a link for an online lesson. A learner of that lesson's group scans or opens it while the window is open and is marked present, or late after the grace period. The teacher sees the check-ins arrive on the register and keeps the last word: a mark the teacher set is never overwritten, and the teacher can change any self check-in.

## Why

This change covers the same capability in two matrices.

- learniq `openspec/parity/capabilities.json`, row `att-learner-checks-in-with-qr` ("Let a learner mark their own attendance by scanning a code or opening a link."), rated `no`, `built.state: none`. Decision: build, two competitors rate yes.
- planninq `openspec/parity/capabilities.json` (planninq#665), row `sib-learniq-att-learner-checks-in-with-qr`, the same capability owed to learniq, rated xedule partial and timeedit partial there.

Evidence, copied from learniq's matrix:

- Demand row (changelog, counted as competitor evidence): https://totara.help/docs/digital-self-attendance, Totara 20: "Learners can now mark their own attendance using a secure link or QR code, whether they attend in person or online."
- totara, yes: https://totara.help/docs/digital-self-attendance "Learner selects the URL or scans the QR code" and is marked Fully attended; https://totara.help/docs/new-features "Digital Self-attendance for seminars allows learners to check-in and confirm their attendance ... using either an event-specific URL or a QR code."
- chamilo, yes: "source read at chamilo/chamilo-lms v3.0.1: src/CoreBundle/Controller/AttendanceController.php:421 generateQrCode (teacher shows a QR code to the sheet) + :666 validateSelf (learner marks own presence)".
- ispring-learn, partial: https://ispringhelpdocs.com/ispring-learn/recording-attendance-35665646.html, attendance is automatic for Teams and Zoom only; "For any training sessions other than Microsoft Teams and Zoom, you will need to manually record attendance."

## What learniq has today

Read at learniq `development` a84b6273.

- `lib/Settings/learniq_register.json:15474` `AttendanceRecord` (0.2.0): `sessionId`, `learnerId`, `status` (`present`, `absent-unexcused`, `absent-excused`, `late`, `left-early`), `markedBy` ("Nextcloud user ID of the teacher or coordinator who marked attendance"), `markedAt`. Read, create and update are limited to `instructors` and `compliance-officers`, so a learner cannot write a record.
- `src/views/AttendanceRegisterView.vue` (`/sessions/:sessionId/attendance`, `src/manifest.d/learning.json:7160-7165`): one row per learner of the session's cohort (`Cohort.learnerIds`, Nextcloud user ids), a status per learner, saved as one `AttendanceRecord` each.
- `lib/Settings/learniq_register.json:6130` `Session`: `startsAt`, `endsAt`, `cohortId`, and no authorization block, so every signed-in user can read sessions. A check-in secret therefore cannot live on `Session`.

## What this change builds

1. A `CheckInWindow` schema: which session, who opened it, when it opens and closes, the late threshold, the mode (`rotating-qr` in the room or `link` for an online lesson), readable only by staff.
2. A check-in code that is never stored: an HMAC of the window id and the current thirty-second step with the instance secret; the QR on the teacher's screen refreshes with it. In `link` mode the code covers the whole window.
3. `POST /api/check-in/{windowId}`: a signed-in learner sends the code; the server checks the window is open, the code is valid, the caller is in the session cohort's `learnerIds` and has no record yet, then writes an `AttendanceRecord` (`present`, or `late` after the threshold) with `markedVia: self-check-in`.
4. On the register screen: "Open self check-in", a full-screen QR or a copyable link, a live count of checked-in learners, and "Close check-in". Self check-ins show a small label; the teacher can change them like any other mark.
5. `AttendanceRecord.markedVia` (`teacher`, `self-check-in`), default `teacher`.

## Out of scope

- Location proof (geofence, IP range, Bluetooth).
- Check-in by guardians or through portaliq.
- Automatic absence for learners who did not check in; the teacher still saves the register.

## Affected projects

- [x] `learniq`: register (new `CheckInWindow`, `AttendanceRecord` 0.3.0), a controller and service, `AttendanceRegisterView.vue`, a learner check-in page, seed data, l10n.

## Risks

- A learner photographs the QR and sends it to a friend outside the room. Mitigation: the rotating code lives thirty seconds (the server accepts the current and the previous step); only learners of the session's own cohort are accepted; the teacher sees the count against the room.
- A self check-in overwrites a teacher's mark. Mitigation: the endpoint never updates an existing record.
