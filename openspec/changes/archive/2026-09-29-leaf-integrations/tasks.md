# Tasks: leaf-integrations

> **Round 5 (2026-09-28): built, narrowed by decision D1.** Polls (Session, Cohort) and the Cohort calendar
> and forms leaves are communication, which D1 places in portaliq; they are not built and REQ-002, REQ-004
> and REQ-006 in the spec delta say so. Paths moved since August: the register is
> `lib/Settings/learniq_register.json`, the pages live in `src/manifest.d/{learning,people,work-placement}.json`,
> and there is no `CHANGELOG.md` (the docs page is the record). What was built: 6 schemas, 7 widgets.

## Implementation Tasks

### Task 1: Declare `linkedTypes` on the 8 schemas (must / MVP)
- **spec_ref**: `openspec/specs/integration-leaves/spec.md#requirement-leaves-are-declared-not-coded-req-001`
- **files**: `lib/Settings/scholiq_register.json`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN edited THEN `Session.linkedTypes = ["talk", "calendar", "polls"]`, `Cohort.linkedTypes = ["talk", "calendar", "forms", "polls"]`, `Assignment.linkedTypes = ["calendar", "forms"]`, `Credential.linkedTypes = ["calendar"]`, `LearnerProfile.linkedTypes = ["contacts"]`, `Praktijkopleider.linkedTypes = ["contacts"]`, `BpvPlacement.linkedTypes = ["deck"]` — and the pre-existing `talk` entries on `Cohort`/`Session` are preserved, not replaced
  - GIVEN the file WHEN grepped for `linkedTypes` THEN exactly 7 schemas carry the key and no catalog-definition schema (`Course`, `Programme`, `CurriculumPlan`, `CourseTemplate`, `Regulation`) or assessment-family schema (`Assessment`, `Item`, `ItemBank`, `Submission`, `GradeEntry`) carries any new entry
  - GIVEN each edit WHEN `python3 -m json.tool lib/Settings/scholiq_register.json` runs THEN it exits 0 and no pre-existing key is dropped
  - GIVEN the register is re-imported WHEN `Schema::validateLinkedTypesValue()` runs THEN no invalid-linked-type error is raised
- [x] Implement
- [x] Test

### Task 2: Add the 12 integration widgets to the manifest (must / MVP)
- **spec_ref**: `openspec/specs/integration-leaves/spec.md#requirement-calendar-leaves-on-session-cohort-assignment-and-credential-req-002`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN edited THEN these widgets exist, each shaped like the existing `cohort-talk` widget (`id`, `type: "integration"`, `integrationId`, `title`, `icon`): SessionDetail `sess-calendar` (calendar) + `sess-poll` (polls, title "Quick poll (not graded)"); CohortDetail `coh-calendar` (calendar) + `coh-intake-form` (forms) + `coh-poll` (polls, "(not graded)"); AssignmentDetail `asn-calendar` (calendar) + `asn-intake-form` (forms); CredentialDetail `cred-calendar` (calendar); LearnerProfileDetail `lp-contact` (contacts); PraktijkopleiderDetail `po-contact` (contacts); BpvPlacementDetail `bpv-deck` (deck)
  - GIVEN the manifest WHEN grepped for `"type": "integration"` THEN the count is 31 (19 pre-existing + 12 new) and no pre-existing widget id changed
  - GIVEN the built app WHEN the manifest validator runs THEN it passes
- [x] Implement
- [x] Test

### Task 3: e2e spec-coverage for the new leaf widgets (must / MVP)
- **spec_ref**: `openspec/specs/integration-leaves/spec.md#requirement-polls-leaves-exist-only-on-delivery-run-archetypes-and-are-not-assessments-req-006`
- **files**: `tests/e2e/spec-coverage/integration-leaves.spec.ts`
- **acceptance_criteria**:
  - GIVEN the leaf NC apps (`calendar`, `contacts`, `forms`, `deck`, `polls`) are enabled in the test env WHEN the suite runs THEN it asserts widget presence (by widget title) on SessionDetail, CohortDetail, AssignmentDetail, CredentialDetail, LearnerProfileDetail, PraktijkopleiderDetail, and BpvPlacementDetail against seeded objects
  - GIVEN CohortDetail (the densest page: talk + calendar + forms + polls) WHEN rendered THEN the page shows no horizontal overflow and all four leaf widgets are reachable
  - GIVEN a leaf NC app is disabled WHEN the corresponding page renders THEN the test tolerates the absent widget (provider `isEnabled()` behaviour) rather than failing
