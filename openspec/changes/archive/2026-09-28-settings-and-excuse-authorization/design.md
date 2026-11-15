# Design: settings-and-excuse-authorization

## Architecture overview

```
portal SPA (portaliq#607 createExcuseRequest)
  POST /portal/api/collections/learniq/excuse-request   {dateFrom, dateTo, reason, reasonKind, attachmentRef}
    portaliq: whitelist, stamp learnerRef (pupil) or submittedByRef (guardian), saveObject(_rbac:false)
      -> OpenRegister saveObject: validate (required = dateFrom, dateTo, reason, reasonKind) -> ObjectCreatingEvent
           -> ExcuseRequestOwnerStamp: portal report? read the pupil's profile (and the guardian's)
                -> learnerId, learnerRef, tenant_id, submittedBy(+Ref), submittedAuthLevel
                -> refuse: unknown pupil, guardian not on the profile, lookup failed, owner missing
```

`LearniqSettings` needs no code: an `authorization` block is enforced by OpenRegister. `SegmentService` reads and writes the row with `_rbac: false` from the admin-only setup contract, so the block does not touch it.

## Decisions

### D1. Mirror SubmissionOwnerStamp
Same event pair, same portal detection (no session, no pupil id, a scope ref), same fail-closed lookups, same refusal shape (`setErrors` + `stopPropagation`), same `learnerRef` derivation for every other write. `LearnerProfileLookup::byRef()` and `refForUser()` are reused. The difference is the guardian path, which Submission does not have.

### D2. The guardian is checked against the child's profile
Portaliq already validates the guardian's `learnerRef` through its `via` join. Learniq checks again that `submittedByRef` is in the child's `guardianRefs`, because the listener is the last point before a row is written in another family's name. A guardian without an account (no `ncUserId`, so `byRef()` answers null) is still accepted: `submittedByRef` names them, and the submitter rule accepts either field.

### D3. The recorded level is the action's minimum
Portaliq does not pass the level the caller reached. The pupil action requires `low`, the guardian action `substantial`, so learniq records `basic` and `substantial`: never more than was enforced. A staff write keeps a level it sends and otherwise gets the schema's default `basic`.

### D4. Settings: staff read, administration managers write
Every staff group may read the segment, which is harmless configuration. Writing it changes menus and example sets for the whole organisation, so only `administration-managers` and admins may. Learners and guardians get no grant.

## Declarative-vs-imperative decision

| Behaviour | Path | Rationale |
|---|---|---|
| Who reads and writes LearniqSettings | declarative `authorization` block | OpenRegister enforces it |
| ExcuseRequest owner stamp and owner rule | imperative pre-write listener (ADR-031 exception) | reads another object (LearnerProfile), and OpenRegister validates `required` before any listener could fill it |

## Security considerations

- The pupil is never taken from the client on a portal report: it comes from the profile named by portaliq's server stamp.
- A guardian cannot file for a child that does not list them (D2).
- A signed-in caller never takes the portal path: a create with only a `learnerRef` and no `learnerId` is refused.
- The segment can no longer be changed by an instructor through the objects API.

## File structure

```
lib/Listener/ExcuseRequestOwnerStamp.php              new
lib/AppInfo/Registrar/IntegrityListenerRegistrar.php  registers it
lib/Settings/learniq_register.json                    LearniqSettings.authorization, ExcuseRequest.required, versions
tests/Unit/Listener/ExcuseRequestOwnerStampTest.php   new
tests/Unit/Settings/SegmentFeatureFlagsRegisterTest.php
tests/Unit/Settings/ExcuseRequestRegisterTest.php     new
docs/user-guide/user/05-attendance.md                 a short section on portal reports
```

## Seed data

No schema is added. The ExcuseRequest seed rows carry every owner field already and keep passing the stamp.

## Trade-offs

A generic "owner stamp" base class for Submission and ExcuseRequest would remove some duplication, and would couple two schemas whose rules differ (assignment tenant vs profile tenant, guardian path). Two small listeners stay easier to read.
