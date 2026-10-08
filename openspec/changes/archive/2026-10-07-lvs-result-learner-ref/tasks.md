# Tasks: an LVS result names its pupil by learner profile

- [x] 1.1 Declare `learnerRef` on `LvsResult` and bump `info.version`. Verify: PHPUnit `LvsResultLearnerRefRegisterTest::testLvsResultDeclaresLearnerRef` (red before, green after).
- [x] 1.2 Stamp `learnerRef` on every LvsResult write with `LvsResultLearnerRefStamp`, registered for create and update. Verify: PHPUnit `LvsResultLearnerRefStampTest`, including `testAnImportedResultIsLinkedToItsPupil` and `testTheStampIsRegisteredForCreateAndUpdate` (red before, green after).
- [x] 1.3 Back-fill existing results with `BackfillLvsResultLearnerRef`, listed under post-migration. Verify: PHPUnit `BackfillLvsResultLearnerRefTest`.
- [x] 1.4 Set `learnerRef` on every LVS result in `po.py` and regenerate `po.json`. Verify: `python3 scripts/example-sets/po.py --check`, PHPUnit `LvsResultLearnerRefRegisterTest::testEveryExampleLvsResultResolvesToItsPupilsProfile` and `testAGeneratedRowFitsTheRealSchema`, and `ExampleSetDescriptorContractTest`.
- [x] 1.5 Add the description's catalogue key with a Dutch translation. Verify: `npm run check:schema-l10n`.
