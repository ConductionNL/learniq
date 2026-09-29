# Tenants

Most learniq objects carry a `tenant_id`. Learniq uses it to keep one school's or company's records apart from another's on a shared instance: endpoints that take an object id from the caller only answer for objects in the caller's own tenant.

## Which tenant a user belongs to

Learniq resolves a user's tenant in one place, `CallerTenantResolver`:

1. If the user has a `tenant_id` setting for learniq, that is their tenant.
2. Otherwise they belong to the default tenant, `00000000-0000-4000-8000-000000000000`.

The default tenant is the one the example sets carry, so on an instance that serves one organisation every user, every example record and every record created in the app share it. You do not have to configure anything.

Every learniq path that stamps or checks a tenant uses this resolution: the create dialog in the app, xAPI statements and documents, course package and QTI imports, the course store, lesson onboarding and the learning record intake.

## Serving more than one organisation

On an instance that serves several organisations, give each user the tenant of their organisation. Pick one UUID per organisation and set it per user:

```bash
occ user:setting <uid> learniq tenant_id <tenant-uuid>
```

Check a user's binding with:

```bash
occ user:setting <uid> learniq tenant_id
```

To remove it, so the user falls back to the default tenant:

```bash
occ user:setting --delete <uid> learniq tenant_id
```

A binding changes which records the user can reach through tenant-scoped endpoints, so set it before the user starts working, and keep it the same afterwards.

## Before 2026-09-29

An unbound user used to get the Nextcloud instance id as their tenant. That value is not a UUID, so most schemas refused records carrying it, and it matched none of the example records. The repair step `MoveInstanceIdTenantToDefaultTenant` runs on upgrade and moves every record that still carries the instance id to the default tenant. It only touches those records, and running it again changes nothing.
