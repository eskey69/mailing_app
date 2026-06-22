<?php

declare(strict_types=1);

namespace MailingApp;

use MailingApp\MailTemplateFactory;
use RuntimeException;

final class PublicationService
{
    private LeadRepository $leadRepository;
    private PublicationPayloadBuilder $payloadBuilder;
    private array $config;

    public function __construct(LeadRepository $leadRepository, PublicationPayloadBuilder $payloadBuilder, array $config)
    {
        $this->leadRepository = $leadRepository;
        $this->payloadBuilder = $payloadBuilder;
        $this->config = $config;
    }

    public function previewLead(int $leadId): array
    {
        $lead = $this->requireLead($leadId);
        $payload = $this->payloadBuilder->build($lead);
        $imageSelection = $this->selectListingImages($lead, $payload);
        $this->applyImageSelectionToPayload($payload, $imageSelection);

        return $payload;
    }

    public function prepareDraftListing(int $leadId): array
    {
        $lead = $this->requireLead($leadId);
        $payload = $this->payloadBuilder->build($lead);
        $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
        $imageSelection = $this->selectListingImages($lead, $payload);
        $this->applyImageSelectionToPayload($payload, $imageSelection);

        if (($payload['status'] ?? 'review_required') !== 'ready') {
            throw new RuntimeException('Lead is not ready to prepare a draft listing. Resolve warnings first.');
        }

        $database = new Database($this->config, 'polonads_db');
        $pdo = $database->pdo();
        $gateway = new PolonadsPublicationGateway(
            $pdo,
            (string) ($this->config['polonads_db']['prefix'] ?? 'jost3_')
        );

        $pdo->beginTransaction();

        try {
            $userResult = $this->ensureJoomlaUser($gateway, $payload['joomla_user']);
            $profileResult = $this->ensureDjcfProfile($gateway, (int) $userResult['user']['id'], $payload['djcf_profile']);
            $itemResult = $this->createDjcfItem($gateway, (int) $userResult['user']['id'], $payload['djcf_item']);
            $this->syncDjcfItemImages($gateway, (int) $itemResult['item']['id'], $imageSelection);

            $listingUrl = $this->buildListingUrl((int) $itemResult['item']['id'], (string) ($itemResult['item']['alias'] ?? ''));
            if ($listingUrl === '') {
                $listingUrl = sprintf('https://polonads.com/index.php/en-us/dodaj-ogloszenie-uslugi-2/%d', (int) $itemResult['item']['id']);
            }

            $metaUpdate = [
                'publication_target' => 'polonads',
                'joomla_user_id' => (int) $userResult['user']['id'],
                'joomla_username' => (string) $userResult['user']['username'],
                'portal_account_action' => (string) $userResult['action'],
                'portal_login' => (string) $userResult['user']['username'],
                'portal_password_plain' => (string) ($userResult['temporary_password'] ?? ''),
                'portal_password_mode' => (string) ($userResult['temporary_password'] ?? '') !== '' ? 'temporary' : 'reset_required',
                'djcf_profile_user_id' => (int) $profileResult['profile']['user_id'],
                'djcf_item_id' => (int) $itemResult['item']['id'],
                'listing_url' => $listingUrl,
                'publication_status' => (string) ($meta['publication_status'] ?? 'drafted') === 'approved' ? 'approved' : 'drafted',
                'account_status' => 'created',
            ];
            $metaUpdate = array_replace($metaUpdate, $this->buildImageMeta($imageSelection));

            $this->leadRepository->mergeLeadMeta($leadId, $metaUpdate, 'publisher');

            $this->leadRepository->recordPublicationLog(
                $leadId,
                'draft_prepared',
                sprintf(
                    'Prepared draft listing in Polonads. user_id=%d, profile_user_id=%d, item_id=%d',
                    (int) $userResult['user']['id'],
                    (int) $profileResult['profile']['user_id'],
                    (int) $itemResult['item']['id']
                ),
                $payload
            );

            $pdo->commit();
            $this->recordListingImageUsage($imageSelection, $leadId);

            return [
                'status' => 'draft_prepared',
                'user' => $userResult,
                'profile' => $profileResult,
                'item' => $itemResult,
                'listing_url' => $listingUrl,
            ];
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            $this->leadRepository->recordPublicationLog(
                $leadId,
                'draft_prepare_failed',
                $exception->getMessage(),
                $payload
            );
            throw $exception;
        }
    }

