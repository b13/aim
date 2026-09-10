# Development

Running the test suite, the static analysis and the coding standards check, on every supported TYPO3 and PHP version.

[Back to the README](../README.md)

## Testing

```bash
cd typo3conf/ext/aim

# Unit tests
Build/Scripts/runTests.sh -s unit

# Functional tests, SQLite by default
Build/Scripts/runTests.sh -s functional

# Functional tests against MariaDB, the second engine CI runs
Build/Scripts/runTests.sh -s functional -d mariadb

# Static analysis, coding standards, syntax check
Build/Scripts/runTests.sh -s phpstan
Build/Scripts/runTests.sh -s cgl
Build/Scripts/runTests.sh -s lint

# With specific PHP version
Build/Scripts/runTests.sh -s unit -p 8.3

# Specific test
Build/Scripts/runTests.sh -s unit -- --filter BudgetService
```

Run the functional tests on both engines before proposing a change that touches
the database. SQLite accepts a double-quoted unknown column as a string literal
instead of erroring, so a query against a column that does not exist passes
there and fails on every other DBMS.

PHPStan is analysed against the installed core, so `phpstan-baseline.neon` is
specific to the TYPO3 version in `.Build`. New findings belong in the code, not
in the baseline.
