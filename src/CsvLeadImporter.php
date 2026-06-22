<?php

declare(strict_types=1);

namespace MailingApp;

use RuntimeException;

final class CsvLeadImporter
{
    private const REQUIRED_HEADERS = [
        'company_name',
        'category',
        'city',
        'state',
        'phone',
        'address',
        'website',
        'yp_url',
        'primary_email',
        'all_emails',
        'email_count',
        'email_source',
        'status',
        'ready_for_import',
        'source',
        'imported',
    ];

    private const ELIGIBLE_STATUSES = ['email_found'];
    private PolonadsCategoryMapper $polonadsCategoryMapper;
    private PolonadsRegionMapper $polonadsRegionMapper;

    public function __construct(
        ?PolonadsCategoryMapper $polonadsCategoryMapper = null,
        ?PolonadsRegionMapper $polonadsRegionMapper = null
    )
    {
        $this->polonadsCategoryMapper = $polonadsCategoryMapper ?? new PolonadsCategoryMapper();
        $this->polonadsRegionMapper = $polonadsRegionMapper ?? new PolonadsRegionMapper();
    }

    public function import(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open uploaded CSV file.');
        }

        try {
            $headers = fgetcsv($handle);
            if (!is_array($headers)) {
                throw new RuntimeException('CSV file is missing a header row.');
            }

            $headers = array_map([$this, 'normalizeHeader'], $headers);
            $missingHeaders = array_diff(self::REQUIRED_HEADERS, $headers);
            if ($missingHeaders !== []) {
                throw new RuntimeException('Missing required CSV columns: ' . implode(', ', $missingHeaders));
            }

            $rows = [];
            $issues = [];
            $rowNumber = 1;

            while (($values = fgetcsv($handle)) !== false) {
                $rowNumber++;
                $rawRow = $this->combineRow($headers, $values);
                if ($this->isEmptyRow($rawRow)) {
                    continue;
                }

                $row = $this->normalizeRow($rawRow, $rowNumber);
                foreach ($this->validateRow($row) as $issue) {
                    $issues[] = $issue;
                }
                $rows[] = $row;
            }

            return [
                'rows' => $rows,
                'issues' => $issues,
            ];
        } finally {
            fclose($handle);
        }
    }

    private function normalizeHeader(string $header): string
    {
        return trim($header);
    }

    private function combineRow(array $headers, array $values): array
    {
        $paddedValues = array_pad($values, count($headers), '');
        return array_combine($headers, $paddedValues) ?: [];
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalizeRow(array $rawRow, int $rowNumber): LeadRow
    {
        $primaryEmail = strtolower(trim((string) $rawRow['primary_email']));
        $allEmails = $this->normalizeEmailList((string) $rawRow['all_emails'], $primaryEmail);
        $polonadsCategory = $this->polonadsCategoryMapper->map(
            trim((string) $rawRow['category']),
            trim((string) $rawRow['company_name']),
            trim((string) $rawRow['website'])
        );
        $polonadsRegion = $this->polonadsRegionMapper->map(
            trim((string) $rawRow['city']),
            strtoupper(trim((string) $rawRow['state']))
        );

        return new LeadRow([
            'row_number' => $rowNumber,
            'company_name' => trim((string) $rawRow['company_name']),
            'category' => trim((string) $rawRow['category']),
            'city' => trim((string) $rawRow['city']),
            'state' => strtoupper(trim((string) $rawRow['state'])),
            'phone' => trim((string) $rawRow['phone']),
            'address' => trim((string) $rawRow['address']),
            'website' => trim((string) $rawRow['website']),
            'yp_url' => trim((string) $rawRow['yp_url']),
            'primary_email' => $primaryEmail,
            'all_emails' => $allEmails,
            'email_count' => $this->normalizeEmailCount((string) $rawRow['email_count'], $allEmails),
            'email_source' => trim((string) $rawRow['email_source']),
            'source_status' => trim((string) $rawRow['status']),
            'ready_for_import' => $this->normalizeYesNo((string) $rawRow['ready_for_import'], 'no'),
            'source_name' => trim((string) $rawRow['source']),
            'source_imported' => $this->normalizeYesNo((string) $rawRow['imported'], 'no'),
            'is_mailable' => false,
            'personalization_data' => LeadMeta::encode([
                'polonads_category' => $polonadsCategory,
                'polonads_region' => $polonadsRegion,
                'source_snapshot' => [
                    'category' => trim((string) $rawRow['category']),
                    'city' => trim((string) $rawRow['city']),
                    'state' => strtoupper(trim((string) $rawRow['state'])),
                ],
                'ai_category_review' => 'pending',
                'ai_region_review' => 'pending',
            ]),
        ]);
    }

    private function normalizeEmailList(string $allEmails, string $primaryEmail): string
    {
        $parts = $allEmails === '' ? [] : explode(';', $allEmails);
        if ($primaryEmail !== '') {
            array_unshift($parts, $primaryEmail);
        }

        $normalized = [];
        foreach ($parts as $part) {
            $email = strtolower(trim($part));
            if ($email === '' || in_array($email, $normalized, true)) {
                continue;
            }
            $normalized[] = $email;
        }

        return implode('; ', $normalized);
    }

    private function normalizeEmailCount(string $emailCount, string $allEmails): int
    {
        $parsed = filter_var($emailCount, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($parsed !== false) {
            return (int) $parsed;
        }

        if ($allEmails === '') {
            return 0;
        }

        return count(array_filter(array_map('trim', explode(';', $allEmails))));
    }

    private function normalizeYesNo(string $value, string $default): string
    {
        $normalized = strtolower(trim($value));
        return in_array($normalized, ['yes', 'no'], true) ? $normalized : $default;
    }

    /**
     * @return list<ImportIssue>
     */
    private function validateRow(LeadRow $row): array
    {
        $issues = [];
        $addIssue = function (string $severity, string $code, string $message) use (&$issues, $row): void {
            $issues[] = new ImportIssue(
                $row->rowNumber,
                $row->companyName,
                $row->primaryEmail,
                $severity,
                $code,
                $message
            );
        };

        if ($row->companyName === '') {
            $addIssue('error', 'missing_company_name', 'Company name is empty.');
        }

        if ($row->primaryEmail === '') {
            $addIssue('warning', 'missing_primary_email', 'Primary email is empty.');
        } elseif (!filter_var($row->primaryEmail, FILTER_VALIDATE_EMAIL)) {
            $addIssue('error', 'invalid_primary_email', 'Primary email is not a valid email address.');
        }

        if ($row->allEmails !== '') {
            $emails = array_filter(array_map('trim', explode(';', $row->allEmails)));
            if (!in_array($row->primaryEmail, $emails, true) && $row->primaryEmail !== '') {
                $addIssue('warning', 'primary_not_in_all_emails', 'Primary email is missing from all_emails.');
            }

            if ($row->emailCount !== count($emails)) {
                $addIssue('warning', 'email_count_mismatch', 'Email count does not match the number of emails.');
            }
        }

        if ($row->readyForImport !== 'yes') {
            $addIssue('info', 'not_ready_for_import', 'Record is not marked ready_for_import=yes.');
        }

        if ($row->sourceImported === 'yes') {
            $addIssue('info', 'already_imported', 'Record is already marked as imported.');
        }

        if (!in_array($row->sourceStatus, self::ELIGIBLE_STATUSES, true)) {
            $addIssue('info', 'status_not_mailable', sprintf('Status %s is not eligible for mailing.', $row->sourceStatus));
        }

        $meta = LeadMeta::decode($row->personalizationData);
        $polonadsCategory = is_array($meta['polonads_category'] ?? null) ? $meta['polonads_category'] : [];
        if (($polonadsCategory['requires_review'] ?? false) === true) {
            $addIssue('info', 'polonads_category_needs_review', 'Polonads category mapping needs manual review.');
        }

        $polonadsRegion = is_array($meta['polonads_region'] ?? null) ? $meta['polonads_region'] : [];
        if (($polonadsRegion['requires_review'] ?? false) === true) {
            $addIssue('info', 'polonads_region_needs_review', 'Polonads region mapping needs manual review.');
        }

        $row->isMailable = (
            $row->readyForImport === 'yes'
            && $row->sourceImported === 'no'
            && $row->primaryEmail !== ''
            && filter_var($row->primaryEmail, FILTER_VALIDATE_EMAIL)
            && in_array($row->sourceStatus, self::ELIGIBLE_STATUSES, true)
        );

        return $issues;
    }
}
