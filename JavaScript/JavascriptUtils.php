<?php


namespace Zan\BrowserAutomationBundle\JavaScript;

class JavascriptUtils
{
    /**
     * Escapes all single quotes and surrounds $argument with single quotes
     *
     * @param $argument
     * @return string
     */
    public static function escapeStringArgument($argument)
    {
        return sprintf("'%s'", str_replace("'", '\'', $argument));
    }
}