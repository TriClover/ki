<?php
namespace mls\ki\Widgets;

/**
* RadTabber creates a pure-CSS tab navigation widget using the "radio button" method.
* Its appearance and operation is flawless compared to TargetTabber but it lacks the
* ability to link to a specific tab.
*/
class RadTabber extends Widget
{
	protected $name          = '';
	protected $contents      = array();
	protected $tabAppearance = true;
	protected $styles        = array();
	
	/**
	* @param name Name to distinguish multiple tabbers on one page.
	* @param contents An associative array mapping tab titles to their HTML content
	* @param tabAppearance if false, the options will appear as a list of radio buttons instead of tabs
	*/
	function __construct(string $name, array $contents, bool $tabAppearance = true, array $styles = array())
	{
		$this->name          = $name;
		$this->contents      = $contents;
		$this->tabAppearance = $tabAppearance;
		$this->styles        = $styles;
	}

	protected function getHTMLInternal()
	{
		$allowedStylesMain = array('float', 'width', 'border');
		$allowedStylesContent = array('height');
		$mainStyles = Widget::filterStyles($this->styles, $allowedStylesMain);
		$contentStyles = Widget::filterStyles($this->styles, $allowedStylesContent);
		
		$fw_class = in_array('width', array_keys($this->styles)) ? ' class="ki_rtabber_fullWidth"' : '';
		if(in_array('height', array_keys($this->styles))) $mainStyles .= 'margin-bottom:' . $this->styles['height'];
		$radClass = $this->tabAppearance ? '' : ' class="ki_rtabber_untab"';
		
		$out = '<div class="ki_rtabber" style="' . $mainStyles . '" id="' . $this->name . '">';
		$tabnum=0;
		foreach($this->contents as $tabName => $content)
		{
			++$tabnum;
			$tabId = $this->name . 'tab' . $tabnum;
			$checked = ($tabnum == 1) ? ' checked ' : '';
			$out .= '<input type="radio" id="' . $tabId . '" name="' . $this->name . '"' . $radClass . $checked . '/>';
			$out .= '<label for="' . $tabId . '">' . $tabName . '</label>';
			$out .= '<div style="' . $contentStyles . '"' . $fw_class . '>' . $content . '</div>';
		}
		$out .= '</div>';
		return $out;
	}
}
?>