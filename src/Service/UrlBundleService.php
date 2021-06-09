<?php

namespace SilverStripe\StaticPublishQueue\Service;

use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\StaticPublishQueue\Job;

/**
 * Class UrlBundleService
 *
 * This service is responsible for bundling URLs which to static cache jobs
 * Several extension points are available to allow further customisation
 */
class UrlBundleService implements UrlBundleInterface
{
    use Extensible;
    use Injectable;

    /**
     * @var array
     */
    protected $urls = [];

    /**
     * @inheritDoc
     */
    public function addUrls(array $urls): void
    {
        foreach ($urls as $url) {
            $this->urls[$url] = $url;
        }
    }

    /**
     * @inheritDoc
     */
    public function getJobsForUrls(string $jobClass, ?string $message = null, ?DataObject $contextModel = null): array
    {
        $singleton = singleton($jobClass);

        if (!$singleton instanceof Job) {
            return [];
        }

        $urls = $this->getUrls();
        $urlsPerJob = $singleton->getUrlsPerJob();
        $batches = $urlsPerJob > 0 ? array_chunk($urls, $urlsPerJob) : [$urls];
        $jobs = [];

        foreach ($batches as $urlBatch) {
            $priorityUrls = $this->assignPriorityToUrls($urlBatch);

            /** @var Job $job */
            $job = Injector::inst()->create($jobClass);
            $job->hydrate($priorityUrls, $message);

            // Use this extension point to inject some additional data into the job
            $this->extend('updateHydratedJob', $job, $contextModel);

            $jobs[] = $job;
        }

        return $jobs;
    }

    /**
     * Get URLs for further processing
     *
     * @return array
     */
    protected function getUrls(): array
    {
        $urls = [];

        foreach ($this->urls as $url) {
            $url = $this->formatUrl($url);

            if (!$url) {
                continue;
            }

            $urls[] = $url;
        }

        $urls = array_unique($urls);

        // Use this extension point to change the order of the URLs if needed
        $this->extend('updateGetUrls', $urls);

        return $urls;
    }

    /**
     * Extensibility function which allows to handle custom formatting / encoding needs for URLs
     * Returning "falsy" value will make the URL to be skipped
     *
     * @param string $url
     * @return string|null
     */
    protected function formatUrl(string $url): ?string
    {
        // Use this extension point to reformat URLs, for example encode special characters
        $this->extend('updateFormatUrl', $url);

        return $url;
    }

    /**
     * Add priority data to URLs
     *
     * @param array $urls
     * @return array
     */
    protected function assignPriorityToUrls(array $urls): array
    {
        $priority = 0;
        $priorityUrls = [];

        foreach ($urls as $url) {
            $priorityUrls[$url] = $priority;
            $priority += 1;
        }

        return $priorityUrls;
    }
}
