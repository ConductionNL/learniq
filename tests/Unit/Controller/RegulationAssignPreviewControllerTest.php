<?php

/**
 * The regulation assignment preview route.
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
 * @spec openspec/changes/regulation-assign-by-audience/specs/compliance-rule-coverage/spec.md#requirement-the-assignment-is-previewed-per-audience
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\RegulationAssignPreviewController;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\RegulationAssignmentService;
use OCA\Learniq\Service\RegulationAssignPreview;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Learniq\Controller\RegulationAssignPreviewController
 * @covers \OCA\Learniq\Controller\AssignableRegulation
 * @uses \OCA\Learniq\Service\RegulationAssignmentService
 * @uses \OCA\Learniq\Service\ExternalTrainingLearnerMatch
 */
class RegulationAssignPreviewControllerTest extends TestCase {

	/**
	 * What happened, in order: the action asked, then each regulation read.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * The controller over a store holding a published and a draft regulation.
	 *
	 * @param RegulationAssignPreview $preview  The preview double.
	 * @param bool                    $signedIn Whether a user is signed in.
	 * @param bool                    $allowed  Whether the caller holds regulation.assign.
	 *
	 * @return RegulationAssignPreviewController
	 */
	private function controller(RegulationAssignPreview $preview, bool $signedIn=true, bool $allowed=true): RegulationAssignPreviewController {
		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			function (string $id) {
				$this->calls[] = 'read ' . $id;
				$rows = ['reg-mc' => 'published', 'reg-draft' => 'draft'];
				if (isset($rows[$id]) === false) {
					throw new DoesNotExistException(msg: 'no such regulation');
				}

				return OrEntityFactory::make(['id' => $id, 'slug' => 'MELDCODE', 'lifecycle' => $rows[$id]], 'regulation');
			}
		);

		$user = $this->createMock(originalClassName: IUser::class);
		$session = $this->createMock(originalClassName: IUserSession::class);
		if ($signedIn === true) {
			$session->method('getUser')->willReturn($user);
		}

		$actionAuth = $this->createMock(originalClassName: ActionAuthService::class);
		$actionAuth->method('requireAction')->willReturnCallback(
			function (IUser $user, string $action) use ($allowed): void {
				$this->calls[] = $action;
				if ($allowed === false) {
					throw new OCSForbiddenException(message: 'Not allowed.');
				}
			}
		);

		return new RegulationAssignPreviewController(
			request: $this->createMock(originalClassName: IRequest::class),
			userSession: $session,
			actionAuth: $actionAuth,
			assignment: $this->createPartialMock(originalClassName: RegulationAssignmentService::class, methods: ['assign']),
			preview: $preview,
			objectService: $objectService
		);
	}//end controller()

	/**
	 * The chosen audience reaches the preview, trimmed.
	 *
	 * @return void
	 */
	public function testThePreviewFollowsTheChosenAudience(): void {
		$preview = $this->createMock(originalClassName: RegulationAssignPreview::class);
		$preview->expects(self::once())->method('preview')
			->with(
				self::callback(callback: static fn (array $r): bool => $r['id'] === 'reg-mc'),
				['kind' => 'team', 'value' => 'Talen/Frans']
			)
			->willReturn(['courseName' => 'Meldcode', 'audiences' => [], 'total' => 2]);

		$answer = $this->controller(preview: $preview)->preview(id: 'reg-mc', audienceKind: 'team', audience: ' Talen/Frans ');

		self::assertSame(expected: [Http::STATUS_OK, 2], actual: [$answer->getStatus(), $answer->getData()['total']]);
		self::assertSame(expected: ['regulation.assign', 'read reg-mc'], actual: $this->calls);
	}//end testThePreviewFollowsTheChosenAudience()

	/**
	 * 401, 404, 409 and 400 like the assignment; nothing is previewed.
	 *
	 * @return void
	 */
	public function testThePreviewRefusesWhatTheAssignmentRefuses(): void {
		$preview = $this->createMock(originalClassName: RegulationAssignPreview::class);
		$preview->expects(self::never())->method('preview');

		$signedOut = $this->controller(preview: $preview, signedIn: false);
		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $signedOut->preview(id: 'reg-mc')->getStatus());
		$officer = $this->controller(preview: $preview);
		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $officer->preview(id: 'reg-missing')->getStatus());
		self::assertSame(expected: Http::STATUS_NOT_FOUND, actual: $officer->preview()->getStatus());
		self::assertSame(expected: Http::STATUS_CONFLICT, actual: $officer->preview(id: 'reg-draft')->getStatus());
		self::assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $officer->preview(id: 'reg-mc', audienceKind: 'school', audience: 'x')->getStatus());
	}//end testThePreviewRefusesWhatTheAssignmentRefuses()

	/**
	 * A caller without regulation.assign is refused before the regulation is read.
	 *
	 * @return void
	 */
	public function testACallerWithoutTheActionIsRefusedBeforeTheRead(): void {
		$preview = $this->createMock(originalClassName: RegulationAssignPreview::class);
		$preview->expects(self::never())->method('preview');

		try {
			$this->controller(preview: $preview, allowed: false)->preview(id: 'reg-mc');
			self::fail(message: 'Expected a 403.');
		} catch (OCSForbiddenException) {
			self::assertSame(expected: ['regulation.assign'], actual: $this->calls);
		}
	}//end testACallerWithoutTheActionIsRefusedBeforeTheRead()
}//end class
