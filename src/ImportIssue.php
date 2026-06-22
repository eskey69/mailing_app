<?php

declare(strict_types=1);

namespace MailingApp;

final class ImportIssue
{
    public int $rowNumber;
    public string $companyName;
    public string $primaryEmail;
    public string $severity;
    public string $issueCode;
    public string $message;

    public function __construct(
        int $rowNumber,
        string $companyName,
        string $primaryEmail,
        string $severity,
        string $issueCode,
        string $message
    ) {
        $this->rowNumber = $rowNumber;
        $this->companyName = $companyName;
        $this->primaryEmail = $primaryEmail;
        $this->severity = $severity;
        $this->issueCode = $issueCode;
        $this->message = $message;
    }
}
