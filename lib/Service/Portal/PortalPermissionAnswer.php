<?php

/**
 * Learniq PortalPermissionAnswer
 *
 * A guardian answers a school's permission request for one of her children
 * from the parent portal. This checks the answer and stores it as the one
 * PermissionResponse of that child for that request, with who answered,
 * when and at which assurance level her session was.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/permission-slips
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use DateTimeImmutable;
use OCA\Learniq\Kernel\Contracts\LearnerRefResolverInterface;
use OCA\Learniq\Kernel\Contracts\PortalPermissionAnswerInterface;
use OCA\Learniq\Support\SerialisedRow;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Checks and stores a guardian's answer to a permission request.
 *
 * In order: the session's assurance against the school's floor (403), a
 * complete answer (422), the request (404), an open request whose deadline
 * has not passed (409), a child the request covers and who lists this
 * guardian (403). One response per child per request: a second answer
 * replaces the first.
 *
 * @spec openspec/changes/permission-slips
 */
class PortalPermissionAnswer implements PortalPermissionAnswerInterface {

	private const REGISTER = 'learniq';

	private const REQUEST_SCHEMA = 'permission-request';

	private const RESPONSE_SCHEMA = 'permission-response';

	/**
	 * The school's floor for the assurance of a permission answer.
	 */
	public const MIN_ASSURANCE_KEY = 'permission_answer_min_assurance';

	/**
	 * The answers a guardian can give.
	 *
	 * @var array<int, string>
	 */
	public const ANSWERS = ['yes', 'no'];

	/**
	 * What a portal session's trust means on the eIDAS ladder.
	 *
	 * @var array<string, string>
	 */
	private const TRUST_TO_ASSURANCE = ['low' => 'basic', 'substantial' => 'substantial', 'high' => 'high'];

	/**
	 * The ladder, in order, so two levels can be compared.
	 *
	 * @var array<string, int>
	 */
	private const ASSURANCE_ORDER = ['none' => 0, 'basic' => 1, 'substantial' => 2, 'high' => 3];

