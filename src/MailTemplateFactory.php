<?php

declare(strict_types=1);

namespace MailingApp;

final class MailTemplateFactory
{
    private const POLONADS_ICON_URL = 'https://polonads.com/images/headers/polonads_icon.png';
    private const BIGSKY_LOGO_URL = 'https://bigskydeals.com/wp-content/uploads/2025/09/BIGSKYDEALS-LOGO@4x.png';
    private const POLONADS_LOGIN_URL = 'https://polonads.com/index.php/en-us/login';
    private const POLONADS_RESET_URL = 'https://polonads.com/index.php/en-us/component/users/reset';
    private const POLONADS_LISTING_URL_TEMPLATE = 'https://polonads.com/index.php/en-us/dodaj-ogloszenie-uslugi-2/%d';
    private const COLOR_BG = '#f7f6f2';
    private const COLOR_CARD = '#fffdfa';
    private const COLOR_BORDER = '#e8dccf';
    private const COLOR_TEXT = '#1f2430';
    private const COLOR_MUTED = '#6d7281';
    private const COLOR_PRIMARY = '#D55D0F';
    private const COLOR_SECONDARY = '#394395';
    private const COLOR_SECONDARY_SOFT = '#eef0ff';

    /**
     * @return array{mail_template_id: string, email_subject: string, email_draft: string, email_final: string}
     */
    public static function build(string $templateId, array $lead, array $config = []): array
    {
        $companyName = trim((string) ($lead['company_name'] ?? ''));
        $category = trim((string) ($lead['category'] ?? ''));
        $city = trim((string) ($lead['city'] ?? ''));
        $state = trim((string) ($lead['state'] ?? ''));
        $location = self::buildLocation($city, $state);
        $businessLabel = self::buildBusinessLabel($companyName, $category, $location);
        $callToActions = self::buildCallToActions($config, (int) ($lead['id'] ?? 0));

        switch ($templateId) {
            case 'polonads_intro_v1':
                return [
                    'mail_template_id' => 'polonads_intro_v1',
                    'email_subject' => self::buildIntroSubject($companyName, $category),
                    'email_draft' => self::buildIntroDraft($businessLabel, $callToActions),
                    'email_final' => '',
                ];

            case 'polonads_followup_v1':
                return [
                    'mail_template_id' => 'polonads_followup_v1',
                    'email_subject' => self::buildFollowupSubject($companyName),
                    'email_draft' => self::buildFollowupDraft($businessLabel),
                    'email_final' => '',
                ];

            case 'polonads_interest_reply_v1':
                return [
                    'mail_template_id' => 'polonads_interest_reply_v1',
                    'email_subject' => self::buildInterestReplySubject($companyName),
                    'email_draft' => self::buildInterestReplyDraft($lead, $businessLabel, $config),
                    'email_final' => '',
                ];

            case 'polonads_draft_review_v1':
                return [
                    'mail_template_id' => 'polonads_draft_review_v1',
                    'email_subject' => self::buildDraftReviewSubject($companyName),
                    'email_draft' => self::buildDraftReviewDraft($lead, $config),
                    'email_final' => '',
                ];

            case 'polonads_published_v1':
                return [
                    'mail_template_id' => 'polonads_published_v1',
                    'email_subject' => self::buildPublishedSubject($companyName),
                    'email_draft' => self::buildPublishedDraft($lead, $config),
                    'email_final' => '',
                ];

            case 'polonads_self_publish_v1':
                return [
                    'mail_template_id' => 'polonads_self_publish_v1',
                    'email_subject' => self::buildSelfPublishSubject($companyName),
                    'email_draft' => self::buildSelfPublishDraft($lead, $config),
                    'email_final' => '',
                ];

            case 'polonads_unsubscribe_confirm_v1':
                return [
                    'mail_template_id' => 'polonads_unsubscribe_confirm_v1',
                    'email_subject' => self::buildUnsubscribeSubject(),
                    'email_draft' => self::buildUnsubscribeDraft(),
                    'email_final' => '',
                ];
        }

        return [
            'mail_template_id' => '',
            'email_subject' => '',
            'email_draft' => '',
            'email_final' => '',
        ];
    }

