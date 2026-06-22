<?php

declare(strict_types=1);

namespace MailingApp;

final class LeadRow
{
    public int $rowNumber;
    public string $companyName;
    public string $category;
    public string $city;
    public string $state;
    public string $phone;
    public string $address;
    public string $website;
    public string $ypUrl;
    public string $primaryEmail;
    public string $allEmails;
    public int $emailCount;
    public string $emailSource;
    public string $sourceStatus;
    public string $readyForImport;
    public string $sourceName;
    public string $sourceImported;
    public bool $isMailable;
    public string $personalizationData;

    public function __construct(array $data)
    {
        $this->rowNumber = (int) $data['row_number'];
        $this->companyName = (string) $data['company_name'];
        $this->category = (string) $data['category'];
        $this->city = (string) $data['city'];
        $this->state = (string) $data['state'];
        $this->phone = (string) $data['phone'];
        $this->address = (string) $data['address'];
        $this->website = (string) $data['website'];
        $this->ypUrl = (string) $data['yp_url'];
        $this->primaryEmail = (string) $data['primary_email'];
        $this->allEmails = (string) $data['all_emails'];
        $this->emailCount = (int) $data['email_count'];
        $this->emailSource = (string) $data['email_source'];
        $this->sourceStatus = (string) $data['source_status'];
        $this->readyForImport = (string) $data['ready_for_import'];
        $this->sourceName = (string) $data['source_name'];
        $this->sourceImported = (string) $data['source_imported'];
        $this->isMailable = (bool) $data['is_mailable'];
        $this->personalizationData = (string) ($data['personalization_data'] ?? '');
    }

    public function toDatabaseRow(int $importBatchId): array
    {
        return [
            'import_batch_id' => $importBatchId,
            'row_number' => $this->rowNumber,
            'company_name' => $this->companyName,
            'category' => $this->category,
            'city' => $this->city,
            'state' => $this->state,
            'phone' => $this->phone,
            'address' => $this->address,
            'website' => $this->website,
            'yp_url' => $this->ypUrl,
            'primary_email' => $this->primaryEmail,
            'all_emails' => $this->allEmails,
            'email_count' => $this->emailCount,
            'email_source' => $this->emailSource,
            'source_status' => $this->sourceStatus,
            'ready_for_import' => $this->readyForImport,
            'source_name' => $this->sourceName,
            'source_imported' => $this->sourceImported,
            'is_mailable' => $this->isMailable ? 1 : 0,
            'personalization_data' => $this->personalizationData,
        ];
    }
}
