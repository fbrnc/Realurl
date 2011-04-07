<?php
/***************************************************************
 * Copyright notice
 *
 * (c) 2008 AOE media
 * All rights reserved
 *
 * This script is part of the Typo3 project. The Typo3 project is
 * free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * The GNU General Public License can be found at
 * http://www.gnu.org/copyleft/gpl.html.
 * A copy is found in the textfile GPL.txt and important notices to the license
 * from the author is found in LICENSE.txt distributed with these scripts.
 *
 *
 * This script is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

/**
 *
 * @author  Daniel P�tzinger
 * @author  Tolleiv Nietsch
 * @package realurl
 * @subpackage realurl
 *
 * @todo	check if internal cache array makes sense
 */
class tx_realurl_pathgenerator {
	var $pidForCache;
	var $conf; //conf from reaulurl configuration (segTitleFieldList...)
	var $extconfArr; //ext_conf_template vars
	var $doktypeCache = array ();

	/**
	 *
	 * @param array $conf
	 * @return void
	 */
	function init($conf) {
		$this->conf = $conf;
		$this->extconfArr = unserialize ( $GLOBALS ['TYPO3_CONF_VARS'] ['EXT'] ['extConf'] ['realurl'] );
	}

	/**
	 *
	 * @param int $pid
	 * @param int $langid
	 * @param int $workspace
	 * @return array	buildPageArray
	 */
	function build($pid, $langid, $workspace) {
		if ($shortCutPid = $this->_checkForShortCutPageAndGetTarget ( $pid, $langid, $workspace )) {
			if (is_array ( $shortCutPid ) && array_key_exists ( 'path', $shortCutPid ) && array_key_exists ( 'rootPid', $shortCutPid )) {
				return $shortCutPid;
			}
			$pid = $shortCutPid;
		}

		$this->pidForCache = $pid;
		$rootline = $this->_getRootline ( $pid, $langid, $workspace );
		$firstPage = $rootline [0];
		$rootPid = $firstPage ['uid'];
		$lastPage = $rootline [count ( $rootline ) - 1];

		$pathString = '';
		$external = false;

		if ($lastPage ['doktype'] == 3) {
			$pathString = $this->_buildExternalURL ( $lastPage, $langid, $workspace );
			$external = TRUE;

		} elseif ($lastPage ['tx_realurl_pathoverride'] && $overridePath = $this->_stripSlashes ( $lastPage ['tx_realurl_pathsegment'] )) {
			$parts = explode ( '/', $overridePath );
			$cleanParts = array_map ( array (
				$this,
				'encodeTitle'
			), $parts );
			$nonEmptyParts = array_filter ( $cleanParts );
			$pathString = implode ( '/', $nonEmptyParts );
		}
		if (! $pathString) {
			if ($this->_getDelegationFieldname ( $lastPage ['doktype'] )) {
				$pathString = $this->_getDelegationTarget ( $lastPage );
				if (! preg_match ( '/^[a-z]+:\/\//', $pathString ))
					$pathString = 'http://' . $pathString;
				$external = TRUE;
			} else {
				$pathString = $this->_buildPath ( $this->conf ['segTitleFieldList'], $rootline );
			}
		}


		return array (
			'path' => $pathString,
			'rootPid' => $rootPid,
			'external' => $external
		);
	}

	/**
	 *
	 * @param string $str_org
	 * @return string
	 */
	function _stripSlashes($str_org) {
		$str = $str_org;
		if (substr ( $str, - 1 ) == '/') {
			$str = substr ( $str, 0, - 1 );
		}
		if (substr ( $str, 0, 1 ) == '/') {
			$str = substr ( $str, 1 );
		}
		if ($str_org != $str) {
			return $this->_stripSlashes ( $str );
		} else {
			return $str;
		}
	}

	/**
	 *
	 * @return int Uid for Cache
	 */
	function getPidForCache() {
		return $this->pidForCache;
	}

