#include "Phutil.h"

#include <Windows.h>

#include <algorithm>
#include <cstdlib>
#include <cstring>
#include <cwchar>
#include <iostream>
#include <string>
#include <tuple>

namespace {
const wchar_t* const kCmdBuiltins[] = {
    L"assoc",    L"break",    L"call",  L"cd",     L"chdir",  L"cls",
    L"color",    L"copy",     L"date",  L"del",    L"dir",    L"dpath",
    L"echo",     L"endlocal", L"erase", L"exit",   L"for",    L"ftype",
    L"goto",     L"if",       L"keys",  L"md",     L"mkdir",  L"mklink",
    L"move",     L"path",     L"pause", L"popd",   L"prompt", L"pushd",
    L"rd",       L"rem",      L"ren",   L"rename", L"rmdir",  L"set",
    L"setlocal", L"shift",    L"start", L"time",   L"title",  L"type",
    L"ver",      L"verify",   L"vol",
};

[[noreturn]] void Die(const wchar_t* msg) {
  std::wcerr << L"FATAL: " << msg << std::endl;
  std::exit(1);
}

bool IsCmdBuiltin(const wchar_t* candidate) {
  return std::binary_search(
      std::begin(kCmdBuiltins), std::end(kCmdBuiltins), candidate,
      [](const wchar_t* a, const wchar_t* b) { return ::_wcsicmp(a, b) < 0; });
}

std::wstring EscapeArgWin32(const std::wstring& arg) {
  const wchar_t kQuote = L'"';
  const wchar_t kBackslash = L'\\';

  std::wstring result;
  result.push_back(kQuote);
  int consecutive_backslash_count = 0;

  auto span_begin = arg.cbegin();
  for (auto it = arg.cbegin(), end = arg.cend(); it != end; ++it) {
    switch (*it) {
      case kBackslash:
        ++consecutive_backslash_count;
        break;
      case kQuote:
        result.append(span_begin, it);
        result.append(consecutive_backslash_count + 1, kBackslash);
        span_begin = it;
        consecutive_backslash_count = 0;
        break;
      default:
        consecutive_backslash_count = 0;
        break;
    }
  }
  result.append(span_begin, arg.cend());
  result.append(consecutive_backslash_count, kBackslash);
  result.push_back(kQuote);
  return result;
}

std::wstring EncodeCommandLineWin32(int nargs, const wchar_t* args[]) {
  static const wchar_t* const kEscapeSet =
      // These characters are treated specially by CommandLineToArgvW.
      L" \""

      // These characters are treated specially by binaries built by Cygwin and
      // its derivatives. If left unquoted, they trigger post-processing of the
      // CLI arguments via glob expansion, tilde-expansion, and response file
      // parsing. Super annoying. See e.g.
      // <https://github.com/openunix/cygwin/blob/master/winsup/cygwin/dcrt0.cc>.
      L"~@?*[";

  std::wstring result;

  for (int i = 0; i < nargs; ++i) {
    if (i != 0) {
      result.push_back(L' ');
    }

    if (std::wcslen(args[i]) && std::wcspbrk(args[i], kEscapeSet) == nullptr) {
      result.append(args[i]);
    } else {
      result.append(EscapeArgWin32(args[i]));
    }
  }

  return result;
}

std::wstring EncodeCommandLineCmd(int nargs, const wchar_t* args[]) {
  // These characters are treated specially by CommandLineToArgvW.
  static const wchar_t* const kEscapeSet = L" \"";

  // https://blogs.msdn.microsoft.com/twistylittlepassagesallalike/2011/04/23/everyone-quotes-command-line-arguments-the-wrong-way/
  auto isCmdMetachar = [](wchar_t ch) {
    switch (ch) {
      case L'(':
      case L')':
      case L'%':
      case L'!':
      case L'^':
      case L'"':
      case L'<':
      case L'>':
      case L'&':
      case L'|':
        return true;
      default:
        return false;
    }
  };

  std::wstring result = L"cmd.exe /D /C";

  for (int i = 0; i < nargs; ++i) {
    result.push_back(L' ');

    std::wstring arg;
    if (std::wcslen(args[i]) && std::wcspbrk(args[i], kEscapeSet) == nullptr) {
      arg = args[i];
    } else {
      arg = EscapeArgWin32(args[i]);
    }

    if (i == 0) {
      // First argument needs ' ' escaping also.
      for (wchar_t ch : arg) {
        if (isCmdMetachar(ch) || ch == L' ') {
          result.push_back(L'^');
        }
        result.push_back(ch);
      }
    } else {
      for (wchar_t ch : arg) {
        if (isCmdMetachar(ch)) {
          result.push_back(L'^');
        }
        result.push_back(ch);
      }
    }
  }
  return result;
}
}  // namespace

