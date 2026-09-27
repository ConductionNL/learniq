<?php

/**
 * Contract tests for learniq's StoreController: search through the store
 * plane, install as a copy, publish behind the sharing gate.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lesson-sharing-via-store-plane/tasks.md#task-4-storecontroller-and-routes
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\StoreController;
use OCA\Learniq\Exception\SharingBlockedException;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CourseShareExportService;
use OCA\Learniq\Service\CourseStore\CourseStoreDescriptor;
use OCA\Learniq\Service\CourseStore\CourseStoreInstaller;
use OCA\Learniq\Service\CourseStore\CourseStorePublisher;
use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Service\StoreDescriptor;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Learniq\Controller\StoreController
 */
class StoreControllerTest extends TestCase {

	private GenericStoreService&MockObject $storeService;

	private CourseStoreInstaller&MockObject $installer;

	private CourseStorePublisher&MockObject $publisher;

	private CourseShareExportService&MockObject $shareService;

	private ActionAuthService&MockObject $actionAuth;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->storeService = $this->createMock(GenericStoreService::class);
		$this->installer    = $this->createMock(CourseStoreInstaller::class);
		$this->publisher    = $this->createMock(CourseStorePublisher::class);
		$this->shareService = $this->createMock(CourseShareExportService::class);
		$this->actionAuth   = $this->createMock(ActionAuthService::class);
	}//end setUp()

	/**
	 * Build the controller.
	 *
	 * @param bool                 $signedIn Whether a user is signed in.
	 * @param array<string, mixed> $params   Request parameters.
	 *
	 * @return StoreController
	 */
	private function controller(bool $signedIn=true, array $params=[]): StoreController {
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('docent-07');
		}

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default=null): mixed => ($params[$key] ?? $default)
		);

		return new StoreController(
			request: $request,
			storeService: $this->storeService,
			descriptor: new CourseStoreDescriptor(),
			installer: $this->installer,
			publisher: $this->publisher,
			shareService: $this->shareService,
			userSession: $userSession,
			actionAuth: $this->actionAuth,
			logger: new NullLogger(),
		);
	}//end controller()

	/**
	 * Anonymous callers get 401 on every action, and nothing runs.
	 *
	 * @return void
	 */
	public function testAnonymousCallsAreRefused(): void {
		$this->storeService->expects(self::never())->method('search');
		$this->installer->expects(self::never())->method('install');
		$this->publisher->expects(self::never())->method('publish');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(false)->search()->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(false)->install('course-package-x-1a')->getStatus());
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(false)->publish('course-1')->getStatus());
	}//end testAnonymousCallsAreRefused()

	/**
	 * Search goes through the plane with the course descriptor and passes
	 * the query and kind on.
	 *
	 * @return void
	 */
	public function testSearchGoesThroughTheStorePlane(): void {
		$this->storeService->expects(self::once())->method('search')
			->with(
				self::callback(static fn (StoreDescriptor $d): bool => $d->schema === 'shared-course-package' && $d->appId === 'learniq'),
				'betoog',
				'course-package'
			)
			->willReturn(['outcome' => 'ok', 'cards' => [['slug' => 'course-package-betoog-1a2b3c4d']]]);

		$data = $this->controller(true, ['q' => ' betoog ', 'kind' => 'course-package'])->search()->getData();

		self::assertSame('ok', $data['outcome']);
		self::assertSame('course-package-betoog-1a2b3c4d', $data['cards'][0]['slug']);
		self::assertSame(['course-package'], $data['kinds']);
		self::assertSame([], $data['builtIn']);
	}//end testSearchGoesThroughTheStorePlane()

	/**
	 * A plane failure is a generic unreachable outcome, never an error body.
	 *
	 * @return void
	 */
	public function testASearchFailureIsUnreachable(): void {
		$this->storeService->method('search')->willThrowException(new \RuntimeException('secret upstream detail'));

		$response = $this->controller()->search();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('store_unreachable', $response->getData()['outcome']);
		self::assertStringNotContainsString('secret', (string)json_encode($response->getData()));
	}//end testASearchFailureIsUnreachable()

	/**
	 * Install checks the import action, refuses a foreign slug, and 404s an
	 * unresolved one.
	 *
	 * @return void
	 */
	public function testInstallGuardsSlugAndResolution(): void {
		$this->actionAuth->expects(self::exactly(2))->method('requireAction')->with(self::anything(), 'course-package.import');
		$this->storeService->method('resolve')->willReturn(null);

		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->install('openregister-configset-x')->getStatus());
		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller()->install('course-package-gone-1a2b3c4d')->getStatus());
	}//end testInstallGuardsSlugAndResolution()

	/**
	 * A resolved item is installed and its report returned; a failed import is 422.
	 *
	 * @return void
	 */
	public function testInstallReturnsTheReport(): void {
		$item = ['slug' => 'course-package-betoog-1a2b3c4d', 'package' => ['course' => ['name' => 'Betoog']]];
		$this->storeService->method('resolve')->willReturn($item);
		$this->installer->method('install')->willReturnOnConsecutiveCalls(
			['success' => true, 'courseId' => 'c-1', 'reportId' => 'r-1', 'components' => [], 'message' => ''],
			['success' => false, 'courseId' => null, 'reportId' => null, 'components' => [], 'message' => 'Not a package.']
		);

		$ok = $this->controller()->install('course-package-betoog-1a2b3c4d');
		self::assertSame(Http::STATUS_OK, $ok->getStatus());
		self::assertSame('c-1', $ok->getData()['courseId']);

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller()->install('course-package-betoog-1a2b3c4d')->getStatus());
	}//end testInstallReturnsTheReport()

	/**
	 * Without a registry, publish runs no gate and records nothing.
	 *
	 * @return void
	 */
	public function testPublishWithoutARegistryRunsNoGate(): void {
		$this->actionAuth->expects(self::once())->method('requireAction')->with(self::anything(), 'course-package.share');
		$this->publisher->method('isConfigured')->willReturn(false);
		$this->shareService->expects(self::never())->method('buildPackage');

		$response = $this->controller()->publish('course-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('not_configured', $response->getData()['outcome']);
	}//end testPublishWithoutARegistryRunsNoGate()

	/**
	 * A refused course is 422 with the blockers, and nothing is sent.
	 *
	 * @return void
	 */
	public function testARefusedPublishCarriesTheBlockers(): void {
		$blockers = [['code' => 'licence-missing', 'id' => 'course-1', 'name' => 'Betoog']];
		$this->publisher->method('isConfigured')->willReturn(true);
		$this->shareService->method('buildPackage')->willThrowException(new SharingBlockedException($blockers));
		$this->publisher->expects(self::never())->method('publish');

		$response = $this->controller(true, ['noPupilData' => true, 'rightsCleared' => true])->publish('course-1');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame($blockers, $response->getData()['blockers']);
	}//end testARefusedPublishCarriesTheBlockers()

	/**
	 * A passing course is gated with purpose store and the confirmations,
	 * then published; outcomes map to statuses.
	 *
	 * @return void
	 */
	public function testAPassingCourseIsPublished(): void {
		$this->publisher->method('isConfigured')->willReturn(true);
		$this->shareService->expects(self::exactly(2))->method('buildPackage')
			->with('course-1', 'docent-07', true, false, 'store')
			->willReturn(['course' => ['name' => 'Betoog']]);
		$this->publisher->method('publish')->willReturnOnConsecutiveCalls(
			['outcome' => 'ok', 'slug' => 'course-package-betoog-1a2b3c4d'],
			['outcome' => 'store_unreachable', 'slug' => '']
		);

		$params = ['noPupilData' => 'true', 'rightsCleared' => 'false'];
		$ok     = $this->controller(true, $params)->publish('course-1');
		self::assertSame(Http::STATUS_OK, $ok->getStatus());
		self::assertSame('course-package-betoog-1a2b3c4d', $ok->getData()['slug']);

		self::assertSame(Http::STATUS_BAD_GATEWAY, $this->controller(true, $params)->publish('course-1')->getStatus());
	}//end testAPassingCourseIsPublished()
}//end class
