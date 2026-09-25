<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Service;

/**
 * Descoberta (so leitura) dos comandos occ que uma app regista em info.xml,
 * incluindo o texto de --help. Corre `occ` num subprocesso -- por isso e
 * mais lento (reinicia todo o bootstrap do Nextcloud a cada chamada) e pode
 * falhar em alojamentos que desativam exec/proc_open. Nao executa nenhum
 * comando para alem de `list` e `--help`, que sao inofensivos.
 */
class OccDiscoveryService {
	private function occPath(): string {
		// \OC::$SERVERROOT nao e uma API publica (OCP), mas e a forma estavel
		// e amplamente usada em todo o core do Nextcloud para localizar a raiz
		// da instalacao (onde vive o occ).
		return rtrim(\OC::$SERVERROOT, '/') . '/occ';
	}

	/** @return array{stdout: string, stderr: string} */
	private function runOcc(array $args): array {
		$command = array_merge([PHP_BINARY ?: 'php', $this->occPath()], $args);
		$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

		$process = proc_open($command, $descriptors, $pipes, \OC::$SERVERROOT);
		if (!is_resource($process)) {
			throw new \RuntimeException(
				'appsagent: nao foi possivel invocar o occ (proc_open falhou -- ' .
				'pode estar desativado neste alojamento).'
			);
		}

		$stdout = stream_get_contents($pipes[1]) ?: '';
		$stderr = stream_get_contents($pipes[2]) ?: '';
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exitCode = proc_close($process);

		if ($exitCode !== 0) {
			throw new \RuntimeException('appsagent: occ devolveu erro: ' . trim($stderr));
		}

		return ['stdout' => $stdout, 'stderr' => $stderr];
	}

	/** @return array{name: string, description: string}[] */
	private function listAllCommands(): array {
		$result = $this->runOcc(['list', '--format=json']);
		$data = json_decode($result['stdout'], true);
		if (!is_array($data)) {
			throw new \RuntimeException('appsagent: nao consegui interpretar o output de "occ list --format=json".');
		}

		$commands = [];
		foreach ((array)($data['commands'] ?? []) as $command) {
			$commands[] = [
				'name' => (string)($command['name'] ?? ''),
				'description' => (string)($command['description'] ?? ''),
			];
		}
		return $commands;
	}

	public function listAppCommands(string $appId): array {
		$commands = array_values(array_filter(
			$this->listAllCommands(),
			static fn (array $c): bool => $c['name'] === $appId || str_starts_with($c['name'], $appId . ':'),
		));

		return ['app' => $appId, 'commands' => $commands];
	}

	public function describeCommand(string $command): array {
		$known = array_map(static fn (array $c) => $c['name'], $this->listAllCommands());
		if (!in_array($command, $known, true)) {
			return ['error' => "appsagent: o comando '{$command}' nao existe."];
		}

		$help = $this->runOcc([$command, '--help']);
		return ['command' => $command, 'help' => trim($help['stdout'])];
	}
}