    /**
     * @return array{subject: string, text: string, html: string}
     */
    public static function buildDeliveryPayload(array $lead, array $config = []): array
    {
        $subject = trim((string) ($lead['email_subject'] ?? ''));
        $text = trim((string) ($lead['email_final'] ?? ''));
        if ($text === '') {
            $text = trim((string) ($lead['email_draft'] ?? ''));
        }

        return [
            'subject' => $subject,
            'text' => $text,
            'html' => self::renderHtmlEmail($lead, $config, $subject, $text),
        ];
    }

    private static function buildLocation(string $city, string $state): string
    {
        $parts = array_filter([$city, $state], static fn (string $value): bool => $value !== '');
        return implode(', ', $parts);
    }

    private static function buildBusinessLabel(string $companyName, string $category, string $location): string
    {
        if ($companyName !== '') {
            return $companyName;
        }

        $parts = [];
        if ($category !== '') {
            $parts[] = strtolower($category);
        }
        if ($location !== '') {
            $parts[] = 'in ' . $location;
        }

        return $parts === [] ? 'your business' : implode(' ', $parts);
    }

    private static function buildIntroSubject(string $companyName, string $category): string
    {
        if ($companyName !== '') {
            return sprintf('Quick question about %s', $companyName);
        }

        if ($category !== '') {
            return sprintf('Quick question about your %s business', strtolower($category));
        }

        return 'Quick question about your business';
    }

    private static function buildIntroDraft(string $businessLabel, string $callToActions): string
    {
        return implode("\n\n", [
            sprintf('Hi, I came across %s and wanted to reach out.', $businessLabel),
            'We can prepare a short listing for your business on Polonads.com and send the draft to you first.',
            'If you like it, we can publish it for a free 2-month trial, and your Polonads.com account will start with 50 welcome points. Nothing goes live without your OK.',
            'Choose one option below:',
            $callToActions,
            '*Polonads.com reaches the Polish community in the U.S. and Canada - a market of over 9 million people of Polish origin.*',
            self::buildSignature(),
        ]);
    }

    private static function buildFollowupSubject(string $companyName): string
    {
        if ($companyName !== '') {
            return sprintf('Following up on %s', $companyName);
        }

        return 'Following up on my last email';
    }

    private static function buildFollowupDraft(string $businessLabel): string
    {
        return implode("\n\n", [
            sprintf('Hi, I wanted to follow up on my note about %s.', $businessLabel),
            'If it helps, we can prepare a draft listing for you and send it over for review.',
            'If you like it, we can run it on Polonads.com as a free 2-month trial, and your account will start with 50 welcome points.',
            'If you want me to put together a draft, just let me know.',
            self::buildSignature(),
        ]);
    }

    private static function buildInterestReplySubject(string $companyName): string
    {
        if ($companyName !== '') {
            return sprintf('Your Polonads.com account is ready for %s', $companyName);
        }

        return 'Your Polonads.com account is ready';
    }

    private static function buildInterestReplyDraft(array $lead, string $businessLabel, array $config): string
    {
        $loginUrl = self::resolvePortalLoginUrl($config);
        $setupUrl = self::resolvePortalResetUrl($config);
        $username = self::resolvePortalUsername($lead);
        $temporaryPassword = self::resolvePortalPassword($lead);

        $lines = [
            sprintf('Hi, thanks for getting back to me about %s.', $businessLabel),
            'I already prepared access for your Polonads.com account so we can move faster with your listing.',
        ];

        if ($username !== '') {
            $lines[] = 'Your current Polonads.com login: ' . $username;
        }

        if ($temporaryPassword !== '') {
            $lines[] = 'Temporary password: ' . $temporaryPassword;
            $lines[] = 'For security, please log in once and change this password in your account settings.';
        } elseif ($setupUrl !== '') {
            $lines[] = 'If you do not have a password yet or your old password does not work, set a new one here:';
            $lines[] = $setupUrl;
        }

        if ($loginUrl !== '') {
            $lines[] = 'Log in to Polonads.com:';
            $lines[] = $loginUrl;
        }

        if ($temporaryPassword === '' && $setupUrl !== '') {
            $lines[] = 'Set or reset password:';
            $lines[] = $setupUrl;
        }

        $lines[] = 'The next step is simple: we will prepare a draft listing and send it over for your review.';
        $lines[] = 'We usually start with an English version, and if you want, we can also prepare a Polish version or a bilingual version.';
        $lines[] = 'Once you approve the draft, we can publish it on Polonads.com for the free 2-month trial.';
        $lines[] = self::buildSignature();

        return implode("\n\n", $lines);
    }

