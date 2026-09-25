<?php

declare(strict_types=1);

namespace OCA\AppsAgent\Controller;

use OCA\AppsAgent\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

class TelegramLinkController extends Controller {
	private const CODE_TTL_SECONDS = 600;

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Gera um codigo de ligacao de curta duracao para o utilizador autenticado
	 * atual, e volta para as definicoes pessoais. O codigo e consumido pelo
	 * TelegramController::webhook() quando o utilizador o manda ao bot.
	 */
	#[NoAdminRequired]
	public function generate(): RedirectResponse {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$code = strtoupper(bin2hex(random_bytes(3)));
			$this->config->setUserValue($user->getUID(), Application::APP_ID, 'telegram_link_code', $code);
			$this->config->setUserValue(
				$user->getUID(),
				Application::APP_ID,
				'telegram_link_code_expires',
				(string)(time() + self::CODE_TTL_SECONDS),
			);
		}

		return new RedirectResponse(
			$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => Application::APP_ID]),
		);
	}
}
