# Revenue Ingestion and Reconciliation System

## Business Requirements Document

**LUCKY LINCOLN GAMING**

---

## 1. System Objectives

- Ingest daily Video Gaming Terminal (VGT) meter data.
- Reconcile physical route collections against terminal meters and state tax portal reports.

## 2. Core Operational Pillars

### API Design

- Semantic versioning (`/v1/`, `/v2/`) enforced across all machine-facing ingestion endpoints.
- Strict JSON schema validation contracts for all incoming payloads.
- Rate limiting applied at the API gateway layer to absorb concurrent data bursts from over 1,450 active VGTs.

### Concurrency and Transaction Isolation

- Serializable isolation level or pessimistic locking on reconciliation ledger updates to prevent race conditions during concurrent collection entries.
- Optimistic concurrency control on master terminal configurations to manage simultaneous operational updates.

### Data Lifecycle and Archival

- 12-month hot storage retention policy for high-frequency terminal session data.
- Automatic partitioning and migration of historical records to cold storage to comply with state gaming board retention mandates.

### Data Models

- **Location Ledger:** Location_ID, Business_Name, Revenue_Split_Percentage. Incorporates statutory allocations: 32.04% Location, 32.04% Operator, 30% State, 5% Municipality, and 0.92% Light & Wonder.
- **Terminal Inventory:** Terminal_ID, Location_ID, Manufacturer, Status.
- **Session Data:** Append-only event sourcing architecture capturing Session_ID, Terminal_ID, Timestamp, Funds_In, Funds_Out, and Net_Terminal_Income.
- **Reconciliation Ledger:** Date, Location_ID, Expected_Drop, Actual_Drop, Variance_Amount, Resolution_Status.

### Disaster Recovery

- High-availability database replication across isolated availability zones.
- Recovery Point Objective (RPO) of < 15 minutes via automated transaction log backups.
- Recovery Time Objective (RTO) of < 2 hours with automated failover mechanics.

### Error Handling

- Divert malformed JSON payloads to a Dead Letter Queue (DLQ) utilizing exponential backoff.
- Automated discrepancy alerts generated when Variance_Amount exceeds an acceptable threshold.
- Circuit Breaker pattern on external dependencies to queue requests during state portal downtime.

### Idempotency

- Unique composite keys (Terminal_ID + Timestamp + Read_Type) on ingestion endpoints to automatically discard duplicate payloads.
- Idempotency keys attached to all POST requests from field collection tablets to prevent multi-counting during network drops.

### Observability

- Centralized logging aggregating API requests, ingestion status, and processing durations.
- Real-time metrics dashboard tracking ingestion throughput, queue depths, and error rates.
- Distributed tracing across ingestion layers to pinpoint pipeline bottlenecks.

### Scale

- Event-driven architecture and message queues to decouple terminal ingestion from backend database writes.
- Temporal partitioning on historical ledger data to optimize query speeds during multi-year audits.
- Asynchronous background workers to offload heavy reconciliation calculations.

### Security and Compliance

- End-to-end encryption for data in transit (TLS 1.3) and at rest (AES-256).
- Role-Based Access Control (RBAC) separating field collectors, auditors, and administrators.
- Immutable audit logs capturing every user modification, transaction reversal, and ledger override.

## 3. Multi-Product Future

The organization’s roadmap includes additional products—such as an AI-powered call center and a retail operating system—that must share the same financial truth without duplicating logic or forcing a rewrite of route revenue capabilities.

### Product Boundaries

- **Shared financial core:** Authoritative location catalog, daily financial totals, reconciliation outcomes, and submission audit history. Other products consume these facts; they do not redefine them.
- **Route revenue product:** Nightly VGT ingestion, validation, discrepancy detection, and operator workflows specific to gaming route operations. Gaming-specific rules and partner submission behavior remain here.

### Cross-Product Contracts

The shared core exposes stable business capabilities that future products may rely on:

- **Ingestion:** A standardized submission envelope (location, business date, expected completeness, duplicate protection) so any product can record financial activity without corrupting totals.
- **Reconciliation:** Location-day comparison of expected versus actual amounts, variance classification, and resolution status.
- **Audit:** Traceability of every submission attempt, including partial failures and resubmissions.

Future products must integrate through these contracts—not by copying route revenue business rules into their own systems.

### Future Product Integration

| Product | Relationship to shared core |
|---|---|
| **AI call center** | Read-only access to reconciliation exceptions, shortfalls, and submission history to support operator inquiries. Does not ingest or alter financial totals. |
| **Retail operating system** | Own source-specific ingestion for retail locations, using the same submission and idempotency standards. Posts into the shared ledger; reconciliation logic is reused, not reimplemented. |

### Shared vs. Product-Specific

| Shared (financial core) | Route revenue only |
|---|---|
| Location master data and daily location totals | Terminal inventory and gaming meter semantics |
| Expected-vs-actual reconciliation and variance status | Partner submission simulation and gaming-specific fault categories |
| Submission audit and duplicate protection | Route operator dashboards and reconciliation workflows |

### Current Scope and Extraction Path

The present release delivers route revenue ingestion and reconciliation as a cohesive capability. It validates the shared ledger concepts above in operation. Extraction of a standalone financial core service is a later phase; the priority now is proving that location-day reconciliation and audit patterns are product-agnostic enough for call center and retail adoption without architectural rework.