    public function publishLead(int $leadId): array
    {
        $lead = $this->requireLead($leadId);
        $payload = $this->payloadBuilder->build($lead);
        $meta = LeadMeta::decode((string) ($lead['personalization_data'] ?? ''));
        $imageSelection = $this->selectListingImages($lead, $payload);
        $this->applyImageSelectionToPayload($payload, $imageSelection);
        $payload['djcf_item']['create']['published'] = 1;

        if ((string) ($meta['publication_status'] ?? '') !== 'approved') {
            throw new RuntimeException('Listing draft has not been approved by the recipient yet.');
        }

        if (($payload['status'] ?? 'review_required') !== 'ready') {
            throw new RuntimeException('Lead is not ready for publication. Resolve warnings first.');
        }

        $database = new Database($this->config, 'polonads_db');
        $pdo = $database->pdo();
        $gateway = new PolonadsPublicationGateway(
            $pdo,
            (string) ($this->config['polonads_db']['prefix'] ?? 'jost3_')
        );

        $pdo->beginTransaction();

        try {
            $userResult = $this->ensureJoomlaUser($gateway, $payload['joomla_user']);
            $profileResult = $this->ensureDjcfProfile($gateway, (int) $userResult['user']['id'], $payload['djcf_profile']);
            $itemResult = $this->createDjcfItem($gateway, (int) $userResult['user']['id'], $payload['djcf_item']);
            $this->syncDjcfItemImages($gateway, (int) $itemResult['item']['id'], $imageSelection);

            $listingUrl = $this->buildListingUrl((int) $itemResult['item']['id'], (string) ($itemResult['item']['alias'] ?? ''));

            $metaUpdate = [
                'publication_target' => 'polonads',
                'joomla_user_id' => (int) $userResult['user']['id'],
                'joomla_username' => (string) $userResult['user']['username'],
                'portal_account_action' => (string) $userResult['action'],
                'portal_login' => (string) $userResult['user']['username'],
                'portal_password_plain' => (string) ($userResult['temporary_password'] ?? ''),
                'portal_password_mode' => (string) ($userResult['temporary_password'] ?? '') !== '' ? 'temporary' : 'reset_required',
                'djcf_profile_user_id' => (int) $profileResult['profile']['user_id'],
                'djcf_item_id' => (int) $itemResult['item']['id'],
                'listing_url' => $listingUrl,
                'publication_status' => 'live',
                'account_status' => 'active',
            ];
            $metaUpdate = array_replace($metaUpdate, $this->buildImageMeta($imageSelection));

            $this->leadRepository->mergeLeadMeta($leadId, $metaUpdate, 'publisher');

            $this->leadRepository->markLeadPublished($leadId, $listingUrl, (string) ($meta['draft_language'] ?? 'en'));

            $publishedLead = $this->requireLead($leadId);
            $publishedMail = MailTemplateFactory::build('polonads_published_v1', $publishedLead, $this->config);
            $publishedFinal = trim((string) ($publishedMail['email_final'] ?? ''));
            if ($publishedFinal === '') {
                $publishedFinal = (string) ($publishedMail['email_draft'] ?? '');
            }
            $this->leadRepository->updateLeadWorkflow($leadId, [
                'contact_status' => (string) ($publishedLead['contact_status'] ?? 'published'),
                'approval_status' => (string) ($publishedLead['approval_status'] ?? 'approved'),
                'campaign_id' => (string) ($publishedLead['campaign_id'] ?? ''),
                'notes' => (string) ($publishedLead['notes'] ?? ''),
                'email_subject' => $publishedMail['email_subject'],
                'email_draft' => $publishedMail['email_draft'],
                'email_final' => $publishedFinal,
            ]);
            $this->leadRepository->mergeLeadMeta($leadId, [
                'mail_template_id' => (string) ($publishedMail['mail_template_id'] ?? ''),
            ], 'system');

            $this->leadRepository->recordPublicationLog(
                $leadId,
                'published',
                sprintf(
                    'Published to Polonads. user_id=%d, profile_user_id=%d, item_id=%d',
                    (int) $userResult['user']['id'],
                    (int) $profileResult['profile']['user_id'],
                    (int) $itemResult['item']['id']
                ),
                $payload
            );

            $pdo->commit();
            $this->recordListingImageUsage($imageSelection, $leadId);

            return [
                'status' => 'published',
                'user' => $userResult,
                'profile' => $profileResult,
                'item' => $itemResult,
                'listing_url' => $listingUrl,
            ];
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            $this->leadRepository->recordPublicationLog(
                $leadId,
                'failed',
                $exception->getMessage(),
                $payload
            );
            throw $exception;
        }
    }

