<?php


namespace Zan\BrowserAutomationBundle\Robot;


use Behat\Mink\Element\NodeElement;
use \Behat\Mink\Session;
use WebDriver\Exception\Timeout;
use Zan\BrowserAutomationBundle\JavaScript\JavascriptUtils;

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

    /**
     * How long (in microseconds) to wait while polling when checking for elements,
     * javascript values, etc.
     *
     * @var int microseconds
     */
    protected $loopDelayUs;

    public function __construct(Session $browserSession)
    {
        $this->browserSession = $browserSession;

        $this->javascriptExecutionTimeout = 30;
        $this->loopDelayUs = 250000;

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

    /**
     * Clicks on the element with the specified text.
     *
     * This text is matched exactly.
     *
     * @param      $text
     * @param null $parentElementExpression
     */
    public function click($text, $parentElementExpression = null)
    {
        $xpath = sprintf("//*[text()='%s']", $text);

        $this->waitForFunction(function() use ($text, $xpath, $parentElementExpression) {
            $inElement = null;
            if ($parentElementExpression) {
                $inElement = $this->browserSession->getPage()->find('css', $parentElementExpression);
            }
            else {
                $inElement = $this->browserSession->getPage();
            }

            $elements = $inElement->findAll('xpath', $xpath);

            // Nothing found
            if (!$elements) return false;

            if (count($elements) > 1) {
                throw new \InvalidArgumentException(sprintf('Text "%s" matched multiple elements', $text));
            }

            /** @var NodeElement $element */
            $element = $elements[0];
            $element->click();

            // Found and clicked element, return true
            return true;
        }, $this->getJavascriptExecutionTimeout());
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

        try {
            $json = $this->browserSession->evaluateScript($readValueJs);
        }
        catch (\Exception $ex) {
            throw new \Exception(sprintf("%s\n\nwhile evaluating:\n------------------------------------\n%s\n------------------------------------\n", $ex->getMessage(), $readValueJs));
        }


        //fwrite(STDERR, sprintf("VALUE: %s -> %s -> %s\n", $expression, $json, print_r(json_decode($json, true), true)));
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

        // Clean up expression
        if (substr($expression, -1) == ';') $expression = substr($expression, 0, -1);

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
                usleep($this->loopDelayUs);
            }
        } while (microtime(true) < $end && 'ZAN_BROWSER_ROBOT_UNCHANGED_VALUE' === $result);

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

        return $result;
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
            //printf("--------------------\n%s\n--------------------\n", $js);
            $result = $this->browserSession->evaluateScript($js);
            usleep($this->loopDelayUs);
        } while (microtime(true) < $end && 'ZAN_BROWSER_ROBOT_RETRY_EXCEPTION' == $result);

        if (microtime(true) >= $end) {
            throw new Timeout(sprintf("Timed out waiting for javascript: %s", $expression));
        }

        return true;
    }

    /**
     * @param $variableName
     * @return mixed
     */
    public function getTestVariableValue($variableName)
    {
        $value = null;

        $this->waitForFunction(function() use ($variableName, &$value) {
            $readValue = $this->javascriptValue(
                sprintf("window.ZAN_BROWSERTEST_VARIABLES[%s]",
                $this->escapeJavascriptStringFunctionArgument($variableName))
            );

            // Assume empty value means its not set yet
            if (!$readValue) return false;

            else {
                $value = $readValue;
                return true;
            }
        });

        return $value;
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

                if (stristr($e->getMessage(), 'session deleted because of page crash')) {
                    throw new \ErrorException("Session crashed!");
                }
            }
            usleep($this->loopDelayUs);
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
            usleep($this->loopDelayUs);
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
        return JavascriptUtils::escapeStringArgument($argument);
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