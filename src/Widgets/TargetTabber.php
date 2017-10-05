<?php
namespace mls\ki\Widgets;

/**
* TargetTabber creates a pure-CSS tab navigation widget using the "target" method.
* It allows the default open tab to be specified in the link, making it
* a must when using forms that show their own submit feedback in a tab content area.
* However, it forces scrolling to itself when a tab is clicked, and the
* correct display of the "default default" tab depends on CSS4 which for now is shored up with javascript.
*/
class TargetTabber extends Widget
{
	protected $name     = '';
	protected $contents = array();
	protected $styles   = array();

	/**
	* @param name Name to distinguish multiple tabbers on one page. Appears in the URL when a tab is clicked.
	* @param contents An associative array mapping tab titles to their HTML content
	*/
	function __construct(string $name, array $contents, array $styles)
	{
		$this->name     = $name;
		$this->contents = $contents;
		$this->styles   = $styles;
	}
	
	protected function getHTMLInternal()
	{
		$allowedStylesMain = array('width');
		$allowedStylesContent = array('height');
		$mainStyles = Widget::filterStyles($this->styles, $allowedStylesMain, ['width'=>'350px']);
		$contentStyles = Widget::filterStyles($this->styles, $allowedStylesContent, ['height'=>'auto']);
		
		$out = '<div class="ki_tabber" id="' . $this->name . '" style="' . $mainStyles . '">';
		foreach($this->contents as $tabName => $content)
		{
			$tabId = $this->name . '_' . $tabName;
			$tabId = \preg_replace('/[^A-Za-z0-9\-\_]/', '', $tabId);
			$out .= '<div id="' . $tabId . '"><a href="#' . $tabId . '">' . $tabName . '</a>'
				. '<div style="' . $contentStyles . '">' . $content . '</div></div>';
		}
		$out .= '</div>';
		return $out;
	}
}
?>