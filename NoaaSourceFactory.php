<?php

namespace Omnilab\Noaa;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * NOAA's observations as series: NCEI's station records of the world
 * (api: ncei, the default) or the National Weather Service's observations
 * and forecast for a point of the United States (api: nws, a source of its
 * own) - no key.
 *
 *   options:
 *     api: ncei                        # or nws
 *     mailto: 'support@glitchr.io'     # in the User-Agent, which the NWS requires
 *     radius_km: 50                    # NCEI: how far from a Point the nearest station is looked for
 *     timezone: ~                      # the zone of the days and times when a query does not say: local (the NWS point's own), UTC, America/New_York...; null: local for the NWS, UTC for NCEI
 *     throttle: 0                      # seconds between two calls: neither API publishes a figure
 *     base_uri: ~                      # https://www.ncei.noaa.gov/access/services/ (ncei), https://api.weather.gov/ (nws)
 *     now: ~                           # the time taken for now (tests): the real one when not set
 */
final class NoaaSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'noaa',
            'omnilab.required_options' => [],
            'api' => NoaaSource::NCEI,
            'radius_km' => 50.0,
            'timezone' => null,
            'throttle' => 0.0,
            'base_uri' => null,
            'now' => null,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new NoaaSource(
            $this->http ?? HttpClient::create(),
            (string) $c->get('api'),
            $c->get('base_uri'),
            (float) $c->get('radius_km'),
            $c->get('timezone'),
            $c->get('now'),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
