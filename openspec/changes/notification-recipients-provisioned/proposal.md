---
kind: code
depends_on: []
---

# Proposal: notification-recipients-provisioned

## Summary

Twenty notification rules in `lib/Settings/learniq_register.json` sent to groups named by role words (`coordinator`, `mentor`, `examboard`, `exam-board`, `study-advisor`, `compliance-officer`) that no install provisions, so they fired and reached nobody. Each now names groups the register declares, and a register test fails on any group recipient outside that set or unable to read the object.

## Motivation

TRACKER-R2 "ROUND 3 FINAL": "notification rules naming unprovisioned role words (coordinator, mentor, examboard)". The groups an install has are the oauth2 scopes in `components.securitySchemes` (`instructors`, `hr`, `compliance-officers`, `team-leads`, `learners`, `coordinators`, `guardians`, `administration-managers`, `confidential-counsellors`) plus `admin`. The migrated `Regulation.onPublished` scenario in the scholiq-notifications spec even pins the singular `compliance-officer`.

## Affected Projects

- [ ] Project: `learniq`: `lib/Settings/learniq_register.json` (19 schemas, `info.version`), a new register test, two register tests that pinned the old words.

## Scope

### In Scope

The mapping, per rule, chosen so the group can read the object it is told about (the schema's `authorization.read`, where one is declared):

| Rule | Was | Now |
|---|---|---|
| Regulation.published | compliance-officer | compliance-officers |
| ExternalTrainingRecord.submittedForVerification | compliance-officer, hr | compliance-officers, hr |
| TimetableConflict.conflictDetected | coordinator | coordinators |
| ItemRevisionFlag.flagRaised | examboard, admin | compliance-officers, admin |
| ReportPeriod.lockDatePassed | mentor, coordinator | coordinators, team-leads (team-leads write the period) |
| ExemptionCase.submittedForAssessment | examboard | compliance-officers |
| FraudCase.reported | examboard | compliance-officers |
| TlvApplication.tlvDecisionReceived, tlvExpiringSoon | coordinator | compliance-officers, team-leads (its readers) |
| BehaviourIncident.incidentRecorded | mentor, coordinator | compliance-officers (its staff reader besides instructors) |
| WellbeingCheckIn.checkInSubmitted | mentor | compliance-officers |
| AttendanceThreshold.thresholdCrossed | mentor, coordinator | coordinators |
| AttendanceFlag.reportDeadlineOverdue | coordinator (plus field `mentorId`) | compliance-officers (plus field `mentorId`) |
| BsaProgressFlag.flagRaised | study-advisor, exam-board | team-leads, compliance-officers |
| EngagementRiskFlag.flagRaised | mentor, coordinator | coordinators |
| BsaDecision.decided | study-advisor (plus field `learnerId`) | team-leads (plus field `learnerId`) |
| ConferenceRound.bookingAutoClosed | coordinator | coordinators |
| AccessibilityStatement.reviewOverdue | compliance-officer | compliance-officers |
| AccessibilityFeedback.onSubmitted | compliance-officer, admin | compliance-officers, admin |
| FirstAidIncident.incidentRecorded | mentor, coordinator | compliance-officers |

The exam-board role maps to `compliance-officers`, as decision D23 does for the import guards. There is no mentor group; where a rule has a mentor field (`AttendanceFlag.mentorId`) it already uses it, elsewhere the rule reaches the staff group that reads the object.

### Out of Scope

- `x-property-rbac` role words (`teacher`, `examboard`, `mentor`): a different vocabulary, not group recipients.
- A per-pupil mentor field on the schemas that lack one.

## Approach

Rewrite the groups of each rule, bump each touched schema's `version` and `info.version`, and add `tests/Unit/Register/NotificationRecipientGroupsAreDeclaredTest.php`.

## New Dependencies

None.

## Impact

Twenty rules that reached nobody now reach a real group. Instructors are not added anywhere: a mentor rule sent to all instructors would reach every teacher.

## Cross-Project Dependencies

None.

## Risks

### Risk 1: A school expects the mentor to hear of a behaviour incident

**Severity:** Medium. **Mitigation:** Named in Out of Scope; a mentor field on the incident is the follow-up. Today the rule reached nobody.

## Rollback Strategy

Revert the merge commit.
