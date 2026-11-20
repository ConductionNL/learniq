---
kind: spec
depends_on: []
---

# Every user can read the AI disclosure, and admins edit it

## Why

Row `gov-show-users-the-ai-disclosure` says users see one page naming every AI feature and where it runs. The spec says otherwise: the disclosure page is for `admin` and `compliance-officer` only, and its navigation entry is hidden from everyone else (`openspec/specs/ai-locality-guarantee`, REQ "compose an AI-processing disclosure" and REQ "registered and reachable"). A student or teacher whose work an AI feature reads cannot find out that it does, or where. Ruben decided on 12 November 2026 (decision 399): every user can read the disclosure, admins edit it.

The endpoint already answers any signed-in user (`lib/Controller/AiProcessingDisclosureController.php:139`, `#[NoAdminRequired]`, only a session check); the gate is the navigation entry. What the disclosure says is composed only from Hermiq's feature register and the locality verdict, so a school cannot add its own explanation or a contact for questions.

### Matrix rows (learniq `openspec/parity/capabilities.json`)

- `gov-show-users-the-ai-disclosure` Show users one page naming every AI feature and its locality. State `specified`, plan this change.

## What changes

- Every signed-in user reads the disclosure page and finds it in the navigation. The composed facts stay as they are: features, processing activity fields, locality verdict with its three states.
- An admin (or compliance officer) edits the school's own part of the disclosure: an introduction, a contact for questions, and a plain-language explanation per feature. Readers see the edited text beside the composed facts; they cannot edit.
- The verdict badges cannot be edited by anyone; they come from the classifier.

## Out of scope

- A public, signed-out version of the page.
- Changing how locality is classified.

## Impact

- `src/manifest.json`: the disclosure navigation entry loses its role gate.
- `src/views/LearniqAiProcessingDisclosure.vue`: reader view for everyone, edit mode for admins.
- A small OpenRegister object for the school's disclosure text, written only by admin or compliance officer.
