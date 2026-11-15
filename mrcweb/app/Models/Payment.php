<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use SoftDeletes;

    public const DIRECTION_IN = 'in';
    public const DIRECTION_OUT = 'out';

    public const METHOD_CASH = 'cash';
    public const METHOD_BANK = 'bank';
    public const METHOD_CARD = 'card';
    public const METHOD_TRANSFER = 'transfer';

    /**
     * Online tahsilat : ödeme linki üzerinden gelen para, hangi
     * sağlayıcının tahsil ettiğini ayırt eder. Kasa/banka raporunda "el ile girilmiş"
     * tahsilatla aynı satır değildir — sanal POS'ta para gecikmeli ve kesintili gelir.
     */
    public const METHOD_PAYTR = 'paytr';
    public const METHOD_IYZICO = 'iyzico';

    public const METHODS = [
        self::METHOD_CASH,
        self::METHOD_BANK,
        self::METHOD_CARD,
        self::METHOD_TRANSFER,
        self::METHOD_PAYTR,
        self::METHOD_IYZICO,
    ];

    /**
     * Elle girilebilen yöntemler : `paytr`/`iyzico` burada YOKTUR.
     * Online tahsilat yalnız `OnlinePaymentRecorder` ile, imzası doğrulanmış bildirim
     * üzerine yazılır — elle „paytr ile ödendi" kaydı, paranın gerçekten geldiğini
     * kanıtlamaz ve muhasebeyi kirletir.
     */
    public const MANUAL_METHODS = [
        self::METHOD_CASH,
        self::METHOD_BANK,
        self::METHOD_CARD,
        self::METHOD_TRANSFER,
    ];

    protected $fillable = [
        'company_id', 'party_id', 'document_id', 'bank_account_id',
        'direction', 'amount', 'currency_code', 'exchange_rate',
        'method', 'paid_at', 'reference', 'note', 'created_by',
        'tendered_amount', 'change_amount',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'exchange_rate' => 'decimal:6',
        'paid_at' => 'date',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * İşaretli tutar: tahsilat (+) / ödeme (−).
     */
    public function signedAmount(): float
    {
        return $this->direction === self::DIRECTION_IN
            ? (float) $this->amount
            : -1 * (float) $this->amount;
    }
}
