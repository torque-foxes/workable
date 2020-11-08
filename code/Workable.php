<?php

namespace SilverStripe\Workable;

use Monolog\Logger;
use RuntimeException;
use Psr\Log\LoggerInterface;
use SilverStripe\ORM\ArrayList;
use SilverStripe\Core\Flushable;
use SilverStripe\View\ArrayData;
use SilverStripe\Core\Extensible;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

class Workable implements Flushable
{
    use Extensible;
    use Injectable;
    use Configurable;

    /**
     * Reference to the RestfulService dependency
     * @var RestfulService
     */
    private $restfulService;

    /**
     * Reference to the Cache dependency
     * @var CacheInterface
     */
    private $cache;

    /**
     * API key used to connect to Workable, set via WORKABLE_API_KEY env var or in config
     * @config
     */
    private $apiKey;

    /**
     * Subdomain for Workable API call (e.g. $subdomain.workable.com)
     * @config
     */
    private $subdomain;

    /**
     * Constructor, inject the restful service dependency
     * @param RestfulService $restfulService
     * @param CacheInterface $cache
     */
    public function __construct(RestfulService $restfulService, CacheInterface $cache)
    {
        $this->restfulService = $restfulService;
        $this->cache = $cache;
    }

    /**
     * Gets all the jobs from the Workable API
     * @param  array  $params Array of params, e.g. ['state' => 'published'].
     *                        see https://workable.readme.io/docs/jobs for full list of query params
     * @return ArrayList
     */
    public function getJobs(array $params = []): ArrayList
    {
        $cacheKey = 'Jobs' . implode('-', $params);
        if ($this->cache->has($cacheKey)) {
            return $this->cache->get($cacheKey);
        }

        $list = ArrayList::create();
        $response = $this->callRestfulService('jobs', $params);

        if (!$response) {
            return $list;
        }

        $jobs = $response['jobs'] ?? [];
        foreach ($jobs as $record) {
            $list->push(WorkableResult::create($record));
        }

        $this->cache->set($cacheKey, $list);

        return $list;
    }

    /**
     * Gets information on a specific job form the Workable API
     * @param  string $shortcode Workable shortcode for the job, e.g. 'GROOV005'
     * @param  array  $params    Array of params, e.g. ['state' => 'published'].
     *                           see https://workable.readme.io/docs/jobs for full list of query params
     * @return WorkableResult|null
     */
    public function getJob(string $shortcode, array $params = []): ?WorkableResult
    {
        $cacheKey = 'Job-' . $shortcode . implode('-', $params);

        if ($this->cache->has($cacheKey)) {
            return $this->cache->get($cacheKey);
        }

        $job = null;
        $response = $this->callRestfulService('jobs/' . $shortcode, $params);

        if ($response && isset($response['id'])) {
            $job = WorkableResult::create($response);
            $this->cache->set($cacheKey, $job);
        }

        return $job;
    }

    /**
     * Gets all the jobs from the workable API, populating each job with its full data
     * Note: This calls the API multiple times so should be used with caution, see
     * rate limiting docs https://workable.readme.io/docs/rate-limits
     * @param  array  $params Array of params, e.g. ['state' => 'published'].
     *                        see https://workable.readme.io/docs/jobs for full list of query params
     * @return ArrayList
     */
    public function getFullJobs($params = [])
    {
        $cacheKey = 'FullJobs' . implode('-', $params);

        if ($this->cache->has($cacheKey)) {
            return $this->cache->get($cacheKey);
        }

        $list = ArrayList::create();
        $response = $this->callRestfulService('jobs', $params);

        if (!$response) {
            return $list;
        }

        $jobs = $response['jobs'] ?? [];
        foreach ($jobs as $record) {
            $job = $this->getJob($record['shortcode'], $params);
            $list->push($job);
        }

        $this->cache->set($cacheKey, $list);

        return $list;
    }

    /**
     * Wrapper method to configure the RestfulService, make the call, and handle errors
     * @param  string $url
     * @param  array  $params
     * @param  string $method
     * @return array  JSON as array
     */
    public function callRestfulService(string $url, array $params = [], string $method = 'GET'): array
    {
        try {
            $response = $this->restfulService->request($method, $url, ['query' => $params]);
        } catch (\RuntimeException $e) {
            Injector::inst()->get(LoggerInterface::class)->warning(
                'Failed to retrieve valid response from workable',
                ['exception' => $e]
            );
            return [];
        }

        return json_decode($response->getBody(), true);
    }

    /**
     * Clear the cache when flush is called
     */
    public static function flush()
    {
        $cache = static::singleton()->getCache();
        $cache->clear();
    }
}
