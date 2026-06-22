# Home PC Handoff

This file is the current operational handoff for Codex on the home computer.

Read this first before making new changes.

## Current Focus

The active product is `mailing_app`.

Do not switch attention back to the Yellow Pages scraper unless explicitly asked.

We are stabilizing the workflow around:

- lead records in `polojrre_mailing`
- mailing workflow and customer response handling
- Joomla user creation or reuse
- DJ-Classifieds draft item creation, update, category sync, and publication
- future AI-generated listing drafts

## Latest Campaign State

Current operational rule after the latest changes:

- one campaign = one final Polonads / DJ-Classifieds category
- category is chosen once at the start of the campaign
- all approved listings from that campaign must publish into that same category
- mail templates must not change listing category
- campaign setup must stay simple for operators

Important clarification:

- `campaign_id` is now treated as the stable business campaign id for a lead
- mail template identity is stored separately in lead metadata as `mail_template_id`
- old import-based category suggestion may still appear as helper context, but it must not override campaign category for publication
- on lead screen, the important field is active publication category, not old import suggestion

Current campaign in use:

- `campaign_id = polonads_draft_review_v1`
- publication category must be `Dam prace` (`id = 3`)

Observed expected behavior:

- test lead `1236` / `eskey69@gmail.com` already shows correct active publication category `3`
- remaining leads needed bulk assignment to the same `campaign_id` so they inherit category `3`
- current expectation is that all mailable records in this active batch use `campaign_id = polonads_draft_review_v1`
- this bulk assignment has now been applied
- for current active batch, all records should inherit publication category `3` through campaign

## Latest Image Library State

Update from 2026-05-26:

- production `mailing_app/config/app.php` was updated on the server through the FileZilla FTP profile named `polonads`
- FTP host: `ftp.polonads.com`
- FTP user: `codex@polonads.com`
- the FTP account starts directly inside the remote `mailing_app` directory
- only remote `config/app.php` was uploaded in that update
- remote `config/app.local.php` was downloaded for inspection but was not overwritten
- local production config files remain ignored by Git:
  - `mailing_app/config/app.php`
  - `mailing_app/config/app.local.php`
- tracked example config files now document the intended non-secret `photo_library` settings

Important production `photo_library` values after the FTP upload:

```php
'source_url' => 'https://polonads.com/images/kategorie',
'source_path' => '/home/polojrre/public_html/images/kategorie',
```

Correct category folder map currently used for the image library:

```php
3 => '3_dam_prace',
4 => '4_uslugi',
5 => '5_budowa_i_remonty',
6 => '6_transport_i_przeprowadzki',
9 => '9_it_i_internet',
10 => '10_reklama_i_fotografia',
11 => '11_opieka_i_pomoc_domowa',
12 => '12_nauka_i_kursy',
13 => '13_zdrowie_i_uroda',
14 => '14_prawo_i_finanse',
16 => '16_nieruchomosci',
21 => '21_paczki_do_polski',
22 => '22_ubezpieczenia',
23 => '23_sprzedam_kupie_oddam',
36 => '36_organizacje_spoleczne_i_religijne',
```

Previously fixed mistakes:

- `15 => '16_nieruchomosci'` was wrong; DJ-Classifieds uses category id `16` for Nieruchomosci
- `15 => '21_paczki_do_polski'` was wrong; DJ-Classifieds uses category id `21` for Paczki do Polski
- `22 => '22ubezpieczenia'` was wrong; the actual folder is `22_ubezpieczenia`
- `source_path => '/images/kategorie'` was wrong for PHP filesystem access; it must be the absolute server path under `/home/polojrre/public_html`

DJ-Classifieds category IDs were cross-checked against public PolonAds category URLs:

