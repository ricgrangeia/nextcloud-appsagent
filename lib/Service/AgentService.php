<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Service;

use OCA\AppsAgent\AppInfo\Application;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Orquestra o agente: mantem um transcript, pede ao ReasoningService a proxima
 * acao (num formato JSON fixo, ja que o core:text2text do Nextcloud so devolve
 * texto livre -- nao ha tool-calling estruturado nesta camada), executa a
 * ferramenta escolhida e repete ate obter uma resposta final ou atingir o
 * limite de passos.
 */
class AgentService {
	// Usado so se a configuracao nao tiver um valor valido -- ver maxSteps().
	private const DEFAULT_MAX_STEPS = 25;

	public function __construct(
		private ReasoningService $reasoning,
		private CalendarService $calendar,
		private TaskService $tasks,
		private DiscoveryService $discovery,
		private RouteDiscoveryService $routes,
		private MemoryService $memory,
		private DynamicApiService $dynamicApi,
		private OccDiscoveryService $occDiscovery,
		private PromptRulesService $promptRules,
		private SupervisorToolsClient $supervisorTools,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param callable(): void|null $onStep chamado no inicio de cada passo do
	 *   raciocinio -- usado, por exemplo, para repetir o indicador "a escrever..."
	 *   do Telegram em esperas mais longas.
	 */
	public function run(?string $userId, string $instruction, ?callable $onStep = null): string {
		$transcript = $this->buildSystemPrompt() . "\n\nInstrucao do utilizador: " . $instruction . "\n";
		$actionsTaken = [];

		$maxSteps = $this->maxSteps();
		for ($step = 0; $step < $maxSteps; $step++) {
			if ($onStep !== null) {
				$onStep();
			}
			$reply = $this->reasoning->complete($transcript);
			$action = $this->parseAction($reply);

			if ($action === null) {
				// Nunca mostrar JSON em bruto/partido ao utilizador -- pede ao modelo
				// para corrigir e tentar de novo (conta como um passo normal).
				$actionsTaken[] = '(resposta invalida)';
				$transcript .= "\nAcao: " . $reply . "\nObservacao: {\"error\":\"A tua resposta anterior nao "
					. "era um objeto JSON valido -- provavelmente aspas dentro do texto por escapar (usa \\\\\" "
					. "para aspas dentro de valores string). Responde de novo, APENAS com um objeto JSON valido, "
					. "no formato pedido.\"}\n";
				continue;
			}

			if (($action['action'] ?? null) === 'final') {
				return (string)($action['text'] ?? '');
			}

			$actionName = (string)($action['action'] ?? '');
			$actionsTaken[] = $actionName;

			$result = $this->dispatchTool($actionName, (array)($action['args'] ?? []), $userId);
			$transcript .= "\nAcao: " . $reply . "\nObservacao: " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
		}

		// Sem isto, uma instrucao presa em loop nao deixa rasto nenhum no log
		// -- so este aviso, com a sequencia de ferramentas chamadas, permite
		// perceber depois o que o modelo andou a tentar fazer.
		$this->logger->warning('appsagent: instrucao nao concluida dentro do limite de passos', [
			'instruction' => $instruction,
			'user' => $userId,
			'max_steps' => $maxSteps,
			'actions_taken' => $actionsTaken,
		]);

		return 'Nao consegui concluir a instrucao dentro do numero maximo de passos.';
	}

	/**
	 * Configuravel em Definicoes de administracao > Apps Agent -- tentativa e
	 * erro numa API mal documentada pode precisar de bastantes passos ate
	 * acertar o nome certo de um campo, e preferimos deixar o agente insistir
	 * a corta-lo cedo demais. Limites (1-100) evitam um valor absurdo (custos
	 * de LLM por passo) partires o campo com um numero invalido/negativo.
	 */
	private function maxSteps(): int {
		$raw = (int)$this->config->getAppValue(Application::APP_ID, 'max_steps', (string)self::DEFAULT_MAX_STEPS);
		return max(1, min(100, $raw > 0 ? $raw : self::DEFAULT_MAX_STEPS));
	}

	private function buildSystemPrompt(): string {
		$lines = [
			'Es o agente Nextcloud do utilizador. So podes responder com UM UNICO objeto JSON,',
			'sem texto a volta, sem markdown, sem ```json, sem ``` -- APENAS o objeto JSON puro.',
			'Formatos permitidos:',
			'{"action": "nome_da_ferramenta", "args": { ... }}',
			'ou',
			'{"action": "final", "text": "resposta final para o utilizador, em portugues"}',
			'',
			'FORMATO OBRIGATORIO: a tua resposta DEVE começar com "{" e terminar com "}".',
			'NUNCA uses ```json, ``` ou qualquer outro bloco de formatacao.',
			'NUNCA escrevas texto antes ou depois do JSON.',
			'Exemplo CORRETO: {"action":"final","text":"Feito"}',
			'Exemplo INCORRETO: ```json\n{"action":"final","text":"Feito"}\n```',
			'',
			'REGRA MAIS IMPORTANTE: se a instrucao pede uma ACAO (criar/atualizar/eliminar/cancelar '
				. 'qualquer coisa), o teu PRIMEIRO passo tem SEMPRE de ser "action" com uma ferramenta -- '
				. 'NUNCA "final" logo de inicio. So podes responder "final" a confirmar que algo foi feito '
				. 'depois de teres visto, nesta mesma conversa, uma Observacao real dessa ferramenta a '
				. 'confirma-lo (ex: {"uid": ...} de calendar_create_event, ou {"ok": true} de app_api_call). '
				. 'Exemplo do que NAO fazer: instrucao "cria uma nota X" -> responder logo '
				. '{"action":"final","text":"A nota X foi criada"} sem nunca teres chamado nenhuma ferramenta. '
				. 'Isso e mentira -- nada foi criado. Se ainda nao tens ferramenta para a acao pedida, di-lo '
				. 'honestamente ("ainda nao sei fazer X") em vez de inventares que foi feito. '
				. 'ATENCAO -- "ok":true NAO E PROVA SUFICIENTE para uma ATUALIZACAO: uma API pode aceitar '
				. 'um campo desconhecido/errado e devolver ok:true na mesma sem mudar nada. Antes de dizeres '
				. '"final" a confirmar uma atualizacao de conteudo, confirma que o VALOR ESPECIFICO que '
				. 'mudaste aparece correto na propria resposta -- SE A RESPOSTA DO PUT/POST NAO MOSTRAR '
					. 'CLARAMENTE ESSE CAMPO, FAZ TU MESMO, POR INICIATIVA PROPRIA, UMA CHAMADA GET A SEGUIR '
					. 'PARA VERIFICAR, sem esperares que o utilizador peca ou pergunte se resultou. Se o campo '
				. 'relevante (ex: "content") ficar vazio ou diferente do que pediste apesar de ok:true, o '
				. 'campo que enviaste estava errado: tenta outro nome de campo (ve a documentacao outra vez) '
				. 'em vez de assumires sucesso.',
			'',
			'Ferramentas disponiveis:',
		];
		foreach ($this->toolCatalog() as $name => $description) {
			$lines[] = "- {$name}: {$description}";
		}

		// A partir daqui, as regras vem do PromptRulesService (editavel sem
		// reimplantar codigo -- ve learnRule() abaixo e occ appsagent:rules).
		foreach ($this->promptRules->loadTexts() as $ruleText) {
			$lines[] = '';
			$lines[] = $ruleText;
		}

		$timezone = new \DateTimeZone($this->config->getAppValue(Application::APP_ID, 'default_timezone', 'UTC'));
		$lines[] = '';
		$lines[] = 'Data e hora atual: ' . (new \DateTimeImmutable('now', $timezone))->format(DATE_ATOM)
			. ' (fuso horario: ' . $timezone->getName() . ')';

		return implode("\n", $lines);
	}

	/**
	 * Guarda/atualiza uma regra de comportamento GERAL, aprendida pelo proprio
	 * agente (PromptRulesService trata do "onde" e do "como"). Regista sempre
	 * no log -- e uma escrita que muda o comportamento futuro do agente.
	 */
	private function learnRule(string $name, string $text, ?string $userId): array {
		$result = $this->promptRules->learn($name, $text);
		if (isset($result['error'])) {
			return $result;
		}

		$this->logger->warning('appsagent: nova regra de comportamento aprendida pelo agente', [
			'name' => $result['name'],
			'user' => $userId,
		]);

		return $result;
	}

	private function toolCatalog(): array {
		return [
			'calendar_list_events' => 'Lista eventos entre duas datas. args: {start, end, calendar?}',
			'calendar_create_event' => 'Cria um evento. args: {summary, start, end, description?, calendar?}',
			'calendar_update_event' => 'Atualiza campos de um evento existente. args: {uid, summary?, start?, end?, description?, calendar?}',
			'calendar_deactivate_event' => 'Marca um evento como CANCELLED sem o apagar. args: {uid, calendar?}',
			'calendar_delete_event' => 'Apaga definitivamente um evento -- IRREVERSIVEL, exige confirm:true (ve a regra de confirmacao abaixo). args: {uid, calendar?, confirm?}',
			'task_lists' => 'Lista as listas de tarefas disponiveis (calendarios que aceitam VTODO), com uri e nome. Usa isto se task_list devolver vazio -- a lista pode nao se chamar "personal". args: {}',
			'task_list' => 'Lista tarefas. Por omissao so as por fazer; include_completed:true inclui concluidas e canceladas. args: {include_completed?, list?}',
			'task_create' => 'Cria uma tarefa. args: {summary, due?, description?, priority?, parent?, list?}',
			'task_update' => 'Atualiza campos de uma tarefa existente. args: {uid, summary?, description?, due?, start?, priority?, percent_complete?, list?}',
			'task_complete' => 'Marca uma tarefa como concluida (STATUS COMPLETED + 100%). args: {uid, list?}',
			'task_reopen' => 'Reabre uma tarefa concluida ou cancelada. args: {uid, list?}',
			'task_cancel' => 'Marca uma tarefa como CANCELLED sem a apagar. args: {uid, list?}',
			'task_delete' => 'Apaga definitivamente uma tarefa -- IRREVERSIVEL, exige confirm:true (ve a regra de confirmacao abaixo). args: {uid, list?, confirm?}',
			'discovery_list_apps' => 'Lista as apps ativas neste Nextcloud, com id e nome (ex: {"id":"notes","name":"Notes"}) -- usa o nome para mapeares o que o utilizador disser (ex: "Notas") ao app_id certo. args: {}',
			'discovery_describe_app' => 'Le (so leitura) as capacidades reportadas por uma app especifica, para perceberes o que ela suporta antes de assumires que consegues agir sobre ela. args: {app_id}',
			'discovery_describe_app_api' => 'Catalogo real (so leitura) das operacoes HTTP de uma app instalada: metodo, caminho completo (ja com prefixo /apps/<id>/... ou /ocs/v2.php/apps/<id>/...), origem (routes.php / atributos / openapi.json), flags no_csrf/public/cors, e a lista "docs" de ficheiros de documentacao que a app envia. Funciona para QUALQUER app, nao so as que tem openapi.json. Fica em cache; args: {app_id, refresh?}',
			'discovery_read_app_docs' => 'Le um ficheiro de documentacao que a app envia (README.md, docs/**/*.md) -- util para perceber campos e semantica de uma API antes de a chamar. Sem "file", le o primeiro da lista "docs" do catalogo. args: {app_id, file?}',
			'memory_save_note' => 'MEMORIA INTERNA DO AGENTE (nao e a app Notes do Nextcloud): guarda um apontamento de texto livre teu (preferencias do utilizador, factos soltos) sobre um topico. Para receitas de como usar uma app usa antes memory_save_recipe. args: {topic, note}',
			'memory_recall' => 'MEMORIA INTERNA DO AGENTE: le o apontamento de texto livre guardado sobre um topico, se existir. args: {topic}',
			'memory_list_notes' => 'MEMORIA INTERNA DO AGENTE: lista todos os apontamentos de texto livre que ja guardaste. args: {}',
			'memory_save_recipe' => 'Guarda/atualiza uma receita confirmada de como executar uma tarefa numa app (por baixo, guarda-a no topico "app:<app_id>"). Usa a mesma "task" para atualizares uma receita existente em vez de duplicares. args: {app_id, task, method, path, body_template?, example?}',
			'memory_get_recipes' => 'Le as receitas ja confirmadas para uma app (lista de {task, method, path, body_template, example}). Chama isto ANTES de explorares uma app, para veres se ja sabes fazer a tarefa pedida. args: {app_id}',
			'memory_list_learned_apps' => 'Lista as apps para as quais ja tens pelo menos uma receita (confirmada ou por confirmar), e as tarefas que sabes fazer em cada uma -- usa isto quando o utilizador perguntar "que apps sabes usar" ou "que apps tens configuradas/aprendidas". args: {}',
			'memory_describe_app' => 'Responde a "o que ja sabes fazer da app X": devolve para que serve a app (nome/resumo) e as tuas receitas separadas em confirmed_actions (o utilizador validou que ficam bem) e tentative_actions (a API disse ok, mas ainda ninguem confirmou que o resultado esta correto). args: {app_id}',
			'app_api_call' => 'Executa UMA operacao HTTP do catalogo de uma app (discovery_describe_app_api). O metodo+caminho tem de existir mesmo no catalogo, senao e recusado. DELETE e IRREVERSIVEL e exige confirm:true (ve a regra de confirmacao abaixo), e pode estar bloqueado por completo (generic_api_allow_delete). Devolve {status, ok, body, hint?} -- erros HTTP vem como observacao (nao excecao) para poderes corrigir e tentar de novo. args: {app_id, method, path, body?, query?, confirm?}. Pode estar desligada -- se devolver erro a dizer isso, informa o utilizador.',
			'discovery_list_app_commands' => 'Lista os comandos occ que uma app regista (mais lento -- corre um subprocesso occ). args: {app_id}',
			'discovery_describe_command' => 'Le o texto de --help de um comando occ especifico (so leitura, nao o executa). args: {command}',
			'agent_learn_rule' => 'Ensina-te uma regra de comportamento GERAL e persistente, que passa a aplicar-se a TODAS as conversas futuras (nao so a uma app -- para isso usa memory_save_recipe). Usa isto quando o utilizador te disser explicitamente para te lembrares de algo sobre como te deves comportar (ex: "a partir de agora, quando eu disser X, faz Y"). Nao uses para factos soltos (isso e memory_save_note) nem para receitas de uma app (memory_save_recipe). Nao podes sobrescrever as regras base do sistema com o mesmo nome. So funciona quando falas com um utilizador Nextcloud autenticado (Assistant/Chat) -- recusa a partir da fila/cron ou do Telegram. args: {name, text}',
			'memory_forget_app' => 'Apaga TODAS as receitas aprendidas de uma app, para as voltares a explorar do zero na proxima vez -- usa quando o utilizador pedir para "esquecer"/"reaprender" uma app, ou quando as receitas guardadas parecerem erradas/desatualizadas. args: {app_id}',
			'calculator' => 'Avalia uma expressao matematica (raiz, trigonometria, logaritmos, fatorial, pi/e, etc.) -- usa isto para QUALQUER conta, nunca calcules tu mesmo de cabeca. Devolve {result} ou {error}. args: {expression}',
			'web_search' => 'Pesquisa na web (DuckDuckGo) e devolve uma lista de resultados {title, url, snippet}. Usa quando precisares de informacao atual ou que nao sabes. args: {query, max_results?}',
			'datetime_calc' => 'Responde a perguntas de datas/horas em linguagem natural (ex: "que dia e daqui a 10 dias?", "quantos dias faltam para 25/12?"). Devolve {result} com a resposta ja calculada. args: {question}',
		];
	}

	private function parseAction(string $reply): ?array {
		// Remover blocos ```json ... ``` ou ``` ... ```
		$cleaned = preg_replace('/```(?:json)?\s*([\s\S]*?)```/', '$1', $reply);
		// Se nada foi removido, usa o original
		$text = $cleaned !== $reply ? $cleaned : $reply;

		// Procurar o primeiro { válido
		$start = strpos($text, '{');
		if ($start === false) {
			return null;
		}

		$depth = 0;
		$length = strlen($text);
		for ($i = $start; $i < $length; $i++) {
			if ($text[$i] === '{') {
				$depth++;
			} elseif ($text[$i] === '}') {
				$depth--;
				if ($depth === 0) {
					$json = substr($text, $start, $i - $start + 1);
					$decoded = json_decode($json, true);
					if (is_array($decoded)) {
						return $decoded;
					}
					return $this->salvageFinalText($json);
				}
			}
		}

		return null;
	}

	/** @return array{action: string, text: string}|null */
	private function salvageFinalText(string $json): ?array {
		if (!str_contains($json, '"action"') || !preg_match('/"action"\s*:\s*"final"/', $json)) {
			return null;
		}
		// Remover qualquer ``` ou whitespace no final antes de procurar
		$trimmed = preg_replace('/[`]*\s*$/', '', $json);
		if (!preg_match('/"text"\s*:\s*"(.*)"\s*\}\s*$/s', $trimmed, $matches)) {
			return null;
		}
		$text = str_replace(['\\"', '\\n', '\\\\'], ['"', "\n", '\\'], $matches[1]);
		return ['action' => 'final', 'text' => $text];
	}

	private function saveNote(string $topic, string $note): array {
		if ($topic === '') {
			return ['error' => 'memory_save_note precisa de um "topic" nao vazio.'];
		}
		$this->memory->remember($topic, $note);
		return ['saved' => true, 'topic' => $topic];
	}

	private function saveRecipe(string $appId, array $args, ?string $userId): array {
		if ($appId === '' || trim((string)($args['task'] ?? '')) === '') {
			return ['error' => 'memory_save_recipe precisa de "app_id" e "task" nao vazios.'];
		}
		$recipe = [
			'task' => (string)$args['task'],
			'method' => (string)($args['method'] ?? ''),
			'path' => (string)($args['path'] ?? ''),
			'body_template' => $args['body_template'] ?? null,
			'example' => $args['example'] ?? null,
		];
		$recipes = $this->memory->saveRecipe($appId, $recipe);
		$savedStatus = 'tentative';
		foreach ($recipes as $stored) {
			if (($stored['task'] ?? null) === $recipe['task']) {
				$savedStatus = (string)($stored['status'] ?? 'tentative');
				break;
			}
		}

		// Auditoria: esta escrita muda o que app_api_call executa da proxima
		// vez sem mais confirmacao -- fica registada tal como as chamadas
		// dinamicas e as regras de comportamento gerais.
		$this->logger->warning('appsagent: receita de app guardada/atualizada pelo agente', [
			'app_id' => $appId,
			'task' => $recipe['task'],
			'status' => $savedStatus,
			'user' => $userId,
		]);

		return ['saved' => true, 'recipes' => $recipes];
	}

	private function describeAppUsage(string $appId): array {
		if ($appId === '') {
			return ['error' => 'memory_describe_app precisa de "app_id".'];
		}
		$info = $this->discovery->getAppInfo($appId);
		$grouped = $this->memory->getRecipesGrouped($appId);
		return [
			'app_id' => $appId,
			'name' => $info['name'] ?? $appId,
			'summary' => $info['summary'] ?? '',
			'confirmed_actions' => $grouped['confirmed'],
			'tentative_actions' => $grouped['tentative'],
		];
	}

	/**
	 * Porta de confirmacao obrigatoria para acoes destrutivas: sem
	 * args['confirm'] === true, recusa sem executar nada. O prompt instrui o
	 * modelo a nunca definir confirm:true na mesma resposta em que o
	 * utilizador pediu a eliminacao pela primeira vez -- so depois de ele
	 * confirmar numa mensagem separada.
	 */
	private function requiresConfirmation(array $args): ?array {
		if (($args['confirm'] ?? null) === true) {
			return null;
		}
		return [
			'error' => 'Esta e uma acao destrutiva/irreversivel -- recusada sem confirmacao explicita.',
			'requires_confirmation' => true,
			'hint' => 'Pergunta ao utilizador se tem a certeza (numa resposta "final", sem chamar nenhuma '
				. 'ferramenta). So depois de ele confirmar explicitamente numa mensagem seguinte, repete '
				. 'esta chamada com confirm:true.',
		];
	}

	private function dispatchTool(string $name, array $args, ?string $userId): array {
		try {
			return match ($name) {
				'calendar_list_events' => ['events' => $this->calendar->listEvents(
					$args['start'] ?? null,
					$args['end'] ?? null,
					$args['calendar'] ?? null,
				)],
				'calendar_create_event' => $this->calendar->createEvent(
					(string)($args['summary'] ?? ''),
					(string)($args['start'] ?? ''),
					(string)($args['end'] ?? ''),
					(string)($args['description'] ?? ''),
					$args['calendar'] ?? null,
				),
				'calendar_update_event' => $this->calendar->updateEvent(
					(string)($args['uid'] ?? ''),
					$args,
					$args['calendar'] ?? null,
				),
				'calendar_deactivate_event' => $this->calendar->deactivateEvent(
					(string)($args['uid'] ?? ''),
					$args['calendar'] ?? null,
				),
				'calendar_delete_event' => $this->requiresConfirmation($args) ?? $this->calendar->deleteEvent(
					(string)($args['uid'] ?? ''),
					$args['calendar'] ?? null,
				),
				'task_lists' => ['lists' => $this->tasks->listTaskLists()],
				'task_list' => ['tasks' => $this->tasks->listTasks(
					filter_var($args['include_completed'] ?? false, FILTER_VALIDATE_BOOLEAN),
					$args['list'] ?? null,
				)],
				'task_create' => $this->tasks->createTask(
					(string)($args['summary'] ?? ''),
					(string)($args['due'] ?? ''),
					(string)($args['description'] ?? ''),
					isset($args['priority']) ? (int)$args['priority'] : null,
					isset($args['parent']) ? (string)$args['parent'] : null,
					$args['list'] ?? null,
				),
				'task_update' => $this->tasks->updateTask((string)($args['uid'] ?? ''), $args, $args['list'] ?? null),
				'task_complete' => $this->tasks->completeTask((string)($args['uid'] ?? ''), $args['list'] ?? null),
				'task_reopen' => $this->tasks->reopenTask((string)($args['uid'] ?? ''), $args['list'] ?? null),
				'task_cancel' => $this->tasks->cancelTask((string)($args['uid'] ?? ''), $args['list'] ?? null),
				'task_delete' => $this->requiresConfirmation($args) ?? $this->tasks->deleteTask(
					(string)($args['uid'] ?? ''),
					$args['list'] ?? null,
				),
				'discovery_list_apps' => ['apps' => $this->discovery->listEnabledAppsDetailed()],
				'discovery_describe_app' => $this->discovery->describeApp((string)($args['app_id'] ?? '')),
				'discovery_describe_app_api' => $this->routes->describe(
					(string)($args['app_id'] ?? ''),
					filter_var($args['refresh'] ?? false, FILTER_VALIDATE_BOOLEAN),
				),
				'discovery_read_app_docs' => $this->routes->readDocs(
					(string)($args['app_id'] ?? ''),
					isset($args['file']) ? (string)$args['file'] : null,
				),
				'memory_save_note' => $this->saveNote(
					(string)($args['topic'] ?? ''),
					(string)($args['note'] ?? ''),
				),
				'memory_recall' => ['note' => $this->memory->recall((string)($args['topic'] ?? ''))],
				'memory_save_recipe' => $this->saveRecipe((string)($args['app_id'] ?? ''), $args, $userId),
				'memory_get_recipes' => ['recipes' => $this->memory->getRecipes((string)($args['app_id'] ?? ''))],
				'memory_list_learned_apps' => ['apps' => $this->memory->listLearnedApps()],
				'memory_describe_app' => $this->describeAppUsage((string)($args['app_id'] ?? '')),
				'memory_list_notes' => ['notes' => $this->memory->recallAll()],
				'app_api_call' => (strtoupper(trim((string)($args['method'] ?? ''))) === 'DELETE' ? $this->requiresConfirmation($args) : null)
					?? $this->dynamicApi->call(
						(string)($args['app_id'] ?? ''),
						(string)($args['method'] ?? ''),
						(string)($args['path'] ?? ''),
						isset($args['body']) ? (array)$args['body'] : null,
						isset($args['query']) ? (array)$args['query'] : null,
					),
				'discovery_list_app_commands' => $this->occDiscovery->listAppCommands((string)($args['app_id'] ?? '')),
				'discovery_describe_command' => $this->occDiscovery->describeCommand((string)($args['command'] ?? '')),
				'agent_learn_rule' => $userId === null
					? ['error' => 'agent_learn_rule so pode ser usado por um utilizador Nextcloud autenticado '
						. '(Assistant/Chat) -- nao a partir da fila/cron ou do Telegram, que correm sem '
						. 'utilizador associado.']
					: $this->learnRule((string)($args['name'] ?? ''), (string)($args['text'] ?? ''), $userId),
				'memory_forget_app' => ['forgotten' => $this->memory->forgetApp((string)($args['app_id'] ?? ''))],
				'calculator' => $this->supervisorTools->calculate((string)($args['expression'] ?? '')),
				'web_search' => $this->supervisorTools->webSearch(
					(string)($args['query'] ?? ''),
					(int)($args['max_results'] ?? 5),
				),
				'datetime_calc' => $this->supervisorTools->dateTimeQuestion((string)($args['question'] ?? '')),
				default => ['error' => "Ferramenta desconhecida: {$name}"],
			};
		} catch (\Throwable $e) {
			$this->logger->warning('appsagent: falha ao executar ferramenta', [
				'tool' => $name,
				'exception' => $e,
			]);
			return ['error' => $e->getMessage()];
		}
	}
}
