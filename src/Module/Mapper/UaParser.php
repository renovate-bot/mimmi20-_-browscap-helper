<?php
/**
 * This file is part of the browscap-helper package.
 *
 * Copyright (c) 2015-2017, Thomas Mueller <mimmi20@live.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);
namespace BrowscapHelper\Module\Mapper;

use BrowscapHelper\DataMapper\InputMapper;
use BrowserDetector\Helper\GenericRequestFactory;
use BrowserDetector\Version\Version;
use Psr\Cache\CacheItemPoolInterface;
use UaResult\Browser\Browser;
use UaResult\Device\Device;
use UaResult\Engine\Engine;
use UaResult\Os\Os;
use UaResult\Result\Result;
use UaResult\Result\ResultInterface;

/**
 * BrowscapHelper.ini parsing class with caching and update capabilities
 *
 * @category  BrowscapHelper
 *
 * @author    Thomas Mueller <mimmi20@live.de>
 * @copyright 2015 Thomas Mueller
 * @license   http://www.opensource.org/licenses/MIT MIT License
 */
class UaParser implements MapperInterface
{
    /**
     * @var \BrowscapHelper\DataMapper\InputMapper
     */
    private $mapper;

    /**
     * @var \Psr\Cache\CacheItemPoolInterface
     */
    private $cache;

    /**
     * @param \BrowscapHelper\DataMapper\InputMapper $mapper
     * @param \Psr\Cache\CacheItemPoolInterface      $cache
     */
    public function __construct(InputMapper $mapper, CacheItemPoolInterface $cache)
    {
        $this->mapper = $mapper;
        $this->cache  = $cache;
    }

    /**
     * Gets the information about the browser by User Agent
     *
     * @param \stdClass $parserResult
     * @param string    $agent
     *
     * @return \UaResult\Result\ResultInterface the object containing the browsers details
     */
    public function map($parserResult, string $agent): ResultInterface
    {
        $browser = new Browser(
            $this->mapper->mapBrowserName($parserResult->ua->family),
            null,
            new Version((string) $parserResult->ua->major, (string) $parserResult->ua->minor, (string) $parserResult->ua->patch)
        );

        $os = new Os(
            $this->mapper->mapOsName($parserResult->os->family),
            null,
            null,
            new Version((string) $parserResult->os->major, (string) $parserResult->os->minor, (string) $parserResult->os->patch)
        );

        $device = new Device(null, null);
        $engine = new Engine(null);

        $requestFactory = new GenericRequestFactory();

        return new Result($requestFactory->createRequestFromString(trim($agent))->getHeaders(), $device, $os, $browser, $engine);
    }
}
