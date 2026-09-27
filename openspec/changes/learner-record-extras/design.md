# Design: learner-record-extras

## Context

`LearnerProfileDetail` (`src/manifest.d/people.json`) is learniq's most-complete detail page today
(`learniq-round1/learniq-baseline/pupil-card-anatomy.md`: "the ONE detail page in the app observed to show
its own object's fields prominently") but is missing fields every corpus competitor names (address, gezag,
medical/allergy, first aid — findings 2.13/2.1/2.12/1.14, G-new-12, G-new-15), and its guardian linkage
(`guardianRefs`) renders as a dash in the generic `Data` widget's raw field grid rather than through the
already-shipped `related` widget, which resolves `$ref` properties but sits at the bottom of a long
single-column page (layout slot 8 of 12).

`Cohort` (finding 1.14) is the only standing (cross-period) group construct in the schema; `GroupPlanSubgroup`
requires a `groupPlanId` and dies with its plan, so a school cannot model a persistent care or plusklas group
today.

## Goals / Non-Goals

**Goals:**
- Close the concrete field gaps findings 2.13/2.1/2.12/G-new-12/G-new-15 name, on `LearnerProfile`.
- Give a first-aid incident its own auditable record, distinct from a standing medical condition.
- Let a school model a standing care/plusklas group without a `GroupPlan`.
- Reorganise `LearnerProfileDetail` so guardians are visible without scrolling, using the `tabs` widget type
  that `@conduction/nextcloud-vue` already ships and no learniq manifest currently uses.

**Non-Goals:**
- A generic custom-field engine (deferred; see proposal Out of Scope).
- Per-child gezag granularity.
- A photo/avatar mechanism (open question, deferred).
- Any change to how `GroupPlanSubgroup` itself works.

## Decisions

### Decision 1: `hasParentalAuthority` is a flag on the guardian's own `LearnerProfile`, not a per-item field on `guardianRefs`

**Alternatives considered:**
- Change `guardianRefs` from `array<string uuid>` to `array<object {ref, gezag}>`. Rejected: `guardianRefs`
  is consumed elsewhere by the portal's one-hop join (`portal-identity`/`ADR-046 A4` — "the portal resolves
  parent->learner via a one-hop join: it matches the parent subject's guardianRef against this array") and by
  the `related` widget's `$ref`-array resolution (`CnObjectDataWidget.vue`'s `relationProp`/`relatedLabels`
  fetch, which expects a flat array of ids). Changing the item shape from string to object would be a
  breaking change to every existing consumer, which this `config`-kind, additive-only change must not do.
- A flag per guardian-child relationship (a join object). Rejected as over-scoped for this change; no
  concrete customer need for divorced-parents-with-different-gezag-per-child was found in the corpus beyond
  the general ParnasSys pattern, and it would need its own schema (a real relationship object), not a
  property addition.

**Chosen:** `hasParentalAuthority` (nullable boolean) lives on `LearnerProfile` itself, meaningful when that
profile's own `roles` includes `parent` (a guardian is a `LearnerProfile`, confirmed by
`Application.guardianRef`'s `$ref: "LearnerProfile"`). This is additive, breaks nothing, and matches
ParnasSys's per-verzorger (not per-relationship) framing in `documented-column.md` row 2.4.

### Decision 2: Ship a real `FirstAidIncident` schema, not a `LearnerProfile.firstAidRecords` array

