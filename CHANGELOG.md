# Changelog — k9o/sdk (PHP)

## 1.1.0 — 2026-10-25

- Documentation rewritten with runnable examples and expected output; internal cross-references removed from the published sources. No change to the public API.
- Fixed: boolean filter values are sent as `true` / `false`. `eq('done', false)` previously sent an empty string.
- Error text for `update()` / `delete()` without an `id` filter no longer mentions a version or a roadmap.

## 1.0.0 — 2026-10-23

First public release. See the README for the full API overview.

### Requirements

- PHP 8.5.12 or later.
