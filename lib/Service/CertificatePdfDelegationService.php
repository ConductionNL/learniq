<?php

/**
 * Learniq Certificate PDF Delegation Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Asks filinq to print a certificate on its course's template
 * (`Course.certificateTemplateSlug`) and records the document reference on the
 * Credential. filinq owns templates, house style and PDF output; learniq sends
 * the data and keeps the reference (design D1).
 *
 * Modelled on ReportCardPdfDelegationService: the same render path and the
 * same bearer-token key (`learniq.docudesk_api_token`). Fail soft (design D3):
 * the signed record and its verification page are the certificate, the PDF is
 * a copy of it, so a missing filinq, a missing token or a failed call is
 * recorded in `pdfRenderStatus` / `pdfRenderError` and never thrown.
 *
 * @category  Service
 * @package   OCA\Learniq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec openspec/changes/credentials-certificate-pdf/specs/certification/spec.md#requirement-issuing-a-certificate-renders-its-pdf-fail-soft
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\Kernel\Contracts\CertificatePdfDelegationServiceInterface;
use OCA\Learniq\Kernel\Contracts\ReadableCopiesInterface;
use OCA\Learniq\Support\FleetAppId;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Renders a certificate's PDF through filinq, fail soft.
 *
 * @spec openspec/changes/credentials-certificate-pdf/specs/certification/spec.md#requirement-issuing-a-certificate-renders-its-pdf-fail-soft
 */
class CertificatePdfDelegationService implements CertificatePdfDelegationServiceInterface {

	/**
	 * filinq's render endpoint, relative to its app root.
	 *
	 * @var string
	 */
	public const RENDER_PATH = 'api/v1/documents/render';

	/**
	 * The app config key holding the bearer token for filinq.
	 *
	 * @var string
	 */
	public const TOKEN_KEY = 'docudesk_api_token';

	/**
	 * Constructor.
	 *
	 * @param IClientService  $clientService The HTTP client factory.
	 * @param IURLGenerator   $urlGenerator  Builds filinq's absolute URL.
	 * @param IAppConfig      $appConfig     Holds the bearer token.
	 * @param IAppManager     $appManager    Says whether filinq is installed.
	 * @param LoggerInterface $logger        The logger.
	 * @param ReadableCopiesInterface $copies The certificate's readable copies (holder and course names, validity),
	 *                                        which OpenRegister writes only on the save that follows the render.
	 *
	 * @spec openspec/changes/credentials-certificate-pdf/specs/certification/spec.md#requirement-issuing-a-certificate-renders-its-pdf-fail-soft
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppConfig $appConfig,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
		private readonly ReadableCopiesInterface $copies,
	) {
	}//end __construct()

	/**
	 * Render a certificate's PDF on its course's template, and record the outcome.
	 *
	 * @param array<string,mixed> $credential The Credential data array.
	 * @param array<string,mixed> $course     The Course it certifies.
	 *
	 * @return array<string,mixed> The Credential with the render outcome applied.
	 *
	 * @spec openspec/changes/credentials-certificate-pdf/specs/certification/spec.md#requirement-issuing-a-certificate-renders-its-pdf-fail-soft
	 */
	public function render(array $credential, array $course): array {
		$slug = trim((string)($course['certificateTemplateSlug'] ?? ''));
		if ($slug === '') {
			return $credential;
		}

		$unavailable = $this->unavailableReason();
		if ($unavailable !== null) {
			return $this->outcome(credential: $credential, status: 'unavailable', ref: null, error: $unavailable);
		}

		try {
			$withCopies = $this->withCopies(credential: $credential);
			$ref        = $this->callRender(payload: self::payload(credential: $withCopies, course: $course, slug: $slug));
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[CertificatePdfDelegationService] Rendering the PDF of a certificate failed: {msg}',
				['msg' => $exception->getMessage()]
			);
			return $this->outcome(
				credential: $credential,
				status: 'failed',
				ref: null,
				error: 'filinq render error: ' . $exception->getMessage()
			);
		}

		if ($ref === null) {
			return $this->outcome(credential: $credential, status: 'failed', ref: null, error: 'filinq answered without a document reference');
		}

