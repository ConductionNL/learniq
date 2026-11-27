---
kind: code
---

# Show the extra time and the free invigilators on an exam sitting

## Why

An exam week is mostly planned from its pages: `ExamPeriods`, `ExamPeriodDetail`, `ExamSittings` and `ExamSittingDetail` (`src/manifest.d/exam-schedule.json`). `ExamSittingPlacementCheck` records rooms, capacity and clashes on save, and the sitting lists its invigilation requests. Two things the archived `2026-09-29-timetabling-exam-schedule` built never reach a page. `ExamSittingOverview::overview()` computes, for every learner with an approved accommodation, the end time with extra time and whether they sit in a separate room, plus the invigilator places (needed, confirmed, pending, open). `availableInvigilators()` lists the colleagues who are free for the whole sitting and not asked yet. Both are served at `GET /api/exam-sittings/{id}/overview` and `GET /api/exam-sittings/{id}/available-invigilators`, and no file in `src/` calls either route. A planner cannot see whose exam ends later, and has to guess who to ask.

### Matrix rows (`openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `ass-plan-an-exam-week` | Plan an exam week with rooms, extra time and invigilators, and see the clashes. | `partial`: rooms and clashes on the pages; extra time and free invigilators only on the API |

## What changes

- A section "Extra time and invigilators" on `ExamSittingDetail`, under the sitting's data: one row per accommodated learner (name, extra time, end time, separate room), and the invigilator places as "2 of 3 confirmed, 1 asked, 0 open".
- Under it, the free colleagues, each with an "Ask" button that creates a pending invigilation request for this sitting. The request then shows in the existing Invigilators list and the free list drops that colleague.
- Empty states that say what is missing: "No learner in these classes has extra time" and "Nobody else is free for the whole sitting".

## Capabilities

### Modified capabilities

- `exam-schedule`: ADDED requirements for the overview section.

## Impact

- **Frontend**: new `src/components/sections/ExamSittingOverview.vue`, registered in `src/registry.js` as `kind: 'section'`; `config.bodyWidgets` on `ExamSittingDetail`; l10n.
- **Backend**: none. The routes, the service and the `InvigilatorAssignmentCheck` on create exist. The learner name is read from the learner profile the caller may read.
- **Register**: none.
