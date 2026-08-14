# Changelog

Todas as alteracoes relevantes deste projeto serao documentadas neste arquivo.
O formato segue Keep a Changelog e o projeto usa Versionamento Semantico.

## [Unreleased]

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
