# Governance

Ordo is a solo-maintained project. This document exists so that's an explicit, documented fact
rather than something a contributor has to infer — see "Bus factor" below for the risk that
implies and what mitigates it.

## Roles

- **Maintainer** (currently [@michalper](https://github.com/michalper)): reviews and merges pull
  requests, triages issues, cuts releases, and has the final say on project direction (scope,
  roadmap, breaking changes). There is currently no second maintainer.
- **Contributor**: anyone who opens an issue or pull request. See
  [CONTRIBUTING.md](CONTRIBUTING.md) for how to get a change in.

## Decision-making

For a solo-maintained project this is simple in practice: the maintainer decides, informed by
issue/PR discussion. There's no voting process or steering committee, because there's no second
maintainer for one to be meaningful. If the project ever gains additional maintainers, this
document will be updated to describe how decisions are made among them before that happens in
practice, not after.

## Becoming a maintainer

Not formalized yet, since it's never come up — there is no second maintainer today. A contributor
who sends several substantial, well-reviewed pull requests over time is the kind of track record
that would prompt the current maintainer to offer commit access; reach out via an issue if you're
interested.

## Bus factor

This project currently has a bus factor of 1 - everything (releases, security response, merge
access) depends on one person. That's a real risk for anyone depending on Ordo in production, not
something to understate. Two things mitigate it today:

- The project is FLOSS (OSL-3.0) and hosted on GitHub - anyone can fork it and continue
  independently if the original project goes unmaintained.
- See [SECURITY.md](SECURITY.md) for how to report a vulnerability even if general maintenance has
  slowed.

There's no additional continuity arrangement (e.g. a documented key/credential handoff plan)
beyond that today.

## Code of conduct

See [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).
