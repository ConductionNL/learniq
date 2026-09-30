# Proposal: an absence decision records who decided and when

## Why

Found testing a primary school end to end (2026-09-30). A guardian reported her child absent from the parent portal and the group teacher approved it in learniq. The guardian's portal list showed "approved" with an empty decision date, and the report did not say which teacher decided: `decidedBy` and `decidedAt` stayed empty. Nothing stamped them; the `approve` and `reject` transitions declared no action.

## What changes

- The `approve` and `reject` transitions of `ExcuseRequest` run the existing `StampTransitionActorAction` with `actorField: decidedBy` and `timeField: decidedAt`, the same action `ExternalTrainingRecord.verify` uses.
- `info.version` moves up one patch version so the register re-imports.
