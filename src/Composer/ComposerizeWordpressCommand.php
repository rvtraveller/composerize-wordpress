<?php

namespace rvtraveller\ComposerConverter\Composer;

use Composer\Semver\Semver;
use Composer\Util\ProcessExecutor;
use rvtraveller\ComposerConverter\Utility\CoreFinder;
use Grasmash\ComposerConverter\Utility\ComposerJsonManipulator;
use rvtraveller\ComposerConverter\Utility\WordpressInspector;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Command\BaseCommand;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

class ComposerizeWordpressCommand extends BaseCommand
{

    /** @var InputInterface */
    protected $input;
    protected $baseDir;
    protected $composerConverterDir;
    protected $templateComposerJson;
    protected $rootComposerJsonPath;
    protected $coreRoot;
    protected $coreRootRelative;
    protected $coreVersion;
    /** @var Filesystem */
    protected $fs;

    public function configure()
    {
        $this->setName('composerize-wordpress');
        $this->setDescription("Convert a non-Composer managed Wordpress application into a Composer-managed application.");
        $this->addOption('composer-root', null, InputOption::VALUE_REQUIRED, 'The relative path to the directory that should contain composer.json.');
        $this->addOption('core-root', null, InputOption::VALUE_REQUIRED, 'The relative path to the Wordpress root directory.');
        $this->addOption('exact-versions', null, InputOption::VALUE_NONE, 'Use exact version constraints rather than the recommended caret operator.');
        $this->addOption('no-update', null, InputOption::VALUE_NONE, 'Prevent "composer update" being run after file generation.');
        $this->addOption('no-gitignore', null, InputOption::VALUE_NONE, 'Prevent root .gitignore file from being modified.');
        $this->addUsage('--composer-root=. --core-root=./docroot');
        $this->addUsage('--composer-root=. --core-root=./web');
        $this->addUsage('--composer-root=. --core-root=.');
        $this->addUsage('--exact-versions --no-update --no-gitignore');
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return int
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->fs = new Filesystem();
        $this->setDirectories($input);
        $this->coreVersion = $this->determineWordPressCoreVersion();
        $this->removeAllComposerFiles();
        $this->createNewComposerJson();
        $this->addRequirementsToComposerJson();
        if (!$this->input->getOption('no-gitignore')) {
            $this->mergeTemplateGitignore();
        }

        $exit_code = 0;
        if (!$input->getOption('no-update')) {
            $this->getIO()->write("Executing <comment>composer update</comment>...");
            $exit_code = $this->executeComposerUpdate();
        } else {
            $this->getIO()->write("Execute <comment>composer update</comment> to install dependencies.");
        }

        if (!$exit_code) {
            $this->printPostScript();
        }

        return $exit_code;
    }

  /**
   * @return mixed
   */
    public function getTemplateComposerJson()
    {
        if (!isset($this->templateComposerJson)) {
            $this->templateComposerJson = $this->loadTemplateComposerJson();
        }

        return $this->templateComposerJson;
    }

  /**
   * @return mixed
   */
    public function getBaseDir()
    {
        return $this->baseDir;
    }

  /**
   * @param mixed $baseDir
   */
    public function setBaseDir($baseDir)
    {
        $this->baseDir = $baseDir;
    }

  /**
   * @return mixed
   */
    protected function loadTemplateComposerJson()
    {
        $template_composer_json = json_decode(file_get_contents($this->composerConverterDir . "/template.composer.json"));
        ComposerJsonManipulator::processPaths($template_composer_json, $this->coreRootRelative);

        return $template_composer_json;
    }

    protected function loadRootComposerJson()
    {
        return json_decode(file_get_contents($this->rootComposerJsonPath));
    }

    protected function createNewComposerJson()
    {
        ComposerJsonManipulator::writeObjectToJsonFile(
            $this->getTemplateComposerJson(),
            $this->rootComposerJsonPath
        );
        $this->getIO()->write("<info>Created composer.json</info>");
    }

    protected function addRequirementsToComposerJson()
    {
        $root_composer_json = $this->loadRootComposerJson();
        $projects = $this->findContribProjects($root_composer_json);
        $this->requireContribProjects($root_composer_json, $projects);
        $this->requireWordpressCore($root_composer_json);

        ComposerJsonManipulator::writeObjectToJsonFile(
            $root_composer_json,
            $this->rootComposerJsonPath
        );
    }

    /**
     * @return mixed|string
     * @throws \Exception
     */
    protected function determineWordPressCoreVersion()
    {
        if (file_exists($this->coreRoot . "/wp-includes/version.php")) {
            $core_version = WordpressInspector::determineWordpressCoreVersionFromVersionPhp(
                $this->coreRoot . "/wp-includes/version.php"
            );

            if (!Semver::satisfiedBy([$core_version], "*")) {
                throw new \Exception("WordPress core version $core_version is invalid.");
            }

            return $core_version;
        }
        if (!isset($this->coreVersion)) {
            throw new \Exception("Unable to determine WordPress core version.");
        }
    }

