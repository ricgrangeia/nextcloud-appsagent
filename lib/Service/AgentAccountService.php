<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Service;

use OCA\AppsAgent\AppInfo\Application;
use OCP\IConfig;

/**
 * Credenciais da conta Nextcloud dedicada ao agente (partilhada por
 * CalendarService e DiscoveryService para chamadas CalDAV/OCS).
 */
class AgentAccountService {
	public function __construct(
		private IConfig $config,
	) {
	}

	/** @return array{0: string, 1: string} */
	public function credentials(): array {
		$username = $this->config->getAppValue(Application::APP_ID, 'nc_username', '');
		$password = $this->config->getAppValue(Application::APP_ID, 'nc_app_password', '');
		if ($username === '' || $password === '') {
			throw new \RuntimeException(
				'appsagent: nc_username/nc_app_password nao configurados. Corre: ' .
				'occ config:app:set appsagent nc_username --value=... e ' .
				'occ config:app:set appsagent nc_app_password --value=...'
			);
		}
		return [$username, $password];
	}
}
