<?php
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2008 AOE media GmbH
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/
/**
 * Test case for checking the PHPUnit 3.1.9
 *
 * WARNING: Never ever run a unit test like this on a live site!
 *
 *
 * @author	Daniel Pötzinger
 * @author	Tolleiv Nietsch
 */
require_once (t3lib_extMgm::extPath ( "realurl" ) . 'class.tx_realurl_cachemgmt.php');
//require_once (t3lib_extMgm::extPath('phpunit').'class.tx_phpunit_test.php');
require_once (PATH_t3lib . 'class.t3lib_tcemain.php');

class tx_realurl_cachemgmt_testcase extends tx_phpunit_database_testcase {

	private $rootlineFields;

	public function setUp() {
		$GLOBALS['TYPO3_DB']->debugOutput = true;
		$this->createDatabase();
		$db = $this->useTestDatabase();
		$this->importStdDB();

			// make sure addRootlineFields has the right content - otherwise we experience DB-errors within testdb
		$this->rootlineFields = $GLOBALS['TYPO3_CONF_VARS']['FE']['addRootLineFields'];
		$GLOBALS['TYPO3_CONF_VARS']['FE']['addRootLineFields'] = 'tx_realurl_pathsegment,tx_realurl_pathoverride,tx_realurl_exclude';

			//create relevant tables:
		$extList = array('cms','realurl');
		$extOptList = array('templavoila','languagevisibility','aoe_webex_tableextensions','aoe_localizeshortcut');
		foreach($extOptList as $ext) {
			if(t3lib_extMgm::isLoaded($ext)) {
				$extList[] = $ext;
			}
		}
		$this->importExtensions($extList);

		$this->importDataSet ( dirname ( __FILE__ ) . '/fixtures/page-livews.xml' );
		$this->importDataSet ( dirname ( __FILE__ ) . '/fixtures/page-ws.xml' );

		if (!is_object($GLOBALS['TSFE']->csConvObj)) {
			$GLOBALS['TSFE']->csConvObj=t3lib_div::makeInstance('t3lib_cs');
		}
	}

	public function tearDown() {
		$this->cleanDatabase();
		$this->dropDatabase();
		$GLOBALS['TYPO3_DB']->sql_select_db(TYPO3_db);
		$GLOBALS['TYPO3_CONF_VARS']['FE']['addRootLineFields'] = $this->rootlineFields;
	}

	/**
	 * Basic cache storage / retrieval works as supposed
	 *
	 * @test
	 */
	public function storeInCache() {
		$cache = new tx_realurl_cachemgmt ( 0, 0 );
		$cache->setCacheTimeOut ( 200 );
		$cache->setRootPid ( 1 );
		$path = $cache->storeUniqueInCache ( '9999', 'test9999' );
		$this->assertEquals ( 'test9999', $cache->isInCache ( 9999 ), 'should be in cache' );
		$cache->_delCacheForPid ( 9999 );
		$this->assertFalse ( $cache->isInCache ( 9999 ), 'should not be in cache' );
	}

	/**
	 * Storing empty paths should work as supposed
	 *
	 * @test
	 */
	public function storeEmptyInCache() {
		$cache = new tx_realurl_cachemgmt ( 0, 0 );
		$cache->clearAllCache ();
		$cache->setCacheTimeOut ( 200 );
		$cache->setRootPid ( 1 );
		$path = $cache->storeUniqueInCache ( '9995', '' );
		$this->assertEquals ( '', $path, 'should be empty path' );
		$this->assertEquals ( '', $cache->isInCache ( 9995 ), 'should be in cache' );
		$path = $cache->storeUniqueInCache ( '9995', '' );
		$this->assertEquals ( '', $path, 'should be empty path' );
		$this->assertEquals ( '', $cache->isInCache ( 9995 ), 'should be in cache' );
		$cache->_delCacheForPid ( 9995 );
		$this->assertFalse ( $cache->isInCache ( 9995 ), 'should not be in cache' );
	}

	/**
	 * Retrieving empty paths works as supposed
	 *
	 * @test
	 */
	public function getEmptyFromCache() {
		$cache = new tx_realurl_cachemgmt ( 0, 0 );
		$cache->clearAllCache ();
		$cache->setCacheTimeOut ( 200 );
		$cache->setRootPid ( 1 );
		$path = $cache->storeUniqueInCache ( '9995', '' );
		$pidOrFalse = $cache->checkCacheWithDecreasingPath ( array ('' ), $dummy );
		$this->assertEquals ( $pidOrFalse, 9995, 'should be in cache' );
	}

