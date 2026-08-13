// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Small OpenRegister REST helpers for the e2e specs.
 *
 * These exist so a spec can navigate to a REAL object instead of a
 * deliberately non-existent id. That distinction matters more than it looks:
 * CnDetailPage's object store `console.error`s on a 404
 * (`Error fetching <type>/<id>`), so a spec that asserts "the page rendered
 * with no console errors" while pointing at
 * `00000000-0000-0000-0000-000000000000` is asserting two things that cannot
 * both hold. Pointing it at a real row makes the assertion coherent AND
 * strictly stronger: the page now has to render actual data, not the
 * all-`—` placeholder grid an unresolved id produces.
 *
 * Everything here goes through `page.request`, so it reuses the authenticated
 * storageState the global setup wrote — no second login.
 */
import type { Page } from '@playwright/test'

/** The scholiq register slug, as imported by `ci-seed.sh` / `seed-example-data.mjs`. */
export const REGISTER_SLUG = 'scholiq'

/**
 * The tenant UUID `seed-example-data.mjs` stamps on every row it creates, used
 * as the fallback when the active organisation cannot be discovered. Kept
 * byte-identical to the seeder's constant so fixtures created here are
 * coherent with the seeded dataset.
 */
const FALLBACK_TENANT = '00000000-0000-0000-0000-00000000d3a0'

let cachedTenant: string | null = null

/**
 * The tenant UUID to stamp on a fixture row.
 *
 * Mirrors `seed-example-data.mjs`: prefer OpenRegister's active organisation,
 * fall back to the seeder's fixed demo UUID.
 *
 * @param page An authenticated Playwright page.
 * @return A UUID string.
 */
export async function seededTenantId(page: Page): Promise<string> {
	if (cachedTenant) return cachedTenant
	try {
		const resp = await page.request.get(
			'/index.php/apps/openregister/api/registers?limit=1',
		)
		if (resp.ok()) {
			const body = await resp.json()
			const items =
				body?.results ?? body?.data ?? (Array.isArray(body) ? body : [])
			const org = items?.[0]?.organisation
			if (typeof org === 'string' && /^[0-9a-f-]{36}$/i.test(org)) {
				cachedTenant = org
				return cachedTenant
			}
		}
	} catch {
		// fall through to the seeder's fixed demo tenant
	}
	cachedTenant = FALLBACK_TENANT
	return cachedTenant
}

/**
 * The uuid of an existing object of `slug`, or `null` when the collection is
 * empty / the schema did not import.
 *
 * @param page An authenticated Playwright page.
 * @param slug The schema slug (e.g. `accessibility-limitation`).
 * @return The object uuid, or null.
 */
export async function firstObjectId(
	page: Page,
	slug: string,
): Promise<string | null> {
	const resp = await page.request.get(
		`/index.php/apps/openregister/api/objects/${REGISTER_SLUG}/${slug}?_limit=1`,
	)
	if (!resp.ok()) return null
	const body = await resp.json().catch(() => null)
	const items = body?.results ?? body?.data ?? (Array.isArray(body) ? body : [])
	const first = items?.[0]
	const id = first?.['@self']?.uuid ?? first?.uuid ?? first?.id
	return typeof id === 'string' ? id : null
}

/**
 * Create one object of `slug` and return its uuid.
 *
 * ⚠️ Returns `null` rather than throwing when the create is refused — the
 * caller decides whether that is fatal. A schema that failed to import
 * (openregister#1487) and a genuinely broken create look different at the
 * call site and should be reported differently.
 *
 * @param page An authenticated Playwright page.
 * @param slug The schema slug.
 * @param body The object payload (`tenant_id` is filled in when absent).
 * @return The created object's uuid, or null.
 */
export async function createObject(
	page: Page,
	slug: string,
	body: Record<string, unknown>,
): Promise<string | null> {
	const payload = { tenant_id: await seededTenantId(page), ...body }
	const resp = await page.request.post(
		`/index.php/apps/openregister/api/objects/${REGISTER_SLUG}/${slug}`,
		{ data: payload },
	)
	if (!resp.ok()) return null
	const created = await resp.json().catch(() => null)
	const id = created?.['@self']?.uuid ?? created?.uuid ?? created?.id
	return typeof id === 'string' ? id : null
}

/**
 * The uuid of an existing object of `slug`, creating one from `body` when the
 * collection is empty.
 *
 * @param page An authenticated Playwright page.
 * @param slug The schema slug.
 * @param body The payload to create with when nothing exists yet.
 * @return The object uuid, or null when the schema is absent and the create failed.
 */
export async function ensureObject(
	page: Page,
	slug: string,
	body: Record<string, unknown>,
): Promise<string | null> {
	return (
		(await firstObjectId(page, slug)) ?? (await createObject(page, slug, body))
	)
}
