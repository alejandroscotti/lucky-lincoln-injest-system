# Strict review checklist

Generic audit list. Pair with [common-violations.md](common-violations.md). For repo-specific conventions, read `.cursor/rules/*.mdc` and `AGENTS.md` separately — do not conflate them with universal standards.

Use every section for repo-wide audits. For narrow PRs, skip irrelevant sections and note "N/A".

## 1. Reference data & single source of truth

- [ ] No lookup/taxonomy/catalog tables hardcoded in application code
- [ ] No application startup INSERT/upsert of reference rows
- [ ] Bootstrap/reference data in migrations or admin paths — not app init
- [ ] No duplicated enum vocabulary across code, config, and DB without FK/reference table
- [ ] Test fixtures isolated; not imported by production code

## 2. Database schema

- [ ] Normalized design — no unjustified redundant columns
- [ ] Primary keys explicit; natural composite keys where appropriate
- [ ] `NOT NULL` on required fields
- [ ] FKs on logical relationships with deliberate `ON DELETE` / `ON UPDATE`
- [ ] Enumerated values via reference table + FK or documented CHECK — not scattered magic strings
- [ ] Indexes on FK and filter/join columns
- [ ] Canonical schema and migrations in sync
- [ ] Migration SQL valid and idempotent where required

## 3. Application layer

- [ ] No `any`; validated types at boundaries (DB, HTTP, JSON, LLM)
- [ ] Fail fast on missing config/cache — no silent empty defaults for required reference data
- [ ] No dead code, orphan modules, or commented-out blocks
- [ ] Minimal diff scope — no unrelated refactors
- [ ] Actionable error messages
- [ ] Secrets in env only; example env documented

## 4. Data access

- [ ] Parameterized queries only
- [ ] Transactions for atomic multi-step writes
- [ ] Persistence in data layer; orchestration in services
- [ ] Bounded reads — no unbounded full-table loads

## 5. Security

- [ ] No secrets in source
- [ ] Input sanitized at boundaries
- [ ] No injection vectors (SQL, shell, XSS)

## 6. Tests & verification

- [ ] Project typecheck/lint passes (discover from package.json / CI)
- [ ] Project tests pass
- [ ] New behavior has tests
- [ ] Tests use fixtures, not production reference data in code

## 7. Hygiene

- [ ] No orphan one-off scripts without purpose
- [ ] Docs updated when public contracts change
- [ ] Naming consistent with existing codebase conventions

## Verdict rules

- **FAIL** — any P0, or 3+ P1 in scope
- **PASS WITH REQUIRED FIXES** — P1 present, no P0
- **PASS** — at most P2/P3 in scope
