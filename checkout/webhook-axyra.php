<?php
// ========== WEBHOOK AXYRAPAY ==========
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

// Recebe o payload do webhook
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload invalido']);
    exit;
}

error_log("[Webhook Axyra] 🔔 Recebido: " . $input);

$event = $data['event'] ?? '';
$status = $data['status'] ?? '';
$transaction_id = $data['transaction_id'] ?? null;

// Verifica se a transação existe e se o status é 'confirmed'
if ($event === 'payment.confirmed' && $status === 'confirmed' && $transaction_id) {
    try {
        $dbPath = __DIR__ . '/database.sqlite';
        $db = new PDO("sqlite:$dbPath");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $db->prepare("SELECT * FROM pedidos WHERE transaction_id = :transaction_id");
        $stmt->execute(['transaction_id' => $transaction_id]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pedido && $pedido['status'] !== 'paid') {
            $update = $db->prepare("UPDATE pedidos SET status = 'paid', updated_at = :updated_at WHERE transaction_id = :transaction_id AND status != 'paid'");
            $update->execute([
                'updated_at'     => date('c'),
                'transaction_id' => $transaction_id
            ]);

            if ($update->rowCount() > 0) {
                error_log("[Webhook Axyra] ✅ Pagamento aprovado via webhook: $transaction_id. Notificando Utmify...");

                $utmParams = json_decode($pedido['utm_params'] ?? '[]', true);
                if (!is_array($utmParams)) $utmParams = [];

                $utmifyData = [
                    'orderId'       => (string) $transaction_id,
                    'platform'      => 'AxyraPay',
                    'paymentMethod' => strtolower($pedido['metodo'] ?? 'pix'),
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
                        'externalId' => (string) $transaction_id,
                        'fbp'        => $pedido['fbp'] ?? null,
                        'fbc'        => $pedido['fbc'] ?? null
                    ],
                    'products'      => [
                        [
                            'id'           => uniqid('PROD_'),
                            'name'         => 'Produto ' . (($pedido['valor'] ?? 0) / 100),
                            'planId'       => null,
                            'planName'     => null,
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
                $utmifyHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                error_log("[Webhook Axyra] 📡 Utmify paid (HTTP $utmifyHttpCode): " . $utmifyResponse);
            }
        }
    } catch (Exception $e) {
        error_log("[Webhook Axyra] ❌ Erro: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro interno']);
        exit;
    }
}

// Retorna 200 sempre, mesmo que o status já fosse paid (para avisar o gateway que recebemos)
http_response_code(200);
echo json_encode(['ok' => true]);
