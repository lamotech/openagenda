<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Command;

use OCA\OpenAgenda\Service\Demo\DemoSeeder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Destructive: wipes all OpenAgenda data and re-seeds the demo. Intended for
 * demo and test environments only; requires --force.
 */
class ResetDemo extends Command {
	public function __construct(
		private DemoSeeder $seeder,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('openagenda:demo:reset')
			->setDescription('SLETTER alle OpenAgenda-data (inkl. auditlog og mock-OpenCase) og indlæser demo-data igen. Kun til demo/test.')
			->addOption('force', null, InputOption::VALUE_NONE, 'Bekræft at alle data skal slettes');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$input->getOption('force')) {
			$output->writeln('<error>Denne kommando sletter ALLE OpenAgenda-data. Kør igen med --force for at bekræfte.</error>');
			return 1;
		}
		$log = static fn (string $line) => $output->writeln($line);
		$this->seeder->purgeAll($log);
		$this->seeder->seed([], $log);
		return 0;
	}
}
