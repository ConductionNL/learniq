<!--
  OrderPaymentPanel.vue
  Custom page component for the OrderPaymentPanel manifest page (type: custom).

  The payer's "pay now" surface for an open/partially-paid Order: shows its
  OrderLines and totalAmount, lets the payer pick a PSP, calls
  PaymentTransactionController::initiate(), and renders the returned checkout
  reference opaquely — no PSP-specific rendering logic (design.md's "forward
  the response as-is" rule, mirroring LessonPlayer's LTI-launch handling).

  Talks to:
    - GET  /apps/openregister/api/objects/learniq/order/:orderId
    - GET  /apps/openregister/api/objects/learniq/order-line?orderId=:orderId
    - POST /apps/learniq/api/payments/:orderId/initiate

  Uses Options API + direct fetch calls (no custom Pinia store modules),
  mirroring LessonPlayer's shape — the only other genuine custom-view
  exception this app's dashboard/detail-page manifest pattern doesn't cover.

  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
-->

<template>
	<div class="order-payment-panel">
		<div v-if="loading" class="order-payment-panel__loading" aria-live="polite">
			<span class="icon-loading" aria-hidden="true" />
			<span>{{ t('learniq', 'Loading order…') }}</span>
		</div>

		<div v-else-if="error" class="order-payment-panel__error" role="alert">
			<NcEmptyContent
				:name="t('learniq', 'Order not found')"
				:description="error">
				<template #icon>
					<AlertCircleOutline />
				</template>
			</NcEmptyContent>
		</div>

		<article v-else class="order-payment-panel__content">
			<header class="order-payment-panel__header">
				<h1 class="order-payment-panel__title">
					{{ t('learniq', 'Payment') }}
				</h1>
				<p class="order-payment-panel__status">
					{{ statusLabel }}
				</p>
			</header>

			<table class="order-payment-panel__lines">
				<thead>
					<tr>
						<th scope="col">{{ t('learniq', 'Description') }}</th>
						<th scope="col">{{ t('learniq', 'Quantity') }}</th>
						<th scope="col">{{ t('learniq', 'Amount') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="line in orderLines" :key="line.id">
						<td>{{ line.description }}</td>
						<td>{{ line.quantity }}</td>
						<td>{{ formatAmount(line.lineTotal) }}</td>
					</tr>
					<tr v-if="orderLines.length === 0">
						<td colspan="3">
							{{ t('learniq', 'This order has no lines yet.') }}
						</td>
					</tr>
				</tbody>
				<tfoot>
					<tr>
						<td colspan="2">
							{{ t('learniq', 'Total') }}
						</td>
						<td>{{ formatAmount(order.totalAmount) }}</td>
					</tr>
				</tfoot>
			</table>

			<section v-if="canPay" class="order-payment-panel__pay">
				<NcSelect
					id="order-payment-panel-psp-select"
					v-model="pspProvider"
					class="order-payment-panel__psp-select"
					:options="pspOptions"
					:reduce="(o) => o.value"
					label="label"
					:clearable="false"
					:inputLabel="t('learniq', 'Payment provider')"
					:aria-label-combobox="t('learniq', 'Payment provider')" />

				<NcButton variant="primary" :disabled="paying" @click="payNow">
					{{
						paying
							? t('learniq', 'Starting payment…')
							: t('learniq', 'Pay now')
					}}
				</NcButton>

				<p
					v-if="payError"
					class="order-payment-panel__pay-error"
					role="alert">
					{{ payError }}
				</p>
			</section>

			<NcEmptyContent
				v-else-if="isSettled"
				:name="t('learniq', 'This order is already paid')"
				:description="t('learniq', 'No further payment is needed.')">
				<template #icon>
					<CheckCircleOutline />
				</template>
			</NcEmptyContent>

			<NcEmptyContent
				v-else
				:name="t('learniq', 'This order cannot be paid')"
				:description="
					t('learniq', 'Its current status does not allow payment.')
				">
				<template #icon>
					<AlertCircleOutline />
				</template>
			</NcEmptyContent>

			<section v-if="checkoutReference" class="order-payment-panel__checkout">
				<p>{{ t('learniq', 'Continue to complete your payment:') }}</p>
				<NcButton
					variant="primary"
					:href="checkoutReference"
					target="_blank"
					rel="noopener">
					{{ t('learniq', 'Continue to payment') }}
				</NcButton>
			</section>
		</article>
	</div>
</template>

<script>
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcSelect } from '@nextcloud/vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'

/**
 * Order lifecycle states from which payment may be initiated.
 *
 * @type {string[]}
 */
const PAYABLE_STATES = ['open', 'partially-paid']

/**
 * Order lifecycle states considered "already settled" (nothing left to pay).
 *
 * @type {string[]}
 */
const SETTLED_STATES = ['paid', 'refunded']

export default {
	name: 'OrderPaymentPanel',

	components: {
		NcButton,
		NcEmptyContent,
		NcSelect,
		AlertCircleOutline,
		CheckCircleOutline,
	},

	props: {
		/** Order UUID injected by CnAppRoot from the route :orderId param. */
		orderId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			error: '',
			order: null,
			orderLines: [],
			pspProvider: 'mollie',
			pspOptions: [
				{ value: 'mollie', label: this.t('learniq', 'Mollie') },
				{ value: 'stripe', label: this.t('learniq', 'Stripe') },
			],

			paying: false,
			payError: '',
			checkoutReference: '',
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-a-payer-opens-the-payment-panel-and-initiates-payment
		 */
		canPay() {
			return !!this.order && PAYABLE_STATES.includes(this.order.lifecycle)
		},

		isSettled() {
			return !!this.order && SETTLED_STATES.includes(this.order.lifecycle)
		},

		/**
		 * @spec exclude Presentation-only string formatting a "Order status: {lifecycle}" line from the raw lifecycle value; the payable/settled business rules live in canPay/isSettled, not here.
		 */
		statusLabel() {
			if (!this.order) {
				return ''
			}
			return this.t('learniq', 'Order status: {status}', {
				status: this.order.lifecycle,
			})
		},
	},

	async mounted() {
		await this.loadOrder()
	},

	methods: {
		/**
		 * Load the Order and its OrderLines.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-a-payer-opens-the-payment-panel-and-initiates-payment
		 */
		async loadOrder() {
			this.loading = true
			this.error = ''

			try {
				const [orderRes, linesRes] = await Promise.all([
					fetch(
						generateUrl(
							'/apps/openregister/api/objects/learniq/order/'
								+ this.orderId,
						),
					),
					fetch(
						generateUrl(
							'/apps/openregister/api/objects/learniq/order-line?orderId='
								+ this.orderId
								+ '&limit=100',
						),
					),
				])

				if (!orderRes.ok) {
					throw new Error(
						this.t(
							'learniq',
							'This order does not exist or you do not have access to it.',
						),
					)
				}

				this.order = await orderRes.json()

				const linesBody = linesRes.ok
					? await linesRes.json()
					: { results: [] }
				this.orderLines = linesBody?.results ?? linesBody ?? []
			} catch (e) {
				this.error = e?.message ?? String(e)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Format a numeric amount using the order's currency.
		 *
		 * @param {number} amount The amount to format.
		 * @return {string} The formatted amount.
		 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-a-payer-opens-the-payment-panel-and-initiates-payment
		 */
		formatAmount(amount) {
			const currency = this.order?.currency ?? 'EUR'
			return new Intl.NumberFormat(undefined, {
				style: 'currency',
				currency,
			}).format(amount ?? 0)
		},

		/**
		 * Initiate payment via PaymentTransactionController::initiate() and
		 * render the returned checkout reference opaquely — no PSP-specific
		 * field is parsed (design.md's "forward the response as-is" rule).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/school-payments/specs/payments/spec.md#scenario-a-payer-opens-the-payment-panel-and-initiates-payment
		 */
		async payNow() {
			this.paying = true
			this.payError = ''
			this.checkoutReference = ''

			try {
				const res = await fetch(
					generateUrl(
						'/apps/learniq/api/payments/' + this.orderId + '/initiate',
					),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: window.OC?.requestToken ?? '',
						},
						body: JSON.stringify({ pspProvider: this.pspProvider }),
					},
				)
				const body = await res.json().catch(() => ({}))
				if (!res.ok) {
					throw new Error(
						body?.error
							|| this.t(
								'learniq',
								'Failed to start payment (HTTP {status})',
								{ status: res.status },
							),
					)
				}

				this.checkoutReference = body?.checkoutUrl ?? ''
			} catch (e) {
				this.payError = e?.message ?? String(e)
			} finally {
				this.paying = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.order-payment-panel {
	padding: 16px;
	max-width: 640px;
}

.order-payment-panel__lines {
	width: 100%;
	border-collapse: collapse;
	margin: 16px 0;

	th,
	td {
		text-align: left;
		padding: 8px;
		border-bottom: 1px solid var(--color-border);
	}

	tfoot td {
		font-weight: bold;
	}
}

.order-payment-panel__pay {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 320px;
}

.order-payment-panel__pay-error {
	color: var(--color-error);
}

.order-payment-panel__checkout {
	margin-top: 16px;
}
</style>
