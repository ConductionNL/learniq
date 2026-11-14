<?php

/**
 * Tests for the listener that writes the ReportCard PDF self-loop results.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Kernel\Contracts\ReportCardPdfAttacherInterface;
use OCA\Learniq\Listener\ReportCardPdfTransitionListener;
use OCA\Learniq\Service\ReportCardPdfDelegationService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Lifecycle\TransitionActor;
use OCA\OpenRegister\Service\Lifecycle\TransitionMove;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * renderToPdf and rerenderToPdf are self-loops, and OpenRegister runs neither
 * guards nor actions on a self-loop. The render and its result therefore run
 * here, after the save, on ObjectTransitionedEvent (learniq#983).
 */
class ReportCardPdfTransitionListenerTest extends TestCase {

	/**
	 * ObjectService mock that records the save.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Attacher mock that records the attach.
	 *
	 * @var ReportCardPdfAttacherInterface&MockObject
	 */
	private ReportCardPdfAttacherInterface&MockObject $attacher;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->attacher      = $this->createMock(ReportCardPdfAttacherInterface::class);
	}//end setUp()

	/**
	 * Build the listener over a docudesk rail answering with the given body.
	 *
	 * @param string $body The render endpoint's response body.
	 *
	 * @return ReportCardPdfTransitionListener
	 */
	private function listener(string $body): ReportCardPdfTransitionListener {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($body);
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturn($response);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('token-abc');
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnArgument(0);

		return new ReportCardPdfTransitionListener(
			objectService: $this->objectService,
			pdfService: new ReportCardPdfDelegationService(
				clientService: $clientService,
				urlGenerator: $urlGenerator,
				appConfig: $appConfig,
				appManager: $this->createMock(IAppManager::class),
				objectService: $this->objectService,
				logger: new NullLogger()
			),
			logger: new NullLogger(),
			schemas: \OCA\Learniq\Tests\Support\TransitionScope::resolver(),
			attacher: $this->attacher
		);
	}//end listener()

	/**
	 * A transitioned event for a ReportCard.
	 *
	 * @param string $action The transition that ran.
	 * @param string $state The lifecycle state (from and to).
	 * @param string $schema The schema slug on the event.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function event(string $action, string $state, string $schema = 'report-card'): ObjectTransitionedEvent {
		$card = ['id' => 'card-1', 'lifecycle' => $state, 'subjectGrades' => [], 'mentorComment' => 'Goed gedaan.'];

		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn(OrEntityFactory::make($card, $schema));
		$event->method('getAction')->willReturn($action);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn($schema);
		$event->method('getFrom')->willReturn($state);
		$event->method('getTo')->willReturn($state);

		return $event;
	}//end event()

	/**
	 * Capture the object handed to saveObject().
	 *
	 * @return \ArrayObject<string,mixed> Filled with the saved object and uuid once saved.
	 */
	private function captureSave(): \ArrayObject {
		$captured = new \ArrayObject();
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			static function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) use ($captured) {
				$captured['object'] = $object;
				$captured['schema'] = $schema;
				$captured['uuid'] = $uuid;

				return OrEntityFactory::make($object, 'report-card');
			}
		);

		return $captured;
	}//end captureSave()

	/**
	 * renderToPdf saves the docudesk document reference on the ReportCard.
	 *
	 * @return void
	 */
	public function testRenderToPdfSavesTheDocumentReference(): void {
		$captured = $this->captureSave();

		$this->listener('{"documentRef":"doc-1"}')->handle($this->event('renderToPdf', 'finalised'));

		self::assertSame('rendered', $captured['object']['docudeskRenderStatus']);
		self::assertSame('doc-1', $captured['object']['docudeskDocumentRef']);
		self::assertNotEmpty($captured['object']['docudeskRequestedAt']);
		self::assertSame('finalised', $captured['object']['lifecycle']);
		self::assertSame('card-1', $captured['uuid']);
		self::assertSame('report-card', $captured['schema']);
	}//end testRenderToPdfSavesTheDocumentReference()

	/**
	 * A failed rerenderToPdf saves the failure on the ReportCard, fail-soft.
	 *
	 * @return void
	 */
	public function testFailedRerenderSavesTheFailure(): void {
		$captured = $this->captureSave();

		$this->listener('not json')->handle($this->event('rerenderToPdf', 'published-to-parents'));

		self::assertSame('failed', $captured['object']['docudeskRenderStatus']);
		self::assertNotEmpty($captured['object']['docudeskRenderError']);
		self::assertSame('published-to-parents', $captured['object']['lifecycle']);
	}//end testFailedRerenderSavesTheFailure()

	/**
	 * A real transitioned event, as OpenRegister dispatches it.
	 *
	 * @param string $action The transition that ran.
	 * @param array<string, mixed> $card The report card as saved.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function realEvent(string $action, array $card): ObjectTransitionedEvent {
		return new ObjectTransitionedEvent(
			object: OrEntityFactory::make($card, 'report-card'),
			move: new TransitionMove(action: $action, from: 'finalised', to: 'published-to-parents'),
			scope: new RegisterSchemaRef(register: 'learniq', schema: 'report-card'),
			actor: new TransitionActor(userId: 'mentor-1')
		);
	}//end realEvent()

	/**
	 * Publishing a card that has no PDF renders it after the publish (REQ: publishing renders its PDF).
	 *
	 * @return void
	 */
	public function testPublishToParentsRendersTheMissingPdf(): void {
		$captured = $this->captureSave();
		$card = ['id' => 'card-1', 'lifecycle' => 'published-to-parents', 'subjectGrades' => [], 'mentorComment' => 'Goed gedaan.'];

		$this->listener('{"documentRef":"doc-7"}')->handle($this->realEvent('publishToParents', $card));

		self::assertSame('rendered', $captured['object']['docudeskRenderStatus']);
		self::assertSame('doc-7', $captured['object']['docudeskDocumentRef']);
		self::assertSame('published-to-parents', $captured['object']['lifecycle']);
		self::assertSame('card-1', $captured['uuid']);
	}//end testPublishToParentsRendersTheMissingPdf()

	/**
	 * filinq down at publish: the card stays published and the failure is recorded.
	 *
	 * @return void
	 */
	public function testAFailedRenderAtPublishLeavesTheCardPublished(): void {
		$captured = $this->captureSave();
		$card = ['id' => 'card-1', 'lifecycle' => 'published-to-parents', 'docudeskRenderStatus' => 'failed', 'subjectGrades' => []];

		$this->listener('not json')->handle($this->realEvent('publishToParents', $card));

		self::assertSame('failed', $captured['object']['docudeskRenderStatus']);
		self::assertNotEmpty($captured['object']['docudeskRenderError']);
		self::assertSame('published-to-parents', $captured['object']['lifecycle']);
	}//end testAFailedRenderAtPublishLeavesTheCardPublished()

	/**
	 * A card already rendered before the meeting is not rendered again on publish.
	 *
	 * @return void
	 */
	public function testPublishToParentsKeepsAnAlreadyRenderedPdf(): void {
		$this->objectService->expects($this->never())->method('saveObject');
		$card = ['id' => 'card-1', 'lifecycle' => 'published-to-parents', 'docudeskRenderStatus' => 'rendered', 'docudeskDocumentRef' => 'doc-1'];

		$this->listener('{"documentRef":"doc-2"}')->handle($this->realEvent('publishToParents', $card));
	}//end testPublishToParentsKeepsAnAlreadyRenderedPdf()

	/**
	 * A successful render attaches the PDF to the card as saved (REQ: the rendered PDF is a file on the report card).
	 *
	 * @return void
	 */
	public function testASuccessfulRenderAttachesThePdfToTheSavedCard(): void {
		$captured = $this->captureSave();
		$attached = new \ArrayObject();
		$this->attacher->expects($this->once())->method('attach')->willReturnCallback(
			static function (ObjectEntity $reportCard) use ($attached): string {
				$attached['card'] = $reportCard->getObject();
				return 'Rapport 1 Sam.pdf';
			}
		);

		$this->listener('{"documentRef":"417"}')->handle($this->event('rerenderToPdf', 'published-to-parents'));

		self::assertSame('417', $attached['card']['docudeskDocumentRef']);
		self::assertSame('rendered', $attached['card']['docudeskRenderStatus']);
		self::assertSame('rendered', $captured['object']['docudeskRenderStatus']);
	}//end testASuccessfulRenderAttachesThePdfToTheSavedCard()

	/**
	 * A failed render attaches nothing.
	 *
	 * @return void
	 */
	public function testAFailedRenderAttachesNothing(): void {
		$this->captureSave();
		$this->attacher->expects($this->never())->method('attach');

		$this->listener('not json')->handle($this->event('renderToPdf', 'finalised'));
	}//end testAFailedRenderAttachesNothing()

	/**
	 * Another schema or another transition saves nothing.
	 *
	 * @return void
	 */
	public function testIgnoresOtherSchemasAndTransitions(): void {
		$this->objectService->expects($this->never())->method('saveObject');
		$this->attacher->expects($this->never())->method('attach');

		$this->listener('{}')->handle($this->event('renderToPdf', 'finalised', 'credential'));
		$this->listener('{}')->handle($this->event('finalise', 'finalised'));
	}//end testIgnoresOtherSchemasAndTransitions()
}//end class
