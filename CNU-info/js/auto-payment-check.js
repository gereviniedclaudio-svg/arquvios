/**
 * Sistema de Verificação Automática de Pagamento
 * Verifica a cada 8 segundos se o pagamento foi confirmado
 * Redireciona automaticamente quando o pagamento for confirmado
 * 
 * IMPORTANTE: Este sistema roda EM PARALELO com os popups existentes
 * Os popups continuam funcionando normalmente como sistema principal
 * Este sistema é apenas um complemento para melhorar a experiência
 */

class AutoPaymentChecker {
    constructor() {
        this.checkInterval = null;
        this.attempts = 0;
        this.maxAttempts = 75; // 10 minutos máximo (75 * 8s = 600s)
        this.isRunning = false;
    }

    /**
     * Inicia a verificação automática de pagamento
     * @param {string} transactionId - ID da transação
     * @param {object} tribopay - Instância compatível (MedusapayPayment ou outros)
     */
    startAutoCheck(transactionId, tribopay) {
        if (this.isRunning) {
            console.log('⚠️ Verificação automática já está rodando');
            return;
        }

        console.log('🔄 Iniciando verificação automática de pagamento...');
        console.log(`📋 Transaction ID: ${transactionId}`);
        
        // Verificar se o objeto foi passado corretamente
        if (!tribopay || typeof tribopay !== 'object') {
            console.error('❌ Objeto de pagamento inválido ou não foi passado');
            return;
        }
        
        // Verificar métodos disponíveis
        const methods = Object.getOwnPropertyNames(Object.getPrototypeOf(tribopay));
        console.log(`📋 Métodos disponíveis no objeto:`, methods);
        console.log(`📋 checkTransactionStatus existe?`, typeof tribopay.checkTransactionStatus);
        console.log(`📋 checkPaymentStatus existe?`, typeof tribopay.checkPaymentStatus);
        
        this.isRunning = true;
        this.attempts = 0;
        
        this.checkInterval = setInterval(async () => {
            this.attempts++;
            
            // Parar verificação após máximo de tentativas
            if (this.attempts > this.maxAttempts) {
                console.log('⏰ Tempo limite de verificação automática atingido');
                this.stopAutoCheck();
                return;
            }
            
            console.log(`🔍 Verificação automática ${this.attempts}/${this.maxAttempts} - Transaction: ${transactionId}`);
            
            try {
                // Verificar se o objeto de pagamento foi passado corretamente
                if (!tribopay || typeof tribopay !== 'object') {
                    throw new Error('Objeto de pagamento inválido');
                }
                
                // Verificar status do pagamento
                // MedusaPay usa checkTransactionStatus, outros podem usar checkPaymentStatus
                let statusResult;
                if (typeof tribopay.checkTransactionStatus === 'function') {
                    console.log('✅ Usando checkTransactionStatus (MedusaPay)');
                    statusResult = await tribopay.checkTransactionStatus(transactionId);
                } else if (typeof tribopay.checkPaymentStatus === 'function') {
                    console.log('✅ Usando checkPaymentStatus (outros gateways)');
                    statusResult = await tribopay.checkPaymentStatus(transactionId);
                } else {
                    console.error('❌ Métodos disponíveis no objeto:', Object.getOwnPropertyNames(tribopay));
                    throw new Error('Método de verificação de status não encontrado. Objeto não possui checkTransactionStatus nem checkPaymentStatus');
                }
                
                console.log('📊 Resultado da verificação:', statusResult);
                
                // Verificar se o pagamento foi confirmado
                const isPaid = statusResult.success && (
                    statusResult.isPaid === true || 
                    statusResult.status === 'paid' || 
                    (statusResult.data && statusResult.data.payment_status === 'paid')
                );
                
                if (isPaid) {
                    console.log('🎉 PAGAMENTO CONFIRMADO VIA VERIFICAÇÃO AUTOMÁTICA!');
                    console.log('📋 Status detectado:', statusResult.status);
                    console.log('📋 isPaid:', statusResult.isPaid);
                    console.log('📋 payment_status:', statusResult.data?.payment_status);
                    
                    // Parar verificação
                    this.stopAutoCheck();
                    
                    // Enviar eventos de conversão
                    tribopay.sendTrackingEvents('Purchase', transactionId);

                    try {
                        var tx = JSON.parse(localStorage.getItem('medusapayTransaction') || '{}');
                        var amountCents = Number(localStorage.getItem('taxaCobradaCents') || tx.amount || 0);
                        if (!amountCents) {
                            var taxa = Number(localStorage.getItem('taxaValor') || 0);
                            amountCents = (taxa === 100 || taxa === 150) ? taxa * 100 : 15000;
                        }
                        localStorage.setItem('pagamentoConfirmado', JSON.stringify({
                            transactionId: transactionId,
                            amount: amountCents,
                            valorReais: amountCents / 100,
                            status: 'paid',
                            confirmedAt: new Date().toISOString()
                        }));
                        localStorage.removeItem("currentPix");
                        localStorage.removeItem("medusapayTransaction");
                    } catch (error) {
                        console.warn('Erro ao limpar dados:', error);
                    }

                    console.log('Redirecionando para confirmação...');

                    try { if (window.FunnelTracker) FunnelTracker.advance('pagamento'); } catch (e) {}

                    if (typeof redirectWithUtmParams === 'function') {
                        redirectWithUtmParams("/confirmacao/");
                    } else {
                        window.location.href = "/confirmacao/";
                    }
                    
                } else {
                    console.log(`⏳ Pagamento ainda pendente. Status: ${statusResult.statusText || 'Verificando...'}`);
                }
                
            } catch (error) {
                console.error('❌ Erro na verificação automática:', error);
                // Continuar tentando mesmo com erro
            }
            
        }, 4000); // Verificar a cada 4 segundos
    }

    /**
     * Para a verificação automática
     */
    stopAutoCheck() {
        if (this.checkInterval) {
            clearInterval(this.checkInterval);
            this.checkInterval = null;
        }
        this.isRunning = false;
        console.log('🛑 Verificação automática interrompida');
    }

    /**
     * Verifica se a verificação está rodando
     */
    isActive() {
        return this.isRunning;
    }

    /**
     * Obtém informações sobre o status da verificação
     */
    getStatus() {
        return {
            isRunning: this.isRunning,
            attempts: this.attempts,
            maxAttempts: this.maxAttempts,
            remainingAttempts: this.maxAttempts - this.attempts
        };
    }
}

// Instância global do verificador automático
window.autoPaymentChecker = new AutoPaymentChecker();

console.log('✅ Sistema de verificação automática de pagamento carregado');
