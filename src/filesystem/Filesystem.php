<?php

/**
 * Simple wrapper class for common filesystem tasks like reading and writing
 * files. When things go wrong, this class throws detailed exceptions with
 * good information about what didn't work.
 *
 * Filesystem will resolve relative paths against PWD from the environment.
 * When Filesystem is unable to complete an operation, it throws a
 * FilesystemException.
 *
 * @task directory   Directories
 * @task file        Files
 * @task path        Paths
 * @task exec        Executables
 * @task assert      Assertions
 */
final class Filesystem extends Phobject {


/* -(  Files  )-------------------------------------------------------------- */


  /**
   * Read a file in a manner similar to file_get_contents(), but throw detailed
   * exceptions on failure.
   *
   * @param  string  File path to read. This file must exist and be readable,
   *                 or an exception will be thrown.
   * @return string  Contents of the specified file.
   *
   * @task   file
   */
  public static function readFile($path) {
    $path = self::resolvePath($path);

    self::assertExists($path);
    self::assertIsFile($path);
    self::assertReadable($path);

    $data = @file_get_contents($path);
    if ($data === false) {
      throw new FilesystemException(
        $path,
        pht("Failed to read file '%s'.", $path));
    }

    return $data;
  }

  /**
   * Make assertions about the state of path in preparation for
   * writeFile() and writeFileIfChanged().
   */
  private static function assertWritableFile($path) {
    $path = self::resolvePath($path);
    $dir = dirname($path);

    self::assertExists($dir);
    self::assertIsDirectory($dir);

    // File either needs to not exist and have a writable parent, or be
    // writable itself.
    $exists = true;
    try {
      self::assertNotExists($path);
      $exists = false;
    } catch (Exception $ex) {
      self::assertWritable($path);
    }

    if (!$exists) {
      self::assertWritable($dir);
    }
  }

  /**
   * Write a file in a manner similar to file_put_contents(), but throw
   * detailed exceptions on failure. If the file already exists, it will be
   * overwritten.
   *
   * @param  string  File path to write. This file must be writable and its
   *                 parent directory must exist.
   * @param  string  Data to write.
   *
   * @task   file
   */
  public static function writeFile($path, $data) {
    self::assertWritableFile($path);

    if (@file_put_contents($path, $data) === false) {
      throw new FilesystemException(
        $path,
        pht("Failed to write file '%s'.", $path));
    }
  }

  /**
   * Write a file in a manner similar to `file_put_contents()`, but only touch
   * the file if the contents are different, and throw detailed exceptions on
   * failure.
   *
   * As this function is used in build steps to update code, if we write a new
   * file, we do so by writing to a temporary file and moving it into place.
   * This allows a concurrently reading process to see a consistent view of the
   * file without needing locking; any given read of the file is guaranteed to
   * be self-consistent and not see partial file contents.
   *
   * @param string file path to write
   * @param string data to write
   *
   * @return boolean indicating whether the file was changed by this function.
   */
  public static function writeFileIfChanged($path, $data) {
    if (file_exists($path)) {
      $current = self::readFile($path);
      if ($current === $data) {
        return false;
      }
    }
    self::assertWritableFile($path);

    // Create the temporary file alongside the intended destination,
    // as this ensures that the rename() will be atomic (on the same fs)
    $dir = dirname($path);
    $temp = tempnam($dir, 'GEN');
    if (!$temp) {
      throw new FilesystemException(
        $dir,
        pht('Unable to create temporary file in %s.', $dir));
    }
    try {
      self::writeFile($temp, $data);
      // tempnam will always restrict ownership to us, broaden
      // it so that these files respect the actual umask
      self::changePermissions($temp, 0666 & ~umask());
      // This will appear atomic to concurrent readers
      $ok = rename($temp, $path);
      if (!$ok) {
        throw new FilesystemException(
          $path,
          pht('Unable to move %s to %s.', $temp, $path));
      }
    } catch (Exception $e) {
      // Make best effort to remove temp file
      unlink($temp);
      throw $e;
    }
    return true;
  }


  /**
   * Write data to unique file, without overwriting existing files. This is
   * useful if you want to write a ".bak" file or something similar, but want
   * to make sure you don't overwrite something already on disk.
   *
   * This function will add a number to the filename if the base name already
   * exists, e.g. "example.bak", "example.bak.1", "example.bak.2", etc. (Don't
   * rely on this exact behavior, of course.)
   *
   * @param   string  Suggested filename, like "example.bak". This name will
   *                  be used if it does not exist, or some similar name will
   *                  be chosen if it does.
   * @param   string  Data to write to the file.
   * @return  string  Path to a newly created and written file which did not
   *                  previously exist, like "example.bak.3".
   * @task file
   */
  public static function writeUniqueFile($base, $data) {
    $full_path = self::resolvePath($base);
    $sequence = 0;
    assert_stringlike($data);
    // Try 'file', 'file.1', 'file.2', etc., until something doesn't exist.

    $dir = dirname($full_path);
    self::assertExists($dir);
    self::assertIsDirectory($dir);
    self::assertWritable($dir);

    while (true) {
      $try_path = $full_path;
      if ($sequence) {
        $try_path .= '.'.$sequence;
      }

      $handle = @fopen($try_path, 'x');
      if ($handle) {
        $ok = fwrite($handle, $data);
        if ($ok === false) {
          throw new FilesystemException(
            $try_path,
            pht('Failed to write file data.'));
        }

        $ok = fclose($handle);
        if (!$ok) {
          throw new FilesystemException(
            $try_path,
            pht('Failed to close file handle.'));
        }

        return $try_path;
      }

      $sequence++;
    }
  }


