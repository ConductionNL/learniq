# Review: leaf-integrations

Reviewer: r5 spec-review lane, 2026-09-29, against `origin/development` at the branch point of `chore/r5-spec-reviews`.
Scope: every requirement and scenario in `specs/integration-leaves/spec.md` (6 requirements, 8 scenarios).

## What was checked

- The register: every schema carrying `linkedTypes` in `lib/Settings/learniq_register.json`. Exactly seven: Credential (`calendar`, line 1067), LearnerProfile (`contacts`, 5352), Cohort (`talk`, 6230), Session (`talk`, `calendar`, 6842), Assignment (`calendar`, `forms`, 8524), Praktijkopleider (`contacts`, 19368), BpvPlacement (`deck`, 19614).
- The manifest: every `"type": "integration"` widget in `src/manifest.d/*.json`. Beyond `files` and the two pre-existing `talk` widgets there are six leaf widgets, each with `requiredApp` equal to its `integrationId`: `sess-calendar` (learning.json:3030), `asn-calendar` (learning.json:4009), `asn-intake-form` (learning.json:4017), `cred-calendar` (people.json:589), `lp-contact` (people.json:2044), `po-contact` (work-placement.json:524), `bpv-deck` (work-placement.json:374).
- `lib/`: no `IntegrationProvider` implementation and no `addProvider(` call. The only leaf listener is the pre-existing `lib/Listener/CohortTalkMembershipHandler.php`.

## Gap found and fixed in this PR

The static scenarios (the surface is enumerable, courses carry no calendar leaf, no polls leaf) were marked `@e2e exclude` as "covered by the task acceptance criteria grep". A grep in an acceptance criterion runs once, at build time, and nothing re-runs it: a leaf added or moved later would pass every gate. This PR adds `tests/Unit/Settings/IntegrationLeavesRegisterTest.php`, which pins the exact `linkedTypes` matrix, the exact leaf widgets per page, that each widget's leaf is declared on the page's schema, `requiredApp` on the four new leaf types, no leaf on catalogue definitions beyond `files`, no polls anywhere, and no provider code in `lib/`.

## Review table

