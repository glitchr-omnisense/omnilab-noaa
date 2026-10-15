<?php

namespace Omnilab\Noaa\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\BBox;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Scheme;
use Omnilab\Model\Series;
use Omnilab\Noaa\NoaaSource;
use Omnilab\Noaa\NoaaSourceFactory;
use Omnilab\Source\Capability;
use Omnilab\Source\ProductQuery;
use Omnilab\Source\Query;
use Omnilab\Source\SeriesQuery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded on 2026-10-15. From NCEI's Access Data Service
 * (www.ncei.noaa.gov/access/services): its search service around
 * Strasbourg (a box of 50 km about 48.5734 N 7.7521 E) for GHCN-Daily's
 * TMAX, TMIN and PRCP from 2026-08-01 to 08-07 (fourteen stations), and for
 * the Global Summary of the Month's TAVG and PRCP in 2025 (cut to three of
 * its fourteen stations); the data of Strasbourg-Entzheim (FR000007190) for
 * both; the search around 0 N 140 W in the Pacific (no station). From the
 * NWS API (api.weather.gov) at 07:08:25 UTC: the point of Central Park, New
 * York (40.7789 N 73.9692 W), its nearest station (KNYC), that station's
 * observations from 2026-10-14 00:00 local time (each cut to the fields
 * read here), its grid's forecast (OKX 34,45, cut to six layers); the
 * point of Strasbourg, outside the NWS's grids (404). Later that day, for
 * the stations: NCEI's search for GHCN's USW00094728 (Central Park, its 59
 * elements) and for an unknown id, the station's data of 2026-10-10 (its
 * elevation) and its TMAX and PRCP from 2026-10-01 to 07, the data of
 * an unknown id (an empty list); the NWS's
 * station KNYC.
 */
final class NoaaSourceTest extends TestCase
{
    private const NOW = '2026-10-15T07:08:25Z';

    /** @var list<string> */
    private array $urls = [];

    /** @var list<array<string, string>> */
    private array $headers = [];

