<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class LocationsFeedApiClient
{
    /**
     * @return list<array{
     *   location_id: string,
     *   location_name: string,
     *   machines: list<array{machine_id: string, location_id: string, location_name: string}>
     * }>
     */
    public function getLocationGroups(): array
    {
        $options = $this->getJson('/api/locations/options');
        $groups = [];

        foreach ($options as $loc) {
            $locationId = (string) ($loc['location_id'] ?? '');
            $locationName = (string) ($loc['location_name'] ?? '');
            if ($locationId === '') {
                continue;
            }

            $machines = $this->getJson('/api/locations/'.rawurlencode($locationId).'/machines');
            $groups[] = [
                'location_id' => $locationId,
                'location_name' => $locationName,
                'machines' => array_map(fn ($m) => [
                    'machine_id' => (string) ($m['machine_id'] ?? ''),
                    'location_id' => $locationId,
                    'location_name' => $locationName,
                ], $machines),
            ];
        }

        usort($groups, fn ($a, $b) => $a['location_id'] <=> $b['location_id']);

        return $groups;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  array<string, mixed>  $meta
     * @return array{ok: bool, status: int, summary: array<string, mixed>}
     */
    public function postImport(array $records, array $meta): array
    {
        $locationId = (string) $meta['location_id'];

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'x-source' => $locationId,
            'x-submission-kind' => (string) $meta['submission_kind'],
            'x-location-id' => $locationId,
            'x-report-date' => (string) $meta['report_date'],
            'x-idempotency-key' => (string) $meta['idempotency_key'],
            'x-expected-record-count' => (string) $meta['expected_record_count'],
        ];

        try {
            $response = Http::timeout(120)
                ->withHeaders($headers)
                ->post($this->apiBaseUrl().'/api/revenue/import', $records);
        } catch (ConnectionException $e) {
            return [
                'ok' => false,
                'status' => 0,
                'summary' => ['error' => $e->getMessage(), 'code' => 'CONNECTION_FAILED'],
            ];
        }

        /** @var array<string, mixed> $summary */
        $summary = $response->json() ?? [];

        if ($response->successful()) {
            return ['ok' => true, 'status' => $response->status(), 'summary' => $summary];
        }

        return ['ok' => false, 'status' => $response->status(), 'summary' => $summary];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{submissions: list<array<string, mixed>>, total: int}
     */
    public function listSubmissions(array $query): array
    {
        $qs = http_build_query(array_filter($query, fn ($v) => $v !== null && $v !== ''));
        $path = '/api/submissions'.($qs !== '' ? '?'.$qs : '');

        /** @var array{submissions?: list<array<string, mixed>>, total?: int} $data */
        $data = $this->getJson($path);

        return [
            'submissions' => $data['submissions'] ?? [],
            'total' => (int) ($data['total'] ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    public function getCompletion(string $locationId, string $reportDate): array
    {
        $qs = http_build_query([
            'location_id' => $locationId,
            'report_date' => $reportDate,
        ]);

        /** @var array<string, mixed> */
        return $this->getJson('/api/submissions/completion?'.$qs);
    }

    /** @return array<string, mixed>|null */
    public function getSubmission(int $id): ?array
    {
        try {
            /** @var array<string, mixed> */
            return $this->getJson('/api/submissions/'.$id);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return null;
            }

            throw $e;
        }
    }

    /** @return list<mixed> */
    private function getJson(string $path): array
    {
        try {
            $response = Http::timeout(60)
                ->acceptJson()
                ->get($this->apiBaseUrl().$path);
        } catch (ConnectionException $e) {
            throw new \RuntimeException('Locations feed API unreachable: '.$e->getMessage(), 0, $e);
        }

        if ($response->status() === 404) {
            throw new \RuntimeException('HTTP 404 for '.$path);
        }

        if ($response->failed()) {
            throw new \RuntimeException(
                'Locations feed API GET '.$path.' failed with HTTP '.$response->status().': '.substr((string) $response->body(), 0, 200),
            );
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new \RuntimeException('Locations feed API GET '.$path.' returned non-JSON body');
        }

        return $json;
    }

    private function apiBaseUrl(): string
    {
        $configured = config('locations-feed.api_base_url');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        $appUrl = config('app.url');
        if (is_string($appUrl) && $appUrl !== '' && $appUrl !== 'http://localhost') {
            return rtrim($appUrl, '/');
        }

        $port = env('PORT', 8000);

        return 'http://127.0.0.1:'.$port;
    }
}
