<?php

namespace SilverStripe\StaticPublishQueue\Extension\Engine;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\CMS\Model\SiteTreeExtension;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Resettable;
use SilverStripe\ORM\ValidationException;
use SilverStripe\StaticPublishQueue\Contract\StaticPublishingTrigger;
use SilverStripe\StaticPublishQueue\Extension\Publishable\PublishableSiteTree;
use SilverStripe\StaticPublishQueue\Job\DeleteStaticCacheJob;
use SilverStripe\StaticPublishQueue\Job\GenerateStaticCacheJob;
use SilverStripe\StaticPublishQueue\Service\UrlBundleInterface;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * This extension couples to the StaticallyPublishable and StaticPublishingTrigger implementations
 * on the SiteTree objects and makes sure the actual change to SiteTree is triggered/enqueued.
 *
 * Provides the following information as a context to StaticPublishingTrigger:
 * * action - name of the executed action: publish or unpublish
 *
 * @see PublishableSiteTree
 */
class SiteTreePublishingEngine extends SiteTreeExtension implements Resettable
{
    /**
     * Queued job service injection property
     * Used for unit tests only to cover edge cases where Injector doesn't cover
     *
     * @var QueuedJobService|null
     */
    protected static $queueService = null;

    /**
     * Queues the urls to be flushed into the queue.
     *
     * @var array
     */
    private $toUpdate = [];

    /**
     * Queues the urls to be deleted as part of a next flush operation.
     *
     * @var array
     */
    private $toDelete = [];

    public static function reset(): void
    {
        static::$queueService = null;
    }

    /**
     * Force inject queue service
     * Used for unit tests only to cover edge cases where Injector doesn't cover
     *
     *
     * @param QueuedJobService $service
     */
    public static function setQueueService(QueuedJobService $service): void
    {
        static::$queueService = $service;
    }

    /**
     * @return array
     */
    public function getToUpdate()
    {
        return $this->toUpdate;
    }

    /**
     * @return array
     */
    public function getToDelete()
    {
        return $this->toDelete;
    }

    /**
     * @param array $toUpdate
     * @return $this
     */
    public function setToUpdate($toUpdate)
    {
        $this->toUpdate = $toUpdate;
        return $this;
    }

    /**
     * @param array $toDelete
     * @return $this
     */
    public function setToDelete($toDelete)
    {
        $this->toDelete = $toDelete;
        return $this;
    }

    /**
     * @param SiteTree|SiteTreePublishingEngine|null $original
     * @throws ValidationException
     */
    public function onAfterPublishRecursive(&$original)
    {
        // If the site tree has been "reorganised" (ie: the parentID has changed)
        // then this is the equivalent of an un-publish and publish as far as the
        // static publisher is concerned
        if ($original && (
            (int) $original->ParentID !== (int) $this->getOwner()->ParentID
                || $original->URLSegment !== $this->getOwner()->URLSegment
            )
        ) {
            $context = [
                'action' => 'unpublish',
            ];
            $original->collectChanges($context);
            $original->flushChanges();
        }
        $context = [
            'action' => 'publish',
        ];
        $this->collectChanges($context);
        $this->flushChanges();
    }

    public function onBeforeUnpublish()
    {
        $context = [
            'action' => 'unpublish',
        ];
        $this->collectChanges($context);
    }

    /**
     * @throws ValidationException
     */
    public function onAfterUnpublish()
    {
        $this->flushChanges();
    }

    /**
     * Collect all changes for the given context.
     *
     * @param array $context
     */
    public function collectChanges($context)
    {
        Environment::increaseMemoryLimitTo();
        Environment::increaseTimeLimitTo();

        if ($this->getOwner()->hasExtension(PublishableSiteTree::class)
            || $this->getOwner() instanceof StaticPublishingTrigger
        ) {
            $toUpdate = $this->getOwner()->objectsToUpdate($context);
            $this->setToUpdate($toUpdate);

            $toDelete = $this->getOwner()->objectsToDelete($context);
            $this->setToDelete($toDelete);
        }
    }

    /**
     * Execute URL deletions, enqueue URL updates.
     * @throws ValidationException
     */
    public function flushChanges()
    {
        $queueService = static::$queueService ?? QueuedJobService::singleton();

        if (count($this->toUpdate) > 0) {
            /** @var UrlBundleInterface $urlService */
            $urlService = Injector::inst()->create(UrlBundleInterface::class);

            foreach ($this->toUpdate as $item) {
                $urls = $item->urlsToCache();
                ksort($urls);
                $urls = array_keys($urls);
                $urlService->addUrls($urls);
            }

            $jobs = $urlService->getJobsForUrls(GenerateStaticCacheJob::class, 'Building URLs', $this->owner);

            foreach ($jobs as $job) {
                $queueService->queueJob($job);
            }

            $this->toUpdate = [];
        }

        if (count($this->toDelete) > 0) {
            /** @var UrlBundleInterface $urlService */
            $urlService = Injector::inst()->create(UrlBundleInterface::class);

            foreach ($this->toDelete as $item) {
                $urls = $item->urlsToCache();
                ksort($urls);
                $urls = array_keys($urls);
                $urlService->addUrls($urls);
            }

            $jobs = $urlService->getJobsForUrls(DeleteStaticCacheJob::class, 'Purging URLs', $this->owner);

            foreach ($jobs as $job) {
                $queueService->queueJob($job);
            }

            $this->toDelete = [];
        }
    }
}
