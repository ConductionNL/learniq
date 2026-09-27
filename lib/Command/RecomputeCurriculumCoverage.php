<?php

/**
 * Learniq Recompute Curriculum Coverage Command
 *
 * `occ learniq:curriculum-coverage:recompute [--framework=<uuid>]` fills the
 * CurriculumCoverage rows for data saved before curriculum-coverage-rollup
 * existed, or repairs one framework. Without the option it recomputes every
 * framework; with it, only that one. An occ command rather than an HTTP
 * endpoint, so the backfill adds no route and no access surface.
 *
 * @category Command
 * @package  OCA\Learniq\Command
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
 * @spec openspec/changes/curriculum-coverage-rollup/specs/competency/spec.md#requirement-an-occ-command-fills-coverage-for-existing-data
 */

declare(strict_types=1);

namespace OCA\Learniq\Command;

use OCA\Learniq\Service\CurriculumCoverageRollup;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Recomputes curriculum coverage for every framework, or for one.
 *
 * @spec openspec/changes/curriculum-coverage-rollup/specs/competency/spec.md#requirement-an-occ-command-fills-coverage-for-existing-data
 */
class RecomputeCurriculumCoverage extends Command {

	/**
	 * Constructor.
	 *
	 * @param CurriculumCoverageRollup $rollup Recomputes one framework.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CurriculumCoverageRollup $rollup,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, description and the --framework option.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/curriculum-coverage-rollup/specs/competency/spec.md#requirement-an-occ-command-fills-coverage-for-existing-data
	 */
	protected function configure(): void {
		$this->setName(name: 'learniq:curriculum-coverage:recompute');
		$this->setDescription(description: 'Recompute curriculum coverage for every framework, or for one.');
		$this->addOption(
			name: 'framework',
			shortcut: null,
			mode: InputOption::VALUE_REQUIRED,
			description: 'UUID of the one framework to recompute.'
		);
	}//end configure()

	/**
	 * Recompute and report.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int 0 on success, 1 when the named framework does not exist.
	 *
	 * @spec openspec/changes/curriculum-coverage-rollup/specs/competency/spec.md#requirement-an-occ-command-fills-coverage-for-existing-data
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$frameworkIds = $this->rollup->allFrameworkIds();
		$one          = $input->getOption('framework');
		if (is_string($one) === true && $one !== '') {
			if ($this->rollup->frameworkExists(frameworkId: $one) === false) {
				$output->writeln('<error>No framework with id ' . $one . '.</error>');
				return 1;
			}

			$frameworkIds = [$one];
		}

		foreach ($frameworkIds as $frameworkId) {
			$result = $this->rollup->recompute(frameworkId: $frameworkId);
			$output->writeln(
				sprintf('%s: %d saved, %d deleted, %d unchanged', $frameworkId, $result['saved'], $result['deleted'], $result['unchanged'])
			);
		}

		$output->writeln(sprintf('Recomputed %d framework(s).', count($frameworkIds)));
		return 0;
	}//end execute()
}//end class
