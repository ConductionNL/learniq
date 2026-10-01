<?php

/**
 * Learniq AccessibilityEvidenceController unit tests.
 *
 * governance-wcag-evidence-report task 2.2: the evidence downloads as CSV or
 * JSON without signing in, only for a published statement, and a read failure
 * never reaches the anonymous caller as a trace.
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
 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-evidence-on-request
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\AccessibilityEvidenceController;
use OCA\Learniq\Service\Accessibility\ConformanceEvidence;
use OCA\Learniq\Service\Accessibility\WcagCriteriaCatalogue;
use OCA\Learniq\Service\CsvCellSanitizer;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * The public evidence routes.
 *
 * @covers \OCA\Learniq\Controller\AccessibilityEvidenceController
 * @uses \OCA\Learniq\Service\Accessibility\ConformanceEvidence
 * @uses \OCA\Learniq\Service\Accessibility\WcagCriteriaCatalogue
 * @uses \OCA\Learniq\Service\CsvCellSanitizer
 */
class AccessibilityEvidenceControllerTest extends TestCase {

	/**
	 * The controller over a register holding the given statements.
	 *
	 * @param array<int,array<string,mixed>>|null $statements Published statements, or null to make the read fail.
	 *
	 * @return AccessibilityEvidenceController
	 */
	private function controller(?array $statements): AccessibilityEvidenceController {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config) use ($statements): array {
				if ($statements === null) {
					throw new RuntimeException('register not installed');
				}

				if (($config['filters']['schema'] ?? '') === 'accessibility-statement') {
					return $statements;
				}

				if (($config['filters']['schema'] ?? '') === 'accessibility-criterion-result') {
					return [['wcagCriterion' => '1.4.3', 'result' => 'pass', 'method' => 'axe', 'testedOn' => '2026-08-01', 'testedBy' => 'Sem de Jong']];
				}

				return [];
			}
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $params): string => '/' . $route . '?' . http_build_query($params)
		);

		return new AccessibilityEvidenceController(
			$this->createMock(IRequest::class),
			new ConformanceEvidence($objectService, new WcagCriteriaCatalogue(), new CsvCellSanitizer()),
			$urls,
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * The headers the response set itself. Response::getHeaders() merges in
	 * server defaults through \OCP\Server, which the unit environment lacks.
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string, string>
	 */
	private static function headersOf(Response $response): array {
		return (array)(new ReflectionProperty(Response::class, 'headers'))->getValue($response);
	}//end headersOf()

	/**
	 * A published statement.
	 *
	 * @return array<string,mixed>
	 */
	private static function published(): array {
		return ['id' => 'statement-1', 'channelTitle' => 'Learniq', 'evaluationDate' => '2026-09-01', 'lifecycle' => 'published'];
	}//end published()

	/**
	 * An anonymous visitor downloads the CSV: one row per criterion, no tester.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#scenario-a-procurement-officer-asks-for-evidence
	 */
	public function testAVisitorDownloadsTheCsv(): void {
		$response = $this->controller(statements: [self::published()])->evidence(format: 'csv');

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		self::assertSame('text/csv', self::headersOf($response)['Content-Type']);
		self::assertStringContainsString('accessibility-evidence-2026-09-01.csv', self::headersOf($response)['Content-Disposition']);
		$csv = $response->render();
		self::assertCount(51, array_filter(explode("\n", $csv)));
		self::assertStringContainsString('1.4.3,AA,"Contrast (Minimum)",pass,axe,,2026-08-01,', $csv);
		self::assertStringNotContainsString('Sem de Jong', $csv);
	}//end testAVisitorDownloadsTheCsv()

	/**
	 * The JSON download holds the statement fields and the table.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-evidence-on-request
	 */
	public function testTheJsonHoldsStatementAndTable(): void {
		$response = $this->controller(statements: [self::published()])->evidence();

		self::assertInstanceOf(DataDownloadResponse::class, $response);
		$body = json_decode($response->render(), true);
		self::assertSame('Learniq', $body['statement']['channelTitle']);
		self::assertCount(50, $body['criteria']);
		self::assertSame(49, $body['summary']['not-tested']);
		self::assertStringNotContainsString('Sem de Jong', $response->render());
	}//end testTheJsonHoldsStatementAndTable()

	/**
	 * A wrong format, nothing published, or a failing read each get a plain answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-evidence-on-request
	 */
	public function testTheErrorAnswers(): void {
		$badFormat = $this->controller(statements: [self::published()])->evidence(format: 'xlsx');
		self::assertInstanceOf(JSONResponse::class, $badFormat);
		self::assertSame(400, $badFormat->getStatus());

		$none = $this->controller(statements: [])->evidence(format: 'csv');
		self::assertInstanceOf(JSONResponse::class, $none);
		self::assertSame(404, $none->getStatus());

		$broken = $this->controller(statements: null)->evidence(format: 'json');
		self::assertInstanceOf(JSONResponse::class, $broken);
		self::assertSame(503, $broken->getStatus());
		self::assertSame(['error' => 'The evidence could not be read.'], $broken->getData());
	}//end testTheErrorAnswers()

	/**
	 * The public page renders the statement with both download links, or an empty state.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#scenario-a-procurement-officer-asks-for-evidence
	 */
	public function testThePublicPageLinksBothDownloads(): void {
		$page = $this->controller(statements: [self::published()])->page();

		self::assertInstanceOf(TemplateResponse::class, $page);
		self::assertSame('accessibility-statement', $page->getTemplateName());
		self::assertSame(TemplateResponse::RENDER_AS_GUEST, $page->getRenderAs());
		$params = $page->getParams();
		self::assertSame('statement-1', $params['evidence']['statement']['id']);
		self::assertStringContainsString('format=csv', $params['csvUrl']);
		self::assertStringContainsString('statement=statement-1', $params['jsonUrl']);

		self::assertNull($this->controller(statements: null)->page()->getParams()['evidence']);
		self::assertNull($this->controller(statements: [])->page()->getParams()['evidence']);
	}//end testThePublicPageLinksBothDownloads()

	/**
	 * Both routes are public and rate limited.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-evidence-on-request
	 */
	public function testBothRoutesArePublicAndRateLimited(): void {
		foreach (['evidence', 'page'] as $method) {
			$reflection = new ReflectionMethod(AccessibilityEvidenceController::class, $method);
			self::assertCount(1, $reflection->getAttributes(PublicPage::class), $method);
			self::assertCount(1, $reflection->getAttributes(AnonRateLimit::class), $method);
		}
	}//end testBothRoutesArePublicAndRateLimited()
}//end class
