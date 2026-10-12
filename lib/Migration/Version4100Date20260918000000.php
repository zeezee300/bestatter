<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Consolidated schema baseline superseding the individual development-era
 * migrations Version1800 through Version4000. Creates the complete current
 * schema on a fresh installation and only adds whatever is still missing on
 * an existing development database that already ran the former migration
 * chain. Every operation is guarded with hasTable()/hasColumn()/hasIndex()
 * and stays idempotent either way.
 */
class Version4100Date20260918000000 extends SimpleMigrationStep {
	public function __construct(private IDBConnection $db) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$this->cases($schema);
		$this->choices($schema);
		$this->records($schema);
		$this->checklists($schema);
		$this->articles($schema);
		$this->caseServices($schema);
		$this->workflows($schema);
		$this->configuration($schema);
		$this->commercial($schema);
		$this->auditLog($schema);
		$this->assistantRules($schema);
		$this->incomingInvoices($schema);
		$this->scheduling($schema);
		$this->paperless($schema);
		$this->captureImports($schema);
		$this->countryProfiles($schema);
		$this->sideOrders($schema);
		$this->sideOrderLinks($schema);
		$this->surchargeAndVariantRules($schema);
		$this->mailOutbox($schema);

		return $schema;
	}

	/**
	 * Reproduces the net effect of Version3800's postSchemaChange(): on a
	 * database that was seeded (or previously migrated) with the
	 * BURIAL_VARIANT choice list under the old FOREST-only hierarchy, split
	 * tree burial into its own TREE choice item and re-parent the
	 * FOREST_BASIC / FOREST_COMMUNITY sub-items under it. Fresh
	 * installations receive the revised seed list from elsewhere (not from
	 * migrations), so bestatter_choice_lists has no BURIAL_VARIANT row yet
	 * at this point and the guard below makes this a safe no-op for them.
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$q = $this->db->getQueryBuilder();
		$listId = $q->select('id')->from('bestatter_choice_lists')
			->where($q->expr()->eq('list_key', $q->createNamedParameter('BURIAL_VARIANT')))
			->executeQuery()->fetchOne();
		if ($listId === false) {
			return;
		}

		$items = [];
		$q = $this->db->getQueryBuilder();
		foreach ($q->select('id', 'value', 'parent_item_id')->from('bestatter_choice_items')
			->where($q->expr()->eq('list_id', $q->createNamedParameter((int)$listId)))
			->executeQuery()->fetchAllAssociative() as $item) {
			$items[(string)$item['value']] = $item;
		}

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

		if (!isset($items['FOREST'])) {
			return;
		}
		foreach (['FOREST_BASIC', 'FOREST_COMMUNITY'] as $code) {
			if (!isset($items[$code]) || (int)($items[$code]['parent_item_id'] ?? 0) !== (int)$items['FOREST']['id']) {
				continue;
			}
			$q = $this->db->getQueryBuilder();
			$q->update('bestatter_choice_items')
				->set('parent_item_id', $q->createNamedParameter((int)$items['TREE']['id']))
				->where($q->expr()->eq('id', $q->createNamedParameter((int)$items[$code]['id'])))
				->executeStatement();
		}
	}

	private function cases(ISchemaWrapper $schema): void {
		if (!$schema->hasTable('bestatter_cases')) {
			$t = $schema->createTable('bestatter_cases');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('case_number', 'string', ['length' => 30]);
			$t->addColumn('creation_token', 'string', ['length' => 64, 'notnull' => false]);
			$t->addColumn('first_name', 'string', ['length' => 100]);
			$t->addColumn('last_name', 'string', ['length' => 100]);
			$t->addColumn('date_of_death', 'date', ['notnull' => false]);
			// Final length after Version3800 widened it from 100 to 180.
			$t->addColumn('funeral_type', 'string', ['length' => 180, 'notnull' => false]);
			$t->addColumn('status', 'string', ['length' => 40]);
			$t->addColumn('branch', 'string', ['length' => 100, 'notnull' => false]);
			$t->addColumn('responsible_employee', 'string', ['length' => 100, 'notnull' => false]);
			$t->addColumn('master_data', 'text', ['notnull' => false]);
			$t->addColumn('created_at', 'datetime');
			$t->addColumn('updated_at', 'datetime');
			// Retention/anonymization fields (from Version1800 currentExtensions).
			$t->addColumn('retention_hold', 'boolean', ['default' => false]);
			$t->addColumn('retention_due_at', 'date', ['notnull' => false]);
			$t->addColumn('anonymized_at', 'datetime', ['notnull' => false]);
			$t->addColumn('retention_hold_reason', 'text', ['notnull' => false]);
			$t->addColumn('retention_hold_responsible', 'string', ['length' => 64, 'notnull' => false]);
			$t->addColumn('retention_hold_set_at', 'string', ['length' => 40, 'notnull' => false]);
			$t->addColumn('retention_hold_review_at', 'date', ['notnull' => false]);
			// Guided burial fields (from Version3700).
			$t->addColumn('burial_variant_code', 'string', ['length' => 120, 'notnull' => false]);
			$t->addColumn('with_funeral_ceremony', 'boolean', ['notnull' => false]);
			$t->addColumn('pickup_time', 'string', ['length' => 40, 'notnull' => false]);
			$t->addColumn('body_height_cm', 'integer', ['notnull' => false]);
			$t->addColumn('body_weight_kg', 'integer', ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['case_number'], 'bestatter_case_number');
			$t->addUniqueIndex(['creation_token'], 'bestatter_case_create_token');
			$t->addIndex(['created_at', 'id'], 'bestatter_case_created');
			$t->addIndex(['status', 'created_at'], 'bestatter_case_status');
			$t->addIndex(['branch', 'created_at'], 'bestatter_case_branch');
			$t->addIndex(['date_of_death'], 'bestatter_case_death');
			$t->addIndex(['status', 'retention_hold', 'retention_due_at'], 'bestatter_case_retention');
			return;
		}

		// Upgrade path: add whatever is still missing on an existing install.
		$t = $schema->getTable('bestatter_cases');
		$this->columns($t, [
			'retention_hold' => ['boolean', ['default' => false]],
			'retention_due_at' => ['date', ['notnull' => false]],
			'anonymized_at' => ['datetime', ['notnull' => false]],
			'retention_hold_reason' => ['text', ['notnull' => false]],
			'retention_hold_responsible' => ['string', ['length' => 64, 'notnull' => false]],
			'retention_hold_set_at' => ['string', ['length' => 40, 'notnull' => false]],
			'retention_hold_review_at' => ['date', ['notnull' => false]],
			'burial_variant_code' => ['string', ['length' => 120, 'notnull' => false]],
			'with_funeral_ceremony' => ['boolean', ['notnull' => false]],
			'pickup_time' => ['string', ['length' => 40, 'notnull' => false]],
			'body_height_cm' => ['integer', ['notnull' => false]],
			'body_weight_kg' => ['integer', ['notnull' => false]],
		]);
		if (!$t->hasIndex('bestatter_case_retention')) {
			$t->addIndex(['status', 'retention_hold', 'retention_due_at'], 'bestatter_case_retention');
		}
	}

	private function choices(ISchemaWrapper $schema): void {
		if (!$schema->hasTable('bestatter_choice_lists')) {
			$t = $schema->createTable('bestatter_choice_lists');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('list_key', 'string', ['length' => 100]);
			$t->addColumn('name', 'string', ['length' => 180]);
			$t->addColumn('sort_order', 'integer', ['default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['list_key'], 'bestatter_choice_key');
		}

		if (!$schema->hasTable('bestatter_choice_items')) {
			$t = $schema->createTable('bestatter_choice_items');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('list_id', 'integer', ['unsigned' => true]);
			$t->addColumn('value', 'string', ['length' => 120]);
			$t->addColumn('label', 'string', ['length' => 180]);
			$t->addColumn('sort_order', 'integer', ['default' => 0]);
			// Self-referencing hierarchy support (from Version3700).
			$t->addColumn('parent_item_id', 'integer', ['unsigned' => true, 'notnull' => false]);
			$t->addColumn('metadata', 'text', ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['list_id'], 'bestatter_choice_list');
			$t->addIndex(['parent_item_id'], 'bestatter_choice_parent');
			return;
		}

		$t = $schema->getTable('bestatter_choice_items');
		$this->columns($t, [
			'parent_item_id' => ['integer', ['unsigned' => true, 'notnull' => false]],
			'metadata' => ['text', ['notnull' => false]],
		]);
		if (!$t->hasIndex('bestatter_choice_parent')) {
			$t->addIndex(['parent_item_id'], 'bestatter_choice_parent');
		}
	}

	private function records(ISchemaWrapper $schema): void {
		if ($schema->hasTable('bestatter_records')) {
			$t = $schema->getTable('bestatter_records');
			$this->columns($t, [
				'created_by' => ['string', ['length' => 64, 'notnull' => false]],
				'updated_by' => ['string', ['length' => 64, 'notnull' => false]],
			]);
			return;
		}

		$t = $schema->createTable('bestatter_records');
		$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
		$t->addColumn('case_id', 'integer', ['unsigned' => true, 'notnull' => false]);
		$t->addColumn('owner_uid', 'string', ['length' => 64, 'notnull' => false]);
		$t->addColumn('assignee_uid', 'string', ['length' => 64, 'notnull' => false]);
		$t->addColumn('assignee_name', 'string', ['length' => 180, 'notnull' => false]);
		$t->addColumn('record_type', 'string', ['length' => 30]);
		$t->addColumn('title', 'string', ['length' => 250]);
		$t->addColumn('record_date', 'string', ['length' => 30, 'notnull' => false]);
		$t->addColumn('status', 'string', ['length' => 40]);
		$t->addColumn('payload', 'text', ['notnull' => false]);
		foreach ([
			'nextcloud_uid' => 128,
			'nextcloud_calendar_key' => 128,
			'nextcloud_uri' => 255,
			'nextcloud_etag' => 255,
			'nextcloud_last_modified' => 40,
			'nextcloud_sync_hash' => 64,
		] as $name => $length) {
			$t->addColumn($name, 'string', ['length' => $length, 'notnull' => false]);
		}
		$t->addColumn('created_by', 'string', ['length' => 64, 'notnull' => false]);
		$t->addColumn('updated_by', 'string', ['length' => 64, 'notnull' => false]);
		$t->addColumn('created_at', 'string', ['length' => 40]);
		$t->addColumn('updated_at', 'string', ['length' => 40]);
		$t->setPrimaryKey(['id']);
		$t->addIndex(['record_type', 'case_id'], 'bestatter_record_type_case');
		$t->addUniqueIndex(['owner_uid', 'record_type', 'nextcloud_uid'], 'bestatter_record_nc_uid');
		$t->addIndex(['owner_uid', 'record_type', 'case_id'], 'bestatter_record_owner_type');
		$t->addIndex(['assignee_uid', 'record_type', 'status'], 'bestatter_record_assignee');
	}

	private function checklists(ISchemaWrapper $schema): void {
		if (!$schema->hasTable('bestatter_checklist_templates')) {
			$t = $schema->createTable('bestatter_checklist_templates');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('template_key', 'string', ['length' => 100]);
			$t->addColumn('name', 'string', ['length' => 200]);
			$t->addColumn('case_type', 'string', ['length' => 80]);
			$t->addColumn('description', 'text', ['notnull' => false]);
			$t->addColumn('active', 'boolean', ['default' => true]);
			$t->addColumn('sort_order', 'integer', ['default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['template_key'], 'bestatter_checklist_key');
		}
		if (!$schema->hasTable('bestatter_checklist_items')) {
			$t = $schema->createTable('bestatter_checklist_items');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('template_id', 'integer', ['unsigned' => true]);
			$t->addColumn('title', 'string', ['length' => 250]);
			$t->addColumn('description', 'text', ['notnull' => false]);
			$t->addColumn('due_offset_days', 'integer', ['default' => 0]);
			$t->addColumn('priority', 'string', ['length' => 30]);
			$t->addColumn('sort_order', 'integer', ['default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['template_id'], 'bestatter_checklist_template');
		}
	}

	private function articles(ISchemaWrapper $schema): void {
		if (!$schema->hasTable('bestatter_articles')) {
			$t = $schema->createTable('bestatter_articles');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('item_type', 'string', ['length' => 20, 'default' => 'SINGLE']);
			$t->addColumn('article_number', 'string', ['length' => 50]);
			$t->addColumn('short_name', 'string', ['length' => 180]);
			$t->addColumn('long_text', 'text', ['notnull' => false]);
			$t->addColumn('category', 'string', ['length' => 50]);
			$t->addColumn('article_group', 'string', ['length' => 100]);
			$t->addColumn('cost_type', 'string', ['length' => 30, 'default' => 'INTERNAL']);
			$t->addColumn('funeral_scope', 'string', ['length' => 20, 'default' => 'ALL']);
			$t->addColumn('vat_rate', 'integer', ['default' => 19]);
			$t->addColumn('supplier_contact', 'string', ['length' => 255, 'notnull' => false]);
			$t->addColumn('photo_path', 'string', ['length' => 500, 'notnull' => false]);
			$t->addColumn('purchase_price_cents', 'integer', ['default' => 0]);
			$t->addColumn('sales_price_cents', 'integer', ['default' => 0]);
			$t->addColumn('unit', 'string', ['length' => 20, 'default' => 'STK']);
			$t->addColumn('quantity_decimals', 'integer', ['default' => 0]);
			$t->addColumn('exclusive_group', 'boolean', ['default' => false]);
			$t->addColumn('active', 'boolean', ['default' => true]);
			$t->addColumn('created_at', 'string', ['length' => 40]);
			$t->addColumn('updated_at', 'string', ['length' => 40]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['article_number'], 'bestatter_article_number');
			$t->addIndex(['article_group'], 'bestatter_article_group');
		}
		if (!$schema->hasTable('bestatter_article_components')) {
			$t = $schema->createTable('bestatter_article_components');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('package_id', 'integer', ['unsigned' => true]);
			$t->addColumn('article_id', 'integer', ['unsigned' => true]);
			$t->addColumn('quantity_milli', 'integer', ['default' => 1000]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['package_id', 'article_id'], 'bestatter_package_component');
			$t->addIndex(['article_id'], 'bestatter_component_article');
		}
		if (!$schema->hasTable('bestatter_article_groups')) {
			$t = $schema->createTable('bestatter_article_groups');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('group_name', 'string', ['length' => 150]);
			$t->addColumn('exclusive_selection', 'boolean', ['default' => false]);
			$t->addColumn('created_at', 'string', ['length' => 40]);
			$t->addColumn('updated_at', 'string', ['length' => 40]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['group_name'], 'bestatter_article_group_name');
		}
	}

	private function caseServices(ISchemaWrapper $schema): void {
		if (!$schema->hasTable('bestatter_case_services')) {
			$t = $schema->createTable('bestatter_case_services');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('case_id', 'integer', ['unsigned' => true]);
			$t->addColumn('article_id', 'integer', ['unsigned' => true, 'notnull' => false]);
			$t->addColumn('source_package_id', 'integer', ['unsigned' => true, 'notnull' => false]);
			$t->addColumn('source_package_name', 'string', ['length' => 180, 'notnull' => false]);
			$t->addColumn('article_number', 'string', ['length' => 50]);
			$t->addColumn('title', 'string', ['length' => 220]);
			$t->addColumn('long_text', 'text', ['notnull' => false]);
			$t->addColumn('article_group', 'string', ['length' => 100]);
			$t->addColumn('cost_type', 'string', ['length' => 30]);
			$t->addColumn('quantity_milli', 'integer', ['default' => 1000]);
			$t->addColumn('unit', 'string', ['length' => 20, 'default' => 'STK']);
			$t->addColumn('quantity_decimals', 'integer', ['default' => 0]);
			$t->addColumn('unit_price_cents', 'integer', ['default' => 0]);
			$t->addColumn('vat_rate', 'integer', ['default' => 19]);
			$t->addColumn('sort_order', 'integer', ['default' => 0]);
			$t->addColumn('origin', 'string', ['length' => 24, 'default' => 'ORDER']);
			$t->addColumn('service_status', 'string', ['length' => 32, 'default' => 'BEAUFTRAGT']);
			$t->addColumn('ordered_quantity_milli', 'integer', ['default' => 1000]);
			$t->addColumn('performed_quantity_milli', 'integer', ['default' => 0]);
			$t->addColumn('invoiced_quantity_milli', 'integer', ['default' => 0]);
			$t->addColumn('billability', 'string', ['length' => 32, 'default' => 'ABRECHENBAR']);
			$t->addColumn('classification_reason', 'text', ['notnull' => false]);
			$t->addColumn('performed_at', 'string', ['length' => 40, 'notnull' => false]);
			$t->addColumn('performed_by', 'string', ['length' => 64, 'notnull' => false]);
			$t->addColumn('created_at', 'string', ['length' => 40]);
			$t->addColumn('updated_at', 'string', ['length' => 40]);
			// Incremental columns from Version3100/3200/3300.
			$t->addColumn('position_type', 'string', ['length' => 2, 'default' => 'EL']);
			// Existing rows have no category snapshot yet; keep nullable, no default (Version3200).
			$t->addColumn('article_category', 'string', ['length' => 100, 'notnull' => false]);
			$t->addColumn('note', 'text', ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['case_id'], 'bestatter_case_service_case');
			$t->addIndex(['article_id'], 'bestatter_case_service_article');
			$t->addIndex(['case_id', 'service_status'], 'bestatter_service_status');
			return;
		}

		$t = $schema->getTable('bestatter_case_services');
		$this->columns($t, [
			'position_type' => ['string', ['length' => 2, 'default' => 'EL']],
			'article_category' => ['string', ['length' => 100, 'notnull' => false]],
			'note' => ['text', ['notnull' => false]],
		]);
	}

	private function workflows(ISchemaWrapper $schema): void {
		if (!$schema->hasTable('bestatter_workflows')) {
			$t = $schema->createTable('bestatter_workflows');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('workflow_key', 'string', ['length' => 100]);
			$t->addColumn('name', 'string', ['length' => 180]);
			$t->addColumn('description', 'text', ['notnull' => false]);
			$t->addColumn('task_title_pattern', 'string', ['length' => 255, 'notnull' => false]);
			$t->addColumn('checklist_key', 'string', ['length' => 100, 'notnull' => false]);
			$t->addColumn('required_status', 'string', ['length' => 40, 'default' => 'ANY']);
			$t->addColumn('actions_json', 'text');
			$t->addColumn('active', 'boolean', ['default' => true]);
			$t->addColumn('sort_order', 'integer', ['default' => 0]);
			$t->addColumn('created_at', 'string', ['length' => 40]);
			$t->addColumn('updated_at', 'string', ['length' => 40]);
			// Trigger fields (from Version1800 currentExtensions).
			$t->addColumn('trigger_type', 'string', ['length' => 24, 'default' => 'TASK']);
			$t->addColumn('trigger_event', 'string', ['length' => 24, 'default' => 'MANUAL']);
			$t->addColumn('trigger_config', 'text', ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['workflow_key'], 'bestatter_workflow_key');
			$t->addIndex(['active', 'sort_order'], 'bestatter_workflow_active');
			$t->addIndex(['trigger_type', 'trigger_event', 'active'], 'bestatter_workflow_trigger');
		} else {
			$t = $schema->getTable('bestatter_workflows');
			$this->columns($t, [
				'trigger_type' => ['string', ['length' => 24, 'default' => 'TASK']],
				'trigger_event' => ['string', ['length' => 24, 'default' => 'MANUAL']],
				'trigger_config' => ['text', ['notnull' => false]],
			]);
			if (!$t->hasIndex('bestatter_workflow_trigger')) {
				$t->addIndex(['trigger_type', 'trigger_event', 'active'], 'bestatter_workflow_trigger');
			}
		}

		if (!$schema->hasTable('bestatter_workflow_runs')) {
			$t = $schema->createTable('bestatter_workflow_runs');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('workflow_id', 'integer', ['unsigned' => true]);
			$t->addColumn('source_record_id', 'integer', ['unsigned' => true]);
			$t->addColumn('action_key', 'string', ['length' => 100]);
			$t->addColumn('execution_key', 'string', ['length' => 180, 'notnull' => false]);
			$t->addColumn('run_status', 'string', ['length' => 24, 'default' => 'COMPLETED']);
			$t->addColumn('result_json', 'text');
			$t->addColumn('executed_by', 'string', ['length' => 64]);
			$t->addColumn('created_at', 'string', ['length' => 40]);
			$t->addColumn('updated_at', 'string', ['length' => 40, 'notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['source_record_id', 'workflow_id'], 'bestatter_workflow_source');
			$t->addUniqueIndex(['execution_key'], 'bestatter_workflow_execution');
		}
	}

	private function configuration(ISchemaWrapper $schema): void {
		if (!$schema->hasTable('bestatter_branches')) {
			$t = $schema->createTable('bestatter_branches');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('branch_key', 'string', ['length' => 100]);
			$t->addColumn('name', 'string', ['length' => 180]);
			foreach ([
				'street' => 255,
				'postal_code' => 20,
				'city' => 120,
				'country' => 100,
				'phone' => 80,
				'email' => 255,
				'account_holder' => 180,
				'bank_name' => 180,
				'iban' => 50,
				'bic' => 20,
				'creditor_id' => 50,
				'vat_id' => 40,
				'tax_number' => 50,
				'register_court' => 180,
				'register_number' => 80,
				'managing_directors' => 255,
				'electronic_address' => 255,
			] as $name => $length) {
				$t->addColumn($name, 'string', ['length' => $length, 'notnull' => false]);
			}
			$t->addColumn('payment_term_days', 'integer', ['default' => 14]);
			$t->addColumn('member_uids', 'text', ['notnull' => false]);
			$t->addColumn('active', 'boolean', ['default' => true]);
			$t->addColumn('sort_order', 'integer', ['default' => 0]);
			$t->addColumn('created_at', 'string', ['length' => 40]);
			$t->addColumn('updated_at', 'string', ['length' => 40]);
			// Localization fields (from Version3000).
			$t->addColumn('country_code', 'string', ['length' => 2, 'default' => 'DE']);
			$t->addColumn('invoice_profile', 'string', ['length' => 20, 'default' => 'ZUGFERD']);
			$t->addColumn('payment_qr_standard', 'string', ['length' => 20, 'default' => 'EPC069-12']);
			$t->addColumn('federal_state', 'string', ['length' => 40, 'notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['branch_key'], 'bestatter_branch_key');
			$t->addIndex(['country_code', 'active'], 'bestatter_branch_country');
		} else {
			$t = $schema->getTable('bestatter_branches');
			$this->columns($t, [
				'country_code' => ['string', ['length' => 2, 'default' => 'DE']],
				'invoice_profile' => ['string', ['length' => 20, 'default' => 'ZUGFERD']],
				'payment_qr_standard' => ['string', ['length' => 20, 'default' => 'EPC069-12']],
				'federal_state' => ['string', ['length' => 40, 'notnull' => false]],
			]);
			if (!$t->hasIndex('bestatter_branch_country')) {
				$t->addIndex(['country_code', 'active'], 'bestatter_branch_country');
			}
		}

		if (!$schema->hasTable('bestatter_document_templates')) {
			$t = $schema->createTable('bestatter_document_templates');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('template_key', 'string', ['length' => 100]);
			$t->addColumn('name', 'string', ['length' => 180]);
			$t->addColumn('category', 'string', ['length' => 100]);
			$t->addColumn('description', 'text', ['notnull' => false]);
			$t->addColumn('file_name', 'string', ['length' => 255, 'notnull' => false]);
			$t->addColumn('required_fields', 'text', ['notnull' => false]);
			$t->addColumn('output_subfolder', 'string', ['length' => 100, 'notnull' => false]);
			$t->addColumn('supports_pdf', 'boolean', ['default' => true]);
			$t->addColumn('active', 'boolean', ['default' => true]);
			$t->addColumn('sort_order', 'integer', ['default' => 0]);
			$t->addColumn('created_at', 'string', ['length' => 40]);
			$t->addColumn('updated_at', 'string', ['length' => 40]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['template_key'], 'bestatter_document_key');
		}

		if (!$schema->hasTable('bestatter_dereg_templates')) {
			$t = $schema->createTable('bestatter_dereg_templates');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('template_key', 'string', ['length' => 100]);
			$t->addColumn('recipient_type', 'string', ['length' => 100]);
			$t->addColumn('name', 'string', ['length' => 180]);
			$t->addColumn('contact_category', 'string', ['length' => 100, 'notnull' => false]);
			$t->addColumn('delivery_channels', 'text');
			$t->addColumn('default_channel', 'string', ['length' => 40, 'notnull' => false]);
			$t->addColumn('subject_template', 'text');
			$t->addColumn('body_template', 'text');
			$t->addColumn('form_type', 'string', ['length' => 60, 'default' => 'STANDARD']);
			$t->addColumn('required_fields', 'text', ['notnull' => false]);
			$t->addColumn('allowed_statuses', 'text', ['notnull' => false]);
			$t->addColumn('follow_up_days', 'integer', ['default' => 0]);
			$t->addColumn('document_template_key', 'string', ['length' => 100, 'notnull' => false]);
			$t->addColumn('active', 'boolean', ['default' => true]);
			$t->addColumn('sort_order', 'integer', ['default' => 0]);
			$t->addColumn('created_at', 'string', ['length' => 40]);
			$t->addColumn('updated_at', 'string', ['length' => 40]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['template_key'], 'bestatter_dereg_key');
		}

		if (!$schema->hasTable('bestatter_invoice_settings')) {
			$t = $schema->createTable('bestatter_invoice_settings');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('number_prefix', 'string', ['length' => 20, 'default' => 'RE']);
			$t->addColumn('number_pattern', 'string', ['length' => 100, 'default' => '{PREFIX}-{YYYY}-{SEQ}']);
			$t->addColumn('sequence_length', 'integer', ['default' => 6]);
			$t->addColumn('sequence_scope', 'string', ['length' => 24, 'default' => 'YEAR_GLOBAL']);
			$t->addColumn('case_reference', 'boolean', ['default' => true]);
			$t->addColumn('zugferd_enabled', 'boolean', ['default' => true]);
			$t->addColumn('zugferd_version', 'string', ['length' => 20, 'default' => '2.5.2']);
			$t->addColumn('zugferd_profile', 'string', ['length' => 32, 'default' => 'EN16931']);
			$t->addColumn('xrechnung_enabled', 'boolean', ['default' => false]);
			$t->addColumn('norm_validation_required', 'boolean', ['default' => false]);
			$t->addColumn('qr_enabled', 'boolean', ['default' => true]);
			$t->addColumn('created_at', 'string', ['length' => 40]);
			$t->addColumn('updated_at', 'string', ['length' => 40]);
			$t->setPrimaryKey(['id']);
		}

		if (!$schema->hasTable('bestatter_invoice_sequences')) {
			$t = $schema->createTable('bestatter_invoice_sequences');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('sequence_key', 'string', ['length' => 100]);
			$t->addColumn('last_number', 'integer', ['default' => 0]);
			$t->addColumn('updated_at', 'string', ['length' => 40]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['sequence_key'], 'bestatter_invoice_sequence_key');
		}
	}

	private function commercial(ISchemaWrapper $schema): void {
		if (!$schema->hasTable('bestatter_commercial_docs')) {
			$t = $schema->createTable('bestatter_commercial_docs');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('case_id', 'integer', ['unsigned' => true]);
			$t->addColumn('document_type', 'string', ['length' => 16]);
			$t->addColumn('document_number', 'string', ['length' => 80]);
			$t->addColumn('version_no', 'integer', ['default' => 1]);
			$t->addColumn('status', 'string', ['length' => 32, 'default' => 'ENTWURF']);
			$t->addColumn('source_document_id', 'integer', ['unsigned' => true, 'notnull' => false]);
			$t->addColumn('snapshot', 'text');
			foreach (['net_cents', 'vat_cents', 'gross_cents'] as $name) {
				$t->addColumn($name, 'integer', ['default' => 0]);
			}
			$t->addColumn('created_by', 'string', ['length' => 64]);
			$t->addColumn('created_at', 'string', ['length' => 40]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['document_type', 'document_number', 'version_no'], 'bestatter_commercial_number');
			$t->addIndex(['case_id', 'document_type'], 'bestatter_commercial_case');
		}

		if (!$schema->hasTable('bestatter_invoices')) {
			$t = $schema->createTable('bestatter_invoices');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('case_id', 'integer', ['unsigned' => true]);
			$t->addColumn('order_id', 'integer', ['unsigned' => true, 'notnull' => false]);
			$t->addColumn('invoice_number', 'string', ['length' => 80]);
			$t->addColumn('status', 'string', ['length' => 32, 'default' => 'ENTWURF']);
			$t->addColumn('recipient', 'text');
			$t->addColumn('billing_check', 'text');
			$t->addColumn('override_reason', 'text', ['notnull' => false]);
			foreach (['net_cents', 'vat_cents', 'gross_cents'] as $name) {
				$t->addColumn($name, 'integer', ['default' => 0]);
			}
			$t->addColumn('created_by', 'string', ['length' => 64]);
			$t->addColumn('created_at', 'string', ['length' => 40]);
			$t->addColumn('updated_at', 'string', ['length' => 40]);
			// Billing-closure fields (from Version1800 currentExtensions).
			$t->addColumn('invoice_type', 'string', ['length' => 16, 'default' => 'PARTIAL']);
			$t->addColumn('invoice_sequence', 'integer', ['default' => 1]);
			$t->addColumn('prior_gross_cents', 'integer', ['default' => 0]);
			$t->addColumn('closure_manifest', 'text', ['notnull' => false]);
			$t->addColumn('closed_at', 'string', ['length' => 40, 'notnull' => false]);
			$t->addColumn('closed_by', 'string', ['length' => 64, 'notnull' => false]);
			$t->addColumn('service_period_from', 'date', ['notnull' => false]);
			$t->addColumn('service_period_to', 'date', ['notnull' => false]);
			// Payment snapshot (from Version3400).
			$t->addColumn('payment_snapshot', 'text', ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['invoice_number'], 'bestatter_invoice_number');
			$t->addIndex(['case_id'], 'bestatter_invoice_case');
			$t->addIndex(['case_id', 'invoice_type', 'status'], 'bestatter_invoice_case_type');
		} else {
			$t = $schema->getTable('bestatter_invoices');
			$this->columns($t, [
				'invoice_type' => ['string', ['length' => 16, 'default' => 'PARTIAL']],
				'invoice_sequence' => ['integer', ['default' => 1]],
				'prior_gross_cents' => ['integer', ['default' => 0]],
				'closure_manifest' => ['text', ['notnull' => false]],
				'closed_at' => ['string', ['length' => 40, 'notnull' => false]],
				'closed_by' => ['string', ['length' => 64, 'notnull' => false]],
				'service_period_from' => ['date', ['notnull' => false]],
				'service_period_to' => ['date', ['notnull' => false]],
				'payment_snapshot' => ['text', ['notnull' => false]],
			]);
			if (!$t->hasIndex('bestatter_invoice_case_type')) {
				$t->addIndex(['case_id', 'invoice_type', 'status'], 'bestatter_invoice_case_type');
			}
		}

		if (!$schema->hasTable('bestatter_invoice_items')) {
			$t = $schema->createTable('bestatter_invoice_items');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('invoice_id', 'integer', ['unsigned' => true]);
			$t->addColumn('case_service_id', 'integer', ['unsigned' => true]);
			$t->addColumn('position_no', 'integer');
			$t->addColumn('description', 'text');
			$t->addColumn('quantity_milli', 'integer');
			$t->addColumn('unit', 'string', ['length' => 20, 'default' => 'STK']);
			$t->addColumn('unit_price_cents', 'integer');
			$t->addColumn('vat_rate', 'integer');
			foreach (['net_cents', 'vat_cents', 'gross_cents'] as $name) {
				$t->addColumn($name, 'integer');
			}
			$t->setPrimaryKey(['id']);
			$t->addIndex(['invoice_id'], 'bestatter_invoice_item_invoice');
			$t->addIndex(['case_service_id'], 'bestatter_invoice_item_service');
		}
	}

	private function auditLog(ISchemaWrapper $schema): void {
		if (!$schema->hasTable('bestatter_audit_log')) {
			$t = $schema->createTable('bestatter_audit_log');
			$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
			$t->addColumn('case_id', 'integer', ['unsigned' => true]);
			$t->addColumn('object_type', 'string', ['length' => 32]);
			$t->addColumn('object_id', 'integer', ['unsigned' => true, 'notnull' => false]);
			$t->addColumn('action', 'string', ['length' => 64]);
			$t->addColumn('before_data', 'text', ['notnull' => false]);
			$t->addColumn('after_data', 'text', ['notnull' => false]);
			$t->addColumn('user_uid', 'string', ['length' => 64]);
			$t->addColumn('created_at', 'string', ['length' => 40]);
			// Hash chain fields (from Version1800 currentExtensions).
			$t->addColumn('previous_hash', 'string', ['length' => 64, 'notnull' => false]);
			$t->addColumn('event_hash', 'string', ['length' => 64, 'notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['case_id', 'created_at'], 'bestatter_audit_case');
			$t->addIndex(['event_hash'], 'bestatter_audit_hash');
			return;
		}

		$t = $schema->getTable('bestatter_audit_log');
		$this->columns($t, [
			'previous_hash' => ['string', ['length' => 64, 'notnull' => false]],
			'event_hash' => ['string', ['length' => 64, 'notnull' => false]],
		]);
		if (!$t->hasIndex('bestatter_audit_hash')) {
			$t->addIndex(['event_hash'], 'bestatter_audit_hash');
		}
	}

	private function assistantRules(ISchemaWrapper $schema): void {
		if ($schema->hasTable('bestatter_assistant_rules')) {
			return;
		}
		$t = $schema->createTable('bestatter_assistant_rules');
		$this->columns($t, [
			'id' => ['integer', ['autoincrement' => true, 'unsigned' => true]],
			'field_key' => ['string', ['length' => 100]],
			'trigger_pattern' => ['string', ['length' => 255]],
			'strategy' => ['string', ['length' => 40]],
			'canonical_value' => ['string', ['length' => 255, 'notnull' => false]],
			'status' => ['string', ['length' => 20, 'default' => 'PENDING']],
			'usage_count' => ['integer', ['default' => 0]],
			'created_by' => ['string', ['length' => 64]],
			'approved_by' => ['string', ['length' => 64, 'notnull' => false]],
			'created_at' => ['string', ['length' => 40]],
			'updated_at' => ['string', ['length' => 40]],
		]);
		$t->setPrimaryKey(['id']);
		$t->addIndex(['status', 'field_key'], 'bestatter_assistant_rule_state');
		$t->addUniqueIndex(['field_key', 'trigger_pattern'], 'bestatter_assistant_rule_unique');
	}

	private function incomingInvoices(ISchemaWrapper $schema): void {
		if (!$schema->hasTable('bestatter_incoming_invoices')) {
			$t = $schema->createTable('bestatter_incoming_invoices');
			$this->columns($t, [
				'id' => ['integer', ['autoincrement' => true, 'unsigned' => true]],
				'case_id' => ['integer', ['unsigned' => true]],
				'supplier_name' => ['string', ['length' => 220]],
				'supplier_normalized' => ['string', ['length' => 220]],
				'supplier_invoice_no' => ['string', ['length' => 100]],
				'invoice_date' => ['date', []],
				'received_date' => ['date', []],
				'due_date' => ['date', ['notnull' => false]],
				'currency' => ['string', ['length' => 3, 'default' => 'EUR']],
				'status' => ['string', ['length' => 24, 'default' => 'ENTWURF']],
				'declared_gross_cents' => ['integer', ['default' => 0]],
				'net_cents' => ['integer', ['default' => 0]],
				'vat_cents' => ['integer', ['default' => 0]],
				'gross_cents' => ['integer', ['default' => 0]],
				'file_id' => ['integer', ['unsigned' => true, 'notnull' => false]],
				'document_record_id' => ['integer', ['unsigned' => true, 'notnull' => false]],
				'file_path' => ['string', ['length' => 750, 'notnull' => false]],
				'document_sha256' => ['string', ['length' => 64, 'notnull' => false]],
				'source_system' => ['string', ['length' => 32, 'default' => 'BESTATTER_UPLOAD']],
				'source_reference' => ['string', ['length' => 255, 'notnull' => false]],
				'notes' => ['text', ['notnull' => false]],
				'created_by' => ['string', ['length' => 64]],
				'updated_by' => ['string', ['length' => 64]],
				'created_at' => ['string', ['length' => 40]],
				'updated_at' => ['string', ['length' => 40]],
			]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['supplier_normalized', 'supplier_invoice_no'], 'bestatter_incoming_supplier');
			$t->addUniqueIndex(['document_sha256'], 'bestatter_incoming_document');
			$t->addIndex(['case_id', 'status'], 'bestatter_incoming_case');
			$t->addIndex(['due_date', 'status'], 'bestatter_incoming_due');
		}

		if (!$schema->hasTable('bestatter_incoming_items')) {
			$t = $schema->createTable('bestatter_incoming_items');
			$this->columns($t, [
				'id' => ['integer', ['autoincrement' => true, 'unsigned' => true]],
				'incoming_invoice_id' => ['integer', ['unsigned' => true]],
				'position_no' => ['integer', []],
				'description' => ['string', ['length' => 500]],
				'supplier_quantity_milli' => ['integer', ['default' => 1000]],
				'unit' => ['string', ['length' => 20, 'default' => 'STK']],
				'supplier_unit_cents' => ['integer', ['default' => 0]],
				'supplier_vat_rate' => ['integer', ['default' => 19]],
				'supplier_net_cents' => ['integer', ['default' => 0]],
				'supplier_vat_cents' => ['integer', ['default' => 0]],
				'supplier_gross_cents' => ['integer', ['default' => 0]],
				'classification' => ['string', ['length' => 32, 'default' => 'CLASSIFICATION_PENDING']],
				'case_service_id' => ['integer', ['unsigned' => true, 'notnull' => false]],
				'customer_quantity_milli' => ['integer', ['default' => 1000]],
				'customer_unit_cents' => ['integer', ['default' => 0]],
				'customer_vat_rate' => ['integer', ['default' => 19]],
				'variance_reason' => ['text', ['notnull' => false]],
				'transfer_status' => ['string', ['length' => 20, 'default' => 'OFFEN']],
				'created_service_id' => ['integer', ['unsigned' => true, 'notnull' => false]],
				'transferred_by' => ['string', ['length' => 64, 'notnull' => false]],
				'transferred_at' => ['string', ['length' => 40, 'notnull' => false]],
			]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['incoming_invoice_id', 'position_no'], 'bestatter_incoming_position');
			$t->addIndex(['case_service_id'], 'bestatter_incoming_service');
			$t->addIndex(['transfer_status'], 'bestatter_incoming_transfer');
		}
	}

	private function scheduling(ISchemaWrapper $schema): void {
		if (!$schema->hasTable('bestatter_schedule_types')) {
			$t = $schema->createTable('bestatter_schedule_types');
			$this->columns($t, [
				'id' => ['integer', ['autoincrement' => true, 'unsigned' => true]],
				'type_key' => ['string', ['length' => 100]],
				'name' => ['string', ['length' => 180]],
				'schedule_kind' => ['string', ['length' => 30, 'default' => 'EXTERNAL_APPOINTMENT']],
				'default_duration' => ['integer', ['default' => 60]],
				'buffer_before' => ['integer', ['default' => 0]],
				'buffer_after' => ['integer', ['default' => 0]],
				'active' => ['boolean', ['default' => true]],
				'sort_order' => ['integer', ['default' => 0]],
				'required_staff' => ['integer', ['default' => 0]],
				'required_vehicles' => ['integer', ['default' => 0]],
				'required_rooms' => ['integer', ['default' => 0]],
				'required_chapels' => ['integer', ['default' => 0]],
				'required_equipment' => ['integer', ['default' => 0]],
				'default_resource_keys' => ['text', ['notnull' => false]],
			]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['type_key'], 'bestatter_schedule_type_key');
			$t->addIndex(['active', 'sort_order'], 'bestatter_schedule_type_active');
		} else {
			$this->columns($schema->getTable('bestatter_schedule_types'), [
				'required_staff' => ['integer', ['default' => 0]],
				'required_vehicles' => ['integer', ['default' => 0]],
				'required_rooms' => ['integer', ['default' => 0]],
				'required_chapels' => ['integer', ['default' => 0]],
				'required_equipment' => ['integer', ['default' => 0]],
				'default_resource_keys' => ['text', ['notnull' => false]],
			]);
		}

		if (!$schema->hasTable('bestatter_resources')) {
			$t = $schema->createTable('bestatter_resources');
			$this->columns($t, [
				'id' => ['integer', ['autoincrement' => true, 'unsigned' => true]],
				'resource_key' => ['string', ['length' => 100]],
				'name' => ['string', ['length' => 180]],
				'resource_type' => ['string', ['length' => 30]],
				'linked_uid' => ['string', ['length' => 64, 'notnull' => false]],
				'conflict_relevant' => ['boolean', ['default' => true]],
				'active' => ['boolean', ['default' => true]],
				'sort_order' => ['integer', ['default' => 0]],
			]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['resource_key'], 'bestatter_resource_key');
			$t->addIndex(['resource_type', 'active'], 'bestatter_resource_type');
		}

		if (!$schema->hasTable('bestatter_schedule_history')) {
			$t = $schema->createTable('bestatter_schedule_history');
			$this->columns($t, [
				'id' => ['integer', ['autoincrement' => true, 'unsigned' => true]],
				'record_id' => ['integer', ['unsigned' => true]],
				'case_id' => ['integer', ['unsigned' => true, 'notnull' => false]],
				'action' => ['string', ['length' => 24]],
				'change_reason' => ['text', ['notnull' => false]],
				'snapshot_json' => ['text', []],
				'changes_json' => ['text', ['notnull' => false]],
				'changed_by' => ['string', ['length' => 64]],
				'created_at' => ['string', ['length' => 40]],
			]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['record_id', 'created_at'], 'bestatter_schedule_history_record');
		} else {
			$this->columns($schema->getTable('bestatter_schedule_history'), [
				'changes_json' => ['text', ['notnull' => false]],
			]);
		}
	}

	private function paperless(ISchemaWrapper $schema): void {
		if (!$schema->hasTable('bestatter_external_documents')) {
			$t = $schema->createTable('bestatter_external_documents');
			$this->columns($t, [
				'id' => ['integer', ['autoincrement' => true, 'unsigned' => true]],
				'case_id' => ['integer', ['unsigned' => true, 'notnull' => false]],
				'record_type' => ['string', ['length' => 32, 'default' => 'INCOMING_INVOICE']],
				'record_id' => ['integer', ['unsigned' => true, 'notnull' => false]],
				'provider' => ['string', ['length' => 32, 'default' => 'PAPERLESS']],
				'external_document_id' => ['string', ['length' => 64, 'notnull' => false]],
				'external_task_id' => ['string', ['length' => 100, 'notnull' => false]],
				'nextcloud_file_id' => ['integer', ['unsigned' => true, 'notnull' => false]],
				'document_sha256' => ['string', ['length' => 64, 'notnull' => false]],
				'title' => ['string', ['length' => 500, 'notnull' => false]],
				'sync_status' => ['string', ['length' => 24, 'default' => 'INBOX']],
				'sync_error' => ['text', ['notnull' => false]],
				'metadata_json' => ['text', ['notnull' => false]],
				'last_synced_at' => ['string', ['length' => 40, 'notnull' => false]],
				'created_at' => ['string', ['length' => 40]],
				'updated_at' => ['string', ['length' => 40]],
			]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['provider', 'external_document_id'], 'bestatter_external_provider_doc');
			$t->addIndex(['sync_status', 'updated_at'], 'bestatter_external_status');
			$t->addIndex(['case_id', 'record_type'], 'bestatter_external_case');
			$t->addIndex(['record_type', 'record_id'], 'bestatter_external_record');
		}

		if (!$schema->hasTable('bestatter_integration_jobs')) {
			$t = $schema->createTable('bestatter_integration_jobs');
			$this->columns($t, [
				'id' => ['integer', ['autoincrement' => true, 'unsigned' => true]],
				'provider' => ['string', ['length' => 32, 'default' => 'PAPERLESS']],
				'operation' => ['string', ['length' => 32]],
				'object_type' => ['string', ['length' => 32]],
				'object_id' => ['integer', ['unsigned' => true]],
				'payload_json' => ['text', ['notnull' => false]],
				'job_status' => ['string', ['length' => 20, 'default' => 'QUEUED']],
				'attempts' => ['integer', ['default' => 0]],
				'next_attempt_at' => ['string', ['length' => 40]],
				'last_error' => ['text', ['notnull' => false]],
				'created_at' => ['string', ['length' => 40]],
				'updated_at' => ['string', ['length' => 40]],
			]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['provider', 'job_status', 'next_attempt_at'], 'bestatter_integration_due');
			$t->addIndex(['object_type', 'object_id'], 'bestatter_integration_object');
		}
	}

	private function captureImports(ISchemaWrapper $schema): void {
		if ($schema->hasTable('bestatter_capture_imports')) {
			return;
		}
		$t = $schema->createTable('bestatter_capture_imports');
		$this->columns($t, [
			'id' => ['integer', ['autoincrement' => true, 'unsigned' => true]],
			'user_id' => ['string', ['length' => 64]],
			'case_id' => ['integer', ['unsigned' => true, 'notnull' => false]],
			'nextcloud_file_id' => ['integer', ['unsigned' => true]],
			'external_document_id' => ['integer', ['unsigned' => true, 'notnull' => false]],
			'template_key' => ['string', ['length' => 100, 'default' => 'AUFTRAGSERFASSUNG_STANDARD']],
			'template_version' => ['string', ['length' => 30, 'default' => '1']],
			'original_name' => ['string', ['length' => 255]],
			'mime_type' => ['string', ['length' => 100, 'default' => 'application/pdf']],
			'document_sha256' => ['string', ['length' => 64]],
			'import_status' => ['string', ['length' => 32, 'default' => 'UPLOADED']],
			'ocr_text' => ['text', ['notnull' => false]],
			'suggestions_json' => ['text', ['notnull' => false]],
			'error_message' => ['text', ['notnull' => false]],
			'created_at' => ['string', ['length' => 40]],
			'updated_at' => ['string', ['length' => 40]],
			'reviewed_at' => ['string', ['length' => 40, 'notnull' => false]],
		]);
		$t->setPrimaryKey(['id']);
		$t->addIndex(['user_id', 'import_status'], 'bestatter_capture_user_status');
		$t->addIndex(['case_id'], 'bestatter_capture_case');
		$t->addIndex(['external_document_id'], 'bestatter_capture_external');
		$t->addUniqueIndex(['document_sha256', 'user_id'], 'bestatter_capture_hash_user');
	}

	private function countryProfiles(ISchemaWrapper $schema): void {
		if ($schema->hasTable('bestatter_country_profiles')) {
			return;
		}
		$t = $schema->createTable('bestatter_country_profiles');
		$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
		$t->addColumn('country_code', 'string', ['length' => 2]);
		$t->addColumn('name', 'string', ['length' => 100]);
		$t->addColumn('vat_rates', 'text');
		$t->addColumn('default_invoice_profile', 'string', ['length' => 20, 'default' => 'NONE']);
		$t->addColumn('default_payment_qr', 'string', ['length' => 20, 'default' => 'NONE']);
		$t->addColumn('template_namespace', 'string', ['length' => 100]);
		$t->addColumn('ready', 'boolean', ['default' => false]);
		$t->addColumn('created_at', 'string', ['length' => 40]);
		$t->addColumn('updated_at', 'string', ['length' => 40]);
		$t->setPrimaryKey(['id']);
		$t->addUniqueIndex(['country_code'], 'bestatter_country_code');
	}

	private function sideOrders(ISchemaWrapper $schema): void {
		if ($schema->hasTable('bestatter_side_orders')) {
			$t = $schema->getTable('bestatter_side_orders');
			if (!$t->hasIndex('bestatter_side_order_name')) {
				$t->addIndex(['last_name', 'first_name'], 'bestatter_side_order_name');
			}
			if (!$t->hasIndex('bestatter_side_order_status_case')) {
				$t->addIndex(['status', 'case_id'], 'bestatter_side_order_status_case');
			}
			return;
		}

		$t = $schema->createTable('bestatter_side_orders');
		$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
		$t->addColumn('case_id', 'integer', ['unsigned' => true]);
		$t->addColumn('side_order_number', 'string', ['length' => 80]);
		$t->addColumn('first_name', 'string', ['length' => 100, 'notnull' => false]);
		$t->addColumn('last_name', 'string', ['length' => 100]);
		$t->addColumn('street', 'string', ['length' => 255, 'notnull' => false]);
		$t->addColumn('postal_city', 'string', ['length' => 180, 'notnull' => false]);
		$t->addColumn('country', 'string', ['length' => 100, 'notnull' => false]);
		$t->addColumn('phone', 'string', ['length' => 80, 'notnull' => false]);
		$t->addColumn('email', 'string', ['length' => 255, 'notnull' => false]);
		$t->addColumn('relation', 'string', ['length' => 100, 'notnull' => false]);
		$t->addColumn('status', 'string', ['length' => 32, 'default' => 'ENTWURF']);
		$t->addColumn('required_by', 'string', ['length' => 40, 'notnull' => false]);
		$t->addColumn('created_at', 'string', ['length' => 40]);
		$t->addColumn('updated_at', 'string', ['length' => 40]);
		$t->setPrimaryKey(['id']);
		$t->addUniqueIndex(['side_order_number'], 'bestatter_side_order_number');
		$t->addIndex(['case_id', 'status'], 'bestatter_side_order_case_status');
		// Lookup indexes from Version3600 (BEST-115).
		$t->addIndex(['last_name', 'first_name'], 'bestatter_side_order_name');
		$t->addIndex(['status', 'case_id'], 'bestatter_side_order_status_case');
	}

	/**
	 * Adds the side_order_id back-reference (from Version3500) to every
	 * table that can be attributed to a side order, together with the
	 * matching (case_id, side_order_id) lookup index. Index names reproduce
	 * Version3500's `'bestatter_' . substr(str_replace('bestatter_', '', $tableName), 0, 18) . '_side_order'`
	 * computation for these three table names exactly.
	 */
	private function sideOrderLinks(ISchemaWrapper $schema): void {
		$targets = [
			'bestatter_case_services' => 'bestatter_case_services_side_order',
			'bestatter_commercial_docs' => 'bestatter_commercial_docs_side_order',
			'bestatter_invoices' => 'bestatter_invoices_side_order',
		];
		foreach ($targets as $tableName => $indexName) {
			if (!$schema->hasTable($tableName)) {
				continue;
			}
			$t = $schema->getTable($tableName);
			if (!$t->hasColumn('side_order_id')) {
				$t->addColumn('side_order_id', 'integer', ['unsigned' => true, 'notnull' => false]);
			}
			if (!$t->hasIndex($indexName)) {
				$t->addIndex(['case_id', 'side_order_id'], $indexName);
			}
		}
	}

	private function surchargeAndVariantRules(ISchemaWrapper $schema): void {
		if (!$schema->hasTable('bestatter_surcharge_rules')) {
			$t = $schema->createTable('bestatter_surcharge_rules');
			$t->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
			$t->addColumn('rule_key', 'string', ['length' => 100]);
			$t->addColumn('label', 'string', ['length' => 180]);
			$t->addColumn('dimension', 'string', ['length' => 24]);
			$t->addColumn('threshold_from', 'integer', ['notnull' => false]);
			$t->addColumn('threshold_to', 'integer', ['notnull' => false]);
			$t->addColumn('article_id', 'integer', ['unsigned' => true, 'notnull' => false]);
			$t->addColumn('active', 'boolean', ['default' => false]);
			$t->addColumn('sort_order', 'integer', ['default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['rule_key'], 'bestatter_surcharge_key');
			$t->addIndex(['dimension', 'sort_order'], 'bestatter_surcharge_dim');
		}

		if (!$schema->hasTable('bestatter_burial_variant_rules')) {
			$t = $schema->createTable('bestatter_burial_variant_rules');
			$t->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
			$t->addColumn('variant_code', 'string', ['length' => 120]);
			$t->addColumn('rule_type', 'string', ['length' => 48]);
			$t->addColumn('article_group', 'string', ['length' => 100, 'notnull' => false]);
			$t->addColumn('article_id', 'integer', ['unsigned' => true, 'notnull' => false]);
			$t->addColumn('default_article_id', 'integer', ['unsigned' => true, 'notnull' => false]);
			$t->addColumn('note', 'text', ['notnull' => false]);
			$t->addColumn('active', 'boolean', ['default' => false]);
			$t->addColumn('sort_order', 'integer', ['default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['variant_code', 'active'], 'bestatter_variant_rule_variant');
		}

		if (!$schema->hasTable('bestatter_case_automation')) {
			$t = $schema->createTable('bestatter_case_automation');
			$t->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
			$t->addColumn('case_id', 'integer', ['unsigned' => true]);
			$t->addColumn('trigger_key', 'string', ['length' => 100]);
			$t->addColumn('record_id', 'integer', ['unsigned' => true, 'notnull' => false]);
			$t->addColumn('created_at', 'string', ['length' => 40]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['case_id', 'trigger_key'], 'bestatter_case_auto_unique');
		}
	}

	private function mailOutbox(ISchemaWrapper $schema): void {
		if ($schema->hasTable('bestatter_mail_outbox')) {
			$t = $schema->getTable('bestatter_mail_outbox');
			if (!$t->hasColumn('reply_to')) {
				$t->addColumn('reply_to', 'string', ['length' => 320, 'notnull' => false]);
			}
			return;
		}

		$t = $schema->createTable('bestatter_mail_outbox');
		$t->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
		$t->addColumn('case_id', 'integer', ['unsigned' => true]);
		$t->addColumn('record_id', 'integer', ['unsigned' => true]);
		$t->addColumn('file_id', 'integer', ['unsigned' => true]);
		$t->addColumn('request_key', 'string', ['length' => 64]);
		$t->addColumn('dedupe_key', 'string', ['length' => 64]);
		$t->addColumn('recipient', 'string', ['length' => 320]);
		$t->addColumn('sender', 'string', ['length' => 320]);
		$t->addColumn('subject', 'string', ['length' => 250]);
		$t->addColumn('body', 'text');
		$t->addColumn('file_name', 'string', ['length' => 250]);
		$t->addColumn('file_sha256', 'string', ['length' => 64]);
		$t->addColumn('status', 'string', ['length' => 24]);
		$t->addColumn('repeat_reason', 'string', ['length' => 500, 'notnull' => false]);
		$t->addColumn('created_by', 'string', ['length' => 64]);
		$t->addColumn('created_at', 'string', ['length' => 40]);
		$t->addColumn('updated_at', 'string', ['length' => 40]);
		// Reply-to address (from Version4000).
		$t->addColumn('reply_to', 'string', ['length' => 320, 'notnull' => false]);
		$t->setPrimaryKey(['id']);
		$t->addUniqueIndex(['request_key'], 'bestatter_mail_request');
		$t->addUniqueIndex(['dedupe_key'], 'bestatter_mail_dedupe');
		$t->addIndex(['case_id', 'created_at'], 'bestatter_mail_case');
		$t->addIndex(['case_id', 'record_id', 'recipient'], 'bestatter_mail_repeat');
	}

	/** @param array<string,array{0:string,1:array<string,mixed>}> $definitions */
	private function columns(mixed $table, array $definitions): void {
		foreach ($definitions as $name => [$type, $options]) {
			if (!$table->hasColumn($name)) {
				$table->addColumn($name, $type, $options);
			}
		}
	}
}
