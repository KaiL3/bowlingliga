# Security Policy

## Supported versions

Bowlingliga has no release branches. Security fixes go into the latest
state of the default branch only; please update to it before reporting.

## Reporting a vulnerability

Please **do not open a public issue** for a security problem.

Report it privately via GitHub instead: on the repository page open
**Security → Report a vulnerability** (GitHub's private vulnerability
reporting). Only the maintainers can see the report, and we can discuss
and fix it there before anything becomes public.

Helpful details:

- what is affected (page, route or file) and the version or commit,
- steps to reproduce, ideally with a minimal example request,
- the impact you expect (e.g. access to the admin area, data of other
  teams, stored scripts),
- whether the problem needs a particular configuration of the server.

This is a volunteer project, so an answer may take a while. We will keep
you informed in the report until a fix is available. If you like, you will
be credited in the advisory.

## Scope

In scope is the code in this repository, including the installation and
update scripts under `deploy/`. Out of scope are weaknesses of a particular
installation that come from its own server configuration, and problems in
third-party dependencies that are already publicly known (please report
those upstream).
