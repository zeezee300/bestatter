<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.71.0 – Eindeutigkeit (Prüfsumme, Person) gilt nur noch für offene Erfassungsbögen (im Dienst geprüft):
 * Ein verworfener oder bereits zugeordneter Bogen darf erneut hochgeladen werden. Der eindeutige Index
 * wird durch einen normalen Index ersetzt.
 */
class Version5101Date20261002000100 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('bestatter_capture_imports')) return null;
		$t = $schema->getTable('bestatter_capture_imports');
		$changed = false;
		if ($t->hasIndex('bestatter_capture_hash_user')) { $t->dropIndex('bestatter_capture_hash_user'); $changed = true; }
		if (!$t->hasIndex('bestatter_capture_hash')) { $t->addIndex(['document_sha256', 'user_id'], 'bestatter_capture_hash'); $changed = true; }
		return $changed ? $schema : null;
	}
}
