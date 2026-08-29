<?php
header('Content-Type: application/json');

$configFile = __DIR__ . '/utmify-config.json';
$utmifyToken = '';

if (file_exists($configFile)) {
    $config = json_decode(file_get_contents($configFile), true);
    $utmifyToken = trim($config['token'] ?? ''); // trim remove espaços/quebras que possam ter ficado ao colar
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
    if (!mkdir($logDir, 0777, true)) {
        error_log("Erro ao criar diretório de logs: " . $logDir);
    } else {
        chmod($logDir, 0777);
    }
}
$logFile = $logDir . '/utmify-pendente-' . date('Y-m-d') . '.log';

function writeLog($message, $data = null) {
    global $logFile;
    $timestamp = gmdate('Y-m-d H:i:s'); // UTC
    $logMessage = "[$timestamp] $message\n";
    if ($data !== null) {
        $logMessage .= "Dados: " . json_encode($data, JSON_PRETTY_PRINT) . "\n";
    }
    $logMessage .= "----------------------------------------\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

// ===== CAPTURA IP REAL DO CLIENTE (fallback quando o payload não traz) =====
function getClientIP() {
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR'
    ];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = $_SERVER[$header];
            if (strpos($ip, ',') !== false) {
                $ips = explode(',', $ip);
                $ip = trim($ips[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return null;
}

try {
    $rawData = file_get_contents('php://input');
    writeLog("📥 Dados recebidos", ['raw' => $rawData]);

    $inputData = json_decode($rawData, true);
    if (!$inputData) {
        throw new Exception("Dados JSON inválidos");
    }

    writeLog("🔄 Processando dados recebidos", $inputData);

    // ==================================================================
    // FIX PRINCIPAL: usar IP/UA/fbp/fbc/phone/externalId QUE VÊM NO
    // PAYLOAD do pagamento-flevopay.php, e SÓ recorrer a REMOTE_ADDR
    // como último fallback (que aqui é o IP do próprio servidor,
    // portanto inútil pra matching Meta - mas evita null completo).
    // ==================================================================
    $customerInput = $inputData['customer'] ?? [];

    $customerIp        = $customerInput['ip']        ?? null;
    $customerUserAgent = $customerInput['userAgent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null);
    $customerPhone     = $customerInput['phone']     ?? null;
    $customerFbp       = $customerInput['fbp']       ?? null;
    $customerFbc       = $customerInput['fbc']       ?? null;
    $customerExtId     = $customerInput['externalId'] ?? ($inputData['orderId'] ?? null);

    // Se o payload não trouxe IP (compat retrocompat com formatos antigos),
    // NÃO usar REMOTE_ADDR direto - pode ser IP local. Só usar se parecer
    // um IP público válido de cliente real.
    if (empty($customerIp)) {
        $fallbackIp = getClientIP();
        // Ignora IPs locais/privados que confundem o matching
        if ($fallbackIp && filter_var($fallbackIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $customerIp = $fallbackIp;
        }
    }

    // Suporte a formatos aninhados (document.number) e simples (document)
    $customerDocument = null;
    if (isset($customerInput['document'])) {
        if (is_array($customerInput['document']) && isset($customerInput['document']['number'])) {
            $customerDocument = $customerInput['document']['number'];
        } else {
            $customerDocument = $customerInput['document'];
        }
    }

    // Produtos - suporta $inputData['products'] ou $inputData['items']
    $productsSource = $inputData['products'] ?? $inputData['items'] ?? [];
    $firstProduct   = $productsSource[0] ?? [];

    $productId       = $firstProduct['id'] ?? uniqid('PROD_');
    $productName     = $firstProduct['name'] ?? $firstProduct['title'] ?? 'Produto';
    $productQuantity = $firstProduct['quantity'] ?? 1;
    $productPrice    = $firstProduct['priceInCents'] ?? $firstProduct['unitPrice'] ?? ($inputData['amount'] ?? 0);

    // Commission - suporta comission já formatada ou $inputData['amount']
    $commissionInput = $inputData['commission'] ?? [];
    $totalPrice      = $commissionInput['totalPriceInCents']     ?? $inputData['amount'] ?? $productPrice;
    $gatewayFee      = $commissionInput['gatewayFeeInCents']     ?? ($inputData['fee']['fixedAmount'] ?? 0);
    $userCommission  = $commissionInput['userCommissionInCents'] ?? ($inputData['fee']['netAmount'] ?? $totalPrice);

    // Determina platform / createdAt
    $platform  = $inputData['platform'] ?? 'FlevoPay';
    $createdAt = !empty($inputData['createdAt'])
        ? gmdate('Y-m-d H:i:s', strtotime($inputData['createdAt']))
        : gmdate('Y-m-d H:i:s');

    $utmifyData = [
        'orderId'       => $inputData['orderId'],
        'platform'      => $platform,
        'paymentMethod' => $inputData['paymentMethod'] ?? 'pix',
        'status'        => 'waiting_payment',
        'createdAt'     => $createdAt,
        'approvedDate'  => null,
        'refundedAt'    => null,
        'customer' => [
            // PRIVACIDADE: dados reais do cliente nunca são enviados à UTMify.
            'name'       => 'Cliente',
            'email'      => 'cliente@email.com',
            'phone'      => '11999999999',
            'document'   => '11144477735',
            'country'    => $customerInput['country'] ?? 'BR',
            'ip'         => $customerIp,           // <- IP REAL DO CLIENTE (não REMOTE_ADDR)
            // Campos extras - documentação oficial não menciona, mas
            // são consumidos pelo pixel Utmify pra matching Meta CAPI:
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
        'response'  => json_decode($response, true)
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