    private static function buildDraftReviewSubject(string $companyName): string
    {
        if ($companyName !== '') {
            return sprintf('Draft listing for %s', $companyName);
        }

        return 'Your draft listing';
    }

    private static function buildDraftReviewDraft(array $lead, array $config): string
    {
        $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
        $reviewActions = self::buildDraftReviewActions($lead, $config);
        $body = trim((string) ($meta['listing_body'] ?? ''));

        if ($body === '') {
            $body = 'Draft listing content will appear here once the AI module or operator prepares the listing.';
        }

        return implode("\n\n", [
            'Hi,',
            'I put together a draft listing for your business.',
            'Take a look below. If you would like any edits, you can review and manage the listing before it goes live.',
            'Draft preview:',
            $body,
            'When you are ready, choose one of the options below:',
            $reviewActions,
            self::buildSignature(),
        ]);
    }

    private static function buildPublishedSubject(string $companyName): string
    {
        if ($companyName !== '') {
            return sprintf('Your listing for %s is now live', $companyName);
        }

        return 'Your listing is now live';
    }

    private static function buildPublishedDraft(array $lead, array $config): string
    {
        $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
        $listingUrl = trim((string) ($meta['listing_url'] ?? ''));
        $loginUrl = self::resolvePortalLoginUrl($config);
        $setupUrl = self::resolvePortalResetUrl($config);

        $lines = [
            'Hi,',
            'Your listing is now live on Polonads.com.',
        ];

        if ($listingUrl !== '') {
            $lines[] = 'Listing link:';
            $lines[] = $listingUrl;
        }

        if ($loginUrl !== '') {
            $lines[] = 'You can manage your listing here:';
            $lines[] = $loginUrl;
        }

        if ($setupUrl !== '') {
            $lines[] = 'If you need to set your password or activate access, use this link:';
            $lines[] = $setupUrl;
        }

        $lines[] = 'You can update the listing, add content, and manage everything from your Polonads.com panel.';
        $lines[] = self::buildSignature();

        return implode("\n\n", $lines);
    }

    private static function buildSelfPublishSubject(string $companyName): string
    {
        if ($companyName !== '') {
            return sprintf('Your Polonads.com access is ready for %s', $companyName);
        }

        return 'Your Polonads.com access is ready';
    }

    private static function buildSelfPublishDraft(array $lead, array $config): string
    {
        $selfPublishUrl = trim((string) ($config['app']['self_publish_url'] ?? ''));
        $loginUrl = self::resolvePortalLoginUrl($config);
        $setupUrl = self::resolvePortalResetUrl($config);
        $username = self::resolvePortalUsername($lead);
        $temporaryPassword = self::resolvePortalPassword($lead);

        $lines = [
            'Hi,',
            'Your Polonads.com access is ready, so you can create or manage your listing yourself in the Polonads panel.',
        ];

        if ($username !== '') {
            $lines[] = 'Your current Polonads.com login: ' . $username;
        }

        if ($temporaryPassword !== '') {
            $lines[] = 'Temporary password: ' . $temporaryPassword;
            $lines[] = 'If this is your first login, use the temporary password above. After signing in, please change it in your account settings.';
        } elseif ($setupUrl !== '') {
            $lines[] = 'If the account does not yet have a password, or you do not know it, set a new password here:';
            $lines[] = $setupUrl;
        }

        if ($selfPublishUrl !== '') {
            $lines[] = 'You can create or edit your listing here:';
            $lines[] = $selfPublishUrl;
        }

        if ($loginUrl !== '') {
            $lines[] = 'Login page:';
            $lines[] = $loginUrl;
        }

        if ($temporaryPassword === '' && $setupUrl !== '') {
            $lines[] = 'If you need to set your password or reset access, use this link:';
            $lines[] = $setupUrl;
        }

        $lines[] = 'From there, you can update the content, add photos, and manage your listing anytime.';
        $lines[] = self::buildSignature();

        return implode("\n\n", $lines);
    }

    private static function buildUnsubscribeSubject(): string
    {
        return 'You have been removed from our mailing list';
    }

    private static function buildUnsubscribeDraft(): string
    {
        return implode("\n\n", [
            'Hi,',
            'Thanks for letting us know, and sorry for the interruption.',
            'Your address has been removed from our outreach list, and you will not receive similar emails from us again.',
            self::buildSignature(),
        ]);
    }

