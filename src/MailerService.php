<?php

declare(strict_types=1);

namespace MailingApp;

use RuntimeException;

final class MailerService
{
    private LeadRepository $repository;
    private SmtpMailer $mailer;
    private array $config;

    public function __construct(LeadRepository $repository, SmtpMailer $mailer, array $config)
    {
        $this->repository = $repository;
        $this->mailer = $mailer;
        $this->config = $config;
    }

    public function sendApprovedBatch(?int $limit = null): array
    {
        $batchSize = $limit ?? (int) ($this->config['batch_size'] ?? 3);
        $pauseSeconds = (int) ($this->config['pause_seconds'] ?? 90);

        $leads = $this->repository->fetchApprovedLeadsForSending($batchSize);
        $results = [
            'selected' => count($leads),
            'sent' => 0,
            'failed' => 0,
            'redirected' => 0,
        ];

        foreach ($leads as $index => $lead) {
            $delivery = MailTemplateFactory::buildDeliveryPayload($lead, $this->config);
            $subject = trim((string) ($delivery['subject'] ?? ''));
            $body = trim((string) ($delivery['text'] ?? ''));
            $htmlBody = trim((string) ($delivery['html'] ?? ''));

            if ($subject === '' || $body === '') {
                $message = 'Lead is approved but missing email subject or draft content.';
                $this->repository->markLeadSendFailed((int) $lead['id'], $message, (string) ($this->config['host'] ?? ''));
                $results['failed']++;
                continue;
            }

            try {
                $deliveryResult = $this->mailer->send((string) $lead['primary_email'], $subject, $body, $htmlBody);
                $this->repository->markLeadSent(
                    (int) $lead['id'],
                    (string) ($this->config['host'] ?? ''),
                    (string) ($deliveryResult['smtp_recipient'] ?? ''),
                    (string) ($deliveryResult['delivery_mode'] ?? 'live'),
                    (string) ($deliveryResult['original_recipient'] ?? (string) $lead['primary_email'])
                );
                if ((string) ($deliveryResult['delivery_mode'] ?? 'live') === 'redirect') {
                    $results['redirected']++;
                }
                $results['sent']++;
            } catch (RuntimeException $exception) {
                $this->repository->markLeadSendFailed((int) $lead['id'], $exception->getMessage(), (string) ($this->config['host'] ?? ''));
                $results['failed']++;
            }

            if ($index < count($leads) - 1 && $pauseSeconds > 0) {
                sleep($pauseSeconds);
            }
        }

        return $results;
    }
}
