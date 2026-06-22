<?php

declare(strict_types=1);

namespace MailingApp;

final class Support
{
    private const DEFAULT_PUBLIC_URL = 'https://polonads.com/mailing_app/public';
    private const LOCAL_UNISERVER_CA_BUNDLE = 'C:\\Users\\skrupa\\UniServerZ\\home\\us_opt1\\vendor\\composer\\ca-bundle\\res\\cacert.pem';

    public static function baseUrl(array $config, string $path = ''): string
    {
        $base = rtrim((string) ($config['app']['base_url'] ?? ''), '/');
        $suffix = ltrim($path, '/');

        if ($suffix === '') {
            return $base === '' ? '/' : $base;
        }

        return ($base === '' ? '' : $base) . '/' . $suffix;
    }

    public static function publicUrl(array $config, string $path = ''): string
    {
        $public = self::resolvePublicBaseUrl($config);

        $suffix = ltrim($path, '/');
        if ($suffix === '') {
            return $public;
        }

        return $public . '/' . $suffix;
    }

    private static function resolvePublicBaseUrl(array $config): string
    {
        $configured = rtrim((string) ($config['app']['public_url'] ?? ''), '/');
        if (self::isUsableAbsoluteHttpUrl($configured)) {
            return $configured;
        }

        $derived = self::derivePublicUrl($config);
        if ($derived !== '') {
            return $derived;
        }

        $base = rtrim((string) ($config['app']['base_url'] ?? ''), '/');
        if ($base !== '' && str_starts_with($base, '/')) {
            return 'https://polonads.com' . $base;
        }

        return self::DEFAULT_PUBLIC_URL;
    }

