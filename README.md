# ZapCore Gateway

ZapCore Gateway e uma plataforma PHP + MySQL + Node.js para gerenciar instancias WhatsApp Web via Baileys. O PHP e o sistema principal: painel, API REST, autenticacao, historico, webhooks e fila. O worker Node.js fica restrito ao motor WhatsApp Web/Baileys.

> Use apenas para comunicacao legitima com contatos autorizados/opt-in. O projeto nao implementa recursos de spam, disparo abusivo ou tentativa de burlar regras do WhatsApp.

## Arquitetura

```text
PHP Backend / Painel / API
        |
      MySQL
        |
   send_queue
        |
Worker Node.js + Baileys
        |
 WhatsApp Web
```

## Requisitos

- Docker e Docker Compose, recomendado.
- Ou instalacao manual com PHP 8.2+, Composer, MySQL 8+ e Node.js 20+.

## Documentacao complementar

- API, webhooks e rotas internas: `docs/api.md`
- Instalacao detalhada: `docs/installation.md`
- Versionamento e publicacoes: `docs/versioning.md`
- Colecao Postman: `docs/ZapCore-Gateway.postman_collection.json`

## Versao

A versao atual fica no arquivo `VERSION` e segue Semantic Versioning. Mudancas
destinadas a publicacao devem ser registradas em `CHANGELOG.md`. Para preparar
uma nova versao, use o script `scripts/release.ps1`; o fluxo completo esta em
`docs/versioning.md`.

## Instalar com Docker

```bash
cp backend-php/.env.example backend-php/.env
cp worker-baileys/.env.example worker-baileys/.env
docker compose up -d --build
```

O painel fica em `http://localhost:8080`.

Servicos principais:

- PHP/Apache: `8080`
- MySQL: `3306`
- Worker Baileys: `3333`
- Redis opcional: `6379`

## Instalar sem Docker

```bash
cd backend-php
composer install
cp .env.example .env
```

Configure o Apache/Nginx apontando o document root para `backend-php/public`.

Importe o banco:

```bash
mysql -u zapcore -p zapcore_gateway < backend-php/database/schema.sql
mysql -u zapcore -p zapcore_gateway < backend-php/database/seed.sql
```

Para bancos existentes, aplique os indices de performance sem resetar dados:

```bash
mysql -u zapcore -p zapcore_gateway < backend-php/database/performance_indexes.sql
```

Para habilitar o fluxo de primeiro acesso em bancos ja criados:

```bash
mysql -u zapcore -p zapcore_gateway < backend-php/database/first_login_setup.sql
```

Inicie o worker:

```bash
cd worker-baileys
npm install
cp .env.example .env
npm run build
npm start
```

Para desenvolvimento:

```bash
npm run dev
```

### Manter o worker ativo no Windows

Em uma instalacao nativa no Windows, registre o worker no Agendador de Tarefas para
inicia-lo no logon e reinicia-lo automaticamente quando o processo falhar:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\install-worker-task.ps1 -StartNow
```

O agendamento usa o build em `worker-baileys/dist`, impede processos duplicados e
supervisiona o Node, reiniciando o worker poucos segundos apos uma falha. Para
remover o agendamento:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\uninstall-worker-task.ps1
```

## Variaveis .env

Backend PHP:

```env
APP_NAME="ZapCore Gateway"
APP_URL=http://localhost:8080
APP_KEY=change_me_32_chars_minimum_key_123
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=zapcore_gateway
DB_USERNAME=zapcore
DB_PASSWORD=zapcore_pass
INTERNAL_SECRET=change_internal_secret
WORKER_URL=http://baileys-worker:3333
WORKER_SECRET=change_worker_secret
```

Worker:

```env
WORKER_PORT=3333
WORKER_SECRET=change_worker_secret
APP_KEY=change_me_32_chars_minimum_key_123
PHP_API_URL=http://php-app:80
PHP_INTERNAL_SECRET=change_internal_secret
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=zapcore_gateway
DB_USERNAME=zapcore
DB_PASSWORD=zapcore_pass
```

Use o mesmo `APP_KEY` no backend e no worker para criptografar e ler o estado Baileys em `baileys_auth`.

## Acesso inicial

- Email: `admin@zapcore.local`
- Senha: `admin123`

Na primeira entrada com o usuario seed, o sistema abre a tela `Primeiro acesso` e exige trocar nome/e-mail/senha antes de liberar o painel.

Se instalar sem importar o seed, acesse `http://localhost:8080/setup` para criar o primeiro administrador.

O seed tambem cria um token local de desenvolvimento:

```text
dev_zapcore_token
```

Use outro token em producao.

## Conectar uma instancia

1. Acesse `http://localhost:8080`.
2. Entre com o usuario admin.
3. Abra `Instancias`.
4. Crie uma nova instancia.
5. Clique em `Gerenciar`.
6. Clique em `Conectar QR` para escanear o QR Code no WhatsApp.
7. Ou clique em `PIN Code`, informe o numero com DDI/DDD e use o codigo no WhatsApp em aparelhos conectados.

O painel faz polling em `/instances/{id}/qr` ate a instancia ficar `connected`.

Tambem e possivel gerar PIN Code via API:

