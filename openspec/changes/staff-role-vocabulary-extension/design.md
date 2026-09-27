# Design: staff-role-vocabulary-extension

## Architecture Overview
`Staff` is a declarative OpenRegister schema in `lib/Settings/learniq_register.json` (no PHP entity; learniq has no `lib/Db/`). The change edits one property of it. The form and index come from `fieldsFromSchema()` in `@conduction/nextcloud-vue`, which already reads `items['x-enum-labels']` and runs each label through the app's `t()` (`src/utils/schema.js`, the `enumLabels` field). So labels in the register plus keys in the catalogue are all the frontend needs.

## Nextcloud Integration
- Controllers: none.
- Services: none.
- Mappers/Entities: none (OpenRegister object storage).
- Events/Hooks: none.

## Decisions

### D1: Append the new values, never reorder
The seven existing values keep their position; the seven new ones follow. A stored array of strings is order-free, but some consumers (a CSV export, a facet list) show enum order, and a stable prefix keeps a diff of the enum readable. Alternative considered: alphabetical order. Rejected, because it moves every existing value.

### D2: English kebab-case codes, Dutch in labels only
Codes follow the register's convention (`teaching-assistant`, `support-staff`). The Dutch job titles live in `l10n/nl.json`: "Decaan of loopbaanbegeleider", "Examensecretaris", "Vertrouwenspersoon". Alternative considered: Dutch codes (`decaan`). Rejected: code and data model are English in this fleet.

### D3: One tag for decaan and loopbaanbegeleider
VO says decaan, MBO says loopbaanbegeleider (LOB), HE says studentendecaan for a different job. `career-counsellor` covers the first two; `study-adviser` covers the HE studieadviseur. Alternative considered: one tag per sector title. Rejected as sprawl with no difference in data.

### D4: The vertrouwenspersoon tag is directory data only
A school has to publish who its vertrouwenspersoon is (schoolgids). The tag records that. Reading confidential notes is decided by membership of the confidential group that `confidential-counsellor-channel` adds, never by this tag, and the description says so.

### Declarative-vs-imperative decision
| Behaviour | Path | Rationale |
|---|---|---|
| New enum values and labels | Declarative, register patch | Plain schema vocabulary; no lifecycle, aggregation or notification involved. |

No new service, listener or guard.

## Security Considerations
No access change. The property description states a tag grants no access; a unit test asserts that no `authorization` block names a tag-only value, so a future edit cannot quietly turn a tag into a gate.

## File Structure
```
lib/Settings/learniq_register.json       Staff.roles items: enum +7, x-enum-labels; seed row; versions
lib/Settings/learniq_mock_register.json  one demo row uses new tags
l10n/en.json, l10n/nl.json (+ .js)       14 label keys
tests/Unit/Settings/StaffRoleVocabularyRegisterTest.php   new
tests/Unit/Settings/SubjectAndTeacherAssignmentRegisterTest.php   floor instead of exact enum
```

## Seed Data

### Schema: `staff`
| Field | Object 1 (new) |
|-------|----------|
| id | `00000000-0000-0000-0000-0000000e0003` |
| ncUserId | `staff-decaan-01` |
| roles | `teacher`, `career-counsellor`, `exam-secretary` |
| qualifications | `tweedegraads bevoegdheid economie`, `LOB-coördinator` |
| workingDays | monday, tuesday, thursday |
| tenant_id | `00000000-0000-0000-0000-000000000001` |

The two existing seed rows (`staff-mentor-01`, `staff-duo-01`) stay as they are. In the demo register, the first `staff` row gains `roles: ["remedial-teacher", "care-coordinator"]` so a fresh install shows a labelled new tag.

## Trade-offs
A tag that names the vertrouwenspersoon could be read as a permission. The alternative, leaving it out, would push schools to type the function into `qualifications` or pick `other`, which is worse for the directory and no safer. The description and the test carry the "no access" rule instead.
