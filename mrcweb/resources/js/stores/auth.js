import { defineStore } from 'pinia';
import client from '../api/client';
import { t, syncLocaleFromUser, setLocaleFormats } from '../i18n';

// /me, login ve register cevaplarındaki dil alanlarını i18n katmanına işler
function applyLocalePayload(data) {
    if (!data) return;
    if (data.locale_formats) setLocaleFormats(data.locale_formats);
    const l = data.locale || data.user?.locale;
    if (l) syncLocaleFromUser(l);
}

export const useAuthStore = defineStore('auth', {
    state: () => ({
        token: localStorage.getItem('mrc_token') || null,
        user: null,
        company: null,
        companies: [],
        loading: false,
        error: null,

        // 2FA sınama durumu: login 202 döndüğünde dolar, kod onaylanınca boşalır.
        // Kalıcı değildir (localStorage'a yazılmaz) — sekme kapanınca yeniden giriş gerekir.
        challengeToken: null,
        challengeUser: null,

        // Davetli taraf: bu kullanıcının e-postasına gönderilmiş bekleyen davetler
        // (GET /invitations/mine). E-posta teslimi bu fazda olmadığı için kabul
        // bağlantısının (token) tek güvenli dağıtım yolu bu uçtur.
        invitations: [],
        invitationsLoaded: false,
        invitationsDismissed: false,
    }),

    getters: {
        isAuthenticated: (state) => !!state.token,
        userName: (state) => state.user?.name || t('Kullanıcı'),
        userEmail: (state) => state.user?.email || '',
        currentCompanyName: (state) => state.company?.name || t('Firma Seçilmedi'),
        userInitials: (state) => {
            if (!state.user?.name) return 'M';
            const parts = state.user.name.trim().split(/\s+/);
            if (parts.length === 1) {
                return parts[0].substring(0, 2).toUpperCase();
            }
            return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
        },
    },

    actions: {
        async login({ email, password }) {
            this.loading = true;
            this.error = null;
            try {
                const response = await client.post('/auth/login', { email, password });

                // 2FA etkin hesap: 202 + sınama anahtarı döner, tam oturum AÇILMAZ.
                // Sınama anahtarı kalıcı oturum değildir: localStorage'a yazılmaz,
                // yalnız bu sekmenin belleğinde durur (kapatınca yeniden giriş gerekir).
                if (response.data?.requires_two_factor) {
                    this.challengeToken = response.data.challenge_token || null;
                    this.challengeUser = response.data.user || null;
                    return response.data;
                }

                const { token, user, company, companies } = response.data;

                this.token = token;
                this.user = user;
                this.company = company || null;
                this.companies = Array.isArray(companies) ? companies : (company ? [company] : []);

                if (token) {
                    localStorage.setItem('mrc_token', token);
                }
                applyLocalePayload(response.data);
                return response.data;
            } catch (err) {
                this.error = err.response?.data?.message || t('Giriş yapılamadı.');
                throw err;
            } finally {
                this.loading = false;
            }
        },

        /** 2FA ikinci adım: sınama anahtarı + kod → tam oturum. */
        async completeTwoFactor({ code, recovery_code }) {
            this.loading = true;
            this.error = null;
            try {
                const payload = code ? { code } : { recovery_code };
                const response = await client.post('/auth/2fa/challenge', payload, {
                    headers: { Authorization: `Bearer ${this.challengeToken}` },
                });
                const { token, user, company, companies } = response.data;

                this.token = token;
                this.user = user;
                this.company = company || null;
                this.companies = Array.isArray(companies) ? companies : (company ? [company] : []);
                this.challengeToken = null;
                this.challengeUser = null;

                if (token) {
                    localStorage.setItem('mrc_token', token);
                }
                applyLocalePayload(response.data);
                return response.data;
            } catch (err) {
                this.error = err.response?.data?.message || t('Doğrulama yapılamadı.');
                throw err;
            } finally {
                this.loading = false;
            }
        },

        async register({ name, email, password, company_name }) {
            this.loading = true;
            this.error = null;
            try {
                const response = await client.post('/auth/register', {
                    name,
                    email,
                    password,
                    company_name,
                });
                const { token, user, company } = response.data;

                this.token = token;
                this.user = user;
                this.company = company || null;
                this.companies = company ? [company] : [];

                if (token) {
                    localStorage.setItem('mrc_token', token);
                }
                applyLocalePayload(response.data);
                return response.data;
            } catch (err) {
                this.error = err.response?.data?.message || t('Kayıt oluşturulamadı.');
                throw err;
            } finally {
                this.loading = false;
            }
        },

        async logout() {
            try {
                if (this.token) {
                    await client.post('/auth/logout');
                }
            } catch {
                // Ignore logout network errors
            } finally {
                this.clearAuth();
            }
        },

        async fetchMe() {
            if (!this.token) return null;
            try {
                const response = await client.get('/me');
                const { user, company, companies } = response.data;
                this.user = user;
                this.company = company || null;
                if (companies) {
                    this.companies = companies;
                }
                applyLocalePayload(response.data);
                return response.data;
            } catch (err) {
                if (err.response?.status === 401) {
                    this.clearAuth();
                }
                return null;
            }
        },

        async fetchCompanies() {
            if (!this.token) return [];
            try {
                const response = await client.get('/companies');
                this.companies = response.data?.companies || [];
                return this.companies;
            } catch {
                return [];
            }
        },

        // Uç firma bağlamı gerektirmez: davet edilen kişi henüz hiçbir firmaya
        // bağlı olmayabilir. Yalnız kendi e-postasına gönderilmiş davetler döner.
        async fetchMyInvitations() {
            if (!this.token) return [];
            try {
                const response = await client.get('/invitations/mine');
                this.invitations = Array.isArray(response.data?.invitations)
                    ? response.data.invitations
                    : [];
            } catch {
                this.invitations = [];
            } finally {
                this.invitationsLoaded = true;
            }
            return this.invitations;
        },

        async acceptInvitation(token) {
            try {
                const response = await client.post('/invitations/accept', { token });
                // kabul edilen davet listeden düşer (sunucu accepted_at yazar)
                this.invitations = this.invitations.filter((i) => i.token !== token);
                // üyelik + bağlam değişmiş olabilir: üst menü ve rol etiketleri
                // bayat kalmasın (sunucu, bağlamı olmayan kullanıcıyı davet edilen
                // firmaya bağlar) → /me ile tazele
                await this.fetchMe();
                return response.data;
            } catch (err) {
                throw err;
            }
        },

        async selectCompany(company_id) {
            try {
                const response = await client.post('/companies/select', { company_id });
                const { user, company } = response.data;
                if (user) this.user = user;
                if (company) this.company = company;
                applyLocalePayload(response.data);
                return response.data;
            } catch (err) {
                throw err;
            }
        },

        clearAuth() {
            this.token = null;
            this.user = null;
            this.company = null;
            this.companies = [];
            this.invitations = [];
            this.invitationsLoaded = false;
            this.invitationsDismissed = false;
            localStorage.removeItem('mrc_token');
        },
    },
});
