<?php

/**
 * Learniq occ command: give a loaded example set its themed portal.
 *
 * Loading a set through the wizard already does this. The command is for a
 * set that was loaded before the step existed, so its portal gets the theme
 * without importing thousands of objects again.
 *
 * @category Command
 * @package  OCA\Learniq\Command
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
 * @spec openspec/changes/example-sets-themed-portal/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Command;

use OCA\Learniq\Portal\ExamplePortalProvisioner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ learniq:example-set:portal <set>`.
 *
 * @spec openspec/changes/example-sets-themed-portal/specs/example-sets/spec.md
 */
class ExampleSetPortalCommand extends Command {

	/**
	 * The answers that mean nothing went wrong.
	 */
	public const SUCCESS_STATUSES = ['created', 'filled', 'unchanged', 'kept-legacy'];

	/**
	 * Constructor.
	 *
	 * @param ExamplePortalProvisioner $portals Creates or themes the portal.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExamplePortalProvisioner $portals,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, description and arguments.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-sets-themed-portal/specs/example-sets/spec.md#requirement-loading-an-example-set-gives-its-school-a-themed-portal
	 */
	protected function configure(): void {
		$this->setName(name: 'learniq:example-set:portal')
			->setDescription(description: 'Give a loaded example set its portal and the site its declaration names; writes only what is missing')
			->addArgument(
				name: 'set',
				mode: InputArgument::REQUIRED,
				description: 'The example set: ' . implode(', ', array_keys(ExamplePortalProvisioner::PORTALS))
			);
	}//end configure()

	/**
	 * Create or theme the portal.
	 *
	 * @param InputInterface  $input  The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/example-sets-themed-portal/specs/example-sets/spec.md#requirement-loading-an-example-set-gives-its-school-a-themed-portal
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$result = $this->portals->provision(profileId: (string)$input->getArgument('set'));
		$status = $result['status'];

		$output->writeln(self::describe(result: $result));

		if (in_array($status, self::SUCCESS_STATUSES, true) === true) {
			return self::SUCCESS;
		}

		return self::FAILURE;
	}//end execute()

	/**
	 * One line that says what the portal step did.
	 *
	 * @param array<string, mixed> $result The provisioner's answer.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/example-portal-declares-its-site/specs/example-sets/spec.md#requirement-loading-a-set-writes-its-declared-site-once
	 */
	public static function describe(array $result): string {
		$line = 'Portal ' . ($result['slug'] ?? '-') . ': ' . (string)$result['status'];
		if (isset($result['theme']) === true) {
			$fallback = '';
			if (($result['themeFallback'] ?? false) === true) {
				$fallback = ', fallback';
			}

			$line .= ' (theme ' . $result['theme'] . $fallback . ')';
		}

		foreach (['menus', 'pages', 'news'] as $part) {
			if (isset($result[$part]) === true) {
				$line .= '; ' . $part . ' ' . (int)$result[$part]['created'] . ' created, ' . (int)$result[$part]['kept'] . ' kept';
			}
		}

		return $line;
	}//end describe()
}//end class
