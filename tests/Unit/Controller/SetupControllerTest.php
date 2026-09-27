<?php

/**
 * Learniq SetupController unit tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
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
 *
 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\SetupController;
use OCA\Learniq\Service\SeedProfileService;
use OCA\Learniq\Service\SegmentService;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ADR-042 / ADR-111 setup contract, with example sets and the segment step.
 *
 * The assertions are about what the wizard can OBSERVE. A step the status
 * document never mentions resolves to `done: false` forever, and an optional
 * step that can never be marked done keeps the wizard open over every page,
 * so "the step is reported" and "a decision closes it" are the contract.
 */
class SetupControllerTest extends TestCase {

	/**
	 * App config double.
	 *
	 * @var IAppConfig
	 */
	private IAppConfig $appConfig;

	/**
	 * Example set service double.
	 *
	 * @var SeedProfileService
	 */
	private SeedProfileService $profiles;

	/**
	 * Segment service double.
	 *
	 * @var SegmentService
	 */
	private SegmentService $segments;

	/**
	 * Request double.
	 *
	 * @var IRequest
	 */
	private IRequest $request;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->profiles  = $this->createMock(SeedProfileService::class);
		$this->segments  = $this->createMock(SegmentService::class);
		$this->request   = $this->createMock(IRequest::class);

