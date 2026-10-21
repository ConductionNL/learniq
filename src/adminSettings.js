/**
 * The admin page, declared: its tabs and, per tab, the sections that render
 * on CnAdminSettingsShell. Each section names a library component by `type`
 * and carries its props; AdminRoot.vue only maps the types to components.
 *
 * Section types:
 * - `fields`: CnSettingsFieldsSection (`props` are its props).
 * - `shipped`: CnShippedConfigurationSection in a titled section.
 * - `record-list`: CnRecordListWidget in a titled section (`content`).
 * - `action-matrix`: CnActionAuthMatrix.
 * - `ai-translation-review`: learniq's own AI translation review (c).
 *
 * Plain ES module so a Node test can read it without a build step.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

const APP = '/apps/learniq'

/** The answer of the app's settings endpoint (`openregisters`, `hermiqInstalled`). */
const SETTINGS_URL = `${APP}/api/settings`

/** The rostering systems' display names; the ids are the server's. */
const ROSTER_SOURCES = [
	{ id: 'roster-zermelo', label: 'Zermelo' },
	{ id: 'roster-untis-oneroster', label: 'Untis' },
	{ id: 'roster-xedule', label: 'Xedule' },
	{ id: 'roster-timeedit', label: 'TimeEdit' },
]

const SEE_OWN = { value: 'own', label: 'Their own' }
const SEE_RELATED = { value: 'related', label: 'Those of their own lessons' }
const SEE_ALL = { value: 'all', label: 'All' }
const SEE_NONE = { value: 'none', label: 'None' }

/** The tabs, in the order of board LqInstellingen. */
export const ADMIN_TABS = [
	{ id: 'menu', label: 'Menu' },
	{ id: 'example-data', label: 'Example data' },
	{ id: 'data-exchange', label: 'Data exchange' },
	{ id: 'timetable-exchange', label: 'Timetable exchange' },
	{ id: 'lti', label: 'LTI tools' },
	{ id: 'store', label: 'Store' },
	{ id: 'app', label: 'App settings' },
	{ id: 'ai-translations', label: 'AI translations' },
	{ id: 'action-rights', label: 'Action rights' },
]

