<?php
// ========== WEBHOOK TRUST7 ==========

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

$TRUST7_WEBHOOK_SECRET = ''; // Opcional: Coloque seu Secret do Webhook aqui se quiser validação de assinatura

$rawBody = file_get_contents('php://input');

// 1. Validação de Assinatura (Opcional - mas recomendado)
if (!empty($TRUST7_WEBHOOK_SECRET)) {
    $signatureHeader = $_SERVER['HTTP_TRUST7_SIGNATURE'] ?? '';
    
    if (empty($signatureHeader)) {
        error_log("[Webhook Trust7] ❌ Recusado: Cabeçalho Trust7-Signature ausente");
        http_response_code(400);
        exit;
    }

    // Parse do cabeçalho t=TIMESTAMP,v1=HEX
    $parts = explode(',', $signatureHeader);
    $timestamp = '';
    $v1 = '';
    
    foreach ($parts as $part) {
        if (strpos($part, 't=') === 0) {
            $timestamp = substr($part, 2);
        } elseif (strpos($part, 'v1=') === 0) {
            $v1 = substr($part, 3);
        }
    }

    if (empty($timestamp) || empty($v1)) {
        error_log("[Webhook Trust7] ❌ Recusado: Formato de assinatura inválido");
        http_response_code(400);
        exit;
    }

    // Verificar tempo (diferença máx 5 min)
    if (abs(time() - (int)$timestamp) > 300) {
        error_log("[Webhook Trust7] ❌ Recusado: Timestamp expirado");
        http_response_code(400);
        exit;
    }

    // Calcular HMAC-SHA256
    $signedPayload = $timestamp . '.' . $rawBody;
    $computedSignature = hash_hmac('sha256', $signedPayload, $TRUST7_WEBHOOK_SECRET);

    if (!hash_equals($computedSignature, $v1)) {
        error_log("[Webhook Trust7] ❌ Recusado: Assinatura inválida");
        http_response_code(401);
        exit;
    }
} else {
    error_log("[Webhook Trust7] ⚠️ Aviso: Webhook rodando sem validação de assinatura (Secret não configurado)");
}

// 2. Parse do Payload JSON
$payload = json_decode($rawBody, true);

if (!is_array($payload) || empty($payload['type'])) {
    error_log("[Webhook Trust7] ❌ Recusado: Payload inválido");
    http_response_code(400);
    exit;
}

$eventType = $payload['type'];
$eventData = $payload['data']['object'] ?? null;

if (!$eventData || empty($eventData['id'])) {
    http_response_code(200);
    exit;
}

$transactionId = $eventData['id'];

// Só nos interessa o pagamento capturado
if ($eventType === 'payment.captured') {
    try {
        $dbPath = __DIR__ . '/database.sqlite';
        $db = new PDO("sqlite:$dbPath");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $db->prepare("SELECT * FROM pedidos WHERE transaction_id = :transaction_id");
        $stmt->execute(['transaction_id' => $transactionId]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pedido && $pedido['status'] !== 'paid') {
            // Atualizar status
            $update = $db->prepare("UPDATE pedidos SET status = 'paid', updated_at = :updated_at WHERE transaction_id = :transaction_id");
            $update->execute([
                'updated_at' => date('c'),
                'transaction_id' => $transactionId
            ]);

            error_log("[Webhook Trust7] ✅ Pagamento capturado atualizado: $transactionId");

            // ========== NOTIFICA UTMIFY (paid) ==========
            $utmParams = json_decode($pedido['utm_params'] ?? '[]', true);
            if (!is_array($utmParams)) $utmParams = [];

            $utmifyData = [
                'orderId'       => (string) $transactionId,
                'platform'      => 'Trust7',
                'paymentMethod' => 'pix',
                'status'        => 'paid',
                'createdAt'     => $pedido['created_at'],
                'approvedDate'  => gmdate('Y-m-d H:i:s'),
                'paidAt'        => gmdate('Y-m-d H:i:s'),
                'refundedAt'    => null,
                'customer'      => [
                    'name'       => $pedido['nome'],
                    'email'      => $pedido['email'],
                    'phone'      => $pedido['telefone'] ?? null,
                    'document'   => $pedido['cpf'],
                    'country'    => 'BR',
                    'ip'         => $pedido['client_ip'] ?? null,
                    'userAgent'  => $pedido['client_user_agent'] ?? null,
                    'externalId' => (string) $transactionId,
                    'fbp'        => $pedido['fbp'] ?? null,
                    'fbc'        => $pedido['fbc'] ?? null
                ],
                'products'      => [
                    [
                        'id'           => uniqid('PROD_'),
                        'name'         => 'Produto ' . (($pedido['valor'] ?? 0) / 100),
                        'quantity'     => 1,
                        'priceInCents' => (int) ($pedido['valor'] ?? 0)
                    ]
                ],
                'trackingParameters' => $utmParams,
                'commission'    => [
                    'totalPriceInCents'     => (int) ($pedido['valor'] ?? 0),
                    'gatewayFeeInCents'     => 0,
                    'userCommissionInCents' => (int) ($pedido['valor'] ?? 0)
                ],
                'isTest' => false
            ];

            $serverUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://" . $_SERVER['HTTP_HOST'];
            $utmifyUrl = $serverUrl . dirname($_SERVER['SCRIPT_NAME']) . '/utmify.php';

            $ch = curl_init($utmifyUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($utmifyData, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ]);
            $utmifyResponse = curl_exec($ch);
            curl_close($ch);
            
            error_log("[Webhook Trust7] 📡 UTMify atualizado para $transactionId");
        }
    } catch (Exception $e) {
        error_log("[Webhook Trust7] ❌ Erro: " . $e->getMessage());
        http_response_code(500);
        exit;
    }
}

// Responde rápido com 2xx
http_response_code(200);
echo json_encode(['received' => true]);
