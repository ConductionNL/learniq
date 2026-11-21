// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The tenant every seeded and fixture row carries.
 *
 * It is learniq's default tenant (CallerTenantResolverInterface::DEFAULT_TENANT):
 * the tenant every caller without a stamped `tenant_id` user value resolves
 * to. Rows in any other tenant are invisible to tenant-scoped reads such as
 * the certificate migration and the regulation lookup.
 */
export const SEED_TENANT = '00000000-0000-4000-8000-000000000000'
