<?php

declare(strict_types=1);

namespace Viz\Model;

final readonly class Agent
{
    public const MAIN = 'main';

    public function __construct(
        public string $id,
        public string $type,
        public string $description,
        public ?string $parentToolUseId,
    ) {
    }

    public function label(): string
    {
        return $this->id === self::MAIN ? 'main' : $this->type . ': ' . $this->description;
    }
}
