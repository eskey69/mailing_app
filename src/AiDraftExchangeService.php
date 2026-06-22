<?php

declare(strict_types=1);

namespace MailingApp;

use RuntimeException;

final class AiDraftExchangeService
{
    private const PAYLOAD_VERSION = 1;

    private LeadRepository $leadRepository;
    private array $config;

    public function __construct(LeadRepository $leadRepository, array $config)
    {
        $this->leadRepository = $leadRepository;
        $this->config = $config;
    }

    public function exportLeadPackage(int $leadId): array
    {
        $lead = $this->requireLead($leadId);
        $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
        $translationRequested = self::isTranslationRequestPending($meta);
        $existingTitle = self::resolveTranslationSourceTitle($meta);
        $existingBody = self::resolveTranslationSourceBody($meta);
        $existingLanguage = self::resolveTranslationSourceLanguage($meta);

        if ($translationRequested) {
            $existingBody = self::stripPolishTranslationSection($existingBody);
        }

        return [
            'payload_version' => self::PAYLOAD_VERSION,
            'lead_id' => (int) $lead['id'],
            'company' => [
                'name' => (string) ($lead['company_name'] ?? ''),
                'category' => (string) ($lead['category'] ?? ''),
                'city' => (string) ($lead['city'] ?? ''),
                'state' => (string) ($lead['state'] ?? ''),
                'address' => (string) ($lead['address'] ?? ''),
                'phone' => (string) ($lead['phone'] ?? ''),
                'website' => (string) ($lead['website'] ?? ''),
                'primary_email' => (string) ($lead['primary_email'] ?? ''),
                'all_emails' => (string) ($lead['all_emails'] ?? ''),
                'yp_url' => (string) ($lead['yp_url'] ?? ''),
            ],
            'polonads_mapping' => [
                'category' => is_array($meta['polonads_category'] ?? null) ? $meta['polonads_category'] : [],
                'region' => is_array($meta['polonads_region'] ?? null) ? $meta['polonads_region'] : [],
            ],
            'existing_listing_draft' => [
                'title' => $existingTitle,
                'body' => $existingBody,
                'language' => $translationRequested ? $existingLanguage : (string) ($meta['listing_language'] ?? ''),
                'images' => is_array($meta['listing_images'] ?? null) ? $meta['listing_images'] : [],
                'visual_subtype' => (string) ($meta['listing_visual_subtype'] ?? 'general'),
                'source_urls' => is_array($meta['listing_source_urls'] ?? null) ? $meta['listing_source_urls'] : [],
            ],
            'translation_request' => [
                'requested' => $translationRequested,
                'target_language' => 'pl',
                'mode' => 'append_below_original',
                'instructions' => $translationRequested
                    ? 'Translate the existing English draft into Polish. Keep the English original unchanged. Return only the Polish title and the Polish body so the application can append the translation below the original draft.'
                    : 'No translation requested.',
            ],
            'expected_response' => [
                'title' => 'string',
                'body' => 'string',
                'language' => 'pl|en|bilingual',
                'images' => ['https://example.com/image.jpg'],
                'source_urls' => ['https://example.com/about'],
                'visual_subtype' => 'general|marketing|construction|transport|warehouse|cleaning|caregiver|beauty|restaurant|office|medical|it|sales|education|home_services|insurance|legal_finance|real_estate',
                'notes' => 'When translation_request.requested=true, return the Polish translation draft only.',
            ],
        ];
    }