- `/index.php/pl/ogloszenia/dam-prace,3`
- `/index.php/pl/ogloszenia/uslugi,4`
- `/index.php/pl/ogloszenia/budowa-i-remonty,5`
- `/index.php/pl/ogloszenia/transport-i-przeprowadzki,6`
- `/index.php/pl/ogloszenia/it-i-internet,9`
- `/index.php/pl/ogloszenia/reklama-i-fotografia,10`
- `/index.php/pl/ogloszenia/opieka-i-pomoc-domowa,11`
- `/index.php/pl/ogloszenia/nauka-i-kursy,12`
- `/index.php/pl/ogloszenia/zdrowie-i-uroda,13`
- `/index.php/pl/ogloszenia/prawo-i-finanse,14`
- `/index.php/pl/ogloszenia/nieruchomosci,16`
- `/index.php/pl/ogloszenia/paczki-do-polski,21`
- `/index.php/pl/ogloszenia/ubezpieczenia,22`
- `/index.php/pl/ogloszenia/sprzedam-kupie-oddam,23`
- `/index.php/pl/ogloszenia/organizacje-spoleczne-i-religijne,36`

Public image-folder verification from 2026-05-26:

- `https://polonads.com/images/kategorie/` returns an autoindex listing
- all configured top-level category folders exist publicly
- `https://polonads.com/images/kategorie/22_ubezpieczenia/` returns HTTP 200
- `https://polonads.com/images/kategorie/22ubezpieczenia/` returns HTTP 404
- a sample image URL such as `https://polonads.com/images/kategorie/3_dam_prace/beauty/beauty_1.jpg` returns HTTP 200 and `image/jpeg`
- real files were visible mainly under `3_dam_prace/*` subfolders
- many other category folders were present but empty in the public listing at the time of verification
- for empty category folders, `ListingImageLibrary` will not assign an image until files are uploaded there

Image theme selection update from 2026-05-26:

- AI listing generation now asks OpenAI to return `visual_subtype`
- allowed `visual_subtype` values are:
  - `general`
  - `marketing`
  - `construction`
  - `transport`
  - `warehouse`
  - `cleaning`
  - `caregiver`
  - `beauty`
  - `restaurant`
  - `office`
  - `medical`
  - `it`
  - `sales`
  - `education`
  - `home_services`
  - `insurance`
  - `legal_finance`
  - `real_estate`
- imported AI drafts store this as `personalization_data.listing_visual_subtype`
- `ListingImageLibrary` now uses this value to try a thematic subfolder first
- fallback order is:
  1. AI-selected or locally inferred theme subfolder, e.g. `marketing`
  2. `general`
  3. any image in the category folder, only if neither thematic nor `general` exists
- selected image metadata now includes `listing_image_theme`
- image rotation still uses `listing_image_usage`
- rotation rule: a used image is not selected again while another image in the same selected pool has a lower `use_count`
- this means reuse starts only after the current thematic/default pool is exhausted

Important operational implication:

- `Archadvertising` is now detected by website analysis as `Reklama i Fotografia` (`id = 10`) instead of campaign-forced `Dam prace` (`id = 3`)
- with `visual_subtype = marketing`, the system will look under `10_reklama_i_fotografia/marketing`, then `10_reklama_i_fotografia/general`
- at the time of testing, category `10_reklama_i_fotografia` had no visible images, so `image_selection` correctly returned empty instead of using an unrelated `3_dam_prace/beauty` image
- to get images for corrected advertising leads, add files under:
  - `/home/polojrre/public_html/images/kategorie/10_reklama_i_fotografia/marketing/`
  - or `/home/polojrre/public_html/images/kategorie/10_reklama_i_fotografia/general/`

Follow-up production test for Atlas Employment from 2026-05-26:

- tested URL: `https://www.atlasemployment.com/atlas-employment-service-chicago`
- production lead: `id = 33`, `company_name = Atlas Employment Service Inc`
- source category: `Employment Agencies`
- campaign category before website analysis: `Dam pracę` (`id = 3`)
- website analysis suggested the same category: `Dam prace` (`id = 3`)
- `matched_keyword = employment service`
- `website_keyword_score = 7`
- `would_override_category = false`
- simulated `visual_subtype = office`
- selected image:
  - `https://polonads.com/images/kategorie/3_dam_prace/office/118754.jpg`
- selected image metadata:
  - `image_theme = office`
  - `image_key = 3_dam_prace/office/118754.jpg`

Important category-analysis lesson:

- an initial broad test accidentally matched unrelated leads because `user@domain.com` appears in many imported rows
- always filter diagnostics by exact website or unique URL path first
- website text can contain footer/legal/privacy text that may falsely trigger `Prawo i Finanse`
- employment/staffing terms must be weighted strongly toward `Dam pracę`
- `PolonadsCategoryMapper` now treats these as `Dam pracę` signals:
  - `employment service`
  - `employment services`
  - `employment agency`
  - `temporary employment`
  - `staffing`
  - `staffing agency`
  - `job seekers`
  - `employers`
  - `workforce`
  - `clerical`
  - `industrial`

