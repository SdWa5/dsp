<?php

namespace App\Measurement;

/**
 * A measurement stopped by Ctrl+C or SIGTERM before its recording started.
 */
final class Interrupted extends \RuntimeException
{
}
