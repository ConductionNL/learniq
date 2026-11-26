# Proposal: trainer-saves-an-assessment-for-later

## Why

live-learniq-1 (25 Nov 2026, 20:08Z): the workplace trainer cannot save a werkproces assessment halfway (site-workplace-trainer-portal-design T9). `createWerkprocesAssessment` is endpoint-forward since an-invited-trainer-may-assess T2 (the assertion carries the sign-in level), and portaliq kept drafts only of create actions. Ruben decided (25 Nov 2026 21:50 CET): an endpoint-forward action may declare `draftSchema`; portaliq saves the draft there through OpenRegister, the endpoint receives the final body (portaliq endpoint-forward-draft-schema, ConductionNL/portaliq#2829).

## What changes

- New schema `werkproces-assessment-draft` (register 0.77.0): the form's fields plus `practicalTrainerId` and `organisation`, nothing required (OpenRegister excuses `required` in a draft anyway), hard delete, not searchable, its processing record under `learniq-work-placement`. Nothing in learniq reads or acts on it; OpenRegister keeps drafts to their owner.
- `createWerkprocesAssessment` declares `draftSchema: {register: learniq, schema: werkproces-assessment-draft}` beside its `draft: {retentionDays: 30}`. An older portaliq drops the key and offers no draft, as today.
- The real `werkproces-assessment` schema, its guards and its endpoint are unchanged: only the finished assessment reaches them.

## Out of scope

- The live walk (site-workplace-trainer-portal-design T9, mbo-trainer-flows "saving halfway"): the learniq live lane, once portaliq#2829 is on the instance.
