<?php

namespace App\Integration\Outbox;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Transactional outbox satırı.
 *
 * Olay önce buraya yazılır, relayer kanala iletir. Durum geçişleri tek yerde
 * toplanır: `new → relaying → published` ya da `failed → dead`.
 */
class OutboxMessage extends Model
{
    public const STATUS_NEW = 'new';

    public const STATUS_RELAYING = 'relaying';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DEAD = 'dead';

    public const MAX_ATTEMPTS = 5;

    protected $table = 'outbox_messages';

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'company_id', 'event_type', 'payload', 'headers',
        'status', 'attempts', 'next_retry_at', 'idempotency_key', 'available_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'next_retry_at' => 'datetime',
        'available_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function markRelaying(): self
    {
        $this->status = self::STATUS_RELAYING;
        $this->save();

        return $this;
    }

    public function markPublished(): self
    {
        $this->status = self::STATUS_PUBLISHED;
        $this->save();

        return $this;
    }

    /**
     * Denemeyi artırır, üstel gecikmeyle yeniden deneme zamanı kurar.
     * 5. denemeden sonra satır `dead` olur (artık otomatik yeniden denenmez).
     */
    public function markFailed(string $error, array $backoffMinutes = [1, 5, 30, 120, 720]): self
    {
        $this->attempts = (int) $this->attempts + 1;

        $minutes = $backoffMinutes === []
            ? 1
            : (int) ($backoffMinutes[min($this->attempts - 1, count($backoffMinutes) - 1)] ?? 1);

        $this->next_retry_at = now()->addMinutes($minutes);
        $this->status = $this->attempts >= self::MAX_ATTEMPTS
            ? self::STATUS_DEAD
            : self::STATUS_FAILED;

        // Hata metni JSON headers içinde tutulur (tabloda ayrı hata kolonu yok).
        $headers = (array) $this->headers;
        $headers['last_error'] = mb_substr($error, 0, 500);
        $this->headers = $headers;

        $this->save();

        return $this;
    }

    public function isRetryable(): bool
    {
        return in_array($this->status, [self::STATUS_NEW, self::STATUS_FAILED], true)
            && $this->attempts < self::MAX_ATTEMPTS;
    }
}
