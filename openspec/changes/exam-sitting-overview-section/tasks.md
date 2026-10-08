# Tasks: extra time and free invigilators on an exam sitting

## 1. Section

- [ ] 1.1 Add `src/components/sections/ExamSittingOverview.vue`: props `objectId`; fetches `/apps/learniq/api/exam-sittings/{id}/overview` and `/available-invigilators` with `@nextcloud/axios` and `generateUrl`; resolves learner names through the OpenRegister objects API (`learner-profile`, `fullName`) and user display names; renders the accommodation rows, the place counts and the free list with "Ask". Uses NC components and CSS variables only.
- [ ] 1.2 "Ask": POST an `invigilator-assignment` `{examSittingId, invigilatorId}` to the OpenRegister objects API; on success reload both routes and emit a refresh for the Invigilators list; on a refusal show the server's message.
- [ ] 1.3 Register it in `src/registry.js` as `ExamSittingOverview: {kind: 'section', component, _note}`.
- [ ] 1.4 `src/manifest.d/exam-schedule.json` ExamSittingDetail: `config.bodyWidgets: [{id: "exam-sitting-overview", component: "ExamSittingOverview", title: "Extra time and invigilators", placement: "after-data"}]`. Verify: `npm run check:manifest`.
- [ ] 1.5 English and Dutch strings (design labels and empty states) in `l10n/`. Verify: `npm run check:l10n`.

## 2. Tests

- [ ] 2.1 Vitest `tests/js/ExamSittingOverview.spec.js`: renders rows from a mocked overview, the two empty states, and the reload after "Ask".
- [ ] 2.2 Playwright `tests/e2e/exam-sitting-overview.spec.ts` on the example set: open a sitting with an accommodated learner, read the end time, ask a free colleague, see the counts change. Tag the scenarios with `@e2e`.
- [ ] 2.3 Visual baseline for the section (gate 26).

## 3. Close out

- [ ] 3.1 Set row `ass-plan-an-exam-week` to `built`, `learniq: yes`, evidence with the section and the e2e, `reachedOn: "/exam-sittings/:id > Extra time and invigilators"`; archive this change.
