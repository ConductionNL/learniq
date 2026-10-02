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
| Student card: "Stage-uren 312 van 640" | not in this change: `progress` (REQ-SMO-028) needs a value and a total field, and no hours record exists | none |
| Student card: "Stageperiode" | shown, through lookups on `poBpvPlacements` | `periodFrom`, `periodTo` |
| Student card: "Volgende stap" | not on the card; a portal task (see "Open steps") | placement `lifecycle`, own assessments |
| "Bekijk het dossier van Daan" | the student's page: placement, her own assessments, shared portfolio entries | existing + NEW collections |
| "Uw contact bij school" | not in this change (decision) | `schoolCoachId`, deliberately not projected |

## The student cards

```
{ type: collection, collection: poLearners, display: cards, titleFields: [givenName, familyName],
  lookups: [
    { as: periodFrom, collection: poBpvPlacements, matchField: learnerRef, valueField: periodFrom },
    { as: periodTo,   collection: poBpvPlacements, matchField: learnerRef, valueField: periodTo } ] }
```

The cards run over `poLearners`, not over the placements. A lookup copies the `valueField` of the lookup row whose `matchField` holds the outer row's id. A placement's `learnerRef` holds the learner's id, so the period reaches the learner's card. The other way round, a placement card could not get the learner's name. A learner with two placements with her shows the first period found; that is rare and acceptable.

No `progress`: REQ-SMO-028 asks for a projected value field and total field ("120 van 400 uur"). Learniq has neither for BPV hours. When `bpv-hours-registration` adds them, the cards gain `progress` without other change.

## The next step

Derived from data the trainer can already read, in this order:

1. The placement is `proposed` or `sbb-verification-pending` and she has no POK signature: "Onderteken de praktijkovereenkomst".
2. The placement is `active` and she has no `submitted` or `confirmed` assessment for it: "Vul een beoordeling in".
3. Otherwise no step.

The deadline "Voor 16 oktober" in the mockup has no source. A step carries no deadline.

## Open steps

The mockup's "Dit moet u nog doen" is a derived list: no collection holds "an assessment to write". Portaliq's `tasks` block needs a collection with a `dueField` (REQ-SMO-021), so it cannot show a derived list. Portaliq's `/mijn` home does show open portal tasks first (`site-mijn-omgeving-components` D4), from OpenRegister's portal-task seam (`PortalTaskGateway`).

So the open steps are portal tasks. Learniq raises a portal task for the trainer when a step opens (a placement moves to `active` without her assessment, a POK waits for her signature) and completes it when the step is done. That uses an existing seam, not a new key. Whether a praktijkopleider portal subject can be addressed by that seam is to be checked at build time. If it cannot, the overview shows the student cards without a task list, and this is named in the PR.

## Student names (NEW)

```
poLearners
  schema: learner-profile     scopeField: id        scopeClaim: practicalTrainerId
  via: { schema: bpv-placement, scopeField: practicalTrainerId, targetField: learnerRef,
         when: { field: lifecycle, in: [proposed, sbb-verification-pending, confirmed, active, completed] } }
  fields: givenName, familyName
```

The forward join (the default `match`) keeps a learner-profile whose own id is in the set of `learnerRef` values of her placements. A placement in `terminated` must not reveal the name. Portaliq's `via.when` (REQ-SMO-023) does that: the join lists every placement state except `terminated`. A malformed `when` fails the join closed.

Only names leave learniq. Birth date, address, medical data and contacts stay out.

## The assessment form

`createWerkprocesAssessment` keeps its fields and its server stamp of `assessorId`. Changes:

- `werkprocesCode`, `coreTaskCode`, `kwalificatiedossierCode` and `werkprocesLabel` are filled from one choice. The choices are the werkprocessen of the placement's kwalificatiedossier, shown by label. The trainer never types a code.
- `bpvPlacementId` is picked from her own placements by student name (`crossRefs`, as the guardian's child pick).
- `assessment` reads "Nog niet competent" or "Competent", with one line of explanation each.
- The form declares `steps`, each with a `description` in plain words: "Welke student en welk werkproces?", "Uw oordeel", "Toelichting", then a step with `review: true`.
- `assessment` uses `widget: choices`: two cards, "Nog niet competent" and "Competent", with one line of explanation each.
- `draft: { retentionDays: 30 }`: portaliq keeps her answers in its own `portalDraft`, scoped to her, until she sends or the draft expires. Learniq writes no `draft` assessment from the portal. Sending creates the assessment as `submitted`. The school's confirmation (`confirmed`) stays staff-only.
- `confirmation: { title: "Uw beoordeling is verstuurd", body: "De begeleider van school ziet uw beoordeling nu." }`.

A file answer is not kept in a portaliq draft. The assessment form has no file field, so that limit does not bite here.
