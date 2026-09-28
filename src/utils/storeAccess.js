// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Store access for the page (store-rights-for-teachers, D27).
 *
 * PageController provides `storeAccess` ({install, publish}) as initial
 * state, resolved from learniq's ADR-023 matrix and the store plane. The
 * manifest cannot compute a per-user answer itself (its sentinel vocabulary has
 * no per-user token), so boot writes the answer into the config of every
 * `type: "store"` page, where CnPageRenderer passes it to CnStorePage as the
 * `canInstall` and `canPublish` props (nextcloud-vue #1268).
 *
 * A CnStorePage that predates those props ignores the keys, so Install stays
 * administrator-only until a release carrying #1268 reaches the lockfile.
 * These flags only decide what renders; every store endpoint checks the same
 * rights itself.
 *
 * @spec openspec/specs/course-management/spec.md#requirement-the-store-page-shows-each-user-the-actions-they-may-take
 */

/**
 * Normalise the initial state: anything but an explicit true is false.
 *
 * @param {object|null|undefined} access The `storeAccess` initial state.
 * @return {{install: boolean, publish: boolean}} Both flags as booleans.
 * @spec openspec/specs/course-management/spec.md#requirement-the-store-page-shows-each-user-the-actions-they-may-take
 */
export function normaliseStoreAccess(access) {
	return {
		install: access?.install === true,
		publish: access?.publish === true,
	}
}

/**
 * Write `canInstall` and `canPublish` into every store page's config. Other
 * pages and every other config key are left as they are.
 *
 * @param {object} manifest The merged manifest (mutated in place and returned).
 * @param {object|null|undefined} access The `storeAccess` initial state.
 * @return {object} The same manifest.
 * @spec openspec/specs/course-management/spec.md#requirement-the-store-page-shows-each-user-the-actions-they-may-take
 */
export function applyStoreAccess(manifest, access) {
	const flags = normaliseStoreAccess(access)
	for (const page of manifest?.pages ?? []) {
		if (page?.type !== 'store') {
			continue
		}
		const config =
			page.config
			&& typeof page.config === 'object'
			&& !Array.isArray(page.config)
				? page.config
				: {}
		page.config = {
			...config,
			canInstall: flags.install,
			canPublish: flags.publish,
		}
	}
	return manifest
}
