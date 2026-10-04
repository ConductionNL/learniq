<?php

/**
 * Learniq Report Card PDF Delegation Service
 *
 * Lifecycle guard for the ReportCard schema's `renderToPdf`
 * (finalised -> finalised) and `rerenderToPdf` (published-to-parents ->
 * published-to-parents) self-loop transitions. POSTs a **proposed, not-yet-
 * verified** docudesk REST contract
 * (`POST /apps/docudesk/api/v1/documents/render`) — no docudesk endpoint,
 * controller, or PHP call exists anywhere in this repo to reference
 * (verified: `grep -rni docudesk` across every non-vendor file returns only
 * prose mentions). The docudesk-side endpoint implementation is an explicit,
 * tracked follow-up leaf, mirroring `bpv-praktijkovereenkomst`'s POK-PDF
 * precedent: the `ReportCard` OpenRegister object, its `subjectGrades[]`,
 * and its `mentorComment` are the legally complete record regardless of
 * whether a rendered PDF exists.
 *
 * FAIL-SOFT BY DESIGN (per spec): a PDF-render failure is logged and
 * recorded in `docudeskRenderError`, and MUST NOT block any ReportCard
 * lifecycle transition — this guard therefore always allows, and render()
 * catches every `Throwable`, mirroring
 * {@see \OCA\Learniq\Service\WalletRevocationPropagationService}'s
 * fail-soft shape (a render failure is a convenience-feature-degraded
 * state, not a compliance blocker).
 *
 * Reuses the `IClientService` + `IURLGenerator` + `IAppConfig`
 * bearer-token seam `DataExchangeRunHandler::callOpenConnector()` /
 * `WalletOfferDelegationService` already establish
 * (`learniq.docudesk_api_token`, mirroring `learniq.openconnector_api_token`).
 *
 * Legitimate PHP per ADR-031: "external-system bridge — a genuine
 * cross-app delegation that cannot be expressed as a schema declaration."
 * Referenced from the ReportCard schema's
 * x-openregister-lifecycle.transitions.renderToPdf/rerenderToPdf.requires
 * in learniq_register.json. Both are self-loops, so render() runs after the
 * save in ReportCardPdfTransitionListener (learniq#983).
 *
 * @category Service
 * @package  OCA\Learniq\Service
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
 * @spec openspec/specs/report-card/spec.md#scenario-a-pdf-render-failure-does-not-block-publication
 * @spec openspec/specs/report-card/spec.md#scenario-a-successful-render-records-the-docudesk-document-reference
 * @spec openspec/specs/report-card/spec.md#scenario-a-report-card-with-an-assigned-template-sends-that-templates-slug-to-docudesk
 * @spec openspec/specs/report-card/spec.md#scenario-a-report-card-with-no-assigned-template-keeps-sending-the-default-slug
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\Support\FleetAppId;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Guards the ReportCard `renderToPdf`/`rerenderToPdf` self-loop transitions
 * (as an additional `requires` hook, fail-soft).
 *
 * POSTs the ReportCard's rendering payload to the proposed docudesk
 * endpoint. On a confirmed 2xx with a document reference, sets
 * `docudeskRenderStatus=rendered`, stamps `docudeskDocumentRef`, and clears
 * `docudeskRenderError`. On any failure (missing config, HTTP error, thrown
 * exception, non-2xx, malformed body) sets `docudeskRenderStatus=failed` +
 * `docudeskRenderError`, logs, and never throws. The guard always allows.
 *
 * @spec openspec/specs/report-card/spec.md#requirement-docudesk-pdf-rendering-is-fail-soft-non-blocking-and-its-contract-is-explicitly-proposed
 */
class ReportCardPdfDelegationService implements LifecycleGuardInterface {

	/**
	 * Proposed, not-yet-verified docudesk REST contract for rendering a
	 * report card to PDF. No docudesk endpoint exists in this repo to
	 * confirm the path against — filed as an explicit follow-up leaf.
	 *
	 * @var string
	 */
	/**
	 * Path AFTER the app segment; the segment is resolved at call time.
	 *
	 * @var string
	 */
	private const DOCUDESK_RENDER_PATH = 'api/v1/documents/render';