	/**
	 * Constructor.
	 *
	 * @param ObjectService               $objectService Reads the request and earlier answers; writes the answer.
	 * @param LearnerRefResolverInterface $profiles      The child's profile, to check it lists the guardian.
	 * @param IAppConfig                  $appConfig     Holds the school's assurance floor.
	 * @param LoggerInterface             $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LearnerRefResolverInterface $profiles,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Store the guardian's answer for one child.
	 *
	 * @param string               $guardianRef The `guardianRef` claim.
	 * @param string               $trust       The session's trust (`low`, `substantial`, `high`).
	 * @param array<string, mixed> $body        What the form sent: `permissionRequestId`, `learnerRef`, `answer`.
	 * @param DateTimeImmutable|null $now       The moment of the answer; now when null.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/changes/permission-slips
	 */
	public function answer(string $guardianRef, string $trust, array $body, ?DateTimeImmutable $now=null): PortalOutcome {
		$assurance = (self::TRUST_TO_ASSURANCE[$trust] ?? 'basic');
		$floor     = $this->floor();
		if (self::ASSURANCE_ORDER[$assurance] < self::ASSURANCE_ORDER[$floor]) {
			return new PortalOutcome(status: 403, body: ['error' => 'assurance_too_low', 'required' => $floor], reason: 'assurance-too-low');
		}

		$requestId  = self::text(value: ($body['permissionRequestId'] ?? null));
		$learnerRef = self::text(value: ($body['learnerRef'] ?? null));
		$answer     = self::text(value: ($body['answer'] ?? null));
		if ($guardianRef === '' || $requestId === '' || $learnerRef === '' || in_array($answer, self::ANSWERS, true) === false) {
			return new PortalOutcome(status: 422, body: ['error' => 'incomplete'], reason: 'incomplete');
		}

		$now = ($now ?? new DateTimeImmutable());
		try {
			$checked = $this->refusal(requestId: $requestId, learnerRef: $learnerRef, guardianRef: $guardianRef, now: $now);
			if ($checked instanceof PortalOutcome) {
				return $checked;
			}

			$response = [
				'permissionRequestId' => $requestId,
				'learnerRef' => $learnerRef,
				'answer' => $answer,
				'answeredByRef' => $guardianRef,
				'answeredAt' => $now->format(DATE_ATOM),
				'assuranceLevel' => $assurance,
				'tenant_id' => self::text(value: ($checked['tenant_id'] ?? null)),
			];
			$earlier = $this->earlierResponseId(requestId: $requestId, learnerRef: $learnerRef);
			if ($earlier !== null) {
				$response['id'] = $earlier;
			}

			$this->objectService->saveObject(
				object: $response,
				register: self::REGISTER,
				schema: self::RESPONSE_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[PortalPermissionAnswer] A permission answer could not be stored: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return new PortalOutcome(status: 502, body: ['error' => 'downstream_error'], reason: 'downstream');
		}//end try

		return new PortalOutcome(
			status: 200,
			body: [
				'permissionRequestId' => $requestId,
				'learnerRef' => $learnerRef,
				'answer' => $answer,
				'assuranceLevel' => $assurance,
			]
		);
	}//end answer()

	/**
	 * Why the answer is refused, or the request when it may be given.
	 *
	 * @param string            $requestId   The request's uuid.
	 * @param string            $learnerRef  The child's uuid.
	 * @param string            $guardianRef The guardian's uuid.
	 * @param DateTimeImmutable $now         The moment of the answer.
	 *
	 * @return PortalOutcome|array<string, mixed> The refusal, or the request.
	 */
	private function refusal(string $requestId, string $learnerRef, string $guardianRef, DateTimeImmutable $now): PortalOutcome|array {
		$request = $this->row(schema: self::REQUEST_SCHEMA, id: $requestId);
		if ($request === null) {
			return new PortalOutcome(status: 404, body: ['error' => 'not_found'], reason: 'request-unknown');
		}

		if (self::isClosed(request: $request, now: $now) === true) {
			return new PortalOutcome(
				status: 409,
				body: ['error' => 'request_closed', 'message' => 'This request is closed. It no longer takes an answer.'],
				reason: 'request-closed'
			);
		}

		$child = $this->profiles->byRef(learnerRef: $learnerRef);
		$covers = in_array($learnerRef, array_map('strval', (array)($request['learnerRefs'] ?? [])), true);
		if ($covers === false || $child === null || in_array($guardianRef, (array)($child['guardianRefs'] ?? []), true) === false) {
			return new PortalOutcome(
				status: 403,
				body: ['error' => 'forbidden', 'message' => 'You can only answer for your own child.'],
				reason: 'not-your-child'
			);
		}

		return $request;
	}//end refusal()

	/**
	 * Whether a request no longer takes answers: closed, or past its deadline.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param DateTimeImmutable    $now     The moment of the answer.
	 *
	 * @return bool
	 */
	private static function isClosed(array $request, DateTimeImmutable $now): bool {
		if (($request['lifecycle'] ?? 'open') !== 'open') {
			return true;
		}

		$respondBy = self::text(value: ($request['respondBy'] ?? null));
		if ($respondBy === '') {
			return false;
		}

		try {
			return new DateTimeImmutable($respondBy) < $now;
		} catch (Throwable) {
			return false;
		}
	}//end isClosed()

	/**
	 * The id of the answer already given for this child, so it is replaced.
	 *
	 * @param string $requestId  The request's uuid.
	 * @param string $learnerRef The child's uuid.
	 *
	 * @return string|null
	 */
	private function earlierResponseId(string $requestId, string $learnerRef): ?string {
		$objects = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::RESPONSE_SCHEMA,
					'permissionRequestId' => $requestId,
					'learnerRef' => $learnerRef,
				],
				'limit' => 1,
			],
			_rbac: false,
			_multitenancy: false
		);

		foreach ($objects as $object) {
			$row = (new SerialisedRow())->asArray(object: $object);
			if (($row['permissionRequestId'] ?? null) === $requestId && ($row['learnerRef'] ?? null) === $learnerRef) {
				return (string)($row['id'] ?? ($row['uuid'] ?? ''));
			}
		}

		return null;
	}//end earlierResponseId()

	/**
	 * The school's floor for the assurance of a permission answer.
	 *
	 * @return string
	 */
	private function floor(): string {
		$floor = $this->appConfig->getValueString('learniq', self::MIN_ASSURANCE_KEY, 'basic');
		if (isset(self::ASSURANCE_ORDER[$floor]) === false) {
			return 'basic';
		}

		return $floor;
	}//end floor()

	/**
	 * One row by uuid, RBAC off (the receiver has no session), or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The uuid.
	 *
	 * @return array<string, mixed>|null
	 */
	private function row(string $schema, string $id): ?array {
		$objects = $this->objectService->findAll(
			config: [
				'filters' => ['register' => self::REGISTER, 'schema' => $schema],
				'ids' => [$id],
				'limit' => 1,
			],
			_rbac: false,
			_multitenancy: false
		);

		foreach ($objects as $object) {
			$row = (new SerialisedRow())->asArray(object: $object);
			if (($row['id'] ?? ($row['uuid'] ?? null)) === $id) {
				return $row;
			}
		}

		return null;
	}//end row()

	/**
	 * A trimmed string, or '' for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end text()
}//end class
