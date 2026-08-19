# Tasks: leaf-integrations

## Implementation Tasks

### Task 1: Declare `linkedTypes` on the 8 schemas (must / MVP)
- **spec_ref**: `openspec/specs/integration-leaves/spec.md#requirement-leaves-are-declared-not-coded-req-001`
- **files**: `lib/Settings/scholiq_register.json`
- **acceptance_criteria**:
  - GIVEN the register JSON WHEN edited THEN `Session.linkedTypes = ["talk", "calendar", "polls"]`, `Cohort.linkedTypes = ["talk", "calendar", "forms", "polls"]`, `Assignment.linkedTypes = ["calendar", "forms"]`, `Credential.linkedTypes = ["calendar"]`, `LearnerProfile.linkedTypes = ["contacts"]`, `Praktijkopleider.linkedTypes = ["contacts"]`, `BpvPlacement.linkedTypes = ["deck"]` — and the pre-existing `talk` entries on `Cohort`/`Session` are preserved, not replaced
  - GIVEN the file WHEN grepped for `linkedTypes` THEN exactly 7 schemas carry the key and no catalog-definition schema (`Course`, `Programme`, `CurriculumPlan`, `CourseTemplate`, `Regulation`) or assessment-family schema (`Assessment`, `Item`, `ItemBank`, `Submission`, `GradeEntry`) carries any new entry
  - GIVEN each edit WHEN `python3 -m json.tool lib/Settings/scholiq_register.json` runs THEN it exits 0 and no pre-existing key is dropped
  - GIVEN the register is re-imported WHEN `Schema::validateLinkedTypesValue()` runs THEN no invalid-linked-type error is raised
- [ ] Implement
- [ ] Test

### Task 2: Add the 12 integration widgets to the manifest (must / MVP)
- **spec_ref**: `openspec/specs/integration-leaves/spec.md#requirement-calendar-leaves-on-session-cohort-assignment-and-credential-req-002`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN edited THEN these widgets exist, each shaped like the existing `cohort-talk` widget (`id`, `type: "integration"`, `integrationId`, `title`, `icon`): SessionDetail `sess-calendar` (calendar) + `sess-poll` (polls, title "Quick poll (not graded)"); CohortDetail `coh-calendar` (calendar) + `coh-intake-form` (forms) + `coh-poll` (polls, "(not graded)"); AssignmentDetail `asn-calendar` (calendar) + `asn-intake-form` (forms); CredentialDetail `cred-calendar` (calendar); LearnerProfileDetail `lp-contact` (contacts); PraktijkopleiderDetail `po-contact` (contacts); BpvPlacementDetail `bpv-deck` (deck)
  - GIVEN the manifest WHEN grepped for `"type": "integration"` THEN the count is 31 (19 pre-existing + 12 new) and no pre-existing widget id changed
  - GIVEN the built app WHEN the manifest validator runs THEN it passes
- [ ] Implement
- [ ] Test

### Task 3: e2e spec-coverage for the new leaf widgets (must / MVP)
- **spec_ref**: `openspec/specs/integration-leaves/spec.md#requirement-polls-leaves-exist-only-on-delivery-run-archetypes-and-are-not-assessments-req-006`
- **files**: `tests/e2e/spec-coverage/integration-leaves.spec.ts`
- **acceptance_criteria**:
  - GIVEN the leaf NC apps (`calendar`, `contacts`, `forms`, `deck`, `polls`) are enabled in the test env WHEN the suite runs THEN it asserts widget presence (by widget title) on SessionDetail, CohortDetail, AssignmentDetail, CredentialDetail, LearnerProfileDetail, PraktijkopleiderDetail, and BpvPlacementDetail against seeded objects
  - GIVEN CohortDetail (the densest page: talk + calendar + forms + polls) WHEN rendered THEN the page shows no horizontal overflow and all four leaf widgets are reachable
  - GIVEN a leaf NC app is disabled WHEN the corresponding page renders THEN the test tolerates the absent widget (provider `isEnabled()` behaviour) rather than failing
- [ ] Implement
- [ ] Test

### Task 4: Document the leaf surface (must / MVP)
- **spec_ref**: `openspec/specs/integration-leaves/spec.md#requirement-leaves-are-declared-not-coded-req-001`
- **files**: `docs/`, `CHANGELOG.md`
- **acceptance_criteria**:
  - GIVEN `docs/` WHEN read THEN it records the ON matrix (leaf × schema × page), the OFF list with reasons, and the rule that catalog definitions carry no leaves beyond `files`
  - GIVEN `CHANGELOG.md` WHEN read THEN it records the five new leaf types and names the pages gaining widgets
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate leaf-integrations --type change --strict` passes
- [ ] Manual testing against acceptance criteria (link an event, a contact, a form, a card, and a poll from the touched pages)
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)
- [ ] Browser tests (Playwright MCP): `tests/e2e/spec-coverage/integration-leaves.spec.ts` (Task 3)
- [ ] All tests pass; zero new failures vs a self-measured baseline
- PHPUnit: N/A — this change ships no PHP; the only leaf listener (`CohortTalkMembershipHandler`) predates it and is untouched.
- Newman/Postman: N/A — no HTTP endpoint is added; leaf data flows through OpenRegister's existing integrations API.

## Documentation (company-wide ADR-010)
- [ ] `docs/` records the leaf matrix and the OFF rationale (Task 4)
- [ ] Screenshots of SessionDetail and CohortDetail with the new widgets committed to `docs/images/`

## i18n (company-wide ADR-005)
- [ ] Widget titles ("Agenda", "Quick poll (not graded)", "Intake form", "Contact card", "Follow-ups") are new user-facing strings: `nl_NL` and `en_US` entries added through the manifest's i18n mechanism used by the existing widget titles
