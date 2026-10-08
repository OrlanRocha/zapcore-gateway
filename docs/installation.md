# ZapCore Gateway - Guia de Instalacao

Este guia mostra como subir o ZapCore Gateway com Docker ou em ambiente local sem Docker.

## Requisitos

Com Docker:

- Docker
- Docker Compose

Sem Docker:

- PHP 8.2+
- Composer
- MySQL 8+
- Node.js 20+
- npm
- Extensoes PHP: `pdo_mysql`, `curl`, `openssl`, `mbstring`

## Instalacao com Docker

Na raiz do projeto:

```bash
cp backend-php/.env.example backend-php/.env
cp worker-baileys/.env.example worker-baileys/.env
docker compose up -d --build
```

Servicos:

- Painel PHP: `http://localhost:8080`
- MySQL: `localhost:3306`
- Worker Baileys: `http://localhost:3333`
- Redis opcional: `localhost:6379`

O MySQL importa automaticamente:

- `backend-php/database/schema.sql`
- `backend-php/database/seed.sql`
- `backend-php/database/first_login_setup.sql`
- `backend-php/database/chat_type_migration.sql`
- `backend-php/database/performance_indexes.sql`
- `backend-php/database/storage_retention_migration.sql`

## Instalacao sem Docker

### 1. Criar banco MySQL

Crie um banco com charset `utf8mb4`:

```sql
CREATE DATABASE zapcore_gateway CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'zapcore'@'localhost' IDENTIFIED BY 'zapcore_pass';
GRANT ALL PRIVILEGES ON zapcore_gateway.* TO 'zapcore'@'localhost';
FLUSH PRIVILEGES;
```

### 2. Configurar backend PHP

```bash
cd backend-php
composer install
cp .env.example .env
```

Edite `backend-php/.env`:

```env
APP_URL=http://localhost:8080
APP_KEY=change_me_32_chars_minimum_key_123
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=zapcore_gateway
DB_USERNAME=zapcore
DB_PASSWORD=zapcore_pass
INTERNAL_SECRET=change_internal_secret
WORKER_URL=http://127.0.0.1:3333
WORKER_SECRET=change_worker_secret
```

Importe o schema e o seed:

```bash
mysql -u zapcore -p zapcore_gateway < database/schema.sql
mysql -u zapcore -p zapcore_gateway < database/seed.sql
```

Se o banco ja existia antes dos indices de performance, aplique tambem:

```bash
mysql -u zapcore -p zapcore_gateway < database/performance_indexes.sql
```

Se o banco ja existia antes do fluxo de primeiro acesso, aplique:

```bash
mysql -u zapcore -p zapcore_gateway < database/first_login_setup.sql
```

Se o banco ja existia antes da separacao de mensagens por usuarios, grupos e newsletters, aplique:

```bash
mysql -u zapcore -p zapcore_gateway < database/chat_type_migration.sql
mysql -u zapcore -p zapcore_gateway < database/performance_indexes.sql
```

Para habilitar upload local e retencao em um banco existente, aplique:

```bash
mysql -u zapcore -p zapcore_gateway < database/storage_retention_migration.sql
```

Inicie o PHP apontando para `backend-php/public`:

```bash
php -S 127.0.0.1:8080 -t public
```

Em Apache/Nginx, configure o document root para `backend-php/public`.

Uploads usam limite de **256 MiB** na aplicacao. O `php.ini` do projeto define
`upload_max_filesize=256M` e `post_max_size=260M`. No virtual host Nginx:

```nginx
client_max_body_size 260M;
```

Sem esse ajuste, o Nginx responde `413` antes de a aplicacao validar o arquivo.

### 3. Configurar worker Node.js

Em outro terminal:

```bash
cd worker-baileys
npm install
cp .env.example .env
```

Edite `worker-baileys/.env`:

```env
NODE_ENV=development
WORKER_PORT=3333
WORKER_SECRET=change_worker_secret
APP_KEY=change_me_32_chars_minimum_key_123
PHP_API_URL=http://127.0.0.1:8080
PHP_INTERNAL_SECRET=change_internal_secret
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=zapcore_gateway
DB_USERNAME=zapcore
DB_PASSWORD=zapcore_pass
```

Use o mesmo `APP_KEY`, `WORKER_SECRET` e `INTERNAL_SECRET` do backend.

Build e start:

```bash
npm run build
npm start
```

Para desenvolvimento:

```bash
npm run dev
```

## Acesso Inicial

Usuario seed:

```text
Email: admin@zapcore.local
Senha: admin123
```

No primeiro login com o usuario seed, o sistema exige trocar nome/e-mail/senha antes de abrir o painel.

Se voce nao importar o seed e o banco estiver sem usuarios, acesse:

```text
http://localhost:8080/setup
```

Token de desenvolvimento:

```text
dev_zapcore_token
```

Troque a senha e gere tokens proprios antes de usar fora do ambiente local.

## Conectar Instancia

1. Acesse `http://localhost:8080`.
2. Entre com o admin.
3. Abra `Instancias`.
4. Crie uma instancia.
5. Clique em `Gerenciar`.
6. Use `Conectar QR` para escanear QR Code, ou `PIN Code` para gerar codigo de pareamento.

No modo PIN Code, informe o numero com DDI e DDD, por exemplo:

```text
5511999999999
```

## Envio de Teste

Depois que a instancia estiver `connected`, envie pelo painel ou via API:

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

Use `chat_type=user` para contatos individuais. Para grupos use o JID completo com `@g.us`; para newsletter/canal use o JID completo com `@newsletter`.

## Manutencao

### Retencao automatica de midias

O painel `/storage` e exclusivo de administradores. Ele exibe uso da particao,
tamanho da pasta de midias, historico, simulacao e limpeza manual. O modo
percentual usa limite e alvo seguro da particao; o modo absoluto limita apenas a
pasta em MB/GB e, por seguranca sem ambiguidade, nao usa percentual alvo.

No Docker, o servico `storage-scheduler` consulta a configuracao a cada cinco
minutos. Em uma instalacao nativa, instale `deploy/zapcore-storage-retention.cron`
em `/etc/cron.d/zapcore-storage-retention` e garanta acesso de escrita do usuario
`www-data` a `backend-php/storage/media` e ao log. O comando executado e:

```bash
cd /opt/zapcore-gateway/backend-php
php bin/storage-retention.php
```

Codigos de saida: `0` para concluido ou sem necessidade, `2` quando outra limpeza
detem o lock e `1` para falha. A limpeza remove somente arquivos mais antigos,
preserva as mensagens e faz o endpoint de uma midia removida responder `410`.

Com Docker:

```bash
docker compose logs -f php-app
docker compose logs -f baileys-worker
docker compose logs -f mysql
```

Sem Docker:

- Backend: veja o terminal do servidor PHP ou logs do Apache/Nginx.
- Worker: veja o terminal do `npm start` ou `npm run dev`.
- Banco: consulte `connection_logs`, `send_queue`, `messages` e `webhook_logs`.

## Cuidados

- Nao remova volumes ou tabelas sem backup.
- Nao apague diretorios fora da pasta do projeto.
- Nao versionar `.env`.
- Use contatos autorizados/opt-in.
- Baileys depende do WhatsApp Web e nao substitui a WhatsApp Cloud API oficial.
