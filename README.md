# omnilab/noaa

NOAA's weather and climate observations for [glitchr/omnilab](https://github.com/glitchr-studio/omnilab),
as series at a point: the station records of the whole world from NCEI's Access Data Service -
GHCN-Daily by day, the Global Summary of the Month and of the Year, by the elements' own names
(`TMAX`, `TMIN`, `TAVG`, `PRCP`, `SNOW`, `AWND`...) in metric units - at the nearest station that
has them over the period, or at a GHCN station named (`ghcn:USW00094728`); and, for a point of the United States, the
National Weather Service's observations of the nearest station (seven days back) joined to the
forecast of its grid (seven days ahead), or of a station named (`nws.station:KNYC`). A station
is an entity too: its name, place, elevation and the period its records cover. No key.

```php
$noaa = (new NoaaSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
[$tmax, $prcp] = $noaa->series(new SeriesQuery(['TMAX', 'PRCP'], new Point(48.5734, 7.7521),
    new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-07'), SeriesQuery::DAILY));
$tmax->label;                 // STRASBOURG ENTZHEIM, FR (daily-summaries:FR000007190)
$tmax->values();              // 30.7 33.7 37.6 36.3 33.4 29.9 30.0 (°C)
$prcp->max();                 // 14.3 (mm, on the 4th)

// The month's (P1M) or the year's (P1Y) summaries.
$noaa->series(new SeriesQuery(['TAVG'], $strasbourg, new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-12-31'), 'P1M'));

// A GHCN station in place of the point: its own records (seriesAt(), its method before, is deprecated).
[$tmax] = $noaa->series(new SeriesQuery(['TMAX'], Identifier::of(Scheme::GHCN, 'USW00094728'), new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-07'), SeriesQuery::DAILY));
$tmax->values();              // 24.4 28.3 21.1 16.7 20 15.6 18.3 (°C, Central Park)
$station = $noaa->entity('ghcn:FR000007190'); // STRASBOURG ENTZHEIM, FR: 48.5492,7.6403, 150 m, records from 1923-05-01

// The NWS, a source of its own: what was observed, then what is forecast.
$nws = (new NoaaSourceFactory($http))->create(['api' => 'nws', 'mailto' => 'support@glitchr.io']);
[$temperature] = $nws->series(new SeriesQuery(['temperature'], new Point(40.7789, -73.9692), new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+1 day')));
$temperature->label;          // New York City, Central Park (KNYC), then NWS OKX grid 34,45
$nws->series(new SeriesQuery(['temperature'], Identifier::of(Scheme::NWS_STATION, 'KNYC'), ...)); // that station's, then its grid's
$nws->entity('nws.station:KNYC');                                                                 // its name, place, elevation, zone
```

```yaml
omnilab:
    sources:
        noaa:
            factory: noaa
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent
                radius_km: 50                  # how far from a Point the nearest station is looked for
        nws:
            factory: noaa
            options:
                api: nws
                mailto: 'support@glitchr.io'   # the NWS refuses a call without a User-Agent
```

## Terms of use

NOAA's data are in the U.S. public domain; credit NOAA (NCEI, the National Weather Service) where
you show them. Neither API needs a key nor publishes a rate; the NWS asks every caller for a
User-Agent that says who calls (the factory sends `omnilab/1.x` with the `mailto` address) and
answers 403 without one. NCEI's GHCN-Daily records come from many national services (Météo-France,
the Japan Meteorological Agency... through ECA&D and the WMO's exchanges); a station's latest
months may lag. CDO v2, NCEI's older API, needs a token and serves the same datasets: it is not
used.

See [docs/](docs/index.md) for what is read, how it maps, and what is left out.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by NOAA. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
