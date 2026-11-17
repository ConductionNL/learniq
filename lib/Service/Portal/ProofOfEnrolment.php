<?php

/**
 * Learniq Proof Of Enrolment
 *
 * A learner asks the portal for a proof of her enrolment, for a side job or a
 * travel card. Her active enrolment for the current school year is rendered as
 * a PDF through filinq's render endpoint, as the report card is, and the PDF
 * is kept on that enrolment, so her documents list it under "Choices and
 * statements" from then on. Without an active enrolment the request is refused
 * in words; a render that fails changes nothing.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-a-pupil-asks-for-a-proof-of-enrolment
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\ProofOfEnrolmentInterface;
use OCA\Learniq\Support\FleetAppId;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Renders a learner's proof of enrolment and keeps it on the enrolment.
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-a-pupil-asks-for-a-proof-of-enrolment
 */
class ProofOfEnrolment implements ProofOfEnrolmentInterface {

	private const REGISTER = 'learniq';

	private const ENROLMENT_SCHEMA = 'enrolment';

	/**
	 * filinq's render endpoint, after the app segment (the report card's).
	 */
	private const RENDER_PATH = 'api/v1/documents/render';

	/**
	 * The template filinq renders the proof with.
	 */
	public const TEMPLATE_SLUG = 'proof-of-enrolment';

	/**
	 * The app-config key of the render credential, shared with the report card.
	 */
	private const TOKEN_KEY = 'docudesk_api_token';

	/**
	 * The month a school year starts in: August.
	 */
	private const YEAR_START_MONTH = 8;

	/**
	 * The most enrolments one read holds.
	 */
	private const LIMIT = 200;

