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
 * @author	Daniel Pötzinger
 * @author	Tolleiv Nietsch
 */
include_once (t3lib_extMgm::extPath ( 'realurl' ) . 'class.tx_realurl_pathgenerator.php');
include_once (t3lib_extMgm::extPath ( 'realurl' ) . 'class.tx_realurl_cachemgmt.php');
/**
 *
 * @author	Daniel Pötzinger
 * @package realurl
 * @subpackage realurl
 *
 * @todo	check if internal cache array can improve speed
 * @todo	move oldlinks to redirects
 * @todo	check last updatetime of pages
 */
class tx_realurl_pagepath {
	var $generator; //help object for generating paths
	var $insert = false;
	var $pObj;
	var $conf;

	/**
	 * @var tx_realurl_cachemgmt
	 */
	protected $cachemgmt;

	/** Main function -> is called from real_url
	 * parameters and results are in $params (some by reference)
	 *
	 * @param	array		Parameters passed from parent object, "tx_realurl". Some values are passed by reference! (paramKeyValues, pathParts and pObj)
	 * @param	tx_realurl		Copy of parent object.
	 * @return	mixed		Depends on branching.
	 */
	public function main($params, $ref) {
			// Setting internal variables:
		$this->_setParent ( $ref );
		$this->_setConf ( $params ['conf'] );
			//TODO is this needed ??
		srand (); //init rand for cache

		$this->initGenerator ();

		switch (( string ) $params ['mode']) {
			case 'encode' :
				$this->initCacheMgm($this->_getLanguageVarEncode());
				$path = $this->_id2alias ( $params ['paramKeyValues'] );
				$params ['pathParts'] = array_merge ( $params ['pathParts'], $path );
				unset ( $params ['paramKeyValues'] ['id'] );
				return;
				break;
			case 'decode' :
				$this->initCacheMgm($this->_getLanguageVarDecode());
				$id = $this->_alias2id ( $params ['pathParts'] );
				return array (
					$id,
					array ()
				);
				break;
		}
	}

	/**
	 * gets the path for a pageid, must store and check the generated path in cache
	 * (should be aware of workspace)
	 *
	 * @param array $paramKeyValues from real_url
	 * @param array $pathParts from real_url ??
	 * @return string with path
	 */
	protected function _id2alias($paramKeyValues) {
		$pageId = $paramKeyValues['id'];
		if (!is_numeric($pageId)  && is_object($GLOBALS ['TSFE']->sys_page)) {
			$pageId = $GLOBALS['TSFE']->sys_page->getPageIdFromAlias($pageId );
		}
		if ($this->_isCrawlerRun() && $GLOBALS['TSFE']->id == $pageId) {
			$GLOBALS['TSFE']->applicationData['tx_crawler']['log'][] = 'realurl: _id2alias ' . $pageId . '/' . $this->_getLanguageVarEncode() . '/' . $this->_getWorkspaceId();
			//clear this page cache:
			$this->cachemgmt->markAsDirtyCompletePid($pageId );
		}
		
		$buildedPath = $this->cachemgmt->isInCache($pageId);

		if (!$buildedPath) {
			$buildPageArray = $this->generator->build($pageId, $this->_getLanguageVarEncode(), $this->_getWorkspaceId() );
			$buildedPath = $buildPageArray['path'];
			$buildedPath = $this->cachemgmt->storeUniqueInCache($this->generator->getPidForCache(), $buildedPath, $buildPageArray['external'] );
			if($this->_isCrawlerRun() && $GLOBALS['TSFE']->id == $pageId) {
				$GLOBALS['TSFE']->applicationData['tx_crawler']['log'][] = 'created: ' . $buildedPath . ' pid:' . $pageId . '/' . $this->generator->getPidForCache();
			}
		}
		if ($buildedPath) {
			$pagePath_exploded = explode('/', $buildedPath );
			return $pagePath_exploded;
		} else {
			return array();
		}
	}

