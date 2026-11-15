<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = [
        'owner_id',
        'name',
        'slug',
        'tax_number',
        'tax_office',
        'email',
        'phone',
        'address',
        'city',
        'country',
        'currency_code',
        'plan',
        'approval_status',
        'subscription_ends_at',
        'allowed_modules',
        'max_users',
        'custom_limits',
        'accepting_applications',
        'application_form_schema',
        'logo_path',
    ];

    protected function casts(): array
    {
        return [
            'subscription_ends_at' => 'datetime',
            'allowed_modules' => 'array',
            'max_users' => 'integer',
            'custom_limits' => 'array',
            'accepting_applications' => 'boolean',
            'application_form_schema' => 'array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function settings(): HasMany
    {
        return $this->hasMany(Setting::class);
    }

    /**
     * Firma başına ayar değeri okur .
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        $value = $this->settings()->where('key', $key)->value('value');

        return $value ?? $default;
    }

    public function setSetting(string $key, mixed $value): void
    {
        $this->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * Firmanın abonelik/kullanım süresi dolmuş mu?
     */
    public function isSubscriptionExpired(): bool
    {
        return $this->subscription_ends_at !== null && $this->subscription_ends_at->isPast();
    }

    /**
     * Firmanın varsayılan başvuru / İK formu şeması.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function defaultApplicationFormSchema(): array
    {
        return [
            [
                'id' => 'first_name',
                'type' => 'text',
                'label_tr' => 'Adınız',
                'label_en' => 'First Name',
                'placeholder_tr' => 'Örn: Ahmet',
                'placeholder_en' => 'e.g. John',
                'width' => 'half',
                'required' => true,
                'enabled' => true,
                'locked' => true,
            ],
            [
                'id' => 'last_name',
                'type' => 'text',
                'label_tr' => 'Soyadınız',
                'label_en' => 'Last Name',
                'placeholder_tr' => 'Örn: Yılmaz',
                'placeholder_en' => 'e.g. Doe',
                'width' => 'half',
                'required' => true,
                'enabled' => true,
                'locked' => true,
            ],
            [
                'id' => 'email',
                'type' => 'email',
                'label_tr' => 'E-posta Adresi',
                'label_en' => 'Email Address',
                'placeholder_tr' => 'ahmet@ornek.com',
                'placeholder_en' => 'john@example.com',
                'width' => 'half',
                'required' => true,
                'enabled' => true,
                'locked' => true,
            ],
            [
                'id' => 'phone',
                'type' => 'tel',
                'label_tr' => 'Telefon Numarası',
                'label_en' => 'Phone Number',
                'placeholder_tr' => '0555 000 00 00',
                'placeholder_en' => '+90 555 000 00 00',
                'width' => 'half',
                'required' => true,
                'enabled' => true,
                'locked' => false,
            ],
            [
                'id' => 'requested_class',
                'type' => 'select',
                'label_tr' => 'Başvurulan Pozisyon / Sınıf',
                'label_en' => 'Applied Position / Class',
                'options_tr' => ['Personel', 'Satış Temsilcisi', 'Kıdemli Muhasebe Uzmanı', 'Depo Sorumlusu', 'Yönetici'],
                'options_en' => ['Staff', 'Sales Representative', 'Senior Accountant', 'Warehouse Supervisor', 'Manager'],
                'width' => 'half',
                'required' => true,
                'enabled' => true,
                'locked' => false,
            ],
            [
                'id' => 'experience_years',
                'type' => 'select',
                'label_tr' => 'Sektör Deneyimi',
                'label_en' => 'Years of Experience',
                'options_tr' => ['Yeni Mezun / 0-1 Yıl', '1-3 Yıl', '3-5 Yıl', '5-10 Yıl', '10+ Yıl'],
                'options_en' => ['Junior / 0-1 Year', '1-3 Years', '3-5 Years', '5-10 Years', '10+ Years'],
                'width' => 'half',
                'required' => false,
                'enabled' => true,
                'locked' => false,
            ],
            [
                'id' => 'education_level',
                'type' => 'select',
                'label_tr' => 'Eğitim Durumu',
                'label_en' => 'Education Level',
                'options_tr' => ['Lise', 'Ön Lisans', 'Lisans', 'Yüksek Lisans / Doktora'],
                'options_en' => ['High School', 'Associate Degree', 'Bachelor Degree', 'Master / PhD'],
                'width' => 'half',
                'required' => false,
                'enabled' => true,
                'locked' => false,
            ],
            [
                'id' => 'address',
                'type' => 'text',
                'label_tr' => 'Şehir / İkamet Adresi',
                'label_en' => 'City / Residential Address',
                'placeholder_tr' => 'İl / İlçe / Mahalle',
                'placeholder_en' => 'City / District',
                'width' => 'half',
                'required' => false,
                'enabled' => true,
                'locked' => false,
            ],
            [
                'id' => 'cv_file',
                'type' => 'file',
                'label_tr' => 'Özgeçmiş (CV) / Portfolyo Yükle (PDF, DOCX, Görsel)',
                'label_en' => 'Upload Resume (CV) / Portfolio (PDF, DOCX, Image)',
                'width' => 'full',
                'required' => false,
                'enabled' => true,
                'locked' => false,
            ],
            [
                'id' => 'message',
                'type' => 'textarea',
                'label_tr' => 'Ön Yazı & Kendinizi Tanıtın',
                'label_en' => 'Cover Letter & About You',
                'placeholder_tr' => 'Deneyimleriniz, yetkinlikleriniz ve başvuru notunuz...',
                'placeholder_en' => 'Your experience, skills, and application note...',
                'width' => 'full',
                'required' => false,
                'enabled' => true,
                'locked' => false,
            ],
        ];
    }

    public function resolvedApplicationFormSchema(): array
    {
        $schema = $this->application_form_schema;
        if (! is_array($schema) || empty($schema)) {
            return static::defaultApplicationFormSchema();
        }

        return $schema;
    }
}
