<?php

/**
 * Learniq SovereigntyPolicySetByStamp unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
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
 * @spec openspec/specs/ai-locality-guarantee/spec.md#scenario-a-school-sets-its-locality-policy
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\EventListenerWiring;
use OCA\Learniq\Listener\SovereigntyPolicySetByStamp;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SovereigntyPolicySetByStamp::handle().
 */
class SovereigntyPolicySetByStampTest extends TestCase {

	private const NOW = 1792540800;

	private const NOW_ISO = '2026-10-21T00:00:00+00:00';

	/**
	 * Build the stamp, signed in as $userId.
	 *
	 * @param string|null $userId The session user, null for no session.
	 * @param string      $slug   What the schema resolver answers.
	 *
	 * @return SovereigntyPolicySetByStamp
	 */
	private function makeStamp(?string $userId, string $slug = 'sovereignty-policy'): SovereigntyPolicySetByStamp {
		$schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$schemaResolver->method('guardSchemaSlug')->willReturn($slug);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$session->method('getUser')->willReturn($user);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new SovereigntyPolicySetByStamp(
			schemaResolver: $schemaResolver,
			userSession: $session,
			timeFactory: $time,
		);
	}//end makeStamp()

	/**
	 * Setting the policy records the signed-in user and the server's time,
	 * whatever the client sent.
	 *
	 * @return void
	 */
	public function testACreateRecordsWhoSetThePolicyAndWhen(): void {
		$event = new ObjectCreatingEvent(
			OrEntityFactory::make(['policy' => 'on-premises-only', 'setBy' => 'someone-else', 'setAt' => '2001-01-01T00:00:00Z'], 'sovereignty-policy')
		);
		$this->makeStamp(userId: 'officer')->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame('officer', $event->getModifiedData()['setBy']);
		self::assertSame(self::NOW_ISO, $event->getModifiedData()['setAt']);
	}//end testACreateRecordsWhoSetThePolicyAndWhen()

	/**
	 * Changing the policy records the user who changed it.
	 *
	 * @return void
	 */
	public function testAnUpdateRecordsWhoChangedThePolicy(): void {
		$stored = ['policy' => 'eu-hosted-allowed', 'setBy' => 'first-officer', 'setAt' => '2026-01-01T00:00:00+00:00'];
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(array_merge($stored, ['policy' => 'on-premises-only']), 'sovereignty-policy'),
			OrEntityFactory::make($stored, 'sovereignty-policy')
		);
		$this->makeStamp(userId: 'second-officer')->handle($event);

		self::assertSame('second-officer', $event->getModifiedData()['setBy']);
		self::assertSame(self::NOW_ISO, $event->getModifiedData()['setAt']);
	}//end testAnUpdateRecordsWhoChangedThePolicy()

	/**
	 * A write without a signed-in user keeps the stored stamp and cannot
	 * supply its own.
	 *
	 * @return void
	 */
	public function testAWriteWithoutASessionKeepsTheStoredStamp(): void {
		$stored = ['policy' => 'eu-hosted-allowed', 'setBy' => 'first-officer', 'setAt' => '2026-01-01T00:00:00+00:00'];
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(array_merge($stored, ['setBy' => 'forged', 'setAt' => null]), 'sovereignty-policy'),
			OrEntityFactory::make($stored, 'sovereignty-policy')
		);
		$this->makeStamp(userId: null)->handle($event);

		self::assertSame('first-officer', $event->getModifiedData()['setBy']);
		self::assertSame('2026-01-01T00:00:00+00:00', $event->getModifiedData()['setAt']);

		$create = new ObjectCreatingEvent(OrEntityFactory::make(['policy' => 'eu-hosted-allowed', 'setBy' => 'forged'], 'sovereignty-policy'));
		$this->makeStamp(userId: null)->handle($create);

		self::assertNull($create->getModifiedData()['setBy']);
		self::assertNull($create->getModifiedData()['setAt']);
	}//end testAWriteWithoutASessionKeepsTheStoredStamp()

	/**
	 * Another schema's write is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsLeftAlone(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['setBy' => 'someone-else'], 'accessibility-statement'));
		$this->makeStamp(userId: 'officer', slug: 'accessibility-statement')->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testAnotherSchemaIsLeftAlone()

	/**
	 * The stamp is wired on both create and update.
	 *
	 * @return void
	 */
	public function testTheStampIsRegisteredForCreateAndUpdate(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = $event . ' => ' . $listener;
			}
		);

		(new EventListenerWiring())->registerAll(context: $context);

		self::assertContains(ObjectCreatingEvent::class . ' => ' . SovereigntyPolicySetByStamp::class, $registered);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . SovereigntyPolicySetByStamp::class, $registered);
	}//end testTheStampIsRegisteredForCreateAndUpdate()
}//end class
