# Lane f-imports (learniq follow-ups), 2026-09-28
Clone lq-contracts. Artifacts written by hand in the f-quality format (proposal, spec delta, tasks), validated with openspec.

## 1 import-records-in-gate-answer: DONE
- branch feat/import-records-in-gate-answer, was stacked on #1220, development merged in after #1220 landed (suite 2186 OK), PR https://github.com/ConductionNL/learniq/pull/1229
- ExchangeImportInput reads scope.fileId from requester's folder (CSV/JSON/XML, 10 MB, 5000 rows), gate hands rows in allow()
- red first; strict: all 0 except phpmd from shared ~/.pdepend cache (isolated HOME rerun 0); test:all 2177 OK; lint/format/schema-l10n 0; gates 8 inherited

## 2 oso-import-dossier-nullable-job: DONE
- branch fix/oso-import-dossier-nullable-job, PR https://github.com/ConductionNL/learniq/pull/1231
- nullable + schema 0.2.2 / register 0.31.5; DemoNullsAreNullableTest red first; 9 demo rows appended for DossierReview, ExchangePartnerApproval, TeldatumCheck (gate 101 judges whole descriptor)
- strict 0 (2179), lint/format/schema-l10n 0; gates --base: 101 PASS, 108 PASS, 8 inherited reds

## 3 gate-61-deferral: DONE
- branch fix/gate-61-deferral, PR https://github.com/ConductionNL/learniq/pull/1233
- LessonProgress + XapiEnrolmentCompletion (moved unchanged), XapiStatementFollowUpJob (2 kinds, dedupe per statement); handlers only queue
- red first; strict 0 (2183); lint/format 0; gates --base: 61 PASS, 16 PASS, 8 inherited
- #1229 gates rerun with --base: 16, 46 PASS, same 8 inherited

## Lane complete. PRs 1229, 1231, 1233 open, none merged.
