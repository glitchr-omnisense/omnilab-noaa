# omnilab/noaa

NOAA's observations as series for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab):
NCEI's station records of the world (GHCN-Daily, the monthly and yearly summaries, through
`https://www.ncei.noaa.gov/access/services/`) at the nearest station or one named, and the
National Weather Service's observations and forecasts in the United States
(`https://api.weather.gov/`) at a point or a station; the stations themselves as entities. No key.

## Installation

```sh
composer require omnilab/noaa
```

```php
use Omnilab\Noaa\NoaaSourceFactory;

$noaa = (new NoaaSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional: in the User-Agent
    'radius_km' => 50,                 // optional: how far from a Point NCEI's nearest station is looked for
]);
$nws = (new NoaaSourceFactory($httpClient))->create(['api' => 'nws', 'mailto' => 'support@glitchr.io']);
```

With the Symfony bundle, the factory is registered as `noaa` when the bundle knows it; otherwise
register `NoaaSourceFactory` as a service (it is autoconfigured as a `SourceFactoryInterface`).

## Credentials

None: neither NCEI's Access Data Service nor the NWS's API takes a key. The NWS requires a
User-Agent that says who calls (403 without one): the factory sends `omnilab/1.x` with the `mailto`
address. CDO v2, NCEI's older API, needs a token: it is not used.

## Options

