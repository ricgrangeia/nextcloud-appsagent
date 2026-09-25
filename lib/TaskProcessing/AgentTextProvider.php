<?php

declare(strict_types=1);

namespace OCA\AppsAgent\TaskProcessing;

use OCA\AppsAgent\Service\AgentService;
use OCP\IL10N;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\TaskTypes\TextToText;

/**
 * Provider de Task Processing que aparece no Assistant como alternativa para a
 * tarefa "texto livre" (core:text2text): quando escolhido, reencaminha o
 * prompt para o AgentService em vez de o responder diretamente com um LLM.
 */
class AgentTextProvider implements ISynchronousProvider {
	public const ID = 'appsagent:agent';

	public function __construct(
		private AgentService $agentService,
		private IL10N $l,
	) {
	}

	public function getId(): string {
		return self::ID;
	}

	public function getName(): string {
		return $this->l->t('Agente Nextcloud (appsagent)');
	}

	public function getTaskTypeId(): string {
		return TextToText::ID;
	}

	public function getExpectedRuntime(): int {
		return 120;
	}

	public function getInputShapeDefaults(): array {
		return [];
	}

	public function getOptionalInputShape(): array {
		return [];
	}

	public function getOptionalInputShapeDefaults(): array {
		return [];
	}

	public function getOptionalOutputShape(): array {
		return [];
	}

	public function getInputShapeEnumValues(): array {
		return [];
	}

	public function getOptionalInputShapeEnumValues(): array {
		return [];
	}

	public function getOutputShapeEnumValues(): array {
		return [];
	}

	public function getOptionalOutputShapeEnumValues(): array {
		return [];
	}

	public function process(?string $userId, array $input, callable $reportProgress): array {
		if (!isset($input['input']) || !is_string($input['input'])) {
			throw new \RuntimeException('appsagent: input em falta ou invalido.');
		}

		return ['output' => $this->agentService->run($userId, $input['input'])];
	}
}