	/**
	 * Cache avoids collisions
	 *
	 * @test
	 */
	public function storeInCacheCollision() {
		$cache = new tx_realurl_cachemgmt ( 0, 0 );
		$cache->setCacheTimeOut ( 200 );
		$cache->setRootPid ( 1 );
		$path = $cache->storeUniqueInCache ( '9999', 'test9999' );
		$this->assertEquals ( 'test9999', $cache->isInCache ( 9999 ), 'should be in cache' );
		$path = $cache->storeUniqueInCache ( '9998', 'test9999' );
		$this->assertEquals ( 'test9999_9998', $cache->isInCache ( 9998 ), 'should be in cache' );
	}

	/**
	 * Cache avoids collisions
	 *
	 * @test
	 */
	public function storeInCacheCollisionInWorkspace() {

			// new cachemgm for live workspace
		$liveCache = new tx_realurl_cachemgmt ( 0, 0 );
		$liveCache->setCacheTimeOut ( 200 );
		$liveCache->setRootPid ( 1 );
		$path = $liveCache->storeUniqueInCache ( '1000', 'test1000' );
		$this->assertEquals ( 'test1000', $liveCache->isInCache ( 1000 ), 'should be in cache' );
		unset($liveCache);

			// new cachemgm with workspace setting
		$workspaceCache = new tx_realurl_cachemgmt ( 1, 0 );
		$workspaceCache->setCacheTimeOut ( 200 );
		$workspaceCache->setRootPid ( 1 );
			// assuming that 1001 is a different page
		$workspaceCache->storeUniqueInCache ( '1001', 'test1000' );
		$this->assertEquals ( 'test1000_1001', $workspaceCache->isInCache ( 1001 ), 'should be in cache' );

			// assuming that 1010 is a workspace overlay for 1000
		$workspaceCache->storeUniqueInCache ( '1010', 'test1000' );
		$this->assertEquals ( 'test1000', $workspaceCache->isInCache ( 1010 ), 'should be in cache' );

			// assuming that 1020 is a workspace overlay for 1002
		$workspaceCache->storeUniqueInCache ( '1020', 'test1002' );
		$this->assertEquals ( 'test1002', $workspaceCache->isInCache ( 1020 ), 'should be in cache' );
		unset($workspaceCache);

			// new cachemgm without workspace setting
		$liveCache = new tx_realurl_cachemgmt ( 0, 0 );
		$liveCache->setCacheTimeOut ( 200 );
		$liveCache->setRootPid ( 1 );
			// now try to add the live record to cache
		$liveCache->storeUniqueInCache ( '1002', 'test1002' );
		$this->assertEquals ( 'test1002', $liveCache->isInCache ( 1002 ), 'should be in cache' );
		unset($liveCache);
	}

	/**
	 * Cache collisiondetetion makes sure that even if a workspace uses the cache
	 * no false positive collision between LIVE and Workspace is found
	 *
	 * @test
	 */
	public function storeInCacheNoCollisionInLiveWorkspace() {

			// new cachemgm for live workspace
		$cache = new tx_realurl_cachemgmt ( 1, 0 );
		$cache->setCacheTimeOut ( 200 );
		$cache->setRootPid ( 1 );
		$path = $cache->storeUniqueInCache ( '1001', 'test1000' );
		$this->assertEquals ( 'test1000', $cache->isInCache ( 1001 ), 'should be in cache' );
		unset($cache);

		$cache = new tx_realurl_cachemgmt ( 0, 0 );
		$cache->setCacheTimeOut ( 200 );
		$cache->setRootPid ( 1 );
		$path = $cache->storeUniqueInCache ( '1000', 'test1000' );
		$this->assertEquals ( 'test1000', $cache->isInCache ( 1000 ), 'should be in cache and should not collide with the workspace-record' );

	}

