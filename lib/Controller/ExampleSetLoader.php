<?php

/**
 * Learniq example-set import and removal for the setup wizard.
 *
 * @category Controller
 * @package  OCA\Learniq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
 * @spec openspec/specs/example-sets/spec.md#requirement-the-wizard-removes-a-loaded-example-set-through-openregisters-import-jobs
 */

declare(strict_types=1);

namespace OCA\Learniq\Controller;

use OCA\Learniq\Kernel\AppIdentity;
use OCA\Learniq\Kernel\Contracts\SeedProfileServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;

/**
 * Imports or removes one example set for the setup wizard and words every
 * outcome as the answer the wizard shows, naming the decision the wizard
 * records. Built by SetupController, which records it.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-the-wizard-removes-a-loaded-example-set-through-openregisters-import-jobs
 */
final class ExampleSetLoader {
	/**
	 * What app config `demo_data_decided` holds after a removal: not empty, so
	 * the load step stays answered and the wizard does not reopen over every
	 * page (an outstanding optional step opens it).
	 *
	 * @var string
	 */
	private const REMOVED = 'removed';

	/**
	 * What app config `demo_data_decided` holds after an import.
	 *
	 * @var string
	 */
	private const INSTALLED = 'installed';

	/**
	 * Constructor.
	 *
	 * @param SeedProfileServiceInterface $seedProfiles Imports and removes the example sets.
	 * @param LoggerInterface    $logger       Records a failed import or removal.
	 */
	public function __construct(
		private readonly SeedProfileServiceInterface $seedProfiles,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Import one set; a finished import is the wizard's answer to record.
	 *
	 * @param string $picked The set to import.
	 *
	 * @return ExampleSetAnswer `{ success, message, objects, skipped }` and the decision to record.
	 *
	 * @spec openspec/changes/wizard-dataset-card-load/specs/first-time-setup/spec.md
	 */
	public function install(string $picked): ExampleSetAnswer {
		try {
			$imported = $this->seedProfiles->install(profileId: $picked);
		} catch (\Throwable $e) {
			return new ExampleSetAnswer(response: $this->importFailed(picked: $picked, error: $e), decided: null);
		}

		return new ExampleSetAnswer(response: $this->importedResponse(imported: $imported), decided: self::INSTALLED);
	}//end install()

	/**
	 * Log a failed import and answer it with a 500 that names the reason.
	 *
	 * @param string     $picked The set that failed.
	 * @param \Throwable $error  Why it failed.
	 *
	 * @return JSONResponse `{ success, message }`.
	 */
	private function importFailed(string $picked, \Throwable $error): JSONResponse {
		$this->logger->error(
			'Setup load-example-set failed for "' . $picked . '": ' . $error->getMessage(),
			['app' => AppIdentity::APP_ID, 'exception' => $error]
		);

		return new JSONResponse(
			data: ['success' => false, 'message' => 'Could not import the example data: ' . $error->getMessage()],
			statusCode: Http::STATUS_INTERNAL_SERVER_ERROR,
		);
	}//end importFailed()

	/**
	 * The answer to a finished import, naming what did not arrive.
	 *
	 * @param array<string, mixed> $imported The importer's reply.
	 *
	 * @return JSONResponse `{ success, message, objects, skipped }`.
	 */
	private function importedResponse(array $imported): JSONResponse {
		$skipped = (int)($imported['skipped'] ?? 0);

		return new JSONResponse(
			data: [
				'success' => true,
				'message' => $this->importedMessage(imported: $imported, skipped: $skipped),
				'objects' => $imported['objects'],
				'skipped' => $skipped,
			]
		);
	}//end importedResponse()

	/**
	 * What an import reports, including the objects that did not land.
	 *
	 * 🔴 SAY WHAT DID NOT ARRIVE. OpenRegister skips an object it cannot place
	 * and keeps going, so "Imported 490" was reported while 302 of them never
	 * landed. The skips come from the importer's own reply.
	 *
	 * @param array<string, mixed> $imported The importer's reply.
	 * @param int                  $skipped  How many objects it skipped.
	 *
	 * @return string The message.
	 */
	private function importedMessage(array $imported, int $skipped): string {
		if ($skipped <= 0) {
			return 'Imported ' . $imported['objects'] . ' example object(s).';
		}

		return 'Imported ' . max(0, ($imported['objects'] - $skipped)) . ' of ' . $imported['objects']
			. ' example object(s). ' . $skipped . ' could not be imported; the Nextcloud log names each one.';
	}//end importedMessage()

	/**
	 * Remove one example set through OpenRegister's recorded import jobs
	 * (openregister PR 4080).
	 *
	 * Every outcome is an answer the wizard shows: nothing loaded, nothing
	 * recorded, an OpenRegister without the method (with the occ command that
	 * removes the set instead), errors (with the command that finishes the
	 * job), or the number of objects moved to the trash.
	 *
	 * @param string $picked The set to remove, or '' when none is picked.
	 *
	 * @return ExampleSetAnswer `{ success, message }` and the decision to record.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-wizard-removes-a-loaded-example-set-through-openregisters-import-jobs
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-administrator-removes-a-loaded-example-set-on-the-admin-page
	 */
	public function remove(string $picked): ExampleSetAnswer {
		if ($picked === '' || $picked === SeedProfileServiceInterface::NONE_PROFILE) {
			return $this->unrecorded(
				response: new JSONResponse(data: ['success' => true, 'message' => 'No example data was loaded, so there is nothing to remove.'])
			);
		}

		try {
			$removed = $this->seedProfiles->remove(profileId: $picked);
		} catch (\Throwable $e) {
			return $this->unrecorded(response: $this->removalFailed(picked: $picked, error: $e));
		}

		return $this->removalOutcome(picked: $picked, removed: $removed);
	}//end remove()

	/**
	 * Log a failed removal and answer it with a 500 that names the reason.
	 *
	 * @param string     $picked The set that failed.
	 * @param \Throwable $error  Why it failed.
	 *
	 * @return JSONResponse `{ success, message }`.
	 */
	private function removalFailed(string $picked, \Throwable $error): JSONResponse {
		$this->logger->error(
			'Setup remove-example-set failed for "' . $picked . '": ' . $error->getMessage(),
			['app' => AppIdentity::APP_ID, 'exception' => $error]
		);

		return new JSONResponse(
			data: ['success' => false, 'message' => 'Could not remove the example data: ' . $error->getMessage()],
			statusCode: Http::STATUS_INTERNAL_SERVER_ERROR,
		);
	}//end removalFailed()

	/**
	 * The answer to a finished removal: unsupported, partial, nothing recorded, or done.
	 *
	 * @param string               $picked  The set that was removed.
	 * @param array<string, mixed> $removed The removal's reply.
	 *
	 * @return ExampleSetAnswer `{ success, message }` and the decision to record.
	 */
	private function removalOutcome(string $picked, array $removed): ExampleSetAnswer {
		if ($removed['supported'] === false) {
			return $this->unrecorded(response: new JSONResponse(data: ['success' => false, 'message' => $this->fallbackMessage(profileId: $picked)]));
		}

		if ($removed['errors'] > 0) {
			return $this->unrecorded(response: $this->partialRemoval(removed: $removed));
		}

		if ($removed['jobs'] === []) {
			return $this->unrecorded(response: $this->noRecordedImport(picked: $picked));
		}

		return new ExampleSetAnswer(
			response: new JSONResponse(data: ['success' => true, 'message' => 'Moved ' . $removed['softDeleted'] . ' example object(s) to the trash.']),
			decided: self::REMOVED
		);
	}//end removalOutcome()

	/**
	 * An answer that leaves the wizard's recorded decision as it is.
	 *
	 * @param JSONResponse $response The answer.
	 *
	 * @return ExampleSetAnswer The answer with nothing to record.
	 */
	private function unrecorded(JSONResponse $response): ExampleSetAnswer {
		return new ExampleSetAnswer(response: $response, decided: null);
	}//end unrecorded()

	/**
	 * The answer to a removal that left objects behind, naming one command per job.
	 *
	 * `--import-job` takes one job per run, and purge destroys rows for good,
	 * so the advice names one command per job and says so.
	 *
	 * @param array<string, mixed> $removed The removal's reply.
	 *
	 * @return JSONResponse `{ success, message }`.
	 */
	private function partialRemoval(array $removed): JSONResponse {
		$commands = array_map(
			static fn (string $job): string => 'php occ openregister:objects:purge --import-job ' . $job,
			$removed['failedJobs']
		);

		return new JSONResponse(
			data: [
				'success' => false,
				'message' => 'Moved ' . $removed['softDeleted'] . ' example object(s) to the trash; ' . $removed['errors']
					. ' could not be removed. Run this step again, or finish on the server with: ' . implode('; ', $commands)
					. ' (each shows what it would remove; add --apply to delete for good).',
			]
		);
	}//end partialRemoval()

	/**
	 * The answer when OpenRegister recorded no import of the set.
	 *
	 * @param string $picked The set.
	 *
	 * @return JSONResponse `{ success, message }`.
	 */
	private function noRecordedImport(string $picked): JSONResponse {
		return new JSONResponse(
			data: [
				'success' => true,
				'message' => 'OpenRegister has no recorded import of this example set, so nothing was removed. '
					. $this->fallbackMessage(profileId: $picked),
			]
		);
	}//end noRecordedImport()

	/**
	 * How to remove a set without OpenRegister's recorded import jobs.
	 *
	 * @param string $profileId The set.
	 *
	 * @return string The advice, naming the command where one exists.
	 */
	private function fallbackMessage(string $profileId): string {
		if ($profileId === SeedProfileServiceInterface::GENERATED_PROFILE) {
			return 'This OpenRegister cannot remove the generated example data from here. Update OpenRegister and run this step again.';
		}

		return 'Remove it on the server with: php occ learniq:example-set:remove ' . $profileId . ' --apply';
	}//end fallbackMessage()
}//end class