    public function hasListingDraft(array $lead): bool
    {
        $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));

        return trim((string) ($meta['listing_title'] ?? '')) !== ''
            && trim((string) ($meta['listing_body'] ?? '')) !== '';
    }

    public function autoTranslatePolishDraft(int $leadId): array
    {
        $this->requireLead($leadId);

        $this->leadRepository->mergeLeadMeta($leadId, [
            'translation_status' => 'in_progress',
            'ai_draft_status' => 'translation_in_progress',
            'translation_started_at' => date('Y-m-d H:i:s'),
        ], 'ai');

        try {
            return $this->generateDraftWithProvider($leadId, 'openai_translation');
        } catch (\Throwable $exception) {
            $this->leadRepository->mergeLeadMeta($leadId, [
                'translation_status' => 'failed',
                'ai_draft_status' => 'translation_failed',
                'translation_last_error' => $exception->getMessage(),
            ], 'ai');
            $this->leadRepository->recordPublicationLog(
                $leadId,
                'translation_failed',
                'Automatic Polish translation failed: ' . $exception->getMessage(),
                ['lead_id' => $leadId]
            );

            throw $exception;
        }
    }

    public function generateDraftForLead(int $leadId): array
    {
        return $this->generateDraftWithProvider($leadId, 'openai');
    }

    public function simulateDraftForLead(int $leadId, string $source = 'simulation'): array
    {
        $payload = $this->exportLeadPackage($leadId);
        $company = $payload['company'];
        $existingDraft = is_array($payload['existing_listing_draft'] ?? null) ? $payload['existing_listing_draft'] : [];
        $translationRequest = is_array($payload['translation_request'] ?? null) ? $payload['translation_request'] : [];
        $isTranslationRequested = ($translationRequest['requested'] ?? false) === true;
        $website = trim((string) ($company['website'] ?? ''));
        $category = trim((string) ($company['category'] ?? 'local business'));
        $location = trim(implode(', ', array_filter([
            (string) ($company['city'] ?? ''),
            (string) ($company['state'] ?? ''),
        ])));
        $companyName = trim((string) ($company['name'] ?? 'This business'));

        if ($isTranslationRequested) {
            $sourceTitle = trim((string) ($existingDraft['title'] ?? ''));
            $sourceBody = trim((string) ($existingDraft['body'] ?? ''));

            $generatedDraft = [
                'title' => $sourceTitle !== '' ? self::simulatePolishTitle($sourceTitle) : sprintf('%s - ogloszenie po polsku', $companyName),
                'body' => self::simulatePolishBody($sourceBody, $companyName, $category, $location, $website),
                'language' => 'pl',
                'images' => is_array($existingDraft['images'] ?? null) ? $existingDraft['images'] : [],
                'source_urls' => is_array($existingDraft['source_urls'] ?? null) ? $existingDraft['source_urls'] : array_values(array_filter([
                    $website,
                    (string) ($company['yp_url'] ?? ''),
                ])),
                'visual_subtype' => (string) ($existingDraft['visual_subtype'] ?? 'general'),
            ];
        } else {
            $generatedDraft = [
                'title' => implode(' - ', array_filter([
                    $companyName,
                    $location !== '' ? ucfirst($category) . ' in ' . $location : ucfirst($category),
                ])),
                'body' => implode("\n\n", array_filter([
                    sprintf('%s offers dependable %s with a focus on clear communication, reliable service, and easy contact for local customers.', $companyName, strtolower($category)),
                    $location !== '' ? sprintf('The listing is prepared for the Polish community looking for trusted services in %s.', $location) : 'The listing is prepared for the Polish community in the U.S. and Canada.',
                    'Customers can use this listing to quickly understand what the company does, how to get in touch, and why it is worth considering.',
                    $website !== '' ? sprintf('More details and brand context were inferred from %s.', $website) : 'Website details can be enriched later by the AI module or edited manually by the operator.',
                ])),
                'language' => 'en',
                'images' => [],
                'source_urls' => array_values(array_filter([
                    $website,
                    (string) ($company['yp_url'] ?? ''),
                ])),
                'visual_subtype' => 'general',
            ];
        }

        $this->importDraft($leadId, $generatedDraft, $source);

        return $generatedDraft;
    }

    public function importDraft(int $leadId, array $draft, string $source = 'ai'): array
    {
        $lead = $this->requireLead($leadId);
        $currentMeta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));

        $title = trim((string) ($draft['title'] ?? ''));
        $body = trim((string) ($draft['body'] ?? ''));
        $language = trim((string) ($draft['language'] ?? 'en'));
        $images = array_values(array_filter(
            is_array($draft['images'] ?? null) ? $draft['images'] : [],
            static fn ($value): bool => is_string($value) && trim($value) !== ''
        ));
        $sourceUrls = array_values(array_filter(
            is_array($draft['source_urls'] ?? null) ? $draft['source_urls'] : [],
            static fn ($value): bool => is_string($value) && trim($value) !== ''
        ));
        $visualSubtype = $this->normalizeVisualSubtype((string) ($draft['visual_subtype'] ?? 'general'));

        if ($title === '' || $body === '') {
            throw new RuntimeException('AI draft import requires both title and body.');
        }

        $isPolishTranslationRequest = self::isTranslationRequestPending($currentMeta);
        if ($isPolishTranslationRequest) {
            $existingTitle = self::resolveTranslationSourceTitle($currentMeta);
            $existingBody = self::stripPolishTranslationSection(self::resolveTranslationSourceBody($currentMeta));

            if ($existingTitle !== '' && strcasecmp($existingTitle, $title) !== 0) {
                $title = $existingTitle . ' / ' . $title;
            }

            if ($existingBody !== '') {
                $body = $existingBody . "\n\n---\nWersja polska:\n" . $body;
            }

            $language = 'en+pl';
        }

        $meta = [
            'listing_title' => $title,
            'listing_body' => $body,
            'listing_language' => $language !== '' ? $language : 'en',
            'listing_images' => $images,
            'listing_visual_subtype' => $visualSubtype,
            'listing_source_urls' => $sourceUrls,
            'ai_draft_status' => $isPolishTranslationRequest ? 'translation_ready' : 'ready',
            'ai_generated_at' => date('Y-m-d H:i:s'),
            'ai_provider' => $source,
            'listing_payload_version' => self::PAYLOAD_VERSION,
            'translation_status' => $isPolishTranslationRequest ? 'ready' : (string) ($currentMeta['translation_status'] ?? ''),
            'translation_source' => $isPolishTranslationRequest ? $source : (string) ($currentMeta['translation_source'] ?? ''),
            'publication_status' => $isPolishTranslationRequest ? 'drafted' : (string) ($currentMeta['publication_status'] ?? 'drafted'),
            'translation_source_title' => $isPolishTranslationRequest ? $existingTitle : (string) ($currentMeta['translation_source_title'] ?? ''),
            'translation_source_body' => $isPolishTranslationRequest ? $existingBody : (string) ($currentMeta['translation_source_body'] ?? ''),
            'translation_source_language' => $isPolishTranslationRequest
                ? self::resolveTranslationSourceLanguage($currentMeta)
                : (string) ($currentMeta['translation_source_language'] ?? ''),
        ];

        $this->leadRepository->mergeLeadMeta($leadId, $meta, 'ai');
        if ($isPolishTranslationRequest) {
            $this->leadRepository->updateLeadWorkflow($leadId, [
                'contact_status' => 'client_review',
                'approval_status' => 'pending',
                'campaign_id' => (string) ($lead['campaign_id'] ?? ''),
                'notes' => trim((string) ($lead['notes'] ?? '')),
                'email_subject' => trim((string) ($lead['email_subject'] ?? '')),
                'email_draft' => trim((string) ($lead['email_draft'] ?? '')),
                'email_final' => trim((string) ($lead['email_final'] ?? '')),
            ]);
        }
        $this->leadRepository->recordPublicationLog(
            $leadId,
            $isPolishTranslationRequest ? 'translation_ready' : 'ai_draft_ready',
            $isPolishTranslationRequest
                ? sprintf('Polish translation draft imported from %s and appended below the original.', $source)
                : sprintf('Listing draft imported from %s.', $source),
            [
                'lead_id' => $leadId,
                'title' => $title,
                'language' => $language,
                'images' => $images,
                'visual_subtype' => $visualSubtype,
                'source_urls' => $sourceUrls,
            ]
        );

        return [
            'lead_id' => $leadId,
            'title' => $title,
            'language' => $language,
            'source' => $source,
            'company_name' => (string) ($lead['company_name'] ?? ''),
        ];
    }

    private function generateDraftWithProvider(int $leadId, string $source): array
    {
        $payload = $this->exportLeadPackage($leadId);
        $websiteContext = null;
        if ($source === 'openai') {
            $websiteContext = $this->buildWebsiteContext($payload);
            $this->applyWebsiteCategoryAnalysis($leadId, $websiteContext);
        }

        $provider = new OpenAiListingClient($this->config);
        $draft = $provider->generateDraft($payload, $websiteContext);
        $httpMeta = $this->sanitizeProviderMeta($provider->getLastHttpMeta());
        if ($httpMeta !== []) {
            $this->leadRepository->mergeLeadMeta($leadId, [
                'ai_last_http_meta' => $httpMeta,
            ], 'ai');
        }
        $this->importDraft($leadId, $draft, $source);

        return $draft;
    }

    /**
     * @param array<string, mixed>|null $websiteContext
     */
    private function applyWebsiteCategoryAnalysis(int $leadId, ?array $websiteContext = null): void
    {
        $lead = $this->requireLead($leadId);
        $currentPayload = (new PublicationPayloadBuilder($this->config))->build($lead);
        $currentCategory = is_array($currentPayload['trace']['category_mapping'] ?? null)
            ? $currentPayload['trace']['category_mapping']
            : [];

        $analyzer = new WebsiteCategoryAnalyzer($this->config);
        $suggestedCategory = $analyzer->analyze($lead, $websiteContext);

        if (!$analyzer->shouldOverrideCampaign($suggestedCategory, $currentCategory)) {
            $this->leadRepository->mergeLeadMeta($leadId, [
                'website_category_analysis' => [
                    'checked_at' => date('Y-m-d H:i:s'),
                    'suggested_category' => $suggestedCategory,
                    'current_category' => $currentCategory,
                    'override_applied' => false,
                ],
            ], 'ai');
            return;
        }

        $suggestedCategory['override_applied'] = true;
        $suggestedCategory['overrode_category'] = [
            'id' => (int) ($currentCategory['id'] ?? 0),
            'name' => (string) ($currentCategory['name'] ?? ''),
            'source' => (string) ($currentCategory['source'] ?? ''),
            'campaign_id' => (string) ($currentCategory['campaign_id'] ?? ''),
        ];
        $suggestedCategory['reason'] = sprintf(
            '%s Overrode previous category %s (%d) after website analysis.',
            (string) ($suggestedCategory['reason'] ?? ''),
            (string) ($currentCategory['name'] ?? ''),
            (int) ($currentCategory['id'] ?? 0)
        );

        $this->leadRepository->mergeLeadMeta($leadId, [
            'polonads_category' => $suggestedCategory,
            'website_category_analysis' => [
                'checked_at' => date('Y-m-d H:i:s'),
                'suggested_category' => $suggestedCategory,
                'current_category' => $currentCategory,
                'override_applied' => true,
            ],
        ], 'ai');

        $this->leadRepository->recordPublicationLog(
            $leadId,
            'category_corrected',
            sprintf(
                'Website analysis corrected publication category from %s (%d) to %s (%d).',
                (string) ($currentCategory['name'] ?? ''),
                (int) ($currentCategory['id'] ?? 0),
                (string) ($suggestedCategory['name'] ?? ''),
                (int) ($suggestedCategory['id'] ?? 0)
            ),
            [
                'lead_id' => $leadId,
                'previous_category' => $currentCategory,
                'suggested_category' => $suggestedCategory,
            ]
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildWebsiteContext(array $payload): array
    {
        $company = is_array($payload['company'] ?? null) ? $payload['company'] : [];
        return (new WebsiteContentExtractor($this->config))->extract($company);
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function sanitizeProviderMeta(array $meta): array
    {
        $allowedKeys = [
            'url',
            'task',
            'status_code',
            'response_id',
            'x_request_id',
            'x_client_request_id',
            'openai_organization',
            'attempt',
            'max_attempts',
            'latency_ms',
            'retry_after_ms',
            'usage',
        ];

        $sanitized = [];
        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $meta)) {
                continue;
            }

            $sanitized[$key] = $meta[$key];
        }

        return $sanitized;
    }

    private static function stripPolishTranslationSection(string $body): string
    {
        $marker = "\n\n---\nWersja polska:\n";
        $position = strpos($body, $marker);
        if ($position === false) {
            return $body;
        }

        return trim(substr($body, 0, $position));
    }

    private static function simulatePolishTitle(string $sourceTitle): string
    {
        if (stripos($sourceTitle, ' po polsku') !== false) {
            return $sourceTitle;
        }

        return $sourceTitle . ' - po polsku';
    }

    private static function simulatePolishBody(string $sourceBody, string $companyName, string $category, string $location, string $website): string
    {
        if ($sourceBody !== '') {
            return implode("\n\n", [
                'To jest symulowane tlumaczenie polskie przygotowane na podstawie oryginalnego draftu angielskiego.',
                sprintf('%s prezentuje swoje uslugi w kategorii %s%s.', $companyName, strtolower($category), $location !== '' ? ' dla klientow w lokalizacji ' . $location : ''),
                'Ogloszenie podkresla rzetelna komunikacje, latwy kontakt oraz informacje potrzebne klientom do podjecia decyzji.',
                'Oryginalny draft EN pozostaje bez zmian powyzej, a ta sekcja sluzy do sprawdzenia finalnej wersji dwujezycznej.',
            ]);
        }

        return implode("\n\n", array_filter([
            sprintf('%s oferuje uslugi w kategorii %s dla spolecznosci polonijnej.', $companyName, strtolower($category)),
            $location !== '' ? sprintf('Ogloszenie jest przygotowane dla klientow szukajacych sprawdzonych firm w lokalizacji %s.', $location) : 'Ogloszenie jest przygotowane dla klientow w USA i Kanadzie.',
            'Klienci moga szybko sprawdzic zakres uslug, dane kontaktowe i powod, dla ktorego warto rozwazyc kontakt z firma.',
            $website !== '' ? sprintf('Dodatkowy kontekst marki pochodzi ze strony %s.', $website) : '',
        ]));
    }

    private static function resolveTranslationSourceTitle(array $meta): string
    {
        $title = trim((string) ($meta['translation_source_title'] ?? ''));
        if ($title !== '') {
            return $title;
        }

        return trim((string) ($meta['listing_title'] ?? ''));
    }

    private static function resolveTranslationSourceBody(array $meta): string
    {
        $body = trim((string) ($meta['translation_source_body'] ?? ''));
        if ($body !== '') {
            return $body;
        }

        return trim((string) ($meta['listing_body'] ?? ''));
    }

    private static function resolveTranslationSourceLanguage(array $meta): string
    {
        $language = trim((string) ($meta['translation_source_language'] ?? ''));
        if ($language !== '') {
            return $language;
        }

        return trim((string) ($meta['listing_language'] ?? 'en'));
    }

    private static function isTranslationRequestPending(array $meta): bool
    {
        $translationStatus = trim((string) ($meta['translation_status'] ?? ''));
        if (in_array($translationStatus, ['requested', 'in_progress'], true)) {
            return true;
        }

        return trim((string) ($meta['publication_status'] ?? '')) === 'translation_requested';
    }

    private function requireLead(int $leadId): array
    {
        $lead = $this->leadRepository->findLeadById($leadId);
        if ($lead === null) {
            throw new RuntimeException('Lead not found.');
        }

        return $lead;
    }

    private function normalizeVisualSubtype(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        $value = trim($value, '_');

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

        return in_array($value, $allowed, true) ? $value : 'general';
    }
}
