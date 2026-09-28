<?php

/**
 * Unit tests for ReportCardPdfDelegationService.
 *
 * Covers the fail-soft contract: the guard `check()` ALWAYS allows, and
 * `render()` records the outcome and never throws regardless of outcome
 * (missing token, unreachable docudesk, malformed response, thrown
 * exception, or success), mirroring WalletRevocationPropagationService's
 * fail-soft shape. Records the request-body shape against the proposed
 * docudesk contract.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-pdf-render-failure-does-not-block-publication
 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-successful-render-records-the-docudesk-document-reference
 * @spec openspec/specs/report-card/spec.md#scenario-a-report-card-with-an-assigned-template-sends-that-templates-slug-to-docudesk
 * @spec openspec/specs/report-card/spec.md#scenario-a-report-card-with-no-assigned-template-keeps-sending-the-default-slug
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\ReportCardPdfDelegationService;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for ReportCardPdfDelegationService: the guard and render().
 */
class ReportCardPdfDelegationServiceTest extends TestCase {

	/**
	 * HTTP client-service mock.
	 *
	 * @var IClientService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private IClientService $clientService;

	/**
	 * URL generator mock.
	 *
	 * @var IURLGenerator&\PHPUnit\Framework\MockObject\MockObject
	 */
	private IURLGenerator $urlGenerator;

	/**
	 * App-config mock.
	 *
	 * @var IAppConfig&\PHPUnit\Framework\MockObject\MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * OpenRegister object-access mock, resolves a ReportCard's assigned
	 * ReportCardTemplate by id (report-card-templates change).
	 *
	 * @var ObjectService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private ObjectService $objectService;

	/**
	 * templateId => ReportCardTemplate data, read by {@see self::service()}'s
	 * $objectService `find()` stub.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $templates = [];

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->clientService = $this->createMock(IClientService::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->templates = [];

		$this->urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://learniq.example' . $path
		);

		$this->objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null) {
				if ($schema === 'report-card-template' && isset($this->templates[$id]) === true) {
					return OrEntityFactory::make($this->templates[$id], 'report-card-template');
				}

				return null;
			}
		);

	}//end setUp()

	/**
	 * Build the service under test.
	 *
	 * @return ReportCardPdfDelegationService
	 */
	private function service(): ReportCardPdfDelegationService {
		return new ReportCardPdfDelegationService(
			clientService: $this->clientService,
			urlGenerator: $this->urlGenerator,
			appConfig: $this->appConfig,
			appManager: $this->createMock(IAppManager::class),
			objectService: $this->objectService,
			logger: new NullLogger()
		);

	}//end service()

	/**
	 * A reachable docudesk endpoint returning a 2xx with a documentRef records
	 * `rendered`/docudeskDocumentRef and clears any prior error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-successful-render-records-the-docudesk-document-reference
	 */
	public function testSuccessfulRenderRecordsDocumentReference(): void {
		$this->appConfig->method('getValueString')->willReturn('token-abc');

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode(['documentRef' => 'doc-uuid-1']));

		$capturedUrl = null;
		$capturedOptions = null;

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('post')
			->willReturnCallback(
				function (string $url, array $options) use (&$capturedUrl, &$capturedOptions, $response): IResponse {
					$capturedUrl = $url;
					$capturedOptions = $options;
					return $response;
				}
			);
		$this->clientService->method('newClient')->willReturn($client);

		$object = [
			'id' => 'card-1',
			'subjectGrades' => [['curriculumPlanId' => 'plan-1']],
			'mentorComment' => 'Goed gedaan.',
			'attendanceSummary' => ['presentCount' => 10],
			'docudeskRenderError' => 'previous failure',
			'lifecycle' => 'finalised',
		];

		$saved = $this->service()->render(reportCard: $object);

		self::assertSame('rendered', $saved['docudeskRenderStatus']);
		self::assertSame('doc-uuid-1', $saved['docudeskDocumentRef']);
		self::assertNull($saved['docudeskRenderError']);
		self::assertNotEmpty($saved['docudeskRequestedAt']);

