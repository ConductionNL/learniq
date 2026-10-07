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
 * @spec openspec/specs/example-sets/spec.md
 * @spec openspec/specs/example-sets/spec.md
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\ExampleSetsController;
use OCA\Learniq\Controller\SetupController;
use OCA\Learniq\Service\LoadedExampleSets;
use OCA\Learniq\Service\SeedProfileService;
use OCA\Learniq\Service\SegmentService;
use OCP\IAppConfig;
use OCP\IGroupManager;
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
	 * The groups the signed-in user `admin` is in.
	 *
	 * @var array<int, string>
	 */
	private array $userGroups = ['admin'];

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
		$this->profiles->method('loadedSets')->willReturn(new LoadedExampleSets($this->appConfig));
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

		$groups     = $this->createMock(IGroupManager::class);
		$userGroups = $this->userGroups;
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => $uid === 'admin' && in_array($group, $userGroups, true)
		);

		return new SetupController(
			$this->request,
			$this->appConfig,
			$this->createMock(LoggerInterface::class),
			$this->profiles,
			$this->segments,
			$session,
			$groups
		);
	}//end controller()

	/**
	 * The admin page's example-sets endpoint, over the same stored config.
	 *
	 * @param array<string, string> $stored App-config values by key.
	 *
	 * @return ExampleSetsController The controller under test.
	 */
	private function exampleSetsController(array $stored = []): ExampleSetsController {
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($stored[$key] ?? $default)
		);

		return new ExampleSetsController($this->request, $this->profiles);
	}//end exampleSetsController()

	/**
	 * SetupController no longer carries the admin list, which keeps it under
	 * phpmd's class complexity threshold; the URL moved controllers, not paths.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/wizard-drops-the-removal-step/specs/example-sets/spec.md
	 */
	public function testTheExampleSetListLivesInItsOwnController(): void {
		self::assertFalse(method_exists(SetupController::class, 'exampleSets'));
		$routes = include __DIR__ . '/../../../appinfo/routes.php';
		$names  = array_column($routes['routes'], 'name', 'url');
		self::assertSame('example_sets#exampleSets', $names['/api/setup/example-sets']);
	}//end testTheExampleSetListLivesInItsOwnController()

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
		self::assertSame(
			['welcome', 'example-set', 'segment', 'remove-example-set', 'done', 'remove-example-set-po', 'remove-example-set-demo'],
			array_keys($data['steps'])
		);
		self::assertFalse($data['steps']['example-set']['done']);
		self::assertFalse($data['steps']['segment']['done']);
	}//end testStatusReportsEveryStepAndBothOptionLists()

	/**
	 * A stored segment closes the segment step, however it was stored.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-a-school-picks-primary-school
	 */
	public function testAStoredSegmentClosesTheSegmentStep(): void {
		$this->segments->method('hasSegment')->willReturn(true);

		self::assertTrue($this->controller()->status()->getData()['steps']['segment']['done']);
	}//end testAStoredSegmentClosesTheSegmentStep()

	/**
	 * Choosing "None" closes the example step; an answer given under the
	 * legacy key still counts.
	 *
	 * @return void
	 */
	public function testChoosingNoneClosesTheExampleStep(): void {
		$data = $this->controller(stored: ['demo_dataset' => 'none'])->status()->getData();

		self::assertTrue($data['steps']['example-set']['done']);
		self::assertArrayNotHasKey('load-example-set', $data['steps']);
	}//end testChoosingNoneClosesTheExampleStep()

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
	 * @spec openspec/specs/example-sets/spec.md#scenario-a-path-in-the-answer-is-refused
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
	 * @spec openspec/specs/example-sets/spec.md#scenario-a-school-picks-primary-school
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
	 * @spec openspec/specs/example-sets/spec.md#scenario-an-unknown-segment-is-refused
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
	 * @spec openspec/specs/example-sets/spec.md#scenario-loading-the-primary-school-set
	 */
	public function testLoadingImportsThePickedSetAndNamesTheCount(): void {
		$written = $this->captureWrites();
		$this->profiles->expects(self::once())->method('install')->with('po')->willReturn(['objects' => 3, 'profile' => 'po']);

		$data = $this->controller(stored: ['example_profile' => 'po'])->runAction('load-example-set')->getData();

		self::assertTrue($data['success']);
		self::assertStringContainsString('3', $data['message']);
		self::assertSame('installed', $written['demo_data_decided']);
		self::assertSame('po', $written['example_profile']);
	}//end testLoadingImportsThePickedSetAndNamesTheCount()

	/**
	 * A load that skipped objects says how many did not arrive.
	 *
	 * Live on 2026-10-04 the reply read "Imported 490 example object(s)." while
	 * OpenRegister skipped 302 of them.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-loading-a-set-imports-exactly-its-descriptor
	 */
	public function testALoadThatSkippedObjectsSaysHowManyDidNotArrive(): void {
		$this->captureWrites();
		$this->profiles->method('install')->willReturn(['objects' => 490, 'skipped' => 302, 'profile' => 'demo']);

		$data = $this->controller(stored: ['example_profile' => 'demo'])->runAction('load-example-set')->getData();

		self::assertStringContainsString('Imported 188 of 490', $data['message']);
		self::assertStringContainsString('302 could not be imported', $data['message']);
		self::assertSame(302, $data['skipped']);
	}//end testALoadThatSkippedObjectsSaysHowManyDidNotArrive()

	/**
	 * A per-set step removes the set it names, not the wizard's answer.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-removing-one-of-two-loaded-sets
	 */
	public function testAPerSetStepRemovesThatSet(): void {
		$this->profiles->expects(self::once())->method('remove')->with('demo')->willReturn(
			['supported' => true, 'appId' => 'learniq.demo', 'jobs' => ['job-1'], 'softDeleted' => 7, 'errors' => 0, 'failedJobs' => []]
		);

		$data = $this->controller(stored: ['example_profile' => 'po'])->runAction('remove-example-set-demo')->getData();

		self::assertTrue($data['success']);
		self::assertStringContainsString('Moved 7', $data['message']);
	}//end testAPerSetStepRemovesThatSet()

	/**
	 * A per-set step naming no set is refused before anything is called.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-wizard-lists-every-loaded-example-set-with-its-own-remove-button
	 */
	public function testAPerSetStepForAnUnknownSetIsRefused(): void {
		$this->profiles->expects(self::never())->method('remove');

		$response = $this->controller()->runAction('remove-example-set-nope');

		self::assertSame(400, $response->getStatus());
	}//end testAPerSetStepForAnUnknownSetIsRefused()

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

	/**
	 * The removal step is done in every state, so the wizard never starts it
	 * on its own and never reopens for it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-opening-the-wizard-after-loading-a-set
	 */
	public function testTheRemovalStepIsAlwaysDone(): void {
		foreach ([[], ['example_profile' => 'po', 'demo_data_decided' => 'installed'], ['example_profile' => 'none']] as $stored) {
			$this->setUp();
			$data = $this->controller(stored: $stored)->status()->getData();
			self::assertTrue($data['steps']['remove-example-set']['done'], json_encode($stored));
		}
	}//end testTheRemovalStepIsAlwaysDone()

	/**
	 * Removing the loaded set reports what moved to the trash and keeps the
	 * load step answered.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-removing-the-company-set
	 */
	public function testRemovingTheLoadedSetReportsTheTrashedCount(): void {
		$written = $this->captureWrites();
		$this->profiles->expects(self::once())->method('remove')->with('po')->willReturn(
			['supported' => true, 'appId' => 'learniq.profile.po', 'jobs' => ['job-1'], 'softDeleted' => 3, 'errors' => 0, 'failedJobs' => []]
		);

		$data = $this->controller(stored: ['example_profile' => 'po'])->runAction('remove-example-set')->getData();

		self::assertTrue($data['success']);
		self::assertStringContainsString('Moved 3 example object(s) to the trash', $data['message']);
		self::assertSame('removed', $written['demo_data_decided'] ?? null);
	}//end testRemovingTheLoadedSetReportsTheTrashedCount()

	/**
	 * Nothing loaded: nothing is called and the answer says so.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-nothing-was-loaded
	 */
	public function testNothingLoadedRemovesNothing(): void {
		$this->profiles->expects(self::never())->method('remove');

		foreach ([[], ['example_profile' => 'none']] as $stored) {
			$data = $this->controller(stored: $stored)->runAction('remove-example-set')->getData();
			self::assertTrue($data['success']);
			self::assertStringContainsString('nothing to remove', $data['message']);
		}
	}//end testNothingLoadedRemovesNothing()

	/**
	 * An OpenRegister without softDeleteAppImports(): the answer is a failure
	 * that names the occ command removing the set by uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-an-openregister-without-the-method
	 */
	public function testWithoutTheMethodTheAnswerNamesTheOccCommand(): void {
		$this->appConfig->expects(self::never())->method('setValueString');
		$this->profiles->method('remove')->willReturn(
			['supported' => false, 'appId' => 'learniq.profile.corporate', 'jobs' => [], 'softDeleted' => 0, 'errors' => 0, 'failedJobs' => []]
		);

		$data = $this->controller(stored: ['example_profile' => 'corporate'])->runAction('remove-example-set')->getData();

		self::assertFalse($data['success']);
		self::assertStringContainsString('php occ learniq:example-set:remove corporate --apply', $data['message']);
	}//end testWithoutTheMethodTheAnswerNamesTheOccCommand()

	/**
	 * No recorded import job (a set loaded on an older OpenRegister): nothing
	 * removed, and the occ command is named; errors name the job to finish.
	 *
	 * @return void
	 */
	public function testNoJobAndErrorsAreReportedWithTheirCommand(): void {
		$this->profiles->method('remove')->willReturnOnConsecutiveCalls(
			['supported' => true, 'appId' => 'learniq.profile.po', 'jobs' => [], 'softDeleted' => 0, 'errors' => 0, 'failedJobs' => []],
			['supported' => true, 'appId' => 'learniq.profile.po', 'jobs' => ['job-1', 'job-2', 'job-3'], 'softDeleted' => 5, 'errors' => 2, 'failedJobs' => ['job-2', 'job-3']]
		);
		$controller = $this->controller(stored: ['example_profile' => 'po']);

		$none = $controller->runAction('remove-example-set')->getData();
		self::assertTrue($none['success']);
		self::assertStringContainsString('no recorded import', $none['message']);
		self::assertStringContainsString('learniq:example-set:remove po --apply', $none['message']);

		$partial = $controller->runAction('remove-example-set')->getData();
		self::assertFalse($partial['success']);
		self::assertStringContainsString('Moved 5 example object(s)', $partial['message']);
		self::assertStringContainsString('php occ openregister:objects:purge --import-job job-2; php occ openregister:objects:purge --import-job job-3', $partial['message']);
		self::assertStringContainsString('add --apply', $partial['message']);
	}//end testNoJobAndErrorsAreReportedWithTheirCommand()

	/**
	 * A failing removal is a 500 with the reason, not a quiet success.
	 *
	 * @return void
	 */
	public function testAFailedRemovalIsReported(): void {
		$this->profiles->method('remove')->willThrowException(new RuntimeException('Example data needs OpenRegister, which is not installed.'));

		$response = $this->controller(stored: ['example_profile' => 'po'])->runAction('remove-example-set');

		self::assertSame(500, $response->getStatus());
		self::assertStringContainsString('OpenRegister', $response->getData()['message']);
	}//end testAFailedRemovalIsReported()

	/**
	 * A user outside admin and administration-managers cannot choose the
	 * segment, even when they may open the setup wizard.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-a-delegated-admin-outside-both-groups
	 */
	public function testTheSegmentIsRefusedOutsideTheTwoGroups(): void {
		$this->userGroups = ['compliance-officers'];
		$this->segments->expects(self::never())->method('setSegment');

		$response = $this->controller(params: ['segment' => 'corporate'])->saveConfig();

		self::assertSame(403, $response->getStatus());
		self::assertStringContainsString('administration manager', $response->getData()['message']);
	}//end testTheSegmentIsRefusedOutsideTheTwoGroups()

	/**
	 * An administration manager who is not a Nextcloud admin chooses it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#scenario-an-administration-manager
	 */
	public function testAnAdministrationManagerChoosesTheSegment(): void {
		$this->userGroups = ['administration-managers'];
		$this->segments->expects(self::once())->method('setSegment')->with('po', 'admin');

		$data = $this->controller(params: ['segment' => 'po'])->saveConfig()->getData();

		self::assertSame(['segment' => 'po'], $data['config']);
	}//end testAnAdministrationManagerChoosesTheSegment()

	/**
	 * Every manifest step id is reported; the extra ids are the removal
	 * actions, still reported done so a browser holding an older manifest
	 * never starts one by itself (the wizard no longer declares them).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	public function testStatusReportsEveryManifestStepId(): void {
		$manifest = json_decode((string)file_get_contents(__DIR__ . '/../../../src/manifest.json'), true);
		$declared = array_column($manifest['setup']['steps'], 'id');
		$reported = array_keys($this->controller()->status()->getData()['steps']);

		self::assertSame([], array_values(array_diff($declared, $reported)), 'every manifest step is reported');
		foreach (array_diff($reported, $declared) as $extra) {
			self::assertStringStartsWith('remove-example-set', $extra);
			self::assertTrue($this->controller()->status()->getData()['steps'][$extra]['done']);
		}

		$steps = array_column($manifest['setup']['steps'], null, 'id');
		self::assertSame('load-example-set', $steps['example-set']['loadAction'] ?? null);
		self::assertArrayNotHasKey('load-example-set', $steps);
	}//end testStatusReportsEveryManifestStepId()

	/**
	 * The card's Load button posts `{ dataset }`; the load records the pick.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	public function testTheCardPostsItsSetAndTheLoadRecordsTheChoice(): void {
		$written = $this->captureWrites();
		$this->profiles->expects(self::once())->method('install')->with('po')->willReturn(['objects' => 3, 'profile' => 'po']);

		$data = $this->controller(params: ['dataset' => 'po'])->runAction('load-example-set')->getData();

		self::assertTrue($data['success']);
		self::assertSame(['example_profile' => 'po', 'demo_data_decided' => 'installed'], $written->getArrayCopy());
	}//end testTheCardPostsItsSetAndTheLoadRecordsTheChoice()

	/**
	 * A posted set no card offers is refused, and nothing loads.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	public function testAnUnknownPostedSetIsRefusedAndNothingLoads(): void {
		$written = $this->captureWrites();
		$this->profiles->expects(self::never())->method('install');

		$response = $this->controller(params: ['dataset' => 'atlantis'], stored: ['example_profile' => 'po'])->runAction('load-example-set');

		self::assertSame(400, $response->getStatus());
		self::assertStringContainsString('atlantis', $response->getData()['message']);
		self::assertSame([], $written->getArrayCopy());
	}//end testAnUnknownPostedSetIsRefusedAndNothingLoads()

	/**
	 * A failed card load stores neither the pick nor the decision.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	public function testAFailedCardLoadStoresNothing(): void {
		$written = $this->captureWrites();
		$this->profiles->method('install')->willThrowException(new RuntimeException('OpenRegister is not installed'));

		$response = $this->controller(params: ['dataset' => 'po'])->runAction('load-example-set');

		self::assertSame(500, $response->getStatus());
		self::assertSame([], $written->getArrayCopy());
	}//end testAFailedCardLoadStoresNothing()

	/**
	 * Nothing loaded, nothing listed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/wizard-drops-the-removal-step/specs/example-sets/spec.md
	 */
	public function testTheAdminPageListsNothingWhenNothingWasLoaded(): void {
		self::assertSame(['sets' => []], $this->exampleSetsController()->exampleSets()->getData());
	}//end testTheAdminPageListsNothingWhenNothingWasLoaded()

	/**
	 * The admin page lists the loaded example sets, with their labels.
	 *
	 * The wizard no longer removes example data; the admin page's Example
	 * data section reads this list and offers a Remove button per set.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/wizard-drops-the-removal-step/specs/example-sets/spec.md
	 */
	public function testTheAdminPageReadsTheLoadedSets(): void {
		$stored = ['example_sets_loaded' => json_encode([['id' => 'po', 'label' => 'Primary school'], ['id' => 'demo', 'label' => 'Generated']])];

		$data = $this->exampleSetsController(stored: $stored)->exampleSets()->getData();

		self::assertSame(
			[['id' => 'po', 'label' => 'Primary school'], ['id' => 'demo', 'label' => 'Generated']],
			$data['sets']
		);
	}//end testTheAdminPageReadsTheLoadedSets()
}//end class

