<?php

/**
 * Learniq CredentialWalletOfferController unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
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
 * @spec openspec/changes/credential-wallet-offer-for-the-learner/specs/certification/spec.md#requirement-the-holder-starts-the-wallet-offer
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use Exception;
use OCA\Learniq\Controller\CredentialWalletOfferController;
use OCA\Learniq\Service\CredentialLearner;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\ServiceAccount;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\OCSController;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Who may start a wallet offer, and as whom it runs.
 *
 * @covers \OCA\Learniq\Controller\CredentialWalletOfferController
 * @uses   \OCA\Learniq\Service\ServiceAccount
 * @uses   \OCA\Learniq\Service\CredentialLearner
 */
class CredentialWalletOfferControllerTest extends TestCase {

	private const OFFER = 'openid-credential-offer://?credential_offer_uri=https%3A%2F%2Fissuer.example%2Foffers%2F8f2c1d';

	/**
	 * Stored credentials by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $credentials = [];

	/**
	 * Who was signed in each time the transition ran, with its action.
	 *
	 * @var array<int, array{user: string|null, id: string, action: string}>
	 */
	private array $transitions = [];

	/**
	 * The user the session carries right now.
	 *
	 * @var IUser|null
	 */
	private ?IUser $current = null;

	/**
	 * Whether the limiter refuses.
	 *
	 * @var bool
	 */
	private bool $limited = false;

	/**
	 * Whether the transition's guard refuses.
	 *
	 * @var bool
	 */
	private bool $engineRefuses = false;

	/**
	 * Identifiers the limiter saw.
	 *
	 * @var array<int, array{id: string, limit: int, period: int}>
	 */
	private array $limits = [];

