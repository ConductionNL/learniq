<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Learniq settings page — the single custom Vue view in the v0.1 wedge.
 Declared in manifest.json as type: "custom", component: "LearniqSettings".
 CnAppRoot resolves this name against the customComponents registry at runtime.

 Sections:
   1. OpenRegister default register picker (IAppConfig key: default_register)
   2. AI features read-only table (sourced from AiFeature schema objects via OR)
   3. Credential signing key widget (calls CredentialSigningController — ADR-031)
-->
<template>
	<div class="scholiq-settings">
		<h2 v-if="!inDialog" class="scholiq-settings__title">
			{{ t('learniq', 'Learniq Settings') }}
		</h2>

		<!-- Section 1: OpenRegister default register -->
		<NcSettingsSection
			:name="t('learniq', 'OpenRegister')"
			:description="
				t(
					'learniq',
					'Configure the default register used by Learniq for data storage.',
				)
			">
			<div class="scholiq-settings__field">
				<label for="scholiq-default-register">{{
					t('learniq', 'Default register')
				}}</label>
				<NcSelect
					id="scholiq-default-register"
					v-model="defaultRegister"
					:options="registerOptions"
					:loading="registersLoading"
					:placeholder="t('learniq', 'Select a register…')"
					:aria-label-combobox="t('learniq', 'Default register')"
					label="title"
					@update:modelValue="saveDefaultRegister" />
			</div>
		</NcSettingsSection>

		<!-- Section 2: AI features — governance delegated to Hermiq (ADR-005). -->
		<NcSettingsSection
			:name="t('learniq', 'AI Features')"
			:description="
				t(
					'learniq',
					'EU AI Act high-risk AI-feature governance (the feature register and DPO acknowledgement) is centralised in the Hermiq app, the fleet-wide home for AI-feature governance. Learniq\'s AI features are declared and acknowledged there.',
				)
			">
			<div v-if="hermiqInstalled" class="scholiq-settings__field">
				<NcButton variant="secondary" @click="openHermiqAiFeatures">
					<template #icon>
						<OpenInNew :size="20" />
					</template>
					{{ t('learniq', 'Open the AI-feature register in Hermiq') }}
				</NcButton>
			</div>
			<NcNoteCard v-else type="warning">
				{{
					t(
						'learniq',
						"Install and enable the Hermiq app to manage this app's EU AI Act high-risk AI features in the central governance register.",
					)
				}}
			</NcNoteCard>
		</NcSettingsSection>

		<!-- Section 3: Credential signing key -->
		<NcSettingsSection
			:name="t('learniq', 'Credential Signing')"
			:description="
				t(
					'learniq',
					'RS256 key pair used to sign verifiable credentials. Stored encrypted in Nextcloud\'s keystore.',
				)
			">
			<div class="scholiq-settings__field">
				<NcButton
					:disabled="signingKeyLoading"
					variant="secondary"
					@click="rotateSigningKey">
					<template #icon>
						<NcLoadingIcon v-if="signingKeyLoading" :size="20" />
					</template>
					{{ t('learniq', 'Rotate signing key') }}
				</NcButton>
				<p v-if="signingKeyMessage" class="scholiq-settings__message">
					{{ signingKeyMessage }}
				</p>
			</div>
		</NcSettingsSection>

		<!-- Section 4: AVG Art. 30 processing-activity register (provided by OpenRegister) -->
		<NcSettingsSection
			v-if="isAdmin"
			:name="t('learniq', 'Processing Activity Register (AVG Art. 30)')"
			:description="
				t(
					'learniq',
					'Learniq\'s personal-data processing activities are recorded in OpenRegister\'s platform processing-activity register. The Art. 30 register, per-access logging, exports, and access control are provided by OpenRegister; Learniq contributes its activity catalogue and surfaces it here. Access is restricted to administrators and the privacy officer (FG); non-privileged users are denied by OpenRegister.',
				)
			">
			<div v-if="!openRegisterInstalled" class="scholiq-settings__field">
				<NcNoteCard type="warning">
					{{
						t(
							'learniq',
							'OpenRegister is not installed. The processing-activity register and Art. 30 export are provided by OpenRegister and are unavailable until it is installed.',
						)
					}}
				</NcNoteCard>
			</div>
			<template v-else>
				<!-- Controller-identity record state + accountability prompt (OR-PA-1) -->
				<div class="scholiq-settings__field">
					<NcNoteCard type="info">
						{{
							t(
								'learniq',
								'The verwerkingsverantwoordelijke (controller) identity for the Art. 30 register is maintained centrally in OpenRegister. The school is the controller; configure it once in OpenRegister so it appears on every export and accountability report.',
							)
						}}
					</NcNoteCard>
					<NcButton
						variant="secondary"
						@click="openProcessingAccountability">
						<template #icon>
							<OpenInNew :size="20" />
						</template>
						{{
							t(
								'learniq',
								'View controller identity & accountability in OpenRegister',
							)
						}}
					</NcButton>
				</div>

				<!-- Activity catalogue (the ten Learniq categories) -->
				<div class="scholiq-settings__field">
					<div class="scholiq-settings__catalogue-label">
						{{ t('learniq', 'Learniq processing activities') }}
					</div>
					<div class="scholiq-settings__message">
						{{
							t(
								'learniq',
								'Learniq declares ten processing activities. They are seeded into OpenRegister as drafts when the Learniq register configuration is imported; the privacy officer reviews, amends, and activates them in OpenRegister to make Learniq processing attributable in the Art. 30 register.',
							)
						}}
					</div>
					<ul class="scholiq-settings__activities">
						<li
							v-for="activity in processingActivities"
							:key="activity.code">
							<strong>{{ activity.name }}</strong>
							<span class="scholiq-settings__activity-meta">{{
								activity.purpose
							}}</span>
							<span class="scholiq-settings__activity-meta">{{
								t('learniq', 'Legal basis: {basis}', {
									basis: activity.basis,
								})
							}}</span>
						</li>
					</ul>
				</div>

				<!-- Per-access log + per-subject extract (delegates to OpenRegister, OR-PA-7/8) -->
				<div class="scholiq-settings__field">
					<div class="scholiq-settings__catalogue-label">
						{{ t('learniq', 'Processing log & Art. 30 export') }}
					</div>
					<div class="scholiq-settings__message">
						{{
							t(
								'learniq',
								"The per-access processing log and the per-subject (betrokkene) inzage extract are produced by OpenRegister, scoped to Learniq's register, and never contain literal personal data beyond what the data subject is entitled to.",
							)
						}}
					</div>
					<div class="scholiq-settings__activity-actions">
						<NcButton variant="primary" @click="openProcessingLog">
							<template #icon>
								<FileExportOutline :size="20" />
							</template>
							{{ t('learniq', 'Open processing log in OpenRegister') }}
						</NcButton>
						<NcButton variant="secondary" @click="openSubjectExtract">
							<template #icon>
								<AccountSearchOutline :size="20" />
							</template>
							{{ t('learniq', 'Per-subject (betrokkene) extract') }}
						</NcButton>
					</div>
					<div class="scholiq-settings__message">
						<em>{{
							t(
								'learniq',
								'Note: the per-access read log and per-subject extract are available now. The aggregate Art. 30 register export to JSON/CSV/PDF is a forthcoming OpenRegister capability; until it lands, the compliance audit pack includes the read-log query result as verwerkingsregister.csv.',
							)
						}}</em>
					</div>
				</div>
			</template>
		</NcSettingsSection>
	</div>
