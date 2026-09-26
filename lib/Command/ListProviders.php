<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Command;

use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\ISynchronousProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Diagnostico: mostra os providers de Task Processing realmente registados
 * nesta instancia, e a que tipo de tarefa cada um responde.
 *
 * Existe porque o ReasoningService assume "texto -> texto" (core:text2text)
 * e nada mais -- antes de construir qualquer coisa com imagens (ex: ler uma
 * foto de um conta-quilometros), e preciso de saber se ha algum provider
 * registado para um tipo de tarefa que aceite imagem como entrada, em vez de
 * supor pelo nome. Ver a que tipo de tarefa serve, nao adivinhar.
 */
class ListProviders extends Command {
	public function __construct(
		private IManager $taskProcessingManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('appsagent:providers');
		$this->setDescription('Lista os providers de Task Processing registados e o tipo de tarefa de cada um');
		$this->addOption(
			'shape',
			null,
			\Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED,
			'Mostra a forma de entrada/saida (nomes dos parametros) de um tipo de tarefa, ex: core:analyze-images'
		);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$taskTypeId = $input->getOption('shape');
		if ($taskTypeId !== null) {
			return $this->showShape((string)$taskTypeId, $output);
		}

		$providers = $this->taskProcessingManager->getProviders();
		if ($providers === []) {
			$output->writeln('Nenhum provider de Task Processing registado.');
			return 0;
		}

		foreach ($providers as $provider) {
			// getId()/getTaskTypeId() so estao confirmados na interface
			// sincrona -- nao presumir que existem nos restantes tipos.
			if ($provider instanceof ISynchronousProvider) {
				$output->writeln(sprintf(
					'%s -> tarefa "%s" | sincrono: sim | classe: %s',
					$provider->getId(),
					$provider->getTaskTypeId(),
					get_class($provider),
				));
			} else {
				$output->writeln(sprintf('(assincrono, sem id/tarefa expostos aqui) | classe: %s', get_class($provider)));
			}
		}

		return 0;
	}

	/**
	 * Nao sei de certeza o nome do metodo do IManager que devolve a forma
	 * (nomes dos parametros de entrada/saida) de um TIPO de tarefa -- em vez
	 * de adivinhar e escrever codigo consumidor em cima de uma suposicao,
	 * tenta os nomes mais provaveis e, se nenhum existir, lista por reflexao
	 * os metodos publicos reais do IManager para se ver o que ha.
	 */
	private function showShape(string $taskTypeId, OutputInterface $output): int {
		foreach (['getAvailableTaskTypes', 'getTaskTypes'] as $metodo) {
			if (!method_exists($this->taskProcessingManager, $metodo)) {
				continue;
			}
			$tipos = $this->taskProcessingManager->{$metodo}();
			$tipo = $tipos[$taskTypeId] ?? null;
			if ($tipo === null) {
				$output->writeln("'{$taskTypeId}' nao encontrado via {$metodo}(). Tipos disponiveis: " . implode(', ', array_keys($tipos)));
				return 1;
			}
			$output->writeln(json_encode($tipo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
			return 0;
		}

		$output->writeln('Nenhum metodo conhecido (getAvailableTaskTypes/getTaskTypes) existe no IManager. Metodos publicos reais:');
		foreach ((new \ReflectionClass($this->taskProcessingManager))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
			$output->writeln('  ' . $m->getName() . '(' . implode(', ', array_map(
				static fn ($p) => $p->getName(),
				$m->getParameters()
			)) . ')');
		}
		return 1;
	}
}
