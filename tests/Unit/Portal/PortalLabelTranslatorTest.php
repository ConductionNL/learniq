<?php

/**
 * Learniq PortalLabelTranslator tests
 *
 * The guardian's portal labels arrive in her language: every visible string
 * of the parent manifest has a Dutch entry in l10n/nl.json, the provider
 * hands the Dutch catalogue's words to portaliq, and identifiers never move.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Portal
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
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Portal;

use OCA\Learniq\Portal\PortalContributionProvider;
use OCA\Learniq\Portal\PortalLabelTranslator;
use OCA\Learniq\Portal\PortalValueLabels;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PortalLabelTranslator and its use on the parent manifest.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
class PortalLabelTranslatorTest extends TestCase {

	/**
	 * The Dutch catalogue the app ships.
	 *
	 * @var array<string, string>
	 */
	private array $dutch;

	/**
	 * Load the real Dutch catalogue.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$file = dirname(__DIR__, 3).'/l10n/nl.json';
		$this->dutch = json_decode((string) file_get_contents($file), true)['translations'];
	}//end setUp()

	/**
	 * An IL10N that answers from the shipped Dutch catalogue, the way
	 * Nextcloud's own does: a missing key comes back as the English source.
	 *
	 * @return IL10N The Dutch translator.
	 */
	private function dutchL10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			fn (string $text): string => ($this->dutch[$text] ?? $text)
		);
		return $l10n;
	}//end dutchL10n()

	/**
	 * Every visible string of a manifest, keyed by its path.
	 *
	 * @param array<array-key, mixed> $manifest The manifest.
	 * @param string                  $path     The path so far.
	 *
	 * @return array<string, string> Path => string.
	 */
	private static function visibleStrings(array $manifest, string $path=''): array {
		$found = [];
		foreach ($manifest as $key => $value) {
			$here = $path.'/'.$key;
			if ($key === 'valueLabels' && is_array($value) === true) {
				foreach ($value as $stored => $label) {
					$found[$here.'/'.$stored] = $label;
				}

				continue;
			}

			if (is_array($value) === true) {
				$found = array_merge($found, self::visibleStrings(manifest: $value, path: $here));
				continue;
			}

			if (is_string($value) === true && self::isVisible(path: $here) === true) {
				$found[$here] = $value;
			}
		}

		return $found;
	}//end visibleStrings()

	/**
	 * Whether the string at a path is one a reader sees: a label, button
	 * text, message, unit or fallback anywhere; the kind and fixed title of a
	 * calendar source; every label of a lookup's `values`.
	 *
	 * @param string $path The path.
	 *
	 * @return bool
	 */
	private static function isVisible(string $path): bool {
		return preg_match('#/(label|submitLabel|successMessage|unit|fallback|group|otherLabel|requiredMessage|buttonLabel|template|eyebrow|soonLabel|noteLabel)$#', $path) === 1
			|| preg_match('#/confirmation/(title|body|next)$#', $path) === 1
			|| preg_match('#/phrases/[^/]+/[^/]+$#', $path) === 1
			|| preg_match('#/(label|unit)/(one|other)$#', $path) === 1
			|| preg_match('#/sources/\d+/(kind|title)$#', $path) === 1
			|| preg_match('#/values/[^/]+$#', $path) === 1;
	}//end isVisible()

	/**
	 * Every visible parent string has a Dutch entry, so no guardian on a
	 * Dutch portal reads an English label.
	 *
	 * @return void
	 */
	public function testEveryParentLabelHasADutchEntry(): void {
		$english = (new PortalContributionProvider())->getContribution(['audience' => 'parent']);
		$strings = self::visibleStrings(manifest: $english);

		self::assertNotEmpty($strings);
		$missing = array_values(array_filter($strings, fn (string $text): bool => isset($this->dutch[$text]) === false));
		self::assertSame([], $missing, 'Parent portal strings without a Dutch entry in l10n/nl.json');
	}//end testEveryParentLabelHasADutchEntry()

	/**
	 * Through the container's l10n factory the parent manifest comes out in
	 * Dutch: section, column, action and form labels.
	 *
	 * @return void
	 */
	public function testTheParentManifestArrivesInDutch(): void {
		$factory = $this->createMock(IFactory::class);
		$factory->expects(self::once())->method('get')->with('learniq')->willReturn($this->dutchL10n());

		$manifest = (new PortalContributionProvider(l10nFactory: $factory))->getContribution(['audience' => 'parent']);
		$labels = array_column($manifest['collections'], 'label', 'id');

		self::assertSame('Mijn kinderen', $labels['parentChildren']);
		self::assertSame('Cijfers van mijn kind', $labels['parentGrades']);
		self::assertSame('Aanwezigheid van mijn kind', $labels['parentAttendance']);
		self::assertSame('Afwezigheidsmeldingen van mijn kind', $labels['parentExcuseRequests']);
		self::assertSame('Rapporten van mijn kind', $labels['parentReportCards']);
		self::assertSame('Uw geboekte oudergesprekken', $labels['parentConferenceSignups']);
		self::assertSame('Uw gesprekstijden', $labels['parentConferenceSlots']);

		$children = array_values(array_filter($manifest['collections'], fn (array $c): bool => $c['id'] === 'parentChildren'))[0];
		self::assertSame(['Voornaam', 'Achternaam'], array_column($children['columns'], 'label'));

		$actions = array_column($manifest['actions'], null, 'id');
		self::assertSame('Afwezigheid van uw kind melden', $actions['createExcuseRequest']['label']);
		self::assertSame('Kind', $actions['createExcuseRequest']['fieldConfigs']['learnerRef']['label']);
		self::assertSame('Afwezigheid melden', $actions['createExcuseRequest']['submitLabel']);
		self::assertSame('Voorkeur versturen', $actions['createConferenceSignup']['submitLabel']);
	}//end testTheParentManifestArrivesInDutch()

	/**
	 * Every visible string of the student manifest has a Dutch entry
	 * (site-pupil-portal-design).
	 *
	 * @return void
	 */
	public function testEveryStudentLabelHasADutchEntry(): void {
		$english = (new PortalContributionProvider())->getContribution(['audience' => 'student']);
		$strings = self::visibleStrings(manifest: $english);

		self::assertNotEmpty($strings);
		$missing = array_values(array_filter($strings, fn (string $text): bool => isset($this->dutch[$text]) === false));
		self::assertSame([], $missing, 'Pupil portal strings without a Dutch entry in l10n/nl.json');
	}//end testEveryStudentLabelHasADutchEntry()

	/**
	 * Through the container's l10n factory the student manifest comes out in
	 * Dutch, in the "je" form, and its ids stay as they were.
	 *
	 * @return void
	 */
	public function testTheStudentManifestArrivesInDutch(): void {
		$factory = $this->createMock(IFactory::class);
		$factory->expects(self::once())->method('get')->with('learniq')->willReturn($this->dutchL10n());

		$manifest = (new PortalContributionProvider(l10nFactory: $factory))->getContribution(['audience' => 'student']);
		$labels = array_column($manifest['collections'], 'label', 'id');

		self::assertSame('Mijn cijfers', $labels['studentGrades']);
		self::assertSame('Mijn ingeleverde werk', $labels['studentSubmissions']);
		self::assertSame('Mijn toetsen', $labels['studentTests']);

		$actions = array_column($manifest['actions'], null, 'id');
		self::assertSame('Werk inleveren', $actions['createSubmission']['label']);
		self::assertSame('Je werk', $actions['createSubmission']['fieldConfigs']['attachmentRefs']['label']);
		self::assertSame('Toetsen die je kunt maken', $actions['listTests']['label']);
		self::assertSame('Afwezig melden', $actions['createExcuseRequest']['label']);
	}//end testTheStudentManifestArrivesInDutch()

	/**
	 * Every visible string of the trainer's and the assessor's manifests has a
	 * Dutch entry, and both arrive in Dutch through the container's factory.
	 *
	 * @return void
	 */
	public function testTheTrainerAndAssessorReadDutch(): void {
		foreach (['praktijkopleider', 'external-assessor'] as $audience) {
			$english = (new PortalContributionProvider())->getContribution(['audience' => $audience]);
			$strings = self::visibleStrings(manifest: $english);
			self::assertNotEmpty($strings, $audience);
			$missing = array_values(array_filter($strings, fn (string $text): bool => isset($this->dutch[$text]) === false));
			self::assertSame([], $missing, $audience . ' strings without a Dutch entry in l10n/nl.json');
		}

		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->with('learniq')->willReturn($this->dutchL10n());
		$provider = new PortalContributionProvider(l10nFactory: $factory);

		$trainer = array_column($provider->getContribution(['audience' => 'praktijkopleider'])['collections'], 'label', 'id');
		self::assertSame('Mijn stageplaatsen', $trainer['poBpvPlacements']);
		self::assertSame('Beoordelingen die u schreef', $trainer['poWerkprocesAssessments']);

		$assessor = $provider->getContribution(['audience' => 'external-assessor'])['collections'][0];
		self::assertSame('Met u gedeeld', $assessor['label']);
		self::assertSame(['Kandidaat', 'Portfolio', 'Toegang tot en met'], array_column($assessor['columns'], 'label'));
	}//end testTheTrainerAndAssessorReadDutch()

	/**
	 * Only visible strings move: ids, field names, schemas, the `labelField`
	 * a dropdown reads, and every scope value stay exactly as they were.
	 *
	 * @return void
	 */
	public function testOnlyVisibleStringsAreTranslated(): void {
		$english = (new PortalContributionProvider())->getContribution(['audience' => 'parent']);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'NL:'.$text);

		$translated = (new PortalLabelTranslator(l10n: $l10n))->translate(manifest: $english);

		$strip = static function (array $manifest, string $path='') use (&$strip): array {
			foreach ($manifest as $key => $value) {
				if ($key === 'valueLabels') {
					$manifest[$key] = array_keys($value);
				} else if (is_array($value) === true) {
					$manifest[$key] = $strip($value, $path.'/'.$key);
				} else if (self::isVisible(path: $path.'/'.$key) === true) {
					unset($manifest[$key]);
				}
			}

			return $manifest;
		};
		self::assertSame($strip($english), $strip($translated));
		foreach (self::visibleStrings(manifest: $translated) as $path => $text) {
			self::assertStringStartsWith('NL:', $text, $path);
		}
	}//end testOnlyVisibleStringsAreTranslated()

	/**
	 * A guardian reads statuses and the kinds of absence in Dutch, while the
	 * stored values they are keyed by stay exactly as the schema has them.
	 *
	 * @return void
	 */
	public function testStatusesAndAbsenceKindsArriveInDutch(): void {
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->with('learniq')->willReturn($this->dutchL10n());

		$manifest    = (new PortalContributionProvider(l10nFactory: $factory))->getContribution(['audience' => 'parent']);
		$collections = array_column($manifest['collections'], null, 'id');
		$statusOf    = static function (array $collection, string $field): array {
			return array_column($collection['columns'], 'valueLabels', 'field')[$field];
		};

		self::assertSame(
			['submitted' => 'Ingediend', 'approved' => 'Goedgekeurd', 'rejected' => 'Afgewezen'],
			$statusOf($collections['parentExcuseRequests'], 'lifecycle')
		);
		self::assertSame('Afwezig (geoorloofd)', $statusOf($collections['parentAttendance'], 'status')['absent-excused']);
		self::assertSame('Op de wachtlijst', $statusOf($collections['parentConferenceSignups'], 'lifecycle')['waitlisted']);
		self::assertSame('Niet verschenen', $statusOf($collections['parentConferenceSlots'], 'lifecycle')['no-show']);

		$kinds = array_column($manifest['actions'], null, 'id')['createExcuseRequest']['fieldConfigs']['reasonKind']['valueLabels'];
		self::assertSame('Ziekte', $kinds['illness']);
		self::assertSame('Medische afspraak', $kinds['medical-appointment']);
	}//end testStatusesAndAbsenceKindsArriveInDutch()

	/**
	 * The figure cards arrive in Dutch singular and plural: "1 dag" and
	 * "5 dagen", "1 keer", and "1 minuut in totaal" (lq-polish, 2026-10-03).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parent-figures-singular-and-plural/specs/portal-contribution/spec.md#requirement-the-figure-cards-count-in-singular-and-plural
	 */
	public function testTheFigureCardsArriveInDutchSingularAndPlural(): void {
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->with('learniq')->willReturn($this->dutchL10n());

		$manifest = (new PortalContributionProvider(l10nFactory: $factory))->getContribution(['audience' => 'parent']);
		$record   = array_column($manifest['pages'], null, 'id')['parentChildren'];
		$kpi      = array_values(array_filter($record['blocks'], static fn (array $b): bool => $b['type'] === 'kpi'))[0];

		self::assertSame(['one' => 'dag', 'other' => 'dagen'], $kpi['cards'][0]['unit']);
		self::assertSame(['one' => 'keer', 'other' => 'keer'], $kpi['cards'][1]['unit']);
		self::assertSame(['one' => 'minuut in totaal', 'other' => 'minuten in totaal'], $kpi['cards'][1]['details'][0]['label']);
		self::assertSame('met toestemming', $kpi['cards'][0]['details'][0]['label']);
	}//end testTheFigureCardsArriveInDutchSingularAndPlural()

	/**
	 * Every labelled value is a value the schema stores, and every value the
	 * schema stores has a label, so a renamed enum value cannot slip past.
	 *
	 * @return void
	 */
	public function testEveryValueLabelMatchesTheSchemaEnum(): void {
		$register   = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/lib/Settings/learniq_register.json'), true);
		$schemas    = array_column($register['components']['schemas'], null, 'slug');
		$enumOf     = static fn (string $schema, string $property): array => $schemas[$schema]['properties'][$property]['enum'];
		$labelSets  = [
			[PortalValueLabels::EXCUSE_STATUS, 'excuse-request', 'lifecycle'],
			[PortalValueLabels::ABSENCE_KIND, 'excuse-request', 'reasonKind'],
			[PortalValueLabels::ATTENDANCE_STATUS, 'attendance-record', 'status'],
			[PortalValueLabels::SIGNUP_STATUS, 'conference-signup', 'lifecycle'],
			[PortalValueLabels::SLOT_STATUS, 'conference-slot', 'lifecycle'],
			[PortalValueLabels::WERKPROCES_ASSESSMENT, 'werkproces-assessment', 'assessment'],
		];
		foreach ($labelSets as [$labels, $schema, $property]) {
			self::assertSame($enumOf($schema, $property), array_keys($labels), $schema.'.'.$property);
		}
	}//end testEveryValueLabelMatchesTheSchemaEnum()

	/**
	 * Without a translator the manifest is returned untouched.
	 *
	 * @return void
	 */
	public function testWithoutATranslatorTheManifestStaysEnglish(): void {
		$manifest = ['label' => 'My children', 'columns' => [['field' => 'givenName', 'label' => 'First name']]];

		self::assertSame($manifest, (new PortalLabelTranslator())->translate(manifest: $manifest));
	}//end testWithoutATranslatorTheManifestStaysEnglish()
}//end class
