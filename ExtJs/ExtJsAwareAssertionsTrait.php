<?php


namespace Zan\BrowserAutomationBundle\ExtJs;


use Zan\BrowserAutomationBundle\JavaScript\JavascriptUtils;
use Zan\BrowserAutomationBundle\Robot\BrowserRobot;

trait ExtJsAwareAssertionsTrait
{
    /**
     * @param $query
     * @param $value
     */
    public function assertExtComponentValueLike($query, $value, $message = null)
    {
        $this->assertTrue($this->browser->javascriptValue(
            sprintf("Zan.qa.BrowserTestInterop.isValueLike(%s, %s)",
                JavascriptUtils::escapeStringArgument($query),
                JavascriptUtils::escapeStringArgument($value)
            )
        ), $message);
    }

}