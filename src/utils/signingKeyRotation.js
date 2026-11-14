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
 * Whether the admin said yes to the rotation question. Closing the question
 * rejects in some @nextcloud/dialogs versions; that is a no.
 *
 * @param {() => Promise<boolean>} confirm Asks the admin.
 * @return {Promise<unknown>} The answer; only `true` goes on.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-allow-the-credential-signing-key-to-be-rotated-from-settings
 */
function askToRotate(confirm) {
	return Promise.resolve()
		.then(() => confirm())
		.catch(() => false)
}

/**
 * A failed outcome with its message.
 *
 * @param {string} message The translated message.
 * @return {{status: 'failed', message: string, fingerprint: string}} The outcome.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-allow-the-credential-signing-key-to-be-rotated-from-settings
 */
function failedRotation(message) {
	return { status: 'failed', message, fingerprint: '' }
}

/**
 * The outcome of the server's answer: done only for a 201 with a fingerprint.
 *
 * @param {{status: number, data: object}|undefined} response The answer.
 * @param {(text: string, vars?: object) => string} t Translates.
 * @return {{status: 'done'|'failed', message: string, fingerprint: string}} What happened.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-allow-the-credential-signing-key-to-be-rotated-from-settings
 */
function rotationOutcome(response, t) {
	const fingerprint = typeof response?.data?.fingerprint === 'string' ? response.data.fingerprint : ''
	if (response?.status !== 201 || fingerprint === '') {
		return failedRotation(t('The signing key was not changed.'))
	}
	const message = response.data.rotated === true
		? t('The signing key was rotated. Credentials issued before stay verifiable.')
		: t('A signing key was generated.')
	return { status: 'done', message, fingerprint }
}

/**
 * The message for a refused or failed post.
 *
 * @param {unknown} error What the post threw.
 * @param {(text: string, vars?: object) => string} t Translates.
 * @return {string} The translated message.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-allow-the-credential-signing-key-to-be-rotated-from-settings
 */
function refusalMessage(error, t) {
	const status = error?.response?.status
	if (status === 429) {
		return t('The signing key was rotated less than a day ago. Try again tomorrow.')
	}
	if (status === 403) {
		return t('Only an administrator can change the signing key.')
	}
	return t('The signing key was not changed.')
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
	if (status?.configured === true && (await askToRotate(confirm)) !== true) {
		return { status: 'cancelled', message: '', fingerprint: '' }
	}

	try {
		const response = await post(SIGNING_KEY_ROTATE_URL, status?.configured === true ? { confirm: 'true' } : {})
		return rotationOutcome(response, t)
	} catch (e) {
		return failedRotation(refusalMessage(e, t))
	}
}
