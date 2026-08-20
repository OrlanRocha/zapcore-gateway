# Seguranca de mensageria

Nao existe metodo tecnico que garanta uma conta contra bloqueios do WhatsApp.
Baileys e uma integracao nao oficial, e o risco permanece mesmo com limites. A
politica do ZapCore reduz abuso acidental e picos operacionais; ela nao deve ser
descrita comercialmente como "anti-ban".

## Controles implementados

- Serializacao da decisao de fila por instancia.
- Cadencia maxima por minuto e intervalo minimo entre envios.
- Cooldown por destinatario.
- Bloqueio de mensagens identicas recentes para o mesmo destinatario.
- Limites diarios de mensagens e destinatarios unicos.
- Limite de itens pendentes por instancia.
- Segunda barreira de cadencia no worker, inclusive para filas antigas.
- Retentativa exponencial limitada e falha definitiva para erros HTTP 4xx.

As configuracoes ficam no `.env` do backend:

```env
MESSAGE_SAFETY_ENABLED=true
MESSAGE_REQUIRE_OPT_IN=true
MESSAGE_MAX_PER_MINUTE=12
MESSAGE_MIN_INTERVAL_SECONDS=5
MESSAGE_RECIPIENT_COOLDOWN_SECONDS=10
MESSAGE_DUPLICATE_WINDOW_SECONDS=300
MESSAGE_MAX_PER_DAY=500
MESSAGE_MAX_UNIQUE_RECIPIENTS_PER_DAY=250
MESSAGE_MAX_PENDING_PER_INSTANCE=500
```

O worker tambem aplica um intervalo independente:

```env
MESSAGE_MIN_INTERVAL_MS=5000
```

Com `MESSAGE_REQUIRE_OPT_IN=true`, envios individuais so entram na fila quando
o destinatario possui consentimento ativo. Palavras explicitas como `SAIR`,
`STOP`, `CANCELAR`, `PARE`, `PARAR` e `DESCADASTRAR` revogam automaticamente o
consentimento e cancelam itens ainda pendentes. `SIM`, `ACEITO`, `AUTORIZO`,
`INICIAR` e `START` registram novo opt-in quando recebidas diretamente do contato.

## Regras operacionais obrigatorias

1. Enviar somente para contatos que deram consentimento verificavel.
2. Identificar o remetente e oferecer uma forma simples de parar mensagens.
3. Nao comprar listas, raspar numeros ou iniciar conversas em massa.
4. Interromper envios diante de bloqueios, denuncias ou aumento de falhas.
5. Preferir a API oficial WhatsApp Business para operacoes de maior escala.

Os limites devem ser reduzidos para contas novas ou com pouco historico. Aumentar
limites exige revisar opt-in, taxa de respostas, bloqueios e qualidade da conta;
nao deve ser uma tentativa de contornar controles da plataforma.
