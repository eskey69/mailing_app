<?php

declare(strict_types=1);

namespace MailingApp;

final class PublicationPayloadBuilder
{
    private const DEFAULT_PROFILE_GROUP_ID = 2;
    private const DEFAULT_TYPE_ID = 0;
    private array $config;
    private ?CampaignRepository $campaignRepository = null;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function build(array $lead): array
    {
        $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
        $autoCategory = is_array($meta['polonads_category'] ?? null) ? $meta['polonads_category'] : [];
        $category = $this->resolvePublicationCategory($lead, $autoCategory);
        $region = is_array($meta['polonads_region'] ?? null) ? $meta['polonads_region'] : [];

        $listingTitle = $this->resolveListingTitle($lead, $meta);
        $listingBody = $this->resolveListingBody($lead, $meta);
        $username = $this->buildUsername($lead);
        $temporaryPassword = $this->generateTemporaryPassword((int) ($lead['id'] ?? 0), (string) ($lead['primary_email'] ?? ''));
        $postCode = $this->extractPostCode((string) ($lead['address'] ?? ''));
        $isLive = ((string) ($lead['contact_status'] ?? '') === 'published')
            || ((string) ($meta['publication_status'] ?? '') === 'live');

        $warnings = $this->collectWarnings($lead, $category, $region, $listingTitle, $listingBody);

        return [
            'status' => $warnings === [] ? 'ready' : 'review_required',
            'warnings' => $warnings,
            'mapping' => PublicationFieldMapper::describe(),
            'joomla_user' => [
                'lookup' => [
                    'email' => (string) ($lead['primary_email'] ?? ''),
                    'username' => $username,
                ],
                'create' => [
                    'name' => (string) ($lead['company_name'] ?? ''),
                    'username' => $username,
                    'email' => (string) ($lead['primary_email'] ?? ''),
                    'password_plain' => $temporaryPassword,
                    'block' => 0,
                    'sendEmail' => 0,
                ],
                'groups' => [
                    'registered' => 2,
                ],
            ],
            'djcf_profile' => [
                'upsert' => [
                    'group_id' => self::DEFAULT_PROFILE_GROUP_ID,
                    'region_id' => (int) ($region['id'] ?? 1),
                    'address' => (string) ($lead['address'] ?? ''),
                    'post_code' => $postCode,
                    'verified' => 0,
                    'description' => '',
                ],
            ],
            'djcf_item' => [
                'existing_item_id' => (int) ($meta['djcf_item_id'] ?? 0),
                'create' => [
                    'cat_id' => (int) ($category['id'] ?? 4),
                    'type_id' => self::DEFAULT_TYPE_ID,
                    'name' => $listingTitle,
                    'description' => $listingBody,
                    'intro_desc' => $this->buildIntroDescription($listingBody),
                    'published' => $isLive ? 1 : 0,
                    'address' => (string) ($lead['address'] ?? ''),
                    'region_id' => (int) ($region['id'] ?? 1),
                    'website' => (string) ($lead['website'] ?? ''),
                    'email' => (string) ($lead['primary_email'] ?? ''),
                    'contact' => $this->buildContactBlock($lead),
                    'publication_approved_at' => (string) ($meta['publication_approved_at'] ?? ''),
                ],
            ],
            'trace' => [
                'lead_id' => (int) ($lead['id'] ?? 0),
                'company_name' => (string) ($lead['company_name'] ?? ''),
                'yp_url' => (string) ($lead['yp_url'] ?? ''),
                'all_emails' => (string) ($lead['all_emails'] ?? ''),
                'source_name' => (string) ($lead['source_name'] ?? ''),
                'source_status' => (string) ($lead['source_status'] ?? ''),
                'category_mapping' => $category,
                'auto_category_mapping' => $autoCategory,
                'region_mapping' => $region,
            ],
        ];
    }

    private function resolvePublicationCategory(array $lead, array $autoCategory): array
    {
        $websiteCategory = $this->resolveWebsiteAnalyzedCategory($autoCategory);
        if ($websiteCategory !== null) {
            return $websiteCategory;
        }

        $campaignId = trim((string) ($lead['campaign_id'] ?? ''));
        $campaignCategory = $this->resolveCampaignCategory($campaignId);

        if ($campaignCategory !== null) {
            return $campaignCategory;
        }

        $liveCategory = $this->resolveLiveCategoryById((int) ($autoCategory['id'] ?? 0));
        if ($liveCategory !== null) {
            return array_replace($autoCategory, $liveCategory, [
                'source' => 'djcf_auto',
                'reason' => (string) ($autoCategory['reason'] ?? 'Category mapped automatically and confirmed from DJ-Classifieds.'),
            ]);
        }

        if ($autoCategory === []) {
            return [];
        }

        $autoCategory['source'] = (string) ($autoCategory['source'] ?? 'auto');

        return $autoCategory;
    }