```bash
curl -X POST http://localhost:8080/api/instances/UUID_DA_INSTANCIA/connect \
  -H "Authorization: Bearer dev_zapcore_token" \
  -H "Content-Type: application/json" \
  -d '{
    "mode": "pin",
    "phone_number": "5511999999999"
  }'
```

## Enviar mensagem via API

```bash
curl -X POST http://localhost:8080/api/messages/text \
  -H "Authorization: Bearer dev_zapcore_token" \
  -H "Content-Type: application/json" \
  -d '{
    "instance_uuid": "UUID_DA_INSTANCIA",
    "chat_type": "user",
    "to": "5511999999999",
    "text": "Ola, teste pelo ZapCore Gateway"
  }'
```

O PHP valida token e instancia, cria `messages`, cria `send_queue`, e o worker processa a fila.

Use `chat_type` para separar o destino:

- `user`: contato individual; aceita numero com DDI/DDD ou JID `@s.whatsapp.net`.
- `group`: grupo; exige JID completo, por exemplo `120363000000000000@g.us`.
- `newsletter`: newsletter/canal; exige JID completo, por exemplo `120363000000000000@newsletter`.

Para listar mensagens separadas por tipo:

```bash
curl "http://localhost:8080/api/messages?instance_uuid=UUID_DA_INSTANCIA&chat_type=group&page=1&limit=50&q=pedido" \
  -H "Authorization: Bearer dev_zapcore_token"
```

## Enviar midia via API

```bash
curl -X POST http://localhost:8080/api/messages/media \
  -H "Authorization: Bearer dev_zapcore_token" \
  -H "Content-Type: application/json" \
  -d '{
    "instance_uuid": "UUID_DA_INSTANCIA",
    "chat_type": "user",
    "to": "5511999999999",
    "media_type": "image",
    "media_url": "https://files.seudominio.com/imagem.jpg",
    "caption": "Imagem de teste"
  }'
```

`media_type` aceita `image`, `audio`, `video` e `document`. A `media_url` precisa apontar para um arquivo publico real, acessivel pelo servidor.

Mensagens recebidas com imagem, figurinha, audio, video ou documento sao baixadas pelo worker e salvas em `backend-php/storage/media`. A listagem de mensagens retorna `media_url` quando houver anexo. Use `GET /api/messages/{message_id}/media` com o mesmo Bearer token para visualizar ou baixar o arquivo.

## Rotas REST principais

- `GET /api/instances`
- `POST /api/instances`
- `GET /api/instances/{uuid}/status`
- `GET /api/instances/{uuid}/qr`
- `POST /api/instances/{uuid}/connect` com `mode: "qr"` ou `mode: "pin"`
- `POST /api/instances/{uuid}/disconnect`
- `POST /api/messages/text`
- `POST /api/messages/media`
- `GET /api/messages`
- `POST /api/webhooks`
- `GET /api/webhook-logs`

Formato de sucesso:

```json
{
  "success": true,
  "data": {},
  "message": "Operacao realizada com sucesso"
}
```

Formato de erro:

```json
{
  "success": false,
  "error": "Descricao do erro"
}
```

## Rotas internas

Backend protegido por `Internal-Secret`:

- `POST /internal/instances/{uuid}/status`
- `POST /internal/instances/{uuid}/qr`
- `POST /internal/messages/received`
- `POST /internal/messages/status`
- `POST /internal/connection-log`

Worker protegido por `Worker-Secret`:

- `POST /worker/instances/:uuid/connect`
- `POST /worker/instances/:uuid/disconnect`
- `GET /worker/instances/:uuid/status`
- `POST /worker/messages/send`

## Webhooks

Eventos suportados:

- `instance.qr`
- `instance.connected`
- `instance.disconnected`
- `instance.logged_out`
- `message.received`
- `message.sent`
- `message.delivered`
- `message.read`
- `message.failed`

Payload:

```json
{
  "event": "message.received",
  "instance_uuid": "uuid-da-instancia",
  "timestamp": "2026-07-06T12:00:00-03:00",
  "data": {}
}
```

A assinatura HMAC SHA256 e enviada no header:

```text
X-ZapCore-Signature
```

## Seguranca

- Senhas usam `password_hash`.
- Login usa sessao PHP.
- Tokens de API sao armazenados como SHA-256.
- Rotas internas exigem secrets.
- Estado Baileys e criptografado em `baileys_auth` com `APP_KEY`.
- Payloads sao validados antes de entrar na fila.
- Envios sao bloqueados se a instancia nao estiver conectada.
- Existe rate limit basico por token em `storage/cache`.
- `.env`, logs, cache e midias locais ficam ignorados no `.gitignore`.

## Limitacoes

- Baileys usa WhatsApp Web e nao e API oficial.
- Mudancas no WhatsApp Web podem exigir ajuste do worker.
- Midia remota depende de URLs acessiveis pelo worker.
- O worker atual processa fila por polling simples.
- Redis esta no Compose como opcional, mas a fila principal do MVP usa MySQL.

## Plano futuro

- Suporte a WhatsApp Cloud API oficial.
- UI para gerenciar tokens de API.
- Fila Redis opcional com retry/backoff avancado.
- Upload local de midia pelo painel.
- Testes automatizados de integracao.
