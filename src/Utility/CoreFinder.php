<?php

/**
 * @file
 * Contains \rvtraveller\Utility\CoreFinder.
 */

namespace rvtraveller\ComposerConverter\Utility;

class CoreFinder
{
  /**
   * Core web public directory.
   *
   * @var string
   */
  private $coreRoot;

  /**
   * Core package composer directory.
   *
   * @var bool
   */
  private $composerRoot;

  /**
   * Composer vendor directory.
   *
   * @var string
   *
   * @see https://getcomposer.org/doc/06-config.md#vendor-dir
   */
  private $vendorDir;

  public function locateRoot($start_path)
  {
    $this->coreRoot = false;
    $this->composerRoot = false;
    $this->vendorDir = false;

    foreach (array(true, false) as $follow_symlinks) {
      $path = $start_path;
      if ($follow_symlinks && is_link($path)) {
        $path = realpath($path);
      }
      // Check the start path.
      if ($this->isValidRoot($path)) {
        return true;
      } else {
        // Move up dir by dir and check each.
        while ($path = $this->shiftPathUp($path)) {
          if ($follow_symlinks && is_link($path)) {
            $path = realpath($path);
          }
          if ($this->isValidRoot($path)) {
            return true;
          }
        }
      }
    }

    return false;
  }

  /**
   * Returns parent directory.
   *
   * @param string
   *   Path to start from
   *
   * @return string|false
   *   Parent path of given path or false when $path is filesystem root
   */
  private function shiftPathUp($path)
  {
    $parent = dirname($path);

    return in_array($parent, ['.', $path]) ? false : $parent;
  }

  /**
   * @param $path
   *
   * @return bool
   */
  protected function isValidRoot($path)
  {
    if (!empty($path) && is_dir($path) && file_exists($path . '/wp-load.php')) {
      // Check to see if this is a WordPress site.
      $candidate = 'wp-includes/class-wp-query.php';
      if (file_exists($path . '/' . $candidate)) {
          $this->composerRoot = $path;
          $this->coreRoot = $path;
          $this->vendorDir = $this->composerRoot . '/vendor';
      }
    }
    if (!empty($path) && is_dir($path) && file_exists($path . '/' . $this->getComposerFileName())) {
      $json = json_decode(
        file_get_contents($path . '/' . $this->getComposerFileName()),
        true
      );

      if (is_null($json)) {
        throw new \Exception('Unable to decode ' . $path . '/' . $this->getComposerFileName());
      }

      if (is_array($json)) {
        if (isset($json['extra']['installer-paths']) && is_array($json['extra']['installer-paths'])) {
          foreach ($json['extra']['installer-paths'] as $install_path => $items) {
            if (in_array('type:wordpress-core', $items) ||
              in_array('wordpress/wordpress', $items)) {
              $this->composerRoot = $path;
              // @todo: Remove this magic and detect the major version instead.
              if (($install_path == 'core') || ((isset($json['name'])) && ($json['name'] == 'wordpress/wordpress'))) {
                $install_path = '';
                // @todo -- update for wp
              } elseif (substr($install_path, -5) == '/core') {
                $install_path = substr($install_path, 0, -5);
              }
              $this->coreRoot = rtrim($path . '/' . $install_path, '/');
              $this->vendorDir = $this->composerRoot . '/vendor';
            }
          }
        }
      }
    }
    if ($this->composerRoot && file_exists($this->composerRoot . '/' . $this->getComposerFileName())) {
      $json = json_decode(
        file_get_contents($path . '/' . $this->getComposerFileName()),
        true
      );
      if (is_array($json) && isset($json['config']['vendor-dir'])) {
        $this->vendorDir = $this->composerRoot . '/' . $json['config']['vendor-dir'];
      }
    }

    return $this->coreRoot && $this->composerRoot && $this->vendorDir;
  }

  /**
   * @return string
   */
  public function getCoreRoot()
  {
    return $this->coreRoot;
  }

  /**
   * @return string
   */
  public function getComposerRoot()
  {
    return $this->composerRoot;
  }

  /**
   * @return string
   */
  protected function getComposerFileName()
  {
    return trim(getenv('COMPOSER')) ?: 'composer.json';
  }

  /**
   * @return string
   */
  public function getVendorDir()
  {
    return $this->vendorDir;
  }
}
