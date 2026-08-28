<?php
// Headers já definidos pelo roteador pagamento.php
// NÃO remova este comentário - evita redefinição de headers

// Configurações de erro (sem headers)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// ========== CONFIGURAÇÃO AXYRAPAY ==========
$AXYRA_BASE = 'https://axyrapay.com.br/api';
$AXYRA_TOKEN = 'd55371597f057d4bbef93fbff2bfe4f72947d808341b03bd912a76840784a76b'; // Substitua pelo seu token AxyraPay

// Função para gerar CPF válido (fallback)
function gerarCPF() {
    $cpf = '';
    for ($i = 0; $i < 9; $i++) { $cpf .= rand(0, 9); }
    $soma = 0;
    for ($i = 0; $i < 9; $i++) { $soma += intval($cpf[$i]) * (10 - $i); }
    $resto = $soma % 11;
    $cpf .= ($resto < 2) ? 0 : 11 - $resto;
    $soma = 0;
    for ($i = 0; $i < 10; $i++) { $soma += intval($cpf[$i]) * (11 - $i); }
    $resto = $soma % 11;
    $cpf .= ($resto < 2) ? 0 : 11 - $resto;

    $invalidos = ['00000000000', '11111111111', '22222222222', '33333333333', '44444444444', '55555555555', '66666666666', '77777777777', '88888888888', '99999999999'];
    if (in_array($cpf, $invalidos)) return gerarCPF();
    return $cpf;
}

// Função para gerar email dinâmico (fallback)
function gerarEmail($nome) {
    $dominios = ['@gmail.com', '@hotmail.com', '@yahoo.com.br', '@outlook.com'];
    $nomeEmail = preg_replace('/[^a-z0-9]/', '', str_replace(' ', '', strtolower($nome)));
    return $nomeEmail . rand(10, 9999) . $dominios[array_rand($dominios)];
}

