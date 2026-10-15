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
use OCP\ICacheFactory;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class TelegramController extends Controller {
	/** Segundos a esperar por mais uma foto do mesmo album antes de dar por fechado. */
	private const ALBUM_ESPERA = 3;

	/** Quanto tempo a legenda de um album fica guardada, em segundos. */
	private const ALBUM_TTL = 300;

	public function __construct(
		string $appName,
		IRequest $request,
		private AgentService $agentService,
		private TelegramClient $telegramClient,
		private MemoryService $memory,
		private ConversationService $conversation,
		private ImageAnalysisService $imageAnalysis,
		private LoggerInterface $logger,
		private ICacheFactory $cacheFactory,
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
		// Um album chega partido em varios updates, um por foto, todos com o
		// mesmo media_group_id -- e a legenda vem so num deles.
		$mediaGroupId = $message['media_group_id'] ?? null;

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

		$chatKey = ConversationService::telegramKey((int)$chatId);

		// Comando determinístico, sem passar pelo modelo -- exatamente porque
		// o dia de hoje mostrou que confiar na LLM para "esquece a conversa"
		// nao e fiavel (foi um caso destes que exigiu limpar a memoria a mao,
		// via occ, antes de 0.7.15 ter o comando -- agora ha um atalho igual
		// diretamente no Telegram, sem precisar de acesso ao servidor).
		if (in_array(mb_strtolower(trim((string)$text)), ['/reset', '/novo'], true)) {
			$apagados = $this->conversation->forgetChat($chatKey);
			$this->telegramClient->sendMessage(
				(int)$chatId,
				$apagados > 0
					? 'Histórico desta conversa apagado. Começamos do zero.'
					: 'Já não havia histórico guardado -- já estava do zero.',
			);
			return new DataResponse(['ok' => true]);
		}

		$this->telegramClient->sendTyping((int)$chatId);
		$nextcloudUserId = $this->telegramClient->resolveNextcloudUser((int)$fromId);

		if (is_array($photos) && $photos !== []) {
			$this->handlePhoto(
				(int)$chatId,
				$chatKey,
				$nextcloudUserId,
				$photos,
				is_string($caption) ? $caption : '',
				is_string($mediaGroupId) ? $mediaGroupId : '',
			);
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
	 * Uma foto continua a nao executar nada por si: o modelo de visao nao tem
	 * ferramentas e limita-se a descrever. O que mudou e o passo a seguir --
	 * quando a foto traz legenda, a descricao E a legenda seguem para o
	 * AgentService, que esse sim tem ferramentas.
	 *
	 * A regra anterior ("a instrucao real chega numa mensagem de texto a
	 * seguir") era segura mas obrigava a escrever duas vezes o que ja estava
	 * escrito: mandar a fatura com "regista abastecimento no Opel astra" e
	 * receber de volta uma descricao da fatura e um pedido para repetir a
	 * ordem em texto.
	 *
	 * Album: o Telegram manda cada foto no seu proprio update, todas com o
	 * mesmo media_group_id, e a legenda vem so numa delas. Cada update
	 * descreve a sua foto e grava-a no historico; o ULTIMO a chegar e que
	 * chama o agente, e ai o historico ja tem as descricoes todas -- a fatura
	 * e o conta-quilometros. Os outros calam-se, senao a conversa enche de
	 * descricoes antes de aparecer o resultado.
	 *
	 * @param array<int, array<string, mixed>> $photoSizes o Telegram manda a mesma foto
	 *   em varias resolucoes, da mais pequena para a maior -- a ultima e a melhor.
	 */
	private function handlePhoto(
		int $chatId,
		string $chatKey,
		?string $nextcloudUserId,
		array $photoSizes,
		string $caption,
		string $mediaGroupId,
	): void {
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

		// A legenda entra aqui como CONTEXTO, nunca como ordem: este modelo
		// nao tem ferramentas e, se receber a legenda como ordem, responde
		// "registei" sem ter registado nada. Quem executa e o agente, abaixo.
		$instrucoes = 'Descreve com detalhe o que ves nesta imagem, com atencao especial a '
			. 'numeros, valores monetarios, quantidades e datas que aparecam.';
		if ($caption !== '') {
			$instrucoes .= "\n\nO utilizador escreveu: \"" . $caption . "\". "
				. 'Usa isso APENAS para saber ao que prestar atencao na imagem.';
		}
		$instrucoes .= "\n\nNAO executas accoes. Nao tens ferramentas, nao gravas nada, "
			. 'nao registas nada. Nunca digas que registaste, guardaste, criaste ou '
			. 'actualizaste o que quer que seja: limita-te a descrever o que ves.';

		$descricao = $this->imageAnalysis->ask($bytes, $mime, $instrucoes);
		if ($descricao === null) {
			$this->telegramClient->sendMessage($chatId, 'Recebi a imagem mas nao consegui interpretar o conteudo.');
			return;
		}

		// Guardado como um par de turno normal -- e daqui que o agente tira a
		// descricao de CADA foto do album, sem precisar de a transportar.
		$rotuloUtilizador = '[enviou uma imagem' . ($caption !== '' ? (': ' . $caption) : '') . ']';
		$this->conversation->remember($chatKey, $rotuloUtilizador, $descricao);

		$ordem = $mediaGroupId !== ''
			? $this->ordemDoAlbum($mediaGroupId, $caption)
			: $caption;

		// Album, e esta nao e a ultima foto: quem responde e a ultima, ja com
		// o resultado.
		if ($ordem === null) {
			return;
		}

		// Sem legenda nao ha ordem nenhuma para executar. Fica a descricao e o
		// aviso, porque a alternativa e o utilizador pensar que a foto bastou.
		if ($ordem === '') {
			$this->telegramClient->sendMessage(
				$chatId,
				$descricao . "\n\nSo li a imagem; nao registei nada. "
					. 'Diz-me em texto o que queres que faca com isto.',
			);
			return;
		}

		$this->telegramClient->sendTyping($chatId);

		try {
			$resposta = $this->agentService->run(
				$nextcloudUserId,
				$this->conversation->withHistory($chatKey, $ordem),
				function () use ($chatId): void {
					$this->telegramClient->sendTyping($chatId);
				},
				$chatKey,
			);
		} catch (\Throwable $e) {
			$this->logger->error('appsagent: erro a executar a legenda de uma foto', ['exception' => $e]);
			$resposta = 'Li as imagens, mas falhei a executar "' . $ordem . '": ' . $e->getMessage();
		}

		// A ordem fica no historico como um turno normal, para o "sim" ou o
		// "muda a data" que venham a seguir saberem do que se fala.
		$this->conversation->remember($chatKey, $ordem, $resposta);
		$this->telegramClient->sendMessage($chatId, $resposta !== '' ? $resposta : 'Feito.');
	}

	/**
	 * Decide, dentro de um album, se e esta a foto que manda executar a ordem.
	 *
	 * O Telegram nao diz quantas fotos tem o album nem quando acabou. Entao
	 * cada update inscreve-se como o ultimo a chegar e espera um instante: se
	 * entretanto nenhum outro se inscreveu, era mesmo o ultimo. A legenda fica
	 * na cache porque pode ter vindo em qualquer uma das fotos, nao
	 * necessariamente nesta.
	 *
	 * @return string|null a ordem a executar, '' se o album nao traz legenda
	 *   nenhuma, ou null se esta foto nao e a ultima -- e portanto cala-se.
	 */
	private function ordemDoAlbum(string $mediaGroupId, string $caption): ?string {
		// Sem cache distribuida (Redis/memcached) o createDistributed devolve
		// uma ArrayCache, que vive so dentro de UM pedido -- cada foto do
		// album julgar-se-ia a ultima e executaria a ordem, gravando o mesmo
		// abastecimento duas vezes. Nesse caso nao se executa nada a partir de
		// um album: volta ao comportamento antigo, que e pedir a ordem em
		// texto. Um registo a menos corrige-se; um a dobrar e pior.
		if (!$this->cacheFactory->isAvailable()) {
			$this->logger->warning(
				'appsagent: album de fotos recebido sem cache distribuida configurada; '
				. 'a legenda nao e executada. Configurar memcache.distributed para a ligar.',
			);
			return '';
		}

		$cache = $this->cacheFactory->createDistributed('appsagent_album');
		$chaveLegenda = $mediaGroupId . ':legenda';
		$chaveUltimo = $mediaGroupId . ':ultimo';

		if ($caption !== '') {
			$cache->set($chaveLegenda, $caption, self::ALBUM_TTL);
		}

		$meuToken = bin2hex(random_bytes(8));
		$cache->set($chaveUltimo, $meuToken, self::ALBUM_TTL);
		sleep(self::ALBUM_ESPERA);

		if ($cache->get($chaveUltimo) !== $meuToken) {
			return null;
		}

		return (string)($cache->get($chaveLegenda) ?? '');
	}
}
