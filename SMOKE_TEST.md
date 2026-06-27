# PPL DeepL V3 Extension Translator Smoke Test

## Install

1. Install or activate `ppl_deepl_v3_requests` first.
2. Install `ppl_deepl_v3_extension_translator`.
3. Clear TYPO3 caches.
4. Verify the backend module appears at `PPL DeepL V3 > Extension Translator`.

## Static Safety

- Open the module without submitting anything.
- Verify the header shows the current environment and DeepL configuration state.
- Verify no DeepL request is made before choosing a solution that explicitly translates with DeepL.

## Taxonomy Smoke Test

1. Activate or place `ppl_et_issue_fixture` beside the translator extension.
2. Run `vendor/bin/typo3 ppl:extension-translator:smoke --reset-fixture --run-matrix`.
3. Verify the summary is written under `var/smoke/extension-translator-taxonomy/<timestamp>/summary.md`.
4. Verify Fake DeepL calls are logged in `fake-deepl-calls.json` and no real API request is made.
5. Verify the persistent Fake DeepL context is deactivated after the matrix run. Use `--keep-fake` only when manual debugging should keep it active.

## Vendor Safety Test

1. Scan one concrete package path such as `vendor/vendor/package`.
2. Verify broad `vendor/` and `vendor/vendor` scans are rejected.
3. Verify findings from vendor paths are read-only and cannot be selected for write.

## Write Smoke Test

1. Use `ppl_et_issue_fixture` or another disposable local test extension.
2. Select rows from one issue type only.
3. Choose one issue-specific solution such as `Write TODO target` or `Copy source`.
4. Run the solution directly from the right-side Solution tool.
5. Verify a backup is created under `var/ppl_deepl_v3_extension_translator/backups/<timestamp>/...`.
6. Verify the row state changed after the automatic rescan.

## DeepL Smoke Test

1. Select one safe writable row with source text.
2. Use an issue type with known source text, such as `missing_target`.
3. Run `Translate and write` from the Solution tool.
4. Verify the target file is written in the same action and the row is gone or reclassified after rescan.
5. Verify DeepL is accessed only through `ppl_deepl_v3_requests` or the fake provider during smoke tests.

## Request Safety Smoke Test

1. Call the module with `GET ?module_action=delete_ignore_rules`.
2. Verify the action is ignored and no blacklist entry is deleted.
3. Submit a write action without a valid FormProtection token.
4. Verify the action is ignored and a token error is shown.
