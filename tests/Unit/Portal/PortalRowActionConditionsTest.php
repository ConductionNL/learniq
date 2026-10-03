<?php

/**
 * Learniq portal row action conditions tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Portal
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
 * @spec openspec/changes/parent-row-actions-only-where-they-apply/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every row action learniq contributes says on which rows it applies
 * (`rowWhen`, portaliq update-row-action-condition), with states the
 * schema really has, so the site never offers a cancel on a finished time.
 *
 * @spec openspec/changes/parent-row-actions-only-where-they-apply/specs/portal-contribution/spec.md
 */
class PortalRowActionConditionsTest extends TestCase {

	/**
	 * The condition each row action declares.
	 */
	private const CONDITIONS = [
		'cancelConferenceTime' => ['field' => 'lifecycle', 'in' => ['booked', 'acknowledged']],
		'handIn' => ['field' => 'lifecycle', 'in' => ['draft']],
	];

	/**
	 * The guardian is offered the cancel only on a booked or acknowledged
	 * time: the states ConferenceSlotBookingSync lets a parent cancel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parent-row-actions-only-where-they-apply/specs/portal-contribution/spec.md#requirement-a-guardian-is-offered-a-cancel-only-on-a-time-that-can-still-be-cancelled
	 */
	public function testTheCancelNamesTheTimesThatCanStillBeCancelled(): void {
		$actions = array_column($this->manifest(audience: 'parent')['actions'], null, 'id');

		self::assertSame(self::CONDITIONS['cancelConferenceTime'], $actions['cancelConferenceTime']['rowWhen']);
	}//end testTheCancelNamesTheTimesThatCanStillBeCancelled()

	/**
	 * Every row action of every audience declares a condition on a field its
	 * collection projects, with values the schema's lifecycle allows.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parent-row-actions-only-where-they-apply/specs/portal-contribution/spec.md#requirement-a-guardian-is-offered-a-cancel-only-on-a-time-that-can-still-be-cancelled
	 */
	public function testEveryRowActionNamesRealStates(): void {
		$seen = [];
		foreach ((new PortalContributionProvider())->getAudiences() as $audience) {
			$manifest = $this->manifest(audience: $audience);
			$actions = array_column($manifest['actions'], null, 'id');
			foreach ($manifest['collections'] as $collection) {
				foreach (($collection['rowActions'] ?? []) as $actionId) {
					$condition = ($actions[$actionId]['rowWhen'] ?? null);
					self::assertIsArray($condition, $actionId.' declares no rowWhen');
					self::assertSame(['field', 'in'], array_keys($condition), $actionId);
					self::assertContains($condition['field'], $collection['fields'], $actionId);

					$allowed = $this->lifecycleStates(slug: $collection['schema']);
					foreach ($condition['in'] as $value) {
						self::assertContains($value, $allowed, $actionId);
					}

					$seen[$actionId] = $condition;
				}
			}
		}//end foreach

		ksort($seen);
		self::assertSame(self::CONDITIONS, $seen);
	}//end testEveryRowActionNamesRealStates()

	/**
	 * One audience's manifest.
	 *
	 * @param string $audience The audience.
	 *
	 * @return array<string, mixed>
	 */
	private function manifest(string $audience): array {
		return (new PortalContributionProvider())->getContribution(['audience' => $audience]);
	}//end manifest()

	/**
	 * The lifecycle states a register schema allows.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return array<int, string>
	 */
	private function lifecycleStates(string $slug): array {
		$register = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/learniq_register.json'), true);
		foreach ($register['components']['schemas'] as $schema) {
			if (($schema['slug'] ?? null) === $slug) {
				return ($schema['properties']['lifecycle']['enum'] ?? []);
			}
		}

		return [];
	}//end lifecycleStates()
}//end class
