---
kind: code
depends_on: [access-control-ratchet-compliance]
---

# Proposal: round1-landing-repairs

## Summary

The 31 round-one pull requests were landed one after another, and the landing re-ran the register checks between merges but not the PHP tests. PR #1006 repaired what that missed; development has since moved on and fixed part of it. This change carries the parts development still lacks: one notification recipient list, two connection descriptions, the seed tests that pin a position or an exact count, and the listener registrar that phpmd flags. With `access-control-ratchet-compliance` (stacked below), the full PHPUnit suite on learniq is green again. #1006 is closed in favour of this change.

## Motivation

On `development` at `a84b6273`, after `access-control-ratchet-compliance`, six PHPUnit failures and one phpmd finding remain:

| Finding | What went wrong in the landing |
|---|---|
| `AttendanceThresholdRegisterTest::testThresholdCrossedNotificationNotifiesMentorAndCoordinator` | `thresholdCrossed` has two recipient entries, `[mentor]` and `[mentor, coordinator]`: the mentor is notified twice |
| `LvsResultRegisterTest::testDataExchangeJobTargetDescribesLvsResults`, `OsoImportDossierRegisterTest::testDataExchangeJobTargetDescribesOsoImport`, `UwlrEduvBasispoortRegisterTest::testTargetDescriptionsNameNewConnections` | four changes rewrote `DataExchangeJob.target` and `DataMappingProfile.target`; only the last survived, so lvs-results, the inbound oso direction, uwlr, edu-v, basispoort and entree-content went missing |
| `DataMappingProfilePresetsRegisterTest::testExistingSeedsUnchangedAndFiveAdded`, `UwlrEduvBasispoortRegisterTest::testExistingTimetableImportSeedsUnchanged` | exact seed counts (12, 16) that every sibling change breaks by appending a seed; there are 23 now |
| phpmd `CouplingBetweenObjects` on `CaseListenerRegistrar` (13) | the school-advice bridge was added to an already full registrar |

Three more tests pin `x-openregister-seed[0]` (`ConfidentialCounsellorChannelRegisterTest`, `CourseShareConsentRegisterTest`, `SharedCoursePackageRegisterTest`). They pass today and break the day another change seeds the same schema.

What #1006 fixed that development no longer needs: the duplicate Cohort seed "Groep 5/6" (register seeds moved to the example sets in #1031, and `po.json` holds one row) and the Cohort seed index test (already by name).

## Affected Projects

- [x] Project: `learniq` — register (three schemas), catalogue keys, one new listener registrar, seven tests

## Scope

### In Scope

- `AttendanceThreshold.thresholdCrossed.recipients`: one entry, `mentor` and `coordinator`; the test also refuses a group named twice.
- `DataExchangeJob.target` and `DataMappingProfile.target`: #1006's descriptions, naming every connection, without em-dashes; en and nl catalogue keys.
- The two exact seed counts become floors; the three index-0 seed reads find their row by id.
- `TransitionBridgeListenerRegistrar`: `CredentialRenewalListener` and `SchoolAdviesSendToRodHandler` move out of the scheduling and case registrars, as in #1006.
- Versions: the three schemas to `0.1.1`, `info.version` to `0.29.1`.

### Out of Scope

- The notification recipients name role words (`mentor`, `coordinator`) that are not declared groups, here and in nine other rules. That is a separate defect; this change only removes the duplicate entry.
- D25 moves data exchange to integriq. This change edits two descriptions only.

## Approach

Register patches for the recipients and descriptions, test edits for the pins, and one registrar class that takes two existing registrations.

## New Dependencies

None.

## Impact

`lib/Settings/learniq_register.json`, `l10n/{en,nl}.{json,js}`, `lib/AppInfo/Registrar/{Case,Scheduling,TransitionBridge}ListenerRegistrar.php`, `EventListenerWiring.php`, seven tests under `tests/Unit/Settings/`.

## Cross-Project Dependencies

None.

## Risks

### Risk 1: Listener order changes for two transitions
**Severity:** Low — **Mitigation:** each bridge acts on its own schema (Credential, SchoolAdvies), which no other `ObjectTransitionedEvent` listener writes; `RegisteredListenersHandleRealEventsTest` runs the wiring.

### Risk 2: The D25 lane edits DataExchangeJob too
**Severity:** Low — **Mitigation:** named in the PR body so the landing orders the two.

## Rollback Strategy

Revert the merge commit.
