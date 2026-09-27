# Design: assignment-portal-wiring

## Architecture Overview

```
portal SPA (portaliq #745 SchemaForm)
  1. POST /portal/api/collections/learniq/submission         {assignmentId}
       portaliq: whitelist, drop file fields, stamp learnerRef = subjectRef, saveObject(_rbac:false)
         -> OpenRegister saveObject: validate (required = [assignmentId]) -> ObjectCreatingEvent
              -> SubmissionOwnerStamp: portal hand-in? read LearnerProfile(learnerRef) + Assignment
                   -> learnerIds = [ncUserId], learnerRefs = [learnerRef], tenant_id = Assignment.tenant_id
                   -> refuse when the profile or assignment does not resolve, or tenants differ
  2. POST /portal/api/collections/learniq/submission/{id}/fields/attachmentRefs?action=createSubmission
       portaliq: attach file to the object folder, append the file id to attachmentRefs (update)
         -> ObjectUpdatingEvent -> SubmissionOwnerStamp re-derives learnerRef from learnerIds[0]
  3. GET  /portal/api/collections/learniq/submission          scoped by learnerRef == scope claim
```

Learniq holds the rules; portaliq holds the form and the upload. No learniq route is added.

## Decisions

### D1: Stamp in a listener and move the requirement with it
OpenRegister validates `required` in `ObjectService::saveObject()` (`validateObjectIfRequired()`)
before `SaveObject` reaches `MagicMapper`, which dispatches `ObjectCreatingEvent`. Its only
pre-validation hooks are `defaultBehavior: always` Twig defaults and expression defaults, neither of
which can read another object. So a server stamp of `learnerIds` cannot satisfy `required`.
`learnerIds` and `tenant_id` leave `required`, and `SubmissionOwnerStamp` refuses any write that
still lacks them after stamping. Staff keep the rule, now enforced by the server instead of the
validator.

Alternatives considered:
- A portaliq action `defaults` map: static values only, while `learnerIds` differs per pupil.
- OpenRegister's `@notSupplied` record: it records a deliberate gap, stays on the stored body and
  would contradict the value the stamp writes.
- A learniq endpoint action for the whole hand-in: loses portaliq's file field, which only exists
  on `create` and `update` actions.

### D2: A scalar `learnerRef` scope instead of `learnerRefs`
Portaliq's direct scope compares one value (`PortalObjectReader::verifyScope`,
`PortalObjectWriter::fetchOwnedObject`) and its create stamps a string. A new scalar
`Submission.learnerRef` mirrors `GradeEntry.learnerRef`. `learnerRefs` stays for the group case and
is filled on a portal hand-in, so nothing that reads it changes.

