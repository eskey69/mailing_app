<?php

declare(strict_types=1);

namespace MailingApp;

use PDO;

final class LeadRepository
{
    private PDO $pdo;
    private array $config;

    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = $config;
    }

    /**
     * @param list<LeadRow> $rows
     * @param list<ImportIssue> $issues
     */
    public function saveImportBatch(string $originalFilename, string $storedFilename, array $rows, array $issues): int
    {
        $eligibleRows = count(array_filter($rows, static fn (LeadRow $row): bool => $row->isMailable));

        $this->pdo->beginTransaction();

        $batchStatement = $this->pdo->prepare(
            'INSERT INTO import_batches (original_filename, stored_filename, imported_rows, imported_eligible_rows, issue_count)
             VALUES (:original_filename, :stored_filename, :imported_rows, :imported_eligible_rows, :issue_count)'
        );
        $batchStatement->execute([
            'original_filename' => $originalFilename,
            'stored_filename' => $storedFilename,
            'imported_rows' => count($rows),
            'imported_eligible_rows' => $eligibleRows,
            'issue_count' => count($issues),
        ]);

        $batchId = (int) $this->pdo->lastInsertId();

        $leadStatement = $this->pdo->prepare(
            'INSERT INTO leads (
                import_batch_id, `row_number`, company_name, category, city, state, phone, address, website, yp_url,
                primary_email, all_emails, email_count, email_source, source_status, ready_for_import, source_name,
                source_imported, is_mailable, personalization_data
             ) VALUES (
                :import_batch_id, :row_number, :company_name, :category, :city, :state, :phone, :address, :website, :yp_url,
                :primary_email, :all_emails, :email_count, :email_source, :source_status, :ready_for_import, :source_name,
                :source_imported, :is_mailable, :personalization_data
             )'
        );

        foreach ($rows as $row) {
            $leadStatement->execute($row->toDatabaseRow($batchId));
        }

        $issueStatement = $this->pdo->prepare(
            'INSERT INTO lead_import_issues (
                import_batch_id, `row_number`, company_name, primary_email, severity, issue_code, message
             ) VALUES (
                :import_batch_id, :row_number, :company_name, :primary_email, :severity, :issue_code, :message
             )'
        );

        foreach ($issues as $issue) {
            $issueStatement->execute([
                'import_batch_id' => $batchId,
                'row_number' => $issue->rowNumber,
                'company_name' => $issue->companyName,
                'primary_email' => $issue->primaryEmail,
                'severity' => $issue->severity,
                'issue_code' => $issue->issueCode,
                'message' => $issue->message,
            ]);
        }

        $this->pdo->commit();

        return $batchId;
    }

    public function fetchDashboardStats(): array
    {
        $totals = $this->pdo->query(
            'SELECT
                COUNT(*) AS total_leads,
                SUM(CASE WHEN is_mailable = 1 THEN 1 ELSE 0 END) AS mailable_leads,
                SUM(CASE WHEN approval_status = "approved" THEN 1 ELSE 0 END) AS approved_leads,
                SUM(CASE WHEN contact_status = "sent" THEN 1 ELSE 0 END) AS sent_leads
             FROM leads'
        )->fetch() ?: [];

        $latestBatch = $this->pdo->query(
            'SELECT id, original_filename, imported_rows, imported_eligible_rows, issue_count, created_at
             FROM import_batches
             ORDER BY id DESC
             LIMIT 1'
        )->fetch() ?: null;

        return [
            'totals' => $totals,
            'latest_batch' => $latestBatch,
        ];
    }

    public function fetchWorkflowSummary(): array
    {
        $summary = $this->pdo->query(
            'SELECT
                SUM(CASE
                    WHEN approval_status = "pending"
                      OR contact_status = "failed"
                      OR (approval_status = "approved" AND contact_status IN ("new", "approved", "draft_ready") AND (email_subject = "" OR (email_final = "" AND email_draft = "")))
                      OR personalization_data LIKE \'%"translation_status":"requested"%\'
                      OR personalization_data LIKE \'%"translation_status":"in_progress"%\'
                      OR personalization_data LIKE \'%"translation_status":"failed"%\'
                    THEN 1 ELSE 0 END
                ) AS needs_attention,
                SUM(CASE
                    WHEN contact_status IN ("sent", "client_review", "replied")
                    THEN 1 ELSE 0 END
                ) AS waiting_for_client,
                SUM(CASE
                    WHEN contact_status IN ("published", "skipped")
                      OR personalization_data LIKE \'%"publication_status":"live"%\'
                    THEN 1 ELSE 0 END
                ) AS completed_count
             FROM leads'
        )->fetch();

        return $summary ?: [
            'needs_attention' => 0,
            'waiting_for_client' => 0,
            'completed_count' => 0,
        ];
    }

    public function fetchRecentLeads(int $limit = 25): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, company_name, city, state, primary_email, is_mailable, approval_status, contact_status, created_at
             FROM leads
             ORDER BY id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function fetchLeads(array $filters = [], int $limit = 100): array
    {
        $where = [];
        $params = [];

        if (($filters['is_mailable'] ?? '') !== '') {
            $where[] = 'is_mailable = :is_mailable';
            $params['is_mailable'] = (int) $filters['is_mailable'];
        }

        if (($filters['approval_status'] ?? '') !== '') {
            $where[] = 'approval_status = :approval_status';
            $params['approval_status'] = (string) $filters['approval_status'];
        }

        if (($filters['contact_status'] ?? '') !== '') {
            $where[] = 'contact_status = :contact_status';
            $params['contact_status'] = (string) $filters['contact_status'];
        }

        if (($filters['search'] ?? '') !== '') {
            $where[] = '(company_name LIKE :search OR primary_email LIKE :search OR city LIKE :search)';
            $params['search'] = '%' . (string) $filters['search'] . '%';
        }

        $sql = 'SELECT
                    id,
                    company_name,
                    city,
                    state,
                    primary_email,
                    email_count,
                    is_mailable,
                    approval_status,
                    contact_status,
                    campaign_id,
                    created_at
                FROM leads';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY id DESC LIMIT :limit';

        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function fetchAttentionLeads(int $limit = 12): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, company_name, primary_email, city, state, approval_status, contact_status, email_subject, email_draft, email_final, personalization_data, updated_at
             FROM leads
             WHERE approval_status = "pending"
                OR contact_status = "failed"
                OR (approval_status = "approved" AND contact_status IN ("new", "approved", "draft_ready") AND (email_subject = "" OR (email_final = "" AND email_draft = "")))
                OR personalization_data LIKE \'%"translation_status":"requested"%\'
                OR personalization_data LIKE \'%"translation_status":"in_progress"%\'
                OR personalization_data LIKE \'%"translation_status":"failed"%\'
             ORDER BY updated_at DESC, id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function fetchWaitingForClientLeads(int $limit = 12): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, company_name, primary_email, city, state, approval_status, contact_status, personalization_data, sent_at, updated_at
             FROM leads
             WHERE contact_status IN ("sent", "client_review", "replied")
             ORDER BY updated_at DESC, id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function fetchCompletedLeads(int $limit = 12): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, company_name, primary_email, city, state, approval_status, contact_status, personalization_data, updated_at
             FROM leads
             WHERE contact_status IN ("published", "skipped")
                OR personalization_data LIKE \'%"publication_status":"live"%\'
             ORDER BY updated_at DESC, id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function findLeadById(int $leadId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM leads
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $leadId]);
        $lead = $statement->fetch();

        return $lead === false ? null : $lead;
    }

    public function updateLeadWorkflow(int $leadId, array $data): void
    {
        $current = $this->findLeadById($leadId);
        if ($current === null) {
            return;
        }

        $normalizedData = $this->normalizeWorkflowData($data, $current);

        $this->pdo->beginTransaction();

        $statement = $this->pdo->prepare(
            'UPDATE leads
             SET contact_status = :contact_status,
                 approval_status = :approval_status,
                 campaign_id = :campaign_id,
                 notes = :notes,
                 email_subject = :email_subject,
                 email_draft = :email_draft,
                 email_final = :email_final
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $leadId,
            'contact_status' => $normalizedData['contact_status'],
            'approval_status' => $normalizedData['approval_status'],
            'campaign_id' => $normalizedData['campaign_id'],
            'notes' => $normalizedData['notes'],
            'email_subject' => $normalizedData['email_subject'],
            'email_draft' => $normalizedData['email_draft'],
            'email_final' => $normalizedData['email_final'],
        ]);

        $this->recordWorkflowChanges($leadId, $current, $normalizedData, 'operator');
        $this->pdo->commit();
    }

    public function updateLeadDraftReview(int $leadId, array $data, string $actor = 'client'): void
    {
        $current = $this->findLeadById($leadId);
        if ($current === null) {
            return;
        }

        $normalizedData = $this->normalizeWorkflowData($data, $current);
        $currentMeta = LeadMeta::decode((string) ($current['personalization_data'] ?? ''));
        $incomingMeta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $mergedMeta = array_replace_recursive($currentMeta, $incomingMeta);

        $this->pdo->beginTransaction();

        $statement = $this->pdo->prepare(
            'UPDATE leads
             SET contact_status = :contact_status,
                 approval_status = :approval_status,
                 campaign_id = :campaign_id,
                 notes = :notes,
                 email_subject = :email_subject,
                 email_draft = :email_draft,
                 email_final = :email_final,
                 personalization_data = :personalization_data,
                 last_contacted_at = NOW()
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $leadId,
            'contact_status' => $normalizedData['contact_status'],
            'approval_status' => $normalizedData['approval_status'],
            'campaign_id' => $normalizedData['campaign_id'],
            'notes' => $normalizedData['notes'],
            'email_subject' => $normalizedData['email_subject'],
            'email_draft' => $normalizedData['email_draft'],
            'email_final' => $normalizedData['email_final'],
            'personalization_data' => LeadMeta::encode($mergedMeta),
        ]);

        $this->recordWorkflowChanges($leadId, $current, $normalizedData, $actor);
        $this->recordMetaChanges($leadId, $currentMeta, $mergedMeta, $actor);
        $this->pdo->commit();
    }

    public function fetchWorkflowEvents(int $leadId, int $limit = 25): array
    {
        $statement = $this->pdo->prepare(
            'SELECT event_type, actor, from_value, to_value, message, created_at
             FROM lead_workflow_events
             WHERE lead_id = :lead_id
             ORDER BY id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':lead_id', $leadId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function fetchSendAttempts(int $leadId, int $limit = 20): array
    {
        $statement = $this->pdo->prepare(
            'SELECT smtp_host, recipient_email, original_recipient_email, subject_line, mail_template_id, delivery_mode, status, error_message, created_at
             FROM email_send_attempts
             WHERE lead_id = :lead_id
             ORDER BY id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':lead_id', $leadId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function fetchSendQueueSummary(): array
    {
        $summary = $this->pdo->query(
            'SELECT
                SUM(CASE
                    WHEN is_mailable = 1
                     AND approval_status = "approved"
                     AND contact_status IN ("new", "approved", "draft_ready")
                     AND email_subject <> ""
                     AND (email_final <> "" OR email_draft <> "")
                    THEN 1 ELSE 0 END
                ) AS ready_to_send,
                SUM(CASE
                    WHEN is_mailable = 1
                     AND approval_status = "approved"
                     AND contact_status IN ("new", "approved", "draft_ready")
                     AND (email_subject = "" OR (email_final = "" AND email_draft = ""))
                    THEN 1 ELSE 0 END
                ) AS blocked_missing_content,
                SUM(CASE WHEN contact_status = "sent" THEN 1 ELSE 0 END) AS sent_count,
                SUM(CASE WHEN contact_status = "failed" THEN 1 ELSE 0 END) AS failed_count
             FROM leads'
        )->fetch();

        return $summary ?: [
            'ready_to_send' => 0,
            'blocked_missing_content' => 0,
            'sent_count' => 0,
            'failed_count' => 0,
        ];
    }

    public function fetchSendQueueReady(int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, company_name, city, state, primary_email, campaign_id, approval_status, contact_status, email_subject
             FROM leads
             WHERE is_mailable = 1
               AND approval_status = "approved"
               AND contact_status IN ("new", "approved", "draft_ready")
               AND email_subject <> ""
               AND (email_final <> "" OR email_draft <> "")
             ORDER BY id ASC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function fetchSendQueueBlocked(int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, company_name, city, state, primary_email, approval_status, contact_status, email_subject, email_draft, email_final
             FROM leads
             WHERE is_mailable = 1
               AND approval_status = "approved"
               AND contact_status IN ("new", "approved", "draft_ready")
               AND (email_subject = "" OR (email_final = "" AND email_draft = ""))
             ORDER BY id ASC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function fetchIntroPreparationCandidates(int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM leads
             WHERE is_mailable = 1
               AND contact_status = "new"
               AND email_subject = ""
               AND email_draft = ""
               AND email_final = ""
             ORDER BY id ASC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function fetchRequestedDraftReviewCandidates(int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM leads
             WHERE is_mailable = 1
               AND approval_status = "pending"
               AND contact_status IN ("replied", "client_review", "draft_ready")
               AND personalization_data LIKE \'%"publication_status":"requested"%\'
               AND personalization_data LIKE \'%"listing_title"%\'
               AND personalization_data LIKE \'%"listing_body"%\'
               AND personalization_data NOT LIKE \'%"mail_template_id":"polonads_draft_review_v1"%\'
             ORDER BY id ASC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function fetchRequestedAiDraftCandidates(int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM leads
             WHERE is_mailable = 1
               AND approval_status = "pending"
               AND contact_status IN ("replied", "client_review", "draft_ready")
               AND personalization_data LIKE \'%"publication_status":"requested"%\'
               AND personalization_data NOT LIKE \'%"listing_title"%\'
               AND personalization_data NOT LIKE \'%"listing_body"%\'
             ORDER BY id ASC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function fetchRequestedTranslationCandidates(int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM leads
             WHERE is_mailable = 1
               AND approval_status = "pending"
               AND contact_status = "client_review"
               AND personalization_data LIKE \'%"translation_status":"requested"%\'
               AND personalization_data LIKE \'%"publication_status":"translation_requested"%\'
               AND personalization_data LIKE \'%"listing_title"%\'
               AND personalization_data LIKE \'%"listing_body"%\'
             ORDER BY id ASC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function fetchTranslationReviewCandidates(int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM leads
             WHERE is_mailable = 1
               AND approval_status = "pending"
               AND contact_status = "client_review"
               AND personalization_data LIKE \'%"translation_status":"ready"%\'
               AND personalization_data LIKE \'%"publication_status":"drafted"%\'
               AND personalization_data LIKE \'%"listing_title"%\'
               AND personalization_data LIKE \'%"listing_body"%\'
             ORDER BY id ASC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function fetchRecentlySent(int $limit = 50): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, company_name, primary_email, campaign_id, sent_at, send_attempts
             FROM leads
             WHERE contact_status = "sent"
             ORDER BY sent_at DESC, id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function fetchRecentlyFailed(int $limit = 50): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, company_name, primary_email, campaign_id, last_error, last_contacted_at, send_attempts
             FROM leads
             WHERE contact_status = "failed"
             ORDER BY last_contacted_at DESC, id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function fetchApprovedLeadsForSending(int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM leads
             WHERE is_mailable = 1
                AND approval_status = "approved"
                AND contact_status IN ("new", "approved", "draft_ready")
                AND email_subject <> ""
                AND (email_final <> "" OR email_draft <> "")
              ORDER BY id ASC
              LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function fetchDueFollowUpCandidates(int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM leads
             WHERE is_mailable = 1
               AND contact_status IN ("sent", "replied")
               AND next_followup_at IS NOT NULL
               AND next_followup_at <= NOW()
               AND approval_status <> "rejected"
               AND last_response_type IN ("", "contact_later")
               AND (
                    last_mail_template_id IN ("polonads_intro_v1", "polonads_followup_v1")
                    OR last_response_type = "contact_later"
               )
             ORDER BY next_followup_at ASC, id ASC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function markLeadSent(
        int $leadId,
        string $smtpHost,
        ?string $actualRecipientEmail = null,
        string $deliveryMode = 'live',
        ?string $originalRecipientEmail = null
    ): void
    {
        $lead = $this->findLeadById($leadId);
        if ($lead === null) {
            return;
        }

        $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
        $mailTemplateId = trim((string) ($meta['mail_template_id'] ?? ''));
        $isOutreachMail = $this->isOutreachMailTemplate($mailTemplateId);
        $outreachSendCount = (int) ($lead['outreach_send_count'] ?? 0);
        if ($isOutreachMail) {
            $outreachSendCount++;
        }
        $nextFollowupAt = $isOutreachMail
            ? $this->calculateNextFollowupAt($lead, (string) ($lead['last_response_type'] ?? ''), $outreachSendCount)
            : ($lead['next_followup_at'] ?? null);

        $this->pdo->beginTransaction();

        $statement = $this->pdo->prepare(
            'UPDATE leads
              SET contact_status = "sent",
                  source_imported = "yes",
                  send_attempts = send_attempts + 1,
                  outreach_send_count = :outreach_send_count,
                  last_mail_template_id = :last_mail_template_id,
                  next_followup_at = :next_followup_at,
                  sent_at = NOW(),
                  last_contacted_at = NOW(),
                  last_error = ""
              WHERE id = :id'
        );
        $statement->bindValue(':id', $leadId, PDO::PARAM_INT);
        $statement->bindValue(':outreach_send_count', $outreachSendCount, PDO::PARAM_INT);
        $statement->bindValue(':last_mail_template_id', $mailTemplateId);
        if ($nextFollowupAt === null || $nextFollowupAt === '') {
            $statement->bindValue(':next_followup_at', null, PDO::PARAM_NULL);
        } else {
            $statement->bindValue(':next_followup_at', $nextFollowupAt);
        }
        $statement->execute();

        $this->insertSendAttempt(
            $leadId,
            $smtpHost,
            $actualRecipientEmail !== null && trim($actualRecipientEmail) !== '' ? trim($actualRecipientEmail) : (string) $lead['primary_email'],
            $originalRecipientEmail !== null && trim($originalRecipientEmail) !== '' ? trim($originalRecipientEmail) : (string) $lead['primary_email'],
            (string) $lead['email_subject'],
            $mailTemplateId,
            $deliveryMode,
            'sent',
            ''
        );
        $this->insertWorkflowEvent($leadId, 'contact_status', (string) $lead['contact_status'], 'sent', 'Lead sent successfully via SMTP.', 'sender');
        $this->pdo->commit();
    }

    public function markLeadSendFailed(
        int $leadId,
        string $errorMessage,
        string $smtpHost,
        ?string $actualRecipientEmail = null,
        string $deliveryMode = 'live',
        ?string $originalRecipientEmail = null
    ): void
    {
        $lead = $this->findLeadById($leadId);
        if ($lead === null) {
            return;
        }

        $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
        $mailTemplateId = trim((string) ($meta['mail_template_id'] ?? ''));

        $this->pdo->beginTransaction();

        $statement = $this->pdo->prepare(
            'UPDATE leads
              SET contact_status = "failed",
                  last_mail_template_id = :last_mail_template_id,
                  send_attempts = send_attempts + 1,
                  last_contacted_at = NOW(),
                  last_error = :last_error
              WHERE id = :id'
        );
        $statement->execute([
            'id' => $leadId,
            'last_mail_template_id' => $mailTemplateId,
            'last_error' => $errorMessage,
        ]);

        $this->insertSendAttempt(
            $leadId,
            $smtpHost,
            $actualRecipientEmail !== null && trim($actualRecipientEmail) !== '' ? trim($actualRecipientEmail) : (string) $lead['primary_email'],
            $originalRecipientEmail !== null && trim($originalRecipientEmail) !== '' ? trim($originalRecipientEmail) : (string) $lead['primary_email'],
            (string) $lead['email_subject'],
            $mailTemplateId,
            $deliveryMode,
            'failed',
            $errorMessage
        );
        $this->insertWorkflowEvent($leadId, 'contact_status', (string) $lead['contact_status'], 'failed', $errorMessage, 'sender');
        $this->pdo->commit();
    }

    public function registerLeadResponse(int $leadId, string $responseType): void
    {
        $lead = $this->findLeadById($leadId);
        if ($lead === null) {
            return;
        }

        $responseMap = [
            'request_draft' => [
                'is_mailable' => 1,
                'approval_status' => 'pending',
                'contact_status' => 'replied',
                'message' => 'Recipient requested a draft for the free listing.',
                'note' => 'Recipient selected: please send a free listing draft.',
                'meta' => [
                    'response_type' => 'request_draft',
                    'publication_status' => 'requested',
                    'account_status' => 'not_created',
                ],
            ],
            'self_publish' => [
                'is_mailable' => 1,
                'approval_status' => 'pending',
                'contact_status' => 'replied',
                'message' => 'Recipient chose to publish the listing manually.',
                'note' => 'Recipient selected: self-publish listing.',
                'meta' => [
                    'response_type' => 'self_publish',
                    'publication_status' => 'self_publish_requested',
                    'account_status' => 'not_created',
                ],
            ],
            'contact_later' => [
                'is_mailable' => 1,
                'approval_status' => 'pending',
                'contact_status' => 'replied',
                'message' => 'Recipient asked to be contacted later.',
                'note' => 'Recipient selected: contact later.',
                'meta' => [
                    'response_type' => 'contact_later',
                    'publication_status' => 'deferred',
                    'account_status' => 'not_created',
                ],
            ],
            'unsubscribe' => [
                'is_mailable' => 0,
                'approval_status' => 'rejected',
                'contact_status' => 'skipped',
                'message' => 'Recipient opted out and should be removed from future outreach.',
                'note' => 'Recipient selected: unsubscribe / delete from mailing base.',
                'meta' => [
                    'response_type' => 'unsubscribe',
                    'account_status' => 'not_created',
                ],
            ],
        ];

        if (!isset($responseMap[$responseType])) {
            return;
        }

        $target = $responseMap[$responseType];
        $note = $this->appendSystemNote((string) ($lead['notes'] ?? ''), $target['note']);
        $currentMeta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
        $mergedMeta = array_replace_recursive($currentMeta, $target['meta']);
        $nextFollowupAt = $responseType === 'contact_later'
            ? $this->buildFutureTimestamp($this->automationInt('contact_later_followup_delay_days', 14))
            : null;

        $this->pdo->beginTransaction();

        $statement = $this->pdo->prepare(
            'UPDATE leads
             SET is_mailable = :is_mailable,
                  approval_status = :approval_status,
                  contact_status = :contact_status,
                  last_response_type = :last_response_type,
                  next_followup_at = :next_followup_at,
                  notes = :notes,
                  personalization_data = :personalization_data,
                  last_contacted_at = NOW()
             WHERE id = :id'
        );
        $statement->bindValue(':id', $leadId, PDO::PARAM_INT);
        $statement->bindValue(':is_mailable', $target['is_mailable'], PDO::PARAM_INT);
        $statement->bindValue(':approval_status', $target['approval_status']);
        $statement->bindValue(':contact_status', $target['contact_status']);
        $statement->bindValue(':last_response_type', $responseType);
        if ($nextFollowupAt === null) {
            $statement->bindValue(':next_followup_at', null, PDO::PARAM_NULL);
        } else {
            $statement->bindValue(':next_followup_at', $nextFollowupAt);
        }
        $statement->bindValue(':notes', $note);
        $statement->bindValue(':personalization_data', LeadMeta::encode($mergedMeta));
        $statement->execute();

        if ((string) $lead['contact_status'] !== (string) $target['contact_status']) {
            $this->insertWorkflowEvent(
                $leadId,
                'contact_status',
                (string) $lead['contact_status'],
                (string) $target['contact_status'],
                (string) $target['message'],
                'recipient'
            );
        }

        if ((string) $lead['approval_status'] !== (string) $target['approval_status']) {
            $this->insertWorkflowEvent(
                $leadId,
                'approval_status',
                (string) $lead['approval_status'],
                (string) $target['approval_status'],
                (string) $target['message'],
                'recipient'
            );
        }

        if ((int) $lead['is_mailable'] !== (int) $target['is_mailable']) {
            $this->insertWorkflowEvent(
                $leadId,
                'is_mailable',
                (string) $lead['is_mailable'],
                (string) $target['is_mailable'],
                (string) $target['message'],
                'recipient'
            );
        }

        $this->insertWorkflowEvent(
            $leadId,
            'recipient_response',
            '',
            $responseType,
            (string) $target['message'],
            'recipient'
        );

        $this->recordMetaChanges($leadId, $currentMeta, $mergedMeta, 'recipient');

        $this->pdo->commit();
    }

    public function registerEmailOpen(int $leadId, string $mailTemplateId = ''): void
    {
        $lead = $this->findLeadById($leadId);
        if ($lead === null) {
            return;
        }

        $isFirstOpen = (int) ($lead['open_count'] ?? 0) === 0;
        $nextFollowupAt = $lead['next_followup_at'] ?? null;
        if ($this->shouldFastTrackOpenedLead($lead)) {
            $candidate = $this->buildFutureTimestamp($this->automationInt('open_followup_delay_days', 3));
            if ($nextFollowupAt === null || $nextFollowupAt === '' || strtotime((string) $candidate) < strtotime((string) $nextFollowupAt)) {
                $nextFollowupAt = $candidate;
            }
        }

        $this->pdo->beginTransaction();

        $statement = $this->pdo->prepare(
            'UPDATE leads
             SET open_count = open_count + 1,
                 last_opened_at = NOW(),
                 next_followup_at = :next_followup_at
             WHERE id = :id'
        );
        $statement->bindValue(':id', $leadId, PDO::PARAM_INT);
        if ($nextFollowupAt === null || $nextFollowupAt === '') {
            $statement->bindValue(':next_followup_at', null, PDO::PARAM_NULL);
        } else {
            $statement->bindValue(':next_followup_at', $nextFollowupAt);
        }
        $statement->execute();

        $this->logTrackingEvent($leadId, 'open', $mailTemplateId, []);
        if ($isFirstOpen) {
            $this->insertWorkflowEvent($leadId, 'email_open', '', $mailTemplateId, 'Email opened via tracking pixel.', 'recipient');
        }

        $this->pdo->commit();
    }

    public function registerEmailClick(int $leadId, string $mailTemplateId = ''): void
    {
        $lead = $this->findLeadById($leadId);
        if ($lead === null) {
            return;
        }

        $isFirstClick = (int) ($lead['click_count'] ?? 0) === 0;

        $this->pdo->beginTransaction();

        $statement = $this->pdo->prepare(
            'UPDATE leads
             SET click_count = click_count + 1,
                 last_clicked_at = NOW()
             WHERE id = :id'
        );
        $statement->execute(['id' => $leadId]);

        $this->logTrackingEvent($leadId, 'click', $mailTemplateId, []);
        if ($isFirstClick) {
            $this->insertWorkflowEvent($leadId, 'email_click', '', $mailTemplateId, 'Email link clicked.', 'recipient');
        }

        $this->pdo->commit();
    }

    public function resetLeadForFreshOutreach(int $leadId): void
    {
        $lead = $this->findLeadById($leadId);
        if ($lead === null) {
            return;
        }

        $currentMeta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
        $preservedMeta = [];
        foreach (['polonads_category', 'polonads_region'] as $key) {
            if (array_key_exists($key, $currentMeta)) {
                $preservedMeta[$key] = $currentMeta[$key];
            }
        }

        $notes = $this->appendSystemNote(
            (string) ($lead['notes'] ?? ''),
            'Lead reset for a fresh test outreach.'
        );

        $this->pdo->beginTransaction();

        $statement = $this->pdo->prepare(
            'UPDATE leads
             SET contact_status = "new",
                  approval_status = "pending",
                  personalization_data = :personalization_data,
                  email_subject = "",
                  email_draft = "",
                  email_final = "",
                  sent_at = NULL,
                  send_attempts = 0,
                  outreach_send_count = 0,
                  last_mail_template_id = "",
                  last_response_type = "",
                  open_count = 0,
                  click_count = 0,
                  last_opened_at = NULL,
                  last_clicked_at = NULL,
                  next_followup_at = NULL,
                  last_error = "",
                  last_contacted_at = NULL,
                  source_imported = "no",
                  notes = :notes
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $leadId,
            'personalization_data' => LeadMeta::encode($preservedMeta),
            'notes' => $notes,
        ]);

        $this->insertWorkflowEvent(
            $leadId,
            'contact_status',
            (string) ($lead['contact_status'] ?? ''),
            'new',
            'Lead reset for a fresh test outreach.',
            'operator'
        );
        $this->insertWorkflowEvent(
            $leadId,
            'approval_status',
            (string) ($lead['approval_status'] ?? ''),
            'pending',
            'Lead reset for a fresh test outreach.',
            'operator'
        );
        $this->recordMetaChanges($leadId, $currentMeta, $preservedMeta, 'operator');

        $this->pdo->commit();
    }

    public function markLeadPublicationApproved(int $leadId, string $languageMode = 'en'): void
    {
        $lead = $this->findLeadById($leadId);
        if ($lead === null) {
            return;
        }

        $currentMeta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
        $approvedAt = date('Y-m-d H:i:s');
        $mergedMeta = array_replace_recursive($currentMeta, [
            'publication_status' => 'approved',
            'draft_language' => $languageMode,
            'account_status' => 'pending_publication',
            'publication_approved_at' => $approvedAt,
        ]);

        $this->pdo->beginTransaction();

        $statement = $this->pdo->prepare(
            'UPDATE leads
             SET contact_status = "approved",
                 approval_status = "approved",
                 personalization_data = :personalization_data,
                 last_contacted_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $leadId,
            'personalization_data' => LeadMeta::encode($mergedMeta),
        ]);

        if ((string) $lead['contact_status'] !== 'approved') {
            $this->insertWorkflowEvent(
                $leadId,
                'contact_status',
                (string) $lead['contact_status'],
                'approved',
                'Recipient approved the draft listing for publication.',
                'recipient'
            );
        }

        if ((string) $lead['approval_status'] !== 'approved') {
            $this->insertWorkflowEvent(
                $leadId,
                'approval_status',
                (string) $lead['approval_status'],
                'approved',
                'Recipient approved the draft listing for publication.',
                'recipient'
            );
        }

        $this->recordMetaChanges($leadId, $currentMeta, $mergedMeta, 'recipient');
        $this->pdo->commit();
    }

    public function requestLeadPolishTranslation(int $leadId): void
    {
        $lead = $this->findLeadById($leadId);
        if ($lead === null) {
            return;
        }

        $currentMeta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
        $requestedAt = date('Y-m-d H:i:s');
        $originalTitle = trim((string) ($currentMeta['listing_title'] ?? ''));
        $originalBody = trim((string) ($currentMeta['listing_body'] ?? ''));
        $originalLanguage = trim((string) ($currentMeta['listing_language'] ?? 'en'));
        $mergedMeta = array_replace_recursive($currentMeta, [
            'publication_status' => 'translation_requested',
            'draft_language' => 'en+pl',
            'account_status' => 'translation_requested',
            'client_requested_polish' => true,
            'client_intent' => 'approve_polish',
            'translation_status' => 'requested',
            'translation_requested_at' => $requestedAt,
            'translation_source_title' => $originalTitle,
            'translation_source_body' => $originalBody,
            'translation_source_language' => $originalLanguage !== '' ? $originalLanguage : 'en',
        ]);

        $this->pdo->beginTransaction();

        $statement = $this->pdo->prepare(
            'UPDATE leads
             SET contact_status = "client_review",
                 approval_status = "pending",
                 personalization_data = :personalization_data,
                 last_contacted_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $leadId,
            'personalization_data' => LeadMeta::encode($mergedMeta),
        ]);

        if ((string) $lead['contact_status'] !== 'client_review') {
            $this->insertWorkflowEvent(
                $leadId,
                'contact_status',
                (string) $lead['contact_status'],
                'client_review',
                'Recipient requested a Polish translation before final approval.',
                'recipient'
            );
        }

        if ((string) $lead['approval_status'] !== 'pending') {
            $this->insertWorkflowEvent(
                $leadId,
                'approval_status',
                (string) $lead['approval_status'],
                'pending',
                'Recipient requested a Polish translation before final approval.',
                'recipient'
            );
        }

        $this->recordMetaChanges($leadId, $currentMeta, $mergedMeta, 'recipient');
        $this->pdo->commit();
    }

    public function markLeadPublished(int $leadId, string $listingUrl = '', string $languageMode = 'en'): void
    {
        $lead = $this->findLeadById($leadId);
        if ($lead === null) {
            return;
        }

        $currentMeta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
        $mergedMeta = array_replace_recursive($currentMeta, [
            'publication_status' => 'live',
            'draft_language' => $languageMode,
            'listing_url' => $listingUrl,
            'account_status' => 'active',
        ]);

        $this->pdo->beginTransaction();

        $statement = $this->pdo->prepare(
            'UPDATE leads
             SET contact_status = "published",
                 approval_status = "approved",
                 personalization_data = :personalization_data,
                 last_contacted_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $leadId,
            'personalization_data' => LeadMeta::encode($mergedMeta),
        ]);

        $this->insertWorkflowEvent(
            $leadId,
            'contact_status',
            (string) $lead['contact_status'],
            'published',
            'Listing approved and published for the client.',
            'system'
        );
        $this->recordMetaChanges($leadId, $currentMeta, $mergedMeta, 'system');
        $this->pdo->commit();
    }

    private function recordWorkflowChanges(int $leadId, array $current, array $data, string $actor): void
    {
        $trackedFields = [
            'contact_status' => 'Contact status updated.',
            'approval_status' => 'Approval status updated.',
            'campaign_id' => 'Campaign updated.',
            'email_subject' => 'Email subject updated.',
            'email_draft' => 'Email draft updated.',
            'email_final' => 'Final email updated.',
            'notes' => 'Internal notes updated.',
        ];

        foreach ($trackedFields as $field => $message) {
            $fromValue = (string) ($current[$field] ?? '');
            $toValue = (string) ($data[$field] ?? '');
            if ($fromValue === $toValue) {
                continue;
            }

            $this->insertWorkflowEvent($leadId, $field, $fromValue, $toValue, $message, $actor);
        }
    }

    private function recordMetaChanges(int $leadId, array $currentMeta, array $newMeta, string $actor): void
    {
        $trackedKeys = [
            'response_type' => 'Recipient path updated.',
            'account_status' => 'Account status updated.',
            'publication_status' => 'Publication status updated.',
            'draft_language' => 'Draft language updated.',
            'client_requested_polish' => 'Polish translation preference updated.',
            'translation_status' => 'Translation status updated.',
            'translation_requested_at' => 'Translation request time updated.',
            'translation_source' => 'Translation source updated.',
            'translation_source_title' => 'Translation source title updated.',
            'translation_source_body' => 'Translation source body updated.',
            'translation_source_language' => 'Translation source language updated.',
            'listing_url' => 'Listing URL updated.',
            'listing_title' => 'Listing title updated.',
            'listing_body' => 'Listing body updated.',
            'listing_language' => 'Listing language updated.',
            'ai_draft_status' => 'AI draft status updated.',
            'ai_generated_at' => 'AI draft generation time updated.',
            'ai_provider' => 'AI draft source updated.',
            'listing_payload_version' => 'Listing payload version updated.',
            'client_intent' => 'Client intent updated.',
        ];

        foreach ($trackedKeys as $key => $message) {
            $fromValue = (string) ($currentMeta[$key] ?? '');
            $toValue = (string) ($newMeta[$key] ?? '');
            if ($fromValue === $toValue) {
                continue;
            }

            $this->insertWorkflowEvent($leadId, $key, $fromValue, $toValue, $message, $actor);
        }
    }

    private function insertWorkflowEvent(
        int $leadId,
        string $eventType,
        string $fromValue,
        string $toValue,
        string $message,
        string $actor
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO lead_workflow_events (lead_id, event_type, actor, from_value, to_value, message)
             VALUES (:lead_id, :event_type, :actor, :from_value, :to_value, :message)'
        );
        $statement->execute([
            'lead_id' => $leadId,
            'event_type' => $eventType,
            'actor' => $actor,
            'from_value' => $this->truncateWorkflowEventValue($fromValue),
            'to_value' => $this->truncateWorkflowEventValue($toValue),
            'message' => $message,
        ]);
    }

    private function truncateWorkflowEventValue(string $value, int $limit = 255): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, max(0, $limit - 3))) . '...';
    }

    private function insertSendAttempt(
        int $leadId,
        string $smtpHost,
        string $recipientEmail,
        string $originalRecipientEmail,
        string $subjectLine,
        string $mailTemplateId,
        string $deliveryMode,
        string $status,
        string $errorMessage
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO email_send_attempts (lead_id, smtp_host, recipient_email, original_recipient_email, subject_line, mail_template_id, delivery_mode, status, error_message)
             VALUES (:lead_id, :smtp_host, :recipient_email, :original_recipient_email, :subject_line, :mail_template_id, :delivery_mode, :status, :error_message)'
        );
        $statement->execute([
            'lead_id' => $leadId,
            'smtp_host' => $smtpHost,
            'recipient_email' => $recipientEmail,
            'original_recipient_email' => $originalRecipientEmail,
            'subject_line' => $subjectLine,
            'mail_template_id' => $mailTemplateId,
            'delivery_mode' => $deliveryMode,
            'status' => $status,
            'error_message' => $errorMessage,
        ]);
    }

    private function logTrackingEvent(int $leadId, string $eventType, string $mailTemplateId, array $meta): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO email_tracking_events (lead_id, event_type, mail_template_id, event_meta_json)
             VALUES (:lead_id, :event_type, :mail_template_id, :event_meta_json)'
        );
        $statement->execute([
            'lead_id' => $leadId,
            'event_type' => $eventType,
            'mail_template_id' => $mailTemplateId,
            'event_meta_json' => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function shouldFastTrackOpenedLead(array $lead): bool
    {
        return (int) ($lead['is_mailable'] ?? 0) === 1
            && in_array((string) ($lead['contact_status'] ?? ''), ['sent', 'replied'], true)
            && (string) ($lead['last_response_type'] ?? '') === '';
    }

    private function isOutreachMailTemplate(string $mailTemplateId): bool
    {
        return in_array($mailTemplateId, ['polonads_intro_v1', 'polonads_followup_v1'], true);
    }

    private function calculateNextFollowupAt(array $lead, string $lastResponseType, int $outreachSendCount): ?string
    {
        if ($lastResponseType === 'contact_later') {
            return $this->buildFutureTimestamp($this->automationInt('contact_later_followup_delay_days', 14));
        }

        if ((int) ($lead['open_count'] ?? 0) > 0) {
            return $this->buildFutureTimestamp($this->automationInt('open_followup_delay_days', 3));
        }

        $maxAttempts = $this->automationInt('cold_max_attempts_before_cooldown', 3);
        if ($outreachSendCount >= $maxAttempts) {
            return $this->buildFutureTimestamp($this->automationInt('cooldown_retry_days', 14));
        }

        return $this->buildFutureTimestamp($this->automationInt('unopened_followup_delay_days', 7));
    }

    private function buildFutureTimestamp(int $days): string
    {
        $days = max(0, $days);

        return date('Y-m-d H:i:s', strtotime('+' . $days . ' days'));
    }

    private function automationInt(string $key, int $default): int
    {
        return max(0, (int) ($this->config['automation'][$key] ?? $default));
    }

    private function normalizeWorkflowData(array $data, array $current): array
    {
        $normalized = [
            'contact_status' => trim((string) ($data['contact_status'] ?? ($current['contact_status'] ?? 'new'))),
            'approval_status' => trim((string) ($data['approval_status'] ?? ($current['approval_status'] ?? 'pending'))),
            'campaign_id' => trim((string) ($data['campaign_id'] ?? ($current['campaign_id'] ?? ''))),
            'notes' => trim((string) ($data['notes'] ?? ($current['notes'] ?? ''))),
            'email_subject' => trim((string) ($data['email_subject'] ?? ($current['email_subject'] ?? ''))),
            'email_draft' => trim((string) ($data['email_draft'] ?? ($current['email_draft'] ?? ''))),
            'email_final' => trim((string) ($data['email_final'] ?? ($current['email_final'] ?? ''))),
        ];

        $hasContent = $normalized['email_subject'] !== ''
            && ($normalized['email_final'] !== '' || $normalized['email_draft'] !== '');

        if ($normalized['approval_status'] === 'approved' && $hasContent && $normalized['contact_status'] === 'new') {
            $normalized['contact_status'] = 'approved';
        }

        if ($normalized['approval_status'] !== 'approved' && $normalized['contact_status'] === 'approved') {
            $normalized['contact_status'] = $hasContent ? 'draft_ready' : 'new';
        }

        if ($normalized['contact_status'] === 'draft_ready' && !$hasContent) {
            $normalized['contact_status'] = 'new';
        }

        if ($normalized['contact_status'] === 'new' && $hasContent) {
            $normalized['contact_status'] = 'draft_ready';
        }

        return $normalized;
    }

    private function appendSystemNote(string $existingNotes, string $note): string
    {
        $prefix = '[' . date('Y-m-d H:i:s') . '] ';
        if (trim($existingNotes) === '') {
            return $prefix . $note;
        }

        return rtrim($existingNotes) . "\n" . $prefix . $note;
    }

    public function fetchLatestIssues(int $limit = 20): array
    {
        $statement = $this->pdo->prepare(
            'SELECT company_name, primary_email, severity, issue_code, message, created_at
             FROM lead_import_issues
             ORDER BY id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function mergeLeadMeta(int $leadId, array $meta, string $actor = 'system'): void
    {
        $lead = $this->findLeadById($leadId);
        if ($lead === null) {
            return;
        }

        $currentMeta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
        $mergedMeta = array_replace_recursive($currentMeta, $meta);

        $this->pdo->beginTransaction();

        $statement = $this->pdo->prepare(
            'UPDATE leads
             SET personalization_data = :personalization_data,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $leadId,
            'personalization_data' => LeadMeta::encode($mergedMeta),
        ]);

        $this->recordMetaChanges($leadId, $currentMeta, $mergedMeta, $actor);
        $this->pdo->commit();
    }

    public function recordPublicationLog(int $leadId, string $status, string $message, array $payload = []): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO publication_logs (lead_id, status, message, payload_json)
             VALUES (:lead_id, :status, :message, :payload_json)'
        );
        $statement->execute([
            'lead_id' => $leadId,
            'status' => $status,
            'message' => $message,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function fetchPublicationLogs(int $leadId, int $limit = 20): array
    {
        $statement = $this->pdo->prepare(
            'SELECT status, message, payload_json, created_at
             FROM publication_logs
             WHERE lead_id = :lead_id
             ORDER BY id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':lead_id', $leadId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function fetchReadyForPublication(int $limit = 25): array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM leads
             WHERE is_mailable = 1
               AND approval_status = "approved"
               AND contact_status = "approved"
               AND (email_final <> "" OR email_draft <> "")
               AND personalization_data LIKE \'%"publication_status":"approved"%\'
             ORDER BY id ASC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function fetchPublicationQueueSummary(): array
    {
        $summary = $this->pdo->query(
            'SELECT
                SUM(CASE
                    WHEN is_mailable = 1
                     AND approval_status = "approved"
                     AND contact_status = "approved"
                     AND (email_final <> "" OR email_draft <> "")
                     AND personalization_data LIKE \'%"publication_status":"approved"%\'
                    THEN 1 ELSE 0 END
                ) AS ready_to_publish,
                SUM(CASE
                    WHEN is_mailable = 1
                     AND approval_status = "approved"
                     AND personalization_data LIKE \'%"publication_status":"approved"%\'
                     AND (
                        email_final = "" AND email_draft = ""
                     )
                    THEN 1 ELSE 0 END
                ) AS blocked_missing_listing,
                SUM(CASE
                    WHEN personalization_data LIKE \'%"publication_status":"live"%\'
                    THEN 1 ELSE 0 END
                ) AS published_count,
                SUM(CASE
                    WHEN id IN (
                        SELECT lead_id
                        FROM publication_logs
                        WHERE status = "failed"
                    )
                    THEN 1 ELSE 0 END
                ) AS failed_count
             FROM leads'
        )->fetch();

        return $summary ?: [
            'ready_to_publish' => 0,
            'blocked_missing_listing' => 0,
            'published_count' => 0,
            'failed_count' => 0,
        ];
    }

    public function fetchPublicationQueueReady(int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, company_name, city, state, primary_email, campaign_id, approval_status, contact_status, email_subject, personalization_data
             FROM leads
             WHERE is_mailable = 1
               AND approval_status = "approved"
               AND contact_status = "approved"
               AND (email_final <> "" OR email_draft <> "")
               AND personalization_data LIKE \'%"publication_status":"approved"%\'
             ORDER BY id ASC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function fetchPublicationQueueBlocked(int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, company_name, city, state, primary_email, approval_status, contact_status, email_subject, email_draft, email_final, personalization_data
             FROM leads
             WHERE is_mailable = 1
               AND approval_status = "approved"
               AND personalization_data LIKE \'%"publication_status":"approved"%\'
               AND (
                    email_final = "" AND email_draft = ""
               )
             ORDER BY id ASC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function fetchRecentlyPublished(int $limit = 50): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, company_name, primary_email, campaign_id, updated_at, personalization_data
             FROM leads
             WHERE personalization_data LIKE \'%"publication_status":"live"%\'
             ORDER BY updated_at DESC, id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function fetchRecentlyFailedPublication(int $limit = 50): array
    {
        $statement = $this->pdo->prepare(
            'SELECT l.id, l.company_name, l.primary_email, l.campaign_id, p.message, p.created_at
             FROM publication_logs p
             INNER JOIN leads l ON l.id = p.lead_id
             WHERE p.status = "failed"
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }
}
