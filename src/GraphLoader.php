<?php

declare(strict_types=1);

namespace Quazardous\GramPHPBundle;

use Quazardous\GramPHP\Graph;

/** Graphs from configuration: a file in grampy's format, or the same form inline. */
final class GraphLoader
{
    public static function fromFile(string $path): Graph
    {
        $text = is_file($path) ? file_get_contents($path) : false;
        if (false === $text) {
            throw new \InvalidArgumentException(\sprintf('gramphp: no graph file at %s', $path));
        }

        return Graph::fromJson($text);
    }

    /** @param array<string, mixed> $definition the canonical form, as `Graph::toArray()` writes it */
    public static function fromDefinition(array $definition): Graph
    {
        return Graph::fromArray($definition);
    }
}