    private function ensureJoomlaUser(PolonadsPublicationGateway $gateway, array $payload): array
    {
        $lookup = $payload['lookup'] ?? [];
        $create = $payload['create'] ?? [];
        $groups = $payload['groups'] ?? [];
        $user = null;
        $action = 'existing';
        $temporaryPassword = '';

        if (($lookup['email'] ?? '') !== '') {
            $user = $gateway->findUserByEmail((string) $lookup['email']);
        }

        if ($user === null) {
            $username = $gateway->reserveUniqueUsername((string) ($create['username'] ?? 'lead_user'));
            $temporaryPassword = (string) ($create['password_plain'] ?? '');
            $user = $gateway->createUser([
                'name' => (string) ($create['name'] ?? ''),
                'username' => $username,
                'email' => (string) ($create['email'] ?? ''),
                'password' => password_hash($temporaryPassword, PASSWORD_BCRYPT),
                'block' => (int) ($create['block'] ?? 0),
                'sendEmail' => (int) ($create['sendEmail'] ?? 0),
            ]);
            $action = 'created';
        }

        foreach ($groups as $groupId) {
            $gateway->addUserToGroup((int) $user['id'], (int) $groupId);
        }

        return [
            'action' => $action,
            'user' => $user,
            'temporary_password' => $action === 'created' ? $temporaryPassword : '',
        ];
    }

    private function ensureDjcfProfile(PolonadsPublicationGateway $gateway, int $userId, array $payload): array
    {
        $upsert = $payload['upsert'] ?? [];
        $profile = $gateway->findProfileByUserId($userId);
        $action = 'updated';

        if ($profile === null) {
            $profile = $gateway->insertProfile($userId, $upsert);
            $action = 'created';
        } else {
            $profile = $gateway->updateProfile($userId, $upsert);
        }

        return [
            'action' => $action,
            'profile' => $profile,
        ];
    }

    private function createDjcfItem(PolonadsPublicationGateway $gateway, int $userId, array $payload): array
    {
        $create = $payload['create'] ?? [];
        $existingItemId = (int) ($payload['existing_item_id'] ?? 0);
        $item = null;
        $action = 'created';

        if ($existingItemId > 0) {
            $item = $gateway->findItemById($existingItemId);
            if ($item !== null) {
                $item = $gateway->updateItem($existingItemId, $create);
                $action = 'updated';
            }
        }

        if ($item === null) {
            $item = $gateway->findItemByUserAndFingerprint(
                $userId,
                (string) ($create['name'] ?? ''),
                (string) ($create['website'] ?? '')
            );
            if ($item !== null) {
                $item = $gateway->updateItem((int) $item['id'], $create);
                $action = 'updated';
            }
        }

        if ($item === null) {
            $item = $gateway->createItem($userId, $create);
        }

        return [
            'action' => $action,
            'item' => $item,
        ];
    }

    private function buildListingUrl(int $itemId, string $alias): string
    {
        $template = (string) ($this->config['app']['polonads_listing_url_template'] ?? '');
        if ($template === '') {
            return '';
        }

        return str_replace(
            ['{id}', '{alias}'],
            [(string) $itemId, $alias],
            $template
        );
    }

    private function selectListingImages(array $lead, array $payload): array
    {
        try {
            $database = new Database($this->config);
            $library = new ListingImageLibrary($this->config, $database->pdo());
            $category = is_array($payload['trace']['category_mapping'] ?? null) ? $payload['trace']['category_mapping'] : [];

            return $library->selectForLead($lead, $category);
        } catch (\Throwable $exception) {
            return [];
        }
    }

    private function recordListingImageUsage(array $selection, int $leadId): void
    {
        if ($selection === []) {
            return;
        }

        try {
            $database = new Database($this->config);
            $library = new ListingImageLibrary($this->config, $database->pdo());
            $library->recordUsage($selection, $leadId);
        } catch (\Throwable $exception) {
            $this->leadRepository->recordPublicationLog(
                $leadId,
                'image_assignment_failed',
                $exception->getMessage(),
                $selection
            );
        }
    }

