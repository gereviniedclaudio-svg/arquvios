<?php
// Headers já definidos pelo roteador verificar.php
// NÃO remova este comentário - evita redefinição de headers

// ========== CONFIGURAÇÃO AXYRAPAY ==========
$AXYRA_BASE = 'https://axyrapay.com.br/api';
$AXYRA_TOKEN = 'd55371597f057d4bbef93fbff2bfe4f72947d808341b03bd912a76840784a76b'; // Substitua pelo seu token AxyraPay

if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'ID não fornecido']);
    exit;
}

$id = preg_replace('/[^a-zA-Z0-9\-_]/', '', trim($_GET['id']));
if ($id === '') {
    echo json_encode(['error' => 'ID inválido']);
    exit;
}

try {
    // Conecta ao SQLite
    $dbPath = __DIR__ . '/database.sqlite';
    $db = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $db->prepare("SELECT * FROM pedidos WHERE transaction_id = :transaction_id");
    $stmt->execute(['transaction_id' => $id]);
    $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

    // ========== CONSULTA STATUS NA AXYRAPAY ==========
    $ch = curl_init($AXYRA_BASE . '/status.php?transaction_id=' . rawurlencode($id));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: Bearer ' . $AXYRA_TOKEN
        ]
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $tx = ($response !== false) ? json_decode($response, true) : null;

    if ($httpCode >= 200 && $httpCode < 300 && is_array($tx) && !empty($tx['ok'])) {
        $statusRaw = strtolower((string) ($tx['status'] ?? ''));
        $isPaid = ($statusRaw === 'confirmed');
        $status = $isPaid ? 'paid' : $statusRaw;
    } elseif ($pedido) {
        // API indisponível ou erro: usa o último status conhecido no banco
        error_log("[Verificar Axyra] ⚠️ API indisponível ou erro (HTTP $httpCode). Usando status do banco.");
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

        if ($update->rowCount() > 0) {
            error_log("[Verificar Axyra] ✅ Pagamento aprovado: $id. Notificando Utmify...");

            $utmParams = json_decode($pedido['utm_params'] ?? '[]', true);
            if (!is_array($utmParams)) $utmParams = [];

            $utmifyData = [
                'orderId'       => (string) $id,
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

            error_log("[Verificar Axyra] 📡 Utmify paid (HTTP $utmifyHttpCode): " . $utmifyResponse);
        }
    }

} catch (Exception $e) {
    error_log("[Verificar Axyra] ❌ Erro: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'status'  => 'error',
        'message' => 'Erro ao verificar o status do pagamento'
    ]);
}
