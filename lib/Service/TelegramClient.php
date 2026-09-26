<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Service;

use OCA\AppsAgent\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;

class TelegramClient {
	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
		private IUserManager $userManager,
	) {
	}

	private function token(): string {
		$token = $this->config->getAppValue(Application::APP_ID, 'telegram_bot_token', '');
		if ($token === '') {
			throw new \RuntimeException('appsagent: telegram_bot_token nao configurado.');
		}
		return $token;
	}

	public function sendMessage(int $chatId, string $text): void {
		$url = 'https://api.telegram.org/bot' . $this->token() . '/sendMessage';
		$this->clientService->newClient()->post($url, [
			'json' => ['chat_id' => $chatId, 'text' => $text],
			'timeout' => 30,
		]);
	}

	/** Mostra o indicador "a escrever..." -- dura uns segundos no Telegram. */
	public function sendTyping(int $chatId): void {
		$url = 'https://api.telegram.org/bot' . $this->token() . '/sendChatAction';
		try {
			$this->clientService->newClient()->post($url, [
				'json' => ['chat_id' => $chatId, 'action' => 'typing'],
				'timeout' => 10,
			]);
		} catch (\Throwable) {
			// Puramente cosmetico -- nao vale a pena falhar a resposta real por isto.
		}
	}

	/**
	 * Sem allowlist configurada, qualquer pessoa que descubra o bot consegue
	 * usa-lo -- nao recomendado. Com allowlist configurada, tambem aceita
	 * quem ja se ligou por codigo nas Definicoes pessoais (isso ja exigiu
	 * uma sessao Nextcloud autenticada, e um sinal de autorizacao tao bom
	 * como estar na lista).
	 */
	public function isAllowedUser(int $telegramUserId): bool {
		$raw = trim($this->config->getAppValue(Application::APP_ID, 'telegram_allowed_user_ids', ''));
		if ($raw === '') {
			return true;
		}
		$allowed = array_map('trim', explode(',', $raw));
		if (in_array((string)$telegramUserId, $allowed, true)) {
			return true;
		}
		return $this->resolveNextcloudUser($telegramUserId) !== null;
	}

	public function webhookSecret(): string {
		return $this->config->getAppValue(Application::APP_ID, 'telegram_webhook_secret', '');
	}

	/**
	 * Liga um ID Telegram a um utilizador Nextcloud, via o mapa configurado
	 * (JSON {"id_telegram": "utilizador", ...}). Sem entrada correspondente,
	 * o Telegram continua a correr sem utilizador associado (null) -- o que
	 * bloqueia ferramentas restritas a utilizadores autenticados, como
	 * agent_learn_rule.
	 */
	public function resolveNextcloudUser(int $telegramUserId): ?string {
		$raw = trim($this->config->getAppValue(Application::APP_ID, 'telegram_user_map', ''));
		if ($raw === '') {
			return null;
		}
		$map = json_decode($raw, true);
		if (!is_array($map)) {
			return null;
		}
		$user = $map[(string)$telegramUserId] ?? null;
		return is_string($user) && $user !== '' ? $user : null;
	}

	/**
	 * Sentido inverso: dado um utilizador Nextcloud, o chat_id do Telegram
	 * para lhe mandar uma mensagem sem ele ter escrito primeiro (ver
	 * ProactiveDigestJob). Assume conversa privada, onde o chat_id do
	 * Telegram e sempre igual ao id do utilizador Telegram -- verdade para
	 * uma conversa 1:1 com o bot, que e o unico uso previsto aqui.
	 */
	public function findChatIdForUser(string $nextcloudUserId): ?int {
		$raw = trim($this->config->getAppValue(Application::APP_ID, 'telegram_user_map', ''));
		if ($raw === '') {
			return null;
		}
		$map = json_decode($raw, true);
		if (!is_array($map)) {
			return null;
		}
		foreach ($map as $telegramUserId => $mappedUser) {
			if ($mappedUser === $nextcloudUserId && ctype_digit((string)$telegramUserId)) {
				return (int)$telegramUserId;
			}
		}
		return null;
	}

	/**
	 * Tenta consumir $rawText como um codigo de ligacao gerado nas
	 * Definicoes pessoais de algum utilizador (TelegramLinkController). Se
	 * for valido e ainda nao tiver expirado, liga esse utilizador ao ID
	 * Telegram que mandou a mensagem e devolve o utilizador; caso contrario
	 * devolve null (a mensagem nao era um codigo, ou ja expirou).
	 */
	public function consumeLinkCode(string $rawText, int $telegramUserId): ?string {
		$code = strtoupper(trim($rawText));
		if ($code === '' || !preg_match('/^[A-F0-9]{6}$/', $code)) {
			return null;
		}

		$matchedUserId = null;
		$this->userManager->callForAllUsers(function (IUser $user) use ($code, &$matchedUserId): void {
			if ($matchedUserId !== null) {
				return;
			}
			$uid = $user->getUID();
			$pending = strtoupper((string)$this->config->getUserValue($uid, Application::APP_ID, 'telegram_link_code', ''));
			if ($pending === '' || $pending !== $code) {
				return;
			}
			$expiresAt = (int)$this->config->getUserValue($uid, Application::APP_ID, 'telegram_link_code_expires', '0');
			if ($expiresAt < time()) {
				return;
			}
			$matchedUserId = $uid;
		});

		if ($matchedUserId === null) {
			return null;
		}

		$this->config->deleteUserValue($matchedUserId, Application::APP_ID, 'telegram_link_code');
		$this->config->deleteUserValue($matchedUserId, Application::APP_ID, 'telegram_link_code_expires');

		$map = json_decode(trim($this->config->getAppValue(Application::APP_ID, 'telegram_user_map', '')), true);
		$map = is_array($map) ? $map : [];
		$map[(string)$telegramUserId] = $matchedUserId;
		$this->config->setAppValue(Application::APP_ID, 'telegram_user_map', json_encode($map, JSON_UNESCAPED_UNICODE));

		return $matchedUserId;
	}
}
