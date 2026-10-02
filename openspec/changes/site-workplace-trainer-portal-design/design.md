# Design: site-workplace-trainer-portal-design

## Design of record

`LearniqTrainer.dc.html` in the portal-design canvas (artifact `3Jy3r5e5f9v9ktCLxisNG6`): the stageportaal of Esdoorn Techniek College, for workplace trainer Karin Smit of Installatiebedrijf Van Dam, with students Daan Visser and Lotte Bos.

## Mockup to declaration

| Mockup element | This change | Data |
|---|---|---|
| "Keur de uren van Daan goed" | not in this change | no hours record |
| "Vul de tussenbeoordeling van Lotte in" | task: an active placement without a submitted assessment in its period | `poBpvPlacements`, `poWerkprocesAssessments` (NEW) |
| Student card: name | `poLearners` (NEW) | `learner-profile.givenName`, `familyName` |
| Student card: programme | left out | `programmeId` only |
| Student card: "Stage-uren 312 van 640" | not in this change | no hours record |
| Student card: "Stageperiode" | shown | `periodFrom`, `periodTo` |
| Student card: "Volgende stap" | derived: sign the POK, or write an assessment | placement `lifecycle`, own assessments |
| "Bekijk het dossier van Daan" | the student's page: placement, her own assessments, shared portfolio entries | existing + NEW collections |
| "Uw contact bij school" | not in this change (decision) | `schoolCoachId`, deliberately not projected |

## The next step on a student card

Derived from data the trainer can already read, in this order:

1. The placement is `proposed` or `sbb-verification-pending` and she has no POK signature: "Onderteken de praktijkovereenkomst".
2. The placement is `active` and she has a `draft` assessment: "Maak de beoordeling af".
3. The placement is `active` and she has no `submitted` or `confirmed` assessment for it: "Vul een beoordeling in".
4. Otherwise no step.

The deadline "Voor 16 oktober" in the mockup has no source. The task shows how long the step has been open where a date exists (the placement's `periodFrom`), and no deadline otherwise.

Where the derivation runs is a build decision. Portaliq has no computed-field hook in the manifest. The likely shape is a learniq endpoint-forward listing the trainer's open steps, the pattern the student flows already use (`StudentFlowActions`). It stays server-side and takes the trainer from the stamped claim only.

## Student names (NEW)

```
poLearners
  schema: learner-profile     scopeField: id        scopeClaim: practicalTrainerId
  via: { schema: bpv-placement, scopeField: practicalTrainerId, targetField: learnerRef }
  fields: givenName, familyName
```

The forward join (the default `match`) keeps a learner-profile whose own id is in the set of `learnerRef` values of her placements. A placement in `terminated` should not reveal the name. The `via` join has no filter on the joined schema today. Same open point as the pupil timetable: portaliq's joined-schema filter, or accept that a terminated placement still shows a first and last name. The spec requires the filter.

Only names leave learniq. Birth date, address, medical data and contacts stay out.

## The assessment form

`createWerkprocesAssessment` keeps its fields and its server stamp of `assessorId`. Changes:

- `werkprocesCode`, `coreTaskCode`, `kwalificatiedossierCode` and `werkprocesLabel` are filled from one choice. The choices are the werkprocessen of the placement's kwalificatiedossier, shown by label. The trainer never types a code.
- `bpvPlacementId` is picked from her own placements by student name (`crossRefs`, as the guardian's child pick).
- `assessment` reads "Nog niet competent" or "Competent", with one line of explanation each.
- The form is in steps with save and resume (portaliq `site-multi-step-forms`). A saved form is a `draft` assessment. Sending moves it to `submitted`. The school's confirmation (`confirmed`) stays staff-only.

Continuing a draft needs an update action on her own `draft` rows (`rowWhen lifecycle in [draft]`), the pattern `cancelConferenceTime` uses. That action is part of this change.
