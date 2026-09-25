<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Controller;

use OCA\AppsAgent\Service\AgentService;
use OCA\AppsAgent\Service\MemoryService;
use OCA\AppsAgent\Service\TelegramClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class TelegramController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private AgentService $agentService,
		private TelegramClient $telegramClient,
		private MemoryService $memory,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Webhook do Telegram. Configura-se com:
	 *   https://api.telegram.org/bot<TOKEN>/setWebhook?url=<nextcloud>/apps/appsagent/telegram/webhook&secret_token=<segredo>
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	public function webhook(): DataResponse {
		$configuredSecret = $this->telegramClient->webhookSecret();
		$providedSecret = $this->request->getHeader('X-Telegram-Bot-Api-Secret-Token');
		if ($configuredSecret !== '' && $providedSecret !== $configuredSecret) {
			return new DataResponse(['ok' => false], 401);
		}

		$payload = json_decode(file_get_contents('php://input') ?: '', true);
		$updateId = $payload['update_id'] ?? null;
		$message = $payload['message'] ?? null;
		$chatId = $message['chat']['id'] ?? null;
		$fromId = $message['from']['id'] ?? null;
		$text = $message['text'] ?? null;

		if ($chatId === null || $text === null) {
			return new DataResponse(['ok' => true]);
		}

		// O Telegram tem um timeout curto para o webhook e reenvia a MESMA
		// mensagem (mesmo update_id) se nao respondermos a tempo -- o que,
		// como o nosso agente pode demorar mais que isso, causava respostas
		// duplicadas. Em vez de tentar responder mais depressa (o truque de
		// fechar a ligacao cedo nao funciona de forma fiavel neste servidor),
		// marcamos cada update_id como "ja visto" logo a entrada e ignoramos
		// reenvios -- so o primeiro pedido para cada update_id e processado.
		if ($updateId !== null) {
			$topic = 'telegram_update_' . $updateId;
			if ($this->memory->recall($topic) !== null) {
				return new DataResponse(['ok' => true]);
			}
			$this->memory->remember($topic, 'processado');
		}

		// Se a mensagem for um codigo valido de ligacao (gerado nas Definicoes
		// pessoais), liga este chat_id ao utilizador Nextcloud e para por
		// aqui -- nao passa pelo agente.
		$linkedUser = $this->telegramClient->consumeLinkCode((string)$text, (int)$fromId);
		if ($linkedUser !== null) {
			$this->telegramClient->sendMessage(
				(int)$chatId,
				"Ligado com sucesso a conta Nextcloud '{$linkedUser}'.",
			);
			return new DataResponse(['ok' => true]);
		}

		if (!$this->telegramClient->isAllowedUser((int)$fromId)) {
			$this->telegramClient->sendMessage((int)$chatId, 'Nao estas autorizado a usar este agente.');
			return new DataResponse(['ok' => true]);
		}

		$this->telegramClient->sendTyping((int)$chatId);
		$nextcloudUserId = $this->telegramClient->resolveNextcloudUser((int)$fromId);

		try {
			$reply = $this->agentService->run($nextcloudUserId, (string)$text, function () use ($chatId): void {
				$this->telegramClient->sendTyping((int)$chatId);
			});
		} catch (\Throwable $e) {
			$this->logger->error('appsagent: erro a processar mensagem do telegram', ['exception' => $e]);
			$reply = 'Ocorreu um erro: ' . $e->getMessage();
		}

		$this->telegramClient->sendMessage((int)$chatId, $reply !== '' ? $reply : 'Feito.');
		return new DataResponse(['ok' => true]);
	}
}
