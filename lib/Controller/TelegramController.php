<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Controller;

use OCA\AppsAgent\Service\AgentService;
use OCA\AppsAgent\Service\ConversationService;
use OCA\AppsAgent\Service\ImageAnalysisService;
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
		private ConversationService $conversation,
		private ImageAnalysisService $imageAnalysis,
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
		// Uma foto NAO vem em "text" -- o Telegram usa "caption" para o texto
		// que a acompanha (pode nem existir). Sem isto, uma foto enviada era
		// silenciosamente ignorada, sem resposta nenhuma.
		$text = $message['text'] ?? null;
		$photos = $message['photo'] ?? null;
		$caption = $message['caption'] ?? null;

		if ($chatId === null || ($text === null && !is_array($photos))) {
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
		$chatKey = ConversationService::telegramKey((int)$chatId);

		if (is_array($photos) && $photos !== []) {
			$this->handlePhoto((int)$chatId, $chatKey, $photos, is_string($caption) ? $caption : '');
			return new DataResponse(['ok' => true]);
		}

		// Sem isto, cada mensagem chegava ao agente sozinha -- "sim" ou "confirmo"
		// nao dizem nada por si so. Um bug real: confirmar uma eliminacao com "sim
		// força" fez o agente inventar uma acao totalmente diferente, porque nao
		// tinha rasto nenhum do que estava a confirmar.
		$instruction = $this->conversation->withHistory($chatKey, (string)$text);

		try {
			$reply = $this->agentService->run($nextcloudUserId, $instruction, function () use ($chatId): void {
				$this->telegramClient->sendTyping((int)$chatId);
			}, $chatKey);
		} catch (\Throwable $e) {
			$this->logger->error('appsagent: erro a processar mensagem do telegram', ['exception' => $e]);
			$reply = 'Ocorreu um erro: ' . $e->getMessage();
		}

		// Guarda-se a mensagem ORIGINAL (nao a $instruction com o historico
		// embutido) -- senao o historico duplicava-se a cada troca.
		$this->conversation->remember($chatKey, (string)$text, $reply);

		$this->telegramClient->sendMessage((int)$chatId, $reply !== '' ? $reply : 'Feito.');
		return new DataResponse(['ok' => true]);
	}

	/**
	 * Uma foto nunca executa nada sozinha -- so descreve o que ve e responde,
	 * gravando a troca no historico da conversa (ConversationService). Assim,
	 * quando a instrucao real chegar numa mensagem de texto a seguir ("insere
	 * abastecimento no Hyundai accent"), o agente ja tem, no seu proprio
	 * historico, o que cada foto mostrava -- sem precisar de nenhum mecanismo
	 * novo para alem do que ja existe.
	 *
	 * @param array<int, array<string, mixed>> $photoSizes o Telegram manda a mesma foto
	 *   em varias resolucoes, da mais pequena para a maior -- a ultima e a melhor.
	 */
	private function handlePhoto(int $chatId, string $chatKey, array $photoSizes, string $caption): void {
		$maior = end($photoSizes);
		$fileId = (string)($maior['file_id'] ?? '');
		if ($fileId === '') {
			return;
		}

		$baixado = $this->telegramClient->downloadFile($fileId);
		if ($baixado === null) {
			$this->telegramClient->sendMessage($chatId, 'Nao consegui descarregar essa imagem do Telegram.');
			return;
		}
		[$bytes, $mime] = $baixado;

		$pergunta = $caption !== ''
			? $caption
			: 'Descreve com detalhe o que ve nesta imagem, com atencao especial a numeros, '
				. 'valores monetarios, quantidades e datas que apareçam.';

		$descricao = $this->imageAnalysis->ask($bytes, $mime, $pergunta);
		$resposta = $descricao ?? 'Recebi a imagem mas nao consegui interpretar o conteudo.';

		// Guardado como um par de turno normal -- e assim que a instrucao
		// seguinte, em texto, vai encontrar isto no historico.
		$rotuloUtilizador = '[enviou uma imagem' . ($caption !== '' ? (': ' . $caption) : '') . ']';
		$this->conversation->remember($chatKey, $rotuloUtilizador, $resposta);

		$this->telegramClient->sendMessage($chatId, $resposta);
	}
}
