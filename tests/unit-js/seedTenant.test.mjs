// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

// The e2e seed and fixtures stamp the tenant every caller resolves to;
// rows in any other tenant are invisible to tenant-scoped reads.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { SEED_TENANT } from '../e2e/seed-tenant.mjs'

test('the seed tenant is learniq\'s default caller tenant', () => {
	const php = readFileSync(
		new URL('../../lib/Kernel/Contracts/CallerTenantResolverInterface.php', import.meta.url),
		'utf8',
	)
	const declared = php.match(/DEFAULT_TENANT = '([^']+)'/)?.[1]
	assert.equal(SEED_TENANT, declared)
})

test('the seeder and the fixtures read the same tenant', () => {
	for (const file of ['../e2e/seed-example-data.mjs', '../e2e/or-api.ts']) {
		const source = readFileSync(new URL(file, import.meta.url), 'utf8')
		assert.match(source, /SEED_TENANT/, file)
		assert.doesNotMatch(source, /00000000d3a0/, file)
	}
})
