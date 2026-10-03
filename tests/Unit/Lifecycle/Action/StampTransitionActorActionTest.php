<?php

/**
 * Learniq StampTransitionActorAction unit tests.
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

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Learniq\Lifecycle\Action\StampTransitionActorAction;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The stamping half of ExamAccommodation.approve, ExternalTrainingRecord.verify
 * and ExchangeRejection.waive (learniq#983): the guards only check, this action
 * writes who did it (and when) onto the object OpenRegister saves.
 */
class StampTransitionActorActionTest extends TestCase {

	/**
	 * Build the action over a session holding the given user, or none.
	 *
	 * @param string|null $uid The session user's uid, or null for no session.
	 *
	 * @return StampTransitionActorAction
	 */
	private function makeAction(?string $uid): StampTransitionActorAction {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new StampTransitionActorAction($session);
	}//end makeAction()

	/**
	 * OpenRegister's action registry refuses a handler without the interface.
	 *
	 * @return void
	 */
	public function testImplementsTheOpenRegisterActionInterface(): void {
		self::assertInstanceOf(LifecycleActionInterface::class, $this->makeAction('actor-1'));
	}//end testImplementsTheOpenRegisterActionInterface()

	/**
	 * The actor lands in the declared field and overwrites a caller-supplied value.
	 *
	 * @return void
	 */
	public function testActorFieldEndsUpOnTheSavedObject(): void {
		$object = ['id' => 'accommodation-1', 'approvedBy' => 'someone-else', 'lifecycle' => 'approved'];

		$result = $this->makeAction('actor-1')->execute($object, [], ['actorField' => 'approvedBy'], StampTransitionActorAction::class);

		self::assertSame('actor-1', $result['approvedBy']);
		self::assertSame('approved', $result['lifecycle']);
		self::assertArrayNotHasKey('verifiedAt', $result);
	}//end testActorFieldEndsUpOnTheSavedObject()

	/**
	 * With a time field declared, the server's UTC time lands there in ATOM format.
	 *
	 * @return void
	 */
	public function testTimeFieldIsStampedInUtc(): void {
		$before = time();
		$result = $this->makeAction('verifier-1')->execute(
			['id' => 'record-1', 'verifiedAt' => '1999-01-01T00:00:00+00:00'],
			[],
			['actorField' => 'verifiedBy', 'timeField' => 'verifiedAt'],
			StampTransitionActorAction::class
		);

		self::assertSame('verifier-1', $result['verifiedBy']);
		$stamped = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $result['verifiedAt']);
		self::assertNotFalse($stamped);
		self::assertStringEndsWith('+00:00', $result['verifiedAt']);
		self::assertGreaterThanOrEqual($before, $stamped->getTimestamp());
	}//end testTimeFieldIsStampedInUtc()

	/**
	 * Without a session user the action throws instead of saving an unattributed record.
	 *
	 * @return void
	 */
	public function testNoSessionUserThrows(): void {
		$this->expectException(RuntimeException::class);

		$this->makeAction(null)->execute(['id' => 'x'], [], ['actorField' => 'approvedBy'], StampTransitionActorAction::class);
	}//end testNoSessionUserThrows()

	/**
	 * A declaration without an actorField is useless, so it throws.
	 *
	 * @return void
	 */
	public function testMissingActorFieldParameterThrows(): void {
		$this->expectException(RuntimeException::class);

		$this->makeAction('actor-1')->execute(['id' => 'x'], [], [], StampTransitionActorAction::class);
	}//end testMissingActorFieldParameterThrows()
}//end class
