<?php

/**
 * Learniq LinkCoveringCorrectionAction unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle\Action
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle\Action;

use OCA\Learniq\Lifecycle\Action\LinkCoveringCorrectionAction;
use OCA\Learniq\Service\Grading\CorrectionApprovals;
use OCA\OpenRegister\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * GradeEntry.republish: the approved correction that covers the publish is
 * named in correctionRequestId in the same save; without one, without a
 * session user or when the lookup fails, the entry is saved as it is.
 *
 * @covers \OCA\Learniq\Lifecycle\Action\LinkCoveringCorrectionAction
 *
 * @spec openspec/changes/governance-four-eyes-on-approved-data/specs/governance-four-eyes/spec.md#requirement-audit-of-corrections
 */
class LinkCoveringCorrectionActionTest extends TestCase {

	private const ENTRY = ['id' => 'entry-1', 'lifecycle' => 'published', 'value' => '7.5'];

	public function testImplementsTheOpenRegisterActionInterface(): void {
		self::assertInstanceOf(
			expected: LifecycleActionInterface::class,
			actual: new LinkCoveringCorrectionAction($this->createMock(CorrectionApprovals::class), $this->session(uid: 'p'), $this->createMock(LoggerInterface::class))
		);
	}//end testImplementsTheOpenRegisterActionInterface()

	public function testTheCoveringCorrectionIsNamedOnTheEntry(): void {
		$approvals = $this->createMock(CorrectionApprovals::class);
		$approvals->expects($this->once())->method('approvedFor')->with(self::ENTRY, 'publisher')->willReturn(['id' => 'req-9', 'uuid' => 'u-9']);

		$result = $this->action(approvals: $approvals, uid: 'publisher')->execute($this->context());

		self::assertSame(self::ENTRY + ['correctionRequestId' => 'req-9'], $result);
	}//end testTheCoveringCorrectionIsNamedOnTheEntry()

	public function testARequestWithOnlyAUuidIsNamedByItsUuid(): void {
		$approvals = $this->createMock(CorrectionApprovals::class);
		$approvals->method('approvedFor')->willReturn(['uuid' => 'u-9']);

		self::assertSame('u-9', $this->action(approvals: $approvals, uid: 'publisher')->execute($this->context())['correctionRequestId']);
	}//end testARequestWithOnlyAUuidIsNamedByItsUuid()

	public function testWithoutACoveringCorrectionTheEntryIsUnchanged(): void {
		foreach ([null, [], ['id' => '']] as $request) {
			$approvals = $this->createMock(CorrectionApprovals::class);
			$approvals->method('approvedFor')->willReturn($request);

			self::assertSame(self::ENTRY, $this->action(approvals: $approvals, uid: 'publisher')->execute($this->context()), (string)json_encode($request));
		}
	}//end testWithoutACoveringCorrectionTheEntryIsUnchanged()

	public function testWithoutASessionUserThePublisherIsBlank(): void {
		$approvals = $this->createMock(CorrectionApprovals::class);
		$approvals->expects($this->once())->method('approvedFor')->with(self::ENTRY, '')->willReturn(null);

		self::assertSame(self::ENTRY, $this->action(approvals: $approvals, uid: null)->execute($this->context()));
	}//end testWithoutASessionUserThePublisherIsBlank()

	public function testAFailingLookupIsLoggedAndNeverRefusesThePublish(): void {
		$approvals = $this->createMock(CorrectionApprovals::class);
		$approvals->method('approvedFor')->willThrowException(new RuntimeException('register down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'[LinkCoveringCorrectionAction] Could not look up the correction for grade entry {entry}: {error}',
			['entry' => 'entry-1', 'error' => 'register down']
		);

		$action = new LinkCoveringCorrectionAction($approvals, $this->session(uid: 'publisher'), $logger);

		self::assertSame(self::ENTRY, $action->execute($this->context()));
	}//end testAFailingLookupIsLoggedAndNeverRefusesThePublish()

	private function action(CorrectionApprovals $approvals, ?string $uid): LinkCoveringCorrectionAction {
		return new LinkCoveringCorrectionAction($approvals, $this->session(uid: $uid), $this->createMock(LoggerInterface::class));
	}//end action()

	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	private function context(): LifecycleActionContext {
		return new LifecycleActionContext(
			objectData: self::ENTRY,
			previousData: [],
			parameters: [],
			actionName: LinkCoveringCorrectionAction::class
		);
	}//end context()
}//end class
