<?php

declare(strict_types=1);

namespace MailingApp;

use PDO;

final class PolonadsPublicationGateway
{
    private const STARTER_POINTS = 50;
    private const STARTER_POINTS_DESCRIPTION = 'Welcome bonus from mailing_app';

    private PDO $pdo;
    private string $prefix;

    public function __construct(PDO $pdo, string $prefix = 'jost3_')
    {
        $this->pdo = $pdo;
        $this->prefix = $prefix;
    }

    public function findUserByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            sprintf('SELECT * FROM %susers WHERE email = :email LIMIT 1', $this->prefix)
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();

        return $user === false ? null : $user;
    }

    public function findUserByUsername(string $username): ?array
    {
        $statement = $this->pdo->prepare(
            sprintf('SELECT * FROM %susers WHERE username = :username LIMIT 1', $this->prefix)
        );
        $statement->execute(['username' => $username]);
        $user = $statement->fetch();

        return $user === false ? null : $user;
    }

    public function reserveUniqueUsername(string $preferredUsername): string
    {
        $candidate = $preferredUsername;
        $suffix = 1;

        while ($this->findUserByUsername($candidate) !== null) {
            $candidate = substr($preferredUsername, 0, max(1, 150 - strlen((string) $suffix) - 1)) . '_' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    public function createUser(array $data): array
    {
        $statement = $this->pdo->prepare(
            sprintf(
                'INSERT INTO %susers (name, username, email, password, block, sendEmail, registerDate, lastvisitDate, activation, params, lastResetTime, resetCount, otpKey, otep, requireReset, authProvider)
                 VALUES (:name, :username, :email, :password, :block, :sendEmail, NOW(), NULL, \'\', \'\', NULL, 0, \'\', \'\', 0, \'\')',
                $this->prefix
            )
        );
        $statement->execute([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => $data['password'],
            'block' => (int) ($data['block'] ?? 0),
            'sendEmail' => (int) ($data['sendEmail'] ?? 0),
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->grantStarterPoints($id);
        $user = $this->findUserById($id);

        return $user ?? [];
    }

    public function findUserById(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            sprintf('SELECT * FROM %susers WHERE id = :id LIMIT 1', $this->prefix)
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();

        return $user === false ? null : $user;
    }

    public function findProfileByUserId(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            sprintf('SELECT * FROM %sdjcf_profiles WHERE user_id = :user_id LIMIT 1', $this->prefix)
        );
        $statement->execute(['user_id' => $userId]);
        $profile = $statement->fetch();

        return $profile === false ? null : $profile;
    }

    public function insertProfile(int $userId, array $data): array
    {
        $statement = $this->pdo->prepare(
            sprintf(
                'INSERT INTO %sdjcf_profiles (user_id, group_id, region_id, address, post_code, latitude, longitude, verified, disabled_emails, description)
                 VALUES (:user_id, :group_id, :region_id, :address, :post_code, 0, 0, :verified, \'\', :description)',
                $this->prefix
            )
        );
        $statement->execute([
            'user_id' => $userId,
            'group_id' => (int) ($data['group_id'] ?? 2),
            'region_id' => (int) ($data['region_id'] ?? 1),
            'address' => (string) ($data['address'] ?? ''),
            'post_code' => (string) ($data['post_code'] ?? ''),
            'verified' => (int) ($data['verified'] ?? 0),
            'description' => (string) ($data['description'] ?? ''),
        ]);

        $profile = $this->findProfileByUserId($userId);

        return $profile ?? [];
    }

    public function updateProfile(int $userId, array $data): array
    {
        $statement = $this->pdo->prepare(
            sprintf(
                'UPDATE %sdjcf_profiles
                 SET group_id = :group_id,
                     region_id = :region_id,
                     address = :address,
                     post_code = :post_code,
                     verified = :verified,
                     description = :description
                 WHERE user_id = :user_id',
                $this->prefix
            )
        );
        $statement->execute([
            'user_id' => $userId,
            'group_id' => (int) ($data['group_id'] ?? 2),
            'region_id' => (int) ($data['region_id'] ?? 1),
            'address' => (string) ($data['address'] ?? ''),
            'post_code' => (string) ($data['post_code'] ?? ''),
            'verified' => (int) ($data['verified'] ?? 0),
            'description' => (string) ($data['description'] ?? ''),
        ]);

        $profile = $this->findProfileByUserId($userId);

        return $profile ?? [];
    }

    public function createItem(int $userId, array $data): array
    {
        $alias = $this->reserveUniqueItemAlias((string) ($data['name'] ?? 'listing'));
        $dates = $this->resolvePublicationDates((string) ($data['publication_approved_at'] ?? ''));

        $statement = $this->pdo->prepare(
            sprintf(
                'INSERT INTO %sdjcf_items (
                    cat_id,
                    type_id,
                    user_id,
                    name,
                    alias,
                    description,
                    intro_desc,
                    date_start,
                    date_exp,
                    date_mod,
                    date_sort,
                    published,
                    price,
                    price_negotiable,
                    contact,
                    address,
                    region_id,
                    promotions,
                    post_code,
                    video,
                    website,
                    ip_address,
                    currency,
                    metakey,
                    metadesc,
                    latitude,
                    longitude,
                    email,
                    token,
                    metarobots,
                    last_view,
                    auction_assist
                 ) VALUES (
                    :cat_id,
                    :type_id,
                    :user_id,
                    :name,
                    :alias,
                    :description,
                    :intro_desc,
                    :date_start,
                    :date_exp,
                    :date_mod,
                    :date_sort,
                    :published,
                    \'\',
                    0,
                    :contact,
                    :address,
                    :region_id,
                    \'\',
                    :post_code,
                    \'\',
                    :website,
                    \'\',
                    \'\',
                    \'\',
                    \'\',
                    0,
                    0,
                    :email,
                    \'\',
                    \'\',
                    \'0000-00-00 00:00:00\',
                    0
                 )',
                $this->prefix
            )
        );
        $statement->execute([
            'cat_id' => (int) ($data['cat_id'] ?? 4),
            'type_id' => (int) ($data['type_id'] ?? 0),
            'user_id' => $userId,
            'name' => (string) ($data['name'] ?? ''),
            'alias' => $alias,
            'description' => (string) ($data['description'] ?? ''),
            'intro_desc' => (string) ($data['intro_desc'] ?? ''),
            'date_start' => $dates['date_start'],
            'date_exp' => $dates['date_exp'],
            'date_mod' => $dates['date_mod'],
            'date_sort' => $dates['date_sort'],
            'published' => (int) ($data['published'] ?? 0),
            'contact' => (string) ($data['contact'] ?? ''),
            'address' => (string) ($data['address'] ?? ''),
            'region_id' => (int) ($data['region_id'] ?? 1),
            'post_code' => (string) ($data['post_code'] ?? ''),
            'website' => (string) ($data['website'] ?? ''),
            'email' => (string) ($data['email'] ?? ''),
        ]);

        $itemId = (int) $this->pdo->lastInsertId();
        $this->syncItemCategory($itemId, (int) ($data['cat_id'] ?? 4));
        $item = $this->findItemById($itemId);

        return $item ?? [];
    }

    public function findItemById(int $itemId): ?array
    {
        $statement = $this->pdo->prepare(
            sprintf('SELECT * FROM %sdjcf_items WHERE id = :id LIMIT 1', $this->prefix)
        );
        $statement->execute(['id' => $itemId]);
        $item = $statement->fetch();

        return $item === false ? null : $item;
    }

    public function findItemByUserAndFingerprint(int $userId, string $name, string $website): ?array
    {
        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT *
                 FROM %sdjcf_items
                 WHERE user_id = :user_id
                   AND name = :name
                   AND website = :website
                 ORDER BY id DESC
                 LIMIT 1',
                $this->prefix
            )
        );
        $statement->execute([
            'user_id' => $userId,
            'name' => $name,
            'website' => $website,
        ]);
        $item = $statement->fetch();

        return $item === false ? null : $item;
    }

    public function updateItem(int $itemId, array $data): array
    {
        $dates = $this->resolvePublicationDates((string) ($data['publication_approved_at'] ?? ''));

        $statement = $this->pdo->prepare(
            sprintf(
                'UPDATE %sdjcf_items
                 SET cat_id = :cat_id,
                     type_id = :type_id,
                     name = :name,
                     description = :description,
                     intro_desc = :intro_desc,
                     published = :published,
                     contact = :contact,
                     address = :address,
                     region_id = :region_id,
                     post_code = :post_code,
                     website = :website,
                     email = :email,
                     date_start = :date_start,
                     date_exp = :date_exp,
                     date_mod = :date_mod,
                     date_sort = :date_sort
                 WHERE id = :id',
                $this->prefix
            )
        );
        $statement->execute([
            'id' => $itemId,
            'cat_id' => (int) ($data['cat_id'] ?? 4),
            'type_id' => (int) ($data['type_id'] ?? 0),
            'name' => (string) ($data['name'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'intro_desc' => (string) ($data['intro_desc'] ?? ''),
            'published' => (int) ($data['published'] ?? 0),
            'contact' => (string) ($data['contact'] ?? ''),
            'address' => (string) ($data['address'] ?? ''),
            'region_id' => (int) ($data['region_id'] ?? 1),
            'post_code' => (string) ($data['post_code'] ?? ''),
            'website' => (string) ($data['website'] ?? ''),
            'email' => (string) ($data['email'] ?? ''),
            'date_start' => $dates['date_start'],
            'date_exp' => $dates['date_exp'],
            'date_mod' => $dates['date_mod'],
            'date_sort' => $dates['date_sort'],
        ]);

        $this->syncItemCategory($itemId, (int) ($data['cat_id'] ?? 4));
        $item = $this->findItemById($itemId);

        return $item ?? [];
    }

    public function findItemImages(int $itemId, string $type = 'item'): array
    {
        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT *
                 FROM %sdjcf_images
                 WHERE item_id = :item_id
                   AND type = :type
                 ORDER BY ordering ASC, id ASC',
                $this->prefix
            )
        );
        $statement->execute([
            'item_id' => $itemId,
            'type' => $type,
        ]);

        $images = $statement->fetchAll();

        return is_array($images) ? $images : [];
    }

    public function deleteItemImages(int $itemId, string $type = 'item'): void
    {
        $statement = $this->pdo->prepare(
            sprintf(
                'DELETE FROM %sdjcf_images
                 WHERE item_id = :item_id
                   AND type = :type',
                $this->prefix
            )
        );
        $statement->execute([
            'item_id' => $itemId,
            'type' => $type,
        ]);
    }

    public function insertItemImage(
        int $itemId,
        string $type,
        string $name,
        string $ext,
        string $path,
        string $caption = '',
        int $ordering = 1
    ): void {
        $statement = $this->pdo->prepare(
            sprintf(
                'INSERT INTO %sdjcf_images (item_id, type, name, ext, path, caption, ordering)
                 VALUES (:item_id, :type, :name, :ext, :path, :caption, :ordering)',
                $this->prefix
            )
        );
        $statement->execute([
            'item_id' => $itemId,
            'type' => $type,
            'name' => $name,
            'ext' => $ext,
            'path' => $path,
            'caption' => $caption,
            'ordering' => $ordering,
        ]);
    }

    public function userGroupMappingExists(int $userId, int $groupId): bool
    {
        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT user_id
                 FROM %suser_usergroup_map
                 WHERE user_id = :user_id
                   AND group_id = :group_id
                 LIMIT 1',
                $this->prefix
            )
        );
        $statement->execute([
            'user_id' => $userId,
            'group_id' => $groupId,
        ]);

        return $statement->fetch() !== false;
    }

    public function addUserToGroup(int $userId, int $groupId): void
    {
        if ($this->userGroupMappingExists($userId, $groupId)) {
            return;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'INSERT INTO %suser_usergroup_map (user_id, group_id)
                 VALUES (:user_id, :group_id)',
                $this->prefix
            )
        );
        $statement->execute([
            'user_id' => $userId,
            'group_id' => $groupId,
        ]);
    }

    public function syncItemCategory(int $itemId, int $categoryId): void
    {
        if ($itemId <= 0 || $categoryId <= 0) {
            return;
        }

        $delete = $this->pdo->prepare(
            sprintf('DELETE FROM %sdjcf_items_categories WHERE item_id = :item_id', $this->prefix)
        );
        $delete->execute([
            'item_id' => $itemId,
        ]);

        $insert = $this->pdo->prepare(
            sprintf(
                'INSERT INTO %sdjcf_items_categories (item_id, cat_id, ordering)
                 VALUES (:item_id, :cat_id, 0)',
                $this->prefix
            )
        );
        $insert->execute([
            'item_id' => $itemId,
            'cat_id' => $categoryId,
        ]);
    }

    public function findCategoryById(int $categoryId): ?array
    {
        if ($categoryId <= 0) {
            return null;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT c.id, c.name, c.parent_id, c.alias, c.published
                 FROM %sdjcf_categories c
                 WHERE c.id = :id
                 LIMIT 1',
                $this->prefix
            )
        );
        $statement->execute(['id' => $categoryId]);
        $category = $statement->fetch();

        return $category === false ? null : $category;
    }

    public function fetchCategories(): array
    {
        $statement = $this->pdo->query(
            sprintf(
                'SELECT c.id, c.name, c.parent_id, c.alias, c.published
                 FROM %sdjcf_categories c
                 ORDER BY c.parent_id ASC, c.ordering ASC, c.name ASC',
                $this->prefix
            )
        );

        $categories = $statement->fetchAll();

        return is_array($categories) ? $categories : [];
    }

    private function reserveUniqueItemAlias(string $name): string
    {
        $base = $this->slugify($name);
        if ($base === '') {
            $base = 'listing';
        }

        $candidate = $base;
        $suffix = 1;

        while ($this->itemAliasExists($candidate)) {
            $candidate = substr($base, 0, max(1, 255 - strlen((string) $suffix) - 1)) . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function itemAliasExists(string $alias): bool
    {
        $statement = $this->pdo->prepare(
            sprintf('SELECT id FROM %sdjcf_items WHERE alias = :alias LIMIT 1', $this->prefix)
        );
        $statement->execute(['alias' => $alias]);

        return $statement->fetch() !== false;
    }

    private function grantStarterPoints(int $userId): void
    {
        if ($userId <= 0 || $this->hasStarterPoints($userId)) {
            return;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'INSERT INTO %sdjcf_users_points (user_id, points, description, desc_json)
                 VALUES (:user_id, :points, :description, :desc_json)',
                $this->prefix
            )
        );
        $statement->execute([
            'user_id' => $userId,
            'points' => self::STARTER_POINTS,
            'description' => self::STARTER_POINTS_DESCRIPTION,
            'desc_json' => '{}',
        ]);
    }

    private function hasStarterPoints(int $userId): bool
    {
        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT id
                 FROM %sdjcf_users_points
                 WHERE user_id = :user_id
                   AND description = :description
                 LIMIT 1',
                $this->prefix
            )
        );
        $statement->execute([
            'user_id' => $userId,
            'description' => self::STARTER_POINTS_DESCRIPTION,
        ]);

        return $statement->fetch() !== false;
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    /**
     * @return array{date_start: string, date_exp: string, date_mod: string, date_sort: string}
     */
    private function resolvePublicationDates(string $approvedAt): array
    {
        $start = trim($approvedAt) !== '' ? strtotime($approvedAt) : false;
        if ($start === false) {
            $start = time();
        }

        $end = strtotime('+62 days', $start);

        return [
            'date_start' => date('Y-m-d H:i:s', $start),
            'date_exp' => date('Y-m-d H:i:s', $end),
            'date_mod' => date('Y-m-d H:i:s', $start),
            'date_sort' => date('Y-m-d H:i:s', $start),
        ];
    }
}
