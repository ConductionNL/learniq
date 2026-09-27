# Test Plan: care-and-support-index

| Spec scenario | Test case | File |
|---|---|---|
| isOverdueForActivation is true once the deadline has passed on a still-draft plan | `test_isOverdueForActivation_calculation_shape` | `tests/Unit/Settings/CareAndSupportIndexRegisterTest.php` (new) |
| isOverdueForActivation is false once the plan has activated | (covered by the same calculation-shape assertion — OR evaluates the expression at read time; the register test asserts the declared expression, not a live evaluation, mirroring `ReportCardComposerRegisterTest`'s `isLocked` precedent) | `tests/Unit/Settings/CareAndSupportIndexRegisterTest.php` (new) |
| A tracked growth entry persists against the declared bandwidth | `test_outflowBandwidth_and_trackedGrowth_shape` | `tests/Unit/Settings/CareAndSupportIndexRegisterTest.php` (new) |
| An ObservationInstrument persists one entry per leerlijn | `test_observationInstrument_registered_with_full_enums` | `tests/Unit/Settings/CareAndSupportIndexRegisterTest.php` (new) |
| A Trajectory moves through its full lifecycle with a status update at each stage | `test_trajectory_lifecycle_transition_table` + `test_trajectoryStatusUpdate_is_appendOnly` | `tests/Unit/Settings/CareAndSupportIndexRegisterTest.php` (new) |
| LearningPlans carries the care-team-relevant columns, with no duplicate index page | manifest inspection via `build_effective_manifest.js` + `check_duplicate_index_pages.js` (no PHP-side behaviour to unit test — a manifest `config.columns`/`menu[].query` addition) | verified at build time, see tasks.md Task 4 |

## Non-spec regression coverage
- `php -l` — not applicable (no PHP files touched by this change).
- `python3 -m json.tool lib/Settings/learniq_register.json` and `learniq_mock_register.json`.
- `npm run check:specs` (json-strict, manifest, register, menu-role-gates).
- `npm run check:schema-l10n` (new schema strings).
