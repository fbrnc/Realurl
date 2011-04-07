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
 * *
 * @author  Daniel Pötzinger
 * @author  Tolleiv Nietsch
 */

//TODO: add testdatabase xml
//require_once (t3lib_extMgm::extPath ( "realurl" ) . 'class.tx_realurl_pathgenerator.php');
// require_once (t3lib_extMgm::extPath('phpunit').'class.tx_phpunit_test.php');
require_once (PATH_t3lib . 'class.t3lib_tcemain.php');
class tx_realurl_pathgenerator_testcase extends tx_phpunit_database_testcase {

	/**
	 * Enter description here...
	 *
	 * @var tx_realurl_pathgenerator
	 */
	private $pathgenerator;
	private $rootlineFields;

	public function setUp() {
		$GLOBALS ['TYPO3_DB']->debugOutput = true;
		$this->createDatabase ();
		$db = $this->useTestDatabase ();
		$this->importStdDB ();

		// make sure addRootlineFields has the right content - otherwise we experience DB-errors within testdb
		$this->rootlineFields = $GLOBALS ['TYPO3_CONF_VARS'] ['FE'] ['addRootLineFields'];
		$GLOBALS ['TYPO3_CONF_VARS'] ['FE'] ['addRootLineFields'] = 'tx_realurl_pathsegment,tx_realurl_pathoverride,tx_realurl_exclude';

		//create relevant tables:
		$extList = array ('cms', 'realurl' );
		$extOptList = array ('templavoila', 'languagevisibility', 'aoe_webex_tableextensions', 'aoe_localizeshortcut' );
		foreach ( $extOptList as $ext ) {
			if (t3lib_extMgm::isLoaded ( $ext )) {
				$extList [] = $ext;
			}
		}
		$this->importExtensions ( $extList );

		$this->importDataSet ( dirname ( __FILE__ ) . '/fixtures/page-livews.xml' );
		$this->importDataSet ( dirname ( __FILE__ ) . '/fixtures/overlay-livews.xml' );
		$this->importDataSet ( dirname ( __FILE__ ) . '/fixtures/page-ws.xml' );
		$this->importDataSet ( dirname ( __FILE__ ) . '/fixtures/overlay-ws.xml' );

		$this->pathgenerator = new tx_realurl_pathgenerator ( );
		$this->pathgenerator->init ( $this->fixture_defaultconfig () );
		$this->pathgenerator->setRootPid ( 1 );
		if (! is_object ( $GLOBALS ['TSFE']->csConvObj )) {
			$GLOBALS ['TSFE']->csConvObj = t3lib_div::makeInstance ( 't3lib_cs' );
		}
	}

	public function tearDown() {
		$this->cleanDatabase ();
		$this->dropDatabase ();
		$GLOBALS ['TYPO3_DB']->sql_select_db ( TYPO3_db );
		$GLOBALS ['TYPO3_CONF_VARS'] ['FE'] ['addRootLineFields'] = $this->rootlineFields;
	}

	/**
	 * Rootline retrieval needs to work otherwise we can't generate paths
	 *
	 * @test
	 */
	public function canGetCorrectRootline() {
		$result = $this->pathgenerator->_getRootline ( 87, 0, 0 );
		$count = count ( $result );
		$first = $result [0];
		$this->assertEquals ( $count, 4, 'rootline should be 3 long' );
		$this->assertTrue ( isset ( $first ['tx_realurl_pathsegment'] ), 'tx_realurl_pathsegment should be set' );
		$this->assertTrue ( isset ( $first ['tx_realurl_exclude'] ), 'tx_realurl_exclude should be set' );
	}

	/**
	 * Generator works for standard paths
	 *
	 * @test
	 */
	public function canBuildStandardPaths() {
			// 1) Rootpage
		$result = $this->pathgenerator->build ( 1, 0, 0 );
		$this->assertEquals ( $result ['path'], '', 'wrong path build: root should be empty' );

			// 2) Normal Level 2 page
		$result = $this->pathgenerator->build ( 83, 0, 0 );
		$this->assertEquals ( $result ['path'], 'excludeofmiddle', 'wrong path build: should be excludeofmiddle' );

			// 3) Page without title informations
		$result = $this->pathgenerator->build ( 94, 0, 0 );
		$this->assertEquals ( $result ['path'], 'normal-3rd-level/page_94', 'wrong path build: should be normal-3rd-level/page_94 (last page should have default name)' );
	}

