<?php

namespace Omnilab\Noaa;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\Point;
use Omnilab\Model\Series;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\SeriesQuery;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * NOAA's weather and climate observations, as series at a point, through
 * one of two APIs, each a source of its own ($api):
 *
 * - NCEI (the default): the National Centers for Environmental
 *   Information's Access Data Service (www.ncei.noaa.gov/access/services/
 *   data/v1), the station records of the whole world - GHCN-Daily by day,
 *   the Global Summary of the Month and of the Year - by the elements' own
 *   names (TMAX, TMIN, TAVG, PRCP, SNOW, SNWD, AWND...) in metric units, at
 *   the nearest station that has them over the period (its search service
 *   finds it), or at a station named (seriesAt());
 * - NWS: the National Weather Service's API (api.weather.gov), for a point
 *   of the United States: the observations of the nearest station (the
 *   last seven days) and the forecast of its 2.5 km grid (seven days
 *   ahead), joined when a period spans both, by the API's own names
 *   (temperature, dewpoint, relativeHumidity, windSpeed,
 *   quantitativePrecipitation...).
 *
 * No key: both are open (NOAA's data are in the U.S. public domain). The
 * NWS refuses a call without a User-Agent (403); the factory sends one.
 * CDO v2, NCEI's older API, needs a token and serves the same datasets:
 * it is not used.
 */
final class NoaaSource extends HttpSource
{
    public const NCEI = 'ncei';
    public const NWS = 'nws';

    public const NCEI_URI = 'https://www.ncei.noaa.gov/access/services/';
    public const NWS_URI = 'https://api.weather.gov/';

    /** NCEI's datasets, by the step that reads them. */
    public const DATASETS = [SeriesQuery::DAILY => 'daily-summaries', 'P1M' => 'global-summary-of-the-month', 'P1Y' => 'global-summary-of-the-year'];

    /**
     * The units of NCEI's elements asked in metric (NCEI's documentation of
     * GHCN-Daily and of the global summaries); an element not listed has no
     * unit here (a count of days, a flag, a time).
     */
    public const NCEI_UNITS = [
        'TMAX' => '°C', 'TMIN' => '°C', 'TAVG' => '°C', 'TOBS' => '°C', 'MNPN' => '°C', 'MXPN' => '°C', 'EMXT' => '°C', 'EMNT' => '°C', 'CLDD' => '°C', 'HTDD' => '°C',
        'PRCP' => 'mm', 'SNOW' => 'mm', 'SNWD' => 'mm', 'EVAP' => 'mm', 'WESD' => 'mm', 'WESF' => 'mm', 'EMXP' => 'mm', 'EMSN' => 'mm', 'EMSD' => 'mm',
        'AWND' => 'm/s', 'WSF1' => 'm/s', 'WSF2' => 'm/s', 'WSF5' => 'm/s', 'WSFG' => 'm/s', 'WSFI' => 'm/s', 'WSFM' => 'm/s',
        'WDF1' => '°', 'WDF2' => '°', 'WDF5' => '°', 'WDFG' => '°', 'WDFI' => '°', 'WDFM' => '°',
        'TSUN' => 'min', 'PSUN' => '%', 'ACMC' => '%', 'ACMH' => '%', 'ACSC' => '%', 'ACSH' => '%', 'RHAV' => '%', 'RHMN' => '%', 'RHMX' => '%',
    ];

    /** What a day of GHCN-Daily's elements stands for. */
    public const NCEI_DAILY_AGGREGATIONS = ['TMAX' => Series::MAX, 'TMIN' => Series::MIN, 'TAVG' => Series::MEAN, 'PRCP' => Series::SUM, 'SNOW' => Series::SUM, 'EVAP' => Series::SUM, 'AWND' => Series::MEAN, 'WSF2' => Series::MAX, 'WSF5' => Series::MAX, 'WSFG' => Series::MAX, 'TSUN' => Series::SUM];

    /** The NWS's amounts: a forecast interval's value is its total, given at its start (the other hours null). */
    public const NWS_AMOUNTS = ['quantitativePrecipitation', 'snowfallAmount', 'iceAccumulation'];

    /** The NWS's units (WMO codes), as written here. */
    private const WMO_UNITS = ['degC' => '°C', 'degF' => '°F', 'percent' => '%', 'km_h-1' => 'km/h', 'm_s-1' => 'm/s', 'degree_(angle)' => '°', 'mm' => 'mm', 'm' => 'm', 'km' => 'km', 'Pa' => 'Pa', 'hPa' => 'hPa', 'cm' => 'cm'];

    /** How far back the NWS keeps a station's observations, in days. */
    public const NWS_OBSERVATION_DAYS = 7;

    /** The most observations the NWS answers a page. */
    public const NWS_PAGE = 500;

    /**
     * @param string      $api       NCEI or NWS
     * @param float       $radius    how far from a Point NCEI's nearest station is looked for, in kilometres
     * @param string|null $now       the time taken for now (a test's), the real one when null
     * @param string|null $timezone  the zone of the days and times when a query does not say: "local" (the NWS point's own zone; UTC for NCEI, whose days are the stations' own), "UTC", "America/New_York"...; null: local for the NWS, UTC for NCEI
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly string $api = self::NCEI,
        ?string $baseUri = null,
        private readonly float $radius = 50.0,
        private ?string $timezone = null,
        private readonly ?string $now = null,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        if (!\in_array($api, [self::NCEI, self::NWS], true)) {
            throw new \InvalidArgumentException(\sprintf('NOAA\'s APIs here are "%s" and "%s", not "%s".', self::NCEI, self::NWS, $api));
        }
        $this->timezone = $timezone ?? (self::NWS === $api ? 'local' : 'UTC');
        parent::__construct($http, $baseUri ?? (self::NWS === $api ? self::NWS_URI : self::NCEI_URI), $headers, $throttle);
    }

    public function getName(): string
    {
        return 'noaa';
    }

    public function capabilities(): array
    {
        return self::NWS === $this->api
            ? [Capability::SERIES, Capability::FORECAST, Capability::HISTORICAL]
            : [Capability::SERIES, Capability::HISTORICAL];
    }

    public function series(SeriesQuery $query): array
    {
        if (!$query->place instanceof Point) {
            throw NotSupportedException::operation('noaa', 'serve a series over an area (a Point: the nearest station answers)');
        }

        return self::NWS === $this->api ? $this->nws($query, $query->place) : $this->ncei($query, null);
    }

    /**
     * NCEI's series at a station named by its id in the dataset
     * ("FR000007190", "USW00094728"), outside the contract: the core has
     * no scheme for a station yet. The query's place is not read.
     *
     * @return list<Series>
     */
    public function seriesAt(string $station, SeriesQuery $query): array
    {
        if (self::NCEI !== $this->api) {
            throw NotSupportedException::operation('noaa', 'serve a station by its id through the NWS (configured api: ncei)');
        }
        if (!preg_match('~^[A-Z0-9]{6,20}$~', $station = strtoupper(trim($station)))) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a station id of NCEI (FR000007190, USW00094728).', $station));
        }

        return $this->ncei($query, $station);
    }

    /** @return list<Series> */
    private function ncei(SeriesQuery $query, ?string $station): array
    {
        $dataset = self::DATASETS[$query->step] ?? throw NotSupportedException::operation('noaa', \sprintf('serve NCEI\'s summaries by a step of %s (P1D: GHCN-Daily, P1M: the month\'s, P1Y: the year\'s)', $query->step));
        $variables = array_map(static fn (string $v) => strtoupper(trim($v)), $query->variables);
        foreach ($variables as $variable) {
            $own = SeriesQuery::DAILY === $query->step ? (self::NCEI_DAILY_AGGREGATIONS[$variable] ?? null) : null;
            if (null !== $query->aggregation && $query->aggregation !== $own) {
                throw NotSupportedException::operation('noaa', \sprintf('aggregate %s as "%s": NCEI serves its stations\' own summaries (TMAX is the day\'s maximum, PRCP its total...)', $variable, $query->aggregation));
            }
        }
        $zone = new \DateTimeZone($query->timezone ?? ('local' === $this->timezone ? 'UTC' : $this->timezone));
        $today = $this->now()->setTimezone($zone)->setTime(0, 0);
        $to = self::date($query->to ?? $today, $zone);
        $from = self::date($query->from ?? $to->modify('-6 days'), $zone);

        $name = null;
        $place = $query->place;
        if (null === $station) {
            $found = $this->nearest($dataset, $query->place, $variables, $from, $to);
            if (null === $found) {
                return array_map(fn (string $v) => $this->nceiSeries($v, $query, $place, [], null, null), $variables);
            }
            [$station, $name, $place] = $found;
        }

        $rows = $this->getJson('data/v1', [
            'dataset' => $dataset,
            'stations' => $station,
            'startDate' => $from->format('Y-m-d'),
            'endDate' => $to->format('Y-m-d'),
            'dataTypes' => implode(',', $variables),
            'format' => 'json',
            'units' => 'metric',
            'includeStationName' => 'true',
            'includeStationLocation' => '1',
        ]) ?? [];

        $byDate = [];
        foreach ($rows as $row) {
            if (!\is_array($row) || !isset($row['DATE'])) {
                continue;
            }
            $byDate[(string) $row['DATE']] = $row;
            $name ??= isset($row['NAME']) ? (string) $row['NAME'] : null;
            if (isset($row['LATITUDE'], $row['LONGITUDE']) && is_numeric($row['LATITUDE']) && is_numeric($row['LONGITUDE'])) {
                $place = new Point((float) $row['LATITUDE'], (float) $row['LONGITUDE'], isset($row['ELEVATION']) && is_numeric($row['ELEVATION']) ? (float) $row['ELEVATION'] : null);
            }
        }

        // Every step of the period, null where the station has nothing.
        $format = match ($query->step) {
            SeriesQuery::DAILY => 'Y-m-d',
            'P1M' => 'Y-m',
            default => 'Y',
        };
        $steps = [];
        $cursor = match ($query->step) {
            SeriesQuery::DAILY => $from,
            'P1M' => $from->modify('first day of this month'),
            default => $from->setDate((int) $from->format('Y'), 1, 1),
        };
        $interval = new \DateInterval($query->step);
        while ($cursor <= $to) {
            $steps[$cursor->format($format)] = $cursor;
            $cursor = $cursor->add($interval);
        }

        $series = [];
        foreach ($variables as $variable) {
            $points = [];
            foreach ($steps as $key => $time) {
                $value = $byDate[$key][$variable] ?? null;
                $points[] = [$time, is_numeric(trim((string) $value)) ? self::number(trim((string) $value)) : null];
            }
            $series[] = $this->nceiSeries($variable, $query, $place, $points, $station, $name);
        }

        return $series;
    }

    /** @param list<array{\DateTimeImmutable, int|float|null}> $points */
    private function nceiSeries(string $variable, SeriesQuery $query, Point $place, array $points, ?string $station, ?string $name): Series
    {
        return new Series(
            variable: $variable,
            unit: self::NCEI_UNITS[$variable] ?? null,
            place: $place,
            points: $points,
            step: $query->step,
            aggregation: SeriesQuery::DAILY === $query->step ? (self::NCEI_DAILY_AGGREGATIONS[$variable] ?? null) : null,
            label: null === $station ? null : trim(($name ?? '').' ('.self::DATASETS[$query->step].':'.$station.')'),
            forecast: false,
            source: 'noaa',
        );
    }

    /**
     * NCEI's nearest station to a point that has every variable over the
     * period (else the nearest that has one of them): [id, name, place].
     *
     * @param list<string> $variables
     *
     * @return array{string, string|null, Point}|null
     */
    private function nearest(string $dataset, Point $point, array $variables, \DateTimeImmutable $from, \DateTimeImmutable $to): ?array
    {
        $box = $point->around($this->radius);
        $found = $this->getJson('search/v1/data', [
            'dataset' => $dataset,
            // North, west, south, east.
            'bbox' => implode(',', [$box->north, $box->west, $box->south, $box->east]),
            'startDate' => $from->format('Y-m-d').'T00:00:00',
            'endDate' => $to->format('Y-m-d').'T23:59:59',
            'dataTypes' => implode(',', $variables),
            'limit' => 1000,
        ]) ?? [];

        $candidates = [];
        foreach ($found['results'] ?? [] as $result) {
            $station = $result['stations'][0] ?? null;
            $coordinates = $result['location']['coordinates'] ?? $result['centroid'] ?? null;
            if (!\is_array($station) || !isset($station['id']) || !\is_array($coordinates) || \count($coordinates) < 2) {
                continue;
            }
            $covered = 0;
            foreach ($station['dataTypes'] ?? [] as $type) {
                $start = self::time($type['dateRange']['start'] ?? $type['startDate'] ?? null);
                $end = self::time($type['dateRange']['end'] ?? $type['endDate'] ?? null);
                if (\in_array($type['id'] ?? null, $variables, true) && null !== $start && null !== $end && $start <= $to->setTime(23, 59, 59) && $end >= $from) {
                    ++$covered;
                }
            }
            $place = new Point((float) $coordinates[1], (float) $coordinates[0]);
            $id = (string) $station['id'];
            // Several results for one station (one per year, in some datasets): the best coverage kept.
            if ($covered > 0 && (!isset($candidates[$id]) || $candidates[$id][0] < $covered)) {
                $candidates[$id] = [$covered, self::distance($point, $place), $id, isset($station['name']) ? (string) $station['name'] : null, $place];
            }
        }
        if (!$candidates) {
            return null;
        }
        // The most variables covered, then the nearest.
        usort($candidates, static fn (array $a, array $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        return [$candidates[0][2], $candidates[0][3], $candidates[0][4]];
    }

    /**
     * The NWS's series at a point of the United States: the observations of
     * its nearest station before now, its grid's forecast from now on.
     *
     * @return list<Series>
     */
    private function nws(SeriesQuery $query, Point $point): array
    {
        if (!\in_array($query->step, [SeriesQuery::HOURLY, SeriesQuery::DAILY], true)) {
            throw NotSupportedException::operation('noaa', \sprintf('serve the NWS by a step of %s (PT1H, or P1D aggregated)', $query->step));
        }
        $daily = SeriesQuery::DAILY === $query->step;
        if ($daily !== (null !== $query->aggregation) || ($daily && !\in_array($query->aggregation, [Series::MEAN, Series::MAX, Series::MIN, Series::SUM], true))) {
            throw NotSupportedException::operation('noaa', $daily ? 'serve the NWS by day but as the mean, max, min or sum of its hours' : 'aggregate the NWS other than by day');
        }

        $where = $this->getJson(\sprintf('points/%s,%s', self::coordinate($point->latitude), self::coordinate($point->longitude)), [], ['Accept' => 'application/geo+json']);
        $properties = $where['properties'] ?? null;
        $zoneName = (string) ($properties['timeZone'] ?? 'UTC');
        $zone = new \DateTimeZone($query->timezone ?? ('local' === $this->timezone ? $zoneName : $this->timezone));
        $now = $this->now();
        $from = null === $query->from ? $now->setTimezone($zone)->setTime(0, 0) : self::date($query->from, $zone);
        $to = (null === $query->to ? $from->modify('+6 days') : self::date($query->to, $zone))->setTime(23, 59, 59);
        if (!\is_array($properties)) {
            // Outside the NWS's grids (a point beyond the United States): nothing.
            return array_map(static fn (string $v) => new Series($v, null, $point, [], $daily ? SeriesQuery::DAILY : null, $query->aggregation, forecast: null, source: 'noaa'), $query->variables);
        }

        $observed = [];
        $forecast = [];
        $units = [];
        $place = $point;
        $stationLabel = null;
        $gridLabel = null;
        if ($from < $now) {
            $stations = isset($properties['observationStations']) ? $this->getJson((string) $properties['observationStations'], ['limit' => 1], ['Accept' => 'application/geo+json']) : null;
            $station = $stations['features'][0] ?? null;
            if (\is_array($station) && isset($station['properties']['stationIdentifier'])) {
                $id = (string) $station['properties']['stationIdentifier'];
                $coordinates = $station['geometry']['coordinates'] ?? null;
                if (\is_array($coordinates) && \count($coordinates) >= 2) {
                    $place = new Point((float) $coordinates[1], (float) $coordinates[0], isset($station['properties']['elevation']['value']) ? (float) $station['properties']['elevation']['value'] : null);
                }
                $stationLabel = trim(($station['properties']['name'] ?? '').' ('.$id.')');
                $end = $to < $now ? $to : $now;
                $next = \sprintf('stations/%s/observations', rawurlencode($id));
                $parameters = ['start' => self::utc($from), 'end' => self::utc($end), 'limit' => self::NWS_PAGE];
                // Newest first, NWS_PAGE a page (a week is about 200); the next page's cursor forgets the start: stop once past it.
                for ($page = 0; null !== $next && $page < 10; ++$page) {
                    $data = $this->getJson($next, $parameters, ['Accept' => 'application/geo+json']) ?? [];
                    $oldest = null;
                    foreach ($data['features'] ?? [] as $feature) {
                        $time = self::time($feature['properties']['timestamp'] ?? null);
                        if (null === $time) {
                            continue;
                        }
                        $oldest = null === $oldest || $time < $oldest ? $time : $oldest;
                        foreach ($query->variables as $variable) {
                            $measure = $feature['properties'][$variable] ?? null;
                            if (\is_array($measure)) {
                                $units[$variable] ??= self::unit($measure['unitCode'] ?? null);
                                $observed[$variable][$time->getTimestamp()] = [$time->setTimezone($zone), self::value($measure['value'] ?? null)];
                            }
                        }
                    }
                    $next = \count($data['features'] ?? []) >= self::NWS_PAGE && null !== $oldest && $oldest > $from && isset($data['pagination']['next']) ? (string) $data['pagination']['next'] : null;
                    $parameters = [];
                }
            }
        }
        // The forecast from the current hour on; the observations before it.
        $hour = $now->setTime((int) $now->format('H'), 0);
        if ($to >= $hour && isset($properties['forecastGridData'])) {
            $grid = $this->getJson((string) $properties['forecastGridData'], [], ['Accept' => 'application/geo+json']);
            foreach ($query->variables as $variable) {
                $layer = $grid['properties'][$variable] ?? null;
                if (!\is_array($layer) || !\is_array($layer['values'] ?? null)) {
                    continue;
                }
                $units[$variable] ??= self::unit($layer['uom'] ?? null);
                $amount = \in_array($variable, self::NWS_AMOUNTS, true);
                foreach ($layer['values'] as $value) {
                    [$start, $duration] = array_pad(explode('/', (string) ($value['validTime'] ?? ''), 2), 2, null);
                    $start = self::time($start);
                    if (null === $start || null === $duration) {
                        continue;
                    }
                    try {
                        $end = $start->add(new \DateInterval($duration));
                    } catch (\Exception) {
                        continue;
                    }
                    for ($time = $start; $time < $end; $time = $time->modify('+1 hour')) {
                        if ($time >= $hour) {
                            $forecast[$variable][$time->getTimestamp()] = [$time->setTimezone($zone), $amount && $time > $start ? null : self::value($value['value'] ?? null)];
                        }
                    }
                }
            }
            $gridLabel = isset($grid['properties']['gridId']) ? \sprintf('NWS %s grid %s,%s', $grid['properties']['gridId'], $grid['properties']['gridX'] ?? '?', $grid['properties']['gridY'] ?? '?') : null;
        }

        $series = [];
        foreach ($query->variables as $variable) {
            $points = [];
            foreach ($observed[$variable] ?? [] as $at => $observation) {
                if ($at >= $from->getTimestamp() && $at <= $to->getTimestamp() && $at < $hour->getTimestamp()) {
                    $points[$at] = $observation;
                }
            }
            foreach ($forecast[$variable] ?? [] as $at => $hourly) {
                if ($at >= $from->getTimestamp() && $at <= $to->getTimestamp()) {
                    $points[$at] = $hourly;
                }
            }
            ksort($points);
            $points = array_values($points);
            $hasObserved = [] !== array_filter(array_keys($observed[$variable] ?? []), static fn (int $at) => $at < $hour->getTimestamp() && $at >= $from->getTimestamp() && $at <= $to->getTimestamp());
            $hasForecast = [] !== ($forecast[$variable] ?? []) && $to >= $hour;
            if ($daily) {
                $points = self::days($points, (string) $query->aggregation, $zone);
            }
            $series[] = new Series(
                variable: $daily ? $variable.'_'.$query->aggregation : $variable,
                unit: $units[$variable] ?? null,
                place: $hasObserved ? $place : $point,
                points: $points,
                // Observations come at the station's report times (about :51 every hour, more when the weather turns): irregular.
                step: $daily ? SeriesQuery::DAILY : ($hasObserved ? null : SeriesQuery::HOURLY),
                aggregation: $daily ? $query->aggregation : null,
                label: match (true) {
                    $hasObserved && $hasForecast => $stationLabel.', then '.$gridLabel,
                    $hasObserved => $stationLabel,
                    default => $gridLabel,
                },
                forecast: match (true) {
                    $hasObserved && $hasForecast => null,
                    $hasForecast => true,
                    $hasObserved => false,
                    default => null,
                },
                source: 'noaa',
            );
        }

        return $series;
    }

    /**
     * Points made daily: each day's aggregate of the points that have a
     * value (null when none has), at the day's midnight in the zone.
     *
     * @param list<array{\DateTimeImmutable, int|float|null}> $points
     *
     * @return list<array{\DateTimeImmutable, int|float|null}>
     */
    private static function days(array $points, string $aggregation, \DateTimeZone $zone): array
    {
        $days = [];
        foreach ($points as [$time, $value]) {
            $day = $time->setTimezone($zone)->format('Y-m-d');
            $days[$day] ??= [];
            if (null !== $value) {
                $days[$day][] = $value;
            }
        }
        $daily = [];
        foreach ($days as $day => $values) {
            $daily[] = [new \DateTimeImmutable($day, $zone), $values ? match ($aggregation) {
                Series::MEAN => round(array_sum($values) / \count($values), 2),
                Series::MAX => max($values),
                Series::MIN => min($values),
                Series::SUM => round(array_sum($values), 2),
            } : null];
        }

        return $daily;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->now ?? 'now', new \DateTimeZone('UTC'));
    }

    /** The calendar day of a date as written, at midnight in the zone. */
    private static function date(\DateTimeInterface $date, \DateTimeZone $zone): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d'), $zone);
    }

    private static function utc(\DateTimeInterface $time): string
    {
        return \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /** "wmoUnit:degC" as "°C". */
    private static function unit(mixed $code): ?string
    {
        if (!\is_string($code) || '' === $code) {
            return null;
        }
        $code = preg_replace('~^(?:wmoUnit|nwsUnit|unit):~', '', $code);

        return self::WMO_UNITS[$code] ?? $code;
    }

    private static function value(mixed $value): int|float|null
    {
        return \is_int($value) || \is_float($value) ? $value : null;
    }

    private static function number(string $value): int|float
    {
        return str_contains($value, '.') || str_contains($value, 'e') || str_contains($value, 'E') ? (float) $value : (int) $value;
    }

    /** A coordinate as the NWS takes it: four decimals at most (it redirects a longer one). */
    private static function coordinate(float $degrees): string
    {
        return rtrim(rtrim(\sprintf('%.4F', $degrees), '0'), '.');
    }

    /** The great-circle distance in kilometres. */
    private static function distance(Point $a, Point $b): float
    {
        $dLat = deg2rad($b->latitude - $a->latitude);
        $dLon = deg2rad($b->longitude - $a->longitude);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($a->latitude)) * cos(deg2rad($b->latitude)) * sin($dLon / 2) ** 2;

        return 2 * 6371.0 * asin(min(1.0, sqrt($h)));
    }
}
