<?php


namespace Zan\BrowserAutomationBundle\ExtJs;


/**
 * Adds helper methods for working with ExtJs applications
 */
trait ExtJsAwareBrowserTrait
{
    public function waitForExtComponent($query)
    {
        $js = sprintf("Ext.ComponentQuery.query(%s)[0].id", $this->escapeJavascriptStringFunctionArgument($query));
        $this->waitForTrue($js);
    }

    /**
     * Clicks on the Ext component specified by $query
     *
     * @param $query
     * @throws \WebDriver\Exception\Timeout
     * @throws null
     */
    public function clickExtComponent($query)
    {
        $this->waitForExtComponent($query);

        $js = sprintf("Ext.ComponentQuery.query(%s)[0].id", $this->escapeJavascriptStringFunctionArgument($query));
        $componentGlobalId = $this->javascriptValue($js);

        if (!$componentGlobalId) {
            throw new \InvalidArgumentException("Could not find ID for component from query %s", $query);
        }

        $component = $this->browserSession->getPage()->find('css', sprintf('#%s', $componentGlobalId));
        $component->click();
    }

    public function waitForExtComponentValueText($query, $text)
    {
        $this->waitForExtComponent($query);

        $componentJs = sprintf("Ext.ComponentQuery.query(%s)[0]", $this->escapeJavascriptStringFunctionArgument($query));

        $isComboBox = $this->immediateJavascriptValue(sprintf('%s instanceof Ext.form.field.ComboBox', $componentJs));

        // Combo boxes: read the value of the displayField for the currently selected record
        if ($isComboBox) {
            $this->waitForTrue(sprintf("%s.findRecordByValue(%s.getValue()).get(%s.displayField).indexOf(%s) != -1",
                $componentJs,
                $componentJs,
                $componentJs,
                $this->escapeJavascriptStringFunctionArgument($text)
            ));
        }
        // Default: use getValue()
        else {
            $this->waitForTrue(sprintf("%s.getValue().indexOf(%s) != -1",
                $componentJs,
                $this->escapeJavascriptStringFunctionArgument($text)
            ));
        }
    }
}