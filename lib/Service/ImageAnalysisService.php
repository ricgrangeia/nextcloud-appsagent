<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Service;

use OCP\Files\IRootFolder;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\ISynchronousProvider;
use Psr\Log\LoggerInterface;

/**
 * Faz uma pergunta em texto sobre uma imagem, usando o provider de Task
 * Processing "core:analyze-images" ja registado nesta instancia
 * (integration_openai, o LLM local com visao -- confirmado com
 * `occ appsagent:providers`, nao suposto).
 *
 * O tipo de entrada "ListOfImages" desse provider refere-se a FICHEIROS do
 * Nextcloud, nao a bytes em bruto -- por isso a imagem tem primeiro de ser
 * gravada, mesmo que temporariamente, na pasta do utilizador antes de se
 * poder perguntar algo sobre ela. Fica limpa a seguir (finally), porque nao
 * e um ficheiro que o utilizador pediu para guardar.
 */
class ImageAnalysisService {
	private const TASK_TYPE_ID = 'core:analyze-images';
	private const TMP_FOLDER = '.appsagent-tmp';

	public function __construct(
		private IManager $taskProcessingManager,
		private IRootFolder $rootFolder,
		private AgentAccountService $agentAccount,
		private LoggerInterface $logger,
	) {
	}

	/** @return string|null a resposta do modelo, ou null se algo falhar */
	public function ask(string $bytes, string $mime, string $question): ?string {
		$provider = $this->findProvider();
		if ($provider === null) {
			$this->logger->warning('appsagent: nenhum provider para ' . self::TASK_TYPE_ID . ' registado nesta instancia.');
			return null;
		}

		[$username] = $this->agentAccount->credentials();
		$node = null;
		try {
			$userFolder = $this->rootFolder->getUserFolder($username);
			if (!$userFolder->nodeExists(self::TMP_FOLDER)) {
				$userFolder->newFolder(self::TMP_FOLDER);
			}
			$tmpFolder = $userFolder->get(self::TMP_FOLDER);

			$extensao = match ($mime) {
				'image/png' => 'png',
				'image/webp' => 'webp',
				default => 'jpg',
			};
			$nome = 'telegram-' . bin2hex(random_bytes(6)) . '.' . $extensao;
			$node = $tmpFolder->newFile($nome, $bytes);

			$result = $provider->process($username, [
				'images' => [$node->getId()],
				'input' => $question,
			], static fn (): bool => true);

			$saida = $result['output'] ?? null;
			return is_string($saida) && $saida !== '' ? $saida : null;
		} catch (\Throwable $e) {
			$this->logger->warning('appsagent: falha a analisar imagem', ['exception' => $e]);
			return null;
		} finally {
			// Nunca fica no espaco real do utilizador -- e um ficheiro de
			// trabalho interno, nao algo que ele pediu para guardar.
			try {
				$node?->delete();
			} catch (\Throwable) {
				// Falha a limpar nao pode esconder o resultado real da pergunta.
			}
		}
	}

	private function findProvider(): ?ISynchronousProvider {
		foreach ($this->taskProcessingManager->getProviders() as $provider) {
			if ($provider instanceof ISynchronousProvider && $provider->getTaskTypeId() === self::TASK_TYPE_ID) {
				return $provider;
			}
		}
		return null;
	}
}
