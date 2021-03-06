<?php

namespace Mix\Select;

use Mix\Coroutine\Coroutine;
use Mix\Select\Clause\ClauseIntercase;
use Mix\Select\Clause\Pop;
use Mix\Select\Clause\Push;
use Mix\Coroutine\Channel;

/**
 * Class Select
 * @package Mix\Select
 */
class Select
{

    const BREAK = 'BREAK';

    /**
     * @var Clauses
     */
    protected $clauses;

    /**
     * @var bool
     */
    protected $break = false;

    /**
     * @var \Swoole\Coroutine\Channel
     */
    protected $waitChannel;

    /**
     * Select constructor.
     * @param \Closure ...$clauses
     */
    public function __construct(\Closure ...$clauses)
    {
        $this->options = new Clauses();
        foreach ($clauses as $option) {
            call_user_func($option, $this->options);
        }
    }

    /**
     * Case
     * @param ClauseIntercase $clause
     * @param \Closure $statement
     * @return \Closure
     */
    public static function case(ClauseIntercase $clause, \Closure $statement): \Closure
    {
        return function (Clauses $clauses) use ($clause, $statement) {
            $clauses->cases[] = [
                'clause'    => $clause,
                'statement' => $statement,
            ];
        };
    }

    /**
     * Default
     * @param \Closure $statement
     * @return \Closure
     */
    public static function default(\Closure $statement): \Closure
    {
        return function (Clauses $clauses) use ($statement) {
            $clauses->default = $statement;
        };
    }

    /**
     * Pop
     * @return ClauseIntercase
     */
    public static function pop(Channel $channel): ClauseIntercase
    {
        return new Pop($channel);
    }

    /**
     * Push
     * @return ClauseIntercase
     */
    public static function push(Channel $channel, $value): ClauseIntercase
    {
        return new Push($channel, $value);
    }

    /**
     * Run
     * @return $this
     */
    public function run()
    {
        $clauses = $this->options;

        $processes = [];
        foreach ($clauses->cases as $case) {
            /** @var ClauseIntercase $clause */
            $clause    = $case['clause'];
            $statement = $case['statement'];
            if ($clause instanceof Push && !$clause->channel()->isFull()) {
                $processes[] = function () use ($clause, $statement) {
                    $clause->run();
                    $return       = call_user_func($statement);
                    $this->return = $return ? true : false;
                };
            }
            if ($clause instanceof Pop && !$clause->channel()->isEmpty()) {
                $processes[] = function () use ($clause, $statement) {
                    $value        = $clause->run();
                    $return       = call_user_func($statement, $value);
                    $this->return = $return ? true : false;
                };
            }
        }

        if (!empty($processes)) {
            call_user_func($processes[array_rand($processes)]);
            return $this;
        }

        if ($clauses->default) {
            call_user_func($clauses->default);
            return $this;
        }

        // 阻塞，直到某个通信可以运行
        // 没有可运行的通信才会执行到这里
        $this->wait();

        return $this;
    }

    /**
     * Wait and run
     */
    protected function wait()
    {
        $this->waitChannel = $waitChannel = new \Swoole\Coroutine\Channel(); // 必须是 Swoole 的 Channel
        $clauses           = $this->options;
        $processe          = null;

        foreach ($clauses->cases as $case) {
            /** @var ClauseIntercase $clause */
            $clause = $case['clause'];
            $clause->channel()->addNotifier($waitChannel);
        }

        while (true) {
            $waitChannel->pop();
            foreach ($clauses->cases as $case) {
                /** @var ClauseIntercase $clause */
                $clause    = $case['clause'];
                $statement = $case['statement'];
                if ($clause instanceof Pop && !$clause->channel()->isEmpty()) {
                    $processe = function () use ($clause, $statement) {
                        $value       = $clause->run();
                        $break       = call_user_func($statement, $value);
                        $this->break = $break == static::BREAK ? true : false;
                    };
                    break;
                }
                if ($clause instanceof Push && !$clause->channel()->isFull()) {
                    $processe = function () use ($clause, $statement) {
                        $clause->run();
                        $break       = call_user_func($statement);
                        $this->break = $break == static::BREAK ? true : false;
                    };
                    break;
                }
            }
            if ($processe) {
                break;
            }
        }

        call_user_func($processe);
    }

    /**
     * @return bool
     */
    public function break()
    {
        return $this->break;
    }

    /**
     * Destruct
     */
    public function __destruct()
    {
        $waitChannel = $this->waitChannel;
        if ($waitChannel) {
            $clauses = $this->options;
            foreach ($clauses->cases as $case) {
                /** @var ClauseIntercase $clause */
                $clause = $case['clause'];
                $clause->channel()->delNotifier($waitChannel);
            }
        }
    }

}
