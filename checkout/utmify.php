<?php
header('Content-Type: application/json');

// Carregar token do arquivo de configuração
$configFile = __DIR__ . '/utmify-config.json';
$utmifyToken = '';

if (file_exists($configFile)) {
    $config = json_decode(file_get_contents($configFile), true);
    $utmifyToken = trim($config['token'] ?? ''); // trim evita espaços/quebras
}

if (empty($utmifyToken)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Token Utmify não configurado. Configure o token no painel administrativo.'
    ]);
    exit;
}

$utmifyApiUrl = "https://api.utmify.com.br/api-credentials/orders";
$logDir = __DIR__ . '/logs';
if (!file_exists($logDir)) {
    mkdir($logDir, 0777, true);
}
$logFile = $logDir . '/utmify-' . date('Y-m-d') . '.log';

function writeLog($message, $data = null) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    if ($data !== null) {
        $logMessage .= "Dados: " . json_encode($data, JSON_PRETTY_PRINT) . "\n";
    }
    $logMessage .= "----------------------------------------\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

try {
    $rawData = file_get_contents('php://input');
    writeLog("📥 Dados recebidos", ['raw' => $rawData]);

    $inputData = json_decode($rawData, true);
    if (!$inputData) {
        throw new Exception("Dados JSON inválidos");
    }

    writeLog("🔄 Processando dados recebidos", $inputData);

    if ($inputData['status'] !== 'paid' && $inputData['status'] !== 'PAID' &&
        $inputData['status'] !== 'approved' && $inputData['status'] !== 'APPROVED') {
        writeLog("⏭️ Status ignorado", ['status' => $inputData['status']]);
        http_response_code(200);
        echo json_encode(['message' => 'Status ignorado']);
        exit;
    }

    // ==================================================================
    // FIX PRINCIPAL: usar IP/UA/fbp/fbc/phone/externalId que JÁ VÊM
    // no payload do webhook-paradise.php (que buscou do banco).
    // NÃO usar $_SERVER['REMOTE_ADDR'] aqui (é IP localhost do servidor,
    // pois utmify.php é chamado LOCALMENTE pelo webhook).
    // ==================================================================
    $customerInput = $inputData['customer'] ?? [];

    $customerIp        = $customerInput['ip']         ?? null;
    $customerUserAgent = $customerInput['userAgent']  ?? null;
    $customerPhone     = $customerInput['phone']      ?? null;
    $customerFbp       = $customerInput['fbp']        ?? null;
    $customerFbc       = $customerInput['fbc']        ?? null;
    $customerExtId     = $customerInput['externalId'] ?? ($inputData['orderId'] ?? null);

    // Suporte a formato aninhado (document.number com type CPF) e simples (document)
    $customerDocument = null;
    if (isset($customerInput['document'])) {
        if (is_array($customerInput['document']) && isset($customerInput['document']['number'])) {
            $customerDocument = $customerInput['document']['number'];
        } else {
            $customerDocument = $customerInput['document'];
        }
    }

    // Produtos - suporta $inputData['items'] (formato Paradise) ou $inputData['products']
    $productsSource = $inputData['items'] ?? $inputData['products'] ?? [];
    $firstProduct   = $productsSource[0] ?? [];

    $productId       = $firstProduct['id'] ?? uniqid('PROD_');
    $productName     = $firstProduct['title'] ?? $firstProduct['name'] ?? 'Produto';
    $productQuantity = $firstProduct['quantity'] ?? 1;
    $productPrice    = $firstProduct['unitPrice'] ?? $firstProduct['priceInCents'] ?? ($inputData['amount'] ?? 0);

    // Commission - suporta fee (formato Paradise) ou commission direto
    $totalPrice     = $inputData['commission']['totalPriceInCents']     ?? $inputData['amount'] ?? $productPrice;
    $gatewayFee     = $inputData['commission']['gatewayFeeInCents']     ?? ($inputData['fee']['fixedAmount'] ?? 0);
    $userCommission = $inputData['commission']['userCommissionInCents'] ?? ($inputData['fee']['netAmount'] ?? $totalPrice);

    // Datas
    $createdAt    = !empty($inputData['createdAt'])
        ? gmdate('Y-m-d H:i:s', strtotime($inputData['createdAt']))
        : gmdate('Y-m-d H:i:s');
    $approvedDate = !empty($inputData['paidAt'])
        ? gmdate('Y-m-d H:i:s', strtotime($inputData['paidAt']))
        : (!empty($inputData['approvedDate'])
            ? gmdate('Y-m-d H:i:s', strtotime($inputData['approvedDate']))
            : gmdate('Y-m-d H:i:s'));

    $utmifyData = [
        'orderId'       => $inputData['orderId'],
        'platform'      => $inputData['platform'] ?? 'ParadisePags',
        'paymentMethod' => $inputData['paymentMethod'] ?? 'pix',
        'status'        => 'paid',
        'createdAt'     => $createdAt,
        'approvedDate'  => $approvedDate,
        'refundedAt'    => null,
        'customer' => [
            'name'       => $customerInput['name']  ?? null,
            'email'      => $customerInput['email'] ?? null,
            'phone'      => $customerPhone,        // <- telefone real (não null)
            'document'   => $customerDocument,
            'country'    => $customerInput['country'] ?? 'BR',
            'ip'         => $customerIp,           // <- IP REAL DO CLIENTE (do banco)
            // Campos extras - doc pública Utmify não menciona mas o pixel Utmify
            // consome pra matching Meta CAPI (já testado, funciona):
            'userAgent'  => $customerUserAgent,
            'externalId' => $customerExtId,
            'fbp'        => $customerFbp,
            'fbc'        => $customerFbc,
        ],
        'products' => [
            [
                'id'           => $productId,
                'name'         => $productName,
                'planId'       => null,
                'planName'     => null,
                'quantity'     => $productQuantity,
                'priceInCents' => $productPrice
            ]
        ],
        'trackingParameters' => [
            'src'          => $inputData['trackingParameters']['src']          ?? null,
            'sck'          => $inputData['trackingParameters']['sck']          ?? null,
            'utm_source'   => $inputData['trackingParameters']['utm_source']   ?? null,
            'utm_campaign' => $inputData['trackingParameters']['utm_campaign'] ?? null,
            'utm_medium'   => $inputData['trackingParameters']['utm_medium']   ?? null,
            'utm_content'  => $inputData['trackingParameters']['utm_content']  ?? null,
            'utm_term'     => $inputData['trackingParameters']['utm_term']     ?? null,
            'xcod'         => $inputData['trackingParameters']['xcod']         ?? null,
            'fbclid'       => $inputData['trackingParameters']['fbclid']       ?? null,
            'gclid'        => $inputData['trackingParameters']['gclid']        ?? null,
            'ttclid'       => $inputData['trackingParameters']['ttclid']       ?? null
        ],
        'commission' => [
            'totalPriceInCents'     => $totalPrice,
            'gatewayFeeInCents'     => $gatewayFee,
            'userCommissionInCents' => $userCommission
        ],
        'isTest' => false
    ];

    writeLog("📤 Dados formatados para Utmify", $utmifyData);

    $ch = curl_init($utmifyApiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "x-api-token: $utmifyToken"
        ],
        CURLOPT_POSTFIELDS => json_encode($utmifyData, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30
    ]);

    writeLog("📡 Enviando requisição para Utmify", [
        'url' => $utmifyApiUrl,
        'headers' => [
            'Content-Type: application/json',
            'x-api-token: [REDACTED]'
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        writeLog("❌ Erro CURL", ['error' => curl_error($ch)]);
        throw new Exception("Erro ao enviar dados para Utmify: " . curl_error($ch));
    }

    curl_close($ch);

    writeLog("✅ Resposta da API Utmify", [
        'http_code' => $httpCode,
        'response' => json_decode($response, true)
    ]);

    if ($httpCode !== 200) {
        throw new Exception("Erro na API Utmify. HTTP Code: $httpCode");
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Dados enviados com sucesso para Utmify'
    ]);

} catch (Exception $e) {
    writeLog("❌ Erro", ['message' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
