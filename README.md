# appsagent

App Nextcloud nativa (PHP) que interpreta instrucoes em linguagem natural e
executa acoes reais no Nextcloud. Sem servico externo nenhum a manter --
instala-se como qualquer outra app (`occ app:enable`).

## Como funciona

```
Assistant (chat nativo) --\
Telegram (webhook)        >--> AgentService --+--> ReasoningService --> outro
Fila (occ appsagent:      /                   |     provider "texto livre" ja
  submit + cron)         /                    |     configurado no Nextcloud
                                               |     (o teu LLM local, etc.)
                                               +--> CalendarService (CalDAV)
                                               +--> DiscoveryService (apps ativas)
```

O raciocinio **nao** chama nenhum LLM diretamente -- reutiliza o provider de
"texto livre" (`core:text2text`) que ja tiveres configurado no Nextcloud para
o Assistant (o teu LLM local, ou outra integracao). Como o Task Processing do
Nextcloud so devolve texto livre (sem tool-calling estruturado), o
`AgentService` pede ao modelo que responda sempre com um pequeno JSON de
acao (estilo ReAct: "que ferramenta queres usar" / "resposta final"),
executa a ferramenta e repete ate ter uma resposta final ou atingir o limite
de passos.

## Funcionalidades (v0.1)

- **Calendario**: listar, criar, atualizar, desativar (CANCELLED) e eliminar
  eventos, via CalDAV (autenticado com uma conta Nextcloud dedicada ao agente).
  E a unica app onde o agente tem acoes reais de escrita nesta versao.
- **Descoberta (so leitura)**: `discovery_list_apps` lista as apps ativas;
  `discovery_describe_app` le as capacidades que o Nextcloud reporta para uma
  app especifica -- para o agente perceber o que existe sem inventar acoes
  que nao tem.
- **Memoria persistente**: `memory_save_note` / `memory_recall` /
  `memory_list_notes` guardam notas de texto (numa tabela propria da app) que
  o agente pode reconsultar em conversas futuras -- preferencias tuas,
  limitacoes de uma app, o que ja aprendeu, etc. Isto nao lhe da capacidades
  novas de escrita; e so uma memoria de texto entre conversas.
- **Aprender a usar qualquer app (opt-in, desligado por omissao)**. Ha
  centenas de apps Nextcloud; em vez de eu codificar uma a uma, o agente tem
  um ciclo generico de exploracao -> tentativa -> memorizacao:
  1. `discovery_describe_app_api` constroi o catalogo real de operacoes HTTP
     de uma app instalada, lendo o que **toda** a app Nextcloud tem:
     `appinfo/routes.php`, os atributos `#[ApiRoute]`/`#[FrontpageRoute]`
     dos controladores (via reflexao, incluindo se exigem CSRF) e o
     `openapi.json` quando existe. Devolve metodo, caminho completo, flags e a
     lista de documentacao que a app envia. Fica em cache na memoria do agente
     (`catalog:<app>`), por isso a segunda consulta e instantanea.
  2. `discovery_read_app_docs` le o README/`docs/**/*.md` da app, para o
     modelo perceber a semantica dos campos antes de chamar.
  3. `app_api_call` executa a operacao -- so aceita metodo+caminho que existam
     no catalogo (nunca inventados), devolve `{status, ok, body, hint}` em vez
     de rebentar em erros HTTP (para o modelo corrigir e tentar de novo), e
     bloqueia DELETE salvo `generic_api_allow_delete=yes`. Mesmo com isso
     ativo, **eliminar exige confirmacao em duas mensagens separadas**: o
     agente e proibido (por codigo, nao so por prompt) de eliminar sem um
     `confirm:true` explicito, e o prompt instrui-o a nunca definir isso na
     mesma resposta em que pediste a eliminacao -- so depois de confirmares
     numa mensagem seguinte. O mesmo vale para `calendar_delete_event`.
  4. Quando resulta, o agente guarda a **receita** em `memory_save_recipe`
     (por app: tarefa, metodo, caminho, campos, exemplo -- estruturado em
     JSON, varias tarefas por app coexistem sem se sobreporem). Da proxima
     vez que pedires a mesma tarefa, `memory_get_recipes` encontra-a e vai
     direto, sem explorar. Se lhe disseres "nao inseriste o texto" ou "faltou
     X", ele tenta corrigir (normalmente uma operacao de atualizacao sobre o
     mesmo item) e so depois de confirmado atualiza a receita -- e assim que
     o ensinas por tentativa e erro, tal como tu me ensinas a mim.
     `memory_list_learned_apps` responde a "que apps ja sabes usar?".
  Tudo isto so funciona depois de ativares `generic_api_enabled` (ve abaixo):
  e o LLM a decidir sozinho que operacoes chamar. Le a seccao de riscos.
