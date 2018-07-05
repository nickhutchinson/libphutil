<?php

final class PhutilDirectoryFixture extends Phobject {

  protected $path;

  public static function newFromArchive($archive) {
    $obj = self::newEmptyFixture();
    $path = $obj->getPath();
    $archive = Filesystem::resolvePath($archive);

    if (phutil_is_windows()) {
      $path = str_replace('\\', '/', $path);
      $archive = str_replace('\\', '/', $archive);
    }

    execx('%C -C %s -xzvvf %s',
      phutil_is_windows() ? 'tar --force-local' : 'tar',
      $path,
      $archive);
    return $obj;
  }

  public static function newEmptyFixture() {
    $obj = new PhutilDirectoryFixture();
    $obj->path = Filesystem::createTemporaryDirectory();
    return $obj;
  }

  private function __construct() {
    // <restricted>
  }

  public function __destruct() {
    Filesystem::remove($this->path);
  }

  public function getPath($to_file = null) {
    return $this->path.'/'.ltrim($to_file, '/');
  }

  public function saveToArchive($path) {
    $path = $this->getPath();
    $tmp = new TempFile();

    if (phutil_is_windows()) {
      $path = str_replace('\\', '/', $path);
      $tmp = str_replace('\\', '/', $tmp);
    }

    execx('%C -C %s -czvvf %s .',
      phutil_is_windows() ? 'tar --force-local' : 'tar',
      $path,
      $tmp);

    $ok = rename($tmp, Filesystem::resolvePath($path));
    if (!$ok) {
      throw new FilesystemException($path, pht('Failed to overwrite file.'));
    }

    return $this;
  }

}