// Captura o IP real do cliente (atrás de proxy/CDN)
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

    // Garante colunas novas em bancos antigos
    foreach (['telefone', 'client_ip', 'client_user_agent', 'fbp', 'fbc', 'metodo'] as $coluna) {
        try { $db->exec("ALTER TABLE pedidos ADD COLUMN $coluna TEXT"); } catch (Exception $e) {}
    }

    // ========== ENTRADA (aceita form-data e JSON) ==========
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];
    $req = array_merge($_GET, $_POST, $input);

    $valor_centavos = isset($req['valor']) ? intval($req['valor']) : 0;
    if (!$valor_centavos || $valor_centavos <= 0) throw new Exception('Valor inválido');
    $valor_reais = round($valor_centavos / 100, 2);

    $nome_cliente = !empty($req['nome']) ? trim($req['nome']) : null;
    $email        = !empty($req['email']) ? trim($req['email']) : null;
    $cpf          = !empty($req['cpf']) ? preg_replace('/[^0-9]/', '', $req['cpf']) : null;
    $telefone     = !empty($req['telefone']) ? preg_replace('/[^0-9]/', '', $req['telefone']) : null;
    $produto      = 'ebook estudos';
    
    $method       = 'PIX';

    // Parâmetros UTM
    $utmParams = array_filter([
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
    ], function ($v) { return $v !== null && $v !== ''; });

    // Dados extras para matching (Meta CAPI via Utmify)
    $fbp       = $req['fbp'] ?? null;
    $fbc       = $req['fbc'] ?? null;
    $userAgent = $req['userAgent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null);
    $clientIp  = getClientIP();

    // Fallback: gera dados do cliente se não enviados
    if (!$nome_cliente || !$email || !$cpf) {
        $nome_cliente = $nome_cliente ?: 'Cliente ' . rand(1000, 9999);
        $email = $email ?: gerarEmail($nome_cliente);
        $cpf = ($cpf && strlen($cpf) === 11) ? $cpf : gerarCPF();
    }
    if (strlen((string) $cpf) !== 11) $cpf = gerarCPF();
    if (!$telefone || strlen($telefone) < 10 || strlen($telefone) > 11) $telefone = '11999999999';

    // Determina a Webhook URL dinamicamente
    $serverUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://" . $_SERVER['HTTP_HOST'];
    $webhook_url = $serverUrl . dirname($_SERVER['SCRIPT_NAME']) . '/webhook-axyra.php';

    // ========== CHAMADA À AXYRAPAY ==========
    $payload = [
        'name'        => $nome_cliente,
        'amount'      => $valor_reais,
        'document'    => $cpf,
        'email'       => $email,
        'phone'       => $telefone,
        'description' => substr($produto, 0, 200),
        'webhook_url' => $webhook_url,
        'method'      => 'PIX'
    ];

    error_log("[AxyraPay] 📦 Criando pagamento ($method): " . json_encode($payload, JSON_UNESCAPED_UNICODE));

    $ch = curl_init($AXYRA_BASE . '/payment.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $AXYRA_TOKEN
        ]
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) throw new Exception('Falha ao contatar AxyraPay: ' . $curlError);

    error_log("[AxyraPay] 📊 HTTP $httpCode - Resposta: " . $response);

    $result = json_decode($response, true);
    if ($httpCode < 200 || $httpCode >= 300 || !is_array($result) || empty($result['ok'])) {
        $msg = is_array($result) ? ($result['error'] ?? $response) : $response;
        throw new Exception("Erro na API AxyraPay (HTTP $httpCode): " . $msg);
    }

    // Pega o transaction_id seguro da AxyraPay
    $payment_id = $result['transaction_id'] ?? null;
    if (!$payment_id) throw new Exception('ID da transação não encontrado na resposta');

    // ========== PERSISTÊNCIA LOCAL ==========
    $stmt = $db->prepare("INSERT OR REPLACE INTO pedidos
        (transaction_id, status, valor, nome, email, cpf, telefone, utm_params, client_ip, client_user_agent, fbp, fbc, metodo, created_at, updated_at)
        VALUES (:transaction_id, 'pending', :valor, :nome, :email, :cpf, :telefone, :utm_params, :client_ip, :client_user_agent, :fbp, :fbc, :metodo, :created_at, :updated_at)");
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
        'metodo'            => $method,
        'created_at'        => date('c'),
        'updated_at'        => date('c')
    ]);

    error_log("[AxyraPay] 💳 Transação criada: " . $payment_id);

    // ========== NOTIFICA UTMIFY (waiting_payment) ==========
    $utmifyData = [
        'orderId'       => (string) $payment_id,
        'platform'      => 'AxyraPay',
        'paymentMethod' => strtolower($method === 'CREDIT_CARD' ? 'credit_card' : $method),
        'status'        => 'waiting_payment',
        'createdAt'     => gmdate('Y-m-d H:i:s'),
        'approvedDate'  => null,
        'refundedAt'    => null,
        'customer'      => [
            'name'       => $nome_cliente,
            'email'      => $email,
            'phone'      => $telefone,
            'document'   => $cpf,
            'country'    => 'BR',
            'ip'         => $clientIp,
            'userAgent'  => $userAgent,
            'externalId' => (string) $payment_id,
            'fbp'        => $fbp,
            'fbc'        => $fbc
        ],
        'products'      => [
            [
                'id'           => uniqid('PROD_'),
                'name'         => $produto,
                'planId'       => null,
                'planName'     => null,
                'quantity'     => 1,
                'priceInCents' => $valor_centavos
            ]
        ],
        'trackingParameters' => $utmParams,
        'commission'    => [
            'totalPriceInCents'     => $valor_centavos,
            'gatewayFeeInCents'     => 0,
            'userCommissionInCents' => $valor_centavos
        ],
        'isTest' => false
    ];

    $utmifyUrl = $serverUrl . dirname($_SERVER['SCRIPT_NAME']) . '/utmify-pendente.php';
    $ch = curl_init($utmifyUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($utmifyData, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    $utmifyResponse = curl_exec($ch);
    $utmifyHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("[AxyraPay] 📡 Utmify pendente (HTTP $utmifyHttpCode)");

    // ========== RESPOSTA AO FRONTEND ==========
    $responseToFrontend = [
        'success'   => true,
        'token'     => (string) $payment_id, // transaction_id no frontend
        'method'    => $method,
        'valor'     => $valor_centavos
    ];

    $pixCode = $result['copy_paste'] ?? $result['pix_code'] ?? null;
    $responseToFrontend['pixCode'] = $pixCode;
    $responseToFrontend['qrCodeUrl'] = 'https://api.qrserver.com/v1/create-qr-code/?data=' . urlencode($pixCode) . '&size=300x300';

    echo json_encode($responseToFrontend);

} catch (Exception $e) {
    error_log("[AxyraPay] ❌ Erro: " . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'Erro ao processar pagamento: ' . $e->getMessage()
    ]);
}
