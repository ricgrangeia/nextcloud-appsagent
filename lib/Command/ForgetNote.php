<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Command;

use OCA\AppsAgent\Service\MemoryService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Apaga apontamentos da memoria interna do agente.
 *
 * Existe porque ate agora so havia forma de LER (appsagent:memory) e de
 * apagar receitas de uma app (appsagent:forget) -- um apontamento guardado
 * por engano ficava la para sempre, e voltava a aparecer nas respostas.
 *
 * O --prefixo serve para o lixo que se acumula em serie, como os topicos
 * telegram_update_*.
 */
class ForgetNote extends Command {
	public function __construct(
		private MemoryService $memory,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('appsagent:forget-note');
		$this->setDescription('Apaga apontamentos da memoria interna do agente (nao mexe nas receitas de apps)');
		$this->addArgument('topic', InputArgument::OPTIONAL, 'Topico exato a apagar');
		$this->addOption('prefixo', null, InputOption::VALUE_REQUIRED, 'Apaga todos os topicos que comecem por isto');
		$this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Mostra o que seria apagado, sem apagar');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$topic = (string)$input->getArgument('topic');
		$prefixo = (string)$input->getOption('prefixo');
		$simular = (bool)$input->getOption('dry-run');

		if (($topic === '') === ($prefixo === '')) {
			$output->writeln('ERRO: indica um topico OU --prefixo, nao ambos nem nenhum.');
			return 1;
		}

		$alvos = $topic !== ''
			? [$topic]
			: array_values(array_filter(
				array_keys($this->memory->recallAll()),
				static fn (string $t): bool => str_starts_with($t, $prefixo)
			));

		if ($alvos === []) {
			$output->writeln('Nao ha nada a apagar.');
			return 0;
		}

		$apagados = 0;
		foreach ($alvos as $alvo) {
			if ($simular) {
				$output->writeln('  (simulacao) ' . $alvo);
				continue;
			}
			if ($this->memory->forgetNote($alvo)) {
				$output->writeln('  apagado: ' . $alvo);
				$apagados++;
			} else {
				// forgetNote recusa topicos "app:" -- sao receitas, e apagam-se
				// com appsagent:forget <app_id>, que e explicito sobre o que leva.
				$output->writeln('  ignorado: ' . $alvo . ' (nao existe, ou e uma receita de app)');
			}
		}

		if (!$simular) {
			$output->writeln($apagados . ' apontamento(s) apagado(s).');
		}

		return 0;
	}
}
