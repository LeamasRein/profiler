<?php
putenv('PROFILE_MODE=TRACE');
include "src/Profiler.php";
declare(ticks=1);

class MyClass
{
    public static function test1()
    {
        print "test1 used\n";
    }
    
    public static function test2($timeout)
    {
        sleep($timeout);
        print "test2 used\n";
    }

    public static function test3()
    {
        print "test3 used\n";
    }
}

rein\profiler\Profiler::getInstance()
    ->onShutdown = fn(array $timings) => var_dump($timings);

MyClass::test1();
MyClass::test2(2);
MyClass::test2(2);
MyClass::test3();