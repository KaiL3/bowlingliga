<?php

declare(strict_types=1);

namespace Bowlingliga\Application\League;

use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Security\Validator;
use Bowlingliga\Security\WriteAccess;

/**
 * Use case: the settings of the whole installation – the name of the league
 * (R38) and whether player data is public without login (R40).
 *
 * The name applies to the whole installation (R38.1). Without a stored name
 * the default {@see DEFAULT_NAME} applies (R38.2). Changing the settings
 * requires write access and a name of 1–100 characters after trimming
 * (R38.3, R40.1).
 */
final class LeagueSettings
{
    /** Name of a new installation (R38.2). */
    public const DEFAULT_NAME = 'Bowlingliga';

    public function __construct(
        private readonly LeagueSettingsStore $store,
        private readonly Validator $validator,
        private readonly WriteAccess $writeAccess,
    ) {
    }

    /**
     * The league name to show: the stored one, otherwise the default (R38.2).
     */
    public static function nameFrom(LeagueSettingsStore $store): string
    {
        return $store->leagueName() ?? self::DEFAULT_NAME;
    }

    public function name(): string
    {
        return self::nameFrom($this->store);
    }

    /** Whether player data is public without login (R40.1). */
    public function playersPublic(): bool
    {
        return $this->store->playersPublic();
    }

    /**
     * Saves the league name (R38.3) and the player visibility (R40.1) and
     * returns the name as stored (trimmed).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws \Bowlingliga\Security\ValidationException if the name is empty
     *         or longer than 100 characters; then nothing is saved.
     */
    public function save(string $name, bool $playersPublic): string
    {
        if (! $this->writeAccess->checkWriteAccess()) {
            throw new AccessDeniedException();
        }

        $name = $this->validator->checkName($name, 'field.league_name');
        $this->store->save($name, $playersPublic);

        return $name;
    }
}
