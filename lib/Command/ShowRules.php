<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Command;

use OCA\AppsAgent\Service\PromptRulesService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShowRules extends Command {
	public function __construct(
		private PromptRulesService $promptRules,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('appsagent:rules');
		$this->setDescription('Mostra onde vivem as regras de comportamento do agente e o seu conteudo atual');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->writeln('Localizacao: ' . $this->promptRules->describeLocation());
		$output->writeln('');

		$rules = (array)($this->promptRules->loadDocument()['rules'] ?? []);
		if ($rules === []) {
			$output->writeln('Sem regras guardadas.');
			return 0;
		}

		foreach ($rules as $rule) {
			if (!is_array($rule)) {
				continue;
			}
			$name = (string)($rule['name'] ?? '?');
			$tag = in_array($name, PromptRulesService::PROTECTED_RULE_NAMES, true) ? '[base, protegida]' : '[aprendida]';
			$output->writeln("[{$name}] {$tag}");
			$output->writeln('  ' . (string)($rule['text'] ?? ''));
			if (isset($rule['learned_at'])) {
				$output->writeln('  (aprendida em: ' . $rule['learned_at'] . ')');
			}
			$output->writeln('');
		}

		return 0;
	}
}
