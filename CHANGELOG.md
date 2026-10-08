# Changelog

Todas as alteracoes relevantes deste projeto serao documentadas neste arquivo.
O formato segue Keep a Changelog e o projeto usa Versionamento Semantico.

## [Unreleased]

## [1.2.0] - 2026-10-08

### Added

- Upload local de imagens, videos, audios e documentos pelo chat, com seletor,
  arrastar e soltar e limite exato de 256 MiB.
- Envio multipart pela API no campo `media`, mantendo `media_url` compativel.
- Painel administrativo de armazenamento com metricas, simulacao, limpeza
  manual, historico auditavel e agendamento configuravel.
- Retencao por uso percentual da particao ou limite absoluto da pasta de midias
  em MB/GB, removendo arquivos antigos sem apagar mensagens.
- Comando nativo `storage-retention.php`, definicao cron e servico scheduler no
  Docker Compose.

### Changed

- Worker resolve midias locais por caminho confinado ao diretorio configurado.
- Listagens identificam anexos removidos e endpoints de midia retornam `410`.
- Limites PHP atualizados para uploads de 256 MiB e corpo multipart de 260M.
- README, instalacao, API e colecao Postman cobrem upload e retencao.

### Security

- Uploads validam tamanho, MIME real, tipo de midia, nome e caminho aleatorio.
- Resolucao e exclusao usam caminhos canonicos, bloqueiam traversal e symlinks
  fora da raiz, e a limpeza usa lock de banco contra execucoes concorrentes.
- Midias pendentes ou em processamento nunca entram na selecao de retencao.

### Operations

- Bancos existentes devem aplicar `storage_retention_migration.sql`.
- Proxies Nginx devem usar `client_max_body_size 260M`.
- O agendador consulta a configuracao a cada cinco minutos; simulacoes nao
  removem arquivos nem adiam a proxima execucao automatica.

## [1.1.2] - 2026-10-07

### Security

- Atualizados `axios`, `proxy-addr` e `sharp` para versoes corrigidas.
- Removida a cadeia vulneravel de desenvolvimento do `ts-node-dev`, substituida
  por `tsx` no modo watch.
- Auditoria completa das dependencias Node sem vulnerabilidades conhecidas.

### Changed

- Producao sincronizada com a versao publicada mais recente do GitHub.

## [1.1.1] - 2026-09-28

### Fixed

- Downloads de midia recebida agora tentam renovar URLs expiradas pelo Baileys.
- Callbacks internos possuem timeout, retentativas e backoff para falhas transitorias.
- Atualizacao atomica do status da fila e do historico apos envios aceitos.
- Configuracao de producao sem o virtual host legado do PHP 8.2.

### Security

- Atualizado Baileys para `6.7.24` e fixada uma versao corrigida de `qs`.
- Auditoria das dependencias de producao sem vulnerabilidades conhecidas.
- Permissoes excessivas dos diretorios de producao foram reduzidas.
- Protecao de requisicoes web contra POSTs originados de outros sites.

### Operations

- Swap persistente de 2 GB configurado no servidor de producao.
- Usuario SSH administrativo dedicado e login SSH do root desabilitado.
- Processo de publicacao passa a exigir commit, tag e GitHub Release por mudanca concluida.

## [1.1.0] - 2026-08-20
### Security

- Politica de seguranca de mensageria com cadencia por instancia, cooldown por destinatario, deduplicacao e cotas configuraveis.
- Segunda barreira de intervalo entre envios no worker Baileys para evitar rajadas de filas antigas.
- Registro de consentimento por contato, opt-out por palavra-chave e cancelamento imediato de envios pendentes.

### Added

- Suporte a instalacao como PWA, com manifesto, service worker e pagina offline.
- Icones para navegador, Android, iOS e imagem de compartilhamento social.
- Metadados SEO, Open Graph e Twitter Card na pagina de login.

### Changed

- A propriedade das instancias agora e aplicada de forma centralizada no modelo,
  garantindo que painel e API permitam acesso apenas ao usuario proprietario.
- A administracao de usuarios mostra a quantidade de instancias sem expor seus
  dados e impede excluir usuarios que ainda possuam instancias.

### Added

- Proprietarios podem compartilhar uma instancia com outro usuario ativo por
  e-mail ou nome de login e revogar o acesso posteriormente.
- Usuarios convidados recebem permissao de editor para operar conexao,
  mensagens, chats, midias e webhooks, sem poder excluir ou compartilhar a
  instancia.

## [1.0.0] - 2026-08-14

### Added

- Painel PHP para administracao de instancias, usuarios, mensagens e webhooks.
- Worker Baileys com persistencia, fila de envio e suporte a midias.
- Conversas separadas entre usuarios, grupos e newsletters.
- Instalacao via Docker e execucao supervisionada no Windows.
- Documentacao da API, instalacao e colecao Postman.
