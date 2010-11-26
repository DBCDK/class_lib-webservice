# ============================================================================
#			Commands
# ============================================================================

CC=php -l

# ============================================================================
#			Objects
# ============================================================================

SOURCES=$(patsubst %.php,%.chk,$(wildcard *.php))

# ============================================================================
#			Targets
# ============================================================================

all: compile test

compile: $(SOURCES)

%.chk: %.php
	$(CC) $<

test:
	@echo "Unit tests is not implementated yet!"
