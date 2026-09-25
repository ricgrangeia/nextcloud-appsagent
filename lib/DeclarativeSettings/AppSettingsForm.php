<?php

declare(strict_types=1);

namespace OCA\AppsAgent\DeclarativeSettings;

use OCA\AppsAgent\AppInfo\Application;
use OCP\IL10N;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsForm;

/**
 * Definicoes de administracao do appsagent, geridas pelo Nextcloud (guarda
 * automaticamente em IConfig::setAppValue -- os campos "sensitive" ficam
 * cifrados na base de dados). Substitui teres de correr `occ config:app:set`
 * para tudo isto.
 */
class AppSettingsForm implements IDeclarativeSettingsForm {
	public function __construct(
		private IL10N $l,
	) {
	}

	public function getSchema(): array {
		return [
			'id' => 'appsagent_settings',
			'priority' => 50,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'additional',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
			'title' => $this->l->t('Apps Agent'),
			'description' => $this->l->t(
				'Agente que interpreta instrucoes em linguagem natural e age no Nextcloud (Calendario, '
				. 'outras apps via API dinamica, Telegram).'
			),
			'doc_url' => '',
			'fields' => [
				[
					'id' => 'nc_username',
					'title' => $this->l->t('Utilizador do agente'),
					'description' => $this->l->t('Conta Nextcloud dedicada que o agente usa para o Calendario (CalDAV). Recomendado: nao uses a tua conta principal.'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => 'agent',
					'default' => '',
				],
				[
					'id' => 'nc_app_password',
					'title' => $this->l->t('App password do agente'),
					'description' => $this->l->t('Gerada em Definicoes > Seguranca > Palavras-passe de dispositivos, para a conta acima.'),
					'type' => DeclarativeSettingsTypes::PASSWORD,
					'placeholder' => '',
					'default' => '',
				],
				[
					'id' => 'calendar_uri',
					'title' => $this->l->t('Calendario por omissao'),
					'description' => $this->l->t('URI do calendario a usar quando a instrucao nao especificar um.'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => 'personal',
					'default' => 'personal',
				],
				[
					'id' => 'default_timezone',
					'title' => $this->l->t('Fuso horario'),
					'description' => $this->l->t('Usado para interpretar datas/horas sem fuso explicito (ex: Europe/Lisbon).'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => 'Europe/Lisbon',
					'default' => 'UTC',
				],
				[
					'id' => 'internal_base_url',
					'title' => $this->l->t('Endereco interno (Docker, avancado)'),
					'description' => $this->l->t('So se o nome publico nao resolver de dentro dos teus containers (ex: http://nextcloud-app). Deixa vazio se nao souberes.'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => 'http://nextcloud-app',
					'default' => '',
				],
				[
					'id' => 'generic_api_enabled',
					'title' => $this->l->t('Execucao dinamica de API'),
					'description' => $this->l->t('Deixa o agente chamar operacoes descobertas de qualquer app instalada (nao so o Calendario). O agente decide sozinho que operacoes chamar -- le o README antes de ativares.'),
					// CHECKBOX foi trocado por RADIO: o Nextcloud 34 tem um bug em que o
					// campo checkbox grava um inteiro (1/0) onde o AppConfig::setValueString()
					// exige string, e a gravacao falha sempre com um TypeError. RADIO grava
					// sempre string ("yes"/"no"), sem esse problema.
					'type' => DeclarativeSettingsTypes::RADIO,
					'options' => [
						['name' => $this->l->t('Desativado'), 'value' => 'no'],
						['name' => $this->l->t('Ativado'), 'value' => 'yes'],
					],
					'default' => 'no',
				],
				[
					'id' => 'generic_api_allow_delete',
					'title' => $this->l->t('Permitir DELETE na execucao dinamica'),
					'description' => $this->l->t('Alem disto, o agente ainda exige confirmacao em duas mensagens antes de qualquer eliminacao.'),
					'type' => DeclarativeSettingsTypes::RADIO,
					'options' => [
						['name' => $this->l->t('Nao permitir'), 'value' => 'no'],
						['name' => $this->l->t('Permitir'), 'value' => 'yes'],
					],
					'default' => 'no',
				],
				[
					'id' => 'telegram_bot_token',
					'title' => $this->l->t('Token do bot Telegram'),
					'description' => $this->l->t('Obtido no @BotFather.'),
					'type' => DeclarativeSettingsTypes::PASSWORD,
					'placeholder' => '',
					'default' => '',
				],
				[
					'id' => 'telegram_allowed_user_ids',
					'title' => $this->l->t('IDs Telegram autorizados'),
					'description' => $this->l->t('IDs numericos separados por virgula. Vazio = qualquer pessoa que encontre o bot pode usa-lo (nao recomendado).'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '6180482497,111222333',
					'default' => '',
				],
				[
					'id' => 'telegram_user_map',
					'title' => $this->l->t('Mapa Telegram -> utilizador Nextcloud'),
					'description' => $this->l->t('JSON {"id_telegram": "utilizador_nextcloud", ...} -- liga cada ID Telegram autorizado a um utilizador Nextcloud (para agent_learn_rule e afins funcionarem a partir do Telegram). Sem mapa, o Telegram corre sem utilizador associado.'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '{"6180482497": "ricardo", "111222333": "sofia"}',
					'default' => '',
				],
				[
					'id' => 'telegram_webhook_secret',
					'title' => $this->l->t('Segredo do webhook Telegram'),
					'description' => $this->l->t('Tem de bater certo com o secret_token usado ao registares o webhook junto do Telegram.'),
					'type' => DeclarativeSettingsTypes::PASSWORD,
					'placeholder' => '',
					'default' => '',
				],
				[
					'id' => 'max_steps',
					'title' => $this->l->t('Numero maximo de passos por instrucao'),
					'description' => $this->l->t(
						'Quantas vezes o agente pode chamar ferramentas antes de desistir de uma instrucao. '
						. 'Tentativa e erro numa API mal documentada pode precisar de muitos passos ate acertar '
						. '-- mais passos custam mais tempo/chamadas ao LLM. Entre 1 e 100.'
					),
					// TEXT em vez de NUMBER: o Nextcloud 34 tem o mesmo bug de tipo (int em vez
					// de string) que tinhamos nos checkboxes -- ver generic_api_enabled acima.
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '25',
					'default' => '25',
				],
				[
					'id' => 'prompt_rules_path',
					'title' => $this->l->t('Caminho alternativo das regras (avancado)'),
					'description' => $this->l->t('Deixa vazio para usar o armazenamento automatico da app (recomendado). So preenche se quiseres editar as regras num ficheiro teu, num sitio a tua escolha.'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '',
					'default' => '',
				],
				[
					'id' => 'supervisor_base_url',
					'title' => $this->l->t('Endereco do Ai Supervisor'),
					'description' => $this->l->t('Usado para as ferramentas de calculadora, pesquisa web e datas (POST /tools/calc, /tools/web_search, /tools/datetime). Ex: http://192.168.1.94:3030'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => 'http://192.168.1.94:3030',
					'default' => '',
				],
				[
					'id' => 'supervisor_api_key',
					'title' => $this->l->t('Chave de API do Ai Supervisor'),
					'description' => $this->l->t('Enviada como cabecalho x-api-key nas chamadas as ferramentas do Ai Supervisor.'),
					'type' => DeclarativeSettingsTypes::PASSWORD,
					'placeholder' => '',
					'default' => '',
				],
			],
		];
	}
}
