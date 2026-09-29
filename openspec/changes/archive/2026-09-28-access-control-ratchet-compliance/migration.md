# Migration: access-control-ratchet-compliance

## Current state
`LvsResult` and `OsoImportDossier` have no `authorization` block, so OpenRegister applies the register cascade. `LvsResult` and `FirstAidIncident` are `appendOnly`.

## Target state
The three schemas carry the blocks in design.md and neither of the two lifecycle schemas is append-only.

## Migration class
```
No Doctrine migration and no repair step.
The register import that runs on every app upgrade (InitializeSettings) re-imports the schemas
from lib/Settings/learniq_register.json; the schema versions move to 0.2.0 so the import updates them.
```

## Migration steps
1. Upgrade the app. The register import updates the three schemas.
2. Nothing else: no stored object changes shape, and no property is added or removed.

## Data impact
None. Rows keep every field. Who can read and write them changes the moment the schema is updated.

## Rollback
Revert the merge and upgrade again; the import restores the previous schemas.