- **Descoberta de comandos occ (so leitura)**: `discovery_list_app_commands`
  e `discovery_describe_command` leem (via subprocesso `occ`) os comandos
  que uma app regista e o respetivo `--help`. Mais lento (reinicia o
  bootstrap do Nextcloud a cada chamada) e pode falhar em alojamentos que
  desativam `exec`/`proc_open`. Nao ha (ainda) execucao automatica de
  comandos occ descobertos -- o raio de acao de um comando occ e maior que
  o de uma operacao REST de uma app, por isso deixei essa decisao para
  quando quiseres mesmo avancar com ela.
- **Interfaces**: chat nativo do Assistant, Telegram (webhook), e fila
  processada pelo cron do proprio Nextcloud (`occ appsagent:submit`).
- **Confirmacao obrigatoria para eliminar**: `calendar_delete_event` e
  `app_api_call` com `DELETE` recusam (no codigo, nao so no prompt) sem um
  `confirm:true` explicito. O agente e instruido a nunca o definir na mesma
  mensagem em que pediste a eliminacao -- so depois de confirmares numa
  mensagem seguinte.
- **`memory_describe_app`**: responde a "o que ja sabes fazer da app X" --
  para que serve a app, e as receitas separadas em `confirmed_actions` (tu
  validaste que ficam bem) e `tentative_actions` (a API disse ok, mas
  ninguem confirmou o resultado ainda).
- **Regras de comportamento editaveis sem reimplantar codigo**: o que e
  mesmo obrigatorio e invariavel (formato JSON de acao, o portao de
  confirmar antes de eliminar) fica fixo em `AgentService.php`; todo o resto
  -- como agir numa app nova, a distincao nota/memoria, etc. -- vive num
  `prompt-rules.json` no **armazenamento proprio da app** (`IAppData`, fora
  da pasta de codigo -- criado sozinho na primeira utilizacao, semeado com
  o ficheiro que vem em `lib/Resources/prompt-rules.json`, e sobrevive
  sempre a reimplantacoes de codigo sem nenhum passo manual). Editar as
  regras (por um destes dois caminhos, ve abaixo) muda o comportamento do
  agente sem precisares de `app:disable`/`app:enable`. O agente tambem pode
  ensinar-se regras GERAIS a si proprio com `agent_learn_rule` (ex: se lhe
  disseres "a partir de agora, sempre que eu disser X, faz Y") -- mas nao
  pode sobrescrever as regras base que eu escrevi (protegidas por nome), e
  cada escrita (tanto de regras gerais como de receitas por app,
  `memory_save_recipe`) fica registada no log do Nextcloud, com o
  utilizador que a pediu. `agent_learn_rule` so funciona quando vem de um
  utilizador Nextcloud autenticado (Assistant/Chat) -- fica bloqueado a
  partir da fila/cron ou do Telegram, que correm sem utilizador associado
  (ler/executar receitas ja aprendidas continua disponivel em todo o lado,
  so o ENSINAR de regras gerais fica limitado a este canal).
- **`memory_forget_app`** (e `occ appsagent:forget <app_id>`): apaga as
  receitas aprendidas de uma app, para o agente as voltar a explorar do
  zero -- pede isto quando quiseres que ele reaprenda uma tarefa do inicio.

## Instalar

1. Copia (ou symlink) esta pasta para a pasta de apps do Nextcloud (ex:
   `custom_apps/appsagent`).
