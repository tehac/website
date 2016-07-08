<?php
/*-- třída pro zobrazení obsahu webu --*/
/*-- © Tomáš Haluza, www.haluza.cz   --*/
/*-- 28.06.2016                      --*/

namespace Website;

use \Exception;
use \Latte\Engine as Latte;
use \Gettext\Translations as Translations;
use \Gettext\Generators\Po as Po;


class Presenter
{
	public $debug;					// debug - zobraz prázdné hodnoty pro template
	
	public $moduleID;				// ID zpracováváného modulu

	public $encoding;				// compress ?
	public $modulePath;				// cesta k modulu
	public $templatePath;			// cesta k šabloně
	public $cachePath;				// cesta ke cache
	public $html;					// HTML výstup
	public $lang;					// id jazyka, výchozí = 'cs'

	public $locale;					// locale (cs_CZ.utf8)
	public $locales;				// locales [cs_CZ.utf8]
	public $localePo;				// message.po
	public $localeMo;				// message.mo
	public $translations;			// překlady
	public $translationsInserted;	// je nějaký neuložený překlad

	public $config;					// konfigurační parametry
	public $moduleRights;			// dodatečná práva k modulu
	public $module;					// vykonávaný modul webu
	public $params;					// proměnné pro parsování do šablon
	public $script;					// hodnoty prováděné stránky, modulu
	public $htmlArray;				// HTML výstup v poli

	public $latte;					// šablonovací systém

	/**
	 * Constructor
	 */
	function __construct()
	{
		global $config;

		$this->debug = false;
		if (
			(isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] == "192.168.1.99")
			|| (isset($argv[1]) && $argv[1] == "local")
		)
		{
			$this->debug = true;
		}

		$this->config = $config;
		$this->moduleRights = array ();
		$this->module = array (
			'path' 		=> $config['Script']['path']['module'].'/home.php',	// cesta k zobrazované stránce, výchozí hodnota home page
			'_GET' 		=> array(),												// předané parametry
			'textID'	=> NULL													// textový ID stránky
		);												
		$this->script = array();
		$this->htmlArray = NULL;
		$this->params = array(
			'TITLE' 	=> $config['Web']['title'],		// titulek stránky - výchozí hodnota
			'VERZE' 	=> $config['Admin']['version'],	// version
			'GENERATED'	=> 0,
		);
		$this->aLang = array();

		$this->modulePath = $config['Script']['path']['module'];
		$this->templatePath = $GLOBALS['latte']['templatePath'] = $config['Script']['path']['template'];
		$this->cachePath = $config['Script']['path']['cache'];
		$this->html = '';

		$this->lang = 'cs';
		$this->locale = setlocale(LC_ALL, 0);
		$this->locales = $config['Web']['locales'];
		$this->localePo = $config['Web']['localePo'];
		$this->localeMo = $config['Web']['localeMo'];
		$this->translations = Translations::fromPoFile($this->locales[str_replace(".utf8", "", $this->locale)].'/'.$this->localePo);
		$this->translationsInserted = false;

		// překlady - gettext init
		$this->gettext();

		// Latte + překlad šablony
		$this->latte = new Latte();
		$this->latte->setTempDirectory($config['Script']['path']['cache']);
		$this->latte->addFilter('translate', function ($text) {
			return $this->translate($text);
		});
		$this->latte->addFilter('json_encode', function ($text) {
			return json_encode($text, JSON_UNESCAPED_UNICODE);
		});

		// vymaž moduly uložené v cache
		if (isset($_GET['nocache']))
		{
			$this->deleteCache();
		}

