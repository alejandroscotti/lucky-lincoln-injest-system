<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DiagramService
{
    /** @var array<string, int> */
    private const TABLE_COLUMN_LIMIT = [
        'location_daily_files' => 12,
        'import_batches' => 14,
        'revenue_records' => 12,
    ];

    public static function truncateDescription(string $desc, int $max = 150): string
    {
        return strlen($desc) <= $max ? $desc : substr($desc, 0, $max - 3).'...';
    }

    /** @return list<array<string, mixed>> */
    public static function staticDiagrams(): array
    {
        return [
            [
                'type' => 'idempotency',
                'title' => 'Submission Idempotency Strategy',
                'description' => 'Authoritative import reference: file key LOC-042-2026-06-20, mandatory HTTP headers, envelope gate before DB writes, canonical location_daily_files, upsert/skip per machine, completion when present_machines equals expected_record_count. Reference catalog is loaded by migration SQL; locations-feed POSTs each location with x-source LOC-xxx.',
                'descriptionMax' => 500,
                'mermaid' => self::idempotencyMermaid(),
            ],
            [
                'type' => 'architecture',
                'title' => 'System Architecture',
                'description' => 'Single Railway/Docker container: serve + migrate/seed (reference data) + schedule:work + bootstrap locations-feed. Every location submits via POST /api/revenue/import; import_batches.source stores LOC-xxx.',
                'descriptionMax' => 320,
                'mermaid' => self::architectureMermaid(),
            ],
            [
                'type' => 'api',
                'title' => 'REST API Layer',
                'description' => 'All routes from routes/api.php. Ingestion: POST /api/revenue/import only. Reads: dashboard, submissions, reconcile, locations, faults, reports, diagrams. Locations feed uses the same GET endpoints to discover locations and hydrate state.',
                'descriptionMax' => 320,
                'mermaid' => self::apiMermaid(),
            ],
            [
                'type' => 'import_sequence',
                'title' => 'Import Request Lifecycle',
                'description' => 'Every persisted location submits via HTTP POST /api/revenue/import through the API layer (x-source = location_id): envelope validation, idempotency, upsert loop, reconciliation, completion; Vue polls GET /api/submissions.',
                'descriptionMax' => 280,
                'mermaid' => self::importSequenceMermaid(),
            ],
            [
                'type' => 'locations_feed',
                'title' => 'Locations Feed — Simulated Partner Submissions',
                'description' => 'Prototype driver: LocationsFeedCommand loads every persisted location from the API, builds deterministic payloads with FaultSimulation, and POSTs each file through LocationsFeedApiClient (x-source = location_id). Scheduled daily 00:00 UTC, resubmit every 15m, plus bootstrap on container start.',
                'descriptionMax' => 400,
                'mermaid' => self::locationsFeedMermaid(),
            ],
            [
                'type' => 'data_model',
                'title' => 'Core Data Model — Idempotency Tables',
                'description' => 'location_daily_files is the canonical per location-day file record; import_batches logs every attempt including failed validation; revenue_records stores per-machine upserts linked to audit batches.',
                'descriptionMax' => 280,
                'mermaid' => self::dataModelMermaid(),
            ],
        ];
    }

    public function buildSchemaDiagram(): string
    {
        return Cache::remember('schema_diagram_mermaid_v2', 60, function () {
            $tables = DB::select(
                "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
                 AND TABLE_NAME NOT IN ('seed_runs', 'migrations')
                 ORDER BY TABLE_NAME",
            );

            $lines = ['erDiagram'];

            foreach ($tables as $tableRow) {
                $table = $tableRow->TABLE_NAME;
                $cols = DB::select(
                    'SELECT COLUMN_NAME, COLUMN_KEY, DATA_TYPE, IS_NULLABLE
                     FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                     ORDER BY ORDINAL_POSITION',
                    [$table],
                );
                $limit = self::TABLE_COLUMN_LIMIT[$table] ?? 8;
                $lines[] = "  {$table} {";
                foreach (array_slice($cols, 0, $limit) as $c) {
                    $lines[] = self::mermaidErAttribute(
                        (string) $c->DATA_TYPE,
                        (string) $c->COLUMN_NAME,
                        (string) $c->COLUMN_KEY,
                    );
                }
                if (count($cols) > $limit) {
                    $lines[] = '    string _plus_'.(count($cols) - $limit).'_more_columns';
                }
                $lines[] = '  }';
            }

            $fks = DB::select(
                'SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
                 ORDER BY TABLE_NAME, COLUMN_NAME',
            );

            foreach ($fks as $fk) {
                $label = "{$fk->COLUMN_NAME}_fk";
                $lines[] = "  {$fk->REFERENCED_TABLE_NAME} ||--o{ {$fk->TABLE_NAME} : {$label}";
            }

            return implode("\n", $lines);
        });
    }

    /** @return array<string, mixed> */
    public function getMermaidDiagrams(?string $type = 'all', ?string $format = 'json'): array
    {
        $result = [];

        foreach (self::staticDiagrams() as $d) {
            if ($type === 'all' || $type === $d['type']) {
                $max = $d['descriptionMax'] ?? 150;
                $result[] = [
                    ...$d,
                    'description' => self::truncateDescription($d['description'], $max),
                ];
            }
        }

        if ($type === 'schema' || $type === 'all') {
            try {
                $result[] = [
                    'type' => 'schema',
                    'title' => 'Database Schema (ER — live)',
                    'description' => self::truncateDescription(
                        'Live ER diagram generated from INFORMATION_SCHEMA: all application tables, primary keys, and foreign-key relationships including location_daily_files and import_batches idempotency columns.',
                        250,
                    ),
                    'mermaid' => $this->buildSchemaDiagram(),
                ];
            } catch (\Throwable $e) {
                if ($type === 'schema') {
                    throw $e;
                }
                $result[] = [
                    'type' => 'schema',
                    'title' => 'Database Schema (ER — live)',
                    'description' => 'Unavailable until database is connected.',
                    'mermaid' => "flowchart LR\n  db[\"Database unavailable\"]\n  err[\"{$this->escapeMermaid($e->getMessage())}\"]",
                    'unavailable' => true,
                ];
            }
        }

        return [
            'diagrams' => $result,
            'raw' => ($format === 'raw' && count($result) === 1) ? $result[0]['mermaid'] : null,
        ];
    }

    private function escapeMermaid(string $text): string
    {
        return str_replace(['"', "\n", "\r"], ["'", ' ', ' '], substr($text, 0, 120));
    }

    private static function mermaidErAttribute(string $dataType, string $columnName, string $columnKey = ''): string
    {
        $type = self::mermaidErType($dataType);
        $name = preg_replace('/[^a-zA-Z0-9_]/', '_', $columnName) ?: 'col';
        $suffix = $columnKey === 'PRI' ? ' PK' : ($columnKey === 'MUL' ? ' FK' : '');

        return "    {$type} {$name}{$suffix}";
    }

    private static function mermaidErType(string $sqlType): string
    {
        $base = strtolower(preg_replace('/\(.*/', '', $sqlType));

        return match ($base) {
            'bigint', 'int', 'integer', 'tinyint', 'smallint', 'mediumint' => 'int',
            'decimal', 'numeric', 'float', 'double', 'real' => 'decimal',
            'date', 'datetime', 'timestamp', 'time', 'year' => 'string',
            'enum', 'set', 'json' => 'string',
            default => 'string',
        };
    }

    private static function idempotencyMermaid(): string
    {
        return <<<'MERMAID'
flowchart TB
  subgraph keyContract ["1 — File identity contract"]
    K1["Idempotency key: LOC-042-2026-06-20"]
    K2["Built by fileKey location_id + report_date"]
    K3["Parsed by parseIdempotencyKey regex LOC-ddd-YYYY-MM-DD"]
    K4["Kind is separate: x-submission-kind daily or resubmit"]
    K5["Same key for valid daily and valid resubmit of one file"]
    K1 --> K2 --> K3 --> K4 --> K5
  end

  subgraph headers ["2 — Mandatory HTTP headers every POST"]
    H1["x-idempotency-key"]
    H2["x-submission-kind daily or resubmit"]
    H3["x-expected-record-count"]
    H4["x-location-id"]
    H5["x-report-date"]
    H6["x-source location id e.g. LOC-042"]
  end

  subgraph submitters ["3 — Submitters — identical HTTP contract"]
    REAL["Production: real partner HTTPS"]
    FEED["Prototype: locations-feed per LOC-xxx"]
    REAL --> POST
    FEED --> POST
  end

  POST["POST /api/revenue/import"] --> GATE["validateSubmissionEnvelope — before any DB write"]

  keyContract --> POST
  headers --> POST

  GATE --> G1{"Key parses?"}
  G1 -->|no INVALID_IDEMPOTENCY_KEY| FAIL
  G1 --> G2{"Key loc and date match x-location-id and x-report-date?"}
  G2 -->|no LOCATION or REPORT_DATE_KEY_MISMATCH| FAIL
  G2 --> G3{"Every record.report_date equals key date?"}
  G3 -->|no RECORD_DATE_MISMATCH| FAIL
  G3 --> G4{"x-expected-record-count equals body.length?"}
  G4 -->|no RECORD_COUNT_MISMATCH| FAIL

  FAIL["import_batches status=failed counts=0"] --> R400["HTTP 400 validation_failed"]
  R400 --> NP["No location_daily_files change"]
  R400 --> NR["No revenue_records change"]

  G4 -->|ok| HASH["hashPayload SHA-256 sorted machines"]
  HASH --> CANON{"location_daily_files for loc + date?"}

  CANON -->|missing daily| NEW["INSERT in_progress expected_record_count payload_hash"]
  CANON -->|in_progress same hash| GAP["Resume gaps same payload"]
  CANON -->|complete same hash daily| SKIP["Replay all rows skipped"]
  CANON -->|complete same hash resubmit| AUDIT["Audit batch all skipped"]
  CANON -->|complete different hash daily| E409A["409 DAILY_FILE_ALREADY_ACCEPTED"]
  CANON -->|in_progress different hash| E409B["409 FILE_PAYLOAD_CONFLICT"]

  NEW --> LOOP
  GAP --> LOOP
  SKIP --> LOOP
  AUDIT --> LOOP

  subgraph loop ["3 — Per-record upsert plus skip NO loc/day rewrite"]
    LOOP["processOneRecord each row"]
    LOOP --> V["validateImportRecord net and faults"]
    V --> U{"Row unchanged?"}
    U -->|yes| SK["skipped"]
    U -->|no| UP["imported or updated"]
    SK --> RC
    UP --> RC["recomputeReconciliation per affected loc-day"]
  end

  RC --> DONE{"present_machines equals expected_record_count AND errors=0?"}
  DONE -->|yes| COMP["canonical status complete"]
  DONE -->|no| PART["canonical status in_progress"]
  COMP --> OK["completion.is_complete true"]
  PART --> RETRY["completion.is_complete false locations-feed retries"]

  subgraph locationsFeedOps ["4 — locations-feed operations"]
    F0["LocationsFeedApiClient POST x-source LOC-xxx"]
    F1["GET /api/locations/options + /machines"]
    F2["seededRngForLocationDate + FaultSimulation"]
    F3["Daily stagger retry until is_complete"]
    F4["Resubmit cached body or invalid-date ~10pct"]
    F5["Schedule: 00:00 UTC daily + every 15m resubmit"]
    F6["Bootstrap --daily on container start"]
    F0 --> F1 --> F2 --> F3
    F5 --> F0
    F6 --> F0
    F4 --> F0
    F4 --> FAIL
  end

  FEED --> F0
MERMAID;
    }

    private static function architectureMermaid(): string
    {
        return <<<'MERMAID'
flowchart TB
  subgraph clients ["Clients"]
    USER["Browser operators"]
    URL["luckylincoln.xyz or localhost:18430"]
    USER --> URL
  end

  subgraph deploy ["Docker / Railway — single app container"]
    subgraph entry ["entrypoint.sh startup"]
      SERVE["php artisan serve :PORT"]
      MIG["migrate + reference_data.sql"]
      SCH["php artisan schedule:work"]
      BOOT["locations-feed:run --daily bootstrap"]
      SERVE --> MIG --> SCH --> BOOT
    end

    subgraph feed ["Locations feed — HTTP client per location"]
      LFC["LocationsFeedCommand"]
      LFA["LocationsFeedApiClient"]
      LFC --> LFC1["GET locations + machines"]
      LFC1 --> LFC2["build batch + headers"]
      LFC2 --> LFA
    end

    SCH -->|"00:00 UTC daily + 15m resubmit"| LFC
    BOOT --> LFC

    subgraph app ["Laravel app"]
      SPA["Vue 3 SPA public/"]
      API["REST /api/*"]
      IMPCTRL["RevenueImportController POST /revenue/import"]
      SERVE --> SPA
      SERVE --> API
      API --> IMPCTRL
    end

    subgraph domain ["Services"]
      IMP["ImportService"]
      ENV["SubmissionEnvelopeService"]
      SUB["SubmissionsService"]
      DASH["DashboardService"]
      REC["ReconcileService"]
      LOC["LocationsService"]
    end

    subgraph mysql ["MySQL 8 revenue_db"]
      REF["locations machines game_types expected_totals"]
      RUN["location_daily_files import_batches revenue_records transaction_faults reconciliation_results"]
    end

    MIG --> REF
    LFA -->|"POST x-source LOC-xxx"| IMPCTRL
    IMPCTRL --> ENV --> IMP
    API --> domain
    domain --> RUN
    LFA -.->|"read location list"| API
    URL -->|"GET SPA + /api"| SERVE
    USER -->|"poll dashboard submissions"| API
  end
MERMAID;
    }

    private static function locationsFeedMermaid(): string
    {
        return <<<'MERMAID'
flowchart LR
  subgraph seed ["Reference data — migration SQL"]
    SD["database/sql/reference_data.sql"]
    SG["game_types machines expected_totals"]
    SD --> SG
    SG --> DBREF[("locations + machines")]
  end

  subgraph trigger ["Triggers"]
    T1["Container bootstrap"]
    T2["schedule:work 00:00 UTC"]
    T3["schedule:work every 15m"]
  end

  subgraph cmd ["LocationsFeedCommand"]
    C0["getLocationGroups via API"]
    C1["buildLocationBatch seededRng + FaultSimulation"]
    C2["postImport HTTP"]
    C3["retry until completion.is_complete"]
    C4["cache submissions for resubmit"]
    C0 --> C1 --> C2 --> C3
    C3 --> C4
  end

  subgraph http ["LocationsFeedApiClient"]
    H1["x-source = location_id"]
    H2["x-submission-kind daily or resubmit"]
    H3["x-idempotency-key LOC-ddd-YYYY-MM-DD"]
    H4["POST /api/revenue/import"]
    H1 --> H4
    H2 --> H4
    H3 --> H4
  end

  subgraph api ["API layer"]
    IMP["RevenueImportController → ImportService"]
  end

  DBREF --> C0
  T1 -->|"--daily"| cmd
  T2 -->|"--daily"| cmd
  T3 -->|"--resubmit random + ~10pct invalid date"| cmd
  C2 --> http
  H4 --> IMP
  IMP --> DBRUN[("revenue_records import_batches")]
MERMAID;
    }

    private static function apiMermaid(): string
    {
        return <<<'MERMAID'
flowchart TB
  subgraph submitters ["HTTP submitters — revenue ingestion"]
    PARTNERS["Real location partners production"]
    LOCFEED["LocationsFeedCommand + LocationsFeedApiClient prototype"]
  end

  subgraph readClients ["Read clients"]
    VUE["Vue 3 SPA operators"]
  end

  subgraph health ["HealthController"]
    H1["GET /health — status ready stack"]
    H2["GET /health?ready=1 — 503 until DB up"]
    H3["GET /health?diag=1 — Railway DB hints"]
  end

  subgraph meta ["MetaController"]
    M1["GET /meta/fault-types — codes and labels"]
  end

  subgraph import ["RevenueImportController — single ingestion entry"]
    I1["POST /revenue/import — JSON array of machine records"]
    I1H["Headers: x-idempotency-key x-submission-kind x-expected-record-count x-location-id x-report-date x-source"]
  end

  subgraph revenue ["RevenueController"]
    R1["GET /revenue/dashboard — KPIs from to location_id"]
    R2["GET /revenue/recent — limit offset faulty_only filters"]
    R3["GET /revenue/reconcile — shortfall overage status sort paging"]
  end

  subgraph submissions ["SubmissionsController"]
    S1["GET /submissions — list batches filters"]
    S2["GET /submissions/id — batch detail"]
    S3["GET /submissions/completion — location_id report_date"]
  end

  subgraph locations ["LocationsController"]
    L1["GET /locations/options — picker list"]
    L2["GET /locations — paginated shortfall filters"]
    L3["GET /locations/id/machines — machines at location"]
  end

  subgraph catalog ["CatalogController"]
    C1["GET /games — game_types"]
    C2["GET /machines — all machines with location"]
  end

  subgraph faults ["FaultsController"]
    F1["GET /transactions/faults — fault_type from to paging"]
  end

  subgraph diagrams ["DiagramsController"]
    D1["GET /diagrams/mermaid — type all|schema|api|... format json|raw"]
  end

  subgraph reports ["ReportsController — XLSX download"]
    X1["GET /reports/reconciliation.xlsx"]
    X2["GET /reports/transaction-faults.xlsx"]
    X3["GET /reports/daily-revenue.xlsx"]
    X4["GET /reports/location-summary.xlsx"]
    X5["GET /reports/machine-detail.xlsx"]
  end

  subgraph services ["Service layer"]
    IMP["ImportService"]
    ENV["SubmissionEnvelopeService"]
    SUB["SubmissionsService"]
    DASH["DashboardService"]
    REC["ReconcileService"]
    LOC["LocationsService"]
    RPT["ReportsService"]
    DIA["DiagramService"]
  end

  PARTNERS -->|"HTTPS POST x-source LOC-xxx"| I1
  LOCFEED -->|"HTTP POST x-source LOC-xxx"| I1
  LOCFEED --> L1
  LOCFEED --> L3
  LOCFEED --> S1
  LOCFEED --> S2
  LOCFEED --> S3

  VUE --> health
  VUE --> meta
  VUE --> revenue
  VUE --> submissions
  VUE --> locations
  VUE --> faults
  VUE --> diagrams
  VUE --> reports

  H1 --> DBCHK[("MySQL SELECT 1")]
  M1 --> DBMETA[("fault_types table")]
  I1 --> ENV
  I1 --> IMP
  R1 --> DASH
  R2 --> IMP
  R3 --> REC
  S1 --> SUB
  S2 --> SUB
  S3 --> SUB
  L1 --> LOC
  L2 --> LOC
  L3 --> DBLOC[("machines join")]
  C1 --> DBGAME[("game_types")]
  C2 --> DBMACH[("machines join")]
  F1 --> IMP
  D1 --> DIA
  X1 --> RPT
  X2 --> RPT
  X3 --> RPT
  X4 --> RPT
  X5 --> RPT

  subgraph vueviews ["Vue views using API"]
    VD["/dashboard → dashboard"]
    VL["/live → dashboard recent locations/options"]
    VS["/submissions → submissions locations/options"]
    VLO["/locations → locations/options"]
    VR["/reconcile → reconcile locations/options reports"]
    VF["/faults → faults meta reports"]
    VDI["/diagrams → diagrams/mermaid"]
  end

  VUE --> vueviews
MERMAID;
    }

    private static function importSequenceMermaid(): string
    {
        return <<<'MERMAID'
sequenceDiagram
  autonumber
  participant P as Location HTTP LOC-xxx
  participant F as LocationsFeedCommand
  participant A as LocationsFeedApiClient
  participant H as RevenueImportController
  participant E as SubmissionEnvelopeService
  participant I as ImportService
  participant C as location_daily_files
  participant M as MySQL
  participant UI as Vue UI polling

  Note over P,H: Single ingestion path — x-source must be location id LOC-xxx for envelope

  alt Production partner
    P->>H: HTTPS POST /api/revenue/import + x-* headers
  else Prototype locations-feed
    F->>F: schedule or bootstrap trigger
    F->>A: load location machines build payload
    A->>H: HTTP POST /api/revenue/import x-source LOC-xxx
  end

  H->>E: validateSubmissionEnvelope from headers
  E-->>I: parsed key expected_record_count

  alt envelope rejected
    E-->>H: INVALID or MISMATCH codes
    H->>M: INSERT import_batches failed
    H-->>P: 400 validation_failed imported=0
    H-->>A: 400 validation_failed imported=0
    Note over M: revenue_records untouched
  else import continues
    I->>I: hashPayload
    I->>C: ensureCanonicalFile
    alt conflict 409
      I-->>H: 409 JSON error code
      H-->>P: 409 conflict
      H-->>A: 409 conflict
    else continue
      I->>M: INSERT import_batches partial
      loop each machine at location
        I->>M: upsert or skip revenue_records
        I->>M: transaction_faults if faulty
      end
      I->>M: reconciliation_results
      I->>C: complete or in_progress
      UI->>H: GET /api/submissions poll
      H-->>P: 200 JSON summary
      H-->>A: 200 JSON summary
    end
  end
  F->>A: GET /api/submissions/completion hydrate cache
  Note over F: Resubmit every 15m — valid replay or ~10pct invalid date
MERMAID;
    }

    private static function dataModelMermaid(): string
    {
        return <<<'MERMAID'
erDiagram
  locations ||--o{ machines : operates
  machines ||--o{ revenue_records : daily_row
  revenue_records ||--o{ transaction_faults : may_have
  locations ||--o{ location_daily_files : canonical_file
  locations ||--o{ import_batches : audit_log
  locations ||--o{ reconciliation_results : daily_total
  locations ||--o{ expected_totals : target
  import_batches ||--o{ revenue_records : batch

  location_daily_files {
    string location_id PK
    string report_date PK
    string idempotency_key
    string payload_hash
    string status
    int expected_record_count
  }

  import_batches {
    int id PK
    string source
    string location_id FK
    string report_date
    string submission_kind
    string idempotency_key
    string payload_hash
    string status
    int imported_count
    int skipped_count
    int error_count
  }

  revenue_records {
    int id PK
    string machine_id FK
    string report_date
    decimal net_revenue
    int import_batch_id FK
  }

  transaction_faults {
    int id PK
    int revenue_record_id FK
    string fault_type
    string detail
  }

  reconciliation_results {
    string location_id FK
    string report_date
    decimal actual_net
    decimal expected_net
    decimal variance
  }
MERMAID;
    }
}
