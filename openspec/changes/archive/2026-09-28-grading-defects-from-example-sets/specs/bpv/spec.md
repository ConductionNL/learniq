# BPV: werkproces code resolution within the dossier delta

## ADDED Requirements

### Requirement: A werkproces code resolves inside the assessment's own kwalificatiedossier

Server-side resolution of `WerkprocesAssessment.competencyId` MUST look for the `werkprocesCode` inside the `sbb-kwalificatiedossier` CompetencyFramework whose `sourceRef` equals the assessment's `kwalificatiedossierCode`, in the assessment's tenant. When no framework carries that dossier code, resolution MAY consider every SBB framework of the tenant, but MUST resolve only when exactly one framework has a Competency with that code. When the code is found in more than one framework, or in none, `competencyId` MUST stay `null` and the miss MUST be logged. Resolution MUST NOT take the first of several matches.

#### Scenario: A repeated code resolves to the assessment's own dossier

- **GIVEN** SBB frameworks with `sourceRef` `90201` and `90302`, each with a Competency coded `B1-K1-W1`
- **WHEN** a WerkprocesAssessment with `kwalificatiedossierCode: "90302"` and `werkprocesCode: "B1-K1-W1"` is created
- **THEN** its `competencyId` is the Competency under framework `90302`

#### Scenario: An ambiguous code stays unresolved

- **GIVEN** two SBB frameworks without a `sourceRef` matching the assessment, each with a Competency coded `B1-K1-W1`
- **WHEN** a WerkprocesAssessment with `werkprocesCode: "B1-K1-W1"` is created
- **THEN** `competencyId` stays `null`
- **AND** the assessment is saved and confirmable as before

#### Scenario: A code only one framework knows still resolves

- **GIVEN** no framework carries the assessment's dossier code, and exactly one SBB framework has a Competency coded `B1-K3-W2`
- **WHEN** a WerkprocesAssessment with `werkprocesCode: "B1-K3-W2"` is created
- **THEN** its `competencyId` is that Competency