	/**
	 *
	 * @param int $id
	 * @param int $langid
	 * @param int $workspace
	 * @param int $reclevel
	 * @return boolean
	 */
	function _checkForShortCutPageAndGetTarget($id, $langid = 0, $workspace = 0, $reclevel = 0) {
		if ($this->conf ['renderShortcuts']) {
			return FALSE;
		} else {

			static $cache = array();
			$paramhash = intval($id).'_'.intval($langid).'_'.intval($workspace).'_'.intval($reclevel);

			if (isset($cache[$paramhash])) {
				return $cache[$paramhash];
			}

			$returnValue = FALSE;

			if ($reclevel > 20) {
				$returnValue =  FALSE;
			}
			$this->_initSysPage ( 0, $workspace ); // check defaultlang since overlays should not contain this (usually)
			$result = $this->sys_page->getPage ( $id );

				// if overlay for the of shortcuts is requested
			if ($this->extconfArr ['localizeShortcuts'] && t3lib_div::inList ( $GLOBALS ['TYPO3_CONF_VARS'] ['FE'] ['pageOverlayFields'], 'shortcut' ) && $langid) {

				$resultOverlay = $this->_getPageOverlay ( $id, $langid );
				if ($resultOverlay ["shortcut"]) {
					$result ["shortcut"] = $resultOverlay ["shortcut"];
				}
			}

			if ($result ['doktype'] == 4) {
				switch ($result ['shortcut_mode']) {
					case '1' : //firstsubpage
						if ($reclevel > 10) {
							$returnValue = FALSE;
						}
						$where = "pid=\"" . $id . "\"";
						$query = $GLOBALS ['TYPO3_DB']->exec_SELECTquery ( "uid", "pages", $where, '', 'sorting', '0,1' );
						if ($query)
							$resultfirstpage = $GLOBALS ['TYPO3_DB']->sql_fetch_assoc ( $query );
						$subpageShortCut = $this->_checkForShortCutPageAndGetTarget ( $resultfirstpage ['uid'], $langid, $workspace, $reclevel+1 );
						if ($subpageShortCut !== FALSE) {
							$returnValue = $subpageShortCut;
						} else {
							$returnValue = $resultfirstpage ['uid'];
						}
						break;
					case '2' : //random
						$returnValue = FALSE;
						break;
					default :
						if ($result ['shortcut'] == $id) {
							$returnValue = FALSE;
						} else {
							//look recursive:
							$subpageShortCut = $this->_checkForShortCutPageAndGetTarget ( $result ['shortcut'], $langid, $workspace, $reclevel+1 );
							if ($subpageShortCut !== FALSE) {
								$returnValue = $subpageShortCut;
							} else {
								$returnValue = $result ['shortcut'];
							}
						}
						break;
				}
			} elseif ($this->_getDelegationFieldname ( $result ['doktype'] )) {

				$target = $this->_getDelegationTarget ( $result, $langid, $workspace );
				if (is_numeric ( $target )) {
					$res = $this->_checkForShortCutPageAndGetTarget ( $target, $langid, $workspace, $reclevel-1 );
					//if the recursion fails we keep the original target
					if ($res === FALSE) {
						$res = $target;
					}
				} else {
					$res = $result ['uid'];
				}
				$returnValue = $res;
			} else {
				$returnValue = FALSE;
			}

			$cache[$paramhash] = $returnValue;
			return $returnValue;
		}
	}

	/**
	 * set the rootpid that is used for generating the path. (used to stop rootline on that pid)
	 *
	 * @param int $id
	 * @return void
	 */
	public function setRootPid($id) {
		$this->rootPid = $id;
	}

	/**
	 *
	 * @param int $pid	Pageid of the page where the rootline should be retrieved
	 * @param int $langID
	 * @param int $wsId
	 * @param mixed $mpvar
	 * @return mixed	array with rootline for pid
	 */
	function _getRootLine($pid, $langID, $wsId, $mpvar = '') {
		// Get rootLine for current site (overlaid with any language overlay records).
		$this->_initSysPage ( $langID, $wsId );
		$rootLine = $this->sys_page->getRootLine ( $pid, $mpvar );
			//only return rootline to the given rootpid
		$rootPidFound = FALSE;
		while ( ! $rootPidFound && count ( $rootLine ) > 0 ) {
			$last = array_pop ( $rootLine );
			if ($last ['uid'] == $this->rootPid) {
				$rootPidFound = TRUE;
				$rootLine [] = $last;
				break;
			}
		}
		if (! $rootPidFound) {
			if ($this->conf ['strictMode'] == 1) {
				throw new Exception ( 'The rootpid ' . $this->rootPid . '.configured for pagepath generation was not found in the rootline for page' . $pid );
			}
			return $rootLine;
		}

		$siteRootLine = array ();
		$c = count ( $rootLine );
		foreach ( $rootLine as $val ) {
			$c --;
			$siteRootLine [$c] = $val;
		}
		return $siteRootLine;
	}

	/**
	 * checks if the user is logged in backend
	 * @return bool
	 **/
	function _isBELogin() {
		return is_object ( $GLOBALS ['BE_USER'] );
	}

