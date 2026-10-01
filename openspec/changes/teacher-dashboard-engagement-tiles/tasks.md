# Tasks: the engagement tiles of a group teacher count only their own groups

- [x] 1.1 `teacherScope()` also returns the pupils of the taught cohorts (`learnerIds`). Verify: `node --test tests/unit-js/teacherScope.test.mjs` (the scope holds the pupils of the cohorts the teacher teaches, and no others).
- [x] 1.2 `teacherTileSource()` adds `learnerId: { in: [...] }` for a group teacher, keeps the declared source for school-wide roles, and returns no source while pending or without pupils. Verify: `teacherScope.test.mjs` (red before: the export did not exist).
- [x] 1.3 `LearniqDashboards` feeds both engagement tiles through `teacherTile()`. Verify: `teacherScope.test.mjs` (the teacher view feeds both engagement tiles through the teacher scope).
- [ ] 1.4 Live: the tiles as `po-leerkracht-09` against the aggregation of Groep 7's pupils, and as `po-ib-01` against the school-wide aggregation.
