<?php
/**
 * Arquivo de configuração para integração MedusaPay + UTMfy
 * 
 * INSTRUÇÕES:
 * 1. Crie uma credencial de API na UTMfy
 * 2. Substitua SUA_CHAVE_API_UTMFY_AQUI pela chave que você receber
 * 3. Configure o webhook na MedusaPay para apontar para este arquivo
 */

return [
    // Configurações da UTMfy
    'utmfy' => [
        'api_key' => 'X2WJKFRnpDV8KREIG46A0dMPy498hBOrDCwF', // Substitua pela sua chave da UTMfy
        'api_url' => 'https://api.utmfy.com.br/v1/conversions',
        'pixel_id' => '68fafce3e95a8ad5bfc8863d',
        'enabled' => true
    ],
    
    // Configurações da MedusaPay
    'medusapay' => [
        'webhook_url' => '/webhook/utmfy.php',
        'events' => ['transaction.approved', 'transaction.paid'],
        'enabled' => true
    ],
    
    // Configurações do produto
    'product' => [
        'name' => 'Taxa de Inscricao SES TO 2026 - Edital 001/2026',
        'id' => 'SESTO26_TAXA',
        'price' => 85.00,
        'currency' => 'BRL'
    ],
    
    // Configurações de debug
    'debug' => [
        'enabled' => true,
        'log_file' => 'webhook_log.txt',
        'log_level' => 'info' // debug, info, warning, error
    ]
];
?>
