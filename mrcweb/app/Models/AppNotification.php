<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    protected $table = 'app_notifications';

    protected $fillable = [
        'company_id',
        'user_id',
        'type',
        'title',
        'body',
        'action_url',
        'color',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    /**
     * Bir firmadaki tüm üyelere (veya belirli bir kullanıcıya) bildirim gönderir.
     */
    public static function pushToCompany(
        int $companyId,
        string $title,
        ?string $body = null,
        string $type = 'info',
        ?string $actionUrl = '/',
        string $color = '#B9FF66',
        ?int $specificUserId = null
    ): void {
        try {
            if ($specificUserId !== null) {
                static::create([
                    'company_id' => $companyId,
                    'user_id' => $specificUserId,
                    'type' => $type,
                    'title' => $title,
                    'body' => $body,
                    'action_url' => $actionUrl,
                    'color' => $color,
                ]);

                return;
            }

            $company = Company::find($companyId);
            if (! $company) {
                return;
            }

            $userIds = $company->users()->pluck('users.id')->all();
            foreach ($userIds as $uid) {
                static::create([
                    'company_id' => $companyId,
                    'user_id' => $uid,
                    'type' => $type,
                    'title' => $title,
                    'body' => $body,
                    'action_url' => $actionUrl,
                    'color' => $color,
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
