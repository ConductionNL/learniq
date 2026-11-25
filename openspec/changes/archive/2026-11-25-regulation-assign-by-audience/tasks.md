# Tasks: regulation-assign-by-audience

- [x] **T1**: `RegulationAudienceResolver::audienceValues()`, audience and due date on `RegulationAssignmentService::assign()`
  - PHPUnit `RegulationAssignByAudienceTest`
- [x] **T2**: `RegulationAssignPreview` and the preview route, gated on `regulation.assign`
  - PHPUnit `ComplianceRollupControllerTest`
- [x] **T3**: live: the group enrol dialog previews and enrols one team
  - Live 2026-11-25 on a sandbox instance (Nextcloud 34.0.6, PostgreSQL 16, OpenRegister main; corporate set), in a browser as admin: Regulations > "VCA, veiligheid, gezondheid en milieu" > Enrol a group > A team > Operatie/Werkplaats, due 2026-12-01. The preview read 14 in the audience, 0 already enrolled, 14 newly enrolled, with a row per employee; "Enrol 14 employees" created 14 mandatory enrolments in VCA Basis due 2026-12-01, and Operatie/Magazijn en logistiek stayed at 0 of 32. Two dialog defects found and fixed on the way (#this PR): the dialog asked for a preview with a kind and no audience, which the server refuses ("No preview. Unknown audience", empty list), and it offered "Enrol N" for a regulation without a mandatory course (NIS2), which enrols nobody.