The factory's options, with the defaults it applies (`NoaaSourceFactory`; the capabilities below are `ncei`'s, the default):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `api` | `string` | `ncei` | no | `ncei` (NCEI's station records of the world) or `nws` (the National Weather Service, the United States): a source of its own each; another is an `\InvalidArgumentException` at `create()` |
| `radius_km` | `float` | `50` | no | NCEI: how far from a `Point` the nearest station is looked for, in kilometres |
| `timezone` | `?string` | null | no | the zone of the days and times when a query does not say: `local` (the NWS point's own), `UTC`, `America/New_York`...; null: local for the NWS, UTC for NCEI |
| `now` | `?string` | null | no | the time taken for now (tests); null: the real one |
| `mailto` | `?string` | null | no | a contact address, in the User-Agent, which the NWS requires |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0` | no | seconds left between two calls: neither API publishes a figure |
| `base_uri` | `string` | null | no | null: the API's own - `https://www.ncei.noaa.gov/access/services/` (ncei), `https://api.weather.gov/` (nws) |

## Capabilities

`capabilities()` answers `entity`, `series`, `historical`, `station`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | a station: a GHCN one (`ghcn:USW00094728`, two calls) for `ncei`, an NWS one (`nws.station:KNYC`, one call) for `nws`; another scheme refused before any call |
| `search` | no |  |
| `series` | yes | `ncei`: GHCN-Daily and the monthly and yearly summaries (`TMAX`, `PRCP`...) at a station or the nearest one of a `Point`; `nws`: a point's or a station's observations, then its grid's forecast, hourly or daily |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | no | none: a station gives no other base's id |
| `forecast` | no | `nws` only: its capabilities add `forecast` (the grid's forecast) |
| `historical` | yes | NCEI's records; the NWS's observations of the last seven days |
| `area` | no | a box is refused before any call |
| `station` | yes | `ghcn` for `ncei`, `nws.station` for `nws` |
| `cloud_cover` | no |  |

Outside the contract, deprecated: `seriesAt(string $station, SeriesQuery $query)` (`ncei`), which
`series()` with a station's `Identifier` replaced.

## Which API

NOAA serves its observations through several APIs; two are read, each a source of its own (option
`api`), both under the name `noaa`:

- **`ncei`** (the default): NCEI's [Access Data Service](https://www.ncei.noaa.gov/support/access-data-service-api-user-documentation)
  (`https://www.ncei.noaa.gov/access/services/data/v1`) for the records, and its search service
  (`/access/services/search/v1/data`) to find a station near a point. No key, the whole world, the
  records back to the 19th century for some stations. Three datasets, chosen by the query's step:

  | Step | Dataset | What a point is |
  |---|---|---|
  | `SeriesQuery::DAILY` (`P1D`) | `daily-summaries` (GHCN-Daily) | the station's day |
  | `P1M` | `global-summary-of-the-month` (GSOM) | the month's summary |
  | `P1Y` | `global-summary-of-the-year` (GSOY) | the year's summary |

- **`nws`**: the National Weather Service's API (`https://api.weather.gov`), for a point of the
  United States (and its territories): `points/{lat},{lon}` gives the grid of the forecast office
  and the stations near; the nearest station's `observations` (the NWS keeps the last seven days)
  and the grid's raw forecast (`gridpoints/{office}/{x},{y}`, seven days ahead) are read and joined.

Left out: **CDO v2** (`www.ncei.noaa.gov/cdo-web/api/v2`), which needs a token and serves the same
GHCN-Daily and summaries; the **Global Summary of the Day** (GSOD), whose metric conversion turns
the missing-value markers into numbers (a gust of 999.9 knots answered as 514.4) and leaves the
temperatures in °F, and which lags GHCN-Daily; the **hourly** ISD records (`global-hourly`),
whose values are packed strings (`+0123,1`) - a later addition.

## Calls

| Method | Reads | API |
|---|---|---|
| `series()` (`ncei`) | GHCN-Daily, GSOM or GSOY element names (`TMAX`, `TMIN`, `TAVG`, `PRCP`, `SNOW`, `SNWD`, `AWND`, `WSF2`, `EMXT`, `DX90`...; any case); a `Point`; the period (a date alone is its day; the last seven days by default); the step above; no aggregation, or the element's own (`TMAX` is the day's `max`) | the search (`dataset`, `bbox` of `radius_km` around the point as north, west, south, east, `startDate`, `endDate`, `dataTypes`), then the data (`stations`, `dataTypes`, `units=metric`, `format=json`, the station's name and place) |
| `series()` at a station (`ncei`) | the same, the query's place a GHCN station's `Identifier` (`ghcn:FR000007190`, `USW00094728`, `JA000047662`) in place of the point: that station's records. Unknown, or no record and not found: no series (an empty list) | the data alone (and the search when the station has no record over the period, for its place) |
| `seriesAt($station, SeriesQuery)` (`ncei`) | deprecated (`E_USER_DEPRECATED`): `series()` with the station, the query's point kept for the series of an unknown station | the data alone |
| `entity()` (`ncei`) | a GHCN station (`ghcn:USW00094728`, its page, or the bare id): its name, place and elevation (`elevation` in m), the period of its records (`period_start`, `period_end`), each element and the period it covers (`variable`: `TMAX: 1869-01-01/2026-10-10`); kind `station`, domain earth | the search (`stations`), then a day of its data (its elevation, which the search does not give) |
| `entity()` (`nws`) | an NWS station (`nws.station:KNYC`): its name, place, `elevation` (m), `time_zone`, `network` (ASOS...) | `stations/{id}` |
| `series()` (`nws`) | the NWS's names (`temperature`, `dewpoint`, `relativeHumidity`, `windSpeed`, `windDirection`, `windGust`, `visibility`, `heatIndex`, `windChill` in both; `barometricPressure`, `seaLevelPressure`, `precipitationLastHour`... observed only; `quantitativePrecipitation`, `probabilityOfPrecipitation`, `skyCover`, `apparentTemperature`, `snowfallAmount`... forecast only); a `Point`; the period (today and six days ahead by default, a date alone its whole day in the zone); hourly, or `DAILY` with `mean`, `max`, `min` or `sum` of the day | `points`, the grid's `stations?limit=1`, `stations/{id}/observations?start&end&limit=500` when the period starts before now, the grid when it ends after |
| `series()` at a station (`nws`) | the same, the query's place an NWS station's `Identifier` (`nws.station:KNYC`): that station's observations, then the forecast of its point's grid; an unknown station: no series | `stations/{id}`, then as above without the grid's station list |

**The nearest station** (`ncei`): the search answers the stations of the box that have one of the
elements at some time in the period; each element's own date range is read, and the station kept
is the one that covers the most elements asked, then the nearest (great circle). Around Strasbourg
for `TMAX`, `TMIN`, `PRCP` in August 2026, Kehl (GME00124450) is nearer, but has had no `TMAX`
since 2003: Strasbourg-Entzheim (FR000007190) answers. No station in the box: one series per
variable without points, at the point asked.

**A point of a series** (`ncei`): one per day, month or year of the period, null where the station
has no value (NCEI leaves the day out); the time is the step's first day at midnight in the
query's zone (UTC by default: a GHCN day is the station's own observing day, which NCEI does not
date further). The series' `place` is the station's (with its elevation), its `label` the
station's name and id (`STRASBOURG ENTZHEIM, FR (daily-summaries:FR000007190)`), its `aggregation`
what a GHCN-Daily element is (`TMAX` max, `TMIN` min, `TAVG` and `AWND` mean, `PRCP`, `SNOW`,
`EVAP`, `TSUN` sum, the peak gusts max; null for the monthly and yearly summaries, which mix them:
`TMAX` of a month is the mean of its daily maxima, `EMXT` its extreme); `forecast` false.

Units, asked in metric (NCEI's conversion): °C (`TMAX`, `TMIN`, `TAVG`, `TOBS`, `EMXT`, `EMNT`,
`CLDD`, `HTDD`...), mm (`PRCP`, `SNOW`, `SNWD`, `EVAP`, `WESD`, `EMXP`...), m/s (`AWND`, `WSF2`,
`WSF5`, `WSFG`...), ° (`WDF2`, `WDF5`...), min (`TSUN`), % (`PSUN`, cloudiness, humidity); none
for a count of days (`DX90`, `DP10`...) or an element not listed.

**The NWS joined** (`nws`): before the current hour, the observations of the station nearest the
point's grid, at their report times (about :51 each hour, more when the weather turns: the series'
`step` is then null, irregular); from the current hour on, the grid's forecast, each interval of
its layers (`2026-10-15T00:00:00+00:00/PT6H`) made hourly: a state (a temperature, a humidity)
holds over its interval, an amount (`quantitativePrecipitation`, `snowfallAmount`,
`iceAccumulation`) is given at its interval's start, the other hours null - so that a day's sum
is the day's amount. `forecast` is false for observations alone, true for the forecast alone, null
when joined; the `label` names the station, the grid, or both (`New York City, Central Park
(KNYC), then NWS OKX grid 34,45`); the `place` is the station's when observed, the point's when
forecast. Times are given in the point's zone (`America/New_York`; option `timezone`, or the
query's). By day, the points of each day of the zone are aggregated as asked. The units are the
API's WMO codes, written as symbols: `wmoUnit:degC` °C, `wmoUnit:km_h-1` km/h, `wmoUnit:Pa` Pa,
`wmoUnit:percent` %, `wmoUnit:mm` mm; values as given (the forecast's °C are made from °F:
16.666666666666668). A point outside the NWS's grids (Strasbourg: 404 "Data Unavailable For
Requested Point"): one series per variable without points, after one call.

Refused before any call: a `BBox` (a station or a grid answers for a point, never an area), a step
neither API serves (`PT1H` from NCEI, `P1M` from the NWS), an aggregation NCEI does not hold
(`TMAX` as a mean) or a daily NWS query without one, `seriesAt()` on the NWS, a station id that is
not one, a station of the other API's scheme (an NWS station of NCEI, a GHCN station of the NWS)
or a WIGOS id (neither API reads it). `search()`, `products()`, `occurrences()`: not supported.

## Limits

Neither API publishes a rate (NCEI's CDO v2 allowed five calls a second, 10,000 a day; the NWS
says its limit is "generous" and answers 429 beyond it, a `RateLimitedException`). A series query
costs NCEI two calls (one at a station), a station two; the NWS two to four (the point, its station, the
observations, the grid; a week of observations is about 200, one page of 500). The NWS answers
403 to a call without a User-Agent; a 5xx from either is an `UnavailableException` (retry). NCEI answers 400 with a JSON body that says which parameter it refuses (a
`ProviderException` with its message).

## The Collector

Nothing to follow: series have no identity, and a station gives no other base's id (NCEI's search
does not give a GHCN station's WMO index, which only its station list file holds; the NWS's
names none). Wikidata keeps WIGOS ids (P4136, 1,594 items on 2026-10-15), which neither API
reads.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Scheme;
use Omnilab\Noaa\NoaaSourceFactory;
use Omnilab\Source\SeriesQuery;
use Symfony\Component\HttpClient\HttpClient;