    private static function buildCallToActions(array $config, int $leadId): string
    {
        if ($leadId <= 0 || (string) ($config['app']['response_secret'] ?? '') === '') {
            return implode("\n", [
                '1. Yes, please send me a draft.',
                '2. I would rather publish it myself.',
                '3. Please follow up later.',
                '4. I am not interested.',
            ]);
        }

        return implode("\n", [
            '1. Yes, please send me a draft:',
            Support::buildResponseUrl($config, $leadId, 'request_draft'),
            '',
            '2. I would rather publish it myself:',
            Support::buildResponseUrl($config, $leadId, 'self_publish'),
            '',
            '3. Please follow up later:',
            Support::buildResponseUrl($config, $leadId, 'contact_later'),
            '',
            '4. I am not interested:',
            Support::buildResponseUrl($config, $leadId, 'unsubscribe'),
            '',
            'If a link does not open directly, copy it into your browser.',
        ]);
    }

    private static function buildDraftReviewActions(array $lead, array $config): string
    {
        $leadId = (int) ($lead['id'] ?? 0);
        $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
        $editListingUrl = self::resolveDraftReviewListingUrl($meta);

        if ($leadId <= 0 || (string) ($config['app']['response_secret'] ?? '') === '') {
            return implode("\n", [
                '1. Accept and publish.',
                '2. Review and edit listing.',
                '3. Publish it in Polish too.',
            ]);
        }

        return implode("\n", [
            '1. Accept and publish:',
            Support::buildReviewUrl($config, $leadId, 'approve'),
            '',
            '2. Review and edit listing:',
            $editListingUrl !== '' ? $editListingUrl : Support::buildReviewUrl($config, $leadId, 'open'),
            '',
            '3. Publish it in Polish too:',
            Support::buildReviewUrl($config, $leadId, 'approve_polish'),
        ]);
    }

