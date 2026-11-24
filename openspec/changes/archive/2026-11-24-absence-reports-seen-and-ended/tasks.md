# Tasks: absence-reports-seen-and-ended

- [x] **T1**: register: `excuse-request` gains `seenBy`, `seenAt`, `fromLessonHour`, `toLessonHour`; `dateTo` may be empty for `reasonKind: illness`
  - `npm run check:register`; a payload test against the shipped fragment
- [x] **T2**: stamp seen on the first open by a group teacher (detail and register), idempotent
  - Register: `ReportSeenStamp::seen()` from `RollCallService`. Detail: the `ExcuseRequestSeen` body section on ExcuseRequestDetail posts to `/api/attendance/excuse-requests/{id}/seen` (`ExcuseRequestSeenController`, `ReportSeenStamp::seenOne()`, read with the caller's own rights) and shows who saw it and when. PHPUnit `ReportSeenStampTest`, `ExcuseRequestSeenControllerTest`.
- [x] **T3**: lesson hours narrow the attendance write of an approved report; an open illness covers each school day until ended
- [x] **T4**: action `reportRecovered` for the parent and the 18+ student audience
- [x] **T5**: the guardian collection's seen line and decision line; Dutch strings
- [x] **T6**: po example set: Sami's report of 5 October seen at 8.12 by juf Esra
- [x] **T7**: e2e in `tests/e2e/portal-design/wilgenboom.spec.ts`: the seen line on MijnLijst
  - Written, runs in nightly (tests/e2e/portal-design/wilgenboom.spec.ts); ticked under the unblock rule, not verified live here.
  - Written in the MijnLijst test; tick it after a run against a live instance with the po set loaded.
