<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCA\Bestatter\Service\AuditService;
use OCA\Bestatter\Service\CaseService;
use OCA\Bestatter\Service\ContactService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * 0.86.0 – Kontakte: Dublettenprüfung vor dem Anlegen und Übernahme einer Person des Falls (Auftraggeber/in oder
 * „nur für diesen Fall“ erfasste Person) ins gemeinsame Adressbuch. Die Übernahme geschieht nur auf ausdrücklichen
 * Wunsch; ohne $force und ohne $uri werden zuerst mögliche Dubletten zurückgegeben.
 */
class ContactApiController extends ApiController {
	public function __construct(string $appName, IRequest $request, private ContactService $contacts, private CaseService $cases, private AuditService $audit) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function duplicates(string $title = '', string $data = '{}'): DataResponse {
		$values = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
		return new DataResponse(['duplicates' => $this->contacts->duplicates($title, is_array($values) ? $values : [])]);
	}

	#[NoAdminRequired]
	public function savePerson(int $caseId, string $person = '{}', string $uri = '', bool $force = false): DataResponse {
		$this->cases->getCase($caseId); // Fall muss existieren und sichtbar sein
		$values = json_decode($person, true, 512, JSON_THROW_ON_ERROR);
		$values = is_array($values) ? $values : [];
		if ($uri === '' && !$force) {
			$title = trim((string)($values['firstName'] ?? '') . ' ' . (string)($values['lastName'] ?? ''));
			$duplicates = $this->contacts->duplicates($title, $values);
			if ($duplicates !== []) return new DataResponse(['status' => 'DUPLICATES', 'duplicates' => $duplicates]);
		}
		$contact = $this->contacts->savePerson($values, $uri);
		$this->audit->log($caseId, 'CASE_CONTACT', null, $uri === '' ? 'ADDRESS_BOOK_CREATED' : 'ADDRESS_BOOK_UPDATED', null, ['contactId' => $contact['id'], 'title' => $contact['title'], 'addressBook' => $contact['data']['addressBook'] ?? '', 'role' => (string)($values['role'] ?? '')]);
		return new DataResponse(['status' => 'SAVED', 'contact' => $contact], $uri === '' ? 201 : 200);
	}
}
