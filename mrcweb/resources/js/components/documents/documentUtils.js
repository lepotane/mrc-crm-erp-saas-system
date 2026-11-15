// Belgeler (teklif / sipariş / fatura / alım) için paylaşılan yardımcılar.
//
// Etiket fonksiyonları çeviri ANAHTARI döndürür; $t() şablonda uygulanır
// (dil değişimi reaktif kalır). Tutarlılık: Cari→Account, Belge→Document,
// Teklif→Quotation, Sipariş→Sales order, Fatura→Invoice,
// Alım faturası→Purchase invoice, İade faturası→Return invoice,
// İhraç→Issued, Taslak→Draft.

export function documentTypeLabel(type) {
    if (type === 'quote') return 'Teklif';
    if (type === 'sales_order') return 'Sipariş';
    if (type === 'invoice') return 'Fatura';
    if (type === 'purchase') return 'Alım faturası';
    if (type === 'return') return 'İade faturası';
    return type || '-';
}

export function documentTypeColor(type) {
    if (type === 'quote') return 'cyan';
    if (type === 'sales_order') return 'violet';
    if (type === 'invoice') return 'lime';
    if (type === 'purchase') return 'orange';
    if (type === 'return') return 'coral';
    return 'neutral';
}

export function documentStatusLabel(status) {
    if (status === 'draft') return 'Taslak';
    if (status === 'sent') return 'Gönderildi';
    if (status === 'accepted') return 'Kabul edildi';
    if (status === 'rejected') return 'Reddedildi';
    if (status === 'confirmed') return 'Onaylandı';
    if (status === 'issued') return 'İhraç';
    if (status === 'cancelled') return 'İptal';
    if (status === 'paid') return 'Ödendi';
    if (status === 'partially_paid') return 'Kısmen ödendi';
    return status || '-';
}

export function documentStatusColor(status) {
    if (status === 'draft') return 'neutral';
    if (status === 'sent') return 'cyan';
    if (status === 'accepted' || status === 'confirmed' || status === 'issued') return 'lime';
    if (status === 'rejected' || status === 'cancelled') return 'coral';
    if (status === 'paid') return 'violet';
    return 'orange';
}

/**
 * Belgenin bir sonraki DURUM eylemleri (kapalı makinenin `Document::TRANSITIONS`
 * aynası). Yanlış türe yanlış fiil önerilmez.
 *
 * Etiketler çeviri anahtarıdır; $t() şablonda uygulanır.
 *
 * @returns {Array<{status: string, label: string, variant: string}>}
 */
export function statusActionsFor(doc) {
    if (!doc) return [];

    if (doc.type === 'quote') {
        if (doc.status === 'draft') return [{ status: 'sent', label: 'Gönderildi yap', variant: 'primary' }];
        if (doc.status === 'sent') {
            return [
                { status: 'accepted', label: 'Kabul et', variant: 'primary' },
                { status: 'rejected', label: 'Reddet', variant: 'ghost' },
            ];
        }
        return [];
    }

    if (doc.type === 'sales_order') {
        if (doc.status === 'draft') return [{ status: 'confirmed', label: 'Onayla', variant: 'primary' }];
        return [];
    }

    if (doc.type === 'invoice' || doc.type === 'purchase' || doc.type === 'return') {
        if (doc.status === 'draft') return [{ status: 'issued', label: 'İhraç et', variant: 'primary' }];
        return [];
    }

    return [];
}

// İade açılabilir mi: kesilmiş satış/alış faturası (taslak/iptal değil).
export function canReturn(doc) {
    if (!doc) return false;
    if (doc.type !== 'invoice' && doc.type !== 'purchase') return false;
    return doc.status !== 'draft' && doc.status !== 'cancelled';
}

/**
 * Faturadan iade aç : satırlar kaynaktan dolar, miktar budanabilir.
 * Sunucu kalanı denetler (kaynağı aşan istek 422).
 */
export async function submitReturn(client, doc, lines) {
    const payload = {};
    if (Array.isArray(lines) && lines.length) {
        payload.lines = lines.map((l) => ({ product_id: l.product_id, quantity: l.quantity }));
    }
    const res = await client.post(`/documents/${doc.id}/return`, payload);
    return res.data;
}

// Satış akışındaki bir sonraki dönüştürme hedefi (yoksa null).
export function convertTargetFor(doc) {
    if (!doc) return null;
    if (doc.type === 'quote') return 'sales_order';
    if (doc.type === 'sales_order') return 'invoice';
    return null;
}

// Sunucudaki kapalı durum makinesi (Document::TRANSITIONS) ile birebir: iptal yalnız
// taslak aşamasında (teklif için ayrıca „gönderildi”). İhraç edilmiş fatura/alım v1’de
// iptal edilemez — buton gösterilmez, düzeltme yeni belge ile yapılır.
export function canCancel(doc) {
    if (!doc) return false;
    if (doc.status === 'draft') return true;

    return doc.type === 'quote' && doc.status === 'sent';
}

export function convertLabelFor(doc) {
    const to = convertTargetFor(doc);
    if (to === 'sales_order') return 'Siparişe dönüştür';
    if (to === 'invoice') return 'Faturaya dönüştür';
    return '';
}

