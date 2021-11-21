<?php
namespace rein\profiler;

class Profiler
{
    protected array $timestamps = [];
    protected int $debug_args;

    /** @var callable|null $onShutdown */
    public $onShutdown = null;

    protected function __construct()
    {
        $this->initProfiler();
        $this->debug_args = getenv('PROFILE_TRACE_ARGS') ? 0 : DEBUG_BACKTRACE_IGNORE_ARGS;
    }

    /**
     * @method string getProfilerMode()
     * returns the mode of the profiler
     */
    protected function getProfilerMode():string
    {
        $profiler_web_key = getenv('PROFILER_WEB_KEY');
        if(!empty($profiler_web_key) && $this->isProfileTraceLink($_SERVER['REQUEST_URI'] ?? ''))
            return 'TRACE';

        return mb_strtoupper(getenv('PROFILER_MODE'));
    }

    protected function unparse_url(array $parsed_url):string
    {
        $scheme   = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
        $host     = isset($parsed_url['host']) ? $parsed_url['host'] : '';
        $port     = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
        $user     = isset($parsed_url['user']) ? $parsed_url['user'] : '';
        $pass     = isset($parsed_url['pass']) ? ':' . $parsed_url['pass']  : '';
        $pass     = ($user || $pass) ? "$pass@" : '';
        $path     = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        $query    = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
        $fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';
        return "$scheme$user$pass$host$port$path$query$fragment";
    }

    protected function isProfileTraceLink(string $url):bool
    {
        $profiler_web_key = getenv('PROFILER_WEB_KEY');
        if(!$profiler_web_key)
            throw new \Exception("The environment variable 'PROFILER_WEB_KEY' is required to generate the link");
        
        $parts = parse_url($url);
        if(!isset($parts['query']))
            return false;

        $query = [];
        parse_str($parts['query'], $query);
        if(!isset($query['PROFILER_KEY']) || !isset($query['PROFILER_TOKEN']))
            return false;

        $key = $query['PROFILER_KEY'];
        $token = $query['PROFILER_TOKEN'];

        unset($query['PROFILER_KEY']);
        unset($query['PROFILER_TOKEN']);

        $parts['query'] = http_build_query($query);

        $url = $this->unparse_url($parts);
        return ($this->generateToken($profiler_web_key, $key, $parts) == $token);
    }

    /**
     * @method string generateToken(string $profile_web_key, string $key, mixed $url)
     * generate runtime token by key
     */
    protected function generateToken(string $profiler_web_key, string $key, $url):string
    {
        if(is_string($url))
        {
            $url = parse_url($url);
        }
        elseif(is_array($url))
        {
            // nothing to do
        }
        else
        {
            throw new \Exception("Incorrect url format");
        }

        if(isset($url['schema']))
            unset($url['schema']);
        
        if(isset($url['host']))
            unset($url['host']);

        $url = $this->unparse_url($url);

        return md5(implode([$profiler_web_key, $key, $url]));
    }

    /**
     * @method string generateProfileTraceLink(string $url)
     * generate a link to enable tracker mode in runtime
     */
    protected function generateProfileTraceLink(string $url):string
    {
        $profiler_web_key = getenv('PROFILER_WEB_KEY');
        if(!$profiler_web_key)
            throw new \Exception("The environment variable 'PROFILER_WEB_KEY' is required to generate the link");

        $key = (string) time();
        $token = $this->generateToken($profiler_web_key, $key, $url);

        return implode([
            $url,
            mb_strpos('?', $url) > -1 ? '&' : '?',
            http_build_query([
                'PROFILER_KEY'      => $key,
                'PROFILER_TOKEN'    => $token,
            ])
        ]);
    }

    /**
     * @method void initProfiler()
     * init profiler depending on the selected mode
     */
    protected function initProfiler():void
    {
        $mode = $this->getProfilerMode();
        switch($mode)
        {
            // default off
            case '':
            case 'OFF':
                // nothing to do
                break;

            // app timings
            case 'TIMING':
                $this->registerStartupTracker();
                register_shutdown_function([$this, 'registerShutdownTracker']);
                break;

            // trace functions
            case 'TRACE':
                $this->registerStartupTracker();
                register_shutdown_function([$this, 'registerShutdownTracker']);

                /** add tracer array */
                $this->timestamps['tracker'] = [];

                /** register tracker */
                register_tick_function([$this, 'registerTickTracker']);
                break;

            // unknown mode
            default:
                throw new \Exception("Unknown profiler mode '$mode'");
                break;
        }
    }