	/**
	 * builds the path based on the rootline
	 * @param $segment configuration wich field from database should use
	 * @param $rootline The rootLine  from the actual page
	 * @return array with rootLine and path
	 **/
	function _buildPath($segment, $rootline) {
		$segment = t3lib_div::trimExplode ( ",", $segment );
		$path = array ();
		$size = count ( $rootline );
		$rootline = array_reverse ( $rootline );
			//do not include rootpage itself, except it is only the root and filename is set:
		if ($size > 1 || $rootline [0] ['tx_realurl_pathsegment'] == '') {
			array_shift ( $rootline );
			$size = count ( $rootline );
		}
		$i = 1;
		foreach ( $rootline as $key => $value ) {
				//check if the page should exlude from path (if not last)
			if ($value ['tx_realurl_exclude'] && $i != $size) {
			} else {  //the normal way

				$pathSeg = $this->_getPathSeg ( $value, $segment );
				if (strcmp ( $pathSeg, '' ) === 0) {
					if ((strcmp ( $pathSeg, '' ) === 0) && $value ['_PAGES_OVERLAY']) {
						$pathSeg = $this->_getPathSeg ( $this->_getDefaultRecord ( $value ), $segment );
					}
					if (strcmp ( $pathSeg, '' ) === 0) {
						$pathSeg = 'page_' . $value ['uid'];
					}
				}
				$path [] = $pathSeg;
			}
			$i ++;
		}
			//build the path
		$path = implode ( "/", $path );
		return $path;
	}

	/**
	 *
	 * @param array $pageRec
	 * @param array $segments
	 * @return string
	 */
	function _getPathSeg($pageRec, $segments) {
		$retVal = '';
		foreach ( $segments as $segmentName ) {
			if ($this->encodeTitle ( $pageRec [$segmentName] ) != '') {
				$retVal = $this->encodeTitle ( $pageRec [$segmentName] );
				break;
				//$value['uid']
			}
		}
		return $retVal;
	}

	/**
	 *
	 * @param array $l10nrec
	 * @return arrray
	 */
	function _getDefaultRecord($l10nrec) {
		$lang = $this->sys_page->sys_language_uid;
		$this->sys_page->sys_language_uid = 0;
		$rec = $this->sys_page->getPage ( $l10nrec ['uid'] );
		$this->sys_page->sys_language_uid = $lang;
		return $rec;
	}

	/**
	 *
	 * @param int $doktype
	 * @return boolean
	 */
	function isDelegationDoktype($doktype) {
		if (! array_key_exists ( $doktype, $this->doktypeCache )) {
			$this->doktypeCache [$doktype] = ($this->_getDelegationFieldname ( $doktype )) ? TRUE : FALSE;
		}
		return $this->doktypeCache [$doktype];
	}

	/**
	 *
	 * @param int $doktype
	 * @return string
	 */
	function _getDelegationFieldname($doktype) {
		if (is_array ( $this->conf ['delegation'] ) && array_key_exists ( $doktype, $this->conf ['delegation'] )) {
			return $this->conf ['delegation'] [$doktype];
		} else if (is_array ( $GLOBALS ['TYPO3_CONF_VARS'] ['EXTCONF'] ['realurl'] ['delegate'] ) && array_key_exists ( $doktype, $GLOBALS ['TYPO3_CONF_VARS'] ['EXTCONF'] ['realurl'] ['delegate'] )) {
			return $GLOBALS ['TYPO3_CONF_VARS'] ['EXTCONF'] ['realurl'] ['delegate'] [$doktype];
		} else {
			return FALSE;
		}
	}

	/**
	 *
	 * @param array $record
	 * @param int $langid
	 * @param int $workspace
	 * @return int
	 */
	function _getDelegationTarget($record, $langid = 0, $workspace = 0) {

		$fieldname = $this->_getDelegationFieldname ( $record ['doktype'] );

		if (! array_key_exists ( $fieldname, $record )) {
			$this->_initSysPage ( $langid, $workspace );
			$record = $this->sys_page->getPage ( $record ['uid'] );
		}

		$parts = explode ( ' ', $record [$fieldname] );

		return $parts [0];
	}

