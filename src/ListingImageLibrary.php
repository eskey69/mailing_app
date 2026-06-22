<?php

declare(strict_types=1);

namespace MailingApp;

use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class ListingImageLibrary
{
    private const DEFAULT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    private array $config;
    private ?PDO $pdo;
    private bool $usageTableReady = false;

    public function __construct(array $config = [], ?PDO $pdo = null)
    {
        $this->config = is_array($config['photo_library'] ?? null) ? $config['photo_library'] : [];
        $this->pdo = $pdo;
    }

    public function isEnabled(): bool
    {
        return trim((string) ($this->config['source_url'] ?? '')) !== ''
            && trim((string) ($this->config['source_path'] ?? '')) !== '';
    }

    public function selectForLead(array $lead, array $category): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
        $existingImages = is_array($meta['listing_images'] ?? null) ? array_values($meta['listing_images']) : [];
        if (($meta['listing_image_source'] ?? '') === 'photo_library' && $existingImages !== []) {
            $imageKey = trim((string) ($meta['listing_image_key'] ?? ''));
            $resolvedPath = $imageKey !== '' ? $this->resolveAbsoluteSourcePath($imageKey) : '';

            return [
                'images' => $existingImages,
                'source' => 'photo_library',
                'category_key' => (string) ($meta['listing_image_category_key'] ?? ''),
                'image_key' => $imageKey,
                'image_theme' => (string) ($meta['listing_image_theme'] ?? ''),
                'path' => $resolvedPath,
                'url' => trim((string) ($existingImages[0] ?? '')),
                'existing' => true,
            ];
        }

        $categoryKey = $this->buildCategoryKey($lead, $category);
        $selectionTheme = '';
        $pool = [];
        foreach ($this->resolvePreferredThemes($lead, $category) as $theme) {
            $pool = $this->discoverPool($lead, $category, $theme);
            if ($pool !== []) {
                $selectionTheme = $theme;
                break;
            }
        }

        if ($pool === []) {
            return [];
        }

        $selected = $this->selectLeastUsedImage($categoryKey, $pool);
        if ($selected === []) {
            return [];
        }

        return [
            'images' => [$selected['url']],
            'source' => 'photo_library',
            'category_key' => $categoryKey,
            'image_key' => $selected['key'],
            'image_theme' => $selectionTheme,
            'path' => $selected['path'],
            'url' => $selected['url'],
            'existing' => false,
        ];
    }

    public function recordUsage(array $selection, int $leadId): void
    {
        if (($selection['source'] ?? '') !== 'photo_library' || ($selection['existing'] ?? false) === true) {
            return;
        }

        $categoryKey = trim((string) ($selection['category_key'] ?? ''));
        $imageKey = trim((string) ($selection['image_key'] ?? ''));
        $imageUrl = trim((string) ($selection['url'] ?? ''));

        if ($this->pdo === null || $categoryKey === '' || $imageKey === '' || $imageUrl === '') {
            return;
        }

        $this->ensureUsageTable();

        $statement = $this->pdo->prepare(
            'INSERT INTO listing_image_usage (category_key, image_key, image_url, use_count, last_used_at, last_lead_id)
             VALUES (:category_key, :image_key, :image_url, 1, NOW(), :last_lead_id)
             ON DUPLICATE KEY UPDATE
                image_url = VALUES(image_url),
                use_count = use_count + 1,
                last_used_at = NOW(),
                last_lead_id = VALUES(last_lead_id)'
        );
        $statement->execute([
            'category_key' => $categoryKey,
            'image_key' => $imageKey,
            'image_url' => $imageUrl,
            'last_lead_id' => $leadId,
        ]);
    }

    private function discoverPool(array $lead, array $category, string $theme = ''): array
    {
        $sourcePath = rtrim(trim((string) ($this->config['source_path'] ?? '')), '/');
        if ($sourcePath === '' || !is_dir($sourcePath)) {
            return [];
        }

        $folders = $this->resolveCategoryFolders($sourcePath, $lead, $category);
        $pool = [];

        foreach ($folders as $folder) {
            foreach ($this->scanImages($folder, $sourcePath) as $image) {
                if ($theme !== '' && (string) ($image['theme'] ?? '') !== $theme) {
                    continue;
                }
                $pool[$image['key']] = $image;
            }
        }

        ksort($pool);

        return array_values($pool);
    }

    private function resolveCategoryFolders(string $sourcePath, array $lead, array $category): array
    {
        $categoryId = (int) ($category['id'] ?? 0);
        $configuredFolders = is_array($this->config['category_folders'] ?? null) ? $this->config['category_folders'] : [];
        $folderNames = [];

        if ($categoryId > 0 && isset($configuredFolders[$categoryId])) {
            $folderNames[] = (string) $configuredFolders[$categoryId];
        }
        if ($categoryId > 0 && isset($configuredFolders[(string) $categoryId])) {
            $folderNames[] = (string) $configuredFolders[(string) $categoryId];
        }

        $categoryAlias = $this->slugify((string) ($category['alias'] ?? ''));
        $categoryName = $this->slugify((string) ($category['name'] ?? ''));
        $leadCategory = $this->slugify((string) ($lead['category'] ?? ''));

        foreach (array_filter([$categoryAlias, $categoryName, $leadCategory]) as $slug) {
            if ($categoryId > 0) {
                $folderNames[] = $categoryId . '_' . $slug;
            }
            $folderNames[] = $slug;
        }

        if ($categoryId > 0) {
            $folderNames[] = (string) $categoryId;
        }

        $folders = [];
        foreach (array_unique(array_filter($folderNames)) as $folderName) {
            $candidate = $sourcePath . '/' . trim($folderName, '/');
            if (is_dir($candidate)) {
                $folders[] = $candidate;
            }
        }

        if ($folders === [] && $categoryId > 0) {
            $prefix = $categoryId . '_';
            foreach (scandir($sourcePath) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $candidate = $sourcePath . '/' . $entry;
                if (is_dir($candidate) && str_starts_with($entry, $prefix)) {
                    $folders[] = $candidate;
                }
            }
        }

        return array_values(array_unique($folders));
    }

    private function scanImages(string $folder, string $sourcePath): array
    {
        $extensions = is_array($this->config['extensions'] ?? null)
            ? array_map('strtolower', array_map('strval', $this->config['extensions']))
            : self::DEFAULT_EXTENSIONS;
        $maxDepth = max(0, (int) ($this->config['max_depth'] ?? 2));
        $result = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            $relativeToFolder = trim(str_replace('\\', '/', substr($file->getPathname(), strlen($folder))), '/');
            if ($maxDepth > 0 && substr_count($relativeToFolder, '/') > $maxDepth) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (!in_array($extension, $extensions, true)) {
                continue;
            }

            $relative = trim(str_replace('\\', '/', substr($file->getPathname(), strlen($sourcePath))), '/');
            if ($relative === '') {
                continue;
            }
            $relativeToCategory = trim(str_replace('\\', '/', substr($file->getPathname(), strlen($folder))), '/');
            $theme = '';
            if (str_contains($relativeToCategory, '/')) {
                $theme = $this->slugify((string) strtok($relativeToCategory, '/'));
            }

            $result[] = [
                'key' => $relative,
                'path' => $file->getPathname(),
                'url' => $this->buildPublicUrl($relative),
                'theme' => $theme,
            ];
        }

        return $result;
    }

    private function resolvePreferredThemes(array $lead, array $category): array
    {
        $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
        $themes = [];

        foreach ([
            (string) ($meta['listing_visual_subtype'] ?? ''),
            (string) ($meta['visual_subtype'] ?? ''),
            (string) ($meta['listing_image_theme'] ?? ''),
            (string) ($category['visual_subtype'] ?? ''),
        ] as $candidate) {
            $theme = $this->normalizeTheme($candidate);
            if ($theme !== '') {
                $themes[] = $theme;
            }
        }

        foreach ($this->inferThemesFromText($lead, $category) as $theme) {
            $themes[] = $theme;
        }

        $themes[] = 'general';
        $themes[] = '';

        return array_values(array_unique($themes));
    }

    private function inferThemesFromText(array $lead, array $category): array
    {
        $text = strtolower(implode(' ', [
            (string) ($lead['company_name'] ?? ''),
            (string) ($lead['category'] ?? ''),
            (string) ($lead['website'] ?? ''),
            (string) ($category['name'] ?? ''),
            (string) ($category['matched_keyword'] ?? ''),
        ]));

        $rules = [
            'marketing' => ['advertising', 'marketing', 'branding', 'social media', 'promotion', 'promotional', 'copywriting', 'graphic design', 'print shop'],
            'construction' => ['construction', 'contractor', 'roofing', 'remodel', 'renovation', 'flooring', 'drywall', 'plumbing', 'electric', 'hvac'],
            'transport' => ['moving', 'movers', 'transport', 'trucking', 'delivery', 'courier', 'freight', 'shipping'],
            'warehouse' => ['warehouse', 'logistics', 'fulfillment', 'inventory'],
            'cleaning' => ['cleaning', 'janitorial', 'maid'],
            'caregiver' => ['caregiver', 'care giver', 'home care', 'senior care', 'child care', 'nanny'],
            'beauty' => ['beauty', 'salon', 'spa', 'cosmetic', 'massage', 'nails', 'manicure'],
            'restaurant' => ['restaurant', 'food', 'catering', 'bakery', 'cafe'],
            'office' => ['office', 'administrative', 'accounting', 'bookkeeping', 'staffing', 'employment agency', 'recruiting'],
            'medical' => ['medical', 'doctor', 'dentist', 'dental', 'therapy', 'therapist', 'chiropractic', 'health'],
            'it' => ['software', 'computer', 'web design', 'website', 'seo', 'developer', 'cybersecurity', 'hosting'],
            'sales' => ['sales', 'retail', 'store', 'shop', 'boutique', 'wholesale'],
            'education' => ['school', 'tutoring', 'course', 'training', 'lesson', 'academy', 'class'],
            'home_services' => ['home service', 'home services', 'repair', 'maintenance', 'handyman'],
            'insurance' => ['insurance', 'insured', 'insurer', 'coverage', 'policy', 'medicare'],
            'legal_finance' => ['law', 'legal', 'attorney', 'lawyer', 'tax', 'finance', 'financial', 'payroll'],
            'real_estate' => ['real estate', 'realtor', 'property', 'mortgage', 'apartment', 'rental', 'brokerage'],
        ];

        foreach ($rules as $theme => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return [$theme];
                }
            }
        }

        return [];
    }

    private function normalizeTheme(string $value): string
    {
        $value = $this->slugify($value);
        $aliases = [
            'advertising' => 'marketing',
            'branding' => 'marketing',
            'promotion' => 'marketing',
            'promotional' => 'marketing',
            'social_media' => 'marketing',
            'legal' => 'legal_finance',
            'finance' => 'legal_finance',
            'financial' => 'legal_finance',
            'realestate' => 'real_estate',
            'realty' => 'real_estate',
            'health' => 'medical',
            'healthcare' => 'medical',
            'care' => 'caregiver',
            'homecare' => 'caregiver',
            'technology' => 'it',
            'internet' => 'it',
        ];
        $value = $aliases[$value] ?? $value;

        $allowed = [
            'general',
            'marketing',
            'construction',
            'transport',
            'warehouse',
            'cleaning',
            'caregiver',
            'beauty',
            'restaurant',
            'office',
            'medical',
            'it',
            'sales',
            'education',
            'home_services',
            'insurance',
            'legal_finance',
            'real_estate',
        ];

        return in_array($value, $allowed, true) ? $value : '';
    }

    private function selectLeastUsedImage(string $categoryKey, array $pool): array
    {
        if ($pool === []) {
            return [];
        }

        $usage = $this->fetchUsage($categoryKey);
        usort($pool, static function (array $left, array $right) use ($usage): int {
            $leftUsage = $usage[$left['key']]['use_count'] ?? 0;
            $rightUsage = $usage[$right['key']]['use_count'] ?? 0;
            if ($leftUsage !== $rightUsage) {
                return $leftUsage <=> $rightUsage;
            }

            $leftDate = (string) ($usage[$left['key']]['last_used_at'] ?? '');
            $rightDate = (string) ($usage[$right['key']]['last_used_at'] ?? '');
            if ($leftDate !== $rightDate) {
                return $leftDate <=> $rightDate;
            }

            return strcmp((string) $left['key'], (string) $right['key']);
        });

        return $pool[0];
    }

    private function fetchUsage(string $categoryKey): array
    {
        if ($this->pdo === null || $categoryKey === '') {
            return [];
        }

        $this->ensureUsageTable();

        $statement = $this->pdo->prepare(
            'SELECT image_key, use_count, last_used_at
             FROM listing_image_usage
             WHERE category_key = :category_key'
        );
        $statement->execute(['category_key' => $categoryKey]);

        $usage = [];
        foreach ($statement->fetchAll() as $row) {
            $usage[(string) $row['image_key']] = [
                'use_count' => (int) ($row['use_count'] ?? 0),
                'last_used_at' => (string) ($row['last_used_at'] ?? ''),
            ];
        }

        return $usage;
    }

    private function ensureUsageTable(): void
    {
        if ($this->usageTableReady || $this->pdo === null) {
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS listing_image_usage (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                category_key VARCHAR(128) NOT NULL,
                image_key VARCHAR(255) NOT NULL,
                image_url VARCHAR(1024) NOT NULL,
                use_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_used_at DATETIME NULL,
                last_lead_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_listing_image_usage_category_image (category_key, image_key),
                INDEX idx_listing_image_usage_category_count (category_key, use_count, last_used_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->usageTableReady = true;
    }

    private function buildPublicUrl(string $relativePath): string
    {
        $baseUrl = rtrim(trim((string) ($this->config['source_url'] ?? '')), '/');
        if ($baseUrl === '') {
            throw new RuntimeException('Photo library source_url is empty.');
        }

        $segments = array_map('rawurlencode', explode('/', trim($relativePath, '/')));

        return $baseUrl . '/' . implode('/', $segments);
    }

    private function resolveAbsoluteSourcePath(string $relativePath): string
    {
        $sourcePath = rtrim(trim((string) ($this->config['source_path'] ?? '')), '/');
        if ($sourcePath === '') {
            return '';
        }

        $candidate = $sourcePath . '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
        return is_file($candidate) ? $candidate : '';
    }

    private function buildCategoryKey(array $lead, array $category): string
    {
        $categoryId = (int) ($category['id'] ?? 0);
        if ($categoryId > 0) {
            return (string) $categoryId;
        }

        $leadCategory = $this->slugify((string) ($lead['category'] ?? ''));

        return $leadCategory !== '' ? $leadCategory : 'default';
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';

        return trim($value, '_');
    }
}
