<?php

declare(strict_types=1);

namespace Bowlingliga\Security;

use RuntimeException;

/**
 * Signals that the library needed for HTML sanitizing (HTML Purifier) is not
 * available at runtime (R33.11, R34.8).
 *
 * Thrown if a whitelist-based sanitizing is requested but the necessary
 * library is not installed. Without a sanitizing that can be performed, no
 * unchecked CMS content may be stored or shown.
 */
final class HtmlSanitizerUnavailableException extends RuntimeException
{
}