	/*******************************
	 *
	 * Helper functions
	 *
	 ******************************/
	/**
	 * Convert a title to something that can be used in an page path:
	 * - Convert spaces to underscores
	 * - Convert non A-Z characters to ASCII equivalents
	 * - Convert some special things like the 'ae'-character
	 * - Strip off all other symbols
	 * Works with the character set defined as "forceCharset"
	 *
	 * @param	string		Input title to clean
	 * @return	string		Encoded title, passed through rawurlencode() = ready to put in the URL.
	 * @see rootLineToPath()
	 */
	function encodeTitle($title) {
			// Fetch character set:
		$charset = $GLOBALS ['TYPO3_CONF_VARS'] ['BE'] ['forceCharset'] ? $GLOBALS ['TYPO3_CONF_VARS'] ['BE'] ['forceCharset'] : $GLOBALS ['TSFE']->defaultCharSet;
			// Convert to lowercase:
		$processedTitle = $GLOBALS ['TSFE']->csConvObj->conv_case ( $charset, $title, 'toLower' );
			// Convert some special tokens to the space character:
		$space = isset ( $this->conf ['spaceCharacter'] ) ? $this->conf ['spaceCharacter'] : '-';
		$processedTitle = preg_replace ( '/[\s+]+/', $space, $processedTitle ); // convert spaces
			// Convert extended letters to ascii equivalents:
		$processedTitle = $GLOBALS ['TSFE']->csConvObj->specCharsToASCII ( $charset, $processedTitle );
			// Strip the rest...:
		$processedTitle = preg_replace ( '/[^a-zA-Z0-9\\_\\' . $space . ']/', $space, $processedTitle );
		$processedTitle = preg_replace ( '/\\' . $space . '+/', $space, $processedTitle );
		$processedTitle = trim ( $processedTitle, $space );
		if ($this->conf ['encodeTitle_userProc']) {
			$params = array (
				'pObj' => &$this,
				'title' => $title,
				'processedTitle' => $processedTitle
			);
			$processedTitle = t3lib_div::callUserFunction ( $this->conf ['encodeTitle_userProc'], $params, $this );
		}
			// Return encoded URL:
		return rawurlencode ( $processedTitle );
	}

	/**
	 *
	 *
	 * @param int $langID
	 * @param int $workspace
	 * @return void
	 */
	function _initSysPage($langID, $workspace) {
		if (! is_object ( $this->sys_page )) {
			/**
			 *	Initialize the page-select functions.
			 * 	don't use $GLOBALS['TSFE']->sys_page here this might
			 *	lead to strange side-effects due to the fact that some
			 *	members of sys_page are modified.
			 *
			 *	I also opted against "clone $GLOBALS['TSFE']->sys_page"
			 *	since this might still cause race conditions on the object
			 **/
			$this->sys_page = t3lib_div::makeInstance ( 't3lib_pageSelect' );
		}
		$this->sys_page->sys_language_uid = $langID;
		if ($workspace != 0 && is_numeric ( $workspace )) {
			$this->sys_page->versioningWorkspaceId = $workspace;
			$this->sys_page->versioningPreview = 1;
		} else {
			$this->sys_page->versioningWorkspaceId = 0;
			$this->sys_page->versioningPreview = FALSE;
		}
	}

	/**
	 *
	 * @param array $page
	 * @param int $langid
	 * @param int $workspace
	 * @return string
	 */
	function _buildExternalURL($page, $langid = 0, $workspace = 0) {

		$this->_initSysPage ( 0, $workspace ); // check defaultlang since overlays should not contain this (usually)
		$fullPageArr = $this->sys_page->getPage ( $page ['uid'] );
		if ($langid) {
			$fullPageArr = array_merge ( $fullPageArr, $this->_getPageOverlay ( $page ['uid'], $langid ) );
		}

		t3lib_div::loadTCA ( 'pages' );
		$prefix = FALSE;
		$prefixItems = $GLOBALS ['TCA'] ['pages'] ['columns'] ['urltype'] ['config'] ['items'];
		if (is_array($prefixItems)) {
			foreach ( $prefixItems as $prefixItem ) {
				if (intval ( $prefixItem ['1'] ) == intval ( $fullPageArr ['urltype'] )) {
					$prefix = $prefixItem ['0'];
					break;
				}
			}
		}

		if (! $prefix) {
			$prefix = 'http://';
		}
		return $prefix . $fullPageArr ['url'];
	}

	/**
	 *
	 * @param int $id
	 * @param int $langid
	 * @return array
	 */
	function _getPageOverlay($id, $langid = 0) {
		$relevantLangId = $langid;
		if ($this->extconfArr ['useLanguagevisibility'] && t3lib_extMgm::isLoaded('languagevisibility')) {
			require_once (t3lib_extMgm::extPath ( "languagevisibility" ) . 'class.tx_languagevisibility_feservices.php');
			$relevantLangId = tx_languagevisibility_feservices::getOverlayLanguageIdForElementRecord ( $id, 'pages', $langid );
		}
		return $this->sys_page->getPageOverlay ( $id, $relevantLangId );
	}

}
?>
