#include <cstdlib>
#include <iostream>
#include <string>

#include "Phutil.h"

namespace {
[[noreturn]] void PrintUsageAndExit() {
  std::wcerr << L"PhutilLancher.exe prog [arg1 [arg2...]]\n";
  std::exit(1);
}
}  // namespace

int wmain(int argc, const wchar_t* argv[]) {
  if (argc < 2) {
    PrintUsageAndExit();
  }

  return Phutil::CallProcess(argc - 1, argv + 1);
}
