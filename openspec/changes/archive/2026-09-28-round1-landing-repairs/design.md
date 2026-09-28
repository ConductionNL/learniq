# Design: round1-landing-repairs

## Architecture overview

Repairs only. Every item comes from PR #1006, checked against `development` at `a84b6273`; items development already fixed are left out.

| #1006 item | Still needed | Done here |
|---|---|---|
| `thresholdCrossed` duplicate recipient | yes | one entry; test refuses a group named twice |
| `DataExchangeJob.target`, `DataMappingProfile.target` | yes | #1006 text, em-dashes replaced, "Scholiq implements" read as "learniq implements" |
| exact counts 12 and 16 | yes | floors |
| Cohort seed "Groep 5/6" twice | no: seeds moved to `po.json` (#1031), one row there | nothing |
| Cohort seed index test | no: already by name | nothing |
| registrar split | yes: phpmd flags `CaseListenerRegistrar` (13) | `TransitionBridgeListenerRegistrar` |
| register version | yes | `0.29.1` |

Added beyond #1006: three tests that read `x-openregister-seed[0]` now look the row up by id, the rule LANE-RULES-R2 sets for seed tests.

## Decisions

### D1. Take #1006's descriptions, not a rewrite
The brief names #1006 as the source of the exact values. The descriptions stay long and technical; the em-dashes go, per the copy rules. A rewrite into short helper text with the rationale in `x-notes` would be better for the form, and is left for the schema-copy pass that owns every description.

### D2. One registrar for both transition bridges
Both listeners answer a transition by creating a follow-up object. Moving only the school-advice bridge would clear phpmd today; moving both keeps #1006's design and leaves the scheduling registrar room for its next listener.

## Mixed-spec rationale
The PHP part is one new registrar with two existing registrations and two removals, coupled to nothing in the register patch. It is kept in this change because #1006 carried it and the brief asks for the still-needed parts of #1006 in one PR.

## Declarative-vs-imperative decision

| Behaviour | Path | Rationale |
|---|---|---|
| Threshold notification recipients | declarative `x-openregister-notifications` | unchanged path, one entry removed |
| Listener wiring | imperative registrar (ADR-031 exception: the two bridges are cross-object writes) | existing listeners, new home |

## Security considerations
No security impact. No access rule changes; the notification reaches the same groups, once.

## Seed data
No seed row changes. The seed tests change how they read rows, not the rows.

## Trade-offs
Keeping #1006's long descriptions favours traceability over form copy (D1).
