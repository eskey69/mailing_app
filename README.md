# mailing_app

PHP + MariaDB application for importing leads, running outreach workflow,
reviewing AI-generated drafts, and publishing listings to Polonads / DJ-Classifieds.

## Scope

- CSV import into MariaDB
- lead dashboard and detail view
- approval and contact workflow management
- send queue and send-attempt history
- OpenAI draft exchange and translation support
- publication workflow for Polonads / DJ-Classifieds

## Structure

- `bin/` - CLI scripts for checks, workflow automation, queue sending, and publication
- `config/` - configuration examples and local overrides
- `database/` - schema and migrations
- `docs/` - handoff notes and integration documentation
- `public/` - web entrypoints and static assets
- `src/` - application code
- `storage/` - runtime storage such as uploads
- `bootstrap.php` - application bootstrap
- `OPENAI_SETUP.md` - OpenAI configuration notes

## Local Setup

1. Copy `config/app.example.php` to `config/app.php`.
2. Optionally copy `config/app.local.example.php` to `config/app.local.php`.
3. Import `database/schema.sql` into MariaDB.
4. Point your local web server at `public/`.

## Workflow Scripts

Examples:

```bash
php bin/check_openai.php --config-only
php bin/process_workflow.php --phase=intro --limit=50
php bin/process_workflow.php --execute --phase=ai_draft --limit=20
php bin/send_approved.php --limit=2
php bin/publish_ready.php --dry-run
```

## OpenAI

See:

- `OPENAI_SETUP.md`

## Documentation

Long-form handoff and integration notes live in:

- `docs/`

## Current Backlog

- `TODO.md`
