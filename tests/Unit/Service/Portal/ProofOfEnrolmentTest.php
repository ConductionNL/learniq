<?php

/**
 * A learner's proof of enrolment: only her active enrolment is proved, the
 * render answer must hold a PDF, and the PDF is kept on that enrolment under
 * the title of this school year.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Service\Portal
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
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-a-pupil-asks-for-a-proof-of-enrolment
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service\Portal;

use OCA\Learniq\Service\Portal\PortalLearner;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCA\Learniq\Service\Portal\ProofOfEnrolment;
use OCA\Learniq\Service\Portal\ProofOfEnrolmentPdf;
use OCA\Learniq\Support\FleetAppId;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ProofOfEnrolment.
 */
#[CoversClass(ProofOfEnrolment::class)]
#[UsesClass(PortalLearner::class)]
#[UsesClass(PortalOutcome::class)]
#[UsesClass(FleetAppId::class)]
class ProofOfEnrolmentTest extends TestCase {

	/**
	 * What the render call was sent.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $sent = null;

	/**
	 * The uploads kept, with the enrolment they were kept on.
	 *
	 * @var array<int, array{object: mixed, upload: FileUpload}>
	 */
	private array $kept = [];

	/**
	 * A service over these enrolments, answering the render with this body.
	 *
	 * @param array<int, array<string, mixed>> $enrolments What the store answers.
	 * @param string|RuntimeException          $render     The render answer's body, or what the call throws.
	 * @param int                              $now        Today, as a timestamp.
	 * @param string                           $token      The render credential.
	 *
	 * @return ProofOfEnrolment
	 */
	private function service(array $enrolments, string|RuntimeException $render='%PDF-1.7 proof', int $now=1794268800, string $token='secret'): ProofOfEnrolment {
		$store = $this->createMock(ObjectService::class);
		$store->method('findAll')->willReturn($enrolments);
		$store->method('find')->willReturnCallback(
			function (string|int $id): ?ObjectEntity {
				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('getUuid')->willReturn((string)$id);
				return $entity;
			}
		);

		$files = $this->createMock(FileService::class);
		$files->method('saveFile')->willReturnCallback(
			function (ObjectEntity $objectEntity, FileUpload $upload): File {
				$this->kept[] = ['object' => $objectEntity->getUuid(), 'upload' => $upload];
				return $this->createMock(File::class);
			}
		);

		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			function (string $url, array $options) use ($render): IResponse {
				$this->sent = ['url' => $url] + $options;
				if ($render instanceof RuntimeException) {
					throw $render;
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getBody')->willReturn($render);
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://school.example'.$path);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(true);
		$apps->method('getInstalledApps')->willReturn(['filinq']);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($token);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params=[]): string => 'nl:'.vsprintf($text, $params));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$factory->method('getUserLanguage')->willReturn('nl');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);

		$logger = $this->createMock(LoggerInterface::class);