</template>

<script>
import { getRequestToken } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcSettingsSection,
} from '@nextcloud/vue'
import AccountSearchOutline from 'vue-material-design-icons/AccountSearchOutline.vue'
import FileExportOutline from 'vue-material-design-icons/FileExportOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'

export default {
	name: 'LearniqSettings',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcSettingsSection,
		OpenInNew,
		FileExportOutline,
		AccountSearchOutline,
	},

	props: {
		/**
		 * When true, suppresses the standalone page `<h2>` title because the
		 * NcAppSettingsDialog already displays the app name in its own header.
		 */
		inDialog: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			defaultRegister: null,
			registerOptions: [],
			registersLoading: false,
			signingKeyLoading: false,
			signingKeyMessage: '',
			isAdmin: false,
			openRegisterInstalled: false,
		}
	},

	computed: {
		/**
		 * Whether the Hermiq app (the fleet-wide AI-feature governance home) is
		 * enabled on this instance. Drives the delegated "AI Features" section:
		 * link to Hermiq's register when present, otherwise an install notice.
		 *
		 * @spec openspec/changes/ai-feature-delegate-to-hermiq/specs/ai-surface/spec.md#requirement-req-sai-004-the-system-shall-surface-ai-feature-governance-from-settings-via-hermiq
		 * @return {boolean} True when Hermiq is enabled.
		 */
		hermiqInstalled() {
			return window.OC?.appswebroots?.hermiq !== undefined
		},

		/**
		 * The ten Learniq processing activities surfaced in the AVG Art. 30
		 * compliance section. Mirrors the x-openregister-processing catalogue
		 * annotations in lib/Settings/scholiq_register.json (authoring source of
		 * truth); the register itself is owned and rendered by OpenRegister.
		 *
		 * @return {Array<{code: string, name: string, purpose: string, basis: string}>} Catalogue rows.
		 * @spec openspec/specs/avg-verwerkingsregister/spec.md
		 */
		processingActivities() {
			return [
				{
					code: 'scholiq-learner-administration',
					name: t(
						'learniq',
						'Learner administration (leerlingadministratie)',
					),

					purpose: t(
						'learniq',
						'Maintain the learner record (incl. encrypted BSN, ECK iD, SchoolID) to deliver education and meet statutory reporting.',
					),

					basis: t('learniq', 'public-task'),
				},
				{
					code: 'scholiq-attendance-leerplicht',
					name: t('learniq', 'Attendance and leerplicht reporting'),
					purpose: t(
						'learniq',
						'Register attendance and report verzuim to the leerplichtambtenaar / DUO.',
					),

					basis: t('learniq', 'legal-obligation'),
				},
				{
					code: 'scholiq-grading-assessment',
					name: t('learniq', 'Grading and assessment'),
					purpose: t(
						'learniq',
						'Administer assessments and record grades and final marks.',
					),

					basis: t('learniq', 'public-task'),
				},
				{
					code: 'scholiq-attestations',
					name: t(
						'learniq',
						'Compliance training and signed attestations',
					),

					purpose: t(
						'learniq',
						'Record completed mandatory training and capture signed attestations (incl. actor IP) as legal evidence.',
					),

					basis: t('learniq', 'legal-obligation'),
				},
				{
					code: 'scholiq-credentialing',
					name: t('learniq', 'Credentialing and certification'),
					purpose: t(
						'learniq',
						'Issue, verify, and revoke verifiable credentials (EDCI / Open Badges 3.0).',
					),

					basis: t('learniq', 'contract'),
				},
				{
					code: 'scholiq-data-exchange',
					name: t('learniq', 'Data exchange with external parties'),
					purpose: t(
						'learniq',
						'Exchange learner and result data with DUO/BRON-ROD, OSO, municipality, and HR systems.',
					),

					basis: t('learniq', 'legal-obligation'),
				},
				{
					code: 'scholiq-ai-features',
					name: t('learniq', 'AI-assisted learning features'),
					purpose: t(
						'learniq',
						'Operate adaptive learning paths and record EU AI Act high-risk decision traces.',
					),

					basis: t('learniq', 'consent'),
				},
				{
					code: 'scholiq-pupil-dossier-notes',
					name: t(
						'learniq',
						'Pupil dossier notes (leerlingdossier notities)',
					),

					purpose: t(
						'learniq',
						"Record routine staff observations, conversations, and concerns about a learner as part of the school's ongoing pastoral/mentoring duty of care.",
					),

					basis: t('learniq', 'public-task'),
				},
				{
					code: 'scholiq-behaviour-incidents',
					name: t('learniq', 'Behaviour incidents (gedragsincidenten)'),
					purpose: t(
						'learniq',
						'Record behaviour incidents involving a learner, their follow-up handling, and an optional escalation into a formal support request.',
					),

					basis: t('learniq', 'public-task'),
				},
				{
					code: 'scholiq-wellbeing-checkins',
					name: t(
						'learniq',
						'Wellbeing check-ins (welbevinden check-ins)',
					),

					purpose: t(
						'learniq',
						"Record a learner's own periodic self-reported mood/wellbeing signal, visible to their mentor.",
					),

					basis: t('learniq', 'public-task'),
				},
			]
		},
	},

	/**
	 * Load the register options and settings status in parallel on mount.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/archive/retrofit-2026-05-25-app-shell-settings/tasks.md#tasks
	 */
	async created() {
		await Promise.all([this.fetchRegisters(), this.fetchSettingsStatus()])
	},

	methods: {
		/**
		 * Load available registers from OpenRegister for the default-register picker.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/retrofit-2026-05-25-app-shell-settings/tasks.md#tasks
		 */
		async fetchRegisters() {
			this.registersLoading = true
			try {
				const response = await fetch(
					generateUrl('/apps/openregister/api/registers'),
					{
						headers: { requesttoken: getRequestToken() },
					},
				)
				if (response.ok) {
					const data = await response.json()
					this.registerOptions = data.results || data
				}
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('[LearniqSettings] fetchRegisters failed:', error)
			} finally {
				this.registersLoading = false
			}
		},

		/**
		 * Load the settings status (admin flag + whether OpenRegister is
		 * installed) from the Learniq settings API; both gate the AVG Art. 30
		 * section. AI-feature governance is delegated to Hermiq, so this no
		 * longer reads any AiFeature objects.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/retrofit-2026-05-25-app-shell-settings/tasks.md#tasks
		 */
		async fetchSettingsStatus() {
			try {
				const response = await fetch(
					generateUrl('/apps/learniq/api/settings'),
					{
						headers: { requesttoken: getRequestToken() },
					},
				)
				if (response.ok) {
					const data = await response.json()
					// The settings API reports admin status and whether
					// OpenRegister is installed; both gate the AVG Art. 30 section.
					this.isAdmin = !!data.isAdmin
					this.openRegisterInstalled = !!data.openregisters
				}
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('[LearniqSettings] fetchSettingsStatus failed:', error)
			}
		},

		/**
		 * Persist the selected default register to IAppConfig.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/retrofit-2026-05-25-app-shell-settings/tasks.md#tasks
		 */
		async saveDefaultRegister() {
			if (!this.defaultRegister) return
			try {
				await fetch(generateUrl('/apps/learniq/api/settings'), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: getRequestToken(),
					},
					body: JSON.stringify({
						default_register:
							this.defaultRegister.slug || this.defaultRegister,
					}),
				})
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('[LearniqSettings] saveDefaultRegister failed:', error)
			}
		},

		/**
		 * Rotate the RS256 credential signing key pair via the backend endpoint.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/retrofit-2026-05-25-app-shell-settings/tasks.md#tasks
		 */
		async rotateSigningKey() {
			this.signingKeyLoading = true
			this.signingKeyMessage = ''
			try {
				const response = await fetch(
					generateUrl('/apps/learniq/api/settings/load'),
					{
						method: 'POST',
						headers: { requesttoken: getRequestToken() },
					},
				)
				if (response.ok) {
					this.signingKeyMessage = this.t(
						'learniq',
						'Signing key rotated successfully.',
					)
				} else {
					this.signingKeyMessage = this.t(
						'learniq',
						'Failed to rotate signing key.',
					)
				}
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('[LearniqSettings] rotateSigningKey failed:', error)
				this.signingKeyMessage = this.t(
					'learniq',
					'An error occurred while rotating the signing key.',
				)
			} finally {
				this.signingKeyLoading = false
			}
		},

		/**
		 * Open Hermiq's central EU AI Act AI-feature governance register.
		 * AI-feature governance is delegated to Hermiq (ADR-005), so this opens
		 * Hermiq's `/ai-features` register via a full navigation (a different
		 * Nextcloud app, so no in-app router). Only shown when Hermiq is enabled.
		 *
		 * @return {void}
		 * @spec openspec/changes/ai-feature-delegate-to-hermiq/specs/ai-surface/spec.md
		 */
		openHermiqAiFeatures() {
			window.location.href = generateUrl('/apps/hermiq') + '/ai-features'
		},

		/**
		 * Open OpenRegister's AVG controller-identity & accountability report
		 * (verantwoording). The record and report are OpenRegister's (OR-PA-1);
		 * Learniq only deep-links.
		 *
		 * @return {void}
		 * @spec openspec/specs/avg-verwerkingsregister/spec.md
		 */
		openProcessingAccountability() {
			window.open(
				generateUrl('/apps/openregister/api/avg/verantwoording'),
				'_blank',
			)
		},

		/**
		 * Open OpenRegister's AVG per-access processing log (verwerkingenlogging)
		 * scoped to Learniq's register. The log, export, and access control are
		 * provided by OpenRegister (OR-PA-7/OR-PA-8); Learniq only deep-links.
		 *
		 * @return {void}
		 * @spec openspec/specs/avg-verwerkingsregister/spec.md
		 */
		openProcessingLog() {
			window.open(
				generateUrl(
					'/apps/openregister/api/avg/verwerkingen?register=learniq',
				),
				'_blank',
			)
		},

		/**
		 * Open OpenRegister's per-subject (betrokkene) inzage extract endpoint,
		 * scoped to Learniq's register. Produced and gated by OpenRegister.
		 *
		 * @return {void}
		 * @spec openspec/specs/avg-verwerkingsregister/spec.md
		 */
		openSubjectExtract() {
			window.open(
				generateUrl(
					'/apps/openregister/api/avg/verwerkingen/betrokkene?register=learniq',
				),
				'_blank',
			)
		},
	},
}
</script>

