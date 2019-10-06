<?php

namespace rvtraveller\ComposerConverter\Tests;

use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

class ComposerizeWordpressCommandTest extends CommandTestBase
{

    /**
     * {@inheritdoc}
     *
     * @see https://symfony.com/doc/current/console.html#testing-commands
     */
    public function setUp()
    {
        parent::setUp();
        $this->application->add(new TestableComposerizeWordpressCommand());
        $this->command = $this->application->find('composerize-drupal');
        $this->commandTester = new CommandTester($this->command);
    }

    // @todo Test --core-root option.

    /**
     * Tests various Wordpress core versions with command.
     *
     * @param string $wordpress_core_version
     *   The Wordpress core version. E.g., 8.6.0, 8.6.x-dev.
     *
     * @dataProvider providerTestWordpressCoreVersions
     */
    public function testWordpressCoreVersions($wordpress_core_version)
    {
        $this->sandboxManager->setDrupalVersion($wordpress_core_version);
        $this->sandbox = $this->sandboxManager->makeSandbox();
        $this->sandbox = $this->sandbox . "/docroot";
        $args = [];
        $options = [ 'interactive' => false ];
        $exit_code = $this->commandTester->execute($args, $options);

        $this->assertEquals(0, $exit_code);
        $this->assertCorrectFileGeneration('');
    }

    /**
     * Provides values to testWordpressCoreVersions().
     *
     * @return array
     *   An array of values to test.
     */
    public function providerTestWordpressCoreVersions()
    {
        return [
            // Currently dev versions of core do not work due to
            // the coder module making vendor file changes that prompt Composer
            // to ask interactive questions.
            // ['8.6.x-dev'],
            ['8.6.10'],
        ];
    }

    /**
     * Tests that composer.json contents are valid.
     *
     * This test will assume composer root is docroot, a default subdir that
     * is automatically detected.
     */
    public function testNoSubdirAssumed()
    {
        $this->sandbox = $this->sandboxManager->makeSandbox();
        $this->sandbox = $this->sandbox . "/docroot";
        $args = [];
        $options = [ 'interactive' => false ];
        $this->commandTester->execute($args, $options);

        $this->assertCorrectFileGeneration('');
    }

    /**
     * This command explicitly sets the composer root is docroot.
     */
    public function testNoSubDirExplicit()
    {
        $this->sandbox = $this->sandboxManager->makeSandbox();
        $this->sandbox = $this->sandbox . "/docroot";
        $args = [
            '--composer-root' => 'docroot',
            '--core-root' => 'docroot',
            '--no-update' => true,
        ];
        $options = [ 'interactive' => false ];
        $this->commandTester->execute($args, $options);
        $this->assertCorrectFileGeneration('');
    }

    /**
     * Test command when Wordpress is in a default subdirectory.
     */
    public function testSubdirAssumed()
    {
        $this->sandbox = $this->sandboxManager->makeSandbox();
        $args = [
            '--composer-root' => '.',
            '--no-update' => true,
        ];
        $options = [ 'interactive' => false ];
        $this->commandTester->execute($args, $options);
        $this->assertCorrectFileGeneration('docroot/');
        $this->assertFileNotExists($this->sandbox . "/docroot/composer.json");
    }

    /**
     * Test command when Wordpress is in an explicitly defined subdir.
     */
    public function testSubDirExplicit()
    {
        $this->sandbox = $this->sandboxManager->makeSandbox();
        $this->fs->rename($this->sandbox . "/docroot", $this->sandbox . "/wp");
        $args = [
            '--composer-root' => '.',
            '--drupal-root' => 'wp',
            '--no-update' => true,
        ];
        $options = [ 'interactive' => false ];
        $this->commandTester->execute($args, $options);
        $this->assertCorrectFileGeneration('wp/');
        $this->assertFileNotExists($this->sandbox . "/wp/composer.json");
    }

    /**
     * Test command with --no-gitignore option is passed.
     */
    public function testNoGitignore()
    {
        $this->sandbox = $this->sandboxManager->makeSandbox();
        $original_gitignore = 'vendor';
        file_put_contents($this->sandbox . '/.gitignore', $original_gitignore);
        $args = [
            '--composer-root' => '.',
            '--no-update' => true,
            '--no-gitignore' => true,
        ];
        $options = [ 'interactive' => false ];
        $this->commandTester->execute($args, $options);
        $this->assertCorrectFileGeneration('docroot/');
        $this->assertFileNotExists($this->sandbox . "/docroot/composer.json");
        $this->assertEquals($original_gitignore, file_get_contents($this->sandbox . '/.gitignore'));
    }

