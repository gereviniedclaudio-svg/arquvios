<?php
// Headers já definidos pelo roteador pagamento.php

// Configurações de erro
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// ========== CONFIGURAÇÃO BRAVOPAY ==========
$BRAVOPAY_API_URL = 'https://bravopay.club/api/v1';
// Chave lida da variável de ambiente "api" (Bearer bp_live_...)
$BRAVOPAY_API_KEY = getenv('api') ?: ($_ENV['api'] ?? ($_SERVER['api'] ?? ''));

if (empty($BRAVOPAY_API_KEY)) {
    error_log("[BravoPay] ❌ Variável de ambiente 'api' não configurada");
    echo json_encode(['success' => false, 'message' => 'Gateway não configurado. Contate o suporte.']);
    exit;
}

// Função para gerar CPF válido (fallback)
function gerarCPF() {
    $cpf = '';
    for ($i = 0; $i < 9; $i++) $cpf .= rand(0, 9);
    $soma = 0;
    for ($i = 0; $i < 9; $i++) $soma += intval($cpf[$i]) * (10 - $i);
    $resto = $soma % 11;
    $cpf .= ($resto < 2) ? 0 : 11 - $resto;
    $soma = 0;
    for ($i = 0; $i < 10; $i++) $soma += intval($cpf[$i]) * (11 - $i);
    $resto = $soma % 11;
    $cpf .= ($resto < 2) ? 0 : 11 - $resto;
    return $cpf;
}

function gerarEmail($nome) {
    $dominios = ['@gmail.com', '@hotmail.com', '@yahoo.com.br', '@outlook.com'];
    $nomeEmail = strtolower(preg_replace('/[^a-z0-9]/i', '', $nome));
    return $nomeEmail . rand(10, 9999) . $dominios[array_rand($dominios)];
}

