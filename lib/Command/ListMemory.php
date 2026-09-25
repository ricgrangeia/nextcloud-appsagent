<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Command;

use OCA\AppsAgent\Service\MemoryService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListMemory extends Command {
	public function __construct(
		private MemoryService $memory,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('appsagent:memory');
		$this->setDescription('Lista as notas guardadas na memoria interna do agente (tabela appsagent_notes)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$notes = $this->memory->recallAll();

		if ($notes === []) {
			$output->writeln('A memoria do agente esta vazia.');
			return 0;
		}

		foreach ($notes as $topic => $note) {
			$output->writeln("[{$topic}]");

			$decoded = json_decode($note, true);
			if (str_starts_with($topic, 'app:') && is_array($decoded)) {
				foreach ($decoded as $recipe) {
					$output->writeln('  - ' . json_encode($recipe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
				}
			} else {
				$output->writeln('  ' . str_replace("\n", "\n  ", $note));
			}
			$output->writeln('');
		}

		return 0;
	}
}