    private static function derivePublicUrl(array $config): string
    {
        $base = rtrim((string) ($config['app']['base_url'] ?? ''), '/');
        $candidates = [
            (string) ($config['app']['account_login_url'] ?? ''),
            (string) ($config['app']['account_setup_url'] ?? ''),
            (string) ($config['app']['self_publish_url'] ?? ''),
            (string) ($config['app']['polonads_listing_url_template'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            if (!self::isUsableAbsoluteHttpUrl($candidate)) {
                continue;
            }

            $parts = parse_url($candidate);
            $scheme = (string) ($parts['scheme'] ?? '');
            $host = (string) ($parts['host'] ?? '');
            if ($scheme === '' || $host === '') {
                continue;
            }

            $origin = $scheme . '://' . $host;
            return $base !== '' ? $origin . '/' . ltrim($base, '/') : $origin;
        }

        return '';
    }

    private static function isUsableAbsoluteHttpUrl(string $value): bool
    {
        if (preg_match('~^https?://[^/]+(?:/.*)?$~i', $value) !== 1) {
            return false;
        }

        $parts = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return str_contains($host, '.');
    }

    public static function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public static function yesNo(bool $value): string
    {
        return $value ? 'tak' : 'nie';
    }

    public static function approvalLabel(string $status): string
    {
        return [
            'pending' => 'oczekuje',
            'approved' => 'zatwierdzony',
            'rejected' => 'odrzucony',
        ][$status] ?? $status;
    }

    public static function contactLabel(string $status): string
    {
        return [
            'new' => 'nowy',
            'draft_ready' => 'draft gotowy',
            'approved' => 'zatwierdzony',
            'client_review' => 'klient edytuje draft',
            'published' => 'opublikowany',
            'sent' => 'wysłany',
            'replied' => 'odpowiedź klienta',
            'skipped' => 'pominięty',
            'failed' => 'błąd',
        ][$status] ?? $status;
    }

    public static function workflowEventLabel(string $eventType): string
    {
        return [
            'contact_status' => 'status kontaktu',
            'approval_status' => 'status zatwierdzenia',
            'campaign_id' => 'kampania',
            'email_subject' => 'temat emaila',
            'email_draft' => 'draft emaila',
            'email_final' => 'finalna wersja emaila',
            'notes' => 'notatki',
            'response_type' => 'odpowiedź klienta',
            'recipient_response' => 'odpowiedź klienta',
            'account_status' => 'status konta',
            'publication_status' => 'status publikacji',
            'draft_language' => 'język draftu',
            'listing_url' => 'link ogłoszenia',
            'listing_title' => 'tytuł ogłoszenia',
            'listing_body' => 'treść ogłoszenia',
            'listing_language' => 'język ogłoszenia',
            'ai_draft_status' => 'status draftu AI',
            'ai_generated_at' => 'czas draftu AI',
            'ai_provider' => 'źródło draftu AI',
            'listing_payload_version' => 'wersja payloadu ogłoszenia',
            'client_intent' => 'decyzja klienta',
            'is_mailable' => 'do mailingu',
            'email_open' => 'otwarcie emaila',
            'email_click' => 'klikniecie w emailu',
        ][$eventType] ?? $eventType;
    }

    public static function workflowValueLabel(string $eventType, string $value): string
    {
        if ($value === '') {
            return 'puste';
        }

        if ($eventType === 'contact_status') {
            return self::contactLabel($value);
        }

        if ($eventType === 'approval_status') {
            return self::approvalLabel($value);
        }

        $labels = [
            'request_draft' => 'klient prosi o draft',
            'self_publish' => 'klient chce dodać sam',
            'contact_later' => 'kontakt później',
            'unsubscribe' => 'wypisanie z listy',
            'requested' => 'poproszono o draft',
            'drafted' => 'draft przygotowany',
            'approved' => 'zaakceptowane',
            'live' => 'opublikowane',
            'deferred' => 'odłożone',
            'self_publish_requested' => 'wybrano self-publish',
            'not_created' => 'nie utworzono',
            'created' => 'utworzono',
            'pending_publication' => 'oczekuje na publikację',
            'active' => 'aktywne',
            'ready' => 'gotowy',
            'in_progress' => 'w toku',
            'failed' => 'blad',
            'en' => 'angielski',
            'pl' => 'polski',
            'bilingual' => 'dwujęzyczny',
            'en+pl' => 'angielski i polski',
            'simulation' => 'symulacja',
            'ai' => 'AI',
            'ai_auto_translation' => 'AI auto tlumaczenie',
            'openai' => 'OpenAI',
            'openai_translation' => 'OpenAI tlumaczenie',
            'translation_in_progress' => 'tlumaczenie w toku',
            'translation_failed' => 'blad tlumaczenia',
            'translation_ready' => 'tlumaczenie gotowe',
            'save' => 'zapisano zmiany',
            'approve' => 'zaakceptowano',
            'approve_polish' => 'zaakceptowano z wersją polską',
            '0' => 'nie',
            '1' => 'tak',
        ];

        if (isset($labels[$value])) {
            return $labels[$value];
        }

        if (in_array($eventType, ['email_draft', 'email_final', 'notes'], true) && mb_strlen($value) > 80) {
            return mb_substr($value, 0, 80) . '...';
        }

        return $value;
    }

    public static function workflowMessageLabel(string $message): string
    {
        return [
            'Contact status updated.' => 'Zmieniono status kontaktu.',
            'Approval status updated.' => 'Zmieniono status zatwierdzenia.',
            'Campaign updated.' => 'Zmieniono kampanię.',
            'Email subject updated.' => 'Zmieniono temat emaila.',
            'Email draft updated.' => 'Zmieniono draft emaila.',
            'Final email updated.' => 'Zmieniono finalną wersję emaila.',
            'Internal notes updated.' => 'Zmieniono notatki wewnętrzne.',
            'Recipient path updated.' => 'Zmieniono ścieżkę odpowiedzi klienta.',
            'Account status updated.' => 'Zmieniono status konta.',
            'Publication status updated.' => 'Zmieniono status publikacji.',
            'Draft language updated.' => 'Zmieniono język draftu.',
            'Listing URL updated.' => 'Zmieniono link ogłoszenia.',
            'Listing title updated.' => 'Zmieniono tytuł ogłoszenia.',
            'Listing body updated.' => 'Zmieniono treść ogłoszenia.',
            'Listing language updated.' => 'Zmieniono język ogłoszenia.',
            'AI draft status updated.' => 'Zmieniono status draftu AI.',
            'AI draft generation time updated.' => 'Zmieniono czas wygenerowania draftu AI.',
            'AI draft source updated.' => 'Zmieniono źródło draftu AI.',
            'Listing payload version updated.' => 'Zmieniono wersję payloadu ogłoszenia.',
            'Client intent updated.' => 'Zmieniono decyzję klienta.',
            'Lead sent successfully via SMTP.' => 'Email wysłany poprawnie przez SMTP.',
            'Email opened via tracking pixel.' => 'Email zostal otwarty wedlug piksela sledzacego.',
            'Email link clicked.' => 'Kliknieto link z emaila.',
            'Recipient requested a draft for the free listing.' => 'Klient poprosił o draft darmowego ogłoszenia.',
            'Recipient chose to publish the listing manually.' => 'Klient wybrał samodzielne dodanie ogłoszenia.',
            'Recipient asked to be contacted later.' => 'Klient poprosił o kontakt później.',
            'Recipient opted out and should be removed from future outreach.' => 'Klient wypisał się z listy mailingowej.',
            'Recipient approved the draft listing for publication.' => 'Klient zaakceptował draft do publikacji.',
            'Listing approved and published for the client.' => 'Ogłoszenie zostało zaakceptowane i opublikowane.',
        ][$message] ?? $message;
    }

    public static function buildResponseUrl(array $config, int $leadId, string $action): string
    {
        $token = self::signLeadAction($config, $leadId, $action);
        return self::publicUrl($config, 'respond.php?action=' . rawurlencode($action) . '&lead=' . $leadId . '&token=' . rawurlencode($token));
    }

    public static function buildReviewUrl(array $config, int $leadId, string $action = 'open'): string
    {
        $token = self::signLeadAction($config, $leadId, 'review_' . $action);
        return self::publicUrl($config, 'review.php?action=' . rawurlencode($action) . '&lead=' . $leadId . '&token=' . rawurlencode($token));
    }

    public static function buildTrackingUrl(array $config, int $leadId, string $eventType, string $mailTemplateId = ''): string
    {
        $token = self::signTrackingToken($config, $leadId, $eventType, $mailTemplateId);

        return self::publicUrl(
            $config,
            'track.php?event=' . rawurlencode($eventType)
            . '&lead=' . $leadId
            . '&template=' . rawurlencode($mailTemplateId)
            . '&token=' . rawurlencode($token)
        );
    }

    public static function signLeadAction(array $config, int $leadId, string $action): string
    {
        $secret = self::resolveResponseSecret($config);

        return hash_hmac('sha256', $leadId . '|' . $action, $secret);
    }

    public static function verifyLeadActionToken(array $config, int $leadId, string $action, string $token): bool
    {
        $expected = self::signLeadAction($config, $leadId, $action);
        if ($expected === '' || $token === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }

    public static function signTrackingToken(array $config, int $leadId, string $eventType, string $mailTemplateId = ''): string
    {
        $secret = self::resolveResponseSecret($config);

        return hash_hmac('sha256', $leadId . '|track|' . $eventType . '|' . $mailTemplateId, $secret);
    }

    public static function verifyTrackingToken(
        array $config,
        int $leadId,
        string $eventType,
        string $mailTemplateId,
        string $token
    ): bool {
        $expected = self::signTrackingToken($config, $leadId, $eventType, $mailTemplateId);
        if ($expected === '' || $token === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }

    public static function resolveHttpCaBundlePath(array $config): string
    {
        $candidates = [
            getenv('OPENAI_CAINFO'),
            getenv('CURL_CA_BUNDLE'),
            getenv('SSL_CERT_FILE'),
            $config['ai']['ca_bundle_path'] ?? '',
            self::LOCAL_UNISERVER_CA_BUNDLE,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $path = trim($candidate);
            if ($path === '' || !is_file($path)) {
                continue;
            }

            return $path;
        }

        return '';
    }

    public static function simplifyWebsiteToDomain(?string $website): string
    {
        $website = trim((string) $website);
        if ($website === '') {
            return '';
        }

        if (!preg_match('~^https?://~i', $website)) {
            $website = 'https://' . ltrim($website, '/');
        }

        $host = (string) parse_url($website, PHP_URL_HOST);
        if ($host === '') {
            return '';
        }

        $host = strtolower($host);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return trim($host, '.');
    }

    private static function resolveResponseSecret(array $config): string
    {
        $secret = trim((string) ($config['app']['response_secret'] ?? ''));
        if ($secret !== '') {
            return $secret;
        }

        $fallbackSeedCandidates = [
            trim((string) ($config['mail']['password'] ?? '')),
            trim((string) ($config['db']['password'] ?? '')),
            trim((string) ($config['polonads_db']['password'] ?? '')),
        ];

        foreach ($fallbackSeedCandidates as $seed) {
            if ($seed !== '') {
                return hash('sha256', 'mailing-app-link-secret|' . $seed . '|' . self::resolvePublicBaseUrl($config));
            }
        }

        return hash('sha256', __FILE__ . '|' . __DIR__ . '|' . self::resolvePublicBaseUrl($config) . '|' . (string) ($config['app']['name'] ?? 'mailing-app'));
    }
}
