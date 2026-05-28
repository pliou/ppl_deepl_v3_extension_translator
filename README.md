# PPL DeepL V3 Extension Translator

TYPO3 12.4 backend module for auditing extension XLF files and writing selected translation fixes.

The module is audit-first:

- scans local extension paths and concrete vendor packages
- classifies findings with the current issue taxonomy instead of a broad "missing key" bucket
- never calls DeepL during a dry scan
- previews all writes before changing XLF files
- creates a backup before every write
- blocks vendor writes unconditionally
- uses `ppl_deepl_v3_requests` as the only DeepL request layer

Current issue types:

```text
key_mismatch_candidate
keyless_unit
missing_source_from_locale_candidate
missing_source_unit
missing_target
todo_value
equal_value
unused_candidate
locale_gap
cannot_change
```

Important taxonomy rules:

- `keyless_unit` and `key_mismatch_candidate` are resolved before true missing-source findings.
- `missing_source_unit` means the source unit is genuinely absent and no safe candidate was found.
- `missing_target` is the normal DeepL-capable translation case because a source exists and the target is missing.
- `unused_candidate` replaces the old "extra key" wording because dynamic usage can make a key valid.
- DeepL actions are only offered when a reliable source text is known.
- Vendor findings remain read-only.

Backend module:

```text
PPL DeepL V3 > Extension Translator
```

Supported scopes:

```text
packages/
extensions/
local/
typo3conf/ext/
vendor/<vendor>/<package> read-only
```

Backups are written below:

```text
var/ppl_deepl_v3_extension_translator/backups/<timestamp>/
```
