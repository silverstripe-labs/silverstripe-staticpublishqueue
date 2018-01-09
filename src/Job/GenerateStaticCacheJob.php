<?php

namespace SilverStripe\StaticPublishQueue\Job;

use SilverStripe\StaticPublishQueue\Job;
use SilverStripe\StaticPublishQueue\Publisher;

class GenerateStaticCacheJob extends Job
{
    /**
     * @return string
     */
    public function getTitle()
    {
        return 'Generate a set of static pages from URLs';
    }

    public function setup()
    {
        parent::setup();
        $this->URLsToProcess = $this->findAffectedURLs();
        $this->totalSteps = ceil(count($this->URLsToProcess) / self::config()->get('chunk_size'));
        $this->addMessage('Building for ' . (string)$this->getObject());
        $this->addMessage('Building URLS ' . var_export(array_keys($this->URLsToProcess), true));
    }

    /**
     * Do some processing yourself!
     */
    public function process()
    {
        $chunkSize = self::config()->get('chunk_size');
        $count = 0;
        foreach ($this->jobData->URLsToProcess as $url => $priority) {
            if (++$count > $chunkSize) {
                break;
            }
            $meta = Publisher::singleton()->publishURL($url, true);
            if (!empty($meta['success'])) {
                $this->jobData->ProcessedURLs[$url] = $url;
                unset($this->jobData->URLsToProcess[$url]);
            }
        }
        $this->isComplete = empty($this->jobData->URLsToProcess);
    }
}