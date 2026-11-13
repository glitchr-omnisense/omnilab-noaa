# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Added

- Omnilab NOAA: weather and climate observations as series at a point - the station records of the
  world from NCEI's Access Data Service (GHCN-Daily by day, the Global Summary of the Month and of
  the Year: TMAX, TMIN, TAVG, PRCP, SNOW, AWND... in metric units) at the nearest station that has
  them or at a station named, and, for a point of the United States, the National Weather Service's
  observations of the nearest station (seven days back) joined to its grid's forecast (seven days
  ahead); no key.

### Release

- Requires `omnisense/omnilab` `^1.0`, stable versions, where it required `@dev` ones:
  `composer require omnilab/noaa` installs in a project at Composer's default minimum-stability
  (stable). `extra.branch-alias` names the branch (`dev-1.x`: `1.x-dev`).
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml, pinned: the package
  installed alone, PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml`
  (that CI on the tag, then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the
  package's own tests.
- README: the install line and a first call.