	/**
	 * Gets the pageid from a pagepath, needs to check the cache
	 * 
	 * @param	array		Array of segments from virtual path
	 * @return	integer		Page ID
	 */
	protected function _alias2id(&$pagePath) {
		$pagePathOrigin = $pagePath;
		$keepPath = array ();
			//Check for redirect
		$this->_checkAndDoRedirect ( $pagePathOrigin );
			//read cache with the path you get, decrease path if nothing is found
		$pageId = $this->cachemgmt->checkCacheWithDecreasingPath ( $pagePathOrigin, $keepPath );
			//fallback 1 - use unstrict cache where
			/**
			 * @todo
			 * @issue http://bugs.aoedev.com/view.php?id=19834
			 */
		if ($pageId == false) {
			$this->cachemgmt->useUnstrictCacheWhere ();
			$keepPath = array ();
			$pageId = $this->cachemgmt->checkCacheWithDecreasingPath ( $pagePathOrigin, $keepPath );
			$this->cachemgmt->doNotUseUnstrictCacheWhere ();
		}
			//fallback 2 - look in history
		if ($pageId == false) {
			$keepPath = array ();
			$pageId = $this->cachemgmt->checkHistoryCacheWithDecreasingPath ( $pagePathOrigin, $keepPath );
		}

		$pagePath = $keepPath;
		return $pageId;
	}

