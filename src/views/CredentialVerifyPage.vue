<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 CredentialVerifyPage: the public page behind a certificate's verification
 link (board LqVerifieren). It asks GET /api/credentials/{id}/verify for JSON
 and says in words whether the certificate is real and still valid, what was
 checked, and whom to call. It shows only what the route answers: a title and
 a name only for a valid or an expired certificate, nothing for an unknown id.

 @spec openspec/changes/certificate-verification-and-key-rotation/specs/certification/spec.md#requirement-the-verification-link-opens-a-page-that-says-in-words-what-was-checked
-->
<template>
	<main class="learniq-verify" data-testid="credential-verify-page">
		<h1 class="learniq-verify__heading">
			{{ t('learniq', 'Check a certificate') }}
		</h1>

		<p v-if="verdict === null" role="status">
			{{ t('learniq', 'Checking the certificate…') }}
		</p>

		<template v-else>
			<section
				class="learniq-verify__verdict"
				:class="'learniq-verify__verdict--' + tone"
				role="status"
				data-testid="credential-verify-verdict">
				<h2 class="learniq-verify__verdict-title">
					{{ headline }}
				</h2>
				<p>{{ explanation }}</p>
			</section>

			<dl v-if="details.length > 0" class="learniq-verify__details" data-testid="credential-verify-details">
				<template v-for="row in details" :key="row.label">
					<dt>{{ row.label }}</dt>
					<dd>{{ row.value }}</dd>
				</template>
			</dl>

			<section v-if="verdict.checks" class="learniq-verify__checks">
				<h2>{{ t('learniq', 'What was checked') }}</h2>
				<ul>
					<li
						v-for="check in checkRows"
						:key="check.id"
						class="learniq-verify__check"
						:class="'learniq-verify__check--' + check.outcome"
						:data-testid="'credential-verify-check-' + check.id">
						<span class="learniq-verify__check-mark" aria-hidden="true">{{ check.mark }}</span>
						<span>
							<strong>{{ check.name }}</strong>
							<span class="learniq-verify__check-outcome">{{ check.outcomeLabel }}</span>
							{{ check.sentence }}
						</span>
					</li>
				</ul>
			</section>
		</template>

		<p v-if="schoolPhone" class="learniq-verify__contact" data-testid="credential-verify-phone">
			{{ t('learniq', 'Questions about this certificate? Call the school on {phone}.', { phone: schoolPhone }) }}
		</p>
	</main>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { CHECKS, credentialVerdict, FAILED, NOT_RUN, PASSED } from '../services/credentialVerdict.js'

/**
 * A date as the reader's locale writes it.
 *
 * @param {string|null} value An ISO date.
 * @return {string}
 */
function longDate(value) {
	const time = Date.parse(value ?? '')
	return Number.isNaN(time) ? String(value ?? '') : new Date(time).toLocaleDateString(undefined, { dateStyle: 'long' })
}

