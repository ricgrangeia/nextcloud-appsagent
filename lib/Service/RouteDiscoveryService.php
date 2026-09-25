<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Service;

use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Descobre (so leitura) o catalogo de operacoes HTTP de uma app instalada,
 * juntando as fontes que qualquer app Nextcloud tem -- nao so o openapi.json,
 * que a maioria nao publica:
 *
 *  - appinfo/routes.php        (rotas 'routes' -> /apps/<id>/..., 'ocs' -> /ocs/v2.php/apps/<id>/...)
 *  - atributos nos controladores  (#[ApiRoute], #[FrontpageRoute], #[Route]) via reflexao,
 *                                  incluindo se exigem CSRF (i.e. se sao chamaveis com app password)
 *  - openapi.json               (quando existir)
 *
 * O resultado fica em cache na memoria do agente (topico catalog:<app>) para
 * as proximas consultas serem instantaneas. Tambem le a documentacao que a
 * app envie (README.md, docs/**\/*.md) para o agente perceber a semantica.
 */
class RouteDiscoveryService {
	private const CACHE_PREFIX = 'catalog:';
	private const MAX_DOC_CHARS = 12000;

	public function __construct(
		private IAppManager $appManager,
		private MemoryService $memory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{app: string, operations: list<array<string,mixed>>, docs: list<string>, sources: list<string>, cached: bool}|array{error: string}
	 */
	public function describe(string $appId, bool $refresh = false): array {
		try {
			$appPath = $this->appManager->getAppPath($appId);
		} catch (\Throwable $e) {
			return ['error' => "Nao encontrei a app '{$appId}': " . $e->getMessage()];
		}

		if (!$refresh) {
			$cached = $this->memory->recall(self::CACHE_PREFIX . $appId);
			if ($cached !== null) {
				$decoded = json_decode($cached, true);
				if (is_array($decoded) && isset($decoded['operations'])) {
					$decoded['cached'] = true;
					return $decoded;
				}
			}
		}

		$operations = [];
		$sources = [];

		$fromRoutes = $this->fromRoutesFile($appId, $appPath);
		if ($fromRoutes !== []) {
			$operations = array_merge($operations, $fromRoutes);
			$sources[] = 'appinfo/routes.php';
		}

		$fromAttributes = $this->fromControllerAttributes($appId, $appPath);
		if ($fromAttributes !== []) {
			$operations = array_merge($operations, $fromAttributes);
			$sources[] = 'atributos nos controladores';
		}

		$fromOpenApi = $this->fromOpenApi($appPath);
		if ($fromOpenApi !== []) {
			$operations = array_merge($operations, $fromOpenApi);
			$sources[] = 'openapi.json';
		}

		$operations = $this->dedupe($operations);

		$catalog = [
			'app' => $appId,
			'operations' => $operations,
			'docs' => $this->listDocs($appPath),
			'sources' => $sources,
			'cached' => false,
			'discovered_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
		];

		if ($operations !== [] || $catalog['docs'] !== []) {
			$this->memory->remember(self::CACHE_PREFIX . $appId, json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		}

		return $catalog;
	}

	/** Verifica se metodo+caminho concreto correspondem a alguma operacao declarada da app. */
	public function isDeclared(string $appId, string $method, string $actualPath): ?array {
		$catalog = $this->describe($appId);
		if (isset($catalog['error'])) {
			return null;
		}
		$method = strtoupper($method);
		$actualSegments = explode('/', trim($this->stripQuery($actualPath), '/'));

		foreach ($catalog['operations'] as $operation) {
			if (strtoupper((string)$operation['method']) !== $method) {
				continue;
			}
			$templateSegments = explode('/', trim((string)$operation['path'], '/'));
			if (count($templateSegments) !== count($actualSegments)) {
				continue;
			}
			$matches = true;
			foreach ($templateSegments as $i => $segment) {
				if (str_starts_with($segment, '{') && str_ends_with($segment, '}')) {
					continue;
				}
				if ($segment !== $actualSegments[$i]) {
					$matches = false;
					break;
				}
			}
			if ($matches) {
				return $operation;
			}
		}
		return null;
	}

	/**
	 * Le um ficheiro de documentacao da app (README.md ou docs/**\/*.md).
	 * @return array{app: string, file: string, content: string, truncated: bool}|array{error: string, available?: list<string>}
	 */
	public function readDocs(string $appId, ?string $file): array {
		try {
			$appPath = $this->appManager->getAppPath($appId);
		} catch (\Throwable $e) {
			return ['error' => "Nao encontrei a app '{$appId}': " . $e->getMessage()];
		}

		$available = $this->listDocs($appPath);
		if ($available === []) {
			return ['error' => "A app '{$appId}' nao envia documentacao (README.md / docs/*.md)."];
		}

		$file = $file !== null && $file !== '' ? ltrim($file, '/') : $available[0];
		if (!in_array($file, $available, true)) {
			return ['error' => "Ficheiro '{$file}' nao e um documento disponivel desta app.", 'available' => $available];
		}

		$real = realpath($appPath . '/' . $file);
		$root = realpath($appPath);
		if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
			return ['error' => 'Caminho de documentacao invalido.'];
		}

		$content = (string)file_get_contents($real);
		$truncated = strlen($content) > self::MAX_DOC_CHARS;
		if ($truncated) {
			$content = substr($content, 0, self::MAX_DOC_CHARS) . "\n\n[... truncado; pede outro ficheiro ou uma parte especifica ...]";
		}

		return ['app' => $appId, 'file' => $file, 'content' => $content, 'truncated' => $truncated];
	}

	// ---------------------------------------------------------------- fontes

	/** @return list<array<string,mixed>> */
	private function fromRoutesFile(string $appId, string $appPath): array {
		$file = $appPath . '/appinfo/routes.php';
		if (!is_file($file)) {
			return [];
		}

		try {
			// routes.php modernos devolvem um array puro; os antigos usam $this->create()
			// (o que aqui lanca um Error, apanhado abaixo) -- nesse caso ficamos sem esta fonte.
			$routes = (static function (string $f) {
				return include $f;
			})($file);
		} catch (\Throwable $e) {
			$this->logger->debug('appsagent: routes.php nao devolveu array', ['app' => $appId, 'exception' => $e]);
			return [];
		}

		if (!is_array($routes)) {
			return [];
		}

		$operations = [];
		foreach (['routes' => '/apps/' . $appId, 'ocs' => '/ocs/v2.php/apps/' . $appId] as $key => $prefix) {
			foreach ((array)($routes[$key] ?? []) as $route) {
				if (!is_array($route) || !isset($route['url'])) {
					continue;
				}
				$operations[] = [
					'method' => strtoupper((string)($route['verb'] ?? 'GET')),
					'path' => $prefix . '/' . ltrim((string)$route['url'], '/'),
					'kind' => $key === 'ocs' ? 'ocs' : 'app',
					'handler' => (string)($route['name'] ?? ''),
					'source' => 'routes.php',
				];
			}
		}

		// Recursos REST declarados em bloco (['resources' => ['note' => ['url' => '/notes']]])
		foreach ((array)($routes['resources'] ?? []) as $name => $resource) {
			if (!is_array($resource) || !isset($resource['url'])) {
				continue;
			}
			$base = '/apps/' . $appId . '/' . ltrim((string)$resource['url'], '/');
			foreach ([['GET', ''], ['POST', ''], ['GET', '/{id}'], ['PUT', '/{id}'], ['DELETE', '/{id}']] as [$verb, $suffix]) {
				$operations[] = [
					'method' => $verb,
					'path' => $base . $suffix,
					'kind' => 'app',
					'handler' => (string)$name . ' (resource)',
					'source' => 'routes.php',
				];
			}
		}

		return $operations;
	}

	/** @return list<array<string,mixed>> */
	private function fromControllerAttributes(string $appId, string $appPath): array {
		$dir = $appPath . '/lib/Controller';
		if (!is_dir($dir)) {
			return [];
		}

		$namespace = $this->appNamespace($appId, $appPath);
		$operations = [];

		foreach (glob($dir . '/*.php') ?: [] as $file) {
			$class = 'OCA\\' . $namespace . '\\Controller\\' . basename($file, '.php');
			try {
				if (!class_exists($class)) {
					continue;
				}
				$reflection = new \ReflectionClass($class);
			} catch (\Throwable) {
				continue;
			}

			foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class) {
					continue;
				}
				$flags = $this->methodFlags($method);

				foreach ($method->getAttributes() as $attribute) {
					$short = substr($attribute->getName(), (int)strrpos($attribute->getName(), '\\') + 1);
					if (!in_array($short, ['ApiRoute', 'FrontpageRoute', 'Route'], true)) {
						continue;
					}
					$args = $attribute->getArguments();
					$route = $this->routeFromAttributeArgs($short, $args);
					if ($route === null) {
						continue;
					}
					[$verb, $url, $root, $isOcs] = $route;

					$prefix = $isOcs
						? '/ocs/v2.php' . ($root !== '' ? $root : '/apps/' . $appId)
						: '/apps/' . $appId;

					$operations[] = [
						'method' => strtoupper($verb),
						'path' => $prefix . '/' . ltrim($url, '/'),
						'kind' => $isOcs ? 'ocs' : 'app',
						'handler' => $reflection->getShortName() . '::' . $method->getName(),
						'source' => 'atributo #[' . $short . ']',
						'no_csrf' => $flags['no_csrf'],
						'public' => $flags['public'],
						'cors' => $flags['cors'],
					];
				}
			}
		}

		return $operations;
	}

	/** @return array{0: string, 1: string, 2: string, 3: bool}|null [verb, url, root, isOcs] */
	private function routeFromAttributeArgs(string $attributeShortName, array $args): ?array {
		$get = static function (array $args, string $name, int $position) {
			if (array_key_exists($name, $args)) {
				return $args[$name];
			}
			return $args[$position] ?? null;
		};

		if ($attributeShortName === 'Route') {
			// Route(type, verb, url, requirements, defaults, root, postfix)
			$type = (string)($get($args, 'type', 0) ?? '');
			$verb = $get($args, 'verb', 1);
			$url = $get($args, 'url', 2);
			$root = (string)($get($args, 'root', 5) ?? '');
			$isOcs = $type === 'ocs';
		} else {
			// ApiRoute/FrontpageRoute(verb, url, requirements, defaults, root, postfix)
			$verb = $get($args, 'verb', 0);
			$url = $get($args, 'url', 1);
			$root = (string)($get($args, 'root', 4) ?? '');
			$isOcs = $attributeShortName === 'ApiRoute';
		}

		if (!is_string($verb) || !is_string($url)) {
			return null;
		}
		return [$verb, $url, $root, $isOcs];
	}

	/** @return array{no_csrf: bool, public: bool, cors: bool} */
	private function methodFlags(\ReflectionMethod $method): array {
		$flags = ['no_csrf' => false, 'public' => false, 'cors' => false];
		foreach ($method->getAttributes() as $attribute) {
			$short = substr($attribute->getName(), (int)strrpos($attribute->getName(), '\\') + 1);
			if ($short === 'NoCSRFRequired') {
				$flags['no_csrf'] = true;
			} elseif ($short === 'PublicPage') {
				$flags['public'] = true;
			} elseif ($short === 'CORS') {
				$flags['cors'] = true;
			}
		}
		$doc = (string)$method->getDocComment();
		if (str_contains($doc, '@NoCSRFRequired')) {
			$flags['no_csrf'] = true;
		}
		if (str_contains($doc, '@PublicPage')) {
			$flags['public'] = true;
		}
		if (str_contains($doc, '@CORS')) {
			$flags['cors'] = true;
		}
		return $flags;
	}

	/** @return list<array<string,mixed>> */
	private function fromOpenApi(string $appPath): array {
		$specPath = $appPath . '/openapi.json';
		if (!is_file($specPath)) {
			return [];
		}
		$spec = json_decode((string)file_get_contents($specPath), true);
		if (!is_array($spec)) {
			return [];
		}

		$base = '';
		$servers = (array)($spec['servers'] ?? []);
		if (isset($servers[0]['url']) && is_string($servers[0]['url']) && !str_starts_with($servers[0]['url'], 'http')) {
			$base = rtrim($servers[0]['url'], '/');
		}

		$operations = [];
		foreach ((array)($spec['paths'] ?? []) as $path => $methods) {
			if (!is_array($methods)) {
				continue;
			}
			foreach ($methods as $method => $details) {
				if (!is_array($details)) {
					continue;
				}
				$operations[] = [
					'method' => strtoupper((string)$method),
					'path' => $base . $path,
					'kind' => str_starts_with($base . $path, '/ocs/') ? 'ocs' : 'app',
					'handler' => (string)($details['operationId'] ?? ''),
					'summary' => (string)($details['summary'] ?? ($details['description'] ?? '')),
					'source' => 'openapi.json',
				];
			}
		}
		return $operations;
	}

	// -------------------------------------------------------------- auxiliares

	private function appNamespace(string $appId, string $appPath): string {
		$info = $appPath . '/appinfo/info.xml';
		if (is_file($info)) {
			try {
				$xml = new \SimpleXMLElement((string)file_get_contents($info));
				$ns = trim((string)($xml->namespace ?? ''));
				if ($ns !== '') {
					return $ns;
				}
			} catch (\Throwable) {
				// cai no fallback abaixo
			}
		}
		return ucfirst($appId);
	}

	/** @return list<string> caminhos relativos a raiz da app */
	private function listDocs(string $appPath): array {
		$docs = [];
		foreach (['README.md', 'readme.md', 'API.md', 'docs/README.md'] as $candidate) {
			if (is_file($appPath . '/' . $candidate)) {
				$docs[] = $candidate;
			}
		}
		$docsDir = $appPath . '/docs';
		if (is_dir($docsDir)) {
			$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($docsDir, \FilesystemIterator::SKIP_DOTS));
			foreach ($iterator as $entry) {
				/** @var \SplFileInfo $entry */
				if (strtolower($entry->getExtension()) !== 'md') {
					continue;
				}
				$relative = ltrim(str_replace($appPath, '', $entry->getPathname()), '/\\');
				$relative = str_replace('\\', '/', $relative);
				if (!in_array($relative, $docs, true)) {
					$docs[] = $relative;
				}
				if (count($docs) >= 40) {
					break;
				}
			}
		}
		sort($docs);
		return $docs;
	}

	/** @param list<array<string,mixed>> $operations @return list<array<string,mixed>> */
	private function dedupe(array $operations): array {
		$seen = [];
		$result = [];
		foreach ($operations as $operation) {
			$key = $operation['method'] . ' ' . $operation['path'];
			if (isset($seen[$key])) {
				// junta flags/summary da fonte adicional a entrada ja existente
				$result[$seen[$key]] = $operation + $result[$seen[$key]];
				continue;
			}
			$seen[$key] = count($result);
			$result[] = $operation;
		}
		return $result;
	}

	private function stripQuery(string $path): string {
		$pos = strpos($path, '?');
		return $pos === false ? $path : substr($path, 0, $pos);
	}
}