    /**
     * @method Profiler getInstance()
     */
    public static function getInstance():self
    {
        static $profiler = null;
        if(is_null($profiler))
            $profiler = new self();

        return $profiler;
    }

    /**
     * @method bool include(string $filename):bool
     * profile include method
     * already returns true
     */
    public function include(string $filename):bool
    {
        static $cache = [];

        if(in_array($filename, $cache) !== false)
            return true;
        // already included

        $code = @file_get_contents($filename);
        $code = preg_replace('#\s*\<\?php#sui', '', $code);
        $code = preg_replace('#\?\>\s*$#sui', '', $code);
        $code = "declare(ticks=1);$code";

        eval($code);
        $cache[] = $filename;

        return true;
    }

    /**
     * @method void registerStartupTracker()
     * set the start time of the application or calculates a new one
     */
    public function registerStartupTracker():void
    {
        $this->timestamps['startup'] = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
    }

    /**
     * @method void registerShutdownTracker()
     * application termination actions
     */
    public function registerShutdownTracker():void
    {
        $this->timestamps['shutdown'] = microtime(true);
        $this->timestamps['executionTime'] = $this->timestamps['shutdown'] - $this->timestamps['startup'];
        if(isset($this->timestamps['tracker']))
        {
            $this->closeLastTracker();

            $this->timestamps['funcExecutionTime'] = 0;
            foreach($this->timestamps['tracker'] as $tracker)
                $this->timestamps['funcExecutionTime']+= $tracker['executionTime'];
        }

        if(!is_null($this->onShutdown))
        {
            /** profiler has shutdown user action */
            call_user_func($this->onShutdown, [$this->timestamps]);
        }
        elseif(class_exists('custom', false))
        {
            /** add hook by custom framework */
            forward_static_call(['custom', 'hook'], [])
                ->run('rein.profiler.results', $this->timestamps);
        }
    }

    /**
     * @method void closeLastTracker()
     * close last method tracker and calc the execution time
     */
    public function closeLastTracker():void
    {
        $traceLastId = count($this->timestamps['tracker']) -1;
        if($traceLastId > -1)
        {
            $traceRecord = &$this->timestamps['tracker'][$traceLastId];
            if(!$traceRecord['ended'])
            {
                $traceRecord['executionTime'] = microtime(true) - $traceRecord['timestamp'];
                $traceRecord['ended'] = true;
            }
        }
    }

    /**
     * @method bool isArrayDiff()
     * returns true if the arrays are different
     */
    protected static function isArraysDiff(array $array1, array $array2):bool
    {
        foreach($array1 as $key => $value)
        {
            if(is_array($value))
            {
                if(!isset($array2[$key]) || !is_array($array2[$key]))
                    return true;

                if(!static::isArraysDiff($value, $array2[$key]))
                    return true;
            }
            else
            {
                if($value !== ($array2[$key] ?? null))
                    return true;
            }
        }

        return false;
    }

    /**
     * @method void registerTickTracker()
     * functions tracing tracker
     */
    public function registerTickTracker():void
    {
        /**
         * @var int|null $nullTimestamp
         * Used to calculate ticks between funcs calls
         */
        static $nullTimestamp = null;

        $caller = (array) (debug_backtrace($this->debug_args, 2)[1] ?? null);
        if($caller)
        {
            /**
             * @var array|null $traceRecord
             * The PHP syntax does not support var reference an inline conditions
             */
            $traceRecord = null;

            $traceLastId = count($this->timestamps['tracker']) -1;
            if($traceLastId > -1)
                $traceRecord = &$this->timestamps['tracker'][$traceLastId];
            
            if(!$traceRecord || static::isArraysDiff($caller, $traceRecord['raw']))
            {
                /**
                 * First time or another method was calling
                 */

                $this->closeLastTracker();

                $id = implode([
                    $caller['class'] ?? '',
                    $caller['type'] ?? '',
                    $caller['function'] ?? '',
                ]);

                $this->timestamps['tracker'][] = [
                    'timestamp' => $nullTimestamp ?? microtime(true),
                    'id' => $id,
                    'executionTime' => 0,
                    'raw' => $caller,
                    'stack' => array_slice(debug_backtrace($this->debug_args, 10), 3),
                    'ended' => false,
                ];
                $nullTimestamp = null;
            }
        }
        else
        {
            /**
             * The function was not called in this interval
             */

            $this->closeLastTracker();
            $nullTimestamp = microtime(true);
        }
    }
}

Profiler::getInstance();