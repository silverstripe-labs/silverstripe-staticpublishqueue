<?php

namespace SilverStripe\StaticPublishQueue\Service;

use SilverStripe\ORM\DataObject;

interface UrlBundleInterface
{
    /**
     * Add URLs to this bundle
     *
     * @param array $urls
     */
    public function addUrls(array $urls): void;

    /**
     * Package URLs into jobs
     *
     * @param string $jobClass
     * @param string|null $message
     * @param DataObject|null $contextModel
     * @return array
     */
    public function getJobsForUrls(string $jobClass, ?string $message = null, ?DataObject $contextModel = null): array;
}
