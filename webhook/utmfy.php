<?php
/**
 * Webhook para integrar BilionPay com UTMfy via API
 * Recebe notificações da BilionPay e envia para UTMfy usando credencial de API
 * 
 * Documentação UTMfy: https://api.utmify.com.br/api-credentials/orders
 */

// Configurações - CREDENCIAIS UTMFY
$UTMFY_API_TOKEN = 'X2WJKFRnpDV8KREIG46A0dMPy498hBOrDCwF'; // Token de API gerado na UTMfy
$UTMFY_API_URL = 'https://api.utmify.com.br/api-credentials/orders'; // Endpoint da API da UTMfy

// Log de debug
function logWebhook($message) {
    $logFile = 'webhook_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Verificar se é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Obter dados do webhook da BilionPay
// Documentação: https://developers.fastsoftbrasil.com/docs/webhook/transaction
$input = file_get_contents('php://input');
$headers = getallheaders();
$webhookData = json_decode($input, true);

logWebhook("Webhook recebido da BilionPay - Headers: " . json_encode($headers));
logWebhook("Webhook recebido da BilionPay - Body: " . $input);

// Verificar se os dados são válidos
if (!$webhookData) {
    logWebhook("Erro: Dados JSON inválidos");
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON data']);
    exit;
}

// Estrutura esperada da BilionPay conforme documentação:
// {
//   "type": "transaction",
//   "objectId": "5f5d22ab-34e2-4c9b-832b-9a87b6e5b798",
//   "url": "https://webhook.example.com/transaction",
//   "data": { ...dados da transação... }
// }

// Verificar se é um webhook de transação
if (!isset($webhookData['type']) || $webhookData['type'] !== 'transaction') {
    logWebhook("Tipo de webhook não é 'transaction': " . ($webhookData['type'] ?? 'unknown'));
    // Responder 200 mas não processar
    echo json_encode([
        'status' => 'ignored',
        'reason' => 'Not a transaction webhook',
        'type' => $webhookData['type'] ?? 'unknown'
    ]);
    exit;
}

// Extrair dados da transação conforme documentação
$data = $webhookData['data'] ?? null;
$objectId = $webhookData['objectId'] ?? null;

if (!$data || !is_array($data)) {
    logWebhook("Erro: Campo 'data' não encontrado ou inválido no webhook. Estrutura: " . json_encode($webhookData));
    http_response_code(400);
    echo json_encode(['error' => 'Transaction data not found in webhook']);
    exit;
}

logWebhook("Dados da transação extraídos - ObjectId: $objectId, Transaction ID: " . ($data['id'] ?? 'N/A'));

// Status possíveis conforme documentação:
// PROCESSING, WAITING_PAYMENT, IN_ANALYSIS, AUTHORIZED, PAID, 
// IN_PROTEST, REFUNDED, CHARGEDBACK, REFUSED, CANCELED
$status = strtoupper($data['status'] ?? '');

logWebhook("Status da transação: $status");

// Mapear status da BilionPay para status da UTMfy
$utmfyStatus = null;
$shouldProcess = false;

// Mapeamento de status conforme documentação UTMfy:
// 'waiting_payment' | 'paid' | 'refused' | 'refunded' | 'chargedback'
if ($status === 'PAID') {
    $utmfyStatus = 'paid';
    $shouldProcess = true;
    logWebhook("✅ Transação PAGA confirmada! Enviando para UTMfy como 'paid'...");
} elseif (in_array($status, ['WAITING_PAYMENT', 'PROCESSING', 'IN_ANALYSIS', 'AUTHORIZED'])) {
    $utmfyStatus = 'waiting_payment';
    $shouldProcess = true;
    logWebhook("⏳ Transação PENDENTE detectada ($status). Enviando para UTMfy como 'waiting_payment'...");
} elseif ($status === 'REFUNDED') {
    $utmfyStatus = 'refunded';
    $shouldProcess = true;
    logWebhook("💰 Transação REEMBOLSADA detectada. Enviando para UTMfy como 'refunded'...");
} elseif ($status === 'REFUSED') {
    $utmfyStatus = 'refused';
    $shouldProcess = true;
    logWebhook("❌ Transação RECUSADA detectada. Enviando para UTMfy como 'refused'...");
} elseif ($status === 'CHARGEDBACK') {
    $utmfyStatus = 'chargedback';
    $shouldProcess = true;
    logWebhook("⚠️ Transação CHARGEDBACK detectada. Enviando para UTMfy como 'chargedback'...");
} else {
    logWebhook("Transação ignorada. Status: $status - Não será enviada para UTMfy");
    echo json_encode([
        'status' => 'ignored', 
        'reason' => 'Transaction status not tracked', 
        'current_status' => $status,
        'message' => 'Webhook recebido mas não processado - status não elegível para tracking'
    ]);
    exit;
}

if (!$shouldProcess || !$utmfyStatus) {
    exit;
}

// Extrair dados da transação BilionPay
$transactionId = $data['id'] ?? $objectId ?? null;
if (empty($transactionId)) {
    logWebhook("ERRO CRÍTICO: Transaction ID não encontrado!");
    http_response_code(400);
    echo json_encode(['error' => 'Transaction ID is required']);
    exit;
}

$amount = intval($data['amount'] ?? 0); // Já vem em centavos
if ($amount <= 0) {
    logWebhook("ERRO CRÍTICO: Amount inválido: $amount");
    http_response_code(400);
    echo json_encode(['error' => 'Invalid transaction amount']);
    exit;
}

$customer = $data['customer'] ?? [];
$paymentMethodRaw = strtoupper($data['paymentMethod'] ?? 'PIX');
$metadataRaw = $data['metadata'] ?? [];
// Tratar metadata que pode vir como string JSON ou objeto
$metadata = [];
if (is_string($metadataRaw)) {
    $metadata = json_decode($metadataRaw, true) ?? [];
} elseif (is_array($metadataRaw)) {
    $metadata = $metadataRaw;
}
$items = $data['items'] ?? [];
$createdAt = $data['createdAt'] ?? $data['created_at'] ?? $data['created'] ?? null;
$paidAt = $data['paidAt'] ?? $data['paid_at'] ?? $data['paid'] ?? null;
$refundedAt = $data['refundedAt'] ?? $data['refunded_at'] ?? $data['refunded'] ?? null;
$currency = strtoupper($data['currency'] ?? 'BRL');
$customerAddress = $customer['address'] ?? [];

// Extrair IP de várias fontes possíveis
$customerIp = $data['ip'] ?? $metadata['ip'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
if (!empty($customerIp)) {
    // Pegar o primeiro IP se for uma lista (X-Forwarded-For pode ter múltiplos IPs)
    $customerIp = trim(explode(',', $customerIp)[0]);
}

// Mapear paymentMethod para formato UTMfy: 'credit_card' | 'boleto' | 'pix' | 'paypal' | 'free_price'
$utmfyPaymentMethod = 'pix'; // padrão
if (stripos($paymentMethodRaw, 'CREDIT') !== false || stripos($paymentMethodRaw, 'CARD') !== false) {
    $utmfyPaymentMethod = 'credit_card';
} elseif (stripos($paymentMethodRaw, 'BOLETO') !== false) {
    $utmfyPaymentMethod = 'boleto';
} elseif (stripos($paymentMethodRaw, 'PIX') !== false) {
    $utmfyPaymentMethod = 'pix';
}

// Extrair parâmetros UTM dos metadados (se existirem)
$utmSource = $metadata['utm_source'] ?? null;
$utmCampaign = $metadata['utm_campaign'] ?? null;
$utmMedium = $metadata['utm_medium'] ?? null;
$utmContent = $metadata['utm_content'] ?? null;
$utmTerm = $metadata['utm_term'] ?? null;
$src = $metadata['src'] ?? null;
$sck = $metadata['sck'] ?? null;

// Formatar datas para UTC no formato 'YYYY-MM-DD HH:MM:SS'
function formatDateForUtmfy($dateString) {
    if (empty($dateString)) return null;
    try {
        $dt = new DateTime($dateString, new DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        try {
            // Tentar parsear como ISO 8601
            $dt = new DateTime($dateString);
            $dt->setTimezone(new DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e2) {
            return null;
        }
    }
}

// Usar a data atual se não houver createdAt
if (empty($createdAt)) {
    $createdAt = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
} else {
    $createdAt = formatDateForUtmfy($createdAt);
}

$approvedDate = ($utmfyStatus === 'paid' && !empty($paidAt)) ? formatDateForUtmfy($paidAt) : null;
$refundedDate = ($utmfyStatus === 'refunded' && !empty($refundedAt)) ? formatDateForUtmfy($refundedAt) : null;

// Extrair informações do produto
$products = [];
$totalProductsAmount = 0;

if (!empty($items) && is_array($items)) {
    foreach ($items as $item) {
        $itemId = $item['externalRef'] ?? $item['id'] ?? $item['externalRef'] ?? uniqid('prod_');
        $itemName = $item['title'] ?? $item['name'] ?? 'Taxa de Inscricao SES TO 2026 - Edital 001/2026';
        $itemQuantity = max(1, intval($item['quantity'] ?? 1));
        $itemPrice = intval($item['unitPrice'] ?? $item['price'] ?? $item['amount'] ?? 0);
        
        // Se não tiver preço, dividir o amount total pela quantidade de produtos
        if ($itemPrice <= 0 && !empty($items)) {
            $itemPrice = intval($amount / count($items));
        }
        
        // Se ainda não tiver preço, usar o amount total
        if ($itemPrice <= 0) {
            $itemPrice = $amount;
        }
        
        $totalProductsAmount += ($itemPrice * $itemQuantity);
        
        $products[] = [
            'id' => (string)$itemId,
            'name' => (string)$itemName,
            'planId' => null,
            'planName' => null,
            'quantity' => $itemQuantity,
            'priceInCents' => $itemPrice
        ];
    }
}

// Se não houver produtos ou lista vazia, criar produto padrão
if (empty($products)) {
    $products[] = [
        'id' => 'SESTO26_TAXA',
        'name' => 'Taxa de Inscricao SES TO 2026 - Edital 001/2026',
        'planId' => null,
        'planName' => null,
        'quantity' => 1,
        'priceInCents' => $amount
    ];
    $totalProductsAmount = $amount;
}

// Garantir que totalProductsAmount está correto
if ($totalProductsAmount <= 0) {
    $totalProductsAmount = $amount;
}

logWebhook("Produtos processados: " . count($products) . " produto(s), Total: R$ " . ($totalProductsAmount / 100));

// Calcular comissões (assumindo taxa de 3% + R$ 1,00 para PIX)
$gatewayFeeInCents = 0;
if ($utmfyPaymentMethod === 'pix') {
    $gatewayFeeInCents = intval($amount * 0.03 + 100); // 3% + R$ 1,00
} else {
    $gatewayFeeInCents = intval($amount * 0.05); // 5% para outros métodos
}
$userCommissionInCents = $amount - $gatewayFeeInCents;

// Extrair dados do cliente com validações
$customerName = trim($customer['name'] ?? 'Cliente');
if (empty($customerName) || $customerName === 'Cliente') {
    // Tentar obter nome dos metadados ou usar padrão
    $customerName = $metadata['nome'] ?? $metadata['name'] ?? 'Cliente';
}

$customerEmail = trim(strtolower($customer['email'] ?? ''));
if (empty($customerEmail)) {
    $customerEmail = $metadata['email'] ?? '';
}
// Validar formato de email básico
if (!empty($customerEmail) && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
    logWebhook("AVISO: Email inválido detectado: $customerEmail");
    // Não bloquear, mas registrar aviso
}

$customerPhone = null;
if (isset($customer['phone'])) {
    $customerPhone = preg_replace('/\D/', '', $customer['phone']);
    if (empty($customerPhone)) {
        $customerPhone = null;
    }
}

$customerDocument = null;
if (isset($customer['document']['number'])) {
    $customerDocument = preg_replace('/\D/', '', $customer['document']['number']);
    if (empty($customerDocument)) {
        $customerDocument = null;
    }
}
// Tentar obter CPF dos metadados se não estiver no customer
if (empty($customerDocument) && !empty($metadata['cpf'])) {
    $customerDocument = preg_replace('/\D/', '', $metadata['cpf']);
}

$customerCountry = strtoupper($customerAddress['country'] ?? $customer['country'] ?? 'BR');
// Garantir formato ISO 3166-1 alfa-2 (2 letras)
if (strlen($customerCountry) > 2) {
    $customerCountry = 'BR'; // Fallback se formato inválido
}

// Construir payload conforme documentação UTMfy - TODOS OS CAMPOS OBRIGATÓRIOS
$utmfyPayload = [
    'orderId' => (string)$transactionId, // OBRIGATÓRIO
    'platform' => 'BilionPay', // OBRIGATÓRIO
    'paymentMethod' => $utmfyPaymentMethod, // OBRIGATÓRIO
    'status' => $utmfyStatus, // OBRIGATÓRIO
    'createdAt' => $createdAt, // OBRIGATÓRIO - formato 'YYYY-MM-DD HH:MM:SS' UTC
    'approvedDate' => $approvedDate, // OBRIGATÓRIO (pode ser null)
    'refundedAt' => $refundedDate, // OBRIGATÓRIO (pode ser null)
    'customer' => [ // OBRIGATÓRIO
        // PRIVACIDADE: dados reais do cliente nunca são enviados à UTMify.
        'name' => 'Cliente', // OBRIGATÓRIO
        'email' => 'cliente@email.com', // OBRIGATÓRIO
        'phone' => '11999999999', // OBRIGATÓRIO (pode ser null)
        'document' => '11144477735', // OBRIGATÓRIO (pode ser null)
        'country' => $customerCountry, // OPCIONAL mas recomendado
        'ip' => $customerIp // OPCIONAL mas recomendado
    ],
    'products' => $products, // OBRIGATÓRIO - array
    'trackingParameters' => [ // OBRIGATÓRIO
        'src' => $src, // OPCIONAL (pode ser null)
        'sck' => $sck, // OPCIONAL (pode ser null)
        'utm_source' => $utmSource, // OPCIONAL (pode ser null)
        'utm_campaign' => $utmCampaign, // OPCIONAL (pode ser null)
        'utm_medium' => $utmMedium, // OPCIONAL (pode ser null)
        'utm_content' => $utmContent, // OPCIONAL (pode ser null)
        'utm_term' => $utmTerm // OPCIONAL (pode ser null)
    ],
    'commission' => [ // OBRIGATÓRIO
        'totalPriceInCents' => $amount, // OBRIGATÓRIO
        'gatewayFeeInCents' => $gatewayFeeInCents, // OBRIGATÓRIO
        'userCommissionInCents' => max($userCommissionInCents, 1) // OBRIGATÓRIO - mínimo 1 centavo
    ]
];

// Validar campos obrigatórios antes de enviar
$requiredFields = [
    'orderId' => 'Transaction ID',
    'platform' => 'Platform',
    'paymentMethod' => 'Payment Method',
    'status' => 'Status',
    'createdAt' => 'Created At',
    'approvedDate' => 'Approved Date (pode ser null)',
    'refundedAt' => 'Refunded At (pode ser null)'
];

foreach ($requiredFields as $field => $name) {
    if ($field === 'approvedDate' || $field === 'refundedAt') {
        // Estes podem ser null, só verificar se está definido
        if (!isset($utmfyPayload[$field])) {
            logWebhook("ERRO: Campo obrigatório '$name' não está definido!");
        }
    } elseif (empty($utmfyPayload[$field])) {
        logWebhook("ERRO: Campo obrigatório '$name' está vazio!");
        http_response_code(400);
        echo json_encode(['error' => "Required field '$field' is missing or empty"]);
        exit;
    }
}

// Validar estrutura do customer
if (empty($utmfyPayload['customer']['name']) || empty($utmfyPayload['customer']['email'])) {
    logWebhook("ERRO: Campos obrigatórios do customer estão vazios!");
    http_response_code(400);
    echo json_encode(['error' => 'Customer name and email are required']);
    exit;
}

// Validar products
if (empty($utmfyPayload['products']) || !is_array($utmfyPayload['products'])) {
    logWebhook("ERRO: Products está vazio ou inválido!");
    http_response_code(400);
    echo json_encode(['error' => 'Products array is required']);
    exit;
}

// Validar commission
if ($utmfyPayload['commission']['totalPriceInCents'] <= 0) {
    logWebhook("ERRO: Total price inválido!");
    http_response_code(400);
    echo json_encode(['error' => 'Invalid total price']);
    exit;
}

// Adicionar currency apenas se não for BRL (conforme documentação UTMfy)
if ($currency !== 'BRL') {
    $utmfyPayload['commission']['currency'] = $currency;
}

// Log detalhado do payload antes de enviar
logWebhook("=== PAYLOAD PARA UTMFY ===");
logWebhook("Order ID: " . $utmfyPayload['orderId']);
logWebhook("Platform: " . $utmfyPayload['platform']);
logWebhook("Status: " . $utmfyPayload['status']);
logWebhook("Payment Method: " . $utmfyPayload['paymentMethod']);
logWebhook("Customer: " . $utmfyPayload['customer']['name'] . " (" . $utmfyPayload['customer']['email'] . ")");
logWebhook("Products: " . count($utmfyPayload['products']) . " item(s)");
logWebhook("Total: R$ " . ($utmfyPayload['commission']['totalPriceInCents'] / 100));
logWebhook("Payload completo: " . json_encode($utmfyPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
logWebhook("========================");

// Enviar para UTMfy via API
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $UTMFY_API_URL);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($utmfyPayload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json',
    'x-api-token: ' . $UTMFY_API_TOKEN,
    'User-Agent: BilionPay-UTMfy-Integration/1.0'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    logWebhook("Erro ao enviar para UTMfy: $error");
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send to UTMfy', 'details' => $error]);
} else {
    logWebhook("Enviado para UTMfy com sucesso. HTTP Code: $httpCode. Response: $response");
    
    // Verificar se a resposta indica sucesso
    if ($httpCode >= 200 && $httpCode < 300) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Order sent to UTMfy successfully',
            'utmfy_response' => $response,
            'http_code' => $httpCode,
            'order_status' => $utmfyStatus
        ]);
    } else {
        logWebhook("UTMfy retornou erro HTTP: $httpCode - $response");
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'UTMfy API returned error',
            'utmfy_response' => $response,
            'http_code' => $httpCode
        ]);
    }
}
?>
