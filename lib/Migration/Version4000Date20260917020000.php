<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Record the reply address actually used for each outbound message. */
class Version4000Date20260917020000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('bestatter_mail_outbox')) return $schema;
		$table = $schema->getTable('bestatter_mail_outbox');
		if (!$table->hasColumn('reply_to')) $table->addColumn('reply_to', 'string', ['length' => 320, 'notnull' => false]);
		return $schema;
	}
}
