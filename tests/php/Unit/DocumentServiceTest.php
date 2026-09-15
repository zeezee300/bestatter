<?php

declare(strict_types=1);

namespace OCA\Bestatter\Tests\Unit;

use OCA\Bestatter\Service\DocumentService;
use OCA\Bestatter\Service\ConfigurationService;

final class DocumentServiceTest extends ServiceTestCase {
	public function testOrderDocumentKeysFollowCurrentMode(): void {
		$service = $this->withoutConstructor(DocumentService::class);
		self::assertSame(['BESTATTUNGSAUFTRAG'], $service->orderDocumentKeys(['masterData' => ['order_mode' => 'KVA']]));
		self::assertSame(['BESTATTUNGSAUFTRAG', 'BESTATTUNGSVOLLMACHT'], $service->orderDocumentKeys(['masterData' => ['order_mode' => 'A']]));
	}

	public function testQuotePlaceholderDataUsesQuoteTitleAndValidity(): void {
		$service = $this->withoutConstructor(DocumentService::class);
		$configuration = $this->createMock(ConfigurationService::class);
		$configuration->method('branchByKey')->willReturn(null);
		$this->setProperty($service, 'configuration', $configuration);
		$case = [
			'id' => 12,
			'caseNumber' => '2026-0001',
			'firstName' => 'Maria',
			'lastName' => 'Beispiel',
			'dateOfDeath' => '2026-08-22',
			'funeralType' => 'Feuerbestattung',
			'masterData' => ['order_mode' => 'KVA', 'kva_valid_until' => '2026-09-22'],
		];
		$costing = [
			'totals' => ['netCents' => 13000, 'vatCents' => 1900, 'grossCents' => 14900],
			'items' => [
				['positionType' => 'EL', 'netCents' => 10000, 'grossCents' => 11900],
				['positionType' => 'DP', 'netCents' => 3000, 'grossCents' => 3000],
			],
		];

		$placeholders = $this->invoke($service, 'placeholderData', $case, $costing);
		self::assertSame('Kostenvoranschlag', $placeholders['{{order.document_title}}']);
		self::assertSame('2026-09-22', $placeholders['{{order.workflow_detail}}']);
		self::assertSame('149,00 EUR', $placeholders['{{order.total_gross}}']);
		self::assertSame('100,00 EUR', $placeholders['{{order.taxable_net}}']);
		self::assertSame('30,00 EUR', $placeholders['{{order.pass_through_total}}']);
		self::assertSame('Voraussichtliche Leistungen und Kosten', $placeholders['{{order.services_heading}}']);
		self::assertSame('Voraussichtlicher Gesamtbetrag', $placeholders['{{order.total_gross_label}}']);
		self::assertStringContainsString('Schätzbeträge', $placeholders['{{order.cost_note}}']);
		self::assertStringContainsString('wesentliche Kostenabweichung', $placeholders['{{order.cost_note}}']);
	}

	public function testQuoteServiceRowsUseEstimateLabelsAndOmitEmptyGroups(): void {
		$service = $this->withoutConstructor(DocumentService::class);
		$xml = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:tbl>'
			. '<w:tr><w:tc><w:p><w:r><w:t>{{service.group_heading}}</w:t></w:r></w:p></w:tc></w:tr>'
			. '<w:tr><w:tc><w:p><w:r><w:t>{{service.title}}</w:t></w:r></w:p></w:tc></w:tr>'
			. '<w:tr><w:tc><w:p><w:r><w:t>{{service.group_subtotal}} {{service.group_subtotal_amount}}</w:t></w:r></w:p></w:tc></w:tr>'
			. '</w:tbl></w:body></w:document>';
		$result = $this->invoke($service, 'replaceServiceRows', $xml, [[
			'title' => 'Überführung', 'positionType' => 'FK', 'grossCents' => 11900,
		]], true);
		self::assertStringContainsString('Voraussichtliche Fremdleistungen und Fremdkosten', $result);
		self::assertStringNotContainsString('Eigene Leistungen', $result);
		self::assertStringNotContainsString('durchlaufende Posten', $result);
		self::assertStringContainsString('119,00 EUR', $result);
	}

	public function testOutputTitleRejectsPathTraversalCharacters(): void {
		$service = $this->withoutConstructor(DocumentService::class);
		$title = $this->invoke($service, 'safeOutputTitle', '../Rechnung/Entwurf');
		self::assertStringNotContainsString('/', $title);
		self::assertStringNotContainsString('..', $title);
	}

	public function testDocumentDatesDefaultToGermanAndSupportTemplateFormats(): void {
		$service = $this->withoutConstructor(DocumentService::class);
		self::assertSame('01.09.2026', $this->invoke($service, 'formatDocumentDate', '2026-09-01'));
		self::assertSame('2026-09-01', $this->invoke($service, 'formattedPlaceholder', '{{case.test_date|date:YYYY-MM-DD}}', ['{{case.test_date}}' => '01.09.2026']));
		self::assertSame('01.09.26', $this->invoke($service, 'formattedPlaceholder', '{{case.test_date|date:DD.MM.YY}}', ['{{case.test_date}}' => '2026-09-01']));
	}

	public function testDocxPlaceholderReplacementHandlesSplitRunsUtf8AndEmptyFields(): void {
		$service = $this->withoutConstructor(DocumentService::class);
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p>'
			. '<w:r><w:t>Name: {{case.first_</w:t></w:r><w:r><w:t>name}} {{case.last_name}}</w:t></w:r>'
			. '<w:r><w:t> · Religion: {{case.religion_disclosure}}</w:t></w:r>'
			. '<w:r><w:t> · Unbekannt: {{case.not_available}}</w:t></w:r>'
			. '</w:p></w:body></w:document>';

		$result = $this->invoke($service, 'replaceTextPlaceholders', $xml, [
			'{{case.first_name}}' => 'Jörg',
			'{{case.last_name}}' => 'Müller & Söhne',
			'{{case.religion_disclosure}}' => '',
		]);

		$document = new \DOMDocument();
		self::assertTrue($document->loadXML($result));
		self::assertSame('Name: Jörg Müller & Söhne · Religion:  · Unbekannt: ', $document->textContent);
		self::assertStringNotContainsString('{{', $result);
	}
}