$http = HttpClient::create(['timeout' => 60]);
$ncei = (new NoaaSourceFactory($http))->create(['mailto' => 'you@example.org']);
$nws = (new NoaaSourceFactory($http))->create(['mailto' => 'you@example.org', 'api' => 'nws']);

// Central Park's own daily records (GHCN-Daily).
[$tmax, $prcp] = $ncei->series(new SeriesQuery(['TMAX', 'PRCP'], Identifier::of(Scheme::GHCN, 'USW00094728'), new DateTimeImmutable('2026-10-01'), new DateTimeImmutable('2026-10-07'), SeriesQuery::DAILY));
printf("%s: TMAX %s %s, PRCP %s %s\n", $tmax->label, implode(' ', $tmax->values()), $tmax->unit, implode(' ', $prcp->values()), $prcp->unit);

// The NWS's forecast for a point of Manhattan, tomorrow, hourly.
[$temperature] = $nws->series(new SeriesQuery(['temperature'], new Point(40.7812, -73.9665), new DateTimeImmutable('tomorrow'), new DateTimeImmutable('tomorrow')));
printf("%s: %d hours, %s to %s %s, forecast %s\n", $temperature->label, count($temperature), $temperature->min(), $temperature->max(), $temperature->unit, var_export($temperature->forecast, true));
```

```
NY CITY CENTRAL PARK, NY US (daily-summaries:USW00094728): TMAX 24.4 28.3 21.1 16.7 20 15.6 18.3 °C, PRCP 0 0 0 3.8 0 0 0 mm
NWS OKX grid 34,45: 24 hours, 7.2222222222222 to 16.666666666667 °C, forecast true
```

(as answered on 2026-10-27: NCEI's call at the station, the NWS's point and grid; the NWS's grid gives its own unit, here °C)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        noaa: { factory: noaa, options: { mailto: '%env(OMNILAB_MAILTO)%' } }
        nws: { factory: noaa, options: { mailto: '%env(OMNILAB_MAILTO)%', api: nws } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

NOAA's own: NCEI answers 400 with a JSON body that names the parameter it refuses, read into the
`ProviderException`'s message; the NWS answers 403 without a User-Agent, 429 past its "generous"
limit. An unknown station answers an empty list (no series); no station near a point, a series
without points per variable. Before any call (`NotSupportedException`): a box, a station of the
other API's scheme, a step NCEI's summaries do not have, an aggregation of NCEI's own summaries, a
daily NWS query without one.

## Verified, and not

Against the real services on 2026-10-15: GHCN-Daily at Strasbourg for 2026-08-01 to 08-07
(Strasbourg-Entzheim chosen over the nearer Kehl; `TMAX` 30.7, 33.7, 37.6, 36.3, 33.4, 29.9,
30.0 °C, `TMIN` down to 14.1, `PRCP` 14.3 mm on the 4th), its months of 2025 (`TAVG` 4.1 °C in
January to 21.8 in June, `PRCP` 108.6 mm in September) and its years 2015 to 2025 (`TAVG` 11.3 to
13.2 °C, `PRCP` 474.7 to 796.6 mm); Tokyo for 2025-08-01 to 08-07 (JA000047662: `TAVG` 26.6 to
32.5 °C, `PRCP` 26.4 mm on the 2nd; `TMIN` null on the 6th and 7th, as NCEI has it); New York's
Central Park by its id (USW00094728, 2026-10-05 to 10-11: `TMAX` 15.6 to 22.8 °C, `PRCP` 46.7 mm
on the 11th, `AWND` 1.2 to 3.2 m/s); no station around 0° N 140° W; the NWS at Central Park from
the day before to the day after (KNYC's observations, then the OKX 34,45 grid: 13.3 to 24.4 °C,
48 to 95 % humidity, up to 5.08 mm in six hours forecast, 1010.5 to 1018.3 hPa observed in Pa); the
NWS refusing Strasbourg (404), a call without a User-Agent (403), and the observations' next page
losing the period's start (it goes on a week back: the source stops paging there). GHCN-Daily ended
on 2026-08-31 for Strasbourg and on 2025-08-24 for Tokyo on 2026-10-15, on 2026-10-11 for Central
Park: the European and Asian stations come later than the American ones. Later that day, the
stations: Central Park's records by `ghcn:USW00094728` in place of a point (2026-10-01 to 07:
`TMAX` 24.4, 28.3, 21.1, 16.7, 20, 15.6, 18.3 °C, `PRCP` 3.8 mm on the 4th, one call), Strasbourg-
Entzheim as an entity (`ghcn:FR000007190`: 48.5492, 7.6403, 150 m, records from 1923-05-01 to
2026-08-31, five elements, `TMAX` from 1924), KNYC as an entity (New York City, Central Park,
40.78333, -73.96667, 46.9 m, America/New_York, ASOS) and its records then its grid's forecast
(72 points, 13.3 to 24.4 °C, four calls: the station, its point, its observations, the grid), an unknown GHCN station (no series) and
an unknown NWS station (404, null). The fixtures in
`Tests/Fixtures/` are the answers of that day (the NWS's cut to the fields read).

Again on 2026-10-27: Central Park's daily TMAX and PRCP of 1 to 7 October 2026 at its GHCN station,
the NWS's hourly forecast for a point of Manhattan (the example above).

Not verified: a 429 from either (none met; the test feeds one); the NWS's territories outside the
50 states; the monthly and yearly summaries' less common elements (only `TAVG` and `PRCP` read
live).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by NOAA, NCEI or the National Weather Service. NOAA's data are in the U.S. public
domain; credit NOAA (NCEI, the National Weather Service) where they are shown, and send a
User-Agent that says who calls, as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-noaa#terms-of-use) says. Whoever runs
the package is responsible for any use made of what it returns: see the README's
[Responsibility](https://github.com/glitchr-omnisense/omnilab-noaa#responsibility) section.
