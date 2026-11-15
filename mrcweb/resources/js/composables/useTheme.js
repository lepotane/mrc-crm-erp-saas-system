import { ref, computed } from 'vue';

const STORAGE_KEY = 'mrc_theme_preference';
const themePreference = ref('system'); // 'system' | 'light' | 'dark'
const systemIsLight = ref(false);
let initialized = false;

function applyDomTheme(effective) {
    if (typeof document === 'undefined') return;
    document.documentElement.setAttribute('data-theme', effective);
    if (effective === 'light') {
        document.documentElement.classList.add('theme-light');
        document.documentElement.classList.remove('theme-dark');
    } else {
        document.documentElement.classList.add('theme-dark');
        document.documentElement.classList.remove('theme-light');
    }
}

export function initTheme() {
    if (initialized || typeof window === 'undefined') return;
    initialized = true;

    try {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (saved === 'light' || saved === 'dark' || saved === 'system') {
            themePreference.value = saved;
        }
    } catch {
        // ignore storage errors
    }

    if (window.matchMedia) {
        const mq = window.matchMedia('(prefers-color-scheme: light)');
        systemIsLight.value = mq.matches;
        const listener = (e) => {
            systemIsLight.value = e.matches;
            updateEffectiveTheme();
        };
        if (mq.addEventListener) {
            mq.addEventListener('change', listener);
        }
    }

    updateEffectiveTheme();
}

const currentTheme = computed(() => {
    if (themePreference.value === 'system') {
        return systemIsLight.value ? 'light' : 'dark';
    }
    return themePreference.value;
});

function updateEffectiveTheme() {
    applyDomTheme(currentTheme.value);
}

export function useTheme() {
    initTheme();

    const isLight = computed(() => currentTheme.value === 'light');

    function setTheme(mode) {
        themePreference.value = mode;
        try {
            localStorage.setItem(STORAGE_KEY, mode);
        } catch {
            // ignore
        }
        updateEffectiveTheme();
    }

    function toggleTheme() {
        const next = currentTheme.value === 'dark' ? 'light' : 'dark';
        setTheme(next);
    }

    return {
        themePreference,
        currentTheme,
        isLight,
        setTheme,
        toggleTheme,
    };
}
