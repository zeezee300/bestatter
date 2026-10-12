<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 0.69.0 – Die Paketkomponenten sind in Version4800 als Stücklisten (bestatter_boms,
 * bestatter_bom_items) übernommen worden; die alte Tabelle entfällt.
 */
class Version4801Date20260929000100 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('bestatter_article_components')) $schema->dropTable('bestatter_article_components');
		return $schema;
	}
}