		return $this->outcome(credential: $credential, status: 'rendered', ref: $ref, error: null);
	}//end render()

	/**
	 * What filinq is sent to print (design D4).
	 *
	 * The verification URL is printed and is the QR target, so paper can be
	 * checked against the signed record.
	 *
	 * @param array<string,mixed> $credential The Credential.
	 * @param array<string,mixed> $course     The Course.
	 * @param string              $slug       The template slug.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/credentials-certificate-pdf/specs/certification/spec.md#scenario-every-pdf-shows-how-to-check-it
	 */
	public static function payload(array $credential, array $course, string $slug): array {
		$verificationUrl = self::stringOrNull(value: ($credential['verificationUrl'] ?? null));
		$courseName      = (self::stringOrNull(value: ($credential['courseName'] ?? null))
			?? self::stringOrNull(value: ($course['title'] ?? ($course['name'] ?? null))));

		return [
			'templateSlug' => $slug,
			'outputQuality' => 'print',
			'data' => [
				'learnerName' => self::stringOrNull(value: ($credential['learnerName'] ?? null)),
				'courseName' => $courseName,
				'issuedAt' => self::stringOrNull(value: ($credential['issuedAt'] ?? null)),
				'expiresAt' => self::stringOrNull(value: ($credential['expiresAt'] ?? null)),
				'validUntilLabel' => self::stringOrNull(value: ($credential['validUntilLabel'] ?? null)),
				'verificationUrl' => $verificationUrl,
				'qrTarget' => $verificationUrl,
				'organisationRef' => self::stringOrNull(value: ($credential['organisationRef'] ?? null)),
			],
		];
	}//end payload()

	/**
	 * The credential with its readable copies filled in where it carries none yet.
	 *
	 * Issuance renders before the save that writes the copies, so they are
	 * derived here; a value the credential already holds wins.
	 *
	 * @param array<string,mixed> $credential The Credential.
	 *
	 * @return array<string,mixed>
	 */
	private function withCopies(array $credential): array {
		try {
			$derived = $this->copies->derive(slug: 'credential', row: $credential);
		} catch (Throwable $exception) {
			$this->logger->info(
				'[CertificatePdfDelegationService] The certificate\'s readable copies could not be derived: {msg}',
				['msg' => $exception->getMessage()]
			);
			return $credential;
		}

		foreach ($derived as $key => $value) {
			if (self::stringOrNull(value: ($credential[$key] ?? null)) === null) {
				$credential[$key] = $value;
			}
		}

		return $credential;
	}//end withCopies()

	/**
	 * Why no render can be asked for, or null when it can.
	 *
	 * @return string|null
	 */
	private function unavailableReason(): ?string {
		if (FleetAppId::isInstalled(appManager: $this->appManager, canonical: 'filinq') === false) {
			return 'filinq is not installed on this instance';
		}

		if ($this->token() === '') {
			return 'No filinq API token is configured (learniq.' . self::TOKEN_KEY . ')';
		}

		return null;
	}//end unavailableReason()

	/**
	 * The bearer token for filinq, or ''.
	 *
	 * @return string
	 */
	private function token(): string {
		return $this->appConfig->getValueString(app: 'learniq', key: self::TOKEN_KEY, default: '');
	}//end token()

	/**
	 * Ask filinq to render, and return the document reference it answers with.
	 *
	 * @param array<string,mixed> $payload The render request.
	 *
	 * @return string|null The document reference, or null when the answer carries none.
	 */
	private function callRender(array $payload): ?string {
		$url = $this->urlGenerator->getAbsoluteURL(
			'/index.php' . FleetAppId::path($this->appManager, 'filinq', self::RENDER_PATH)
		);

		$response = $this->clientService->newClient()->post(
			$url,
			[
				'json' => $payload,
				'timeout' => 60,
				'headers' => ['Authorization' => 'Bearer ' . $this->token()],
			]
		);

		$body = json_decode((string)$response->getBody(), true);
		if (is_array($body) === false) {
			return null;
		}

		return self::stringOrNull(value: ($body['documentRef'] ?? null));
	}//end callRender()

	/**
	 * The credential with a render outcome written onto it.
	 *
	 * @param array<string,mixed> $credential The Credential.
	 * @param string              $status     The render status.
	 * @param string|null         $ref        The document reference, when rendered.
	 * @param string|null         $error      The reason, when not.
	 *
	 * @return array<string,mixed>
	 */
	private function outcome(array $credential, string $status, ?string $ref, ?string $error): array {
		$credential['pdfRenderStatus'] = $status;
		$credential['pdfRenderError']  = $error;
		if ($status === 'rendered') {
			$credential['pdfDocumentRef'] = $ref;
		}

		return $credential;
	}//end outcome()

	/**
	 * A non-blank string, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private static function stringOrNull(mixed $value): ?string {
		if (is_scalar($value) === false) {
			return null;
		}

		$value = trim((string)$value);
		if ($value === '') {
			return null;
		}

		return $value;
	}//end stringOrNull()
}//end class
