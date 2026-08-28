/**
 * 🔧 Configuração Individual do Site - Concurso SES TO 2026
 * Sistema de inscrição com gateway MedusaPay
 */

// ⚠️ CONFIGURAR PARA CADA SITE INDIVIDUALMENTE
window.SITE_CONFIG = {
  // 💳 PIX via /checkout/pagamento.php (chave só no PHP Medusa)
  CHECKOUT: {
    createUrl: '/checkout/pagamento.php',
    statusUrl: '/checkout/verificar.php'
  },
  
  // 🔗 Integração MedusaPay + UTMfy
  INTEGRATION: {
    webhook_enabled: true,
    webhook_url: '/webhook/utmfy.php',
    utmfy_pixel_id: '68fafce3e95a8ad5bfc8863d',
    fallback_tracking: true
  },
  
  // 🎭 Produto
  PRODUCT: {
    name: 'Taxa de Inscricao SES TO 2026 - Edital 001/2026',
    category: 'taxa_inscricao',
    vendor: 'SES TO 2026',
    value: 150.00,
    value_cents: 15000, // Valor em centavos para MedusaPay (superior; medio = 10000 via resolveTaxaSesTo)
    currency: 'BRL',
    product_hash: 'SESTO26_TAXA',
    offer_hash: 'SESTO26_OFFER'
  }
};

// 📊 Função auxiliar para aguardar pixel carregar
function waitForPixel(callback, maxAttempts = 10) {
  let attempts = 0;
  const checkPixel = () => {
    attempts++;
    if (typeof window.googlePixelId !== 'undefined' && (typeof gtag !== 'undefined' || typeof window.dataLayer !== 'undefined')) {
      callback();
    } else if (attempts < maxAttempts) {
      setTimeout(checkPixel, 500);
    } else {
      console.warn('⚠️ Pixel não carregado após ' + (maxAttempts * 500) + 'ms, enviando mesmo assim');
      callback();
    }
  };
  checkPixel();
}