    /**
     * @param $root_composer_json
     * @param $projects
     */
    protected function requireContribProjects($root_composer_json, $projects)
    {
        foreach ($projects as $project_name => $project) {
            $package_name = "wpackagist-plugin/$project_name";
            $version_constraint = WordpressInspector::getVersionConstraint($project['version'], $this->input->getOption('exact-versions'));
            $root_composer_json->require->{$package_name} = $version_constraint;

            if ($version_constraint == "*") {
                $this->getIO()->write("<comment>Could not determine correct version for project $package_name. Added to requirements without constraint.</comment>");
            } else {
                $this->getIO()->write("<info>Added $package_name with constraint $version_constraint to requirements.</info>");
            }
        }
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     */
    protected function setDirectories(InputInterface $input)
    {
        $this->composerConverterDir = dirname(dirname(__DIR__));
        $coreFinder = new CoreFinder();
        $this->determineCoreRoot($input, $coreFinder);
        $this->determineComposerRoot($input, $coreFinder);
        $this->coreRootRelative = trim($this->fs->makePathRelative(
            $this->coreRoot,
            $this->baseDir
        ), '/');
        $this->rootComposerJsonPath = $this->baseDir . "/composer.json";
    }

    /**
     * @return int
     */
    protected function executeComposerUpdate()
    {
        $io = $this->getIO();
        $executor = new ProcessExecutor($io);
        $output_callback = function ($type, $buffer) use ($io) {
            $io->write($buffer, false);
        };
        return $executor->execute('composer update --no-interaction', $output_callback, $this->baseDir);
    }

    /**
     *
     */
    protected function mergeTemplateGitignore()
    {
        $template_gitignore = file($this->composerConverterDir . "/template.gitignore");
        $gitignore_entries = [];
        foreach ($template_gitignore as $key => $line) {
            $gitignore_entries[] = str_replace(
                '[web-root]',
                $this->coreRootRelative,
                $line
            );
        }
        $root_gitignore_path = $this->getBaseDir() . "/.gitignore";
        $verb = "modified";
        if (!file_exists($root_gitignore_path)) {
            $verb = "created";
            $this->fs->touch($root_gitignore_path);
        }
        $root_gitignore = file($root_gitignore_path);
        foreach ($root_gitignore as $key => $line) {
            if ($key_to_remove = array_search($line, $gitignore_entries)) {
                unset($gitignore_entries[$key_to_remove]);
            }
        }
        $merged_gitignore = $root_gitignore + $gitignore_entries;
        file_put_contents(
            $root_gitignore_path,
            implode('', $merged_gitignore)
        );

        $this->getIO()->write("<info>$verb .gitignore. Composer dependencies will NOT be committed.</info>");
    }

    /**
     * @param $root_composer_json
     */
    protected function requireWordpressCore($root_composer_json)
    {
        $version_constraint = WordpressInspector::getVersionConstraint($this->coreVersion, $this->input->getOption('exact-versions'));
        $root_composer_json->require->{'pantheon-systems/wordpress-composer'} = $version_constraint;
        $this->getIO()
            ->write("<info>Added pantheon-systems/wordpress-composer $version_constraint to requirements.</info>");
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param CoreFinder $coreFinder
     *
     * @throws \Exception
     */
    protected function determineComposerRoot(
        InputInterface $input,
        CoreFinder $coreFinder
    ) {
        if ($input->getOption('composer-root')) {
            if (!$this->fs->isAbsolutePath($input->getOption('composer-root'))) {
                $this->baseDir = getcwd() . "/" . $input->getOption('composer-root');
            } else {
                $this->baseDir = $input->getOption('composer-root');
            }
        } else {
            $this->baseDir = $coreFinder->getComposerRoot();
            $confirm = $this->getIO()
                ->askConfirmation("<question>Assuming that composer.json should be generated at {$this->baseDir}. Is this correct?</question> ");
            if (!$confirm) {
                throw new \Exception("Please use --composer-root to specify the correct Composer root directory");
            }
        }
    }

    /**
     * @param InputInterface $input
     * @param CoreFinder $coreFinder
     *
     * @throws \Exception
     */
    protected function determineCoreRoot(InputInterface $input, CoreFinder $coreFinder)
    {
        if (!$input->getOption('core-root')) {
            $common_core_root_subdirs = [
                'docroot',
                'web',
                'htdocs',
                'public_html',
            ];
            $root = getcwd();
            foreach ($common_core_root_subdirs as $candidate) {
                if (file_exists("$root/$candidate")) {
                    $root = "$root/$candidate";
                    break;
                }
            }
        } else {
            $root = $input->getOption('core-root');
        }

        if ($coreFinder->locateRoot($root)) {
            $this->coreRoot = $coreFinder->getCoreRoot();
            if (!$this->fs->isAbsolutePath($root)) {
                $this->coreRoot = getcwd() . "/$root";
            }
        } else {
            throw new \Exception("Unable to find Core root directory. Please change directories to a valid application. Try specifying it with --core-root.");
        }
    }

    /**
     * Removes all composer.json and composer.lock files recursively.
     */
    protected function removeAllComposerFiles()
    {
        $finder = new Finder();
        $finder->in($this->baseDir)
            ->files()
            ->name('/^composer\.(lock|json)$/');
        $files = iterator_to_array($finder);
        $this->fs->remove($files);
    }

    protected function printPostScript()
    {
        $this->getIO()->write("<info>Completed composerization!</info>");
    }

    /**
     * @param $root_composer_json
     *
     * @todo
     *
     * @return array
     */
    protected function findContribProjects($root_composer_json)
    {
        $modules_contrib = WordpressInspector::findContribProjects(
            $this->coreRoot,
            "wp-content/plugins",
            $root_composer_json
        );
        $themes = WordpressInspector::findContribProjects(
            $this->coreRoot,
            "wp-content/themes",
            $root_composer_json
        );
        $projects = array_merge($modules_contrib, $themes);
        return $projects;
    }
}
