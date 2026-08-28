<?php
// Headers já definidos pelo roteador verificar.php
// NÃO remova este comentário - evita redefinição de headers

// ========== CONFIGURAÇÃO TRUST7 ==========
$TRUST7_BASE = 'https://trust7.dev/v1';
$TRUST7_KEY  = 'SUA_CHAVE_SECRETA_AQUI'; // Substitua pela sua Secret Key da Trust7

if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'ID não fornecido']);
    exit;
}

$id = preg_replace('/[^a-zA-Z0-9\-]/', '', trim($_GET['id']));
if ($id === '') {
    echo json_encode(['error' => 'ID inválido']);
    exit;
}

try {
    // Conecta ao SQLite (dados do pedido para Utmify)
    $dbPath = __DIR__ . '/database.sqlite';
    $db = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $db->prepare("SELECT * FROM pedidos WHERE transaction_id = :transaction_id");
    $stmt->execute(['transaction_id' => $id]);
    $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

    // ========== CONSULTA STATUS NA TRUST7 ==========
    $ch = curl_init($TRUST7_BASE . '/payments/' . rawurlencode($id));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: Bearer ' . $TRUST7_KEY
        ]
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $tx = ($response !== false) ? json_decode($response, true) : null;

    if ($httpCode >= 200 && $httpCode < 300 && is_array($tx)) {
        $statusRaw = strtolower((string) ($tx['status'] ?? ''));
        // Status do Trust7: pending_action, captured, expired, canceled, failed
        $isPaid = ($statusRaw === 'captured');
        $status = $isPaid ? 'paid' : 'pending';
    } elseif ($pedido) {
        // API indisponível: usa o último status conhecido no banco
        error_log("[Verificar Trust7] ⚠️ API indisponível (HTTP $httpCode). Usando status do banco.");
        $status = $pedido['status'];
        $isPaid = ($status === 'paid');
    } else {
        echo json_encode([
            'success' => false,
            'status'  => 'error',
            'message' => 'Pedido não encontrado'
        ]);
        exit;
    }

    // ========== RESPOSTA AO FRONTEND ==========
    echo json_encode([
        'success'        => true,
        'status'         => $status,
        'transaction_id' => $id,
        'data' => [
            'amount'     => $pedido['valor'] ?? null,
            'created_at' => $pedido['created_at'] ?? null,
            'updated_at' => $pedido['updated_at'] ?? null,
            'customer'   => [
                'name'     => $pedido['nome'] ?? null,
                'email'    => $pedido['email'] ?? null,
                'document' => $pedido['cpf'] ?? null
            ]
        ]
    ]);

    // Continua em background: marca como pago e notifica Utmify (uma única vez)
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    }

    if ($isPaid && $pedido && $pedido['status'] !== 'paid') {
        $update = $db->prepare("UPDATE pedidos SET status = 'paid', updated_at = :updated_at WHERE transaction_id = :transaction_id AND status != 'paid'");
        $update->execute([
            'updated_at'     => date('c'),
            'transaction_id' => $id
        ]);

        // Só notifica se esta requisição foi a que efetivou a mudança (evita duplicidade)
        if ($update->rowCount() > 0) {
            error_log("[Verificar Trust7] ✅ Pagamento aprovado: $id. Notificando Utmify...");

            $utmParams = json_decode($pedido['utm_params'] ?? '[]', true);
            if (!is_array($utmParams)) {
                $utmParams = [];
            }

            $utmifyData = [
                'orderId'       => (string) $id,
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
                    'externalId' => (string) $id,
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

            error_log("[Verificar Trust7] 📡 Utmify paid (HTTP $utmifyHttpCode): " . $utmifyResponse);
        }
    }

} catch (Exception $e) {
    error_log("[Verificar Trust7] ❌ Erro: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'status'  => 'error',
        'message' => 'Erro ao verificar o status do pagamento'
    ]);
}
