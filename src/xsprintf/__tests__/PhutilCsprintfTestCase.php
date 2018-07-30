<?php

final class PhutilCsprintfTestCase extends PhutilTestCase {

  public function testCommandReadableEscapes() {
    $inputs = array(
      // For arguments comprised of only characters which are safe in any
      // context, %R this should avoid adding quotes.
      'ab' => true,

      // For arguments which have any characters which are not safe in some
      // context, %R should apply standard escaping.
      'a b' => false,


      'http://domain.com/path/' => true,
      'svn+ssh://domain.com/path/' => true,
      '`rm -rf`' => false,
      '$VALUE' => phutil_is_windows(),
      '%VALUE%' => !phutil_is_windows(),
    );

    foreach ($inputs as $input => $expect_same) {
      $actual = (string)csprintf('%R', $input);
      if ($expect_same) {
        $this->assertEqual($input, $actual);
      } else {
        $this->assertFalse($input === $actual);
      }
    }
  }

  public function testPowershell() {
    $cmd = csprintf('%s', "\n");
    $cmd->setEscapingMode(PhutilCommandString::MODE_POWERSHELL);

    $this->assertEqual(
      '"`n"',
      (string)$cmd);
  }

  public function testNoPowershell() {
    $cmd = csprintf('%s', '#"');
    $cmd->setEscapingMode(PhutilCommandString::MODE_DEFAULT);

    $this->assertEqual(
      phutil_is_windows() ? '^"#\^"^"' : '\'#"\'',
      (string)$cmd);
  }

  public function testPasswords() {
    // Normal "%s" doesn't do anything special.
    $command = csprintf('echo %s', 'hunter2trustno1');
    $this->assertTrue(strpos($command, 'hunter2trustno1') !== false);

    // "%P" takes a PhutilOpaqueEnvelope.
    $caught = null;
    try {
      csprintf('echo %P', 'hunter2trustno1')->getMaskedString();
    } catch (Exception $ex) {
      $caught = $ex;
    }
    $this->assertTrue($caught instanceof InvalidArgumentException);


    // "%P" masks the provided value.
    $command = csprintf('echo %P', new PhutilOpaqueEnvelope('hunter2trustno1'));
    $this->assertFalse(strpos($command, 'hunter2trustno1'));


    // Executing the command works as expected.
    list($out) = execx('%C', $command);
    $this->assertTrue(strpos($out, 'hunter2trustno1') !== false);
  }

  public function testEscapingIsRobust() {
    if (phutil_is_windows()) {
      // NOTE: The reason we can't run this test on Windows is two fold:
      //         1. We need to use both `argv` escaping and `cmd` escaping
      //            when running commands on Windows because of the CMD proxy
      //         2. After the first `argv` escaping, you only need CMD escaping
      //            but we need a new `%x` thing to signal this which is
      //            probably not worth the added complexity.
      $this->assertSkipped(pht("This test doesn't work on Windows."));
    }

    // Escaping should be robust even when used to escape commands which take
    // other commands.
    list($out) = execx(
      'sh -c %s',
      csprintf(
        'sh -c %s',
        csprintf(
          'sh -c %s',
          csprintf(
            'echo %P',
            new PhutilOpaqueEnvelope('!@#$%^&*()')))));
    $this->assertTrue(strpos($out, '!@#$%^&*()') !== false);
  }

  public function testEdgeCases() {
    $edge_cases = array(
      '\\',
      '%',
      '%%',
      ' ',  // space
      '',  // empty string
      '-',
      '/flag',
      '\\\^\%\\"\ \\',
      '%PATH%',
      '%XYZ%',
      '%%HOMEDIR%',
      'a b',
      '"a b"',
      '"%%$HOMEDIR%^^"',
      '\'a b\'',
      '^%HO ^"M\'EDIR^%^%\'',
      '"\'a\0\r\nb%PATH%%`\'"\'`\'`\'',
    );

    foreach ($edge_cases as $edge_case) {
      list($output) = execx('php -r %s -- %s', 'echo $argv[1];', $edge_case);
      $this->assertEqual($edge_case, $output);
    }
  }

  public function testThrowingEdgeCases() {
    $edge_cases = array(
      "\0",
      "\n",
      "\r",
      "\n\r\n",
    );

    foreach ($edge_cases as $edge_case) {
      $caught = null;
      try {
        $cmd = csprintf('echo %s', $edge_case);
        $cmd->setEscapingMode(PhutilCommandString::MODE_WIN_CMD);
        $cmd->getMaskedString();
      } catch (Exception $ex) {
        $caught = $ex;
      }
      $this->assertTrue($caught instanceof UnexpectedValueException);
    }
  }
}
