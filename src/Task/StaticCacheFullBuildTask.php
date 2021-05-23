<?php

namespace SilverStripe\StaticPublishQueue\Task;

use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\ValidationException;
use SilverStripe\StaticPublishQueue\Job\StaticCacheFullBuildJob;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

class StaticCacheFullBuildTask extends BuildTask
{

    /**
     * Queue up a StaticCacheFullBuildJob
     * Check for startAfter param and do some sanity checking
     *
     * @param HTTPRequest $request
     * @return bool
     * @throws ValidationException
     */
    public function run($request)
    {
        $job = Injector::inst()->create(StaticCacheFullBuildJob::class);
        $signature = $job->getSignature();

        // see if we already have this job in a queue
        $filter = [
            'Signature' => $signature,
            'JobStatus' => [
                QueuedJob::STATUS_NEW,
                QueuedJob::STATUS_INIT,
            ],
        ];

        /** @var QueuedJobDescriptor $existing */
        $existing = DataList::create(QueuedJobDescriptor::class)->filter($filter)->first();

        if ($existing && $existing->exists()) {
            $this->log(sprintf(
                'There is already a %s in the queue, added %s %s',
                StaticCacheFullBuildJob::class,
                $existing->Created,
                $existing->StartAfter ? 'and set to start after ' . $existing->StartAfter : ''
            ));
            return false;
        }

        if ($request->getVar('startAfter')) {
            $now = DBDatetime::now();
            $today = $now->Date();
            $startTime = $request->getVar('startAfter');

            // move to tomorrow if the starttime has passed today
            if ($now->Time24() > $startTime) {
                $timestamp = strtotime($today . ' ' . $startTime . ' +1 day');
                $dayWord = 'tomorrow';
            } else {
                $timestamp = strtotime($today . ' ' . $startTime);
                $dayWord = 'today';
            }
            $startAfter = (new \DateTime())->setTimestamp($timestamp);
            $thisTimeTomorrow = (new \DateTime())->setTimestamp(strtotime($now . ' +1 day'))->getTimestamp();

            // sanity check that we are in the next 24 hours - prevents some weird stuff sneaking through
            if ($startAfter->getTimestamp() > $thisTimeTomorrow || $startAfter->getTimestamp() < $now->getTimestamp()) {
                $this->log('Invalid startAfter parameter passed. Please ensure the time format is HHmm e.g. 1300');
                return false;
            }

            $this->log(sprintf(
                '%s queued for %s %s.',
                StaticCacheFullBuildJob::class,
                $startAfter->format('H:m'),
                $dayWord
            ));
        } else {
            $startAfter = null;
            $this->log(StaticCacheFullBuildJob::class . ' added to the queue for immediate processing');
        }

        $job->setJobData(0, 0, false, new \stdClass(), [
            'Building static cache for full site',
        ]);
        QueuedJobService::singleton()->queueJob($job, $startAfter ? $startAfter->format('Y-m-d H:i:s') : null);

        return true;
    }

    protected function log($message)
    {
        $newLine = Director::is_cli() ? PHP_EOL : '<br>';
        echo $message . $newLine;
    }
}