Current server-side caveat:

- because `config/app.local.php` is loaded after `config/app.php`, a future `photo_library` block in local config can override the fixed values
- as of the FTP check on 2026-05-26, remote `config/app.local.php` did not contain `photo_library`, so the fixed base config should be active

Image assignment for listings is now implemented as a separate library workflow.

Current rule:

- every final listing should have one illustrative image
- image is assigned at listing-generation stage
- images come from our own public library, not from DJ-Classifieds upload storage
- one campaign category = one image pool
- images should not repeat until the whole category pool is exhausted
- when pool is exhausted, rotation starts again from the beginning
- system should automatically detect new files added later into category folders

Chosen storage model:

- public source folders under `public_html/images/kategorie/`
- category folders use pattern:
  - `images/kategorie/{category_id}_{slug}/`

Examples:

- `images/kategorie/3_dam_prace/`
- `images/kategorie/4_uslugi/`
- `images/kategorie/16_nieruchomosci/`

Additional structure decision for broad categories:

- broad categories like `3_dam_prace` and `4_uslugi` should support themed subfolders
- this is intended to improve image relevance for different job/service types
- operator may reuse similar image sets between `3_dam_prace` and `4_uslugi`

Suggested subfolders already agreed for broad categories:

- `general`
- `construction`
- `transport`
- `warehouse`
- `cleaning`
- `caregiver`
- `beauty`
- `restaurant`
- `office`
- `medical`
- `it`
- `sales`
- and for services additionally acceptable broader service-oriented reuse

Important decision:

- do not mix this library with internal DJ-Classifieds image folders
- use these files directly as public image URLs
- do not copy them into DJ-Classifieds image storage for now

Chosen filename convention:

- ASCII file names with supported extensions: `jpg`, `jpeg`, `png`, `webp`
- simple numbered names are acceptable, for example `{category_slug}_{NNN}.jpg`

Examples:

- `dam_prace_001.jpg`
- `dam_prace_002.jpg`
- `uslugi_001.jpg`

Current operator decision:

- keep this convention
- use public source files directly
- do not copy image files into DJ-Classifieds internals

Implementation state:

- `mailing_app/src/ListingImageLibrary.php` selects one public image URL from the configured category library
- `mailing_app/src/PublicationService.php` calls the library during preview, draft preparation, and publication
- selected image URLs are stored in lead metadata under `listing_images`
- image rotation is tracked in the local mailing DB table `listing_image_usage`
- newly added files are detected by rescanning the configured folders each time
- if `photo_library.source_url` or `photo_library.source_path` is empty, no image is assigned and DJ-Catalog keeps its default image
- the app must not fetch logos or photos from advertiser websites without advertiser action/consent

Configuration keys:

- `photo_library.source_url`
- `photo_library.source_path`
- `photo_library.category_folders`
- `photo_library.extensions`
- `photo_library.max_depth`

Namecheap deployment note:

- latest changed files were uploaded by FTP through `codex@polonads.com`
- this FTP account starts directly inside the remote `mailing_app` directory
- `config/app.php` was uploaded on 2026-05-26 with fixed `photo_library`
- `config/app.local.php` was not uploaded or overwritten
- image assignment can work from base config as long as local config does not override `photo_library`

Database state:

- table `listing_image_assignments` has already been created in `polonads_mailing`
- this table is intended to track image usage history per lead / campaign / category

Current implementation status:

- folder structure prepared on server
- SQL table prepared
- image selection logic is implemented in `mailing_app/src/ListingImageLibrary.php`
- production `config/app.php` has the fixed photo-library map
- image files themselves are still incomplete for many categories

## Current Test Lead

Use this lead for workflow tests:

- `lead_id = 1236`
- `company_name = BigSky Test Company`
- `primary_email = eskey69@gmail.com`

Do not switch to another test user unless explicitly asked.

## Latest Mailing Diagnostics - 2026-05-27

Production phpMyAdmin checks for `lead_id = 1236` showed repeated successful
mail sends to the same recipient:

