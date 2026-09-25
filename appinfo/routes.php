<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'telegram#webhook', 'url' => '/telegram/webhook', 'verb' => 'POST'],
		['name' => 'telegram_link#generate', 'url' => '/telegram/link/generate', 'verb' => 'POST'],
	],
];
