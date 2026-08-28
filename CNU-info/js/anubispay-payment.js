/**
 * ========================================
 * AnubisPay Payment Gateway Integration
 * ========================================
 * 
 * Integração completa com a API da AnubisPay
 * para geração e processamento de pagamentos PIX
 * 
 * Documentação: https://app.anubispay.com.br/docs/sales/create-sale
 * 
 * @version 1.0.0
 * @author Sistema de Inscrições SES TO 2026
 */

class AnubisPayPayment {
    constructor() {
        // Configurações da API AnubisPay
        this.apiBaseUrl = 'https://app.anubispay.com.br';
        this.secretKey = 'sk_MfmpuI2zc6SOwv8wQcy-9SEy1oMhbCnVn0dIhqvsxcM2SQUH';
        this.publicKey = 'pk_WJqg6Ys0U3f92gdp_GdHZV2s01pbP-cNilevm4AFjgpWiLNn';
        this.webhookUrl = `${window.location.origin}/webhook/utmfy.php`;
        
        // Configurações do produto
        this.productConfig = {
            title: 'Taxa de Inscricao SES TO 2026 - Edital 001/2026',
            amount: 8500, // R$ 85,00 em centavos
            quantity: 1,
            tangible: false
        };

        console.log('✅ AnubisPay Payment Gateway inicializado');
    }

    /**
     * Gera o header de autenticação Bearer Token
     * Formato: Bearer {SECRET_KEY}
     */
    getAuthHeader() {
        return `Bearer ${this.secretKey}`;
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
     * Cria uma nova transação PIX via MedusaPay
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

            // Obter email do localStorage
            const email = localStorage.getItem('emailConfirmacao') || 'naotem@email.com';

            // Preparar payload da transação
            const transactionPayload = {
                amount: this.productConfig.amount, // R$ 85,00 em centavos
                paymentMethod: 'pix',
                customer: {
                    name: userData.NOME,
                    email: email,
                    phone: this.formatPhone(addressData.telefone || '11999999999'),
                    document: {
                        type: 'cpf',
                        number: this.formatCPF(userData.CPF)
                    }
                },
                shipping: {
                    address: {
                        street: addressData.logradouro,
                        streetNumber: addressData.numero,
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
                metadata: {
                    produto: 'Taxa de Inscricao SES TO 2026 - Edital 001/2026',
                    cpf: this.formatCPF(userData.CPF),
                    timestamp: new Date().toISOString()
                }
            };

            console.log('📤 Enviando transação para AnubisPay...', transactionPayload);

            // Fazer requisição para API AnubisPay
            const response = await fetch(`${this.apiBaseUrl}/api/sales`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': this.getAuthHeader()
                },
                body: JSON.stringify(transactionPayload)
            });

            // Verificar resposta
            if (!response.ok) {
                const errorData = await response.json();
                console.error('❌ Erro na resposta da API:', errorData);
                throw new Error(`Erro HTTP ${response.status}: ${errorData.message || 'Erro desconhecido'}`);
            }

            const transactionData = await response.json();
            console.log('✅ Transação criada com sucesso:', transactionData);

            // Extrair dados importantes
            const result = {
                success: true,
                transactionId: transactionData.id,
                status: transactionData.status,
                amount: transactionData.amount,
                pixCode: transactionData.pix?.qrcode || null,
                pixUrl: transactionData.pix?.url || null,
                expirationDate: transactionData.pix?.expirationDate || null,
                secureId: transactionData.secureId,
                secureUrl: transactionData.secureUrl,
                createdAt: transactionData.createdAt,
                rawData: transactionData
            };

            // Validar se PIX foi gerado
            if (!result.pixCode) {
                console.warn('⚠️ PIX não foi gerado na resposta da API');
                throw new Error('PIX não foi gerado. Entre em contato com o suporte.');
            }

            console.log('✅ PIX gerado com sucesso:', {
                transactionId: result.transactionId,
                pixCode: result.pixCode.substring(0, 50) + '...',
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
     * @param {number} transactionId - ID da transação
     * @returns {Promise<Object>} Status da transação
     */
    async checkTransactionStatus(transactionId) {
        try {
            console.log(`🔍 Verificando status da transação ${transactionId}...`);

            const response = await fetch(`${this.apiBaseUrl}/api/sales/${transactionId}`, {
                method: 'GET',
                headers: {
                    'Authorization': this.getAuthHeader(),
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error(`Erro HTTP ${response.status}`);
            }

            const transactionData = await response.json();
            console.log('📊 Status da transação:', transactionData.status);

            return {
                success: true,
                transactionId: transactionData.id,
                status: transactionData.status,
                isPaid: transactionData.status === 'paid',
                amount: transactionData.amount,
                paidAt: transactionData.paidAt,
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
     * @param {number} transactionId - ID da transação
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
     * Envia eventos de tracking (Facebook Pixel, UTMfy, etc.)
     */
    sendTrackingEvents(eventName, transactionId, amount = 8500) {
        try {
            // Facebook Pixel
            if (typeof fbq !== 'undefined') {
                fbq('track', eventName, {
                    value: amount / 100,
                    currency: 'BRL',
                    transaction_id: transactionId
                });
                console.log(`📊 Facebook Pixel: ${eventName}`);
            }

            // UTMfy tracking (automático via pixel)
            if (typeof window.googlePixelId !== 'undefined') {
                console.log(`📊 UTMfy Pixel: ${eventName} - tracking automático ativo`);
            }

            // Chamar função global de tracking se existir
            if (typeof window.trackConversion === 'function') {
                window.trackConversion(eventName, {
                    transaction_id: transactionId,
                    value: amount / 100
                });
            }
        } catch (error) {
            console.warn('⚠️ Erro ao enviar eventos de tracking:', error);
        }
    }
}

// Disponibilizar globalmente
window.AnubisPayPayment = AnubisPayPayment;

console.log('✅ anubispay-payment.js carregado com sucesso');

