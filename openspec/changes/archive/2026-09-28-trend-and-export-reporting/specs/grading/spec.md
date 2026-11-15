# grading Specification

## ADDED Requirements

### Requirement: GradeScale declares DLE and leerrendement as scale kinds, for later LVS data

`GradeScale.kind` MUST gain two additional enum values, `dle` (didactische leeftijd) and
`leerrendement`, alongside the existing `numeric`/`letter`/`ects`/`pass-fail`/`percentage`/`band`
values — a vocabulary declaration only, with no calculation attached, so the not-yet-built
`lvs-import-contract` change (tier B) has a scale kind to attach imported LVS results to rather
than inventing one alongside its own, larger scope.

#### Scenario: A GradeScale can be declared with the new kind values

<!-- @e2e exclude Pure OpenRegister enum declaration; no scholiq DOM surface for schema registration itself, covered by PHPUnit GradeScaleDleLeerrendementRegisterTest mirroring the established `*RegisterTest` convention. -->

- **GIVEN** the `grading` schemas are registered
- **WHEN** a `GradeScale` is created with `kind: "dle"` and another with `kind: "leerrendement"`
- **THEN** both persist as valid OpenRegister objects
