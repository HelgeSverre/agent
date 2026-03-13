<?php

namespace App\WebUI\Session;

use Illuminate\Support\Str;
use Ratchet\ConnectionInterface;

class WebUISessionManager
{
    protected array $sessions = [];

    protected array $connections = [];

    public function createSession(ConnectionInterface $connection): string
    {
        $sessionId = 'webui_'.Str::random(12);
        $connectionId = spl_object_id($connection);

        $this->sessions[$sessionId] = [
            'id' => $sessionId,
            'connection_id' => $connectionId,
            'created_at' => time(),
            'last_activity' => time(),
            'tasks' => [],
            'context' => [],
        ];

        $this->connections[$connectionId] = $sessionId;

        return $sessionId;
    }

    public function getSessionId(ConnectionInterface $connection): ?string
    {
        $connectionId = spl_object_id($connection);

        return $this->connections[$connectionId] ?? null;
    }

    public function getSession(string $sessionId): ?array
    {
        return $this->sessions[$sessionId] ?? null;
    }

    public function updateSession(string $sessionId, array $data): void
    {
        if (isset($this->sessions[$sessionId])) {
            $this->sessions[$sessionId] = array_merge($this->sessions[$sessionId], $data);
            $this->sessions[$sessionId]['last_activity'] = time();
        }
    }

    public function addTaskToSession(string $sessionId, string $taskId, array $taskData): void
    {
        if (isset($this->sessions[$sessionId])) {
            $this->sessions[$sessionId]['tasks'][$taskId] = array_merge($taskData, [
                'started_at' => time(),
            ]);
            $this->sessions[$sessionId]['last_activity'] = time();
        }
    }

    public function updateTaskInSession(string $sessionId, string $taskId, array $updates): void
    {
        if (isset($this->sessions[$sessionId]['tasks'][$taskId])) {
            $this->sessions[$sessionId]['tasks'][$taskId] = array_merge(
                $this->sessions[$sessionId]['tasks'][$taskId],
                $updates
            );
            $this->sessions[$sessionId]['last_activity'] = time();
        }
    }

    public function destroySession(ConnectionInterface $connection): void
    {
        $sessionId = $this->getSessionId($connection);
        $connectionId = spl_object_id($connection);

        if ($sessionId) {
            unset($this->sessions[$sessionId]);
        }

        unset($this->connections[$connectionId]);
    }

    public function getActiveSessions(): array
    {
        return $this->sessions;
    }

    public function getSessionCount(): int
    {
        return count($this->sessions);
    }

    public function cleanupExpiredSessions(int $maxAge = 3600): int
    {
        $now = time();
        $cleaned = 0;

        foreach ($this->sessions as $sessionId => $session) {
            if (($now - $session['last_activity']) > $maxAge) {
                unset($this->sessions[$sessionId]);

                // Also clean up connection mapping if it exists
                $connectionId = $session['connection_id'] ?? null;
                if ($connectionId && isset($this->connections[$connectionId])) {
                    unset($this->connections[$connectionId]);
                }

                $cleaned++;
            }
        }

        return $cleaned;
    }
}