namespace Phutil {
int CallProcess(int argc, const wchar_t* argv[]) {
  if (argc == 0) {
    Die(L"No arguments specified");
  }

  std::wstring cmd = IsCmdBuiltin(argv[0]) ? EncodeCommandLineCmd(argc, argv)
                                           : EncodeCommandLineWin32(argc, argv);

  JOBOBJECT_EXTENDED_LIMIT_INFORMATION info = {};
  bool ok = true;

  HANDLE job = ::CreateJobObject(nullptr, nullptr);  // intentionally leaked
  DWORD len = 0;
  ok = ::QueryInformationJobObject(job, JobObjectExtendedLimitInformation,
                                   &info, sizeof(info), &len);
  if (!ok || (len != sizeof(info)) || !job) {
    Die(L"Job information querying failed");
  }
  info.BasicLimitInformation.LimitFlags |= JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE;
  info.BasicLimitInformation.LimitFlags |= JOB_OBJECT_LIMIT_SILENT_BREAKAWAY_OK;
  ok = ::SetInformationJobObject(job, JobObjectExtendedLimitInformation, &info,
                                 sizeof(info));
  if (!ok) {
    Die(L"Job information setting failed");
  }

  STARTUPINFOW si = {};
  si.cb = sizeof(si);
  si.dwFlags = STARTF_USESTDHANDLES;
  si.hStdInput = ::GetStdHandle(STD_INPUT_HANDLE);
  si.hStdOutput = ::GetStdHandle(STD_OUTPUT_HANDLE);
  si.hStdError = ::GetStdHandle(STD_ERROR_HANDLE);

  ok = ::SetConsoleCtrlHandler([](DWORD) -> BOOL { return TRUE; }, TRUE);
  if (!ok) {
    Die(L"control handler setting failed");
  }

  PROCESS_INFORMATION pi = {};
  ok = ::CreateProcessW(nullptr, &*cmd.begin(), nullptr, nullptr, TRUE, 0,
                        nullptr, nullptr, &si, &pi);
  if (!ok) {
    if (::GetLastError() == ERROR_FILE_NOT_FOUND) {
      std::wcerr << argv[0] << ": not found\n";
      return 127;
    }
    Die(L"Unable to create process");
  }

  ::AssignProcessToJobObject(job, pi.hProcess);
  ::CloseHandle(pi.hThread);

  ::WaitForSingleObjectEx(pi.hProcess, INFINITE, FALSE);

  DWORD rc;
  ok = ::GetExitCodeProcess(pi.hProcess, &rc);
  if (!ok) {
    Die(L"Failed to get exit code of process");
  }

  return rc;
}

// Split a pathname into drive/UNC sharepoint and relative path specifiers.
std::pair<std::wstring, std::wstring> SplitDrive(const std::wstring& path) {
  if (path.size() >= 2) {
    std::wstring normp(path);
    for (wchar_t& ch : normp) {
      ch = (ch == L'/') ? L'\\' : ch;
    }
    if (normp.substr(0, 2) == L"\\\\" && normp.substr(2, 1) != L"\\") {
      // is a UNC path:
      // vvvvvvvvvvvvvvvvvvvv drive letter or UNC path
      // \\machine\mountpoint\directory\etc\...
      //           directory ^^^^^^^^^^^^^^^
      std::size_t index = normp.find(L"\\", 2);
      if (index == normp.npos) {
        return {L"", path};
      }
      std::size_t index2 = normp.find(L"\\", index + 1);
      // a UNC path can't have two slashes in a row
      // (after the initial two)
      if (index2 == index + 1) {
        return {L"", path};
      }
      if (index2 == normp.npos) {
        index2 = path.size();
      }
      return {path.substr(0, index2), path.substr(index2)};
    }
    if (normp[1] == L':') {
      return {path.substr(0, 2), path.substr(2)};
    }
  }
  return {L"", path};
}

// Split the pathname path into a pair, (head, tail) where tail is the last
// pathname component and head is everything leading up to that.
std::pair<std::wstring, std::wstring> SplitPath(const std::wstring& path) {
  wchar_t sep = L'\\';
  wchar_t altsep = L'/';
  const wchar_t* seps = L"/\\";

  std::wstring d, p;
  std::tie(d, p) = SplitDrive(path);

  // set i to index beyond p's last slash
  std::size_t i = p.size();
  while (i && p[i - 1] != sep && p[i - 1] != altsep) {
    i -= 1;
  }
  std::wstring head = p.substr(0, i);
  std::wstring tail = p.substr(i);  // now tail has no slashes
  // remove trailing slashes from head, unless it's all slashes
  std::size_t j = head.find_last_not_of(seps);
  if (j != head.npos) {
    head.erase(j + 1);
  }
  return {d + head, tail};
}

// Returns the path to the current executable.
std::wstring GetExecutablePath() {
  std::wstring buf(256, L'\0');
  for (;;) {
    DWORD result = ::GetModuleFileNameW(nullptr, &*buf.begin(),
                                        static_cast<DWORD>(buf.size()));
    if (result == 0) {
      Die(L"Failed to get executable path");
    }
    if (result < buf.size()) {
      buf.resize(result);
      return buf;
    }
    buf.resize(result);
  }
}

void SetEnv(const std::wstring& key, const std::wstring& val) {
  ::SetEnvironmentVariableW(key.c_str(), val.c_str());
}

std::wstring GetEnv(const std::wstring& key) {
  std::wstring buf(256, L'\0');
  for (;;) {
    DWORD result = ::GetEnvironmentVariableW(key.c_str(), &*buf.begin(),
                                             static_cast<DWORD>(buf.size()));
    if (result == 0) {
      if (::GetLastError() == ERROR_ENVVAR_NOT_FOUND) {
        return L"";
      }
      Die(L"Failed to look up environment variable");
    }
    if (result < buf.size()) {
      buf.resize(result);
      return buf;
    }
    buf.resize(result);
  }
}
}  // namespace Phutil
