---
kind: config
depends_on:
  - segment-wizard-choice
  - segment-example-datasets-po
---

# Proposal: segment-example-datasets-training

## Summary
The training institute example set: `lib/Settings/profiles/training.json`, one fictional institute (Voorbeeld Opleidingscentrum Het Kompas in the fictional town of Kompasveen) through the complete 2025-2026 year. One location, a public catalogue of fourteen courses with prices, 40 open editions and a leadership programme with two starts, 150 participants from six client companies and ten private individuals, trainers on the staff, a morning and an afternoon session per training day with every participant marked, knowledge tests with resits, signed attestations, certificates with expiry and renewal, an intake with a waiting list, quarterly course evaluations with quality scores and improvement actions, and the course package imports and export round trips: 3831 objects that agree with each other.

## Motivation
Decision D21 (Ruben, 2026-09-27): six example sets, one lane per set; this lane builds the training institute one, the sixth segment `LearniqSettings.segment` gained for it. Recon A section 1 found no training-institute data anywhere in learniq: the one generated demo register mixes primary school, MBO, higher education and corporate schemas with placeholder values ("Voorbeeld Reporteruserid 1"), so an institute that runs open courses for client companies cannot see its own day in the app. Recon A section 1 also lists what a set needs per organisation kind; for a training institute the brief names a public catalogue with open enrolment, participants from several client companies and no guardians, trainers, attendance and attestations, certificates, evaluations and quality reports, waiting lists and exported course packages.

Evidence: market (recon A section 2): Moodle's demo is one canned school, "Mount Orange School"; no competitor offers an example set per organisation kind, which is the gap D21 closes. Decision D7 keeps course package import and export in learniq, and decision D19 keeps FeeItem, so both appear in the set; the schemas those decisions retire do not.

## Affected Projects
- [x] Project: `learniq`: new `lib/Settings/profiles/training.json` and its generator `scripts/example-sets/training.py`; a new content test `TrainingExampleSetTest`; one catalogue key pair for the wizard card.

## Scope

### In Scope
- The set, written against `openspec/changes/segment-wizard-choice/contract.md` and passing `ExampleSetDescriptorContractTest`.
- A deterministic generator (`python3 scripts/example-sets/training.py`, `--check` for CI and review), so the thousands of objects stay consistent and a reviewer reads rules instead of JSON.
- `TrainingExampleSetTest`: the set's promises and its internal consistency.
- The card copy ("Training institute" already exists; the description is new) in `l10n/en.json` and `l10n/nl.json`.

### Out of Scope
- The other five sets (sibling lanes).
- `DataExchangeJob` (D7 moves data exchange to integriq), `Order`, `OrderLine`, `PaymentTransaction` and `Entitlement` (D19 retires the first three; an Entitlement needs an OrderLine).
- `Regulation` objects: the schema's own `slug` property must match `^[A-Z0-9_-]+$`, which the contract's lowercase envelope slug can never satisfy. Courses and attestations carry the regulation as a plain slug, as the schema allows.
- A live import and purge on an instance (lanes keep off the shared instance).

## Approach
Generate, do not hand-write. One Python script lays out the catalogue, spreads each client's bookings over its staff, places every edition on open days without double-booking a trainer or a room, marks every participant per session, rebooks whoever missed part of a certificate course into a later edition with room, runs the knowledge tests and resits, and derives certificates, attestations, evaluation invitations, anonymous responses and quality scores from what happened. Output is strict JSON with one object per line, like the primary school set.

## New Dependencies
None. The generator uses the Python standard library only.

## Impact
- `lib/Settings/profiles/training.json` (new, about 3 MB, one object per line).
- `tests/Unit/Settings/TrainingExampleSetTest.php` (new).
- `l10n/en.json`, `l10n/nl.json` and their built `.js`: one key.
- No register, schema, PHP or manifest change.

## Cross-Project Dependencies
None. Stacked on learniq #1031 (`segment-example-datasets-po`), which is stacked on #1028 and #1022 (`segment-wizard-choice` and its base).

## Risks

### Risk 1: loading takes minutes
**Severity:** Medium. **Mitigation:** 3831 objects in one wizard request. Seeding runs as a system operation without lifecycle listeners, and the import is idempotent by uuid, so a timed-out request finishes on a second run. The card shows the object count. Measured on a live instance: not in this lane.

### Risk 2: a schema change later invalidates objects
**Severity:** Medium. **Mitigation:** the contract test validates every object against its schema on every PR, so a schema change that breaks the set fails in that PR; the generator is where the fix goes.

### Risk 3: a name resembles a real person or company
**Severity:** Low. **Mitigation:** surnames are invented compounds (Ankerstede, Getijwerf), every company carries the invented town name Kompasveen, postcodes start with 0, web addresses end in `.example`, IP addresses come from the documentation ranges, and signatures read "voorbeeld". No BSN, no ECK iD.

## Rollback Strategy
Revert the PR: the set disappears from the wizard. An instance that loaded the set removes it first with `occ learniq:example-set:remove training --apply`.

## Open Questions
None.
