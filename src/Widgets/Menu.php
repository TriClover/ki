<?php
namespace mls\ki\Widgets;

class Menu extends Widget
{
	public static function getHTML(string $button, array $items, array $styles = array())
	{
		$allowedStylesMain = array('float', 'width');
		$allowedStylesButton = array('height');
		$mainStyles = Menu::filterStyles($styles, $allowedStylesMain);
		$buttonStyles = Menu::filterStyles($styles, $allowedStylesButton);
		
		$fromRight = mb_strpos('float:right;',$mainStyles) !== false;

		$out = '<div tabindex="0" class="ki_menu" style="' . $mainStyles . '">'
			. '<div style="' . $buttonStyles . '"><span>▼</span>' . $button . ' </div><ul style="'
			. ($fromRight ? 'right:0;' : '') . '">';
		foreach($items as $item)
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