    private function applyImageSelectionToPayload(array &$payload, array $selection): void
    {
        if ($selection === []) {
            $payload['trace']['image_assignment'] = [
                'status' => 'not_assigned',
                'reason' => 'Photo library is not configured or no category images were found.',
            ];
            return;
        }

        $payload['djcf_item']['images'] = array_values($selection['images'] ?? []);
        $payload['trace']['image_assignment'] = [
            'status' => 'assigned',
            'source' => (string) ($selection['source'] ?? ''),
            'category_key' => (string) ($selection['category_key'] ?? ''),
            'image_key' => (string) ($selection['image_key'] ?? ''),
            'image_theme' => (string) ($selection['image_theme'] ?? ''),
            'images' => array_values($selection['images'] ?? []),
            'existing' => (bool) ($selection['existing'] ?? false),
        ];
    }

    private function buildImageMeta(array $selection): array
    {
        if ($selection === []) {
            return [];
        }

        return [
            'listing_images' => array_values($selection['images'] ?? []),
            'listing_image_source' => (string) ($selection['source'] ?? ''),
            'listing_image_category_key' => (string) ($selection['category_key'] ?? ''),
            'listing_image_key' => (string) ($selection['image_key'] ?? ''),
            'listing_image_theme' => (string) ($selection['image_theme'] ?? ''),
        ];
    }

    private function syncDjcfItemImages(PolonadsPublicationGateway $gateway, int $itemId, array $selection): void
    {
        if ($itemId <= 0 || $selection === []) {
            return;
        }

        $sourcePath = trim((string) ($selection['path'] ?? ''));
        if ($sourcePath === '' || !is_file($sourcePath)) {
            throw new RuntimeException('Selected listing image file is missing and cannot be synced to DJ-Classifieds.');
        }

        $existingImages = $gateway->findItemImages($itemId, 'item');
        foreach ($existingImages as $image) {
            $this->deleteDjcfImageFiles($image);
        }
        $gateway->deleteItemImages($itemId, 'item');

        $targetRelativeDir = $this->buildDjcfItemImageRelativeDir($itemId);
        $targetAbsoluteDir = $this->resolveDjcfSiteRoot() . str_replace('/', DIRECTORY_SEPARATOR, $targetRelativeDir);
        if (!is_dir($targetAbsoluteDir) && !mkdir($targetAbsoluteDir, 0775, true) && !is_dir($targetAbsoluteDir)) {
            throw new RuntimeException('Unable to create DJ-Classifieds image directory: ' . $targetAbsoluteDir);
        }

        $extension = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
        if ($extension === '') {
            throw new RuntimeException('Selected listing image file has no extension.');
        }

        $baseName = $this->buildDjcfImageBaseName($itemId, $sourcePath);
        $originalAbsolutePath = $targetAbsoluteDir . DIRECTORY_SEPARATOR . $baseName . '.' . $extension;

        if (!copy($sourcePath, $originalAbsolutePath)) {
            throw new RuntimeException('Unable to copy listing image into the DJ-Classifieds image directory.');
        }

        $thumbs = [
            'ths' => ['w' => 56, 'h' => 32],
            'thm' => ['w' => 150, 'h' => 110],
            'thb' => ['w' => 600, 'h' => 0],
        ];

        foreach ($thumbs as $suffix => $size) {
            $thumbAbsolutePath = $targetAbsoluteDir . DIRECTORY_SEPARATOR . $baseName . '_' . $suffix . '.' . $extension;
            $this->createDjcfThumb($originalAbsolutePath, $thumbAbsolutePath, $size['w'], $size['h']);
        }

        $gateway->insertItemImage($itemId, 'item', $baseName, $extension, $targetRelativeDir, '', 1);
    }

    private function resolveDjcfSiteRoot(): string
    {
        $sourcePath = rtrim(trim((string) ($this->config['photo_library']['source_path'] ?? '')), '/');
        if ($sourcePath === '') {
            throw new RuntimeException('Photo library source_path is not configured.');
        }

        $imagesDir = dirname($sourcePath);
        $siteRoot = dirname($imagesDir);
        if (!is_dir($siteRoot)) {
            throw new RuntimeException('Could not derive DJ-Classifieds site root from photo library source_path.');
        }

        return rtrim($siteRoot, DIRECTORY_SEPARATOR);
    }

    private function buildDjcfItemImageRelativeDir(int $itemId): string
    {
        $bucket = (int) (($itemId - ($itemId % 1000)) / 1000);

        return '/components/com_djclassifieds/images/item/' . $bucket . '/';
    }

    private function buildDjcfImageBaseName(int $itemId, string $sourcePath): string
    {
        $stem = strtolower((string) pathinfo($sourcePath, PATHINFO_FILENAME));
        $stem = preg_replace('/[^a-z0-9]+/', '_', $stem) ?? '';
        $stem = trim($stem, '_');
        if ($stem === '') {
            $stem = 'listing_image';
        }

        return $itemId . '_' . substr($stem, 0, 200);
    }

