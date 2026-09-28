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
 * @uses   \OCA\Learniq\Service\CourseStore\CourseStoreDescriptor
 * @uses   \OCA\Learniq\Exception\SharingBlockedException
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
			descriptor: new CourseStoreDescriptor($this->createMock(ActionAuthService::class)),
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
	 * Install checks the store install action (D27: any teacher, not the
	 * package-import action), refuses a foreign slug, and 404s an unresolved one.
	 *
	 * @return void
	 */
	public function testInstallGuardsSlugAndResolution(): void {
		$this->actionAuth->expects(self::exactly(2))->method('requireAction')->with(self::anything(), 'course-store.install');
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
		$this->publisher->method('supportsPublish')->willReturn(true);
		$this->publisher->method('mayPublish')->willReturn(true);
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
		$this->publisher->method('supportsPublish')->willReturn(true);
		$this->publisher->method('mayPublish')->willReturn(true);
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

	/**
	 * TC-4: the plane refuses a user the matrix admitted (an empty matrix
	 * entry names nobody): 403, and no gate, consent or publish ran.
	 *
	 * @return void
	 */
	public function testThePlaneRefusesBeforeAnythingIsBuilt(): void {
		$this->publisher->method('isConfigured')->willReturn(true);
		$this->publisher->method('supportsPublish')->willReturn(true);
		$this->publisher->expects(self::once())->method('mayPublish')->willReturn(false);
		$this->shareService->expects(self::never())->method('buildPackage');
		$this->publisher->expects(self::never())->method('publish');

		$response = $this->controller(true, ['noPupilData' => true, 'rightsCleared' => true])->publish('course-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('forbidden', $response->getData()['outcome']);
	}//end testThePlaneRefusesBeforeAnythingIsBuilt()

	/**
	 * TC-7: an OpenRegister without the publish path answers 501 and runs no gate.
	 *
	 * @return void
	 */
	public function testAnOpenRegisterWithoutThePublishPathIsNotImplemented(): void {
		$this->publisher->method('isConfigured')->willReturn(true);
		$this->publisher->method('supportsPublish')->willReturn(false);
		$this->publisher->expects(self::never())->method('mayPublish');
		$this->shareService->expects(self::never())->method('buildPackage');

		$response = $this->controller()->publish('course-1');

		self::assertSame(Http::STATUS_NOT_IMPLEMENTED, $response->getStatus());
		self::assertSame('publish_not_supported', $response->getData()['outcome']);
	}//end testAnOpenRegisterWithoutThePublishPathIsNotImplemented()

	/**
	 * TC-5: every plane outcome maps to its status.
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function planeOutcomes(): array {
		return [
			'ok'                     => ['ok', Http::STATUS_OK],
			'too_large'              => ['too_large', Http::STATUS_REQUEST_ENTITY_TOO_LARGE],
			'rate_limited'           => ['rate_limited', Http::STATUS_TOO_MANY_REQUESTS],
			'store_unreachable'      => ['store_unreachable', Http::STATUS_BAD_GATEWAY],
			'store_rejected'         => ['store_rejected', Http::STATUS_BAD_GATEWAY],
			'store_invalid_response' => ['store_invalid_response', Http::STATUS_BAD_GATEWAY],
			'not_publishable'        => ['not_publishable', Http::STATUS_INTERNAL_SERVER_ERROR],
			'unknown'                => ['something_new', Http::STATUS_BAD_GATEWAY],
		];
	}//end planeOutcomes()

	/**
	 * @dataProvider planeOutcomes
	 *
	 * @param string $outcome The plane's outcome.
	 * @param int    $status  The expected HTTP status.
	 *
	 * @return void
	 */
	public function testEveryPlaneOutcomeMapsToAStatus(string $outcome, int $status): void {
		$this->publisher->method('isConfigured')->willReturn(true);
		$this->publisher->method('supportsPublish')->willReturn(true);
		$this->publisher->method('mayPublish')->willReturn(true);
		$this->shareService->method('buildPackage')->willReturn(['course' => ['name' => 'Betoog']]);
		$this->publisher->method('publish')->willReturn(['outcome' => $outcome, 'slug' => '']);

		self::assertSame($status, $this->controller()->publish('course-1')->getStatus());
	}//end testEveryPlaneOutcomeMapsToAStatus()
}//end class
