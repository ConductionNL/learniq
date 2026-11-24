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
 * @spec openspec/changes/archive/2026-11-24-permission-slips
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use DateTimeImmutable;
use OCA\Learniq\Kernel\Contracts\LearnerRefResolverInterface;
use OCA\Learniq\Kernel\Contracts\PortalPermissionAnswerInterface;
use OCA\Learniq\Support\SerialisedRow;
use OCA\Learniq\Support\TextValue;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
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
 * @spec openspec/changes/archive/2026-11-24-permission-slips
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
	 * @spec openspec/changes/archive/2026-11-24-permission-slips
	 */
	public function answer(string $guardianRef, string $trust, array $body, ?DateTimeImmutable $now=null): PortalOutcome {
		$assurance = (self::TRUST_TO_ASSURANCE[$trust] ?? 'basic');
		$floor     = $this->floor();
		if (self::ASSURANCE_ORDER[$assurance] < self::ASSURANCE_ORDER[$floor]) {
			return new PortalOutcome(status: 403, body: ['error' => 'assurance_too_low', 'required' => $floor], reason: 'assurance-too-low');
		}

		$now      = ($now ?? new DateTimeImmutable());
		$response = self::response(guardianRef: $guardianRef, body: $body, now: $now, assurance: $assurance);
		if ($response === null) {
			return new PortalOutcome(status: 422, body: ['error' => 'incomplete'], reason: 'incomplete');
		}

		$keys = ['permissionRequestId' => true, 'learnerRef' => true, 'answer' => true, 'assuranceLevel' => true];

		return $this->store(response: $response, now: $now) ?? new PortalOutcome(status: 200, body: array_intersect_key($response, $keys));
	}//end answer()

	/**
	 * The answer to store, from what the form sent, or null when the request,
	 * the child or the guardian is missing or the answer is unknown.
	 *
	 * @param string               $guardianRef The `guardianRef` claim.
	 * @param array<string, mixed> $body        What the form sent.
	 * @param DateTimeImmutable    $now         The moment of the answer.
	 * @param string               $assurance   The session's assurance level.
	 *
	 * @return array<string, string>|null
	 */
	private static function response(string $guardianRef, array $body, DateTimeImmutable $now, string $assurance): ?array {
		$response = [
			'permissionRequestId' => (new TextValue())->trimmedOrEmpty(value: ($body['permissionRequestId'] ?? null)),
			'learnerRef' => (new TextValue())->trimmedOrEmpty(value: ($body['learnerRef'] ?? null)),
			'answer' => (new TextValue())->trimmedOrEmpty(value: ($body['answer'] ?? null)),
			'answeredByRef' => $guardianRef,
			'answeredAt' => $now->format(DATE_ATOM),
			'assuranceLevel' => $assurance,
		];
		$named = $guardianRef !== '' && $response['permissionRequestId'] !== '' && $response['learnerRef'] !== '';
		if ($named === false || in_array($response['answer'], self::ANSWERS, true) === false) {
			return null;
		}

		return $response;
	}//end response()

	/**
	 * Check the request and the child, then store the answer, replacing an earlier one.
	 *
	 * @param array<string, string> $response The answer to store, naming the request, child and guardian.
	 * @param DateTimeImmutable     $now      The moment of the answer.
	 *
	 * @return PortalOutcome|null The refusal or the failure; null when stored.
	 */
	private function store(array $response, DateTimeImmutable $now): ?PortalOutcome {
		try {
			$checked = $this->refusal(response: $response, now: $now);
			if ($checked instanceof PortalOutcome) {
				return $checked;
			}

			$this->save(response: $response, request: $checked);
		} catch (Throwable $exception) {
			$context = ['msg' => $exception->getMessage(), 'exception' => $exception];
			$this->logger->error('[PortalPermissionAnswer] A permission answer could not be stored: {msg}', $context);
			return new PortalOutcome(status: 502, body: ['error' => 'downstream_error'], reason: 'downstream');
		}//end try

		return null;
	}//end store()

	/**
	 * Save the answer in the request's tenant, replacing the answer already given for this child.
	 *
	 * @param array<string, string> $response The answer.
	 * @param array<string, mixed>  $request  The request answered.
	 *
	 * @return void
	 */
	private function save(array $response, array $request): void {
		$response['tenant_id'] = (new TextValue())->trimmedOrEmpty(value: ($request['tenant_id'] ?? null));
		$earlier = $this->earlierResponseId(requestId: $response['permissionRequestId'], learnerRef: $response['learnerRef']);
		if ($earlier !== null) {
			$response['id'] = $earlier;
		}

		$this->objectService->saveObject(
			object: $response,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: self::REGISTER, schema: self::RESPONSE_SCHEMA)),
			_rbac: false,
			_multitenancy: false
		);
	}//end save()

	/**
	 * Why the answer is refused, or the request when it may be given.
	 *
	 * @param array<string, string> $response The answer, naming the request, the child and the guardian.
	 * @param DateTimeImmutable     $now      The moment of the answer.
	 *
	 * @return PortalOutcome|array<string, mixed> The refusal, or the request.
	 */
	private function refusal(array $response, DateTimeImmutable $now): PortalOutcome|array {
		$request = $this->row(schema: self::REQUEST_SCHEMA, id: $response['permissionRequestId']);
		if ($request === null) {
			return new PortalOutcome(status: 404, body: ['error' => 'not_found'], reason: 'request-unknown');
		}

		if (self::isClosed(request: $request, now: $now) === true) {
			$closed = ['error' => 'request_closed', 'message' => 'This request is closed. It no longer takes an answer.'];
			return new PortalOutcome(status: 409, body: $closed, reason: 'request-closed');
		}

		if ($this->isOwnChild(request: $request, learnerRef: $response['learnerRef'], guardianRef: $response['answeredByRef']) === false) {
			$notYours = ['error' => 'forbidden', 'message' => 'You can only answer for your own child.'];
			return new PortalOutcome(status: 403, body: $notYours, reason: 'not-your-child');
		}

		return $request;
	}//end refusal()

	/**
	 * Whether the request asked this child and the child's profile lists this guardian.
	 *
	 * @param array<string, mixed> $request     The request.
	 * @param string               $learnerRef  The child's uuid.
	 * @param string               $guardianRef The guardian's uuid.
	 *
	 * @return bool
	 */
	private function isOwnChild(array $request, string $learnerRef, string $guardianRef): bool {
		$child  = $this->profiles->byRef(learnerRef: $learnerRef);
		$covers = in_array($learnerRef, array_map('strval', (array)($request['learnerRefs'] ?? [])), true);

		return $covers === true && $child !== null && in_array($guardianRef, (array)($child['guardianRefs'] ?? []), true) === true;
	}//end isOwnChild()

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

		$respondBy = (new TextValue())->trimmedOrEmpty(value: ($request['respondBy'] ?? null));
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
		$earlier = $this->first(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::RESPONSE_SCHEMA,
					'permissionRequestId' => $requestId,
					'learnerRef' => $learnerRef,
				],
			],
			matches: static fn (array $row): bool => ($row['permissionRequestId'] ?? null) === $requestId && ($row['learnerRef'] ?? null) === $learnerRef
		);

		if ($earlier === null) {
			return null;
		}

		return (string)($earlier['id'] ?? ($earlier['uuid'] ?? ''));
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
		return $this->first(
			config: [
				'filters' => ['register' => self::REGISTER, 'schema' => $schema],
				'ids' => [$id],
			],
			matches: static fn (array $row): bool => ($row['id'] ?? ($row['uuid'] ?? null)) === $id
		);
	}//end row()

	/**
	 * The first row a search finds that matches, RBAC off (the receiver has no session), or null.
	 *
	 * @param array<string, mixed> $config  The search, without its limit.
	 * @param callable             $matches Whether a row is the one asked for.
	 *
	 * @return array<string, mixed>|null
	 */
	private function first(array $config, callable $matches): ?array {
		$objects = $this->objectService->findAll(config: $config + ['limit' => 1], _rbac: false, _multitenancy: false);
		foreach ($objects as $object) {
			$row = (new SerialisedRow())->asArray(object: $object);
			if ($matches($row) === true) {
				return $row;
			}
		}

		return null;
	}//end first()
}//end class
