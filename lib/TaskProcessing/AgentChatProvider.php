<?php

declare(strict_types=1);

namespace OCA\AppsAgent\TaskProcessing;

use OCA\AppsAgent\Service\AgentService;
use OCP\IL10N;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\TaskTypes\TextToTextChat;

/**
 * Provider de Task Processing para a tarefa "Chat" (core:text2text:chat) --
 * distinta de "texto livre" (core:text2text, ver AgentTextProvider): esta
 * recebe tambem o historico da conversa, nao so a mensagem atual.
 */
class AgentChatProvider implements ISynchronousProvider {
	public const ID = 'appsagent:agent-chat';

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
		return TextToTextChat::ID;
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

		$instruction = $input['input'];
		$history = is_array($input['history'] ?? null) ? $input['history'] : [];
		if ($history !== []) {
			$instruction = "Historico da conversa ate agora:\n"
				. implode("\n", array_map(function ($entry) {
					$decoded = json_decode($entry, true);
					if (is_array($decoded) && isset($decoded['role'], $decoded['content'])) {
						return $decoded['role'] === 'user' ? 'Utilizador: ' . $decoded['content']
							: ($decoded['role'] === 'assistant' ? 'Assistente: ' . $decoded['content']
							: $decoded['role'] . ': ' . $decoded['content']);
					}
					return (string)$entry;
				}, $history))
				. "\n\nNova mensagem do utilizador: " . $instruction;
		}

		return ['output' => $this->agentService->run($userId, $instruction)];
	}
}
