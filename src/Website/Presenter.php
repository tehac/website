<?php
/*-- třída pro zobrazení obsahu webu --*/
/*-- © Tomáš Haluza, www.haluza.cz   --*/
/*-- 28.06.2016                      --*/

namespace Website;

use Exception;
use \Tracy\Debugger as Debugger;

class Presenter extends Exception
{
	public $bDebug;				// debug - zobraz prázdné hodnoty pro template
	
	public $iModuleID;			// ID zpracováváného modulu

	public $sEncoding;			// compress ?
	public $sModulePath;		// cesta k modulu
	public $sTemplatePath;		// cesta k šabloně
	public $sCachePath;			// cesta ke cache
	public $sHTML;				// HTML výstup
	public $sLang;				// id jazyka, výchozí = 'cs'

	public $config;				// konfigurační parametry
	public $aModuleRights;		// dodatečná práva k modulu
	public $aModule;			// vykonávaný modul webu
	public $aVars;				// proměnné pro parsování do šablon
	public $aScript;			// hodnoty prováděné stránky, modulu
	public $aHTML;				// HTML výstup v poli
	public $aLang;				// jazyky pro mutace webu

	public $latte;				// šablonovací systém

	/**
	 * Constructor
	 */
	function __construct()
	{
		global $config, $latte;

		$this->bDebug = $_SERVER['REMOTE_ADDR'] == "127.0.0.1" || $_SERVER['SERVER_ADDR'] == "192.168.1.99" ? true : false;

		$this->config = $config;
		$this->aModuleRights = array ();
		$this->aModule = array (
			'path' 		=> $config['Script']['path']['module'].'/home.php',	// cesta k zobrazované stránce, výchozí hodnota home page
			'_GET' 		=> array(),												// předané parametry
			'textID'	=> NULL													// textový ID stránky
		);												
		$this->aScript = array();
		$this->aHTML = NULL;
		$this->aVars = array(
			'TITLE' 	=> $config['Web']['title'],		// titulek stránky - výchozí hodnota
			'VERZE' 	=> $config['Admin']['version'],	// version
			'GENERATED'	=> 0,
		);
		$this->aLang = array();

		$this->sModulePath = $config['Script']['path']['module'];
		$this->sTemplatePath = $config['Script']['path']['template'];
		$this->sCachePath = $config['Script']['path']['cache'];
		$this->sHTML = '';
		$this->sLang = 'cs';

		// Latte
		$this->latte = $latte;

		// vymaž moduly uložené v cache
		if (isset($_GET['nocache']))
		{
			$this->deleteCache();
		}

		// čas začátku provádění skriptu
		$this->aScript['time']['start'] = microtime();
	}

	function tmp ()
	{
		return $this->sLang;
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
		if (file_exists($this->aModule['path']))
		{
			include $this->aModule['path'];
		}
		else
		{
			log('neexistujici nastaveni modulu: '.$this->aModule['path']);
			throw new Exception('neexistujici nastaveni modulu: '.$this->aModule['path']);
		}
	}

	// vytvoř HTML výstup, pošli na výstup
	function writeOutput ($sHeader = '')
	{
		// zjisti podporované kódování
		$this->findEncoding();

		// doba ukončení skriptu - pro testování doby běhu
		list($iSecStart, $iMsesStart) = explode (" ", $this->aScript['time']['start']);
		$this->aScript['time']['end'] = microtime ();
		list($iSecEnd, $iMsecEnd) = explode (" ", $this->aScript['time']['end']);
		$this->aScript['time']['created'] = ($iSecEnd + $iMsecEnd) - ($iSecStart + $iMsesStart);

		// přepiš titulek stránky a ostatní neparsované podle aktuální hodnoty
		$this->sHTML = !empty($this->aHTML) ? implode ("", $this->aHTML) : '';
		foreach ($this->aVars as $var => $value)
		{
			$this->sHTML = str_replace (
				"{ _".$var."_ }",
				($var == "GENERATED" ? $this->aScript['time']['created'].' seconds' : $value),
				$this->sHTML
			);
		}


		// pošli header
		if (!empty($sHeader) && !headers_sent ())
		{
			header ($sHeader);
		}

		// pokud existuje komprese prohlížeče a nejsou poslané header, komprimuj výsledné HTML
		if (!is_null ($this->sEncoding) && !headers_sent () && (ob_get_contents () === false || ob_get_contents () === ""))
		{
			header ("Content-Encoding: {$this->sEncoding}");
			echo "\x1f\x8b\x08\x00\x00\x00\x00\x00";
			$sGZIP = gzcompress ($this->sHTML, 7);
			$sGZIP = substr ($sGZIP, 0, strlen ($sGZIP) - 4);

			echo $sGZIP;
			echo pack ('V', crc32 ($this->sHTML));
			echo pack ('V', strlen ($this->sHTML));
		}
		else
		{
			echo $this->sHTML;
		}

		// smaž hodnoty, které by neměly být vidět
		unset($this->aHTML, $this->sHTML, $this->aScript['script'], $this->aScript['cache'], $this->config['SQL']);

		// ze které stránky se přistupovalo - pro tlačítko zpět na další stránce
		if (isset($this->aModule['array']) && !empty($this->aModule['array']))
		{
			$_SESSION['script']['referer'] = '/' . join ("/", $this->aModule['array']) . '/';
		}
		else
		{
			$_SESSION['script']['referer'] = '/';
		}
	}

