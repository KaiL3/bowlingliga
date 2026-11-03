<?php

declare(strict_types=1);

namespace Bowlingliga\Tests;

use Bowlingliga\Domain\Message;
use Bowlingliga\I18n\Translator;

/**
 * German texts of errors and messages for tests that check the wording the
 * league sees (the default language of the installation).
 */
final class GermanText
{
    public static function of(\Throwable|Message|null $value): string
    {
        $translator = new Translator('de');

        return match (true) {
            $value === null              => '',
            $value instanceof Message    => $translator->message($value),
            default                      => $translator->error($value),
        };
    }

    /**
     * @param list<Message> $messages
     *
     * @return list<string>
     */
    public static function all(array $messages): array
    {
        return array_map(self::of(...), $messages);
    }
}
