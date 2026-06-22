<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DiagramService;
use Illuminate\Http\Request;

class DiagramsController extends Controller
{
    public function __construct(
        private readonly DiagramService $diagrams,
    ) {}

    public function mermaid(Request $request)
    {
        $type = $request->query('type', 'all');
        $format = $request->query('format', 'json');
        $result = $this->diagrams->getMermaidDiagrams((string) $type, (string) $format);

        if ($format === 'raw' && $result['raw'] !== null) {
            return response($result['raw'], 200, ['Content-Type' => 'text/plain']);
        }

        return response()->json(['diagrams' => $result['diagrams']]);
    }
}
