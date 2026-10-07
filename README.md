# phpmd plugin for phpcq.

This plugin provides [phpmd](https://github.com/phpmd/phpmd) integration for phpcq.

See also https://phpmd.org

Starting with version 2.0 of this plugin, phpmd 3 is required and the option `excluded` has been renamed to `exclude`.

## Configuration

```yaml
tasks:
  phpmd:
    config:
      # Built-in rulesets or paths to ruleset files.
      ruleset:
        - naming
        - unusedcode
      # Paths or patterns to exclude, passed as "--exclude" to phpmd.
      exclude:
        - src/Legacy
        - '*src/foo/*.php'
      # PHP script which is loaded before the analysis.
      bootstrap: tests/bootstrap.php
      # Any further flags, see "phpmd analyze --help".
      custom_flags:
        - --strict
```
