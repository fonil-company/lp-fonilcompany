# Verificação de Lead

Execute `node --test` na raiz do repositório com Node.js 20+ e PHP 8.3+ (extensões curl e mbstring). Se PHP não estiver no PATH, defina `PHP_BINARY` com o caminho do executável.

Os testes da API usam uma cópia temporária do endpoint e um webhook local simulado. Não utilizam credenciais do projeto nem enviam cadastros reais. Verificam antispam, validação, webhook ausente, falha de conexão, rejeição do CRM e aceite HTTP 2xx.

Os testes do JavaScript verificam que `Lead` e `generate_lead` só disparam com HTTP de sucesso, `ok: true` e `crm_sent: true`. Também verificam consentimento do pixel, envio pendente, falhas, repetição de cliques e preservação de `event_id` nas novas tentativas. A API informa `crm_sent: true` somente depois do aceite do webhook CRM; uma resposta de antispam não confirma entrega.
