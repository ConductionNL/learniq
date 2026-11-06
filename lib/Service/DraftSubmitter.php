<?php

/**
 * Learniq Draft Submitter
 *
 * Creates an object as the caller and fires its `submit` transition in one
 * server call. When the save or the transition is refused, the draft is
 * removed again, so a refused request leaves nothing behind.
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
 * @spec openspec/specs/parent-conferences/spec.md#requirement-a-guardian-or-self-signup-submission-is-gated-by-a-per-object-authorization-guard
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\Exception\DraftSubmitException;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Create, then submit, as one server step.
 *
 * @spec openspec/specs/parent-conferences/spec.md#requirement-a-guardian-or-self-signup-submission-is-gated-by-a-per-object-authorization-guard
 */
class DraftSubmitter {

	private const REGISTER = 'learniq';

	private const ACTION = 'submit';

	/**
	 * Constructor.
	 *
	 * @param ObjectService    $objectService    OpenRegister object access.
	 * @param TransitionEngine $transitionEngine OpenRegister lifecycle transitions.
	 * @param LoggerInterface  $logger           PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly TransitionEngine $transitionEngine,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create the object as the caller and submit it.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $data   The new object.
	 *
	 * @return array<string, mixed> The submitted object.
	 *
	 * @throws DraftSubmitException When the save (`invalid`) or the transition (`refused`) is refused.
	 *
	 * @spec openspec/specs/parent-conferences/spec.md#requirement-a-guardian-or-self-signup-submission-is-gated-by-a-per-object-authorization-guard
	 */
	public function createAndSubmit(string $schema, array $data): array {
		try {
			$draft = $this->objectService->saveObject(object: $data, register: self::REGISTER, schema: $schema);
		} catch (Throwable $exception) {
			$this->logger->info('[DraftSubmitter] A {schema} draft was refused: {msg}', ['schema' => $schema, 'msg' => $exception->getMessage()]);
			throw new DraftSubmitException(reason: DraftSubmitException::INVALID, previous: $exception);
		}

		$id = (string)$draft->getUuid();
		try {
			return $this->transitionEngine->transition(objectId: $id, action: self::ACTION)->jsonSerialize();
		} catch (Throwable $exception) {
			$this->logger->info(
				'[DraftSubmitter] Submitting {schema} {id} was refused: {msg}',
				['schema' => $schema, 'id' => $id, 'msg' => $exception->getMessage()]
			);
			$this->removeDraft(schema: $schema, id: $id);
			throw new DraftSubmitException(reason: DraftSubmitException::REFUSED, previous: $exception);
		}
	}//end createAndSubmit()

	/**
	 * Remove a draft whose submit was refused.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The draft's uuid.
	 *
	 * @return void
	 */
	private function removeDraft(string $schema, string $id): void {
		try {
			$this->objectService->deleteObject(uuid: $id, register: self::REGISTER, schema: $schema, _rbac: false);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[DraftSubmitter] Could not remove refused {schema} draft {id}: {msg}',
				['schema' => $schema, 'id' => $id, 'msg' => $exception->getMessage()]
			);
		}
	}//end removeDraft()
}//end class
