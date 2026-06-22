<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function __invoke()
    {
        $ready = false;
        $error = null;

        try {
            DB::select('SELECT 1');
            $ready = true;
        } catch (\Throwable $e) {
            $ready = false;
            $error = $e->getMessage();
        }

        if (request()->query('ready') === '1' && ! $ready) {
            return response()->json(['status' => 'starting'], 503);
        }

        $payload = [
            'status' => 'ok',
            'ready' => $ready,
            'stack' => 'laravel',
        ];

        if (request()->query('diag') === '1' && (app()->environment('local') || env('RAILWAY_ENVIRONMENT'))) {
            $payload['db_connection'] = config('database.default');
            $payload['db_host'] = config('database.connections.mysql.host');
            $payload['db_database'] = config('database.connections.mysql.database');
            $payload['db_url_set'] = (bool) config('database.connections.mysql.url');
            $payload['has_database_url'] = self::is_valid_db_env((string) env('DATABASE_URL', ''));
            $payload['has_mysqlhost'] = self::is_valid_db_env((string) env('MYSQLHOST', ''));
            $payload['has_mysql_tcp'] = self::is_valid_db_env((string) env('MYSQL_TCP_HOST', ''));
            $payload['database_url_len'] = strlen((string) env('DATABASE_URL', ''));
            $payload['mysqlhost_len'] = strlen((string) env('MYSQLHOST', ''));
            $payload['env_keys'] = array_values(array_filter(
                array_keys($_ENV),
                fn (string $k) => (bool) preg_match('/^(MYSQL|DATABASE|DB_|RAILWAY_)/', $k)
            ));
            sort($payload['env_keys']);
            $payload['error'] = $error;
            if (! $ready && env('RAILWAY_ENVIRONMENT')) {
                $payload['railway_service'] = env('RAILWAY_SERVICE_NAME');
                $payload['railway_mysql_synthesized'] = env('DB_HOST') && ! self::is_valid_db_env((string) env('DATABASE_URL', ''));
                $payload['hint'] = 'Entrypoint synthesizes DATABASE_URL when ${{MySQL.MYSQL_URL}} is empty. See deploy/railway/DEPLOY.md.';
            }
        }

        return response()->json($payload, 200);
    }

    private static function is_valid_db_env(string $value): bool
    {
        return $value !== '' && ! str_contains($value, '${{');
    }
}
