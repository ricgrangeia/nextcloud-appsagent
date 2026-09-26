<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Service;

use OCA\AppsAgent\AppInfo\Application;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;

/**
 * Regras de comportamento do agente. O que e mesmo obrigatorio e invariavel
 * (formato JSON de acao, o portao de confirmar antes de eliminar) fica fixo
 * em AgentService; tudo o resto vive aqui, no armazenamento proprio da app
 * (IAppData -- fora da pasta de codigo, sobrevive sempre a reimplantacoes),
 * criado sozinho na primeira utilizacao e semeado com o ficheiro que vem em
 * lib/Resources/prompt-rules.json.
 *
 * `prompt_rules_path`, se definido, desvia tudo para um ficheiro no disco a
 * gosto do utilizador (avancado; nao e preciso para o uso normal).
 */
class PromptRulesService {
	private const APPDATA_FOLDER = 'config';
	private const RULES_FILENAME = 'prompt-rules.json';

	/**
	 * Nomes das regras base escritas por mim -- o agente pode acrescentar as
	 * suas proprias regras (agent_learn_rule), mas nao pode sobrescrever
	 * estas so por as ter mencionado com o mesmo nome. A protecao real contra
	 * eliminar sem confirmar esta no codigo do AgentService, nao aqui -- isto
	 * e so para o ficheiro nao ficar confuso.
	 */
	public const PROTECTED_RULE_NAMES = [
		'calendar-actions', 'notes-vs-memory', 'explore-app', 'app-naming', 'recipe-status',
		'research-vs-action', 'delete-confirmation', 'date-arithmetic',
	];

	public function __construct(
		private IAppData $appData,
		private IConfig $config,
	) {
	}

	private function overridePath(): string {
		return trim((string)$this->config->getAppValue(Application::APP_ID, 'prompt_rules_path', ''));
	}

	/** Descricao legivel de onde as regras vivem, para mostrar ao utilizador (occ appsagent:rules). */
	public function describeLocation(): string {
		$override = $this->overridePath();
		if ($override !== '') {
			return $override;
		}
		return 'armazenamento proprio da app (data/appdata_.../' . Application::APP_ID
			. '/' . self::APPDATA_FOLDER . '/' . self::RULES_FILENAME . ')';
	}

	private function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::APPDATA_FOLDER);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::APPDATA_FOLDER);
		}
	}

	private function file(): ISimpleFile {
		$folder = $this->folder();
		try {
			return $folder->getFile(self::RULES_FILENAME);
		} catch (NotFoundException) {
			$defaultPath = __DIR__ . '/../Resources/prompt-rules.json';
			$seed = is_file($defaultPath) ? (string)file_get_contents($defaultPath) : '{"rules":[]}';
			return $folder->newFile(self::RULES_FILENAME, $seed);
		}
	}

	/** @return array<string,mixed> o documento completo (com "rules" e o resto) */
	public function loadDocument(): array {
		$override = $this->overridePath();
		$json = $override !== ''
			? (is_file($override) ? (string)file_get_contents($override) : '')
			: $this->file()->getContent();

		$decoded = json_decode($json, true);
		$document = is_array($decoded) ? $decoded : [];

		return $this->healMissingBaseRules($document);
	}

	/**
	 * Instalacoes que ja tinham o ficheiro semeado antes de eu acrescentar uma
	 * nova regra base (PROTECTED_RULE_NAMES) ficam sem ela para sempre, ja que
	 * a semente so corre uma vez, na criacao do ficheiro. Para essas, junta
	 * aqui -- por nome -- qualquer regra base que exista no ficheiro semente
	 * mas falte no documento carregado, e persiste a diferenca. Nao mexe em
	 * regras que o utilizador/agente tenha acrescentado ou editado.
	 */
	private function healMissingBaseRules(array $document): array {
		$rules = is_array($document['rules'] ?? null) ? $document['rules'] : [];

		$seedPath = __DIR__ . '/../Resources/prompt-rules.json';
		$seedDecoded = is_file($seedPath) ? json_decode((string)file_get_contents($seedPath), true) : null;
		$seedByName = [];
		foreach ((is_array($seedDecoded['rules'] ?? null) ? $seedDecoded['rules'] : []) as $seedRule) {
			$seedName = (string)($seedRule['name'] ?? '');
			if ($seedName !== '' && in_array($seedName, self::PROTECTED_RULE_NAMES, true)) {
				$seedByName[$seedName] = $seedRule;
			}
		}

		$changed = false;
		$seenNames = [];
		foreach ($rules as $i => $rule) {
			if (!is_array($rule) || !isset($rule['name'])) {
				continue;
			}
			$name = (string)$rule['name'];
			$seenNames[$name] = true;
			// Regras protegidas sao definidas no codigo (lib/Resources/prompt-rules.json),
			// nunca editaveis pelo agente -- se o texto da versao instalada
			// (semeada numa versao antiga) ja nao bater certo com o que o
			// codigo atual traz, repoe-se sozinho.
			if (isset($seedByName[$name]) && ($rule['text'] ?? null) !== $seedByName[$name]['text']) {
				$rules[$i] = $seedByName[$name];
				$changed = true;
			}
		}

		foreach ($seedByName as $name => $seedRule) {
			if (!isset($seenNames[$name])) {
				$rules[] = $seedRule;
				$changed = true;
			}
		}

		if (!$changed) {
			return $document;
		}

		$document['rules'] = array_values($rules);
		$this->writeDocument($document);
		return $document;
	}

	private function writeDocument(array $document): bool {
		$json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
		$override = $this->overridePath();

		if ($override !== '') {
			return @file_put_contents($override, $json) !== false;
		}

		try {
			$this->file()->putContent($json);
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/** @return list<string> so o texto de cada regra, pela ordem do documento */
	public function loadTexts(): array {
		$texts = [];
		foreach ((array)($this->loadDocument()['rules'] ?? []) as $rule) {
			if (is_array($rule) && isset($rule['text'])) {
				$texts[] = (string)$rule['text'];
			}
		}
		return $texts;
	}

	/**
	 * Acrescenta ou atualiza (por "name") uma regra de comportamento.
	 * @return array{error: string}|array{saved: true, name: string, total_rules: int}
	 */
	public function learn(string $name, string $text): array {
		$name = trim($name);
		if ($name === '' || trim($text) === '') {
			return ['error' => 'precisa de "name" e "text" nao vazios.'];
		}
		if (in_array($name, self::PROTECTED_RULE_NAMES, true)) {
			return ['error' => "'{$name}' e uma regra base protegida -- usa outro nome para a tua propria regra."];
		}

		$document = $this->loadDocument();
		$rules = is_array($document['rules'] ?? null) ? $document['rules'] : [];

		$matched = false;
		foreach ($rules as $i => $rule) {
			if (is_array($rule) && ($rule['name'] ?? null) === $name) {
				$rules[$i] = ['name' => $name, 'text' => $text, 'learned_at' => (new \DateTimeImmutable())->format(DATE_ATOM)];
				$matched = true;
				break;
			}
		}
		if (!$matched) {
			$rules[] = ['name' => $name, 'text' => $text, 'learned_at' => (new \DateTimeImmutable())->format(DATE_ATOM)];
		}
		$document['rules'] = array_values($rules);

		if (!$this->writeDocument($document)) {
			return ['error' => 'Nao consegui guardar a regra -- falha ao escrever no armazenamento da app.'];
		}

		return ['saved' => true, 'name' => $name, 'total_rules' => count($rules)];
	}
}
