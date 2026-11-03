<?php

declare(strict_types=1);

namespace Bowlingliga\Application\League;

use Bowlingliga\Security\AccessDeniedException;
use Bowlingliga\Security\Validator;
use Bowlingliga\Security\WriteAccess;

/**
 * Use case: the name of the league (R38).
 *
 * The name applies to the whole installation (R38.1). Without a stored name
 * the default {@see DEFAULT_NAME} applies (R38.2). Changing it requires write
 * access and a name of 1–100 characters after trimming (R38.3).
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

    /**
     * Saves a new league name (R38.3) and returns it as stored (trimmed).
     *
     * @throws AccessDeniedException if there is no write access (R5.1).
     * @throws \Bowlingliga\Security\ValidationException if the name is empty
     *         or longer than 100 characters.
     */
    public function rename(string $name): string
    {
        if (! $this->writeAccess->checkWriteAccess()) {
            throw new AccessDeniedException();
        }

        $name = $this->validator->checkName($name, 'field.league_name');
        $this->store->saveLeagueName($name);

        return $name;
    }
}