    private function resolveWebsiteAnalyzedCategory(array $autoCategory): ?array
    {
        if ((string) ($autoCategory['source'] ?? '') !== 'website_analysis') {
            return null;
        }

        $categoryId = (int) ($autoCategory['id'] ?? 0);
        if ($categoryId <= 0) {
            return null;
        }

        $liveCategory = $this->resolveLiveCategoryById($categoryId);
        if ($liveCategory !== null) {
            return array_replace($autoCategory, $liveCategory, [
                'source' => 'website_analysis',
                'reason' => (string) ($autoCategory['reason'] ?? 'Category corrected from website analysis.'),
                'requires_review' => false,
            ]);
        }

        return $autoCategory;
    }

    private function resolveCampaignCategory(string $campaignId): ?array
    {
        if ($campaignId === '') {
            return null;
        }

        $categoryId = 0;

        try {
            $campaign = $this->campaignRepository()->findCampaignById($campaignId);
            if ($campaign !== null) {
                $categoryId = (int) ($campaign['polonads_category_id'] ?? 0);
            }
        } catch (\Throwable $exception) {
            $categoryId = 0;
        }

        if ($categoryId <= 0) {
            $campaigns = $this->config['campaigns'] ?? null;
            if (is_array($campaigns)) {
                $configCampaign = $campaigns[$campaignId] ?? null;
                if (is_array($configCampaign)) {
                    $category = $configCampaign['polonads_category'] ?? null;
                    if (is_array($category)) {
                        $categoryId = (int) ($category['id'] ?? 0);
                    }
                }
            }
        }

        if ($categoryId <= 0) {
            return null;
        }

        $liveCategory = $this->resolveLiveCategoryById($categoryId);
        if ($liveCategory !== null) {
            return [
                'id' => (int) $liveCategory['id'],
                'name' => (string) ($liveCategory['name'] ?? ''),
                'parent_id' => (int) ($liveCategory['parent_id'] ?? 0),
                'alias' => (string) ($liveCategory['alias'] ?? ''),
                'match_type' => 'campaign',
                'confidence' => 'campaign',
                'matched_keyword' => '',
                'requires_review' => false,
                'reason' => 'Category forced by campaign configuration and loaded from DJ-Classifieds.',
                'source' => 'djcf_campaign',
                'campaign_id' => $campaignId,
            ];
        }

        return [
            'id' => $categoryId,
            'name' => '',
            'match_type' => 'campaign',
            'confidence' => 'campaign',
            'matched_keyword' => '',
            'requires_review' => false,
            'reason' => 'Category forced by campaign selection.',
            'source' => 'campaign',
            'campaign_id' => $campaignId,
        ];
    }

    private function campaignRepository(): CampaignRepository
    {
        if ($this->campaignRepository !== null) {
            return $this->campaignRepository;
        }

        $database = new Database($this->config);
        $this->campaignRepository = new CampaignRepository($database->pdo());

        return $this->campaignRepository;
    }

