# Design: learner-lookup-and-learnerrefs-fixes

## The defect class

LearnerProfile declares `ncUserId` (the Nextcloud user id) and has no `learnerId`. Every other schema calls the same value `learnerId`, which is how the wrong key got copied into profile lookups. OpenRegister's `MagicSearchHandler::applyObjectFilters()` answers a filter on an undeclared property with `1 = 0` (lines 2366-2376 at `d611a366`).

A scan of every LearnerProfile `findAll()` under `lib/` (schema constant or literal `learner-profile`, then its filter keys):

| site | key | fixed |
|---|---|---|
| `ReportCardComposer::resolveLearnerRef()` | `learnerId` | replaced by `LearnerRefResolver::resolve()` |
| `GradeRollupHandler::fanOutParentNotifications()` | `learnerId` | `ncUserId`, `_rbac: false` |
| `ReportCardPublishHandler::fanOutParentNotifications()` | `learnerId` | `ncUserId`, `_rbac: false` |
| `LearningPlanSignatureGuard::filterVerifiedParentSignatures()` | `learnerId` | `ncUserId`, `_rbac: false` |
| `AttendanceFlagCreationHandler`, `AssessmentResultAudience`, `LearningRecordAggregationService`, `SubjectChoiceConsentGuard`, `OsoDossierReviewGuard`, `DataExchangePayloadBuilder`, `SessionChangeNoticeHandler`, `ConferenceSignupGuardianGuard` | `ncUserId` | already right |

## Decisions

- **UUID only: reuse `LearnerRefResolver`.** It reads on `ncUserId`, prefers the profile that is not `mergedInto` another, and reads without RBAC. `ReportCardComposer` gains it as a constructor dependency and loses its private copy.
- **Profile row needed: correct the key in place.** The three `parentIds` readers need the row, not the UUID, and adding a row method to `LearnerRefResolver` would edit PR 1020's file while it is still open. They follow `AssessmentResultAudience::learnerManager()`: `ncUserId`, `limit: 1`, `_rbac: false`.
- **Why `_rbac: false`.** LearnerProfile read access is `instructors`, `hr`, `compliance-officers` and the pupil. A team lead may publish a grade, and a parent signs a learning plan; neither can read the profile. Only `parentIds` or the UUID leaves these methods.
- **Template lookup by `ids`.** `ObjectService::findAll()` passes `$config['ids']` to the get handler, which `MagicMapper::findAll()` turns into the reserved `_ids` filter. The tenant filter stays.
- **Submission stamp mirrors `GradeEntryLearnerRefStamp`.** Same events, same schema guard, same merge of earlier modified data, same fail-closed rules, with a list: learners without a profile are skipped, duplicates are removed, and "same learners" compares the sorted id lists.

## Declarative-vs-imperative decision

| behaviour | path | reason |
|---|---|---|
| Submission.learnerRefs | imperative listener | a cross-schema lookup (Submission to LearnerProfile on a non-key field) that no `x-openregister-calculations` expression can make; the ADR-031 exception PR 1020 already uses |
| the four lookups and the template lookup | imperative, existing code | corrections to existing guards and listeners |

## Seed Data

None. No schema is added or changed; the existing seed Submissions and LearnerProfiles already carry `learnerIds` and `ncUserId`.