// 📊 Função para enviar conversão (executada no frontend)
window.trackConversion = function(eventType, data) {
  if (!window.SITE_CONFIG.INTEGRATION.fallback_tracking) {
    console.log('⚠️ Tracking desabilitado para este site');
    return;
  }
  
  // Dados da conversão
  const conversionData = {
    pixel_id: window.SITE_CONFIG.INTEGRATION.utmfy_pixel_id,
    value: data.value || window.SITE_CONFIG.PRODUCT.value,
    currency: window.SITE_CONFIG.PRODUCT.currency,
    transaction_id: data.transaction_id || data.transaction_hash,
    items: [{
      id: data.transaction_id || 'SESTO26_' + Date.now(),
      name: window.SITE_CONFIG.PRODUCT.name,
      category: window.SITE_CONFIG.PRODUCT.category,
      quantity: 1,
      price: data.value || window.SITE_CONFIG.PRODUCT.value
    }]
  };
  
  console.log('📊 Enviando conversão para UTMfy:', {
    event: eventType,
    pixel_id: conversionData.pixel_id,
    product: window.SITE_CONFIG.PRODUCT.name,
    value: conversionData.value,
    transaction_id: conversionData.transaction_id,
    googlePixelId: window.googlePixelId,
    gtag_available: typeof gtag !== 'undefined',
    dataLayer_available: typeof window.dataLayer !== 'undefined'
  });
  
  // Aguardar pixel carregar antes de enviar (máximo 5 segundos)
  waitForPixel(() => {
    // Método 1: Usar gtag se disponível (Google Analytics / UTMfy Pixel)
    if (typeof gtag !== 'undefined' && typeof window.googlePixelId !== 'undefined') {
      try {
        // Mapear eventos para formato do Google Analytics
        const gaEventName = eventType === 'InitiateCheckout' ? 'begin_checkout' : 
                           eventType === 'Purchase' ? 'purchase' : 
                           eventType.toLowerCase();
        
        gtag('event', gaEventName, {
          'send_to': window.googlePixelId,
          'value': conversionData.value,
          'currency': conversionData.currency,
          'transaction_id': conversionData.transaction_id,
          'items': conversionData.items
        });
        console.log(`✅ Conversão ${eventType} (${gaEventName}) enviada via gtag para UTMfy`);
      } catch (error) {
        console.warn('⚠️ Erro ao enviar via gtag:', error);
      }
    }
    
    // Método 1.5: Tentar usar dataLayer do Google Tag Manager se disponível
    if (typeof window.dataLayer !== 'undefined') {
      try {
        window.dataLayer.push({
          'event': eventType,
          'ecommerce': {
            'transaction_id': conversionData.transaction_id,
            'value': conversionData.value,
            'currency': conversionData.currency,
            'items': conversionData.items
          }
        });
        console.log(`✅ Conversão ${eventType} enviada via dataLayer para UTMfy`);
      } catch (error) {
        console.warn('⚠️ Erro ao enviar via dataLayer:', error);
      }
    }
    
    // Método 2: Enviar via fetch (mais confiável)
    try {
    const pixelId = window.SITE_CONFIG.INTEGRATION.utmfy_pixel_id;
    const trackingUrl = `https://track.utmfy.com.br/conversion`;
    
    const payload = {
      pixel_id: pixelId,
      event: eventType,
      value: conversionData.value,
      transaction_id: conversionData.transaction_id,
      currency: conversionData.currency,
      product_name: window.SITE_CONFIG.PRODUCT.name,
      product_id: window.SITE_CONFIG.PRODUCT.product_hash,
      quantity: 1,
      timestamp: new Date().toISOString()
    };
    
    // Tentar enviar via fetch POST
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
        console.log(`✅ Conversão ${eventType} enviada para UTMfy via fetch`);
      } else {
        console.warn(`⚠️ UTMfy retornou status ${response.status}`);
      }
    })
    .catch(error => {
      console.warn('⚠️ Erro ao enviar via fetch, tentando método alternativo:', error);
      
      // Método 3: Fallback para Image pixel tracking
      const imgUrl = `${trackingUrl}?pixel_id=${pixelId}&event=${eventType}&value=${conversionData.value}&transaction_id=${conversionData.transaction_id}`;
      const img = new Image();
      img.src = imgUrl;
      img.onload = () => {
        console.log(`✅ Conversão ${eventType} enviada para UTMfy via pixel (fallback)`);
      };
      img.onerror = () => {
        console.warn(`⚠️ Falha ao enviar conversão ${eventType} para UTMfy`);
      };
    });
  } catch (error) {
    console.error('❌ Erro ao enviar para UTMfy:', error);
  }
  
    // Log do pixel status
    if (typeof window.googlePixelId !== 'undefined') {
      console.log(`📊 UTMfy Pixel ID: ${window.googlePixelId} - tracking ativo`);
    } else {
      console.warn('⚠️ UTMfy Pixel ID não encontrado');
    }
  }, 10); // Aguardar até 5 segundos (10 tentativas x 500ms)
};

// 📢 Log de configuração ao carregar
console.log('🔧 Site configurado:', {
  api: 'MedusaPay',
  gateway: 'MedusaPay',
  utmfy_pixel: window.SITE_CONFIG.INTEGRATION.utmfy_pixel_id,
  product: window.SITE_CONFIG.PRODUCT.name,
  valor: 'R$ 150,00 (superior) / R$ 100,00 (medio)',
  nota: 'Sistema de pagamento com MedusaPay e tracking UTMfy'
});

// Taxa conforme Edital 001/2026: medio R$100 | superior R$150
window.resolveTaxaSesTo = function () {
  try {
    var cargos = JSON.parse(localStorage.getItem('cargosSelecionados') || '[]');
    var nivel = (localStorage.getItem('nivel') || '').toLowerCase();
    var taxa = Number(localStorage.getItem('taxaValor') || 0);
    var nome = ((cargos[0] && (cargos[0].nome || cargos[0].title || cargos[0].cargo)) || localStorage.getItem('cargo') || '').toLowerCase();
    var isMedio = taxa === 100 || nivel.indexOf('med') >= 0 || /tecnico|assistente|instrumentador|imobilizacao|radiologia|laboratorio|saude bucal/.test(nome);
    var valor = isMedio ? 100 : 150;
    window.SITE_CONFIG.PRODUCT.value = valor;
    window.SITE_CONFIG.PRODUCT.value_cents = valor * 100;
    window.SITE_CONFIG.PRODUCT.name = isMedio
      ? 'Taxa de Inscricao SES TO 2026 - Nivel Medio/Tecnico (R$ 100,00)'
      : 'Taxa de Inscricao SES TO 2026 - Nivel Superior (R$ 150,00)';
    return valor;
  } catch (e) {
    return window.SITE_CONFIG.PRODUCT.value;
  }
};
try { window.resolveTaxaSesTo(); } catch (e) {}