| Requirement / scenario | Implementing file:line | Test file::method | Verdict |
|---|---|---|---|
| REQ-001 Leaves are declared, not coded | `lib/Settings/learniq_register.json` (seven `linkedTypes` lines above); `src/manifest.d/*.json` widgets above; no provider in `lib/` | `tests/Unit/Settings/IntegrationLeavesRegisterTest.php::testTheRegisterDeclaresExactlyTheAgreedLeaves`, `::testTheManifestDrawsExactlyTheAgreedLeaves`, `::testLibShipsNoIntegrationProvider` | MET (test added in this PR) |
| Scenario: The leaf surface is enumerable from two files | same | `IntegrationLeavesRegisterTest::testTheRegisterDeclaresExactlyTheAgreedLeaves`, `::testTheManifestDrawsExactlyTheAgreedLeaves`, `::testLibShipsNoIntegrationProvider` | MET (test added in this PR) |
| Scenario: An unknown leaf id fails the import loudly | OpenRegister `Schema::validateLinkedTypesValue()`; learniq supplies only `talk`, `calendar`, `forms`, `contacts`, `deck` | OpenRegister's own suite (learniq ships no validation code) | MET (upstream-owned; learniq's ids are all served providers) |
| REQ-002 Calendar leaves on Session, Assignment and Credential | register lines 6842, 8524, 1067; widgets `sess-calendar`, `asn-calendar`, `cred-calendar`; Cohort has only `talk` | `IntegrationLeavesRegisterTest::testTheRegisterDeclaresExactlyTheAgreedLeaves`, `::testTheManifestDrawsExactlyTheAgreedLeaves`; `tests/e2e/spec-coverage/integration-leaves.spec.ts` (SessionDetail, AssignmentDetail, CredentialDetail cases) | MET |
| Scenario: A teacher links a renewal event to an expiring credential | `people.json:589` `cred-calendar` | `tests/e2e/spec-coverage/integration-leaves.spec.ts` "CredentialDetail carries its leaf widgets" | MET, with a note: the e2e proves the widget is drawn and follows the app state; the link-and-reappear round trip is the OpenRegister calendar provider's behaviour and is not exercised by a learniq test |
| Scenario: Courses carry no calendar leaf | Course has no `linkedTypes`; CourseDetail has only `course-materials` (files) | `IntegrationLeavesRegisterTest::testCatalogueDefinitionsCarryNoLeaf` | MET (test added in this PR) |
| REQ-003 Contacts leaves link, never copy | register lines 5352, 19368; widgets `lp-contact`, `po-contact`; no learniq code reads a contact card (no listener, no provider) | `IntegrationLeavesRegisterTest::testTheRegisterDeclaresExactlyTheAgreedLeaves`, `::testLibShipsNoIntegrationProvider`; e2e LearnerProfileDetail and PraktijkopleiderDetail cases | MET |
| Scenario: A BPV coordinator links the practical trainer's contact card | `work-placement.json:524` `po-contact` | `integration-leaves.spec.ts` "PraktijkopleiderDetail carries its leaf widgets" | MET, same note as the credential scenario: "properties unchanged" holds by construction (learniq has no code path that writes from a card) |
| Scenario: No leaf renders on an object the caller may not read | OpenRegister RBAC on the object read; the detail page draws widgets only after the object loads | none in learniq (excluded in the spec as upstream) | MET (upstream-owned, by construction) |
| REQ-004 Forms leaf on Assignment | register line 8524; widget `asn-intake-form` (learning.json:4017) next to `asn-files`; Cohort has no `forms` | `IntegrationLeavesRegisterTest::testTheRegisterDeclaresExactlyTheAgreedLeaves`, `::testTheManifestDrawsExactlyTheAgreedLeaves`; e2e AssignmentDetail case | MET |
| Scenario: An assignment gains a structured intake form | `learning.json:4017` | `integration-leaves.spec.ts` "AssignmentDetail carries its leaf widgets" | MET (widget presence; link round trip upstream, as above) |
| REQ-005 Deck leaf on BpvPlacement | register line 19614; widget `bpv-deck` (work-placement.json:374); no learniq code reads card state | `IntegrationLeavesRegisterTest` (matrix tests); e2e BpvPlacementDetail case | MET |
| Scenario: A school coach tracks a placement chase as a card | `work-placement.json:374` | `integration-leaves.spec.ts` "BpvPlacementDetail carries its leaf widgets" | MET ("completing the card does not change the lifecycle" holds by construction: nothing in `lib/` listens to Deck) |
| REQ-006 Learniq declares no polls leaf | no `polls` in the register or any manifest fragment | `IntegrationLeavesRegisterTest::testTheRegisterDeclaresExactlyTheAgreedLeaves`, `::testTheManifestDrawsExactlyTheAgreedLeaves` | MET (test added in this PR) |
| Scenario: The register derives no polls surface | same | `IntegrationLeavesRegisterTest::testTheRegisterDeclaresExactlyTheAgreedLeaves` | MET (test added in this PR) |

## Totals

6 requirements, 8 scenarios: 14 rows MET (4 of them only through the test added in this PR), 0 PARTIAL, 0 NOT MET, 0 SUPERSEDED.

## Observations (no action in this PR)

- tasks.md Task 1 to 3 acceptance criteria still describe the August scope (8 schemas, 12 widgets, polls, `scholiq_register.json`); the Round 5 note at the top explains the narrowing. The spec delta is the current contract and matches the code.
- On LearnerProfileDetail the contacts leaf shows the registry label "Contacts" instead of the manifest title "Contact card" (recorded in tasks.md by the screenshot lane). That is the nextcloud-vue contacts leaf ignoring the title, not a learniq declaration fault.
