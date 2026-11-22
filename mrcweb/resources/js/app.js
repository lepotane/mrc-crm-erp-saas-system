import './bootstrap';
import { createApp, computed } from 'vue';
import { createPinia } from 'pinia';
import router from './router';
import App from './App.vue';
import { t, locale, localeTag } from './i18n';
import { initTheme } from './composables/useTheme';

initTheme();

const app = createApp(App);
const pinia = createPinia();

app.use(pinia);
app.use(router);

// Hafif i18n katmanı: şablonlarda {{ $t('...') }} kullanımı
app.config.globalProperties.$t = t;
app.config.globalProperties.$locale = locale;
app.config.globalProperties.$localeTag = computed(() => localeTag());

app.mount('#app');

// PWA: service worker yalnızca HTTPS/Localhost'ta kaydedilir.
// API verileri önbelleğe alınmaz (bkz. public/sw.js).
if ('serviceWorker' in navigator && (location.protocol === 'https:' || ['localhost', '127.0.0.1'].includes(location.hostname))) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {
            // PWA olmadan da uygulama normal çalışır.
        });
    });
}
