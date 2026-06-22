<?php

declare(strict_types=1);

namespace MailingApp;

final class PublicationFieldMapper
{
    public static function describe(): array
    {
        return [
            'joomla_user' => [
                'name' => 'Lead company name -> jost3_users.name',
                'username' => 'Generated from company name or email local part -> jost3_users.username',
                'email' => 'Lead primary_email -> jost3_users.email',
                'password' => 'Generated temporary password for account setup flow -> jost3_users.password',
                'block' => 'Default 0 for active account',
                'sendEmail' => 'Default 0',
                'registerDate' => 'Set at account creation time',
            ],
            'djcf_profile' => [
                'user_id' => 'Linked 1:1 with jost3_users.id',
                'group_id' => 'Default 2 based on existing Polonads data',
                'region_id' => 'Mapped from city/state via PolonadsRegionMapper',
                'address' => 'Lead address',
                'post_code' => 'Extracted from address when possible, otherwise empty',
                'verified' => 'Default 0',
                'description' => 'Optional company/about summary generated later',
            ],
            'djcf_item' => [
                'user_id' => 'Owner account id from Joomla user',
                'cat_id' => 'Mapped via PolonadsCategoryMapper',
                'type_id' => 'Default 0 for regular listing',
                'name' => 'Listing title from listing draft or company name',
                'description' => 'Listing body from approved draft',
                'intro_desc' => 'Short excerpt generated from description',
                'published' => '0 by default until final publication step, or 1 when explicitly live',
                'address' => 'Lead address',
                'region_id' => 'Mapped via PolonadsRegionMapper',
                'website' => 'Lead website',
                'email' => 'Lead primary_email',
                'contact' => 'Composed contact block: company, phone, email, website',
            ],
            'internal_only' => [
                'yp_url' => 'Kept in mailing app for traceability and later scraping, not pushed to DJ-Classifieds item table',
                'all_emails' => 'Kept for operator context and fallback contact',
                'email_source' => 'Kept for provenance and audit',
                'source_status' => 'Kept for workflow/debugging',
                'source_name' => 'Kept for provenance',
            ],
        ];
    }
}