2. Ativa a app -- isto tambem cria a tabela `appsagent_instructions`:

   ```bash
   occ app:enable appsagent
   ```

   **A partir daqui, o mais facil e configurar tudo em Definicoes de
   administracao > Apps Agent** (uma pagina propria, com todos os campos
   abaixo -- conta do agente, calendario, fuso horario, Telegram incluindo
   o mapa de varios utilizadores, execucao dinamica de API). Os passos 3-6
   e os comandos `occ config:app:set` ficam documentados abaixo para quem
   preferir a linha de comandos, ou precisar de automatizar a instalacao --
   sao equivalentes, escolhe um dos dois caminhos.

3. Cria uma conta Nextcloud dedicada ao agente (recomendado -- nao uses a tua
   conta principal) e gera uma app password para ela em **Definicoes >
   Seguranca > Palavras-passe de dispositivos**. Partilha com essa conta os
   calendarios que queres que o agente possa gerir.

   As regras de comportamento do agente ficam guardadas automaticamente no
   armazenamento proprio da app (`data/appdata_*/appsagent/config/`) --
   nao precisas de fazer nada aqui, e uma futura reimplantacao (copiares a
   pasta da app outra vez) nunca lhes toca. Se preferires editar num
   ficheiro teu, num sitio a tua escolha, podes desviar para la:

   ```bash
   occ config:app:set appsagent prompt_rules_path --value="/caminho/a/tua/escolha/prompt-rules.json"
   ```

   (avancado; nao e preciso para o uso normal -- so muda o SITIO onde o
   ficheiro vive, o comportamento por omissao ja e persistente sem isto.)

4. Configura as credenciais e o calendario por omissao:

   ```bash
   occ config:app:set appsagent nc_username --value="agent"
   occ config:app:set appsagent nc_app_password --value="<app password gerada>"
   occ config:app:set appsagent calendar_uri --value="personal"
   ```

   **Instalacoes em Docker:** se o nome publico do Nextcloud nao resolver
   corretamente de dentro do proprio container (o agente faz chamadas CalDAV/OCS
   a si mesmo, e o DNS interno do Docker pode apontar para IPs sem a porta 443
   a escutar -- sintoma: `cURL error 7: Failed to connect ... port 443` no log),
   aponta essas chamadas para um endereco interno. O cabecalho `Host` publico e
   mantido, por isso o Apache/Nginx continua a rotear para o virtual host certo:

   ```bash
   occ config:app:set appsagent internal_base_url --value="http://<endereco-interno>"
   ```

   Se o Nextcloud correr em **varios containers** (ex: um para a web, outro
   para cron, outro para task processing -- `docker ps` mostra os nomes), usa o
   **nome do container** que serve HTTP (ex: `http://nextcloud-app`), nunca
   `127.0.0.1`: esse so aponta para dentro do container onde o PHP esta a
   correr naquele momento, e cron/task-processing costumam correr num
   container *diferente* do que serve a web -- e nesses, `127.0.0.1` nao tem
   nada a escutar (sintoma: `Failed to connect to 127.0.0.1 port 80 after 0 ms`,
   so nas chamadas via cron/Task Processing, nao via `occ`). O nome do
   container resolve-se pelo DNS interno do Docker a partir de qualquer
   container na mesma rede. Confirma primeiro, a partir do container onde o
   erro aconteceu:

   ```bash
   docker exec -it <container-onde-falhou> curl -H "Host: <o-teu-dominio-publico>" http://<nome-do-container-web>/status.php
   ```

5. Garante que tens um provider de "texto livre" (`core:text2text`) ja
   configurado no Nextcloud (o teu LLM local, por exemplo) em **Definicoes de
   administracao > Inteligencia artificial** -- e o unico requisito para o
   `AgentService` conseguir raciocinar. Sem isto, o agente devolve um erro
   claro a explicar o que falta.

6. Testa em **Assistant**, na tarefa "Texto livre", escolhendo **Agente
   Nextcloud (appsagent)** como provider: escreve por exemplo "marca reuniao
   para dia 29/09/2026 as 10:00, Visita cliente" e confirma que aparece no
   calendario configurado.

### Ativar a execucao dinamica de API (opcional, risco aceite)

Por omissao, `app_api_call` esta desligada e devolve um erro explicito. Só a
ativas depois de perceberes que isto deixa o agente (guiado pelo teu LLM)
decidir sozinho quando chamar operacoes de escrita de qualquer app instalada,
sem eu ter revisto cada operacao especifica. Salvaguardas de codigo: o
metodo+caminho tem de existir mesmo no catalogo da app (nunca um endpoint
inventado); DELETE fica bloqueado por omissao; cada chamada fica registada nos
logs do Nextcloud; e as chamadas correm com a conta dedicada do agente, por
isso so alcancam o que essa conta alcanca.