/** The sections per tab id. */
export const ADMIN_SECTIONS = {
	menu: [
		{
			type: 'fields',
			id: 'section-segment',
			props: {
				name: 'Kind of organisation',
				description: 'Learniq shows the menus that fit your kind of organisation.',
				url: `${APP}/api/setup/status`,
				saveUrl: `${APP}/api/setup/config`,
				saveMethod: 'POST',
				fields: [
					{
						key: 'segment',
						label: 'Kind of organisation',
						type: 'select',
						required: true,
						optionsUrl: `${APP}/api/setup/status`,
						optionsPath: 'segments',
						optionValue: 'id',
						optionLabel: 'label',
					},
				],
			},
		},
		{
			type: 'fields',
			id: 'section-menu-structure',
			props: {
				name: 'Menu structure',
				description: 'Choose how much the menu shows. Simple is the default.',
				url: SETTINGS_URL,
				autoSave: true,
				reloadAfterSave: true,
				savedMessage: 'Saved. People see the change the next time they open Learniq.',
				fields: [
					{
						key: 'menu_structure',
						label: 'Menu structure',
						type: 'radio',
						default: 'simple',
						options: [
							{ value: 'simple', label: 'Simple', description: 'At most ten menu entries for each role. Everything else is one step further, in settings or on a page.' },
							{ value: 'full', label: 'Full', description: 'Every entry in the menu, as it was before.' },
						],
					},
					{ key: 'menuStructureNote', type: 'status', text: 'No page is removed. Both menus open the same pages.' },
				],
			},
		},
	],
	'example-data': [
		{
			type: 'shipped',
			id: 'section-example-data',
			name: 'Example data',
			description: 'Example sets you loaded in the setup wizard. Removing a set moves its objects to the trash of OpenRegister, so you can restore them. Anything you made yourself stays.',
			props: {
				setsUrl: `${APP}/api/setup/example-sets`,
				removeSetUrl: `${APP}/api/setup/action/remove-example-set-{id}`,
				removeSetQuestion: 'Remove the example set "{set}"? Its objects move to the trash of OpenRegister. Anything you made yourself stays.',
			},
		},
	],
	'data-exchange': [
		{
			type: 'fields',
			id: 'section-data-exchange',
			props: {
				name: 'Data exchange',
				description: 'Integriq sends learniq\'s reports to DUO, OSO transfer files and hand-offs to the regional support partnership (SWV) once learniq\'s checks allow them. Follow the jobs and the parent reviews here.',
				url: SETTINGS_URL,
				fields: [
					{ key: 'openExchangeJobs', label: 'Exchange jobs', type: 'action', href: `${APP}/data-exchange/jobs` },
					{ key: 'openParentReviews', label: 'Parent reviews', type: 'action', href: `${APP}/data-exchange/parent-reviews` },
				],
			},
		},
	],
	'timetable-exchange': [
		{
			type: 'fields',
			id: 'section-timetable-exchange',
			props: {
				name: 'Timetable and SWV exchange',
				description: 'Say which group in your rostering system is which group in learniq, so an imported timetable lands on the right groups. Name the receiver that takes your support requests for the SWV.',
				url: `${APP}/api/admin/timetable-exchange`,
				saveAll: true,
				fields: [
					{
						key: 'swvReceiverId',
						label: 'SWV receiver',
						hint: 'The integriq receiver of your support requests, for example swv-kindkans. Leave empty when you send none.',
					},
					...ROSTER_SOURCES.map((source) => ({
						key: `groups-${source.id}`,
						path: `groupMaps.${source.id}`,
						label: `Groups from ${source.label}`,
						type: 'pairs',
						keyLabel: `Group code in ${source.label}`,
						valueLabel: 'Group in learniq',
						addLabel: 'Add a group',
						valueSource: { register: 'learniq', schema: 'cohort', labelField: 'name' },
						wide: true,
					})),
				],
			},
		},
		{
			type: 'fields',
			id: 'section-timetable-visibility',
			props: {
				name: 'Timetable visibility',
				description: 'Choose which timetables learners and teachers may open. Team leads and compliance officers always see all.',
				url: `${APP}/api/timetable/visibility-policy`,
				saveMethod: 'PUT',
				columns: 2,
				fields: [
					{ key: 'learnerSeesGroups', path: 'policy.learnerSeesGroups', label: 'Learners see groups', type: 'select', options: [SEE_OWN, SEE_ALL] },
					{ key: 'learnerSeesTeachers', path: 'policy.learnerSeesTeachers', label: 'Learners see teachers', type: 'select', options: [SEE_NONE, SEE_RELATED, SEE_ALL] },
					{ key: 'learnerSeesRooms', path: 'policy.learnerSeesRooms', label: 'Learners see rooms', type: 'select', options: [SEE_NONE, SEE_RELATED, SEE_ALL] },
					{ key: 'instructorSeesGroups', path: 'policy.instructorSeesGroups', label: 'Teachers see groups', type: 'select', options: [SEE_OWN, SEE_ALL] },
					{ key: 'instructorSeesTeachers', path: 'policy.instructorSeesTeachers', label: 'Teachers see teachers', type: 'select', options: [SEE_OWN, SEE_ALL] },
				],
			},
		},
		{
			type: 'fields',
			id: 'section-opening-hours',
			props: {
				name: 'Opening hours',
				description: 'The hours the rooms are open. The room use report counts from them.',
				url: `${APP}/api/reports/room-use/opening-hours`,
				saveMethod: 'PUT',
				saveAll: true,
				fields: [
					{ key: 'weekdays', path: 'openingHours.weekdays', label: 'Opening hours', type: 'week-hours', wide: true },
					{ key: 'closedOnStudyDays', path: 'openingHours.closedOnStudyDays', label: 'Rooms are closed on study days', type: 'switch' },
				],
			},
		},
	],
	lti: [
		{
			type: 'fields',
			id: 'section-lti',
			props: {
				name: 'LTI tools',
				description: 'Integriq opens external tools from a lesson and sends their grades back. Create a subscription on grade events in integriq, then enter its id here so learniq picks up the grades.',
				url: SETTINGS_URL,
				fields: [
					{
						key: 'lti_ags_subscription_id',
						label: 'Grade subscription',
						hint: 'The id of the integriq event subscription on LTI grade events. Leave empty to stop pulling grades.',
					},
				],
			},
		},
	],
	store: [
		{
			type: 'fields',
			id: 'section-course-store',
			props: {
				name: 'Course store',
				description: 'Connect a course registry so teachers can find and install courses other schools share. The registry is another learniq, for example your school board\'s.',
				url: `${APP}/api/admin/store-registry`,
				saveAll: true,
				fields: [
					{ key: 'url', label: 'Registry address', type: 'url', hint: 'For example https://store.example.nl. Leave empty to disconnect the store.' },
					{ key: 'register', label: 'Register', hint: 'Leave empty to use learniq, the usual register name.' },
					{
						key: 'token',
						label: 'Token',
						type: 'secret',
						setKey: 'tokenSet',
						clearable: true,
						clearKey: 'clearToken',
						clearLabel: 'Remove the stored token',
						hint: 'The token of an account in the instructors group on the registry. A stored token is kept until you enter a new one.',
					},
				],
			},
		},
	],
	app: [
		{
			type: 'fields',
			id: 'section-openregister',
			props: {
				name: 'OpenRegister',
				description: 'Configure the default register used by Learniq for data storage.',
				url: SETTINGS_URL,
				fields: [
					{
						key: 'register',
						label: 'Default register',
						type: 'select',
						optionsUrl: '/apps/openregister/api/registers',
						optionValue: 'slug',
						optionLabel: 'title',
						placeholder: 'Select a register…',
					},
				],
			},
		},
		{
			type: 'fields',
			id: 'section-ai-features',
			props: {
				name: 'AI features',
				description: 'EU AI Act high-risk AI-feature governance (the feature register and DPO acknowledgement) is centralised in the Hermiq app. Learniq\'s AI features are declared and acknowledged there.',
				url: SETTINGS_URL,
				fields: [
					{ key: 'openHermiq', label: 'Open the AI-feature register in Hermiq', type: 'action', href: '/apps/hermiq/ai-features', visibleWhen: { key: 'hermiqInstalled', equals: true } },
					{ key: 'hermiqMissing', type: 'status', tone: 'warning', text: 'Install and enable the Hermiq app to manage this app\'s EU AI Act high-risk AI features in the central governance register.', visibleWhen: { key: 'hermiqInstalled', notEquals: true } },
					{ key: 'differentiationNote', type: 'status', text: 'Learniq does not profile how a pupil prefers to learn. Research finds no benefit in matching lessons to such a profile (NRO Kennisrotonde, Differentiatie in de klas). Differentiate by level, goal, time and material instead, and record support needs in the group plan or the learning plan.' },
				],
			},
		},
		{
			type: 'fields',
			id: 'section-credential-signing',
			props: {
				name: 'Credential signing',
				description: 'The RS256 key pair that signs verifiable credentials. The private key is stored encrypted on the server.',
				url: `${APP}/api/settings/signing-key`,
				fields: [
					{
						key: 'configured',
						type: 'status',
						textMap: {
							true: 'Current key fingerprint: {fingerprint}',
							'*': 'No signing key yet. Credentials cannot be signed until one is generated.',
						},
						toneMap: { true: 'success', '*': 'warning' },
					},
					{
						key: 'rotate',
						label: 'Rotate signing key',
						type: 'action',
						url: `${APP}/api/settings/signing-key/rotate`,
						params: { confirm: 'true' },
						confirm: 'New credentials will be signed with a new key. Credentials issued before stay verifiable with the old key. You can rotate once a day.',
						confirmLabel: 'Rotate',
						reload: true,
					},
				],
			},
		},
		{
			type: 'fields',
			id: 'section-processing-register',
			props: {
				name: 'Processing register (AVG Art. 30)',
				description: 'Learniq records its processing of personal data in the processing register of OpenRegister. Only administrators and the privacy officer see this register.',
				url: SETTINGS_URL,
				fields: [
					{ key: 'openRegisterMissing', type: 'status', tone: 'warning', text: 'OpenRegister is not installed. The processing-activity register and Art. 30 export are provided by OpenRegister and are unavailable until it is installed.', visibleWhen: { key: 'openregisters', notEquals: true } },
					{ key: 'controllerNote', type: 'status', text: 'The school is the controller. Configure the controller identity once in OpenRegister, so it appears on every export and accountability report.', visibleWhen: { key: 'openregisters', equals: true } },
					{ key: 'openAccountability', label: 'View controller identity and accountability in OpenRegister', type: 'action', href: '/apps/openregister/api/avg/verantwoording', target: '_blank', visibleWhen: { key: 'openregisters', equals: true } },
					{ key: 'openProcessingLog', label: 'Open processing log in OpenRegister', type: 'action', href: '/apps/openregister/api/avg/verwerkingen?register=learniq', target: '_blank', visibleWhen: { key: 'openregisters', equals: true } },
					{ key: 'openSubjectExtract', label: 'Per-subject extract', type: 'action', href: '/apps/openregister/api/avg/verwerkingen/betrokkene?register=learniq', target: '_blank', visibleWhen: { key: 'openregisters', equals: true } },
				],
			},
		},
		{
			type: 'record-list',
			id: 'section-processing-activities',
			name: 'Processing activities',
			description: 'Learniq declares these processing activities. They arrive in OpenRegister as drafts; the privacy officer checks and activates them there.',
			content: {
				// OpenRegister owns the processing register (ADR-022, OR-PA-7):
				// the list reads its activities, scoped to learniq's register.
				endpointSource: { url: '/apps/openregister/api/avg/processing-activities?register=learniq', responsePath: 'results' },
				presentation: 'rows',
				title: '{naam}',
				meta: '{doelbinding}',
				trailing: '{rechtsgrond}',
				valueLabels: {
					rechtsgrond: {
						consent: 'Consent',
						contract: 'Contract',
						'legal-obligation': 'Legal obligation',
						'vital-interest': 'Vital interest',
						'public-task': 'Public task',
						'legitimate-interest': 'Legitimate interest',
					},
				},
				emptyText: 'Learniq declares no processing activities.',
			},
		},
		{
			type: 'fields',
			id: 'section-privacy-governance',
			props: {
				name: 'Privacy governance',
				description: 'Record the processing agreement of the Privacy Covenant and the privacy notice.',
				url: `${APP}/api/admin/compliance`,
				reloadAfterSave: true,
				columns: 2,
				fields: [
					{ key: 'privacyconvenantSigned', label: 'The processing agreement of the Privacy Covenant is signed', type: 'switch', wide: true },
					{ key: 'signedNote', type: 'status', text: 'Signed on {privacyconvenantSignedAt|date}.', visibleWhen: { key: 'privacyconvenantSignedAt', empty: false } },
					{ key: 'verwerkersovereenkomstUrl', label: 'Link to the processing agreement', type: 'url', placeholder: 'https://' },
					{ key: 'privacybijsluiterUrl', label: 'Link to the privacy notice', type: 'url', placeholder: 'https://' },
					{ key: 'privacybijsluiterVersion', label: 'Version of the privacy notice' },
					{ key: 'reviewedNote', type: 'status', text: 'Last saved by {lastReviewedByName} on {lastReviewedAt|date}.', visibleWhen: { key: 'lastReviewedAt', empty: false } },
				],
			},
		},
		{
			type: 'record-list',
			id: 'section-privacy-requests',
			name: 'Recent privacy requests',
			description: 'The latest requests for access, correction or deletion.',
			content: {
				// OpenRegister's own register of privacy requests, newest five.
				endpointSource: {
					url: '/apps/openregister/api/objects/data-subject-requests/dataSubjectRequest?_limit=5&_order[@self.created]=desc',
					responsePath: 'results',
				},
				presentation: 'rows',
				title: '{type}',
				meta: '{subjectId}',
				valueLabels: {
					type: {
						access: 'Access request',
						rectification: 'Correction request',
						erasure: 'Deletion request',
						restriction: 'Restriction request',
						portability: 'Portability request',
						objection: 'Objection',
					},
				},
				pill: {
					field: 'status',
					labels: {
						received: 'Received',
						verifying: 'Verifying',
						'in-progress': 'In progress',
						'evidence-collection': 'Collecting evidence',
						'denial-drafted': 'Refusal drafted',
						fulfilled: 'Fulfilled',
						refused: 'Refused',
						closed: 'Closed',
					},
					colorMap: { received: 'warning', 'in-progress': 'purple', fulfilled: 'success', refused: 'error', closed: 'default' },
				},
				emptyText: 'No privacy requests logged yet.',
				headerLink: { label: 'Open the full list', href: `${APP}/compliance/data-subject-requests` },
			},
		},
	],
	'ai-translations': [
		{ type: 'ai-translation-review', id: 'section-ai-translations' },
	],
	'action-rights': [
		{
			type: 'action-matrix',
			id: 'section-action-rights',
			props: {
				endpoint: `${APP}/api/admin/action-matrix`,
				name: 'Action authorization',
				description: 'Decide which Nextcloud groups may invoke each Learniq action. Admins always pass. Every action is for admins only until you tick a group.',
			},
		},
	],
}

/**
 * Every section, in tab order, with its tab id.
 *
 * @return {Array<object>} The sections, each with `tab`.
 */
export function allSections() {
	return ADMIN_TABS.flatMap((tab) => (ADMIN_SECTIONS[tab.id] || []).map((section) => ({ ...section, tab: tab.id })))
}

/**
 * The settings-page address of a section, for links from elsewhere.
 *
 * @param {string} sectionId The section's id.
 * @return {string} `/settings/admin/learniq?tab=<tab>#<id>`, or '' for an unknown id.
 */
export function sectionAddress(sectionId) {
	const section = allSections().find((candidate) => candidate.id === sectionId)
	return section ? `/settings/admin/learniq?tab=${section.tab}#${section.id}` : ''
}
