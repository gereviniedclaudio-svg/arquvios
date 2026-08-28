<?php
/**
 * ROTEADOR DE VERIFICAÇÃO DE PAGAMENTO
 * 
 * Este arquivo redireciona automaticamente para o verificador correto
 * baseado no gateway configurado em payment-config.php
 */

// Define headers antes de qualquer output
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Habilita log de erros
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

try {
    // Carrega configuração do gateway
    require_once __DIR__ . '/payment-config.php';
    
    error_log("[Verificar Router] 🔄 Redirecionando verificação para gateway: " . getActiveGateway());
    
    // Mapeia o gateway para o arquivo de verificação correto
    $VERIFICATION_FILES = [
        'bravopay' => 'verificar-bravopay.php'
    ];
    
    // Define o arquivo do verificador ativo (fallback: bravopay)
    $VERIFICATION_FILE = $VERIFICATION_FILES[$ACTIVE_GATEWAY] ?? $VERIFICATION_FILES['bravopay'];
    
    error_log("[Verificar Router] 📄 Arquivo de verificação: " . $VERIFICATION_FILE);
    
    // Verifica se o arquivo do verificador existe
    if (!file_exists(__DIR__ . '/' . $VERIFICATION_FILE)) {
        error_log("[Verificar Router] ❌ ERRO: Arquivo verificador não encontrado: " . $VERIFICATION_FILE);
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'status' => 'error',
            'message' => 'Verificador de pagamento não encontrado. Contate o suporte.'
        ]);
        exit;
    }
    
    // Redireciona todos os dados (GET, POST, etc.) para o verificador configurado
    require __DIR__ . '/' . $VERIFICATION_FILE;
    
} catch (Exception $e) {
    error_log("[Verificar Router] ❌ ERRO FATAL: " . $e->getMessage());
    error_log("[Verificar Router] 🔍 Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'message' => 'Erro ao verificar pagamento: ' . $e->getMessage()
    ]);
}
