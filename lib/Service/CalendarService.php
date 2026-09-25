<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Service;

use OCA\AppsAgent\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * CRUD de eventos de Calendario via CalDAV, autenticado com uma conta Nextcloud
 * dedicada ao agente (username + app password, guardados na config da app).
 *
 * Usa CalDAV (e nao OCP\Calendar\IManager) porque a API publica de Calendar do
 * Nextcloud so documenta leitura e criacao (ICreateFromString /
 * ICalendarEventBuilder) -- nao ha metodo publico documentado para atualizar
 * ou eliminar um evento existente.
 */
class CalendarService {
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

	private function defaultCalendar(): string {
		return $this->config->getAppValue(Application::APP_ID, 'calendar_uri', 'personal');
	}

	/**
	 * O LLM muitas vezes copia o sufixo de fuso horario (Z ou +00:00) das
	 * observacoes de ferramentas anteriores (calendar_list_events devolve
	 * DATE_ATOM, que inclui sempre um offset) mesmo quando o utilizador quis
	 * dizer a sua hora local -- ele nao sabe qual e o fuso horario "certo".
	 * Por isso ignoramos sempre esse sufixo, se existir, e interpretamos a
	 * hora "de parede" (ano-mes-dia hora:min:seg) no fuso horario configurado
	 * em default_timezone. E o unico comportamento que corresponde ao que o
	 * utilizador quis dizer quando fala em "as 18:00".
	 */
	private function parseDateTime(string $value): \DateTimeImmutable {
		$wallClock = preg_replace('/(Z|[+-]\d{2}:?\d{2})$/', '', trim($value));
		$timezone = new \DateTimeZone(
			$this->config->getAppValue(Application::APP_ID, 'default_timezone', 'UTC')
		);
		return new \DateTimeImmutable($wallClock, $timezone);
	}

	/** Caminho (nao URL absoluto) da colecao de calendarios do utilizador. */
	private function calendarsPath(string $username): string {
		return '/remote.php/dav/calendars/' . rawurlencode($username);
	}

	public function listEvents(?string $start, ?string $end, ?string $calendarUri = null): array {
		[$username, $password] = $this->credentials();
		$calendarUri = $calendarUri ?: $this->defaultCalendar();

		$startDt = $start ? $this->parseDateTime($start) : new \DateTimeImmutable('-1 month');
		$endDt = $end ? $this->parseDateTime($end) : new \DateTimeImmutable('+6 months');

		// O filtro time-range do CalDAV exige um instante UTC absoluto quando
		// termina em "Z" -- por isso convertemos explicitamente para UTC aqui
		// em vez de so acrescentar a letra "Z" as horas locais (o que daria
		// um instante errado sempre que o fuso horario nao for UTC).
		$utc = new \DateTimeZone('UTC');
		$startUtc = $startDt->setTimezone($utc);
		$endUtc = $endDt->setTimezone($utc);

		$body = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
			. '<d:prop><d:getetag/><c:calendar-data/></d:prop>'
			. '<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">'
			. '<c:time-range start="' . $startUtc->format('Ymd\THis\Z') . '" end="' . $endUtc->format('Ymd\THis\Z') . '"/>'
			. '</c:comp-filter></c:comp-filter></c:filter>'
			. '</c:calendar-query>';

		$path = $this->calendarsPath($username) . '/' . rawurlencode($calendarUri) . '/';
		[$url, $extraHeaders] = $this->internalHttp->resolve($path);
		$response = $this->clientService->newClient()->request('REPORT', $url, [
			'auth' => [$username, $password],
			'timeout' => 30,
			'headers' => $extraHeaders + ['Content-Type' => 'application/xml', 'Depth' => '1'],
			'body' => $body,
		]);

		return $this->parseEventsFromMultistatus((string)$response->getBody(), $calendarUri);
	}

	/**
	 * Diagnostico: repete o mesmo pedido REPORT de listEvents() mas devolve
	 * os dados crus (URL, corpo enviado, estado HTTP, corpo da resposta) em
	 * vez de eventos ja interpretados -- para depurar quando listEvents()
	 * nao encontra o que devia.
	 */
	public function debugListEvents(?string $start, ?string $end, ?string $calendarUri = null): array {
		[$username, $password] = $this->credentials();
		$calendarUri = $calendarUri ?: $this->defaultCalendar();

		$startDt = $start ? $this->parseDateTime($start) : new \DateTimeImmutable('-1 month');
		$endDt = $end ? $this->parseDateTime($end) : new \DateTimeImmutable('+6 months');
		$utc = new \DateTimeZone('UTC');
		$startUtc = $startDt->setTimezone($utc);
		$endUtc = $endDt->setTimezone($utc);

		$body = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
			. '<d:prop><d:getetag/><c:calendar-data/></d:prop>'
			. '<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">'
			. '<c:time-range start="' . $startUtc->format('Ymd\THis\Z') . '" end="' . $endUtc->format('Ymd\THis\Z') . '"/>'
			. '</c:comp-filter></c:comp-filter></c:filter>'
			. '</c:calendar-query>';

		$path = $this->calendarsPath($username) . '/' . rawurlencode($calendarUri) . '/';
		[$url, $extraHeaders] = $this->internalHttp->resolve($path);
		$response = $this->clientService->newClient()->request('REPORT', $url, [
			'auth' => [$username, $password],
			'timeout' => 30,
			'headers' => $extraHeaders + ['Content-Type' => 'application/xml', 'Depth' => '1'],
			'body' => $body,
		]);

		return [
			'url' => $url,
			'request_body' => $body,
			'status' => $response->getStatusCode(),
			'response_body' => (string)$response->getBody(),
		];
	}

