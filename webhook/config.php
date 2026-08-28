<?php
/**
 * Arquivo de configuração para integração BravoPay + UTMfy
 * 
 * INSTRUÇÕES:
 * 1. Crie uma credencial de API na UTMfy
 * 2. Substitua a chave em 'api_key' pela sua chave da UTMfy
 * 3. Configure o webhook na BravoPay (dashboard/integracoes/webhooks) apontando para /webhook/utmfy.php
 */

return [
    // Configurações da UTMfy
    'utmfy' => [
        'api_key' => 'X2WJKFRnpDV8KREIG46A0dMPy498hBOrDCwF', // Substitua pela sua chave da UTMfy
        'api_url' => 'https://api.utmfy.com.br/v1/conversions',
        'pixel_id' => '68fafce3e95a8ad5bfc8863d',
        'enabled' => true
    ],
    
    // Configurações da BravoPay
    'bravopay' => [
        'webhook_url' => '/webhook/utmfy.php',
        'events' => ['transaction.created', 'transaction.paid', 'transaction.refunded', 'transaction.chargeback'],
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
