<?php
/*-- třída pro zobrazení obsahu webu --*/
/*-- © Tomáš Haluza, www.haluza.cz   --*/
/*-- 28.06.2016                      --*/

class Website
{
	public $bDebug;				// debug - zobraz prázdné hodnoty pro template
	
	public $iModuleID;			// ID zpracováváného modulu
	public $iCacheLifeTime;		// doba platnosti cache

	public $sEncoding;			// compress ?
	public $sModulePath;		// cesta k modulu
	public $sTemplatePath;		// cesta k šabloně
	public $sCachePath;			// cesta ke cache
	public $sHTML;				// HTML výstup
	public $sSQL;				// SQL dotaz
	public $sLang;				// id jazyka, výchozí = 'cs'

	public $rCache;				// deskriptor souboru při čtení z cache
	
	public $aModuleRights;		// dodatečná práva k modulu
	public $aModule;			// vykonávaný modul webu
	public $aVars;				// proměnné pro parsování do šablon
	public $aVarsNotParsed;		// proměnné, které parsovat až na konci
	public $aConfig;				// konfigurační hodnoty
	public $aScript;			// hodnoty prováděné stránky, modulu
	public $aHTML;				// HTML výstup v poli
	public $aLang;				// jazyky pro mutace webu

	public $latte;				// šablonovací systém

	/**
	 * _display_Website constructor.
	 */
	function __construct()
	{
		global $config, $latte;

		$this->bDebug = $_SERVER['REMOTE_ADDR'] == "127.0.0.1" || $_SERVER['SERVER_ADDR'] == "192.168.1.99" ? true : false;

		$this->aModuleRights = array ();
		$this->aModule = array (
			'path' 		=> $config['Script']['path']['module'].'/home.php',	// cesta k zobrazované stránce, výchozí hodnota home page
			'_GET' 		=> array(),												// předané parametry
			'textID'	=> NULL													// textový ID stránky
		);												
		$this->aScript = array();
		$this->aConfig = $config;
		$this->aHTML = NULL;
		$this->aVarsNotParsed = array(
			'TITLE', 
			'GENERATED', 
			'FACEBOOK_LIKE_URL', 
			'FACEBOOK_LIKE_URL_ENCODED', 
			'FACEBOOK_LIKE_IMAGE', 
			'FACEBOOK_LIKE_TITLE', 
			'FACEBOOK_LIKE_DESC', 
			'FACEBOOK_LIKE_SITE',
		);
		foreach ($this->aVarsNotParsed as $sVar)
		{
			$this->aVars[$sVar] = '';
		}
		$this->aVars = array(
			'TITLE' 			=> $config['Web']['title'],		// titulek stránky - výchozí hodnota
			'VERZE' 			=> $config['Admin']['version'],	// version
			'ADMIN_URL'			=> '',							// admin URL (for / leave blank, othervise /admin)
		);
		$this->aLang = array();

		$this->iCacheLifeTime = $config['Script']['cache']['lifeTime'];

		$this->sModulePath = $config['Script']['path']['module'];
		$this->sTemplatePath = $config['Script']['path']['template'];
		$this->sCachePath = $config['Script']['path']['cache'];
		$this->sHTML = '';
		$this->sSQL = NULL;
		$this->sLang = 'cs';

		$this->rCache = NULL;

		// Latte
		$this->latte = new \Latte\Engine;
		$this->latte->setTempDirectory($config['Script']['path']['cache']);

		// čas začátku provádění skriptu
		$this->aScript['time']['start'] = microtime();
		
		// jazkové mutace
		//$this->_language();

		// najdi cestu k zobrazované stránce
		$this->_find_This_Module();

		// zjisti podporované kódování
		$this->_find_Encoding();

		// vymaž moduly uložené v cache
		if (isset($_GET['nocache']))
		{
			$this->_delete_Cache();
		}
	}

	function __destruct()
	{
		// ukonči spojení s MySQL
		$this->_SQL('close');
	}
	