		return new ProofOfEnrolment(
			objectService: $store,
			pdf: new ProofOfEnrolmentPdf(
				objectService: $store,
				fileService: $files,
				clientService: $clients,
				urlGenerator: $urls,
				appManager: $apps,
				appConfig: $config,
				logger: $logger
			),
			l10nFactory: $factory,
			time: $time,
			logger: $logger
		);
	}//end service()

	/**
	 * Noor, as the portal proved her.
	 *
	 * @return PortalLearner
	 */
	private function noor(): PortalLearner {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Noor de Vries');
		return new PortalLearner(profileRef: 'noor', ncUserId: 'noor', tenantId: 't-1', user: $user);
	}//end noor()

	/**
	 * Noor, in 4 havo for 2026-2027, asks for a proof: her active enrolment is
	 * rendered and the PDF kept on it as "Bewijs van inschrijving, schooljaar
	 * 2026-2027"; her completed enrolment and another pupil's are not used.
	 *
	 * @return void
	 */
	public function testHerActiveEnrolmentIsRenderedAndKept(): void {
		$outcome = $this->service(
			[
				['id' => 'enr-old', 'learnerRef' => 'noor', 'lifecycle' => 'completed', 'inschrijvingDate' => '2027-01-01'],
				['id' => 'enr-other', 'learnerRef' => 'milan', 'lifecycle' => 'active', 'inschrijvingDate' => '2027-02-01'],
				['id' => 'enr-1', 'learnerRef' => 'noor', 'lifecycle' => 'active', 'inschrijvingDate' => '2026-08-20', 'cohortName' => '4 havo'],
			]
		)->request($this->noor());

		self::assertSame(201, $outcome->status);
		self::assertNull($outcome->reason);
		self::assertSame('nl:Proof of enrolment, school year 2026-2027', $outcome->body['title']);
		self::assertCount(1, $this->kept);
		self::assertSame('enr-1', $this->kept[0]['object']);
		self::assertSame('nl:Proof of enrolment, school year 2026-2027.pdf', $this->kept[0]['upload']->fileName);
		self::assertSame('%PDF-1.7 proof', $this->kept[0]['upload']->content);
		self::assertFalse($this->kept[0]['upload']->share);
		self::assertSame('https://school.example/index.php/apps/filinq/api/v1/documents/render', $this->sent['url']);
		self::assertSame('proof-of-enrolment', $this->sent['json']['templateSlug']);
		self::assertSame('Noor de Vries', $this->sent['json']['data']['learnerName']);
		self::assertSame('4 havo', $this->sent['json']['data']['cohortName']);
		self::assertSame('2026-2027', $this->sent['json']['data']['schoolYear']);
		self::assertSame('Bearer secret', $this->sent['headers']['Authorization']);
	}//end testHerActiveEnrolmentIsRenderedAndKept()

	/**
	 * Without an active enrolment she is refused in words, and nothing is rendered or kept.
	 *
	 * @return void
	 */
	public function testWithoutAnActiveEnrolmentSheIsRefused(): void {
		$service = $this->service([['id' => 'enr-old', 'learnerRef' => 'noor', 'lifecycle' => 'withdrawn']]);
		$outcome = $service->request($this->noor());

		self::assertSame(409, $outcome->status);
		self::assertSame('no-active-enrolment', $outcome->reason);
		self::assertNull($this->sent);
		self::assertSame([], $this->kept);
		self::assertStringStartsWith('nl:You have no active enrolment this school year', $service->message('no-active-enrolment', null));
	}//end testWithoutAnActiveEnrolmentSheIsRefused()

	/**
	 * A render that fails, answers no PDF, or cannot be sent for want of a
	 * credential keeps nothing and answers 502.
	 *
	 * @return void
	 */
	public function testAFailedRenderKeepsNothing(): void {
		$enrolment = [['id' => 'enr-1', 'learnerRef' => 'noor', 'lifecycle' => 'active']];
		foreach ([new RuntimeException('down'), '{"documentRef":"doc-1"}', 'not a pdf'] as $render) {
			$outcome = $this->service(enrolments: $enrolment, render: $render)->request($this->noor());
			self::assertSame(502, $outcome->status);
			self::assertSame('render-failed', $outcome->reason);
		}

		$this->sent = null;
		$outcome    = $this->service(enrolments: $enrolment, token: '')->request($this->noor());
		self::assertSame(502, $outcome->status);
		self::assertNull($this->sent, 'Without a credential nothing is sent');
		self::assertSame([], $this->kept);
	}//end testAFailedRenderKeepsNothing()

	/**
	 * The school year turns on the first of August.
	 *
	 * @return void
	 */
	public function testTheSchoolYearStartsInAugust(): void {
		self::assertSame('2025-2026', $this->service(enrolments: [], now: strtotime('2026-07-31 12:00 UTC'))->schoolYear());
		self::assertSame('2026-2027', $this->service(enrolments: [], now: strtotime('2026-08-01 12:00 UTC'))->schoolYear());
		self::assertSame('2026-2027', $this->service(enrolments: [], now: strtotime('2027-01-15 12:00 UTC'))->schoolYear());
	}//end testTheSchoolYearStartsInAugust()

	/**
	 * The PDF is the body itself or JSON's base64 content; anything else is no PDF.
	 *
	 * @return void
	 */
	public function testThePdfInARenderAnswer(): void {
		self::assertSame('%PDF-1.4 x', ProofOfEnrolmentPdf::pdfFrom('%PDF-1.4 x'));
		self::assertSame('%PDF-1.4 y', ProofOfEnrolmentPdf::pdfFrom(json_encode(['content' => base64_encode('%PDF-1.4 y')])));
		self::assertNull(ProofOfEnrolmentPdf::pdfFrom(json_encode(['content' => base64_encode('<html>')])));
		self::assertNull(ProofOfEnrolmentPdf::pdfFrom(json_encode(['content' => '***'])));
		self::assertNull(ProofOfEnrolmentPdf::pdfFrom('{"documentRef":"d"}'));
	}//end testThePdfInARenderAnswer()
}//end class
