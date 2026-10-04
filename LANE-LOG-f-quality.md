# Lane f-quality (learniq follow-ups), 2026-09-28
Clone lq-contracts. Deps reinstalled (composer install, npm ci) from lockfiles. Use `vendor/bin/phpunit` with the default phpunit.xml (phpunit-unit.xml lacks the Integriq/Doctrine stubs).

## 1 lvs-score-freeze: DONE
- branch fix/lvs-score-freeze, PR https://github.com/ConductionNL/learniq/pull/1195
- LvsResultFreezeListener + EvidenceFreezeListenerRegistrar; LvsResult 0.2.2 (+dataExchangeJobId nullable), info 0.30.1
- strict: phpmd coupling fixed by registrar split; phpmd/psalm/phpstan 0 after; test:all 2121 OK; lint 0, format 0; gates exit 6 all inherited

## 2 notification-recipients-provisioned: DONE
- branch fix/notification-recipients-provisioned, PR https://github.com/ConductionNL/learniq/pull/1200
- 20 rules on 19 schemas mapped to declared, reading groups; new NotificationRecipientGroupsAreDeclaredTest (red first)
- check:strict 0, lint 0, format 0, schema-l10n 0, gates 6 (all inherited, same set as development)

## 6 mbz-test-temp-leak: DONE
- branch fix/mbz-test-temp-leak, PR https://github.com/ConductionNL/learniq/pull/1203
- tearDown removes the build dir; new test red first; check:strict 0, lint 0, gates 5 (inherited)
- cleaned own leaks under lq-contracts/.tmp (the /tmp ones belong to other runs, left)

## 3 grading-rollup-followups: DONE
- branch fix/grading-rollup-followups, PR https://github.com/ConductionNL/learniq/pull/1208
- FinalGrade.programmeId from Programme.curriculumPlanId; CompetencyAttainmentRollupHandler defers to CompetencyAttainmentRollupJob (work in CompetencyAttainmentRollup)
- tests red first; check:strict 0, lint 0, gates 5 inherited (16 and 61 PASS)

## 4 test-screen-autosave-and-deadline: DONE
- branch fix/test-screen-autosave-and-deadline, PR https://github.com/ConductionNL/learniq/pull/1213
- deadlineAt stamped and fixed server-side; screen timer + autosave; src/utils/attemptClock.js + node tests
- check:strict 0, lint 0, format 0, schema-l10n 0; js-unit 1 inherited fail (openregisterSchemaRefs); gates: 16 fixed, rest inherited

## 5 docx-through-documentextractor: DONE
- branch fix/docx-through-documentextractor, PR https://github.com/ConductionNL/learniq/pull/1216
- DocumentLessonReader (+DocumentBlockText, DocumentImageLoader); OfficeLessonExtractor prefers it, falls back
- strict run 1 psalm error (mine) fixed, psalm 0; test:all 2161 OK; lint 0; gates 5 inherited

## 7 import-landing-answer: DONE
- branch feat/import-landing-answer, PR https://github.com/ConductionNL/learniq/pull/1220 (integriq #2251 contract)
- ExchangeImportLandingListener + ExchangeImportLanding; verbatim event stub
- check:strict 0, lint 0; gates: 46 on stub fixed, rest inherited. Gap: gate hands allow([]) for imports, so no raw records yet

## 8 schooladvies-voorlopig-to-rod: DONE
- branch feat/schooladvies-voorlopig-to-rod, PR https://github.com/ConductionNL/learniq/pull/1221
- handler on create/update queues SchoolAdviesVoorlopigRodJob; voorlopigExchangeJobId; SchoolAdvies 0.3.1
- strict: test:all red on registrar pin test (updated), test:all 0 after; lint/format/schema-l10n 0; gates 6 inherited

## Lane complete. 8 PRs; 1195 1200 1203 1208 1213 merged by the orchestrator, 1216 1220 1221 open.
