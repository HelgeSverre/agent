<?php

namespace App\Agent;

interface Tool
{
    public function name(): string;

    public function description(): string;

    public function execute(...$args): string;
}
