<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Service;

use OCA\AppsAgent\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Executor generico de operacoes HTTP descobertas pelo RouteDiscoveryService
 * (routes.php, atributos dos controladores, openapi.json).
 *
 * Capacidade deliberadamente arriscada: o agente escolhe metodo+caminho por
 * conta propria com base no que descobriu, sem eu ter revisto cada operacao
 * especifica (ao contrario do CalendarService). Por isso:
 *  - fica DESLIGADA por omissao (generic_api_enabled=yes para ativar);
 *  - DELETE fica bloqueado mesmo depois de ativar, salvo generic_api_allow_delete=yes;
 *  - cada chamada e validada contra o catalogo real da app -- recusa
 *    metodo+caminho que nao existam la (nada inventado pelo modelo);
 *  - cada chamada fica registada nos logs do Nextcloud (nivel warning);
 *  - erros HTTP (4xx/5xx) sao devolvidos como observacao em vez de excecao,
 *    para o modelo os ler e corrigir a tentativa seguinte.
 */
class DynamicApiService {
	private const MAX_BODY_CHARS = 4000;

	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
		private AgentAccountService $agentAccount,
		private InternalHttpService $internalHttp,
		private RouteDiscoveryService $routes,
		private LoggerInterface $logger,
	) {
	}

	private function isEnabled(): bool {
		return $this->config->getAppValue(Application::APP_ID, 'generic_api_enabled', 'no') === 'yes';
	}

	private function deleteAllowed(): bool {
		return $this->config->getAppValue(Application::APP_ID, 'generic_api_allow_delete', 'no') === 'yes';
	}

	public function call(string $appId, string $method, string $path, ?array $body, ?array $query): array {
		if (!$this->isEnabled()) {
			return ['error' =>
				'appsagent: execucao generica de API esta desligada. Ativa com ' .
				'occ config:app:set appsagent generic_api_enabled --value=yes ' .
				'depois de perceberes o risco (ver README).'];
		}

		$method = strtoupper(trim($method));
		if ($method === 'DELETE' && !$this->deleteAllowed()) {
			return ['error' =>
				'appsagent: DELETE via API generica esta bloqueado. Se o utilizador quiser mesmo ' .
				'permitir: occ config:app:set appsagent generic_api_allow_delete --value=yes.'];
		}

		$path = '/' . ltrim(trim($path), '/');
		$operation = $this->routes->isDeclared($appId, $method, $path);
		if ($operation === null) {
			return ['error' =>
				"A operacao {$method} {$path} nao existe no catalogo da app '{$appId}' -- recusado. " .
				'Usa discovery_describe_app_api para veres as operacoes reais (o caminho tem de ' .
				'incluir o prefixo, ex: /apps/<app>/... ou /ocs/v2.php/apps/<app>/...).'];
		}

		[$username, $password] = $this->agentAccount->credentials();
		[$url, $extraHeaders] = $this->internalHttp->resolve($path);

		$options = [
			'auth' => [$username, $password],
			'headers' => $extraHeaders + ['OCS-APIRequest' => 'true', 'Accept' => 'application/json'],
			'timeout' => 30,
			// 4xx/5xx nao lancam excecao -- queremos devolver o estado/corpo ao modelo.
			'http_errors' => false,
		];
		if ($query !== null && $query !== []) {
			$options['query'] = $query;
		}
		if ($body !== null && $body !== []) {
			$options['json'] = $body;
		}

		$this->logger->warning('appsagent: chamada generica de API executada pelo agente', [
			'app' => $appId,
			'method' => $method,
			'path' => $path,
		]);

		try {
			$response = $this->clientService->newClient()->request($method, $url, $options);
		} catch (\Throwable $e) {
			return ['error' => 'Falha de ligacao ao chamar ' . $method . ' ' . $path . ': ' . $e->getMessage()];
		}

		$status = $response->getStatusCode();
		$raw = (string)$response->getBody();
		$decoded = json_decode($raw, true);

		$result = [
			'status' => $status,
			'ok' => $status >= 200 && $status < 300,
			'body' => $decoded ?? $this->truncate($raw),
		];

		if ($status === 412) {
			$result['hint'] = 'HTTP 412 = verificacao CSRF falhou: esta rota nao e chamavel com app password. '
				. 'Procura no catalogo uma operacao equivalente marcada no_csrf=true ou do tipo ocs.';
		} elseif ($status === 401 || $status === 403) {
			$result['hint'] = 'Sem permissao com a conta do agente -- confirma que a conta tem acesso a este recurso.';
		} elseif ($status === 404) {
			$result['hint'] = 'Nao encontrado -- verifica os parametros do caminho ({id}, etc.) e o prefixo.';
		} elseif ($status >= 500) {
			// Removido daqui um "nota: parece exigir CSRF" que disparava para
			// QUALQUER falha numa rota com no_csrf=false -- incluindo este caso
			// real, onde um 500 (a propria app a rebentar) foi apresentado ao
			// utilizador como "falhou por CSRF", que era simplesmente falso: o
			// erro e interno aquela app, nao tem nada a ver com autenticacao.
			$result['hint'] = 'Erro interno da propria app (nao e falha de autenticacao) -- '
				. 'confirma os nomes e tipos exatos dos campos do corpo contra o que a app espera '
				. '(discovery_read_app_docs, se houver documentacao) antes de desistires.';
		}

		return $result;
	}

	private function truncate(string $text): string {
		if (strlen($text) <= self::MAX_BODY_CHARS) {
			return $text;
		}
		return substr($text, 0, self::MAX_BODY_CHARS) . '... [truncado]';
	}
}
