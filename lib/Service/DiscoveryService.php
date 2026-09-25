<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Service;

use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;

class DiscoveryService {
	public function __construct(
		private IAppManager $appManager,
		private IClientService $clientService,
		private AgentAccountService $agentAccount,
		private InternalHttpService $internalHttp,
	) {
	}

	/** @return string[] */
	public function listEnabledApps(): array {
		return $this->appManager->getEnabledApps();
	}

	/**
	 * Nome e resumo de uma app, para o agente mapear o nome que o utilizador
	 * usa (ex: "Notas", "Deck") ao app_id tecnico certo (ex: "notes", "deck").
	 */
	public function getAppInfo(string $appId): array {
		$info = $this->appManager->getAppInfo($appId);
		if ($info === null) {
			return ['error' => "Nao encontrei informacao da app '{$appId}'."];
		}
		return [
			'id' => $appId,
			'name' => $this->firstString($info['name'] ?? null, $appId),
			'summary' => $this->firstString($info['summary'] ?? null, ''),
		];
	}

	/** @return list<array{id: string, name: string, summary: string}> */
	public function listEnabledAppsDetailed(): array {
		$result = [];
		foreach ($this->appManager->getEnabledApps() as $appId) {
			$info = $this->getAppInfo($appId);
			if (!isset($info['error'])) {
				$result[] = $info;
			}
		}
		return $result;
	}

	/** info.xml por vezes devolve string, por vezes array multi-idioma. */
	private function firstString(mixed $value, string $fallback): string {
		if (is_string($value)) {
			return $value;
		}
		if (is_array($value) && $value !== []) {
			return (string)($value['en'] ?? reset($value));
		}
		return $fallback;
	}

	/**
	 * Le (so leitura) as capacidades que o Nextcloud reporta para uma app
	 * especifica -- util para o agente perceber o que uma app suporta antes
	 * de assumir que consegue agir sobre ela.
	 */
	public function describeApp(string $appId): array {
		$capabilities = $this->allCapabilities();
		if (!array_key_exists($appId, $capabilities)) {
			return ['error' => "A app '{$appId}' nao reporta capacidades conhecidas (pode nao estar ativa)."];
		}
		return [$appId => $capabilities[$appId]];
	}

	private function allCapabilities(): array {
		[$username, $password] = $this->agentAccount->credentials();
		[$url, $extraHeaders] = $this->internalHttp->resolve('/ocs/v1.php/cloud/capabilities');

		$response = $this->clientService->newClient()->get($url, [
			'auth' => [$username, $password],
			'headers' => $extraHeaders + ['OCS-APIRequest' => 'true', 'Accept' => 'application/json'],
			'query' => ['format' => 'json'],
			'timeout' => 30,
		]);

		$body = json_decode((string)$response->getBody(), true);
		return $body['ocs']['data']['capabilities'] ?? [];
	}
}
