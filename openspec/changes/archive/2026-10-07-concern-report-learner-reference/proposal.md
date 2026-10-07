# Proposal: a concern report may name the pupil it is about

## Why

Ruben decided on 7 October 2026 that a member of staff reports a concern from the pupil's page. The form opens there with the pupil already filled in. To hold that pupil, concern-report 0.2.0 (register 0.39.0) gained an optional `learnerId`, a reference to the learner profile. It shipped in learniq #1773.

The confidential-counsel spec still says a concern report "MUST NOT reference or be referenced by another schema". The code and the spec now disagree. This change brings the spec in line with the decision.

## What changes

- A concern report MAY reference the learner profile through `learnerId`. The field is optional and nullable, and it carries no `inversedBy`.
- `learnerId` is the only reference out. No other schema references a report.
- A staff report made from the pupil's page fills in `learnerId`. A report from the Report a concern page, or from a pupil's or guardian's start page, leaves the field out.
- Read access does not change. It stays with the confidential counsellors and the person who filed the report. The reference gives the pupil, the pupil's guardians and the pupil's teachers no access.

## Out of scope

- Any change to the code. The register, the overlays and their tests shipped in #1773.
- The menu requirement. Where the Report a concern entry sits per structure profile is not part of this decision.
