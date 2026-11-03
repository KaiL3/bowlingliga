---
name: new-admin-area
description: Recipe for a new admin function or a new admin area of the league website (use case, repository, controller, view, route, tests). Use when a writing admin screen is created or a function without a screen (e.g. handicap chain, previous-season record, 2FA setup) is to get one.
---

# New admin area

Read first: [docs/architecture.md](../../../docs/architecture.md) (pattern of
every admin route) and, if present, the section "Fachlogik ohne Oberfläche"
in [docs/routes.md](../../../docs/routes.md) –
often the use case already exists and only needs wiring. Look up the related
rules in `docs/domain-logic.md` (`grep -n "R27" docs/domain-logic.md`) and
ask the user concrete questions about domain gaps.

## Steps

1. **Use case** in `src/Application/<Area>/` with port interfaces. Checks
   `Security\WriteAccess`; several write steps run through the port
   `Application\Transaction`. Domain errors as a dedicated `…Exception`
   with a message suitable for the user (German UI text).
2. **Adapter** in `src/Persistence/<Area>/`: extends `AbstractRepository`,
   uses `transactional()` and prepared statements. Schema change → new
   numbered migration in `db/migrations/` plus `applySchema()`/`dropSchema()`
   in the integration tests.
3. **Controller + view** in `src/Presentation/`. The controller takes
   `array $input` and returns an `ActionResult` or HTML. The view escapes
   every output, forms contain `$csrf->fieldHtml()`, fields are prefilled
   from the input after errors. Use the CSS variables from
   `public/assets/css/app.css` (Lane/Bento, light/dark).
4. **Wiring** in `public/index.php` (composition root, no DI container):
   `$router->admin('/area/action', …)` following the route pattern (ensure
   access → GET renders → check CSRF → 400 → controller). Add the link in
   `AdminView::renderOverview()`.
5. **Tests:**
   - Unit: controller, view, use case with in-memory ports
     (`tests/Unit/**/InMemory*`, `Recording*`). Add new port methods to all
     test doubles.
   - Integration against `bowlingliga_test`.
   - E2E case in `tests/Integration/Admin/AdminAreaIntegrationTest`: 401
     without login, writing run with persistence check, invalid CSRF token
     → 400.
6. **Check:** full suite green, appearance via skill `visual-check`
   (desktop + phone), then skill `update-docs`.
