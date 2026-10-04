<?php

namespace App\DependencyInjection;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;

/**
 * Reads SDWA5_DSP_* settings under their names from before the rename as well: %env(legacy:SDWA5_DSP_TARGET)%
 * returns TRACKDSP_TARGET when that one is set and not empty, and SDWA5_DSP_TARGET otherwise.
 *
 * The old name wins on purpose. An installation from before the rename sets only old names in .env.local, while
 * .env now supplies new names with their defaults, so the new name being set says nothing about the operator's
 * choice. A fresh installation sets no old name at all. docs/vim3.md describes the migration.
 */
final class LegacyEnvVarProcessor implements EnvVarProcessorInterface
{
    private const PREFIX = 'SDWA5_DSP_';
    private const LEGACY_PREFIX = 'TRACKDSP_';

    public function getEnv(string $prefix, string $name, \Closure $getEnv): mixed
    {
        if (str_starts_with($name, self::PREFIX)) {
            try {
                $legacy = $getEnv(self::LEGACY_PREFIX.substr($name, \strlen(self::PREFIX)));
                if (null !== $legacy && '' !== $legacy) {
                    return $legacy;
                }
            } catch (EnvNotFoundException) {
                // No old name, so the new one applies.
            }
        }

        return $getEnv($name);
    }

    public static function getProvidedTypes(): array
    {
        return ['legacy' => 'string'];
    }
}
