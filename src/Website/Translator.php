<?php
/**
 * Created by PhpStorm.
 * User: tomas@haluza.cz
 * Date: 02.07.2016
 * Time: 20:33
 */

namespace Website;

use \Exception;

class Translator
{
	public static function translate ($text)
	{
		return _($text);
	}
}