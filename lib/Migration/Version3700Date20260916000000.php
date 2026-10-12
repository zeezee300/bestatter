<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Additive groundwork for guided burial choices and configurable surcharge tiers. */
class Version3700Date20260916000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('bestatter_choice_items')) {
			$items = $schema->getTable('bestatter_choice_items');
			if (!$items->hasColumn('parent_item_id')) $items->addColumn('parent_item_id', 'integer', ['unsigned' => true, 'notnull' => false]);
			if (!$items->hasColumn('metadata')) $items->addColumn('metadata', 'text', ['notnull' => false]);
			if (!$items->hasIndex('bestatter_choice_parent')) $items->addIndex(['parent_item_id'], 'bestatter_choice_parent');
			// Keep this relationship application-validated: a self-FK is not reliably prefix-aware on MariaDB migrations.
		}
		if ($schema->hasTable('bestatter_cases')) {
			$cases = $schema->getTable('bestatter_cases');
			foreach (['burial_variant_code' => ['string', ['length' => 120, 'notnull' => false]], 'with_funeral_ceremony' => ['boolean', ['notnull' => false]], 'pickup_time' => ['string', ['length' => 40, 'notnull' => false]], 'body_height_cm' => ['integer', ['notnull' => false]], 'body_weight_kg' => ['integer', ['notnull' => false]]] as $name => [$type, $settings]) {
				if (!$cases->hasColumn($name)) $cases->addColumn($name, $type, $settings);
			}
		}
		if (!$schema->hasTable('bestatter_surcharge_rules')) {
			$rules = $schema->createTable('bestatter_surcharge_rules');
			$rules->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
			$rules->addColumn('rule_key', 'string', ['length' => 100]);
			$rules->addColumn('label', 'string', ['length' => 180]);
			$rules->addColumn('dimension', 'string', ['length' => 24]);
			$rules->addColumn('threshold_from', 'integer', ['notnull' => false]);
			$rules->addColumn('threshold_to', 'integer', ['notnull' => false]);
			$rules->addColumn('article_id', 'integer', ['unsigned' => true, 'notnull' => false]);
			$rules->addColumn('active', 'boolean', ['default' => false]);
			$rules->addColumn('sort_order', 'integer', ['default' => 0]);
			$rules->setPrimaryKey(['id']);
			$rules->addUniqueIndex(['rule_key'], 'bestatter_surcharge_key');
			$rules->addIndex(['dimension', 'sort_order'], 'bestatter_surcharge_dim');
		}
		if (!$schema->hasTable('bestatter_burial_variant_rules')) {
			$rules = $schema->createTable('bestatter_burial_variant_rules');
			$rules->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
			$rules->addColumn('variant_code', 'string', ['length' => 120]);
			$rules->addColumn('rule_type', 'string', ['length' => 48]);
			$rules->addColumn('article_group', 'string', ['length' => 100, 'notnull' => false]);
			$rules->addColumn('article_id', 'integer', ['unsigned' => true, 'notnull' => false]);
			$rules->addColumn('default_article_id', 'integer', ['unsigned' => true, 'notnull' => false]);
			$rules->addColumn('note', 'text', ['notnull' => false]);
			$rules->addColumn('active', 'boolean', ['default' => false]);
			$rules->addColumn('sort_order', 'integer', ['default' => 0]);
			$rules->setPrimaryKey(['id']);
			$rules->addIndex(['variant_code', 'active'], 'bestatter_variant_rule_variant');
		}
		if (!$schema->hasTable('bestatter_case_automation')) {
			$automation = $schema->createTable('bestatter_case_automation');
			$automation->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
			$automation->addColumn('case_id', 'integer', ['unsigned' => true]);
			$automation->addColumn('trigger_key', 'string', ['length' => 100]);
			$automation->addColumn('record_id', 'integer', ['unsigned' => true, 'notnull' => false]);
			$automation->addColumn('created_at', 'string', ['length' => 40]);
			$automation->setPrimaryKey(['id']);
			$automation->addUniqueIndex(['case_id', 'trigger_key'], 'bestatter_case_auto_unique');
		}
		return $schema;
	}
}