export default {
	name: 'CredentialVerifyPage',

	props: {
		credentialId: { type: String, required: true },
		schoolPhone: { type: String, default: '' },
	},

	data() {
		return { verdict: null }
	},

	computed: {
		/**
		 * The colour family of the verdict.
		 *
		 * @return {string}
		 */
		tone() {
			if (this.verdict.state === 'valid') {
				return 'success'
			}
			return this.verdict.state === 'unavailable' ? 'warning' : 'error'
		},

		/**
		 * The verdict in a few words.
		 *
		 * @return {string}
		 */
		headline() {
			switch (this.verdict.state) {
			case 'valid': return t('learniq', 'Real and valid')
			case 'expired':
			case 'revoked':
			case 'notValid': return t('learniq', 'No longer valid')
			case 'notGenuine': return t('learniq', 'Not genuine')
			case 'notFound': return t('learniq', 'No certificate found')
			default: return t('learniq', 'The check could not be done')
			}
		},

		/**
		 * The verdict in one sentence.
		 *
		 * @return {string}
		 */
		explanation() {
			const v = this.verdict
			switch (v.state) {
			case 'valid': return t('learniq', 'This certificate was issued by the school and is still valid.')
			case 'expired': return t('learniq', 'This certificate is real, but it expired on {date}.', { date: longDate(v.expiresAt) })
			case 'revoked': return v.revocationReason
				? t('learniq', 'The school revoked this certificate on {date}. Reason: {reason}', { date: longDate(v.revokedAt), reason: v.revocationReason })
				: t('learniq', 'The school revoked this certificate on {date}.', { date: longDate(v.revokedAt) })
			case 'notValid': return t('learniq', 'The school has not issued this certificate, or no longer stands behind it.')
			case 'notGenuine': return t('learniq', 'The signature does not match the school\'s key: the certificate was changed or not made by the school.')
			case 'notFound': return t('learniq', 'There is no certificate with this link. Check that the link is complete.')
			default: return t('learniq', 'Try again later.')
			}
		},

		/**
		 * The certificate's details the answer carries, in page order.
		 *
		 * @return {Array<{label: string, value: string}>}
		 */
		details() {
			const v = this.verdict
			if (!['valid', 'expired', 'notValid'].includes(v.state)) {
				return []
			}
			const rows = []
			if (v.title) {
				rows.push({ label: t('learniq', 'Certificate'), value: v.title })
			}
			if (v.holderName) {
				rows.push({ label: t('learniq', 'Issued to'), value: v.holderName })
			}
			if (v.issuerName) {
				rows.push({ label: t('learniq', 'Issued by'), value: v.issuerName })
			}
			if (v.issuedAt) {
				rows.push({ label: t('learniq', 'Issued on'), value: longDate(v.issuedAt) })
			}
			rows.push({
				label: t('learniq', 'Valid until'),
				value: v.expiresAt ? longDate(v.expiresAt) : t('learniq', 'No end date'),
			})
			rows.push({
				label: t('learniq', 'Checked on'),
				value: new Date(v.checkedAt).toLocaleString(undefined, { dateStyle: 'long', timeStyle: 'short' }),
			})
			return rows
		},

		/**
		 * The four checks, each with its outcome and one sentence.
		 *
		 * @return {Array<object>}
		 */
		checkRows() {
			const names = {
				signature: t('learniq', 'Signature'),
				notRevoked: t('learniq', 'Not revoked'),
				notExpired: t('learniq', 'Not expired'),
				unchanged: t('learniq', 'Not changed'),
			}
			const sentences = {
				signature: t('learniq', 'The certificate is signed with the school\'s key.'),
				notRevoked: t('learniq', 'The school has not withdrawn the certificate.'),
				notExpired: t('learniq', 'The certificate is within its validity period.'),
				unchanged: t('learniq', 'The content is the same as when the school issued it.'),
			}
			const outcomes = {
				[PASSED]: { mark: '✓', label: t('learniq', 'Passed') },
				[FAILED]: { mark: '✗', label: t('learniq', 'Failed') },
				[NOT_RUN]: { mark: '–', label: t('learniq', 'Not checked') },
			}
			return CHECKS.map((id) => {
				const outcome = this.verdict.checks[id]
				return {
					id,
					outcome,
					name: names[id],
					sentence: sentences[id],
					mark: outcomes[outcome].mark,
					outcomeLabel: outcomes[outcome].label,
				}
			})
		},
	},

	/**
	 * Ask the verification route for its JSON answer.
	 *
	 * @return {Promise<void>}
	 */
	async created() {
		const url = generateUrl('/apps/learniq/api/credentials/{id}/verify', { id: this.credentialId })
		try {
			const response = await axios.get(url, { headers: { Accept: 'application/json' } })
			this.verdict = credentialVerdict(response.status, response.data)
		} catch (error) {
			this.verdict = credentialVerdict(error?.response?.status ?? 0, error?.response?.data ?? null)
		}
	},

	methods: { t },
}
</script>

<style scoped>
.learniq-verify {
	max-width: 640px;
	margin: calc(var(--default-grid-baseline) * 8) auto;
	padding: calc(var(--default-grid-baseline) * 6);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	border-radius: var(--border-radius-large);
}

.learniq-verify__verdict {
	padding: calc(var(--default-grid-baseline) * 4);
	border-radius: var(--border-radius-large);
	border: 2px solid var(--color-border);
}

.learniq-verify__verdict--success {
	border-color: var(--color-success);
}

.learniq-verify__verdict--error {
	border-color: var(--color-error);
}

.learniq-verify__verdict--warning {
	border-color: var(--color-warning);
}

.learniq-verify__details {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 4);
	margin: calc(var(--default-grid-baseline) * 6) 0;
}

.learniq-verify__details dt {
	color: var(--color-text-maxcontrast);
}

.learniq-verify__checks ul {
	list-style: none;
	padding: 0;
}

.learniq-verify__check {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 2) 0;
}

.learniq-verify__check-mark {
	font-weight: bold;
	min-width: 1.5em;
}

.learniq-verify__check--passed .learniq-verify__check-mark {
	color: var(--color-success-text);
}

.learniq-verify__check--failed .learniq-verify__check-mark {
	color: var(--color-error-text);
}

.learniq-verify__check-outcome {
	margin: 0 calc(var(--default-grid-baseline) * 2);
	color: var(--color-text-maxcontrast);
}

.learniq-verify__contact {
	margin-top: calc(var(--default-grid-baseline) * 6);
}
</style>