function getClientIP() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = trim(explode(',', $_SERVER[$header])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

try {
    // Conecta ao SQLite
    $dbPath = __DIR__ . '/database.sqlite';
    $db = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("CREATE TABLE IF NOT EXISTS pedidos (
        transaction_id TEXT PRIMARY KEY,
        status TEXT NOT NULL,
        valor INTEGER NOT NULL,
        nome TEXT,
        email TEXT,
        cpf TEXT,
        telefone TEXT,
        utm_params TEXT,
        client_ip TEXT,
        client_user_agent TEXT,
        fbp TEXT,
        fbc TEXT,
        metodo TEXT,
        created_at TEXT,
        updated_at TEXT
    )");

    foreach (['telefone', 'client_ip', 'client_user_agent', 'fbp', 'fbc', 'metodo'] as $coluna) {
        try { $db->exec("ALTER TABLE pedidos ADD COLUMN $coluna TEXT"); } catch (Exception $e) {}
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $req = array_merge($_GET, $_POST, $input);

    $valor_centavos = isset($req['valor']) ? intval($req['valor']) : 0;
    if (!$valor_centavos || $valor_centavos <= 0) throw new Exception('Valor inválido');
    // BravoPay exige mínimo de R$ 5,00 (500 centavos)
    if ($valor_centavos < 500) $valor_centavos = 500;

    $nome_cliente = !empty($req['nome']) ? trim($req['nome']) : null;
    $email        = !empty($req['email']) ? trim($req['email']) : null;
    $cpf          = !empty($req['cpf']) ? preg_replace('/[^0-9]/', '', $req['cpf']) : null;
    $telefone     = !empty($req['telefone']) ? preg_replace('/[^0-9]/', '', $req['telefone']) : null;

    $utmParams = [
        'utm_source'   => $req['utm_source'] ?? null,
        'utm_medium'   => $req['utm_medium'] ?? null,
        'utm_campaign' => $req['utm_campaign'] ?? null,
        'utm_content'  => $req['utm_content'] ?? null,
        'utm_term'     => $req['utm_term'] ?? null,
        'xcod'         => $req['xcod'] ?? null,
        'sck'          => $req['sck'] ?? null,
        'src'          => $req['src'] ?? null,
        'fbclid'       => $req['fbclid'] ?? null,
        'gclid'        => $req['gclid'] ?? null,
        'ttclid'       => $req['ttclid'] ?? null
    ];
    $utmParams = array_filter($utmParams, fn($v) => $v !== null && $v !== '');

    $fbp       = $req['fbp'] ?? null;
    $fbc       = $req['fbc'] ?? null;
    $userAgent = $req['userAgent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null);
    $clientIp  = getClientIP();

    if (!$nome_cliente || !$email || !$cpf) {
        $nome_cliente = $nome_cliente ?: 'Cliente ' . rand(1000, 9999);
        $email = $email ?: gerarEmail($nome_cliente);
        $cpf = ($cpf && strlen($cpf) === 11) ? $cpf : gerarCPF();
    }
    if (strlen((string) $cpf) !== 11 && strlen((string) $cpf) !== 14) $cpf = gerarCPF();
    if (!$telefone || strlen($telefone) < 10) $telefone = '11999999999';

    $local_order_id = uniqid('order_');
    $product_title = $req['product_title'] ?? 'Inscrição SES TO 2026';

    // Nome genérico fixo enviado ao gateway (não envia o nome real do cliente)
    $nome_gateway = 'Cliente';

    // Payload BravoPay
    $payload = [
        'amount_cents'       => $valor_centavos,
        'method'             => 'pix',
        'customer'           => [
            'name'  => $nome_gateway,
            'email' => $email,
            'cpf'   => $cpf,
            'phone' => $telefone
        ],
        'description'        => $product_title,
        'external_reference' => $local_order_id,
        'expires_in'         => 3600
    ];

    // UTMs para rastreamento/UTMify (obrigatório enviar na API)
    $utm = [];
    if (isset($utmParams['utm_source']))   $utm['source']   = $utmParams['utm_source'];
    if (isset($utmParams['utm_medium']))   $utm['medium']   = $utmParams['utm_medium'];
    if (isset($utmParams['utm_campaign'])) $utm['campaign'] = $utmParams['utm_campaign'];
    if (isset($utmParams['utm_content']))  $utm['content']  = $utmParams['utm_content'];
    if (isset($utmParams['utm_term']))     $utm['term']     = $utmParams['utm_term'];
    if (isset($utmParams['fbclid']))       $utm['fbclid']   = $utmParams['fbclid'];
    if (isset($utmParams['gclid']))        $utm['gclid']    = $utmParams['gclid'];
    if (isset($utmParams['ttclid']))       $utm['ttclid']   = $utmParams['ttclid'];
    if (!empty($utm)) $payload['utm'] = $utm;

    error_log("[BravoPay] 📦 Criando pagamento PIX: " . json_encode($payload, JSON_UNESCAPED_UNICODE));

    $ch = curl_init($BRAVOPAY_API_URL . '/transactions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $BRAVOPAY_API_KEY,
            'Idempotency-Key: ' . $local_order_id
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) throw new Exception('Falha ao contatar BravoPay API: ' . $curlError);

    error_log("[BravoPay] 📊 HTTP $httpCode - Resposta: " . $response);

    $result = json_decode($response, true);

    if ($httpCode < 200 || $httpCode >= 300 || empty($result['id'])) {
        $msg = $result['error']['message'] ?? ($result['message'] ?? $response);
        throw new Exception("Erro na API BravoPay (HTTP $httpCode): " . $msg);
    }

    $payment_id = $result['id'] ?? null;
    $pixCode    = $result['pix']['copy_paste'] ?? null;
    $qrCodeUrl  = 'https://api.qrserver.com/v1/create-qr-code/?data=' . urlencode($pixCode) . '&size=300x300';

    if (!$payment_id || !$pixCode) {
        throw new Exception('PIX não foi gerado pela BravoPay.');
    }

    // Persistência Local
    $stmt = $db->prepare("INSERT OR REPLACE INTO pedidos (transaction_id, status, valor, nome, email, cpf, telefone, utm_params, client_ip, client_user_agent, fbp, fbc, metodo, created_at, updated_at) VALUES (:transaction_id, 'pending', :valor, :nome, :email, :cpf, :telefone, :utm_params, :client_ip, :client_user_agent, :fbp, :fbc, 'PIX', :created_at, :updated_at)");
    $stmt->execute([
        'transaction_id'    => $payment_id,
        'valor'             => $valor_centavos,
        'nome'              => $nome_cliente,
        'email'             => $email,
        'cpf'               => $cpf,
        'telefone'          => $telefone,
        'utm_params'        => json_encode($utmParams, JSON_UNESCAPED_UNICODE),
        'client_ip'         => $clientIp,
        'client_user_agent' => $userAgent,
        'fbp'               => $fbp,
        'fbc'               => $fbc,
        'created_at'        => date('c'),
        'updated_at'        => date('c')
    ]);

    // Notifica UTMIFY pendente
    $utmifyData = [
        'orderId' => (string) $payment_id,
        'platform' => 'BravoPay',
        'paymentMethod' => 'pix',
        'status' => 'waiting_payment',
        'createdAt' => gmdate('Y-m-d H:i:s'),
        'customer' => [
            'name' => $nome_cliente, 'email' => $email, 'phone' => $telefone, 'document' => $cpf,
            'country' => 'BR', 'ip' => $clientIp, 'userAgent' => $userAgent, 'externalId' => (string)$payment_id,
            'fbp' => $fbp, 'fbc' => $fbc
        ],
        'products' => [[ 'id' => uniqid('PROD_'), 'name' => $product_title, 'quantity' => 1, 'priceInCents' => $valor_centavos ]],
        'trackingParameters' => $utmParams,
        'commission' => [ 'totalPriceInCents' => $valor_centavos, 'gatewayFeeInCents' => 0, 'userCommissionInCents' => $valor_centavos ],
        'isTest' => false
    ];

    $serverUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://" . $_SERVER['HTTP_HOST'];
    $utmifyUrl = $serverUrl . dirname($_SERVER['SCRIPT_NAME']) . '/utmify-pendente.php';
    $ch = curl_init($utmifyUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($utmifyData, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false
    ]);
    curl_exec($ch); curl_close($ch);

    // Resposta Frontend
    echo json_encode([
        'success'   => true,
        'token'     => (string) $payment_id,
        'pixCode'   => $pixCode,
        'qrCodeUrl' => $qrCodeUrl,
        'valor'     => $valor_centavos
    ]);

} catch (Exception $e) {
    error_log("[BravoPay] ❌ Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro ao gerar o PIX: ' . $e->getMessage()]);
}
