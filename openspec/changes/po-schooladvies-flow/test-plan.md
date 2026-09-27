# Test Plan: po-schooladvies-flow

| Spec scenario | Test case | File |
|---|---|---|
| A SchoolAdvies persists with its declared lifecycle and calculations | `test_schoolAdvies_registered_with_lifecycle_and_calculations` | `tests/Unit/Settings/SchoolAdviesRegisterTest.php` (new) |
| A higher doorstroomtoets result without a raised definitief or a motivation blocks finalisation | `testHigherDoorstroomtoetsWithoutRaiseOrMotivationBlocksFinalisation` | `tests/Unit/Lifecycle/SchoolAdviesFinalizeGuardTest.php` (new) |
| Raising definitiefAdviesLevel to match the doorstroomtoets result allows finalisation | `testRaisedDefinitiefAllowsFinalisation` | `tests/Unit/Lifecycle/SchoolAdviesFinalizeGuardTest.php` (new) |
| A motivation allows finalisation without raising the level | `testMotivationAllowsFinalisationWithoutRaise` | `tests/Unit/Lifecycle/SchoolAdviesFinalizeGuardTest.php` (new) |
| The pro/vmbo-bb exemption allows finalisation without a raise or motivation | `testProVmboBbExemptionAllowsFinalisation` | `tests/Unit/Lifecycle/SchoolAdviesFinalizeGuardTest.php` (new) |
| A doorstroomtoets result that does not outrank the definitief advies never blocks finalisation | `testNonOutrankingResultNeverBlocks` | `tests/Unit/Lifecycle/SchoolAdviesFinalizeGuardTest.php` (new) |
| Sending a definitief advies creates and links a bron-rod DataExchangeJob | `testSendToRodCreatesAndLinksJob` | `tests/Unit/Listener/SchoolAdviesSendToRodHandlerTest.php` (new) |
| Pages are manifest-declared | manual/manifest inspection via `build_effective_manifest.js` | verified at build time, see tasks.md Task 4 |

## Non-spec regression coverage
- `php -l` on both new PHP files.
- `vendor/bin/phpcs`/`phpstan analyse` on both new PHP files (diff-scoped).
- `php -d memory_limit=2G vendor/bin/phpmd` on both new PHP files (complexity check, per the
  `ExcessiveClassComplexity` finding `report-card-templates` — a sibling change this round — caught
  mid-build).
- `python3 -m json.tool` on both touched register files.
- `npm run check:specs` (manifest/menu-role-gates) for the manifest side.
