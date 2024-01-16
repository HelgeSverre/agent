<?php

namespace App\Agent;

use Closure;

class CallbackHandler
{
    public function __construct(
        protected ?Closure $onIteration = null,
        protected ?Closure $onMaxIterationsReached = null,
        protected ?Closure $onThought = null,
        protected ?Closure $onObservation = null,
        protected ?Closure $onAction = null,
        protected ?Closure $onFinalAnswer = null,
        protected ?Closure $onPrompt = null
    ) {
    }

    public function triggerIteration(int $iteration, int $maxIterations): void
    {
        if ($this->onIteration) {
            ($this->onIteration)($iteration, $maxIterations);
        }
    }

    public function triggerThought(string $thought): void
    {
        if ($this->onThought) {
            ($this->onThought)($thought);
        }
    }

    public function triggerToolObservation(mixed $observation): void
    {
        if ($this->onObservation) {
            ($this->onObservation)($observation);
        }
    }

    public function triggerToolExecution(string $action, mixed $actionInput): void
    {
        if ($this->onAction) {
            ($this->onAction)($action, $actionInput);
        }
    }

    public function triggerFinalAnswer(mixed $finalAnswer): void
    {
        if ($this->onFinalAnswer) {
            ($this->onFinalAnswer)($finalAnswer);
        }
    }

    public function triggerPrompt(string $prompt): void
    {
        if ($this->onPrompt) {
            ($this->onPrompt)($prompt);
        }
    }

    public function triggerMaxIterationsReached(int $currentIteration, int $maxIterations)
    {
        if ($this->onMaxIterationsReached) {
            ($this->onMaxIterationsReached)($currentIteration, $maxIterations);
        }
    }
}
