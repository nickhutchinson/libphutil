#pragma once
#include <string>
#include <utility>

namespace Phutil {

// Split a pathname into drive/UNC sharepoint and relative path specifiers.
std::pair<std::wstring, std::wstring> SplitDrive(const std::wstring& path);

// Split the pathname path into a pair, (head, tail) where tail is the last
// pathname component and head is everything leading up to that.
std::pair<std::wstring, std::wstring> SplitPath(const std::wstring& path);

// Returns the path to the current executable.
std::wstring GetExecutablePath();

std::wstring GetEnv(const std::wstring& key);
void SetEnv(const std::wstring& key, const std::wstring& val);

int CallProcess(int argc, const wchar_t* argv[]);

}  // namespace Phutil
