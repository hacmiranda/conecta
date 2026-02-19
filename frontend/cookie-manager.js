/**
 * Cookie Manager - GDPR/LGPD Compliance
 * Gerencia consentimento de cookies e preferências do usuário
 */

class CookieManager {
    constructor() {
        this.storageKey = 'cookie_consent';
        this.consent = JSON.parse(localStorage.getItem(this.storageKey)) || null;
        this.banner = document.getElementById('cookie-banner');
        this.modal = document.getElementById('cookie-modal');
        
        this.init();
    }

    init() {
        // Verificar se já existe consentimento
        if (!this.consent) {
            this.showBanner();
        } else {
            this.applyPreferences();
        }

        // Event Listeners do Banner
        const btnAcceptAll = document.getElementById('btn-accept-all');
        const btnReject = document.getElementById('btn-reject-all');
        const btnManage = document.getElementById('btn-manage-cookies');
        
        if (btnAcceptAll) btnAcceptAll.addEventListener('click', () => this.acceptAll());
        if (btnReject) btnReject.addEventListener('click', () => this.rejectAll());
        if (btnManage) btnManage.addEventListener('click', () => this.openModal());

        // Event Listeners do Modal
        const btnSavePreferences = document.getElementById('btn-save-preferences');
        const btnCloseModal = document.querySelector('.close-cookie-modal');
        
        if (btnSavePreferences) btnSavePreferences.addEventListener('click', () => this.savePreferences());
        if (btnCloseModal) btnCloseModal.addEventListener('click', () => this.closeModal());
        
        // Links que abrem o modal
        const cookieLinks = document.querySelectorAll('.trigger-cookie-modal');
        cookieLinks.forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                this.openModal();
            });
        });

        // Fechar modal clicando fora
        window.addEventListener('click', (e) => {
            if (e.target === this.modal) {
                this.closeModal();
            }
        });
    }

    showBanner() {
        if (this.banner) {
            this.banner.style.display = 'flex';
            // Pequeno delay para animação funcionar
            setTimeout(() => {
                this.banner.classList.add('visible');
            }, 100);
        }
    }

    hideBanner() {
        if (this.banner) {
            this.banner.classList.remove('visible');
            setTimeout(() => {
                this.banner.style.display = 'none';
            }, 300); // Tempo da transição CSS
        }
    }

    openModal() {
        if (this.modal) {
            this.modal.style.display = 'flex';
            setTimeout(() => {
                this.modal.classList.add('visible');
            }, 10);
            
            // Carregar preferências atuais nos checkboxes
            const analyticsCheck = document.getElementById('check-analytics');
            const marketingCheck = document.getElementById('check-marketing');
            
            if (this.consent) {
                if (analyticsCheck) analyticsCheck.checked = this.consent.analytics;
                if (marketingCheck) marketingCheck.checked = this.consent.marketing;
            } else {
                // Default clean state
                if (analyticsCheck) analyticsCheck.checked = false;
                if (marketingCheck) marketingCheck.checked = false;
            }
        }
        this.hideBanner();
    }

    closeModal() {
        if (this.modal) {
            this.modal.classList.remove('visible');
            setTimeout(() => {
                this.modal.style.display = 'none';
            }, 300);
            
            // Se fechou sem salvar e não tem consentimento prévio, mostra banner de volta
            if (!this.consent) {
                this.showBanner();
            }
        }
    }

    acceptAll() {
        const preferences = {
            necessary: true,
            analytics: true,
            marketing: true,
            timestamp: new Date().toISOString()
        };
        this.saveToStorage(preferences);
    }

    rejectAll() {
        const preferences = {
            necessary: true,
            analytics: false,
            marketing: false,
            timestamp: new Date().toISOString()
        };
        this.saveToStorage(preferences);
    }

    savePreferences() {
        const analyticsCheck = document.getElementById('check-analytics');
        const marketingCheck = document.getElementById('check-marketing');

        const preferences = {
            necessary: true,
            analytics: analyticsCheck ? analyticsCheck.checked : false,
            marketing: marketingCheck ? marketingCheck.checked : false,
            timestamp: new Date().toISOString()
        };
        
        this.saveToStorage(preferences);
        this.closeModal();
    }

    saveToStorage(preferences) {
        this.consent = preferences;
        localStorage.setItem(this.storageKey, JSON.stringify(preferences));
        this.applyPreferences();
        this.hideBanner();
        
        // Feedback visual (opcional)
        // console.log('Preferências de cookies salvas:', preferences);
    }

    applyPreferences() {
        if (!this.consent) return;

        // Lógica de Ativação de Scripts Baseada no Consentimento
        
        // 1. Analytics
        if (this.consent.analytics) {
            this.loadAnalyticsScript();
        } else {
            // Se possível, remover ou desativar scripts
            // console.log('Analytics desativado');
        }

        // 2. Marketing
        if (this.consent.marketing) {
            this.loadMarketingScript();
        }

        // Atualizar estado visual se necessário (ex: botão de gerenciar no footer muda de cor)
    }

    loadAnalyticsScript() {
        // Exemplo: Carregar Google Analytics dinamicamente
        // if (!window.ga) { ... lógica de insert script ... }
        console.log('Carregando scripts de Analytics...');
    }

    loadMarketingScript() {
        console.log('Carregando scripts de Marketing...');
    }
}

// Inicializar quando o DOM estiver pronto
document.addEventListener('DOMContentLoaded', () => {
    window.cookieManager = new CookieManager();
});
