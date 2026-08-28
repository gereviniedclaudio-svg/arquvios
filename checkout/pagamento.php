<?php
/**
 * ROTEADOR DE PAGAMENTO
 * 
 * Este arquivo redireciona automaticamente para o gateway configurado
 * em payment-config.php
 */

// Define headers antes de qualquer output
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Habilita log de erros
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

try {
    error_log("[Router] 🔧 Iniciando carregamento de configuração...");
    require_once __DIR__ . '/payment-config.php';
    error_log("[Router] ✅ Configuração carregada com sucesso");
    
    error_log("[Router] 🔄 Redirecionando para gateway: " . getActiveGateway());
    error_log("[Router] 📄 Arquivo de destino: " . $PAYMENT_FILE);
    
    // Verifica se o arquivo do gateway existe
    $fullPath = __DIR__ . '/' . $PAYMENT_FILE;
    error_log("[Router] 🔍 Verificando arquivo: " . $fullPath);
    
    if (!file_exists($fullPath)) {
        error_log("[Router] ❌ ERRO: Arquivo do gateway não encontrado: " . $fullPath);
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Gateway de pagamento não encontrado. Contate o suporte.'
        ]);
        exit;
    }
    
    error_log("[Router] ✅ Arquivo existe, iniciando require...");
    
    // Redireciona todos os dados (POST, GET, FILES, etc.) para o gateway configurado
    require $fullPath;
    
    error_log("[Router] ✅ Gateway executado com sucesso");
    
} catch (Throwable $e) {
    error_log("[Router] ❌ ERRO FATAL: " . $e->getMessage());
    error_log("[Router] 📍 Arquivo: " . $e->getFile() . " Linha: " . $e->getLine());
    error_log("[Router] 🔍 Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao processar pagamento: ' . $e->getMessage()
    ]);
}
