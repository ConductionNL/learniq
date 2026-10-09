<?php

/**
 * Learniq PortalCopiesRefreshJob
 *
 * Once an hour, brings the copies that change without their own row being
 * saved back in step: a grade stops reading "Nieuw" seven days after it
 * became visible, and a company's employee count follows its employees.
 * Each row whose copy moved is saved again, so ReadableCopyStamp writes it
 * as on every other save.
 *
 * @category BackgroundJob
 * @package  OCA\Learniq\BackgroundJob
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
 * @spec openspec/changes/portal-person-and-list-data/specs/portal-contribution/spec.md#requirement-the-lists-read-the-subject-the-teacher-and-what-is-new
 */

declare(strict_types=1);

namespace OCA\Learniq\BackgroundJob;

use OCA\Learniq\Service\PortalListCopies;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refreshes the copies that age: new grades and company employee counts.
 *
 * @spec openspec/changes/portal-person-and-list-data/specs/portal-contribution/spec.md#requirement-the-lists-read-the-subject-the-teacher-and-what-is-new
 */
class PortalCopiesRefreshJob extends TimedJob {

	public const INTERVAL_SECONDS = 3600;

	private const REGISTER = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory    $time          The clock.
	 * @param ObjectService   $objectService Reads and saves the rows.
	 * @param IUserManager    $users         Names a teacher.
	 * @param LoggerInterface $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ObjectService $objectService,
		private readonly IUserManager $users,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
	}//end __construct()

	/**
	 * Refresh both kinds of copy.
	 *
	 * @param mixed $argument Unused job argument.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is TimedJob's.
	 *
	 * @spec openspec/changes/portal-person-and-list-data/specs/portal-contribution/spec.md#requirement-the-lists-read-the-subject-the-teacher-and-what-is-new
	 */
	protected function run(mixed $argument): void {
		$moved = 0;
		try {
			$now = $this->time->now();
			foreach ($this->rows(schema: 'grade-entry', filters: ['isNew' => true]) as $grade) {
				if (PortalListCopies::isNew(row: $grade, now: $now) === false) {
					$moved += $this->save(schema: 'grade-entry', row: $grade, copies: ['isNew' => false]);
				}
			}

			$copies = new PortalListCopies(objectService: $this->objectService, users: $this->users);
			foreach ($this->rows(schema: 'client-organisation', filters: []) as $company) {
				$fresh = $copies->derive(slug: 'client-organisation', row: $company);
				if (($company['employeeCountLabel'] ?? null) !== $fresh['employeeCountLabel']) {
					$moved += $this->save(schema: 'client-organisation', row: $company, copies: $fresh);
				}
			}
		} catch (Throwable $exception) {
			$this->logger->warning('[PortalCopiesRefreshJob] Stopped early: {msg}', ['msg' => $exception->getMessage()]);
		}

		if ($moved > 0) {
			$this->logger->info('[PortalCopiesRefreshJob] {count} row(s) refreshed.', ['count' => $moved]);
		}
	}//end run()

	/**
	 * The rows of a schema, as arrays.
	 *
	 * @param string                    $schema  The schema slug.
	 * @param array<string, string|bool> $filters Field equals value.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(string $schema, array $filters): array {
		$objects = $this->objectService->findAll(
			config: ['filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters), 'limit' => 1000],
			_rbac: false,
			_multitenancy: false
		);
		$rows = [];
		foreach ($objects as $object) {
			$row = $object;
			if (is_array($object) === false) {
				$row = (array)$object->jsonSerialize();
			}

			$rows[] = $row;
		}

		return $rows;
	}//end rows()

	/**
	 * Save one row with its fresh copies.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $row    The row.
	 * @param array<string, mixed> $copies The copies.
	 *
	 * @return int 1 when saved, 0 when it failed.
	 */
	private function save(string $schema, array $row, array $copies): int {
		$id = (string)($row['id'] ?? ($row['uuid'] ?? ''));
		if ($id === '') {
			return 0;
		}

		try {
			$this->objectService->saveObject(
				object: array_merge($row, $copies),
				register: self::REGISTER,
				schema: $schema,
				uuid: $id,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning('[PortalCopiesRefreshJob] {schema} {id} not saved: {msg}', ['schema' => $schema, 'id' => $id, 'msg' => $exception->getMessage()]);
			return 0;
		}

		return 1;
	}//end save()
}//end class
