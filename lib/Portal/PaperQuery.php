<?php

/**
 * Learniq PaperQuery
 *
 * Which records hold the papers of one portal row, and what every one of them
 * must still say when it is read: the filters are both the query and a check
 * on each row that comes back, so a store that ignores a filter cannot widen
 * what a guardian is given.
 *
 * @category Portal
 * @package  OCA\Learniq\Portal
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
 * @spec openspec/changes/guardian-reports-and-documents/specs/portal-contribution/spec.md#requirement-a-guardian-reads-her-childrens-published-reports-and-the-schools-papers-on-one-page
 */

declare(strict_types=1);

namespace OCA\Learniq\Portal;

/**
 * The schema and equality filters of one papers read.
 *
 * @spec openspec/changes/guardian-reports-and-documents/specs/portal-contribution/spec.md#requirement-a-guardian-reads-her-childrens-published-reports-and-the-schools-papers-on-one-page
 */
class PaperQuery {

	/**
	 * Constructor.
	 *
	 * @param string                $schema  The schema slug of the records that hold the files.
	 * @param array<string, string> $filters The values every such record must have.
	 */
	public function __construct(
		public readonly string $schema,
		public readonly array $filters,
	) {
	}//end __construct()

	/**
	 * Whether the read can answer anything: no filter value is empty.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/guardian-reports-and-documents/specs/portal-contribution/spec.md#requirement-a-guardian-reads-her-childrens-published-reports-and-the-schools-papers-on-one-page
	 */
	public function isComplete(): bool {
		return in_array('', $this->filters, true) === false;
	}//end isComplete()

	/**
	 * Whether a row has every filtered value.
	 *
	 * @param array<string, mixed> $row The row as read.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/guardian-reports-and-documents/specs/portal-contribution/spec.md#requirement-a-guardian-reads-her-childrens-published-reports-and-the-schools-papers-on-one-page
	 */
	public function matches(array $row): bool {
		foreach ($this->filters as $field => $value) {
			if (($row[$field] ?? null) !== $value) {
				return false;
			}
		}

		return true;
	}//end matches()
}//end class