	/**
	 * Set up the stored credentials.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->credentials = [
			'cred-1' => ['id' => 'cred-1', 'learnerId' => 'f.dejong', 'learnerUserId' => 'f.dejong', 'lifecycle' => 'issued', 'walletOfferUri' => null],
			'cred-revoked' => ['id' => 'cred-revoked', 'learnerId' => 'f.dejong', 'learnerUserId' => 'f.dejong', 'lifecycle' => 'revoked'],
		];
	}//end setUp()

	/**
	 * A user with this uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn(true);
		return $user;
	}//end user()

	/**
	 * The controller for one caller.
	 *
	 * @param string      $caller         The signed-in uid.
	 * @param string|null $serviceAccount The configured service account uid, null for none.
	 * @param bool        $offerSucceeds  Whether integriq answers with a link.
	 * @param bool        $readsAll       Whether the caller may read every credential (staff).
	 *
	 * @return CredentialWalletOfferController
	 */
	private function controller(
		string $caller,
		?string $serviceAccount = 'learniq-bot',
		bool $offerSucceeds = true,
		bool $readsAll = false,
	): CredentialWalletOfferController {
		$this->current = $this->user($caller);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->current);
		$session->method('setVolatileActiveUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->current = $user;
			}
		);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id) use ($readsAll): ObjectEntity {
				$row = ($this->credentials[(string)$id] ?? null);
				$reader = $this->current?->getUID();
				$visible = $row !== null && ($readsAll === true || $reader === 'learniq-bot' || ($row['learnerUserId'] ?? null) === $reader);
				if ($visible === false) {
					throw new DoesNotExistException('not visible');
				}

				return OrEntityFactory::make($row, 'credential');
			}
		);

		$engine = $this->createMock(TransitionEngine::class);
		$engine->method('transition')->willReturnCallback(
			function (string $id, string $action) use ($offerSucceeds): ObjectEntity {
				$this->transitions[] = ['user' => $this->current?->getUID(), 'id' => $id, 'action' => $action];
				if ($this->engineRefuses === true) {
					throw new RuntimeException('Transition offerToWallet is not allowed from revoked.');
				}

				$this->credentials[$id]['walletOfferStatus'] = 'offered';
				$this->credentials[$id]['walletOfferedAt'] = '2026-10-15T12:00:00+00:00';
				$this->credentials[$id]['walletOfferUri'] = ($offerSucceeds === true) ? self::OFFER : null;
				return OrEntityFactory::make($this->credentials[$id], 'credential');
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($serviceAccount ?? '');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => ($uid === 'learniq-bot') ? $this->user($uid) : null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => $uid === 'learniq-bot' && $group === ServiceAccount::GROUP
		);

		$limiter = $this->createMock(ILimiter::class);
		$limiter->method('registerUserRequest')->willReturnCallback(
			function (string $identifier, int $limit, int $period): void {
				$this->limits[] = ['id' => $identifier, 'limit' => $limit, 'period' => $period];
				if ($this->limited === true) {
					throw new class('limited') extends Exception implements IRateLimitExceededException {
					};
				}
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new CredentialWalletOfferController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			objects: $objects,
			learners: new CredentialLearner(profiles: $this->createMock(LearnerRefResolver::class)),
			serviceAccount: new ServiceAccount(
				appConfig: $appConfig,
				userManager: $users,
				groupManager: $groups,
				userSession: $session,
				logger: new NullLogger(),
			),
			transitions: $engine,
			limiter: $limiter,
			l10n: $l10n,
			logger: new NullLogger(),
		);
	}//end controller()

	/**
	 * The holder gets a code; the transition ran as the service account, and the holder is signed in again after.
	 *
	 * @return void
	 */
	public function testTheHolderStartsAnOfferThatRunsAsTheServiceAccount(): void {
		$response = $this->controller(caller: 'f.dejong')->create(id: 'cred-1');

		self::assertSame(200, $response->getStatus());
		self::assertSame(self::OFFER, $response->getData()['walletOfferUri']);
		self::assertSame('offered', $response->getData()['walletOfferStatus']);
		self::assertSame([['user' => 'learniq-bot', 'id' => 'cred-1', 'action' => 'offerToWallet']], $this->transitions);
		self::assertSame('f.dejong', $this->current?->getUID());
		self::assertSame(
			[['id' => 'learniq-wallet-offer-cred-1', 'limit' => CredentialWalletOfferController::OFFERS_PER_PERIOD, 'period' => 3600]],
			$this->limits
		);
	}//end testTheHolderStartsAnOfferThatRunsAsTheServiceAccount()

	/**
	 * Another learner cannot see the certificate, so it is not found, and nothing runs.
	 *
	 * @return void
	 */
	public function testAnotherLearnerIsRefused(): void {
		$response = $this->controller(caller: 'n.yilmaz')->create(id: 'cred-1');

		self::assertSame(404, $response->getStatus());
		self::assertSame('not_found', $response->getData()['error']);
		self::assertSame([], $this->transitions);
	}//end testAnotherLearnerIsRefused()

	/**
	 * Staff who can read the certificate are not its holder: 403, and the staff path stays the transition itself.
	 *
	 * @return void
	 */
	public function testStaffWhoReadTheCertificateAreNotItsHolder(): void {
		$response = $this->controller(caller: 'hr.officer', readsAll: true)->create(id: 'cred-1');

		self::assertSame(403, $response->getStatus());
		self::assertSame('not_holder', $response->getData()['error']);
		self::assertSame([], $this->transitions);
	}//end testStaffWhoReadTheCertificateAreNotItsHolder()

	/**
	 * Without a usable service account the holder hears so, nothing runs and no code is counted.
	 *
	 * @return void
	 */
	public function testNoServiceAccountRefusesWithAClearMessage(): void {
		$response = $this->controller(caller: 'f.dejong', serviceAccount: null)->create(id: 'cred-1');

		self::assertSame(503, $response->getStatus());
		self::assertSame('service_account_missing', $response->getData()['error']);
		self::assertStringContainsString('service account', $response->getData()['message']);
		self::assertSame([], $this->transitions);
		self::assertSame([], $this->limits);
	}//end testNoServiceAccountRefusesWithAClearMessage()

	/**
	 * An account outside the service group is no service account either.
	 *
	 * @return void
	 */
	public function testAnAccountOutsideTheGroupIsRefused(): void {
		$response = $this->controller(caller: 'f.dejong', serviceAccount: 'someone-else')->create(id: 'cred-1');

		self::assertSame(503, $response->getStatus());
		self::assertSame([], $this->transitions);
	}//end testAnAccountOutsideTheGroupIsRefused()

	/**
	 * Past the bound for this certificate this hour: 429, and integriq is not asked.
	 *
	 * @return void
	 */
	public function testTooManyCodesInAnHourAreRefused(): void {
		$this->limited = true;
		$response = $this->controller(caller: 'f.dejong')->create(id: 'cred-1');

		self::assertSame(429, $response->getStatus());
		self::assertSame('too_many_codes', $response->getData()['error']);
		self::assertSame([], $this->transitions);
	}//end testTooManyCodesInAnHourAreRefused()

	/**
	 * A revoked certificate cannot be offered.
	 *
	 * @return void
	 */
	public function testARevokedCertificateCannotBeOffered(): void {
		$response = $this->controller(caller: 'f.dejong')->create(id: 'cred-revoked');

		self::assertSame(409, $response->getStatus());
		self::assertSame('not_issued', $response->getData()['error']);
		self::assertSame([], $this->transitions);
	}//end testARevokedCertificateCannotBeOffered()

	/**
	 * When integriq gives no link the holder hears it failed, and the session is the holder's again.
	 *
	 * @return void
	 */
	public function testAFailedOfferAnswersBadGateway(): void {
		$response = $this->controller(caller: 'f.dejong', offerSucceeds: false)->create(id: 'cred-1');

		self::assertSame(502, $response->getStatus());
		self::assertSame('offer_failed', $response->getData()['error']);
		self::assertSame('f.dejong', $this->current?->getUID());
	}//end testAFailedOfferAnswersBadGateway()

	/**
	 * A refusal from the transition itself is a 422, and the holder is signed in again.
	 *
	 * @return void
	 */
	public function testATransitionRefusalAnswersUnprocessable(): void {
		$this->engineRefuses = true;
		$response = $this->controller(caller: 'f.dejong')->create(id: 'cred-1');

		self::assertSame(422, $response->getStatus());
		self::assertSame('refused', $response->getData()['error']);
		self::assertSame('f.dejong', $this->current?->getUID());
	}//end testATransitionRefusalAnswersUnprocessable()

	/**
	 * Without a session: 401.
	 *
	 * @return void
	 */
	public function testNobodySignedInIsUnauthorized(): void {
		$controller = $this->controller(caller: 'f.dejong');
		$this->current = null;

		self::assertSame(401, $controller->create(id: 'cred-1')->getStatus());
	}//end testNobodySignedInIsUnauthorized()

	/**
	 * The status reaches the caller: a plain Controller (an OCSController answers 200 for a 403),
	 * routed under `routes`, not `ocs`.
	 *
	 * @return void
	 */
	public function testRefusalsReachTheCallerWithTheirOwnStatus(): void {
		self::assertFalse(is_subclass_of(CredentialWalletOfferController::class, OCSController::class));

		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$names = array_column($routes['routes'], 'name');
		self::assertContains('credentialWalletOffer#create', $names);
		self::assertNotContains('credentialWalletOffer#create', array_column(($routes['ocs'] ?? []), 'name'));
	}//end testRefusalsReachTheCallerWithTheirOwnStatus()
}//end class
