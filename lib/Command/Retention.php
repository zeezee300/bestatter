<?php
declare(strict_types=1);
namespace OCA\Bestatter\Command;
use OCA\Bestatter\Service\RetentionPolicyService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
/** Zeigt abgelaufene Fälle oder wendet die Aufbewahrungsrichtlinie bestätigt an (nur bei eingeschalteter Richtlinie). */
class Retention extends Command{
	private const CONFIRMATION='BESTATTER-AUFBEWAHRUNG-ANWENDEN';
	public function __construct(private RetentionPolicyService $retention){parent::__construct();}
	protected function configure():void{$this->setName('bestatter:retention')->setDescription('Zeigt abgelaufene Fälle oder wendet die konfigurierte Lösch-/Anonymisierungsregel bestätigt an.')->addOption('execute',null,InputOption::VALUE_NONE)->addOption('confirmation',null,InputOption::VALUE_REQUIRED)->addOption('acknowledge-notices',null,InputOption::VALUE_NONE,'Hinweise auf noch laufende Aufbewahrungsfristen gelesen und bestätigt');}
	protected function execute(InputInterface $input,OutputInterface $output):int{
		$preview=$this->retention->preview(500);
		$output->writeln(json_encode($preview,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
		if(!$preview['settings']['enabled'])$output->writeln('<comment>Hinweis: Die Aufbewahrungsrichtlinie ist ausgeschaltet – ausführen ist erst nach dem Einschalten möglich.</comment>');
		foreach($preview['settings']['notices'] as $notice)$output->writeln('<comment>Hinweis: '.$notice.'</comment>');
		$caseNotices=0;
		foreach($preview['cases'] as $case)foreach($case['notices'] as $notice){$caseNotices++;$output->writeln('<comment>Hinweis '.$case['caseNumber'].': '.$notice['text'].'</comment>');}
		if(!$input->getOption('execute')){$output->writeln('<comment>Vorschau: keine Daten verändert.</comment>');return self::SUCCESS;}
		if((string)$input->getOption('confirmation')!==self::CONFIRMATION){$output->writeln('<error>Bestätigungscode fehlt oder ist falsch.</error>');return self::FAILURE;}
		if($caseNotices>0&&!$input->getOption('acknowledge-notices')){$output->writeln('<error>Es bestehen '.$caseNotices.' Hinweise (siehe oben). Nach Prüfung mit --acknowledge-notices bestätigen.</error>');return self::FAILURE;}
		try{$result=$this->retention->execute(array_column($preview['cases'],'id'));}catch(\InvalidArgumentException $error){$output->writeln('<error>'.$error->getMessage().'</error>');return self::FAILURE;}
		$output->writeln(json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
		return self::SUCCESS;
	}
}
