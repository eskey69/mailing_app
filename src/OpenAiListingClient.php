<?php

declare(strict_types=1);

namespace MailingApp;

use RuntimeException;

final class OpenAiListingClient
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';
    private const DEFAULT_MODEL = 'gpt-5-mini';
    private const DEFAULT_CONNECT_TIMEOUT = 15;
    private const DEFAULT_TIMEOUT = 120;
    private const DEFAULT_STORE = false;
    private const DEFAULT_MAX_RETRIES = 2;
    private const DEFAULT_RETRY_BASE_DELAY_MS = 800;
    private const DEFAULT_RETRY_MAX_DELAY_MS = 8000;
    private const TASK_DRAFT = 'draft';
    private const TASK_TRANSLATION = 'translation';
    private const TASK_CHECK = 'check';
    private const EN_BODY_TARGET_MIN_CHARS = 650;
    private const EN_BODY_TARGET_MAX_CHARS = 800;
    private const EN_BODY_HARD_MAX_CHARS = 900;

    private array $config;
    private WebsiteContentExtractor $websiteContentExtractor;
    /** @var array<string, mixed> */
    private array $lastHttpMeta = [];

    public function __construct(array $config, ?WebsiteContentExtractor $websiteContentExtractor = null)
    {
        $this->config = $config;
        $this->websiteContentExtractor = $websiteContentExtractor ?? new WebsiteContentExtractor($config);
    }

    public function isConfigured(): bool
    {
        return $this->resolveApiKey() !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfigurationStatus(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'api_key_source' => $this->resolveApiKeySource(),
            'model' => $this->resolveModel(),
            'draft_model' => $this->resolveModel(self::TASK_DRAFT),
            'translation_model' => $this->resolveModel(self::TASK_TRANSLATION),
            'check_model' => $this->resolveModel(self::TASK_CHECK),
            'base_url' => $this->resolveBaseUrl(),
            'organization_set' => $this->resolveOrganization() !== '',
            'project_set' => $this->resolveProject() !== '',
            'ca_bundle_path' => $this->resolveCaBundlePath(),
            'store' => $this->resolveStore(),
            'connect_timeout' => $this->resolveConnectTimeout(),
            'timeout' => $this->resolveTimeout(),
            'max_retries' => $this->resolveMaxRetries(),
            'task_settings' => [
                self::TASK_DRAFT => $this->describeTaskSettings(self::TASK_DRAFT),
                self::TASK_TRANSLATION => $this->describeTaskSettings(self::TASK_TRANSLATION),
                self::TASK_CHECK => $this->describeTaskSettings(self::TASK_CHECK),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getLastHttpMeta(): array
    {
        return $this->lastHttpMeta;
    }

    /**
     * Performs a small authenticated request against the Responses API.
     *
     * @return array<string, mixed>
     */
    public function checkConnection(): array
    {
        $apiKey = $this->resolveApiKey();
        if ($apiKey === '') {
            throw new RuntimeException('Missing OPENAI_API_KEY. Configure it before running the OpenAI connectivity check.');
        }

        $requestBody = $this->applyTaskRequestOptions([
            'model' => $this->resolveModel(self::TASK_CHECK),
            'input' => 'Return JSON with status="ok" and provider="openai".',
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'openai_connection_check',
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'status' => [
                                'type' => 'string',
                                'enum' => ['ok'],
                            ],
                            'provider' => [
                                'type' => 'string',
                                'enum' => ['openai'],
                            ],
                        ],
                        'required' => ['status', 'provider'],
                    ],
                    'strict' => true,
                ],
            ],
        ], self::TASK_CHECK);

        $response = $this->createResponse($requestBody, $apiKey, self::TASK_CHECK);
        $outputText = $this->extractOutputText($response);
        $decoded = json_decode($outputText, true);
        if (!is_array($decoded) || ($decoded['status'] ?? '') !== 'ok') {
            throw new RuntimeException('OpenAI connectivity check returned an unexpected payload.');
        }

        return [
            'status' => 'ok',
            'model' => $this->resolveModel(self::TASK_CHECK),
            'response_id' => (string) ($response['id'] ?? ''),
            'request_id' => (string) ($this->lastHttpMeta['x_request_id'] ?? ''),
            'client_request_id' => (string) ($this->lastHttpMeta['x_client_request_id'] ?? ''),
            'organization' => (string) ($this->lastHttpMeta['openai_organization'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $websiteContext
     * @return array{title: string, body: string, language: string, images: array<int, string>, source_urls: array<int, string>, visual_subtype: string}
     */
    public function generateDraft(array $payload, ?array $websiteContext = null): array
    {
        $apiKey = $this->resolveApiKey();
        if ($apiKey === '') {
            throw new RuntimeException('Missing OPENAI_API_KEY. Configure it on the server before using AI draft generation.');
        }

        $translationRequested = $this->isTranslationRequested($payload);
        $task = $translationRequested ? self::TASK_TRANSLATION : self::TASK_DRAFT;
        $websiteContext ??= $translationRequested
            ? ['pages' => [], 'source_urls' => [], 'combined_text' => '']
            : $this->websiteContentExtractor->extract(is_array($payload['company'] ?? null) ? $payload['company'] : []);

        $requestBody = $this->applyTaskRequestOptions([
            'model' => $this->resolveModel($task),
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->buildSystemPrompt($translationRequested),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->buildUserPrompt($payload, $websiteContext, $translationRequested),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'polonads_listing_draft',
                    'schema' => $this->buildResponseSchema(),
                    'strict' => true,
                ],
            ],
        ], $task);

        $response = $this->createResponse($requestBody, $apiKey, $task);
        $outputText = $this->extractOutputText($response);
        $decoded = json_decode($outputText, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAI returned invalid JSON for the listing draft.');
        }

        $title = trim((string) ($decoded['title'] ?? ''));
        $body = trim((string) ($decoded['body'] ?? ''));
        $language = trim((string) ($decoded['language'] ?? ($translationRequested ? 'pl' : 'en')));
        $images = $this->normalizeStringList($decoded['images'] ?? []);
        $sourceUrls = $this->normalizeStringList($decoded['source_urls'] ?? []);
        $visualSubtype = $this->normalizeVisualSubtype((string) ($decoded['visual_subtype'] ?? 'general'));

        if ($title === '' || $body === '') {
            throw new RuntimeException('OpenAI returned an incomplete draft. Both title and body are required.');
        }

        if ($translationRequested) {
            $language = 'pl';
            $sourceUrls = array_values(array_unique(array_merge(
                $sourceUrls,
                $this->normalizeStringList($payload['existing_listing_draft']['source_urls'] ?? [])
            )));
        } else {
            if ($language === 'bilingual') {
                $language = 'en';
            }

            $sourceUrls = array_values(array_unique(array_merge(
                $sourceUrls,
                $this->normalizeStringList($websiteContext['source_urls'] ?? []),
                $this->normalizeStringList([
                    $payload['company']['website'] ?? '',
                    $payload['company']['yp_url'] ?? '',
                ])
            )));
        }

        return [
            'title' => $title,
            'body' => $body,
            'language' => $language !== '' ? $language : ($translationRequested ? 'pl' : 'en'),
            'images' => $images,
            'source_urls' => $sourceUrls,
            'visual_subtype' => $visualSubtype,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $websiteContext
     */
    private function buildUserPrompt(array $payload, array $websiteContext, bool $translationRequested): string
    {
        $company = is_array($payload['company'] ?? null) ? $payload['company'] : [];
        $existingDraft = is_array($payload['existing_listing_draft'] ?? null) ? $payload['existing_listing_draft'] : [];
        $mapping = is_array($payload['polonads_mapping'] ?? null) ? $payload['polonads_mapping'] : [];
        $translationRequest = is_array($payload['translation_request'] ?? null) ? $payload['translation_request'] : [];

        $input = [
            'company' => $company,
            'polonads_mapping' => $mapping,
            'existing_listing_draft' => $existingDraft,
            'translation_request' => $translationRequest,
        ];

        if (!$translationRequested) {
            $input['website_context'] = [
                'pages' => $websiteContext['pages'] ?? [],
                'source_urls' => $websiteContext['source_urls'] ?? [],
                'combined_text' => $websiteContext['combined_text'] ?? '',
            ];
        }

        return "Input JSON:\n"
            . json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function buildSystemPrompt(bool $translationRequested): string
    {
        if ($translationRequested) {
            return 'Translate the existing English listing draft into polished natural Polish for Polonads.com. '
                . 'Keep all supported facts accurate, do not invent claims, and do not return the English original. '
                . 'Return the Polish title and Polish body so the application can append the translation below the original English version. '
                . 'Also return visual_subtype, preserving the existing listing visual_subtype when available, otherwise use general.';
        }

        return 'Write a short classified listing for Polonads.com based only on the provided business website and structured company data. '
            . 'Write like a helpful human briefly describing the business to potential customers, not like an advertising template. '
            . 'The text should be clear, warm, and useful, but not exaggerated. '
            . 'Use the provided website content first, then the structured company metadata. '
            . 'Every concrete fact in the listing must be grounded in company metadata, website_context pages, or source_urls. '
            . 'If the website pages are sparse, stay conservative and do not invent unsupported details. '
            . 'Do not list specific services, trades, products, certifications, guarantees, years in business, or team capabilities unless they are explicitly supported by the input. '
            . 'Avoid corporate buzzwords and empty phrases such as trusted, reliable, high-quality, professional team, dedicated, customer satisfaction, excellence, best, leading, tailored solutions, and ready to help unless the input explicitly supports them. '
            . 'Do not say the business is ready to help, can meet customer needs, or provides broad assistance unless the source text says so. '
            . 'For sparse sources, use neutral phrasing such as "is listed as" instead of claiming what the business offers. '
            . 'Prefer concrete details over slogans, use simple sentences, do not overuse adjectives, and do not sound like AI. '
            . 'Do not start every listing with "Welcome to" and do not end with hype. '
            . 'A good ending can simply invite the reader to visit the website or contact the business for details. '
            . 'When only a broad category is available, write a neutral directory-style listing that identifies the business, location, broad category, contact options, and available source links. '
            . 'Mention the city/state only when supported by the provided sources. '
            . 'Also choose one visual_subtype for stock-style category image selection. '
            . 'Allowed visual_subtype values are: general, marketing, construction, transport, warehouse, cleaning, caregiver, beauty, restaurant, office, medical, it, sales, education, home_services, insurance, legal_finance, real_estate. '
            . 'Choose the most specific value supported by the website content; use general when uncertain. '
            . sprintf(
                'The English body should be %d-%d characters, with an absolute maximum of %d characters. ',
                self::EN_BODY_TARGET_MIN_CHARS,
                self::EN_BODY_TARGET_MAX_CHARS,
                self::EN_BODY_HARD_MAX_CHARS
            )
            . 'Do not pad the text; if the source material is sparse, stay shorter and factual.';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResponseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'minLength' => 5,
                ],
                'body' => [
                    'type' => 'string',
                    'minLength' => 50,
                ],
                'language' => [
                    'type' => 'string',
                    'enum' => ['pl', 'en', 'bilingual'],
                ],
                'images' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'source_urls' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                    ],
                ],
                'visual_subtype' => [
                    'type' => 'string',
                    'enum' => [
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
                    ],
                ],
            ],
            'required' => ['title', 'body', 'language', 'images', 'source_urls', 'visual_subtype'],
        ];
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

    private function resolveApiKey(): string
    {
        $apiKey = trim((string) getenv('OPENAI_API_KEY'));
        if ($apiKey !== '') {
            return $apiKey;
        }

        $configuredKey = $this->config['ai']['openai_api_key'] ?? '';
        if (is_string($configuredKey)) {
            return trim($configuredKey);
        }

        return '';
    }

    private function resolveApiKeySource(): string
    {
        if (trim((string) getenv('OPENAI_API_KEY')) !== '') {
            return 'env';
        }

        $configuredKey = $this->config['ai']['openai_api_key'] ?? '';
        if (is_string($configuredKey) && trim($configuredKey) !== '') {
            return 'config';
        }

        return 'missing';
    }

    private function resolveModel(?string $task = null): string
    {
        foreach ($this->resolveTaskStringCandidates($task, 'model') as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return self::DEFAULT_MODEL;
    }

    private function resolveBaseUrl(): string
    {
        foreach ([
            getenv('OPENAI_BASE_URL'),
            $this->config['ai']['base_url'] ?? '',
        ] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return rtrim(trim($value), '/');
            }
        }

        return self::DEFAULT_BASE_URL;
    }

    private function resolveResponsesUrl(): string
    {
        $baseUrl = $this->resolveBaseUrl();
        if (str_ends_with($baseUrl, '/responses')) {
            return $baseUrl;
        }

        return $baseUrl . '/responses';
    }

    private function resolveOrganization(): string
    {
        foreach ([getenv('OPENAI_ORGANIZATION'), $this->config['ai']['organization'] ?? ''] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    private function resolveProject(): string
    {
        foreach ([getenv('OPENAI_PROJECT'), $this->config['ai']['project'] ?? ''] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    private function resolveCaBundlePath(): string
    {
        return Support::resolveHttpCaBundlePath($this->config);
    }

    private function resolveStore(): bool
    {
        $envValue = getenv('MAILING_APP_OPENAI_STORE');
        if ($envValue !== false) {
            $parsedEnvValue = $this->normalizeNullableBool($envValue);
            if ($parsedEnvValue !== null) {
                return $parsedEnvValue;
            }
        }

        $configValue = $this->config['ai']['store'] ?? self::DEFAULT_STORE;
        $parsedConfigValue = $this->normalizeNullableBool($configValue);
        if ($parsedConfigValue !== null) {
            return $parsedConfigValue;
        }

        return self::DEFAULT_STORE;
    }

    private function resolveConnectTimeout(): int
    {
        $envValue = getenv('MAILING_APP_OPENAI_CONNECT_TIMEOUT');
        if ($envValue !== false && trim($envValue) !== '') {
            return max(1, (int) $envValue);
        }

        $value = $this->config['ai']['connect_timeout'] ?? self::DEFAULT_CONNECT_TIMEOUT;
        return max(1, (int) $value);
    }

    private function resolveTimeout(): int
    {
        $envValue = getenv('MAILING_APP_OPENAI_TIMEOUT');
        if ($envValue !== false && trim($envValue) !== '') {
            return max($this->resolveConnectTimeout(), (int) $envValue);
        }

        $value = $this->config['ai']['timeout'] ?? self::DEFAULT_TIMEOUT;
        return max($this->resolveConnectTimeout(), (int) $value);
    }

    private function resolveMaxRetries(): int
    {
        $envValue = getenv('MAILING_APP_OPENAI_MAX_RETRIES');
        if ($envValue !== false && trim($envValue) !== '') {
            return max(0, (int) $envValue);
        }

        return max(0, (int) ($this->config['ai']['max_retries'] ?? self::DEFAULT_MAX_RETRIES));
    }

    private function resolveRetryBaseDelayMs(): int
    {
        $envValue = getenv('MAILING_APP_OPENAI_RETRY_BASE_DELAY_MS');
        if ($envValue !== false && trim($envValue) !== '') {
            return max(100, (int) $envValue);
        }

        return max(100, (int) ($this->config['ai']['retry_base_delay_ms'] ?? self::DEFAULT_RETRY_BASE_DELAY_MS));
    }

    private function resolveRetryMaxDelayMs(): int
    {
        $envValue = getenv('MAILING_APP_OPENAI_RETRY_MAX_DELAY_MS');
        if ($envValue !== false && trim($envValue) !== '') {
            return max($this->resolveRetryBaseDelayMs(), (int) $envValue);
        }

        return max(
            $this->resolveRetryBaseDelayMs(),
            (int) ($this->config['ai']['retry_max_delay_ms'] ?? self::DEFAULT_RETRY_MAX_DELAY_MS)
        );
    }

    private function resolveMaxOutputTokens(string $task): int
    {
        $default = match ($task) {
            self::TASK_CHECK => 80,
            self::TASK_TRANSLATION => 1800,
            default => 1600,
        };

        foreach ($this->resolveTaskStringCandidates($task, 'max_output_tokens', ['MAILING_APP_OPENAI_MAX_OUTPUT_TOKENS']) as $value) {
            if (is_string($value) && trim($value) !== '') {
                return max(64, (int) $value);
            }

            if (is_int($value)) {
                return max(64, $value);
            }
        }

        return $default;
    }

    private function resolveReasoningEffort(string $task): string
    {
        $default = match ($task) {
            self::TASK_CHECK => 'minimal',
            self::TASK_TRANSLATION => 'low',
            default => 'medium',
        };

        foreach ($this->resolveTaskStringCandidates($task, 'reasoning_effort', ['MAILING_APP_OPENAI_REASONING_EFFORT']) as $value) {
            $normalized = $this->normalizeReasoningEffort($value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return $default;
    }

    private function resolveVerbosity(string $task): string
    {
        $default = match ($task) {
            self::TASK_CHECK => 'low',
            self::TASK_TRANSLATION => 'low',
            default => 'medium',
        };

        foreach ($this->resolveTaskStringCandidates($task, 'verbosity', ['MAILING_APP_OPENAI_VERBOSITY']) as $value) {
            $normalized = $this->normalizeVerbosity($value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return $default;
    }

    private function resolvePromptCacheKey(string $task): string
    {
        $default = match ($task) {
            self::TASK_CHECK => 'polonads:openai:check:v1',
            self::TASK_TRANSLATION => 'polonads:listing:translation:v2',
            default => 'polonads:listing:draft:v2',
        };

        foreach ($this->resolveTaskStringCandidates($task, 'prompt_cache_key', ['MAILING_APP_OPENAI_PROMPT_CACHE_KEY']) as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isTranslationRequested(array $payload): bool
    {
        $translationRequest = is_array($payload['translation_request'] ?? null) ? $payload['translation_request'] : [];
        return ($translationRequest['requested'] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $requestBody
     * @return array<string, mixed>
     */
    private function createResponse(array $requestBody, string $apiKey, string $task): array
    {
        if (!array_key_exists('store', $requestBody)) {
            $requestBody['store'] = $this->resolveStore();
        }

        return $this->postJson($this->resolveResponsesUrl(), $requestBody, $apiKey, $task);
    }

    /**
     * @param array<string, mixed> $requestBody
     * @return array<string, mixed>
     */
    private function applyTaskRequestOptions(array $requestBody, string $task): array
    {
        $model = trim((string) ($requestBody['model'] ?? $this->resolveModel($task)));
        if ($model === '') {
            $model = $this->resolveModel($task);
        }

        $requestBody['model'] = $model;
        $requestBody['max_output_tokens'] = $this->resolveMaxOutputTokens($task);
        $requestBody['prompt_cache_key'] = $this->resolvePromptCacheKey($task);

        if (!isset($requestBody['text']) || !is_array($requestBody['text'])) {
            $requestBody['text'] = [];
        }

        if ($this->supportsReasoningControls($model)) {
            $requestBody['reasoning'] = [
                'effort' => $this->resolveReasoningEffort($task),
            ];
            $requestBody['text']['verbosity'] = $this->resolveVerbosity($task);
        }

        return $requestBody;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $body, string $apiKey, string $task): array
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('Failed to encode OpenAI request body.');
        }

        $maxAttempts = max(1, $this->resolveMaxRetries() + 1);
        $lastErrorMessage = 'OpenAI request failed.';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $clientRequestId = $this->buildClientRequestId();
            $headers = $this->buildHeaders($apiKey, $clientRequestId);
            $result = function_exists('curl_init')
                ? $this->performCurlRequest($url, $json, $headers)
                : $this->performStreamRequest($url, $json, $headers);

            $responseHeaders = is_array($result['headers'] ?? null) ? $result['headers'] : [];
            $statusCode = (int) ($result['status_code'] ?? 0);
            $retryAfterMs = $this->resolveRetryAfterMs($responseHeaders);
            $meta = [
                'url' => $url,
                'task' => $task,
                'status_code' => $statusCode,
                'response_id' => '',
                'x_request_id' => (string) ($responseHeaders['x-request-id'] ?? ''),
                'x_client_request_id' => $clientRequestId,
                'openai_organization' => (string) ($responseHeaders['openai-organization'] ?? ''),
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'latency_ms' => (int) ($result['latency_ms'] ?? 0),
                'retry_after_ms' => $retryAfterMs,
            ];
            $this->lastHttpMeta = $meta;

            $transportError = trim((string) ($result['error'] ?? ''));
            if ($transportError !== '') {
                $lastErrorMessage = $this->appendHttpDetails('OpenAI request failed: ' . $transportError, $meta);
                if ($attempt < $maxAttempts && $this->isRetryableStatusCode($statusCode)) {
                    $this->pauseBeforeRetry($attempt, $retryAfterMs);
                    continue;
                }

                throw new RuntimeException($lastErrorMessage);
            }

            $raw = $result['raw'] ?? null;
            if (!is_string($raw) || $raw === '') {
                $lastErrorMessage = $this->appendHttpDetails('OpenAI request returned an empty response.', $meta);
                if ($attempt < $maxAttempts && $this->isRetryableStatusCode($statusCode)) {
                    $this->pauseBeforeRetry($attempt, $retryAfterMs);
                    continue;
                }

                throw new RuntimeException($lastErrorMessage);
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('OpenAI returned invalid JSON.');
            }

            $meta['response_id'] = (string) ($decoded['id'] ?? '');
            if (is_array($decoded['usage'] ?? null)) {
                $meta['usage'] = $decoded['usage'];
            }
            $this->lastHttpMeta = $meta;

            if ($statusCode >= 400 || isset($decoded['error'])) {
                $lastErrorMessage = $this->buildApiErrorMessage($decoded, $statusCode);
                if ($attempt < $maxAttempts && $this->isRetryableApiFailure($statusCode, $decoded)) {
                    $this->pauseBeforeRetry($attempt, $retryAfterMs);
                    continue;
                }

                throw new RuntimeException($lastErrorMessage);
            }

            return $decoded;
        }

        throw new RuntimeException($lastErrorMessage);
    }

    /**
     * @param list<string> $headers
     * @return array{status_code: int, headers: array<string, string>, raw: string, error: string, latency_ms: int}
     */
    private function performCurlRequest(string $url, string $json, array $headers): array
    {
        $responseHeaders = [];
        $handle = curl_init($url);
        if ($handle === false) {
            return [
                'status_code' => 0,
                'headers' => [],
                'raw' => '',
                'error' => 'Unable to initialize HTTP client for OpenAI.',
                'latency_ms' => 0,
            ];
        }

        $caBundlePath = $this->resolveCaBundlePath();
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $this->resolveConnectTimeout(),
            CURLOPT_TIMEOUT => $this->resolveTimeout(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $headerLine) use (&$responseHeaders): int {
                $trimmed = trim($headerLine);
                if ($trimmed === '' || !str_contains($trimmed, ':')) {
                    return strlen($headerLine);
                }

                [$name, $value] = explode(':', $trimmed, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
                return strlen($headerLine);
            },
            CURLOPT_USERAGENT => 'PolonadsMailingApp/1.0',
        ]);

        if ($caBundlePath !== '') {
            curl_setopt($handle, CURLOPT_CAINFO, $caBundlePath);
        }

        $startedAt = microtime(true);
        $raw = curl_exec($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        curl_close($handle);

        return [
            'status_code' => $statusCode,
            'headers' => $responseHeaders,
            'raw' => is_string($raw) ? $raw : '',
            'error' => $error,
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * @param list<string> $headers
     * @return array{status_code: int, headers: array<string, string|int>, raw: string, error: string, latency_ms: int}
     */
    private function performStreamRequest(string $url, string $json, array $headers): array
    {
        $caBundlePath = $this->resolveCaBundlePath();
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => $this->resolveTimeout(),
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $json,
                'ignore_errors' => true,
            ],
            'ssl' => array_filter([
                'verify_peer' => true,
                'verify_peer_name' => true,
                'cafile' => $caBundlePath !== '' ? $caBundlePath : null,
            ], static fn ($value): bool => $value !== null),
        ]);

        $startedAt = microtime(true);
        $raw = @file_get_contents($url, false, $context);
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $responseHeaders = $this->parseResponseHeaders($http_response_header ?? []);
        $statusCode = (int) ($responseHeaders['status_code'] ?? 0);

        return [
            'status_code' => $statusCode,
            'headers' => $responseHeaders,
            'raw' => is_string($raw) ? $raw : '',
            'error' => is_string($raw) ? '' : 'file_get_contents failed.',
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * @param array<int, string> $rawHeaders
     * @return array<string, string|int>
     */
    private function parseResponseHeaders(array $rawHeaders): array
    {
        $parsed = [
            'status_code' => 0,
        ];

        foreach ($rawHeaders as $index => $headerLine) {
            $trimmed = trim($headerLine);
            if ($trimmed === '') {
                continue;
            }

            if ($index === 0 && preg_match('~^HTTP/\S+\s+(\d{3})~', $trimmed, $matches) === 1) {
                $parsed['status_code'] = (int) $matches[1];
                continue;
            }

            if (!str_contains($trimmed, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $trimmed, 2);
            $parsed[strtolower(trim($name))] = trim($value);
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function buildApiErrorMessage(array $decoded, int $statusCode): string
    {
        $message = is_array($decoded['error'] ?? null)
            ? (string) ($decoded['error']['message'] ?? 'OpenAI request failed.')
            : 'OpenAI request failed.';

        $details = [];
        if ($statusCode > 0) {
            $details[] = 'HTTP ' . $statusCode;
        }

        $responseId = (string) ($decoded['id'] ?? '');
        if ($responseId !== '') {
            $details[] = 'response ' . $responseId;
        }

        $requestId = (string) ($this->lastHttpMeta['x_request_id'] ?? '');
        if ($requestId !== '') {
            $details[] = 'x-request-id ' . $requestId;
        }

        if ($details !== []) {
            $message .= ' [' . implode(', ', $details) . ']';
        }

        return $message;
    }

    /**
     * @return list<string>
     */
    private function buildHeaders(string $apiKey, string $clientRequestId): array
    {
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Client-Request-Id: ' . $clientRequestId,
        ];

        $organization = $this->resolveOrganization();
        if ($organization !== '') {
            $headers[] = 'OpenAI-Organization: ' . $organization;
        }

        $project = $this->resolveProject();
        if ($project !== '') {
            $headers[] = 'OpenAI-Project: ' . $project;
        }

        return $headers;
    }

    private function buildClientRequestId(): string
    {
        return 'polonads-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(8));
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractOutputText(array $response): string
    {
        $status = trim((string) ($response['status'] ?? ''));
        if ($status === 'incomplete') {
            $incompleteDetails = is_array($response['incomplete_details'] ?? null) ? $response['incomplete_details'] : [];
            $reason = trim((string) ($incompleteDetails['reason'] ?? ''));
            throw new RuntimeException(
                $reason !== ''
                    ? 'OpenAI response was incomplete: ' . $reason . '.'
                    : 'OpenAI response was incomplete.'
            );
        }

        $outputText = trim((string) ($response['output_text'] ?? ''));
        if ($outputText !== '') {
            return $this->stripJsonFence($outputText);
        }

        $refusal = $this->extractRefusalText($response);
        if ($refusal !== '') {
            throw new RuntimeException('OpenAI refused to generate listing content: ' . $refusal);
        }

        $output = is_array($response['output'] ?? null) ? $response['output'] : [];
        foreach ($output as $item) {
            $content = is_array($item['content'] ?? null) ? $item['content'] : [];
            foreach ($content as $contentItem) {
                $candidate = trim((string) ($contentItem['text'] ?? ''));
                if ($candidate !== '') {
                    return $this->stripJsonFence($candidate);
                }
            }
        }

        throw new RuntimeException('OpenAI response did not contain output_text.');
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }

            $candidate = trim($item);
            if ($candidate === '') {
                continue;
            }

            $normalized[] = $candidate;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param mixed $value
     */
    private function normalizeNullableBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return null;
    }

    /**
     * @param mixed $value
     */
    private function normalizeReasoningEffort(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $normalized = strtolower(trim($value));
        return in_array($normalized, ['minimal', 'low', 'medium', 'high'], true) ? $normalized : '';
    }

    /**
     * @param mixed $value
     */
    private function normalizeVerbosity(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $normalized = strtolower(trim($value));
        return in_array($normalized, ['low', 'medium', 'high'], true) ? $normalized : '';
    }

    private function stripJsonFence(string $value): string
    {
        $value = trim($value);
        if (str_starts_with($value, '```')) {
            $value = preg_replace('/^```(?:json)?\s*/i', '', $value) ?? $value;
            $value = preg_replace('/\s*```$/', '', $value) ?? $value;
        }

        return trim($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function describeTaskSettings(string $task): array
    {
        return [
            'model' => $this->resolveModel($task),
            'max_output_tokens' => $this->resolveMaxOutputTokens($task),
            'reasoning_effort' => $this->resolveReasoningEffort($task),
            'verbosity' => $this->resolveVerbosity($task),
            'prompt_cache_key' => $this->resolvePromptCacheKey($task),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function resolveTaskStringCandidates(?string $task, string $setting, array $genericEnvKeys = []): array
    {
        $taskPrefix = $task !== null ? 'MAILING_APP_OPENAI_' . strtoupper($task) . '_' . strtoupper($setting) : '';
        $configTaskKey = $task !== null ? $task . '_' . $setting : '';

        $values = [];
        if ($taskPrefix !== '') {
            $values[] = getenv($taskPrefix);
        }

        foreach ($genericEnvKeys as $envKey) {
            $values[] = getenv($envKey);
        }

        if ($task !== null) {
            $taskConfig = $this->config['ai']['tasks'][$task][$setting] ?? null;
            if ($taskConfig !== null) {
                $values[] = $taskConfig;
            }
        }

        if ($configTaskKey !== '' && array_key_exists($configTaskKey, $this->config['ai'] ?? [])) {
            $values[] = $this->config['ai'][$configTaskKey];
        }

        if ($setting === 'model') {
            $values[] = getenv('MAILING_APP_OPENAI_MODEL');
            $values[] = getenv('OPENAI_MODEL');
            $values[] = $this->config['ai']['model'] ?? '';
        } elseif (!in_array('MAILING_APP_OPENAI_' . strtoupper($setting), $genericEnvKeys, true)) {
            $values[] = getenv('MAILING_APP_OPENAI_' . strtoupper($setting));
            $values[] = $this->config['ai'][$setting] ?? null;
        }

        return $values;
    }

    private function supportsReasoningControls(string $model): bool
    {
        return str_starts_with(strtolower(trim($model)), 'gpt-5');
    }

    private function isRetryableStatusCode(int $statusCode): bool
    {
        return $statusCode === 0 || in_array($statusCode, [408, 409, 429, 500, 502, 503, 504], true);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function isRetryableApiFailure(int $statusCode, array $decoded): bool
    {
        if ($this->isRetryableStatusCode($statusCode)) {
            return true;
        }

        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $errorType = strtolower(trim((string) ($error['type'] ?? '')));

        return in_array($errorType, ['server_error', 'rate_limit_error'], true);
    }

    /**
     * @param array<string, string|int> $responseHeaders
     */
    private function resolveRetryAfterMs(array $responseHeaders): int
    {
        $value = $responseHeaders['retry-after'] ?? '';
        if (!is_string($value) || trim($value) === '') {
            return 0;
        }

        $candidate = trim($value);
        if (ctype_digit($candidate)) {
            return max(0, min($this->resolveRetryMaxDelayMs(), ((int) $candidate) * 1000));
        }

        $timestamp = strtotime($candidate);
        if ($timestamp === false) {
            return 0;
        }

        return max(0, min($this->resolveRetryMaxDelayMs(), ($timestamp - time()) * 1000));
    }

    private function pauseBeforeRetry(int $attempt, int $retryAfterMs): void
    {
        $delayMs = $retryAfterMs;
        if ($delayMs <= 0) {
            $delayMs = min(
                $this->resolveRetryMaxDelayMs(),
                $this->resolveRetryBaseDelayMs() * (2 ** max(0, $attempt - 1))
            );
            $delayMs += random_int(0, 250);
        }

        usleep(max(0, $delayMs) * 1000);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function appendHttpDetails(string $message, array $meta): string
    {
        $details = [];
        if ((int) ($meta['status_code'] ?? 0) > 0) {
            $details[] = 'HTTP ' . (int) $meta['status_code'];
        }

        $requestId = trim((string) ($meta['x_request_id'] ?? ''));
        if ($requestId !== '') {
            $details[] = 'x-request-id ' . $requestId;
        }

        if ($details === []) {
            return $message;
        }

        return $message . ' [' . implode(', ', $details) . ']';
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractRefusalText(array $response): string
    {
        $output = is_array($response['output'] ?? null) ? $response['output'] : [];
        foreach ($output as $item) {
            $content = is_array($item['content'] ?? null) ? $item['content'] : [];
            foreach ($content as $contentItem) {
                if ((string) ($contentItem['type'] ?? '') !== 'refusal') {
                    continue;
                }

                $refusal = trim((string) ($contentItem['refusal'] ?? $contentItem['text'] ?? ''));
                if ($refusal !== '') {
                    return $refusal;
                }
            }
        }

        return '';
    }
}
