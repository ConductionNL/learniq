# Design: rbac-declare-groups

## Context

`lib/Settings/scholiq_register.json` is a 1.1 MB OpenAPI-shaped configuration document with 118 schemas, imported into OpenRegister as one Register + 118 Schema entities. OpenRegister enforces RBAC at three levels — register, schema, property — by reading a bare `authorization` JSON key on each entity (`Schema::$authorization`, type `json`, validated by `Schema::validateAuthorizationRules()`). That key is absent everywhere in this file today.

**What "absent" actually means, at the code that enforces it** (not inferred from spec prose): `PermissionHandler::hasGroupPermission()` (openregister `lib/Service/Object/PermissionHandler.php`, ~line 1292) branches on `empty($authorization) === true` into a path whose own comment reads *"Default-OPEN behaviour preserved."* There is a partial operator mitigation — `IAppConfig::getValueBool('openregister', 'enforce_default_closed')`, default `false` — but it only closes `DEFAULT_CLOSED_WRITE_ACTIONS = ['create', 'update', 'delete']`. The docblock is explicit that `read` was deliberately excluded: *"Reads remain default-open since `@PublicPage` is the OR-wide read model."* Measured live on localhost:8080, the flag is `UNSET` (i.e. off), so today writes are open too — but even in the hypothetical best case where an operator flips that flag, **every read on every one of the 118 schemas stays open to any authenticated user.** There is no configuration flag that closes it. Declaring an `authorization` block is the only mechanism that does.

OpenRegister already treats the undeclared state as a known defect class: the same branch fires a one-shot-per-action `logger->warning()` — *"DEPRECATION: schema without an authorization block grants {action} to any authenticated user..."* — the first time an undeclared schema/action pair is exercised. That warning has been in scholiq's Nextcloud log, unread, since the relevant schemas shipped.

**A second, independent trap**: 20 of the 118 schemas (`SovereigntyPolicy`, `XapiStatement`, `Application`, `Room`, `ExamAccommodation`, `PeerReview`, `ProctoringSession`, `ReportPeriod`, `ReportCard`, `SupportRequest`, `TlvApplication`, `DeliberationRecord`, `DossierNote`, `BehaviourIncident`, `EngagementRiskThreshold`, `EngagementRiskFlag`, `BsaWarning`, `BsaDecision`, `AccessibilityStatement`, `AccessibilityLimitation`) already carry an `x-openregister-authorization` key with content that *reads* like a real access-control rule (e.g. `DossierNote`'s `create: ["admin","mentor","coordinator"]`). Grepping OpenRegister's `lib/` confirms `x-openregister-authorization` is read nowhere — not by `Schema`, not by `PermissionHandler`, not by `OasService`, not by `RbacGroupCollector`. It is decoration. A reviewer skimming the JSON, or a future engineer relying on `DossierNote`'s own description text ("Server-side x-property-rbac restricts every row..."), would reasonably conclude DossierNote already has row-level protection. It does not — `x-property-rbac` (40 schemas carry it) is equally unread by OpenRegister; it exists only as a convention referenced in scholiq's own PHP comments (`lib/Lifecycle/PortfolioShareGrantHandler.php` etc.), not as an enforcement mechanism. Both keys are AN EXPRESSION OF A PATTERN, not the pattern: they match the shape of a working authorization block closely enough to pass a glance, while doing nothing.

**Positive fact reducing rollout risk**: unlike the risky example in `openregister/openspec/specs/rbac-scopes/spec.md`'s "Provisioning MUST NOT depend on a leaf app's repair-step wiring" requirement (an app that imports its register only under `<post-migration>` never imports on a fresh install, because Nextcloud's first-install path runs only `<install>`), scholiq's `appinfo/info.xml` already declares `OCA\Scholiq\Repair\InitializeSettings` under **both** `<install>` and `<post-migration>`. A fresh scholiq install and an existing-install upgrade both re-import the register and therefore both trigger `GroupProvisioner`. OpenRegister's own background reconciliation sweep (`DeclaredGroupInventoryService` + its `GroupReconciler`) is defense-in-depth for a group an administrator later deletes by hand, not a gap this change needs to work around.

## Goals / Non-Goals

**Goals:**
- Every one of the 118 schemas has an effective, OpenRegister-enforced `authorization` posture after the next import — not merely a JSON key that looks like one.
- The canonical eight group ids (`instructors`, `hr`, `compliance-officers`, `team-leads`, `learners`, `coordinators`, `guardians`, `administration-managers`) exist as real Nextcloud groups after the next import, observably (`GET /cloud/groups`) — four because this change's authorization blocks reference them, four (`learners`, `coordinators`, `guardians`, `administration-managers`) pre-provisioned via the scope map alone so the sibling `fix-dead-role-gates` change and later object-level-scoping work never hit the "group was never created" failure mode this change exists to close.
- The two decoy keys (`x-openregister-authorization` on 20 schemas) no longer coexist with a real `authorization` block in a way that could confuse a future reader about which one is live.
- No blanket group grant admits a learner to another learner's (or employee's) records — see Residual Exposure below.
- **A learner can still see the course catalogue.** This was missed in the first draft of this design (see Decision 4/C5) and is now a first-class goal, not an afterthought: closing lateral disclosure between learners must not mean the learner-facing product renders empty.

