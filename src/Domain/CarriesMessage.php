<?php

declare(strict_types=1);

namespace Bowlingliga\Domain;

/**
 * Implementation of {@see Translatable} for exceptions: the message for the
 * user is a {@see Message}, the exception message is its key with the
 * parameters (for logs).
 *
 * Classes with further data define their own constructor and call
 * {@see carry()}.
 */
trait CarriesMessage
{
    private Message $userMessage;

    /** @param array<string, string|int|float|Message> $params */
    protected function __construct(string $key, array $params = [], ?\Throwable $previous = null)
    {
        $this->carry(new Message($key, $params), $previous);
    }

    public function message(): Message
    {
        return $this->userMessage;
    }

    private function carry(Message $message, ?\Throwable $previous = null): void
    {
        // For logs: the key with its plain parameters, e.g.
        // "error.database_operation (operation=Spielplan speichern)".
        $details = [];
        foreach ($message->params as $name => $value) {
            if (! $value instanceof Message) {
                $details[] = $name . '=' . $value;
            }
        }

        parent::__construct($message->key . ($details !== [] ? ' (' . implode(', ', $details) . ')' : ''), 0, $previous);
        $this->userMessage = $message;
    }
}
