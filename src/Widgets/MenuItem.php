<?php
namespace mls\ki\Widgets;

/**
* Items for the constructor of the Menu widget.
*/
class MenuItem
{
	public $title;    //The text shown
	public $url;      //URL of the link; can include arguments/fragment
	public $postdata; //If not NULL, item will be a POST form instead of a link, and have hidden elements with this data
	function __construct(string $title, string $url, array $postdata = NULL)
	{
		$this->title = $title;
		$this->url = $url;
		$this->postdata = $postdata;
	}
}
?>