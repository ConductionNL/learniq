<?php

/**
 * Unit tests for SsoProfileFillListener.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/identity-federation/spec.md#requirement-signing-in-writes-the-mapped-fields-to-the-learners-profile
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\SsoProfileFillListener;
use OCA\Learniq\Service\SsoAttributeMappingApplier;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\ISession;
use OCP\IUser;
use OCP\User\Events\UserLoggedInEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Learniq\Listener\SsoProfileFillListener
 * @uses   \OCA\Learniq\Service\SsoAttributeMappingApplier
 */
final class SsoProfileFillListenerTest extends TestCase {

	/**
	 * The rows findAll answers, by schema.
	 *
	 * @var array<string, list<array<string,mixed>>>
	 */
	private array $rows = [];

	/**
	 * The saveObject calls.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * What the session holds under the SAML key.
	 *
	 * @var mixed
	 */
	private mixed $samlData = null;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * A pupil without a role, with a phone number, and the role mapping.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->rows = [
			'sso-attribute-mapping' => [
				['externalAttribute' => 'eduPersonAffiliation', 'learniqField' => 'eduPersonAffiliation', 'provider' => 'saml', 'lifecycle' => 'active'],
				['externalAttribute' => 'eduPersonAffiliation', 'learniqField' => 'role', 'externalValue' => 'student', 'roleValue' => 'student', 'provider' => 'saml', 'lifecycle' => 'active'],
			],
			'learner-profile' => [
				['id' => 'p-uuid', 'ncUserId' => 'pupil1', 'tenant_id' => 't-1', 'phone' => '0612345678', 'roles' => ['learner'], '@self' => ['id' => 'p-uuid']],
			],
		];
		$this->saved    = [];
		$this->samlData = ['eduPersonAffiliation' => ['student'], 'mail' => ['pupil1@school.nl']];
		$this->logger   = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * The listener over the stubs.
	 *
	 * @return SsoProfileFillListener
	 */
	private function listener(): SsoProfileFillListener {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = $config['filters'];
				$schema  = $filters['schema'];
				unset($filters['register'], $filters['schema']);
				return array_values(
					array_filter(
						($this->rows[$schema] ?? []),
						static function (array $row) use ($filters): bool {
							foreach ($filters as $field => $value) {
								if (array_key_exists($field, $row) === true && $row[$field] !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function ($object, $extend = [], $register = null, $schema = null, $uuid = null, $rbac = true) {
				$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, '_rbac' => $rbac];
				return null;
			}
		);

		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturnCallback(fn (string $key) => $key === SsoProfileFillListener::SAML_SESSION_KEY ? $this->samlData : null);

		return new SsoProfileFillListener(new SsoAttributeMappingApplier($objects), $objects, $session, $this->logger);

	}//end listener()

	/**
	 * A sign-in event for a user.
	 *
	 * @param string $uid The user id.
	 *
	 * @return UserLoggedInEvent
	 */
	private function signIn(string $uid): UserLoggedInEvent {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$event = $this->createMock(UserLoggedInEvent::class);
		$event->method('getUser')->willReturn($user);
		return $event;

	}//end signIn()

	public function testAPupilSignsInAndTheMappedRoleIsWrittenWhileThePhoneStays(): void {
		$this->listener()->handle($this->signIn('pupil1'));

		$this->assertCount(1, $this->saved);
		$call = $this->saved[0];
		$this->assertSame('learniq', $call['register']);
		$this->assertSame('learner-profile', $call['schema']);
		$this->assertSame('p-uuid', $call['uuid']);
		$this->assertFalse($call['_rbac']);
		$object = $call['object'];
		$this->assertSame(['learner', 'student'], $object['roles']);
		$this->assertSame('student', $object['eduPersonAffiliation']);
		$this->assertSame('0612345678', $object['phone'], 'a field without a mapping is left alone');
		$this->assertArrayNotHasKey('@self', $object);
		$this->assertArrayNotHasKey('mail', $object);

	}//end testAPupilSignsInAndTheMappedRoleIsWrittenWhileThePhoneStays()

	public function testASecondSignInWithTheSameAttributesWritesNothing(): void {
		$this->rows['learner-profile'][0]['roles'] = ['learner', 'student'];
		$this->rows['learner-profile'][0]['eduPersonAffiliation'] = 'student';

		$this->listener()->handle($this->signIn('pupil1'));

		$this->assertSame([], $this->saved);

	}//end testASecondSignInWithTheSameAttributesWritesNothing()

	public function testNothingIsWrittenWithoutSamlAttributesAProfileOrAMatchingMapping(): void {
		$this->samlData = null;
		$this->listener()->handle($this->signIn('pupil1'));

		$this->samlData = ['eduPersonAffiliation' => ['student']];
		$this->listener()->handle($this->signIn('someone-else'));

		$this->samlData = ['givenName' => ['Piet']];
		$this->listener()->handle($this->signIn('pupil1'));

		$this->listener()->handle($this->createMock(Event::class));

		$this->assertSame([], $this->saved);

	}//end testNothingIsWrittenWithoutSamlAttributesAProfileOrAMatchingMapping()

	public function testAFailingWriteIsLoggedAndDoesNotBreakTheSignIn(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willThrowException(new \RuntimeException('db down'));
		$session = $this->createMock(ISession::class);
		$session->method('get')->willReturn(['eduPersonAffiliation' => ['student']]);
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('Could not fill the profile'), $this->callback(static fn (array $c): bool => $c['user'] === 'pupil1' && $c['msg'] === 'db down'));

		(new SsoProfileFillListener(new SsoAttributeMappingApplier($objects), $objects, $session, $this->logger))->handle($this->signIn('pupil1'));

	}//end testAFailingWriteIsLoggedAndDoesNotBreakTheSignIn()
}//end class
