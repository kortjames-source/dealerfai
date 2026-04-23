Legacy SQL files moved from repository root.

Why:
- Keep project root clean.
- Preserve one-off migration history for audit/recovery.

Moved on:
- 2026-02-16

Notes:
- Runtime code should not depend on specific legacy migration filenames.
- Current schema updates should be added under `secure/sql/` using dated filenames.