    private function deleteDjcfImageFiles(array $image): void
    {
        $siteRoot = $this->resolveDjcfSiteRoot();
        $path = trim((string) ($image['path'] ?? ''));
        $name = trim((string) ($image['name'] ?? ''));
        $ext = trim((string) ($image['ext'] ?? ''));

        if ($path === '' || $name === '' || $ext === '') {
            return;
        }

        $basePath = $siteRoot . str_replace('/', DIRECTORY_SEPARATOR, $path) . $name;
        foreach (['', '_ths', '_thm', '_thb'] as $suffix) {
            $candidate = $basePath . $suffix . '.' . $ext;
            if (is_file($candidate)) {
                @unlink($candidate);
            }
        }
    }

    private function createDjcfThumb(string $sourcePath, string $targetPath, int $targetWidth, int $targetHeight): void
    {
        if ($this->createResizedImage($sourcePath, $targetPath, $targetWidth, $targetHeight)) {
            return;
        }

        if (!copy($sourcePath, $targetPath)) {
            throw new RuntimeException('Unable to create DJ-Classifieds thumbnail: ' . basename($targetPath));
        }
    }

    private function createResizedImage(string $sourcePath, string $targetPath, int $targetWidth, int $targetHeight): bool
    {
        if (!function_exists('getimagesize')) {
            return false;
        }

        $imageInfo = @getimagesize($sourcePath);
        if (!is_array($imageInfo)) {
            return false;
        }

        [$sourceWidth, $sourceHeight, $sourceType] = $imageInfo;
        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            return false;
        }

        $createMap = [
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_GIF => 'imagecreatefromgif',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
        ];
        $saveMap = [
            IMAGETYPE_JPEG => static fn ($image, string $path): bool => imagejpeg($image, $path, 88),
            IMAGETYPE_PNG => static fn ($image, string $path): bool => imagepng($image, $path, 6),
            IMAGETYPE_GIF => static fn ($image, string $path): bool => imagegif($image, $path),
            IMAGETYPE_WEBP => static fn ($image, string $path): bool => function_exists('imagewebp') ? imagewebp($image, $path, 88) : false,
        ];

        $createFunction = $createMap[$sourceType] ?? null;
        $saveFunction = $saveMap[$sourceType] ?? null;
        if (!is_string($createFunction) || !function_exists($createFunction) || !is_callable($saveFunction)) {
            return false;
        }

        $sourceImage = @$createFunction($sourcePath);
        if ($sourceImage === false) {
            return false;
        }

        if ($targetWidth <= 0) {
            $targetWidth = (int) round(($targetHeight / $sourceHeight) * $sourceWidth);
        }
        if ($targetHeight <= 0) {
            $targetHeight = (int) round(($targetWidth / $sourceWidth) * $sourceHeight);
        }

        $ratio = min($targetWidth / $sourceWidth, $targetHeight / $sourceHeight);
        $ratio = $ratio > 0 ? min($ratio, 1.0) : 1.0;
        $finalWidth = max(1, (int) round($sourceWidth * $ratio));
        $finalHeight = max(1, (int) round($sourceHeight * $ratio));

        $targetImage = imagecreatetruecolor($finalWidth, $finalHeight);
        if ($targetImage === false) {
            imagedestroy($sourceImage);
            return false;
        }

        if (in_array($sourceType, [IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            imagealphablending($targetImage, false);
            imagesavealpha($targetImage, true);
            $transparent = imagecolorallocatealpha($targetImage, 0, 0, 0, 127);
            imagefilledrectangle($targetImage, 0, 0, $finalWidth, $finalHeight, $transparent);
        }

        $copied = imagecopyresampled(
            $targetImage,
            $sourceImage,
            0,
            0,
            0,
            0,
            $finalWidth,
            $finalHeight,
            $sourceWidth,
            $sourceHeight
        );

        $saved = false;
        if ($copied) {
            $saved = $saveFunction($targetImage, $targetPath);
        }

        imagedestroy($targetImage);
        imagedestroy($sourceImage);

        return $saved;
    }

    private function requireLead(int $leadId): array
    {
        $lead = $this->leadRepository->findLeadById($leadId);
        if ($lead === null) {
            throw new RuntimeException('Lead not found.');
        }

        return $lead;
    }
}
