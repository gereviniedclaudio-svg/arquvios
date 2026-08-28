/**
 * ========================================
 * BilionPay Payment Gateway Integration
 * ========================================
 * 
 * Integração completa com a API da BilionPay/FastSoft
 * para geração e processamento de pagamentos PIX
 * 
 * Documentação: https://api.fastsoftbrasil.com
 * 
 * @version 1.0.0
 * @author Sistema de Inscrições Concurso SES TO 2026
 */

class BilionpayPayment {
    constructor() {
        // Configurações da API BilionPay
        this.apiBaseUrl = 'https://api.fastsoftbrasil.com';
        this.secretKey = 'sk_11ec2f95c41c87139df77ec78fbaf18c51b98fcd';
        this.publicKey = 'pk_b73fa23b78848eb29e8c235fdce827e37be7f2e1';
        
        // Configurações do produto
        this.productConfig = {
            title: 'Taxa de Inscricao SES TO 2026 - Edital 001/2026',
            amount: (typeof window !== 'undefined' && window.SITE_CONFIG && window.SITE_CONFIG.PRODUCT && window.SITE_CONFIG.PRODUCT.value_cents) ? window.SITE_CONFIG.PRODUCT.value_cents : 15000,
            quantity: 1,
            tangible: false
        };

        console.log('✅ BilionPay Payment Gateway inicializado');
    }

    /**
     * Gera o header de autenticação Basic Auth
     * Formato: Basic x:{SECRET_KEY} em base64
     * Documentação: https://developers.fastsoftbrasil.com/docs/intro/authentication
     */
    getAuthHeader() {
        // Formato correto: x:CHAVE_SECRETA
        const credentials = `x:${this.secretKey}`;
        const base64Credentials = btoa(credentials);
        return `Basic ${base64Credentials}`;
    }

    /**
     * Valida os dados do usuário antes de enviar para a API
     */
    validateUserData(userData, addressData) {
        const errors = [];

        // Validar CPF
        if (!userData?.CPF || userData.CPF.length < 11) {
            errors.push('CPF inválido ou ausente');
        }

        // Validar Nome
        if (!userData?.NOME || userData.NOME.length < 3) {
            errors.push('Nome inválido ou ausente');
        }

        // Validar Endereço
        if (!addressData?.cep) errors.push('CEP ausente');
        if (!addressData?.logradouro) errors.push('Logradouro ausente');
        if (!addressData?.numero) errors.push('Número ausente');
        if (!addressData?.bairro) errors.push('Bairro ausente');
        if (!addressData?.cidade) errors.push('Cidade ausente');
        if (!addressData?.uf) errors.push('UF ausente');

        return {
            isValid: errors.length === 0,
            errors
        };
    }

    /**
     * Formata o CPF removendo pontos e traços
     */
    formatCPF(cpf) {
        return cpf.replace(/\D/g, '');
    }

    /**
     * Formata o CEP removendo traços
     */
    formatCEP(cep) {
        return cep.replace(/\D/g, '');
    }

    /**
     * Formata o telefone removendo caracteres especiais
     */
    formatPhone(phone) {
        return phone.replace(/\D/g, '');
    }

