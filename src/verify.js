// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Entry for the public certificate check page
// (certificate-verification-and-key-rotation). A separate, small bundle: the
// page has no signed-in user and needs none of the app shell, the manifest or
// the stores.
// @spec openspec/changes/certificate-verification-and-key-rotation/specs/certification/spec.md#requirement-the-verification-link-opens-a-page-that-says-in-words-what-was-checked

import { loadState } from '@nextcloud/initial-state'
import { createApp } from 'vue'
import CredentialVerifyPage from './views/CredentialVerifyPage.vue'

const host = document.getElementById('learniq-verify')
if (host) {
	createApp(CredentialVerifyPage, {
		credentialId: loadState('learniq', 'verify-id', ''),
		schoolPhone: loadState('learniq', 'school-phone', ''),
	}).mount(host)
}
