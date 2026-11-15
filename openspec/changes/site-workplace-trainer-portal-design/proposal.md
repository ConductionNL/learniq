---
kind: code
depends_on: [site-guardian-portal-design]
---

# Proposal: site-workplace-trainer-portal-design

## Why

Ruben approved the workplace trainer's overview, `LearniqTrainer.dc.html`, on 2026-10-02. The persona is `karin-smit` in hydra `personas/`: office manager and praktijkopleider at an installation company. She uses the stageportaal a few minutes a week, on a desktop, and does not know the school's words.

What the `praktijkopleider` audience offers today (`PortalContributionProvider::practicalTrainerContribution()`):

- `poBpvPlacements`: her own placements, direct scope on `practicalTrainerId`, with `learnerRef`, `curriculumPlanId`, `trainingCompanyName`, `periodFrom`, `periodTo`, `lifecycle`. No student name.
- `poSharedPortfolios`: active portfolio shares to her. Pointers only (`portfolioId`, `entryIds`), not the content.
- `createWerkprocesAssessment`: a werkproces assessment, `minTrust: substantial`. The form asks for codes (`kwalificatiedossierCode`, `coreTaskCode`, `werkprocesCode`) a trainer does not know.
- `signPraktijkovereenkomst`: a POK signature.
- No pages, no labels in Dutch, no read collection of the assessments she wrote.

The mockup assumes more than that. It shows hours to approve, hours done against a target, an interim assessment that saves halfway, the school supervisor's name and phone, and a planned visit.

## What changes

Existing data, new declarations:

- **An overview** `poOverview`, "Overzicht", with `home: true`: one card per student. "Dit moet u nog doen" is portaliq's own list of open portal tasks on `/mijn` (see "Open steps" in the design). "Uw contact bij school" waits (see "Not in this change").
- **A short menu** under `group: Mijn omgeving`: Overzicht, Mijn studenten, Beoordelingen. Berichten is portaliq's own inbox entry. The default collection pages get `menu: false`. "Gegevens van het bedrijf" waits (see "Not in this change").
- **Student cards** (`display: cards` over `poLearners`) show the name and the placement period. The next step is not on the card: a card shows row fields, and the step is derived. It reaches her as a portal task instead.
- **Dutch labels** for the whole manifest, through `PortalLabelTranslator`, with a short explanation of each school term on first use ("praktijkovereenkomst (POK): de afspraken tussen u, de student en school").

New work, clearly marked in the specs:

- **NEW: student names.** `poLearners` over `learner-profile`, through a forward join on her own placements (`bpv-placement.practicalTrainerId` to `learnerRef`), projecting `givenName` and `familyName` only.
- **NEW: her own assessments.** `poWerkprocesAssessments`, direct scope on `assessorId`, so she sees what she submitted.
- **NEW: an assessment form in plain words.** The werkproces is picked from the student's own kwalificatiedossier by its label, not typed as a code. The form runs in steps with a review step, and keeps her answers as a portaliq draft (`draft: { retentionDays: 30 }`) so she can finish later.

## Depends on

Portaliq (lane pq; referenced, not respecified):

- `site-mijn-omgeving-components` (portaliq PR #1110): the page keys `group`, `menu: false` and `home: true`; the `/mijn` home that lists open portal tasks first; `via.when`, so a terminated placement grants no name (REQ-SMO-023).
- `site-multi-step-forms` (portaliq PR #1110): the action keys `steps` (with `description` per step), a step with `review: true`, `draft: { retentionDays }` and `confirmation: { title, body, next }`; `fieldConfigs.widget: choices` for the judgement.
- `display: cards` on a `collection` block (REQ-SMO-028) for the student cards. Its `progress` figure is not declared: it needs a value field and a total field, and no BPV hours record exists (see "Not in this change").

Learniq:

- `site-guardian-portal-design` for the menu and block keys it asks portaliq for.

## Not in this change

- **Hours approval** ("Keur de uren van Daan goed", "Week 39: 32 uur") **and hours done** ("312 van 640"). No schema holds BPV hours. `BpvPlacement` has a period, not a norm or a log. Hours need a record per student per week, a target per placement, a staff screen and a rule for who may approve. That is a domain change in `bpv`, proposed as `bpv-hours-registration`.
- **The school supervisor's name and phone** ("Hans Meijer begeleidt beide studenten"). `schoolCoachId` is dropped from the trainer's projection on purpose, as internal staff identity (`bpv` spec, "field-projected"). Showing it is a decision for Ruben. If he says yes, the pattern exists: `render: user` shows a name and never the id, as `decidedBy` does for guardians.
- **The planned school visit** ("komt op dinsdag 13 oktober langs"). `bpv-visit-report` is a report written after a visit, with a staff narrative. No record holds a planned visit.
- **The programme name** ("Monteur elektrotechnische installaties, jaar 2"). The placement holds `programmeId` and `curriculumPlanId`. A readable copy is possible, like `courseName` on a grade, but no screen needs it yet beyond this card. Left for the build to decide; the card shows the period without it.
- **"Gegevens van het bedrijf."** The trainer's own `praktijkopleider` record is not a portal collection. A read and an update of her own record need a scope on the record's own id. Left out until portaliq confirms that scope.
- **Messages.** Portaliq owns the inbox and conversations.

## Impact

- `lib/Portal/PortalContributionProvider.php`, a new `lib/Portal/TrainerPortalPages.php`, `lib/Portal/PortalLabelTranslator.php`, `l10n/nl.json`.
- The werkproces options come from the resolver behind the `bpv` requirement "A werkproces code resolves inside the assessment's own kwalificatiedossier".
- No register change.