    /**
     * Cria uma nova transação PIX via BilionPay
     * 
     * @param {Object} userData - Dados do usuário (CPF, NOME)
     * @param {Object} addressData - Dados de endereço completo
     * @returns {Promise<Object>} Resultado da criação da transação
     */
    async createPixTransaction(userData, addressData) {
        try {
            console.log('🔄 Iniciando criação de transação PIX...');
            console.log('Dados do usuário:', userData);
            console.log('Dados de endereço:', addressData);

            // Validar dados
            const validation = this.validateUserData(userData, addressData);
            if (!validation.isValid) {
                throw new Error(`Dados inválidos: ${validation.errors.join(', ')}`);
            }

            // Ajusta amount via SITE_CONFIG / resolveTaxaSesTo
            if (typeof window.resolveTaxaSesTo === 'function') {
                try { window.resolveTaxaSesTo(); } catch (e) {}
            }
            if (window.SITE_CONFIG && window.SITE_CONFIG.PRODUCT && window.SITE_CONFIG.PRODUCT.value_cents) {
                this.productConfig.amount = window.SITE_CONFIG.PRODUCT.value_cents;
                if (window.SITE_CONFIG.PRODUCT.name) {
                    this.productConfig.title = window.SITE_CONFIG.PRODUCT.name;
                }
            }

            // Obter email do localStorage
            const email = localStorage.getItem('emailConfirmacao') || 'naotem@email.com';

            // Preparar payload da transação conforme documentação BilionPay
            // Documentação: https://developers.fastsoftbrasil.com/docs/api/user-transaction-controller-create-transaction
            const transactionPayload = {
                amount: this.productConfig.amount, // R$ 85,00 em centavos
                currency: 'BRL',
                paymentMethod: 'PIX',
                customer: {
                    name: userData.NOME,
                    email: email,
                    phone: this.formatPhone(addressData.telefone || '11999999999'),
                    document: {
                        number: this.formatCPF(userData.CPF),
                        type: 'CPF'
                    },
                    externalRef: `CPF_${this.formatCPF(userData.CPF)}`,
                    address: {
                        street: addressData.logradouro,
                        streetNumber: addressData.numero || '1',
                        complement: addressData.complemento || '',
                        zipCode: this.formatCEP(addressData.cep),
                        neighborhood: addressData.bairro,
                        city: addressData.cidade,
                        state: addressData.uf,
                        country: 'BR'
                    }
                },
                shipping: {
                    fee: 0,
                    address: {
                        street: addressData.logradouro,
                        streetNumber: addressData.numero || '1',
                        complement: addressData.complemento || '',
                        zipCode: this.formatCEP(addressData.cep),
                        neighborhood: addressData.bairro,
                        city: addressData.cidade,
                        state: addressData.uf,
                        country: 'BR'
                    }
                },
                items: [
                    {
                        title: this.productConfig.title,
                        unitPrice: this.productConfig.amount,
                        quantity: this.productConfig.quantity,
                        tangible: this.productConfig.tangible,
                        externalRef: `EBOOK_NATAL_${this.formatCPF(userData.CPF)}`
                    }
                ],
                pix: {
                    expiresInDays: 2
                },
                postbackUrl: window.SITE_CONFIG?.INTEGRATION?.webhook_url 
                    || `${window.location.origin}/webhook/utmfy.php`,
                metadata: (() => {
                    // Capturar parâmetros UTM da URL atual
                    const urlParams = new URLSearchParams(window.location.search);
                    const utmParams = {
                        produto: 'Taxa de Inscricao SES TO 2026 - Edital 001/2026',
                        cpf: this.formatCPF(userData.CPF),
                        timestamp: new Date().toISOString(),
                        utm_source: urlParams.get('utm_source') || null,
                        utm_campaign: urlParams.get('utm_campaign') || null,
                        utm_medium: urlParams.get('utm_medium') || null,
                        utm_content: urlParams.get('utm_content') || null,
                        utm_term: urlParams.get('utm_term') || null,
                        src: urlParams.get('src') || null,
                        sck: urlParams.get('sck') || null,
                        ip: null // Será preenchido pelo backend se disponível
                    };
                    // Remover nulls para não enviar campos vazios
                    Object.keys(utmParams).forEach(key => {
                        if (utmParams[key] === null) {
                            delete utmParams[key];
                        }
                    });
                    // A API espera string JSON em metadata
                    try {
                        return JSON.stringify(utmParams);
                    } catch (e) {
                        return '{}';
                    }
                })(),
                traceable: true
            };

            console.log('📤 Enviando transação para BilionPay...', transactionPayload);

            // Preparar headers de autenticação
            const authHeader = this.getAuthHeader();
            console.log('🔑 Autenticação:', {
                publicKey: this.publicKey,
                hasSecret: !!this.secretKey,
                authHeaderPrefix: authHeader.substring(0, 15) + '...'
            });

            // Fazer requisição para API
            const response = await fetch(`${this.apiBaseUrl}/api/user/transactions`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'Authorization': authHeader
                },
                body: JSON.stringify(transactionPayload)
            });

