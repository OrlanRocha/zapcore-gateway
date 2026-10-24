# E2 Dependency Hardening Plan

**Base:** `codex/security-ssrf-hardening` at `4692d37aa3ec225e2522eebb42f3834b855053b2`

**Implementation branch:** `codex/security-dependencies-e2`

## Scope

1. Capture the vulnerable `main` audit and the clean E1-derived baseline.
2. Add a regression policy test for safe transitive versions and reproducible Docker installation.
3. Add compatible npm overrides for `follow-redirects` and `music-metadata`; preserve the existing `qs` override.
4. Regenerate the lockfile without major dependency upgrades and change Docker installation to `npm ci`.
5. Verify clean installation, dependency tree, audit, Node tests, TypeScript build, PHPUnit, Composer audit, and E1 media-boundary tests.
6. Document evidence, compatibility, residual risk, and exact validation commands.
7. Review the final diff, commit only E2 files, and leave the branch unmerged and undeployed.

## Review Focus

- No vulnerable version remains anywhere in the resolved tree.
- Overrides remain within the version ranges accepted by Axios and Baileys.
- Baileys stays on 6.7.24; no authentication/session API migration is introduced.
- E1 continues rejecting remote media in the Node worker.
- The Windows symlink test remains explicitly reported as skipped, not passed.