  /**
   * Append to a file without having to deal with file handles, with
   * detailed exceptions on failure.
   *
   * @param  string  File path to write. This file must be writable or its
   *                 parent directory must exist and be writable.
   * @param  string  Data to write.
   *
   * @task   file
   */
  public static function appendFile($path, $data) {
    $path = self::resolvePath($path);

    // Use self::writeFile() if the file doesn't already exist
    try {
      self::assertExists($path);
    } catch (FilesystemException $ex) {
      self::writeFile($path, $data);
      return;
    }

    // File needs to exist or the directory needs to be writable
    $dir = dirname($path);
    self::assertExists($dir);
    self::assertIsDirectory($dir);
    self::assertWritable($dir);
    assert_stringlike($data);

    if (($fh = fopen($path, 'a')) === false) {
      throw new FilesystemException(
        $path,
        pht("Failed to open file '%s'.", $path));
    }
    $dlen = strlen($data);
    if (fwrite($fh, $data) !== $dlen) {
      throw new FilesystemException(
        $path,
        pht("Failed to write %d bytes to '%s'.", $dlen, $path));
    }
    if (!fflush($fh) || !fclose($fh)) {
      throw new FilesystemException(
        $path,
        pht("Failed closing file '%s' after write.", $path));
    }
  }


  /**
   * Copy a file, preserving file attributes (if relevant for the OS).
   *
   * @param string  File path to copy from.  This file must exist and be
   *                readable, or an exception will be thrown.
   * @param string  File path to copy to.  If a file exists at this path
   *                already, it wll be overwritten.
   *
   * @task  file
   */
  public static function copyFile($from, $to) {
    $from = self::resolvePath($from);
    $to   = self::resolvePath($to);

    self::assertExists($from);
    self::assertIsFile($from);
    self::assertReadable($from);

    if (phutil_is_windows()) {
      execx('copy /Y %s %s', $from, $to);
    } else {
      execx('cp -p %s %s', $from, $to);
    }
  }


  /**
   * Remove a file or directory.
   *
   * @param  string    File to a path or directory to remove.
   * @return void
   *
   * @task   file
   */
  public static function remove($path) {
    if (!strlen($path)) {
      // Avoid removing PWD.
      throw new Exception(
        pht(
          'No path provided to %s.',
          __FUNCTION__.'()'));
    }

    $path = self::resolvePath($path);

    if (!file_exists($path)) {
      return;
    }

    self::executeRemovePath($path);
  }

  /**
   * Rename a file or directory.
   *
   * @param string    Old path.
   * @param string    New path.
   *
   * @task file
   */
  public static function rename($old, $new) {
    $old = self::resolvePath($old);
    $new = self::resolvePath($new);

    self::assertExists($old);

    $ok = rename($old, $new);
    if (!$ok) {
      throw new FilesystemException(
        $new,
        pht("Failed to rename '%s' to '%s'!", $old, $new));
    }
  }


  /**
   * Internal. Recursively remove a file or an entire directory. Implements
   * the core function of @{method:remove} in a way that works on Windows.
   *
   * @param  string    File to a path or directory to remove.
   * @return void
   *
   * @task file
   */
  private static function executeRemovePath($path) {
    if (is_dir($path) && !is_link($path)) {
      foreach (self::listDirectory($path, true) as $child) {
        self::executeRemovePath($path.DIRECTORY_SEPARATOR.$child);
      }
      $ok = @rmdir($path);

      if (!$ok && phutil_is_windows()) {
        // Windows, sigh.
        for ($attempt = 0; !$ok && $attempt < 10; $attempt++) {
          usleep(($attempt + 1) * 25000 /* 25ms */);
          $ok = @rmdir($path);
        }
      }

      if (!$ok) {
         throw new FilesystemException(
          $path,
          pht("Failed to remove directory '%s'!", $path));
      }
    } else {
      $ok = @unlink($path);

      if (!$ok && phutil_is_windows()) {
        // Windows, sigh. First, assume the file has the readonly bit set, and
        // fix that.
        @chmod($path, (fileperms($path) & 07777) | 0200);
        $ok = @unlink($path);

        // Otherwise, assume someone else has an open handle. Retry for a bit.
        for ($attempt = 0; !$ok && $attempt < 10; $attempt++) {
          usleep(($attempt + 1) * 25000 /* 25ms */);
          $ok = @unlink($path);
        }
      }

      if (!$ok) {
        throw new FilesystemException(
          $path,
          pht("Failed to remove file '%s'!", $path));
      }
    }
  }


