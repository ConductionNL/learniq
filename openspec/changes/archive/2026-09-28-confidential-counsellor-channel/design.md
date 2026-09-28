# Design: confidential-counsellor-channel

## Architecture Overview
Three layers carry a role in learniq, and a new scope has to land in all three or it silently hides (the failure `fix-dead-role-gates` repaired):

1. **Declared groups**: `components.securitySchemes.oauth2.flows.authorizationCode.scopes` in `lib/Settings/learniq_register.json`. OpenRegister's group provisioner creates a Nextcloud group per scope on import.
2. **Role resolver**: `lib/Service/DashboardRoleService.php`, whose `GROUP_BACKED_ROLES` feeds `primaryRole` (initial state, `PageController::index()`) and is parsed by `tests/validate-menu-role-gates.js`.
3. **Manifest**: menu `visibleIf` predicates evaluated by `@conduction/nextcloud-vue` against `runtime.user` (`src/main.js`).

The data itself is a new OpenRegister schema, `ConfidentialNote`, whose `authorization` block OpenRegister enforces in SQL (`MagicRbacHandler::processConditionalRule()`: a rule applies when the user qualifies for its `group`, and then its `match` must hold on the same row).

## Nextcloud Integration
- Controllers: `PageController::index()` provides one more initial-state value, `confidentialCounsellor` (bool).
- Services: `DashboardRoleService` gains the role and `isConfidentialCounsellor(IUser)` (`IGroupManager::isInGroup`).
- Mappers/Entities: none (OpenRegister object storage).
- Events/Hooks: none.

## Decisions

### D1: A new schema, not a DossierNote tier
`DossierNote` is read by `instructors`, `compliance-officers` and its care team by design, and it is linked to the learner. A confidential tier on it would need a row-conditional read OpenRegister cannot express per value. A separate schema with its own block is enforceable today. Alternative considered: `DossierNote.confidentiality = vertrouwelijk`. Rejected for that reason, and because the recon found the existing tiers presentational.

### D2: Read = author in the group, or a named participant
- `{"group": "confidential-counsellors", "match": {"authorId": "$userId"}}`: the author reads while they hold the function. Someone who stops being the vertrouwenspersoon loses access to the files, which matches practice (the file belongs to the function, handed over explicitly).
- `{"group": "authenticated", "match": {"participantIds": {"$contains": "$userId"}}}`: the people the author names (a co-counsellor, the person who reported) read. Same `$contains` shape as `DossierNote.careTeamUserIds` and `Submission.learnerIds`.
No group string appears in read, update or delete. Create is the bare group: only a vertrouwenspersoon writes a note.

### D3: The role ranks below staff roles
`primaryRole` is one value and the manifest grammar has no OR. Ranking `confidential-counsellor` above `instructor` would take the teacher menus away from a teacher who is also the vertrouwenspersoon; ranking it below keeps them. So it sits after `instructor` and before `guardian`: it only wins for a user with no staff group, typically an external vertrouwenspersoon, who then resolves to `confidential-counsellor` rather than `learner`. Dashboard views: student only, like `guardian`.

### D4: The menu gates on a boolean, not on primaryRole
Because of D3, a teacher-vertrouwenspersoon has `primaryRole = instructor`. The menu therefore gates on `user.isConfidentialCounsellor`, filled from group membership, the same way the dashboard entries gate on `canTeachDashboard`. Admins do not get the flag: the menu is for the function, not for IT.

### D5: Structural isolation
No `$ref` in or out, `searchable: false`, `hardDelete: true`, no `appendOnly` (the author must be able to destroy the file). No link to a learner: a vertrouwenspersoon's case is often about staff or a parent, and a learner link would show up in that learner's related panel query.

### Mixed-spec rationale
The register change is the substance. The code part is glue: one constant entry and one method in `DashboardRoleService`, one initial-state line in `PageController`, one runtime flag in `src/main.js`. Splitting would ship a scope whose menu cannot show, the exact silent-hide failure the three-layer rule exists to prevent.

### Declarative-vs-imperative decision
| Behaviour | Path | Rationale |
|---|---|---|
| Who reads, writes, deletes a note | Declarative, `authorization` block | Enforced by OpenRegister in SQL. |
| Open or closed | Declarative, `status` enum | No transition guard or side effect, so no lifecycle. |
| Destroy-after reminder | Declarative, date field | The author deletes; no scheduled job. |
| Menu visibility | Imperative glue (initial state) | The manifest grammar cannot express "member of group X" without a runtime value. |

## Security Considerations
- Enforcement is server-side in OpenRegister; the menu flag is presentation only.
- **Admin bypass**: OpenRegister returns every row to the Nextcloud `admin` group. A school must keep `admin` to IT staff who are not school leaders. Stated in the schema description and the docs.
- **Owner bypass**: OpenRegister also lets an object's owner (its creator) through; the creator is the vertrouwenspersoon who wrote it, so this adds no reader.
- **Audit trail**: OpenRegister stores the old object in its audit trail on update and delete; the only switch is the instance-wide `auditTrailsEnabled` retention setting. Follow-up for OpenRegister: a per-schema audit opt-out.
- Portal: no portal collection reads this schema.

## NL Design System
Standard manifest index and detail pages (`CnIndexPage`, `CnDetailPage` through the shared shell). No custom component, no colours.

## File Structure
```
lib/Settings/learniq_register.json            scope, ConfidentialNote schema + seed, info.version
lib/Settings/learniq_mock_register.json       demo rows
lib/Service/DashboardRoleService.php          role + isConfidentialCounsellor()
lib/Controller/PageController.php             initial state confidentialCounsellor
src/main.js                                   runtime.user.isConfidentialCounsellor
src/manifest.d/confidential-counsel.json      menu entry, index + detail pages
l10n/en.json, l10n/nl.json (+ .js)            labels
tests/Unit/Settings/ConfidentialCounsellorChannelRegisterTest.php   new
tests/Unit/Service/DashboardRoleServiceTest.php                    extended
docs/user-guide/admin/03-admin-settings.md                         group note
```

## Seed Data

### Schema: `confidential-note`
| Field | Object 1 |
|-------|----------|
| id | `00000000-0000-0000-0000-0000000f0001` |
| title | `Voorbeeld: gesprek na een melding over omgangsvormen` |
| note | `Voorbeeldnotitie. Eerste gesprek gevoerd, vervolgafspraak over twee weken.` |
| authorId | `staff-vp-01` |
| participantIds | (empty) |
| status | `open` |
| destroyAfter | `2027-07-31` |
| tenant_id | `00000000-0000-0000-0000-000000000001` |

The demo register gets three rows in the generator's shape. The seed author `staff-vp-01` is a demo account in no group, so the seed row is readable by nobody but an admin until a school replaces it.

## Trade-offs
Participants read everything in the note. A finer split (some paragraphs for some readers) would need property-level rules; a vertrouwenspersoon who needs that writes two notes.