    private static function renderHtmlEmail(array $lead, array $config, string $subject, string $textBody): string
    {
        $companyName = trim((string) ($lead['company_name'] ?? 'Your business'));
        $mailTemplateId = self::resolveMailTemplateId($lead);
        $buttons = self::buttonsForLead($lead, $config, $textBody);
        $hasButtons = $buttons !== [];
        $metaRows = self::metaRowsForLead($lead, $config);
        $draftPreviewHtml = self::renderDraftPreviewHtml($lead);
        $trackingPixelHtml = self::renderTrackingPixel($lead, $config);

        $introHtml = '';
        if ($metaRows !== []) {
            $rows = [];
            foreach ($metaRows as $label => $value) {
                $rows[] = sprintf(
                    '<tr><td style="padding:6px 12px 6px 0;color:%s;font-weight:700;vertical-align:top;">%s</td><td style="padding:6px 0;color:%s;">%s</td></tr>',
                    self::COLOR_MUTED,
                    self::escapeHtml($label),
                    self::COLOR_TEXT,
                    self::escapeHtml($value)
                );
            }
            $introHtml = '<table role="presentation" width="100%" style="margin:0 0 18px;border-collapse:collapse;">' . implode('', $rows) . '</table>';
        }

        $paragraphs = self::paragraphsFromText($textBody);
        if ($hasButtons) {
            $paragraphs = self::filterHtmlParagraphs($paragraphs);
        }
        $draftPreviewPromptHtml = '';
        $draftMeta = $mailTemplateId === 'polonads_draft_review_v1'
            ? LeadMeta::decode((string) ($lead['personalization_data'] ?? ''))
            : [];
        $draftBody = $mailTemplateId === 'polonads_draft_review_v1'
            ? trim((string) ($draftMeta['listing_body'] ?? ''))
            : '';
        $buttonMap = [];
        foreach ($buttons as $index => $button) {
            $buttonMap[$button['label']] = self::renderButtonRow($button, $index === 0);
        }

        $paragraphHtml = [];
        foreach ($paragraphs as $paragraph) {
            $normalizedParagraph = strtolower(trim(rtrim($paragraph, ':')));
            if ($mailTemplateId === 'polonads_draft_review_v1') {
                if ($normalizedParagraph === strtolower('Draft preview')) {
                    continue;
                }

                if (str_starts_with($normalizedParagraph, strtolower('Title: '))) {
                    continue;
                }

                if ($draftBody !== '' && trim($paragraph) === $draftBody) {
                    continue;
                }

                if ($normalizedParagraph === strtolower('When you are ready, choose one of the options below')) {
                    $draftPreviewPromptHtml = sprintf(
                        '<p style="margin:0 0 16px;font-size:16px;line-height:1.68;color:%s;">%s</p>',
                        self::COLOR_TEXT,
                        self::paragraphToHtml($paragraph)
                    );
                    continue;
                }
            }

            $paragraphHtml[] = sprintf(
                '<p style="margin:0 0 15px;font-size:16px;line-height:1.68;color:%s;">%s</p>',
                self::COLOR_TEXT,
                self::paragraphToHtml($paragraph)
            );

            if ($mailTemplateId === 'polonads_interest_reply_v1') {
                if ($normalizedParagraph === strtolower('Log in to Polonads.com') && isset($buttonMap['Log in to Polonads.com'])) {
                    $paragraphHtml[] = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 18px;border-collapse:collapse;">' . $buttonMap['Log in to Polonads.com'] . '</table>';
                    unset($buttonMap['Log in to Polonads.com']);
                    continue;
                }

                if ($normalizedParagraph === strtolower('Set or reset password') && isset($buttonMap['Set or reset password'])) {
                    $paragraphHtml[] = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 18px;border-collapse:collapse;">' . $buttonMap['Set or reset password'] . '</table>';
                    unset($buttonMap['Set or reset password']);
                    continue;
                }
            }
        }

        $buttonHtml = '';
        if ($buttonMap !== []) {
            $buttonHtml = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:28px 0 12px;border-collapse:collapse;">' . implode('', array_values($buttonMap)) . '</table>';
        }

        return '<!doctype html>
<html lang="en">
<body style="margin:0;padding:0;background:' . self::COLOR_BG . ';font-family:Arial,Helvetica,sans-serif;color:' . self::COLOR_TEXT . ';">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . self::COLOR_BG . ';padding:24px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;background:' . self::COLOR_CARD . ';border:1px solid ' . self::COLOR_BORDER . ';border-radius:24px;overflow:hidden;box-shadow:0 18px 36px rgba(31,36,48,0.06);">
          <tr>
            <td style="padding:20px 28px 16px;background:#fffaf4;border-bottom:1px solid ' . self::COLOR_BORDER . ';border-top:4px solid ' . self::COLOR_PRIMARY . ';">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td valign="top" style="padding-right:14px;width:58px;">
                    <img src="' . self::POLONADS_ICON_URL . '" alt="Polonads.com" width="48" style="display:block;border:0;max-width:48px;height:auto;">
                  </td>
                  <td valign="middle">
                    <p style="margin:0 0 5px;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:' . self::COLOR_PRIMARY . ';font-weight:700;">Polonads.com outreach</p>
                    <h1 style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:23px;line-height:1.22;color:' . self::COLOR_TEXT . ';font-weight:700;">' . self::escapeHtml($subject !== '' ? $subject : $companyName) . '</h1>
                    <p style="margin:8px 0 0;font-size:14px;line-height:1.55;color:' . self::COLOR_MUTED . ';">Built for the Polish community in the USA and Canada.</p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:28px;">
              ' . $introHtml . '
              ' . implode('', $paragraphHtml) . '
              ' . $draftPreviewHtml . '
              ' . $draftPreviewPromptHtml . '
              ' . $buttonHtml . '
              <hr style="border:0;border-top:1px solid ' . self::COLOR_BORDER . ';margin:24px 0 18px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td valign="middle">
                    <p style="margin:0 0 6px;font-size:15px;line-height:1.55;color:' . self::COLOR_TEXT . ';font-weight:700;">Best regards,<br>Alex</p>
                    <p style="margin:0;font-size:13px;line-height:1.55;color:' . self::COLOR_MUTED . ';">Polonads.com<br>owned by BIGSKYDEALS LLC</p>
                  </td>
                  <td align="right" valign="middle">
                    <img src="' . self::BIGSKY_LOGO_URL . '" alt="BIGSKYDEALS LLC" width="126" style="display:block;border:0;max-width:126px;height:auto;">
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
  ' . $trackingPixelHtml . '
</body>
</html>';
    }

