<?php

declare(strict_types=1);

namespace OCA\Bestatter\Tests\Integration;

use OCA\Bestatter\Middleware\BestatterAccessMiddleware;
use OCA\Bestatter\Service\CommercialService;
use OCA\Bestatter\Service\DocumentService;
use OCA\Bestatter\Service\GroupwareService;
use OCA\Bestatter\Service\IncomingInvoiceService;
use OCA\Bestatter\Service\WorkflowService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/** Runs inside the real Nextcloud test container and always rolls DB writes back. */
final class NextcloudIntegrationTest extends TestCase {
	private ?IDBConnection $db = null;
	private bool $transactionActive = false;

	protected function setUp(): void {
		if (!class_exists(\OC::class)) self::markTestSkipped('Nur innerhalb der Nextcloud-Testinstanz ausführbar.');
		$this->db = \OC::$server->get(IDBConnection::class); $this->db->beginTransaction(); $this->transactionActive = true;
	}

	protected function tearDown(): void { if ($this->db !== null && $this->transactionActive) $this->db->rollBack(); }

	public function testDomainServicesAndCentralPermissionMiddlewareResolveFromContainer(): void {
		foreach ([WorkflowService::class,DocumentService::class,CommercialService::class,IncomingInvoiceService::class,GroupwareService::class,BestatterAccessMiddleware::class] as $class) self::assertInstanceOf($class,\OC::$server->get($class));
	}

	public function testRequiredTablesForTransactionalWorkflowsAndInvoicesExist(): void {
		foreach (['bestatter_cases','bestatter_records','bestatter_workflow_runs','bestatter_invoices','bestatter_invoice_items','bestatter_invoice_sequences','bestatter_assistant_rules','bestatter_incoming_invoices','bestatter_incoming_items'] as $table) {
			$query=$this->db->getQueryBuilder();
			self::assertIsNumeric($query->select($query->func()->count('*','count'))->from($table)->executeQuery()->fetchOne(),$table.' fehlt oder ist nicht lesbar');
		}
	}

	public function testRealDatabaseTransactionCanRollbackInvoiceSequenceReservation(): void {
		$key='PHPUNIT-'.bin2hex(random_bytes(8)); $q=$this->db->getQueryBuilder();
		$q->insert('bestatter_invoice_sequences')->values(['sequence_key'=>$q->createNamedParameter($key),'last_number'=>$q->createNamedParameter(1),'updated_at'=>$q->createNamedParameter(date('c'))])->executeStatement();
		$q=$this->db->getQueryBuilder(); self::assertSame(1,(int)$q->select($q->func()->count('*','count'))->from('bestatter_invoice_sequences')->where($q->expr()->eq('sequence_key',$q->createNamedParameter($key)))->executeQuery()->fetchOne());
		$this->db->rollBack(); $this->transactionActive=false;
		$q=$this->db->getQueryBuilder(); self::assertSame(0,(int)$q->select($q->func()->count('*','count'))->from('bestatter_invoice_sequences')->where($q->expr()->eq('sequence_key',$q->createNamedParameter($key)))->executeQuery()->fetchOne());
		$this->db=null;
	}
}