```bash
occ config:app:set appsagent generic_api_enabled --value=yes
# so se quiseres mesmo permitir apagar coisas por esta via:
occ config:app:set appsagent generic_api_allow_delete --value=yes
```

Para ver o que o agente ja aprendeu (receitas `app:<id>`, catalogos em cache,
e a memoria de mensagens Telegram ja processadas):

```bash
occ appsagent:memory
```

E para ver as regras de comportamento atuais (base + aprendidas) e onde vivem:

```bash
occ appsagent:rules
```

### Telegram

1. Cria um bot com o [@BotFather](https://t.me/BotFather) e copia o token.
2. Descobre o teu ID numerico de utilizador Telegram (ex: fala com
   [@userinfobot](https://t.me/userinfobot)).
3. Configura:

   ```bash
   occ config:app:set appsagent telegram_bot_token --value="<token do BotFather>"
   occ config:app:set appsagent telegram_allowed_user_ids --value="<o teu ID>"
   occ config:app:set appsagent telegram_webhook_secret --value="<um segredo aleatorio, ex: openssl rand -hex 32>"
   ```

   Sem `telegram_allowed_user_ids` definido, qualquer pessoa que encontre o
   bot consegue mexer no teu calendario -- define sempre este valor.

   **Para varios utilizadores** (ou para o teu proprio ID deixar de correr
   "anonimo", o que bloqueia `agent_learn_rule` no Telegram): liga cada ID
   Telegram autorizado a um utilizador Nextcloud, em JSON:

   ```bash
   occ config:app:set appsagent telegram_user_map --value='{"<o teu ID>": "<o teu utilizador Nextcloud>"}'
   ```

   (ou preenche o mesmo campo na pagina de Definicoes de administracao).
   Quem nao estiver neste mapa continua a poder usar o Telegram (se estiver
   em `telegram_allowed_user_ids`), so corre sem utilizador Nextcloud
   associado.

4. Regista o webhook junto do Telegram (substitui `<TOKEN>`, `<NEXTCLOUD_URL>`
   e `<SEGREDO>` pelos valores acima):

   ```bash
   curl "https://api.telegram.org/bot<TOKEN>/setWebhook?url=<NEXTCLOUD_URL>/apps/appsagent/telegram/webhook&secret_token=<SEGREDO>"
   ```

   O teu Nextcloud precisa de ser acessivel publicamente por HTTPS para o
   Telegram conseguir chamar o webhook.

5. Escreve para o bot no Telegram -- a resposta vem do mesmo `AgentService`.

### Fila / cron

```bash
occ appsagent:submit "Marca reuniao para dia 29/09/2026 as 10:00, Visita cliente"
```

O `QueueWorker` (background job registado automaticamente) processa
instrucoes pendentes a cada 5 minutos, desde que o cron do Nextcloud esteja
configurado (Definicoes de administracao > Basico > Cron).

## Notas e limitacoes conhecidas

- Todas as acoes de Calendario correm como a conta Nextcloud dedicada
  configurada em `nc_username`/`nc_app_password` -- nao como o utilizador que
  esta a falar com o Assistant/Telegram. Para varios utilizadores com
  calendarios proprios, isto e uma simplificacao deliberada da v0.1.
- O "raciocinio" via `core:text2text` e baseado em pedir ao modelo que
  responda em JSON (nao ha tool-calling nativo nessa API do Nextcloud). Em
  modelos locais mais pequenos isto pode falhar ocasionalmente a seguir o
  formato -- nesse caso o agente devolve o texto tal como veio, sem executar
  nenhuma ferramenta.
- O CRUD de Calendario (sobretudo `updateEvent`/`deactivateEvent`, que usam
  `Sabre\VObject` para reescrever o ICS) ainda nao foi testado contra uma
  instancia Nextcloud real -- testa primeiro com um evento descartavel.
- MCP (para ligar clientes externos como Claude Desktop/Cursor) fica para
  depois, fora do ambito desta versao.
