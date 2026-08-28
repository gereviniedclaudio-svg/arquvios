/**
 * Configuração de Webhook para BilionPay + UTMfy
 * Este arquivo deve ser incluído nas páginas de pagamento
 */

// Configuração do webhook
const WEBHOOK_CONFIG = {
    bilionpay: {
        webhookUrl: window.SITE_CONFIG?.INTEGRATION?.webhook_url || `${window.location.origin}/webhook/utmfy.php`,
        // Webhook da BilionPay é configurado no postbackUrl durante a criação da transação
    },
    utmfy: {
        pixelId: window.SITE_CONFIG?.INTEGRATION?.utmfy_pixel_id || '68ff9c2d3a80c51fc0d5e309',
        enabled: true
    }
};

/**
 * Nota: Webhook da BilionPay é configurado automaticamente via postbackUrl
 * durante a criação da transação, então não precisa ser configurado separadamente
 */
function configureBilionPayWebhook(transactionId) {
    // O webhook já é configurado no postbackUrl durante createPixTransaction
    console.log('ℹ️ Webhook da BilionPay configurado via postbackUrl na criação da transação:', transactionId);
}

/**
 * Enviar evento de conversão para UTMfy (fallback)
 */
function sendUtmfyConversion(eventType, transactionData) {
    if (!WEBHOOK_CONFIG.utmfy.enabled) {
        console.log('⚠️ UTMfy desabilitado');
        return;
    }

    try {
        const conversionData = {
            pixel_id: WEBHOOK_CONFIG.utmfy.pixelId,
            event_type: eventType,
            transaction_id: transactionData.transaction_id,
            value: transactionData.value || 98.00,
            currency: 'BRL',
            product_name: 'Taxa de Inscricao SES TO 2026 - Edital 001/2026',
            product_id: 'SESTO26_TAXA',
            quantity: 1,
            timestamp: new Date().toISOString(),
            utm_source: new URLSearchParams(window.location.search).get('utm_source') || 'direct',
            utm_medium: new URLSearchParams(window.location.search).get('utm_medium') || 'none',
            utm_campaign: new URLSearchParams(window.location.search).get('utm_campaign') || 'organic'
        };

        console.log('📊 Enviando conversão para UTMfy:', conversionData);

        // Salvar dados localmente para debug
        localStorage.setItem('utmfy_conversion_' + conversionData.transaction_id, JSON.stringify(conversionData));

        // Método 1: Usar gtag se disponível (Google Analytics / UTMfy Pixel)
        if (typeof gtag !== 'undefined' && typeof window.googlePixelId !== 'undefined') {
            try {
                gtag('event', eventType, {
                    'send_to': window.googlePixelId,
                    'value': conversionData.value,
                    'currency': conversionData.currency,
                    'transaction_id': conversionData.transaction_id
                });
                console.log('✅ Conversão enviada via gtag para UTMfy');
            } catch (error) {
                console.warn('⚠️ Erro ao enviar via gtag:', error);
            }
        }

        // Método 2: Enviar via fetch (mais confiável)
        const trackingUrl = `https://track.utmfy.com.br/conversion`;
        
        const payload = {
            pixel_id: conversionData.pixel_id,
            event: conversionData.event_type,
            value: conversionData.value,
            transaction_id: conversionData.transaction_id,
            currency: conversionData.currency,
            product_name: conversionData.product_name,
            product_id: conversionData.product_id,
            quantity: conversionData.quantity,
            timestamp: conversionData.timestamp
        };

        fetch(trackingUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(payload),
            keepalive: true
        })
        .then(response => {
            if (response.ok) {
                console.log('✅ Conversão enviada para UTMfy via fetch');
            } else {
                console.warn(`⚠️ UTMfy retornou status ${response.status}`);
                // Fallback para Image pixel
                const imgUrl = `${trackingUrl}?pixel_id=${conversionData.pixel_id}&event=${conversionData.event_type}&value=${conversionData.value}&transaction_id=${conversionData.transaction_id}`;
                const img = new Image();
                img.src = imgUrl;
            }
        })
        .catch(error => {
            console.warn('⚠️ Erro ao enviar via fetch, tentando método alternativo:', error);
            // Método 3: Fallback para Image pixel tracking
            const imgUrl = `${trackingUrl}?pixel_id=${conversionData.pixel_id}&event=${conversionData.event_type}&value=${conversionData.value}&transaction_id=${conversionData.transaction_id}`;
            const img = new Image();
            img.src = imgUrl;
            img.onload = () => {
                console.log('✅ Conversão enviada para UTMfy via pixel (fallback)');
            };
            img.onerror = () => {
                console.warn('⚠️ Falha ao enviar conversão para UTMfy');
            };
        });

    } catch (error) {
        console.error('❌ Erro ao enviar conversão para UTMfy:', error);
    }
}

/**
 * Integrar com o sistema de pagamento existente
 */
function integrateWithPaymentSystem() {
    // Interceptar eventos de pagamento bem-sucedido
    const originalSendTrackingEvents = window.sendTrackingEvents;
    
    if (typeof originalSendTrackingEvents === 'function') {
        window.sendTrackingEvents = function(eventName, transactionId, amount) {
            // Chamar função original
            originalSendTrackingEvents.call(this, eventName, transactionId, amount);
            
            // Nota: Webhook da BilionPay já está configurado via postbackUrl
            if (transactionId) {
                configureBilionPayWebhook(transactionId);
                
                // Enviar conversão para UTMfy (fallback)
                sendUtmfyConversion(eventName, {
                    transaction_id: transactionId,
                    value: amount / 100
                });
            }
        };
    }

    console.log('🔗 Integração BilionPay + UTMfy configurada');
}

// Inicializar integração quando a página carregar
document.addEventListener('DOMContentLoaded', function() {
    integrateWithPaymentSystem();
});

// Exportar funções para uso global
window.configureBilionPayWebhook = configureBilionPayWebhook;
window.sendUtmfyConversion = sendUtmfyConversion;
