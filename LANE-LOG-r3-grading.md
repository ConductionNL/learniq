# Lane r3-grading (learniq), 2026-09-27 evening

Clone: /home/rubenlinde/memcap-work/lq-lanes/lq-reports. Previous lane's stray `LANE-LOG.md` edit is in
`stash@{0}` ("r3-grading: stray LANE-LOG.md edit from feat/peer-review-projection-guard (previous lane), left untouched"); do not pop it.
Evidence logs for each change live under `.tmp/r3/` in this clone (untracked; never `git add .tmp`).

## Change 1: grading-defects-from-example-sets — DONE

- Branch `fix/grading-defects-from-example-sets` (cut from origin/development a84b6273), commits 603c6630, 0d9028b8, pushed.
- PR https://github.com/ConductionNL/learniq/pull/1127
- Six defects: passRules minValue + final-grade rule; exemption-only plan passes (BSA credit); werkproces code resolved per dossier (sourceRef = kwalificatiedossierCode, else unambiguous only); FinalGrade.cohortId no longer written; AttendanceFlag.flagKind `attendance-requirement` from threshold kind; QTI label 2.1 (editor via src/utils/qtiItemXml.js, Moodle mapper, exporter relabel, register text, llms.txt).
- Register 0.29.0; AttendanceFlag 0.2.0, Item 0.2.1, ItemBank 0.1.1, Assessment 0.3.1. MBO + HE example sets regenerated.
- Red-first: every new test run against development code first (see PR table).
- check:strict exit 1 = inherited only (phpmd CaseListenerRegistrar; PHPUnit 1945 tests, the 12 access-control failures). lint 0, format 0, js-unit 4 failures identical on development, schema-l10n 0, specs 0, hydra gates exit 2 (gate-61 CompetencyAttainmentRollupHandler inherited, gate-108 mock Entitlement inherited).
- opsx-verify: pass, no critical or warning left.
- Follow-ups named in the PR: training set writes real QTI 3.0 markup; roll-up never writes FinalGrade.programmeId; no backfill for old FinalGrades; mock Entitlement.orderLineId; Moodle choice items lack choiceInteraction; gate-61 deferral for CompetencyAttainmentRollupHandler.

## Change 2: pok-signature-parent-role — DONE

- Branch `feat/pok-signature-parent-role` (from origin/development a84b6273). Artifacts written and valid.
- Done: task 1 (register: PokSignature.signerRole parent, Praktijkovereenkomst.parentSignatureRequired + parentSignatureCount + isFullySigned clause + stamp action on requestSignatures/activate, versions 0.2.0, info 0.28.2, catalogue), task 2 (lib/Service/PokParentSignatureRule.php + test, 7 green).
- Done also: task 3 guard, task 4 stamp action, task 5 signing page, task 6 MBO set (171 parentIds, 151 flags, +72 parent signatures appended). Commits f866a830, d8d2005d, 6106bb1c pushed.
- check:strict exit 1 inherited only (phpmd CaseListenerRegistrar; PHPUnit 1940 tests, same 12 failures as development). lint 0, format 0, js-unit same 4 inherited, specs 0, schema-l10n 0, l10n-js 0, openspec 0, g108 3 inherited, g101 0. Hydra gates exit 1: 56 of 56 applicable ran, only gate-108 (inherited).
- PR https://github.com/ConductionNL/learniq/pull/1143 . opsx-verify: pass. Landing: conflicts with #1127 on mbo.json and info.version (rerun the generator).
- 2026-09-28: resumed after the account limit reset; pushed a wip commit before continuing.

## Change 3: reads-that-filter-on-undeclared-ids — DONE

- Branch fix/reads-that-filter-on-undeclared-ids (from origin/development 0171a896), commit 781cbcd5 pushed. Fix used config `ids` (not find(): keeps tenant filters in the query, as #1116 asked). 37 reads in 30 files (the scan found 8 beyond the ratchet's 29, e.g. ObjectRowReader::load, Werkproces/PortfolioGradeEmitHandler). KNOWN_UNDECLARED 35 -> 6. 9 doubles moved from filters.id to ids; 17 failures on development's lib, green here.
- check:strict exit 1 inherited only (phpmd CaseListenerRegistrar; 1922 tests, same 12). lint 0, openspec 0, hydra gates exit 0 (41/41).
- PR https://github.com/ConductionNL/learniq/pull/1144 . opsx-verify: pass.

- Plan: ObjectRowReader::byId(schema, id, tenantId) does find() by id and refuses another tenant's row; load() delegates to it; every id/uuid filter site in FindAllFilterKeysAreDeclaredTest::KNOWN_UNDECLARED moves to it (29 entries, a superset of the 22 in #1047's body); FindAllConfigScopeTest gains a check that refuses an 'id' or 'uuid' filter key even for a schema chosen at run time.

## Change 4: learnerrefs-backfill-and-lookup-dedupe — DONE

- Branch fix/learnerrefs-backfill-and-lookup-dedupe (from 0171a896), commit 9c64484f pushed. resolve() unchanged + resolveAcrossTenants() + byRef() (phpmd refused a boolean flag). LearnerProfileLookup kept as deprecated facade because open #1129 adds a caller. BackfillSubmissionLearnerRefs registered after BackfillGradeEntryLearnerRef, with a registration test.
- check:strict exit 1 inherited only (1927 tests, same 12). Gates: 34/34 ran, gate-110 (version move) fixed in 16dabfb0, standalone 0.
- PR https://github.com/ConductionNL/learniq/pull/1147 . opsx-verify: pass.
- TODO before report: add a <version> move to #1127 and #1143 (register changes only reach instances through the upgrade).

- Plan: LearnerRefResolver absorbs LearnerProfileLookup (byRef + resolve(learnerId, acrossTenants) so each caller keeps its read behaviour); delete LearnerProfileLookup; callers AssessmentResultPortalStamp, PortalLearnerResolver, SubmissionOwnerStamp. New repair step BackfillSubmissionLearnerRefs (learnerRefs + learnerRef from learnerIds, idempotent), registered after BackfillGradeEntryLearnerRef.
- Done: version moves pushed on #1127 (f55fc926) and #1143 (b3829155), noted in both PR bodies.

## Change 5: in-app-test-limits-server-side — IN PROGRESS

- Plan: reuse PortalAttemptClock (deadline, extra time, grace), PortalAttemptReader (attemptsFor, accommodations) and the catalogue's attempts rule. Create: refuse past maxAttempts, stamp startedAt and attemptNumber from the server. Update by the learner: keep startedAt/attemptNumber, drop answer changes after deadline plus grace so the hand-in still goes through with the in-time answers.
- Branch fix/in-app-test-limits-server-side (from 0171a896), commit 09016e1e pushed. attemptsBlock moved into AssessmentAccessPolicy (catalogue shares it); new AssessmentAttemptLimits (start + answersLate) on PortalAttemptReader/Clock; gate uses it; new AssessmentAttemptTimeLimitListener (start fixed, late answers kept out, scores on unchanged answers kept) registered via helper in IntegrityListenerRegistrar (phpmd forced the split out of the integrity listener). TakeAssessmentView shows attempts-used.
- Next: strict, gates, PR, verify, then the final report.
- 2026-09-28 resume: vendor/node_modules reinstalled from lockfiles (unchanged). #1147: ExcuseRequestOwnerStamp (from #1129) moved to LearnerRefResolver, LearnerProfileLookup deleted (4c752d61). #1152, #1127, #1143 merged with development (register 0.29.3, app version moved); full PHPUnit green on each (0 failures; development's 12 are fixed upstream).

## Change 5: DONE — PR #1152. All five changes done.
