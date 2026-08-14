# ZapCore Gateway - Documentacao da API

Esta documentacao cobre a API publica, as rotas internas entre PHP e worker, e o fluxo de conexao por QR Code ou PIN Code.

Todos os endpoints publicos sao isolados pelo usuario vinculado ao Bearer token.
Listagens, status, conexao, mensagens, midias e webhooks retornam apenas dados de
instancias pertencentes a esse usuario. Um UUID de outro usuario responde como
recurso inexistente.

Instancias compartilhadas com o usuario do token tambem aparecem nas listagens e
podem ser operadas normalmente. Compartilhar, revogar e excluir continuam sendo
acoes exclusivas do proprietario no painel.

## Base URL

Ambiente local:

```text
http://localhost:8080
```

## Autenticacao

As rotas publicas usam token Bearer:

```http
Authorization: Bearer dev_zapcore_token
```

O token inicial de desenvolvimento vem no `seed.sql`. Em producao, gere tokens proprios e nunca exponha tokens em logs ou repositorios.

## Formato Padrao

Sucesso:

```json
{
  "success": true,
  "data": {},
  "message": "Operacao realizada com sucesso"
}
```

Erro:

```json
{
  "success": false,
  "error": "Descricao do erro"
}
```

## Instancias

### Listar instancias

```http
GET /api/instances
```

### Criar instancia

```http
POST /api/instances
Content-Type: application/json
```

```json
{
  "name": "Atendimento Principal"
}
```

### Consultar status

```http
GET /api/instances/{uuid}/status
```

### Consultar QR Code

```http
GET /api/instances/{uuid}/qr
```

### Conectar por QR Code

```http
POST /api/instances/{uuid}/connect
Content-Type: application/json
```

```json
{
  "mode": "qr"
}
```

Depois da chamada, consulte `GET /api/instances/{uuid}/qr` ate retornar `status: waiting_qr` com o campo `qr`. O painel faz esse polling automaticamente.

### Conectar por PIN Code

```http
POST /api/instances/{uuid}/connect
Content-Type: application/json
```

```json
{
  "mode": "pin",
  "phone_number": "5511999999999"
}
```

Resposta esperada:

```json
{
  "success": true,
  "data": {
    "mode": "pin",
    "pairing_code": "ABCD1234",
    "already_connected": false
  },
  "message": "PIN Code generated"
}
```

O `phone_number` deve conter DDI e DDD, somente numeros, sem `+`, parenteses ou tracos. O PIN Code e temporario e nao e salvo no banco.

### Desconectar instancia

```http
POST /api/instances/{uuid}/disconnect
```

## Mensagens

### Enviar texto

```http
POST /api/messages/text
Content-Type: application/json
```

```json
{
  "instance_uuid": "UUID_DA_INSTANCIA",
  "chat_type": "user",
  "to": "5511999999999",
  "text": "Ola, teste pelo ZapCore Gateway"
}
```

O backend converte `to` para `5511999999999@s.whatsapp.net`, cria `messages`, cria `send_queue` e o worker envia pelo Baileys.

Tipos aceitos em `chat_type`:

- `user`: aceita numero com DDI/DDD ou JID `@s.whatsapp.net`.
- `group`: exige JID completo do grupo, por exemplo `120363000000000000@g.us`.
- `newsletter`: exige JID completo da newsletter/canal, por exemplo `120363000000000000@newsletter`.

Exemplo para grupo:

```json
{
  "instance_uuid": "UUID_DA_INSTANCIA",
  "chat_type": "group",
  "to": "120363000000000000@g.us",
  "text": "Mensagem para grupo autorizado"
}
```

Exemplo para newsletter:

```json
{
  "instance_uuid": "UUID_DA_INSTANCIA",
  "chat_type": "newsletter",
  "to": "120363000000000000@newsletter",
  "text": "Atualizacao para canal administrado pela conta"
}
```

Envio para grupos depende da conta estar no grupo. Envio para newsletter/canal depende do suporte do Baileys e das permissoes da conta conectada.

