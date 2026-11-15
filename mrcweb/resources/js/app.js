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
