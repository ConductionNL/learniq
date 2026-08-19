<?php

/**
 * Learniq RenameRegisterSlug Repair Step
 *
 * The rename-to-learniq boundary 3 (register-slug migration): renames the app's
 * single OpenRegister register row from slug `scholiq` to slug `learniq`.
 *
 * WHY THIS IS SAFE. Verified directly against a live database, not inferred:
 * `oc_openregister_registers` has exactly one scholiq row (id=9, slug=
 * 'scholiq'), and the app's object storage is sharded into per-schema tables
 * named `oc_openregister_table_{register_id}_{schema_id}` — keyed on the
 * register's NUMERIC id, never on its slug. A decisive negative check
 * (`SELECT COUNT(*) FROM information_schema.tables WHERE table_name ~
 * 'oc_openregister_table_[a-z]'` returns 0 instance-wide) confirms no shard
 * table anywhere embeds a slug in its name. So this step is a single-column
 * `UPDATE` on one row; it never touches, moves, or renames a shard table.
 *
 * ORDERING. This step MUST ship in the same release as every literal
 * `'scholiq'` register-slug string constant/call-site in lib/ being changed
 * to `'learniq'` — a lookup against the old slug after this step has run
 * returns nothing, by design (see the app-metadata spec's "pre-existing
 * objects resolve" scenario). Data and code move together, not
 * data-first-then-code.
 *
 * SAFETY. Idempotent and non-destructive, mirroring RenameDutchColumns:
 *   - only fires when a row with slug 'scholiq' exists;
 *   - a collision guard refuses (logs + skips) rather than merges or
 *     overwrites when a row with slug 'learniq' already exists;
 *   - no DELETE, ever; a re-run finds no matching row and is a no-op.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\Learniq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/rename-to-learniq/specs/app-metadata/spec.md#requirement-pre-existing-openregister-objects-resolve-after-the-register-slug-migration
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Rename the learniq OpenRegister register's slug from scholiq to learniq.
 *
 * @spec openspec/changes/rename-to-learniq/specs/app-metadata/spec.md#requirement-pre-existing-openregister-objects-resolve-after-the-register-slug-migration
 */
class RenameRegisterSlug implements IRepairStep {
	/**
	 * The register slug prior to the rename.
	 *
	 * @var string
	 */
	private const OLD_SLUG = 'scholiq';

	/**
	 * The register slug after the rename.
	 *
	 * @var string
	 */
	private const NEW_SLUG = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Rename the learniq OpenRegister register slug from scholiq to learniq';
	}//end getName()

	/**
	 * Run the register-slug rename.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rename-to-learniq/specs/app-metadata/spec.md#requirement-pre-existing-openregister-objects-resolve-after-the-register-slug-migration
	 */
	public function run(IOutput $output): void {
		if ($this->hasCollision() === true) {
			$this->logger->warning(
				'RenameRegisterSlug: a register with slug \'' . self::NEW_SLUG . '\' already exists; '
				. 'refusing to rename or merge. Manual investigation required.'
			);
			$output->warning(
				'RenameRegisterSlug: a register already uses slug \'' . self::NEW_SLUG
				. '\' — skipped, no change made.'
			);
			return;
		}

		$renamed = $this->renameSlug();

		$output->info('RenameRegisterSlug: ' . $renamed . ' register(s) renamed.');
	}//end run()

	/**
	 * Whether a register already carries the destination slug.
	 *
	 * @return bool
	 */
	private function hasCollision(): bool {
		try {
			$count = $this->db->executeQuery(
				'SELECT COUNT(*) AS c FROM `*PREFIX*openregister_registers` WHERE slug = ?',
				[self::NEW_SLUG]
			)->fetchOne();
			return ((int)$count) > 0;
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameRegisterSlug: could not check for a slug collision; skipping the rename.',
				['exception' => $e->getMessage()]
			);
			// Fail closed: if the collision check itself failed, do not rename.
			return true;
		}
	}//end hasCollision()

	/**
	 * Execute the single-row slug UPDATE.
	 *
	 * @return int Number of rows renamed (0 or 1 — this app owns exactly one register).
	 */
	private function renameSlug(): int {
		try {
			return $this->db->executeStatement(
				'UPDATE `*PREFIX*openregister_registers` SET slug = ? WHERE slug = ?',
				[self::NEW_SLUG, self::OLD_SLUG]
			);
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameRegisterSlug: the slug UPDATE failed; leaving the register as it was.',
				['exception' => $e->getMessage()]
			);
			return 0;
		}
	}//end renameSlug()
}//end class
