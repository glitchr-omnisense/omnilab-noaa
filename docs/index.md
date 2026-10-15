# omnilab/noaa

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
| `seriesAt($station, SeriesQuery)` (`ncei`) | the same, at a station named by its id (`FR000007190`, `USW00094728`, `JA000047662`): outside the contract, the core having no scheme for a station yet; the query's place is not read | the data alone |
| `series()` (`nws`) | the NWS's names (`temperature`, `dewpoint`, `relativeHumidity`, `windSpeed`, `windDirection`, `windGust`, `visibility`, `heatIndex`, `windChill` in both; `barometricPressure`, `seaLevelPressure`, `precipitationLastHour`... observed only; `quantitativePrecipitation`, `probabilityOfPrecipitation`, `skyCover`, `apparentTemperature`, `snowfallAmount`... forecast only); a `Point`; the period (today and six days ahead by default, a date alone its whole day in the zone); hourly, or `DAILY` with `mean`, `max`, `min` or `sum` of the day | `points`, the grid's `stations?limit=1`, `stations/{id}/observations?start&end&limit=500` when the period starts before now, the grid when it ends after |

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
not one. `entity()`, `search()`, `products()`, `occurrences()`: not supported.

## Limits

Neither API publishes a rate (NCEI's CDO v2 allowed five calls a second, 10,000 a day; the NWS
says its limit is "generous" and answers 429 beyond it, a `RateLimitedException`). A series query
costs NCEI two calls (one with `seriesAt()`), the NWS two to four (the point, its station, the
observations, the grid; a week of observations is about 200, one page of 500). The NWS answers
403 to a call without a User-Agent; a 5xx from either is an `UnavailableException` (retry). NCEI answers 400 with a JSON body that says which parameter it refuses (a
`ProviderException` with its message).

## The Collector

Nothing to follow: series have no identity, and the stations are not entities here (the core has
no scheme for a GHCN station, a WMO station or an NWS station; with one, `entity()` could give a
station with its place, elevation and elements, and Wikidata's P4136, WIGOS station ID, could lead
to it).

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
Park: the European and Asian stations come later than the American ones. The fixtures in
`Tests/Fixtures/` are the answers of that day (the NWS's cut to the fields read).

Not verified: a 429 from either (none met; the test feeds one); the NWS's territories outside the
50 states; the monthly and yearly summaries' less common elements (only `TAVG` and `PRCP` read
live).