		// The app SEGMENT is resolved at call time — the target answers to
		// `filinq` on development and `docudesk` on beta/main — so pinning
		// either name here would assert the environment rather than the
		// contract. What must hold is the path after the segment, and that the
		// segment is one of the two ids the resolver may legitimately produce.
		self::assertStringContainsString('/api/v1/documents/render', (string)$capturedUrl);
		self::assertMatchesRegularExpression(
			'#/apps/(filinq|docudesk)/api/v1/documents/render#',
			(string)$capturedUrl
		);
		self::assertSame('Bearer token-abc', $capturedOptions['headers']['Authorization']);
		self::assertSame('card-1', $capturedOptions['json']['reportCardId']);
		self::assertSame([['curriculumPlanId' => 'plan-1']], $capturedOptions['json']['subjectGrades']);
		self::assertSame('report-card', $capturedOptions['json']['templateSlug']);

	}//end testSuccessfulRenderRecordsDocumentReference()

	/**
	 * No configured docudesk API token still returns true (fail-soft) but
	 * records the failure.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-pdf-render-failure-does-not-block-publication
	 */
	public function testMissingTokenIsFailSoft(): void {
		$this->appConfig->method('getValueString')->willReturn('');
		$this->clientService->expects($this->never())->method('newClient');

		$object = ['id' => 'card-2', 'subjectGrades' => [], 'mentorComment' => null, 'lifecycle' => 'finalised'];

		$saved = $this->service()->render(reportCard: $object);

		self::assertSame('failed', $saved['docudeskRenderStatus']);
		self::assertNotEmpty($saved['docudeskRenderError']);

	}//end testMissingTokenIsFailSoft()

	/**
	 * docudesk unreachable (HTTP client throws) is fail-soft — still returns
	 * true, never blocks the transition.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/report-card-composer/specs/report-card/spec.md#scenario-a-pdf-render-failure-does-not-block-publication
	 */
	public function testUnreachableDocudeskIsFailSoft(): void {
		$this->appConfig->method('getValueString')->willReturn('token-abc');

		$client = $this->createMock(IClient::class);
		$client->method('post')->willThrowException(new \Exception('Connection refused'));
		$this->clientService->method('newClient')->willReturn($client);

		$object = ['id' => 'card-3', 'subjectGrades' => [], 'mentorComment' => null, 'lifecycle' => 'finalised'];

		$saved = $this->service()->render(reportCard: $object);

		self::assertSame('failed', $saved['docudeskRenderStatus']);
		self::assertStringContainsString('Connection refused', $saved['docudeskRenderError']);
		// Fail-soft never touches lifecycle — the transition context still applies normally.
		self::assertSame('finalised', $saved['lifecycle']);

	}//end testUnreachableDocudeskIsFailSoft()

	/**
	 * A malformed/no-documentRef response is fail-soft, recorded as `failed`.
	 *
	 * @return void
	 */
	public function testMalformedResponseIsFailSoft(): void {
		$this->appConfig->method('getValueString')->willReturn('token-abc');

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode(['status' => 'accepted']));

		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturn($response);
		$this->clientService->method('newClient')->willReturn($client);

		$object = ['id' => 'card-4', 'subjectGrades' => [], 'mentorComment' => null, 'lifecycle' => 'published-to-parents'];

		$saved = $this->service()->render(reportCard: $object);

		self::assertSame('failed', $saved['docudeskRenderStatus']);
		self::assertNotEmpty($saved['docudeskRenderError']);

	}//end testMalformedResponseIsFailSoft()

	/**
	 * A ReportCard with a `templateId` resolving to a `ReportCardTemplate`
	 * sends that template's own `slug` as `templateSlug`, not the literal
	 * default (report-card-templates change).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/report-card/spec.md#scenario-a-report-card-with-an-assigned-template-sends-that-templates-slug-to-docudesk
	 */
	public function testRenderSendsAssignedTemplateSlug(): void {
		$this->templates['template-1'] = ['id' => 'template-1', 'slug' => 'huisstijl-groep-6'];
		$this->appConfig->method('getValueString')->willReturn('token-abc');

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode(['documentRef' => 'doc-uuid-2']));

		$capturedOptions = null;
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use (&$capturedOptions, $response): IResponse {
				$capturedOptions = $options;
				return $response;
			}
		);
		$this->clientService->method('newClient')->willReturn($client);

		$object = [
			'id' => 'card-5',
			'templateId' => 'template-1',
			'subjectGrades' => [],
			'mentorComment' => null,
			'lifecycle' => 'finalised',
		];

		$saved = $this->service()->render(reportCard: $object);

		self::assertSame('huisstijl-groep-6', $capturedOptions['json']['templateSlug']);

	}//end testRenderSendsAssignedTemplateSlug()

	/**
	 * A ReportCard with no `templateId` keeps sending the literal default
	 * `'report-card'` templateSlug, unchanged (report-card-templates
	 * change: fallback path).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/report-card/spec.md#scenario-a-report-card-with-no-assigned-template-keeps-sending-the-default-slug
	 */
	public function testRenderSendsDefaultSlugWithoutTemplate(): void {
		$this->appConfig->method('getValueString')->willReturn('token-abc');

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode(['documentRef' => 'doc-uuid-3']));

		$capturedOptions = null;
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use (&$capturedOptions, $response): IResponse {
				$capturedOptions = $options;
				return $response;
			}
		);
		$this->clientService->method('newClient')->willReturn($client);

		$object = [
			'id' => 'card-6',
			'templateId' => null,
			'subjectGrades' => [],
			'mentorComment' => null,
			'lifecycle' => 'finalised',
		];

		$saved = $this->service()->render(reportCard: $object);

		self::assertSame('report-card', $capturedOptions['json']['templateSlug']);

	}//end testRenderSendsDefaultSlugWithoutTemplate()

	/**
	 * A ReportCard whose `templateId` does not resolve to any
	 * ReportCardTemplate (deleted, or never existed) falls back to the
	 * default `templateSlug`, not an error (report-card-templates change).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/report-card/spec.md#scenario-a-report-card-with-no-assigned-template-keeps-sending-the-default-slug
	 */
	public function testFallsBackToDefaultSlugWhenTemplateNotFound(): void {
		// 'template-missing' is deliberately absent from $this->templates.
		$this->appConfig->method('getValueString')->willReturn('token-abc');

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode(['documentRef' => 'doc-uuid-4']));

		$capturedOptions = null;
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use (&$capturedOptions, $response): IResponse {
				$capturedOptions = $options;
				return $response;
			}
		);
		$this->clientService->method('newClient')->willReturn($client);

		$object = [
			'id' => 'card-7',
			'templateId' => 'template-missing',
			'subjectGrades' => [],
			'mentorComment' => null,
			'lifecycle' => 'finalised',
		];

		$saved = $this->service()->render(reportCard: $object);

		self::assertSame('report-card', $capturedOptions['json']['templateSlug']);

	}//end testFallsBackToDefaultSlugWhenTemplateNotFound()

	/**
	 * A resolved ReportCardTemplate with no (or an empty) `slug` falls back
	 * to the default `templateSlug` rather than sending an empty string
	 * (report-card-templates change).
	 *
	 * @return void
	 */
	public function testFallsBackToDefaultSlugWhenTemplateHasNoSlug(): void {
		$this->templates['template-no-slug'] = ['id' => 'template-no-slug'];
		$this->appConfig->method('getValueString')->willReturn('token-abc');

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode(['documentRef' => 'doc-uuid-5']));

		$capturedOptions = null;
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use (&$capturedOptions, $response): IResponse {
				$capturedOptions = $options;
				return $response;
			}
		);
		$this->clientService->method('newClient')->willReturn($client);

		$object = [
			'id' => 'card-8',
			'templateId' => 'template-no-slug',
			'subjectGrades' => [],
			'mentorComment' => null,
			'lifecycle' => 'finalised',
		];

		$saved = $this->service()->render(reportCard: $object);

		self::assertSame('report-card', $capturedOptions['json']['templateSlug']);

	}//end testFallsBackToDefaultSlugWhenTemplateHasNoSlug()

	/**
	 * A thrown exception while resolving the ReportCardTemplate is caught
	 * and falls back to the default `templateSlug` — the docudesk render
	 * still proceeds, mirroring this service's overall fail-soft contract
	 * (report-card-templates change).
	 *
	 * @return void
	 */
	public function testFallsBackToDefaultSlugWhenTemplateLookupThrows(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('find')->willThrowException(new RuntimeException('object store unavailable'));

		$this->appConfig->method('getValueString')->willReturn('token-abc');

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode(['documentRef' => 'doc-uuid-6']));

		$capturedOptions = null;
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use (&$capturedOptions, $response): IResponse {
				$capturedOptions = $options;
				return $response;
			}
		);
		$this->clientService->method('newClient')->willReturn($client);

		$object = [
			'id' => 'card-9',
			'templateId' => 'template-throws',
			'subjectGrades' => [],
			'mentorComment' => null,
			'lifecycle' => 'finalised',
		];

		$saved = $this->service()->render(reportCard: $object);

		self::assertSame('report-card', $capturedOptions['json']['templateSlug']);

	}//end testFallsBackToDefaultSlugWhenTemplateLookupThrows()
	/**
	 * The renderToPdf/rerenderToPdf guard is one OpenRegister can run, and it
	 * always allows without calling docudesk.
	 *
	 * @return void
	 */
	public function testGuardAlwaysAllowsTheRender(): void {
		$this->clientService->expects($this->never())->method('newClient');

		$service = $this->service();

		self::assertInstanceOf(LifecycleGuardInterface::class, $service);
		self::assertTrue($service->check(['id' => 'card-9', 'lifecycle' => 'finalised'], 'renderToPdf', 'mentor-1')->isAllowed());
	}//end testGuardAlwaysAllowsTheRender()
}//end class
