<?php

namespace App\Agent;

use Exception;
use HelgeSverre\Brain\Facades\Brain;

class ModelSelector
{
    protected array $models = [
        'default' => [
            'model' => 'gpt-4o',
            'temp' => 0.5,
            'fast' => false,
        ],
        'planning' => [
            'model' => 'gpt-4o',
            'temp' => 0.2,
            'fast' => false,
        ],
        'reasoning' => [
            'model' => 'claude-3-opus-20240229',
            'temp' => 0.2,
            'fast' => false,
        ],
        'code' => [
            'model' => 'gpt-4o',
            'temp' => 0.2,
            'fast' => false,
        ],
        'creative' => [
            'model' => 'claude-3-sonnet-20240229',
            'temp' => 0.7,
            'fast' => false,
        ],
        'fast_response' => [
            'model' => 'gpt-4o-mini',
            'temp' => 0.5,
            'fast' => true,
        ],
    ];

    public function selectModelForTask(string $task, string $purpose): array
    {
        // For complex decision-making, you could use a model to choose the model
        if ($purpose === 'auto') {
            $selectionPrompt = "Task: {$task}\n\n".
                "Based on this task, which type of AI processing would be most appropriate?\n".
                "Options: planning, reasoning, code, creative, fast_response\n".
                'Select the most appropriate option and only respond with that single word.';

            try {
                $modelType = Brain::model('gpt-3.5-turbo')
                    ->fast()
                    ->temperature(0.0)
                    ->text($selectionPrompt);

                // Clean up response
                $modelType = strtolower(trim($modelType));

                // Fallback to default if response doesn't match options
                if (! isset($this->models[$modelType])) {
                    $modelType = 'default';
                }
            } catch (Exception $e) {
                $modelType = 'default';
            }
        } else {
            $modelType = $purpose;
        }

        // Fallback if specified model type doesn't exist
        if (! isset($this->models[$modelType])) {
            $modelType = 'default';
        }

        return [
            'model_type' => $modelType,
            'config' => $this->models[$modelType],
        ];
    }

    public function getBrainInstance(string $task, string $purpose = 'auto'): \HelgeSverre\Brain\Brain
    {
        $selection = $this->selectModelForTask($task, $purpose);
        $config = $selection['config'];

        $brain = Brain::model($config['model'])->temperature($config['temp']);

        if ($config['fast']) {
            $brain = $brain->fast();
        } else {
            $brain = $brain->slow();
        }

        return $brain;
    }
}
