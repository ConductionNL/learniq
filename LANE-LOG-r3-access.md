# Lane r3-access (learniq), log

Clone: /home/rubenlinde/memcap-work/lq-lanes/lq-defects. Logs of each run: .tmp/ in the clone (never staged).

## 1. access-control-ratchet-compliance: DONE
- branch fix/access-control-ratchet-compliance, cut --no-track from origin/development a84b6273; commit d949174a; pushed.
- PR https://github.com/ConductionNL/learniq/pull/1124
- LvsResult + OsoImportDossier authorization (read coordinators, compliance-officers [+ learner self on LvsResult]; create/update coordinators, compliance-officers), FirstAidIncident reportedBy self-read, guards -> coordinators, appendOnly dropped on LvsResult + FirstAidIncident, DossierNote pins learn care-team entry. New ImportRecordAccessTest + singular-coordinator guard cases, shown red on dev.
- check:strict exit 1 (inherited only): phpmd CaseListenerRegistrar coupling; PHPUnit 1929 tests, 6 failures = the six landing-repair tests (change 2). lint/phpcs/psalm/phpstan green. npm lint 0, format 0, check:specs/schema-l10n/l10n-js 0.
- hydra gates (diff, base origin/development): exit 4, inherited only: gate-108 (mock Entitlement.orderLineId), gate-113 (payments spec cites deleted tests), gate-112, gate-53/68 wiring.
- opsx-verify: 0 critical, 0 warning.

## 2. round1-landing-repairs: DONE
- branch fix/round1-landing-repairs, STACKED on fix/access-control-ratchet-compliance (needed for the zero-failure acceptance and the shared register version); commits 08a8eb49 + eefa7701; pushed.
- PR https://github.com/ConductionNL/learniq/pull/1126 ; #1006 CLOSED with a comment pointing at #1126 and #1124.
- thresholdCrossed single entry, DataExchangeJob/DataMappingProfile target descriptions (1006 text, no em-dashes, en+nl keys), count floors (12, 16), three seed[0] reads -> by id, TransitionBridgeListenerRegistrar split (+ test that each bridge registers once).
- Skipped from 1006 as already fixed on dev: Cohort Groep 5/6 duplicate, Cohort index test.
- check:strict exit 0 ALL CHECKS PASSED: PHPUnit 1929 tests 0 failures, phpmd 0 findings. lint 0, format 0, check:specs/schema-l10n/l10n-js 0.
- hydra gates diff vs change-1 branch: exit 4, same inherited set as #1124.
- opsx-verify: one gap (double registration unguarded) fixed in eefa7701.

## 3. settings-and-excuse-authorization: DONE
- branch feat/settings-and-excuse-authorization, cut --no-track from origin/development a84b6273; commit bb390304; pushed.
- PR https://github.com/ConductionNL/learniq/pull/1129
- LearniqSettings.authorization (read: 7 staff groups; create/update: administration-managers; admins bypass). ExcuseRequest.required -> dateFrom,dateTo,reason,reasonKind; new ExcuseRequestOwnerStamp (portal pupil/guardian stamp, guardianRefs check, learnerRef derivation, owner refusal), registered via IntegrityListenerRegistrar::registerOwnerStamps() (extracted to keep register() under phpmd's 100 lines). Docs: 05-attendance.md portal section.
- check:strict exit 1 inherited only: phpmd Case coupling; PHPUnit 1936 tests, failure SET identical to development's known 12. lint 0, format 0, npm checks 0.
- hydra gates diff: exit 4, inherited set only.
- opsx-verify: 0 critical, 0 warning.

## 4. retired-schemas-prune: DONE
- branch feat/retired-schemas-prune, cut --no-track from origin/development 0171a896 (dev moved only docs/openspec since a84b6273); commits d1262ca0 + 32f299ef; pushed.
- PR https://github.com/ConductionNL/learniq/pull/1139
- Repair\PruneRetiredSchemas after ArchiveRetiredPaymentObjects (post-migration, before InitializeSettings): mechanics of occ openregister:schemas:prune-retired (findAllByApplicationAndSlug for learniq+scholiq, archival skip, unlink then cascadeDeleteSchema). Payment schemas with rows only when ArchiveRetiredPaymentObjects::isFullyArchived() (new); data-subject-request only when empty (#1070 decided AVG rows are not deleted by a repair step; prints the occ command). Stubs for SchemaMapper/RegisterMapper/SchemaDeletionService; psalm suppressions. App <version> -> 0.3.6-unstable.20260928090000 (gate-110).
- check:strict exit 1 inherited only: phpmd Case coupling; PHPUnit 1935 tests, failure SET identical to dev's 12; phpstan stale-cache false red, clean after clear-result-cache. lint 0, format 0, npm checks 0.
- hydra gates diff: exit 2, inherited only (gate-110 fixed by the version bump).
- opsx-verify: 0 critical, 0 warning.

## CI read (once, 2026-09-28)
- #1124: PHPUnit 1929 tests, 6 failures (expected: change 2's set), phpmd red (inherited Case coupling), Hydra Gates red (full-tree inherited: gate-3, 25, 49, 55, 62, 108).
- #1126: PHPUnit 1931 tests, 0 failures, but exit 1 from 10 RISKY tests under coverage (failOnRisky + beStrictAboutCoverageMetadata; CourseSharing/Store/CourseShareExport/CourseMetadataFilter/CourseStorePublisher tests, not ours; invisible locally with --no-coverage). Hydra Gates same inherited set.
- #1129: PHPUnit 1936 tests, 12 failures (dev's known set) + the same 10 risky; phpmd inherited.