  /**
   * Change the permissions of a file or directory.
   *
   * @param  string    Path to the file or directory.
   * @param  int       Permission umask. Note that umask is in octal, so you
   *                   should specify it as, e.g., `0777', not `777'.
   * @return void
   *
   * @task   file
   */
  public static function changePermissions($path, $umask) {
    $path = self::resolvePath($path);

    self::assertExists($path);

    if (!@chmod($path, $umask)) {
      $readable_umask = sprintf('%04o', $umask);
      throw new FilesystemException(
        $path,
        pht("Failed to chmod '%s' to '%s'.", $path, $readable_umask));
    }
  }


  /**
   * Get the last modified time of a file
   *
   * @param string Path to file
   * @return int Time last modified
   *
   * @task file
   */
  public static function getModifiedTime($path) {
    $path = self::resolvePath($path);
    self::assertExists($path);
    self::assertIsFile($path);
    self::assertReadable($path);

    $modified_time = @filemtime($path);

    if ($modified_time === false) {
      throw new FilesystemException(
        $path,
        pht('Failed to read modified time for %s.', $path));
    }

    return $modified_time;
  }


  /**
   * Read random bytes from /dev/urandom or equivalent. See also
   * @{method:readRandomCharacters}.
   *
   * @param   int     Number of bytes to read.
   * @return  string  Random bytestring of the provided length.
   *
   * @task file
   */
  public static function readRandomBytes($number_of_bytes) {
    $number_of_bytes = (int)$number_of_bytes;
    if ($number_of_bytes < 1) {
      throw new Exception(pht('You must generate at least 1 byte of entropy.'));
    }

    // Under PHP 7.2.0 and newer, we have a reasonable builtin. For older
    // versions, we fall back to various sources which have a roughly similar
    // effect.
    if (function_exists('random_bytes')) {
      return random_bytes($number_of_bytes);
    }

    // Try to use `openssl_random_pseudo_bytes()` if it's available. This source
    // is the most widely available source, and works on Windows/Linux/OSX/etc.

    if (function_exists('openssl_random_pseudo_bytes')) {
      $strong = true;
      $data = openssl_random_pseudo_bytes($number_of_bytes, $strong);

      if (!$strong) {
        // NOTE: This indicates we're using a weak random source. This is
        // probably OK, but maybe we should be more strict here.
      }

      if ($data === false) {
        throw new Exception(
          pht(
            '%s failed to generate entropy!',
            'openssl_random_pseudo_bytes()'));
      }

      if (strlen($data) != $number_of_bytes) {
        throw new Exception(
          pht(
            '%s returned an unexpected number of bytes (got %s, expected %s)!',
            'openssl_random_pseudo_bytes()',
            new PhutilNumber(strlen($data)),
            new PhutilNumber($number_of_bytes)));
      }

      return $data;
    }


    // Try to use `/dev/urandom` if it's available. This is usually available
    // on non-Windows systems, but some PHP config (open_basedir) and chrooting
    // may limit our access to it.

    $urandom = @fopen('/dev/urandom', 'rb');
    if ($urandom) {
      $data = @fread($urandom, $number_of_bytes);
      @fclose($urandom);
      if (strlen($data) != $number_of_bytes) {
        throw new FilesystemException(
          '/dev/urandom',
          pht('Failed to read random bytes!'));
      }
      return $data;
    }

    // (We might be able to try to generate entropy here from a weaker source
    // if neither of the above sources panned out, see some discussion in
    // T4153.)

    // We've failed to find any valid entropy source. Try to fail in the most
    // useful way we can, based on the platform.

    if (phutil_is_windows()) {
      throw new Exception(
        pht(
          '%s requires the PHP OpenSSL extension to be installed and enabled '.
          'to access an entropy source. On Windows, this extension is usually '.
          'installed but not enabled by default. Enable it in your "s".',
          __METHOD__.'()',
          'php.ini'));
    }

    throw new Exception(
      pht(
        '%s requires the PHP OpenSSL extension or access to "%s". Install or '.
        'enable the OpenSSL extension, or make sure "%s" is accessible.',
        __METHOD__.'()',
        '/dev/urandom',
        '/dev/urandom'));
  }


