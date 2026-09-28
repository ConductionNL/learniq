<?php

/**
 * Learniq PokParentSignatureStampAction unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle\Action
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pok-signature-parent-role/specs/bpv/spec.md#scenario-the-signing-flow-asks-for-the-parent
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle\Action;

use OCA\Learniq\Lifecycle\Action\PokParentSignatureStampAction;
use OCA\Learniq\Service\PokParentSignatureRule;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PokParentSignatureStampAction::execute().
 */
class PokParentSignatureStampActionTest extends TestCase {

	/**
	 * Build the action over a rule that answers $verdict, recording what it was asked.
	 *
	 * @param array{required: bool, reason: string, parentIds: array<int, string>} $verdict The rule's answer.
	 * @param array<int, array<string, mixed>> $signatures PokSignature rows on the version.
	 * @param array<int, string|null> $askedWith Receives the studentSignedAt the rule was asked with.
	 *
	 * @return PokParentSignatureStampAction
	 */
	private function makeAction(array $verdict, array $signatures, array &$askedWith): PokParentSignatureStampAction {
		$rule = $this->createMock(PokParentSignatureRule::class);
		$rule->method('evaluate')->willReturnCallback(
			static function (array $pok, ?string $studentSignedAt) use ($verdict, &$askedWith): array {
				$askedWith[] = $studentSignedAt;
				return $verdict;
			}
		);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config) use ($signatures): array {
				self::assertSame('pok-signature', $config['filters']['schema']);
				self::assertSame('pok-1', $config['filters']['subjectId']);
				self::assertSame(2, $config['filters']['subjectVersion']);
				return $signatures;
			}
		);

		return new PokParentSignatureStampAction($rule, $objectService);
	}//end makeAction()

	/**
	 * A minor's POK gets the flag when signatures are requested; the rule is
	 * asked with no student signature yet.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pok-signature-parent-role/specs/bpv/spec.md#scenario-the-signing-flow-asks-for-the-parent
	 */
	public function testAMinorsAgreementIsFlagged(): void {
		$asked  = [];
		$action = $this->makeAction(['required' => true, 'reason' => 'minor', 'parentIds' => ['ouder-1']], [], $asked);

		$result = $action->execute(['id' => 'pok-1', 'version' => 2, 'lifecycle' => 'pending-signatures'], [], [], 'requestSignatures');

		self::assertTrue($result['parentSignatureRequired']);
		self::assertSame('pending-signatures', $result['lifecycle']);
		self::assertSame([null], $asked);
	}//end testAMinorsAgreementIsFlagged()

	/**
	 * An adult's POK carries false, which also clears a flag written by hand.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pok-signature-parent-role/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-every-required-signature
	 */
	public function testAnAdultsAgreementIsNotFlagged(): void {
		$asked  = [];
		$action = $this->makeAction(['required' => false, 'reason' => 'adult', 'parentIds' => []], [], $asked);

		$result = $action->execute(['id' => 'pok-1', 'version' => 2, 'parentSignatureRequired' => true], [], [], 'requestSignatures');

		self::assertFalse($result['parentSignatureRequired']);
	}//end testAnAdultsAgreementIsNotFlagged()

	/**
	 * On activation the student's earliest signature is what the rule is asked about.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pok-signature-parent-role/specs/bpv/spec.md#requirement-pok-activation-is-gated-on-every-required-signature
	 */
	public function testOnActivationTheStudentsSignatureDateCounts(): void {
		$asked      = [];
		$signatures = [
			['signerRole' => 'school', 'signedAt' => '2025-08-20T10:00:00+02:00'],
			['signerRole' => 'student', 'signedAt' => '2025-08-23T09:00:00+02:00'],
			['signerRole' => 'student', 'signedAt' => '2025-08-22T19:05:00+02:00'],
		];
		$action     = $this->makeAction(['required' => true, 'reason' => 'minor', 'parentIds' => ['ouder-1']], $signatures, $asked);

		$action->execute(['id' => 'pok-1', 'version' => 2], [], [], 'activate');

		self::assertSame(['2025-08-22T19:05:00+02:00'], $asked);
	}//end testOnActivationTheStudentsSignatureDateCounts()

	/**
	 * A POK without an id is returned unchanged and the rule is not asked.
	 *
	 * @return void
	 */
	public function testAPokWithoutAnIdIsLeftAlone(): void {
		$asked  = [];
		$action = $this->makeAction(['required' => true, 'reason' => 'minor', 'parentIds' => []], [], $asked);

		self::assertSame(['version' => 1], $action->execute(['version' => 1], [], [], 'requestSignatures'));
		self::assertSame([], $asked);
	}//end testAPokWithoutAnIdIsLeftAlone()
}//end class
