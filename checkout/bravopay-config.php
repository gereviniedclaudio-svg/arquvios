<?php
/**
 * CONFIGURAÇÃO CENTRAL DA BRAVOPAY
 *
 * Fonte única da URL base e da chave de API usada por:
 *   - pagamento-bravopay.php  (cria a cobrança PIX)
 *   - verificar-bravopay.php  (consulta o status)
 *
 * A chave é lida, nesta ordem:
 *   1) Variável de ambiente "api"  (recomendado em produção / TurboCloud)
 *   2) Chave padrão abaixo (fallback para hospedagens sem variável de ambiente)
 *
 * Para trocar a chave em produção, defina a variável de ambiente "api"
 * (ex.: api=bp_live_xxx) OU edite a constante BRAVOPAY_FALLBACK_KEY abaixo.
 */

// URL base da API BravoPay
if (!defined('BRAVOPAY_API_URL')) {
    define('BRAVOPAY_API_URL', 'https://bravopay.club/api/v1');
}

// Chave padrão (usada apenas se a variável de ambiente "api" não existir)
if (!defined('BRAVOPAY_FALLBACK_KEY')) {
    define('BRAVOPAY_FALLBACK_KEY', 'bp_live_DHwoqsFdPXbhuTXqONUM0VzwJPbNIUyc8GtjvQ');
}

// Resolve a chave de API ativa
if (!defined('BRAVOPAY_API_KEY')) {
    $__bravopayKey = getenv('api') ?: ($_ENV['api'] ?? ($_SERVER['api'] ?? ''));
    if (empty($__bravopayKey)) {
        $__bravopayKey = BRAVOPAY_FALLBACK_KEY;
    }
    define('BRAVOPAY_API_KEY', trim($__bravopayKey));
}