    private function source(array $options = [], ?MockResponse $response = null): NoaaSource
    {
        $http = new MockHttpClient(function (string $method, string $url, array $request) use ($response): MockResponse {
            $this->urls[] = urldecode($url);
            $this->headers[] = $request['normalized_headers'] ?? [];
            if ($response) {
                return $response;
            }
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $fixture = match (true) {
                str_ends_with($path, '/search/v1/data') && isset($query['stations']) => 'USW00094728' === $query['stations'] ? 'ncei-search-station-central-park.json' : 'ncei-search-station-unknown.json',
                str_ends_with($path, '/data/v1') && 'USW00094728' === ($query['stations'] ?? null) => '2026-10-10' === $query['startDate'] ? 'ncei-data-central-park-day.json' : 'ncei-data-central-park.json',
                str_ends_with($path, '/data/v1') && 'XX000000000' === ($query['stations'] ?? null) => 'ncei-data-unknown.json',
                '/stations/KNYC' === $path => 'nws-station-knyc.json',
                '/points/40.7833,-73.9667' === $path => 'nws-points-new-york.json',
                str_ends_with($path, '/search/v1/data') && str_starts_with($query['bbox'] ?? '', '0.') => 'ncei-search-pacific.json',
                str_ends_with($path, '/search/v1/data') && 'daily-summaries' === $query['dataset'] => 'ncei-search-strasbourg-daily.json',
                str_ends_with($path, '/search/v1/data') => 'ncei-search-strasbourg-monthly.json',
                str_ends_with($path, '/data/v1') && 'daily-summaries' === $query['dataset'] => 'ncei-data-strasbourg-daily.json',
                str_ends_with($path, '/data/v1') => 'ncei-data-strasbourg-monthly.json',
                '/points/40.7789,-73.9692' === $path => 'nws-points-new-york.json',
                str_starts_with($path, '/points/') => 'nws-points-strasbourg.json',
                str_ends_with($path, '/stations') => 'nws-stations-new-york.json',
                str_ends_with($path, '/observations') => 'nws-observations-knyc.json',
                default => 'nws-gridpoint-okx.json',
            };

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture), ['http_code' => 'nws-points-strasbourg.json' === $fixture ? 404 : 200]);
        });
        $source = (new NoaaSourceFactory($http))->create($options);
        self::assertInstanceOf(NoaaSource::class, $source);

        return $source;
    }

    private static function strasbourg(): Point
    {
        return new Point(48.5734, 7.7521);
    }

    public function testGhcnDailyAtTheNearestStationThatHasEveryElement(): void
    {
        [$tmax, $tmin, $prcp] = $this->source()->series(new SeriesQuery(['TMAX', 'tmin', 'PRCP'], self::strasbourg(), new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-07'), SeriesQuery::DAILY));

        // Kehl (GME00124450) is nearer, but has had no TMAX since 2003: Entzheim answers.
        self::assertSame('STRASBOURG ENTZHEIM, FR (daily-summaries:FR000007190)', $tmax->label);
        self::assertSame([48.5492, 7.6403, 150.0], [$tmax->place->latitude, $tmax->place->longitude, $tmax->place->elevation]);
        self::assertSame(['TMAX', '°C', Series::MAX, SeriesQuery::DAILY, false, 'noaa'], [$tmax->variable, $tmax->unit, $tmax->aggregation, $tmax->step, $tmax->forecast, $tmax->source]);
        self::assertSame([30.7, 33.7, 37.6, 36.3, 33.4, 29.9, 30.0], $tmax->values());
        self::assertSame('2026-08-01T00:00:00+00:00', $tmax->times()[0]->format(\DATE_ATOM));
        self::assertSame(['TMIN', '°C', Series::MIN, 14.1], [$tmin->variable, $tmin->unit, $tmin->aggregation, $tmin->min()]);
        self::assertSame(['PRCP', 'mm', Series::SUM, 14.3], [$prcp->variable, $prcp->unit, $prcp->aggregation, $prcp->max()]);

        self::assertCount(2, $this->urls, 'the search, then the data');
        self::assertStringContainsString('search/v1/data?dataset=daily-summaries&bbox=49.022555587496,7.0732686347051,48.124244412504,8.4309313652949&startDate=2026-08-01T00:00:00&endDate=2026-08-07T23:59:59&dataTypes=TMAX,TMIN,PRCP', $this->urls[0], 'north, west, south, east');
        self::assertStringContainsString('data/v1?dataset=daily-summaries&stations=FR000007190&startDate=2026-08-01&endDate=2026-08-07&dataTypes=TMAX,TMIN,PRCP&format=json&units=metric', $this->urls[1]);
    }

    public function testTheMonthsOfAYear(): void
    {
        [$tavg, $prcp] = $this->source()->series(new SeriesQuery(['TAVG', 'PRCP'], self::strasbourg(), new \DateTimeImmutable('2025-01-01'), new \DateTimeImmutable('2025-12-31'), 'P1M'));

        self::assertSame('STRASBOURG ENTZHEIM, FR (global-summary-of-the-month:FR000007190)', $tavg->label);
        self::assertCount(12, $tavg);
        self::assertSame(['P1M', null, '°C'], [$tavg->step, $tavg->aggregation, $tavg->unit]);
        self::assertSame([4.1, 21.8], [$tavg->values()[0], $tavg->values()[5]]);
        self::assertSame('2025-09-01', $prcp->times()[8]->format('Y-m-d'));
        self::assertSame(108.6, $prcp->values()[8]);
        self::assertStringContainsString('dataset=global-summary-of-the-month', $this->urls[0]);
    }

    public function testAStationInPlaceOfThePoint(): void
    {
        $central = Identifier::of(Scheme::GHCN, 'USW00094728');
        [$tmax, $prcp] = $this->source()->series(new SeriesQuery(['TMAX', 'PRCP'], $central, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-07'), SeriesQuery::DAILY));

        self::assertCount(1, $this->urls, 'no search: the data alone');
        self::assertStringContainsString('data/v1?dataset=daily-summaries&stations=USW00094728&startDate=2026-10-01&endDate=2026-10-07&dataTypes=TMAX,PRCP', $this->urls[0]);
        self::assertSame('NY CITY CENTRAL PARK, NY US (daily-summaries:USW00094728)', $tmax->label);
        self::assertSame([40.77898, -73.96925, 42.7], [$tmax->place->latitude, $tmax->place->longitude, $tmax->place->elevation]);
        self::assertSame([24.4, 28.3, 21.1, 16.7, 20.0, 15.6, 18.3], $tmax->values());
        self::assertSame(3.8, $prcp->max());

        self::assertSame([], $this->source()->series(new SeriesQuery(['TMAX'], Identifier::of(Scheme::GHCN, 'XX000000000'), new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-07'), SeriesQuery::DAILY)), 'an unknown station: no series');
    }

    public function testSeriesAtIsDeprecatedAndAnswersAsBefore(): void
    {
        $deprecations = [];
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, \E_USER_DEPRECATED);
        try {
            [$tmax] = $this->source()->seriesAt('fr000007190', new SeriesQuery(['TMAX'], self::strasbourg(), new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-07'), SeriesQuery::DAILY));
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, $deprecations);
        self::assertCount(1, $this->urls, 'no search');
        self::assertSame('STRASBOURG ENTZHEIM, FR (daily-summaries:FR000007190)', $tmax->label);
        self::assertSame(37.6, $tmax->max());
    }

    public function testAStationAsAnEntity(): void
    {
        $station = $this->source()->entity('ghcn:USW00094728');

        self::assertSame(['ghcn:USW00094728', 'NY CITY CENTRAL PARK, NY US', 'station', Domain::EARTH], [(string) $station->id, $station->label, $station->kind, $station->domain]);
        self::assertSame([40.77898, -73.96925, 42.7], [$station->place->latitude, $station->place->longitude, $station->place->elevation]);
        self::assertSame(['1869-01-01', '2026-10-10', '42.7 m'], [$station->value('period_start'), $station->value('period_end'), (string) $station->property('elevation')]);
        self::assertCount(59, $station->properties('variable'));
        self::assertContains('TMAX: 1869-01-01/2026-10-10', array_map('strval', $station->properties('variable')));
        self::assertSame('https://www.ncei.noaa.gov/cdo-web/datasets/GHCND/stations/GHCND:USW00094728/detail', $station->url);
        self::assertCount(2, $this->urls, 'the search, a day of its data for its elevation');
        self::assertNull($this->source()->entity('XX000000000'), 'unknown');

        $knyc = $this->source(['api' => 'nws'])->entity('nws.station:KNYC');
        self::assertSame(['nws.station:KNYC', 'New York City, Central Park', 'station', 'America/New_York', 'ASOS', '46.9 m'], [(string) $knyc->id, $knyc->label, $knyc->kind, $knyc->value('time_zone'), $knyc->value('network'), (string) $knyc->property('elevation')]);
        self::assertSame([40.78333, -73.96667], [$knyc->place->latitude, $knyc->place->longitude]);
    }

    public function testAnNwsStationsObservationsThenItsGridsForecast(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        [$temperature] = $this->source(['api' => 'nws', 'now' => self::NOW])->series(new SeriesQuery(['temperature'], Identifier::of(Scheme::NWS_STATION, 'KNYC'), $now->modify('-1 day'), $now->modify('+1 day')));

        self::assertCount(4, $this->urls, 'the station, its point, its observations, the grid');
        self::assertStringEndsWith('/stations/KNYC', $this->urls[0]);
        self::assertStringEndsWith('/points/40.7833,-73.9667', $this->urls[1]);
        self::assertStringContainsString('/stations/KNYC/observations?start=2026-10-14T04:00:00Z', $this->urls[2]);
        self::assertStringStartsWith('New York City, Central Park (KNYC), then NWS OKX grid', (string) $temperature->label);
        self::assertSame(46.9, $temperature->place->elevation, 'the station\'s place');
        self::assertGreaterThan(24, \count($temperature));
    }

    public function testNoStationNearIsASeriesWithoutPoints(): void
    {
        $here = new Point(0.0, -140.0);
        [$tmax] = $this->source()->series(new SeriesQuery(['TMAX'], $here, new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-07'), SeriesQuery::DAILY));

        self::assertCount(0, $tmax);
        self::assertSame($here, $tmax->place);
        self::assertNull($tmax->label);
        self::assertCount(1, $this->urls);
    }

    public function testNwsObservationsThenForecastJoined(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        [$temperature, $humidity, $rain, $pressure] = $this->source(['api' => 'nws', 'now' => self::NOW])->series(new SeriesQuery(['temperature', 'relativeHumidity', 'quantitativePrecipitation', 'barometricPressure'], new Point(40.7789, -73.9692), $now->modify('-1 day'), $now->modify('+1 day')));

        self::assertCount(4, $this->urls, 'the point, its station, the observations, the grid');
        self::assertStringContainsString('/stations/KNYC/observations?start=2026-10-14T04:00:00Z&end=2026-10-15T07:08:25Z&limit=500', $this->urls[2], 'from local midnight (EDT)');

        self::assertSame(['°C', null, null], [$temperature->unit, $temperature->forecast, $temperature->step], 'observed then forecast: mixed, irregular');
        self::assertSame('New York City, Central Park (KNYC), then NWS OKX grid 34,45', $temperature->label);
        self::assertSame('2026-10-14T00:51:00-04:00', $temperature->times()[0]->format(\DATE_ATOM), 'the first observation, in the point\'s zone');
        self::assertSame(21.1, $temperature->values()[0]);
        self::assertSame(16.7, $temperature->at(new \DateTimeImmutable('2026-10-15T05:51:00Z')), 'the last observation');
        self::assertSame(16.666666666666668, $temperature->at(new \DateTimeImmutable('2026-10-15T07:00:00Z')), 'the forecast from the current hour, as the grid gives it (°F made °C)');
        self::assertNull($temperature->at(new \DateTimeImmutable('2026-10-15T06:00:00Z')), 'the grid before the current hour is not taken');
        self::assertSame('2026-10-16T23:00:00-04:00', $temperature->times()[\count($temperature) - 1]->format(\DATE_ATOM));
        self::assertSame(['%', 48, 95], [$humidity->unit, $humidity->min(), $humidity->max()]);

        self::assertSame([true, 'mm', SeriesQuery::HOURLY, 'NWS OKX grid 34,45'], [$rain->forecast, $rain->unit, $rain->step, $rain->label], 'no observation has it: the forecast alone');
        self::assertSame(3.302, $rain->at(new \DateTimeImmutable('2026-10-16T06:00:00Z')), 'an amount at the start of its six hours');
        self::assertNull($rain->at(new \DateTimeImmutable('2026-10-16T07:00:00Z')), 'and null at the others');
        self::assertSame(5.08, $rain->max());

        self::assertSame([false, 'Pa', 101050, 101830], [$pressure->forecast, $pressure->unit, $pressure->min(), $pressure->max()], 'the observations alone');
        self::assertSame([40.78333, -73.96667], [$pressure->place->latitude, $pressure->place->longitude], 'the station\'s place');
        self::assertSame([40.7789, -73.9692], [$rain->place->latitude, $rain->place->longitude], 'the point asked');

        $user = implode("\n", array_merge(...array_values($this->headers[0])));
        self::assertStringContainsString('User-Agent: omnilab/1.x', $user, 'the NWS refuses a call without one');
    }

    public function testNwsByDay(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        [$max, $rain] = $this->source(['api' => 'nws', 'now' => self::NOW])->series(new SeriesQuery(['temperature', 'quantitativePrecipitation'], new Point(40.7789, -73.9692), $now->modify('-1 day'), $now->modify('+1 day'), SeriesQuery::DAILY, Series::MAX));

        self::assertSame('temperature_max', $max->variable);
        self::assertSame(['2026-10-14', '2026-10-15', '2026-10-16'], array_map(static fn (\DateTimeImmutable $t) => $t->format('Y-m-d'), $max->times()));
        self::assertSame('America/New_York', $max->times()[0]->getTimezone()->getName());
        self::assertSame([SeriesQuery::DAILY, Series::MAX], [$max->step, $max->aggregation]);
        self::assertSame([0, 5.08], $rain->values(), 'the forecast\'s days alone: the largest six-hour amount of each');
    }

    public function testNwsOutsideItsGridsAnswersNothing(): void
    {
        [$temperature] = $this->source(['api' => 'nws', 'now' => self::NOW])->series(new SeriesQuery(['temperature'], self::strasbourg()));

        self::assertCount(0, $temperature);
        self::assertCount(1, $this->urls, 'the point alone (404)');
    }

    public function testRefusedBeforeAnyCall(): void
    {
        $ncei = $this->source();
        $nws = $this->source(['api' => 'nws']);
        $refusals = [
            static fn () => $ncei->series(new SeriesQuery(['TMAX'], new BBox(7.6, 48.5, 7.9, 48.7), step: SeriesQuery::DAILY)),
            static fn () => $ncei->series(new SeriesQuery(['TMAX'], self::strasbourg())),
            static fn () => $ncei->series(new SeriesQuery(['TMAX'], self::strasbourg(), step: SeriesQuery::DAILY, aggregation: Series::MEAN)),
            static fn () => $ncei->series(new SeriesQuery(['TAVG'], self::strasbourg(), step: 'P1M', aggregation: Series::MEAN)),
            static fn () => $nws->series(new SeriesQuery(['temperature'], self::strasbourg(), step: 'P1M')),
            static fn () => $nws->series(new SeriesQuery(['temperature'], self::strasbourg(), step: SeriesQuery::DAILY)),
            static fn () => $nws->series(new SeriesQuery(['temperature'], self::strasbourg(), aggregation: Series::MAX)),
            static fn () => @$nws->seriesAt('FR000007190', new SeriesQuery(['TMAX'], self::strasbourg(), step: SeriesQuery::DAILY)),
            static fn () => $ncei->entity('caffeine'),
            static fn () => $ncei->entity('nws.station:KNYC'),
            static fn () => $nws->entity('ghcn:USW00094728'),
            static fn () => $ncei->series(new SeriesQuery(['TMAX'], Identifier::of(Scheme::NWS_STATION, 'KNYC'), step: SeriesQuery::DAILY)),
            static fn () => $nws->series(new SeriesQuery(['temperature'], Identifier::of(Scheme::GHCN, 'USW00094728'))),
            static fn () => $ncei->search(new Query('Strasbourg')),
            static fn () => $ncei->products(new ProductQuery()),
        ];
        foreach ($refusals as $i => $refusal) {
            try {
                $refusal();
                self::fail("refusal $i answered");
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->urls);

        $this->expectException(\InvalidArgumentException::class);
        @$ncei->seriesAt('FR-0007190', new SeriesQuery(['TMAX'], self::strasbourg(), step: SeriesQuery::DAILY));
    }

    public function testCapabilitiesAndOptions(): void
    {
        self::assertSame('noaa', (new NoaaSourceFactory())->getName());
        self::assertSame([Capability::ENTITY, Capability::SERIES, Capability::HISTORICAL, Capability::STATION], $this->source()->capabilities());
        self::assertSame([Capability::ENTITY, Capability::SERIES, Capability::FORECAST, Capability::HISTORICAL, Capability::STATION], $this->source(['api' => 'nws'])->capabilities());
        self::assertSame('noaa', $this->source(['api' => 'nws'])->getName());

        $this->expectException(\InvalidArgumentException::class);
        $this->source(['api' => 'cdo']);
    }

    public function testErrors(): void
    {
        $query = new SeriesQuery(['TMAX'], self::strasbourg(), step: SeriesQuery::DAILY);
        try {
            $this->source([], new MockResponse('{"errorCode":400,"errorMessage":"Bad Request","errors":[{"field":"dataset","message":"Unsupported dataset.","value":"nope"}]}', ['http_code' => 400]))->series($query);
            self::fail('a 400 answered');
        } catch (ProviderException $e) {
            self::assertStringContainsString('Unsupported dataset', $e->getMessage());
        }
        try {
            $this->source([], new MockResponse('', ['http_code' => 503]))->series($query);
            self::fail('a 503 answered');
        } catch (UnavailableException) {
        }
        $this->expectException(RateLimitedException::class);
        $this->source(['api' => 'nws'], new MockResponse('', ['http_code' => 429]))->series(new SeriesQuery(['temperature'], new Point(40.7789, -73.9692)));
    }
}
