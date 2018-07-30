<?php

final class PhutilCommandString extends Phobject {

  private $argv;
  private $escapingMode = false;

  const MODE_DEFAULT = 'default';
  const MODE_WIN_PASSTHRU = 'winargv';
  const MODE_WIN_CMD = 'wincmd';
  const MODE_POWERSHELL = 'powershell';

  public function __construct(array $argv) {
    $this->argv = $argv;

    $this->escapingMode = self::MODE_DEFAULT;
  }

  public function __toString() {
    return $this->getMaskedString();
  }

  public function getUnmaskedString() {
    return $this->renderString(true);
  }

  public function getMaskedString() {
    return $this->renderString(false);
  }

  public function setEscapingMode($escaping_mode) {
    $this->escapingMode = $escaping_mode;
    return $this;
  }

  public function getEscapingMode() {
    return $this->escapingMode;
  }

  private function renderString($unmasked) {
    return xsprintf(
      'xsprintf_command',
      array(
        'unmasked' => $unmasked,
        'mode' => $this->escapingMode,
      ),
      $this->argv);
  }

  public static function escapeArgument($value, $mode) {
    switch ($mode) {
      case self::MODE_DEFAULT:
        return phutil_is_windows()
          ? self::escapeWindowsCMD($value)
          : escapeshellarg($value);
      case self::MODE_POWERSHELL:
        return self::escapePowershell($value);
      case self::MODE_WIN_PASSTHRU:
        return self::escapeWindowsArgv($value);
      case self::MODE_WIN_CMD:
        return self::escapeWindowsCMD($value);
      default:
        throw new Exception(pht('Unknown escaping mode!'));
    }
  }

  /**
   * Escapes a single argument to be glued together and passed into
   * CreateProcess on Windows through `proc_open`.
   *
   * Adapted from https://blogs.msdn.microsoft.com/twistylittlepassagesallalike/2011/04/23/everyone-quotes-command-line-arguments-the-wrong-way/
   *
   * @param  string The argument to be escaped
   * @result string Escaped argument that can be used in a CreateProcess call
   */
  public static function escapeWindowsArgv($value) {
    if (strpos($value, "\0") !== false) {
        throw new UnexpectedValueException(
          pht("Can't pass NULL BYTE in command arguments!"));
    }

    // Don't quote unless we actually need to do so hopefully
    // avoid problems if programs won't parse quotes properly
    if ($value && !preg_match('/["[:space:]]/', $value)) {
        return $value;
    }

    $result = '"';
    $len = strlen($value);
    for ($i = 0; $i < $len; $i++) {
        $num_backslashes = 0;

        while ($i < $len && $value[$i] == '\\') {
            $i++;
            $num_backslashes++;
        }

        if ($i == $len) {
            // Escape all backslashes, but let the terminating
            // double quotation mark we add below be interpreted
            // as a metacharacter.
            $result .= str_repeat('\\', $num_backslashes * 2);
            break;
        } else if ($value[$i] == '"') {
            // Escape all backslashes and the following double quotation mark.
            $result .= str_repeat('\\', $num_backslashes * 2 + 1);
            $result .= $value[$i];
        } else {
            // Backslashes aren't special here.
            $result .= str_repeat('\\', $num_backslashes);
            $result .= $value[$i];
        }
    }

    $result .= '"';

    return $result;
  }

  /**
   * Escapes all CMD metacharacters with a `^`.
   *
   * Adapted from https://blogs.msdn.microsoft.com/twistylittlepassagesallalike/2011/04/23/everyone-quotes-command-line-arguments-the-wrong-way/
   *
   * @param  string The argument to be escaped
   * @result string Escaped argument that can be used in CMD.exe
   */
  private static function escapeWindowsCMD($value) {
    if (preg_match('/[\n\r]/', $value)) {
      throw new UnexpectedValueException(
        pht("Can't pass line breaks to CMD.exe!"));
    }

    // Make sure this is CreateProcess-safe first
    $value = self::escapeWindowsArgv($value);

    // Now prefix all CMD meta characters with a `^` to escape them
    return preg_replace('/[()%!^"<>&|]/', '^$0', $value);
  }

  private static function escapePowershell($value) {
    // These escape sequences are from http://ss64.com/ps/syntax-esc.html

    // Replace backticks first.
    $value = str_replace('`', '``', $value);

    // Now replace other required notations.
    $value = str_replace("\0", '`0', $value);
    $value = str_replace(chr(7), '`a', $value);
    $value = str_replace(chr(8), '`b', $value);
    $value = str_replace("\f", '`f', $value);
    $value = str_replace("\n", '`n', $value);
    $value = str_replace("\r", '`r', $value);
    $value = str_replace("\t", '`t', $value);
    $value = str_replace("\v", '`v', $value);
    $value = str_replace('#', '`#', $value);
    $value = str_replace("'", '`\'', $value);
    $value = str_replace('"', '`"', $value);

    // The rule on dollar signs is mentioned further down the page, and
    // they only need to be escaped when using double quotes (which we are).
    $value = str_replace('$', '`$', $value);

    return '"'.$value.'"';
  }

}
