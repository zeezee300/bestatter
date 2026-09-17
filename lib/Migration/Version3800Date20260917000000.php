<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Separate tree burial from woodland burial in the already seeded variant hierarchy. */
class Version3800Date20260917000000 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db) {}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('bestatter_cases')) $schema->getTable('bestatter_cases')->getColumn('funeral_type')->setLength(180);
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$q = $this->db->getQueryBuilder();
		$listId = $q->select('id')->from('bestatter_choice_lists')->where($q->expr()->eq('list_key', $q->createNamedParameter('BURIAL_VARIANT')))->executeQuery()->fetchOne();
		if ($listId === false) return; // Fresh installations receive the revised seed list.
		$items = [];
		$q = $this->db->getQueryBuilder();
		foreach ($q->select('id', 'value', 'parent_item_id')->from('bestatter_choice_items')->where($q->expr()->eq('list_id', $q->createNamedParameter((int)$listId)))->executeQuery()->fetchAllAssociative() as $item) $items[(string)$item['value']] = $item;
		if (!isset($items['TREE'])) {
			$q = $this->db->getQueryBuilder();
			$q->insert('bestatter_choice_items')->values([
				'list_id' => $q->createNamedParameter((int)$listId),
				'value' => $q->createNamedParameter('TREE'),
				'label' => $q->createNamedParameter('Baumbestattung'),
				'sort_order' => $q->createNamedParameter(4),
				'parent_item_id' => $q->createNamedParameter(null),
				'metadata' => $q->createNamedParameter('{"funeralScope":"CREMATION"}'),
			])->executeStatement();
			$items['TREE'] = ['id' => (int)$this->db->lastInsertId('bestatter_choice_items')];
		}
		if (!isset($items['FOREST'])) return;
		foreach (['FOREST_BASIC', 'FOREST_COMMUNITY'] as $code) {
			if (!isset($items[$code]) || (int)($items[$code]['parent_item_id'] ?? 0) !== (int)$items['FOREST']['id']) continue;
			$q = $this->db->getQueryBuilder();
			$q->update('bestatter_choice_items')->set('parent_item_id', $q->createNamedParameter((int)$items['TREE']['id']))->where($q->expr()->eq('id', $q->createNamedParameter((int)$items[$code]['id'])))->executeStatement();
		}
	}
}
