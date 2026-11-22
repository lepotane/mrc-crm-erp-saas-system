<?php

namespace App\Integration\Translate;

/**
 * Best-effort OFFLINE product name translation (no external API, fully deterministic).
 *
 * Only known whole-word terms are replaced; model numbers, codes, frequencies
 * (e.g. 433MHz, ID46, 95440-N9520) and unknown words/brands are left untouched.
 * This is NOT a real translation: the original English name is always stored
 * separately in products.name_en, so nothing is lost when a term is unknown.
 */
final class ProductNameTranslator
{
    /** @var array<string,string> lowercase English term => Turkish term */
    private const TERMS = [
        // Multi-word terms first (the regex also sorts by length at runtime).
        'key programming' => 'Anahtar Programlama',
        'cutting machine' => 'Kesim Makinesi',
        'remote control' => 'Uzaktan Kumanda',
        'online service' => 'Online Hizmet',
        'door lock' => 'Kapı Kilidi',
        'ignition switch' => 'Kontak Anahtarı',
        'key shell' => 'Anahtar Kabuğu',
        'flip key' => 'Katlanır Anahtar',
        'mechanical key' => 'Mekanik Anahtar',
        'smart key' => 'Akıllı Anahtar',
        'smart keys' => 'Akıllı Anahtarlar',
        'key shells' => 'Anahtar Kabukları',

        // Single words / plurals.
        'adapter' => 'Adaptör',
        'adapters' => 'Adaptörler',
        'aftermarket' => 'Muadil',
        'battery' => 'Pil',
        'batteries' => 'Piller',
        'blade' => 'Bıçak',
        'blades' => 'Bıçaklar',
        'button' => 'Tuş',
        'buttons' => 'Tuşlar',
        'chip' => 'Çip',
        'chips' => 'Çipler',
        'cover' => 'Kılıf',
        'covers' => 'Kılıflar',
        'flip' => 'Katlanır',
        'for' => 'için',
        'frequency' => 'Frekans',
        'ignition' => 'Kontak',
        'key' => 'Anahtar',
        'lock' => 'Kilit',
        'mechanical' => 'Mekanik',
        'module' => 'Modül',
        'modules' => 'Modüller',
        'oem' => 'OEM',
        'original' => 'Orijinal',
        'pcb' => 'Devre Kartı',
        'programmer' => 'Programlayıcı',
        'programming' => 'Programlama',
        'remote' => 'Kumanda',
        'remotes' => 'Kumandalar',
        'replacement' => 'Yedek',
        'rfid' => 'RFID',
        'sensor' => 'Sensör',
        'sensors' => 'Sensörler',
        'service' => 'Hizmet',
        'services' => 'Hizmetler',
        'shell' => 'Kabuk',
        'shells' => 'Kabuklar',
        'tag' => 'Etiket',
        'tags' => 'Etiketler',
        'transponder' => 'Transponder',
        'transponders' => 'Transponderlar',
        'trunk' => 'Bagaj',
        'universal' => 'Evrensel',
        'with' => 'ile',
    ];

    private static ?string $pattern = null;

    public function toTurkish(string $english): string
    {
        $original = trim($english);
        if ($original === '') {
            return '';
        }

        $result = preg_replace_callback(
            self::pattern(),
            static fn (array $match): string => self::TERMS[mb_strtolower($match[0], 'UTF-8')] ?? $match[0],
            $original,
        );

        return is_string($result) ? $result : $original;
    }

    /** Longest terms first so phrases (e.g. "Key Programming") win over "Key". */
    private static function pattern(): string
    {
        if (self::$pattern === null) {
            $terms = array_keys(self::TERMS);
            usort($terms, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
            $quoted = array_map(static fn (string $term): string => preg_quote($term, '/'), $terms);
            self::$pattern = '/\b(?:'.implode('|', $quoted).')\b/iu';
        }

        return self::$pattern;
    }
}
