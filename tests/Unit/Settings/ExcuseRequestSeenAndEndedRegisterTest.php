<?php

/**
 * The shipped excuse-request fragment holds the seen stamp, lesson hours and
 * an open illness (absence-reports-seen-and-ended).
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
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
 * @spec openspec/changes/absence-reports-seen-and-ended/specs/attendance/spec.md#requirement-a-report-may-cover-lesson-hours-and-an-illness-may-stay-open-until-the-pupil-is-better
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Validates excuse-request payloads against the shipped fragment.
 */
class ExcuseRequestSeenAndEndedRegisterTest extends TestCase {

	private const LEARNER_REF = 'b0000000-0000-4000-8000-000000000001';

	/**
	 * The validatable ExcuseRequest schema, as JSON.
	 *
	 * @return string
	 */
	private function schema(): string {
		$schemas = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true)['components']['schemas'];

		return (string)json_encode($this->validatable(schema: $schemas['ExcuseRequest']));
	}//end schema()

	/**
	 * Whether a payload passes the shipped schema.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return bool
	 */
	private function passes(array $payload): bool {
		return (new Validator())->validate(json_decode((string)json_encode($payload)), $this->schema())->isValid();
	}//end passes()

	/**
	 * A whole-day report of a parent.
	 *
	 * @return array<string, mixed>
	 */
	private static function report(): array {
		return ['learnerRef' => self::LEARNER_REF, 'dateFrom' => '2026-10-05', 'dateTo' => '2026-10-05', 'reason' => 'Koorts', 'reasonKind' => 'illness'];
	}//end report()

	/**
	 * A seen report and a report for lesson hours pass; hours outside the day's range do not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/absence-reports-seen-and-ended/specs/attendance/spec.md#scenario-juf-esra-opens-the-register
	 * @spec openspec/changes/absence-reports-seen-and-ended/specs/attendance/spec.md#scenario-noor-leaves-after-the-fourth-hour
	 */
	public function testTheSeenStampAndLessonHoursPass(): void {
		self::assertTrue($this->passes(self::report()));
		self::assertTrue($this->passes(array_merge(self::report(), ['seenBy' => 'esra', 'seenAt' => '2026-10-05T08:12:00+02:00'])));
		self::assertTrue($this->passes(array_merge(self::report(), ['reasonKind' => 'medical-appointment', 'fromLessonHour' => 5, 'toLessonHour' => 7])));
		self::assertFalse($this->passes(array_merge(self::report(), ['fromLessonHour' => 0])), 'control: hour 0 does not exist');
		self::assertFalse($this->passes(array_merge(self::report(), ['toLessonHour' => 'vijf'])), 'control: an hour is a number');
	}//end testTheSeenStampAndLessonHoursPass()

	/**
	 * An illness may stay open; every other reason names its last day.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/absence-reports-seen-and-ended/specs/attendance/spec.md#scenario-better-on-wednesday
	 */
	public function testOnlyAnIllnessMayStayOpen(): void {
		$open = self::report();
		unset($open['dateTo']);
		self::assertTrue($this->passes($open), 'ill, not better yet');

		$appointment = array_merge($open, ['reasonKind' => 'medical-appointment']);
		self::assertFalse($this->passes($appointment), 'an appointment names its last day');

		$noKind = $open;
		unset($noKind['reasonKind']);
		self::assertFalse($this->passes($noKind), 'control: a report needs its reason');
	}//end testOnlyAnIllnessMayStayOpen()

	/**
	 * The schema without OpenRegister's own keys, which a JSON Schema validator cannot resolve.
	 *
	 * @param array<string, mixed> $schema A register schema.
	 *
	 * @return array<string, mixed>
	 */
	private function validatable(array $schema): array {
		$clean = [];
		foreach ($schema as $key => $value) {
			if ($key === '$ref' || $key === 'authorization' || $key === '_comment' || str_starts_with((string)$key, 'x-') === true || in_array($key, ['slug', 'icon', 'version'], true) === true) {
				continue;
			}

			$clean[$key] = $value;
			if (is_array($value) === true && $key !== 'required' && $key !== 'enum') {
				$clean[$key] = $this->validatable(schema: $value);
			}
		}

		return $clean;
	}//end validatable()
}//end class
