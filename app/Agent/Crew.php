<?php

namespace Mindwave\Mindwave\Crew;

use App\Agent\Agent;
use Illuminate\Console\View\Components\Task;

class Crew
{
    protected string $id;

    /**
     * @param  Task[]  $tasks
     * @param  Agent[]  $agents
     */
    public function __construct(
        protected array $tasks,
        protected array $agents,
    ) {
        $this->id = uniqid(); // Generate a unique ID
    }

    public function executeTasks(): string
    {
        $taskOutput = null;
        foreach ($this->tasks as $task) {

            $taskOutput = $task->execute($taskOutput);
        }

        return $taskOutput;
    }
}
