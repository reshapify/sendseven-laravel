# Contributing

Thanks for helping. Open an issue first for anything larger than a fix.

- **What goes where:** this package is the Laravel layer. API coverage and anything framework-agnostic belongs in [reshapify/sendseven](https://github.com/reshapify/sendseven-php).
- **The bar:** `composer test` must pass. It runs Pint, Rector, PHPStan at max level with Larastan, 100% type coverage, and Pest.
- **Behaviour changes** come with a test and a CHANGELOG entry. Keep the README, the Boost guideline and the skill in step.

[AGENTS.md](AGENTS.md) has the full conventions.
