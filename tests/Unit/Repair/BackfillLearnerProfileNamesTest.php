<?php

/**
 * Tests for BackfillLearnerProfileNames.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Repair
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
 * @spec openspec/changes/pupils-read-by-name/specs/school-structure/spec.md#requirement-a-pupil-reads-by-name
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Repair;

use OCA\Learniq\Repair\BackfillLearnerProfileNames;
use OCA\Learniq\Tests\Support\RegisterFaithfulStore;
use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for BackfillLearnerProfileNames::run(), over a store that hydrates
 * `@self.name` from the shipped register the way OpenRegister does.
 */
class BackfillLearnerProfileNamesTest extends TestCase {
	use RegisterSchemaPayloads;

	private const TENANT = '00000000-0000-4000-8000-000000000000';

	/**
	 * The fake OpenRegister store.
	 *
	 * @var RegisterFaithfulStore
	 */
	private RegisterFaithfulStore $store;

	/**
	 * Every _rbac/_multitenancy pair the step passed to a save.
	 *
	 * @var array<int, array{rbac: bool, multitenancy: bool, validation: bool}>
	 */
	private array $saveFlags = [];

	/**
	 * Lines the step reported.
	 *
	 * @var array<int, string>
	 */
	private array $messages = [];

	/**
	 * A stored learner profile, as the primary school set holds one.
	 *
	 * @param string               $id    The uuid.
	 * @param array<string, mixed> $extra Fields to add or override.
	 *
	 * @return array<string, mixed>
	 */
	private static function profile(string $id, array $extra = []): array {
		return array_merge(
			[
				'id'        => $id,
				'ncUserId'  => 'po-leerling-' . $id,
				'givenName' => 'Vera',
				'familyName' => 'Hulstkamp',
				'roles'     => ['learner'],
				'lifecycle' => 'active',
				'tenant_id' => self::TENANT,
			],
			$extra
		);
	}//end profile()

	/**
	 * Build the step over the fake store.
	 *
	 * @param array<int, array<string, mixed>> $profiles    The stored profiles.
	 * @param string|null                      $failSaveFor A uuid whose save throws.
	 *
	 * @return BackfillLearnerProfileNames
	 */
	private function makeStep(array $profiles, ?string $failSaveFor = null): BackfillLearnerProfileNames {
		$this->store = new RegisterFaithfulStore();
		$this->store->rows = ['learner-profile' => $profiles];

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			fn (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array => $this->store->findAll($config, $_rbac, $_multitenancy)
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true, bool $silent = false, bool $_validation = true) use ($failSaveFor) {
				if ($uuid === $failSaveFor) {
					throw new RuntimeException('locked');
				}

				// OpenRegister refuses a null inside a nested object, whatever
				// the fragment's `nullable` says; only an unvalidated save
				// writes such a stored profile.
				if ($_validation === true && in_array(null, (array)($object['beeldmateriaalConsent'] ?? []), true) === true) {
					throw new RuntimeException("Property 'beeldmateriaalConsent.website' should be type 'boolean' but is 'null'.");
				}

				$this->saveFlags[] = ['rbac' => $_rbac, 'multitenancy' => $_multitenancy, 'validation' => $_validation];
				return $this->store->save((string)$schema, $object, $uuid);
			}
		);