**Non-Goals:**
- Populating group membership. Provisioning is create-only by OpenRegister's design; a human administrator adds members after this change ships. Zero-member groups deny everyone — that is correct, not a bug to route around here.
- Assigning any permission to `coordinators`, `guardians`, or `administration-managers`. This change provisions their groups (Goals, above) but writes no authorization rule that references them — that is the `fix-dead-role-gates` change's job.
- Reconciling `DashboardRoleService`'s `scholiq-{role}` naming with the group ids this change provisions. Separate change (`fix-dead-role-gates`, whose vocabulary this change's group ids were coordinated against — see Decision 2).
- Building object-level (relation-scoped) conditional authorization so a learner can read their own grades/progress without a blanket group grant. See Residual Exposure — this is the follow-up that closes the gap this change deliberately leaves open. (This is narrower than the first draft's non-goal: catalogue reads are IN scope now, per Decision 4/C5 — only per-learner scoping of learner-ATTRIBUTED records is deferred.)
- Fixing `RbacGroupCollector`'s reserved-principal list so `authenticated` stops being provisioned as a real (if inert) Nextcloud group. That is OpenRegister's code, not this app's config — see Decision 7/C6. This change only verifies and documents the consequence.
- Removing or fixing `x-property-rbac` (the 40-schema decorative key referenced by scholiq's own Lifecycle/Listener/Controller/Service PHP files). Those references are prose/comments about a documented, named OpenRegister platform-capability gap (row-conditional property RBAC) — not code this change is allowed to touch (config-only), and not the same defect as `x-openregister-authorization` (which is dead weight with no PHP referring to it at all).
- Hand-authoring bespoke `authorization` blocks for all 118 schemas. 76 Tier-2 (learner-attributed) schemas inherit the register cascade.

## Decisions

### Decision 1: Three tiers, not 118 bespoke policies
Hand-authoring a distinct authorization block per schema is not a config change reviewers could meaningfully review, and most of the 118 schemas (timetables, sessions, room bookings) carry no sensitivity distinct from "staff can write it, staff can read it." The register-level cascade absorbs that shared case (Tier 2, 76 schemas — see Decision 4 for why this dropped from an earlier 84 once catalogue schemas were carved out). Only schemas that need something *different* from the cascade get their own block — the 21 sensitive-data schemas (Tier 1, narrower than the cascade) and the 21 catalogue/definitional schemas (Tier 3, split across two write profiles — Decision 4).

**Alternative considered**: authoring `authorization` on all 118 schemas individually, for uniformity. Rejected — it triples the diff size for schemas whose correct policy is identical to the register default, and a 118-schema table is not reviewable; a human reviewer would rubber-stamp it, defeating the point of declaring RBAC explicitly instead of leaving it implicit.

### Decision 2: Group ids are free-form, unprefixed, and sector-neutral — the canonical eight, not `scholiq-*`
`RbacGroupCollector`'s own docblock states the design intent directly: *"Group ids are free-form and deliberately UNPREFIXED — two apps that both declare `behandelaars` converge on one Nextcloud group by design, so a declaring app must never assume it owns a group it declared."* Continuing the app's existing (broken, never-provisioned) `scholiq-{role}` convention would perpetuate exactly the isolation `RbacGroupCollector` is designed to avoid, for no benefit — nothing else on this Nextcloud instance currently claims `instructors` or `hr`, and if something later does, that convergence is the documented, intended behaviour, not a collision to guard against.

Scholiq itself is being reframed from a school-only product to Learniq — serving both schools and companies — while this change was in flight. That reframing has a direct consequence for group-id naming: an identifier like `teacher` or `principal` is a school word, and choosing it now would mean renaming it again the moment the corporate profile ships. The fix is to make the IDENTIFIER sector-neutral and push the sector-specific word down to a presentation-layer LABEL, resolved per deployment profile. This change adopts the full eight-id vocabulary (coordinated with the sibling `fix-dead-role-gates` change, which resolves these ids to a DashboardRoleService-style "current user's role" and needs the same eight so the two changes converge rather than reproducing today's mismatch under new names):

| role (resolver emits) | group (declared + provisioned) | education label | corporate label |
|---|---|---|---|
| `learner` | `learners` | Pupil / Student | Employee |
| `instructor` | `instructors` | Teacher | Trainer |
| `team-lead` | `team-leads` | Mentor | Team lead |
| `coordinator` | `coordinators` | Year coordinator | L&D coordinator |
| `hr` | `hr` | Staff office | HR |
| `compliance-officer` | `compliance-officers` | Compliance officer | Compliance officer |
| `guardian` | `guardians` | Parent / guardian | (off in corporate) |
| `administration-manager` | `administration-managers` | Principal | L&D manager |

`hr` is the one accepted irregular form (singular, not `hrs`) — it is an initialism, and it must match the group id the shared `hrmq`/`shillinq` apps already use, so a school and a company (and hrmq/shillinq) all converge on one Nextcloud group named `hr`, per the same free-form-convergence design `RbacGroupCollector` documents. Every other group id is plural-kebab; every resolved role is singular-kebab. Rejected as identifiers: `teacher`, `principal`, `mentor`, `parent` — school-specific, would require a second rename once the corporate profile ships. `finance` is dropped entirely — Payments leaves the app per ADR-081, so there is no schema left that would need a finance-scoped group.

This change's own authorization content (register cascade, Tier-1, Tier-3) uses only four of the eight — `instructors`, `hr`, `compliance-officers`, `team-leads` — because those are the only ones an existing schema profile needs today. The other four (`learners`, `coordinators`, `guardians`, `administration-managers`) are declared via the scope map only (Decision 5) so they are provisioned ahead of the sibling change and ahead of the object-level-scoping work in Residual Exposure, rather than each needing its own group-declaring change later.

**Alternative considered**: keep `scholiq-{role}` to align with `DashboardRoleService`'s existing (dead) convention. Rejected — that convention has never worked (the groups were never created under either name), so there is no working behaviour to preserve, and adopting it would require this change to *also* touch `DashboardRoleService.php`, which is explicitly out of scope (config-only, PHP changes belong to `fix-dead-role-gates`).

**Alternative considered**: declare only the four ids this change's own authorization blocks use, leave `coordinators`/`guardians`/`administration-managers` for `fix-dead-role-gates` to declare itself. Rejected — that reproduces the exact defect this change exists to fix, one change later: `fix-dead-role-gates` would gate on group ids that don't exist yet if its own artifacts land before its provisioning does, or duplicate this change's JSON-editing work to add them. Declaring all eight now, using the scope-map-only path, costs four lines and removes an entire class of "which change provisions this group" coordination risk.

### Decision 3: Delete is admin-only by omission, everywhere
No profile (register cascade, Tier-1, or Tier-3) declares a `delete` key. Once a schema's `authorization` block is non-empty, `PermissionHandler::hasGroupPermission()`'s fail-closed rule denies any action not explicitly listed (*"an action that is not explicitly listed is denied... Only the empty-block default above still grants by default"*) — except for the admin bypass and the object-owner bypass, both of which are evaluated before that rule and are unaffected by it. So omitting `delete` is equivalent to `"delete": ["admin"]` in effect, without adding a redundant literal `admin` string into the JSON (`admin` is a reserved principal anyway — `RbacGroupCollector::provisionable()` strips it even if written).

**Alternative considered**: write `"delete": ["admin"]` explicitly on every block, for readability. Rejected as noise once the fail-closed rule is understood — but this design doc exists partly so that understanding travels with the change; a future schema-author extending this pattern should know omission is deliberate, not an oversight.

### Decision 4: Catalogue/definitional data is a THIRD population — not folded into the staff-only cascade (C5 correction)
**This decision exists because an earlier draft of this design was wrong, and the mistake is worth preserving in writing so it isn't repeated.** That draft applied C1's "no blanket `learners` grant" rule to ALL of Tier 2 — every schema without its own block, no exceptions. `Course` is Tier 2. So was `Lesson`, `Material`, `Programme`, `CurriculumPlan`. Applying the staff-only cascade to those meant a learner opening "My learning" got a `Course` list of zero, because the register cascade (Decision — REQ-001) grants read only to `instructors`/`hr`/`compliance-officers`/`team-leads`. The object-owner bypass does not rescue this either: an instructor authors a `Course`, so its `objectOwner` is the instructor, exactly as with `GradeEntry` in Residual Exposure below — but the conclusion for `Course` is the opposite of the conclusion for `GradeEntry`. A `GradeEntry` is personal data about one learner; a `Course` is not personal data about anyone, it is what's on offer. Applying one rule to both was the error.

The fix is a third population, sitting alongside the two REQ-001 already distinguishes (Tier-1 sensitive, Tier-2 learner-attributed): **Tier 3, catalogue/definitional data** — `Course`, `Lesson`, `Material`, `Programme`, `CurriculumPlan`, `Assignment`, `Assessment`, `ItemBank` (8 schemas, newly identified) plus the pre-existing 13 shared-configuration schemas (`GradeScale`, `ReportPeriod`, `CompetencyFramework`, `PointRule`, `EngagementLevel`, `Leaderboard`, `CourseTemplate`, `PortfolioTemplate`, `LearningPlanTemplate`, `Regulation`, `ExchangeErrorCode`, `Room`, `FeeItem`) — 21 total. Every Tier-3 schema declares `read: ["authenticated"]`: every authenticated user, `learners` included, **deliberately wider than the Tier-2 cascade**, which grants `learners` nothing at all. These are not "matching" breadths that happen to look similar — they are two different answers for two different populations, and a reader must not assume Tier 3's read grant says anything about what Tier 2 grants, or vice versa.

Because a schema-level block, once present, replaces the register default *entirely* (no merging), Tier-3 blocks must restate `read: ["authenticated"]` explicitly rather than omitting it and hoping it falls through to some inherited breadth — there is no fallback once the block exists. Getting this restatement wrong (writing a Tier-3 block with only `create`/`update`) silently denies read to everyone but admin — the exact trap named in "The two ways this change can go wrong," now with a second reason it matters: getting it wrong here doesn't just under-protect, it reproduces C5's original bug (empty learner-facing catalogue) at the level of a single mis-written schema.

Write stays staff-only in both cases, but "staff" means different people depending on who authors the content — hence two profiles, not one:
- **Profile 3a (13 schemas, unchanged from the original Tier-3 design)**: `create`/`update` → `compliance-officers`, `team-leads` only. These are shared system configuration (grading scales, room definitions, fee schedules) that ordinary `instructors`/`hr` staff should not be able to casually edit.
- **Profile 3b (8 schemas, new)**: `create`/`update` → `instructors`, `hr`, `compliance-officers`, `team-leads` — the SAME four roles as the Tier-2 cascade's write grant. Course content is authored by instructors as a normal part of their job; narrowing their write access the way Profile 3a narrows configuration-editing would break authoring, which is a second, different way this change could have shipped broken (this one on the STAFF side rather than the learner side).

**Alternative considered**: fold `Course`/`Lesson`/`Material`/`Programme`/`CurriculumPlan`/`Assignment`/`Assessment`/`ItemBank` into the existing 13-schema Tier-3 list, one profile, one write grant (`compliance-officers`/`team-leads` only). Rejected — it fixes the read regression but introduces a write regression: an instructor could no longer create their own `Lesson`, which is a materially different, and arguably worse, way to ship broken (the config-editing narrowing in Profile 3a is deliberate friction against a small population of shared objects; applying it to every lesson every instructor writes is friction against the app's core daily workflow).

**Alternative considered**: give Tier-2's register cascade `learners: read` back, narrowed instead to a match condition (`{group: "learners", match: {...}}`) so one cascade handles both catalogue and learner-attributed data. Rejected for the same reason Residual Exposure rejects it for Tier 2 alone: there is no single match field that means "this object is catalogue, safe to read" vs. "this object is personal, read only your own" across 76 differently-shaped schemas — the two populations need genuinely different rules, not one clever rule, and forcing them into one cascade block reintroduces exactly the conflation this decision exists to undo.

**Approximation acknowledged**: `Assignment`, `Assessment`, and `ItemBank` are treated as wholly catalogue at the schema level, even though their names suggest some result-bearing content COULD live there (a score embedded directly in an `Assessment` object, say, rather than in the separate `AssessmentResult` schema). This change did not audit each of these three schemas' properties for embedded result data — if any exists, it needs property-level `authorization` (the mechanism REQ-002's Profile A/B/C blocks don't need but property-level RBAC in general supports), not a schema-level widening. Flagged here, not fixed here, for the same reason the 84 (now 76)-schema individual review was declined in Decision 1: it is a bigger diff than this change's effort budget covers, and guessing wrong at property granularity is a smaller, more numerous version of the same fail-closed trap.

### Decision 5: The scope map is the group inventory of record
`components.securitySchemes.oauth2.flows.authorizationCode.scopes` is authored by hand to list all eight group ids with descriptions, independent of whether every authorization block in the file happens to reference every group. This is the OR spec's explicit requirement (*"An exported configuration MUST declare the groups it depends on... at parity with the map `OasService` generates"*) and gives `RbacGroupCollector::fromScopeMap()` a second, independent path to group ids that no `authorization` block currently references.

This is not a hypothetical for this change — it is `learners`' actual situation after Decision/C1 below. `learners` appears in zero authorization blocks (register cascade or otherwise) once the blanket cascade grant is removed, yet the group still needs to exist the moment this change ships, because (a) an administrator needs somewhere to put learner accounts ahead of the object-level-scoping work that will reference the group, and (b) `fix-dead-role-gates`' resolver needs the group to exist to test membership against, regardless of whether any authorization rule currently grants that membership anything. The same reasoning covers `coordinators`/`guardians`/`administration-managers` (Decision 2) — the scope map is what makes "declared, not yet assigned any permission" a supported, provisioned state instead of an absent one.

### Decision 7 (C6): `authenticated` will be provisioned as a real, empty Nextcloud group — a known wart, recorded rather than routed around
Every Tier-3 (catalogue) schema's `authorization` block declares `read: ["authenticated"]` — a bare string, structurally identical to a group id in the `authorization` block grammar. `RbacGroupCollector::groupFromRule()` extracts bare strings as group ids without distinguishing "this is a real, membership-tested Nextcloud group" from "this is a pseudo-group `PermissionHandler`/`MagicRbacHandler` special-case." `RbacGroupCollector::RESERVED_PRINCIPALS` is `['admin', 'public']` — `authenticated` is not on that list, even though it is exactly as much a pseudo-group as `public` is. `PermissionHandler` treats it identically: *"'authenticated' pseudo-group: any logged-in user qualifies, independent of real group membership"* (~line 750) — the check is `$userId !== null`, never a membership test. `MagicRbacHandler` mirrors this at the SQL layer (`$rule === 'authenticated' && $userId !== null`, multiple call sites).

The practical consequence: the next import provisions a **ninth** Nextcloud group, literally named `authenticated`, alongside the eight canonical ids this change intends. It will always have zero members, and membership in it will never be tested by anything — an administrator who finds it in the group-admin UI and adds a user to it accomplishes nothing, because no RBAC check ever asks "is this user IN the group called `authenticated`," only "is this user logged in at all." This is not a defect in this change's authorization content — REQ-003's `read: ["authenticated"]` is exactly the correct, spec-documented way to grant catalogue read breadth (`openregister/openspec/specs/rbac-scopes/spec.md`'s "Authenticated pseudo-group grants access to any logged-in user" scenario). The defect, if it is one, is one line in `RbacGroupCollector::RESERVED_PRINCIPALS` upstream, not in this file.

**What this change does about it**: names it, here and in spec.md's Notes and REQ-005's new scenario, and adds a verification step (Task 6) that checks the post-import group list for the extra `authenticated` entry rather than assuming it away. **What this change does NOT do**: patch `RbacGroupCollector` (PHP, out of scope, and not this app's file to patch — it lives in `openregister/lib/`) or avoid the string `"authenticated"` to dodge the side effect (that would mean giving up the correct, documented mechanism for granting catalogue-wide read, to work around a cosmetic wart in a different repository). The right next step is raising `authenticated` as a candidate third `RESERVED_PRINCIPALS` entry with OpenRegister directly — an upstream one-line fix that benefits every app using the pseudo-group, not a per-app workaround repeated in every leaf app's register file.

**Alternative considered**: omit `read: ["authenticated"]` and instead list every real group that should read catalogue data (`instructors, hr, compliance-officers, team-leads, learners, coordinators, guardians, administration-managers`) explicitly. Rejected — it is strictly worse: it still provisions no fewer groups (all eight are provisioned anyway, per Decision 2/5), it must be kept in sync by hand every time a new group joins the canonical vocabulary, and it silently excludes any FUTURE group added to the vocabulary until this file is edited again — exactly the kind of drift the `authenticated` pseudo-group exists to avoid. One inert extra group in the admin UI is a smaller cost than a maintenance trap.

### ADR-031 compliance note (declarative-vs-imperative)
This change introduces no lifecycle, aggregation, calculation, notification, relation, or widget behaviour — the trigger conditions for a full "Declarative-vs-imperative decision" section (per the opsx-ff skill) don't apply. Every requirement in the spec is satisfied by JSON content in `lib/Settings/scholiq_register.json`; no `lib/Service/*Service.php` class is added or touched. `kind: config` in the proposal frontmatter reflects this directly.

### Seed Data note (ADR-001)
Not applicable. This change adds no new schema and seeds no new domain objects — it only adds `authorization` content to schemas that already exist and already have their own seed data (or lack of it) unrelated to this change. There is nothing for the apply agent to generate into `_registers.json` here.

## JSON shapes (reference, values are illustrative placeholders — apply-time task list has the authoritative schema-by-schema assignment)

Register-level (`components.registers.scholiq`) — staff-only cascade, `learners` deliberately absent (see Residual Exposure):
```json
{
  "slug": "scholiq",
  "title": "Scholiq Register",
  "configuration": {
    "roles": [
      { "name": "read-write", "description": "Read, create, and update operational Scholiq data.", "actions": ["read", "create", "update"] }
    ]
  },
  "authorization": {
    "roles": {
      "read-write": ["instructors", "hr", "compliance-officers", "team-leads"]
    }
  }
}
```

Tier-1 example (`components.schemas.DossierNote`, Profile A — replaces the existing dead `x-openregister-authorization` key on this schema):
```json
{
  "authorization": {
    "read": ["instructors", "compliance-officers"],
    "create": ["instructors", "compliance-officers"],
    "update": ["instructors", "compliance-officers"]
  }
}
```

Tier-3 example, Profile 3a — shared configuration (`components.schemas.GradeScale`), write EXCLUDES `instructors`/`hr`:
```json
{
  "authorization": {
    "read": ["authenticated"],
    "create": ["compliance-officers", "team-leads"],
    "update": ["compliance-officers", "team-leads"]
  }
}
```

Tier-3 example, Profile 3b — course-content catalogue (`components.schemas.Lesson`), write INCLUDES `instructors` (they author this content) — read breadth is identical to Profile 3a, write breadth is not:
```json
{
  "authorization": {
    "read": ["authenticated"],
    "create": ["instructors", "hr", "compliance-officers", "team-leads"],
    "update": ["instructors", "hr", "compliance-officers", "team-leads"]
  }
}
```

Scope map (top-level `components.securitySchemes`, currently absent entirely — added fresh; all eight canonical ids, four referenced by this change's authorization content and four scope-map-only — see Decision 5):
```json
{
  "securitySchemes": {
    "basicAuth": { "type": "http", "scheme": "basic" },
    "oauth2": {
      "type": "oauth2",
      "flows": {
        "authorizationCode": {
          "authorizationUrl": "/apps/oauth2/authorize",
          "tokenUrl": "/apps/oauth2/api/v1/token",
          "scopes": {
            "instructors": "Access for instructors group",
            "hr": "Access for hr group",
            "compliance-officers": "Access for compliance-officers group",
            "team-leads": "Access for team-leads group",
            "learners": "Access for learners group",
            "coordinators": "Access for coordinators group",
            "guardians": "Access for guardians group",
            "administration-managers": "Access for administration-managers group"
          }
        }
      }
    }
  }
}
```
(`admin` is appended automatically by `OasService`/`RbacGroupCollector` at generation/collection time and does not need to be hand-written; writing it would be harmless but redundant since it is a reserved principal.)

## The two ways this change can go wrong (and how to tell which one happened)

1. **An unconfigured `authorization` block is OPEN, not closed.** If a Tier-1 schema is accidentally left without an `authorization` key (a copy-paste that drops the key, a merge conflict that reverts one schema), that schema silently reverts to the pre-change fully-open state — indistinguishable from "protected" by looking at the schema in isolation, distinguishable only by checking the key exists. The verification task (below) checks presence on all 42 named schemas explicitly, not just "the import didn't error."
2. **An `authorization` block that declares only `read` IS the guard for `create`/`update`/`delete` — and the reverse trap, an incomplete `read`, is JUST as silent.** Because an unlisted action is denied once the block is non-empty (Decision 3), a schema-level block that names `read` but forgets `create` does not "inherit create from the register cascade" — it silently denies create to everyone but admin. Symmetrically, forgetting `read: ["authenticated"]` on a Tier-3 (catalogue) block doesn't "inherit read from the cascade" either — it silently denies read to everyone but admin, which is exactly the C5 bug (empty learner-facing catalogue) reproduced at single-schema granularity. Getting Tier-3's `read: ["authenticated"]` restatement wrong (Decision 4) is the concrete place this bites in this change, and it is now the failure mode this change's own history demonstrates is easy to make, not just a hypothetical.

Both are silent. Neither produces an error at import time. The only way to catch either is to read the resulting effective policy per schema against the profile table in the spec — which is why the verification task below checks specific (schema, action, expected-groups) tuples rather than "the file is valid JSON."

## Residual Exposure (what this change does NOT close)

**The rule this change implements is: learners read the catalogue, never another learner's record.** Not "learners read nothing" — an earlier draft of this design conflated two populations under one word ("Tier 2") and produced exactly that overly blunt rule, which broke the learner-facing product (Decision 4/C5 tells that story in full). The corrected rule has two halves, and a spec or a reviewer that only checks one half would pass a change that still ships broken:

- **Catalogue/definitional data (Tier 3 — `Course`, `Lesson`, `Material`, `Programme`, `CurriculumPlan`, and 17 more)**: `learners` reads it, same as every other authenticated user. This is CLOSED off in this change's scope — it's done, not deferred.
- **Learner-attributed records (Tier 2 — `GradeEntry`, `FinalGrade`, `AssessmentResult`, `ReportCard`, `GradeNotification`, `Enrolment`, `LessonCompletion`, `CompetencyAttainment`, `EngagementScore`, `LearnerEngagement`, `PointAward`, `PortfolioEntry`, and more)**: `learners` reads NONE of it via any group grant — not even their own. THIS is the residual gap. **This change closes instance-wide open access on this population. It does NOT close per-learner scoping.** That distinction matters enough to say plainly, because it is easy to conflate with the catalogue population above, and this change must not be read as "RBAC done."

Why the learner-attributed population can't take the same fix as the catalogue population: the object-owner bypass (`PermissionHandler` grants an object's owner full access regardless of group, used throughout REQ-002's Tier-1 profiles) does **not** save this case. A `GradeEntry` is created BY the instructor who assigns the grade, so its `objectOwner` is the instructor, not the learner it's about — structurally identical to `Course` (Decision 4), except a `Course` is not personal data about anyone and a `GradeEntry` is. That difference is why the two populations get opposite read rules, not the same one. Granting `learners: read` on `GradeEntry` the way this change grants it on `Course` would replace "any authenticated user reads any learner's grades" (today's actual defect) with "any learner reads any OTHER learner's grades" (a narrower, but still real, lateral-disclosure defect) — closing the door to strangers while leaving it open between classmates, or between colleagues in the corporate deployment. That is the mistake C1 was actually trying to prevent, and the mistake the first draft of Decision 4 made by applying the same prevention too broadly.

There is no group-shaped fix for the learner-attributed population. A Nextcloud group is a set of users; `PermissionHandler::hasGroupPermission()` tests membership, not "does this object concern me." Granting `learners: read` on `GradeEntry` is necessarily a grant to see every `GradeEntry` row, not just one's own — there is no third state between "closed to the group" and "open to the group," which is exactly why this population can't be handled the way Tier 3 is (Tier 3's "every row is fine for every learner to see" premise simply isn't true here). Closing this properly needs an **object-level conditional scope**: something in the shape of `{ "group": "learners", "match": { "learnerId": "$userId" } }` (the conditional-rule mechanism this change deliberately doesn't use, documented in `openregister/openspec/specs/rbac-scopes/spec.md`'s "Conditional Scopes with Dynamic Variables" requirement family) — a rule this change could have written today, since the mechanism already exists.

It was deliberately NOT written here, for two reasons: (1) it needs a per-schema decision about which field holds the learner's identity (`learnerId` on some schemas, implicit via a relation on others, absent on schemas that aren't learner-attributed at all — e.g. `Room`, `TeacherAvailability`), which is exactly the kind of 76-schema individual review this change's tiering strategy (Decision 1) chose not to attempt; and (2) getting the match field wrong on even one schema reproduces the fail-closed trap from "The two ways this change can go wrong" above, at conditional-rule granularity — a bigger, harder-to-review diff than this change's effort budget covers. Rather than ship 76 hand-guessed match conditions in the same change that is establishing the tiering methodology, this change ships the STAFF-only floor for learner-attributed data (strictly better than today) and defers the learner-self-scoping layer to a follow-up that can review the learner-identity field per schema on its own.

**Net effect after this change**: a learner can read the catalogue (Tier 3 — courses, lessons, materials, programmes) freely, same as before this change, because that was never the exposure. A learner can read/write learner-attributed records they own outright (owner bypass — e.g. their own `Submission`, `SelfAssessment`, both Tier 1). A learner CANNOT read learner-attributed records ABOUT them that staff created (grades, attendance-derived scores, enrolment records) via any mechanism this change provides — that is a temporary, deliberate regression in ONE SPECIFIC learner-facing capability ("see my own grade") relative to today's fully-open (and insecure) baseline, not a silent gap and not the whole learner surface. It is the accepted trade of "learners can't yet see their own grades" against "any authenticated user, learner or not, can read the whole gradebook," and it is intentionally the narrower failure mode.

**Stated plainly, so this change is not read as "RBAC done"**: this change closes instance-wide open access, on both populations. It closes per-learner scoping for the catalogue population (learners already get exactly the read they need — there was never a narrower "own" version of a `Course` to scope to). It does NOT close per-learner scoping for the learner-attributed population. That needs object-level conditional scopes (the `{group, match}` rule shape referenced above) and is owned by the later administrations change — not this one. The `learners` group this change provisions (Decision 5) exists precisely so that later change has something to reference from day one, but this change assigns it no permission on any learner-attributed schema.

## Risks / Trade-offs

- [Risk] Tier-1 profile assignment is a judgment call (Decision 1's rationale), not derived from a pre-existing authoritative source, for 14 of the 21 schemas whose only existing signal was their schema title/description. → Mitigation: every assignment is named individually in the spec (REQ-002) so a reviewer can challenge any single one without blocking the rest; moving one schema to a different profile is a one-line follow-up, not a design change.
- [Risk] The 76 Tier-2 (learner-attributed) schemas get no individual scrutiny — a schema that should have been Tier 1 (sensitive data not identified during this review) stays on the register cascade, readable only by the four staff groups (`instructors`/`hr`/`compliance-officers`/`team-leads`), not by `learners` at all after C1. → Mitigation: the register cascade is stricter than the pre-change baseline in every case — a mis-tiered schema is staff-only-readable rather than "readable by any authenticated user" (pre-change), so a mis-tiering here is a smaller residual risk than the original defect.
- [Risk] A schema was mis-classified as Tier 3 (catalogue) when it actually carries learner-attributed data, granting `learners` a read they shouldn't have. → Mitigation: REQ-003 names all 21 Tier-3 schemas individually so this is a reviewable, one-line-per-schema check, same as REQ-002's Tier-1 list (Decision 1's existing mitigation for Tier-1 misclassification, extended to Tier 3 after C5). The `Assignment`/`Assessment`/`ItemBank` approximation (Decision 4) is the most likely place this risk materialises, and is flagged explicitly rather than assumed safe.
- [Risk] Zero-member groups (Decision/Goal note above) mean this change, by itself, denies everyone write access to Tier-1/Tier-3 data and denies non-staff-labelled users even read access to learner-attributed (Tier-2) data, until an administrator populates the four staff groups. Catalogue (Tier 3) read is unaffected by this — it uses the `authenticated` pseudo-group, not group membership, so it needs no member population to work. → Mitigation: this is flagged as Risk 1 in the proposal and the immediate operational follow-up (not a task in this change's tasks.md, since adding members is explicitly out of scope per the SCOPE section).
- [Risk] Learners lose the ability to read learner-attributed records ABOUT them (grades, attendance, enrolment) that they do not own outright, until the administrations change ships object-level scoping (Residual Exposure). This does NOT extend to the catalogue (Tier 3) — a learner's course/lesson/material access is unaffected by this change, corrected from an earlier draft that got this wrong (Decision 4/C5). → Mitigation: this is an accepted, documented, and reversible functional trade — not a security risk. It is strictly better than the alternative (a blanket `learners: read` grant on learner-attributed data enabling lateral disclosure between learners) and the fix is additive (a follow-up change adds conditional rules; it does not need to undo anything this change ships).
- [Risk] `authenticated` is provisioned as a real, empty, permanently-inert Nextcloud group (Decision 7/C6) — an administrator could mistake it for a meaningful access boundary and add members to it, accomplishing nothing. → Mitigation: named explicitly in this design, in spec.md, and verified by Task 6; the fix belongs upstream in OpenRegister's `RbacGroupCollector`, flagged there rather than worked around here.

## Migration Plan
No `lib/Migration/` PHP class — this is a configuration-document change picked up by scholiq's existing `InitializeSettings` repair step (already wired under both `<install>` and `<post-migration>`, see Context). Deploy = merge the JSON change, next app upgrade or fresh install re-imports the register, `GroupProvisioner` provisions all eight declared groups. Rollback = revert the JSON file and re-import; see proposal.md's Rollback Strategy for why this is safe (RBAC is evaluated live from the stored entity, no separate cache to bust beyond OpenRegister's documented per-request cache).

## Open Questions
- Whether scholiq's frontend (Vue/Pinia) reads the OAS `security` blocks to hide create/edit affordances for users who lack write access on a Tier-1/Tier-3 schema, or whether they will see a control that then 403s. Not investigated in this change (config-only, no frontend files read or touched) — flagged for a follow-up UX pass once the staff groups have real members and the behaviour is observable end-to-end.
- Whether any of the 76 Tier-2 (learner-attributed) schemas should have been Tier 1 (see Risks). C1's removal of the `learners` cascade grant reduces the blast radius of a mis-tiered schema considerably (staff-only-readable instead of any-authenticated-user-readable), so this stays out of scope for this change with a smaller residual than before, referencing Residual Exposure rather than needing its own investigation here.
- Whether `Assignment`, `Assessment`, or `ItemBank` carry any result-bearing (learner-attributed) fields that shouldn't be catalogue-wide-readable (see Decision 4's "Approximation acknowledged"). Not audited at the property level in this change.
- Whether OpenRegister will accept `authenticated` as a third `RESERVED_PRINCIPALS` entry (Decision 7/C6) — raised as a follow-up ask, not filed as an issue within this change's artifacts.
- See also proposal.md's Open Questions for the per-learner (object-level) scoping question, which is the load-bearing open item this change leaves for the administrations change (Residual Exposure, above).
