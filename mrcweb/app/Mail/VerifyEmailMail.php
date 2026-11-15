<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * E-posta doğrulama postası .
 *
 * Bağlantı imzalı API ucuna gider (`GET /auth/email/verify/{id}/{hash}`,
 * 24 saat ömür); uç doğrulayıp SPA başarı sayfasına 302 atar.
 */
class VerifyEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $verifyUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('E-postanı doğrula — :app', ['app' => config('app.name')]),
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.verify-email',
            with: ['name' => $this->name, 'verifyUrl' => $this->verifyUrl],
        );
    }
}