		$this->profiles->method('listChoices')->willReturn(
			[
				['id' => 'none', 'label' => 'None', 'description' => 'x', 'objectCount' => 0, 'icon' => 'CloseCircleOutline'],
				['id' => 'po', 'label' => 'Primary school', 'description' => 'x', 'objectCount' => 3, 'icon' => 'SchoolOutline'],
				['id' => 'demo', 'label' => 'Every schema, generated values', 'description' => 'x', 'objectCount' => 405, 'icon' => 'DatabaseOutline'],
			]
		);
		$this->profiles->method('isKnown')->willReturnCallback(static fn (string $id): bool => in_array($id, ['po', 'demo'], true));
		$this->segments->method('listChoices')->willReturn([['id' => 'po', 'label' => 'Primary school', 'description' => 'x', 'icon' => 'SchoolOutline']]);
	}//end setUp()

	/**
	 * The controller, with the posted parameters and stored config given.
	 *
	 * @param array<string, mixed>  $params Posted parameters.
	 * @param array<string, string> $stored App config values.
	 *
	 * @return SetupController
	 */
	private function controller(array $params = [], array $stored = []): SetupController {
		$this->request->method('getParam')->willReturnCallback(static fn (string $key): mixed => ($params[$key] ?? null));
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($stored[$key] ?? $default)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new SetupController(
			$this->request,
			$this->appConfig,
			$this->createMock(LoggerInterface::class),
			$this->profiles,
			$this->segments,
			$session
		);
	}//end controller()

	/**
	 * Capture every app-config write.
	 *
	 * @return \ArrayObject<string, string> Written values, filled as the test runs.
	 */
	private function captureWrites(): \ArrayObject {
		$written = new \ArrayObject();
		$this->appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use ($written): bool {
				$written[$key] = $value;
				return true;
			}
		);

		return $written;
	}//end captureWrites()

	/**
	 * The status reports all three optional steps, both option lists, version
	 * 2, and never gates the app.
	 *
	 * @return void
	 */
	public function testStatusReportsEveryStepAndBothOptionLists(): void {
		$data = $this->controller()->status()->getData();

		self::assertSame(2, $data['version']);
		self::assertTrue($data['completed']);
		self::assertSame(['none', 'po', 'demo'], array_column($data['profiles'], 'id'));
		self::assertSame(['po'], array_column($data['segments'], 'id'));
		self::assertSame(['example-set', 'load-example-set', 'segment'], array_keys($data['steps']));
		self::assertFalse($data['steps']['example-set']['done']);
		self::assertFalse($data['steps']['load-example-set']['done']);
		self::assertFalse($data['steps']['segment']['done']);
	}//end testStatusReportsEveryStepAndBothOptionLists()

	/**
	 * A stored segment closes the segment step, however it was stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#scenario-a-school-picks-primary-school
	 */
	public function testAStoredSegmentClosesTheSegmentStep(): void {
		$this->segments->method('hasSegment')->willReturn(true);

		self::assertTrue($this->controller()->status()->getData()['steps']['segment']['done']);
	}//end testAStoredSegmentClosesTheSegmentStep()

	/**
	 * Choosing "None" closes both example steps; an answer given under the
	 * legacy key still counts.
	 *
	 * @return void
	 */
	public function testChoosingNoneClosesBothExampleSteps(): void {
		$data = $this->controller(stored: ['demo_dataset' => 'none'])->status()->getData();

		self::assertTrue($data['steps']['example-set']['done']);
		self::assertTrue($data['steps']['load-example-set']['done']);
	}//end testChoosingNoneClosesBothExampleSteps()

	/**
	 * A set on offer is stored under the new key, also when posted under the
	 * legacy key or as a one-element list.
	 *
	 * @return void
	 */
	public function testTheExampleSetChoiceIsPersisted(): void {
		$written = $this->captureWrites();

		$response = $this->controller(params: ['example_profile' => 'po'])->saveConfig();
		self::assertSame(['example_profile' => 'po'], $response->getData()['config']);
		self::assertSame('po', $written['example_profile']);

		$this->setUp();
		$written  = $this->captureWrites();
		$response = $this->controller(params: ['demo_dataset' => ['demo']])->saveConfig();
		self::assertSame(200, $response->getStatus());
		self::assertSame('demo', $written['example_profile']);
	}//end testTheExampleSetChoiceIsPersisted()

	/**
	 * An unknown set, a path, and a non-scalar answer are refused and nothing
	 * is stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#scenario-a-path-in-the-answer-is-refused
	 */
	public function testAnUnknownSetIsRefusedRatherThanStored(): void {
		foreach (['vo', '../../config/config', [['nested']]] as $value) {
			$this->setUp();
			$this->appConfig->expects(self::never())->method('setValueString');

			$response = $this->controller(params: ['example_profile' => $value])->saveConfig();

			self::assertSame(400, $response->getStatus(), json_encode($value));
			self::assertFalse($response->getData()['success']);
		}
	}//end testAnUnknownSetIsRefusedRatherThanStored()

	/**
	 * Posting nothing is not an answer and stores nothing.
	 *
	 * @return void
	 */
	public function testPostingNothingStoresNothing(): void {
		$this->appConfig->expects(self::never())->method('setValueString');
		$this->segments->expects(self::never())->method('setSegment');

		$data = $this->controller()->saveConfig()->getData();

		self::assertTrue($data['success']);
		self::assertSame([], $data['config']);
	}//end testPostingNothingStoresNothing()

	/**
	 * The segment answer is written to LearniqSettings with the admin as
	 * the one who set it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#scenario-a-school-picks-primary-school
	 */
	public function testTheSegmentAnswerIsWrittenWithTheAdminAsSetter(): void {
		$this->segments->expects(self::once())->method('setSegment')->with('po', 'admin');

		$data = $this->controller(params: ['segment' => 'po'])->saveConfig()->getData();

		self::assertSame(['segment' => 'po'], $data['config']);
	}//end testTheSegmentAnswerIsWrittenWithTheAdminAsSetter()

	/**
	 * An unknown segment is refused and nothing is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#scenario-an-unknown-segment-is-refused
	 */
	public function testAnUnknownSegmentIsRefused(): void {
		$this->segments->expects(self::never())->method('setSegment');

		$response = $this->controller(params: ['segment' => 'kindergarten'])->saveConfig();

		self::assertSame(400, $response->getStatus());
	}//end testAnUnknownSegmentIsRefused()

	/**
	 * Loading imports the picked set and names the count.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-wizard-choice/specs/example-sets/spec.md#scenario-loading-the-primary-school-set
	 */
	public function testLoadingImportsThePickedSetAndNamesTheCount(): void {
		$written = $this->captureWrites();
		$this->profiles->expects(self::once())->method('install')->with('po')->willReturn(['objects' => 3, 'profile' => 'po']);

		$data = $this->controller(stored: ['example_profile' => 'po'])->runAction('load-example-set')->getData();

		self::assertTrue($data['success']);
		self::assertStringContainsString('3', $data['message']);
		self::assertSame('installed', $written['demo_data_decided']);
	}//end testLoadingImportsThePickedSetAndNamesTheCount()

	/**
	 * After "None", loading records the decision and imports nothing.
	 *
	 * @return void
	 */
	public function testChoosingNoneAndThenLoadingImportsNothing(): void {
		$written = $this->captureWrites();
		$this->profiles->expects(self::never())->method('install');

		$data = $this->controller(stored: ['example_profile' => 'none'])->runAction('load-example-set')->getData();

		self::assertTrue($data['success']);
		self::assertSame('skipped', $written['demo_data_decided']);
	}//end testChoosingNoneAndThenLoadingImportsNothing()

	/**
	 * No silent default: loading without an answer is refused.
	 *
	 * @return void
	 */
	public function testLoadingWithoutAChoiceRefusesRatherThanGuessing(): void {
		$this->profiles->expects(self::never())->method('install');

		self::assertSame(400, $this->controller()->runAction('load-example-set')->getStatus());
	}//end testLoadingWithoutAChoiceRefusesRatherThanGuessing()

	/**
	 * The legacy `install-demo-data` still means the generated set.
	 *
	 * @return void
	 */
	public function testTheLegacyActionStillImportsTheGeneratedSet(): void {
		$this->captureWrites();
		$this->profiles->expects(self::once())->method('install')->with('demo')->willReturn(['objects' => 405, 'profile' => 'demo']);

		self::assertTrue($this->controller()->runAction('install-demo-data')->getData()['success']);
	}//end testTheLegacyActionStillImportsTheGeneratedSet()

	/**
	 * Skipping, under either id, closes BOTH example steps.
	 *
	 * @return void
	 */
	public function testSkippingClosesBothExampleSteps(): void {
		foreach (['skip-example-set', 'skip-demo-data'] as $action) {
			$this->setUp();
			$written = $this->captureWrites();

			self::assertTrue($this->controller()->runAction($action)->getData()['success']);
			self::assertSame('none', $written['example_profile'], $action);
			self::assertSame('skipped', $written['demo_data_decided'], $action);
		}
	}//end testSkippingClosesBothExampleSteps()

	/**
	 * An unknown action is a 404.
	 *
	 * @return void
	 */
	public function testUnknownActionIs404(): void {
		self::assertSame(404, $this->controller()->runAction('remove-everything')->getStatus());
	}//end testUnknownActionIs404()

	/**
	 * A failed load is reported and leaves the step undecided, so the wizard
	 * offers it again.
	 *
	 * @return void
	 */
	public function testAFailedLoadIsReportedAndLeavesTheStepUndecided(): void {
		$this->appConfig->expects(self::never())->method('setValueString');
		$this->profiles->method('install')->willThrowException(new RuntimeException('OpenRegister is not installed'));

		$response = $this->controller(stored: ['example_profile' => 'po'])->runAction('load-example-set');

		self::assertSame(500, $response->getStatus());
		self::assertStringContainsString('OpenRegister is not installed', $response->getData()['message']);
	}//end testAFailedLoadIsReportedAndLeavesTheStepUndecided()
}//end class
