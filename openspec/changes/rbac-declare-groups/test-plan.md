# Test Plan: rbac-declare-groups

## Test Cases

### TC-1: Register import provisions all eight declared groups
- **spec_ref**: `openspec/changes/rbac-declare-groups/specs/rbac-groups/spec.md#requirement-req-005-declaring-the-authorization-blocks-and-scope-map-provisions-all-eight-groups-as-real-nextcloud-groups`
- **type**: api
- **persona**: n/a (operator/admin verification)
- **preconditions**: `GET /cloud/groups` (OCS) on the target instance matches zero of the eight canonical ids. Positive control: the same response includes at least one other app's role-named group (e.g. `decidesk-administrators`), proving the query itself returns app-named groups.
- **steps**: Import the updated `scholiq_register.json` (occ command or the app's own repair-step re-run). Re-query `GET /cloud/groups`.
- **expected result**: All eight group ids are present in the post-import response, each with zero members — including `learners`, `coordinators`, `guardians`, and `administration-managers`, none of which is referenced by any authorization rule in this change (they reach `RbacGroupCollector` via the scope map alone, per REQ-004).
- **test command**: `/test-api`

### TC-2: A scope-map-only group hand-deleted by an administrator is restored on the next import, even if content is otherwise unchanged
- **spec_ref**: `openspec/changes/rbac-declare-groups/specs/rbac-groups/spec.md#requirement-req-005-declaring-the-authorization-blocks-and-scope-map-provisions-all-eight-groups-as-real-nextcloud-groups`
- **type**: regression
- **persona**: n/a (operator/admin verification)
- **preconditions**: Register already imported once (content hash matches). Delete the `learners` group via Nextcloud's group admin UI or `occ group:delete learners` — chosen deliberately because `learners` is declared ONLY in the scope map (no authorization block references it after C1), so this also proves the scope-map-only declaration path, not just the authorization-block path, survives the import skip check.
- **steps**: Re-run the same import (no content change).
- **expected result**: `learners` exists again in `GET /cloud/groups`; no configuration entities are re-written (content hash still matches).
- **test command**: `/test-api`

### TC-3: A non-member is refused READ on a Tier-1 schema — the exposure this change actually closes
- **spec_ref**: `openspec/changes/rbac-declare-groups/specs/rbac-groups/spec.md#requirement-req-002-21-named-tier-1-schemas-declare-narrow-individually-assigned-authorization`
- **type**: security
- **persona**: n/a (direct API auth verification)
- **preconditions**: A test user exists who is not a member of `instructors`, `compliance-officers`, `hr`, or `team-leads`, and is not the author/owner of any `DossierNote` object. `instructors`/`compliance-officers` have at least one member so the positive-admission case (TC-4) has a control.
- **steps**: Authenticate as the test user. `GET` a `DossierNote` object via the OpenRegister objects API.
- **expected result**: HTTP 403. This is the behaviour reversal this change exists to produce — before this change, the same request against the same object returned 200 (verified against the pre-change `PermissionHandler` default-open branch in design.md's Context section).
- **test command**: `/test-security`

### TC-4: A member of the assigned profile is admitted on the same Tier-1 schema
- **spec_ref**: `openspec/changes/rbac-declare-groups/specs/rbac-groups/spec.md#requirement-req-002-21-named-tier-1-schemas-declare-narrow-individually-assigned-authorization`
- **type**: security
- **persona**: n/a (direct API auth verification)
- **preconditions**: A test user is a member of `instructors` (Profile A group for `DossierNote`).
- **steps**: Authenticate as the test user. `GET` the same `DossierNote` object used in TC-3.
- **expected result**: HTTP 200 — proves TC-3's 403 is the profile's group check working, not a broken schema or an unrelated 403 (e.g. a missing route).
- **test command**: `/test-security`

### TC-5: Tier-3 write differs by profile — Profile 3a excludes instructors, Profile 3b includes them
- **spec_ref**: `openspec/changes/rbac-declare-groups/specs/rbac-groups/spec.md#requirement-req-003-21-named-tier-3-catalogue-schemas-declare-wide-read-staff-only-write-across-two-profiles`
- **type**: security
- **persona**: n/a (direct API auth verification)
- **preconditions**: A test user is a member of `instructors` only.
- **steps**: Authenticate as the test user. `PUT`/`PATCH` a `GradeScale` object (Profile 3a). Separately, `PUT`/`PATCH` a `Lesson` object (Profile 3b).
- **expected result**: `GradeScale` update is HTTP 403 (Profile 3a's `create`/`update` list is `compliance-officers`, `team-leads` only — deliberately excludes `instructors`). `Lesson` update is HTTP 200 (Profile 3b's write list includes `instructors`, because they author lesson content — this is the C5 fix: excluding them here would break authoring the same way excluding `learners` from read broke the catalogue).
- **test command**: `/test-security`

### TC-6: Tier-3 read is deliberately WIDER than the Tier-2 cascade, not "matching" it
- **spec_ref**: `openspec/changes/rbac-declare-groups/specs/rbac-groups/spec.md#requirement-req-003-21-named-tier-3-catalogue-schemas-declare-wide-read-staff-only-write-across-two-profiles`
- **type**: api
- **persona**: n/a (direct API auth verification)
- **preconditions**: A test user is a member of `learners` only (satisfies NOTHING in the Tier-2 cascade — REQ-001 — but that is a different requirement from this one).
- **steps**: `GET` the `GradeScale` object from TC-5.
- **expected result**: HTTP 200 — confirms Decision 4's "restate `read` explicitly" choice actually prevents the silent-deny trap design.md warns about (a schema-level block that named only `create`/`update` would have denied this read too), AND confirms REQ-003's `read: ["authenticated"]` really does admit `learners`, which REQ-001's cascade never would.
- **test command**: `/test-api`

### TC-7: A `learners`-only user CAN read the catalogue and CANNOT read another learner's own record — both halves, one actor (the corrected rule)
- **spec_ref**: `openspec/changes/rbac-declare-groups/specs/rbac-groups/spec.md#requirement-req-001-the-scholiq-register-declares-a-role-based-authorization-cascade-staff-only`, `openspec/changes/rbac-declare-groups/specs/rbac-groups/spec.md#requirement-req-003-21-named-tier-3-catalogue-schemas-declare-wide-read-staff-only-write-across-two-profiles`
- **type**: security
- **persona**: n/a (direct API auth verification)
- **preconditions**: A SINGLE test user is a member of `learners` only. A `Course` object exists (Tier 3, catalogue). A `GradeEntry` object exists (Tier 2, learner-attributed) that a DIFFERENT learner owns (`objectOwner` = the instructor who created it, not the test user — see design.md Residual Exposure for why the owner bypass does not apply here).
- **steps**: Authenticate as the test user (same session throughout). `GET` the `Course` object. Then, still as the same user, `GET` the `GradeEntry` object, and separately `POST` a new `GradeEntry` object.
- **expected result**: `Course` read is HTTP 200 (catalogue: wide read, `learners` included — REQ-003). `GradeEntry` read AND create are both HTTP 403 (learner-attributed: no group grant for `learners` — REQ-001). Both outcomes on the SAME actor in the SAME test — a test that only proved the refusal half (the pre-C5 version of this test plan) would still pass if the catalogue were also broken, which is exactly the bug C5 caught; this pairing is what makes that impossible.
- **test command**: `/test-security`

### TC-8: No `x-openregister-authorization` or `x-property-rbac`-shaped decoy key remains where a real block now governs
- **spec_ref**: `openspec/changes/rbac-declare-groups/specs/rbac-groups/spec.md#requirement-req-006-the-x-openregister-authorization-decoy-key-is-removed-from-every-schema-that-carries-it`
- **type**: functional
- **persona**: n/a (static file check)
- **preconditions**: Updated `lib/Settings/scholiq_register.json`.
- **steps**: Grep the file for `x-openregister-authorization` across all 118 schemas.
- **expected result**: Zero matches.
- **test command**: `/test-functional` (or a plain grep, run manually — no browser/API round-trip needed for a static JSON check)

### TC-9: `authenticated` is provisioned as a ninth, real-but-inert Nextcloud group (C6 — verify, don't assume)
- **spec_ref**: `openspec/changes/rbac-declare-groups/specs/rbac-groups/spec.md#requirement-req-005-declaring-the-authorization-blocks-and-scope-map-provisions-all-eight-groups-as-real-nextcloud-groups`
- **type**: api
- **persona**: n/a (operator/admin verification)
- **preconditions**: Same as TC-1, run immediately after it (same import).
- **steps**: `GET /cloud/groups` after import (TC-1's post-import query). Check for a group literally named `authenticated` in addition to the eight canonical ids.
- **expected result**: `authenticated` IS present — expected-but-undesirable per design.md Decision 7, not a test failure. If it is EVER absent (e.g. a future OpenRegister release adds it to `RESERVED_PRINCIPALS`), that is a welcome, noteworthy change, not a regression — update this test case's expected result rather than treating the disappearance as a defect.
- **test command**: `/test-api`

## Coverage Summary
- REQ-001 (register cascade, staff-only): TC-7 (the `GradeEntry` half — denial of both read and write for a `learners`-only user, the C1 lateral-disclosure fix), plus design.md Decision 3's admin-only-delete reasoning is a static property of the JSON, not independently tested here (would require testing the impossible-to-construct "authenticated non-admin who is also not a group member" delete attempt, which TC-7's same user/mechanism already exercises for `create`).
- REQ-002 (Tier-1 narrow blocks): TC-3 (refusal), TC-4 (admission) — the SPEC QUALITY BAR pairing.
- REQ-003 (Tier-3 catalogue, wide read / two-profile write): TC-5 (write differs by profile), TC-6 (read is wider than the cascade, not matching it), TC-7 (the `Course` half — the C5 fix, proving learners actually get this read).
- REQ-004 (scope map, all eight canonical ids): TC-1 and TC-2 together cover it — TC-1 proves all eight are provisioned including the four (`learners`/`coordinators`/`guardians`/`administration-managers`) declared only via the scope map, and TC-2 proves the scope-map-only path specifically (not just the authorization-block path) survives the import skip check.
- REQ-005 (provisioning): TC-1, TC-2, TC-9 (the `authenticated` side effect, verified rather than assumed away — C6).
- REQ-006 (decoy key removal): TC-8.
- **The load-bearing pairing (C5)**: TC-7 is the single test case that would have caught the original C5 bug — a test suite that ran only TC-3/TC-4 (Tier-1 refusal/admission) and TC-1/TC-2 (provisioning) would have gone fully green on the broken draft, because nothing in the original test plan asserted a learner could read anything at all. TC-7 exists specifically to close that blind spot.

## Out of Scope
- Object-level (per-learner) conditional scopes — the mechanism design.md's Residual Exposure section names as the follow-up that lets a learner read their OWN `GradeEntry`/`FinalGrade`/etc. without a blanket group grant. Not built or tested in this change; owned by the later administrations change.
- Property-level audit of `Assignment`/`Assessment`/`ItemBank` for embedded result-bearing fields (design.md Decision 4's "Approximation acknowledged") — flagged as an Open Question, not tested here.
- Frontend (Vue/Pinia) behaviour when a user without write access sees a create/edit affordance that would then 403 — flagged as an Open Question in design.md, not tested here (no frontend files are touched by this change).
- Load/performance testing of RBAC evaluation cost — no new query pattern is introduced; OpenRegister's existing per-schema RBAC cost is already paid today regardless of whether the schema declares `authorization`.