    /**
     * Test command without --no-gitignore option is passed.
     */
    public function testGitignore()
    {
        $this->sandbox = $this->sandboxManager->makeSandbox();
        $original_gitignore = 'vendor';
        file_put_contents($this->sandbox . '/.gitignore', $original_gitignore);
        $args = [
            '--composer-root' => '.',
            '--no-update' => true,
            '--no-gitignore' => false,
        ];
        $options = [ 'interactive' => false ];
        $this->commandTester->execute($args, $options);
        $this->assertCorrectFileGeneration('docroot/');
        $this->assertFileNotExists($this->sandbox . "/docroot/composer.json");
        $this->assertNotEquals($original_gitignore, file_get_contents($this->sandbox . '/.gitignore'));
    }

    /**
     * Tests modules can be downloaded from Drupal.org.
     *
     * @todo update
     */
    public function testDrupalEndpoint()
    {
        $this->sandbox = $this->sandboxManager->makeSandbox();
        $args = [
            '--composer-root' => '.',
            '--no-update' => true,
        ];
        $options = [ 'interactive' => false ];
        $this->commandTester->execute($args, $options);
        $process = new Process('composer require drupal/token:1.1.0');
        $process->setTimeout(null);
        $process->run();
        $this->assertEquals(0, $process->getExitCode());
        $this->assertFileExists($this->sandbox . "/docroot/modules/contrib/token");
    }

    /**
     * @param $relative_drupal_root
     *
     * @todo update
     */
    protected function assertCorrectFileGeneration($relative_drupal_root)
    {
        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $this->assertNotContains(
            '[drupal-root]',
            file_get_contents($this->sandbox . "/composer.json")
        );

        $composer_json = json_decode(file_get_contents($this->sandbox . "/composer.json"));

        // Modules existing in codebase were added to composer.json.
        $this->assertObjectHasAttribute(
            'drupal/ctools',
            $composer_json->require
        );
        $this->assertEquals(
            "^3.0.0",
            $composer_json->require->{'drupal/ctools'}
        );
        $this->assertObjectHasAttribute(
            'drupal/core',
            $composer_json->require
        );
        $this->assertEquals(
            "^" . $this->sandboxManager->getDrupalVersion(),
            $composer_json->require->{'drupal/core'}
        );

        // Assert installer paths.
        $this->assertObjectHasAttribute('installer-paths', $composer_json->extra);
        $this->assertObjectHasAttribute('drush/Commands/{$name}', $composer_json->extra->{'installer-paths'});
        $this->assertObjectHasAttribute($relative_drupal_root . 'core', $composer_json->extra->{'installer-paths'});
        $this->assertObjectHasAttribute($relative_drupal_root . 'modules/contrib/{$name}', $composer_json->extra->{'installer-paths'});
        $this->assertObjectHasAttribute($relative_drupal_root . 'modules/custom/{$name}', $composer_json->extra->{'installer-paths'});
        $this->assertObjectHasAttribute($relative_drupal_root . 'profiles/contrib/{$name}', $composer_json->extra->{'installer-paths'});
        $this->assertObjectHasAttribute($relative_drupal_root . 'profiles/custom/{$name}', $composer_json->extra->{'installer-paths'});
        $this->assertObjectHasAttribute($relative_drupal_root . 'themes/contrib/{$name}', $composer_json->extra->{'installer-paths'});
        $this->assertObjectHasAttribute($relative_drupal_root . 'themes/custom/{$name}', $composer_json->extra->{'installer-paths'});
        $this->assertObjectHasAttribute($relative_drupal_root . 'libraries/{$name}', $composer_json->extra->{'installer-paths'});

        // Assert patches.
        $this->assertObjectHasAttribute('patches', $composer_json->extra);
        $this->assertObjectHasAttribute('drupal/ctools', $composer_json->extra->patches);
        $patch_relative_path = "patches/test.patch";
        $this->assertObjectHasAttribute('drupal/ctools', $composer_json->extra->patches);
        $this->assertObjectHasAttribute($patch_relative_path, $composer_json->extra->patches->{"drupal/ctools"});
        $this->assertEquals($patch_relative_path, $composer_json->extra->patches->{"drupal/ctools"}->{$patch_relative_path});

        // Assert merge-plugin.
        $this->assertContains($relative_drupal_root . "modules/custom/*/composer.json", $composer_json->extra->{'merge-plugin'}->include);
    }
}
