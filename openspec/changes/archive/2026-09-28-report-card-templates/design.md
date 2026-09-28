# Design: report-card-templates

## Architecture Overview
`ReportCardTemplate` is a new, standalone OpenRegister schema, the same shape as the existing
`CourseTemplate` template pattern: a named object with a `lifecycle` (`draft` → `active` →
`archived`, with `reactivate` back to `active`), no relations required to exist before it. `Cohort` gains one nullable `$ref` property
(`reportCardTemplateId`) so a group's assigned template is discoverable without a join table.
`ReportCard` gains one nullable `$ref` property (`templateId`), stamped by `ReportCardComposer` at
compose time from the learner's cohort. Two existing PHP classes change behaviour, and one new,
narrow service is extracted (discovered during implementation, when adding the gating logic
directly to `ReportCardComposer` pushed its `phpmd` `ExcessiveClassComplexity` metric over this
repo's threshold of 50):

- `ReportCardComposer` (`lib/Listener/ReportCardComposer.php`) reads `Cohort.reportCardTemplateId`
  and calls the new resolver below to decide what to populate, falling back to the current fixed
  shape when unset.
- `ReportCardTemplateSectionResolver` (`lib/Service/ReportCardTemplateSectionResolver.php`, new)
  resolves a `ReportCardTemplate.sections[].kind` list and gates section population by it. Extracted
  from `ReportCardComposer` the same way `AttendanceWindowAggregator` already carries the attendance
  half of composition — one cohesive responsibility per class, constructor-injected.
- `ReportCardPdfDelegationService` (`lib/Service/ReportCardPdfDelegationService.php`) resolves
  `ReportCard.templateId` → `ReportCardTemplate.slug` for the outbound `templateSlug` field, falling
  back to the existing `'report-card'` literal when unset.

```
Cohort.reportCardTemplateId ──┐
                               ▼
                    ReportCardTemplate (sections[], testKindSectionMap[], scale per section)
                               │
              ReportCardComposer reads sections[] at ReportPeriod.compose
                               │
                               ▼
                 ReportCard.templateId (stamped)
                               │
              ReportCardPdfDelegationService reads .slug at renderToPdf
                               │
                               ▼
                    docudesk payload.templateSlug
```

## API Design
No new HTTP endpoint. `ReportCardTemplate` CRUD is served by OpenRegister's generic object API
(`/apps/openregister/api/objects/learniq/report-card-template`), the same as every other schema in
this register — no PHP controller is added, per the report-card spec's existing "no PHP CRUD
controllers" requirement.

## Database Changes
See `migration.md` — this is an OpenRegister schema addition (JSON register patch), not a Doctrine
migration; OpenRegister's own schema-apply step handles object-table provisioning for the new
schema, and adding two nullable `$ref` properties to existing schemas (`Cohort`, `ReportCard`) is
non-destructive.

## Nextcloud Integration
- Controllers: none added.
- Services: `ReportCardPdfDelegationService` (modified, not new) — adds a template-slug resolution
  step before its existing `callDocudeskRender()` call, via the existing constructor-injected
  `IClientService`/`IURLGenerator`/`IAppConfig` seam plus `OCA\OpenRegister\Service\ObjectService`
  (already used elsewhere in this codebase, e.g. `CompetencyAttainmentWriter`, to resolve a `$ref`
  UUID to its object) to look up the `ReportCardTemplate` by `templateId`.
- Mappers/Entities: none — OpenRegister owns storage.
- Events/Hooks: `ReportCardComposer` (modified, not new) — already listens for
  `ReportPeriod.compose`/`ReportCard.recompose`; gains a `Cohort`/`ReportCardTemplate` lookup step
  before writing each `ReportCard`.

## Security Considerations
No new attack surface: `ReportCardTemplate` uses the register's existing `x-property-rbac`
mechanism (mirrors `CourseTemplateDetail`); no PHP controller means no new auth boundary. The
`templateSlug` sent to docudesk is drawn from a school-authored template's own `slug` field, the
same trust boundary as today's hardcoded `'report-card'` literal — no user-supplied input reaches
that field directly (a template's `slug` is set by whoever the register's role rules already permit
to create/edit templates, the same role that can edit `CourseTemplateDetail` today).

## NL Design System
`ReportCardTemplateDetail`/`ReportCardTemplateIndex` are declarative `src/manifest.json` pages
(index + detail), the same generic NcSelect/NcTextField form components every other manifest page
already uses — no bespoke Vue component, matching the report-card spec's "frontend is declarative"
requirement. `sections[]` and `testKindSectionMap[]` render as the manifest form's existing
array-of-objects widget (already used elsewhere, e.g. `CurriculumPlan.components[]`).

## File Structure
```
lib/
  Settings/
    learniq_register.json        (MODIFIED — new ReportCardTemplate schema; Cohort.reportCardTemplateId; ReportCard.templateId)
    learniq_mock_register.json   (MODIFIED — seed ReportCardTemplate objects; Cohort/ReportCard seed rows gain the new fields)
  Listener/
    ReportCardComposer.php       (MODIFIED — template-driven section population)
    ReportCardTemplateSectionResolver.php (NEW — extracted section-gating logic, see Architecture Overview)
  Service/
    ReportCardPdfDelegationService.php (MODIFIED — template-slug resolution)
src/
  manifest.json                  (MODIFIED — ReportCardTemplate index+detail pages)
tests/
  Unit/
    Listener/ReportCardComposerTest.php                     (MODIFIED — template + fallback scenarios)
    Service/ReportCardPdfDelegationServiceTest.php          (MODIFIED — templateSlug scenarios)
    Service/ReportCardTemplateSectionResolverTest.php       (NEW — direct unit coverage of the extracted resolver)
    Settings/ReportCardTemplateRegisterTest.php             (NEW — schema shape assertions)
```

## Declarative-vs-imperative decision (ADR-031)
| Behaviour | Path chosen | Rationale |
|---|---|---|
| `ReportCardTemplate.lifecycle` (`active`/`archived`) | Declarative (`x-openregister-lifecycle`) | Mirrors `CourseTemplateDetail`'s existing lifecycle exactly; no new guard logic needed. |
| Section-kind/scale enum validation | Declarative (JSON Schema `enum`) | Plain schema validation, no custom code. |
| Template-driven section population at compose time | **Imperative** (modifies existing `ReportCardComposer` Listener) — exception per ADR-031 "lifecycle guard/cross-object write bridge already established": `ReportCardComposer` is already the ADR-031-exempted event-driven bridge for this exact object graph (`ReportPeriod`→`ReportCard`, spanning `Cohort`/`CurriculumPlan`/`FinalGrade`/`AttendanceRecord`); a declarative aggregation cannot conditionally shape which fields of a *newly created* object are populated based on a second object's array contents. | Extending the existing exempted class is narrower than inventing a new declarative primitive that OpenRegister does not offer (a "shape-of-object-to-create depends on a referenced object's array" declaration does not exist in `x-openregister-*`). |
| `templateSlug` resolution before the docudesk POST | **Imperative** (modifies existing `ReportCardPdfDelegationService`) — same ADR-031 external-system-bridge exception this service already operates under. | The service already performs an imperative external POST; resolving one more field via `ObjectService` before building that payload is the same class of work, not new architectural surface. |

## Seed Data
### Schema: `report-card-template`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug (`@self.slug`) | `reportcardtemplate-basisschool-huisstijl-1` | `reportcardtemplate-vo-cijferlijst-2` | `reportcardtemplate-kleuterrapport-3` |
| name | "Basisschool huisstijl rapport" | "VO cijferlijst" | "Kleuterrapport groep 1-2" |
| tenant_id | `00000000-0000-4000-8000-000000000000` | `00000000-0000-4000-8000-000000000001` | `00000000-0000-4000-8000-000000000002` |
| sections[] | grades(scale:steps,order:1), narrative(order:2), pupil-voice(order:3) | grades(scale:grades-1-10,order:1), attendance(order:2) | social-emotional(scale:smileys,order:1), narrative(order:2), portfolio(order:3) |
| testKindSectionMap[] | `[{testKind:"cito-rekenen-groep-6",sectionKind:"lvs-results"}]` | `[]` | `[]` |
| lifecycle | active | active | archived |

**Related items per object:** none (no Files/Notes/Tasks/Contacts relations apply to a template
record).

Seed also updates:
- One existing `Cohort` mock row gains `reportCardTemplateId` pointing at
  `reportcardtemplate-basisschool-huisstijl-1`.
- One existing `ReportCard` mock row gains `templateId` matching that same template, to demonstrate
  the templated composition path in the seeded environment; the remaining `ReportCard` mock rows
  keep `templateId: null` to demonstrate the fallback path.

## Trade-offs
Considered a separate `GroupTemplateAssignment` join schema (period-specific template assignment)
instead of a single `Cohort.reportCardTemplateId` property. Rejected for this round: no corpus
evidence (ParnasSys, Easyrapport, MijnRapportfolio) shows a school changing a group's template
mid-year period-by-period — the per-leerjaar/per-group setting is described as a standing
configuration, not a per-period one. A `Cohort`-level property is one rung cheaper (rung 1, not
rung 4) and matches the evidence; revisit as a join schema only if a future round finds a
per-period override requirement.

## Open Questions
None outstanding — see proposal.md's Open Questions section.
