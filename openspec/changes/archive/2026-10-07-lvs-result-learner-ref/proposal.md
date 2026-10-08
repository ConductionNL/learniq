# Proposal: an LVS result names its pupil by learner profile

## Why

Found testing a primary school end to end (2026-10-01). The Cito and doorstroomtoets results in the primary-school example set reach their pupil only through `learnerId`, the Nextcloud user id. Every other pupil record in that set (enrolment, attendance, report card) also carries `learnerRef`, the LearnerProfile UUID that the portal-identity change made the domain reference to a pupil. `LvsResult` never got the field, so nothing could follow a result to the profile.

Live data had the same gap. The import landing for an integriq `lvs-results` job and a result entered by hand both write only `learnerId`.

## What changes

- `LvsResult` gains `learnerRef`: a string, format uuid, `$ref` LearnerProfile, nullable and optional. Authorization, the read rule and portal exposure stay as they are and keep scoping on `learnerId`.
- `LvsResultLearnerRefStamp` derives `learnerRef` from `learnerId` on every create and update, the way `GradeEntryLearnerRefStamp` does for grades. It looks the profile up in the tenant the result carries, because the import runs in a background job without a session. A value sent by the client is ignored; a learner without a profile gets null; the stamp never blocks a write.
- `BackfillLvsResultLearnerRef` (post-migration repair step) stamps existing results once.
- `scripts/example-sets/po.py` sets `learnerRef` on its 822 LVS results, and `lib/Settings/profiles/po.json` is regenerated. No other example set ships LVS results.
- The field's description gets a catalogue key with a Dutch translation.
- `info.version` moves up one patch version so the register re-imports.

## Out of scope

- Showing LVS results in the portal. The portal reads stay as they are.
- The placeholder LVS rows in `learniq_mock_register.json`: they name no real learner profile, so there is nothing to link.