**Alternatives considered:** an inline array field on `LearnerProfile`, like `emergencyContacts`. Rejected:
G-new-15 explicitly wants "its own follow-up" (gibbon's `gibbonFirstAid`/`FollowUp` is two linked entities),
and the existing `BehaviourIncident` precedent in this exact register already establishes the
append-only-incident-with-followUpActions-log pattern for duty-of-care events. Reusing that shape (rather
than inventing a third) keeps `FirstAidIncident` consistent with `DossierNote`/`BehaviourIncident`/
`WellbeingCheckIn`, all four now grouped under `pupil-dossier`.

### Decision 3: `Cohort.kind`, not a change to `GroupPlanSubgroup`

**Alternatives considered:** dropping `GroupPlanSubgroup.groupPlanId`'s `required` status so a subgroup can
outlive its plan. Rejected: `GroupPlanSubgroupLearnerContext.vue` and the standard
`originGroupPlanSubgroupId` reverse filter are built around a subgroup living inside exactly one GroupPlan;
loosening that would change behaviour for every existing `GroupPlanSubgroup` consumer, not just add a new
one. `Cohort` is already the standing-group construct (its own lifecycle spans an `academicYear`, independent
of any `GroupPlan`), so a `kind` property there is a strictly additive fit.

### Decision 4: Use the existing `tabs` widget type for the pupil-card layout

**Alternatives considered:** (a) multiple stacked `data`/`related` widgets in the grid (the status quo
pattern used everywhere else in this app); (b) a new custom Vue component. (a) does not solve the brief's
"shows the fields in tabs" requirement and does not fix guardians-buried-at-the-bottom. (b) would be a `code`
change and violate ADR-032's config/code split for this `kind: config` proposal.

**Chosen:** `registerDashboardWidget('tabs', { renderer: CnTabsWidget, ... })` is already registered in
`@conduction/nextcloud-vue` (`src/components/CnWidgetGrid/registerDashboardWidgets.js:218-231`), takes
`content.tabs: [{ widgetId, label?, icon? }]` referencing sibling widget definitions declared on the same
page (`CnTabsWidget.vue`'s `availableWidgets` prop — "every widget definition available on the surface"),
and is a `surfaces: ['detail-page']`, `container: true` type — exactly what a manifest-only pupil-card
redesign needs. No learniq manifest fragment uses `"type": "tabs"` today (grepped: zero hits across
`src/manifest.d/`), so this is the first adopter in this app, not a novel platform feature.

**Layout consequence:** widgets consumed by a tab (`lprof-data`'s successors, `lprof-related`) are declared
in the page's `widgets` array as before but MUST NOT also carry their own `layout` grid entry — `CnTabsWidget`
resolves them by id from the full widget list, not from `layout`, and a widget with both a layout entry and
a tab reference would render twice.

## Declarative-vs-imperative decision (ADR-031)

This change touches one declarative behaviour beyond plain schema properties: `FirstAidIncident` gets an
`x-openregister-lifecycle` (`open → in-handling → resolved`) and an `x-openregister-notifications` entry
(`incidentRecorded`, fires on create to the `mentor`/`coordinator` groups). Both are declared directly in
`lib/Settings/learniq_register.json` on the `FirstAidIncident` schema — no new `lib/Service/*Service.php`
class — mirroring `BehaviourIncident`'s identical declarations line for line. No aggregation, calculation,
or dashboard widget is introduced by this change, so no further declarative-vs-imperative call is needed.

## Seed Data

`FirstAidIncident.x-openregister-seed` ships empty (`[]`), matching every sibling pupil-dossier schema
(`DossierNote`, `BehaviourIncident`, `WellbeingCheckIn` all seed `[]` — these are staff-authored records
about a specific, named minor, and a fabricated seed would be exactly the kind of personal-data-shaped
placeholder `avg-verwerkingsregister`'s own rule already forbids: "No seed entry copies personal-data
values"). The new `LearnerProfile` fields (`address`, `emergencyContacts`, `medicalConditions`, `allergies`,
`medicalConsentDate`, `hasParentalAuthority`) are additive and nullable/defaulted, so no existing
`LearnerProfile.x-openregister-seed` entry needs editing to remain valid; a school-context example (not
committed to the seed, illustrative only) would read:

```json
{
  "address": { "street": "Kerkstraat", "houseNumber": "12", "postalCode": "1234 AB", "city": "Utrecht", "country": "NL" },
  "emergencyContacts": [
    { "name": "J. de Vries", "relationship": "grandparent", "phone": "06-XXXXXXXX", "priority": 1 }
  ],
  "medicalConditions": ["asthma"],
  "allergies": ["peanuts"],
  "medicalConsentDate": "2026-09-01",
  "hasParentalAuthority": true
}
```

`Cohort.kind` needs no seed change either (defaults to `"teaching"`, matching every existing seeded row's
implicit prior behaviour).

## Risks / Trade-offs

- [Risk] The `tabs` widget is new to this app — a manifest typo in `content.tabs[].widgetId` fails soft
  (`CnTabsWidget`'s own doc: "The tab still renders, and its panel says which widget id did not resolve"),
  but only a live render or the manifest validator catches it. → Mitigation: `npm run check:manifest` plus a
  manual verification pass against the built app.
- [Risk] `medicalConsentDate`'s "yearly" cadence has no enforcement in this change (no computed staleness
  field, no reminder job). → Mitigation: explicitly scoped out in the proposal; the field exists so staff can
  see and act on it, not so the platform enforces it — building enforcement without a named consumer would be
  speculative.

## Migration Plan

Not applicable — this change edits declarative OpenRegister schema JSON only (no Nextcloud `lib/Migration/`
class, no SQL). New properties are nullable/defaulted, so every existing `LearnerProfile`/`Cohort` row stays
valid without a backfill. Per the `migration` artifact's own `skipWhen` condition ("no backend or schema
impact" beyond declarative OpenRegister schema definitions), a `migration.md` artifact is skipped for this
change.

## Open Questions

- Pupil photo storage shape (file attachment vs. URL) — deferred, see proposal.