/**
 * Durum geçişi sonrası gösterilecek onay mesajının çeviri anahtarı.
 * Mesajlar fiille birlikte verilir ki kullanıcı neyin değiştiğini tek kelimeyle anlasın
 * ("işlem tamam" yerine "teklif gönderildi").
 */
export function statusSuccessMessage(status) {
    if (status === 'issued') return 'Belge ihraç edildi.';
    if (status === 'cancelled') return 'Belge iptal edildi.';
    if (status === 'sent') return 'Teklif gönderildi.';
    if (status === 'accepted') return 'Teklif kabul edildi.';
    if (status === 'rejected') return 'Teklif reddedildi.';
    if (status === 'confirmed') return 'Sipariş onaylandı.';
    return 'Durum güncellendi.';
}

// Boş satırın başlangıç KDV'si firma ayarından gelir ; ayar okunamamışsa
// sistem varsayılanı %20. Ürün seçilince ürün kartının KDV oranı bu değeri ezer.
export function blankLine(varsayilanKdv = 20) {
    return {
        product_id: null,
        name: '',
        quantity: 1,
        unit: '',
        unit_price: 0,
        price_currency_code: null,
        discount_rate: 0,
        tax_rate: Number(varsayilanKdv) || 0,
    };
}

// Tek satır — sunucu formülüyle birebir:
// iskonto = satır tutarı * discount_rate / 100 (discount_rate yüzde 0-100);
// KDV iskonto düşülmüş tutar üzerinden; genel toplam = ara toplam − iskonto + KDV.
export function calcLine(line) {
    const qty = Number(line.quantity) || 0;
    const price = Number(line.unit_price) || 0;
    const gross = qty * price;
    const discount = gross * ((Number(line.discount_rate) || 0) / 100);
    const net = gross - discount;
    const tax = net * ((Number(line.tax_rate) || 0) / 100);
    return { gross, discount, net, tax, total: net + tax };
}

export function calcTotals(lines) {
    return (lines || []).reduce(
        (acc, l) => {
            const c = calcLine(l);
            acc.subtotal += c.gross;
            acc.discount += c.discount;
            acc.tax += c.tax;
            acc.grand += c.total;
            return acc;
        },
        { subtotal: 0, discount: 0, tax: 0, grand: 0 }
    );
}

export function firstMessage(err) {
    if (!err) return '';
    return Array.isArray(err) ? err[0] : err;
}

// Sunucu iş kuralı hataları düz (noktalı) anahtarlarla gelir: "items.0.quantity".
// Bir alan grubunun (ör. satırlar) ilk mesajını bulmak için kullanılır.
export function firstErrorFor(errors, prefix) {
    if (!errors) return '';

    for (const [key, value] of Object.entries(errors)) {
        if (key === prefix || key.startsWith(`${prefix}.`)) {
            const msg = firstMessage(value);
            if (msg) return msg;
        }
    }

    return '';
}

// API'nin gerçek alanı `serial` (örn. FTR-26-00001). Diğer adlar arayüzde yoktu;
// fallback'ler yalnız olası bir alan değişikliğinde sessiz çökme yerine görünür kalsın.
export function documentSerial(d) {
    if (!d) return '-';
    return d.serial ?? d.serial_number ?? d.number ?? d.document_number ?? d.code ?? ('#' + d.id);
}

// GET /documents/{id} satırları belge nesnesinin İÇİNDE değil, kardeş anahtar olarak
// verir: { document: {...}, items: [...] }. Çekmecenin ve düzenleme formunun satırları
// görebilmesi için belge nesnesine birleştirilir.
export function documentWithItems(resData) {
    const doc = resData?.document ?? resData?.data ?? resData ?? null;
    if (!doc) return null;
    const items = Array.isArray(resData?.items) ? resData.items : (doc.items ?? doc.lines ?? []);

    return { ...doc, items };
}

// PDF indir: GET /documents/{id}/pdf?inline=1&lang= → application/pdf.
// Uç JSON değil; blob hata gövdeleri ayrıştırılmaz, çağıran genel mesaj gösterir.
export async function downloadDocumentPdf(doc, lang = 'tr') {
    const { default: client } = await import('../../api/client');
    const res = await client.get(`/documents/${doc.id}/pdf`, {
        params: { inline: 1, lang },
        responseType: 'blob',
    });
    const blob = res.data instanceof Blob ? res.data : new Blob([res.data], { type: 'application/pdf' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${documentSerial(doc)}.pdf`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 5000);
}

// İrsaliye indir: GET /documents/{id}/delivery-note?lang= → application/pdf (fiyatsız).
export async function downloadDeliveryNote(doc, lang = 'tr') {
    const { default: client } = await import('../../api/client');
    const res = await client.get(`/documents/${doc.id}/delivery-note`, {
        params: { inline: 1, lang },
        responseType: 'blob',
    });
    const blob = res.data instanceof Blob ? res.data : new Blob([res.data], { type: 'application/pdf' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `irsaliye-${documentSerial(doc)}.pdf`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 5000);
}
