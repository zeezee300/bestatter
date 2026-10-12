<?php
declare(strict_types=1);
namespace OCA\Bestatter\Command;
use OCA\Bestatter\Service\BackupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
class Backup extends Command {
	public function __construct(private BackupService $backup){parent::__construct();}
	protected function configure():void{$this->setName('bestatter:backup')->setDescription('Erstellt einen prüfbaren App-Datensnapshot oder ein vollständiges Fachpaket.')
		->addOption('output',null,InputOption::VALUE_REQUIRED,'Absolute Zieldatei für einen reinen JSON-Snapshot')
		->addOption('include-files',null,InputOption::VALUE_NONE,'Fallakten, Vorlagen und Artikelbilder in ein ZIP-Fachpaket aufnehmen')
		// 0.78.0
		->addOption('cleanup-expired',null,InputOption::VALUE_NONE,'Danach fristabgelaufene Sicherungen löschen (die neuesten gültigen Pakete bleiben geschützt)')
		->addOption('scheduled',null,InputOption::VALUE_NONE,'Die automatische Sicherung jetzt so ausführen, wie sie unter Administration → Backup & Restore eingestellt ist (Art, Aufräumen), und das Ergebnis dort anzeigen');}
	protected function execute(InputInterface $input,OutputInterface $output):int{
		if($input->getOption('scheduled')){
			$result=$this->backup->runScheduled(true);
			$output->writeln(json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
			return ($result['status']??'ERROR')==='ERROR'?self::FAILURE:self::SUCCESS;
		}
		$result=$input->getOption('include-files')?$this->backup->createPackage():$this->backup->create($input->getOption('output')?:null);
		if($input->getOption('cleanup-expired')){$cleanup=$this->backup->deleteExpired();$result['cleanup']=['deleted'=>array_values(array_map(static fn(array $entry):string=>(string)$entry['name'],$cleanup['deleted'])),'skipped'=>$cleanup['skipped']];}
		$output->writeln(json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
		return self::SUCCESS;
	}
}