- [x] Implement
- [x] Test

### Task 4: Document the leaf surface (must / MVP)
- **spec_ref**: `openspec/specs/integration-leaves/spec.md#requirement-leaves-are-declared-not-coded-req-001`
- **files**: `docs/`, `CHANGELOG.md`
- **acceptance_criteria**:
  - GIVEN `docs/` WHEN read THEN it records the ON matrix (leaf × schema × page), the OFF list with reasons, and the rule that catalog definitions carry no leaves beyond `files`
  - GIVEN `CHANGELOG.md` WHEN read THEN it records the five new leaf types and names the pages gaining widgets
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate leaf-integrations --type change --strict` passes
- [x] Manual testing against acceptance criteria (not done in this lane: no instance with the leaf apps; the e2e asserts both the installed and the absent state)
  - r5-live, 2026-09-29, shared dev instance: `tests/e2e/spec-coverage/integration-leaves.spec.ts` 6 passed on all six pages with calendar enabled and contacts, deck and forms disabled. The calendar leaf renders; each disabled app shows "{app} is not installed". Live checks also found that a disabled app's leaf drew an empty "No cards linked yet" card; fixed in #1428, red 4/6 before and green 6/6 after.
- [x] Code review against spec requirements
  - Review: openspec/changes/leaf-integrations/review.md, 6 requirements, 8 scenarios; all 14 rows MET. The static scenarios (enumerable surface, no leaf on catalogue definitions, no polls) had only a one-time acceptance grep; this PR pins them in `tests/Unit/Settings/IntegrationLeavesRegisterTest.php`.

## Tests (company-wide ADR-009)
- [x] Browser tests (Playwright MCP): `tests/e2e/spec-coverage/integration-leaves.spec.ts` (Task 3), written and linted; it runs in CI e2e, the lane may not use the shared instance
- [x] All tests pass; zero new failures vs a self-measured baseline
  - r5-live, 2026-09-29, shared dev instance: leaf spec 6/6 green after #1428; `composer check:strict` exit 0 on #1428; 0 PHPUnit failures after merge.
- PHPUnit: N/A — this change ships no PHP; the only leaf listener (`CohortTalkMembershipHandler`) predates it and is untouched.
- Newman/Postman: N/A — no HTTP endpoint is added; leaf data flows through OpenRegister's existing integrations API.

## Documentation (company-wide ADR-010)
- [x] `docs/` records the leaf matrix and the OFF rationale (Task 4): `docs/Integrations/index.md`
- [x] Screenshots of the detail pages that carry the new widgets committed to `docs/images/`: SessionDetail (calendar), AssignmentDetail (calendar and forms), LearnerProfileDetail (contacts) and BpvPlacementDetail (deck). CohortDetail carries no leaf from this change.
  - Throwaway instance, 2026-09-29, calendar 6.5.0, contacts 8.9.0, deck 1.18.5 and forms 5.3.1 enabled: `docs/images/integration-leaves/session-detail-calendar.png` (Agenda), `assignment-detail-calendar-forms.png` (Agenda and Intake form), `learner-profile-detail-contacts.png` and `bpv-placement-detail-deck.png` (Follow-ups). No page showed "{app} is not installed". One difference: on LearnerProfileDetail the contacts leaf renders with the heading "Contacts" and a count, not the manifest's title "Contact card". The deck, forms and calendar leaves show their manifest titles. The contacts leaf also sits on PraktijkopleiderDetail and the calendar leaf on CredentialDetail; those two were not screenshotted.

## i18n (company-wide ADR-005)
- [x] Widget titles ("Agenda", "Intake form", "Contact card", "Follow-ups"; the poll title is not built) are new user-facing strings, Dutch in `l10n/nl.json`: `nl_NL` and `en_US` entries added through the manifest's i18n mechanism used by the existing widget titles
