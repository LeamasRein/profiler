# Native PHP Profiler

To use the profiler, you must define in environment variables `PROFILE_MODE`:
- "OFF" (or empty) - don't run profiler.
- "TIMING" - calculate the running time of the application.
- "TRACE" - full functions tracing.

---
To examine any script, it must contain definition `ticks = 1` or was included via `Profiler::include`.
You can declare `ticks = 1` at the beginning of the root script to test it too.

---
Test results can be obtained by calling the profiler and specifying the "onShutdown" method. Example:

``
rein\profiler\Profiler::getInstance()
    ->onShutdown = fn(array $timings) => var_dump($timings);
``