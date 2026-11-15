<!DOCTYPE html>
<html lang="tr">
<head><meta charset="utf-8"></head>
<body style="font-family: sans-serif; background: #191E2C; color: #FFFFFF; padding: 24px;">
<div style="max-width: 520px; margin: 0 auto; background: #212738; border-radius: 16px; padding: 32px;">
<h2 style="color: #B9FF66;">E-postanı doğrula</h2>
<p>Merhaba {{ $name }},</p>
<p>Hesabını kullanmaya başlamak için e-postanı doğrula. Bağlantı <strong>24 saat</strong> geçerlidir.</p>
<p><a href="{{ $verifyUrl }}" style="display: inline-block; background: #B9FF66; color: #131722; padding: 12px 24px; border-radius: 999px; text-decoration: none; font-weight: bold;">E-postamı doğrula</a></p>
<p style="color: #94A3B8; font-size: 12px;">Bu hesabı sen açmadıysan bu postayı yok say.</p>
</div>
</body>
</html>
