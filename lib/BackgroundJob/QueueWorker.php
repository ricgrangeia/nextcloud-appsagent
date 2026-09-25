<?php

declare(strict_types=1);

namespace OCA\AppsAgent\BackgroundJob;

use OCA\AppsAgent\Db\InstructionMapper;
use OCA\AppsAgent\Service\AgentService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Corre a cada 5 minutos (via o cron.php do proprio Nextcloud) e processa as
 * instrucoes pendentes submetidas com `occ appsagent:submit "..."`.
 */
class QueueWorker extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private InstructionMapper $mapper,
		private AgentService $agentService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(300);
	}

	protected function run($argument): void {
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