  /**
   * Read random alphanumeric characters from /dev/urandom or equivalent. This
   * method operates like @{method:readRandomBytes} but produces alphanumeric
   * output (a-z, 0-9) so it's appropriate for use in URIs and other contexts
   * where it needs to be human readable.
   *
   * @param   int     Number of characters to read.
   * @return  string  Random character string of the provided length.
   *
   * @task file
   */
  public static function readRandomCharacters($number_of_characters) {

    // NOTE: To produce the character string, we generate a random byte string
    // of the same length, select the high 5 bits from each byte, and
    // map that to 32 alphanumeric characters. This could be improved (we
    // could improve entropy per character with base-62, and some entropy
    // sources might be less entropic if we discard the low bits) but for
    // reasonable cases where we have a good entropy source and are just
    // generating some kind of human-readable secret this should be more than
    // sufficient and is vastly simpler than trying to do bit fiddling.

    $map = array_merge(range('a', 'z'), range('2', '7'));

    $result = '';
    $bytes = self::readRandomBytes($number_of_characters);
    for ($ii = 0; $ii < $number_of_characters; $ii++) {
      $result .= $map[ord($bytes[$ii]) >> 3];
    }

    return $result;
  }


  /**
   * Generate a random integer value in a given range.
   *
   * This method uses less-entropic random sources under older versions of PHP.
   *
   * @param int Minimum value, inclusive.
   * @param int Maximum value, inclusive.
   */
  public static function readRandomInteger($min, $max) {
    if (!is_int($min)) {
      throw new Exception(pht('Minimum value must be an integer.'));
    }

    if (!is_int($max)) {
      throw new Exception(pht('Maximum value must be an integer.'));
    }

    if ($min > $max) {
      throw new Exception(
        pht(
          'Minimum ("%d") must not be greater than maximum ("%d").',
          $min,
          $max));
    }

    // Under PHP 7.2.0 and newer, we can just use "random_int()". This function
    // is intended to generate cryptographically usable entropy.
    if (function_exists('random_int')) {
      return random_int($min, $max);
    }

    // We could find a stronger source for this, but correctly converting raw
    // bytes to an integer range without biases is fairly hard and it seems
    // like we're more likely to get that wrong than suffer a PRNG prediction
    // issue by falling back to "mt_rand()".

    if (($max - $min) > mt_getrandmax()) {
      throw new Exception(
        pht('mt_rand() range is smaller than the requested range.'));
    }

    $result = mt_rand($min, $max);
    if (!is_int($result)) {
      throw new Exception(pht('Bad return value from mt_rand().'));
    }

    return $result;
  }


  /**
   * Identify the MIME type of a file. This returns only the MIME type (like
   * text/plain), not the encoding (like charset=utf-8).
   *
   * @param string Path to the file to examine.
   * @param string Optional default mime type to return if the file's mime
   *               type can not be identified.
   * @return string File mime type.
   *
   * @task file
   *
   * @phutil-external-symbol function mime_content_type
   * @phutil-external-symbol function finfo_open
   * @phutil-external-symbol function finfo_file
   */
  public static function getMimeType(
    $path,
    $default = 'application/octet-stream') {

    $path = self::resolvePath($path);

    self::assertExists($path);
    self::assertIsFile($path);
    self::assertReadable($path);

    $mime_type = null;

    // Fileinfo is the best approach since it doesn't rely on `file`, but
    // it isn't builtin for older versions of PHP.

    if (function_exists('finfo_open')) {
      $finfo = finfo_open(FILEINFO_MIME);
      if ($finfo) {
        $result = finfo_file($finfo, $path);
        if ($result !== false) {
          $mime_type = $result;
        }
      }
    }

    // If we failed Fileinfo, try `file`. This works well but not all systems
    // have the binary.

    if ($mime_type === null) {
      list($err, $stdout) = exec_manual(
        'file --brief --mime %s',
        $path);
      if (!$err) {
        $mime_type = trim($stdout);
      }
    }

    // If we didn't get anywhere, try the deprecated mime_content_type()
    // function.

    if ($mime_type === null) {
      if (function_exists('mime_content_type')) {
        $result = mime_content_type($path);
        if ($result !== false) {
          $mime_type = $result;
        }
      }
    }

    // If we come back with an encoding, strip it off.
    if (strpos($mime_type, ';') !== false) {
      list($type, $encoding) = explode(';', $mime_type, 2);
      $mime_type = $type;
    }

    if ($mime_type === null) {
      $mime_type = $default;
    }

    return $mime_type;
  }


/* -(  Directories  )-------------------------------------------------------- */


