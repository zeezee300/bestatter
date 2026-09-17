<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Persist outbound-mail attempts before any external SMTP side effect. */
class Version3900Date20260917010000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('bestatter_mail_outbox')) return $schema;
		$table = $schema->createTable('bestatter_mail_outbox');
		$table->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
		$table->addColumn('case_id', 'integer', ['unsigned' => true]);
		$table->addColumn('record_id', 'integer', ['unsigned' => true]);
		$table->addColumn('file_id', 'integer', ['unsigned' => true]);
		$table->addColumn('request_key', 'string', ['length' => 64]);
		$table->addColumn('dedupe_key', 'string', ['length' => 64]);
		$table->addColumn('recipient', 'string', ['length' => 320]);
		$table->addColumn('sender', 'string', ['length' => 320]);
		$table->addColumn('subject', 'string', ['length' => 250]);
		$table->addColumn('body', 'text');
		$table->addColumn('file_name', 'string', ['length' => 250]);
		$table->addColumn('file_sha256', 'string', ['length' => 64]);
		$table->addColumn('status', 'string', ['length' => 24]);
		$table->addColumn('repeat_reason', 'string', ['length' => 500, 'notnull' => false]);
		$table->addColumn('created_by', 'string', ['length' => 64]);
		$table->addColumn('created_at', 'string', ['length' => 40]);
		$table->addColumn('updated_at', 'string', ['length' => 40]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['request_key'], 'bestatter_mail_request');
		$table->addUniqueIndex(['dedupe_key'], 'bestatter_mail_dedupe');
		$table->addIndex(['case_id', 'created_at'], 'bestatter_mail_case');
		$table->addIndex(['case_id', 'record_id', 'recipient'], 'bestatter_mail_repeat');
		return $schema;
	}
}