- `email_send_attempts` contained 5 rows with `status = sent`
- all 5 sends went to `eskey69@gmail.com`
- subjects alternated between `Quick question about Verstela` and
  `Draft listing for Verstela`
- latest successful send was `2026-05-27 17:00:55`
- the `leads` row showed:
  - `approval_status = pending`
  - `contact_status = client_review`
  - `email_subject = Draft listing for Verstela`
  - `sent_at = 2026-05-27 17:00:55`
  - `send_attempts = 3`
  - `last_error` empty

Important finding:

- `email_send_attempts` is the reliable historical log for actual sends
- `leads.send_attempts` did not match the historical count in this case
- future duplicate-send protection should check for existing successful
  `email_send_attempts.status = sent` for the same `lead_id` before sending
  another non-follow-up email

Useful diagnostic query:

```sql
SELECT
  lead_id,
  recipient_email,
  COUNT(*) AS sent_count,
  MIN(created_at) AS first_sent_at,
  MAX(created_at) AS last_sent_at
FROM email_send_attempts
WHERE status = 'sent'
GROUP BY lead_id, recipient_email
HAVING COUNT(*) > 1
ORDER BY sent_count DESC, last_sent_at DESC;
```

## What Is Working

These parts are now working:

- CSV import into `mailing_app`
- lead dashboard and workflow editor
- SMTP sending from `mailing_app`
- real delivery to Gmail after fixing line-length transport issues
- Joomla user create-or-reuse
- DJCF profile create-or-update
- DJCF item create-or-update
- item deduplication
- draft preparation in Polonads before approval mail
- publication after acceptance
- publication logs
- `polonads_published_v1` insertion after publication
- fixed DJCF publication dates:
  - `date_start`
  - `date_mod`
  - `date_sort`
  - `date_exp = +62 days`
- starter bonus for new users:
  - `50` points in `jost3_djcf_users_points`
- category sync for listings:
  - `jost3_djcf_items_categories`

## Important Workflow Decisions

These are current hard decisions and should be treated as project rules:

1. Email drafts and listing drafts are separate things.
   - `email_subject`
   - `email_draft`
   - `email_final`
   belong only to mailing.

2. Listing content must not be derived from email content.
   Listing draft lives in `personalization_data` under keys such as:
   - `listing_title`
   - `listing_body`
   - `listing_language`
   - `listing_images`
   - `listing_source_urls`

3. Before sending the draft-approval email, the listing must already exist in Polonads as a draft/unpublished item.

4. `Review and edit listing` must open the real Polonads item URL:
   - `https://polonads.com/index.php/en-us/dodaj-ogloszenie-uslugi-2/{ID}`

5. We do not rely on changing server config for email links.
   Important Polonads URLs are hardcoded in mail template logic.

6. Campaign category is the source of truth for final listing category.
   Do not treat per-template mail flow as category selection.

7. Listing image library must remain separate from DJ-Classifieds internals.
   Public `foto/...` URLs are the intended source for campaign-assigned images.

## Current Mailing Logic

The intended business flow is:

1. First informational email
   - free 2-month test
   - no pricing
   - options:
     - send me a draft
     - I prefer to publish it myself
     - contact me later
     - not interested

2. Second email depends on the choice from email 1
   - draft path
   - self-publish path
   - undecided path
   - opt-out path

3. Draft path
   - account exists or is created
   - AI or simulation prepares listing draft
   - draft listing is saved separately from email
   - draft listing is inserted into Polonads as unpublished item
   - client receives draft-review email

4. After client approval
   - existing draft item is published
   - thank-you / published email is sent

5. Undecided path
   - later follow-up after 2-3 days

## Email / UI Notes

Current email behavior:

- HTML + plain text sending works
- buttons work
- links must match the buttons
- intro mail and interest-reply mail have already been visually cleaned up
- `Campaign: ...` must never appear in customer-facing emails

Current campaign UI behavior:

- `campaigns.php` is now simplified
- operator should set campaign name + final DJ-Classifieds category there
- campaign id can be auto-derived from campaign name if left blank
- lead screen should display:
  - active publication category
  - optional old import suggestion as helper only

Important interpretation:

