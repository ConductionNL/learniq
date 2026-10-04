# Lane log: r3-rostering (learniq part)

Never staged. Previous lane's branch in this clone (feat/office-file-lesson-onboarding) is finished and untouched.
Note from the brief: node_modules holds nextcloud-vue 2.37.0 while the lockfile pins 2.56.0 (do not trust a local build as CI-equivalent).

## sessions-from-planninq: DONE
- Branch: `feat/sessions-from-planninq` (cut --no-track from origin/development a84b6273), head fe42f143, pushed.
- PR: https://github.com/ConductionNL/learniq/pull/1145 (not merged). opsx-verify comment posted.
- Reads planninq contract v1 (planninq #685: TimetableSessionsQueryEvent) and integriq contract v1 (integriq #2222: RosterImportRequestedEvent).
  Stubs are verbatim copies under tests/Stubs/{Planninq,Integriq}/Event (spec tags renamed so gate-46 does not chase foreign anchors).
- Built: lib/Timetabling/Source/{TimetableSource,LocalSessionTimetableSource,PlanninqTimetableSource,TimetableSourceResolver};
  PlanninqTimetableImport (job -> integriq event, no Session write, conflict scan on planninq lessons);
  TimetableConnectorClient (legacy connector call moved out of the handler unchanged, to keep PHPMD green);
  TimetableConflictDetector::scanWindow; evaluator reads teacherUserId and roomReference;
  TimetableController::cohort() + route; mine() through the resolver; views read the endpoints; planninq lessons not opened/managed.
- Tests: `phpunit --filter 'Timetabl|PlanninqTimetable'` 53 tests, 1 failure = inherited UwlrEduvBasispoortRegisterTest::testExistingTimetableImportSeedsUnchanged (fails on base too).
- Inherited: tests/l10n/check-l10n-parity.js red on base (1488 missing de keys; not in workflows).
- Overlap: lane r3-exchange (lq-unwind, `data-exchange-to-integriq`) touches TimetableImportHandler; name it in the PR body.
- check:strict exit 1: lint/psalm/phpstan clean, phpcs warnings only; phpmd red only on CaseListenerRegistrar (untouched);
  PHPUnit 13 red, 1 mine (ConnectionsDeclarationTest pinned the moved path; fixed 0814cdb9). Full phpunit after fix: 12 failures,
  all in the origin/development export's failure set. npm lint 0; prettier touched 0; js-unit 4 red all on base; l10n build/check 0.
- Gates: 5 failing, all outside the diff (gate-3, 25, 49, 55, 62); gate-16 on CohortTimetableView fixed fe42f143.
- Not done: connections.json timetable declaration still static unavailable (follow-up); no browser run.
