<?php

declare(strict_types=1);

namespace MailingApp;

final class WebsiteCategoryAnalyzer
{
    private array $config;
    private WebsiteContentExtractor $contentExtractor;
    private PolonadsCategoryMapper $categoryMapper;

    public function __construct(
        array $config = [],
        ?WebsiteContentExtractor $contentExtractor = null,
        ?PolonadsCategoryMapper $categoryMapper = null
    ) {
        $this->config = $config;
        $this->contentExtractor = $contentExtractor ?? new WebsiteContentExtractor($config);
        $this->categoryMapper = $categoryMapper ?? new PolonadsCategoryMapper();
    }

    public function analyze(array $lead, ?array $websiteContext = null): array
    {
        $websiteContext ??= $this->contentExtractor->extract([
            'website' => (string) ($lead['website'] ?? ''),
            'yp_url' => (string) ($lead['yp_url'] ?? ''),
        ]);

        $combinedText = trim((string) ($websiteContext['combined_text'] ?? ''));
        $category = $this->categoryMapper->mapWebsiteText(
            $combinedText,
            (string) ($lead['category'] ?? ''),
            (string) ($lead['company_name'] ?? ''),
            (string) ($lead['website'] ?? '')
        );

        $category['analysis_source_urls'] = is_array($websiteContext['source_urls'] ?? null)
            ? array_values($websiteContext['source_urls'])
            : [];
        $category['analysis_page_count'] = is_array($websiteContext['pages'] ?? null)
            ? count($websiteContext['pages'])
            : 0;

        return $category;
    }

    public function shouldOverrideCampaign(array $suggestedCategory, array $currentCategory): bool
    {
        $suggestedId = (int) ($suggestedCategory['id'] ?? 0);
        $currentId = (int) ($currentCategory['id'] ?? 0);

        if ($suggestedId <= 0 || $currentId <= 0 || $suggestedId === $currentId) {
            return false;
        }

        if ((string) ($suggestedCategory['source'] ?? '') !== 'website_analysis') {
            return false;
        }

        $confidence = (string) ($suggestedCategory['confidence'] ?? '');
        $score = (int) ($suggestedCategory['website_keyword_score'] ?? 0);

        return $confidence === 'high' || $score >= 2;
    }
}