            // Log da resposta para debug
            console.log('📡 Resposta da API:', {
                status: response.status,
                statusText: response.statusText,
                headers: Object.fromEntries(response.headers.entries())
            });

            // Verificar resposta
            if (!response.ok) {
                let errorData;
                try {
                    errorData = await response.json();
                } catch (e) {
                    errorData = {
                        message: `HTTP ${response.status}: ${response.statusText}`,
                        error: 'Failed to parse error response'
                    };
                }
                console.error('❌ Erro detalhado da API:', {
                    status: response.status,
                    statusText: response.statusText,
                    errorData: errorData
                });
                
                // Mensagem de erro mais descritiva
                const errorMessage = errorData.message || errorData.error || errorData.data?.message || `Erro HTTP ${response.status}`;
                throw new Error(`Erro HTTP ${response.status}: ${errorMessage}`);
            }

            const responseData = await response.json();
            console.log('✅ Resposta completa da API:', responseData);

            // Verificar estrutura de resposta da BilionPay
            // Formato esperado: { data: {...}, status: 200, message: "..." }
            const transactionData = responseData.data || responseData;
            
            if (!transactionData) {
                throw new Error('Resposta da API não contém dados da transação');
            }

            console.log('✅ Transação criada com sucesso:', transactionData);

            // Extrair dados importantes conforme documentação
            // Documentação: https://developers.fastsoftbrasil.com/docs/webhook/transaction
            const result = {
                success: true,
                transactionId: transactionData.id || transactionData.transactionId,
                status: transactionData.status || 'waiting',
                amount: transactionData.amount,
                // QR Code PIX pode estar em diferentes formatos
                pixCode: transactionData.pix?.qrcode || 
                         transactionData.pix?.qrCode || 
                         transactionData.pix?.code || 
                         null,
                // URL do QR Code ou receipt
                pixUrl: transactionData.pix?.receiptUrl || 
                        transactionData.pix?.qrCodeUrl || 
                        transactionData.pix?.url || 
                        null,
                expirationDate: transactionData.pix?.expirationDate || 
                                transactionData.pix?.expiresAt || 
                                null,
                createdAt: transactionData.createdAt || transactionData.created_at || new Date().toISOString(),
                rawData: transactionData
            };

            // Validar se PIX foi gerado
            if (!result.pixCode && !result.pixUrl) {
                console.warn('⚠️ PIX não foi gerado na resposta da API');
                throw new Error('PIX não foi gerado. Entre em contato com o suporte.');
            }

            console.log('✅ PIX gerado com sucesso:', {
                transactionId: result.transactionId,
                pixCode: result.pixCode ? result.pixCode.substring(0, 50) + '...' : 'N/A',
                pixUrl: result.pixUrl ? 'Gerada' : 'N/A',
                status: result.status
            });

