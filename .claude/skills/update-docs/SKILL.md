---
name: update-docs
description: After a change to the league website, update the project documentation – decisions and open points, test count, requirements and domain rules if affected. Use at the end of every completed change or when the user makes a domain decision.
---

# Update the documentation

The documentation is the memory between sessions. Short, with code
locations. Language: English.

## What goes where

| Occasion | File | Form |
|---|---|---|
| Implementation, bug fix | Commit message (no separate log) | What, where, which tests; if it changes behaviour, the matching doc below |
| Domain decision of the maintainer | The affected doc below plus the commit message | The rule itself in the doc, the reason in the commit message |
| Local decision log / open points | Only if the maintainer keeps one (named in `CLAUDE.local.md`, not versioned) | As described there |
| Rule clarified or changed | `docs/domain-logic.md` (authoritative) | Adjust the rule in its R section, keep the numbers; a dropped rule stays as "deferred"/"superseded" |
| Property test added or changed | `docs/testing.md` | Property, rules, test class |
| New route / screen | `docs/routes.md` | Route, class, purpose |
| Schema changed | `docs/data-model.md` | Table/column, migration |
| Architecture, convention, tool | `docs/architecture.md` or `docs/development.md` | |

Afterwards update the header line in `docs/README.md` (date, number of
green tests from the last PHPUnit run).

## Rules

- Only record what is actually implemented and tested; mark open things
  as open.
- List deferred features (e.g. OCR) as "deferred, not removed".
- Never renumber rules; the code refers to them with `(Rxx.y)`. A new rule
  gets the next free number of its section.
- If documentation and code contradict each other: check the code, ask the
  user if the domain rule is unclear.
