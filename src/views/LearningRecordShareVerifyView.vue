<!--
  LearningRecordShareVerifyView.vue
  Custom page component for the LearningRecordShareVerifyView manifest page
  (type: custom).

  Public verification page for a LearningRecordShare's shared bundle —
  modeled directly on the existing CredentialVerify page's minimal,
  anonymous-verifier shape (a single-purpose proof page, no related-index,
  no comms, no audit sidebar). Calls the public
  GET /api/learning-record-shares/{id}/verify endpoint, never a
  session-authenticated OR object read.

  Uses Options API + direct fetch calls, same pattern as
  CoursePackageImportView.vue.

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.

  @spec openspec/specs/portable-learning-record/spec.md#requirement-a-public-verification-page-resolves-an-active-unexpired-share-and-denies-otherwise
-->

<template>
	<div class="lrs-verify lq-board-page">
		<p v-if="loading" class="lrs-verify__loading">
			{{ t('learniq', 'Verifying…') }}
		</p>

		<template v-else>
			<CnPageHeader
				:title="
					valid
						? t('learniq', 'Verified learning record')
						: t('learniq', 'This share could not be verified')
				" />
			<CnDetailCard
				v-if="valid"
				class="lrs-verify__result lrs-verify__result--valid"
				role="status">
				<p class="lrs-verify__hint">
					{{
						t(
							'learniq',
							'This bundle was signed by the issuing school and has not been tampered with.',
						)
					}}
				</p>
				<dl class="lrs-verify__bundle">
					<dt>{{ t('learniq', 'Issuer') }}</dt>
					<dd>{{ bundle.issuerDid }}</dd>
					<dt>{{ t('learniq', 'Generated at') }}</dt>
					<dd>{{ bundle.generatedAt }}</dd>
				</dl>
				<details class="lrs-verify__raw">
					<summary>{{ t('learniq', 'Full record content') }}</summary>
					<pre>{{ prettyBundle }}</pre>
				</details>
			</CnDetailCard>

			<CnDetailCard
				v-else
				class="lrs-verify__result lrs-verify__result--denied"
				role="alert">
				<p>{{ deniedReasonLabel }}</p>
			</CnDetailCard>
		</template>
	</div>
</template>

<script>
import { CnDetailCard, CnPageHeader } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'LearningRecordShareVerifyView',
	components: { CnDetailCard, CnPageHeader },

	data() {
		return {
			loading: true,
			valid: false,
			bundle: null,
			reason: null,
		}
	},

	computed: {
		/**
		 * The LearningRecordShare UUID from the route.
		 *
		 * @return {string}
		 * @spec openspec/specs/portable-learning-record/spec.md#requirement-a-public-verification-page-resolves-an-active-unexpired-share-and-denies-otherwise
		 */
		shareId() {
			return this.$route?.params?.id ?? ''
		},

		/**
		 * Pretty-printed bundle JSON for the disclosure panel.
		 *
		 * @return {string}
		 * @spec openspec/specs/portable-learning-record/spec.md#requirement-a-public-verification-page-resolves-an-active-unexpired-share-and-denies-otherwise
		 */
		prettyBundle() {
			return this.bundle ? JSON.stringify(this.bundle, null, 2) : ''
		},

		/**
		 * Human-readable label for the denial reason.
		 *
		 * @return {string}
		 * @spec openspec/specs/portable-learning-record/spec.md#requirement-a-public-verification-page-resolves-an-active-unexpired-share-and-denies-otherwise
		 */
		deniedReasonLabel() {
			const labels = {
				not_found: this.t('learniq', 'This link does not exist.'),
				revoked: this.t(
					'learniq',
					'This share has been revoked by the learner.',
				),

				expired: this.t('learniq', 'This share has expired.'),
				export_not_found: this.t(
					'learniq',
					'The underlying record could not be found.',
				),

				bundle_unreadable: this.t(
					'learniq',
					'The underlying record could not be read.',
				),

				signature_invalid: this.t(
					'learniq',
					"This record's signature could not be verified — it may have been altered.",
				),
			}
			return (
				labels[this.reason]
				?? this.t('learniq', 'This link is no longer valid.')
			)
		},
	},

	async mounted() {
		await this.verify()
	},

	methods: {
		/**
		 * Call the public verification endpoint and render its result.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/portable-learning-record/spec.md#scenario-a-valid-unexpired-share-resolves-to-the-shared-bundle
		 * @spec openspec/specs/portable-learning-record/spec.md#scenario-an-expired-share-is-denied-even-though-its-lifecycle-is-still-active
		 */
		async verify() {
			this.loading = true

			try {
				const url = generateUrl(
					`/apps/learniq/api/learning-record-shares/${this.shareId}/verify`,
				)
				const resp = await fetch(url, {
					headers: {
						'OCS-APIREQUEST': 'true',
						Accept: 'application/json',
					},
				})

				// Guard on `resp.ok` BEFORE parsing. A non-2xx from this endpoint is
				// not necessarily JSON — an uncaught server-side exception makes
				// Nextcloud render an HTML error page, and `resp.json()` on that
				// throws `SyntaxError: Unexpected token '<', "<!DOCTYPE "...`, which
				// lands in the catch below as a console error instead of the denied
				// state the user should see. A failed verification IS the denied
				// state, so render it rather than treating it as a client crash.
				if (resp.ok === false) {
					this.valid = false
					this.bundle = null
					this.reason = null
					return
				}

				const json = await resp.json()
				this.valid = json.valid === true
				this.bundle = json.bundle ?? null
				this.reason = json.reason ?? null
			} catch (err) {
				this.valid = false
				this.reason = null
				// eslint-disable-next-line no-console
				console.error('[LearningRecordShareVerifyView] verify error', err)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.lrs-verify__hint {
	color: var(--color-text-maxcontrast);
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.lrs-verify__result--valid {
	border-left: 4px solid var(--color-success);
}

.lrs-verify__result--denied {
	border-left: 4px solid var(--color-error);
}

.lrs-verify__bundle {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px calc(var(--default-grid-baseline, 8px) * 2);
	margin-bottom: var(--default-grid-baseline, 8px);
}

.lrs-verify__bundle dt {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.lrs-verify__raw pre {
	white-space: pre-wrap;
	overflow-wrap: break-word;
	background: var(--color-background-dark);
	padding: var(--default-grid-baseline, 8px);
	border-radius: var(--border-radius, 4px);
}
</style>
