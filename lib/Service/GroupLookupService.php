<?php

declare(strict_types=1);

namespace OCA\Bestatter\Service;

use OCP\IGroupManager;

/**
 * 0.83.2 – Nextcloud-Gruppen werden über ihre Gruppen-ID angesprochen. Benennt man eine Gruppe in der
 * Benutzerverwaltung um, ändert sich nur der Anzeigename; die ID bleibt. Diese Klasse erkennt den Fall und schlägt
 * die passende Gruppen-ID vor (nur lesend, keine automatische Übernahme).
 */
class GroupLookupService {
	public function __construct(private IGroupManager $groups) {}

	/** @return array{name: string, exists: bool, suggestion: string, suggestionName: string} */
	public function lookup(string $name): array {
		$name = trim($name);
		$result = ['name' => $name, 'exists' => false, 'suggestion' => '', 'suggestionName' => ''];
		if ($name === '') return $result;
		if ($this->groups->get($name) !== null) { $result['exists'] = true; return $result; }
		$wanted = mb_strtolower($name);
		foreach ($this->groups->search($name) as $group) {
			// Gleicher Anzeigename (umbenannte Gruppe) oder gleiche ID in anderer Schreibweise.
			if (mb_strtolower(trim($group->getDisplayName())) === $wanted || mb_strtolower($group->getGID()) === $wanted) {
				$result['suggestion'] = $group->getGID();
				$result['suggestionName'] = $group->getDisplayName();
				break;
			}
		}
		return $result;
	}

	/** Klartext für eine nicht gefundene Gruppe, leer wenn die Gruppe existiert. */
	public function missingText(string $name): string {
		$lookup = $this->lookup($name);
		if ($lookup['exists'] || $lookup['name'] === '') return '';
		$text = 'Nextcloud-Gruppe „' . $lookup['name'] . '“ nicht gefunden';
		if ($lookup['suggestion'] !== '') {
			$text .= mb_strtolower($lookup['suggestion']) === mb_strtolower($lookup['name'])
				? ' – es gibt sie mit der Gruppen-ID „' . $lookup['suggestion'] . '“ (andere Groß-/Kleinschreibung); diese ID eintragen'
				: ' – eine Gruppe mit diesem Anzeigenamen hat die Gruppen-ID „' . $lookup['suggestion'] . '“ (umbenannte Gruppe); diese ID eintragen';
		}
		return $text;
	}
}
