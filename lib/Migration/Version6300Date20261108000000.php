<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.91.1 – Gesprächsnotiz: Die Werteliste „Kategorie der Notiz“ bekommt den Wert „Gesprächsnotiz“ (Gesprächsnotiz aus
 * der Schnellerfassung). Neue Installationen erhalten ihn über resources/initial-customizing.json; hier wird er nur in
 * eine bereits angelegte Liste eingefügt (an erster Stelle), wenn er dort noch fehlt. Gepflegte Werte bleiben unberührt.
 */
class Version6300Date20261108000000 extends SimpleMigrationStep {
	private const LIST_KEY = 'NOTE_CATEGORY';
	private const VALUE = 'Gesprächsnotiz';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$lookup = $this->db->getQueryBuilder();
		$listId = $lookup->select('id')->from('bestatter_choice_lists')
			->where($lookup->expr()->eq('list_key', $lookup->createNamedParameter(self::LIST_KEY)))
			->executeQuery()->fetchOne();
		if ($listId === false) {
			return; // Liste noch nicht angelegt: kommt vollständig aus resources/initial-customizing.json.
		}
		$exists = $this->db->getQueryBuilder();
		$found = $exists->select('id')->from('bestatter_choice_items')
			->where($exists->expr()->eq('list_id', $exists->createNamedParameter((int)$listId)))
			->andWhere($exists->expr()->eq('value', $exists->createNamedParameter(self::VALUE)))
			->executeQuery()->fetchOne();
		if ($found !== false) {
			return;
		}
		$insert = $this->db->getQueryBuilder();
		$insert->insert('bestatter_choice_items')->values([
			'list_id' => $insert->createNamedParameter((int)$listId),
			'value' => $insert->createNamedParameter(self::VALUE),
			'label' => $insert->createNamedParameter(self::VALUE),
			'sort_order' => $insert->createNamedParameter(0),
			'parent_item_id' => $insert->createNamedParameter(null),
			'metadata' => $insert->createNamedParameter(null),
		])->executeStatement();
		$output->info('Werteliste „Kategorie der Notiz“: Wert „Gesprächsnotiz“ ergänzt.');
	}
}
