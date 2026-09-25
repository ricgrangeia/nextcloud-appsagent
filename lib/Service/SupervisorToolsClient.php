<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Service;

use OCA\AppsAgent\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;

/**
 * Ferramentas de calculo, pesquisa web e datas -- reutilizadas do proprio Ai
 * Supervisor (o mesmo LangGraph que serve o especialista "appsagent" via
 * TaskProcessing), em vez de reimplementadas aqui. Contrato combinado com
 * esse lado: POST /tools/<nome>, sempre HTTP 200, sucesso/erro distinguido
 * pela chave presente no corpo (result/results vs error).
 */
class SupervisorToolsClient {
	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
	) {
	}

	private function baseUrl(): string {
		return rtrim($this->config->getAppValue(Application::APP_ID, 'supervisor_base_url', ''), '/');
	}

	private function apiKey(): string {
		return $this->config->getAppValue(Application::APP_ID, 'supervisor_api_key', '');
	}

	private function call(string $path, array $body): array {
		$baseUrl = $this->baseUrl();
		if ($baseUrl === '') {
			return ['error' => 'appsagent: supervisor_base_url nao configurado (Definicoes de administracao > Apps Agent).'];
		}

		try {
			$response = $this->clientService->newClient()->post($baseUrl . $path, [
				'json' => $body,
				'headers' => ['x-api-key' => $this->apiKey()],
				'timeout' => 20,
			]);
		} catch (\Throwable $e) {
			return ['error' => 'Falha de ligacao ao Ai Supervisor (' . $path . '): ' . $e->getMessage()];
		}

		$decoded = json_decode((string)$response->getBody(), true);
		return is_array($decoded) ? $decoded : ['error' => 'Resposta invalida do Ai Supervisor em ' . $path];
	}

	public function calculate(string $expression): array {
		if (trim($expression) === '') {
			return ['error' => 'calculator precisa de "expression" nao vazia.'];
		}
		return $this->call('/tools/calc', ['expression' => $expression]);
	}

	public function webSearch(string $query, int $maxResults): array {
		if (trim($query) === '') {
			return ['error' => 'web_search precisa de "query" nao vazia.'];
		}
		$body = ['query' => $query];
		if ($maxResults > 0) {
			$body['max_results'] = $maxResults;
		}
		return $this->call('/tools/web_search', $body);
	}

	public function dateTimeQuestion(string $question): array {
		if (trim($question) === '') {
			return ['error' => 'datetime_calc precisa de "question" nao vazia.'];
		}
		return $this->call('/tools/datetime', ['question' => $question]);
	}
}
