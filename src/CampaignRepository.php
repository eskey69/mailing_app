<?php

declare(strict_types=1);

namespace MailingApp;

use PDO;

final class CampaignRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function fetchActiveCampaigns(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, name, mail_template_id, polonads_category_id, is_active, notes, created_at, updated_at
             FROM campaigns
             WHERE is_active = 1
             ORDER BY name ASC, id ASC'
        );

        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function fetchAllCampaigns(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, name, mail_template_id, polonads_category_id, is_active, notes, created_at, updated_at
             FROM campaigns
             ORDER BY is_active DESC, name ASC, id ASC'
        );

        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function findCampaignById(string $campaignId): ?array
    {
        $campaignId = trim($campaignId);
        if ($campaignId === '') {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, name, mail_template_id, polonads_category_id, is_active, notes, created_at, updated_at
             FROM campaigns
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $campaignId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function saveCampaign(array $data): void
    {
        $campaignId = trim((string) ($data['id'] ?? ''));
        if ($campaignId === '') {
            return;
        }

        $name = trim((string) ($data['name'] ?? ''));
        $mailTemplateId = trim((string) ($data['mail_template_id'] ?? ''));
        $categoryId = (int) ($data['polonads_category_id'] ?? 0);
        $isActive = (int) ($data['is_active'] ?? 0) === 1 ? 1 : 0;
        $notes = trim((string) ($data['notes'] ?? ''));

        $existing = $this->findCampaignById($campaignId);

        if ($existing === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO campaigns (
                    id, name, mail_template_id, polonads_category_id, is_active, notes
                 ) VALUES (
                    :id, :name, :mail_template_id, :polonads_category_id, :is_active, :notes
                 )'
            );
        } else {
            $statement = $this->pdo->prepare(
                'UPDATE campaigns
                 SET name = :name,
                     mail_template_id = :mail_template_id,
                     polonads_category_id = :polonads_category_id,
                     is_active = :is_active,
                     notes = :notes
                 WHERE id = :id'
            );
        }

        $statement->execute([
            'id' => $campaignId,
            'name' => $name,
            'mail_template_id' => $mailTemplateId,
            'polonads_category_id' => $categoryId > 0 ? $categoryId : null,
            'is_active' => $isActive,
            'notes' => $notes,
        ]);
    }
}
