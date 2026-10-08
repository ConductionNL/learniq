<?php

/**
 * The placement as a page with steps, and the trainer's assessment in steps with a draft.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Portal
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
 * @spec openspec/changes/site-workplace-trainer-portal-design/specs/portal-contribution/spec.md#requirement-new-a-placement-shows-where-it-stands
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\PortalContributionProvider;
use OCA\Learniq\Service\Portal\BpvPlacementSteps;
use PHPUnit\Framework\TestCase;

/**
 * Placement steps on the student's and the trainer's pages; the assessment form.
 */
class PlacementPagesTest extends TestCase {

	/**
	 * One audience's manifest.
	 *
	 * @param string $audience The audience.
	 *
	 * @return array<string, mixed>
	 */
	private static function manifest(string $audience): array {
		return (new PortalContributionProvider())->getContribution(['audience' => $audience]);
	}//end manifest()

	/**
	 * Both placement collections are followed like a case with the same provider,
	 * and their pages show the open placement's steps.
	 *
	 * @return void
	 */
	public function testThePlacementPagesShowTheirSteps(): void {
		foreach (['student' => 'studentBpvPlacements', 'praktijkopleider' => 'poBpvPlacements'] as $audience => $id) {
			$manifest = self::manifest(audience: $audience);
			$collection = array_column($manifest['collections'], null, 'id')[$id];
			self::assertSame('cases', $collection['kind'], $id);
			self::assertSame('bpvPlacementSteps', $collection['steps']['provider'], $id);
			self::assertTrue(method_exists(PortalContributionProvider::class, $collection['steps']['provider']));

			$page = array_column($manifest['pages'], null, 'id')[$id];
			self::assertSame($id, $page['record']['collection'], $id);
			self::assertContains(['type' => 'steps', 'collection' => $id, 'label' => $collection['steps']['label']], $page['blocks'], $id);
		}
	}//end testThePlacementPagesShowTheirSteps()

	/**
	 * The provider asks the service, and answers nothing without it.
	 *
	 * @return void
	 */
	public function testTheStepsProviderAsksTheService(): void {
		self::assertSame([], (new PortalContributionProvider())->bpvPlacementSteps(id: 'p-1'));
		$steps = $this->createMock(BpvPlacementSteps::class);
		$steps->expects(self::once())->method('forPlacement')->with('p-1')->willReturn([['label' => 'Agreement signed', 'state' => 'done']]);
		self::assertCount(1, (new PortalContributionProvider(placementSteps: $steps))->bpvPlacementSteps(id: 'p-1'));
	}//end testTheStepsProviderAsksTheService()

	/**
	 * The trainer's assessment runs in two steps that hold every field once, keeps
	 * a draft for 30 days, asks the judgement as cards, and confirms.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/site-workplace-trainer-portal-design/specs/portal-contribution/spec.md#requirement-new-a-trainer-assesses-a-werkproces-in-plain-words
	 */
	public function testTheAssessmentRunsInStepsWithADraft(): void {
		$action = array_column(self::manifest(audience: 'praktijkopleider')['actions'], null, 'id')['createWerkprocesAssessment'];
		$placed = array_merge(...array_map(static fn (array $step): array => ($step['fields'] ?? []), $action['steps']));
		sort($placed);
		$fields = $action['fields'];
		sort($fields);

		self::assertSame($fields, $placed);
		self::assertSame(['retentionDays' => 30], $action['draft']);
		self::assertTrue(end($action['steps'])['review']);
		self::assertSame('choices', $action['fieldConfigs']['assessment']['widget']);
		self::assertSame(['competent', 'nog-niet-competent'], array_column($action['optionsProviders']['assessment']['options'], 'value'));
		self::assertSame('Your assessment has been sent', $action['confirmation']['title']);
		self::assertContains('assessment', $action['requiredFields']);
		self::assertSame('endpoint-forward', $action['type']);
	}//end testTheAssessmentRunsInStepsWithADraft()
	/**
	 * The steps, the placement step labels and the confirmation have a Dutch entry, and the
	 * translator reaches a step's title and description.
	 *
	 * @return void
	 */
	public function testTheStepsReadInDutch(): void {
		$dutch = json_decode((string)file_get_contents(__DIR__ . '/../../../l10n/nl.json'), true)['translations'];
		$action = array_column(self::manifest(audience: 'praktijkopleider')['actions'], null, 'id')['createWerkprocesAssessment'];
		$texts = ['Agreement signed', 'Work plan made', 'Midterm review', 'Final review', 'Placement finished', 'Where do you stand?', 'Where does the placement stand?'];
		foreach ($action['steps'] as $step) {
			$texts[] = $step['title'];
			if (isset($step['description']) === true) {
				$texts[] = $step['description'];
			}
		}

		$texts = array_merge($texts, array_values($action['confirmation']));
		self::assertSame([], array_values(array_filter($texts, static fn (string $text): bool => isset($dutch[$text]) === false)));

		$l10n = $this->createMock(\OCP\IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => ($dutch[$text] ?? $text));
		$out = (new \OCA\Learniq\Portal\PortalLabelTranslator(l10n: $l10n))->translate(manifest: ['actions' => [$action]]);
		self::assertSame('Welk werkproces', $out['actions'][0]['steps'][0]['title']);
		self::assertSame('Kies de stage en het werkproces dat u beoordeelt.', $out['actions'][0]['steps'][0]['description']);
		self::assertSame('bpvPlacementId', $out['actions'][0]['steps'][0]['fields'][0]);
	}//end testTheStepsReadInDutch()
}//end class
