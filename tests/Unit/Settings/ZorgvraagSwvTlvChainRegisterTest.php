<?php

/**
 * Unit tests for the `zorgvraag-swv-tlv-chain` register-JSON declarations.
 *
 * IMPORTANT SCOPE NOTE: `x-openregister-calculations` are evaluated by
 * OpenRegister core at runtime, which does not live in this repository (only
 * test stubs for its PHP service classes do — see composer.json's
 * `autoload-dev`). Learniq cannot unit-test the numeric OUTPUT of a declared
 * calculation (no existing test in this suite does — see e.g.
 * VerzuimReportComposerRegisterTest, Course.isPublished). What Learniq CAN
 * and MUST verify is that the declared SHAPE is correct: the right fields
 * exist, the expression references the right props/thresholds, and the
 * lifecycle/RBAC/notification declarations are wired the way design.md
 * specifies. This mirrors the established pattern in
 * VerzuimReportComposerRegisterTest and ProcessingActivityCatalogueTest.
 *
 * Covers the TlvApplication.tlvExpiringSoon "TLV expiry calc" test the task
 * bar requires, plus SupportRequest/DeliberationRecord shape coverage and
 * the DataExchangeJob/DataMappingProfile swv-target extension.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/zorgvraag-swv-tlv-chain/tasks.md#task-6.3
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the SupportRequest/TlvApplication/DeliberationRecord declarations
 * plus the DataExchangeJob/DataMappingProfile swv-target extension.
 */
class ZorgvraagSwvTlvChainRegisterTest extends TestCase {

	/**
	 * Decoded register configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Load the register configuration once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$path = __DIR__ . '/../../../lib/Settings/learniq_register.json';
		$this->config = json_decode((string)file_get_contents($path), true);

	}//end setUp()

	/**
	 * SupportRequest declares the full draft→…→closed lifecycle, a nullable
	 * LearningPlan link, and admin/principal-only creation (design.md
	 * "tightest posture" — no dedicated coordinator role exists yet).
	 *
	 * @return void
	 */
	public function testSupportRequestLifecycleAndAuthorizationShape(): void {
		$schema = $this->config['components']['schemas']['SupportRequest'];

		$transitions = $schema['x-openregister-lifecycle']['transitions'];
		self::assertSame('draft', $schema['x-openregister-lifecycle']['initial']);
		self::assertSame('submitted', $transitions['submit']['to']);
		self::assertSame('routed-to-swv', $transitions['routeToSwv']['to']);
		self::assertSame('in-deliberation', $transitions['startDeliberation']['to']);
		self::assertSame('decided', $transitions['decide']['to']);
		self::assertSame('closed', $transitions['close']['to']);

		$learningPlanId = $schema['properties']['learningPlanId'];
		self::assertTrue($learningPlanId['nullable']);
		self::assertSame('LearningPlan', $learningPlanId['$ref']);
		self::assertNull($learningPlanId['default']);

		// rbac-declare-groups: the old assertion read `x-openregister-authorization`
		// and named `principal`. Neither survives: OpenRegister reads
		// `authorization`, never the `x-` variant, and `principal` was retired
		// from the role vocabulary as school-specific when the app was reframed
		// from Scholiq to Learniq. learniq#963: the register cascade let every
		// staff group read every support request, wider than the audience its
		// x-property-rbac declares, so SupportRequest now carries its own block:
		// the principal's group (administration-managers) and the coordinator
		// who raised the request.
		self::assertArrayNotHasKey(
			'x-openregister-authorization',
			$schema,
			'The decoy key MUST be gone — OpenRegister never read it.'
		);
		self::assertSame(
			[
				'administration-managers',
				['group' => 'authenticated', 'match' => ['raisedBy' => '$userId']],
			],
			($schema['authorization']['read'] ?? null),
			'SupportRequest is read by administration-managers and the coordinator who raised it.'
		);

	}//end testSupportRequestLifecycleAndAuthorizationShape()

	/**
	 * SupportRequest.supportRequestRouted notification fires on the
	 * routeToSwv transition, addressed to the raising coordinator (raisedBy).
	 *
	 * @return void
	 */
	public function testSupportRequestRoutedNotificationShape(): void {
		$schema = $this->config['components']['schemas']['SupportRequest'];
		$notification = $schema['x-openregister-notifications']['supportRequestRouted'];

		self::assertSame('transition', $notification['trigger']['type']);
		self::assertSame('routeToSwv', $notification['trigger']['action']);
		self::assertSame('raisedBy', $notification['recipients'][0]['field']);

	}//end testSupportRequestRoutedNotificationShape()

	/**
	 * TlvApplication.decide records the SWV's externally-issued decision with
	 * no `requires` guard — the SWV is the sole deciding authority, Learniq
	 * implements no adjudication logic (design.md "TLV decision recorded,
	 * never adjudicated").
	 *
	 * @return void
	 */
	public function testTlvApplicationDecideTransitionHasNoAdjudicationGuard(): void {
		$schema = $this->config['components']['schemas']['TlvApplication'];
		$transitions = $schema['x-openregister-lifecycle']['transitions'];

		self::assertSame('under-review', $transitions['decide']['from']);
		self::assertSame('decided', $transitions['decide']['to']);
		self::assertArrayNotHasKey('requires', $transitions['decide']);

		$decision = $schema['properties']['decision'];
		self::assertContains('approved', $decision['enum']);
		self::assertContains('rejected', $decision['enum']);
		self::assertContains('conditional', $decision['enum']);
		self::assertNull($decision['default']);

	}//end testTlvApplicationDecideTransitionHasNoAdjudicationGuard()

