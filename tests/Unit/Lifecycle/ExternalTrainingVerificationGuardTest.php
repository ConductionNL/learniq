<?php

/**
 * Learniq ExternalTrainingVerificationGuard unit tests.
 *
 * Covers the three verification preconditions: verifier-group membership, an
 * evidence attachment being present, and the no-self-verification rule; plus
 * the verifier stamping on success.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\ExternalTrainingVerificationGuard;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the ExternalTrainingVerificationGuard (submitted → verified).
 *
 * verifiedBy/verifiedAt are StampTransitionActorAction's write (learniq#983),
 * see tests/Unit/Lifecycle/Action/StampTransitionActorActionTest.php.
 */
class ExternalTrainingVerificationGuardTest extends TestCase {
	/**
	 * Build a guard whose group manager reports the given groups for the actor.
	 *
	 * @param array<string> $actorGroups Group IDs the actor belongs to.
	 * @param bool $actorExists Whether the user manager resolves the actor.
	 *
	 * @return ExternalTrainingVerificationGuard
	 */
	private function makeGuard(array $actorGroups, bool $actorExists = true): ExternalTrainingVerificationGuard {
		$user = $this->createMock(IUser::class);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($actorExists === true ? $user : null);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($actorGroups);

		return new ExternalTrainingVerificationGuard(
			$groupManager,
			$userManager,
			$this->createMock(LoggerInterface::class)
		);
	}//end makeGuard()

	/**
	 * A record fixture with one evidence attachment present.
	 *
	 * The guard reads attachments from `@self.files` (or a `files` array). The
	 * object OpenRegister's LifecycleValidationListener hands a guard is
	 * ObjectEntity::getObject(), which carries neither, see
	 * testRecordAsOpenRegisterHandsItHasNoEvidence().
	 *
	 * @param string $submittedBy The submitter user ID.
	 *
	 * @return array<string,mixed>
	 */
	private function recordWithEvidence(string $submittedBy = 'learner-1'): array {
		return [
			'id' => 'rec-1',
			'learnerId' => 'learner-1',
			'submittedBy' => $submittedBy,
			'lifecycle' => 'verified',
			'@self' => ['files' => [['name' => 'certificate.pdf']]],
		];
	}//end recordWithEvidence()

	/**
	 * OpenRegister's registry refuses a guard that does not implement its interface.
	 *
	 * @return void
	 */
	public function testImplementsTheOpenRegisterGuardInterface(): void {
		$this->assertInstanceOf(LifecycleGuardInterface::class, $this->makeGuard([]));
	}//end testImplementsTheOpenRegisterGuardInterface()

	/**
	 * Happy path: officer in a verifier group, evidence present, not self → allowed.
	 *
	 * @return void
	 */
	public function testValidVerificationIsAllowed(): void {
		$result = $this->makeGuard(['compliance-officers'])->check($this->recordWithEvidence(submittedBy: 'learner-1'), 'verify', 'officer-1');

		$this->assertTrue($result->isAllowed());
	}//end testValidVerificationIsAllowed()

	/**
	 * Actor not in any verifier group → denied.
	 *
	 * @return void
	 */
	public function testNonVerifierGroupDenied(): void {
		$result = $this->makeGuard(['learner'])->check($this->recordWithEvidence(), 'verify', 'pupil-1');

		$this->assertFalse($result->isAllowed());
		$this->assertNotSame('', (string)$result->getMessage());
	}//end testNonVerifierGroupDenied()

	/**
	 * No evidence attachment present → denied even for a valid verifier.
	 *
	 * @return void
	 */
	public function testNoEvidenceAttachmentDenied(): void {
		$object = ['id' => 'rec-2', 'learnerId' => 'learner-1', 'submittedBy' => 'learner-1', 'lifecycle' => 'verified'];

		$this->assertFalse($this->makeGuard(['hr'])->check($object, 'verify', 'hr-1')->isAllowed());
	}//end testNoEvidenceAttachmentDenied()

	/**
	 * The record as ObjectEntity::getObject() returns it has no `@self`, so the
	 * evidence check refuses it. Pinned so the gap stays visible (learniq#983).
	 *
	 * @return void
	 */
	public function testRecordAsOpenRegisterHandsItHasNoEvidence(): void {
		$object = $this->recordWithEvidence();
		unset($object['@self']);

		$this->assertFalse($this->makeGuard(['hr'])->check($object, 'verify', 'hr-1')->isAllowed());
	}//end testRecordAsOpenRegisterHandsItHasNoEvidence()

	/**
	 * Self-verification (verifier == submitter) → denied.
	 *
	 * @return void
	 */
	public function testSelfVerificationDenied(): void {
		$result = $this->makeGuard(['admin'])->check($this->recordWithEvidence(submittedBy: 'officer-1'), 'verify', 'officer-1');

		$this->assertFalse($result->isAllowed());
	}//end testSelfVerificationDenied()

	/**
	 * Missing actor → denied.
	 *
	 * @return void
	 */
	public function testMissingActorDenied(): void {
		$this->assertFalse($this->makeGuard(['admin'])->check($this->recordWithEvidence(), 'verify', '')->isAllowed());
	}//end testMissingActorDenied()

	/**
	 * Admin verifying an officer-submitted record (different person) → allowed.
	 *
	 * @return void
	 */
	public function testAdminVerifiesOfficerSubmission(): void {
		$result = $this->makeGuard(['admin'])->check($this->recordWithEvidence(submittedBy: 'officer-2'), 'verify', 'admin');

		$this->assertTrue($result->isAllowed());
	}//end testAdminVerifiesOfficerSubmission()
}//end class
