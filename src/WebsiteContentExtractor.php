<?php

declare(strict_types=1);

namespace MailingApp;

use DOMDocument;
use DOMXPath;

final class WebsiteContentExtractor
{
    private const MAX_PAGES = 6;
    private const MAX_LINK_CANDIDATES = 16;
    private const MAX_FETCH_BYTES = 1000000;
    private const MAX_PAGE_CHARS = 2800;
    private const MAX_TOTAL_CHARS = 12000;
    private const TRACKING_QUERY_KEYS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'utm_id',
        'gclid',
        'gbraid',
        'wbraid',
        'fbclid',
        'msclkid',
    ];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * @param array<string, mixed> $company
     * @return array{pages: array<int, array{url: string, title: string, text: string}>, source_urls: array<int, string>, combined_text: string}
     */
    public function extract(array $company): array
    {
        $website = $this->normalizeUrl((string) ($company['website'] ?? ''));
        $ypUrl = $this->normalizeUrl((string) ($company['yp_url'] ?? ''));
        $websiteHome = $website !== '' ? $this->toOriginUrl($website) : '';
        $websiteHost = $websiteHome !== '' ? (string) parse_url($websiteHome, PHP_URL_HOST) : '';

        $pages = [];
        $visited = [];
        $candidateUrls = [];

        foreach (array_values(array_filter([$websiteHome, $ypUrl])) as $seedUrl) {
            if (isset($visited[$seedUrl])) {
                continue;
            }

            $visited[$seedUrl] = true;
            $html = $this->fetchHtml($seedUrl);
            if ($html === '') {
                continue;
            }

            $page = $this->extractPage($seedUrl, $html);
            if ($page['text'] !== '') {
                $pages[] = $page;
            }

            if ($candidateUrls === [] && $websiteHost !== '' && $this->sameHost($seedUrl, $websiteHost)) {
                $candidateUrls = $this->extractRelevantLinks($html, $seedUrl, $websiteHost);
            }

            if (count($pages) >= self::MAX_PAGES) {
                break;
            }
        }

        foreach ($candidateUrls as $candidateUrl) {
            if (count($pages) >= self::MAX_PAGES) {
                break;
            }

            if (isset($visited[$candidateUrl])) {
                continue;
            }

            $visited[$candidateUrl] = true;
            $html = $this->fetchHtml($candidateUrl);
            if ($html === '') {
                continue;
            }

            $page = $this->extractPage($candidateUrl, $html);
            if ($page['text'] !== '') {
                $pages[] = $page;
            }
        }

        $combinedText = '';
        foreach ($pages as $page) {
            $section = $page['title'] !== ''
                ? $page['title'] . "\n" . $page['text']
                : $page['text'];

            $candidate = trim($combinedText . "\n\n[" . $page['url'] . "]\n" . $section);
            if (mb_strlen($candidate) > self::MAX_TOTAL_CHARS) {
                $remaining = self::MAX_TOTAL_CHARS - mb_strlen($combinedText);
                if ($remaining <= 0) {
                    break;
                }

                $section = mb_substr($section, 0, max(0, $remaining - 32));
                $candidate = trim($combinedText . "\n\n[" . $page['url'] . "]\n" . $section);
            }

            $combinedText = $candidate;
        }

        return [
            'pages' => $pages,
            'source_urls' => array_values(array_map(
                static fn (array $page): string => $page['url'],
                $pages
            )),
            'combined_text' => trim($combinedText),
        ];
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }

        return $this->stripTrackingFromUrl($url);
    }

    private function stripTrackingFromUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $query = [];
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str((string) $parts['query'], $query);
            foreach (array_keys($query) as $key) {
                if (in_array(strtolower((string) $key), self::TRACKING_QUERY_KEYS, true)) {
                    unset($query[$key]);
                }
            }
        }

        $normalized = strtolower((string) $parts['scheme']) . '://' . (string) $parts['host'];
        if (isset($parts['port'])) {
            $normalized .= ':' . (string) $parts['port'];
        }

        $normalized .= (string) ($parts['path'] ?? '');

        if ($query !== []) {
            $normalized .= '?' . http_build_query($query);
        }

        return $normalized;
    }

    private function toOriginUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $origin = strtolower((string) $parts['scheme']) . '://' . (string) $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . (string) $parts['port'];
        }

        return $origin;
    }

    private function fetchHtml(string $url): string
    {
        $caBundlePath = Support::resolveHttpCaBundlePath($this->config);

        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            if ($handle === false) {
                return '';
            }

            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_USERAGENT => 'PolonadsMailingBot/1.0 (+https://polonads.com)',
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            if ($caBundlePath !== '') {
                curl_setopt($handle, CURLOPT_CAINFO, $caBundlePath);
            }

            $html = curl_exec($handle);
            $statusCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $contentType = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
            curl_close($handle);

            if (!is_string($html) || $statusCode >= 400 || !str_contains(strtolower($contentType), 'html')) {
                return '';
            }

            return mb_substr($html, 0, self::MAX_FETCH_BYTES);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 20,
                'header' => "User-Agent: PolonadsMailingBot/1.0 (+https://polonads.com)\r\nAccept: text/html,application/xhtml+xml\r\n",
            ],
            'ssl' => array_filter([
                'verify_peer' => true,
                'verify_peer_name' => true,
                'cafile' => $caBundlePath !== '' ? $caBundlePath : null,
            ], static fn ($value): bool => $value !== null),
        ]);

        $html = @file_get_contents($url, false, $context);
        if (!is_string($html) || $html === '') {
            return '';
        }

        return mb_substr($html, 0, self::MAX_FETCH_BYTES);
    }

    /**
     * @return array{url: string, title: string, text: string}
     */
    private function extractPage(string $url, string $html): array
    {
        $title = '';
        $text = '';

        if (class_exists(DOMDocument::class)) {
            $dom = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $loaded = $dom->loadHTML($html);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            if ($loaded) {
                $xpath = new DOMXPath($dom);
                foreach ($xpath->query('//script|//style|//noscript|//template|//svg') ?: [] as $node) {
                    $node->parentNode?->removeChild($node);
                }

                $title = trim((string) ($xpath->evaluate('string(//title)') ?? ''));
                if ($title === '') {
                    $title = trim((string) ($xpath->evaluate('string(//meta[@property="og:title"]/@content)') ?? ''));
                }

                $parts = [];
                foreach ($xpath->query('//meta[@name="description"]/@content | //h1 | //h2 | //h3 | //p | //li') ?: [] as $node) {
                    $value = $this->normalizeText((string) $node->textContent);
                    if ($value === '' || mb_strlen($value) < 20) {
                        continue;
                    }

                    $parts[] = $value;
                }

                $parts = array_values(array_unique($parts));
                $text = trim(implode("\n", array_slice($parts, 0, 28)));
            }
        }

        if ($text === '') {
            $text = $this->normalizeText(strip_tags($html));
        }

        return [
            'url' => $url,
            'title' => $this->truncate($title, 180),
            'text' => $this->truncate($text, self::MAX_PAGE_CHARS),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function extractRelevantLinks(string $html, string $baseUrl, string $host): array
    {
        if (!class_exists(DOMDocument::class)) {
            return [];
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return [];
        }

        $urls = [];
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//a[@href]') ?: [] as $node) {
            $href = trim((string) $node->attributes?->getNamedItem('href')?->nodeValue);
            $absoluteUrl = $this->absolutizeUrl($href, $baseUrl);
            if ($absoluteUrl === null || !$this->sameHost($absoluteUrl, $host)) {
                continue;
            }

            $anchorText = $this->normalizeText((string) $node->textContent);
            if (!$this->isRelevantLink($absoluteUrl, $anchorText)) {
                continue;
            }

            $score = $this->scoreRelevantLink($absoluteUrl, $anchorText);
            if (!isset($urls[$absoluteUrl]) || $score > $urls[$absoluteUrl]) {
                $urls[$absoluteUrl] = $score;
            }
        }

        arsort($urls);

        return array_slice(array_keys($urls), 0, min(self::MAX_LINK_CANDIDATES, self::MAX_PAGES - 1));
    }

    private function absolutizeUrl(string $href, string $baseUrl): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#') || str_starts_with(strtolower($href), 'mailto:') || str_starts_with(strtolower($href), 'tel:')) {
            return null;
        }

        if (preg_match('~^https?://~i', $href) === 1) {
            return $this->normalizeUrl($href);
        }

        $baseParts = parse_url($baseUrl);
        if (!is_array($baseParts) || !isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }

        $scheme = $baseParts['scheme'];
        $host = $baseParts['host'];
        $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';
        $path = (string) ($baseParts['path'] ?? '/');

        if (str_starts_with($href, '//')) {
            return $this->normalizeUrl($scheme . ':' . $href);
        }

        if (str_starts_with($href, '/')) {
            return $this->normalizeUrl($scheme . '://' . $host . $port . $href);
        }

        $directory = preg_replace('~/[^/]*$~', '/', $path) ?: '/';
        return $this->normalizeUrl($scheme . '://' . $host . $port . $directory . $href);
    }

    private function sameHost(string $url, string $expectedHost): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return false;
        }

        return strcasecmp($host, $expectedHost) === 0;
    }

    private function isRelevantLink(string $url, string $anchorText): bool
    {
        $haystack = strtolower($url . ' ' . $anchorText);
        foreach ([
            'about',
            'about-us',
            'company',
            'services',
            'service',
            'what-we-do',
            'solutions',
            'products',
            'product',
            'menu',
            'pricing',
            'portfolio',
            'projects',
            'gallery',
            'case-studies',
            'faq',
            'team',
            'staff',
            'contact',
            'location',
            'locations',
            'areas-served',
            'who-we-are',
            'our-story',
            'industries',
        ] as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function scoreRelevantLink(string $url, string $anchorText): int
    {
        $haystack = strtolower($url . ' ' . $anchorText);
        $score = 0;

        foreach ([
            'services' => 100,
            'service' => 95,
            'what-we-do' => 90,
            'solutions' => 85,
            'products' => 80,
            'product' => 78,
            'menu' => 76,
            'about-us' => 74,
            'about' => 72,
            'company' => 68,
            'industries' => 65,
            'portfolio' => 62,
            'projects' => 60,
            'case-studies' => 58,
            'locations' => 56,
            'location' => 54,
            'areas-served' => 52,
            'contact' => 45,
            'team' => 30,
            'staff' => 28,
            'gallery' => 20,
            'faq' => 15,
        ] as $keyword => $weight) {
            if (str_contains($haystack, $keyword)) {
                $score += $weight;
            }
        }

        if (str_contains($url, '?') || str_contains($url, '#')) {
            $score -= 10;
        }

        return $score;
    }

    private function normalizeText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    private function truncate(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $limit - 1)) . '...';
    }
}
