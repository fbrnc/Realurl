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
 * @author	Daniel Poetzinger
 * @author	Tolleiv Nietsch
 */

/**
 * TODO:
 - check if internal cache array can improve speed
 - move oldlinks to redirects
 - check last updatetime of pages
 **/
include_once (t3lib_extMgm::extPath('realurl') . 'class.tx_realurl_pathgenerator.php');

/**
 *
 * @author	Daniel Poetzinger
 * @package realurl
 * @subpackage realurl
 */
class tx_realurl_cachemgmt {
	
	//cahce key values
	var $workspaceId;
	var $languageId;
	//unique path check
	var $rootPid;
	var $cacheTimeOut = 1000; //timeout in seconds for cache entries
	var $useUnstrictCacheWhere = FALSE;
	
	/**
	 * @var t3lib_DB
	 */
	protected $dbObj;
	
	static $cache = array();

	/**
	 * Class constructor (PHP4 style)
	 * 
	 * @param int $workspace
	 * @param int $languageid
	 */
	function tx_realurl_cachemgmt($workspace, $languageid) {
		$this->workspaceId = $workspace;
		$this->languageId = $languageid;
		$this->useUnstrictCacheWhere = FALSE;
		$confArr = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['realurl']);
		if (isset($confArr['defaultCacheTimeOut'])) {
			$this->setCacheTimeOut($confArr['defaultCacheTimeOut']);
		}
		$this->dbObj = $GLOBALS['TYPO3_DB'];
	}

	/**
	 *
	 * @param int $rootpid
	 */
	function setRootPid($rootpid) {
		$this->rootPid = $rootpid;
	}

	/**
	 *
	 * @param int $languageid
	 */
	function setLanguageId($languageid) {
		$this->languageId = $languageid;
	}

	/**
	 *
	 * @param int $time - in secounds
	 */
	function setCacheTimeOut($time) {
		$this->cacheTimeOut = intval($time);
	}

	/**
	 * @return void
	 */
	function useUnstrictCacheWhere() {
		$this->useUnstrictCacheWhere = TRUE;
	}

	/**
	 * @return void
	 */
	function doNotUseUnstrictCacheWhere() {
		$this->useUnstrictCacheWhere = FALSE;
	}

	/**
	 * @return int
	 */
	function getWorkspaceId() {
		return $this->workspaceId;
	}

	/**
	 * @return int
	 */
	function getLanguageId() {
		return $this->languageId;
	}

	/**
	 * @return int
	 */
	function getRootPid() {
		return $this->rootPid;
	}

	/**
	 * important function: checks the path in the cache: if not found the check against cache is repeted without the last pathpart
	 * @param array  $pagePathOrigin  the path which should be searched in cache
	 * @param &$keepPath  -> passed by reference -> array with the n last pathparts which could not retrieved from cache -> they are propably preVars from translated parameters (like tt_news is etc...)
	 *
	 * @return pagid or false
	 **/
	function checkCacheWithDecreasingPath($pagePathOrigin, &$keepPath) {
		return $this->_checkACacheTableWithDecreasingPath($pagePathOrigin, $keepPath, FALSE);
	}

	/**
	 * important function: checks the path in the cache: if not found the check against cache is repeated without the last pathpart
	 *
	 * @param array  $pagePathOrigin  the path which should be searched in cache
	 * @param &$keepPath  -> passed by reference -> array with the n last pathparts which could not retrieved from cache -> they are propably preVars from translated parameters (like tt_news is etc...)	 *
	 * @return pagid or false
	 **/
	function checkHistoryCacheWithDecreasingPath($pagePathOrigin, &$keepPath) {
		return $this->_checkACacheTableWithDecreasingPath($pagePathOrigin, $keepPath, TRUE);
	}

	/**
	 *
	 * @see checkHistoryCacheWithDecreasingPath
	 * @param array $pagePathOrigin
	 * @param array $keepPath
	 * @param boolean $inHistoryTable
	 * @return int
	 */
	function _checkACacheTableWithDecreasingPath($pagePathOrigin, &$keepPath, $inHistoryTable = FALSE) {
		$sizeOfPath = count($pagePathOrigin);
		$pageId = false;
		for($i = $sizeOfPath; $i > 0; $i--) {
			if (!$inHistoryTable) {
				$pageId = $this->_readCacheForPath(implode("/", $pagePathOrigin));
			} else {
				$pageId = $this->_readHistoryCacheForPath(implode("/", $pagePathOrigin));
			}
			if ($pageId !== false) {
				//found something => break;
				break;
			} else {
				array_unshift($keepPath, array_pop($pagePathOrigin));
			}
		}
		return $pageId;
	}

	/**
	 * Stores the path in cache and checks if that path is unique, if not this function makes the path unique by adding some numbers
	 * (throws error if caching fails)
	 *
	 * @param string Path
	 * @return string unique path in cache
	 */
	function storeUniqueInCache($pid, $buildedPath, $disableCollisionDetection = false) {
		$this->dbObj->sql_query('BEGIN');
		if ($this->isInCache($pid) === false) {
			$this->_checkForCleanupCache($pid, $buildedPath);
			//do cleanup of old cache entries:
			$_ignore = $pid;
			$_workspace = $this->getWorkspaceId();
			if ($_workspace > 0) {
				$record = t3lib_BEfunc::getLiveVersionOfRecord('pages', $pid, 'uid');
				if (!is_array($record)) {
					$record = t3lib_BEfunc::getWorkspaceVersionOfRecord($_workspace, 'pages', $pid, '*');
				}
				if (is_array($record)) {
					$_ignore = $record['uid'];
				}
			}
			
			if ($this->_readCacheForPath($buildedPath, $_ignore) && !$disableCollisionDetection) {
				$buildedPath .= '_' . $pid;
			}
			//do insert
			$data['tstamp'] = $GLOBALS['EXEC_TIME'];
			$data['path'] = $buildedPath;
			$data['mpvar'] = "";
			$data['workspace'] = $this->getWorkspaceId();
			$data['languageid'] = $this->getLanguageId();
			$data['rootpid'] = $this->getRootPid();
			$data['pageid'] = $pid;
			
			if ($this->dbObj->exec_INSERTquery("tx_realurl_cache", $data)) {
				//TODO ... yeah we saved something in the database - any further problems?
			} else {
				//TODO ... d'oh database didn't like use - what's next?
			}
		}
		$this->dbObj->sql_query('COMMIT');
		return $buildedPath;
	}

	/**
	 * checks cache and looks if a path exist (in workspace, rootpid, language)
	 *
	 * @param string Path
	 * @return string unique path in cache
	 **/
	function _readCacheForPath($pagePath, $ignoreUid = null) {
		
		if (is_numeric($ignoreUid)) {
			$where = 'path="' . $pagePath . '" AND pageid != "' . intval($ignoreUid) . '" ';
		} else {
			$where = 'path="' . $pagePath . '" ';
		}
		$where .= $this->_getAddCacheWhere(TRUE);
		if (method_exists($this->dbObj, 'exec_SELECTquery_master')) {
			// Force select to use master server in t3p_scalable
			$res = $this->dbObj->exec_SELECTquery_master("*", "tx_realurl_cache", $where);
		} else {
			$res = $this->dbObj->exec_SELECTquery("*", "tx_realurl_cache", $where);
		}
		if ($res)
			$result = $this->dbObj->sql_fetch_assoc($res);
		if ($result['pageid']) {
			return $result['pageid'];
		} else {
			return false;
		}
	}

	/**
	 * checks cache and looks if a path exist (in workspace, rootpid, language)
	 *
	 * @param string Path
	 * @return string unique path in cache
	 **/
	function _readHistoryCacheForPath($pagePath) {
		$where = "path=\"" . $pagePath . '"' . $this->_getAddCacheWhere(TRUE);
		$res = $this->dbObj->exec_SELECTquery("*", "tx_realurl_cachehistory", $where);
		if ($res)
			$result = $this->dbObj->sql_fetch_assoc($res);
		if ($result['pageid']) {
			return $result['pageid'];
		} else {
			return false;
		}
	}

	/**
	 * check if a pid has allready a builded path in cache (for workspace,language, rootpid)
	 *
	 * @param int $pid
	 * @return mixed - false or pagepath
	 */
	function isInCache($pid) {
		$row = $this->getCacheRowForPid($pid);
		if (!is_array($row)) {
			return false;
		} else {
			if ($this->_isCacheRowStillValid($row)) {
				return $row['path'];
			} else {
				return false;
			}
		}
	}

	/**
	 *
	 * @param int $pid
	 * @return array
	 */
	function getCacheRowForPid($pid) {
		
		$cacheKey = $this->getCacheKey($pid);
		if (isset($this->cache[$cacheKey]) && is_array($this->cache[$cacheKey])) {
			return $this->cache[$cacheKey];
		}
		
		$row = false;
		$where = 'pageid=' . intval($pid) . $this->_getAddCacheWhere();
		if (method_exists($this->dbObj, 'exec_SELECTquery_master')) {
			// Force select to use master server in t3p_scalable
			$query = $this->dbObj->exec_SELECTquery_master('*', 'tx_realurl_cache', $where);
		} else {
			$query = $this->dbObj->exec_SELECTquery('*', 'tx_realurl_cache', $where);
		}
		if ($query) {
			$row = $this->dbObj->sql_fetch_assoc($query);
		}
		
		if (is_array($row)) {
			$this->cache[$cacheKey] = $row;
		}
		
		return $row;
	}

	/**
	 *
	 * @param int $pid
	 * @return array
	 */
	function getCacheHistoryRowsForPid($pid) {
		$rows = array();
		$where = "pageid=" . intval($pid) . $this->_getAddCacheWhere();
		$query = $this->dbObj->exec_SELECTquery("*", "tx_realurl_cachehistory", $where);
		while ( $row = $this->dbObj->sql_fetch_assoc($query) ) {
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 *
	 * @param integer $pid
	 * @param string $newPath
	 * @return void
	 */
	function _checkForCleanupCache($pid, $newPath) {
		$row = $this->getCacheRowForPid($pid);
		if (!is_array($row)) {
			return false;
		} elseif (!$this->_isCacheRowStillValid($row)) {
			if ($newPath != $row['path']) {
				$this->insertInCacheHistory($row);
			}
			$this->_delCacheForPid($row['pageid']);
		}
	}

	/**
	 *
	 * @param array $row
	 * @return boolean
	 */
	function _isCacheRowStillValid($row) {
		if ($row['dirty'] == 1) {
			return false;
		} elseif (($this->cacheTimeOut > 0) && (($row['tstamp'] + $this->cacheTimeOut) < $GLOBALS['EXEC_TIME'])) {
			return false;
		} else {
			return true;
		}
	}

	/**
	 *
	 * @param int $pid
	 * @return void
	 */
	function _delCacheForPid($pid) {
		$this->cache[$this->getCacheKey($pid)] = false;
		$where = "pageid=" . intval($pid) . $this->_getAddCacheWhere();
		$this->dbObj->exec_DELETEquery("tx_realurl_cache", $where);
	}

	/**
	 *
	 * @param int $pid
	 * @return void
	 */
	function delCacheForCompletePid($pid) {
		$where = "pageid=" . intval($pid) . ' AND workspace=' . intval($this->getWorkspaceId());
		$this->dbObj->exec_DELETEquery("tx_realurl_cache", $where);
	}

	/**
	 *
	 * @param int $pid
	 * @return void
	 */
	function markAsDirtyCompletePid($pid) {
		$where = "pageid=" . intval($pid) . ' AND workspace=' . intval($this->getWorkspaceId());
		$this->dbObj->exec_UPDATEquery("tx_realurl_cache", $where, array('dirty' => 1));
	}

	/**
	 *
	 * @param array $row
	 * @return void
	 */
	function insertInCacheHistory($row) {
		unset($row['dirty']);
		$row['tstamp'] = $GLOBALS['EXEC_TIME'];
		$this->dbObj->exec_INSERTquery("tx_realurl_cachehistory", $row);
	}

	/**
	 *
	 * @return void
	 */
	function clearAllCache() {
		$this->dbObj->exec_DELETEquery("tx_realurl_cache", '1=1');
		$this->dbObj->exec_DELETEquery("tx_realurl_cachehistory", '1=1');
	}

	/**
	 *
	 * @return void
	 */
	function clearAllCacheHistory() {
		$this->dbObj->exec_DELETEquery("tx_realurl_cachehistory", '1=1');
	}

	/**
	 * get where for cache table selects based on internal vars
	 *
	 * @param boolean $withRootPidCheck - is required when selecting for paths -> which should be unique for RootPid
	 * @return string -where clause
	 */
	function _getAddCacheWhere($withRootPidCheck = FALSE) {
		if ($this->useUnstrictCacheWhere) {
			//without the additional keys, thats for compatibility reasons
			$where = '';
		} else {
			$where = ' AND workspace IN (0,' . intval($this->getWorkspaceId()) . ') AND languageid=' . intval($this->getLanguageId());
		}
		if ($withRootPidCheck) {
			$where .= ' AND rootpid=' . intval($this->getRootPid());
		}
		return $where;
	}

	/**
	 * Get cache key
	 * 
	 * @param int $pid
	 */
	protected function getCacheKey($pid) {
		return implode('-', array($pid, $this->getRootPid(), $this->getWorkspaceId(), $this->getLanguageId()));
	}
}

?>