### Enviar midia

```http
POST /api/messages/media
Content-Type: application/json
```

```json
{
  "instance_uuid": "UUID_DA_INSTANCIA",
  "chat_type": "user",
  "to": "5511999999999",
  "media_type": "image",
  "media_url": "https://files.seudominio.com/imagem.jpg",
  "caption": "Imagem de teste"
}
```

`media_type` aceita `image`, `audio`, `video` e `document`. A `media_url` precisa ser uma URL publica real, acessivel pelo servidor, com tipo de conteudo compativel.

### Mídia recebida

O worker baixa anexos recebidos via Baileys e os associa à mensagem. A resposta de `GET /api/messages` inclui `media_url`, `media_file_name` e `media_mime_type` quando existir mídia. Para visualizar ou baixar o arquivo, use `GET /api/messages/{message_id}/media` com `Authorization: Bearer SEU_TOKEN`. O endpoint mantém o MIME original para que navegadores exibam imagens, figurinhas, áudio e vídeo diretamente.

### Listar mensagens

```http
GET /api/messages?instance_uuid=UUID_DA_INSTANCIA&chat_type=group&status=sent&direction=outbound&q=pedido&page=1&limit=100
```

Filtros opcionais:

- `instance_uuid`
- `status`: `pending`, `queued`, `sent`, `delivered`, `read`, `failed`, `received`
- `direction`: `inbound` ou `outbound`
- `chat_type`: `user`, `group` ou `newsletter`
- `q`: busca em texto da mensagem, JID de origem/destino ou ID do WhatsApp
- `limit`: de 1 ate 500
- `page`: pagina a partir de 1

Resposta inclui `messages` e `pagination` com `page`, `limit`, `total` e `total_pages`.

Cada item de mensagem retorna `chat_type`, permitindo separar a leitura entre:

- conversas de usuarios: `chat_type=user`
- grupos: `chat_type=group`
- newsletters/canais: `chat_type=newsletter`

## Webhooks

### Criar webhook

```http
POST /api/webhooks
Content-Type: application/json
```

```json
{
  "instance_uuid": "UUID_DA_INSTANCIA",
  "name": "Webhook Atendimento",
  "url": "https://example.com/webhook",
  "secret": "troque_este_secret",
  "events": [
    "instance.connected",
    "message.received",
    "message.sent",
    "message.failed"
  ],
  "active": true
}
```

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

Cada webhook pertence a uma instancia. A assinatura HMAC SHA256 e enviada em:

```text
X-ZapCore-Signature
```

### Listar logs de webhook

```http
GET /api/webhook-logs
```

## Rotas Internas do Backend

Estas rotas sao chamadas pelo worker e exigem o header:

```http
Internal-Secret: change_internal_secret
```

Rotas:

- `POST /internal/instances/{uuid}/status`
- `POST /internal/instances/{uuid}/qr`
- `POST /internal/messages/received`
- `POST /internal/messages/status`
- `POST /internal/connection-log`

## Rotas Internas do Worker

Estas rotas sao chamadas pelo PHP e exigem o header:

```http
Worker-Secret: change_worker_secret
```

### Conectar por QR

```http
POST /worker/instances/{uuid}/connect
Content-Type: application/json
```

```json
{
  "mode": "qr"
}
```

### Conectar por PIN Code

```http
POST /worker/instances/{uuid}/connect
Content-Type: application/json
```

```json
{
  "mode": "pin",
  "phone_number": "5511999999999"
}
```

### Desconectar

```http
POST /worker/instances/{uuid}/disconnect
```

### Status

```http
GET /worker/instances/{uuid}/status
```

## Boas Praticas

- Use somente contatos com autorizacao/opt-in.
- Bloqueie envio se a instancia nao estiver `connected`.
- Troque todos os secrets antes de publicar.
- Nao versionar `.env`, logs, cache ou midias.
- Baileys usa WhatsApp Web e pode quebrar se o WhatsApp mudar o protocolo.