  /**
   * Create a directory in a manner similar to mkdir(), but throw detailed
   * exceptions on failure.
   *
   * @param  string    Path to directory. The parent directory must exist and
   *                   be writable.
   * @param  int       Permission umask. Note that umask is in octal, so you
   *                   should specify it as, e.g., `0777', not `777'.
   * @param  boolean   Recursively create directories. Default to false.
   * @return string    Path to the created directory.
   *
   * @task   directory
   */
  public static function createDirectory(
    $path,
    $umask = 0755,
    $recursive = false) {

    $path = self::resolvePath($path);

    if (is_dir($path)) {
      if ($umask) {
        self::changePermissions($path, $umask);
      }
      return $path;
    }

    $dir = dirname($path);
    if ($recursive && !file_exists($dir)) {
      // Note: We could do this with the recursive third parameter of mkdir(),
      // but then we loose the helpful FilesystemExceptions we normally get.
      self::createDirectory($dir, $umask, true);
    }

    self::assertIsDirectory($dir);
    self::assertExists($dir);
    self::assertWritable($dir);
    self::assertNotExists($path);

    if (!mkdir($path, $umask)) {
      throw new FilesystemException(
        $path,
        pht("Failed to create directory '%s'.", $path));
    }

    // Need to change permissions explicitly because mkdir does something
    // slightly different. mkdir(2) man page:
    // 'The parameter mode specifies the permissions to use. It is modified by
    // the process's umask in the usual way: the permissions of the created
    // directory are (mode & ~umask & 0777)."'
    if ($umask) {
      self::changePermissions($path, $umask);
    }

    return $path;
  }


  /**
   * Create a temporary directory and return the path to it. You are
   * responsible for removing it (e.g., with Filesystem::remove())
   * when you are done with it.
   *
   * @param  string    Optional directory prefix.
   * @param  int       Permissions to create the directory with. By default,
   *                   these permissions are very restrictive (0700).
   * @param  string    Optional root directory. If not provided, the system
   *                   temporary directory (often "/tmp") will be used.
   * @return string    Path to newly created temporary directory.
   *
   * @task   directory
   */
  public static function createTemporaryDirectory(
    $prefix = '',
    $umask = 0700,
    $root_directory = null) {
    $prefix = preg_replace('/[^A-Z0-9._-]+/i', '', $prefix);

    if ($root_directory !== null) {
      $tmp = $root_directory;
      self::assertExists($tmp);
      self::assertIsDirectory($tmp);
      self::assertWritable($tmp);
    } else {
      $tmp = sys_get_temp_dir();
      if (!$tmp) {
        throw new FilesystemException(
          $tmp,
          pht('Unable to determine system temporary directory.'));
      }
    }

    $base = $tmp.DIRECTORY_SEPARATOR.$prefix;

    $tries = 3;
    do {
      $dir = $base.substr(base_convert(md5(mt_rand()), 16, 36), 0, 16);
      try {
        self::createDirectory($dir, $umask);
        break;
      } catch (FilesystemException $ex) {
        // Ignore.
      }
    } while (--$tries);

    if (!$tries) {
      $df = disk_free_space($tmp);
      if ($df !== false && $df < 1024 * 1024) {
        throw new FilesystemException(
          $dir,
          pht('Failed to create a temporary directory: the disk is full.'));
      }

      throw new FilesystemException(
        $dir,
        pht("Failed to create a temporary directory in '%s'.", $tmp));
    }

    return $dir;
  }


  /**
   * List files in a directory.
   *
   * @param  string    Path, absolute or relative to PWD.
   * @param  bool      If false, exclude files beginning with a ".".
   *
   * @return array     List of files and directories in the specified
   *                   directory, excluding `.' and `..'.
   *
   * @task   directory
   */
  public static function listDirectory($path, $include_hidden = true) {
    $path = self::resolvePath($path);

    self::assertExists($path);
    self::assertIsDirectory($path);
    self::assertReadable($path);

    $list = @scandir($path);
    if ($list === false) {
      throw new FilesystemException(
        $path,
        pht("Unable to list contents of directory '%s'.", $path));
    }

    foreach ($list as $k => $v) {
      if ($v == '.' || $v == '..' || (!$include_hidden && $v[0] == '.')) {
        unset($list[$k]);
      }
    }

    return array_values($list);
  }


  /**
   * Return all directories between a path and the specified root directory
   * (defaulting to "/"). Iterating over them walks from the path to the root.
   *
   * @param  string        Path, absolute or relative to PWD.
   * @param  string        The root directory.
   * @return list<string>  List of parent paths, including the provided path.
   * @task   directory
   */
  public static function walkToRoot($path, $root = null) {
    $path = self::resolvePath($path);

    if ($root == null) {
      $root = phutil_is_windows() ? idx(self::splitDrive($path), 0).'\\' : '/';
    } else {
      $root = self::resolvePath($root);
    }

    if (is_link($path)) {
      $path = realpath($path);
    }
    if (is_link($root)) {
      $root = realpath($root);
    }

    // NOTE: We don't use `isDescendant()` here because we don't want to reject
    // paths which don't exist on disk.
    $root_list = new FileList(array($root));
    if (!$root_list->contains($path)) {
      return array();
    }

    list($drive, $path) = self::splitDrive($path);

    $walk = array();
    $parts = explode(DIRECTORY_SEPARATOR, $path);
    foreach ($parts as $k => $part) {
      if (!strlen($part)) {
        unset($parts[$k]);
      }
    }

    while (true) {
      $next = DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $parts);

      $walk[] = $drive.$next;
      if ($drive.$next == $root) {
        break;
      }

      if (!$parts) {
        break;
      }

      array_pop($parts);
    }

