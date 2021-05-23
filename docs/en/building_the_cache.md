# Building the Cache

There are two main ways the cache is built:

## OnAfterPublish Hook

This calls `urlsToCache()` on a page after it is published. By default, it will cache
only its own URL and the ones of its parents. These URLs are added to a
`GenerateStaticCacheJob` to be executed on the next run.

To alter which URLs are sent to be cached, you should override the `urlsToCache()`
method.

## Full Site Build

You can generate cache files for the entire site via the `StaticCacheFullBuildJob`.
This can either be queued up from the QueuedJobs interface or via the task
`dev/tasks/SilverStripe-StaticPublishQueue-Task-StaticCacheFullBuildTask`. This task also takes a parameter `?startAfter`
which can delay the execution of the job. The parameter should be in HHMM format,
e.g. to start after 2pm, pass `?startAfter=1400`. If it's already after the proposed
time on the current day, it will push it to the next day.

If you want to do a full site build on a cron, you should do so via the task. An
example configuration would be:

```bash
# build the cache at 1am every day
0 1 * * * www-data /path/to/sake dev/tasks/SilverStripe-StaticPublishQueue-Task-StaticCacheFullBuildTask
```