	private function parseEventsFromMultistatus(string $xml, string $calendarUri): array {
		$events = [];
		$doc = new \SimpleXMLElement($xml);
		$doc->registerXPathNamespace('d', 'DAV:');
		$doc->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');

		foreach ($doc->xpath('//d:response') as $responseNode) {
			// Os namespaces registados em $doc nao se propagam automaticamente
			// para os sub-nos devolvidos por xpath() -- sem isto, as pesquisas
			// abaixo devolvem sempre vazio e todos os eventos ficam saltados.
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

			foreach ($vcalendar->select('VEVENT') as $vevent) {
				$events[] = [
					'uid' => isset($vevent->UID) ? (string)$vevent->UID : null,
					'summary' => isset($vevent->SUMMARY) ? (string)$vevent->SUMMARY : '',
					'start' => isset($vevent->DTSTART) ? $vevent->DTSTART->getDateTime()->format(DATE_ATOM) : null,
					'end' => isset($vevent->DTEND) ? $vevent->DTEND->getDateTime()->format(DATE_ATOM) : null,
					'status' => isset($vevent->STATUS) ? (string)$vevent->STATUS : 'CONFIRMED',
					'calendar' => $calendarUri,
					'href' => (string)$hrefNodes[0],
				];
			}
		}

		return $events;
	}

	public function createEvent(
		string $summary,
		string $start,
		string $end,
		string $description = '',
		?string $calendarUri = null,
	): array {
		[$username, $password] = $this->credentials();
		$calendarUri = $calendarUri ?: $this->defaultCalendar();
		$uid = bin2hex(random_bytes(16));

		$vcalendar = new VCalendar();
		$vevent = $vcalendar->add('VEVENT', [
			'UID' => $uid,
			'SUMMARY' => $summary,
		]);
		unset($vevent->DTSTAMP);
		$vevent->add('DTSTAMP', new \DateTimeImmutable('now'));
		$vevent->add('DTSTART', $this->parseDateTime($start));
		$vevent->add('DTEND', $this->parseDateTime($end));
		if ($description !== '') {
			$vevent->add('DESCRIPTION', $description);
		}

		$path = $this->calendarsPath($username) . '/' . rawurlencode($calendarUri) . '/' . $uid . '.ics';
		[$url, $extraHeaders] = $this->internalHttp->resolve($path);
		$this->clientService->newClient()->put($url, [
			'auth' => [$username, $password],
			'timeout' => 30,
			'headers' => $extraHeaders + ['Content-Type' => 'text/calendar; charset=utf-8'],
			'body' => $vcalendar->serialize(),
		]);

		return ['uid' => $uid, 'calendar' => $calendarUri, 'summary' => $summary];
	}

	private function findEvent(string $uid, ?string $calendarUri): array {
		$events = $this->listEvents(
			(new \DateTimeImmutable('-1 year'))->format(DATE_ATOM),
			(new \DateTimeImmutable('+2 years'))->format(DATE_ATOM),
			$calendarUri,
		);
		foreach ($events as $event) {
			if ($event['uid'] === $uid) {
				return $event;
			}
		}
		throw new \RuntimeException("appsagent: evento com UID '{$uid}' nao encontrado.");
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

	public function updateEvent(string $uid, array $fields, ?string $calendarUri = null): array {
		[$username, $password] = $this->credentials();
		$event = $this->findEvent($uid, $calendarUri);
		[$url, $extraHeaders, $vcalendar] = $this->fetchAndParse($event['href'], $username, $password);
		$vevent = $vcalendar->select('VEVENT')[0];

		if (!empty($fields['summary'])) {
			$vevent->SUMMARY = $fields['summary'];
		}
		if (!empty($fields['description'])) {
			$vevent->DESCRIPTION = $fields['description'];
		}
		if (!empty($fields['start'])) {
			unset($vevent->DTSTART);
			$vevent->add('DTSTART', $this->parseDateTime($fields['start']));
		}
		if (!empty($fields['end'])) {
			unset($vevent->DTEND);
			$vevent->add('DTEND', $this->parseDateTime($fields['end']));
		}

		$this->clientService->newClient()->put($url, [
			'auth' => [$username, $password],
			'timeout' => 30,
			'headers' => $extraHeaders + ['Content-Type' => 'text/calendar; charset=utf-8'],
			'body' => $vcalendar->serialize(),
		]);

		return ['uid' => $uid, 'summary' => (string)$vevent->SUMMARY];
	}

	public function deactivateEvent(string $uid, ?string $calendarUri = null): array {
		[$username, $password] = $this->credentials();
		$event = $this->findEvent($uid, $calendarUri);
		[$url, $extraHeaders, $vcalendar] = $this->fetchAndParse($event['href'], $username, $password);
		$vevent = $vcalendar->select('VEVENT')[0];

		unset($vevent->STATUS);
		$vevent->add('STATUS', 'CANCELLED');

		$this->clientService->newClient()->put($url, [
			'auth' => [$username, $password],
			'timeout' => 30,
			'headers' => $extraHeaders + ['Content-Type' => 'text/calendar; charset=utf-8'],
			'body' => $vcalendar->serialize(),
		]);

		return ['uid' => $uid, 'status' => 'CANCELLED'];
	}

	public function deleteEvent(string $uid, ?string $calendarUri = null): array {
		[$username, $password] = $this->credentials();
		$event = $this->findEvent($uid, $calendarUri);
		[$url, $extraHeaders] = $this->internalHttp->resolve($event['href']);

		$this->clientService->newClient()->delete($url, [
			'auth' => [$username, $password],
			'timeout' => 30,
			'headers' => $extraHeaders,
		]);

		return ['uid' => $uid, 'deleted' => true];
	}
}
