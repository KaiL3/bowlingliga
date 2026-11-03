<?php

declare(strict_types=1);

namespace Bowlingliga\Presentation;

use Bowlingliga\Domain\Message;

/**
 * Catalog texts for views and controllers that build HTML. The using class
 * has a {@see \Bowlingliga\I18n\Translator} property `$t`.
 */
trait ViewTexts
{
    /**
     * Escaped catalog text; `$params` are escaped, `$html` is inserted as it
     * is (markup built by the view, e.g. a link).
     *
     * @param array<string, string|int|float> $params
     * @param array<string, string>           $html
     */
    private function text(string $key, array $params = [], array $html = []): string
    {
        $replace = [];
        foreach ($params as $name => $value) {
            $replace['{' . $name . '}'] = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        foreach ($html as $name => $value) {
            $replace['{' . $name . '}'] = $value;
        }

        return strtr(htmlspecialchars($this->t->t($key), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $replace);
    }

    /** Plain catalog text (not escaped), e.g. for {@see AdminComponents::card()}, which escapes. */
    private function plain(string $key, array $params = []): string
    {
        return $this->t->t($key, $params);
    }

    /** Escaped text of a {@see Message}. */
    private function messageText(Message $message): string
    {
        return htmlspecialchars($this->t->message($message), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
