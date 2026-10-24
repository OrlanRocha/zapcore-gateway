# E3 Docker and Secrets Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Harden Docker networking and secret loading while preserving the E1/E2 behavior and persistent data identities.

**Architecture:** A single secure Compose contract uses three least-privilege networks and read-only file secrets. PHP and Node share a strict `_FILE` loading contract; Redis is profile-gated with ACL, and optional MySQL host access exists only in a loopback override.

**Tech Stack:** Docker Compose, MySQL 8, Redis 7, PHP 8.2/PHPUnit, Node.js 20/TypeScript node:test.

**Spec:** `docs/superpowers/specs/2026-10-24-security-docker-secrets-e3-design.md`

## Global Constraints

- Base commit is `a6658c3f02de9f7deebb3ed4f901e13765ca2fa9`; branch is `codex/security-docker-secrets-e3`.
- Do not deploy, access VPS, rotate real credentials, change production data/sessions, merge, or remove volumes.
- Preserve the `zapcore_db_data` key and existing data/media/session mounts.
- Keep worker Internet egress and all E1/E2 controls.
- Docker-unavailable checks must remain reported as pending.

## Review Focus

- Missing, empty, unreadable, or conflicting secret inputs must stop startup without disclosing values.
- MySQL/Redis must have no host binding in the base Compose rendering.
- Worker must share application and data networks while retaining a non-internal network.
- Existing Compose project and `zapcore_db_data` identity must remain migration prerequisites.
- Optional Redis must not start by default and must not accept unauthenticated clients when enabled.

---

### Task 1: Shared secret-loading contracts

**Files:** create PHP and Node secret readers plus focused unit tests; modify PHP `App` and Node configuration startup.

- [ ] Write failing PHP and Node tests for direct values, `_FILE`, conflicts, missing, empty, non-file, and unreadable inputs.
- [ ] Run focused tests and confirm security failures.
- [ ] Implement minimal readers with redacted error messages and production configuration validation.
- [ ] Run focused and existing suites.

### Task 2: Secure Compose topology

**Files:** modify `docker-compose.yml`; create `docker-compose.mysql-admin.yml`, secret templates, and Compose policy tests.

- [ ] Write failing policy tests for public ports, hardcoded passwords, missing secrets, volume identity, service networks, Redis profile/ACL, and loopback-only admin binding.
- [ ] Confirm tests fail against the E2 Compose file.
- [ ] Implement three networks, file secrets, health-aware dependencies, profile-gated Redis, and loopback override.
- [ ] Run policy tests and parse the Compose document structurally.

### Task 3: Templates, ignore rules, and Linux CI validation

**Files:** update `.gitignore`, backend/worker env examples; create safe secret templates and `scripts/validate-compose-linux.sh`.

- [ ] Add tests that reject tracked secret values and unsafe example defaults.
- [ ] Add intentionally invalid templates and runtime fixture generation isolated from production resources.
- [ ] Add Linux commands for `docker compose config`, builds, connectivity, published-port checks, persistence restart, and cleanup of only the E3 test project.
- [ ] Verify shell syntax where tooling permits; document Docker as pending locally.

### Task 4: Architecture and migration runbooks

**Files:** create `docs/security/E3-docker-secrets-hardening.md` and `docs/security/E3-production-migration-runbook.md`; update relevant installation references.

- [ ] Document before/after topology, ports, networks, secrets, permissions, persistence, Redis status, E5/E6 dependencies, and evidence.
- [ ] Document inventory, backup/restore proof, initialized-volume password rotation, coordinated consumer update, stop gates, verification, and rollback.
- [ ] Verify documentation contains no usable credential.

### Task 5: Full verification and delivery

- [ ] Run `npm ci`, Node tests, TypeScript build, npm audits, PHPUnit, and Composer audit.
- [ ] Re-run E1/E2 policy and media tests.
- [ ] Inspect the complete base diff, scan for secrets, and confirm `wiki-seed/` remains untouched.
- [ ] Record Docker/Linux runtime checks as pending if unavailable.
- [ ] Commit as `fix(security): harden docker networking and secrets` without merge or deploy.