- if lead screen shows import suggestion `Uslugi (4)` but active publication category is `Dam prace (3)`, that is correct
- publication must follow active campaign category, not import suggestion

## AI Module Integration

The app is now being prepared for a separate AI generator module.

Assumption:

- future AI module will most likely be in Python
- `mailing_app` will remain the workflow/orchestration app

Current direction:

- AI module receives structured lead payload
- AI module returns full listing draft data
- communication format: JSON

Implemented concept:

- export endpoint:
  - `mailing_app/public/ai_export.php`
- import endpoint:
  - `mailing_app/public/ai_import.php`
- local simulation service:
  - `mailing_app/src/AiDraftExchangeService.php`

Current payload expectation from AI:

- `title`
- `body`
- `language`
- `images`
- `source_urls`

Latest implementation note:

- Python AI stub in `collector/ai_module/` has been extended with:
  - `config.py`
  - `schemas.py`
  - `openai_generator.py`
- `polonads_ai_client.py` now supports:
  - `--mode stub`
  - `--mode openai`
  - `--model gpt-5-mini`
- client can now also auto-build signed export URL from:
  - `--lead-id`
  - `--base-url`
  - `MAILING_APP_RESPONSE_SECRET`
- OpenAI key is expected outside repo, e.g. `~/secrets/openai.env`
- current OpenAI schema also includes:
  - `visual_subtype`

Current server-side secret expectation:

- `~/secrets/openai.env` should contain:
  - `OPENAI_API_KEY=...`
  - `MAILING_APP_RESPONSE_SECRET=...`

Current Python/runtime note:

- hosting server uses Python 3.6
- AI module files were downgraded for Python 3.6 compatibility
- if AI module is updated again, keep Python 3.6 compatibility in mind unless hosting runtime changes

Current execution note:

- `collector` and `mailing_app` are deployed side by side under `public_html`
- AI client should be run from `~/public_html`
- example command:
  - `python3 -m collector.ai_module.polonads_ai_client --lead-id 1236 --base-url "https://polonads.com/mailing_app/public" --source "python-openai" --mode openai --model "gpt-5-mini"`

Latest blocker encountered:

- AI client now reaches OpenAI, but current run stopped with:
  - `HTTP error: 429 Too Many Requests`
- likely cause:
  - missing/insufficient API credits or project quota
- operator plans to add credits and return to test later

For now, draft generation can be simulated locally from lead data.

## Important Files

These files are especially important for the current architecture:

- `mailing_app/src/AiDraftExchangeService.php`
- `mailing_app/src/PublicationPayloadBuilder.php`
- `mailing_app/src/PublicationService.php`
- `mailing_app/src/PolonadsPublicationGateway.php`
- `mailing_app/src/MailTemplateFactory.php`
- `mailing_app/src/LeadRepository.php`
- `mailing_app/src/Support.php`
- `mailing_app/public/lead.php`
- `mailing_app/public/ai_export.php`
- `mailing_app/public/ai_import.php`

## Known Open Work

Still open:

- clean full end-to-end workflow for all branch paths
- follow-up email for undecided users after 2-3 days
- self-publish branch final polish
- final DMARC alignment check
- real Python AI module implementation
- image selection / category illustration workflow
- further simplify operator panels where still too technical
- implement automatic image selection from `foto/{category}` library
- write back selected image path/url into lead metadata
- connect image assignment step with AI listing generation flow
- retry OpenAI generation after credits/quota are enabled

## Important Rule For Next Codex

Do not rebuild the whole project.

Extend `mailing_app` evolutionarily.

Do not mix:

- mailing draft content
- listing draft content

If draft content looks duplicated or polluted, inspect:

- `PublicationPayloadBuilder.php`
- `MailTemplateFactory.php`
- `lead.php`

first.

If campaign/category behavior looks wrong, inspect first:

- `public/campaigns.php`
- `public/lead.php`
- `src/PublicationPayloadBuilder.php`
- `src/MailTemplateFactory.php`

The intended rule is simple:

- campaign chosen once
- category chosen once for that campaign
- all approved listings from that campaign publish into that category

If image-library behavior is being implemented or debugged, inspect first:

- `polonads_mailing.listing_image_assignments`
- future image-selection service/module in `mailing_app`
- `personalization_data` fields for assigned image path/url
