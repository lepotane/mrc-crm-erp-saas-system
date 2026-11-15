<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Şifre sıfırlama postası .
 *
 * Bağlantı SPA sayfasına gider (`/sifre-sifirla?token=…&email=…`); ham token
 * yalnız bu postada bulunur, veritabanında özeti durur (60 dk ömür).
 */
class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $resetUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Şifreni sıfırla — :app', ['app' => config('app.name')]),
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.reset-password',
            with: ['name' => $this->name, 'resetUrl' => $this->resetUrl],
        );
    }
}