	/**
	 *
	 * @param string $path
	 * @return void
	 */
	protected function _checkAndDoRedirect($path) {
		$_params = array();
		if (is_array ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['EXT:realurl/class.tx_realurl_pagepath.php']['checkAndDoRedirect']))	{
			foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['EXT:realurl/class.tx_realurl_pagepath.php']['checkAndDoRedirect'] as $_funcRef)	{
				t3lib_div::callUserFunction($_funcRef, $_params, $this);
			}
		}
	}

	/**
	 *
	 * @return int
	 */
	protected function _getRootPid() {
		// Find the PID where to begin the resolve:
		if ($this->conf ['rootpage_id']) { // Take PID from rootpage_id if any:
			$pid = intval ( $this->conf ['rootpage_id'] );
		} else {
			//if not defined in realUrlConfig get 0
			$pid = 0;
		}
		return $pid;
	}

	/**
	 * Gets the value of current language
	 * What needs to happen:
	 * decode: -the languageid is used by cachemgmt in order to retrieve the correct pid for the given path
	 * -that means it needs to return the languageid of the current context:
	 * (means the L parameter value after realurl processing)
	 *
	 * encode: - the langugeid is used to build the path + to cache the path
	 * - if in the url parameters it is forced to generate the url in a specific language it needs to use this (L parameter defined in typolink)
	 * -
	 * first it tries to recieve it from the get-parameters directly
	 * - orig_paramKeyValues is set by realurl during encoding, and it has the L paremeter value that is passed to typolink
	 *
	 * @return	integer		Current language or 0
     * @deprecated
     * @todo Should be replaced with the new methods - tests "tests/tx_realurl_pagepath_testcase.php"
	 */
	function _getLanguageVar() {
		$lang = FALSE;
		$getVarName = $this->conf ['languageGetVar'] ? $this->conf ['languageGetVar'] : 'L';

		// Setting the language variable based on GETvar in URL which has been configured to carry the language uid:
		if ($getVarName && array_key_exists ( $getVarName, $this->pObj->orig_paramKeyValues )) {
			$lang = intval ( $this->pObj->orig_paramKeyValues [$getVarName] );
			// Might be excepted (like you should for CJK cases which does not translate to ASCII equivalents)
			if (t3lib_div::inList ( $this->conf ['languageExceptionUids'], $lang )) {
				$lang = 0;
			}
		}
		if ($lang === FALSE) {
			//TODO next line is not covered by a test
			$lang = t3lib_div::_GP ( $getVarName );
			if ($lang == 0 && method_exists ( $this->pObj, 'getRetrievedPreGetVar' )) {
				$lang = intval ( $this->pObj->getRetrievedPreGetVar ( $getVarName ) );
			}
		}

		if ($this->conf ['languageGetVarPostFunc']) {
			$lang = t3lib_div::callUserFunction ( $this->conf ['languageGetVarPostFunc'], $lang, $this );
		}
		return intval ( $lang );
	}

	/**
	 * DECODE
	 * Find the current language id.
	 *
	 * The languageid is used by cachemgmt in order to retrieve the correct pid for the given path
	 * -that means it needs to return the languageid of the current context:
	 * (means the L parameter value after realurl processing)
	 *
	 * @return integer Current language id
	 *
	 * @author Michael Klapper <michael.klapper@aoemedia.de>
	 */
	function _getLanguageVarDecode() {
		$getVarName = $this->conf['languageGetVar'] ? $this->conf['languageGetVar'] : 'L';
		$lang = $this->pObj->getRetrievedPreGetVar( $getVarName );

		if ($this->conf['languageGetVarPostFunc']) {
			$lang = t3lib_div::callUserFunction($this->conf['languageGetVarPostFunc'], $lang, $this );
		}
		return (int)$lang;
	}

	/**
	 * ENCODE
	 * Find the current language id.
	 *
	 * The langugeid is used to build the path + to cache the path
	 * - if in the url parameters it is forced to generate the url in a specific language it needs to use this (L parameter defined in typolink)
	 *
	 * - orig_paramKeyValues is set by realurl during encoding, and it has the L paremeter value that is passed to typolink
	 *
	 * @return integer Current language id
	 *
	 * @author Michael Klapper <michael.klapper@aoemedia.de>
	 */
	function _getLanguageVarEncode() {
		$lang = FALSE;
		$getVarName = $this->conf ['languageGetVar'] ? $this->conf ['languageGetVar'] : 'L';
		// $orig_paramKeyValues  Contains the index of GETvars that the URL had when the encoding began.
		// Setting the language variable based on GETvar in URL which has been configured to carry the language uid:
		if ($getVarName && array_key_exists($getVarName, $this->pObj->orig_paramKeyValues)) {
			$lang = intval($this->pObj->orig_paramKeyValues[$getVarName]);
			// Might be excepted (like you should for CJK cases which does not translate to ASCII equivalents)
			if (t3lib_div::inList($this->conf['languageExceptionUids'], $lang)) {
				$lang = 0;
			}
		}

		if ($this->conf['languageGetVarPostFunc']) {
			$lang = t3lib_div::callUserFunction($this->conf['languageGetVarPostFunc'], $lang, $this);
		}

		return (int)$lang;
	}

	/**
	 *
	 * @return boolean
	 */
	function _isBELogin() {
		if (! is_object ( $GLOBALS ['BE_USER'] ))
			return false;
		else
			return true;
	}

	/**
	 * if workspace preview in FE return that workspace
	 *
	 * @return int
	 */
	function _getWorkspaceId() {
		if (is_object ( $GLOBALS ['BE_USER'] ) && t3lib_div::_GP ( 'ADMCMD_noBeUser' ) != 1) {
			if (is_object ( $GLOBALS ['TSFE']->sys_page )) {
				if ($GLOBALS ['TSFE']->sys_page->versioningPreview == 1) {
					return $GLOBALS ['TSFE']->sys_page->versioningWorkspaceId;
				}
			} else {
				if ($GLOBALS ['BE_USER']->user ['workspace_preview'] == 1) {
					return $GLOBALS ['BE_USER']->workspace;
				}
			}
		}
		return 0;
	}

	/**
	 * returns true/false if the current context is within a crawler call (procInstr. tx_cachemgm_recache)
	 * This is used for some logging. The status is cached for performance reasons
	 *
	 * @return boolean
	 */
	function _isCrawlerRun() {
		if (
			t3lib_extMgm::isLoaded('crawler')
			&& $GLOBALS['TSFE']->applicationData['tx_crawler']['running'] 
			&& (
				in_array('tx_cachemgm_recache', $GLOBALS['TSFE']->applicationData['tx_crawler']['parameters']['procInstructions'])
				|| in_array('tx_realurl_rebuild', $GLOBALS['TSFE']->applicationData['tx_crawler']['parameters']['procInstructions'])
			)
		) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * assigns the configuration
	 *
	 * @param $conf
	 * @return void
	 */
	function _setConf($conf) {
		//TODO: validate the incoming conf
		$this->conf = $conf;
	}

	/**
	 * assigns the parent object
	 *
	 * @param tx_realurl    $ref: the parent object
	 * @return void
	 */
	function _setParent($ref) {
		$this->pObj = &$ref;
	}

	/**
	 * Initialize the pathgenerator
	 *
	 */
	function initGenerator() {
		$this->generator = t3lib_div::makeInstance ( 'tx_realurl_pathgenerator' );
		$this->generator->init ( $this->conf );
		$this->generator->setRootPid ( $this->_getRootPid () );
	}

	/**
	 * Initialize the Cache-Layer
	 *
	 * @param integer $lang Current language value
	 * @return void
	 */
	function initCacheMgm($lang) {
		$this->cachemgmt = t3lib_div::makeInstance('tx_realurl_cachemgmt', $this->_getWorkspaceId (), $lang );
		$this->cachemgmt->setCacheTimeout ( $this->conf ['cacheTimeOut'] );
		$this->cachemgmt->setRootPid ( $this->_getRootPid () );
	}
}
?>
