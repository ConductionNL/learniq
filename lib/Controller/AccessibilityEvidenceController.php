<?php

/**
 * Learniq Accessibility Evidence Controller
 *
 * The public side of the accessibility statement: a page anyone can open
 * without signing in, and the evidence export behind its download links
 * (JSON and CSV). Both serve only a PUBLISHED AccessibilityStatement and only
 * the fields ConformanceEvidence lets out: no tester names, no approver
 * (governance-wcag-evidence-report design D2). Read-only; rate limited
 * because it answers anonymous callers.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
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

namespace OCA\Learniq\Controller;

use OCA\Learniq\AppInfo\Application;
use OCA\Learniq\Service\Accessibility\ConformanceEvidence;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Public accessibility statement page and evidence export.
 *
 * @psalm-api
 *
 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-evidence-on-request
 */
class AccessibilityEvidenceController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The HTTP request.
	 * @param ConformanceEvidence $evidence Builds the public conformance table.
	 * @param IURLGenerator $urlGenerator Builds the download links.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ConformanceEvidence $evidence,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Download the conformance evidence of the published statement.
	 *
	 * @param string $format `json` (default) or `csv`.
	 * @param string|null $statement An AccessibilityStatement uuid; the current published one when absent.
	 *
	 * @return DataDownloadResponse|JSONResponse The file, or a JSON error.
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-evidence-on-request
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function evidence(string $format = 'json', ?string $statement = null): DataDownloadResponse|JSONResponse {
		if (in_array($format, ['json', 'csv'], true) === false) {
			return new JSONResponse(data: ['error' => 'The format must be json or csv.'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		try {
			$published = $this->evidence->publishedStatement(statementId: $statement);
			if ($published === null) {
				return new JSONResponse(data: ['error' => 'No accessibility statement is published.'], statusCode: Http::STATUS_NOT_FOUND);
			}

			$table = $this->evidence->forStatement(statement: $published);
		} catch (Throwable $failure) {
			$this->logger->error(
				'AccessibilityEvidenceController: the evidence could not be read: ' . $failure->getMessage(),
				['exception' => $failure]
			);
			return new JSONResponse(data: ['error' => 'The evidence could not be read.'], statusCode: Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$date = (string)($table['statement']['evaluationDate'] ?? '');
		if ($date === '') {
			$date = 'current';
		}

		$name = 'accessibility-evidence-' . $date;
		if ($format === 'csv') {
			return new DataDownloadResponse($this->evidence->toCsv(evidence: $table), $name . '.csv', 'text/csv');
		}

		return new DataDownloadResponse(
			(string)json_encode($table, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
			$name . '.json',
			'application/json'
		);
	}//end evidence()

	/**
	 * The public accessibility statement page, with the evidence download links.
	 *
	 * @param string|null $statement An AccessibilityStatement uuid; the current published one when absent.
	 *
	 * @return TemplateResponse The page (an empty state when nothing is published).
	 *
	 * @spec openspec/changes/governance-wcag-evidence-report/specs/accessibility-evidence/spec.md#requirement-evidence-on-request
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function page(?string $statement = null): TemplateResponse {
		$table = null;
		try {
			$published = $this->evidence->publishedStatement(statementId: $statement);
			if ($published !== null) {
				$table = $this->evidence->forStatement(statement: $published);
			}
		} catch (Throwable $failure) {
			$this->logger->error(
				'AccessibilityEvidenceController: the public statement could not be read: ' . $failure->getMessage(),
				['exception' => $failure]
			);
		}

		$query = [];
		if ($table !== null) {
			$query['statement'] = $table['statement']['id'];
		}

		return new TemplateResponse(
			Application::APP_ID,
			'accessibility-statement',
			[
				'evidence' => $table,
				'jsonUrl' => $this->urlGenerator->linkToRoute('learniq.accessibilityEvidence.evidence', array_merge($query, ['format' => 'json'])),
				'csvUrl' => $this->urlGenerator->linkToRoute('learniq.accessibilityEvidence.evidence', array_merge($query, ['format' => 'csv'])),
			],
			TemplateResponse::RENDER_AS_GUEST
		);
	}//end page()
}//end class
