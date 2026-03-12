<?php

namespace App\Integration;

class ToolDefinition
{
    public function __construct(
        private string $name,
        private string $description,
        private array $parameters = [],
        private ToolCategory $category = ToolCategory::READ
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getCategory(): ToolCategory
    {
        return $this->category;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'parameters' => $this->parameters,
            'category' => $this->category->value
        ];
    }
}
