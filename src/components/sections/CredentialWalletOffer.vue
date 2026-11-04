<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
 CredentialWalletOffer: the holder's "In je wallet" block on CredentialDetail
 (learniq/LqLlCertificaat). While the school's offer is open it shows the offer
 as a QR code (CnQrCode) with a "Naar wallet" link; once claimed, the date;
 after a failed offer or before any offer, a sentence. Only the holder sees
 it. Starting a new offer stays with the school (design D2).
 @spec openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#requirement-the-holder-sees-the-offer-on-their-own-certificate
-->
<template>
	<section v-if="offer" class="wallet-offer" data-testid="credential-wallet-offer">
		<h3>{{ t('learniq', 'In your wallet') }}</h3>
		<p>
			{{ t('learniq', 'With your EUDI wallet you show this certificate without paper. Scan the code with the wallet app on your phone.') }}
		</p>
		<template v-if="offer.state === 'offered'">
			<CnQrCode :value="offer.uri" :label="t('learniq', 'Wallet offer for this certificate')" />
			<p>{{ t('learniq', 'Offered to your wallet on {date}. Not collected yet: scan the code with your wallet app.', { date: formatDate(offer.at) }) }}</p>
			<a :href="offer.uri" class="button-vue button-vue--primary wallet-offer__link">
				{{ t('learniq', 'To wallet') }}
			</a>
		</template>
		<p v-else-if="offer.state === 'claimed'">
			{{ t('learniq', 'In your wallet since {date}.', { date: formatDate(offer.at) }) }}
		</p>
		<p v-else-if="offer.state === 'failed'">
			{{ t('learniq', 'Putting this certificate in your wallet did not work. Your certificate stays valid; ask your school to try again.') }}
		</p>
		<p v-else>
			{{ t('learniq', 'Ask your school to put this certificate in your wallet.') }}
		</p>
	</section>
</template>

<script>
import { CnQrCode } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import { walletOfferState } from '../../utils/walletOffer.js'

export default {
	name: 'CredentialWalletOffer',

	components: { CnQrCode },

	inject: {
		cnSectionContext: { default: null },
	},

	computed: {
		/**
		 * @return {object|null} The block's state, null for anyone but the holder.
		 * @spec openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#requirement-the-holder-sees-the-offer-on-their-own-certificate
		 */
		offer() {
			return walletOfferState(this.cnSectionContext?.value?.object ?? null, getCurrentUser()?.uid ?? '')
		},
	},

	methods: {
		/**
		 * @param {string} value An ISO date-time.
		 * @return {string} The date and time in the reader's locale.
		 * @spec exclude Presentation-only date formatting.
		 */
		formatDate(value) {
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? '' : date.toLocaleString(undefined, { dateStyle: 'long', timeStyle: 'short' })
		},
	},
}
</script>

<style scoped>
.wallet-offer {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	align-items: flex-start;
}
</style>