	// zjisti právě zobrazovaný modul z předané hodnoty mod_rewrite
	function findModule()
	{
		if (isset($_GET['path2module']) && !empty($_GET['path2module']))
		{
			$aTmp = preg_split("/\//", $_GET['path2module']);
			$sExtension = array_pop($aTmp);

			// najdi ID, pokud existuje přípona .html
			if (preg_match("/^([\w\W\-]+)\.html$/", $sExtension, $aID))
			{
				$this->aModule['textID'] = $aID[1];

				// najdi parametry - ID, strana
				if (preg_match("/^([\w\-]+)\-strana\-(\d+)\.html$/", $sExtension, $aID))
				{
					// id
					if (preg_match("/^([\w\-]+)\-(\d+)$/", $aID[1], $aIDsub))
					{
						$this->aModule['textID'] = $aIDsub[1];
						$this->aModule['_GET']['id'] = intval($aIDsub[2]);
					}
					else
					{
						$this->aModule['textID'] = $aID[1];
					}
					$this->aModule['_GET']['strana'] = intval($aID[2]);
				}
				// najdi parametry - strana
				else if (preg_match("/^strana\-(\d+)\.html$/", $sExtension, $aID))
				{
					$this->sTextID = '';
					$this->aModule['_GET']['strana'] = intval($aID[1]);
				}
				// najdi parametry - textID, ID
				else if (preg_match("/^([\w\-]+)\-(\d+)\.html$/", $sExtension, $aID))
				{
					$this->aModule['textID'] = $aID[1];
					$this->aModule['_GET']['id'] = intval($aID[2]);
				}
			}
			// najdi ID, pokud existuje přípona .xml
			else if (preg_match("/^([\w\W\-]+)\.xml$/", $sExtension, $aID))
			{
				// najdi parametry - textID, ID
				if (preg_match("/^([\w\-]+)\-(\d+)\.xml$/", $sExtension, $aID))
				{
					$this->aModule['textID'] = $aID[1];
					$this->aModule['_GET']['id'] = intval($aID[2]);
				}
			}

			$this->createPath($aTmp);

			// nezobraz konkrétní modul, začínající '_', vždy celou stránku!
			if ($aTmp[count($aTmp)-1][0] != "_")
			{
				$this->aModule['path'] = $this->sModulePath.'/'.join("/", $aTmp).'.php';
				$this->aModule['array'] = $aTmp;
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
				$this->sEncoding = 'x-gzip';
			}
			else if (strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false)
			{
				$this->sEncoding = 'gzip';
			}
		}
	}
	
	// vytvoření cesty k stránce, modulu, cache
	// $aThisPath  - cesta k modulu webu
	function createPath ($path)
	{
		$this->aScript['script']['array'] = $path;
		$this->aScript['script']['path'] = strtolower($this->sModulePath.'/'.join("/", $path).'.php');
		$this->aScript['script']['time'] = microtime();
		$this->aScript['template']['path'] = strtolower($this->sTemplatePath.'/'.join("/", $path).'.tpl');
	}
	
	// vytvoření modulu
	// $aPath - cesta k modulu
	// $aCFG - parametry pro vykonávaný modul (platnost, uložení do cache)
	function createModule ($path, $params = array())
	{
		$this->createPath($path);

		// vykonej modul
		$this->includeModule($this->aScript, $params);
	}
	
	// načti a vykonej PHP, zapiš HTML cache
	// $aModule - parametry vykonávaného modulu
	// $bSave - uložení modulu (např. autentifikační modul se neukládá)
	function includeModule ($module, $moduleParams)
	{
		// klíč modulu (cesta)
		$sKey = implode("%", $this->aScript['script']['array']);

		// načti a vykonej PHP modul
		$this->aScript['time']['script'][$sKey] = microtime();
		if (file_exists($module['script']['path']))
		{
			include $module['script']['path'];
		}
		else
		{
			log('neexistujici modul: '. $module['script']['path']);
			throw new Exception('neexistujici modul: '. $module['script']['path']);
		}
		
		// čas vykonání
		if (isset($this->aScript['time']['script'][$sKey]))
		{
			list($iSecStart, $iMsesStart) = explode(" ", $this->aScript['time']['script'][$sKey]);
			list($iSecEnd, $iMsecEnd) = explode(" ", microtime());
			$this->aScript['time']['script'][$sKey] = ($iSecEnd + $iMsecEnd) - ($iSecStart + $iMsesStart);
		}

		$this->aHTML[$sKey] = $this->sHTML;
	}

	// vytvoření modulu - neukládá se do cache, vrátí obsah
	// $aPath - cesta k modulu
	function parseModule ($aPath, $aParams = array())
	{
		$this->sHTML = '';
		$module = $this->sModulePath.'/'.implode("/", $aPath).'.php';

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

		return $this->sHTML;
	}

	// vytvoření HTML ze šablony - spouští se pro každný modul
	// $sTemplatePath - cesta k šabloně
	function createTemplate ($params, $templatePath = '')
	{
		// načti šablonu, výchozí cesta je jako spouštěný modul
		// pokud není prázdná hodnota, načti šablonu z hodnoty
		$templatePath = $templatePath == "" ? $this->aScript['template']['path'] : $this->sTemplatePath.$templatePath;

		$this->sHTML = $this->latte->renderToString($templatePath, (array) $params);
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
			
			$this->aVars = array_merge($this->aVars, $aVars);
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
				else if (is_int($sTypUzivatele) && isset($_SESSION['auth']['prava'][$this->iModuleID]))
				{
					$aPravaModul = explode(",", $_SESSION['auth']['prava'][$this->iModuleID]);
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
			if (isset($_SESSION['auth']['prava'][$this->iModuleID]))
			{
				$aPravaModul = explode(",", $_SESSION['auth']['prava'][$this->iModuleID]);
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
?>