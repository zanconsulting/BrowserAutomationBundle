<?php


namespace Zan\BrowserAutomationBundle\Robot;


use \Behat\Mink\Session;
use WebDriver\Exception\Timeout;

class BrowserRobot
{
    /**
     * Unique ID for this execution of the browser robot
     *
     * @var string
     */
    protected $id;
    
    /**
     * @var Session
     */
    protected $browserSession;

    /**
     * How long (in seconds) to wait before a javascript command is considered failed
     *
     * @var int seconds
     */
    protected $javascriptExecutionTimeout;

    public function __construct(Session $browserSession)
    {
        $this->browserSession = $browserSession;

        $this->javascriptExecutionTimeout = 30;

        $this->id = uniqid();
    }

    public function setUp()
    {
        $this->browserSession->start();
    }

    public function tearDown()
    {
        $this->browserSession->stop();
    }

    public function evaluateScript($javascript)
    {
        return $this->browserSession->evaluateScript($javascript);
    }

    /**
     * Returns the value of $expression
     *
     * The scope of $expression is the top-level window
     *
     * NOTE: In almost all cases javascriptValue is a better method to use
     *
     * @param $expression
     * @return mixed
     */
    public function immediateJavascriptValue($expression)
    {
        // Construct a javascript expression that returns a json-encoded version
        // of the value
        $readValueJs = sprintf("return JSON.stringify(%s)", $expression);

        $json = $this->browserSession->evaluateScript($readValueJs);

        //printf("VALUE: %s -> %s\n", $expression, print_r(json_decode($json, true), true));
        return json_decode($json, true);
    }

    /**
     * Waits for $expression to evaluate to something other than 'undefined' and
     * then returns whatever it evaluates to.
     *
     * The scope of $expression is the top-level window
     *
     * This function uses json to transport values between the browser and PHP
     * so simple objects should come over as native PHP arrays/values
     *
     * @param      $expression
     * @param null $timeout
     * @return mixed
     * @throws Timeout
     */
    public function javascriptValue($expression, $timeout = null)
    {
        if (null === $timeout) $timeout = $this->javascriptExecutionTimeout;

        //$js = sprintf("function() { try { return (typeof %s !== 'undefined') ? true : false; } catch(ex) { return false; } }()", $expression);
        $start = microtime(true);
        $end = $start + $timeout;

        $lastException = null;
        $result = 'ZAN_BROWSER_ROBOT_UNCHANGED_VALUE';
        do {
            try {
                $lastException = null;
                $result = $this->immediateJavascriptValue($expression);
            } catch (\Exception $e) {
                // Track the last exception so we can re-throw it if we time out
                $lastException = $e;
            }
            usleep(100000);
        } while (microtime(true) < $end && 'ZAN_BROWSER_ROBOT_UNCHANGED_VALUE' == $result);

        if (microtime(true) >= $end) {
            // If we timed out with an exception throw that instead
            if ($lastException) {
                throw $lastException;
            }
            // Otherwise, a more generic exception
            else {
                throw new Timeout(sprintf("Timed out waiting for value: %s", $expression));
            }
        }

        return $this->immediateJavascriptValue($expression);
    }

    /**
     * Waits for $expression to execute without throwing an exception
     *
     * @param      $expression
     * @param null $timeout
     * @return bool
     * @throws Timeout
     */
    public function javascript($expression, $timeout = null)
    {
        if (null === $timeout) $timeout = $this->javascriptExecutionTimeout;

        $js = sprintf("function() { try { %s } catch(ex) { return 'ZAN_BROWSER_ROBOT_RETRY_EXCEPTION'; } }()", $expression);
        $start = microtime(true);
        $end = $start + $timeout;

        do {
            $result = $this->browserSession->evaluateScript($js);
            usleep(100000);
        } while (microtime(true) < $end && 'ZAN_BROWSER_ROBOT_RETRY_EXCEPTION' == $result);

        if (microtime(true) >= $end) {
            throw new Timeout(sprintf("Timed out waiting for javascript: %s", $expression));
        }

        return true;
    }

    /**
     * Waits for the given javascript $expression to return a true value
     * @param      $expression
     * @param null $timeout
     * @throws Timeout
     * @throws null
     */
    public function waitForTrue($expression, $timeout = null)
    {
        if (null === $timeout) $timeout = $this->javascriptExecutionTimeout;

        $start = microtime(true);
        $end = $start + $timeout;

        $lastException = null;
        $result = null;
        do {
            try {
                $lastException = null;
                $result = $this->immediateJavascriptValue($expression);
            } catch (\Exception $e) {
                // Track the last exception so we can re-throw it if we time out
                $lastException = $e;
            }
            usleep(100000);
        } while (microtime(true) < $end && !$result);

        if (microtime(true) >= $end) {
            // If we timed out with an exception throw that instead
            if ($lastException) {
                throw $lastException;
            }
            // Otherwise, a more generic exception
            else {
                throw new Timeout(sprintf("Timed out waiting for true: %s", $expression));
            }
        }
    }

    public function waitForText($text, $parentElementExpression = null, $timeout = null)
    {
        $this->waitForFunction(function() use ($text, $parentElementExpression) {
            $inElement = null;
            if ($parentElementExpression) {
                $inElement = $this->browserSession->getPage()->find('css', $parentElementExpression);
            }
            else {
                $inElement = $this->browserSession->getPage();
            }

            return $inElement->hasContent($text);
        }, $timeout);
    }

    public function waitForFunction($function, $timeout = null) {
        if (null === $timeout) $timeout = $this->javascriptExecutionTimeout;

        $start = microtime(true);
        $end = $start + $timeout;

        $result = null;
        do {
            try {
                $lastException = null;
                $result = call_user_func($function);
            } catch (\Exception $e) {
                // Track the last exception so we can re-throw it if we time out
                $lastException = $e;
            }
            usleep(100000);
        } while (microtime(true) < $end && !$result);

        if (microtime(true) >= $end) {
            // If we timed out with an exception throw that instead
            if ($lastException) {
                throw $lastException;
            }
            // Otherwise, a more generic exception
            else {
                throw new Timeout();
            }
        }
    }

    /**
     * Escapes all single quotes and surrounds $argument with single quotes
     *
     * @param $argument
     * @return mixed
     */
    protected function escapeJavascriptStringFunctionArgument($argument)
    {
        return sprintf("'%s'", str_replace("'", '\'', $argument));
    }

    /**
     * @return int
     */
    public function getJavascriptExecutionTimeout()
    {
        return $this->javascriptExecutionTimeout;
    }

    /**
     * @param int $javascriptExecutionTimeout
     */
    public function setJavascriptExecutionTimeout($javascriptExecutionTimeout)
    {
        $this->javascriptExecutionTimeout = $javascriptExecutionTimeout;
    }

    /**
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }
}