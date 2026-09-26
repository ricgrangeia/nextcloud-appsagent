<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Service;

use OCA\AppsAgent\Db\NoteMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Memoria persistente do agente, guardada por "topico". Dois usos distintos
 * partilham a mesma tabela:
 *
 *  - apontamentos de texto livre (preferencias do utilizador, factos soltos);
 *  - receitas estruturadas por app, sob o topico "app:<app_id>": uma lista
 *    JSON de operacoes ja confirmadas (tarefa, metodo, caminho, campos,
 *    exemplo). Cada receita e adicionada/atualizada individualmente por
 *    tarefa -- duas receitas da mesma app (ex: "criar nota" e "atualizar
 *    nota") coexistem sem se sobreporem.
 */
class MemoryService {
	private const APP_TOPIC_PREFIX = 'app:';

	public function __construct(
		private NoteMapper $mapper,
	) {
	}

	public function remember(string $topic, string $note): void {
		$this->mapper->upsert($topic, $note);
	}

	public function recall(string $topic): ?string {
		try {
			return $this->mapper->findByTopic($topic)->getNote();
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return array<string, string> topico => nota */
	public function recallAll(): array {
		$notes = [];
		foreach ($this->mapper->findAll() as $entity) {
			$notes[$entity->getTopic()] = $entity->getNote();
		}
		return $notes;
	}

	/**
	 * Guarda/atualiza uma receita confirmada para uma app. Se ja existir uma
	 * receita com a mesma "task" (comparacao insensivel a maiusculas), e
	 * substituida (mesclando os campos novos por cima); caso contrario e
	 * adicionada a lista.
	 *
	 * @param array<string,mixed> $recipe
	 * @return list<array<string,mixed>> a lista completa de receitas desta app, apos a alteracao
	 */
	public function saveRecipe(string $appId, array $recipe): array {
		$recipe['status'] = in_array($recipe['status'] ?? null, ['confirmed', 'tentative'], true)
			? $recipe['status']
			: 'tentative';
		$recipe['saved_at'] = (new \DateTimeImmutable())->format(DATE_ATOM);
		$recipes = $this->getRecipes($appId);
		$task = trim((string)($recipe['task'] ?? ''));

		$matchedIndex = null;
		if ($task !== '') {
			foreach ($recipes as $index => $existing) {
				if (strcasecmp(trim((string)($existing['task'] ?? '')), $task) === 0) {
					$matchedIndex = $index;
					break;
				}
			}
		}

		if ($matchedIndex !== null) {
			$recipes[$matchedIndex] = $recipe + $recipes[$matchedIndex];
		} else {
			$recipes[] = $recipe;
		}

		$this->remember(
			self::APP_TOPIC_PREFIX . $appId,
			json_encode(array_values($recipes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		);

		return array_values($recipes);
	}

	/** @return list<array<string,mixed>> */
	public function getRecipes(string $appId): array {
		$stored = $this->recall(self::APP_TOPIC_PREFIX . $appId);
		if ($stored === null) {
			return [];
		}
		$decoded = json_decode($stored, true);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * As receitas de uma app separadas por estado: "confirmed" foi validado
	 * explicitamente pelo utilizador; "tentative" so foi confirmado pela API
	 * (ok=true) mas ainda ninguem validou que o resultado esta mesmo certo
	 * (ex: criou a nota mas esqueceu-se do texto).
	 *
	 * @return array{confirmed: list<array<string,mixed>>, tentative: list<array<string,mixed>>}
	 */
	public function getRecipesGrouped(string $appId): array {
		$confirmed = [];
		$tentative = [];
		foreach ($this->getRecipes($appId) as $recipe) {
			if (($recipe['status'] ?? 'tentative') === 'confirmed') {
				$confirmed[] = $recipe;
			} else {
				$tentative[] = $recipe;
			}
		}
		return ['confirmed' => $confirmed, 'tentative' => $tentative];
	}

	/**
	 * Lista as apps para as quais ja ha pelo menos uma receita aprendida,
	 * com as tarefas que sabe fazer em cada uma -- distinto de
	 * DiscoveryService::listEnabledApps(), que lista TODAS as apps
	 * instaladas independentemente de o agente saber agir sobre elas.
	 *
	 * @return array<string, list<array{task: string, status: string}>> app_id => tarefas conhecidas
	 */
	public function listLearnedApps(): array {
		$learned = [];
		foreach ($this->recallAll() as $topic => $note) {
			if (!str_starts_with($topic, self::APP_TOPIC_PREFIX)) {
				continue;
			}
			$appId = substr($topic, strlen(self::APP_TOPIC_PREFIX));
			$decoded = json_decode($note, true);
			if (!is_array($decoded)) {
				continue;
			}
			$tasks = [];
			foreach ($decoded as $recipe) {
				if (is_array($recipe) && isset($recipe['task'])) {
					$tasks[] = [
						'task' => (string)$recipe['task'],
						'status' => (string)($recipe['status'] ?? 'tentative'),
					];
				}
			}
			if ($tasks !== []) {
				$learned[$appId] = $tasks;
			}
		}
		return $learned;
	}

	/**
	 * Apaga todas as receitas aprendidas de uma app, para o agente as voltar
	 * a explorar do zero na proxima vez que lhe pedires algo dessa app.
	 *
	 * @return bool true se havia alguma coisa para apagar
	 */
	public function forgetApp(string $appId): bool {
		return $this->mapper->deleteByTopic(self::APP_TOPIC_PREFIX . $appId);
	}

	/**
	 * Apaga um apontamento solto. Recusa topicos "app:" para nao deitar fora
	 * um conjunto inteiro de receitas por engano -- para isso ha forgetApp.
	 */
	public function forgetNote(string $topic): bool {
		if ($topic === '' || str_starts_with($topic, self::APP_TOPIC_PREFIX)) {
			return false;
		}
		return $this->mapper->deleteByTopic($topic);
	}
}
