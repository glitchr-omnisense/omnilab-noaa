# omnilab/noaa

NOAA's weather and climate observations for [glitchr/omnilab](https://github.com/glitchr-studio/omnilab),
as series at a point: the station records of the whole world from NCEI's Access Data Service -
GHCN-Daily by day, the Global Summary of the Month and of the Year, by the elements' own names
(`TMAX`, `TMIN`, `TAVG`, `PRCP`, `SNOW`, `AWND`...) in metric units - at the nearest station that
has them over the period, or at a station named; and, for a point of the United States, the
National Weather Service's observations of the nearest station (seven days back) joined to the
forecast of its grid (seven days ahead). No key.

```php
$noaa = (new NoaaSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
[$tmax, $prcp] = $noaa->series(new SeriesQuery(['TMAX', 'PRCP'], new Point(48.5734, 7.7521),
    new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-07'), SeriesQuery::DAILY));
$tmax->label;                 // STRASBOURG ENTZHEIM, FR (daily-summaries:FR000007190)
$tmax->values();              // 30.7 33.7 37.6 36.3 33.4 29.9 30.0 (°C)
$prcp->max();                 // 14.3 (mm, on the 4th)

// The month's (P1M) or the year's (P1Y) summaries; a station by its id.
$noaa->series(new SeriesQuery(['TAVG'], $strasbourg, new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-12-31'), 'P1M'));
$noaa->seriesAt('USW00094728', new SeriesQuery(['TMAX'], $anywhere, $from, $to, SeriesQuery::DAILY));

// The NWS, a source of its own: what was observed, then what is forecast.
$nws = (new NoaaSourceFactory($http))->create(['api' => 'nws', 'mailto' => 'support@glitchr.io']);
[$temperature] = $nws->series(new SeriesQuery(['temperature'], new Point(40.7789, -73.9692), new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+1 day')));
$temperature->label;          // New York City, Central Park (KNYC), then NWS OKX grid 34,45
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

License: MIT.
