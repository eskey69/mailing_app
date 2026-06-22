# OpenAI Setup

`mailing_app` uses the OpenAI Responses API for listing draft generation and
AI-driven Polish translation.

## Configuration

Main application config:

- `config/app.php`

Optional local OpenAI overrides:

- `config/app.local.php`

The bootstrap loads `app.php` first and then merges `app.local.php` on top.
That keeps secrets out of the tracked example config.

Example:

```php
<?php

return [
    'ai' => [
        'openai_api_key' => 'OPENAI_API_KEY_HERE',
        'model' => 'gpt-5-mini',
        'organization' => '',
        'project' => '',
        'base_url' => 'https://api.openai.com/v1',
        'store' => false,
        'connect_timeout' => 15,
        'timeout' => 120,
    ],
];
```

## Environment Variables

- `OPENAI_API_KEY`
- `MAILING_APP_OPENAI_MODEL`
- `MAILING_APP_OPENAI_DRAFT_MODEL`
- `MAILING_APP_OPENAI_TRANSLATION_MODEL`
- `MAILING_APP_OPENAI_CHECK_MODEL`
- `OPENAI_MODEL`
- `OPENAI_ORGANIZATION`
- `OPENAI_PROJECT`
- `OPENAI_BASE_URL`
- `MAILING_APP_OPENAI_STORE`
- `MAILING_APP_OPENAI_CONNECT_TIMEOUT`
- `MAILING_APP_OPENAI_TIMEOUT`
- `MAILING_APP_OPENAI_MAX_RETRIES`
- `MAILING_APP_OPENAI_RETRY_BASE_DELAY_MS`
- `MAILING_APP_OPENAI_RETRY_MAX_DELAY_MS`
- `MAILING_APP_OPENAI_MAX_OUTPUT_TOKENS`
- `MAILING_APP_OPENAI_DRAFT_MAX_OUTPUT_TOKENS`
- `MAILING_APP_OPENAI_TRANSLATION_MAX_OUTPUT_TOKENS`
- `MAILING_APP_OPENAI_CHECK_MAX_OUTPUT_TOKENS`
- `MAILING_APP_OPENAI_REASONING_EFFORT`
- `MAILING_APP_OPENAI_DRAFT_REASONING_EFFORT`
- `MAILING_APP_OPENAI_TRANSLATION_REASONING_EFFORT`
- `MAILING_APP_OPENAI_CHECK_REASONING_EFFORT`
- `MAILING_APP_OPENAI_VERBOSITY`
- `MAILING_APP_OPENAI_DRAFT_VERBOSITY`
- `MAILING_APP_OPENAI_TRANSLATION_VERBOSITY`
- `MAILING_APP_OPENAI_CHECK_VERBOSITY`
- `MAILING_APP_OPENAI_PROMPT_CACHE_KEY`
- `MAILING_APP_OPENAI_DRAFT_PROMPT_CACHE_KEY`
- `MAILING_APP_OPENAI_TRANSLATION_PROMPT_CACHE_KEY`
- `MAILING_APP_OPENAI_CHECK_PROMPT_CACHE_KEY`

## Checks

Configuration-only check:

```bash
php bin/check_openai.php --config-only
```

Live authenticated API check:

```bash
php bin/check_openai.php
```

Inspect one lead payload and website context:

```bash
php bin/inspect_ai_lead.php --lead=1236
```

Inspect one lead and include a live OpenAI connection check:

```bash
php bin/inspect_ai_lead.php --lead=1236 --network-check
```

## Notes

- Requests use `Authorization: Bearer ...`.
- Optional `OpenAI-Organization` and `OpenAI-Project` headers are supported.
- Responses use JSON-schema output through `text.format`.
- Draft generation and translation support task-specific model and token settings.
- Requests use retry with backoff for transient OpenAI or network failures.
- GPT-5 requests include task-specific `reasoning.effort` and `text.verbosity`.
- `store` is configurable and defaults to `false` in the example local override.