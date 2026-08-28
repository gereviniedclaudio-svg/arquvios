<?php
/**
 * CONFIGURAÇÃO DE GATEWAY DE PAGAMENTO
 * 
 * Gateway ativo: Medusa Pay (definido em gateway-config.json)
 */

// ========== CARREGA CONFIGURAÇÃO DO JSON ==========
$configFile = __DIR__ . '/gateway-config.json';

// Verifica se o arquivo de configuração existe
if (!file_exists($configFile)) {
    // Cria configuração padrão se não existir
    $defaultConfig = [
        'active_gateway' => 'medusa'
    ];
    file_put_contents($configFile, json_encode($defaultConfig, JSON_PRETTY_PRINT));
}

// Lê a configuração do arquivo JSON
$config = json_decode(file_get_contents($configFile), true);

if (!$config || (!isset($config['active_gateway']) && !isset($config['gateway']))) {
    error_log("[Config] ❌ ERRO: Arquivo de configuração inválido!");
    
    // Define headers se ainda não foram definidos
    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code(500);
    }
    
    die(json_encode([
        'success' => false,
        'message' => 'Configuração de gateway inválida. Contate o suporte.'
    ]));
}

// Determina qual gateway está ativo (suporta ambas as chaves)
$ACTIVE_GATEWAY = $config['active_gateway'] ?? $config['gateway'] ?? 'bravopay';

// Mapeia o gateway para o arquivo PHP correto
$GATEWAY_FILES = [
    'bravopay' => 'pagamento-bravopay.php'
];

// Define o arquivo do gateway ativo (fallback: bravopay)
$PAYMENT_FILE = $GATEWAY_FILES[$ACTIVE_GATEWAY] ?? $GATEWAY_FILES['bravopay'];

// Log da configuração
error_log("[Config] ✅ Gateway ativo: " . strtoupper($ACTIVE_GATEWAY));
error_log("[Config] 📄 Arquivo: " . $PAYMENT_FILE);

/**
 * Função para obter a URL do gateway de pagamento
 */
function getPaymentUrl() {
    global $PAYMENT_FILE;
    return './' . $PAYMENT_FILE;
}

/**
 * Função para obter o nome do gateway ativo
 */
function getActiveGateway() {
    global $ACTIVE_GATEWAY;
    return $ACTIVE_GATEWAY;
}

/**
 * Retorna configuração em JSON (para ser usado via AJAX)
 */
if (isset($_GET['json'])) {
    // Headers já definidos pelo roteador, não redefinir
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode([
        'success' => true,
        'gateway' => $ACTIVE_GATEWAY,
        'paymentUrl' => './' . $PAYMENT_FILE
    ]);
    exit;
}
