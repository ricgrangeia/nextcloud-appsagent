<?php

declare(strict_types=1);

namespace OCA\AppsAgent\BackgroundJob;

use OCA\AppsAgent\Db\InstructionMapper;
use OCA\AppsAgent\Db\PendingConfirmationMapper;
use OCA\AppsAgent\Service\AgentService;
use OCA\AppsAgent\Service\ConversationService;
use OCA\AppsAgent\Service\MemoryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Corre a cada 5 minutos (via o cron.php do proprio Nextcloud) e processa as
 * instrucoes pendentes submetidas com `occ appsagent:submit "..."`.
 */
class QueueWorker extends TimedJob {
	/** Retencao dos registos de confirmacao pendente -- generosa, so para nao crescer para sempre. */
	private const CONFIRMATIONS_RETENTION_SECONDS = 86400;

	public function __construct(
		ITimeFactory $time,
		private InstructionMapper $mapper,
		private AgentService $agentService,
		private MemoryService $memory,
		private ConversationService $conversation,
		private PendingConfirmationMapper $pendingConfirmations,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(300);
	}

	protected function run($argument): void {
		// Arrumacao de fundo: sem isto, cada mensagem do Telegram deixa um
		// marcador de deduplicacao permanente e a tabela de memoria so cresce.
		$this->memory->pruneStaleTelegramDedupMarkers();
		$this->conversation->pruneStale();
		$this->pendingConfirmations->deleteOlderThan(
			(new \DateTimeImmutable('-' . self::CONFIRMATIONS_RETENTION_SECONDS . ' seconds'))->format(DATE_ATOM)
		);

		foreach ($this->mapper->findPending() as $instruction) {
			try {
				$result = $this->agentService->run(null, $instruction->getText());
				$this->mapper->markProcessed($instruction, 'done', $result);
			} catch (\Throwable $e) {
				$this->logger->error('appsagent: falha ao processar instrucao da fila', ['exception' => $e]);
				$this->mapper->markProcessed($instruction, 'error', $e->getMessage());
			}
		}
	}
}
