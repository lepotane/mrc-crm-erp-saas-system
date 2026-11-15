<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $link ? __('Ödeme Bağlantısı') : __('Bağlantı geçersiz') }} — {{ $link?->company?->name ?: 'MRC' }}</title>
    @if ($refresh)
        <meta http-equiv="refresh" content="4">
    @endif
    <style>
        /* Arayüz dili (TASARIM-SISTEMI.md): koyu kanvas #191E2C, kart #131722,
           vurgu #B9FF66. Bu sayfa SPA dışındadır (ödemeyi yapan müşteri giriş yapmaz),
           bu yüzden build'e bağımlı olmadan tek dosyada durur. */
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: #191E2C; color: #fff; padding: 24px;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        .card {
            width: 100%; max-width: 520px; background: #131722; border: 1px solid rgba(255,255,255,.08);
            border-radius: 20px; padding: 28px; box-shadow: 0 18px 50px rgba(0,0,0,.35);
        }
        .brand { display: flex; align-items: center; gap: 10px; margin-bottom: 18px; }
        .dot { width: 10px; height: 10px; border-radius: 999px; background: #B9FF66; }
        .brand span { font-size: 13px; letter-spacing: .08em; text-transform: uppercase; color: #94A3B8; font-weight: 700; }
        h1 { font-size: 22px; margin: 0 0 6px; letter-spacing: -.01em; }
        .sub { color: #94A3B8; font-size: 14px; margin: 0 0 22px; line-height: 1.5; }
        .amount { font-size: 34px; font-weight: 800; letter-spacing: -.02em; }
        .rows { margin-top: 20px; border-top: 1px solid rgba(255,255,255,.08); padding-top: 14px; }
        .row { display: flex; justify-content: space-between; gap: 16px; font-size: 14px; padding: 7px 0; }
        .row b { font-weight: 700; }
        .row span { color: #94A3B8; }
        .pill { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: 999px;
            font-size: 13px; font-weight: 700; border: 1px solid transparent; }
        .pill.paid { background: rgba(185,255,102,.14); color: #B9FF66; border-color: rgba(185,255,102,.3); }
        .pill.pending { background: rgba(110,168,254,.14); color: #8DB8FE; border-color: rgba(110,168,254,.3); }
        .pill.failed, .pill.cancelled { background: rgba(197,80,80,.16); color: #FFA0A0; border-color: rgba(197,80,80,.34); }
        .note { margin-top: 18px; font-size: 13px; color: #94A3B8; line-height: 1.55; }
        .lang { margin-top: 22px; display: flex; gap: 10px; font-size: 13px; }
        .lang a { color: #94A3B8; text-decoration: none; border: 1px solid rgba(255,255,255,.1); padding: 6px 10px; border-radius: 999px; }
        .lang a.on { color: #0B0E14; background: #B9FF66; border-color: #B9FF66; font-weight: 700; }
    </style>
</head>
<body>
    <main class="card">
        <div class="brand">
            <span class="dot"></span>
            <span>{{ __('MRC ile güvenli ödeme') }}</span>
        </div>

        @if (! $link)
            <h1>{{ __('Bağlantı geçersiz') }}</h1>
            <p class="sub">{{ __('Bu ödeme bağlantısı bulunamadı. Lütfen firmadan yeni bir bağlantı isteyin.') }}</p>
        @else
            <h1>{{ $link->company?->name ?: __('Firma') }}</h1>
            <p class="sub">
                @if ($link->document)
                    {{ __('Belge') }}: {{ $link->document->serial }}
                @else
                    {{ __('Ödeme bağlantısı') }}
                @endif
            </p>

            <div class="amount">{{ number_format((float) $link->amount, 2, ',', '.') }} {{ $link->currency_code }}</div>

            <div class="rows">
                <div class="row">
                    <span>{{ __('Ödeme durumu') }}</span>
                    <b><span class="pill {{ $link->status }}">{{ \App\Models\PaymentLink::statusLabel($link->status) }}</span></b>
                </div>
                @if ($link->status === \App\Models\PaymentLink::STATUS_PAID && $link->paid_at)
                    <div class="row">
                        <span>{{ __('Ödeme tarihi') }}</span>
                        <b>{{ $link->paid_at->format('d.m.Y H:i') }}</b>
                    </div>
                @endif
                @if ($link->expires_at)
                    <div class="row">
                        <span>{{ __('Son geçerlilik tarihi') }}</span>
                        <b>{{ $link->expires_at->format('d.m.Y H:i') }}</b>
                    </div>
                @endif
            </div>

            @if ($link->status === \App\Models\PaymentLink::STATUS_PAID)
                <p class="note">{{ __('Ödemeniz alındı. Teşekkür ederiz.') }}</p>
            @elseif ($link->status === \App\Models\PaymentLink::STATUS_PENDING)
                <p class="note">{{ __('Ödemeniz hâlâ bekleniyor. Bu sayfa birkaç saniyede bir kendini yeniler.') }}</p>
            @elseif ($link->failure_reason)
                <p class="note">{{ $link->failure_reason }}</p>
            @else
                <p class="note">{{ __('Bu bağlantı kapatıldı.') }}</p>
            @endif
        @endif

        <div class="lang">
            <a href="?dil=tr" class="{{ app()->getLocale() === 'tr' ? 'on' : '' }}">Türkçe</a>
            <a href="?dil=en" class="{{ app()->getLocale() === 'en' ? 'on' : '' }}">English</a>
        </div>
    </main>
</body>
</html>
