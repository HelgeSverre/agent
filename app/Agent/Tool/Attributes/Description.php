<?php

namespace App\Agent\Tool\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
readonly class Description
{
    public function __construct(public string $description) {}
}
