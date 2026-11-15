// Kamera barkod okuma — V2-4 (PWA).
// Öncelik: BarcodeDetector (Chrome/Android native); yoksa zxing-wasm fallback (iOS Safari).
// - Arka kamera, fener (torch), başarıda bip + titreşim, çift okuma koruması (debounce).
// - resolveBarcode: tek API araması + 60 sn lokal önbellek (<500 ms hedefi).
// - V2-3 henüz yok: sayım entegrasyonu V2-3'te bu composable + BarcodeScanner ile kurulur.

import { ref } from 'vue';
import client from '../api/client';

const NATIVE_FORMATS = [
    'ean_13', 'ean_8', 'code_128', 'code_39', 'itf', 'qr_code', 'upc_a', 'upc_e',
];

const cache = new Map(); // barkod -> { product, at }
const CACHE_TTL_MS = 60_000;

export function isScannerSupported() {
    return typeof window !== 'undefined'
        && (('BarcodeDetector' in window) || isSecureCameraContext());
}

function isSecureCameraContext() {
    if (typeof window === 'undefined' || !navigator.mediaDevices?.getUserMedia) return false;
    return window.isSecureContext === true;
}

function beep() {
    try {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return;
        const ctx = new Ctx();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.frequency.value = 880;
        gain.gain.setValueAtTime(0.12, ctx.currentTime);
        osc.start();
        osc.stop(ctx.currentTime + 0.12);
        osc.onended = () => ctx.close();
    } catch { /* sessiz devam */ }
}

function buzz() {
    try {
        if (navigator.vibrate) navigator.vibrate(60);
    } catch { /* sessiz devam */ }
}

export function useBarcodeScanner() {
    const running = ref(false);
    const torchOn = ref(false);
    const canTorch = ref(false);
    const lastCode = ref('');
    const error = ref('');
    const usingFallback = ref(false);

    let stream = null;
    let rafId = 0;
    let timerId = 0;
    let detector = null;
    let zxing = null;
    let lastEmitAt = 0;
    let lastEmitCode = '';
    let onDetectCb = null;
    let emitTimer = 0;

    const DEBOUNCE_MS = 1500;

    function emit(code) {
        const now = Date.now();
        if (code === lastEmitCode && now - lastEmitAt < DEBOUNCE_MS) return;
        lastEmitCode = code;
        lastEmitAt = now;
        lastCode.value = code;
        beep();
        buzz();
        if (onDetectCb) onDetectCb(code);
    }

    async function detectNative(video) {
        try {
            const codes = await detector.detect(video);
            if (codes && codes.length) {
                const raw = codes[0].rawValue || '';
                if (raw) emit(raw);
            }
        } catch { /* kare atlanır, döngü sürer */ }
    }

    async function detectFallback(video, canvas) {
        try {
            if (!zxing) {
                zxing = await import('zxing-wasm/reader');
                // Vite, emscripten wasm'ını derleme çıktısına `?url` ile alır
                // (ham dosya public/ altında durmaz; sürümle birlikte hash'lenir).
                // Bildirilmezse /build/assets/zxing_reader.wasm arar ve iOS
                // taraması sessizce hiç çalışmaz.
                const { default: wasmUrl } = await import('zxing-wasm/reader/zxing_reader.wasm?url');
                if (zxing.setZXingModuleOverrides) {
                    zxing.setZXingModuleOverrides({ locateFile: () => wasmUrl });
                }
                if (zxing.prepareZXingModule) await zxing.prepareZXingModule();
            }
            const w = video.videoWidth, h = video.videoHeight;
            if (!w || !h) return;
            canvas.width = w;
            canvas.height = h;
            const ctx = canvas.getContext('2d', { willReadFrequently: true });
            ctx.drawImage(video, 0, 0, w, h);
            const imageData = ctx.getImageData(0, 0, w, h);
            const results = await zxing.readBarcodesFromImageData(imageData, {
                formats: ['EAN-13', 'EAN-8', 'Code128', 'Code39', 'ITF', 'UPC-A', 'UPC-E', 'QRCode'],
                tryHarder: true,
            });
            if (results && results.length && results[0].text) emit(results[0].text);
        } catch { /* kare atlanır, döngü sürer */ }
    }

    async function start(videoEl, onDetect) {
        stop();
        error.value = '';
        onDetectCb = onDetect || null;

        if (!isSecureCameraContext()) {
            error.value = 'camera-insecure';
            return false;
        }

        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } },
                audio: false,
            });
        } catch {
            error.value = 'camera-denied';
            return false;
        }

        videoEl.srcObject = stream;
        await videoEl.play().catch(() => {});

        try {
            const track = stream.getVideoTracks()[0];
            const caps = track && track.getCapabilities ? track.getCapabilities() : {};
            canTorch.value = !!caps.torch;
        } catch {
            canTorch.value = false;
        }

        if ('BarcodeDetector' in window) {
            try {
                const supported = await window.BarcodeDetector.getSupportedFormats().catch(() => NATIVE_FORMATS);
                const formats = NATIVE_FORMATS.filter((f) => supported.includes(f));
                detector = new window.BarcodeDetector({ formats: formats.length ? formats : NATIVE_FORMATS });
                usingFallback.value = false;
            } catch {
                detector = null;
            }
        }

        running.value = true;

        if (detector) {
            const loop = async () => {
                if (!running.value) return;
                await detectNative(videoEl);
                timerId = window.setTimeout(loop, 220);
            };
            loop();
        } else {
            usingFallback.value = true;
            const canvas = document.createElement('canvas');
            const loop = async () => {
                if (!running.value) return;
                await detectFallback(videoEl, canvas);
                timerId = window.setTimeout(loop, 450);
            };
            loop();
        }

        return true;
    }

    function stop() {
        running.value = false;
        window.clearTimeout(timerId);
        window.clearTimeout(emitTimer);
        cancelAnimationFrame(rafId);
        if (stream) {
            stream.getTracks().forEach((t) => t.stop());
            stream = null;
        }
        setTorch(false);
        detector = null;
        onDetectCb = null;
    }

    async function setTorch(on) {
        torchOn.value = false;
        if (!stream) return false;
        const track = stream.getVideoTracks()[0];
        if (!track) return false;
        try {
            const caps = track.getCapabilities ? track.getCapabilities() : {};
            if (!caps.torch) return false;
            await track.applyConstraints({ advanced: [{ torch: !!on }] });
            torchOn.value = !!on;
            return !!on;
        } catch {
            return false;
        }
    }

    async function toggleTorch() {
        return setTorch(!torchOn.value);
    }

    return { running, torchOn, canTorch, lastCode, error, usingFallback, start, stop, setTorch, toggleTorch, isScannerSupported };
}

// Barkod → ürün: tek API araması + kısa ömürlü lokal önbellek.
export async function resolveBarcode(code, { ttlMs = CACHE_TTL_MS } = {}) {
    const key = String(code || '').trim();
    if (!key) return null;

    const hit = cache.get(key);
    if (hit && Date.now() - hit.at < ttlMs) return hit.product;

    const res = await client.get('/products', { params: { search: key, per_page: 5 } });
    const list = res.data?.data || [];
    const exact = list.find((p) => p.barcode === key || p.sku === key) || list[0] || null;
    cache.set(key, { product: exact, at: Date.now() });
    return exact;
}

export function clearBarcodeCache() {
    cache.clear();
}
