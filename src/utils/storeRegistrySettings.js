// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The course store connection endpoint, admin-only
 * (StoreRegistrySettingsController, store-rights-for-teachers). One place for
 * the path, so the settings section and its test agree with appinfo/routes.php.
 *
 * @return {string} The path, to wrap in generateUrl().
 * @spec openspec/changes/store-rights-for-teachers/specs/course-management/spec.md#requirement-an-administrator-connects-the-course-registry-in-the-admin-settings
 */
export function storeRegistryUrl() {
	return '/apps/learniq/api/admin/store-registry'
}
