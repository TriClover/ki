<?php
namespace mls\ki\Widgets;

class RadTabber extends Widget
{
	/**
	* RadTabber creates a pure-CSS tab navigation widget using the "radio button" method.
	* Its appearance and operation is flawless compared to TargetTabber but it lacks the
	* ability to link to a specific tab.
	* @param name Name to distinguish multiple tabbers on one page.
	* @param contents An associative array mapping tab titles to their HTML content
	* @param tabAppearance if false, the options will appear as a list of radio buttons instead of tabs
	* @return the HTML for the tabber
	*/
	public static function getHTML(string $name, array $contents, bool $tabAppearance = true, array $styles = array())
	{
		$allowedStylesMain = array('float', 'width', 'border');
		$allowedStylesContent = array('height');
		$mainStyles = Widget::filterStyles($styles, $allowedStylesMain);
		$contentStyles = Widget::filterStyles($styles, $allowedStylesContent);
		
		$fw_class = in_array('width', array_keys($styles)) ? ' class="ki_rtabber_fullWidth"' : '';
		$radClass = $tabAppearance ? '' : ' class="ki_rtabber_untab"';
		
		$out = '<div class="ki_rtabber" style="' . $mainStyles . '" id="' . $name . '">';
		$tabnum=0;
		foreach($contents as $tabName => $content)
		{
			++$tabnum;
			$tabId = $name . 'tab' . $tabnum;
			$checked = ($tabnum == 1) ? ' checked ' : '';
			$out .= '<input type="radio" id="' . $tabId . '" name="' . $name . '"' . $radClass . $checked . '/>';
			$out .= '<label for="' . $tabId . '">' . $tabName . '</label>';
			$out .= '<div style="' . $contentStyles . '"' . $fw_class . '>' . $content . '</div>';
		}
		$out .= '</div>';
		return $out;
	}
}
?>