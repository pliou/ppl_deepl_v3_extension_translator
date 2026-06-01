# PPL DeepL V3 Extension Translator

TYPO3 14 backend module for auditing extension XLF files and writing selected translation fixes.

The module is audit-first:

- scans local extension paths and concrete vendor packages
- classifies findings with the current issue taxonomy instead of a broad "missing key" bucket
- never calls DeepL during a dry scan
- runs the selected solution directly from the Solution tool and rescans after successful writes
- creates a backup before each file write
- requires protected POST requests for write actions
- blocks production writes unless explicitly enabled in extension configuration
- blocks vendor writes unconditionally
- uses `ppl_deepl_v3_requests` as the only DeepL request layer

## Related DeepL V3 Packages

The DeepL V3 line is split into focused TYPO3 extensions:

- `ppl/ppl-deepl-v3-requests` (`ppl_deepl_v3_requests`): shared request and configuration layer. It owns the DeepL API key lookup, endpoint selection, HTTP calls, language/glossary/style-rule fetches, custom instructions and shared approval storage.
- `ppl/ppl-deepl-v3-translate` (`ppl_deepl_v3_translate`): frontend content elements and backend modules for interactive text and file translation.
- `ppl/ppl-deepl-v3-batch-translation` (`ppl_deepl_v3_batch_translation`): backend workspace for translating TYPO3 page trees, pages and content elements with preflight review and controlled record writes.
- `ppl/ppl-deepl-v3-extension-translator` (`ppl_deepl_v3_extension_translator`): this package. It scans extension XLF files and code usage, classifies findings and writes selected translation fixes with backups.

Extension Translator uses `ppl_deepl_v3_requests` for DeepL suggestions when a reliable source text exists. It does not own API key handling or shared language/glossary/style-rule approval storage.

Current issue types:

```text
key_mismatch_candidate
keyless_unit
missing_source_from_locale_candidate
missing_source_unit
missing_translation_unit
locale_gap
missing_target
todo_source
todo_value
equal_value
unused_candidate
```

Important taxonomy rules:

- `keyless_unit` and `key_mismatch_candidate` are resolved before true missing-source findings.
- `missing_source_from_locale_candidate` means the source is empty; locale text is shown only as a candidate.
- `missing_source_unit` means the source is genuinely absent or not reliable; the source column stays empty until the source is entered.
- `missing_translation_unit` is reserved for target/translation-unit completion after source handling; source-less rows are classified as missing source first.
- `locale_gap` means an existing locale file lacks a key that exists in the source file; the fix copies id + source and leaves target empty.
- `missing_target` is the normal DeepL-capable translation case because a source exists and the target is missing.
- `unused_candidate` replaces the old "extra key" wording because dynamic usage can make a key valid.
- Code-key scanning recognizes static `LLL:EXT:` references, Fluid translation usages, TYPO3 `locallang_mod.xlf` module-label conventions and key-like dynamic labels with known prefixes.
- Smoke fixture keys are not counted as production translation usage.
- The Extension Translator package itself is skipped for TODO-placeholder findings because its UI intentionally names the TODO issue types. Other packages keep the normal TODO source/target checks.
- DeepL actions are only offered when a reliable source text is known.
- Vendor findings remain read-only.
- Read-only rows stay in their real issue type and can be ignored permanently; there is no separate `cannot_change` issue type.

Dashboard UI:

- The module uses a dashboard layout with project scan and file selection on the left, issue tabs and problem rows in the center, and the selected issue plus solution controls on the right.
- The old numbered Step 1-5 layout is not part of the current UI.
- Main issue tabs keep stable widths; rarer issue types are grouped under `Other` only as a presentation shortcut.
- Source-less findings keep an empty Source column. Candidate text is shown under related candidates until the user explicitly chooses it as source.
- Manual source, manual target and target key inputs are prefilled from existing values when there is exactly one safe default, but user-cleared values are preserved during refreshes.

Backend module:

```text
PPL DeepL V3 > Extension Translator
```

Write model:

- `GET` requests may only run a scan. Write-capable actions require `POST` and a valid TYPO3 FormProtection token.
- The UI does not add a second confirmation step. Choose a solution, run it, and the module writes immediately when the selected rows are writable.
- Before saving an XLF or code file, the writer copies the original file to `var/ppl_deepl_v3_extension_translator/backups/<timestamp>/...`.
- Automatic writes are plain-text only. Existing XLF `source` or `target` elements with inline XML markup are blocked with a clear error instead of being flattened.
- Local writes are disabled in Production context unless `allowProductionWrites` is enabled in extension configuration.

Supported scopes:

```text
packages/
extensions/
local/
typo3conf/ext/
vendor/<vendor>/<package> read-only
```

Smoke Fake DeepL:

- `vendor/bin/typo3 ppl:extension-translator:smoke --reset-fixture --run-matrix` activates Fake DeepL for the matrix run and deactivates it automatically afterwards.
- Use `--keep-fake` only for deliberate manual debugging.
- Use `--deactivate-fake` to clear a persistent Fake DeepL context.
