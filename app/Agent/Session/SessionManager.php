<?php

namespace App\Agent\Session;

use Illuminate\Support\Facades\File;

class SessionManager
{
    private string $sessionDir;

    public function __construct()
    {
        $this->sessionDir = storage_path('agent-sessions');

        if (! File::exists($this->sessionDir)) {
            File::makeDirectory($this->sessionDir, 0755, true);
        }
    }

    public function save(string $sessionId, array $data): void
    {
        $path = $this->getSessionPath($sessionId);
        $data['updated_at'] = now()->toIso8601String();

        File::put($path, json_encode($data, JSON_PRETTY_PRINT));
    }

    public function load(string $sessionId): ?array
    {
        $path = $this->getSessionPath($sessionId);

        if (! File::exists($path)) {
            return null;
        }

        $content = File::get($path);

        return json_decode($content, true);
    }

    public function list(): array
    {
        $files = File::glob($this->sessionDir.'/*.json');
        $sessions = [];

        foreach ($files as $file) {
            $data = json_decode(File::get($file), true);
            $sessions[] = [
                'id' => basename($file, '.json'),
                'task' => $data['task'] ?? 'Unknown',
                'status' => $data['status'] ?? 'unknown',
                'created_at' => $data['created_at'] ?? null,
                'updated_at' => $data['updated_at'] ?? null,
            ];
        }

        return $sessions;
    }

    private function getSessionPath(string $sessionId): string
    {
        return $this->sessionDir.'/'.$sessionId.'.json';
    }
}
