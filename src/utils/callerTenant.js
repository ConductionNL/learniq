// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The caller's tenant, for nextcloud-vue's tenant context.
 *
 * PageController provides `callerTenant` as initial state, resolved by
 * CallerTenantResolver: the user's own `tenant_id` binding, else the instance
 * id. Every row learniq writes server-side carries that value. nextcloud-vue's
 * create dialog hides a `tenant_id` property and fills it from the tenant
 * context CnAppRoot provides (`initialOrganisationUuid`), so boot hands this
 * value to CnAppRoot. Without it the dialog leaves `tenant_id` out, and it
 * must never fall back to the OpenRegister organisation, which is not
 * learniq's tenant.
 *
 * @spec exclude boot glue — normalises one initial-state value into a CnAppRoot prop; no business behaviour
 */

/**
 * Normalise the `callerTenant` initial state to a tenant id or null.
 *
 * @param {unknown} value The `callerTenant` initial state.
 * @return {string|null} A non-empty tenant id, else null.
 * @spec exclude boot glue — normalises one initial-state value into a CnAppRoot prop; no business behaviour
 */
export function normaliseCallerTenant(value) {
	if (typeof value !== 'string') {
		return null
	}
	const trimmed = value.trim()
	return trimmed !== '' ? trimmed : null
}