	// podporované jazyky, načítáno z SQL - může být specifické pro každý web
	function _language()
	{
	}

	// zjisti možnost komprese výstupu
	function _find_Encoding ()
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
	
	// zjisti právě zobrazovaný modul z předané hodnoty mod_rewrite
	function _find_This_Module()
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
				// najdi parametry - ukázka a záporní ID
				else if (preg_match("/^ukazka_\-(\d+)\.html$/", $sExtension, $aID))
				{
					$this->sTextID = $aID[0];
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
				$this->aModule['textID'] = $aID[1];
				$this->aModule['_GET']['xml'] = 'podcast';
				
				// najdi parametry - textID, ID
				if (preg_match("/^([\w\-]+)\-(\d+)\.xml$/", $sExtension, $aID))
				{
					$this->aModule['textID'] = $aID[1];
					$this->aModule['_GET']['id'] = intval($aID[2]);
				}
			}
			
			//print_r($this->aModule);

			$this->_create_Path($aTmp);
			$this->_check_Timestamp();
			
			// pokud existuje PHP modul (čas je větší než 0), ulož do pole jeho cestu
			if ($this->aScript['script']['timeStamp'])
			{
				// nezobraz konkrétní modul, začínající '_', vždy celou stránku!
				if ($aTmp[count($aTmp)-1][0] != "_")
				{
					$this->aModule['path'] = $this->sModulePath.'/'.join("/", $aTmp).'.php';
					$this->aModule['array'] = $aTmp;
				}
			}
		}
	}
	
	// vytvoření cesty k stránce, modulu, cache
	// $aThisPath  - cesta k modulu webu
	function _create_Path ($aThisPath)
	{
		$this->aScript['script']['array'] = $aThisPath;
		$this->aScript['script']['path'] = strtolower($this->sModulePath.'/'.join("/", $aThisPath).'.php');
		$this->aScript['script']['time'] = microtime();
		$this->aScript['template']['path'] = strtolower($this->sTemplatePath.'/'.join("/", $aThisPath).'.tpl');
		$this->aScript['cache']['path'] = strtolower($this->sCachePath.'/'.join("%", $aThisPath).'.cache');
	}
	
	// zjištění času vytvoření souborů
	function _check_Timestamp ()
	{
		// výchozí stav 0 -> neexistuje
		$this->aScript['script']['timeStamp'] = 0;
		$this->aScript['template']['timeStamp'] = 0;
		$this->aScript['cache']['timeStamp'] = 0;

		if (strpos($this->aScript['script']['path'], ".php.php") === false)
		{
			// čas vytvoření PHP modulu;
			if (file_exists($this->aScript['script']['path']))
			{
				$this->aScript['script']['timeStamp'] = filemtime($this->aScript['script']['path']);
			}
	
			// čas vytvoření šablony
			if (file_exists($this->aScript['template']['path']))
			{
				$this->aScript['template']['timeStamp'] = filemtime($this->aScript['template']['path']);
			}
	
			// čas vytvoření HTML cache
			if (file_exists($this->aScript['cache']['path']))
			{
				$this->aScript['cache']['timeStamp'] = filemtime($this->aScript['cache']['path']);
			}
		}
	}
	
	// vytvoření modulu
	// $aPath - cesta k modulu
	// $aCFG - parametry pro vykonávaný modul (platnost, uložení do cache)
	function _create_Module ($aPath, $aCFG = array())
	{
		$this->_create_Path($aPath);
		$this->_check_Timestamp();
		
		// doba platnosti cache, pokud není definována vezmi default
		if (isset($aCFG['lifetime']))
		{
			$this->aScript['cache']['lifeTime'] = intval($aCFG['lifetime']);
		}
		else
		{
			$this->aScript['cache']['lifeTime'] = $this->iCacheLifeTime;
		}
		
		if (
				$this->aScript['cache']['timeStamp'] > 0 && 												// existuje cache
				$this->aScript['cache']['timeStamp'] > $this->aScript['script']['timeStamp'] && 			// cache je novější než modul
				$this->aScript['cache']['timeStamp'] > $this->aScript['template']['timeStamp']  &&			// cache je novější než tamplate
				$this->aScript['cache']['timeStamp'] > (time() - $this->aScript['cache']['lifeTime']) 		// cache je platná
			)
		{
			// načti modul z cache
			$this->_cached_Module($this->aScript);
			$this->aScript['cached'][] = file_exists($this->aScript['script']['path']) ? $this->aScript['script']['path'] : $this->aScript['template']['path'];
		}
		else
		{
			// vykonej modul
			$this->_include_Module($this->aScript, $aCFG);
			$this->aScript['included'][] = $this->aScript['script']['path'];
		}
	}
	
	// vytvoření modulu - neukládá se do cache, vrátí obsah
	// $aPath - cesta k modulu
	function _parse_Module ($aPath, $aParams = array())
	{
		$this->sHTML = '';

		if (file_exists($this->sModulePath.'/'.join("/", $aPath).'.php'))
		{
			// načti modul
			include ($this->sModulePath.'/'.join("/", $aPath).'.php');
			// vytvoř HTML z template
			//$this->_create_Template('/'.join("/", $aPath).'.tpl');
		}

		return $this->sHTML;
	}
	
	// načti a vykonej PHP, zapiš HTML cache
	// $aModule - parametry vykonávaného modulu
	// $bSave - uložení modulu (např. autentifikační modul se neukládá)
	function _include_Module ($aModule, $aCFG)
	{
		// klíč modulu (cesta)
		$sKey = implode("%", $this->aScript['script']['array']);

		// načti a vykonej PHP modul
		$this->aScript['time']['script'][$sKey] = microtime();
		if (file_exists($aModule['script']['path']))
		{
			include ($aModule['script']['path']);
		}
		else
		{
			echo "<pre style=\"font-size:8pt;\">neexistujici modul: <strong>".$aModule['script']['path']."</strong></pre>";
		}
		
		// čas vykonání
		if (isset($this->aScript['time']['script'][$sKey]))
		{
			list($iSecStart, $iMsesStart) = explode(" ", $this->aScript['time']['script'][$sKey]);
			list($iSecEnd, $iMsecEnd) = explode(" ", microtime());
			$this->aScript['time']['script'][$sKey] = ($iSecEnd + $iMsecEnd) - ($iSecStart + $iMsesStart);
		}

		$this->aHTML[$sKey] = $this->sHTML;
		/*
		 * XXX
		 *
		// pokud je povoleno ukládání, přiřaď HTML kód a ulož do cache
		if (!isset($aCFG['save']) || (isset($aCFG['save']) && $aCFG['save']))
		{
			$this->aHTML[$sKey] = $this->sHTML;
			if ($this->rCache = fopen($aModule['cache']['path'], 'wb'))
			{
				flock($this->rCache, LOCK_EX);
				fwrite($this->rCache, $this->aHTML[$sKey]);
				flock($this->rCache, LOCK_UN);
				fclose($this->rCache);
				@chmod($aModule['cache']['path'], 0777);
			}
		}
		*/
	}
	
	// načti HMTL z cache
	function _cached_Module ($aModule)
	{
		$sKey = join("%", $this->aScript['script']['array']);
		$this->aHTML[$sKey] = file_get_contents($aModule['cache']['path']);
	}
	
	// načti parsovaný HMTL blok z cache
	function _load_Parsed ($aModule)
	{
		$sKey = join("%", $aModule);
		return file_get_contents($this->sCachePath.'/'.$sKey.'.cache');
	}
	
	// vytvoř HTML výstup, pošli na výstup
	function _write_Output ($sHeader = '')
	{
		// ukonči spojení s MySQL
		$this->_SQL('close');


		// doba ukončení skriptu - pro testování doby běhu
		list($iSecStart, $iMsesStart) = explode(" ", $this->aScript['time']['start']);
		$this->aScript['time']['end'] = microtime();
		list($iSecEnd, $iMsecEnd) = explode(" ", $this->aScript['time']['end']);
		$this->aScript['time']['created'] = ($iSecEnd + $iMsecEnd) - ($iSecStart + $iMsesStart);

		// přepiš titulek stránky a ostatní neparsované podle aktuální hodnoty
		$this->sHTML = !empty($this->aHTML) ? implode("", $this->aHTML) : '';
		foreach ($this->aVarsNotParsed as $sVar)
		{
			$this->sHTML = str_replace("{".$sVar."}", ($sVar == "GENERATED" ? $this->aScript['time']['created'].' seconds' : (isset($this->aVars[$sVar]) ? $this->aVars[$sVar] : '')), $this->sHTML);
		}


		// pošli header
		if (!empty($sHeader) && !headers_sent())
		{
			header($sHeader);
		}

		// pokud existuje komprese prohlížeče a nejsou poslané header, komprimuj výsledné HTML
		if (!is_null($this->sEncoding) && !headers_sent() && (ob_get_contents() === false || ob_get_contents() === ""))
		{
			header ("Content-Encoding: {$this->sEncoding}");
			echo "\x1f\x8b\x08\x00\x00\x00\x00\x00";
			$sGZIP = gzcompress($this->sHTML, 7);
			$sGZIP = substr($sGZIP, 0, strlen($sGZIP)-4);

			echo $sGZIP;
			echo pack('V', crc32($this->sHTML));
			echo pack('V',  strlen($this->sHTML));
		}
		else
		{
			echo $this->sHTML;
		}

		// smaž hodnoty, které by neměly být vidět
		unset($this->aHTML, $this->sHTML, $this->aScript['script'], $this->aScript['cache'], $this->aConfig['SQL']);
		
		// ze které stránky se přistupovalo - pro tlačítko zpět na další stránce
		if (isset($this->aModule['array']) && !empty($this->aModule['array']))
		{
			$_SESSION['script']['referer'] = '/'.join("/", $this->aModule['array']).'/';
		}
		else
		{
			$_SESSION['script']['referer'] = '/';
		}

		// ukončení spojení do SQL
		if (dibi::isConnected ())
		{
			dibi::disconnect();
			$this->sSQL = NULL;
		}

	}
	
	// uvozovky " => &quot (kvůli INPUT type="text")
	function _quotes (&$aRow)
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
	function _assign_Vars ($aVars, $bNull = false)
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
	function _null_Vars (&$aVars)
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
	
	// vytvoření HTML ze šablony - spouští se pro každný modul
	// $sTemplatePath - cesta k šabloně
	function _create_Template ($sTemplatePath = '')
	{
		// načti šablonu, výchozí cesta je jako spouštěný modul
		// pokud není prázdná hodnota, načti šablonu z hodnoty
		$sTemplatePath = $sTemplatePath == "" ? $this->aScript['template']['path'] : $this->sTemplatePath.$sTemplatePath;

		$this->sHTML = $this->latte->renderToString($sTemplatePath, $this->aVars);
		/*
		 * XXX
		 *
		if (file_exists($sTemplatePath))
		{
			$this->sHTML = implode("", file($sTemplatePath));

			// přepiš proměnné/cyklus, pokud existují
			if (strpos($this->sHTML, "{") !== false)
			{
				$this->sHTML = $this->_compile_Vars(0, $this->sHTML, $this->aVars);
			}
		}
		else
		{
			$this->sHTML = file_get_contents($this->sTemplatePath.'/_prazdna.tpl');
		}
		*/
	}
	
	// vytvoření HTML bloku ze šablony
	function _create_TemplateBlock ()
	{
		// načti šablonu, výchozí cesta je jako spouštěný modul
		// pokud není prázdná hodnota, načti šablonu z hodnoty
		$sTemplatePath = $this->aScript['template']['path'];
		$this->sHTMLblock = implode("", file($sTemplatePath));
		echo $this->sHTMLblock;

		// přepiš proměnné/cyklus, pokud existují
		if (strpos($this->sHTMLblock, "{") !== false)
		{
			$this->sHTMLblock = $this->_compile_Vars(0, $this->sHTMLblock, $this->aVars);
		}
	}
	
	// parsování proměnných v šabloně
	// $i - počítadlo cyklu; pokud se spouští poprvé, přepíše všechny 'statické' hodnoty
	// $sHTML - HTML kód šablony, nebo sub cyklu
	// $aVarsParent - proměnné, které se budou parsovat
	function _compile_Vars ($i, $sHTML, $aVarsParent)
	{
		// nahraď všechny {PROMĚNNÉ} hodnotami, kromě {TITLE} - pouze v prvním cyklu
		if (!$i && preg_match_all("/\{([^\.\} \\r\\n]+)\}/is", $sHTML, $aMatches))
		{
			$aMatches = array_diff(array_unique($aMatches[1]), $this->aVarsNotParsed);

			foreach ($aMatches as $sVariable)
			{
				if (isset($aVarsParent[$sVariable]))
				{
					$sHTML = str_replace("{".$sVariable."}", $aVarsParent[$sVariable], $sHTML);
				}
				else
				{
					$sHTML = str_replace("{".$sVariable."}", ($this->bDebug ? " {".$sVariable."} " : ""), $sHTML);
				}
			}
		}

		// nalezení cyklu - pouze malá písmena . - _!!!
		if (preg_match_all("/(\<\!\-\- BEGIN ([a-z0-9\.\-_]+) \-\-\>)(.*)(\<\!\-\- END ([a-z0-9\.\-_]+) \-\-\>)/s", $sHTML, $aMatches, PREG_SET_ORDER))
		{
			// pokud je více cyklů za sebou, vyber ten první
			if ($aMatches[0][2] != $aMatches[0][5])
			{
				$iPosEnd = strpos($aMatches[0][3], "<!-- END {$aMatches[0][2]} -->");
				$aMatches[0][3] = substr($aMatches[0][3], 0, $iPosEnd);
			}
			
			$sSection = $aMatches[0][2];
			$sThisHTML = $sThisHTMLoriginal = rtrim($aMatches[0][3]);
			$aHTML = array();

			// pokud existují proměnné, parsuj je
			if (isset($aVarsParent[$sSection]) && !empty($aVarsParent[$sSection]))
			{
				foreach ($aVarsParent[$sSection] as $iKey => $aVars)
				{
					$sThisHTMLsub = '';
					
					// pokud existuje subcyklus v tomto cyklu, parsuj ho
					// !!! pozor, nefunguje pro více 'subcyklů' v cyklu :-(
					/// kvůli odstranění tagů se začátky/konci cyklů
					if (preg_match_all("/(\<\!\-\- BEGIN ([a-z0-9\.\-_]+) \-\-\>)(.*)(\<\!\-\- ([a-z0-9\.\-_]+) \-\-\>)/is", $sThisHTML, $aMatches, PREG_SET_ORDER))
					{
						// !!! pokud nejsou proměnné definovány, zpomaluje
						if (isset($aVars[$aMatches[0][2]]))
						{
							$aThisVars[$aMatches[0][2]] = $aVars[$aMatches[0][2]];
							$sThisHTMLsub = ltrim($this->_compile_Vars(++$i, $aMatches[0][0], $aThisVars));
						}
					}
	
					$sHTMLsection = $sThisHTML;
					foreach ($aVars as $sKey => $sValue)
					{
						if (!is_array($sValue))
						{
							$sHTMLsection = str_replace("{".$sSection.".".$sKey."}", $sValue, $sHTMLsection);
						}
					}
					$aHTML[] = $sThisHTMLsub == "" ? $sHTMLsection : str_replace($aMatches[0][0], $sThisHTMLsub, $sHTMLsection);
				}
			}
			$sHTMLsectionParsed = join("", $aHTML);
			$sHTML = str_replace($sThisHTMLoriginal, $sHTMLsectionParsed, $sHTML);
			
			// vymaž řádky s začátky/konci cyklů
			$sHTML = $this->_erase_Cycle_Def($sHTML, $sSection);

			// jsou ještě další cykly? pokud ano, parsu dále
			if (strpos($sHTML, "<!-- BEGIN") !== false)
			{
				$sHTML = $this->_compile_Vars($i+1, $sHTML, $this->aVars);
			}
		}
		
		return $sHTML;
	}
	
	// vymaž začátek a konec definice cyklu
	// $sHTML - HTML kód, ze kterého se odstraňují začátky a konce cyklu
	// $sCycleID - ID cyklu, který se má odstranit
	function _erase_Cycle_Def ($sHTML, $sCycleID)
	{
		$aHTMLerased = array();
		
		$aHTML = explode("\n", $sHTML);
		if (!empty($aHTML))
		{
			foreach ($aHTML as $sLine)
			{
				if (strpos($sLine, "<!-- BEGIN $sCycleID -->") === false && strpos($sLine, "<!-- END $sCycleID -->") === false)
				{
					$aHTMLerased[] = rtrim($sLine);
				}
			}
		}

		return !empty($aHTMLerased) ? join("\n", $aHTMLerased) : $sHTML;
	}
	
	// vymaž soubory v cache
	// $aOnly - pole souborů, které se mají smazat, ostatní ponechat; default smaž všechny
	function _delete_Cache ($aOnly = array())
	{
		$oDir = dir($this->sCachePath);
		while($sFile = $oDir->read())
		{
			if (is_file($this->sCachePath.'/'.$sFile))
			{
				if (!empty($aOnly) && strpos($sFile, join("%", $aOnly)) !== false)
				{
					unlink ($this->sCachePath.'/'.$sFile);
				}
				else if (empty($aOnly) && strpos($sFile, ".cache") !== false)
				{
					unlink ($this->sCachePath.'/'.$sFile);
				}
			}
		}
		$oDir->close();
	}
	
	// SQL funkce
	// $sAction - akce, která s emá provést
	// $aParams - parametry pro SQL dotazy
	function _SQL ($sAction, $aParams = array())
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
	function _image_Resize ($sPrefix = '.', $sSource, $iWidth = 0, $iHeight = 0, $iQuality = 90, $sDestination = '/nahledy', $sResizeType = 'crop')
	{
		@set_time_limit(600);
		
		$iWidth = !$iWidth ? $this->aConfig['Image']['preview']['width'] : $iWidth;
		$iHeight = !$iHeight ? $this->aConfig['Image']['preview']['height'] : $iHeight;
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
	function _check_rights ($iTyp = 0, $bUrad = false)
	{
		$bRights = false;

		// práva v administraci úřadu		
		if ($bUrad)
		{
			if ($this->iModuleID == NULL)
			{
				$bRights = true;
			}
			else if
			(
				isset($_SESSION['urad']['prava'][$this->iModuleID]) &&																		// má nějaká práva k modulu?
				isset($_SESSION['urad']['prava'][$this->iModuleID][$iTyp]) && $_SESSION['urad']['prava'][$this->iModuleID][$iTyp] == true	// má tento typ práv?
			)
			{
				$bRights = true;
			}
		}
		else
		{
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
		}
	
		return $bRights;
	}
	
	// logování dat
	function _log ($aData, $sLog = __DIR__.'/../var/log/log.txt', $sMode = 'a')
	{
		$rFP = fopen($sLog, $sMode);
		fwrite($rFP, print_r($aData, true)."\n\n");
		fclose($rFP);
		
		chmod($sLog, 0777);
	}
}
?>