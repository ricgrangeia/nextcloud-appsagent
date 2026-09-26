<?php

declare(strict_types=1);

namespace OCA\AppsAgent\TaskProcessing;

use OCA\AppsAgent\Service\AgentService;
use OCP\IL10N;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\TaskTypes\TextToTextChatWithTools;

/**
 * Provider de Task Processing para "Conversar com ferramentas"
 * (core:text2text:chatwithtools) -- o modo que o painel do Assistente usa
 * quando quer poder chamar ferramentas (ex: pesquisa web) durante a conversa.
 *
 * Ao contrario do "Chat" simples (AgentChatProvider), este tipo de tarefa
 * inverte o controlo: em vez do provider executar as ferramentas, ele deveria
 * devolver em "tool_calls" quais quer chamar, e e o proprio Nextcloud que as
 * executa e devolve o resultado num turno seguinte (via "tool_message").
 *
 * O AgentService ja tem o seu proprio ciclo de ferramentas completo e
 * fechado (calendario, apps dinamicas, memoria, etc.) -- nao faz sentido
 * duplicar isso atraves do mecanismo de tool_calls do Nextcloud, que so
 * conhece as ferramentas que OUTRAS apps registam nesse formato. Por isso,
 * este provider resolve a instrucao integralmente por si (chamando
 * AgentService::run(), que corre o proprio ciclo internamente) e devolve
 * sempre "tool_calls" vazio -- do ponto de vista do Nextcloud, e uma
 * resposta final, nunca um pedido de ferramenta.
 */
class AgentChatWithToolsProvider implements ISynchronousProvider {
	public const ID = 'appsagent:agent-chat-with-tools';

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
		return TextToTextChatWithTools::ID;
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

		// Resultados de ferramentas de um turno anterior deste MESMO tipo de
		// tarefa (chamadas por outro provider, antes de o admin mudar para o
		// appsagent) -- nunca geradas por nos proprios, ja que nunca pedimos
		// tool_calls. Passa-los como contexto extra em vez de os ignorar.
		$toolMessage = trim((string)($input['tool_message'] ?? ''));
		if ($toolMessage !== '' && $toolMessage !== '[]') {
			$instruction = 'Resultados de ferramentas de um passo anterior: ' . $toolMessage . "\n\n" . $instruction;
		}

		// channelKey aplica a exigencia de dois turnos separados para
		// eliminacoes tambem aqui (ver AgentService::guardDestructive) -- sem
		// id de sessao proprio nesta camada, usa-se o utilizador como canal.
		$channelKey = $userId !== null ? 'assistant:' . $userId : null;
		return [
			'output' => $this->agentService->run($userId, $instruction, null, $channelKey),
			'tool_calls' => '[]',
		];
	}
}