	/**
	 * The refusal reasons in words.
	 *
	 * @var array<string, string>
	 */
	private const TEXTS = [
		'no-active-enrolment' => 'You have no active enrolment this school year, so there is nothing to prove yet. Ask your school.',
		'render-failed' => 'The proof of enrolment could not be made just now. Try again later.',
		'no-account' => 'Your school account is not ready for this yet. Ask your school.',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OpenRegister reads (system context: the portal proved the learner).
	 * @param FileService     $fileService   Keeps the PDF on the enrolment.
	 * @param IClientService  $clientService The HTTP client for the render call.
	 * @param IURLGenerator   $urlGenerator  Builds the render endpoint's address.
	 * @param IAppManager     $appManager    Resolves filinq's app id.
	 * @param IAppConfig      $appConfig     Holds the render credential.
	 * @param IFactory        $l10nFactory   The learner's words.
	 * @param ITimeFactory    $time          Today, for the school year.
	 * @param LoggerInterface $logger        Logs a read or render that failed.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly FileService $fileService,
		private readonly IClientService $clientService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppManager $appManager,
		private readonly IAppConfig $appConfig,
		private readonly IFactory $l10nFactory,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Render a proof of the learner's active enrolment and keep it with her documents.
	 *
	 * @param PortalLearner $learner The learner, as the portal proved her.
	 *
	 * @return PortalOutcome 201 `{status, title}`, 409 without an active enrolment, 502 when the render failed.
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-a-pupil-asks-for-a-proof-of-enrolment
	 */
	public function request(PortalLearner $learner): PortalOutcome {
		$enrolment = $this->activeEnrolment(learnerRef: $learner->profileRef);
		if ($enrolment === null) {
			return new PortalOutcome(status: Http::STATUS_CONFLICT, body: ['error' => 'no_active_enrolment'], reason: 'no-active-enrolment');
		}

		$schoolYear = $this->schoolYear();
		$l10n       = $this->l10n(user: $learner->user);
		$title      = $l10n->t('Proof of enrolment, school year %s', [$schoolYear]);

		$pdf = $this->render(
			data: [
				'title' => $title,
				'learnerName' => $learner->user->getDisplayName(),
				'cohortName' => (string)($enrolment['cohortName'] ?? ''),
				'programmeId' => (string)($enrolment['programmeId'] ?? ''),
				'leerjaar' => ($enrolment['leerjaar'] ?? null),
				'enrolledOn' => (string)($enrolment['inschrijvingDate'] ?? ''),
				'schoolYear' => $schoolYear,
				'issuedOn' => date('Y-m-d', $this->time->getTime()),
			]
		);
		if ($pdf === null || $this->keep(enrolmentId: (string)$enrolment['id'], fileName: $title.'.pdf', pdf: $pdf) === false) {
			return new PortalOutcome(status: Http::STATUS_BAD_GATEWAY, body: ['error' => 'render_failed'], reason: 'render-failed');
		}

		return new PortalOutcome(status: Http::STATUS_CREATED, body: ['status' => 'created', 'title' => $title]);
	}//end request()

	/**
	 * A refusal reason in the learner's words.
	 *
	 * @param string     $reason The reason code.
	 * @param IUser|null $user   The learner, for her language; null for the default.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-a-pupil-asks-for-a-proof-of-enrolment
	 */
	public function message(string $reason, ?IUser $user): string {
		return $this->l10n(user: $user)->t(self::TEXTS[$reason] ?? self::TEXTS['render-failed']);
	}//end message()

	/**
	 * The current school year, "2026-2027": it starts on the first of August.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-a-pupil-asks-for-a-proof-of-enrolment
	 */
	public function schoolYear(): string {
		$now   = $this->time->getTime();
		$year  = (int)date('Y', $now);
		$first = $year;
		if ((int)date('n', $now) < self::YEAR_START_MONTH) {
			$first = ($year - 1);
		}

		return $first.'-'.($first + 1);
	}//end schoolYear()

	/**
	 * The learner's active enrolment, the most recent first; null when she has none.
	 *
	 * @param string $learnerRef The learner's ref.
	 *
	 * @return array<string, mixed>|null
	 */
	private function activeEnrolment(string $learnerRef): ?array {
		if (trim($learnerRef) === '') {
			return null;
		}

		try {
			$found = $this->objectService->findAll(
				config: [
					'filters' => ['register' => self::REGISTER, 'schema' => self::ENROLMENT_SCHEMA, 'learnerRef' => $learnerRef, 'lifecycle' => 'active'],
					'limit' => self::LIMIT,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('Learniq: the enrolments for a proof of enrolment could not be read', ['reason' => $e->getMessage()]);
			return null;
		}

		// Whatever the store answered: hers, active, with an id.
		$active = array_values(
			array_filter(
				array_map(static fn ($row): array => self::toArray(row: $row), (array)$found),
				static fn (array $row): bool => (string)($row['learnerRef'] ?? '') === $learnerRef
					&& (string)($row['lifecycle'] ?? '') === 'active'
					&& (string)($row['id'] ?? '') !== ''
			)
		);
		if ($active === []) {
			return null;
		}

		usort($active, static fn (array $a, array $b): int => strcmp((string)($b['inschrijvingDate'] ?? ''), (string)($a['inschrijvingDate'] ?? '')));

		return $active[0];
	}//end activeEnrolment()

	/**
	 * The rendered PDF, or null when the render failed or answered no PDF.
	 *
	 * The render endpoint answers with the PDF itself, or with JSON carrying it base64 encoded under `content`.
	 *
	 * @param array<string, mixed> $data What the template shows.
	 *
	 * @return string|null
	 */
	private function render(array $data): ?string {
		$token = $this->appConfig->getValueString(app: AppIdentity::APP_ID, key: self::TOKEN_KEY, default: '');
		if ($token === '') {
			$this->logger->warning('Learniq: no render credential configured (learniq.docudesk_api_token); no proof of enrolment can be made.');
			return null;
		}

		$url = $this->urlGenerator->getAbsoluteURL('/index.php'.FleetAppId::path($this->appManager, 'filinq', self::RENDER_PATH));
		try {
			$response = $this->clientService->newClient()->post(
				$url,
				[
					'json' => ['templateSlug' => self::TEMPLATE_SLUG, 'output' => 'pdf', 'data' => $data],
					'timeout' => 60,
					'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/pdf, application/json'],
				]
			);
			$body = (string)$response->getBody();
		} catch (Throwable $e) {
			$this->logger->warning('Learniq: a proof of enrolment could not be rendered', ['reason' => $e->getMessage()]);
			return null;
		}

		return self::pdfFrom(body: $body);
	}//end render()

	/**
	 * The PDF in a render answer: the body itself, or JSON's base64 `content`.
	 *
	 * @param string $body The answer's body.
	 *
	 * @return string|null The PDF bytes, or null when the answer holds none.
	 *
	 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-a-pupil-asks-for-a-proof-of-enrolment
	 */
	public static function pdfFrom(string $body): ?string {
		if (str_starts_with($body, '%PDF-') === true) {
			return $body;
		}

		$json = json_decode($body, true);
		if (is_array($json) === false || is_string($json['content'] ?? null) === false) {
			return null;
		}

		$content = $json['content'];
		if ($content === '') {
			return null;
		}

		$pdf = base64_decode($content, true);
		if ($pdf === false || str_starts_with($pdf, '%PDF-') === false) {
			return null;
		}

		return $pdf;
	}//end pdfFrom()

	/**
	 * Keep the PDF on the enrolment, replacing an earlier one of the same school year.
	 *
	 * @param string $enrolmentId The enrolment's id.
	 * @param string $fileName    The file's name: the proof's title.
	 * @param string $pdf         The PDF bytes.
	 *
	 * @return bool Whether it was kept.
	 */
	private function keep(string $enrolmentId, string $fileName, string $pdf): bool {
		try {
			$enrolment = $this->objectService->find(
				id: $enrolmentId,
				options: new ObjectFindOptions(register: self::REGISTER, schema: self::ENROLMENT_SCHEMA),
				_rbac: false,
				_multitenancy: false
			);
			if ($enrolment === null) {
				return false;
			}

			$this->fileService->saveFile(
				objectEntity: $enrolment,
				upload: new FileUpload(fileName: $fileName, content: $pdf, share: false, tags: [self::TEMPLATE_SLUG])
			);
		} catch (Throwable $e) {
			$this->logger->warning('Learniq: a proof of enrolment could not be kept', ['reason' => $e->getMessage()]);
			return false;
		}

		return true;
	}//end keep()

	/**
	 * The learner's words.
	 *
	 * @param IUser|null $user The learner; null for the default language.
	 *
	 * @return IL10N
	 */
	private function l10n(?IUser $user): IL10N {
		return $this->l10nFactory->get(AppIdentity::APP_ID, $this->l10nFactory->getUserLanguage($user));
	}//end l10n()

	/**
	 * A stored row as an array.
	 *
	 * @param mixed $row The row as the store answered it.
	 *
	 * @return array<string, mixed>
	 */
	private static function toArray(mixed $row): array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return (array)$row;
	}//end toArray()
}//end class
