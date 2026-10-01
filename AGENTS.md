# AGENTS.md

**Using it:** follow `resources/boost/skills/sendseven-development/SKILL.md`. For SendSeven API calls, the core SDK's `AGENTS.md` and `openapi/manifest.json` apply.

**Changing it:**
- This package is glue: config, the container binding, webhooks, the rate limiter, commands, testing helpers. Anything that isn't Laravel-specific belongs in `reshapify/sendseven`.
- `composer test` must pass: Pint, Rector, PHPStan at its strictest level with Larastan and strict rules, 100% type coverage, and Pest.
- Classes are `final`, files `declare(strict_types=1)`, and exceptions implement `SendSevenException` and say how to fix the problem.
- Never print or log tokens or webhook secrets, except the one-time secret `sendseven:webhooks:register` exists to show.
- Keep the README, the Boost guideline and the skill in step with the code.
