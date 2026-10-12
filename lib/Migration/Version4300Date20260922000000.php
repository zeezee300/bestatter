<?php

declare(strict_types=1);

namespace OCA\Bestatter\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Befund (Review 2026-09-22, Kundenmeldung zum Prozess "Abmeldungen"): "Der Mailversand oder die
 * auch die Druckausgabe wird noch nicht angeboten." Bislang dokumentierte der Statuswechsel nach
 * VERSENDET ausschließlich einen bereits außerhalb der Anwendung erfolgten Versand (Notiz/Beleg,
 * kein tatsächlicher E-Mail-Versand). Für Abmeldungen mit Übertragungsweg E-Mail wird nun ein
 * echter, mehrere Anlagen umfassender Versand angeboten. Diese Tabelle protokolliert jeden
 * Versandversuch VOR dem eigentlichen SMTP-Aufruf (wie bereits bei bestatter_mail_outbox für den
 * Geschäftspostfach-Einzel-PDF-Versand etabliert), damit ein unklarer Serverzustand (Absturz
 * zwischen SMTP-Zusage und Rückschreiben) nie zu einem stillschweigend wiederholten Versand führen
 * kann: request_key und dedupe_key sind eindeutig, sodass ein erneuter Aufruf mit identischem
 * Inhalt denselben Datensatz zurückliefert statt erneut zu versenden. attachments_fingerprint hält
 * fest, welche Anlagen (Datei-ID + Inhalts-Hash) zum Zeitpunkt der Vorschau ausgewählt waren, damit
 * ein zwischenzeitlich geänderter Anlageninhalt den Versand verhindert statt unbemerkt eine andere
 * Datei zu verschicken.
 */
class Version4300Date20260922000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('bestatter_dereg_mail_outbox')) return $schema;
		$table = $schema->createTable('bestatter_dereg_mail_outbox');
		$table->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
		$table->addColumn('case_id', 'integer', ['unsigned' => true]);
		$table->addColumn('record_id', 'integer', ['unsigned' => true]);
		$table->addColumn('request_key', 'string', ['length' => 64]);
		$table->addColumn('dedupe_key', 'string', ['length' => 64]);
		$table->addColumn('recipient', 'string', ['length' => 320]);
		$table->addColumn('sender', 'string', ['length' => 320]);
		$table->addColumn('reply_to', 'string', ['length' => 320, 'notnull' => false]);
		$table->addColumn('subject', 'string', ['length' => 250]);
		$table->addColumn('body', 'text');
		$table->addColumn('attachments_json', 'text');
		$table->addColumn('attachments_fingerprint', 'string', ['length' => 64]);
		$table->addColumn('status', 'string', ['length' => 24]);
		$table->addColumn('repeat_reason', 'string', ['length' => 500, 'notnull' => false]);
		$table->addColumn('created_by', 'string', ['length' => 64]);
		$table->addColumn('created_at', 'string', ['length' => 40]);
		$table->addColumn('updated_at', 'string', ['length' => 40]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['request_key'], 'bestatter_dereg_mail_request');
		$table->addUniqueIndex(['dedupe_key'], 'bestatter_dereg_mail_dedupe');
		$table->addIndex(['case_id', 'created_at'], 'bestatter_dereg_mail_case');
		$table->addIndex(['case_id', 'record_id', 'recipient'], 'bestatter_dereg_mail_repeat');
		return $schema;
	}
}
