# TODO

## Current Priorities

- fix DJ-Classifieds publication state so listings meant to go live do not land as unpublished records
- implement native DJ-Classifieds image assignment, including file creation, thumbnail handling, and rows in `#__djcf_images`
- render bilingual EN/PL listing content as real HTML sections or paragraphs instead of plain text blocks
- rerun a controlled end-to-end publish test after the three fixes above

## Workflow Backlog

- finish branch coverage for the full workflow: `intro`, `followup`, `ai_draft`, `review`, `translation_review`, `send`, `publish`
- add the undecided/contact-later follow-up path after 2-3 days
- polish the self-publish branch and simplify operator-facing panels where they are still too technical
- complete the final DMARC and delivery-alignment check before wider live sending

## Product And Integration Backlog

- implement Joomla client account creation with reset/set-password flow instead of mailing plain passwords
- support `pl`, `en`, and `bilingual` listing modes as first-class workflow options
- improve category illustration and image-library selection logic written back to lead metadata
- retry and verify OpenAI generation in production once credits/quota are available again

## Operational Rules

- keep local runtime files and secrets out of Git: `config/app.php`, `config/app.local.php`, upload/runtime artifacts, and server logs stay local only
- keep listing draft content separate from `email_draft` and `email_final`
- treat `docs/DJ_CLASSIFIEDS_LOGIC.md` as the technical reference before changing publication, images, or Joomla account integration

## References

- `docs/DJ_CLASSIFIEDS_LOGIC.md`
- `docs/HANDOFF_HOME_PC.md`
- `docs/CODEX_MAILING_APP_HANDOFF_2026-04-28.md`
- `src/PublicationService.php`
- `src/PolonadsPublicationGateway.php`
- `src/LeadRepository.php`