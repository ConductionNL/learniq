/**
 * Removing a loaded example set from the admin page (wizard-drops-the-removal-step).
 *
 * The setup wizard no longer removes example data (live audit A1). The admin
 * page's Example data section lists the loaded sets and offers a Remove
 * button per set. The button asks first, then posts the existing admin-only
 * setup action `remove-example-set-<id>`, which moves the set's objects to
 * OpenRegister's trash (not the `occ` command's hard delete).
 *
 * Plain ES module so a Node test can import it without a build step.
 *
 * @spec openspec/changes/wizard-drops-the-removal-step/specs/example-sets/spec.md
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/** The admin-only endpoint that lists the loaded sets. */
export const EXAMPLE_SETS_URL = '/apps/learniq/api/setup/example-sets'

/**
 * The setup action that removes one set.
 *
 * @param {string} setId The set id (`[a-z0-9-]+`).
 * @return {string} The action URL, before generateUrl.
 */
export function removeActionUrl(setId) {
	return `/apps/learniq/api/setup/action/remove-example-set-${encodeURIComponent(setId)}`
}

/**
 * The loaded sets from the endpoint's answer, keeping only usable entries.
 *
 * @param {object|null|undefined} data The answer, `{ sets: [{ id, label }] }`.
 * @return {Array<{id: string, label: string}>} The sets.
 */
export function loadedSetsOf(data) {
	const sets = Array.isArray(data?.sets) ? data.sets : []
	return sets.filter(
		(set) =>
			!!set
			&& typeof set.id === 'string'
			&& /^[a-z0-9-]+$/.test(set.id)
			&& typeof set.label === 'string',
	)
}

/**
 * Ask, then remove one set.
 *
 * @param {{id: string, label: string}} set The set.
 * @param {object} deps Seams.
 * @param {(set: {id: string, label: string}) => Promise<boolean>} deps.confirm Asks the admin; true to go on.
 * @param {(url: string) => Promise<{data: object}>} deps.post Posts the action.
 * @param {(text: string, vars?: object) => string} deps.t Translates.
 * @return {Promise<{status: 'cancelled'|'removed'|'failed', message: string}>} What happened.
 */
export async function removeExampleSet(set, { confirm, post, t }) {
	// Closing the question rejects in some @nextcloud/dialogs versions; that
	// is a no, not an error.
	const confirmed = await Promise.resolve()
		.then(() => confirm(set))
		.catch(() => false)
	if (confirmed !== true) {
		return { status: 'cancelled', message: '' }
	}

	try {
		const { data } = await post(removeActionUrl(set.id))
		const message =
			typeof data?.message === 'string' && data.message !== ''
				? data.message
				: t('The example set "{set}" was removed.', { set: set.label })
		return { status: data?.success === true ? 'removed' : 'failed', message }
	} catch (e) {
		const message = e?.response?.data?.message
		return {
			status: 'failed',
			message:
				typeof message === 'string' && message !== ''
					? message
					: t('The example set "{set}" could not be removed.', {
							set: set.label,
						}),
		}
	}
}
