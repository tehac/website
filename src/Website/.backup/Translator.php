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
	private static $path = null;

	public static function translate ($text)
	{
		self::setPath();
		self::xgettext($text);

		/*
		$translations = new \Gettext\Translations();
		\Gettext\Extractors\Po::fromFile('locale/cs_CZ/LC_MESSAGES/messages.po', $translations);
		print_r($translations);
		bdump($translations->find($text));
		exit;
		*/

		/*
		$translations = \Gettext\Translations::fromPoFile('locale/cs_CZ/LC_MESSAGES/messages.po');
		bdump($translations);
		bdump($translations->find(null, $text));
		exit;
		*/

		return _($text);
	}

	private static function setPath ()
	{
		global $config;

		self::$path = $config['Script']['path']['module'] . '/_.php';

		if (!file_exists(self::$path ))
		{
			if ($fp = fopen(self::$path, 'w'))
			{
				fwrite($fp, "<?php\n");
				fclose($fp);
			}
			else
			{
				log('Nelze vytvořit soubor: ' . self::$path);
				throw new Exception('Nelze vytvořit soubor: ' . self::$path);
			}
		}
	}

	private static function xgettext ($text)
	{
		include self::$path;

		if (!isset($xgettext[$text]))
		{
			$textToSave = $text;
			/*
			$textToSave = str_replace (
				array("\r", "\n", "'", "  "),
				array(" ", " ", "\'", " "),
				$text
			);
			*/

			if ($fp = fopen(self::$path, 'a'))
			{
				fwrite($fp, "\$xgettext['{$textToSave}'] = _('{$textToSave}');\n");
				fclose($fp);
			}
		}
	}
}