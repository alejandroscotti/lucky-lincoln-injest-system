# Common violations — industry standards

Universal anti-patterns the strict review agent must catch. **Not project-specific.** Derive fixes from the codebase under review; do not assume a particular stack beyond what you observe.

Severity guide: most items here are **P0** (data integrity, security, correctness) or **P1** (maintainability, single source of truth).

---

## 1. Reference data & configuration

| Violation | Why it fails | Fix |
|-----------|--------------|-----|
| Lookup catalogs hardcoded in application code (arrays, maps, const objects) | Code becomes the database; deploys required to change reference data | Reference table + migration/bootstrap + read-only runtime load |
| Application startup INSERT/upsert of reference rows | Hidden seed sync; non-idempotent; environment drift | One-time migrations or admin tooling; runtime read-only |
| Same vocabulary in code + config + DB CHECK without FK | Drift guaranteed; invalid states possible | Reference table; FK or validated load from single store |
| `*-seed-data.ts` / JSON master lists for domain taxonomy | Versioned app code for data that belongs in the datastore | DB or dedicated data service as source of truth |
| Conditional re-seed (`if version < N then upsert`) | Racey; masks missing migrations | Schema migrations with explicit versioning |

**Grep signals:** `seedData`, `KNOWN_*`, `INSERT INTO` in startup/init, `upsert.*(Taxonomy|Lookup|Reference)`, large static slug/id arrays.

**Acceptable:** Operational config (URLs, timeouts, feature flags) — not canonical domain vocab. Algorithm constants (thresholds). Test-only fixtures never imported by production.

**NOT acceptable — no parameterizing single-path code (P0):** A function/script/service with one real call path must not gain env vars, CLI flags, optional params, or `options` objects. No default args on helpers only called one way. No `readNumber(process.env.FOO, 50)` for constants that never change. **No parameterizing single-path code** — if there is no second caller with a different value, hardcode the literal or use established SSoT (`config/*.json`, DB policy table). See `.cursor/rules/project-memory.mdc` § No parameterizing single-path code.

**Grep signals:** `process.env.[A-Z_]+` for tuning; `options\?:`; default args on internal-only exports; `--dry-run`, `--max-`, `CONCURRENCY`, `POOL_MAX`.

---

## 2. Database design & normalization

| Violation | Why it fails | Fix |
|-----------|--------------|-----|
| Unnormalized redundant columns | Update anomalies; inconsistent reads | Normalize; derive or document denormalization with triggers/materialized views |
| JSON/JSONB blob for fields that are relational entities | Unqueryable; no FK integrity; schema evolution pain | Proper tables and FKs |
| Missing FK on obvious parent-child relationship | Orphans; no referential integrity | `REFERENCES` with explicit `ON DELETE` / `ON UPDATE` |
| String enum in CHECK duplicated in app types | Two sources of truth | Reference table + FK, or generated types from schema |
| Required business fields nullable | Invalid rows persist | `NOT NULL` + defaults only where semantically correct |
| Missing index on FK or high-cardinality filter column | Full table scans at scale | Index on join/filter columns |
| Ad-hoc status/state as unconstrained TEXT | Garbage values | CHECK, enum table, or FK |
| Migration SQL syntax errors or missing terminators | Broken deploys | Valid SQL; test migrate |
| DDL and canonical schema out of sync | Fresh installs differ from migrated DB | Single source: migrations update canonical schema |

---

## 3. Data access & queries

| Violation | Why it fails | Fix |
|-----------|--------------|-----|
| String-interpolated SQL | Injection; plan cache busting | Parameterized queries |
| Multi-step writes without transaction | Partial failure corrupts state | `BEGIN`/`COMMIT` or equivalent |
| Unbounded `SELECT *` into memory | OOM; latency | Pagination, limits, streaming |
| N+1 query pattern in loops | Performance collapse | Batch/join/eager load |
| Repository SQL in handlers/controllers | Layer violation; untestable | Data layer owns persistence |

---

## 4. Application code quality

| Violation | Why it fails | Fix |
|-----------|--------------|-----|
| `any` or unchecked external input | Runtime surprises; security holes | Narrow types; validate at boundaries |
| `JSON.parse` / API/LLM response used without validation | Silent bad data propagation | Schema validation / parser with errors |
| Empty `catch` or swallowed errors | Failures invisible | Log + rethrow or typed error |
| Silent fallback when config/cache missing | Misconfiguration ships to prod | Fail fast with actionable error |
| Dead code, orphan modules, commented-out blocks | Noise; false confidence | Delete |
| Drive-by refactors in unrelated files | Review burden; regression risk | Minimal scoped diff |
| Duplicated logic (copy-paste) | Divergent fixes | Extract shared function/module |
| God file / god function | Untestable; unclear ownership | Split by responsibility |
| Magic numbers/strings without named constant | Unreadable; scattered changes | Named constants at point of use |
| One-off scripts committed without purpose | Repo clutter | Delete or promote to maintained tooling |
| Parameterizing single-path code (env vars, optional params, CLI flags, `options` bags) | Pollution; false flexibility | No parameterizing single-path code — remove param; literal or SSoT |

---

## 5. Types & API contracts

| Violation | Why it fails | Fix |
|-----------|--------------|-----|
| Stringly-typed IDs/slugs without validation | Invalid references accepted | Validate against store or branded types |
| Public API shape changed without tests/docs | Breaking consumers | Tests + contract docs |
| Optional chaining masking required data | Undefined deep in call stack | Validate early; fail at boundary |
| Leaking internal types across module boundaries | Tight coupling | DTOs / explicit public types |

---

## 6. Security

| Violation | Why it fails | Fix |
|-----------|--------------|-----|
| Secrets in source or committed env files | Credential exposure | Env vars; secret manager; gitignore |
| Missing auth on mutating/sensitive endpoints | Unauthorized access | Auth middleware; least privilege |
| User input in shell commands or raw SQL | Command/SQL injection | Parameterization; allowlists |
| PII/secrets in logs | Compliance breach | Redact; structured logging |

---

## 7. Testing & verification

| Violation | Why it fails | Fix |
|-----------|--------------|-----|
| Behavior change without tests | Regressions undetected | Unit/integration test for changed behavior |
| Tests depending on production DB seed in code | Flaky; couples test to prod data | Fixtures/factories |
| Tests asserting implementation trivia | Brittle; false confidence | Assert observable behavior |
| Typecheck/lint failures in changed code | Broken build | Fix before merge |

**Run project verification commands when they exist** (`typecheck`, `test`, `lint`, `migrate`). Discover them from `package.json`, Makefile, or CI config — do not hardcode.

---

## 8. Architecture & layering

| Violation | Why it fails | Fix |
|-----------|--------------|-----|
| Business logic in UI/route handlers | Untestable; duplicated | Service/domain layer |
| Persistence logic in domain models | Coupling to ORM/SQL | Repository pattern |
| Circular module dependencies | Init order bugs; untestable | Invert dependency; shared interfaces |
| Feature flags as permanent branches | Dead paths accumulate | Remove or consolidate |

---

## Verdict rules

- **FAIL** — any P0, or 3+ P1 in scope
- **PASS WITH REQUIRED FIXES** — P1 present, no P0
- **PASS** — at most P2/P3 in scope

## Review discipline

- Every finding: `path:line`, violation category from this doc, concrete fix.
- Do not invent project-specific rules — read `.cursor/rules/*.mdc` and `AGENTS.md` for repo conventions **after** applying this generic bar.
- Zero findings → list categories checked.
