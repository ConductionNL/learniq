<?php

/**
 * Tests for ContributionController::raise() (D19, payments-to-shillinq-migration).
 *
 * @category Test
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
 * @spec openspec/specs/payments/spec.md#requirement-a-school-raises-a-fees-contributions-in-shillinq-from-learniq
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Learniq\Controller\ContributionController;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\ContributionRaiser;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for ContributionController.
 */
class ContributionControllerTest extends TestCase {

	/**
	 * The raiser double.
	 *
	 * @var ContributionRaiser&MockObject
	 */
	private ContributionRaiser $raiser;

	/**
	 * The action matrix double.
	 *
	 * @var ActionAuthService&MockObject
	 */
	private ActionAuthService $actionAuth;

	/**
	 * Build the controller.
	 *
	 * @param array<string, mixed>|null $feeItem  The FeeItem find() returns.
	 * @param array<string, string>     $params   Request parameters.
	 * @param string                    $default  The app-config administration.
	 * @param bool                      $loggedIn Whether a user is logged in.
	 * @param bool                      $available Whether shillinq is there.
	 *
	 * @return ContributionController
	 */
	private function makeController(?array $feeItem, array $params = [], string $default = 'adm-school-1', bool $loggedIn = true, bool $available = true): ContributionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $fallback = null) => ($params[$key] ?? $fallback));

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($loggedIn === true ? $this->createMock(IUser::class) : null);

		$this->raiser = $this->createMock(ContributionRaiser::class);
		$this->raiser->method('isAvailable')->willReturn($available);
		$this->raiser->method('activeFeeItem')->willReturnCallback(
			static fn (string $id): ?array => ($feeItem !== null && ($feeItem['lifecycle'] ?? '') === 'active' && $id === $feeItem['id']) ? $feeItem : null
		);
		$this->raiser->method('administrationId')->willReturnCallback(
			static fn (string $given): string => (trim($given) !== '') ? trim($given) : $default
		);
		$this->actionAuth = $this->createMock(ActionAuthService::class);

		return new ContributionController($request, $session, $this->actionAuth, $this->raiser, new NullLogger());
	}//end makeController()

	/**
	 * An active fee.
	 *
	 * @return array<string, mixed>
	 */
	private function fee(): array {
		return ['id' => 'fee-1', 'name' => 'Ouderbijdrage', 'kind' => 'schoolkassa', 'amount' => 60.0, 'voluntary' => true, 'lifecycle' => 'active'];
	}//end fee()

	/**
	 * The raise runs for an active fee with the default administration, after the action check.
	 *
	 * @return void
	 */
	public function testAnActiveFeeIsRaisedWithTheDefaultAdministration(): void {
		$controller = $this->makeController(feeItem: $this->fee(), params: ['dueDate' => '2026-11-01']);
		$this->actionAuth->expects(self::once())->method('requireAction')->with(self::anything(), 'fee-item.raise-contributions');
		$this->raiser->expects(self::once())->method('raise')
			->with(
				self::callback(static fn (array $fee): bool => $fee['id'] === 'fee-1'),
				'adm-school-1',
				['invoiceDate' => '', 'dueDate' => '2026-11-01', 'revenueAccount' => '']
			)
			->willReturn(['raised' => 2]);

		$response = $controller->raise('fee-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['raised' => 2], $response->getData());
	}//end testAnActiveFeeIsRaisedWithTheDefaultAdministration()

	/**
	 * The request's administration wins over the default.
	 *
	 * @return void
	 */
	public function testTheRequestsAdministrationWins(): void {
		$controller = $this->makeController(feeItem: $this->fee(), params: ['administrationId' => 'adm-andere-school']);
		$this->raiser->expects(self::once())->method('raise')->with(self::anything(), 'adm-andere-school', self::anything())->willReturn([]);

		$controller->raise('fee-1');
	}//end testTheRequestsAdministrationWins()

	/**
	 * Unauthenticated, an unknown or inactive fee, and no administration answer without raising.
	 *
	 * @return void
	 */
	public function testRefusalsDoNotRaise(): void {
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->makeController(feeItem: $this->fee(), loggedIn: false)->raise('fee-1')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->makeController(feeItem: null)->raise('fee-x')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->makeController(feeItem: array_merge($this->fee(), ['lifecycle' => 'draft']))->raise('fee-1')->getStatus());

		$controller = $this->makeController(feeItem: $this->fee(), default: '');
		$this->raiser->expects(self::never())->method('raise');
		self::assertSame(Http::STATUS_BAD_REQUEST, $controller->raise('fee-1')->getStatus());
	}//end testRefusalsDoNotRaise()

	/**
	 * The action matrix refusal propagates as a 403.
	 *
	 * @return void
	 */
	public function testTheActionMatrixRefusalPropagates(): void {
		$controller = $this->makeController(feeItem: $this->fee());
		$this->actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('no'));
		$this->raiser->expects(self::never())->method('raise');

		$this->expectException(OCSForbiddenException::class);
		$controller->raise('fee-1');
	}//end testTheActionMatrixRefusalPropagates()

	/**
	 * Shillinq absent answers 503; its 400, 403 and other failures are translated.
	 *
	 * @return void
	 */
	public function testShillinqAnswersAreTranslated(): void {
		$controller = $this->makeController(feeItem: $this->fee(), available: false);
		$this->raiser->expects(self::never())->method('raise');
		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $controller->raise('fee-1')->getStatus());

		$cases = [
			[new InvalidArgumentException('This fee has no learners to charge yet.'), Http::STATUS_BAD_REQUEST],
			[new RuntimeException('403 Raising school contributions needs the payment.request action.'), Http::STATUS_FORBIDDEN],
			[new RuntimeException('database gone'), Http::STATUS_INTERNAL_SERVER_ERROR],
		];
		foreach ($cases as [$exception, $status]) {
			$controller = $this->makeController(feeItem: $this->fee());
			$this->raiser->method('raise')->willThrowException($exception);
			$response = $controller->raise('fee-1');
			self::assertSame($status, $response->getStatus(), $exception->getMessage());
			self::assertStringNotContainsString('database gone', (string)json_encode($response->getData()));
		}
	}//end testShillinqAnswersAreTranslated()
}//end class