	/**
	 * Cache should work within several workspaces
	 *
	 * @test
	 */
	public function storeInCacheWithoutCollision() {
		$cache = new tx_realurl_cachemgmt ( 0, 0 );
		$cache->clearAllCache ();
		$cache->setCacheTimeOut ( 200 );
		$cache->setRootPid ( 1 );
		$path = $cache->storeUniqueInCache ( '9990', 'sample' );
		$this->assertEquals ( 'sample', $cache->isInCache ( 9990 ), 'sample should be in cache' );
			//store same page in another workspace
		$cache->workspaceId = 2;
		$path = $cache->storeUniqueInCache ( '9990', 'sample' );
		$this->assertEquals ( 'sample', $cache->isInCache ( 9990 ), 'sample should be in cache for workspace=2' );
		//	store same page in another workspace
		$cache->workspaceId = 3;
		$path = $cache->storeUniqueInCache ( '9990', 'sample' );
		$this->assertEquals ( 'sample', $cache->isInCache ( 9990 ), 'should be in cache for workspace=3' );
		//	and in another language also
		$cache->languageId = 1;
		$path = $cache->storeUniqueInCache ( '9990', 'sample' );
		$this->assertEquals ( 'sample', $cache->isInCache ( 9990 ), 'should be in cache for workspace=3 and language=1' );
	}

	/**
	 * Check etrieval from cache
	 *
	 * @test
	 */
	public function pathRetrieval() {
		$cache = new tx_realurl_cachemgmt ( 0, 0 );
		$cache->clearAllCache ();
		$cache->setCacheTimeOut ( 200 );
		$cache->setRootPid ( 1 );
		$cache->storeUniqueInCache ( '9990', 'sample/path1' );
		$cache->storeUniqueInCache ( '9991', 'sample/path1/path2' );
		$cache->storeUniqueInCache ( '9992', 'sample/newpath1/path3' );
		$dummy = array ();
		$pidOrFalse = $cache->checkCacheWithDecreasingPath ( array ('sample', 'path1' ), $dummy );
		$this->assertEquals ( $pidOrFalse, '9990', '9990 should be fould for path' );
		$dummy = array ();
		$pidOrFalse = $cache->checkCacheWithDecreasingPath ( array ('sample', 'path1', 'nothing' ), $dummy );
		$this->assertEquals ( $pidOrFalse, '9990', '9990 should be fould for path' );
		$dummy = array ();
		$pidOrFalse = $cache->checkCacheWithDecreasingPath ( array ('sample', 'path2' ), $dummy );
		$this->assertEquals ( $pidOrFalse, FALSE, ' should not be fould for path' );
	}

	/**
	 * Cache-rows should be invalid whenever they're marked as dirty or expired
	 *
	 * @test
	 */
	public function canDetectRowAsInvalid() {
		$cache = new tx_realurl_cachemgmt ( 0, 0 );
		$cache->setCacheTimeOut ( 1 );
		$this->assertFalse ( $cache->_isCacheRowStillValid ( array ('dirty' => '1' ), FALSE ), 'should return false' );
		$this->assertFalse ( $cache->_isCacheRowStillValid ( array ('tstamp' => ($GLOBALS['EXEC_TIME'] - 2) ), FALSE ), 'should return false' );
	}

	/**
	 * Check whether history-handling works as supposed
	 *
	 * @test
	 */
	public function canStoreAndGetFromHistory() {
		$cache = new tx_realurl_cachemgmt ( 0, 0 );
		$cache->clearAllCache ();
		$cache->setCacheTimeOut ( 1 );
		$cache->setRootPid ( 1 );
		$cache->storeUniqueInCache ( '9990', 'sample/path1' );

		$dummy = array ();
		$pidOrFalse = $cache->checkCacheWithDecreasingPath ( array ('sample', 'path1' ), $dummy );
		$this->assertEquals ( $pidOrFalse, '9990', '9990 should be fould for path' );

		//sleep ( 2 );
		// back to the future ;)
		$GLOBALS['EXEC_TIME'] = $GLOBALS['EXEC_TIME']+2;

		$dummy = array ();
		$pidOrFalse = $cache->checkCacheWithDecreasingPath ( array ('sample', 'path1' ), $dummy );
		$this->assertEquals ( $cache->isInCache ( 9990 ), FALSE, 'cache should be expired' );

		$cache->storeUniqueInCache ( '9990', 'sample/path1new' );
		$dummy = array ();
		$pidOrFalse = $cache->checkCacheWithDecreasingPath ( array ('sample', 'path1new' ), $dummy );
		$this->assertEquals ( $pidOrFalse, '9990', ' 9990 should be the path' );
		//now check history

		$pidOrFalse = $cache->checkHistoryCacheWithDecreasingPath ( array ('sample', 'path1' ), $dummy );
		$this->assertEquals ( $pidOrFalse, '9990', ' 9990 should be the pid in history' );

	}
}
?>