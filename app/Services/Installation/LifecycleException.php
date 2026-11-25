<?php

namespace App\Services\Installation;

use RuntimeException;

/** A fixed, operator-facing message; never attach credentials or driver diagnostics. */
final class LifecycleException extends RuntimeException
{
}
