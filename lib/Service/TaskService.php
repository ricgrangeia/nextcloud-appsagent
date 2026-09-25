<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Service;

use OCA\AppsAgent\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * CRUD de tarefas (app Tasks do Nextcloud) via CalDAV, com a mesma conta
 * dedicada do agente que o CalendarService usa.
 *
 * A app Tasks nao tem API REST/OCS propria: e um front-end sobre CalDAV, e
 * cada tarefa e um componente VTODO guardado num calendario. Uma "lista de
 * tarefas" e portanto um calendario cujo supported-calendar-component-set
 * inclui VTODO -- o mesmo store dos eventos, so muda o componente.
 */
class TaskService {
	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
		private AgentAccountService $agentAccount,
		private InternalHttpService $internalHttp,
	) {
	}

	/** @return array{0: string, 1: string} */
	private function credentials(): array {
		return $this->agentAccount->credentials();
	}

	/**
	 * Lista de tarefas por omissao. Cai para o mesmo calendario dos eventos
	 * (o "personal" do Nextcloud aceita VEVENT e VTODO), mas fica numa chave
	 * propria para quem tenha uma lista dedicada a poder apontar para la sem
	 * mexer nos eventos.
	 */
	private function defaultList(): string {
		$calendarDefault = $this->config->getAppValue(Application::APP_ID, 'calendar_uri', 'personal');
		return $this->config->getAppValue(Application::APP_ID, 'tasks_calendar_uri', $calendarDefault);
	}

	/** Igual ao CalendarService: interpreta a hora "de parede" no fuso configurado. */
	private function parseDateTime(string $value): \DateTimeImmutable {
		$wallClock = preg_replace('/(Z|[+-]\d{2}:?\d{2})$/', '', trim($value));
		$timezone = new \DateTimeZone(
			$this->config->getAppValue(Application::APP_ID, 'default_timezone', 'UTC')
		);
		return new \DateTimeImmutable($wallClock, $timezone);
	}

	private function calendarsPath(string $username): string {
		return '/remote.php/dav/calendars/' . rawurlencode($username);
	}

	/**
	 * Descobre que colecoes aceitam tarefas. Sem isto, um utilizador cuja
	 * lista nao se chame "personal" recebe sempre zero tarefas e nao tem como
	 * perceber que o problema e so o nome da lista.
	 */
	public function listTaskLists(): array {
		[$username, $password] = $this->credentials();

		$body = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
			. '<d:prop><d:displayname/><c:supported-calendar-component-set/></d:prop>'
			. '</d:propfind>';

		$path = $this->calendarsPath($username) . '/';
		[$url, $extraHeaders] = $this->internalHttp->resolve($path);
		$response = $this->clientService->newClient()->request('PROPFIND', $url, [
			'auth' => [$username, $password],
			'timeout' => 30,
			'headers' => $extraHeaders + ['Content-Type' => 'application/xml', 'Depth' => '1'],
			'body' => $body,
		]);

		$lists = [];
		$doc = new \SimpleXMLElement((string)$response->getBody());
		$doc->registerXPathNamespace('d', 'DAV:');
		$doc->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');

		foreach ($doc->xpath('//d:response') as $responseNode) {
			// Os namespaces registados em $doc nao se propagam para os sub-nos
			// devolvidos por xpath() -- mesma armadilha ja documentada em
			// CalendarService::parseEventsFromMultistatus().
			$responseNode->registerXPathNamespace('d', 'DAV:');
			$responseNode->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');

			$comps = $responseNode->xpath('.//c:comp');
			$supportsTodo = false;
			foreach ($comps as $comp) {
				if (strtoupper((string)($comp['name'] ?? '')) === 'VTODO') {
					$supportsTodo = true;
					break;
				}
			}
			if (!$supportsTodo) {
				continue;
			}

			$hrefNodes = $responseNode->xpath('.//d:href');
			if (empty($hrefNodes)) {
				continue;
			}
			$nameNodes = $responseNode->xpath('.//d:displayname');

			$lists[] = [
				'uri' => rawurldecode(trim(basename(rtrim((string)$hrefNodes[0], '/')))),
				'name' => empty($nameNodes) ? null : (string)$nameNodes[0],
			];
		}

		return $lists;
	}

	/**
	 * Lista tarefas. Ao contrario dos eventos, NAO se usa filtro time-range:
	 * em VTODO esse filtro so encontra tarefas que tenham DTSTART/DUE/DURATION/
	 * COMPLETED, ou seja, uma tarefa simples sem datas (o caso mais comum)
	 * desapareceria em silencio. Filtra-se do lado de ca.
	 */
	public function listTasks(bool $includeCompleted = false, ?string $listUri = null): array {
		[$username, $password] = $this->credentials();
		$listUri = $listUri ?: $this->defaultList();

		$body = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
			. '<d:prop><d:getetag/><c:calendar-data/></d:prop>'
			. '<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VTODO"/>'
			. '</c:comp-filter></c:filter>'
			. '</c:calendar-query>';

		$path = $this->calendarsPath($username) . '/' . rawurlencode($listUri) . '/';
		[$url, $extraHeaders] = $this->internalHttp->resolve($path);
		$response = $this->clientService->newClient()->request('REPORT', $url, [
			'auth' => [$username, $password],
			'timeout' => 30,
			'headers' => $extraHeaders + ['Content-Type' => 'application/xml', 'Depth' => '1'],
			'body' => $body,
		]);

		$tasks = $this->parseTasksFromMultistatus((string)$response->getBody(), $listUri);

		if (!$includeCompleted) {
			$tasks = array_values(array_filter(
				$tasks,
				static fn (array $t): bool => !in_array($t['status'], ['COMPLETED', 'CANCELLED'], true),
			));
		}

		return $tasks;
	}

	/** Igual a debugListEvents, para isolar problemas de CalDAV do raciocinio do agente. */
	public function debugListTasks(?string $listUri = null): array {
		[$username, $password] = $this->credentials();
		$listUri = $listUri ?: $this->defaultList();

		$body = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
			. '<d:prop><d:getetag/><c:calendar-data/></d:prop>'
			. '<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VTODO"/>'
			. '</c:comp-filter></c:filter>'
			. '</c:calendar-query>';

		$path = $this->calendarsPath($username) . '/' . rawurlencode($listUri) . '/';
		[$url, $extraHeaders] = $this->internalHttp->resolve($path);
		$response = $this->clientService->newClient()->request('REPORT', $url, [
			'auth' => [$username, $password],
			'timeout' => 30,
			'headers' => $extraHeaders + ['Content-Type' => 'application/xml', 'Depth' => '1'],
			'body' => $body,
		]);

		return [
			'url' => $url,
			'list' => $listUri,
			'request_body' => $body,
			'status' => $response->getStatusCode(),
			'response_body' => (string)$response->getBody(),
		];
	}

	private function parseTasksFromMultistatus(string $xml, string $listUri): array {
		$tasks = [];
		$doc = new \SimpleXMLElement($xml);
		$doc->registerXPathNamespace('d', 'DAV:');
		$doc->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');

		foreach ($doc->xpath('//d:response') as $responseNode) {
			$responseNode->registerXPathNamespace('d', 'DAV:');
			$responseNode->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');

			$hrefNodes = $responseNode->xpath('.//d:href');
			$dataNodes = $responseNode->xpath('.//c:calendar-data');
			if (empty($hrefNodes) || empty($dataNodes)) {
				continue;
			}

			try {
				$vcalendar = Reader::read((string)$dataNodes[0]);
			} catch (\Throwable) {
				continue;
			}

			foreach ($vcalendar->select('VTODO') as $vtodo) {
				$tasks[] = [
					'uid' => isset($vtodo->UID) ? (string)$vtodo->UID : null,
					'summary' => isset($vtodo->SUMMARY) ? (string)$vtodo->SUMMARY : '',
					'description' => isset($vtodo->DESCRIPTION) ? (string)$vtodo->DESCRIPTION : '',
					'due' => isset($vtodo->DUE) ? $vtodo->DUE->getDateTime()->format(DATE_ATOM) : null,
					'start' => isset($vtodo->DTSTART) ? $vtodo->DTSTART->getDateTime()->format(DATE_ATOM) : null,
					'status' => isset($vtodo->STATUS) ? strtoupper((string)$vtodo->STATUS) : 'NEEDS-ACTION',
					'percent_complete' => isset($vtodo->{'PERCENT-COMPLETE'})
						? (int)(string)$vtodo->{'PERCENT-COMPLETE'}
						: 0,
					'priority' => isset($vtodo->PRIORITY) ? (int)(string)$vtodo->PRIORITY : 0,
					'parent' => isset($vtodo->{'RELATED-TO'}) ? (string)$vtodo->{'RELATED-TO'} : null,
					'list' => $listUri,
					'href' => (string)$hrefNodes[0],
				];
			}
		}

		return $tasks;
	}

	public function createTask(
		string $summary,
		string $due = '',
		string $description = '',
		?int $priority = null,
		?string $parentUid = null,
		?string $listUri = null,
	): array {
		[$username, $password] = $this->credentials();
		$listUri = $listUri ?: $this->defaultList();
		$uid = bin2hex(random_bytes(16));

		$vcalendar = new VCalendar();
		$vtodo = $vcalendar->add('VTODO', [
			'UID' => $uid,
			'SUMMARY' => $summary,
		]);
		unset($vtodo->DTSTAMP);
		$vtodo->add('DTSTAMP', new \DateTimeImmutable('now'));
		$vtodo->add('STATUS', 'NEEDS-ACTION');
		if ($due !== '') {
			$vtodo->add('DUE', $this->parseDateTime($due));
		}
		if ($description !== '') {
			$vtodo->add('DESCRIPTION', $description);
		}
		if ($priority !== null && $priority > 0) {
			$vtodo->add('PRIORITY', (string)$priority);
		}
		if ($parentUid !== null && $parentUid !== '') {
			$vtodo->add('RELATED-TO', $parentUid);
		}

		$path = $this->calendarsPath($username) . '/' . rawurlencode($listUri) . '/' . $uid . '.ics';
		[$url, $extraHeaders] = $this->internalHttp->resolve($path);
		$this->clientService->newClient()->put($url, [
			'auth' => [$username, $password],
			'timeout' => 30,
			'headers' => $extraHeaders + ['Content-Type' => 'text/calendar; charset=utf-8'],
			'body' => $vcalendar->serialize(),
		]);

		return ['uid' => $uid, 'list' => $listUri, 'summary' => $summary];
	}

	private function findTask(string $uid, ?string $listUri): array {
		foreach ($this->listTasks(true, $listUri) as $task) {
			if ($task['uid'] === $uid) {
				return $task;
			}
		}
		throw new \RuntimeException("appsagent: tarefa com UID '{$uid}' nao encontrada.");
	}

	/** @return array{0: string, 1: array<string,string>, 2: \Sabre\VObject\Document} */
	private function fetchAndParse(string $href, string $username, string $password): array {
		[$url, $extraHeaders] = $this->internalHttp->resolve($href);
		$response = $this->clientService->newClient()->get($url, [
			'auth' => [$username, $password],
			'timeout' => 30,
			'headers' => $extraHeaders,
		]);
		return [$url, $extraHeaders, Reader::read((string)$response->getBody())];
	}

	private function put(string $url, array $extraHeaders, string $username, string $password, string $body): void {
		$this->clientService->newClient()->put($url, [
			'auth' => [$username, $password],
			'timeout' => 30,
			'headers' => $extraHeaders + ['Content-Type' => 'text/calendar; charset=utf-8'],
			'body' => $body,
		]);
	}

	public function updateTask(string $uid, array $fields, ?string $listUri = null): array {
		[$username, $password] = $this->credentials();
		$task = $this->findTask($uid, $listUri);
		[$url, $extraHeaders, $vcalendar] = $this->fetchAndParse($task['href'], $username, $password);
		$vtodo = $vcalendar->select('VTODO')[0];

		if (!empty($fields['summary'])) {
			$vtodo->SUMMARY = $fields['summary'];
		}
		if (!empty($fields['description'])) {
			$vtodo->DESCRIPTION = $fields['description'];
		}
		if (!empty($fields['due'])) {
			unset($vtodo->DUE);
			$vtodo->add('DUE', $this->parseDateTime($fields['due']));
		}
		if (!empty($fields['start'])) {
			unset($vtodo->DTSTART);
			$vtodo->add('DTSTART', $this->parseDateTime($fields['start']));
		}
		if (isset($fields['priority']) && (int)$fields['priority'] > 0) {
			unset($vtodo->PRIORITY);
			$vtodo->add('PRIORITY', (string)(int)$fields['priority']);
		}
		if (isset($fields['percent_complete'])) {
			unset($vtodo->{'PERCENT-COMPLETE'});
			$vtodo->add('PERCENT-COMPLETE', (string)max(0, min(100, (int)$fields['percent_complete'])));
		}

		$this->put($url, $extraHeaders, $username, $password, $vcalendar->serialize());

		return ['uid' => $uid, 'summary' => (string)$vtodo->SUMMARY];
	}

	/**
	 * Conclui a tarefa. Os tres campos sao postos em conjunto de proposito:
	 * clientes CalDAV (incluindo a propria app Tasks) esperam STATUS,
	 * COMPLETED e PERCENT-COMPLETE coerentes, e uma tarefa com STATUS
	 * COMPLETED mas sem COMPLETED/100% aparece meia-concluida na interface.
	 */
	public function completeTask(string $uid, ?string $listUri = null): array {
		[$username, $password] = $this->credentials();
		$task = $this->findTask($uid, $listUri);
		[$url, $extraHeaders, $vcalendar] = $this->fetchAndParse($task['href'], $username, $password);
		$vtodo = $vcalendar->select('VTODO')[0];

		unset($vtodo->STATUS, $vtodo->COMPLETED, $vtodo->{'PERCENT-COMPLETE'});
		$vtodo->add('STATUS', 'COMPLETED');
		$vtodo->add('COMPLETED', new \DateTimeImmutable('now'));
		$vtodo->add('PERCENT-COMPLETE', '100');

		$this->put($url, $extraHeaders, $username, $password, $vcalendar->serialize());

		return ['uid' => $uid, 'status' => 'COMPLETED'];
	}

	/** Reabre uma tarefa concluida/cancelada, sem perder o resto do conteudo. */
	public function reopenTask(string $uid, ?string $listUri = null): array {
		[$username, $password] = $this->credentials();
		$task = $this->findTask($uid, $listUri);
		[$url, $extraHeaders, $vcalendar] = $this->fetchAndParse($task['href'], $username, $password);
		$vtodo = $vcalendar->select('VTODO')[0];

		unset($vtodo->STATUS, $vtodo->COMPLETED, $vtodo->{'PERCENT-COMPLETE'});
		$vtodo->add('STATUS', 'NEEDS-ACTION');

		$this->put($url, $extraHeaders, $username, $password, $vcalendar->serialize());

		return ['uid' => $uid, 'status' => 'NEEDS-ACTION'];
	}

	/** Equivalente ao deactivateEvent: marca como CANCELLED sem apagar. */
	public function cancelTask(string $uid, ?string $listUri = null): array {
		[$username, $password] = $this->credentials();
		$task = $this->findTask($uid, $listUri);
		[$url, $extraHeaders, $vcalendar] = $this->fetchAndParse($task['href'], $username, $password);
		$vtodo = $vcalendar->select('VTODO')[0];

		unset($vtodo->STATUS);
		$vtodo->add('STATUS', 'CANCELLED');

		$this->put($url, $extraHeaders, $username, $password, $vcalendar->serialize());

		return ['uid' => $uid, 'status' => 'CANCELLED'];
	}

	public function deleteTask(string $uid, ?string $listUri = null): array {
		[$username, $password] = $this->credentials();
		$task = $this->findTask($uid, $listUri);
		[$url, $extraHeaders] = $this->internalHttp->resolve($task['href']);

		$this->clientService->newClient()->delete($url, [
			'auth' => [$username, $password],
			'timeout' => 30,
			'headers' => $extraHeaders,
		]);

		return ['uid' => $uid, 'deleted' => true];
	}
}
