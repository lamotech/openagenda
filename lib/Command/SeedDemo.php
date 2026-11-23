<?php

declare(strict_types=1);

namespace OCA\OpenAgenda\Command;

use OCA\OpenAgenda\Service\Demo\DemoSeeder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SeedDemo extends Command {
	public function __construct(
		private DemoSeeder $seeder,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('openagenda:demo:seed')
			->setDescription('Indlæs fiktive demo-data (idempotent – kan køres flere gange)')
			->addOption('role', 'r', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Tildel en eksisterende bruger en rolle: uid:rolle (admin|secretary|caseworker|politician). Kan gentages.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$roles = [];
		foreach ((array)$input->getOption('role') as $assignment) {
			$parts = explode(':', (string)$assignment, 2);
			if (count($parts) !== 2) {
				$output->writeln('<error>Ugyldig --role: ' . $assignment . ' (forventet uid:rolle)</error>');
				return 1;
			}
			$roles[$parts[0]] = $parts[1];
		}
		$this->seeder->seed($roles, static fn (string $line) => $output->writeln($line));
		return 0;
	}
}
