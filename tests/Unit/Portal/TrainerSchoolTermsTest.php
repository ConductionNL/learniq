<?php

/**
 * The school terms a workplace trainer meets on her forms carry one line of
 * explanation, in Dutch on the site.
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
 * @spec openspec/changes/site-workplace-trainer-portal-design/specs/portal-contribution/spec.md#requirement-every-trainer-label-on-the-site-reads-in-dutch-school-terms-explained
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\PortalContributionProvider;
use OCA\Learniq\Portal\TrainerSitePages;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

/**
 * Explanations of praktijkovereenkomst, werkproces, kwalificatiedossier and BPV.
 */
class TrainerSchoolTermsTest extends TestCase {

	/**
	 * The trainer's actions by id, as learniq declares them in English.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function actions(): array {
		return array_column((new TrainerSitePages())->contribution()['actions'], null, 'id');
	}//end actions()

	/**
	 * Each term is explained on the first field of her forms that names it.
	 *
	 * @return void
	 */
	public function testEachTermIsExplainedWhereItFirstAppears(): void {
		$actions = self::actions();

		self::assertSame(
			expected: TrainerSitePages::SCHOOL_TERMS['bpv'],
			actual: $actions['approveHourWeek']['fieldConfigs']['hourWeekId']['help']
		);
		self::assertSame(
			expected: TrainerSitePages::SCHOOL_TERMS['werkproces'],
			actual: $actions['createWerkprocesAssessment']['fieldConfigs']['werkprocesCode']['help']
		);
		self::assertSame(
			expected: TrainerSitePages::SCHOOL_TERMS['kwalificatiedossier'],
			actual: $actions['createWerkprocesAssessment']['fieldConfigs']['kwalificatiedossierCode']['help']
		);
		self::assertSame(
			expected: TrainerSitePages::SCHOOL_TERMS['praktijkovereenkomst'],
			actual: $actions['signPraktijkovereenkomst']['fieldConfigs']['subjectId']['help']
		);
	}//end testEachTermIsExplainedWhereItFirstAppears()

	/**
	 * A term is explained once per form: no other field of the same form
	 * repeats the line.
	 *
	 * @return void
	 */
	public function testATermIsExplainedOncePerForm(): void {
		foreach (self::actions() as $id => $action) {
			$helps = array_column($action['fieldConfigs'] ?? [], 'help');
			self::assertSame(expected: array_values(array_unique($helps)), actual: $helps, message: $id);
		}
	}//end testATermIsExplainedOncePerForm()

	/**
	 * Every explained field is a field the form sends, so portaliq draws it.
	 *
	 * @return void
	 */
	public function testEveryExplainedFieldIsOnItsForm(): void {
		foreach (self::actions() as $id => $action) {
			foreach (array_keys($action['fieldConfigs'] ?? []) as $field) {
				self::assertContains(needle: $field, haystack: $action['fields'], message: $id.'.'.$field);
			}
		}
	}//end testEveryExplainedFieldIsOnItsForm()

	/**
	 * On the site the explanations read in Dutch, in the "u" form.
	 *
	 * @return void
	 */
	public function testTheExplanationsReadDutch(): void {
		$dutch = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/l10n/nl.json'), true)['translations'];
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => ($dutch[$text] ?? $text));
		$factory = $this->createMock(originalClassName: IFactory::class);
		$factory->method('get')->with('learniq')->willReturn($l10n);

		$manifest = (new PortalContributionProvider(l10nFactory: $factory))->getContribution(['audience' => 'praktijkopleider']);
		$actions = array_column($manifest['actions'], null, 'id');

		self::assertSame(
			expected: 'Praktijkovereenkomst (POK): de afspraken tussen u, de student en school.',
			actual: $actions['signPraktijkovereenkomst']['fieldConfigs']['subjectId']['help']
		);
		self::assertSame(
			expected: 'BPV (beroepspraktijkvorming) is het deel van de opleiding dat de student bij uw bedrijf leert.',
			actual: $actions['approveHourWeek']['fieldConfigs']['hourWeekId']['help']
		);
		foreach (TrainerSitePages::SCHOOL_TERMS as $term => $line) {
			self::assertArrayHasKey(key: $line, array: $dutch, message: $term);
		}
	}//end testTheExplanationsReadDutch()
}//end class