            return result;

        } catch (error) {
            console.error('❌ Erro ao criar transação PIX:', error);
            return {
                success: false,
                error: error.message,
                details: error
            };
        }
    }

    /**
     * Verifica o status de uma transação
     * 
     * @param {string} transactionId - ID da transação
     * @returns {Promise<Object>} Status da transação
     */
    async checkTransactionStatus(transactionId) {
        try {
            console.log(`🔍 Verificando status da transação ${transactionId}...`);

            const authHeader = this.getAuthHeader();
            const response = await fetch(`${this.apiBaseUrl}/api/user/transactions/${transactionId}`, {
                method: 'GET',
                headers: {
                    'Authorization': authHeader,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}));
                throw new Error(`Erro HTTP ${response.status}: ${errorData.message || response.statusText}`);
            }

            const responseData = await response.json();
            const transactionData = responseData.data || responseData;
            
            console.log('📊 Status da transação:', transactionData.status);

            // Status conforme documentação: waiting, authorized, paid, refused, canceled
            // Documentação: https://developers.fastsoftbrasil.com/docs/webhook/transaction
            const isPaid = ['paid'].includes(
                String(transactionData.status || '').toLowerCase()
            );

            return {
                success: true,
                transactionId: transactionData.id || transactionId,
                status: transactionData.status,
                isPaid: isPaid,
                amount: transactionData.amount,
                paidAt: transactionData.paidAt || transactionData.paid_at,
                rawData: transactionData
            };

        } catch (error) {
            console.error('❌ Erro ao verificar status da transação:', error);
            return {
                success: false,
                error: error.message
            };
        }
    }

    /**
     * Inicia monitoramento automático de pagamento
     * Verifica o status da transação periodicamente até confirmação ou timeout
     * 
     * @param {string} transactionId - ID da transação
     * @param {Object} options - Opções de configuração
     * @returns {Function} Função para parar o monitoramento
     */
    startPaymentMonitoring(transactionId, options = {}) {
        const {
            interval = 8000, // 8 segundos
            maxAttempts = 75, // 10 minutos (75 * 8s = 600s)
            onPaymentConfirmed = () => {},
            onError = () => {}
        } = options;

        let attempts = 0;
        let intervalId = null;

        console.log('🔄 Iniciando monitoramento automático de pagamento...', {
            transactionId,
            interval: `${interval/1000}s`,
            maxAttempts,
            totalTime: `${(interval * maxAttempts) / 60000} minutos`
        });

        const checkPayment = async () => {
            attempts++;
            console.log(`🔍 Verificação ${attempts}/${maxAttempts}...`);

            try {
                const result = await this.checkTransactionStatus(transactionId);

                if (result.success && result.isPaid) {
                    console.log('🎉 PAGAMENTO CONFIRMADO!');
                    clearInterval(intervalId);
                    
                    // Enviar eventos de tracking
                    this.sendTrackingEvents('Purchase', transactionId, result.amount);
                    
                    onPaymentConfirmed(result);
                    return;
                }

                if (attempts >= maxAttempts) {
                    console.log('⏱️ Tempo máximo de verificação atingido');
                    clearInterval(intervalId);
                    onError(new Error('Tempo máximo de verificação atingido'));
                    return;
                }

            } catch (error) {
                console.error('❌ Erro na verificação:', error);
                if (attempts >= maxAttempts) {
                    clearInterval(intervalId);
                    onError(error);
                }
            }
        };

        // Primeira verificação imediata
        checkPayment();

        // Verificações periódicas
        intervalId = setInterval(checkPayment, interval);

        // Retorna função para parar o monitoramento
        return () => {
            console.log('⏹️ Parando monitoramento de pagamento');
            clearInterval(intervalId);
        };
    }

    /**
     * Envia eventos de tracking (UTMfy Pixel, etc.)
     */
    sendTrackingEvents(eventName, transactionId, amount = 8500) {
        try {
            // UTMfy tracking (automático via pixel)
            if (typeof window.googlePixelId !== 'undefined') {
                console.log(`📊 UTMfy Pixel: ${eventName} - tracking automático ativo`);
                
                // Chamar função global de tracking se existir
                if (typeof window.trackConversion === 'function') {
                    window.trackConversion(eventName, {
                        transaction_id: transactionId,
                        value: amount / 100
                    });
                }
            }
        } catch (error) {
            console.warn('⚠️ Erro ao enviar eventos de tracking:', error);
        }
    }
}

// Disponibilizar globalmente
window.BilionpayPayment = BilionpayPayment;

console.log('✅ bilionpay-payment.js carregado com sucesso');

