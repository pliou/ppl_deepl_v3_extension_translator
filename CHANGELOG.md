# Changelog

## Unreleased

- Hardened backend request handling so write actions require protected POST requests.
- Added production write blocking through the `allowProductionWrites` extension setting.
- Added per-file backups before XLF and code writes.
- Blocked automatic writes to existing XLF source/target elements that contain inline XML markup.
- Limited service autowiring to real service classes and added the required `ext-dom` Composer dependency.
- Made smoke matrix Fake DeepL activation temporary by default; `--keep-fake` keeps the previous persistent debug behavior.
- Improved code-key scanning for dynamic translation keys, TYPO3 `locallang_mod.xlf` module labels and incomplete concatenated key prefixes.
- Excluded the Extension Translator package itself from TODO-placeholder findings because it legitimately labels its own TODO issue types.
- Updated documentation to describe the direct-write workflow, backup location and safety checks.