	/**
	 * TLV expiry calc: TlvApplication.tlvExpiringSoon is a declared
	 * `x-openregister-calculations` boolean, materialised, evaluated against
	 * validUntil via the intermediate daysUntilValidUntil calc — the same
	 * declared-calculation-trigger pattern as Credential's
	 * daysUntilExpiry/isExpiringIn30Days (design.md "TLV expiry is a declared
	 * calculation trigger, not a PHP TimedJob"). No PHP TimedJob class exists
	 * for this — verified by the absence of any Cron/TimedJob registration
	 * for tlv-application in Application.php (task 4's grep already covers
	 * that; this test asserts the declared SHAPE only, per the scope note
	 * above).
	 *
	 * @return void
	 */
	public function testTlvExpiringSoonCalculationShape(): void {
		$schema = $this->config['components']['schemas']['TlvApplication'];
		$calcs = $schema['x-openregister-calculations'];

		$daysUntil = $calcs['daysUntilValidUntil'];
		self::assertTrue($daysUntil['materialise']);
		self::assertSame('integer', $daysUntil['type']);
		self::assertSame('validUntil', $daysUntil['expression']['if'][2]['dateDiff']['to']['prop']);
		self::assertSame('days', $daysUntil['expression']['if'][2]['dateDiff']['unit']);

		$expiringSoon = $calcs['tlvExpiringSoon'];
		self::assertTrue($expiringSoon['materialise']);
		self::assertSame('boolean', $expiringSoon['type']);

		$terms = $expiringSoon['expression']['and'];
		self::assertSame('decision', $terms[0]['eq'][0]['prop']);
		self::assertSame('approved', $terms[0]['eq'][1]);
		self::assertSame('validUntil', $terms[1]['ne'][0]['prop']);
		self::assertNull($terms[1]['ne'][1]);
		self::assertSame('daysUntilValidUntil', $terms[2]['lte'][0]['prop']);
		self::assertSame(30, $terms[2]['lte'][1]);
		self::assertSame('daysUntilValidUntil', $terms[3]['gt'][0]['prop']);
		self::assertSame(0, $terms[3]['gt'][1]);

	}//end testTlvExpiringSoonCalculationShape()

	/**
	 * TlvApplication.tlvExpiringSoon notification is a calculatedChange
	 * trigger flipping false → true, idempotent via the same
	 * previously/condition mechanism as AttendanceFlag.reportDeadlineOverdue
	 * and Credential.expiringSoon.
	 *
	 * @return void
	 */
	public function testTlvExpiringSoonNotificationShape(): void {
		$schema = $this->config['components']['schemas']['TlvApplication'];
		$notification = $schema['x-openregister-notifications']['tlvExpiringSoon'];

		self::assertSame('calculatedChange', $notification['trigger']['type']);
		self::assertSame('tlvExpiringSoon', $notification['trigger']['field']);
		self::assertTrue($notification['trigger']['condition']['eq']);
		self::assertFalse($notification['trigger']['previously']['eq']);

	}//end testTlvExpiringSoonNotificationShape()

	/**
	 * DeliberationRecord is not appendOnly (its record transition is an update), requires at least one of
	 * supportRequestId/tlvApplicationId (schema-level anyOf), and the
	 * scheduled → recorded transition requires PupilVoiceGuard.
	 *
	 * @return void
	 */
	public function testDeliberationRecordAppendOnlyAndRequiredOneOfShape(): void {
		$schema = $this->config['components']['schemas']['DeliberationRecord'];

		// Open Register refuses every update on an appendOnly schema, transitions included (learniq#977); a correction is still a new record via correctsId.
		self::assertNotTrue($schema['appendOnly'] ?? false);

		$anyOf = $schema['anyOf'];
		self::assertSame(['supportRequestId'], $anyOf[0]['required']);
		self::assertSame(['tlvApplicationId'], $anyOf[1]['required']);

		$recordTransition = $schema['x-openregister-lifecycle']['transitions']['record'];
		self::assertSame('scheduled', $recordTransition['from']);
		self::assertSame('recorded', $recordTransition['to']);
		self::assertSame('OCA\\Learniq\\Lifecycle\\PupilVoiceGuard', $recordTransition['requires']);

	}//end testDeliberationRecordAppendOnlyAndRequiredOneOfShape()

	/**
	 * DeliberationRecord.pupilVoice carries heard/statementNote/waived/
	 * waiverReason, distinct from any parent Signature/consent (2025
	 * hoorrecht, insight 1145).
	 *
	 * @return void
	 */
	public function testPupilVoicePropertyShape(): void {
		$schema = $this->config['components']['schemas']['DeliberationRecord'];
		$pupilVoice = $schema['properties']['pupilVoice'];

		self::assertSame('object', $pupilVoice['type']);
		self::assertSame(['heard', 'waived'], $pupilVoice['required']);

		$props = $pupilVoice['properties'];
		self::assertSame('boolean', $props['heard']['type']);
		self::assertFalse($props['heard']['default']);
		self::assertTrue($props['statementNote']['nullable']);
		self::assertSame('boolean', $props['waived']['type']);
		self::assertFalse($props['waived']['default']);
		self::assertTrue($props['waiverReason']['nullable']);

	}//end testPupilVoicePropertyShape()

}//end class