	/**
	 * App-config key for the docudesk bearer credential, mirroring
	 * `learniq.openconnector_api_token`.
	 *
	 * @var string
	 */
	private const DOCUDESK_TOKEN_KEY = 'docudesk_api_token';

	/**
	 * Default template slug requested for a report-card render, sent
	 * whenever the ReportCard has no ReportCardTemplate assigned
	 * (report-card-templates change). Unchanged fallback behaviour.
	 *
	 * @var string
	 */
	private const TEMPLATE_SLUG = 'report-card';

	/**
	 * Register/schema for resolving a ReportCard's assigned ReportCardTemplate.
	 *
	 * @var string
	 */
	private const LEARNIQ_REGISTER = 'learniq';

	/**
	 * @var string
	 */
	private const REPORT_CARD_TEMPLATE_SCHEMA = 'report-card-template';

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService NC HTTP client factory.
	 * @param IURLGenerator $urlGenerator NC URL generator for internal requests.
	 * @param IAppConfig $appConfig NC app config for token lookup.
	 * @param IAppManager $appManager NC app manager. Resolving the fleet app id
	 *                                needs it, and it arrives as a dependency now
	 *                                rather than out of the global server.
	 * @param ObjectService $objectService OR object access service — resolves a ReportCard's
	 *                                     assigned ReportCardTemplate by `templateId`
	 *                                     (report-card-templates change).
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppConfig $appConfig,
		private readonly IAppManager $appManager,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * OpenRegister lifecycle guard entry-point for `renderToPdf`/`rerenderToPdf`.
	 *
	 * Always allows: a render failure never blocks a ReportCard transition
	 * (fail-soft by design). Both transitions are self-loops, on which
	 * OpenRegister runs neither guards nor actions, so render() runs after the
	 * save in {@see \OCA\Learniq\Listener\ReportCardPdfTransitionListener}
	 * (learniq#983).
	 *
	 * @param array<string,mixed> $object The ReportCard as it would be saved.
	 * @param string $action The transition, `renderToPdf` or `rerenderToPdf`.
	 * @param string $userId The caller, or '' without a session (unused: this guard does not depend on who asks).
	 *
	 * @return GuardResult Always allow (fail-soft by design).
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface fixes the signature.
	 *
	 * @spec openspec/specs/report-card/spec.md#scenario-a-pdf-render-failure-does-not-block-publication
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		return GuardResult::allow();
	}//end check()

	/**
	 * Render the ReportCard to PDF through docudesk and record the outcome.
	 *
	 * Never throws: on success sets `docudeskRenderStatus=rendered` and
	 * `docudeskDocumentRef`, on any failure `docudeskRenderStatus=failed` and
	 * `docudeskRenderError`; always stamps `docudeskRequestedAt`.
	 *
	 * @param array<string,mixed> $reportCard The ReportCard data array.
	 *
	 * @return array<string,mixed> The ReportCard with the render outcome applied.
	 *
	 * @spec openspec/specs/report-card/spec.md#scenario-a-pdf-render-failure-does-not-block-publication
	 * @spec openspec/specs/report-card/spec.md#scenario-a-successful-render-records-the-docudesk-document-reference
	 */
	public function render(array $reportCard): array {
		$reportId = (string)($reportCard['id'] ?? ($reportCard['uuid'] ?? ''));

		$reportCard['docudeskRequestedAt'] = date('c');

		try {
			$result = $this->callDocudeskRender(reportCard: $reportCard);
			$rendered = ($result !== null && ($result['documentRef'] ?? null) !== null);

			if ($rendered === true) {
				$reportCard['docudeskRenderStatus'] = 'rendered';
				$reportCard['docudeskDocumentRef'] = (string)$result['documentRef'];
				$reportCard['docudeskRenderError'] = null;
				$this->logger->info(
					'[ReportCardPdfDelegationService] ReportCard {id} rendered — docudeskDocumentRef {ref}.',
					['id' => $reportId, 'ref' => $result['documentRef']]
				);
			}

			if ($rendered === false) {
				$reportCard['docudeskRenderStatus'] = 'failed';
				$reportCard['docudeskRenderError'] = 'docudesk render failed or returned no document reference '
					. '(the docudesk-side endpoint is a proposed, not-yet-verified contract — see design.md).';
				$this->logger->warning(
					'[ReportCardPdfDelegationService] ReportCard {id} render did not succeed — recording failure, not blocking the transition.',
					['id' => $reportId]
				);
			}
		} catch (Throwable $exception) {
			// Fail-soft by design: never block renderToPdf/rerenderToPdf on the docudesk rail.
			$reportCard['docudeskRenderStatus'] = 'failed';
			$reportCard['docudeskRenderError'] = 'docudesk render error: ' . $exception->getMessage();
			$this->logger->warning(
				'[ReportCardPdfDelegationService] Render for ReportCard {id} threw: {msg}',
				['id' => $reportId, 'msg' => $exception->getMessage()]
			);
		}//end try

		return $reportCard;
	}//end render()

