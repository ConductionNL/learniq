// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Entry for the public hall-screen page (timetabling-display-screens). A
// separate, small bundle: the page has no signed-in user and needs none of
// the app shell, the manifest or the stores.
// @spec openspec/specs/personal-timetable/spec.md#requirement-a-display-screen-shows-todays-lessons-and-changes-without-a-signed-in-user

import { loadState } from '@nextcloud/initial-state'
import { createApp } from 'vue'
import DisplayScreenView from './views/DisplayScreenView.vue'

const host = document.getElementById('learniq-display')
if (host) {
	createApp(DisplayScreenView, {
		token: loadState('learniq', 'display-token', ''),
	}).mount(host)
}
