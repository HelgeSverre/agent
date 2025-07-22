<?php

namespace App\Agent\Tool\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
readonly class AsTool
{
    public function __construct(
        public string $name,
        public string $description
    ) {}
}
