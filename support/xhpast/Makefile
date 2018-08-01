ifeq ($(OS),Windows_NT)
	RM    = sh -c 'rm -rf "$$@"' ''
	CP    = sh -c 'cp -f "$$@"' ''
	TOUCH = sh -c 'touch "$$@"' ''
	EXE   = .exe
else
	RM    = rm -fr
	CP    = cp -f
	TOUCH =
	EXE   =
endif

BISONFLAGS = --verbose -Wall
CPPFLAGS   = -fPIC -Wall
FLEXFLAGS  = -CFr

ifdef DEBUG
	BISONFLAGS += --debug
	CPPFLAGS   += -ggdb -DDEBUG
	FLEXFLAGS  += --debug
else
	CPPFLAGS  += -O3 -minline-all-stringops
endif

ifdef PROFILE
	CPPFLAGS += -pg
endif

ifdef STATIC
	CPPFLAGS += -static
endif

ifeq ($(OS),Windows_NT)
	CPPFLAGS += -static-libgcc -static-libstdc++ -Wl,--no-insert-timestamp
endif

ROOT = ../../src/parser/xhpast

.PHONY: all
all: xhpast$(EXE)

clean:
	$(RM) xhpast$(EXE) parser.yacc.output libxhpast.a *.o

cleanall: clean
	$(RM) scanner.lex.hpp scanner.lex.cpp parser.yacc.hpp parser.yacc.cpp
	$(RM) node_names.hpp parser_nodes.php

.PHONY: install
install: xhpast$(EXE)
	$(CP) xhpast$(EXE) $(ROOT)/bin/xhpast$(EXE)

.PHONY: parser scanner

parser: parser.yacc.hpp parser.yacc.cpp
scanner: scanner.lex.hpp scanner.lex.cpp

%.lex.hpp %.lex.cpp: %.l
ifndef SKIP_SCANNER
	flex $(FLEXFLAGS) --header-file=$*.lex.hpp --outfile=$*.lex.cpp $<
	@echo '/* @gen''er''ated */' >> $*.lex.hpp
	@echo '/* @gen''er''ated */' >> $*.lex.cpp
else
	$(TOUCH) $*.lex.hpp $*.lex.cpp
endif

%.yacc.hpp %.yacc.cpp: %.y
ifndef SKIP_PARSER
	bison $(BISONFLAGS) --defines=$*.yacc.hpp --output=$*.yacc.cpp $<
	@echo '/* @gen''er''ated */' >> $*.yacc.hpp
	@echo '/* @gen''er''ated */' >> $*.yacc.cpp
else
	$(TOUCH) $*.yacc.hpp $*.yacc.cpp
endif

%.o: %.cpp
	$(CXX) -c $(CPPFLAGS) -o $@ $<

node_names.hpp parser_nodes.php: generate_nodes.php
	php -f $<

parser.yacc.o: scanner.lex.hpp
scanner.lex.o: parser.yacc.hpp node_names.hpp scanner.lex.hpp

libxhpast.a: scanner.lex.o parser.yacc.o
	$(AR) -crs $@ $^

xhpast$(EXE): xhpast.cpp libxhpast.a
	$(CXX) $(CPPFLAGS) -o $@ $^