	/**
	 * Excludes and overrides work as supposed
	 *
	 * @test
	 */
	public function canBuildPathsWithExcludeAndOverride() {

			// page root->excludefrommiddle->subpage(with pathsegment)
		$result = $this->pathgenerator->build ( 85, 0, 0 );
		$this->assertEquals ( $result ['path'], 'subpagepathsegment', 'wrong path build: should be subpage' );

			// page root->excludefrommiddle->subpage(with pathsegment)
		$result = $this->pathgenerator->build ( 87, 0, 0 );
		$this->assertEquals ( $result ['path'], 'subpagepathsegment/sub-subpage', 'wrong path build: should be subpagepathsegment/sub-subpage' );

		$result = $this->pathgenerator->build ( 80, 0, 0 );
		$this->assertEquals ( $result ['path'], 'override/path/item', 'wrong path build: should be override/path/item' );

		$result = $this->pathgenerator->build ( 81, 0, 0 );
		$this->assertEquals ( $result ['path'], 'specialpath/withspecial/chars', 'wrong path build: should be specialpath/withspecial/chars' );

			// instead of shortcut page the shortcut target should be used within path
		$result = $this->pathgenerator->build ( 92, 0, 0 );
		$this->assertEquals ( $result ['path'], 'normal-3rd-level/subsection', 'wrong path build: shortcut from uid92 to uid91 should be resolved' );

	}

	/**
	 * Excludes and overrides work as supposed
	 *
	 * @test
	 */
	public function canHandleSelfReferringShortcuts() {
			// shortcuts with a reference to themselfs might be a problem
		$result = $this->pathgenerator->build ( 95, 0, 0 );
		$this->assertEquals ( $result ['path'], 'shortcut-page', 'wrong path build: shortcut shouldn\'t be resolved' );
	}

	/**
	 * Overridepath is handled right even if it's invalid
	 *
	 * @test
	 */
	public function invalidOverridePathWillFallBackToDefaultGeneration() {
		$result = $this->pathgenerator->build ( 82, 0, 0 );
		$this->assertEquals ( $result ['path'], 'invalid-overridepath', 'wrong path build: should be invalid-overridepath' );
	}

	/**
	 * Languageoverlay is taken into account for pagepaths
	 *
	 * @test
	 */
	public function canBuildPathsWithLanguageOverlay() {

			// page root->excludefrommiddle->languagemix (austria)
		$result = $this->pathgenerator->build ( 86, 2, 0 );
		$this->assertEquals ( $result ['path'], 'own/url/for/austria', 'wrong path build: should be own/url/for/austria' );

			// page root->excludefrommiddle->subpage(with pathsegment)
		$result = $this->pathgenerator->build ( 85, 2, 0 );
		$this->assertEquals ( $result ['path'], 'subpagepathsegment-austria', 'wrong path build: should be subpagepathsegment-austria' );

			// page root->excludefrommiddle->subpage (overlay with exclude middle)->sub-subpage
		$result = $this->pathgenerator->build ( 87, 2, 0 );
		$this->assertEquals ( $result ['path'], 'sub-subpage-austria', 'wrong path build: should be subpagepathsegment-austria' );

			//for french (5)
		$result = $this->pathgenerator->build ( 86, 5, 0 );
		$this->assertEquals ( $result ['path'], 'languagemix-segment', 'wrong path build: should be languagemix-segment' );

			// page root->excludefrommiddle->languagemix (austria)
		$result = $this->pathgenerator->build ( 101, 5, 0 );
		$this->assertEquals ( $result ['path'], 'languagemix-segment/another/vivelafrance', 'wrong path build: should be: languagemix-segment/another/vivelafrance' );
	}

	/**
	 * Generating paths per workspace works as supposed
	 *
	 * @test
	 */
	public function canBuildPathsInWorkspace() {

			// page root->excludefrommiddle->subpagepathsegment-ws
		$result = $this->pathgenerator->build ( 85, 0, 1 );
		$this->assertEquals ( $result ['path'], 'subpagepathsegment-ws', 'wrong path build: should be subpage-ws' );

			// page
		$result = $this->pathgenerator->build ( 86, 2, 1 );
		$this->assertEquals ( $result ['path'], 'own/url/for/austria/in/ws', 'wrong path build: should be own/url/for/austria/in/ws' );

			//page languagemix in deutsch (only translated in ws)
		$result = $this->pathgenerator->build ( 86, 1, 1 );
		$this->assertEquals ( $result ['path'], 'languagemix-de', 'wrong path build: should be own/url/for/austria/in/ws' );

			//page languagemix in deutsch (only translated in ws)
		$result = $this->pathgenerator->build ( 85, 1, 1 );
		$this->assertEquals ( $result ['path'], 'subpage-ws-de', 'wrong path build: should be own/url/for/austria/in/ws' );
	}