    private function resolveLiveCategoryById(int $categoryId): ?array
    {
        if ($categoryId <= 0 || !isset($this->config['polonads_db'])) {
            return null;
        }

        try {
            $database = new Database($this->config, 'polonads_db');
            $gateway = new PolonadsPublicationGateway(
                $database->pdo(),
                (string) ($this->config['polonads_db']['prefix'] ?? 'jost3_')
            );

            return $gateway->findCategoryById($categoryId);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function resolveListingTitle(array $lead, array $meta): string
    {
        $title = trim((string) ($meta['listing_title'] ?? ''));
        if ($title !== '') {
            return $title;
        }

        $companyName = trim((string) ($lead['company_name'] ?? ''));
        $category = trim((string) ($lead['category'] ?? ''));
        $location = trim(implode(', ', array_filter([
            (string) ($lead['city'] ?? ''),
            (string) ($lead['state'] ?? ''),
        ])));

        if ($companyName !== '' && $category !== '' && $location !== '') {
            return sprintf('%s - %s in %s', $companyName, ucfirst(strtolower($category)), $location);
        }

        if ($companyName !== '') {
            return $companyName;
        }

        return 'Draft listing';
    }

    private function resolveListingBody(array $lead, array $meta): string
    {
        $body = trim((string) ($meta['listing_body'] ?? ''));
        if ($body !== '') {
            return $this->formatListingBodyForDjcf($body);
        }

        return '';
    }

    private function buildUsername(array $lead): string
    {
        $company = strtolower(trim((string) ($lead['company_name'] ?? '')));
        $company = preg_replace('/[^a-z0-9]+/', '', $company) ?? '';

        if ($company === '') {
            $email = strtolower(trim((string) ($lead['primary_email'] ?? '')));
            $localPart = explode('@', $email)[0] ?? 'lead';
            $company = preg_replace('/[^a-z0-9]+/', '', $localPart) ?? 'lead';
        }

        $suffix = (string) ((int) ($lead['id'] ?? 0));
        $maxBaseLength = max(1, 150 - strlen($suffix) - 1);
        $base = substr($company, 0, $maxBaseLength);

        return $base . '_' . $suffix;
    }

    private function generateTemporaryPassword(int $leadId, string $email): string
    {
        $seed = sha1($leadId . '|' . strtolower($email));
        return 'Polonads!' . substr($seed, 0, 10);
    }

    private function extractPostCode(string $address): string
    {
        if (preg_match('/\b\d{5}(?:-\d{4})?\b/', $address, $matches) === 1) {
            return $matches[0];
        }

        return '';
    }

    private function buildIntroDescription(string $body): string
    {
        $plain = trim(strip_tags($body));
        $plain = preg_replace('/\s+/', ' ', $plain) ?? '';

        if (strlen($plain) <= 280) {
            return $plain;
        }

        return rtrim(substr($plain, 0, 277)) . '...';
    }

    private function buildContactBlock(array $lead): string
    {
        $parts = [];

        if (trim((string) ($lead['company_name'] ?? '')) !== '') {
            $parts[] = 'Company: ' . trim((string) $lead['company_name']);
        }
        if (trim((string) ($lead['phone'] ?? '')) !== '') {
            $parts[] = 'Phone: ' . trim((string) $lead['phone']);
        }
        if (trim((string) ($lead['primary_email'] ?? '')) !== '') {
            $parts[] = 'Email: ' . trim((string) $lead['primary_email']);
        }
        if (trim((string) ($lead['website'] ?? '')) !== '') {
            $parts[] = 'Website: ' . trim((string) $lead['website']);
        }

        return implode("\n", $parts);
    }

    private function formatListingBodyForDjcf(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        if (preg_match('/<\s*(p|br|div|ul|ol|li|h[1-6])\b/i', $body) === 1) {
            return $body;
        }

        $translationMarker = "\n\n---\nWersja polska:\n";
        if (str_contains($body, $translationMarker)) {
            [$englishBody, $polishBody] = explode($translationMarker, $body, 2);

            return implode("\n", array_filter([
                '<p><strong>English version</strong></p>',
                $this->formatPlainTextParagraphs($englishBody),
                '<p><strong>Wersja polska</strong></p>',
                $this->formatPlainTextParagraphs($polishBody),
            ]));
        }

        return $this->formatPlainTextParagraphs($body);
    }

    private function formatPlainTextParagraphs(string $text): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($normalized === '') {
            return '';
        }

        $chunks = preg_split("/\n{2,}/", $normalized) ?: [];
        $paragraphs = [];

        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            $escaped = htmlspecialchars($chunk, ENT_QUOTES, 'UTF-8');
            $escaped = str_replace("\n", "<br>\n", $escaped);
            $paragraphs[] = '<p>' . $escaped . '</p>';
        }

        return implode("\n", $paragraphs);
    }

    private function collectWarnings(array $lead, array $category, array $region, string $listingTitle, string $listingBody): array
    {
        $warnings = [];

        if (trim((string) ($lead['primary_email'] ?? '')) === '') {
            $warnings[] = 'Missing primary email for Joomla account creation.';
        }

        if (trim((string) ($lead['company_name'] ?? '')) === '') {
            $warnings[] = 'Missing company name for Joomla user and listing owner name.';
        }

        if ($listingTitle === '') {
            $warnings[] = 'Missing listing title.';
        }

        if ($listingBody === '') {
            $warnings[] = 'Missing listing body.';
        }

        if (($category['requires_review'] ?? false) === true) {
            $warnings[] = 'Category mapping still requires manual review.';
        }

        if (($region['requires_review'] ?? false) === true) {
            $warnings[] = 'Region mapping still requires manual review.';
        }

        if ((int) ($category['id'] ?? 0) <= 0) {
            $warnings[] = 'Missing Polonads category id.';
        }

        if ((int) ($region['id'] ?? 0) <= 0) {
            $warnings[] = 'Missing Polonads region id.';
        }

        return $warnings;
    }
}