### D3: Portal hand-in detection
A create is a portal hand-in when there is no Nextcloud session, `learnerIds` is empty and
`learnerRef` is set. Portaliq writes with no session and never sends `learnerIds` (it is not in the
action's `fields`). A signed-in caller never takes this path, so an app user cannot hand in in
somebody else's name by sending only a `learnerRef`: their create has no `learnerIds` and is refused.

### D4: The tenant comes from the Assignment
`SubmissionWindowGuard` loads the Assignment scoped to `Submission.tenant_id`, so the Submission must
carry the Assignment's tenant or the later hand-in is refused. A profile in another tenant than the
Assignment is refused, which also stops a pupil handing in to another school's assignment by uuid.

### D5: `learnerRef` is derived on every other write (the PR 1020 stamp)
Outside the portal hand-in, `learnerRef` is always the profile of `learnerIds[0]`, preferring a
profile that is not merged away, and a client value is ignored. On an update whose `learnerIds` did
not change, a lookup error keeps the stored value. This keeps an in-app pupil from pointing a
Submission at another pupil's portal list.

### D6: One lookup service shared with `assessment-portal-endpoints`
`LearnerProfileLookup` reads a profile by uuid (`byRef`) and a profile uuid by Nextcloud user id
(`refForUser`). It nests `register` and `schema` under `filters`, the only place
`ObjectService::prepareFindAllConfig()` reads them. `refForUser` repeats what PR 1020's
`LearnerRefResolver::resolve()` does; once 1020 lands the two fold into one.

## API Design
No new route. The contract portaliq reads changes:

```php
[
    'id'           => 'createSubmission',
    'type'         => 'create',
    'register'     => 'learniq',
    'schema'       => 'submission',
    'scopeField'   => 'learnerRef',
    'scopeClaim'   => 'learnerRef',
    'minTrust'     => 'low',
    'fields'       => ['assignmentId', 'attachmentRefs'],
    'fieldConfigs' => [
        'attachmentRefs' => [
            'type'      => 'file',
            'label'     => 'Your work',
            'multiple'  => true,
            'accept'    => ['.pdf', '.doc', '.docx', '.odt', '.pptx', '.jpg', '.png'],
            'maxSizeMb' => 20,
        ],
    ],
],
```

## Database Changes
Schema-only, in `lib/Settings/learniq_register.json`: Submission gains `learnerRef` and its
`required` list shrinks to `assignmentId`. Submission 0.2.0 to 0.3.0, register `info.version` bumped
so OpenRegister re-imports it. No table or migration class; see migration.md.

## Nextcloud Integration
- Controllers: none.
- Services: `OCA\Learniq\Service\Portal\LearnerProfileLookup` (new), OpenRegister `ObjectService`.
- Events/Hooks: `SubmissionOwnerStamp` on OpenRegister `ObjectCreatingEvent` and
  `ObjectUpdatingEvent`, registered in `IntegrityListenerRegistrar`; `OCP\IUserSession` to tell a
  portal write from an app write.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Why |
|---|---|---|
| File field on the hand-in | declarative, manifest `fieldConfigs` | portaliq renders and uploads |
| Scalar scope field | declarative, schema property | a property, no logic |
| Learner and tenant stamp | imperative, pre-write listener | reads LearnerProfile and Assignment, which no default can |
| "Learners and tenant required" | imperative, same listener | OpenRegister validates `required` before the stamp can run (D1) |

## Security Considerations
- The learner is never taken from the client: a portal hand-in takes it from the profile named by
  portaliq's server stamp, every other write from `learnerIds`.
- A profile that is unknown, merged away or deleted refuses the create (fail closed, no orphan row).
- Cross-tenant hand-ins are refused (D4).
- Reads in the listener run without RBAC because portaliq writes without a session; only the fields
  the stamp needs leave the lookup.

## File Structure
```
lib/Listener/SubmissionOwnerStamp.php              new
lib/Service/Portal/LearnerProfileLookup.php        new
lib/AppInfo/Registrar/IntegrityListenerRegistrar.php  registers the stamp
lib/Portal/PortalContributionProvider.php          file field, scalar scope
lib/Settings/learniq_register.json                 Submission.learnerRef, required, versions
lib/Settings/learniq_mock_register.json            learnerRef on the three Submission seeds
l10n/en.json, l10n/nl.json (+ .js)                 the new property description
tests/Unit/Listener/SubmissionOwnerStampTest.php   new
tests/Unit/Service/Portal/LearnerProfileLookupTest.php  new
tests/Unit/Portal/PortalContributionProviderTest.php
docs/user-guide/user/04-assignments.md             a section on the portal hand-in
```

## Seed Data
Submission already has three demo rows. Each gets `learnerRef` equal to its `learnerRefs[0]`, so
the demo set shows what the portal scopes on.

### Schema: `submission`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | submission-submission-1-1 | submission-submission-2-2 | submission-submission-3-3 |
| learnerRef | 00000000-0000-4000-8000-000000000000 | 00000000-0000-4000-8000-000000000001 | 00000000-0000-4000-8000-000000000002 |
| lifecycle | draft | submitted | late |

## Trade-offs
- The required marker disappears from generic staff forms for two fields. Accepted: the server
  refusal names both fields, and the portal path cannot work otherwise (D1).
- A portal hand-in stays a draft until a hand-in action exists. The file and the row land; the
  formal hand-in waits for the receiver pattern from `assessment-portal-endpoints`.
