<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 CheckInCode: the QR code and code of an open self check-in window, with the
 number of learners checked in so far. CnQrCode reads the code every few
 seconds; in the room the code changes every thirty seconds and the countdown
 says when, online one code holds for the whole window.

 @spec openspec/specs/attendance/spec.md#requirement-the-check-in-code-changes-every-thirty-seconds-in-the-room
-->
<template>
	<div class="check-in-code">
		<CnQrCode
			:size="size"
			:label="t('learniq', 'Self check-in')"
			:refreshUrl="refreshUrl"
			:refreshSeconds="readSeconds"
			:mapResponse="qrFromCodeResponse"
			:countdownTemplate="countdownTemplate"
			:refreshFailedText="t('learniq', 'The check-in code could not be read.')"
			@refreshed="onRefreshed">
			<p v-if="isOnline && link" class="check-in-code__link">
				<a :href="link" target="_blank" rel="noopener">{{ link }}</a>
			</p>
			<p class="check-in-code__count">
				{{ n('learniq', '%n learner checked in', '%n learners checked in', checkInCount) }}
			</p>
		</CnQrCode>
	</div>
</template>

<script>
import { CnQrCode } from '@conduction/nextcloud-vue'
import { CHECK_IN_READ_SECONDS, checkInCodePath, qrFromCodeResponse } from '../utils/checkInCode.js'

export default {
	name: 'CheckInCode',

	components: { CnQrCode },

	props: {
		/** The open CheckInWindow. */
		checkInWindow: {
			type: Object,
			required: true,
		},

		/** Width and height of the QR code in CSS pixels. */
		size: {
			type: Number,
			default: 200,
		},
	},

	data() {
		return {
			checkInCount: 0,
			link: '',
			readSeconds: CHECK_IN_READ_SECONDS,
		}
	},

	computed: {
		/**
		 * @return {string} Where the code is read.
		 * @spec openspec/specs/attendance/spec.md#requirement-the-check-in-code-changes-every-thirty-seconds-in-the-room
		 */
		refreshUrl() {
			return checkInCodePath(this.checkInWindow)
		},

		/** @return {boolean} Whether the window is for an online lesson. */
		isOnline() {
			return this.checkInWindow.mode === 'link'
		},

		/**
		 * @return {string} The countdown in the room; online, that the code holds.
		 * @spec openspec/specs/attendance/spec.md#requirement-the-check-in-code-changes-every-thirty-seconds-in-the-room
		 */
		countdownTemplate() {
			return this.isOnline
				? this.t('learniq', 'This code holds until you close check-in.')
				: this.t('learniq', 'Changes in {seconds} s', { seconds: '{seconds}' })
		},
	},

	methods: {
		qrFromCodeResponse,

		/**
		 * Keep the count and the check-in link of the latest read.
		 *
		 * @param {object} data The code endpoint's answer.
		 * @spec openspec/specs/attendance/spec.md#requirement-a-teacher-opens-a-self-check-in-window-for-a-lesson
		 */
		onRefreshed(data) {
			this.checkInCount = data?.checkInCount ?? 0
			this.link = data?.url ?? ''
		},
	},
}
</script>

<style scoped>
.check-in-code__link {
	overflow-wrap: anywhere;
}

.check-in-code__count {
	color: var(--color-text-maxcontrast);
}
</style>
