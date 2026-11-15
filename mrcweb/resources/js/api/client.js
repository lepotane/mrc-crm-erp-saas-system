import axios from 'axios';
import { locale } from '../i18n';

const client = axios.create({
    baseURL: '/api/v1',
    headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

// Request interceptor: attach Bearer token if exists
client.interceptors.request.use(
    (config) => {
        const token = localStorage.getItem('mrc_token');
        if (token) {
            config.headers.Authorization = `Bearer ${token}`;
        }
        config.headers['X-Locale'] = locale.value;
        return config;
    },
    (error) => Promise.reject(error)
);

// Response interceptor: handle 401 unauthorized
client.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response && error.response.status === 401) {
            localStorage.removeItem('mrc_token');
            if (window.location.pathname !== '/login' && window.location.pathname !== '/register') {
                window.location.href = '/login';
            }
        }
        return Promise.reject(error);
    }
);

export default client;
