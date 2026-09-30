# Design: support-confidential-concern-report

## Where it fits (development at 21c17a01)

- Counsellor side: `ConfidentialNote` and the scope `confidential-counsellors` in `lib/Settings/learniq_register.json`; the menu in `src/manifest.d/confidential-counsel.json`, gated on `user.isConfidentialCounsellor` (`src/main.js`, from `PageController::index()` and `DashboardRoleService::isConfidentialCounsellor()`).
- Server-side owner stamping: the shape of `lib/Listener/ExcuseRequestOwnerStamp.php` and `SubmissionOwnerStamp.php`: a listener on `ObjectCreatingEvent` and `ObjectUpdatingEvent` that resolves the schema through `ListenerSchemaResolver::guardSchemaSlug()`, merges into `setModifiedData()`, and refuses with `setErrors()` plus `stopPropagation()`. An update reads the new state with `getNewObject()` and the stored one with `getOldObject()`.
- Tenant: the reporter's `LearnerProfile` through `LearnerRefResolver`, as `SubmissionOwnerStamp` does.

## Decisions

### D1: A new schema, not a ConfidentialNote written by a learner
`ConfidentialNote.create` is the counsellor group only, and its read rule is author plus named participants. A learner report is read by every counsellor (the learner does not know who is on duty) and by the reporter. Different readers, different schema. `ConcernReport` has no `$ref` in or out, `searchable: false`, `hardDelete: true`, like `ConfidentialNote`.

### D2: Authorization
- read: `confidential-counsellors` (bare group: any counsellor), and `{"group": "authenticated", "match": {"reporterId": "$userId"}}`.
- create: `authenticated`.
- update and delete: `confidential-counsellors`.
No other group appears. OpenRegister's owner bypass lets the creator through, which is the reporter: no new reader.

### D3: The reporter is stamped, never taken from the client
`ConcernReportReporterStamp` on create sets `reporterId` to the session user, `status` to `received`, and `tenant_id` to the reporter's profile tenant when there is one; with no session it refuses (`concern-no-session`). On update it restores `reporterId` from `getOldObject()`, so a counsellor or a crafted request cannot move a report to another person. `reporterId` is therefore not in `required` (OpenRegister checks `required` before listeners run); the listener enforces it. Without this stamp, a learner could set `reporterId` to a classmate, who would then be able to read the report.

### D4: The notification names no one
`x-openregister-notifications.reportReceived`: trigger `created`, channel `nc-notification`, recipients `{"kind": "groups", "groups": ["confidential-counsellors"]}`, subject without placeholders ("A new confidential report has arrived" / "Er is een nieuwe vertrouwelijke melding binnengekomen"). The subject never carries the reporter, the topic or the text.

### D5: Menu
- Learner: "Report a concern" (index of `concern-report`, which the read rule narrows to the learner's own rows, plus the create form). Visible to every signed-in user: staff can be harassed too.
- Counsellor: "Concern reports", gated on `user.isConfidentialCounsellor` like "Confidential notes"; index and detail, detail with data and history only.

### Declarative versus imperative
| Behaviour | Path | Why |
|---|---|---|
| Who reads, writes, deletes | `authorization` block | Enforced by OpenRegister in SQL. |
| Reporter and tenant | Listener | A client value cannot be trusted; `required` runs before listeners. |
| Counsellor notice | `x-openregister-notifications` | ADR-031 dialect. |
| Status | enum, no lifecycle | No guard or side effect. |

## Fields

| field | type | notes |
|---|---|---|
| topic | enum `bullying`, `harassment`, `discrimination`, `violence`, `other` | required |
| description | string | required, what happened |
| happenedOn | date, nullable | |
| wantsConversation | boolean, default true | |
| status | enum `received`, `in-progress`, `closed`, default `received` | the counsellor sets it; the reporter sees it |
| reporterId | string | stamped |
| tenant_id | uuid, nullable | stamped when known |

## Seed data
No seed row: a sample report in a live school would look like a real one. The demo register (`learniq_mock_register.json`) gets two rows for demo tenants.

## Security
Admin bypass and the audit trail as in the counsellor channel design. The detail page for the reporter shows the history tab too; it shows only the counsellor's status changes, which the reporter may see.
