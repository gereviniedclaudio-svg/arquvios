<?php
// ========== WEBHOOK BLACKCAT ==========

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);

if (!is_array($payload) || empty($payload['event'])) {
    error_log("[Webhook Blackcat] ❌ Recusado: Payload inválido");
    http_response_code(400);
    exit;
}

$eventType = $payload['event'];
$transactionId = $payload['transactionId'] ?? null;

if (!$transactionId) {
    http_response_code(200);
    exit;
}

// Só nos interessa o pagamento capturado/pago
if ($eventType === 'transaction.paid') {
    try {
        $dbPath = __DIR__ . '/database.sqlite';
        $db = new PDO("sqlite:$dbPath");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $db->prepare("SELECT * FROM pedidos WHERE transaction_id = :transaction_id");
        $stmt->execute(['transaction_id' => $transactionId]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pedido && $pedido['status'] !== 'paid') {
            $update = $db->prepare("UPDATE pedidos SET status = 'paid', updated_at = :updated_at WHERE transaction_id = :transaction_id");
            $update->execute([
                'updated_at' => date('c'),
                'transaction_id' => $transactionId
            ]);

            error_log("[Webhook Blackcat] ✅ Pagamento capturado atualizado: $transactionId");

            // UTMify Notificação
            $utmParams = json_decode($pedido['utm_params'] ?? '[]', true);
            if (!is_array($utmParams)) $utmParams = [];

            $utmifyData = [
                'orderId' => (string) $transactionId, 'platform' => 'Blackcat', 'paymentMethod' => 'pix', 'status' => 'paid',
                'createdAt' => $pedido['created_at'], 'approvedDate' => gmdate('Y-m-d H:i:s'), 'paidAt' => gmdate('Y-m-d H:i:s'),
                'customer' => [
                    'name' => $pedido['nome'], 'email' => $pedido['email'], 'phone' => $pedido['telefone'] ?? null, 'document' => $pedido['cpf'],
                    'country' => 'BR', 'ip' => $pedido['client_ip'] ?? null, 'userAgent' => $pedido['client_user_agent'] ?? null, 'externalId' => (string) $transactionId,
                    'fbp' => $pedido['fbp'] ?? null, 'fbc' => $pedido['fbc'] ?? null
                ],
                'products' => [[ 'id' => uniqid('PROD_'), 'name' => 'Produto ' . (($pedido['valor'] ?? 0) / 100), 'quantity' => 1, 'priceInCents' => (int) ($pedido['valor'] ?? 0) ]],
                'trackingParameters' => $utmParams,
                'commission' => [ 'totalPriceInCents' => (int) ($pedido['valor'] ?? 0), 'gatewayFeeInCents' => 0, 'userCommissionInCents' => (int) ($pedido['valor'] ?? 0) ],
                'isTest' => false
            ];

            $serverUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://" . $_SERVER['HTTP_HOST'];
            $utmifyUrl = $serverUrl . dirname($_SERVER['SCRIPT_NAME']) . '/utmify.php';

            $ch = curl_init($utmifyUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($utmifyData, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false
            ]);
            curl_exec($ch); curl_close($ch);
            error_log("[Webhook Blackcat] 📡 UTMify atualizado para $transactionId");
        }
    } catch (Exception $e) {
        error_log("[Webhook Blackcat] ❌ Erro: " . $e->getMessage());
        http_response_code(500);
        exit;
    }
}

// Responde rápido com 200
http_response_code(200);
echo json_encode(['received' => true]);
