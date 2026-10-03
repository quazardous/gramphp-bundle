<?php

declare(strict_types=1);

namespace Quazardous\GramPHPBundle\Command;

use Symfony\Component\Console\Input\InputInterface;

/** @internal typed reads of the console input */
final class Input
{
    /** @return list<string> */
    public static function names(InputInterface $input, string $argument): array
    {
        $value = $input->getArgument($argument);

        return \is_array($value) ? array_values(array_map(static fn(mixed $v): string => \is_scalar($v) ? (string) $v : '', $value)) : [];
    }

    public static function text(InputInterface $input, string $name): string
    {
        $value = $input->hasArgument($name) ? $input->getArgument($name) : $input->getOption($name);

        return \is_scalar($value) ? (string) $value : '';
    }

    public static function positive(InputInterface $input, string $option): int
    {
        $value = $input->getOption($option);
        if (!\is_scalar($value) || !ctype_digit((string) $value) || (int) $value < 1) {
            throw new \InvalidArgumentException("--{$option}: a positive integer");
        }

        return (int) $value;
    }

    /** @param array<array-key, int> $counts */
    public static function counts(array $counts): string
    {
        $out = [];
        foreach ($counts as $what => $count) {
            $out[] = "{$what} {$count}";
        }

        return implode(', ', $out);
    }
}
