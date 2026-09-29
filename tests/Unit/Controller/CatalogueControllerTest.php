<?php

/**
 * Learniq CatalogueController unit tests: the list, each write and the words.
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
 * @spec openspec/changes/enrolment-catalogue-self-signup/specs/enrolment/spec.md#requirement-a-learner-signs-up-from-the-catalogue
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\CatalogueController;
use OCA\Learniq\Controller\PortalCatalogueController;
use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCA\Learniq\Service\Catalogue\CatalogueMessages;
use OCA\Learniq\Service\Catalogue\CatalogueReader;
use OCA\Learniq\Service\Catalogue\CatalogueSignUpService;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalLearnerResolver;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Every CatalogueController method, the portal list and withdraw, and the
 * real message wording.
 */
class CatalogueControllerTest extends TestCase {

	/**
	 * The real messages over an identity translator.
	 *
	 * @return CatalogueMessages
	 */
	private function messages(): CatalogueMessages {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$factory->method('getUserLanguage')->willReturn('en');

		return new CatalogueMessages(l10nFactory: $factory);
	}//end messages()

	/**
	 * A sign-up service double: open entries except `closed`, own enrolments
	 * except `other`, every write answers 200 with what it was asked.
	 *
	 * @return CatalogueSignUpService
	 */
	private function signUps(): CatalogueSignUpService {
		$signUps = $this->createMock(CatalogueSignUpService::class);
		$signUps->method('requireOpenForSignUp')->willReturnCallback(static fn (string $schema, string $id): bool => $id !== 'closed');
		$signUps->method('requireOwnEnrolment')->willReturnCallback(static fn (PortalLearner $l, string $id): bool => $id !== 'other');
		$signUps->method('signUpProgramme')->willReturnCallback(static fn (PortalLearner $l, string $id): PortalOutcome => new PortalOutcome(status: 200, body: ['programme' => $id]));
		$signUps->method('withdraw')->willReturnCallback(static fn (PortalLearner $l, string $id): PortalOutcome => new PortalOutcome(status: 200, body: ['withdrawn' => $id]));
		$signUps->method('catalogue')->willReturn(new PortalOutcome(status: 200, body: ['courses' => [], 'programmes' => []]));

		return $signUps;
	}//end signUps()

	/**
	 * The in-app controller for a learner with a profile.
	 *
	 * @param array<string, mixed> $params     The query parameters.
	 * @param array<int, mixed>    $seen       Receives the reader's arguments.
	 * @param string|null          $profileRef The caller's profile uuid, null for none.
	 *
	 * @return CatalogueController
	 */
	private function controller(array $params, array &$seen, ?string $profileRef='lp-1'): CatalogueController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('p.ganpat');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$reader = $this->createMock(CatalogueReader::class);
		$reader->method('entries')->willReturnCallback(
			function (string $userId, string $search = '', array $filters = []) use (&$seen): array {
				$seen = [$userId, $search, $filters];
				return ['courses' => [], 'programmes' => []];
			}
		);
		$profiles = $this->createMock(LearnerRefResolver::class);
		$profiles->method('resolve')->willReturn($profileRef);

		return new CatalogueController(request: $request, userSession: $session, reader: $reader, signUps: $this->signUps(), messages: $this->messages(), profiles: $profiles);
	}//end controller()

	/**
	 * The list passes the search and the filters, `provider` read as `author`.
	 *
	 * @return void
	 */
	public function testTheListPassesSearchAndFilters(): void {
		$seen = [];
		$this->controller(params: ['search' => ' excel ', 'provider' => 'Go1', 'language' => 'nl', 'level' => [1]], seen: $seen)->index();

		self::assertSame(['p.ganpat', 'excel', ['level' => '', 'language' => 'nl', 'subject' => '', 'author' => 'Go1']], $seen);
	}//end testTheListPassesSearchAndFilters()

	/**
	 * A programme sign-up and a withdraw reach the service; a closed
	 * programme and another learner's enrolment answer 404 in words.
	 *
	 * @return void
	 */
	public function testWritesReachTheServiceOnlyForOpenAndOwnObjects(): void {
		$seen = [];
		$controller = $this->controller(params: [], seen: $seen);

		self::assertSame(['programme' => 'p-1'], $controller->signUpProgramme(id: 'p-1')->getData());
		self::assertSame(['withdrawn' => 'e-1'], $controller->withdraw(id: 'e-1')->getData());

		$closed = $controller->signUpProgramme(id: 'closed');
		self::assertSame(404, $closed->getStatus());
		self::assertSame('This course could not be found.', $closed->getData()['message']);
		self::assertSame(404, $controller->withdraw(id: 'other')->getStatus());
		self::assertSame(404, $controller->signUpCourse(id: 'closed')->getStatus());
	}//end testWritesReachTheServiceOnlyForOpenAndOwnObjects()

	/**
	 * An account without a learner profile is refused every write with
	 * `not_a_learner` and a message that says who to ask, not a bare code.
	 *
	 * @return void
	 */
	public function testAnAccountWithoutALearnerProfileIsToldWhoToAsk(): void {
		$seen = [];
		$controller = $this->controller(params: [], seen: $seen, profileRef: null);
		$expected = ['error' => 'not_a_learner', 'message' => 'Your account has no learner profile yet. Ask your school or administrator to add one.'];

		foreach ([$controller->signUpCourse(id: 'c-1'), $controller->signUpProgramme(id: 'p-1'), $controller->withdraw(id: 'e-1')] as $response) {
			self::assertSame(403, $response->getStatus());
			self::assertSame($expected, $response->getData());
		}
	}//end testAnAccountWithoutALearnerProfileIsToldWhoToAsk()

	/**
	 * The portal lists the catalogue and withdraws for the pupil, and an
	 * unknown pupil is told their account is not ready.
	 *
	 * @return void
	 */
	public function testThePortalListsAndWithdraws(): void {
		$portal = function (bool $known): PortalCatalogueController {
			$params = ['learnerRef' => 'lp-1', 'enrolmentId' => 'e-1', 'search' => 'x'];
			$request = $this->createMock(IRequest::class);
			$request->method('getHeader')->willReturn('token');
			$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $params[$key] ?? $default);
			$verifier = $this->createMock(PortalAssertionVerifier::class);
			$verifier->method('verify')->willReturn(['audience' => 'student']);
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('pupil-1');
			$learners = $this->createMock(PortalLearnerResolver::class);
			$learners->method('resolve')->willReturn($known === true ? new PortalLearner(profileRef: 'lp-1', ncUserId: 'pupil-1', tenantId: '', user: $user) : null);

			return new PortalCatalogueController(request: $request, verifier: $verifier, learners: $learners, signUps: $this->signUps(), messages: $this->messages(), logger: new NullLogger());
		};

		self::assertSame(['courses' => [], 'programmes' => []], $portal(true)->catalogue()->getData());
		self::assertSame(['withdrawn' => 'e-1'], $portal(true)->withdraw()->getData());
		$unknown = $portal(false)->catalogue();
		self::assertSame(403, $unknown->getStatus());
		self::assertSame('Your school account is not ready for this yet. Ask your school.', $unknown->getData()['message']);
	}//end testThePortalListsAndWithdraws()
}//end class
