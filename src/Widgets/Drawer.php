<?php
namespace mls\ki\Widgets;

/**
* Drawer creates a pure-CSS "drawer" like those seen on mobile apps.
* Uses absolute positioning to fill the space it is placed in. Can be contained with a relative div.
*/
class Drawer extends Widget
{
	protected $name      = '';
	protected $contents  = '';
	protected $edge      = Drawer::EDGE_LEFT;
	protected $styles    = array();
	protected $buttonText= '';
	protected $open      = false;
	
	public const EDGE_TOP    = 0;
	public const EDGE_RIGHT  = 1;
	public const EDGE_BOTTOM = 2;
	public const EDGE_LEFT   = 3;
	
	public const DEFAULT_BUTTON = '<b>☰</b>';
	
	/**
	* @param name Name to distinguish multiple widgets on one page.
	* @param contents HTML that goes in the drawer
	* @param edge Which edge of the screen the drawer should be attached to
	* @param buttonText the text to show on the activation button
	*/
	function __construct(string $name,
	                     string $contents,
						 int    $edge = Drawer::EDGE_LEFT,
						 string $buttonText = Drawer::DEFAULT_BUTTON,
						 bool   $open = false,
						 array  $styles = [])
	{
		$this->name       = htmlspecialchars($name);
		$this->contents   = $contents;
		$this->edge       = $edge;
		$this->styles     = $styles;
		$this->buttonText = empty($buttonText) ? Drawer::DEFAULT_BUTTON : $buttonText;
		$this->open       = $open;
	}

	protected function getHTMLInternal()
	{
		$allowedStylesMain = array('border','background','background-color','background-image','background-repeat','background-attachment','background-position');
		$mainStyles = Widget::filterStyles($this->styles, $allowedStylesMain);
		$out = '<input type="checkbox" id="' . $this->name . '" class="ki_drawer '
			. Drawer::edgeToCss($this->edge) . '"' . ($this->open ? ' checked' : '') . '/>';
		$out .= '<label for="' . $this->name . '"></label>';
		$out .= '<label for="' . $this->name . '">' . $this->buttonText . '</label>';
		$out .= '<div style="' . $mainStyles . '"><label for="' . $this->name . '">❌</label>'
			. $this->contents . '</div>';
		return $out;
	}
	
	public static function edgeToCss(int $edge)
	{
		$out = '';
		switch($edge)
		{
			case Drawer::EDGE_TOP:    $out = 'ki_edge_top';    break;
			case Drawer::EDGE_RIGHT:  $out = 'ki_edge_right';  break;
			case Drawer::EDGE_BOTTOM: $out = 'ki_edge_bottom'; break;
			case Drawer::EDGE_LEFT:   $out = 'ki_edge_left';   break;
		}
		return $out;
	}
}
?>