	/**
	 * Call docudesk's proposed render endpoint.
	 *
	 * @param array<string,mixed> $reportCard The ReportCard data array.
	 *
	 * @return array<string,mixed>|null Response data (expects `documentRef` on success), or null on failure/absence.
	 */
	private function callDocudeskRender(array $reportCard): ?array {
		$url = $this->urlGenerator->getAbsoluteURL(
			'/index.php' . FleetAppId::path($this->appManager, 'filinq', self::DOCUDESK_RENDER_PATH)
		);

		$apiToken = $this->appConfig->getValueString(
			app: 'learniq',
			key: self::DOCUDESK_TOKEN_KEY,
			default: ''
		);

		if ($apiToken === '') {
			$this->logger->warning(
				'[ReportCardPdfDelegationService] No docudesk API token configured (learniq.docudesk_api_token); '
				. 'the render call will fail with 401/403.'
			);
			return null;
		}

		$payload = [
			'reportCardId' => $reportCard['id'] ?? ($reportCard['uuid'] ?? null),
			'subjectGrades' => $reportCard['subjectGrades'] ?? [],
			'mentorComment' => $reportCard['mentorComment'] ?? null,
			'attendanceSummary' => $reportCard['attendanceSummary'] ?? null,
			'templateSlug' => $this->resolveTemplateSlug(reportCard: $reportCard),
		];

		$requestOptions = [
			'json' => $payload,
			'timeout' => 60,
			'headers' => [
				'Authorization' => 'Bearer ' . $apiToken,
			],
		];

		$client = $this->clientService->newClient();
		$response = $client->post($url, $requestOptions);

		$body = json_decode($response->getBody(), true);
		if (is_array($body) === false) {
			return null;
		}

		return $body;
	}//end callDocudeskRender()

	/**
	 * Resolve the `templateSlug` to send docudesk: the assigned
	 * `ReportCardTemplate.slug` when `ReportCard.templateId` is set and
	 * resolvable, otherwise the pre-existing default `'report-card'`
	 * literal (report-card-templates change).
	 *
	 * @param array<string,mixed> $reportCard The ReportCard data array.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/report-card/spec.md#scenario-a-report-card-with-an-assigned-template-sends-that-templates-slug-to-docudesk
	 * @spec openspec/specs/report-card/spec.md#scenario-a-report-card-with-no-assigned-template-keeps-sending-the-default-slug
	 */
	private function resolveTemplateSlug(array $reportCard): string {
		$templateId = $reportCard['templateId'] ?? null;
		if ($templateId === null || $templateId === '') {
			return self::TEMPLATE_SLUG;
		}

		try {
			$template = $this->objectService->find(
				id: (string)$templateId,
				register: self::LEARNIQ_REGISTER,
				schema: self::REPORT_CARD_TEMPLATE_SCHEMA
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ReportCardPdfDelegationService] Could not resolve ReportCardTemplate {template}: {msg} — falling back to the default templateSlug.',
				['template' => $templateId, 'msg' => $exception->getMessage()]
			);
			return self::TEMPLATE_SLUG;
		}

		if ($template === null) {
			return self::TEMPLATE_SLUG;
		}

		$templateData = $template->jsonSerialize();
		$slug = (string)($templateData['slug'] ?? '');

		if ($slug === '') {
			return self::TEMPLATE_SLUG;
		}

		return $slug;
	}//end resolveTemplateSlug()
}//end class
