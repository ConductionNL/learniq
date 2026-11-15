<?php

/**
 * Learniq PupilVoiceGuard unit tests.
 *
 * Covers the `zorgvraag-swv-tlv-chain` change: the DeliberationRecord
 * `scheduled → recorded` transition MUST be blocked unless the pupil's own
 * voice was heard directly or explicitly, non-silently waived with a reason
 * (2025 Wet versterking positie ouders en leerlingen in passend onderwijs
 * hoorrecht, insight 1145).
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-07-13-zorgvraag-swv-tlv-chain/tasks.md#task-3.4
 * @spec openspec/specs/learning-plan/spec.md#requirement-the-pupils-own-voice-hoorrecht-is-a-first-class-non-optional-field
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Tests\Support\GuardVerdicts;
use OCA\Learniq\Lifecycle\PupilVoiceGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for PupilVoiceGuard::check() — the DeliberationRecord
 * scheduled → recorded transition.
 */
class PupilVoiceGuardTest extends TestCase {

	use GuardVerdicts;
	/**
	 * Build a guard with a null logger.
	 *
	 * @return PupilVoiceGuard
	 */
	private function makeGuard(): PupilVoiceGuard {
		return new PupilVoiceGuard(new NullLogger());
	}//end makeGuard()

	/**
	 * Neither heard nor waived is set — the transition MUST be blocked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-13-zorgvraag-swv-tlv-chain/tasks.md#task-3.4
	 */
	public function testBlocksWhenNeitherHeardNorWaivedIsSet(): void {
		$object = [
				'id' => 'delib-1',
				'pupilVoice' => [
					'heard' => false,
					'statementNote' => null,
					'waived' => false,
					'waiverReason' => null,
				],
				'lifecycle' => 'recorded',
			];

		self::assertDenied($this->makeGuard()->check($object, 'record', ''));

	}//end testBlocksWhenNeitherHeardNorWaivedIsSet()

	/**
	 * waived is true but waiverReason is empty — the transition MUST still be
	 * blocked. An empty reason is treated identically to no reason at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-13-zorgvraag-swv-tlv-chain/tasks.md#task-3.4
	 */
	public function testBlocksWhenWaivedWithEmptyWaiverReason(): void {
		$object = [
				'id' => 'delib-2',
				'pupilVoice' => [
					'heard' => false,
					'waived' => true,
					'waiverReason' => '',
				],
				'lifecycle' => 'recorded',
			];

		self::assertDenied($this->makeGuard()->check($object, 'record', ''));

	}//end testBlocksWhenWaivedWithEmptyWaiverReason()

	/**
	 * waived is true but waiverReason is only whitespace — treated as empty.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-13-zorgvraag-swv-tlv-chain/tasks.md#task-3.4
	 */
	public function testBlocksWhenWaiverReasonIsOnlyWhitespace(): void {
		$object = [
				'id' => 'delib-2b',
				'pupilVoice' => [
					'heard' => false,
					'waived' => true,
					'waiverReason' => '   ',
				],
				'lifecycle' => 'recorded',
			];

		self::assertDenied($this->makeGuard()->check($object, 'record', ''));

	}//end testBlocksWhenWaiverReasonIsOnlyWhitespace()

	/**
	 * heard is true — the transition is allowed regardless of waived/waiverReason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-13-zorgvraag-swv-tlv-chain/tasks.md#task-3.4
	 */
	public function testAllowsWhenHeardIsTrue(): void {
		$object = [
				'id' => 'delib-3',
				'pupilVoice' => [
					'heard' => true,
					'statementNote' => 'De leerling gaf aan liever op school te blijven.',
					'waived' => false,
					'waiverReason' => null,
				],
				'lifecycle' => 'recorded',
			];

		self::assertAllowed($this->makeGuard()->check($object, 'record', ''));

	}//end testAllowsWhenHeardIsTrue()

	/**
	 * waived is true with a non-empty waiverReason — the transition is allowed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-13-zorgvraag-swv-tlv-chain/tasks.md#task-3.4
	 */
	public function testAllowsWhenWaivedWithNonEmptyWaiverReason(): void {
		$object = [
				'id' => 'delib-4',
				'pupilVoice' => [
					'heard' => false,
					'waived' => true,
					'waiverReason' => 'Leerling is 3 jaar oud; horen niet passend.',
				],
				'lifecycle' => 'recorded',
			];

		self::assertAllowed($this->makeGuard()->check($object, 'record', ''));

	}//end testAllowsWhenWaivedWithNonEmptyWaiverReason()

	/**
	 * pupilVoice is entirely missing from the object — treated as neither
	 * heard nor waived, blocking the transition.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-13-zorgvraag-swv-tlv-chain/tasks.md#task-3.4
	 */
	public function testBlocksWhenPupilVoiceIsMissing(): void {
		$object = [
				'id' => 'delib-5',
				'lifecycle' => 'recorded',
			];

		self::assertDenied($this->makeGuard()->check($object, 'record', ''));

	}//end testBlocksWhenPupilVoiceIsMissing()
}//end class
