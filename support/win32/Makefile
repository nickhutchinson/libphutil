ifeq ($(OS),Windows_NT)
	RM = sh -c 'rm -rf "$$@"' ''
	CP = sh -c 'cp -f "$$@"' ''
else
	RM = rm -fr
	CP = cp -f
endif

PHUTIL_ROOT = ../..

ifdef DEBUG
	CXXFLAGS  += -g -DDEBUG
else
	CXXFLAGS  += -Os -s -DNDEBUG
endif

CXXFLAGS += -Wall -Wextra -Wconversion -std=c++11
CXXFLAGS += -DWIN32_LEAN_AND_MEAN -DUNICODE -D_UNICODE
CXXFLAGS += -municode -static-libgcc -static-libstdc++
CXXFLAGS += -Wl,--no-insert-timestamp

.PHONY: all
all: PhutilLauncher.exe

.PHONY: clean
clean:
	$(RM) PhutilLauncher.exe libphutil.a *.o

.PHONY: install
install: PhutilLauncher.exe
	$(CP) PhutilLauncher.exe $(PHUTIL_ROOT)/bin/PhutilLauncher.exe

%.o: %.cpp
	$(CXX) -c $(CXXFLAGS) -o $@ $<

libphutil.a: Phutil.o
	$(AR) -crs $@ $^

PhutilLauncher.exe: PhutilLauncher.o libphutil.a
	$(CXX) $(CXXFLAGS) -o $@ $^
