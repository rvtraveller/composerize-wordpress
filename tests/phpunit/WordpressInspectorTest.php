<?php

namespace rvtraveller\ComposerConverter\Tests;

use rvtraveller\ComposerConverter\Utility\ComposerJsonManipulator;
use rvtraveller\ComposerConverter\Utility\WordpressInspector;
use Symfony\Component\Process\Process;

/**
 * Tests the WordpressInspector class.
 */
class WordpressInspectorTest extends TestBase
{

    /**
     * Tests WordpressInspector::findContribProjects().
     */
    public function testFindModules()
    {
        $this->sandbox = $this->sandboxManager->makeSandbox();
        $composer_json = json_decode(file_get_contents($this->sandbox . "/docroot/composer.json"));
        $modules = WordpressInspector::findContribProjects($this->sandbox . "/docroot", "wp-content/plugins", $composer_json);
        $this->assertArrayHasKey('ctools', $modules);
        $this->assertArrayHasKey('version', $modules['ctools']);
        $this->assertArrayHasKey('dir', $modules['ctools']);
        $this->assertContains('3.0.0', $modules['ctools']['version']);
    }

    /**
     * @dataProvider providerGetSemanticVersion
     */
    public function testGetSemanticVersion($drupal_version, $semantic_version)
    {
        $converted_version = WordpressInspector::getSemanticVersion($drupal_version);
        $this->assertEquals($semantic_version, $converted_version);
    }

    /**
     * Provides values to testArrayMergeNoDuplicates().
     *
     * @return array
     *   An array of values to test.
     */
    public function providerGetSemanticVersion()
    {
        return [
        ['3.0', '3.0.0'],
        ['1.x-dev', '1.x-dev'],
        ['3.12', '3.12.0'],
        ['3.0-alpha1', '3.0.0-alpha1'],
        ['3.12-beta2', '3.12.0-beta2'],
        ['4.0-rc12', '4.0.0-rc12'],
        ['0.1-rc2', '0.1.0-rc2'],
        ];
    }

    /**
     * @dataProvider providerGetVersionConstraint
     */
    public function testGetVersionConstraint($semantic_version, $exact_versions, $expected_constraint)
    {
        $version_constraint = WordpressInspector::getVersionConstraint($semantic_version, $exact_versions);
        $this->assertEquals($expected_constraint, $version_constraint);
    }

    /**
     * Provides values to testArrayMergeNoDuplicates().
     *
     * @return array
     *   An array of values to test.
     */
    public function providerGetVersionConstraint()
    {
        return [
            ['3.0.0', true, '3.0.0'],
            ['3.0.0', false, '^3.0.0'],
            ['1.x-dev', false, '1.x-dev'],
            ['1.x-dev', true, '1.x-dev'],
            ['8.6.x-dev', false, '8.6.x-dev'],
            ['8.6.x-dev', true, '8.6.x-dev'],
            ['3.0.0-alpha1', false, '^3.0.0-alpha1'],
            ['3.12.0-beta2', false, '^3.12.0-beta2'],
            ['4.0.0-rc12', false, '^4.0.0-rc12'],
            ['0.1.0-rc2', false, '^0.1.0-rc2'],
        ];
    }

    /**
     * @todo update
     *
     * @dataProvider providerDetermineDrupalCoreVersionFromDrupalPhp
     */
    public function testDetermineDrupalCoreVersionFromDrupalPhp($file_contents, $expected_core_version)
    {
        $core_version = WordpressInspector::determineWordpressCoreVersionFromVersionPhp($file_contents);
        $this->assertEquals($expected_core_version, $core_version);
    }

    /**
     * Provides values to determineDrupalCoreVersionFromDrupalPhp().
     *
     * @todo update
     *
     * @return array
     *   An array of values to test.
     */
    public function providerDetermineDrupalCoreVersionFromDrupalPhp()
    {
        return [
         ["const VERSION = '8.0.0';", "8.0.0"],
         ["const VERSION = '8.0.0-beta1';", "8.0.0-beta1"],
         ["const VERSION = '8.0.0-rc2';", "8.0.0-rc2"],
         ["const VERSION = '8.5.11';", "8.5.11"],
         ["const VERSION = '8.5.x-dev';", "8.5.x-dev"],
         ["const VERSION = '8.6.11-dev';", "8.6.x-dev"],
        ];
    }
}
