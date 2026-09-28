---
kind: code
depends_on: []
---

# Proposal: settings-and-excuse-authorization

## Summary

Two access and write defects on `development`. `LearniqSettings` has no `authorization` block, so the register cascade lets every instructor, hr officer, compliance officer and team lead change the organisation's segment, which decides the menus and example sets for everyone. And `ExcuseRequest` lists `learnerId`, `submittedBy`, `submittedAuthLevel` and `tenant_id` as required, which a portal absence report cannot send: OpenRegister validates `required` before any listener runs, so every portal report is refused before learniq sees it. This change gives `LearniqSettings` a block (staff read, administration managers write) and moves `ExcuseRequest`'s owner fields from `required` into a server-side stamp that fills them for a portal report and refuses any write that still lacks them, the pattern `SubmissionOwnerStamp` (learniq PR 1068) set for Submission.

## Motivation

- `LearniqSettings` (segment-feature-flags) is documented as admin-only, and its menu entry is admin-only, but OpenRegister enforces the schema's `authorization` block, not the menu. Without one, the cascade (`read-write`: instructors, hr, compliance-officers, team-leads) applies, so an instructor can PUT a new segment through the objects API.
- The portal already offers `createExcuseRequest` to pupils (`minTrust: low`) and to guardians (`minTrust: substantial`, portaliq#607). Portaliq writes only the dates, the reason, its kind and an attachment, plus its scope stamp (`learnerRef` for a pupil, `submittedByRef` for a guardian). With four fields it cannot send in `required`, OpenRegister's validator refuses the write with a 400 before `ObjectCreatingEvent`. The feature is dark.

## Affected Projects

- [x] Project: `learniq` — register (two schemas), one new listener and its registration, tests, attendance user guide

## Scope

### In Scope

- `LearniqSettings.authorization`: read by the staff groups (`instructors`, `hr`, `compliance-officers`, `team-leads`, `coordinators`, `administration-managers`, `confidential-counsellors`); create and update by `administration-managers`. Admins pass OpenRegister's bypass. No delete.
- `ExcuseRequest.required` shrinks to `dateFrom`, `dateTo`, `reason`, `reasonKind`.
- `ExcuseRequestOwnerStamp` on `ObjectCreatingEvent` and `ObjectUpdatingEvent`:
  - a portal report (no session, no `learnerId`, a `learnerRef`) gets `learnerId`, `learnerRef` and `tenant_id` from the pupil's profile;
  - a pupil's own report names the pupil as submitter and records `basic`, the level the pupil action requires;
  - a guardian's report is refused unless the child's profile lists that guardian in `guardianRefs`, names the guardian's user id when they have one, and records `substantial`;
  - every other write derives `learnerRef` from `learnerId` (a client value is replaced) and gets the default level `basic` when it sends none;
  - every write that still lacks the pupil, a submitter (`submittedBy` or `submittedByRef`) or the school is refused.
- Version bumps: both schemas `0.2.0` to `0.3.0`, `info.version` to `0.29.0`.

### Out of Scope

- The portal action contract in `PortalContributionProvider`: unchanged.
- Reading the level portaliq actually reached. Portaliq does not send it; learniq records the minimum the action enforced.
- Gating other menus on the segment (D26, segment-menu-gating).

## Approach

A register patch for both schemas and one pre-write listener modelled line for line on `SubmissionOwnerStamp`.

## New Dependencies

None. `LearnerProfileLookup` (PR 1068) does the profile reads.

## Impact

`lib/Settings/learniq_register.json`, `lib/Listener/ExcuseRequestOwnerStamp.php` (new), `lib/AppInfo/Registrar/IntegrityListenerRegistrar.php`, tests, `docs/user-guide/user/05-attendance.md`.

## Cross-Project Dependencies

Portaliq's `createExcuseRequest` writer (portaliq#607) starts working once this lands; no portaliq change is needed.

## Risks

### Risk 1: A staff form no longer marks the four fields required
**Severity:** Low — **Mitigation:** the listener refuses the write with a message naming what is missing, as for Submission.

### Risk 2: Another round-three lane edits the register
**Severity:** Low — **Mitigation:** the PR names both schemas for the landing order.

## Rollback Strategy

Revert the merge commit; the next import restores the old required list and removes the block.
