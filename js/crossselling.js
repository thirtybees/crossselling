/**
 * Copyright (C) 2017-2019 thirty bees
 * Copyright (C) 2007-2016 PrestaShop SA
 *
 * thirty bees is an extension to the PrestaShop software by PrestaShop SA.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 * @author    thirty bees <modules@thirtybees.com>
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2017-2019 thirty bees
 * @copyright 2007-2016 PrestaShop SA
 * @license   Academic Free License (AFL 3.0)
 * PrestaShop is an internationally registered trademark of PrestaShop SA.
 */

var cs_serialScrollNbImagesDisplayed;
var cs_serialScrollNbImages;
var cs_serialScrollActualImagesIndex;

function cs_serialScrollFixLock(event, targeted, scrolled, items, position)
{
	serialScrollNbImages = $('#crossselling_list li:visible').length;
	serialScrollNbImagesDisplayed = 5;

	var leftArrow = position == 0 ? true : false;
	var rightArrow = position + serialScrollNbImagesDisplayed >= serialScrollNbImages ? true : false;

	$('a#crossselling_scroll_left').css('cursor', leftArrow ? 'default' : 'pointer').css('display', leftArrow ? 'none' : 'block').fadeTo(0, leftArrow ? 0 : 1);
	$('a#crossselling_scroll_right').css('cursor', rightArrow ? 'default' : 'pointer').fadeTo(0, rightArrow ? 0 : 1).css('display', rightArrow ? 'none' : 'block');
	return true;
}

$(document).ready(function(){
	if($('#crossselling_list').length > 0)
	{
		//init the serialScroll for thumbs
		cs_serialScrollNbImages = $('#crossselling_list li').length;
		cs_serialScrollNbImagesDisplayed = 5;
		cs_serialScrollActualImagesIndex = 0;
		$('#crossselling_list').serialScroll({
			items:'li',
			prev:'a#crossselling_scroll_left',
			next:'a#crossselling_scroll_right',
			axis:'x',
			offset:0,
			stop:true,
			onBefore:cs_serialScrollFixLock,
			duration:300,
			step: 1,
			lazy:true,
			lock: false,
			force:false,
			cycle:false
		});
		$('#crossselling_list').trigger( 'goto', [cs_middle-3] );
	}
});
