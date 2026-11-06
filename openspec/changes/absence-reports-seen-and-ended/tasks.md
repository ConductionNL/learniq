# Tasks: absence-reports-seen-and-ended

- [ ] **T1**: register: `excuse-request` gains `seenBy`, `seenAt`, `fromLessonHour`, `toLessonHour`; `dateTo` may be empty for `reasonKind: illness`
  - `npm run check:register`; a payload test against the shipped fragment
- [ ] **T2**: stamp seen on the first open by a group teacher (detail and register), idempotent
- [ ] **T3**: lesson hours narrow the attendance write of an approved report; an open illness covers each school day until ended
- [ ] **T4**: action `reportRecovered` for the parent and the 18+ student audience
- [ ] **T5**: the guardian collection's seen line and decision line; Dutch strings
- [ ] **T6**: po example set: Sami's report of 5 October seen at 8.12 by juf Esra
- [ ] **T7**: e2e in `tests/e2e/portal-design/wilgenboom.spec.ts`: the seen line on MijnLijst
