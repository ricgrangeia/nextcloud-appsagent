<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Service;

use OCA\AppsAgent\AppInfo\Application;
use OCP\IConfig;
use OCP\IURLGenerator;

/**
 * Resolve os URLs para chamadas que o proprio agente faz ao seu Nextcloud
 * (CalDAV, OCS). Em instalacoes Docker onde o nome publico nao resolve para
 * nada que esteja realmente a escutar visto de dentro do proprio container
 * (o DNS interno pode apontar para IPs sem porta 443 aberta), permite
 * configurar um endereco interno (ex: http://127.0.0.1) para onde a ligacao
 * e feita de facto, mantendo o cabecalho Host original para o
 * Apache/Nginx continuar a rotear para o virtual host correto.
 *
 * occ config:app:set appsagent internal_base_url --value="http://127.0.0.1"
 */
class InternalHttpService {
	public function __construct(
		private IConfig $config,
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * @param string $path caminho absoluto (ex: "/remote.php/dav/...")
	 * @return array{0: string, 1: array<string,string>} [url a usar, cabecalhos extra a acrescentar]
	 */
	public function resolve(string $path): array {
		$publicUrl = $this->urlGenerator->getAbsoluteURL($path);
		$internalBase = trim($this->config->getAppValue(Application::APP_ID, 'internal_base_url', ''));

		if ($internalBase === '') {
			return [$publicUrl, []];
		}

		$publicHost = parse_url($publicUrl, PHP_URL_HOST);
		$internalUrl = rtrim($internalBase, '/') . $path;

		return [$internalUrl, $publicHost !== null ? ['Host' => $publicHost] : []];
	}
}
