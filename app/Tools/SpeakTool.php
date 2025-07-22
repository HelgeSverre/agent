<?php

namespace App\Tools;

use App\Agent\Tool\Attributes\AsTool;
use App\Agent\Tool\Attributes\Description;
use App\Agent\Tool\Tool;
use Illuminate\Support\Str;
use RuntimeException;

#[AsTool(
    name: 'speak',
    description: 'Speak a message using the text-to-speech service'
)]
class SpeakTool extends Tool
{
    public function run(
        #[Description('The message to speak')]
        string $message
    ): string {
        if (empty($message)) {
            return 'No message provided to speak.';
        }

        // If not on macOS, we can't use the `say` command
        if (PHP_OS_FAMILY !== 'Darwin') {
            return throw new RuntimeException('The speak tool is only available on macOS systems.');
        }

        $formatted = Str::of($message)->replace("\n", ' ')->trim();

        shell_exec('say '.escapeshellarg($formatted));

        return 'Said: '.$formatted;
    }
}
