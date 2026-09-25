<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Service;

use OCA\AppsAgent\TaskProcessing\AgentTextProvider;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\TaskTypes\TextToText;

/**
 * Motor de raciocinio do agente: em vez de chamar um LLM diretamente, reutiliza
 * o provider de "texto livre" (core:text2text) ja configurado no Nextcloud para
 * o Assistant -- seja qual for o modelo/LLM local ou remoto que o administrador
 * la tiver ligado. Evita deliberadamente selecionar o proprio AgentTextProvider,
 * para nao entrar em recursao infinita.
 */
class ReasoningService {
	public function __construct(
		private IManager $taskProcessingManager,
	) {
	}

	public function complete(string $prompt): string {
		$provider = $this->findOtherTextProvider();
		if ($provider === null) {
			throw new \RuntimeException(
				'appsagent: nao encontrei outro provider de "texto livre" (core:text2text) ' .
				'configurado no Nextcloud para usar como motor de raciocinio. Configura um ' .
				'(o teu LLM local, por exemplo) em Definicoes de administracao > ' .
				'Inteligencia artificial, antes de usares o appsagent.'
			);
		}

		$result = $provider->process(null, ['input' => $prompt], static fn (): bool => true);
		return (string)($result['output'] ?? '');
	}

	private function findOtherTextProvider(): ?ISynchronousProvider {
		foreach ($this->taskProcessingManager->getProviders() as $provider) {
			if (!$provider instanceof ISynchronousProvider) {
				continue;
			}
			if ($provider->getTaskTypeId() !== TextToText::ID) {
				continue;
			}
			if ($provider->getId() === AgentTextProvider::ID) {
				continue;
			}
			return $provider;
		}
		return null;
	}
}
