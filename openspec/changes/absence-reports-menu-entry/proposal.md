# Proposal: the absence reports get a menu entry

## Why

The absence reports list (`/attendance/excuses`, manifest page `ExcuseRequests`) had no menu entry. A group teacher, the intern begeleider and the director could reach it only by typing the URL. Found on a clean primary school install (2026-10-01), after `excuse-reports-follow-the-pupils-group` gave group teachers the reports of their own groups.

## What changes

- `AbsenceReportsMenu` ("Absence reports", NL "Verzuimmeldingen", the Dutch name the page already has) under People, next to Attendance, for `instructor`, `coordinator`, `administration-manager` and `admin`.
- Compliance officers can read every report, but the People group does not reach them. `AbsenceReportsComplianceMenu` gives them the same page under Compliance (relocated by `menu-layout.json`), for `compliance-officer` only, so nobody sees two entries.
- Both entries are hidden only when the school chose `corporate` (`workspace.chosenSegment notIn [corporate]`), the gate the AttendanceFlags report card uses. An install that never chose keeps them.
- `hr` and `team-lead` get no entry: `ExcuseRequest` does not grant them read.

## Out of scope

- What the list shows: that is the register's read rule, set by `excuse-reports-follow-the-pupils-group`.
