<?php

/**
 * Defines a Filecache cache implementation.
 *
 * Use Directory as a bin and file as a cache file.
 */
class FilecacheCache implements BackdropCacheInterface {
  protected $bin;
  protected $directory;

  /**
   * Constructs a new BackdropDatabaseCache object.
   */
  function __construct($bin) {
    // All cache tables should be prefixed with 'cache_', except for the
    // default 'cache' bin.
    if ($bin != 'cache') {
      $bin = 'cache_' . $bin;
    }
    $this->bin = $bin;

    $this->prepare_directory($bin);
  }

  /**
   * Prepare the directory
   */
  protected function prepare_directory() {
    // If private path exists, store it there, fallback to public files.
    $private_path = config_get('system.core', 'file_private_path');
    $public_path = config_get('system.core', 'file_public_path');
    if ($private_path) {
      $default_location = realpath($private_path);
      if ($default_location === 0) {
        $default_location = realpath($public_path);
      }
    }

    $config = config('filecache.settings');
    $dir = $config->get('file_storage_dir') ? $config->get('file_storage_dir') : $default_location . '/filecache';

    $this->directory = $dir . '/' . $this->bin;

    if (!function_exists('file_prepare_directory')) {
      require_once BACKDROP_ROOT . '/core/includes/file.inc';
    }

    if(!is_dir($this->directory) && !file_exists($this->directory)) {
      file_prepare_directory($this->directory, FILE_CREATE_DIRECTORY);
    }

    // Add htaccess private settings if it's not in the private path.
    if (($dir != $private_path . '/filecache') && backdrop_is_apache()) {
      file_save_htaccess($this->directory, TRUE);
    }
  }

  /**
   * Implements BackdropCacheInterface::get().
   */
  function get($cid) {
    $cid = $this->prepareCid($cid);
    if (file_exists($this->directory . '/' . $cid . '.php')) {
      include $this->directory . '/' . $cid . '.php';
      if (isset($cached_data)) {
        return $this->prepareItem($cached_data);
      }
    }
    return FALSE;
  }

  /**
   * Prepares a cached item.
   *
   * Checks that items are either permanent or did not expire, and unserializes
   * data as appropriate.
   *
   * @param $cache
   *   An item loaded from BackdropCacheInterface::get() or BackdropCacheInterface::getMultiple().
   *
   * @return
   *   The item with data unserialized as appropriate or FALSE if there is no
   *   valid item to load.
   */
  protected function prepareItem($cache) {
    $item = new stdClass();
    if(!$item->data = unserialize($cache)){
      return FALSE;
    }
    return $item;
  }

  /**
   * Implements BackdropCacheInterface::getMultiple().
   */
  function getMultiple(array &$cids) {
    try {
      $cache = array();
      foreach($cids as $cid) {
        if($item = $this->get($cid)){
          $cache[$cid] = $item;
        }
      }
      $cids = array_diff($cids, array_keys($cache));
      return $cache;
    }
    catch (Exception $e) {
      // If the Filecache is not available, cache requests should
      // return FALSE in order to allow exception handling to occur.
      return array();
    }

  }
  /**
   * Implements BackdropCacheInterface::set().
   */
  function prepareCid($cid) {
    return str_replace("/", "-", $cid);
  }
  /**
   * Implements BackdropCacheInterface::set().
   */
  function set($cid, $data, $expire = CACHE_PERMANENT) {
    $cid = $this->prepareCid($cid);
    try {
      $data = '<?php $cached_data=\'' . str_replace("'", "\'", serialize($data)) . '\';';
      $filename = $this->directory . '/' . $cid . '.php';

      if($expire === CACHE_PERMANENT) {
        file_put_contents($filename, $data, LOCK_EX);
        backdrop_chmod($filename);
      } else {
        file_put_contents($filename, $data, LOCK_EX);
        backdrop_chmod($filename);
        file_put_contents($filename . '.expire', $expire, LOCK_EX);
        backdrop_chmod($filename);
      }
    }
    catch (Exception $e) {
      // The Filecache may not be available, so we'll ignore these calls.
    }
  }

  /**
   * Implements BackdropCacheInterface::delete().
   */
  function delete($cid) {
    $cid = $this->prepareCid($cid);
    $filename = $this->directory . '/' . $cid . '.php';
    if(is_file($filename)) {
      unlink($filename);
    }
    if(is_file($filename . '.expire')) {
      unlink($filename . '.expire');
    }
  }

  /**
 * Implements BackdropCacheInterface::deleteMultiple().
 */
  function deleteMultiple(array $cids) {
    foreach($cids as $cid) {
      $this->delete($cid);
    }
  }

  /**
   * Implements BackdropCacheInterface::deletePrefix().
   */
  function deletePrefix($prefix) {
    if (!function_exists('file_scan_directory')) {
      require_once BACKDROP_ROOT . '/core/includes/file.inc';
    }
    $expire_files = file_scan_directory($this->directory, '/^' . $prefix . '.*/');
    foreach($expire_files as $file) {
      unlink($file->uri);
    }
  }

  /**
   * Implements BackdropCacheInterface::flush().
   */
  function flush() {
    file_unmanaged_delete_recursive($this->directory);
    file_prepare_directory($this->directory, FILE_CREATE_DIRECTORY);
  }

  /**
   * Implements BackdropCacheInterface::garbageCollection().
   */
  function garbageCollection() {
    if(!is_dir($this->directory)){
      return;
    }

    // Get current list of items.
    if (!function_exists('file_scan_directory')) {
      require_once BACKDROP_ROOT . '/core/includes/file.inc';
    }
    $expire_files = file_scan_directory($this->directory, '/*.expire$/');
    foreach($expire_files as $file) {
      $timestamp = file_get_contents($file->uri);
      if($timestamp < REQUEST_TIME) {
        unlink($file->uri);
        unlink(substr($file->uri, 0, -7));
      }
    }

  }

  /**
   * Implements BackdropCacheInterface::isEmpty().
   */
  function isEmpty() {
    $this->garbageCollection();

    $handle = opendir($this->directory);
    $empty = TRUE;
    while (false !== ($entry = readdir($handle))) {
      if ($entry != "." && $entry != "..") {
        $empty = FALSE;
        break;
      }
    }
    closedir($handle);
    return $empty;
  }
}
