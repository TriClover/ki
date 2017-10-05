<?php
namespace mls\ki\Widgets;

class Menu extends Widget
{
	protected $button = '';
	protected $items  = array();
	protected $styles = array();
	
	function __construct(string $button, array $items, array $styles = array())
	{
		$this->button = $button;
		$this->items  = $items;
		$this->styles = $styles;
	}
	
	protected function getHTMLInternal()
	{
		$allowedStylesMain = array('float', 'width');
		$allowedStylesButton = array('height');
		$mainStyles = Widget::filterStyles($this->styles, $allowedStylesMain);
		$buttonStyles = Widget::filterStyles($this->styles, $allowedStylesButton);
		
		$fromRight = mb_strpos('float:right;',$mainStyles) !== false;

		$out = '<div tabindex="0" class="ki_menu" style="' . $mainStyles . '">'
			. '<div style="' . $buttonStyles . '"><span>▼</span>' . $this->button . ' </div><ul style="'
			. ($fromRight ? 'right:0;' : '') . '">';
		foreach($this->items as $item)
		{
			if(!$item instanceof MenuItem) continue;
			$out .= '<li>';
			if(!is_array($item->postdata))
			{
				$out .= '<a href="' . $item->url . '">' . $item->title . '</a>';
			}else{
				$out .= '<form action="' . $item->url . '" method="post">';
				foreach($item->postdata as $key => $value)
					$out .= '<input type="hidden" name="' . $key . '" value="' . $value . '"/>';
				$out .= '<input type="submit" class="button2text" value="' . $item->title . '"/>';
				$out .= '</form>';
			}
			$out .= '</li>';
		}
		$out .= '</ul></div>';
		return $out;
	}
}
?>