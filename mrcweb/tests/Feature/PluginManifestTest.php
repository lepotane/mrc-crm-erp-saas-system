<?php

namespace Tests\Feature;

use App\Modules\ModuleRegistry;
use Tests\TestCase;

/**
 * Eklenti (plugin) manifest şeması kilidi.
 *
 * 07 Kas'ta `hedef-takip` ve `ik-basvuru-pro` manifestleri eski şemadaydı
 * (name/description + widgets yok). ModuleRegistry::manifestFor() şemayı
 * doğrulayıp null döndürdüğü için iki eklenti sessizce katalogdan kayboldu —
 * "eklenti görünmüyor" şeklinde bir hata olarak çıkıyordu.
 *
 * Bu test her plugin/ klasörünün:
 *   - geçerli manifest ürettiğini,
 *   - widget'larının (key/title_key/roles) eksiksiz olduğunu,
 *   - başlıklarının EN karşılığının bulunduğunu
 * kilitler.
 */
class PluginManifestTest extends TestCase
{
    protected function pluginDirs(): array
    {
        $dir = base_path('plugins');

        if (! is_dir($dir)) {
            return [];
        }

        return array_values(array_filter(scandir($dir), fn ($e) => $e[0] !== '.' && is_dir($dir.'/'.$e)));
    }

    public function test_her_eklenti_gecerli_manifest_uretir(): void
    {
        $dirs = $this->pluginDirs();
        $this->assertNotEmpty($dirs, 'plugins/ klasörü boş olmamalı.');

        foreach ($dirs as $key) {
            $manifest = ModuleRegistry::manifestFor($key);

            $this->assertNotNull(
                $manifest,
                "plugins/{$key}/manifest.json geçersiz (manifestFor null döndü). name_tr + version + widgets[] şeması zorunlu."
            );
            $this->assertSame($key, $manifest['key']);
            $this->assertNotSame('', trim((string) $manifest['name_tr']));
            $this->assertNotSame('', trim((string) $manifest['version']));
            $this->assertIsArray($manifest['widgets']);
        }
    }

    public function test_eklenti_widget_lari_eksiksiz(): void
    {
        foreach ($this->pluginDirs() as $key) {
            $manifest = ModuleRegistry::manifestFor($key);

            if ($manifest === null) {
                continue;
            }

            foreach ($manifest['widgets'] as $widget) {
                $this->assertNotSame('', trim((string) $widget['key']), "{$key}: widget.key boş.");
                $this->assertNotSame('', trim((string) $widget['title_key']), "{$key}: widget.title_key boş.");
                $this->assertNotEmpty($widget['roles'], "{$key}: widget.roles boş olamaz.");
            }
        }
    }

    public function test_eklenti_basliklarinin_ingilizce_karsiligi_var(): void
    {
        $en = json_decode((string) file_get_contents(base_path('resources/js/i18n/locales/en.json')), true);

        $this->assertIsArray($en);

        foreach ($this->pluginDirs() as $key) {
            $manifest = ModuleRegistry::manifestFor($key);

            if ($manifest === null) {
                continue;
            }

            // Widget başlıkları $t(widget.title_key) ile çözülür → EN karşılığı şart.
            // (Eklentinin kendi adı name_tr/name_en olarak İKİ dille gelir, çeviri
            //  anahtarı değildir.)
            foreach ($manifest['widgets'] as $widget) {
                $this->assertArrayHasKey(
                    $widget['title_key'],
                    $en,
                    "plugins/{$key}: widget title_key için EN karşılığı yok."
                );
            }
        }
    }
}
