<?php

declare(strict_types=1);

namespace OCA\AppsAgent\AppInfo;

use OCA\AppsAgent\DeclarativeSettings\AppSettingsForm;
use OCA\AppsAgent\TaskProcessing\AgentChatProvider;
use OCA\AppsAgent\TaskProcessing\AgentChatWithToolsProvider;
use OCA\AppsAgent\TaskProcessing\AgentTextProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'appsagent';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerTaskProcessingProvider(AgentTextProvider::class);
		$context->registerTaskProcessingProvider(AgentChatProvider::class);
		$context->registerTaskProcessingProvider(AgentChatWithToolsProvider::class);
		$context->registerDeclarativeSettings(AppSettingsForm::class);
	}

	public function boot(IBootContext $context): void {
	}
}
