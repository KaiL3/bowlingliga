# Testing

How to run the tests and what the suites contain:
[development.md](development.md#tests). This file describes the test
strategy and lists the **correctness properties** that the property tests
check. Rule numbers (`R12.8`) refer to [domain-logic.md](domain-logic.md).

## Strategy

| Kind | Covers | Where |
|---|---|---|
| Property tests | Pure calculation logic with universal invariants: average, handicap, gross, points, table order, honours, blind values, season plan, lanes, cache consistency | `tests/Property` (eris, at least 100 generated inputs per property) |
| Unit tests | Concrete examples, edge and error cases: statuses "ohne Durchschnitt"/"ohne Handicap", first-matchday handicap, validation of master data, login lock, use cases with in-memory ports, controllers and views | `tests/Unit` |
| Integration tests | Real test database: repositories, transactions and rollback, aggregation, result entry, team entry, season planning, migrations, login | `tests/Integration` |
| End-to-end | `php -S` with `public/index.php`, all admin areas and the team entry via HTTP | `tests/Integration/Admin` |

- The calculation services in `src/Domain` are pure, so the property tests
  run without a database. Property 23 also runs against the test database
  (`RawDataRoundtripPropertyTest`).
- Generators cover the edge values on purpose: 0 and 300 pins, empty
  histories, the bonus thresholds 2100/2101/2500/2501, ties, odd and even
  numbers of teams, blind and ghost team results mixed in.
- Every property test carries the tag
  `Feature: bowlingliga-website, Property {n}: {title}` and a
  `Validates:` line with the rule numbers. Each property has exactly one
  test class (Property 23 two).

## Correctness properties

A property is a statement that must hold for every valid input. The numbers
are stable; tests and comments refer to them.

| # | Property | Rules | Test |
|---|---|---|---|
| 1 | **Average from games actually played:** for any history of real, blind, unplayed and ghost games, the average net is the mean of the net results of the games the player played themselves; blind and ghost results change nothing. | R11.1, R11.2 | `Average/AverageActuallyPlayedPropertyTest` |
| 2 | **Previous season by matchday threshold:** up to the threshold the previous season games or the previous season record are included, afterwards only the current season. | R11.3–R11.6 | `Average/PreviousSeasonInclusionPropertyTest` |
| 3 | **Handicap formula and limit:** the handicap is the formula result rounded to a whole number and always within [0, maximum handicap]. | R12.8, R12.10, R12.15 | `Handicap/HandicapFormulaLimitPropertyTest` |
| 4 | **Gross = net + handicap** for every net and handicap. | R12.11, R13.3 | `Handicap/GrossSumPropertyTest` |
| 5 | **Frozen handicap is stable:** no change of raw data alters a frozen handicap unless a recalculation of the chain is explicitly confirmed. | R12.12, R12.17, R12.19 | `Handicap/FrozenHandicapStabilityPropertyTest` |
| 6 | **Chain recalculation is deterministic:** recalculating from the same raw data and start matchday always gives the same handicaps, in chronological order. | R12.18 | `Handicap/ChainRecalculationDeterminismPropertyTest` |
| 7 | **Bonus point scale with cap:** ≤ 2100 → 1; from 2101 → 2 plus one per full 100; at most 6 from 2501; never decreasing with a higher gross. | R17.6–R17.8 | `Points/BonusPointsScalePropertyTest` |
| 8 | **Team gross, game points, total points:** team gross of a game = sum of the 4 positions; game points from comparing the team gross; total = game + bonus points and never above 3 × win + 6. | R17.2–R17.4, R17.9 | `Points/TeamGrossTotalPointsPropertyTest` |
| 9 | **Table order and shared rank:** sorted by points, gross, net; equal teams share the rank, the following ranks are skipped. | R16.2–R16.5, R16.8 | `Table/LeagueTableSortingPropertyTest` |
| 10 | **Table aggregation:** only played matchdays count; the total row is the sum of the team rows. | R16.1, R16.6, R16.9, R24.3 | `Table/TableAggregationPropertyTest` |
| 11 | **Blind and ghost results excluded (metamorphic):** adding any blind or ghost results leaves averages, handicap basis and honours unchanged. | R20.4, R20.5, R27.13 | `GhostTeam/MetamorphicExclusionPropertyTest` |
| 12 | **Ghost team results:** for the fixed value passed in (the season's ghost score) every ghost net equals it, gross equals net, 4 positions × 3 games, team net = team gross. | R20.1, R20.2, R16.10, R16.11, R20.8 | `GhostTeam/GhostTeamResultsPropertyTest` |
| 13 | **Derived blind value with clamping:** net = average − 10, gross = average − 10 + handicap, both limited to 0–300. | R27.6–R27.9 | `BlindPlayer/DerivedBlindReferenceValuePropertyTest` |
| 14 | **Entered value is stored:** in the admin's score sheet the stored net of a blind game is always the value entered, never replaced by the reference. (The team entry derives blind values instead, R27.18.) | R27.10, R22.2 | `BlindPlayer/SheetValueStoredPropertyTest` |
| 15 | **Review only on deviation:** a blind game with a reference needs review exactly when the entered value differs from it. | R27.11 | `BlindPlayer/NeedsReviewPropertyTest` |
| 16 | **Filling the lineup:** with 1–4 real players the lineup has exactly 4 positions, at most 3 blind, at least 1 real; with 0 real players no blind players, 0 points, evening lost. | R27.1–R27.3, R21.6 | `BlindPlayer/LineupFillingPropertyTest` |
| 17 | **Schedule structure:** for an even number of teams and any number of rounds every pair meets equally often and no team plays twice on a matchday. | R9.1, R9.2, R9.11 | `SeasonPlanner/ScheduleStructurePropertyTest` |
| 18 | **Ghost team in planning:** added exactly for an odd number of real teams; every real team meets it equally often. | R9.6–R9.8 | `SeasonPlanner/GhostTeamPlanningPropertyTest` |
| 19 | **Even lane assignment:** every fixture gets one lane pair, a pair carries at most one fixture per matchday, and a team's usage of the lane pairs differs by at most one where the plan allows it; otherwise the smallest spread with the deviations reported. | R18.1–R18.3, R18.9, R18.10 | `Lane/EvenLaneAssignmentPropertyTest` |
| 20 | **Positionday fixtures from the standings:** neighbouring ranks are paired (1–2, 3–4, …); no fixtures while previous matchdays are incomplete. | R31.2–R31.4 | `Positionday/PositiondayFixturesPropertyTest` |
| 21 | **Visibility of honours:** public exactly after the end date, for admins at any time. | R13.8–R13.10 | `Honours/HonoursVisibilityPropertyTest` |
| 22 | **Countable series and ties in honours:** only own real games count (series = exactly 3 own games of an evening); ties share the place with skipped following places; at most places 1–3. | R13.1, R13.2, R13.4–R13.6 | `Honours/HonoursSeriesTiePropertyTest` |
| 23 | **Reproducible derived values and cache consistency:** recalculating from the current raw data equals a valid cached value; after a change and invalidation a read equals the recalculation. Raw data is read back exactly as written. | R35.2, R35.4, R4.5 | `Cache/CacheReproducibilityPropertyTest`, `Persistence/RawDataRoundtripPropertyTest` |

Additionally `SeasonPlanner/SpecialDaysPropertyTest` checks that special days
(positionday, lot draw) chosen per matchday are skipped by the round robin
and that incomplete rounds give the warning (R9.12, R9.14).

All test classes are under `tests/Property/`.
