---
kind: code
---

# Proposal: action-matrix-set-by-admins

## Summary

learniq decides per action which Nextcloud groups may run it, and an administrator changes that from the admin settings. Both rows are built (ADR-023, `ActionAuthService`, `ActionMatrixController`, `ActionAuthMatrix.vue`), but the only requirement about it is in `compliance-audit`, and it only asks for a controller test. This change writes the behaviour down as an `action-authorization` capability.

## The rows this covers

Source: learniq `openspec/parity/capabilities.json`.

| row | capability | built |
|---|---|---|
| `gov-decide-who-may-do-what` | Decide per action which roles may run it. | `lib/actions.seed.json`, `lib/Service/ActionAuthService.php`, `lib/Controller/ActionMatrixController.php` |
| `gov-change-permissions-yourself` | Change those permissions yourself, without a developer. | `PUT /api/admin/action-matrix`, from Admin settings, Learniq, `src/components/admin/ActionAuthMatrix.vue` |

## What changes

1. A new capability `action-authorization` with two requirements.
2. `@spec` tags on the service, the controller and the admin component, beside the ADR-023 tag they carry today.

## Screens

LqBeheerRechten draws the matrix: see `screens.md`.

## Out of scope

- Which groups exist and who is in them: Nextcloud and `rbac-groups` own that.
- Field-level rights, decided no (`gov-hide-a-field-from-a-role`).
