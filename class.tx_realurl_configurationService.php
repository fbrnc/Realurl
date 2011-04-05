<?php
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2009 (dev@aoemedia.de)
 *  All rights reserved
 *
 *  This script is part of the Typo3 project. The Typo3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  A copy is found in the textfile GPL.txt and important notices to the license
 *  from the author is found in LICENSE.txt distributed with these scripts.
 *
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

require_once(t3lib_extMgm::extPath('realurl') . 'class.tx_realurl_configurationService_exception.php');

class tx_realurl_configurationService {

	private $confArray = array();
	private $useAutoAdjustRootPid = FALSE;

	public function __construct() {
		$this->loadRealUrlConfiguration();
	}

	public function loadRealUrlConfiguration() {
		$_realurl_conf = @unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['realurl']);
		// Autoconfiguration
		if ($_realurl_conf['enableAutoConf'] && !isset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['realurl']) && !@include_once (PATH_site . TX_REALURL_AUTOCONF_FILE) && !isset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['realurl'])) {
			require_once (t3lib_extMgm::extPath('realurl', 'class.tx_realurl_autoconfgen.php'));
			$_realurl_gen = t3lib_div::makeInstance('tx_realurl_autoconfgen');
			$_realurl_gen->generateConfiguration();
			unset($_realurl_gen);
			@include_once (PATH_site . TX_REALURL_AUTOCONF_FILE);
		}
		$this->confArray = &$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['realurl'];
	}

	public function setRealUrlConfiguration(array $conf) {
		$this->confArray = $conf;
	}

	public function getConfigurationForDomain($host = '') {
		if ($host == '') {
			$host = t3lib_div::getIndpEnv('TYPO3_HOST_ONLY');
		}
		// First pass, finding configuration OR pointer string:
		if (isset($this->confArray[$host])) {
			$extConf = $this->confArray[$host];
			// If it turned out to be a string pointer, then look up the real config:
			while (!is_null($extConf) && is_string($extConf)) {
				$extConf = $this->confArray[$this->extConf];
			}
			if (!is_array($extConf)) {
				$extConf = $this->confArray['_DEFAULT'];
				if ($this->multidomain && isset($extConf['pagePath']['rootpage_id'])) {
					// This can't be right!
					unset($extConf['pagePath']['rootpage_id']);
				}
			}
		} else {
			if ($this->enableStrictMode && $this->multidomain) {
				throw new tx_realurl_configurationService_exception('RealURL strict mode error: ' . 'multidomain configuration detected and domain \'' . $this->host . '\' is not configured for RealURL. Please, fix your RealURL configuration!');
			}
			$extConf = (array)$this->confArray['_DEFAULT'];
			if ($this->multidomain && isset($extConf['pagePath']['rootpage_id']) && $this->enableStrictMode) {
				throw new tx_realurl_configurationService_exception('Rootpid configured for _DEFAULT namespace, tis can cause wron cache entries and should be avoided');
			}
		}

		if ($this->useAutoAdjustRootPid) {
			unset($extConf['pagePath']['rootpage_id']);
			$extConf['pagePath']['rootpage_id'] = $this->findRootPageId($host);
		}

		/*
				   * @todo
				   *
				   *  - do some struct mode checks:
				   *  if (!$this->extConf['pagePath']['rootpage_id']) {

					  if ($this->enableStrictMode) {
						  $this->pObj->pageNotFoundAndExit('RealURL strict mode error: ' .
							  'multidomain configuration without rootpage_id. ' .
							  'Please, fix your RealURL configuration!');
					  }
				   */

		// $GLOBALS['TT']->setTSlogMessage('RealURL warning: rootpage_id was not configured!');

		return $extConf;

	}

	/**
	 * Attempts to find root page ID for the current host. Processes redirectes as well.
	 *
	 * @return	int		Found root page false if not found
	 */
	private function findRootPageId($domain = '') {
		$rootpage_id = false;
		// Search by host
		do {
			$domain = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows('pid,redirectTo,domainName', 'sys_domain', 'domainName=' . $GLOBALS['TYPO3_DB']->fullQuoteStr($host, 'sys_domain') . ' AND hidden=0');
			if (count($domain) > 0) {
				if (!$domain[0]['redirectTo']) {
					$rootpage_id = intval($domain[0]['pid']);
					if ($this->enableDevLog) {
						t3lib_div::devLog('Found rootpage_id by domain lookup', 'realurl', 0, array('domain' => $domain[0]['domainName'], 'rootpage_id' => $rootpage_id));
					}
					break;
				} else {
					$parts = @parse_url($domain[0]['redirectTo']);
					$host = $parts['host'];
				}
			}
		} while (count($domain) > 0);

		// If root page id is not found, try other ways. We can do it only
		// and only if there are no multiple domains. Otherwise we would
		// get a lot of wrong page ids from old root pages, etc.
		if (!$rootpage_id && !$this->multidomain) {
			// Try by TS template
			$rows = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows('pid', 'sys_template', 'root=1 AND hidden=0');
			if (count($rows) == 1) {
				$rootpage_id = $rows[0]['pid'];
				if ($this->enableDevLog) {
					t3lib_div::devLog('Found rootpage_id by searching sys_template', 'realurl', 0, array('rootpage_id' => $rootpage_id));
				}
			}
		}
		return $rootpage_id;
	}


}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/realurl/class.tx_realurl_configurationService.php']) {
	include_once ($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/realurl/class.tx_realurl_configurationService.php']);
}

?>