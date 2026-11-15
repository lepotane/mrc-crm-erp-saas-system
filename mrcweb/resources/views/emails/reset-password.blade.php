<!DOCTYPE html>
<html lang="tr">
<head><meta charset="utf-8"></head>
<body style="font-family: sans-serif; background: #191E2C; color: #FFFFFF; padding: 24px;">
<div style="max-width: 520px; margin: 0 auto; background: #212738; border-radius: 16px; padding: 32px;">
<h2 style="color: #B9FF66;">Şifreni sıfırla</h2>
<p>Merhaba {{ $name }},</p>
<p>Hesabın için şifre sıfırlama istendi. Bağlantı <strong>60 dakika</strong> geçerli, tek kullanımlık gibidir (kullanılınca ölür).</p>
<p><a href="{{ $resetUrl }}" style="display: inline-block; background: #B9FF66; color: #131722; padding: 12px 24px; border-radius: 999px; text-decoration: none; font-weight: bold;">Yeni şifre belirle</a></p>
<p style="color: #94A3B8; font-size: 12px;">Bu isteği sen yapmadıysan bu postayı yok say — şifren değişmez.</p>
</div>
</body>
</html>