    private static function renderTrackingPixel(array $lead, array $config): string
    {
        $trackingEnabled = (bool) ($config['automation']['open_tracking_enabled'] ?? false);
        $leadId = (int) ($lead['id'] ?? 0);
        $mailTemplateId = self::resolveMailTemplateId($lead);

        if (!$trackingEnabled || $leadId <= 0 || $mailTemplateId === '') {
            return '';
        }

        $url = Support::buildTrackingUrl($config, $leadId, 'open', $mailTemplateId);

        return '<img src="' . self::escapeHtml($url) . '" alt="" width="1" height="1" style="display:block;width:1px;height:1px;border:0;opacity:0;">';
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private static function buttonsForLead(array $lead, array $config, string $textBody = ''): array
    {
        $mailTemplateId = self::resolveMailTemplateId($lead);
        $leadId = (int) ($lead['id'] ?? 0);
        $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
        $listingUrl = trim((string) ($meta['listing_url'] ?? ''));
        $loginUrl = self::resolvePortalLoginUrl($config);
        $setupUrl = self::resolvePortalResetUrl($config);
        $selfPublishUrl = trim((string) ($config['app']['self_publish_url'] ?? ''));

        switch ($mailTemplateId) {
            case 'polonads_intro_v1':
            case 'polonads_followup_v1':
                $extractedResponseUrls = self::extractUrlsByLabels($textBody, [
                    'Yes, please send me a draft',
                    'Send me a draft',
                    'I would rather publish it myself',
                    'I prefer to publish it myself',
                    'Please follow up later',
                    'Contact me later',
                    'I am not interested',
                    'Not interested',
                ]);
                if ($extractedResponseUrls !== []) {
                    return $extractedResponseUrls;
                }
                if ($leadId <= 0) {
                    return [];
                }
                return [
                    ['label' => 'Send me a draft', 'url' => Support::buildResponseUrl($config, $leadId, 'request_draft')],
                    ['label' => 'I prefer to publish it myself', 'url' => Support::buildResponseUrl($config, $leadId, 'self_publish')],
                    ['label' => 'Contact me later', 'url' => Support::buildResponseUrl($config, $leadId, 'contact_later')],
                    ['label' => 'Not interested', 'url' => Support::buildResponseUrl($config, $leadId, 'unsubscribe')],
                ];

            case 'polonads_interest_reply_v1':
                $extractedInterestUrls = self::extractUrlsByLabels($textBody, [
                    'Log in to Polonads.com',
                    'Set or reset password',
                ]);
                if ($extractedInterestUrls !== []) {
                    return $extractedInterestUrls;
                }
                return array_values(array_filter([
                    $loginUrl !== '' ? ['label' => 'Log in to Polonads.com', 'url' => $loginUrl] : null,
                    $setupUrl !== '' ? ['label' => 'Set or reset password', 'url' => $setupUrl] : null,
                ]));

            case 'polonads_draft_review_v1':
                $extractedDraftReviewUrls = self::extractUrlsByLabels($textBody, [
                    'Accept and publish',
                    'Review and edit listing',
                    'Publish it in Polish too',
                    'Publish also in Polish',
                ]);
                if ($extractedDraftReviewUrls !== []) {
                    return $extractedDraftReviewUrls;
                }
                if ($leadId <= 0) {
                    return [];
                }
                $editListingUrl = self::resolveDraftReviewListingUrl($meta);
                return [
                    ['label' => 'Accept and publish', 'url' => Support::buildReviewUrl($config, $leadId, 'approve')],
                    ['label' => 'Review and edit listing', 'url' => $editListingUrl !== '' ? $editListingUrl : Support::buildReviewUrl($config, $leadId, 'open')],
                    ['label' => 'Publish also in Polish', 'url' => Support::buildReviewUrl($config, $leadId, 'approve_polish')],
                ];

            case 'polonads_published_v1':
                $extractedPublishedUrls = self::extractUrlsByLabels($textBody, [
                    'View listing',
                    'Log in to manage listing',
                    'Set password',
                ]);
                if ($extractedPublishedUrls !== []) {
                    return $extractedPublishedUrls;
                }
                return array_values(array_filter([
                    $listingUrl !== '' ? ['label' => 'View listing', 'url' => $listingUrl] : null,
                    $loginUrl !== '' ? ['label' => 'Log in to manage listing', 'url' => $loginUrl] : null,
                    $setupUrl !== '' ? ['label' => 'Set password', 'url' => $setupUrl] : null,
                ]));

            case 'polonads_self_publish_v1':
                $extractedSelfPublishUrls = self::extractUrlsByLabels($textBody, [
                    'Create or edit listing',
                    'Log in',
                    'Set password',
                ]);
                if ($extractedSelfPublishUrls !== []) {
                    return $extractedSelfPublishUrls;
                }
                return array_values(array_filter([
                    $selfPublishUrl !== '' ? ['label' => 'Create or edit listing', 'url' => $selfPublishUrl] : null,
                    $loginUrl !== '' ? ['label' => 'Log in', 'url' => $loginUrl] : null,
                    $setupUrl !== '' ? ['label' => 'Set password', 'url' => $setupUrl] : null,
                ]));

            default:
                return [];
        }
    }

    /**
     * @return array<string, string>
     */
    private static function metaRowsForLead(array $lead, array $config): array
    {
        $mailTemplateId = self::resolveMailTemplateId($lead);
        $rows = [];
        $username = self::resolvePortalUsername($lead);
        $temporaryPassword = self::resolvePortalPassword($lead);

        if (in_array($mailTemplateId, ['polonads_interest_reply_v1', 'polonads_self_publish_v1'], true) && $username !== '') {
            $rows['Login'] = $username;
        }

        if (in_array($mailTemplateId, ['polonads_interest_reply_v1', 'polonads_self_publish_v1'], true) && $temporaryPassword !== '') {
            $rows['Temporary password'] = $temporaryPassword;
        }

        if ($mailTemplateId === 'polonads_interest_reply_v1') {
            $rows['First access'] = 'Use the password reset page if this is your first login.';
        }

        if ($mailTemplateId === 'polonads_self_publish_v1' && $temporaryPassword === '') {
            $rows['Password setup'] = 'Use the password reset page if you do not have an active password yet.';
        }

        return $rows;
    }

    private static function resolvePortalUsername(array $lead): string
    {
        $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
        $portalLogin = trim((string) ($meta['portal_login'] ?? ''));
        if ($portalLogin !== '') {
            return $portalLogin;
        }

        $username = trim((string) ($meta['joomla_username'] ?? ''));
        if ($username !== '') {
            return $username;
        }

        return trim((string) ($lead['primary_email'] ?? ''));
    }

    private static function resolvePortalPassword(array $lead): string
    {
        $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
        return trim((string) ($meta['portal_password_plain'] ?? ''));
    }

    private static function resolvePortalLoginUrl(array $config): string
    {
        $configured = trim((string) ($config['app']['account_login_url'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        return self::POLONADS_LOGIN_URL;
    }

    private static function resolvePortalResetUrl(array $config): string
    {
        $configured = trim((string) ($config['app']['account_setup_url'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        return self::POLONADS_RESET_URL;
    }

    /**
     * @return list<string>
     */
    private static function paragraphsFromText(string $text): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($normalized === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split("/\n{2,}/", $normalized) ?: [])));
    }

    private static function paragraphToHtml(string $paragraph): string
    {
        if (preg_match('/^\*(.+)\*$/s', trim($paragraph), $matches) === 1) {
            return sprintf(
                '<em style="color:%s;">%s</em>',
                self::COLOR_MUTED,
                self::escapeHtml(trim($matches[1]))
            );
        }

        $escaped = self::escapeHtml($paragraph);
        $escaped = preg_replace('~(https?://[^\s<]+)~', '<a href="$1" style="color:' . self::COLOR_PRIMARY . ';text-decoration:underline;">$1</a>', $escaped) ?? $escaped;
        return nl2br($escaped);
    }

    private static function renderDraftPreviewHtml(array $lead): string
    {
        if (self::resolveMailTemplateId($lead) !== 'polonads_draft_review_v1') {
            return '';
        }

        $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
        $title = trim((string) ($meta['listing_title'] ?? ''));
        $body = trim((string) ($meta['listing_body'] ?? ''));

        if ($title === '') {
            $title = trim((string) ($lead['company_name'] ?? 'Draft listing'));
        }

        if ($body === '') {
            return '';
        }

        return sprintf(
            '<div style="margin:22px 0 26px;padding:18px 20px;background:#ffffff;border:1px solid %s;border-radius:18px;box-shadow:0 8px 18px rgba(31,36,48,0.05);">
                <div style="margin:0 0 8px;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:%s;font-weight:700;">Draft preview</div>
                <div style="margin:0 0 14px;font-family:Georgia,Times New Roman,serif;font-size:24px;line-height:1.3;color:%s;">%s</div>
                <div style="font-family:Georgia,Times New Roman,serif;font-size:18px;line-height:1.75;color:%s;">%s</div>
            </div>',
            self::COLOR_BORDER,
            self::COLOR_SECONDARY,
            self::COLOR_TEXT,
            self::escapeHtml($title),
            self::COLOR_TEXT,
            nl2br(self::escapeHtml($body))
        );
    }

    private static function resolveMailTemplateId(array $lead): string
    {
        $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
        $mailTemplateId = trim((string) ($meta['mail_template_id'] ?? ''));
        if ($mailTemplateId !== '') {
            return $mailTemplateId;
        }

        return trim((string) ($lead['campaign_id'] ?? ''));
    }

    /**
     * @param array{label: string, url: string} $button
     */
    private static function renderButtonRow(array $button, bool $isPrimary): string
    {
        $background = $isPrimary ? self::COLOR_PRIMARY : self::COLOR_SECONDARY;
        $textColor = '#ffffff';

        return sprintf(
            '<tr><td style="padding:0 0 14px 0;vertical-align:top;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;">
                    <tr>
                        <td bgcolor="%s" style="border-radius:999px;background:%s;">
                            <a href="%s" style="display:inline-block;padding:14px 22px;border-radius:999px;color:%s;text-decoration:none;font-weight:700;font-size:15px;line-height:1.2;text-align:center;font-family:Arial,Helvetica,sans-serif;">%s</a>
                        </td>
                    </tr>
                </table>
            </td></tr>',
            $background,
            $background,
            self::escapeHtml($button['url']),
            $textColor,
            self::escapeHtml($button['label'])
        );
    }

    /**
     * @param list<string> $paragraphs
     * @return list<string>
     */
    private static function filterHtmlParagraphs(array $paragraphs): array
    {
        $filtered = [];
        foreach ($paragraphs as $paragraph) {
            $trimmed = trim($paragraph);
            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/^\d+\.\s/', $trimmed) === 1) {
                continue;
            }

            if (preg_match('/^https?:\/\//i', $trimmed) === 1) {
                continue;
            }

            $filtered[] = $paragraph;
        }

        return $filtered;
    }

    /**
     * @param list<string> $labels
     * @return list<array{label: string, url: string}>
     */
    private static function extractUrlsByLabels(string $textBody, array $labels): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $textBody);
        $lines = explode("\n", $normalized);
        $results = [];

        foreach ($labels as $label) {
            $index = self::findLineIndexForLabel($lines, $label);
            if ($index === null) {
                continue;
            }

            for ($cursor = $index + 1; $cursor < count($lines); $cursor++) {
                $candidate = trim($lines[$cursor]);
                if ($candidate === '') {
                    continue;
                }

                if (preg_match('~^https?://~i', $candidate) === 1) {
                    if (!self::isUsableActionUrl($candidate)) {
                        break;
                    }

                    $results[] = [
                        'label' => $label,
                        'url' => $candidate,
                    ];
                    break;
                }

                break;
            }
        }

        return $results;
    }

    /**
     * @param list<string> $lines
     */
    private static function findLineIndexForLabel(array $lines, string $label): ?int
    {
        $plainLabel = strtolower(trim($label));
        foreach ($lines as $index => $line) {
            $normalized = strtolower(trim($line));
            $normalized = preg_replace('/^\d+\.\s*/', '', $normalized) ?? $normalized;
            $normalized = rtrim($normalized, ':');
            if ($normalized === $plainLabel) {
                return $index;
            }
        }

        return null;
    }

    private static function isUsableActionUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || ($host !== 'localhost' && filter_var($host, FILTER_VALIDATE_IP) === false && !str_contains($host, '.'))) {
            return false;
        }

        $path = strtolower((string) ($parts['path'] ?? ''));
        if (!str_contains($path, 'review.php') && !str_contains($path, 'respond.php')) {
            return true;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        return trim((string) ($query['token'] ?? '')) !== '';
    }

    private static function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function resolveDraftReviewListingUrl(array $meta): string
    {
        $itemId = (int) ($meta['djcf_item_id'] ?? 0);
        if ($itemId > 0) {
            return sprintf(self::POLONADS_LISTING_URL_TEMPLATE, $itemId);
        }

        $listingUrl = trim((string) ($meta['listing_url'] ?? ''));
        if ($listingUrl !== '') {
            return $listingUrl;
        }

        return '';
    }

    private static function buildSignature(): string
    {
        return implode("\n", [
            'Best regards,',
            'Alex',
            'Polonads.com',
            'Polonads.com, owned by BIGSKYDEALS LLC',
        ]);
    }
}
