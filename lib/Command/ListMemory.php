<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Command;

use OCA\AppsAgent\Service\MemoryService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListMemory extends Command {
	/** So estes prefixos sao dados de maquina que ninguem le linha a linha. */
	private const CATALOG_PREFIX = 'catalog:';

	public function __construct(
		private MemoryService $memory,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('appsagent:memory');
		$this->setDescription('Lista as notas guardadas na memoria interna do agente (tabela appsagent_notes)');
		$this->addOption('raw', null, InputOption::VALUE_NONE, 'Mostra os catalogos de API por inteiro, sem resumir');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$notes = $this->memory->recallAll();
		$raw = (bool)$input->getOption('raw');

		if ($notes === []) {
			$output->writeln('A memoria do agente esta vazia.');
			return 0;
		}

		foreach ($notes as $topic => $note) {
			$output->writeln("[{$topic}]");

			$decoded = json_decode($note, true);
			if (!$raw && str_starts_with($topic, self::CATALOG_PREFIX) && is_array($decoded)) {
				// O catalogo de uma app pode ter centenas de operacoes -- util
				// para o agente, ilegivel aqui. --raw mostra-o por inteiro se
				// for mesmo preciso depurar uma entrada especifica.
				$ops = is_array($decoded['operations'] ?? null) ? count($decoded['operations']) : 0;
				$docs = is_array($decoded['docs'] ?? null) ? $decoded['docs'] : [];
				$output->writeln(sprintf(
					'  %d operacoes | descoberto em %s%s',
					$ops,
					(string)($decoded['discovered_at'] ?? '?'),
					$docs !== [] ? ' | docs: ' . implode(', ', $docs) : ''
				));
			} elseif (str_starts_with($topic, 'app:') && is_array($decoded)) {
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
