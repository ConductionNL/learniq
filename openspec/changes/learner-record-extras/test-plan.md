# Test Plan: learner-record-extras

## Coverage Map

| Spec scenario | Test case | Type | Command |
|---|---|---|---|
| avg-verwerkingsregister: Fresh install seeds the register as drafts (extended for address/emergencyContacts/medicalConditions/allergies + FirstAidIncident) | TC1: `testTenActivitiesDeclaredWithCatalogueFields` (renumbered to eleven) asserts `FirstAidIncident` carries the required Art. 30 fields and `LearnerProfile.dataCategories` includes the new categories | Functional (PHPUnit, register-shape) | `vendor/bin/phpunit --filter ProcessingActivityCatalogueTest` |
| avg-verwerkingsregister: Review reminders come from the platform | TC2: `testOwnerReviewFieldsPresentAndNoLearniqNotificationRule` covers `FirstAidIncident`'s owner/review fields and confirms its `incidentRecorded` notification does not mention "review" | Functional (PHPUnit) | `vendor/bin/phpunit --filter ProcessingActivityCatalogueTest` |
| pupil-dossier: A staff member records a first-aid incident | TC3: manual creation of a `FirstAidIncident` via the generic object API against the merged manifest's `FirstAidIncidentDetail` page | Functional (browser, manual pass this iteration) | Playwright MCP manual verification against the local build |
| pupil-dossier: A first-aid incident tracks follow-up to resolution | TC4: schema shape assertion — `x-openregister-lifecycle` transitions `open→in-handling→resolved` declared, `followUpActions` append-only | Functional (JSON schema read, part of TC1's register-shape read) | `python3 -c "import json; ..."` ad hoc during build; no dedicated PHPUnit needed beyond TC1's schema presence check |
| pupil-dossier: Only admin/mentor/coordinator/reportedBy can read | TC5: `x-property-rbac` block present and matches the `DossierNote`/`BehaviourIncident` pattern | Functional (register-shape read, part of TC1) | same as TC1 |
| school-structure: A coordinator creates a standing plusklas group | TC6: `Cohort.properties.kind` enum + default present in the register JSON | Functional (register-shape read) | ad hoc JSON read during build; no dedicated PHPUnit file exists for `Cohort` shape today, so this is verified the same way the property addition itself is verified (parse + assert) |
| school-structure: An existing Cohort without a declared kind defaults to teaching | TC7: default-value assertion on the schema (`default: "teaching"`) | Functional (register-shape read) | same as TC6 |
| design.md Decision 4 (tabbed pupil card) | TC8: manual browser pass — `LearnerProfileDetail` renders four tabs, Guardians resolves without scrolling, no widget renders twice | Functional (browser, manual) | Playwright MCP manual verification |
| Manifest well-formedness | TC9: `npm run check:manifest` | Regression | `npm run check:manifest` |

## Coverage Summary

- All three modified/added capability specs (`avg-verwerkingsregister`, `pupil-dossier`, `school-structure`)
  have at least one mapped test case above.
- Deliberately untested this pass: a dedicated Playwright spec file for the new tabs (TC3/TC8 are manual
  verification passes, not new committed `.spec.ts` files) — the sibling pupil-dossier manifest pattern this
  change extends is already covered by `tests/e2e/spec-coverage/pupil-dossier.spec.ts`, and a `Cohort`-shape
  PHPUnit test does not exist as a precedent to extend (no `CohortSchemaTest` or similar file was found).
  Both are named here rather than silently skipped.
