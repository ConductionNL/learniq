/**
 * Generating or rotating the credential signing key from the admin page.
 *
 * The server resolves the admin's tenant, keeps the old public key for
 * verification, throttles rotations to one a day and writes the admin audit
 * log. A rotation of an existing key asks first and is sent with
 * `confirm=true`. Success is reported only when the server answered 201.
 *
 * Plain ES module so a Node test can import it without a build step.
 *
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-allow-the-credential-signing-key-to-be-rotated-from-settings
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/** The admin-only status of the caller's tenant signing key. */
export const SIGNING_KEY_STATUS_URL = '/apps/learniq/api/settings/signing-key'

/** The admin-only generate-or-rotate action. */
export const SIGNING_KEY_ROTATE_URL = '/apps/learniq/api/settings/signing-key/rotate'

/**
 * The key status from the status answer.
 *
 * @param {object|null|undefined} data The answer, `{ configured, fingerprint? }`.
 * @return {{configured: boolean, fingerprint: string}} The status.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-allow-the-credential-signing-key-to-be-rotated-from-settings
 */
export function signingKeyStatusOf(data) {
	const configured = data?.configured === true
	const fingerprint = configured && typeof data?.fingerprint === 'string' ? data.fingerprint : ''
	return { configured, fingerprint }
}

/**
 * Generate the first key, or ask and then rotate the existing one.
 *
 * @param {{configured: boolean}} status The current key status.
 * @param {object} deps Seams.
 * @param {() => Promise<boolean>} deps.confirm Asks the admin; true to go on.
 * @param {(url: string, body: object) => Promise<{status: number, data: object}>} deps.post Posts the action.
 * @param {(text: string, vars?: object) => string} deps.t Translates.
 * @return {Promise<{status: 'cancelled'|'done'|'failed', message: string, fingerprint: string}>} What happened.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-allow-the-credential-signing-key-to-be-rotated-from-settings
 */
export async function rotateSigningKey(status, { confirm, post, t }) {
	if (status?.configured === true) {
		// Closing the question rejects in some @nextcloud/dialogs versions; that is a no.
		const confirmed = await Promise.resolve()
			.then(() => confirm())
			.catch(() => false)
		if (confirmed !== true) {
			return { status: 'cancelled', message: '', fingerprint: '' }
		}
	}

	try {
		const response = await post(SIGNING_KEY_ROTATE_URL, status?.configured === true ? { confirm: 'true' } : {})
		const fingerprint = typeof response?.data?.fingerprint === 'string' ? response.data.fingerprint : ''
		if (response?.status !== 201 || fingerprint === '') {
			return { status: 'failed', message: t('The signing key was not changed.'), fingerprint: '' }
		}
		const message = response.data.rotated === true
			? t('The signing key was rotated. Credentials issued before stay verifiable.')
			: t('A signing key was generated.')
		return { status: 'done', message, fingerprint }
	} catch (e) {
		if (e?.response?.status === 429) {
			return { status: 'failed', message: t('The signing key was rotated less than a day ago. Try again tomorrow.'), fingerprint: '' }
		}
		if (e?.response?.status === 403) {
			return { status: 'failed', message: t('Only an administrator can change the signing key.'), fingerprint: '' }
		}
		return { status: 'failed', message: t('The signing key was not changed.'), fingerprint: '' }
	}
}
