<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dashboard widget yapılandırması : firma/rol/kullanıcı seviyesi satırlar.
 *
 * Satır YOKSA miras geçer (o seviye sessizdir); satır VARSA enabled/sort/options
 * o seviyenin kararıdır. „Havuz" kuralı: katalog her widget için hangi rollerin
 * görebileceğini söyler (`WidgetRegistry::roles`); kullanıcı satırı havuz DIŞINA
 * widget ekleyemez — API bunu reddeder (403 değil 422: istek anlaşılır ama kural dışı).
 */
class DashboardWidget extends Model
{
    public const SCOPE_COMPANY = 'company';

    public const SCOPE_ROLE = 'role';

    public const SCOPE_USER = 'user';

    public const SCOPES = [self::SCOPE_COMPANY, self::SCOPE_ROLE, self::SCOPE_USER];

    protected $fillable = [
        'company_id', 'scope', 'subject', 'widget_key', 'enabled', 'sort_order', 'options',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'sort_order' => 'integer',
        'options' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
