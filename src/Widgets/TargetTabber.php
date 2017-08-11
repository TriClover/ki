<?php
namespace mls\ki\Widgets;

class TargetTabber
{
	/**
	* targetTabber creates a pure-CSS tab navigation widget using the "target" method.
	* It allows the default open tab to be specified in the link, making it
	* a must when using forms that show their own submit feedback in a tab content area.
	* However, it forces scrolling to itself when a tab is clicked, and the
	* correct display of the "default default" tab depends on CSS4 which for now is shored up with javascript.
	* @param name Name to distinguish multiple tabbers on one page. Appears in the URL when a tab is clicked.
	* @param contents An associative array mapping tab titles to their HTML content
	* @return the HTML for the tabber
	*/
	public static function getHTML(string $name, array $contents, string $cssWidth="350px", string $cssHeight="auto")
	{
		$out = '<div class="ki_tabber" id="' . $name . '" style="width:' . $cssWidth . ';">';
		foreach($contents as $tabName => $content)
		{
			$tabId = htmlspecialchars($name . '_' . $tabName);
			$out .= '<div id="' . $tabId . '"><a href="#' . $tabId . '">' . $tabName . '</a>'
				. '<div style="height:' . $cssHeight . ';">' . $content . '</div></div>';
		}
		$out .= '</div>';
		return $out;
	}
}
?>