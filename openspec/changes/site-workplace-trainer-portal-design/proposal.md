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

- **An overview** `poOverview`, "Overzicht": "Dit moet u nog doen", one card per student, and "Uw contact bij school".
- **A short menu**: Overzicht, Mijn studenten, Beoordelingen, Berichten. "Gegevens van het bedrijf" waits (see "Not in this change").
- **Student cards** show the placement period and the next step from data that exists: a POK to sign, or an assessment to write.
- **Dutch labels** for the whole manifest, through `PortalLabelTranslator`, with a short explanation of each school term on first use ("praktijkovereenkomst (POK): de afspraken tussen u, de student en school").

New work, clearly marked in the specs:

- **NEW: student names.** `poLearners` over `learner-profile`, through a forward join on her own placements (`bpv-placement.practicalTrainerId` to `learnerRef`), projecting `givenName` and `familyName` only.
- **NEW: her own assessments.** `poWerkprocesAssessments`, direct scope on `assessorId`, so she sees what she submitted and what is still a draft.
- **NEW: an assessment form in plain words.** The werkproces is picked from the student's own kwalificatiedossier by its label, not typed as a code. The form saves as a draft and can be finished later.

## Depends on

Portaliq (lane pq; referenced, not respecified):

- `site-mijn-omgeving-components`: task rows with "N dagen open", the student card with a progress figure, the contact card, the menu keys.
- `site-multi-step-forms`: the assessment form in steps, save and resume, the summary before sending.

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