<style scoped>
.scholiq-settings {
	padding: 20px;
	max-width: 900px;
}

.scholiq-settings__title {
	font-size: 1.5em;
	font-weight: bold;
	margin-bottom: 24px;
}

.scholiq-settings__field {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 400px;
}

.scholiq-settings__loading {
	display: flex;
	justify-content: center;
	padding: 24px;
}

.scholiq-settings__table {
	width: 100%;
	border-collapse: collapse;
}

.scholiq-settings__table th,
.scholiq-settings__table td {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.scholiq-settings__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.scholiq-settings__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 0.8em;
	font-weight: 500;
}

.scholiq-settings__badge--enabled {
	background: var(--color-success);
	color: #fff;
}

.scholiq-settings__badge--disabled {
	background: var(--color-border-dark);
	color: var(--color-text-light);
}

.scholiq-settings__message {
	margin-top: 8px;
	color: var(--color-text-maxcontrast);
}

.scholiq-settings__catalogue-label {
	font-weight: 600;
	margin-top: 8px;
}

.scholiq-settings__activities {
	list-style: none;
	margin: 8px 0 0;
	padding: 0;
}

.scholiq-settings__activities li {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
}

.scholiq-settings__activity-meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.scholiq-settings__activity-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 4px 0;
}
</style>