	/**
	 * Non-latin characters won't break path-generator
	 *
	 * @test
	 */
	public function canBuildPathIfOverlayUsesNonLatinChars() {

			// some non latin characters are replaced
		$result = $this->pathgenerator->build ( 83, 4, 0 );
		$this->assertEquals ( $result ['path'], 'page-exclude', 'wrong path build: should be pages-exclude' );

			// overlay has no latin characters therefore the default record is used
		$result = $this->pathgenerator->build ( 84, 4, 0 );
		$this->assertEquals ( $result ['path'], 'normal-3rd-level', 'wrong path build: should be normal-3rd-level (value taken from default record)' );

			// overlay has no latin characters therefore the default record is used
		$result = $this->pathgenerator->build ( 94, 4, 0 );
		$this->assertEquals ( $result ['path'], 'normal-3rd-level/page_94', 'wrong path build: should be normal-3rd-level/page_94 (value from default records and auto generated since non of the pages had relevant chars)' );
	}

	/**
	 * Retrieval works for path being a delegation target
	 *
	 * @test
	 */
	public function canResolvePathFromDeligatedFlexibleURLField() {

		$this->pathgenerator->init ( $this->fixture_delegationconfig () );

			// Test direct delegation
		$result = $this->pathgenerator->build ( 97, 0, 0 );
		$this->assertEquals ( $result ['path'], 'deligation-target', 'wrong path build: deligation should be executed' );

			// Test multi-hop delegation
		$result = $this->pathgenerator->build ( 96, 0, 0 );
		$this->assertEquals ( $result ['path'], 'deligation-target', 'wrong path build: deligation should be executed' );

	}

	/**
	 * Retrieval works for URL for the external URL Doktype
	 *
	 * @test
	 */
	public function canResolveURLFromExternalURLField() {

		$this->pathgenerator->init ( $this->fixture_defaultconfig () );

		$result = $this->pathgenerator->build ( 199, 0, 0 );
		$this->assertEquals ( $result ['path'], 'https://www.aoemedia.de', 'wrong path build: external URL is expected' );

		$result = $this->pathgenerator->build ( 199, 4, 0 );
		$this->assertEquals ( $result ['path'], 'https://www.aoemedia.de', ' wrong path build: external URL is expected - Chinese records doesn\'t provide own value therefore default-value is used' );

		$result = $this->pathgenerator->build ( 199, 5, 0 );
		$this->assertEquals ( $result ['path'], 'https://www.aoemedia.fr', 'wrong path build: external URL is expected - French records is supposed to overlay the url' );

	}

	/**
	 * Retrieval works for URL as delegation target
	 *
	 * @test
	 */
	public function canResolveURLFromDeligatedFlexibleURLField() {

		$this->pathgenerator->init ( $this->fixture_delegationconfig () );

		$result = $this->pathgenerator->build ( 99, 0, 0 );
		$this->assertEquals ( $result ['path'], 'http://www.aoemedia.de', 'wrong path build: deligation should be executed' );

	}

	/**
	 * Retrieval works for path being a delegation target
	 *
	 * @test
	 * @expectedException Exception
	 */
	public function canNotBuildPathForPageInForeignRooline() {

		$this->pathgenerator->init ( $this->fixture_defaultconfig () );

			// Test direct delegation
		$result = $this->pathgenerator->build ( 200, 0, 0 );

		$this->assertTrue(false);
	}

	/**
	 * Basic configuration (strict mode)
	 *
	 */
	public function fixture_defaultconfig() {
		$conf = array ('type' => 'user', 'userFunc' => 'EXT:realurl/class.tx_realurl_advanced.php:&tx_realurl_advanced->main', 'spaceCharacter' => '-', 'cacheTimeOut' => '100', 'languageGetVar' => 'L', 'rootpage_id' => '1', 'strictMode' => 1, 'segTitleFieldList' => 'alias,tx_realurl_pathsegment,nav_title,title,subtitle' );
		return $conf;
	}

	/**
	 * Configuration with enabled delegation function for pagetype 77
	 *
	 */
	public function fixture_delegationconfig() {
		$conf = array ('type' => 'user', 'userFunc' => 'EXT:realurl/class.tx_realurl_advanced.php:&tx_realurl_advanced->main', 'spaceCharacter' => '-', 'cacheTimeOut' => '100', 'languageGetVar' => 'L', 'rootpage_id' => '1', 'strictMode' => 1, 'segTitleFieldList' => 'alias,tx_realurl_pathsegment,nav_title,title,subtitle', 'delegation' => array (77 => 'url' ) );
		return $conf;
	}

	/**
	 * Changes current database to test database
	 *
	 * @param string $databaseName	Overwrite test database name
	 * @return object
	 */
	protected function useTestDatabase($databaseName = null) {
		$db = $GLOBALS ['TYPO3_DB'];

		if ($databaseName) {
			$database = $databaseName;
		} else {
			$database = $this->testDatabase;
		}

		if (! $db->sql_select_db ( $database )) {
			die ( "Test Database not available" );
		}

		return $db;
	}
}
?>
