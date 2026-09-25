<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Settings;

use OCA\AppsAgent\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Settings\ISettings;

/**
 * Definicoes pessoais: cada utilizador liga a sua propria conta Telegram a
 * conta Nextcloud dele, gerando um codigo aqui e mandando-o como mensagem
 * ao bot (ver TelegramClient::consumeLinkCode()).
 */
class Personal implements ISettings {
	public function __construct(
		private IConfig $config,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getForm(): \OCP\AppFramework\Http\TemplateResponse {
		$userId = (string)$this->userSession->getUser()?->getUID();

		$map = json_decode(trim($this->config->getAppValue(Application::APP_ID, 'telegram_user_map', '')), true);
		$map = is_array($map) ? $map : [];
		$linkedChatId = array_search($userId, $map, true);

		$pendingCode = '';
		if ($linkedChatId === false) {
			$expiresAt = (int)$this->config->getUserValue($userId, Application::APP_ID, 'telegram_link_code_expires', '0');
			if ($expiresAt > time()) {
				$pendingCode = (string)$this->config->getUserValue($userId, Application::APP_ID, 'telegram_link_code', '');
			}
		}

		return new TemplateResponse(Application::APP_ID, 'personal', [
			'linked' => $linkedChatId !== false,
			'chatId' => $linkedChatId !== false ? (string)$linkedChatId : '',
			'pendingCode' => $pendingCode,
			'generateUrl' => $this->urlGenerator->linkToRoute('appsagent.telegram_link.generate'),
		], '');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}
}