		// čas začátku provádění skriptu
		$this->script['time']['start'] = microtime();
	}

	// nastaverní gettext
	private function gettext ()
	{
		$domain = 'messages';

		@putenv('LANG='.$this->locale);
		bindtextdomain($domain, $this->config['Script']['path']['locale']);
		textdomain($domain);
	}

	// přelož výraz ze šablony
	private function translate ($text)
	{
		// je přidáno v .po souboru?
		if (!$this->translations->find(null, $text))
		{
			bdump("Přidávám do překladu: {$text}");
			$insertedTranslation = $this->translations->insert(null, $text);
			$this->translationsInserted = true;
		}

		return _($text);
	}

	/*
	 * 1) find modules
	 * 2) parse modules
	 */
	public function renderOutput()
	{
		// jazkové mutace
		//$this->_language();

		// najdi cestu k zobrazované stránce
		$this->findModule();

		// načti modul
		if (file_exists($this->module['path']))
		{
			include $this->module['path'];
		}
		else
		{
			log('neexistujici nastaveni modulu: '. print_r($this->module['path'], true));
			throw new Exception('neexistujici nastaveni modulu: '. print_r($this->module['path'], true));
		}
	}

	// vytvoř HTML výstup, pošli na výstup
	function writeOutput ($sHeader = '')
	{
		// zjisti podporované kódování
		$this->findEncoding();

		// doba ukončení skriptu - pro testování doby běhu
		list($iSecStart, $iMsesStart) = explode (" ", $this->script['time']['start']);
		$this->script['time']['end'] = microtime ();
		list($iSecEnd, $iMsecEnd) = explode (" ", $this->script['time']['end']);
		$this->script['time']['created'] = ($iSecEnd + $iMsecEnd) - ($iSecStart + $iMsesStart);

		// přepiš titulek stránky a ostatní neparsované podle aktuální hodnoty
		$this->html = !empty($this->htmlArray) ? implode ("", $this->htmlArray) : ($this->html != "" ? $this->html : '');
		foreach ($this->params as $var => $value)
		{
			$this->html = str_replace (
				"{ _".$var."_ }",
				($var == "GENERATED" ? $this->script['time']['created'].' seconds' : $value),
				$this->html
			);
		}

		// pošli header
		if (!empty($sHeader) && !headers_sent ())
		{
			header ($sHeader);
		}

		// pokud existuje komprese prohlížeče a nejsou poslané header, komprimuj výsledné HTML
		if (!is_null ($this->encoding) && !headers_sent () && (ob_get_contents () === false || ob_get_contents () === ""))
		{
			header ("Content-Encoding: {$this->encoding}");
			echo "\x1f\x8b\x08\x00\x00\x00\x00\x00";
			$sGZIP = gzcompress ($this->html, 7);
			$sGZIP = substr ($sGZIP, 0, strlen ($sGZIP) - 4);

			echo $sGZIP;
			echo pack ('V', crc32 ($this->html));
			echo pack ('V', strlen ($this->html));
		}
		else
		{
			echo $this->html;
		}

		// smaž hodnoty, které by neměly být vidět
		unset($this->htmlArray, $this->html, $this->script['script'], $this->script['cache'], $this->config['SQL']);

		// ze které stránky se přistupovalo - pro tlačítko zpět na další stránce
		if (isset($this->module['array']) && !empty($this->module['array']))
		{
			$_SESSION['script']['referer'] = '/' . join ("/", $this->module['array']) . '/';
		}
		else
		{
			$_SESSION['script']['referer'] = '/';
		}

		// vložili jsme nějaký nový překlad:
		if ($this->translationsInserted)
		{
			$localePath = $this->locales[str_replace(".utf8", "", $this->locale)].'/'.$this->localePo;

			if (!$this->translations->toPoFile($localePath));
			{
				log('Nelze uložit překlady: '. $localePath);
				//throw new Exception('Nelze uložit překlady: '. $localePath);
			}
		}
	}

	// zjisti právě zobrazovaný modul z předané hodnoty mod_rewrite
	function findModule()
	{
		if (isset($_GET['path2module']) && !empty($_GET['path2module']))
		{
			$tmp = preg_split("/\//", $_GET['path2module']);
			$extension = array_pop($tmp);

			// najdi ID, pokud existuje přípona .html
			if (preg_match("/^([\w\W\-]+)\.html$/", $extension, $id))
			{
				$this->module['textID'] = $id[1];

				// najdi parametry - ID, strana
				if (preg_match("/^([\w\-]+)\-strana\-(\d+)\.html$/", $extension, $id))
				{
					// id
					if (preg_match("/^([\w\-]+)\-(\d+)$/", $id[1], $idSub))
					{
						$this->module['textID'] = $idSub[1];
						$this->module['_GET']['id'] = intval($idSub[2]);
					}
					else
					{
						$this->module['textID'] = $id[1];
					}
					$this->module['_GET']['strana'] = intval($id[2]);
				}
				// najdi parametry - strana
				else if (preg_match("/^strana\-(\d+)\.html$/", $extension, $id))
				{
					$this->sTextID = '';
					$this->module['_GET']['strana'] = intval($id[1]);
				}
				// najdi parametry - textID, ID
				else if (preg_match("/^([\w\-]+)\-(\d+)\.html$/", $extension, $id))
				{
					$this->module['textID'] = $id[1];
					$this->module['_GET']['id'] = intval($id[2]);
				}
			}
			// najdi ID, pokud existuje přípona .xml
			else if (preg_match("/^([\w\W\-]+)\.xml$/", $extension, $id))
			{
				// najdi parametry - textID, ID
				if (preg_match("/^([\w\-]+)\-(\d+)\.xml$/", $extension, $id))
				{
					$this->module['textID'] = $id[1];
					$this->module['_GET']['id'] = intval($id[2]);
				}
			}

			$this->createPath($tmp);

			// nezobraz konkrétní modul, začínající '_', vždy celou stránku!
			if ($tmp[count($tmp)-1][0] != "_")
			{
				$this->module['path'] = $this->modulePath.'/'.join("/", $tmp).'.php';
				$this->module['array'] = $tmp;
			}
		}
	}

	// zjisti možnost komprese výstupu
	function findEncoding ()
	{
		if (isset($_SERVER['HTTP_ACCEPT_ENCODING']))
		{
			if (strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'x-gzip') !== false)
			{
				$this->encoding = 'x-gzip';
			}
			else if (strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false)
			{
				$this->encoding = 'gzip';
			}
		}
	}
	
	// vytvoření cesty k stránce, modulu, cache
	// $aThisPath  - cesta k modulu webu
	function createPath ($path)
	{
		$this->script['script']['array'] = $path;
		$this->script['script']['path'] = strtolower($this->modulePath.'/'.join("/", $path).'.php');
		$this->script['script']['time'] = microtime();
		$this->script['template']['path'] = strtolower($this->templatePath.'/'.join("/", $path).'.tpl');
	}
	
	// vytvoření modulu
	// $aPath - cesta k modulu
	// $aCFG - parametry pro vykonávaný modul (platnost, uložení do cache)
	function createModule ($path, $params = array())
	{
		$this->createPath($path);

		// vykonej modul
		$this->includeModule($this->script, $params);
	}

	// vytvoření modulů
	function createModules ($params)
	{
		foreach ($params as $module)
		{
			$this->createModule($module['module'], $module['params']);
		}
	}
	
	// načti a vykonej PHP, zapiš HTML cache
	// $aModule - parametry vykonávaného modulu
	// $bSave - uložení modulu (např. autentifikační modul se neukládá)
	function includeModule ($module, $moduleParams)
	{
		// klíč modulu (cesta)
		$sKey = implode("%", $this->script['script']['array']);

		// načti a vykonej PHP modul
		$this->script['time']['script'][$sKey] = microtime();
		if (file_exists($module['script']['path']))
		{
			include $module['script']['path'];
		}
		else
		{
			log('neexistujici modul: '. print_r($module['script']['path'], true));
			throw new Exception('neexistujici modul: '. print_r($module['script']['path'], true));
		}
		
		// čas vykonání
		if (isset($this->script['time']['script'][$sKey]))
		{
			list($iSecStart, $iMsesStart) = explode(" ", $this->script['time']['script'][$sKey]);
			list($iSecEnd, $iMsecEnd) = explode(" ", microtime());
			$this->script['time']['script'][$sKey] = ($iSecEnd + $iMsecEnd) - ($iSecStart + $iMsesStart);
		}

		$this->htmlArray[$sKey] = $this->html;
	}

	// vytvoření modulu - neukládá se do cache, vrátí obsah
	// $aPath - cesta k modulu
	function parseModule ($aPath, $aParams = array())
	{
		$this->html = '';
		$module = $this->modulePath.'/'.implode("/", $aPath).'.php';

		// načti modul
		if (file_exists($module))
		{
			include $module;
		}
		else
		{
			log('neexistujici modul: '.$module);
			throw new Exception('neexistujici modul: '.$module);
		}

		return $this->html;
	}

	// vytvoření HTML ze šablony - spouští se pro každný modul
	// $sTemplatePath - cesta k šabloně
	function createTemplate ($params, $templatePath = '')
	{
		// načti šablonu, výchozí cesta je jako spouštěný modul
		// pokud není prázdná hodnota, načti šablonu z hodnoty
		$templatePath = $templatePath == "" ? $this->script['template']['path'] : $this->templatePath.$templatePath;

		$this->html = $this->latte->renderToString($templatePath, (array) $params);
	}

	// vložení JS definicí k dané šabloně
	function createJavascript (&$javascript)
	{
		$module = isset($this->module['array']) && !empty($this->module['array']) ? '/'.implode('/', $this->module['array']).'/' : '/';

		$jsMinified = $this->config['Script']['path']['javascript'].$module.$this->config['Web']['javascript']['fileMinified'];
		$js = $this->config['Script']['path']['javascript'].$module.$this->config['Web']['javascript']['file'];

		if (file_exists ($jsMinified))
		{
			$javascript = "\n\t"
				. sprintf (
					$this->config['Web']['javascript']['definiton'],
					$this->config['Web']['javascript']['path'].$module .$this->config['Web']['javascript']['fileMinified']
				)
				. $javascript;
		}
		else if (file_exists ($js))
		{
			$javascript = "\n\t"
				. sprintf (
					$this->config['Web']['javascript']['definiton'],
					$this->config['Web']['javascript']['path'].$module .$this->config['Web']['javascript']['file']
				)
				. $javascript;
		}
	}

	// vymaž soubory v cache
	// $aOnly - pole souborů, které se mají smazat, ostatní ponechat; default smaž všechny
	function deleteCache ()
	{
		$oDir = dir($this->config['Script']['path']['cache']);
		while($sFile = $oDir->read())
		{
			if (is_file($this->config['Script']['path']['cache'].'/'.$sFile))
			{
				unlink ($this->config['Script']['path']['cache'].'/'.$sFile);
			}
		}
		$oDir->close();
	}


	// podporované jazyky
	function language()
	{
	}

	// uvozovky " => &quot (kvůli INPUT type="text")
	function quotes (&$aRow)
	{
		if (is_array($aRow))
		{
			foreach ($aRow as $sKey => $sValue)
			{
				$aRow[$sKey] = str_replace('"', '&quot;', $aRow[$sKey]);
			}
		}
		else
		{
			$aRow = str_replace('"', '&quot;', $aRow);
		}
	}
	
	// nadefinuj proměnné ze skriptu pro parsovaní v šablonách
	// $aVars - pole proměnných
	function assignVars ($aVars, $bNull = false)
	{
		if (!empty($aVars))
		{
			if ($bNull)
			{
				$this->_null_Vars($aVars);
			}
			
			$this->params = array_merge($this->params, $aVars);
		}
	}
	
	// NULL hodnoty nahraď prázdnou (převod z SQL)
	// $aVars - pole proměnných
	function nullVars (&$aVars)
	{
		if (is_array($aVars) && !empty($aVars))
		{
			foreach ($aVars as $sKey => $sValue)
			{
				if (is_null($sValue))
				{
					$aVars[$sKey] = '';
				}
			}
		}
		else if (is_null($aVars))
		{
			$aVars = '';
		}
	}
	
	// SQL funkce
	// $sAction - akce, která s emá provést
	// $aParams - parametry pro SQL dotazy
	function SQL ($sAction, $aParams = array())
	{
		switch ($sAction)
		{
			// ulož aktivitu uživatele - specifické pro každý web (admin)
			case 'save_updates':
			{
				$this->sSQL = "INSERT INTO 
									`admin_uzivatel-historie` (`cas`, `uzivatel_id`, `polozka_id`, `radio_id`, `prava_id`, `typ`)
								VALUES 
									(CURRENT_TIMESTAMP, {$_SESSION['auth']['uzivatel_id']}, '{$aParams['id']}', '{$aParams['radio_id']}', '{$aParams['prava_id']}', '{$aParams['action']}')";
				//mysql_query($this->sSQL, $this->rSQL);
				if (!mysql_query($this->sSQL, $this->rSQL))
				{
					$this->_SQL('error', array(__FILE__, __LINE__));
				}
			}
			break;
		}
	}
	
	// změna velikosti
	function imageResize ($sPrefix = '.', $sSource, $iWidth = 0, $iHeight = 0, $iQuality = 90, $sDestination = '/nahledy', $sResizeType = 'crop')
	{
		@set_time_limit(600);
		
		$iWidth = !$iWidth ? $this->config['Image']['preview']['width'] : $iWidth;
		$iHeight = !$iHeight ? $this->config['Image']['preview']['height'] : $iHeight;
		$sDir = dirname($sSource);
		$sFile = basename($sSource);

		// vytvoření adresáře pro zmenšený obrázek, pokud neexistuje
		if (!file_exists($sPrefix.$sDir.$sDestination))
		{
			@mkdir($sPrefix.$sDir.$sDestination, 0777);
			//@chmod($sPrefix.$sDir.$sDestination, 0777);
		}
		
		if (file_exists($sPrefix.$sSource))
		{
			require_once($sPrefix.'/include/resize.class.php');
			
			$oResize = new resize($sPrefix.$sSource);
			$oResize->resizeImage($iWidth, $iHeight, $sResizeType);	// exact, portrait, landscape, auto, crop
			$oResize->saveImage($sPrefix.$sDir.$sDestination.'/'.$sFile, $iQuality);
			
			@chmod($sPrefix.$sDir.$sDestination.'/'.$sFile, 0777);
		}

	}
	
	// práva
	function checkRights ($iTyp = 0, $bUrad = false)
	{
		$bRights = false;

		if (is_array($iTyp))
		{
			foreach ($iTyp as $sTypUzivatele)
			{
				// dodatečná práva k modulu
				if (is_string($sTypUzivatele) && isset($_SESSION['auth']['typ_uzivatele']) && $sTypUzivatele == $_SESSION['auth']['typ_uzivatele'])
				{
					$bRights = true;
				}
				// má nějaká práva k modulu?
				else if (is_int($sTypUzivatele) && isset($_SESSION['auth']['prava'][$this->moduleID]))
				{
					$aPravaModul = explode(",", $_SESSION['auth']['prava'][$this->moduleID]);
					// má tento typ práv k modulu
					if (in_array($sTypUzivatele, $aPravaModul))
					{
						$bRights = true;
					}
				}
			}
		}
		else
		{
			// má nějaká práva k modulu?
			if (isset($_SESSION['auth']['prava'][$this->moduleID]))
			{
				$aPravaModul = explode(",", $_SESSION['auth']['prava'][$this->moduleID]);
				// má tento typ práv k modulu
				if (in_array($iTyp, $aPravaModul))
				{
					$bRights = true;
				}
			}
		}
	
		return $bRights;
	}
}