<?php

namespace rvtraveller\ComposerConverter\Utility;

use Composer\Semver\Semver;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

class WordpressInspector
{

    /**
     * @param $core_root
     * @param $subdir
     * @param $composer_json
     *
     * @return array
     */
    public static function findContribProjects($core_root, $subdir, $composer_json)
    {
        if (!file_exists($core_root . "/" . $subdir)) {
            return [];
        }

        $finder = new Finder();
        $finder->in([$core_root . "/" . $subdir])
            ->name('*.php')
            ->depth('== 1')
            ->files();

        $projects = [];

        foreach ($finder as $fileInfo) {
            $path = $fileInfo->getPathname();
            $filename_parts = explode('.', $fileInfo->getFilename());
            $machine_name = $filename_parts[0];
            $semantic_version = false;
            // Use same method of retrieving version from plugin as WP itself.
            $regex = 'Version';
            try {
                // We don't need to write to the file, so just open for reading.
                $fp = fopen($fileInfo->getPathname(), 'r');
                // Pull only the first 8kiB of the file in.
                $file_data = fread($fp, 8192);
                // PHP will close file handle, but we are good citizens.
                fclose($fp);
                // Make sure we catch CR-only line endings.
                $file_data = str_replace("\r", "\n", $file_data);

                if (preg_match('/^[ \t\/*#@]*' . preg_quote($regex, '/') . ':(.*)$/mi', $file_data, $match) && $match[1]) {
                    $semantic_version = trim($match[1]);
                } else {
                    continue;
                }

                $regex = "Text Domain";
                if (preg_match('/^[ \t\/*#@]*' . preg_quote($regex, '/') . ':(.*)$/mi', $file_data, $match) && $match[1]) {
                    $plugin_name = trim($match[1]);
                    $machine_name = $plugin_name;
                } else {
                    continue;
                }

                if ($semantic_version === false) {
                    $semantic_version = null;
                }

                // We need to check if this is a private plugin that doesn't exist for download (or a custom one)
                if (WordpressInspector::checkIfProjectExists($plugin_name)) {
                    $projects[$machine_name]["version"] = $semantic_version;
                    $projects[$machine_name]["dir"] = $plugin_name;
                    $path_parts = explode("/", $path);
                    array_pop($path_parts);
                    self::deleteDirectory(implode("/", $path_parts));
                }
            } catch (\Exception $e) {
                // If we can't open the file, we just go on.
            }
        }

        return $projects;
    }

    /**
     * Checks if project exists on WordPress.org
     *
     * @param string $package_name
     *  The name of the package to check.
     *
     * @return bool
     */
    public static function checkIfProjectExists($package_name)
    {
        $url = 'https://api.wordpress.org/plugins/info/1.0/' . $package_name . '.json';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HEADER, true);    // we want headers
        curl_setopt($ch, CURLOPT_NOBODY, true);    // we don't need body
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $output = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded_body = json_decode($output);
        if (is_array($decoded_body)) {
            if (isset($decoded_body['error'])) {
                return false;
            }
            return true;
        }

        return false;
    }

    /**
     * Deletes a directory, including all contents.
     *
     * @param $dir
     *  The directory to delete.
     *
     * @return bool
     */
    private static function deleteDirectory($dir)
    {
        if (!file_exists($dir)) {
            return true;
        }

        if (!is_dir($dir)) {
            return unlink($dir);
        }

        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }

            if (!self::deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }

        }

        return rmdir($dir);
    }

    /**
     * Finds all *.patch files contrib projects listed in $projects.
     *
     * @param array $projects
     *   An array of contrib projects returned by self::findContribProjects().
     *
     * @return array
     */
    public static function findProjectPatches($projects)
    {
        foreach ($projects as $project_name => $project) {
            $finder = new Finder();
            $finder->in([$project['dir']])
                ->name('*.patch')
                ->files();

            foreach ($finder as $fileInfo) {
                $pathname = $fileInfo->getPathname();
                $projects[$project_name]['patches'][] = $pathname;
            }
        }

        return $projects;
    }

    /**
     * Generates a semantic version for a Wordpress plugin.
     *
     * 3.0
     * 3.0-alpha1
     * 3.12-beta2
     * 4.0-rc12
     * 3.12
     * 1.0-unstable3
     * 0.1-rc2
     * 2.10-rc2
     *
     * {major}.{minor}.0-{stability}{#}
     *
     * @return string
     */
    public static function getSemanticVersion($wordpress_version)
    {
        // Strip the 8.x prefix from the version.
        $version = preg_replace('/^8\.x-/', null, $wordpress_version);

        if (preg_match('/-dev$/', $version)) {
            return preg_replace('/^(\d).+-dev$/', '$1.x-dev', $version);
        }

        $matches = [];
        preg_match('/^(\d{1,2})\.(\d{0,2})(\-(alpha|beta|rc|unstable)\d{1,2})?$/i', $version, $matches);
        $version = false;
        if (!empty($matches)) {
            $version = "{$matches[1]}.{$matches[2]}.0";
            if (array_key_exists(3, $matches)) {
                $version .= $matches[3];
            }
        }

        // Reject 'unstable'.

        return $version;
    }

    /**
     * @param $version
     *
     * @return string
     */
    public static function getVersionConstraint($version, $exact_versions)
    {
        if ($version == null) {
            return "*";
        } elseif (strstr($version, '-dev') !== false) {
            return $version;
        } elseif ($exact_versions) {
            return $version;
        } else {
            return "^" . $version;
        }
    }

    /**
     * Determines the version of Wordpress core by looking at version.php contents.
     *
     * @param string $file_contents
     *   The path of version.php.
     *
     * @return mixed|string
     */
    public static function determineWordpressCoreVersionFromVersionPhp($file_contents)
    {
        // Include the Wordpress version.php file so we can retrieve the version number from the variable.
        ob_start();
        include_once $file_contents;
        ob_end_clean();

        if (isset($wp_version)) {
            return $wp_version;
        }

        return null;
    }
}
