/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * IS THIS INSTANCE SEEDED, AND WHAT DOES A MISSING FIXTURE MEAN?
 *
 * Those are two questions, and conflating them is what let a scenario stop
 * running without anyone noticing.
 *
 * A suite that discovers its fixtures (rather than creating them) has to cope
 * with an instance that was never seeded — there, the question simply cannot be
 * asked, and skipping is right. But on a SEEDED instance a missing fixture is
 * not an absence, it is a DEFECT: the seeder was supposed to produce it.
 *
 * `test.skip(!row, …)` answers both cases the same way, and reports the second
 * as a pass. That is how talk-classroom-spaces' third scenario ran zero times
 * across every green run: `Enrolment.lifecycle` DEFAULTS to `'pending'`, the
 * seeder never set `'active'`, so the `lifecycle === 'active'` guard matched
 * nothing and the test skipped itself for good — asserting nothing, reported as
 * success. Worse, before that the lookup ended in `?? rows[0]`, so a miss
 * returned an ARBITRARY row and the scenario asserted against the wrong object
 * while still looking green.
 *
 * Extracted here because three specs had already grown their own copy of the
 * seeded check, and a rule about what counts as covered should not be spelled
 * three ways.
 */
import * as fs from 'fs'
import * as path from 'path'
import { test, expect } from './fixtures'

/**
 * True once the seeder has actually populated the register.
 *
 * Reads the seeder's marker FILE rather than `process.env`, because
 * globalSetup mutates the runner's environment and test workers are separate
 * processes — the variable it set is not there to be read.
 *
 * @return {boolean} true when the seeder reported at least one schema.
 */
export function isSeeded(): boolean {
	const file = path.resolve(
		__dirname,
		'..',
		'..',
		'.e2e-state',
		'seeded-schemas.json',
	)

	try {
		return Object.keys(JSON.parse(fs.readFileSync(file, 'utf8'))).length > 0
	} catch {
		return process.env.LEARNIQ_E2E_SEEDED === '1'
	}
}

export const SEEDED = isSeeded()

/**
 * Require a discovered fixture on a seeded instance; skip ONLY when the
 * instance was never seeded.
 *
 * ⚠️ This is deliberately louder than a skip. If it starts failing, the answer
 * is to seed the fixture (or fix why the seeder does not produce it) — not to
 * put the skip back. A scenario that cannot find what it needs is a scenario
 * that is not covering anything, and the whole point of this helper is that
 * such a state is visible.
 *
 * @param row  The discovered row, or null/undefined.
 * @param what What was being looked for, phrased for the failure message
 *             (e.g. "a published Lesson with a lesson-completed condition").
 * @return {void}
 */
export function requireFixture(row: unknown, what: string): void {
	test.skip(!SEEDED && !row, `Instance not seeded — cannot look for ${what}.`)

	expect(
		row,
		`${what} is missing on a SEEDED instance — the seeder should provide it (tests/e2e/seed-example-data.mjs).`,
	).toBeTruthy()
}
