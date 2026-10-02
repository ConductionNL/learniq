# Tasks: a fast roll-call for the group teacher

- [x] 1.1 `AttendanceRecord` gains `lateMinutes` and `absenceReasonKind` (`illness`, `appointment`, `other`), optional, with catalogue keys (en, nl). Verify: PHPUnit `AttendanceSummaryRegisterTest::testTheRecordCarriesLateMinutesAndAReasonForAbsence`; `npm run check:schema-l10n`.
- [x] 1.2 `RollCallService` decides who opens which group and day, pre-fills approved absence reports, validates marks, writes only changed records and creates a missing lesson. Verify: PHPUnit `RollCallServiceTest` (16 tests), red before the service existed.
- [x] 1.3 `GET` and `POST /api/attendance/roll-call` (`RollCallController`). Verify: PHPUnit `RollCallControllerTest`.
- [x] 1.4 `RollCallView` at `/attendance/roll-call`: everyone present, one tap or key per exception, quick late minutes, reasons, one save, phone width, keyboard and screen reader. Verify: `node --test tests/unit-js/rollCall.test.mjs`; live as po-leerkracht-09.
- [x] 1.5 Menu entry "Today's register" for instructors, coordinators, administration-managers and admins. Verify: `npm run check:specs`.
- [x] 1.6 Teacher dashboard "Sessions to mark": lessons up to today, newest first, a click opens the roll-call. Verify: `rollCall.test.mjs` (sessionsToMarkFilter, rollCallRoute); live.
- [ ] 1.7 Live on the throwaway primary school: a roll-call for Groep 7 as its teacher (one late, one absent without permission), another group as po-ib-01.