		return new BackfillLearnerProfileNames(objectService: $objectService, logger: new NullLogger());
	}//end makeStep()

	/**
	 * A repair output that records what the step reports.
	 *
	 * @return IOutput
	 */
	private function recorder(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $message): void {
			$this->messages[] = $message;
		});

		return $output;
	}//end recorder()

	/**
	 * The name a profile reads by, after the step ran.
	 *
	 * @param string $id The uuid.
	 *
	 * @return string
	 */
	private function nameOf(string $id): string {
		$found = $this->store->findAll(['filters' => ['register' => 'learniq', 'schema' => 'learner-profile'], 'ids' => [$id]]);
		self::assertCount(1, $found);

		return (string)$found[0]->jsonSerialize()['@self']['name'];
	}//end nameOf()

	/**
	 * A stored profile reads by its uuid until the step saves it; then by name.
	 *
	 * @return void
	 */
	public function testAStoredProfileReadsByNameAfterTheStep(): void {
		$step = $this->makeStep([self::profile('p-147')]);
		self::assertSame('p-147', $this->nameOf(id: 'p-147'));

		$step->run($this->recorder());

		self::assertSame('Vera Hulstkamp', $this->nameOf(id: 'p-147'));
	}//end testAStoredProfileReadsByNameAfterTheStep()

	/**
	 * What the step saves is the stored profile unchanged, minus `@self`, and
	 * it fits the shipped learner-profile schema.
	 *
	 * @return void
	 */
	public function testTheSavedPayloadIsTheStoredProfileAndFitsTheSchema(): void {
		$stored = self::profile('p-147', ['@self' => ['owner' => 'someone', 'name' => 'p-147']]);
		$this->makeStep([$stored])->run($this->recorder());

		self::assertCount(1, $this->store->saves);
		$saved = $this->store->saves[0]['object'];
		self::assertArrayNotHasKey('@self', $saved);
		unset($stored['@self']);
		self::assertSame($stored, $saved);
		self::assertSame('p-147', $this->store->saves[0]['uuid']);

		unset($saved['id']);
		self::assertNull(self::schemaError(slug: 'learner-profile', payload: $saved));
	}//end testTheSavedPayloadIsTheStoredProfileAndFitsTheSchema()

	/**
	 * A session-less step reads and writes past RBAC and multitenancy, so a
	 * profile of another tenant, and a merged or deleted one, is named too.
	 *
	 * @return void
	 */
	public function testItReadsAndWritesWithoutASession(): void {
		$this->makeStep(
			[
				self::profile('p-merged', ['lifecycle' => 'merged']),
				self::profile('p-other', ['tenant_id' => '11111111-1111-4111-8111-111111111111']),
			]
		)->run($this->recorder());

		foreach ($this->store->reads as $read) {
			self::assertFalse($read['rbac']);
			self::assertFalse($read['multitenancy']);
		}

		self::assertSame(
			[
				['rbac' => false, 'multitenancy' => false, 'validation' => false],
				['rbac' => false, 'multitenancy' => false, 'validation' => false],
			],
			$this->saveFlags
		);
		self::assertSame('Vera Hulstkamp', $this->nameOf(id: 'p-merged'));
		self::assertSame('Vera Hulstkamp', $this->nameOf(id: 'p-other'));
	}//end testItReadsAndWritesWithoutASession()

	/**
	 * A profile whose undecided image consent holds nulls is named too: the
	 * step writes the stored profile unchanged and does not validate it again.
	 *
	 * @return void
	 */
	public function testAProfileWithUndecidedConsentIsNamed(): void {
		$consent = ['website' => null, 'socialMedia' => null, 'schoolgids' => true, 'classPhoto' => null, 'video' => null];
		$this->makeStep([self::profile('p-453', ['beeldmateriaalConsent' => $consent])])->run($this->recorder());

		self::assertSame('Vera Hulstkamp', $this->nameOf(id: 'p-453'));
		self::assertSame($consent, $this->store->saves[0]['object']['beeldmateriaalConsent']);
		self::assertContains('BackfillLearnerProfileNames: 1 named, 0 failed, of 1 scanned.', $this->messages);
	}//end testAProfileWithUndecidedConsentIsNamed()

	/**
	 * A second run saves nothing.
	 *
	 * @return void
	 */
	public function testASecondRunSavesNothing(): void {
		$step = $this->makeStep([self::profile('p-1'), self::profile('p-2', ['givenName' => 'Joep', 'familyName' => 'Duinlaan'])]);
		$step->run($this->recorder());
		self::assertCount(2, $this->store->saves);

		$step->run($this->recorder());

		self::assertCount(2, $this->store->saves);
		self::assertContains('BackfillLearnerProfileNames: 0 named, 0 failed, of 2 scanned.', $this->messages);
	}//end testASecondRunSavesNothing()

	/**
	 * A profile without a given or family name is left alone: there is no
	 * name to give it.
	 *
	 * @return void
	 */
	public function testAProfileWithoutNamesIsLeftAlone(): void {
		$this->makeStep([self::profile('p-empty', ['givenName' => null, 'familyName' => ' '])])->run($this->recorder());

		self::assertSame([], $this->store->saves);
	}//end testAProfileWithoutNamesIsLeftAlone()

	/**
	 * A profile with only a family name reads by it, without stray spaces.
	 *
	 * @return void
	 */
	public function testAProfileWithOneNameReadsByIt(): void {
		$this->makeStep([self::profile('p-one', ['givenName' => '', 'familyName' => ' Beekdal '])])->run($this->recorder());

		self::assertSame('Beekdal', $this->nameOf(id: 'p-one'));
	}//end testAProfileWithOneNameReadsByIt()

	/**
	 * A save that fails is counted and the next profile is still named.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsCountedAndTheRestContinue(): void {
		$this->makeStep([self::profile('p-broken'), self::profile('p-ok')], failSaveFor: 'p-broken')->run($this->recorder());

		self::assertSame('Vera Hulstkamp', $this->nameOf(id: 'p-ok'));
		self::assertContains('BackfillLearnerProfileNames: 1 named, 1 failed, of 2 scanned.', $this->messages);
	}//end testAFailedSaveIsCountedAndTheRestContinue()

	/**
	 * When OpenRegister cannot be read, nothing is written and the step ends.
	 *
	 * @return void
	 */
	public function testAFailedReadWritesNothing(): void {
		$step = $this->makeStep([self::profile('p-1')]);
		$this->store->failReads = 'database gone';
		$step->run($this->recorder());

		self::assertSame([], $this->store->saves);
	}//end testAFailedReadWritesNothing()

	/**
	 * Every page is read: more profiles than one page holds are all named.
	 *
	 * @return void
	 */
	public function testEveryPageIsRead(): void {
		$profiles = [];
		for ($i = 0; $i < 450; $i++) {
			$profiles[] = self::profile('p-' . $i);
		}

		$this->makeStep($profiles)->run($this->recorder());

		self::assertCount(450, $this->store->saves);
		self::assertSame('Vera Hulstkamp', $this->nameOf(id: 'p-449'));
	}//end testEveryPageIsRead()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testTheStepNamesItsWork(): void {
		self::assertStringContainsString('learner profile', $this->makeStep([])->getName());
	}//end testTheStepNamesItsWork()
}//end class
