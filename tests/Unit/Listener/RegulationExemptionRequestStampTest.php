<?php

/**
 * Learniq regulation exemption request stamp tests.
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
 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#requirement-regulation-exemption-records
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\EventListenerWiring;
use OCA\Learniq\Listener\RegulationExemptionRequestStamp;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Who may ask for an exemption, and who the record says asked.
 *
 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#requirement-regulation-exemption-records
 */
class RegulationExemptionRequestStampTest extends TestCase {

	private const JAN = '1a2b3c4d-0000-4000-8000-00000000000a';

	/**
	 * A request as the page posts it.
	 *
	 * @var array<string, mixed>
	 */
	private const REQUEST = [
		'learnerId'      => self::JAN,
		'regulationSlug' => 'working-at-height',
		'reasonKind'     => 'medical',
		'reasonText'     => 'A doctor\'s note is on file.',
	];

	/**
	 * The stamp over a real LearnerRefResolver, signed in as $userId.
	 *
	 * @param string|null        $userId The session user, null for no session.
	 * @param array<int, string> $groups The session user's groups.
	 * @param string             $slug   What the schema resolver answers.
	 *
	 * @return RegulationExemptionRequestStamp
	 */
	private function makeStamp(?string $userId, array $groups = [], string $slug = 'regulation-exemption'): RegulationExemptionRequestStamp {
		$jan = ['id' => self::JAN, 'ncUserId' => 'jan', 'managerId' => 'lead-1', 'lifecycle' => 'active'];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			static function (int|string $id) use ($jan): ObjectEntity {
				if ((string)$id === self::JAN) {
					return OrEntityFactory::make($jan, 'learner-profile');
				}

				throw new DoesNotExistException('gone');
			}
		);

		$schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$schemaResolver->method('guardSchemaSlug')->willReturn($slug);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => $uid === $userId && in_array($group, $groups, true)
		);

		return new RegulationExemptionRequestStamp(
			schemaResolver: $schemaResolver,
			profiles: new LearnerRefResolver(objectService: $objectService),
			userSession: $session,
			groupManager: $groupManager,
		);
	}//end makeStamp()

	/**
	 * A team lead asks for their own report; the record names the lead and
	 * starts as requested, whatever the client sent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#scenario-a-manager-requests-an-exemption
	 */
	public function testAManagerRequestsAnExemptionForTheirReport(): void {
		$posted = array_merge(self::REQUEST, ['requestedBy' => 'officer-2', 'lifecycle' => 'granted', 'decidedBy' => 'lead-1', 'validUntil' => '2099-01-01']);
		$event = new ObjectCreatingEvent(OrEntityFactory::make($posted, 'regulation-exemption'));
		$this->makeStamp(userId: 'lead-1', groups: ['team-leads'])->handle($event);

		self::assertFalse($event->isPropagationStopped());
		$data = $event->getModifiedData();
		self::assertSame('lead-1', $data['requestedBy']);
		self::assertSame('requested', $data['lifecycle']);
		self::assertNull($data['decidedBy']);
		self::assertNull($data['validUntil']);
	}//end testAManagerRequestsAnExemptionForTheirReport()

	/**
	 * A team lead cannot ask for someone who does not report to them, and a
	 * failed profile lookup refuses rather than lets through; an officer asks
	 * for anyone.
	 *
	 * @return void
	 */
	public function testAManagerIsScopedToTheirDirectReports(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(self::REQUEST, 'regulation-exemption'));
		$this->makeStamp(userId: 'lead-9', groups: ['team-leads'])->handle($event);
		self::assertTrue($event->isPropagationStopped());
		self::assertSame('exemption-not-your-report', $event->getErrors()['reason']);

		$unknown = new ObjectCreatingEvent(OrEntityFactory::make(array_merge(self::REQUEST, ['learnerId' => 'nobody']), 'regulation-exemption'));
		$this->makeStamp(userId: 'lead-1', groups: ['team-leads'])->handle($unknown);
		self::assertTrue($unknown->isPropagationStopped(), 'a learner that cannot be found is not a report');

		$officer = new ObjectCreatingEvent(OrEntityFactory::make(self::REQUEST, 'regulation-exemption'));
		$this->makeStamp(userId: 'officer-2', groups: ['compliance-officers'])->handle($officer);
		self::assertFalse($officer->isPropagationStopped());
		self::assertSame('officer-2', $officer->getModifiedData()['requestedBy']);
	}//end testAManagerIsScopedToTheirDirectReports()

	/**
	 * An update cannot move the request to another requester, which would let
	 * the requester grant it; no session files nothing; other schemas are left alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-exemption-record/specs/compliance-exemptions/spec.md#scenario-a-requester-cannot-grant-their-own-request
	 */
	public function testTheRequesterCannotBeRewritten(): void {
		$stored = array_merge(self::REQUEST, ['requestedBy' => 'officer-2', 'lifecycle' => 'requested']);
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(array_merge($stored, ['requestedBy' => 'officer-3']), 'regulation-exemption'),
			OrEntityFactory::make($stored, 'regulation-exemption')
		);
		$this->makeStamp(userId: 'officer-2', groups: ['compliance-officers'])->handle($event);
		self::assertSame('officer-2', $event->getModifiedData()['requestedBy']);

		$anonymous = new ObjectCreatingEvent(OrEntityFactory::make(self::REQUEST, 'regulation-exemption'));
		$this->makeStamp(userId: null)->handle($anonymous);
		self::assertSame('exemption-no-session', $anonymous->getErrors()['reason']);

		$other = new ObjectCreatingEvent(OrEntityFactory::make(self::REQUEST, 'regulation'));
		$this->makeStamp(userId: 'lead-9', groups: ['team-leads'], slug: 'regulation')->handle($other);
		self::assertFalse($other->isPropagationStopped());
		self::assertSame([], $other->getModifiedData());
	}//end testTheRequesterCannotBeRewritten()

	/**
	 * The live wiring registers the stamp for create and update.
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

		self::assertContains(ObjectCreatingEvent::class . ' => ' . RegulationExemptionRequestStamp::class, $registered);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . RegulationExemptionRequestStamp::class, $registered);
	}//end testTheStampIsRegisteredForCreateAndUpdate()
}//end class
