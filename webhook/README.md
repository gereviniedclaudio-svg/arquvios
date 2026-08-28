# 🔗 Integração MedusaPay + UTMfy

## 📋 Instruções de Configuração

### 1. Criar Credencial de API na UTMfy

1. Acesse sua conta na UTMfy
2. Vá para **"Credenciais de API"**
3. Clique em **"Adicionar Credencial"**
4. Configure:
   - **Nome:** "MedusaPay Integration"
   - **Descrição:** "Integração com gateway MedusaPay"
5. Copie a chave API gerada (formato: `utmfy_api_xxxxx`)

### 2. Configurar o Webhook

1. Edite o arquivo `webhook/utmfy.php`
2. Substitua `SUA_CHAVE_API_UTMFY_AQUI` pela sua chave da UTMfy
3. Salve o arquivo

### 3. Configurar Webhook na MedusaPay

1. Acesse o painel da MedusaPay
2. Vá para **"Configurações"** → **"Webhooks"**
3. Adicione novo webhook:
   - **URL:** `https://SEU_DOMINIO/webhook/utmfy.php` (ou use caminho relativo `/webhook/utmfy.php`)
   - **Eventos:** 
     - `transaction.approved`
     - `transaction.paid`
   - **Método:** POST

### 4. Testar a Integração

1. Faça uma venda de teste
2. Verifique o arquivo `webhook_log.txt` para logs
3. Confirme na UTMfy se a conversão foi registrada

## 🔧 Arquivos da Integração

- `webhook/utmfy.php` - Webhook principal
- `webhook/config.php` - Configurações
- `webhook_log.txt` - Logs de debug (criado automaticamente)
- `CNU-info/js/medusapay-utmfy-integration.js` - Script JavaScript

## 📊 Dados Enviados para UTMfy

```json
{
  "pixel_id": "68fafce3e95a8ad5bfc8863d",
  "event_type": "purchase",
  "transaction_id": "ID_DA_TRANSACAO",
  "value": 85.00,
  "currency": "BRL",
  "customer_email": "email@cliente.com",
  "customer_name": "Nome do Cliente",
  "product_name": "Taxa de Inscricao SES TO 2026 - Edital 001/2026",
  "product_id": "SESTO26_TAXA",
  "quantity": 1,
  "timestamp": "2025-01-XX...",
  "payment_method": "pix",
  "gateway": "medusapay"
}
```

## 🚨 Troubleshooting

### Problemas Comuns:

1. **Erro 401 (Unauthorized):**
   - Verifique se a chave API da UTMfy está correta
   - Confirme se a chave tem permissões para conversões

2. **Erro 404 (Not Found):**
   - Verifique se a URL da API da UTMfy está correta
   - Confirme se o endpoint `/v1/conversions` existe

3. **Webhook não recebe dados:**
   - Verifique se o webhook está configurado na MedusaPay
   - Confirme se a URL do webhook está acessível

### Logs de Debug:

Os logs são salvos em `webhook_log.txt` com formato:
```
[2025-01-XX HH:MM:SS] Mensagem do log
```

## 📞 Suporte

Se precisar de ajuda:
1. Verifique os logs em `webhook_log.txt`
2. Teste com uma venda de valor baixo
3. Confirme as configurações da UTMfy e MedusaPay