    return $walk;
  }


/* -(  Paths  )-------------------------------------------------------------- */


  /**
   * Checks if a path is specified as an absolute path.
   *
   * @param  string
   * @return bool
   */
  public static function isAbsolutePath($path) {
    if (phutil_is_windows()) {
      list(, $p) = self::splitDrive($path);
      return strlen($p) && ($p[0] == '\\' || $p[0] == '/');
    } else {
      return !strncmp($path, DIRECTORY_SEPARATOR, 1);
    }
  }

  /**
   * Canonicalize a path by resolving it relative to some directory (by
   * default PWD), following parent symlinks and removing artifacts. If the
   * path is itself a symlink it is left unresolved.
   *
   * @param  string    Path, absolute or relative to PWD.
   * @return string    Canonical, absolute path.
   *
   * @task   path
   */
  public static function resolvePath($path, $relative_to = null) {
    if (phutil_is_windows()) {
      $path = str_replace('/', '\\', $path);
      $relative_to = $relative_to
        ? str_replace('/', '\\', $relative_to)
        : $relative_to;
    }

    $is_absolute = self::isAbsolutePath($path);

    if (!$is_absolute) {
      if (!$relative_to) {
        $relative_to = getcwd();
      }
      $path = self::joinPath($relative_to, $path);
    }

    if (is_link($path)) {
      list($parent, $base) = self::splitPath($path);
      $parent_realpath = realpath($parent);
      if ($parent_realpath !== false) {
        return self::joinPath($parent_realpath, $base);
      }
    }

    $realpath = realpath($path);
    if ($realpath !== false) {
      return $realpath;
    }


    // This won't work if the file doesn't exist or is on an unreadable mount
    // or something crazy like that. Try to resolve a parent so we at least
    // cover the nonexistent file case.
    list($head, $tail) = self::splitPath($path);
    while (true) {
      $realpath = realpath($head);
      if ($realpath !== false) {
        return self::joinPath($realpath, $tail);
      }

      list($head2, $tail2) = self::splitPath($head);
      if ($head == $head2) {
        // Reached the root without being able to resolve anything. Bail.
        return self::joinPath($head, $tail);
      }

      $head = $head2;
      $tail = self::joinPath($tail2, $tail);
    }
  }

  /**
   * Test whether a path is descendant from some root path after resolving all
   * symlinks and removing artifacts. Both paths must exists for the relation
   * to obtain. A path is always a descendant of itself as long as it exists.
   *
   * @param  string   Child path, absolute or relative to PWD.
   * @param  string   Root path, absolute or relative to PWD.
   * @return bool     True if resolved child path is in fact a descendant of
   *                  resolved root path and both exist.
   * @task   path
   */
  public static function isDescendant($path, $root) {
    try {
      self::assertExists($path);
      self::assertExists($root);
    } catch (FilesystemException $e) {
      return false;
    }
    $fs = new FileList(array($root));
    return $fs->contains($path);
  }

  /**
   * Convert a canonical path to its most human-readable format. It is
   * guaranteed that you can use resolvePath() to restore a path to its
   * canonical format.
   *
   * @param  string    Path, absolute or relative to PWD.
   * @param  string    Optionally, working directory to make files readable
   *                   relative to.
   * @return string    Human-readable path.
   *
   * @task   path
   */
  public static function readablePath($path, $pwd = null) {
    if ($pwd === null) {
      $pwd = getcwd();
    }

    foreach (array($pwd, self::resolvePath($pwd)) as $parent) {
      list($drive, $parent) = self::splitDrive($parent);
      $parent = $drive.rtrim($parent, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
      $len = strlen($parent);
      if (!strncmp($parent, $path, $len)) {
        $path = substr($path, $len);
        return $path;
      }
    }

    return $path;
  }

  /**
   * Determine whether or not a path exists in the filesystem. This differs from
   * file_exists() in that it returns true for symlinks. This method does not
   * attempt to resolve paths before testing them.
   *
   * @param   string  Test for the existence of this path.
   * @return  bool    True if the path exists in the filesystem.
   * @task    path
   */
  public static function pathExists($path) {
    return file_exists($path) || is_link($path);
  }


  /**
   * Determine if an executable binary (like `git` or `svn`) exists within
   * the configured `$PATH`.
   *
   * @param   string  Binary name, like `'git'` or `'svn'`.
   * @return  bool    True if the binary exists and is executable.
   * @task    exec
   */
  public static function binaryExists($binary) {
    return self::resolveBinary($binary) !== null;
  }

  /**
   * Split a pathname.
   * Return tuple (head, tail) where tail is everything after the final slash.
   * Either part may be empty. Follows Python 3.6's os.path.split; more robust
   * than PHP's dirname()/basename().
   */
  public static function splitPath($p) {
    if (phutil_is_windows()) {
      $sep = '\\';
      $altsep = '/';
      $seps = '/\\';
    } else {
      $sep = $altsep = $seps = '/';
    }

    $p = (string)$p;
    list($d, $p) = self::splitDrive($p);

    // set i to index beyond p's last slash
    $i = strlen($p);
    while ($i && $p[$i - 1] != $sep && $p[$i - 1] != $altsep) {
      $i -= 1;
    }
    $head = substr($p, 0, $i);
    $tail = substr($p, $i);  // now tail has no slashes
    // remove trailing slashes from head, unless it's all slashes
    $head = nonempty(rtrim($head, $seps), $head);
    return array($d.$head, $tail);
  }

  /**
   * Join one or more path components intelligently, in the same was that
   * Python 3.6's os.path.join() does.
   *
   * @param ...     One or more path components.
   */
  public static function joinPath($path /* , ...$paths */) {
    $path = (string)$path;
    $paths = func_get_args();
    array_shift($paths);

    if (phutil_is_windows()) {
      $sep = '\\';
      $altsep = '/';
      list($result_drive, $result_path) = self::splitDrive($path);
      foreach ($paths as $p) {
        $p = (string)$p;
        list($p_drive, $p_path) = self::splitDrive($p);
        if ($p_path && ($p_path[0] == $sep || $p_path[0] == $altsep)) {
          // Second path is absolute
          if ($p_drive || !$result_drive) {
            $result_drive = $p_drive;
          }
          $result_path = $p_path;
          continue;

        } else if ($p_drive && $p_drive != $result_drive) {
          if (strtolower($p_drive) != strtolower($result_drive)) {
            // Different drives => ignore the first path entirely
            $result_drive = $p_drive;
            $result_path = $p_path;
            continue;
          }
          // Same drive in different case
          $result_drive = $p_drive;
        }

        // Second path is relative to the first
        if ($result_path &&
            substr($result_path, -1) != $sep &&
            substr($result_path, -1) != $altsep) {
          $result_path .= $sep;
        }

        $result_path .= $p_path;
      }

      // add separator between UNC and non-absolute path
      if ($result_path &&
          $result_path[0] != $sep &&
          $result_path[0] != $altsep &&
          $result_drive &&
          substr($result_drive, -1) != ':') {
        return $result_drive.$sep.$result_path;
      }

      return $result_drive.$result_path;

    } else {
      $sep = '/';
      $result_path = $path;
      foreach ($paths as $p) {
        if ($p && $p[0] == $sep) {
          $result_path = $p;
        } else if (!$result_path || substr($result_path, -1) == $sep) {
          $result_path .= $p;
        } else {
          $result_path .= $sep.$p;
        }
      }

      return $result_path;
    }
  }

  /**
   * Locates the full path that an executable binary (like `git` or `svn`) is at
   * the configured `$PATH`.
   *
   * @param   string  Binary name, like `'git'` or `'svn'`.
   * @return  string  The full binary path if it is present, or null.
   * @task    exec
   */
  public static function resolveBinary($binary) {
    $files = array();
    if (phutil_is_windows()) {
      // PATHEXT is necessary to check on Windows.
      $pathext = explode(PATH_SEPARATOR, nonempty(getenv('PATHEXT'), ''));
      foreach ($pathext as $ext) {
        // See if the given file matches any of the expected path extensions.
        // This will allow us to short circuit when given "python.exe".
        // If it does match, only test that one, otherwise we have to try
        // others.
        if (strlen($binary) >= strlen($ext) &&
            stripos($binary, $ext, strlen($binary) - strlen($ext)) !== false) {
          $files = array($binary);
          break;
        }
        $files[] = $binary.$ext;
      }
    } else {
      // On other platforms you don't have things like PATHEXT to tell you
      // what file suffixes are executable, so just pass on binary as-is.
      $files[] = $binary;
    }

    // If we're given a path with a directory part, look it up directly rather
    // than referring to PATH directories. This includes checking relative to
    // the current directory, e.g. ./script
    if (idx(self::splitPath($binary), 0)) {
      foreach ($files as $candidate) {
        if (is_file($candidate) &&
            (phutil_is_windows() || is_executable($candidate))) {
          // returning relative path is weird, but consistent with ``which``.
          return $candidate;
        }
      }
      return null;
    }

    $path = explode(PATH_SEPARATOR, nonempty(getenv('PATH'), ''));
    if (!$path) {
      return null;
    }

    if (phutil_is_windows()) {
      if (!in_array(getcwd(), $path)) {
        // The current directory takes precedence on Windows.
        array_unshift($path, getcwd());
      }
    }

    foreach ($path as $dir) {
      foreach ($files as $file) {
        $candidate = self::joinPath($dir, $file);
        if (is_file($candidate) &&
            (phutil_is_windows() || is_executable($candidate))) {
          return $candidate;
        }
      }
    }
    return null;
  }


  /**
   * Determine if two paths are equivalent by resolving symlinks. This is
   * different from resolving both paths and comparing them because
   * resolvePath() only resolves symlinks in parent directories, not the
   * path itself.
   *
   * @param string First path to test for equivalence.
   * @param string Second path to test for equivalence.
   * @return bool  True if both paths are equivalent, i.e. reference the same
   *               entity in the filesystem.
   * @task path
   */
  public static function pathsAreEquivalent($u, $v) {
    $u = self::resolvePath($u);
    $v = self::resolvePath($v);

    $real_u = realpath($u);
    $real_v = realpath($v);

    if ($real_u) {
      $u = $real_u;
    }
    if ($real_v) {
      $v = $real_v;
    }
    return ($u == $v);
  }

  /**
   * Split a pathname into drive/UNC sharepoint and relative path specifiers.
   * Returns a 2-tuple (drive_or_unc, path); either part may be empty. Useful
   * on Windows; on POSIX, the drive is always empty.
   *
   * If you assign
   *     $result = splitDrive($p)
   * It is always true that:
   *     $result[0].$result[1] == $p
   *
   * If the path contained a drive letter, drive_or_unc will contain everything
   * up to and including the colon. E.g. splitDrive("c:/dir") returns
   * ("c:", "/dir") If the path contained a UNC path, the drive_or_unc will
   * contain the host name and share up to but not including the fourth
   * directory separator character. e.g. splitDrive("//host/computer/dir")
   * returns ("//host/computer", "/dir"). Paths cannot contain both a drive
   * letter and a UNC path.
   */
  public static function splitDrive($p) {
    $p = (string)$p;
    if (phutil_is_windows() && strlen($p) >= 2) {
      $sep = '\\';
      $normp = str_replace('/', $sep, $p);
      if (substr($normp, 0, 2) == $sep.$sep && substr($normp, 2, 1) != $sep) {
        // is a UNC path:
        // vvvvvvvvvvvvvvvvvvvv drive letter or UNC path
        // \\machine\mountpoint\directory\etc\...
        //           directory ^^^^^^^^^^^^^^^
        $index = strpos($normp, $sep, 2);
        if ($index == false) {
          return array('', $p);
        }
        $index2 = strpos($normp, $sep, $index + 1);
        // a UNC path can't have two slashes in a row
        // (after the initial two)
        if ($index2 == $index + 1) {
          return array('', $p);
        }
        if ($index2 == false) {
          $index2 = strlen($p);
        }
        return array(substr($p, 0, $index2), substr($p, $index2));
      }
      if ($normp[1] == ':') {
        return array(substr($p, 0, 2), substr($p, 2));
      }
    }
    return array('', $p);
  }

/* -(  Assert  )------------------------------------------------------------- */


  /**
   * Assert that something (e.g., a file, directory, or symlink) exists at a
   * specified location.
   *
   * @param  string    Assert that this path exists.
   * @return void
   *
   * @task   assert
   */
  public static function assertExists($path) {
    if (!self::pathExists($path)) {
      throw new FilesystemException(
        $path,
        pht("File system entity '%s' does not exist.", $path));
    }
  }


  /**
   * Assert that nothing exists at a specified location.
   *
   * @param  string    Assert that this path does not exist.
   * @return void
   *
   * @task   assert
   */
  public static function assertNotExists($path) {
    if (file_exists($path) || is_link($path)) {
      throw new FilesystemException(
        $path,
        pht("Path '%s' already exists!", $path));
    }
  }


  /**
   * Assert that a path represents a file, strictly (i.e., not a directory).
   *
   * @param  string    Assert that this path is a file.
   * @return void
   *
   * @task   assert
   */
  public static function assertIsFile($path) {
    if (!is_file($path)) {
      throw new FilesystemException(
        $path,
        pht("Requested path '%s' is not a file.", $path));
    }
  }


  /**
   * Assert that a path represents a directory, strictly (i.e., not a file).
   *
   * @param  string    Assert that this path is a directory.
   * @return void
   *
   * @task   assert
   */
  public static function assertIsDirectory($path) {
    if (!is_dir($path)) {
      throw new FilesystemException(
        $path,
        pht("Requested path '%s' is not a directory.", $path));
    }
  }


  /**
   * Assert that a file or directory exists and is writable.
   *
   * @param  string    Assert that this path is writable.
   * @return void
   *
   * @task   assert
   */
  public static function assertWritable($path) {
    if (!is_writable($path)) {
      throw new FilesystemException(
        $path,
        pht("Requested path '%s' is not writable.", $path));
    }
  }


  /**
   * Assert that a file or directory exists and is readable.
   *
   * @param  string    Assert that this path is readable.
   * @return void
   *
   * @task   assert
   */
  public static function assertReadable($path) {
    if (!is_readable($path)) {
      throw new FilesystemException(
        $path,
        pht("Path '%s' is not readable.", $path));
    }
  }

}
